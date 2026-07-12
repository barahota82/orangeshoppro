<?php

declare(strict_types=1);

/**
 * Build authoritative Country Backup Inventory registry (Phase 1B.1).
 *
 * Writes config/backup_table_registry.json — generated only; never hand-edit.
 *
 * Usage:
 *   php scripts/backup/build_table_registry.php
 *   php scripts/backup/build_table_registry.php --verify-dump
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_table_registry_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_table_registry_definitions.php';

$verifyDump = in_array('--verify-dump', $_SERVER['argv'] ?? [], true);
$dumpPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'orange_db.sql';

try {
    $tables = orange_backup_registry_table_definitions();
    $tableCount = count($tables);
    if ($tableCount === 0) {
        throw new RuntimeException('No table definitions found.');
    }

    $structureErrors = orange_backup_registry_validate_structure(
        [
            'registry_version' => ORANGE_BACKUP_REGISTRY_VERSION,
            'schema_revision' => ORANGE_CATALOG_SCHEMA_PHP_REVISION,
            'tables' => $tables,
        ],
        ORANGE_CATALOG_SCHEMA_PHP_REVISION
    );
    if ($structureErrors !== []) {
        throw new RuntimeException("Registry definition invalid:\n- " . implode("\n- ", $structureErrors));
    }

    if ($verifyDump && is_file($dumpPath)) {
        $dumpTables = orange_backup_registry_parse_sql_dump_tables($dumpPath);
        $coverageErrors = orange_backup_registry_validate_coverage($tables, $dumpTables);
        if ($coverageErrors !== []) {
            throw new RuntimeException("Dump coverage mismatch:\n- " . implode("\n- ", $coverageErrors));
        }
        fwrite(STDOUT, 'Dump coverage OK (' . count($dumpTables) . " tables)\n");
    } elseif ($verifyDump) {
        fwrite(STDERR, "Warning: --verify-dump requested but dump missing at {$dumpPath}\n");
    }

    $summary = orange_backup_registry_summarize($tables);
    $payload = [
        'registry_version' => ORANGE_BACKUP_REGISTRY_VERSION,
        'schema_revision' => ORANGE_CATALOG_SCHEMA_PHP_REVISION,
        'generated_at' => gmdate('c'),
        'generated_by' => 'scripts/backup/build_table_registry.php',
        'do_not_edit' => 'Generated only — regenerate with build_table_registry.php',
        'table_count' => $tableCount,
        'ownership_summary' => $summary,
        'tables' => $tables,
    ];

    $configDir = $projectRoot . DIRECTORY_SEPARATOR . 'config';
    if (!is_dir($configDir) && !@mkdir($configDir, 0775, true) && !is_dir($configDir)) {
        throw new RuntimeException('Cannot create config directory: ' . $configDir);
    }

    $outPath = orange_backup_registry_path($projectRoot);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed.');
    }
    $json .= "\n";
    if (file_put_contents($outPath, $json) === false) {
        throw new RuntimeException('Cannot write registry: ' . $outPath);
    }

    fwrite(STDOUT, "Wrote {$outPath}\n");
    fwrite(STDOUT, 'table_count=' . $tableCount . "\n");
    foreach ($summary as $type => $count) {
        fwrite(STDOUT, $type . '=' . $count . "\n");
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'build_table_registry failed: ' . $e->getMessage() . "\n");
    exit(1);
}
