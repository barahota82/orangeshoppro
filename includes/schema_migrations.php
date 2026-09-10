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

/**
 * Numbered SQL is a maintenance-only surface. PHP_SAPI is deliberately not
 * sufficient because scheduled backup/finalizer processes are CLI too.
 */
function orange_schema_numbered_sql_apply_allowed(): bool
{
    return defined('ORANGE_NUMBERED_SQL_MAINTENANCE_CONTEXT')
        && ORANGE_NUMBERED_SQL_MAINTENANCE_CONTEXT === true
        && defined('ORANGE_NUMBERED_SQL_APPLY_OPT_IN')
        && ORANGE_NUMBERED_SQL_APPLY_OPT_IN === true;
}

/**
 * Per-connection execution cache; never lets one PDO identity suppress another.
 *
 * @return WeakMap<PDO,bool>
 */
function orange_schema_numbered_sql_connection_cache(): WeakMap
{
    static $cache = null;
    if (!$cache instanceof WeakMap) {
        $cache = new WeakMap();
    }

    return $cache;
}

function orange_schema_migration_bounded_text(string $text, int $maxLength): string
{
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $maxLength)
        : substr($text, 0, $maxLength);
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

function orange_schema_migration_failure_record(PDO $pdo, string $filename, array $diagnostic): void
{
    $encoded = json_encode(
        $diagnostic,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $safeError = is_string($encoded)
        ? orange_schema_migration_bounded_text($encoded, 2000)
        : '{"message":"migration_failed"}';
    try {
        $st = $pdo->prepare(
            'INSERT INTO orange_schema_migration_failures (filename, attempts, last_error, last_attempt_at)
             VALUES (?, 1, ?, NOW())
             ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_error = VALUES(last_error), last_attempt_at = NOW()'
        );
        $st->execute([$filename, $safeError]);
    } catch (Throwable $e) {
        // تتبّع الفشل ثانوي — لا نُسقط الطلب إن تعذّر التسجيل.
    }

    orange_schema_migration_operational_log(
        'schema_migration_failed',
        'Numbered schema migration failed',
        $diagnostic
    );
}

/**
 * @return array{sqlstate:string|null,errno:int|null,message:string}
 */
function orange_schema_migration_sanitize_exception(Throwable $error): array
{
    $sqlState = null;
    $errno = null;
    if ($error instanceof PDOException && is_array($error->errorInfo ?? null)) {
        $candidateState = strtoupper(trim((string) ($error->errorInfo[0] ?? '')));
        if (preg_match('/^[0-9A-Z]{5}$/', $candidateState) === 1) {
            $sqlState = $candidateState;
        }
        $candidateErrno = $error->errorInfo[1] ?? null;
        if (is_int($candidateErrno) || (is_string($candidateErrno) && ctype_digit($candidateErrno))) {
            $errno = (int) $candidateErrno;
        }
    }
    if ($sqlState === null) {
        $message = $error->getMessage();
        if (preg_match('/\bSQLSTATE\[([0-9A-Z]{5})\]/i', $message, $match) === 1) {
            $sqlState = strtoupper($match[1]);
        }
    }
    if ($errno === null) {
        $message = $error->getMessage();
        if (preg_match('/(?:native\s+error|errno|SQLSTATE\[[^\]]+\]\s*:?\s*)\D{0,8}(\d{3,6})\b/i', $message, $match) === 1) {
            $errno = (int) $match[1];
        }
    }

    $parts = ['Database statement failed'];
    if ($sqlState !== null) {
        $parts[] = 'SQLSTATE ' . $sqlState;
    }
    if ($errno !== null) {
        $parts[] = 'errno ' . (string) $errno;
    }

    return [
        'sqlstate' => $sqlState,
        'errno' => $errno,
        'message' => orange_schema_migration_bounded_text(implode('; ', $parts), 160),
    ];
}

/**
 * @return array{operation:string,table:string|null}
 */
function orange_schema_migration_infer_statement(string $sql): array
{
    $operation = 'UNKNOWN';
    $table = null;
    $patterns = [
        '/^\s*(CREATE)\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
        '/^\s*(ALTER)\s+TABLE\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
        '/^\s*(INSERT)\s+INTO\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
        '/^\s*(REPLACE)\s+INTO\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
        '/^\s*(UPDATE)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
        '/^\s*(DELETE)\s+FROM\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
        '/^\s*(DROP)\s+TABLE(?:\s+IF\s+EXISTS)?\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
        '/^\s*(TRUNCATE)\s+(?:TABLE\s+)?[`"]?([a-zA-Z0-9_]+)[`"]?/i',
        '/^\s*(?:CREATE|DROP)\s+(INDEX)\s+[`"]?[a-zA-Z0-9_]+[`"]?\s+ON\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $sql, $match) === 1) {
            $operation = strtoupper($match[1]);
            $table = strtolower($match[2]);
            break;
        }
    }

    return ['operation' => $operation, 'table' => $table];
}

/**
 * The diagnostic contains structure only: never exception literals or SQL text.
 *
 * @return array<string,mixed>
 */
function orange_schema_migration_statement_diagnostic(
    string $filename,
    int $ordinal,
    string $statement,
    Throwable $error
): array {
    $shape = orange_schema_migration_infer_statement($statement);
    $safeError = orange_schema_migration_sanitize_exception($error);

    return [
        'filename' => basename($filename),
        'statement_ordinal' => max(1, $ordinal),
        'sha256' => hash('sha256', $statement),
        'operation' => $shape['operation'],
        'table' => $shape['table'],
        'sqlstate' => $safeError['sqlstate'],
        'errno' => $safeError['errno'],
        'message' => $safeError['message'],
    ];
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
                $lastError = orange_schema_migration_bounded_text($lastError, 300);
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
 * يقسّم SQL مع BOM وأنواع الأسطر المختلفة، ويتجاهل الفواصل المنقوطة
 * داخل النصوص/المعرّفات المقتبسة والتعليقات.
 *
 * @return list<string>
 */
function orange_schema_migration_split_statements(string $sql): array
{
    if (str_starts_with($sql, "\xEF\xBB\xBF")) {
        $sql = substr($sql, 3);
    }
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $statements = [];
    $buf = '';
    $quote = null;
    $lineComment = false;
    $blockComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
                $buf .= "\n";
            }
            continue;
        }
        if ($blockComment) {
            if ($char === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            } elseif ($char === "\n") {
                $buf .= "\n";
            }
            continue;
        }

        if ($quote !== null) {
            $buf .= $char;
            if ($char === '\\' && $next !== '') {
                $buf .= $next;
                $i++;
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $buf .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) {
            $lineComment = true;
            $i++;
            continue;
        }
        if ($char === '#') {
            $lineComment = true;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $blockComment = true;
            $i++;
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buf .= $char;
            continue;
        }
        if ($char === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buf = '';
            continue;
        }
        $buf .= $char;
    }

    $stmt = trim($buf);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

function orange_schema_migration_already_applied(PDO $pdo, string $filename): bool
{
    $st = $pdo->prepare('SELECT 1 FROM orange_schema_migrations WHERE filename = ? LIMIT 1');
    $st->execute([$filename]);

    return (bool) $st->fetchColumn();
}

function orange_schema_run_pending_migrations(PDO $pdo): void
{
    if (!orange_schema_numbered_sql_apply_allowed()) {
        return;
    }
    $cache = orange_schema_numbered_sql_connection_cache();
    if (isset($cache[$pdo])) {
        return;
    }

    $dir = orange_schema_migrations_dir();
    if (!is_dir($dir)) {
        return;
    }

    orange_schema_migrations_ensure_table($pdo);
    orange_schema_migration_failures_ensure_table($pdo);

    // التهدئة مستقلة عن SAPI؛ حتى الصيانة الصريحة لا تعيد قصف ملف فاشل.
    $recentFailures = orange_schema_migration_recent_failures($pdo);

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
            throw new RuntimeException('Numbered SQL migration is in failure cooldown: ' . $base);
        }

        $raw = @file_get_contents($fullPath);
        if ($raw === false) {
            if (function_exists('error_log')) {
                error_log('[orange] migration read failed: ' . $base);
            }
            $readError = new RuntimeException('Migration file could not be read');
            orange_schema_migration_failure_record(
                $pdo,
                $base,
                orange_schema_migration_statement_diagnostic($base, 1, '', $readError)
            );

            throw new RuntimeException('Cannot read numbered SQL migration: ' . $base);
        }

        $statements = orange_schema_migration_split_statements($raw);
        if ($statements === []) {
            try {
                $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
                $ins->execute([$base]);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] migration record empty failed: ' . $base);
                }
                throw new RuntimeException('Could not record empty numbered SQL migration: ' . $base, 0, $e);
            }

            continue;
        }

        try {
            $pdo->beginTransaction();
            $ordinal = 0;
            foreach ($statements as $sql) {
                $ordinal++;
                $pdo->exec($sql);
            }
            $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
            $ins->execute([$base]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $ordinal = max(1, (int) ($ordinal ?? 1));
            $failedStatement = isset($sql) && is_string($sql) ? $sql : '';
            $diagnostic = orange_schema_migration_statement_diagnostic($base, $ordinal, $failedStatement, $e);
            if (function_exists('error_log')) {
                error_log(
                    '[orange] migration failed '
                    . $diagnostic['filename']
                    . ' statement=' . (string) $diagnostic['statement_ordinal']
                    . ' sha256=' . $diagnostic['sha256']
                    . ' operation=' . $diagnostic['operation']
                    . ' table=' . (string) ($diagnostic['table'] ?? '-')
                    . ' sqlstate=' . (string) ($diagnostic['sqlstate'] ?? '-')
                    . ' errno=' . (string) ($diagnostic['errno'] ?? '-')
                );
            }
            orange_schema_migration_failure_record($pdo, $base, $diagnostic);
            throw new RuntimeException(
                'Numbered SQL migration failed: '
                . $base
                . ' statement=' . (string) $diagnostic['statement_ordinal']
                . ' sha256=' . (string) $diagnostic['sha256'],
                0,
                $e
            );
        }
    }

    $cache[$pdo] = true;
}

/**
 * تنفيذ ملف ترحيل مرقّم صارم (001.sql) — جمل متعدّدة عبر orange_schema_migration_split_statements.
 *
 * @throws Throwable
 */
function orange_schema_execute_numbered_file(PDO $pdo, string $fullPath): void
{
    if (!orange_schema_numbered_sql_apply_allowed()) {
        throw new LogicException('Numbered SQL requires explicit maintenance context and apply opt-in');
    }
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
    if (!orange_schema_numbered_sql_apply_allowed()) {
        return;
    }
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
