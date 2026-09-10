<?php

declare(strict_types=1);

/**
 * مسار الصيانة الصريح الوحيد لتطبيق scripts/migrations/*.sql.
 *
 * المعاينة الآمنة (لا DB/DDL/DML): php scripts/run_migrations.php
 * التطبيق الصريح:                 php scripts/run_migrations.php --apply
 */
function orange_run_migrations_cli_has_apply_opt_in(array $arguments): bool
{
    return in_array('--apply', $arguments, true);
}

/**
 * @return list<string>
 */
function orange_run_migrations_cli_candidate_files(string $root): array
{
    $dir = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'migrations';
    $files = array_merge(
        glob($dir . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9].sql') ?: [],
        glob($dir . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql') ?: []
    );
    $names = [];
    foreach (array_unique($files) as $file) {
        $base = basename($file);
        if (preg_match('/^\d{3}(?:_.+)?\.sql$/', $base) === 1) {
            $names[] = $base;
        }
    }
    natcasesort($names);

    return array_values($names);
}

/**
 * @param callable|null $applyRunner Test seam; production leaves it null.
 */
function orange_run_migrations_cli_main(array $arguments, ?callable $applyRunner = null): int
{
    $root = dirname(__DIR__);
    if (!orange_run_migrations_cli_has_apply_opt_in($arguments)) {
        $files = orange_run_migrations_cli_candidate_files($root);
        fwrite(STDERR, "REFUSED: numbered SQL was not applied; explicit --apply is required.\n");
        fwrite(STDOUT, 'CANDIDATE_FILES=' . count($files) . PHP_EOL);
        foreach ($files as $file) {
            fwrite(STDOUT, 'PENDING_OR_APPLIED_UNKNOWN ' . $file . PHP_EOL);
        }

        return 2;
    }

    if (!defined('ORANGE_NUMBERED_SQL_MAINTENANCE_CONTEXT')) {
        define('ORANGE_NUMBERED_SQL_MAINTENANCE_CONTEXT', true);
    }
    if (!defined('ORANGE_NUMBERED_SQL_APPLY_OPT_IN')) {
        define('ORANGE_NUMBERED_SQL_APPLY_OPT_IN', true);
    }

    try {
        if ($applyRunner !== null) {
            $applyRunner();
        } else {
            require_once $root . '/config.php';
            require_once $root . '/includes/schema_manager.php';
            $pdo = db();
            orange_run_migrations($pdo);
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'Migration failed; inspect sanitized migration diagnostics.' . PHP_EOL);

        return 1;
    }

    if (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') && defined('ORANGE_SCHEMA_CODE_VERSION')) {
        echo 'orange_run_migrations OK (php_revision=' . (string) ORANGE_CATALOG_SCHEMA_PHP_REVISION
            . ', schema_code_version=' . (string) ORANGE_SCHEMA_CODE_VERSION . ')' . PHP_EOL;
    } else {
        echo "orange_run_migrations APPLY path reached\n";
    }

    return 0;
}

if (!defined('ORANGE_RUN_MIGRATIONS_TEST_LIBRARY')) {
    if (PHP_SAPI !== 'cli') {
        header('HTTP/1.1 403 Forbidden');
        echo 'CLI only';
        exit(1);
    }
    exit(orange_run_migrations_cli_main($_SERVER['argv'] ?? []));
}
