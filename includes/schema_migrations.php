<?php

declare(strict_types=1);

/**
 * ترحيلات مخطط مرقّمة: scripts/migrations/NNN_وصف.sql أو NNN.sql
 * تُسجَّل في orange_schema_migrations وتُنفَّذ مرة واحدة لكل ملف.
 *
 * القاعدة الأولى للهيكل الكامل تبقى mysql-create-orange-database-full.sql؛
 * الترحيلات لاحقة فقط (تغييرات منظمة، بيئات متعددة، أرشفة معروفة الرقم).
 */
function orange_schema_migrations_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'migrations';
}

function orange_schema_migrations_ensure_table(PDO $pdo): void
{
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS orange_schema_migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(191) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_orange_migration (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * تتبّع الترحيلات الفاشلة لمنع إعادة محاولتها على كل طلب ويب (كان يستنزف اتصالات DB
 * ويغرق السجلّ بآلاف «migration failed» ثم يسبب HTTP 500 «Too many connections»).
 */
function orange_schema_migration_failures_ensure_table(PDO $pdo): void
{
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS orange_schema_migration_failures (
            filename VARCHAR(191) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            last_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * أسماء الملفات التي فشلت خلال فترة التهدئة (لا نعيد محاولتها كل طلب).
 *
 * @return array<string,true>
 */
function orange_schema_migration_recent_failures(PDO $pdo, int $cooldownSeconds = 1800): array
{
    $cooldownSeconds = max(60, $cooldownSeconds);
    try {
        $st = $pdo->query(
            'SELECT filename FROM orange_schema_migration_failures
             WHERE last_attempt_at > (NOW() - INTERVAL ' . $cooldownSeconds . ' SECOND)'
        );
        $out = [];
        foreach (($st ? $st->fetchAll(PDO::FETCH_COLUMN) : []) as $fn) {
            $out[(string) $fn] = true;
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function orange_schema_migration_failure_record(PDO $pdo, string $filename, string $error): void
{
    try {
        $st = $pdo->prepare(
            'INSERT INTO orange_schema_migration_failures (filename, attempts, last_error, last_attempt_at)
             VALUES (?, 1, ?, NOW())
             ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_error = VALUES(last_error), last_attempt_at = NOW()'
        );
        $st->execute([$filename, mb_substr($error, 0, 2000)]);
    } catch (Throwable $e) {
        // تتبّع الفشل ثانوي — لا نُسقط الطلب إن تعذّر التسجيل.
    }

    orange_schema_migration_operational_log(
        'schema_migration_failed',
        'Numbered schema migration failed',
        [
            'filename' => $filename,
            'error' => mb_substr($error, 0, 500),
        ]
    );
}

function orange_schema_migration_failure_clear(PDO $pdo, string $filename): void
{
    try {
        $st = $pdo->prepare('DELETE FROM orange_schema_migration_failures WHERE filename = ?');
        $st->execute([$filename]);
    } catch (Throwable $e) {
        // تجاهل
    }
}

function orange_schema_migration_operational_log(
    string $event,
    string $message,
    array $context = [],
    string $level = 'error'
): void {
    static $loaded = false;
    if (!$loaded) {
        require_once dirname(__DIR__) . '/includes/orange_operational_log.php';
        $loaded = true;
    }
    orange_operational_log($event, $message, $context, $level);
}

/**
 * Read-only migration failure / cooldown status for admin deploy-check and gated health.php.
 *
 * @return array{
 *     cooldown_seconds: int,
 *     has_failures: bool,
 *     failure_count: int,
 *     in_cooldown_count: int,
 *     failures: list<array{
 *         filename: string,
 *         attempts: int,
 *         last_attempt_at: string|null,
 *         in_cooldown: bool,
 *         last_error: string|null
 *     }>
 * }
 */
function orange_schema_migration_operational_status(PDO $pdo, int $cooldownSeconds = 1800): array
{
    $cooldownSeconds = max(60, $cooldownSeconds);
    $status = [
        'cooldown_seconds' => $cooldownSeconds,
        'has_failures' => false,
        'failure_count' => 0,
        'in_cooldown_count' => 0,
        'failures' => [],
    ];

    try {
        if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, 'orange_schema_migration_failures')) {
            return $status;
        }

        $st = $pdo->query(
            'SELECT filename, attempts, last_error, last_attempt_at,
                    (last_attempt_at > (NOW() - INTERVAL ' . (int) $cooldownSeconds . ' SECOND)) AS in_cooldown
             FROM orange_schema_migration_failures
             ORDER BY last_attempt_at DESC
             LIMIT 50'
        );
        if (!$st) {
            return $status;
        }

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $inCooldown = (int) ($row['in_cooldown'] ?? 0) === 1;
            $lastError = isset($row['last_error']) ? trim((string) $row['last_error']) : '';
            if ($lastError !== '') {
                $lastError = mb_substr($lastError, 0, 300);
            } else {
                $lastError = null;
            }
            $status['failures'][] = [
                'filename' => (string) ($row['filename'] ?? ''),
                'attempts' => (int) ($row['attempts'] ?? 0),
                'last_attempt_at' => isset($row['last_attempt_at']) ? (string) $row['last_attempt_at'] : null,
                'in_cooldown' => $inCooldown,
                'last_error' => $lastError,
            ];
            if ($inCooldown) {
                $status['in_cooldown_count']++;
            }
        }

        $status['failure_count'] = count($status['failures']);
        $status['has_failures'] = $status['failure_count'] > 0;
    } catch (Throwable $e) {
        return $status;
    }

    return $status;
}

/**
 * يقسّم ملف SQL إلى جمل منفصلة (سطر ينتهي بـ ؛ يغلق الجملة). لا تضع فاصلة منقوطة داخل نصوص نصية في الترحيلات.
 *
 * @return list<string>
 */
function orange_schema_migration_split_statements(string $sql): array
{
    $lines = preg_split("/\R/", $sql) ?: [];
    $chunks = [];
    $buf = '';
    foreach ($lines as $line) {
        $trimLine = trim($line);
        if ($trimLine === '' || str_starts_with($trimLine, '--')) {
            continue;
        }
        $buf .= ($buf === '' ? '' : "\n") . $line;
        if (str_ends_with(rtrim($line), ';')) {
            $stmt = trim($buf);
            $stmt = rtrim($stmt, " \t\n\r\0\x0B;");
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $chunks[] = $stmt;
            }
            $buf = '';
        }
    }
    $stmt = trim($buf);
    if ($stmt !== '') {
        $chunks[] = $stmt;
    }

    return $chunks;
}

function orange_schema_migration_already_applied(PDO $pdo, string $filename): bool
{
    $st = $pdo->prepare('SELECT 1 FROM orange_schema_migrations WHERE filename = ? LIMIT 1');
    $st->execute([$filename]);

    return (bool) $st->fetchColumn();
}

function orange_schema_run_pending_migrations(PDO $pdo): void
{
    // يُستدعى من عدة مسارات في النواة/المسار السريع/البوابة؛ مرّة واحدة لكل طلب تكفي.
    static $ranThisRequest = false;
    if ($ranThisRequest) {
        return;
    }
    $ranThisRequest = true;

    $dir = orange_schema_migrations_dir();
    if (!is_dir($dir)) {
        return;
    }

    orange_schema_migrations_ensure_table($pdo);
    orange_schema_migration_failures_ensure_table($pdo);

    // على الويب: لا نعيد محاولة ملف فشل خلال فترة التهدئة (يمنع لوب الفشل واستنزاف الاتصالات).
    // على CLI (php scripts/run_migrations.php): نتجاهل التهدئة لإتاحة تشغيل يدوي فوري بعد الإصلاح.
    $isCli = PHP_SAPI === 'cli';
    $recentFailures = $isCli ? [] : orange_schema_migration_recent_failures($pdo);

    $filesUnderscore = glob($dir . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql') ?: [];
    $filesPlain = glob($dir . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9].sql') ?: [];
    $files = array_values(array_unique(array_merge($filesPlain, $filesUnderscore)));
    usort($files, static fn (string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));

    foreach ($files as $fullPath) {
        $base = basename($fullPath);
        if (!preg_match('/^\d{3}(?:_.+)?\.sql$/', $base)) {
            continue;
        }
        if (orange_schema_migration_already_applied($pdo, $base)) {
            continue;
        }
        if (isset($recentFailures[$base])) {
            // فشل مؤخراً — مؤجَّل حتى انقضاء التهدئة (لا تكرار كل طلب).
            static $cooldownSkipLogged = [];
            if (!isset($cooldownSkipLogged[$base])) {
                $cooldownSkipLogged[$base] = true;
                orange_schema_migration_operational_log(
                    'schema_migration_cooldown_skip',
                    'Migration skipped during web cooldown',
                    [
                        'filename' => $base,
                        'cooldown_seconds' => 1800,
                    ],
                    'warn'
                );
            }
            continue;
        }

        $raw = @file_get_contents($fullPath);
        if ($raw === false) {
            if (function_exists('error_log')) {
                error_log('[orange] migration read failed: ' . $base);
            }
            orange_schema_migration_failure_record($pdo, $base, 'read failed');

            continue;
        }

        $statements = orange_schema_migration_split_statements($raw);
        if ($statements === []) {
            try {
                $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
                $ins->execute([$base]);
                orange_schema_migration_failure_clear($pdo, $base);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] migration record empty: ' . $base . ' — ' . $e->getMessage());
                }
            }

            continue;
        }

        try {
            $pdo->beginTransaction();
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
            $ins->execute([$base]);
            $pdo->commit();
            orange_schema_migration_failure_clear($pdo, $base);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('error_log')) {
                error_log('[orange] migration failed ' . $base . ': ' . $e->getMessage());
            }
            orange_schema_migration_failure_record($pdo, $base, $e->getMessage());
        }
    }
}

/**
 * تنفيذ ملف ترحيل مرقّم صارم (001.sql) — جمل متعدّدة عبر orange_schema_migration_split_statements.
 *
 * @throws Throwable
 */
function orange_schema_execute_numbered_file(PDO $pdo, string $fullPath): void
{
    $raw = @file_get_contents($fullPath);
    if ($raw === false) {
        throw new RuntimeException('Cannot read migration: ' . $fullPath);
    }
    $statements = orange_schema_migration_split_statements($raw);
    if ($statements === []) {
        return;
    }
    $pdo->beginTransaction();
    try {
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * سلسلة scripts/migrations/###.sql من (إصدار القاعدة + 1) حتى ORANGE_SCHEMA_CODE_VERSION.
 *
 * - **غير صارم (افتراضي):** يُنفَّذ كل ملف ###.sql الموجود بالترتيب **دون** تحديث orange_schema_meta
 *   بين الخطوات (تفادي مسار سريع خاطئ)؛ ثم تُكمِل orange_catalog_ensure_schema_core المزامنة.
 * - **صارم ORANGE_STRICT_NUMBERED_SQL_MIGRATIONS:** يجب وجود كل الملفات في النطاق؛ يُحدَّث meta بعد كل ملف (كل DDL في SQL).
 */
function orange_schema_run_numbered_sql_chain(PDO $pdo, ?int $knownCurrentMeta): void
{
    orange_schema_meta_ensure_table($pdo);
    $current = $knownCurrentMeta;
    if ($current === null) {
        try {
            $st = $pdo->query('SELECT version FROM orange_schema_meta WHERE id = 1 LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            $current = $row ? (int) ($row['version'] ?? 0) : 0;
        } catch (Throwable $e) {
            $current = 0;
        }
    }
    $target = ORANGE_SCHEMA_CODE_VERSION;
    $strict = defined('ORANGE_STRICT_NUMBERED_SQL_MIGRATIONS') && ORANGE_STRICT_NUMBERED_SQL_MIGRATIONS;

    if ($strict) {
        for ($v = $current + 1; $v <= $target; $v++) {
            $base = sprintf('%03d.sql', $v);
            $path = orange_schema_migrations_dir() . DIRECTORY_SEPARATOR . $base;
            if (!is_file($path)) {
                throw new RuntimeException('Missing migration: ' . $base);
            }
            orange_schema_execute_numbered_file($pdo, $path);
            orange_schema_meta_save($pdo, $v);
        }

        return;
    }

    for ($v = $current + 1; $v <= $target; $v++) {
        $base = sprintf('%03d.sql', $v);
        $path = orange_schema_migrations_dir() . DIRECTORY_SEPARATOR . $base;
        if (!is_file($path)) {
            break;
        }
        orange_schema_execute_numbered_file($pdo, $path);
    }
}
