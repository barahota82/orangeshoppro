<?php

declare(strict_types=1);

/**
 * Validate Country Backup Inventory registry against live schema (Phase 1B.1).
 *
 * Usage:
 *   php scripts/backup/validate_registry.php
 *   php scripts/backup/validate_registry.php --offline
 *   php scripts/backup/validate_registry.php --registry=path/to/backup_table_registry.json
 *
 * Exit 0 when valid; non-zero when validation fails.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_table_registry_lib.php';

$offline = in_array('--offline', $_SERVER['argv'] ?? [], true);
$registryPath = orange_backup_registry_path($projectRoot);
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--registry=')) {
        $registryPath = substr($arg, strlen('--registry='));
    }
}

$dumpPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'orange_db.sql';
$errors = [];

try {
    if (!is_file($registryPath)) {
        throw new RuntimeException('Registry file missing: ' . $registryPath);
    }
    $raw = file_get_contents($registryPath);
    if ($raw === false) {
        throw new RuntimeException('Cannot read registry: ' . $registryPath);
    }
    $registry = json_decode($raw, true);
    if (!is_array($registry)) {
        throw new RuntimeException('Invalid registry JSON.');
    }

    $errors = array_merge(
        $errors,
        orange_backup_registry_validate_structure($registry, ORANGE_CATALOG_SCHEMA_PHP_REVISION)
    );

    /** @var array<string, array<string, mixed>> $registryTables */
    $registryTables = is_array($registry['tables'] ?? null) ? $registry['tables'] : [];
    if ((int) ($registry['table_count'] ?? 0) !== count($registryTables)) {
        $errors[] = 'table_count mismatch (header=' . (int) ($registry['table_count'] ?? 0) . ', actual=' . count($registryTables) . ')';
    }

    if ($offline) {
        if (!is_file($dumpPath)) {
            $errors[] = 'offline mode requires scripts/orange_db.sql';
        } else {
            $liveTables = orange_backup_registry_parse_sql_dump_tables($dumpPath);
            $errors = array_merge($errors, orange_backup_registry_validate_coverage($registryTables, $liveTables));
        }
    } else {
        $pdo = db();
        orange_catalog_ensure_schema($pdo);
        $dbName = (string) ($registry['database_name'] ?? DB_NAME);
        $liveTables = orange_backup_registry_live_tables($pdo, $dbName);
        $errors = array_merge($errors, orange_backup_registry_validate_coverage($registryTables, $liveTables));

        $dbRevision = 0;
        try {
            $stmt = $pdo->query('SELECT version FROM orange_schema_meta WHERE id = 1 LIMIT 1');
            if ($stmt !== false) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $dbRevision = (int) ($row['version'] ?? 0);
            }
        } catch (Throwable) {
            $dbRevision = 0;
        }
        if ($dbRevision > 0 && $dbRevision !== ORANGE_CATALOG_SCHEMA_PHP_REVISION) {
            $errors[] = 'live DB schema_revision mismatch (db=' . $dbRevision . ', code=' . ORANGE_CATALOG_SCHEMA_PHP_REVISION . ')';
        }
    }

    if ($errors !== []) {
        fwrite(STDERR, "validate_registry FAILED (" . count($errors) . " error(s)):\n");
        foreach ($errors as $error) {
            fwrite(STDERR, '- ' . $error . "\n");
        }
        exit(1);
    }

    $summary = orange_backup_registry_summarize($registryTables);
    fwrite(STDOUT, "validate_registry OK\n");
    fwrite(STDOUT, 'registry_version=' . (string) ($registry['registry_version'] ?? '') . "\n");
    fwrite(STDOUT, 'schema_revision=' . (string) ($registry['schema_revision'] ?? '') . "\n");
    fwrite(STDOUT, 'table_count=' . count($registryTables) . "\n");
    foreach ($summary as $type => $count) {
        fwrite(STDOUT, $type . '=' . $count . "\n");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'validate_registry failed: ' . $e->getMessage() . "\n");
    exit(1);
}
