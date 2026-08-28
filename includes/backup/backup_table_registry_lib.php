<?php

declare(strict_types=1);

/**
 * Country Backup Inventory — registry helpers (Phase 1B.1).
 * Generated JSON: config/backup_table_registry.json
 * Source of truth: scripts/backup/build_table_registry.php
 */

const ORANGE_BACKUP_REGISTRY_VERSION = '1.0';
const ORANGE_BACKUP_REGISTRY_FILENAME = 'backup_table_registry.json';

/** @var list<string> */
const ORANGE_BACKUP_REGISTRY_OWNERSHIP_TYPES = [
    'global',
    'country_owned',
    'dependent',
    'excluded_ephemeral',
];

/** @var list<string> */
const ORANGE_BACKUP_REGISTRY_REQUIRED_TABLE_FIELDS = [
    'ownership_type',
    'export_order',
    'delete_order',
    'restore_order',
    'extraction_rule',
    'parent_dependency',
    'uploads_linked',
    'integrity_critical',
];

function orange_backup_registry_path(?string $projectRoot = null): string
{
    $projectRoot = $projectRoot ?? orange_backup_project_root();

    return $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . ORANGE_BACKUP_REGISTRY_FILENAME;
}

/**
 * @param array<string, mixed> $extractionRule
 * @param array{table:string,foreign_key:string,nullable?:bool}|null $parentDependency
 * @return array<string, mixed>
 */
function orange_backup_registry_row(
    string $ownershipType,
    int $exportOrder,
    array $extractionRule,
    ?array $parentDependency = null,
    bool $uploadsLinked = false,
    bool $integrityCritical = false,
    ?int $deleteOrder = null,
    ?int $restoreOrder = null
): array {
    if (!in_array($ownershipType, ORANGE_BACKUP_REGISTRY_OWNERSHIP_TYPES, true)) {
        throw new InvalidArgumentException('Invalid ownership_type: ' . $ownershipType);
    }

    $deleteOrder ??= orange_backup_registry_default_delete_order($ownershipType, $exportOrder);
    $restoreOrder ??= $exportOrder;

    return [
        'ownership_type' => $ownershipType,
        'export_order' => $exportOrder,
        'delete_order' => $deleteOrder,
        'restore_order' => $restoreOrder,
        'extraction_rule' => $extractionRule,
        'parent_dependency' => $parentDependency,
        'uploads_linked' => $uploadsLinked,
        'integrity_critical' => $integrityCritical,
    ];
}

function orange_backup_registry_default_delete_order(string $ownershipType, int $exportOrder): int
{
    return match ($ownershipType) {
        'dependent' => $exportOrder,
        'country_owned' => $exportOrder + 400,
        'global' => $exportOrder + 800,
        'excluded_ephemeral' => 9999,
        default => $exportOrder,
    };
}

function orange_backup_registry_full_table(): array
{
    return ['type' => 'full_table'];
}

function orange_backup_registry_country_id(string $column = 'country_id'): array
{
    return ['type' => 'country_id', 'column' => $column];
}

function orange_backup_registry_country_scope_or(array $columns): array
{
    return ['type' => 'country_scope_or', 'columns' => array_values($columns)];
}

function orange_backup_registry_parent_rows(string $parentTable, string $foreignKey): array
{
    return [
        'type' => 'parent_rows',
        'parent_table' => $parentTable,
        'foreign_key' => $foreignKey,
    ];
}

/**
 * @param array{table:string,foreign_key:string,nullable?:bool} $parentDependency
 */
function orange_backup_registry_dependent(
    string $parentTable,
    string $foreignKey,
    int $exportOrder,
    bool $integrityCritical = false,
    bool $uploadsLinked = false,
    ?int $deleteOrder = null,
    bool $nullableFk = false
): array {
    return orange_backup_registry_row(
        'dependent',
        $exportOrder,
        orange_backup_registry_parent_rows($parentTable, $foreignKey),
        [
            'table' => $parentTable,
            'foreign_key' => $foreignKey,
            'nullable' => $nullableFk,
        ],
        $uploadsLinked,
        $integrityCritical,
        $deleteOrder
    );
}

function orange_backup_registry_skip(): array
{
    return ['type' => 'skip'];
}

/**
 * @return list<string>
 */
function orange_backup_registry_parse_sql_dump_tables(string $dumpPath): array
{
    if (!is_file($dumpPath)) {
        throw new RuntimeException('SQL dump not found: ' . $dumpPath);
    }
    $handle = fopen($dumpPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot read SQL dump: ' . $dumpPath);
    }

    $names = [];
    try {
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/i', $line, $match)) {
                $names[] = $match[1];
            }
        }
    } finally {
        fclose($handle);
    }

    if ($names === []) {
        return [];
    }

    $names = array_values(array_unique($names));
    sort($names, SORT_STRING);

    return $names;
}

/**
 * @return list<string>
 */
function orange_backup_registry_live_tables(PDO $pdo, string $databaseName): array
{
    $stmt = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\'
         ORDER BY TABLE_NAME'
    );
    $stmt->execute([$databaseName]);
    /** @var list<string> $names */
    $names = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name = trim((string) ($row['TABLE_NAME'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * @param array<string, mixed> $registry
 * @return list<string>
 */
function orange_backup_registry_validate_structure(array $registry, int $expectedSchemaRevision): array
{
    $errors = [];

    if (($registry['registry_version'] ?? '') !== ORANGE_BACKUP_REGISTRY_VERSION) {
        $errors[] = 'registry_version mismatch (expected ' . ORANGE_BACKUP_REGISTRY_VERSION . ')';
    }
    if ((int) ($registry['schema_revision'] ?? 0) !== $expectedSchemaRevision) {
        $errors[] = 'schema_revision mismatch (expected ' . $expectedSchemaRevision . ', got ' . (int) ($registry['schema_revision'] ?? 0) . ')';
    }
    if (!isset($registry['tables']) || !is_array($registry['tables'])) {
        $errors[] = 'tables object missing';
        return $errors;
    }

    /** @var array<string, array<string, mixed>> $tables */
    $tables = $registry['tables'];
    foreach ($tables as $tableName => $meta) {
        if (!is_string($tableName) || $tableName === '') {
            $errors[] = 'invalid table key';
            continue;
        }
        if (!is_array($meta)) {
            $errors[] = $tableName . ': metadata must be an object';
            continue;
        }
        foreach (ORANGE_BACKUP_REGISTRY_REQUIRED_TABLE_FIELDS as $field) {
            if (!array_key_exists($field, $meta)) {
                $errors[] = $tableName . ': missing field ' . $field;
            }
        }
        if (!in_array((string) ($meta['ownership_type'] ?? ''), ORANGE_BACKUP_REGISTRY_OWNERSHIP_TYPES, true)) {
            $errors[] = $tableName . ': invalid ownership_type';
        }
        foreach (['export_order', 'delete_order', 'restore_order'] as $orderField) {
            if (!is_int($meta[$orderField] ?? null)) {
                $errors[] = $tableName . ': ' . $orderField . ' must be integer';
            }
        }
        foreach (['uploads_linked', 'integrity_critical'] as $boolField) {
            if (!is_bool($meta[$boolField] ?? null)) {
                $errors[] = $tableName . ': ' . $boolField . ' must be boolean';
            }
        }
        $extraction = $meta['extraction_rule'] ?? null;
        if (!is_array($extraction) || !isset($extraction['type']) || !is_string($extraction['type'])) {
            $errors[] = $tableName . ': extraction_rule.type required';
        }
        $parent = $meta['parent_dependency'] ?? null;
        if ($parent !== null && !is_array($parent)) {
            $errors[] = $tableName . ': parent_dependency must be object or null';
        }
    }

    return $errors;
}

/**
 * @param array<string, array<string, mixed>> $registryTables
 * @param list<string> $liveTables
 * @return list<string>
 */
function orange_backup_registry_validate_coverage(array $registryTables, array $liveTables): array
{
    $errors = [];
    $registryNames = array_keys($registryTables);
    sort($registryNames, SORT_STRING);
    sort($liveTables, SORT_STRING);

    $missing = array_values(array_diff($liveTables, $registryNames));
    foreach ($missing as $tableName) {
        $errors[] = 'missing registry entry for live table: ' . $tableName;
    }

    $unknown = array_values(array_diff($registryNames, $liveTables));
    foreach ($unknown as $tableName) {
        $errors[] = 'unknown registry table (not in live schema): ' . $tableName;
    }

    return $errors;
}

/**
 * @param array<string, array<string, mixed>> $tables
 * @return array<string, int>
 */
function orange_backup_registry_summarize(array $tables): array
{
    $summary = array_fill_keys(ORANGE_BACKUP_REGISTRY_OWNERSHIP_TYPES, 0);
    foreach ($tables as $meta) {
        $type = (string) ($meta['ownership_type'] ?? '');
        if (isset($summary[$type])) {
            $summary[$type]++;
        }
    }

    return $summary;
}

/**
 * @return array{
 *   registry_version:string,
 *   schema_revision:int,
 *   tables:array<string, array<string, mixed>>,
 *   table_count?:int,
 *   ownership_summary?:array<string,int>
 * }
 */
function orange_backup_registry_load(?string $projectRoot = null): array
{
    $path = orange_backup_registry_path($projectRoot);
    if (!is_file($path)) {
        throw new RuntimeException('Missing backup table registry: ' . $path);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Cannot read backup table registry.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['tables']) || !is_array($data['tables'])) {
        throw new RuntimeException('Invalid backup table registry JSON.');
    }

    return $data;
}

/**
 * @return list<array{table:string,meta:array<string,mixed>}>
 */
function orange_backup_registry_exportable_tables(array $registry): array
{
    /** @var array<string, array<string, mixed>> $tables */
    $tables = $registry['tables'];
    $exportable = [];
    foreach ($tables as $tableName => $meta) {
        $type = (string) ($meta['ownership_type'] ?? '');
        if ($type === 'country_owned' || $type === 'dependent') {
            $exportable[] = ['table' => $tableName, 'meta' => $meta];
        }
    }
    usort($exportable, static function (array $a, array $b): int {
        $ao = (int) ($a['meta']['export_order'] ?? 0);
        $bo = (int) ($b['meta']['export_order'] ?? 0);
        if ($ao === $bo) {
            return strcmp($a['table'], $b['table']);
        }

        return $ao <=> $bo;
    });

    return $exportable;
}
