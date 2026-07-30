<?php

declare(strict_types=1);

/**
 * FSR D5 — build Expectations drift matrix vs a disposable Schema-124 DB.
 * Test/tool only. Drops only the DB it creates.
 *
 * Usage: php scripts/lib/final_review_d5_expectations_drift.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/scripts/lib/final_review_d1_fixture.php';
require_once $root . '/scripts/lib/final_review_d5_runtime.php';
require_once $root . '/includes/backup/restore/restore_country_shadow_final_hardening.php';

$probe = orange_d1_mysql_probe();
if (empty($probe['ok'])) {
    fwrite(STDERR, "MySQL unavailable\n");
    exit(2);
}

$db = 'orange_d5_exp_' . getmypid() . '_' . bin2hex(random_bytes(3));
if (!preg_match('/^orange_d5_exp_[a-zA-Z0-9_]+$/', $db)) {
    fwrite(STDERR, "Invalid DB name\n");
    exit(2);
}

$admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$created = false;
$mediaTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $db . '_media';
@mkdir($mediaTmp, 0775, true);
try {
    $admin->exec(
        'CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $created = true;

    $boot = orange_d5_import_schema_and_seed($root, $db, $mediaTmp);
    if (empty($boot['ok'])) {
        throw new RuntimeException((string) ($boot['error'] ?? 'seed failed'));
    }
    /** @var PDO $pdo */
    $pdo = $boot['pdo'];

    $rev = defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') ? (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION : 0;
    $meta = 0;
    try {
        if (orange_table_exists($pdo, 'orange_schema_meta')) {
            $meta = (int) $pdo->query('SELECT `version` FROM orange_schema_meta WHERE id = 1 LIMIT 1')->fetchColumn();
        }
    } catch (Throwable) {
        $meta = 0;
    }

    $exp = orange_country_shadow_schema_expectations_load($root);
    $tables = is_array($exp['tables'] ?? null) ? $exp['tables'] : [];
    $core = is_array($exp['core_tables'] ?? null) ? $exp['core_tables'] : array_keys($tables);

    $classTotals = [
        'EXACT_MATCH' => 0,
        'STALE_EXPECTED_COLUMN' => 0,
        'MISSING_EXPECTED_COLUMN' => 0,
        'ACTUAL_REQUIRED_COLUMN_NOT_EXPECTED' => 0,
        'INDEX_DRIFT' => 0,
        'PRIMARY_KEY_DRIFT' => 0,
        'TABLE_MISSING' => 0,
        'EXPECTATIONS_SCOPE_OK' => 0,
    ];
    $rows = [];

    foreach ($core as $table) {
        $table = (string) $table;
        $metaT = is_array($tables[$table] ?? null) ? $tables[$table] : [];
        $reqCols = array_map('strval', is_array($metaT['required_columns'] ?? null) ? $metaT['required_columns'] : []);
        $reqIdx = is_array($metaT['required_indexes'] ?? null) ? $metaT['required_indexes'] : [];

        if (!orange_table_exists($pdo, $table)) {
            $classTotals['TABLE_MISSING']++;
            $rows[] = ['table' => $table, 'class' => 'TABLE_MISSING', 'detail' => 'absent after seal'];
            continue;
        }

        $st = $pdo->prepare(
            'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $st->execute([$db, $table]);
        $actualCols = [];
        $pkCols = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
            $name = (string) $c['COLUMN_NAME'];
            $actualCols[$name] = $c;
            if ((string) $c['COLUMN_KEY'] === 'PRI') {
                $pkCols[] = $name;
            }
        }

        $stale = [];
        $okCols = [];
        foreach ($reqCols as $col) {
            if (!isset($actualCols[$col])) {
                $stale[] = $col;
                $classTotals['STALE_EXPECTED_COLUMN']++;
                $rows[] = [
                    'table' => $table,
                    'class' => 'STALE_EXPECTED_COLUMN',
                    'detail' => $col,
                    'actual_columns' => array_keys($actualCols),
                    'actual_pk' => $pkCols,
                ];
            } else {
                $okCols[] = $col;
            }
        }

        // Index covering check (same soft rule as Production validator).
        foreach ($reqIdx as $idx) {
            if (!is_array($idx)) {
                continue;
            }
            $cols = array_map('strval', is_array($idx['columns'] ?? null) ? $idx['columns'] : []);
            if ($cols === []) {
                continue;
            }
            if (!orange_country_shadow_mysql_has_index_covering($pdo, $table, $cols)) {
                if (orange_country_shadow_mysql_table_index_count($pdo, $table) === 0) {
                    continue;
                }
                $classTotals['INDEX_DRIFT']++;
                $rows[] = [
                    'table' => $table,
                    'class' => 'INDEX_DRIFT',
                    'detail' => implode(',', $cols),
                ];
            }
        }

        if ($stale === [] && $okCols === $reqCols) {
            $classTotals['EXACT_MATCH']++;
            $rows[] = [
                'table' => $table,
                'class' => 'EXACT_MATCH',
                'detail' => 'required_columns present',
                'required_columns' => $reqCols,
                'actual_pk' => $pkCols,
            ];
        }

        // Suggest canonical required columns for known stale tables (report only).
        if (in_array($table, ['inventory_cost_layers', 'inventory_cost_consumptions', 'document_sequences', 'admin_permissions'], true)) {
            $rows[] = [
                'table' => $table,
                'class' => 'CANONICAL_HINT',
                'actual_columns' => array_keys($actualCols),
                'actual_pk' => $pkCols,
            ];
        }
    }

    $classTotals['EXPECTATIONS_SCOPE_OK'] = count($core);

    $out = [
        'ok' => true,
        'db' => $db,
        'php_revision' => $rev,
        'meta_revision' => $meta,
        'expectations_revision' => (int) ($exp['schema_revision'] ?? 0),
        'core_table_count' => count($core),
        'class_totals' => $classTotals,
        'rows' => $rows,
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERR ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($created) {
        try {
            $admin->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        } catch (Throwable) {
        }
    }
    if (is_dir($mediaTmp)) {
        // Best-effort recursive delete of disposable media root only.
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($mediaTmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($mediaTmp);
    }
}
