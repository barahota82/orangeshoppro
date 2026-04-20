<?php

declare(strict_types=1);

/**
 * ترحيلات مخطط مرقّمة: scripts/migrations/NNN_وصف.sql
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
    $dir = orange_schema_migrations_dir();
    if (!is_dir($dir)) {
        return;
    }

    orange_schema_migrations_ensure_table($pdo);

    $files = glob($dir . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql') ?: [];
    usort($files, static fn (string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));

    foreach ($files as $fullPath) {
        $base = basename($fullPath);
        if (!preg_match('/^\d{3}_.+\.sql$/', $base)) {
            continue;
        }
        if (orange_schema_migration_already_applied($pdo, $base)) {
            continue;
        }

        $raw = @file_get_contents($fullPath);
        if ($raw === false) {
            if (function_exists('error_log')) {
                error_log('[orange] migration read failed: ' . $base);
            }

            continue;
        }

        $statements = orange_schema_migration_split_statements($raw);
        if ($statements === []) {
            try {
                $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
                $ins->execute([$base]);
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
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('error_log')) {
                error_log('[orange] migration failed ' . $base . ': ' . $e->getMessage());
            }
        }
    }
}
