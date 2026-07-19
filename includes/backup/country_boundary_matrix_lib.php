<?php

declare(strict_types=1);

/**
 * Frozen Country Restore boundary matrix loader (Phase C3).
 *
 * Source of truth: config/country_restore_boundary_matrix.json
 * Derived from COUNTRY_RESTORE_BOUNDARY_POLICY.md + COUNTRY_BOUNDARY_VALIDATION.md
 * + COUNTRY_DEPENDENCY_GRAPH.md (not C0).
 */

const ORANGE_COUNTRY_BOUNDARY_MATRIX_FILENAME = 'country_restore_boundary_matrix.json';
const ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION = 'C1.1';
const ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION = 'C2';

/** Schema-truth parent FK overrides (registry may drift — C1.1). */
const ORANGE_CRP_PARENT_FK_OVERRIDES = [
    'journal_lines' => ['table' => 'journal_vouchers', 'foreign_key' => 'voucher_id', 'nullable' => false],
    'bank_reconciliation_line' => ['table' => 'bank_reconciliation', 'foreign_key' => 'reconciliation_id', 'nullable' => false],
    'inventory_reconciliation_line' => ['table' => 'inventory_reconciliation', 'foreign_key' => 'reconciliation_id', 'nullable' => false],
];

/** @var list<string> */
const ORANGE_CRP_NEVER_EXPORT_TABLES = [
    'journal_entries',
    'orange_country_screen_copy_log',
];

function orange_country_boundary_matrix_path(?string $projectRoot = null): string
{
    if ($projectRoot === null || $projectRoot === '') {
        if (function_exists('orange_backup_project_root')) {
            $projectRoot = orange_backup_project_root();
        } else {
            $projectRoot = dirname(__DIR__, 2);
        }
    }

    return $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . ORANGE_COUNTRY_BOUNDARY_MATRIX_FILENAME;
}

/**
 * @return array<string, mixed>
 */
function orange_country_boundary_matrix_load(?string $projectRoot = null): array
{
    $path = orange_country_boundary_matrix_path($projectRoot);
    if (!is_file($path)) {
        throw new RuntimeException('Missing country boundary matrix: ' . $path);
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid country boundary matrix JSON.');
    }
    $errors = orange_country_boundary_matrix_validate($data);
    if ($errors !== []) {
        throw new RuntimeException('Boundary matrix validation failed: ' . implode('; ', $errors));
    }

    return $data;
}

/**
 * @param array<string, mixed> $matrix
 * @return list<string>
 */
function orange_country_boundary_matrix_validate(array $matrix): array
{
    $errors = [];
    if (($matrix['boundary_policy_version'] ?? '') !== ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION) {
        $errors[] = 'boundary_policy_version mismatch';
    }
    if (($matrix['dependency_graph_version'] ?? '') !== ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION) {
        $errors[] = 'dependency_graph_version mismatch';
    }
    if ((int) ($matrix['schema_revision'] ?? 0) !== 121) {
        $errors[] = 'schema_revision must be 121';
    }
    $tables = $matrix['tables'] ?? null;
    if (!is_array($tables) || $tables === []) {
        $errors[] = 'tables missing';

        return $errors;
    }
    $count = 0;
    foreach ($tables as $name => $meta) {
        if (!is_string($name) || !is_array($meta)) {
            $errors[] = 'invalid table entry';
            continue;
        }
        if (!(bool) ($meta['exportable'] ?? false)) {
            continue;
        }
        $count++;
        $class = (string) ($meta['classification'] ?? '');
        if (!in_array($class, ['Country Scoped', 'Mixed'], true)) {
            $errors[] = $name . ' classification must be Country Scoped or Mixed';
        }
        $mode = (string) ($meta['restore_mode'] ?? '');
        if (!in_array($mode, ['replace', 'special'], true)) {
            $errors[] = $name . ' restore_mode invalid';
        }
        if ((int) ($meta['restore_batch'] ?? 0) < 1) {
            $errors[] = $name . ' restore_batch missing';
        }
    }
    if ($count !== 80) {
        $errors[] = 'exportable mutate table count must be 80, got ' . (string) $count;
    }
    foreach (ORANGE_CRP_NEVER_EXPORT_TABLES as $forbidden) {
        if (isset($tables[$forbidden]) && (bool) ($tables[$forbidden]['exportable'] ?? false)) {
            $errors[] = $forbidden . ' must not be exportable';
        }
    }

    return $errors;
}

/**
 * Ordered export plan: restore_batch ASC, table name ASC.
 *
 * @param array<string, mixed> $matrix
 * @param array<string, mixed> $registry
 * @return list<array{table:string,meta:array<string,mixed>,matrix:array<string,mixed>}>
 */
function orange_country_boundary_matrix_export_plan(array $matrix, array $registry): array
{
    /** @var array<string, array<string, mixed>> $regTables */
    $regTables = is_array($registry['tables'] ?? null) ? $registry['tables'] : [];
    /** @var array<string, array<string, mixed>> $matrixTables */
    $matrixTables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];

    $plan = [];
    foreach ($matrixTables as $tableName => $mMeta) {
        if (!(bool) ($mMeta['exportable'] ?? false)) {
            continue;
        }
        if (in_array($tableName, ORANGE_CRP_NEVER_EXPORT_TABLES, true)) {
            throw new RuntimeException('Boundary matrix forbids export of ' . $tableName);
        }
        $regMeta = is_array($regTables[$tableName] ?? null) ? $regTables[$tableName] : [];
        $meta = orange_country_boundary_matrix_merge_export_meta($tableName, $mMeta, $regMeta);
        $plan[] = [
            'table' => $tableName,
            'meta' => $meta,
            'matrix' => $mMeta,
        ];
    }

    usort($plan, static function (array $a, array $b): int {
        $ab = (int) ($a['matrix']['restore_batch'] ?? 0);
        $bb = (int) ($b['matrix']['restore_batch'] ?? 0);
        if ($ab !== $bb) {
            return $ab <=> $bb;
        }

        return strcmp($a['table'], $b['table']);
    });

    return $plan;
}

/**
 * @param array<string, mixed> $matrixMeta
 * @param array<string, mixed> $registryMeta
 * @return array<string, mixed>
 */
function orange_country_boundary_matrix_merge_export_meta(
    string $tableName,
    array $matrixMeta,
    array $registryMeta
): array {
    $handler = (string) ($matrixMeta['special_handler'] ?? '');
    $resolver = (string) ($matrixMeta['ownership_resolver'] ?? '');
    $batch = (int) ($matrixMeta['restore_batch'] ?? 0);
    $exportOrder = ($batch * 1000) + (int) ($registryMeta['export_order'] ?? 0);

    $meta = $registryMeta;
    $meta['export_order'] = $exportOrder;
    $meta['restore_order'] = $exportOrder;
    $meta['boundary_classification'] = (string) ($matrixMeta['classification'] ?? '');
    $meta['boundary_restore_mode'] = (string) ($matrixMeta['restore_mode'] ?? '');
    $meta['boundary_restore_batch'] = $batch;
    $meta['ownership_resolver'] = $resolver;
    $meta['special_handler'] = $handler !== '' ? $handler : null;

    if ($handler === 'admins_permissions_composite' && $tableName === 'admin_permissions') {
        $meta['ownership_type'] = 'dependent';
        $meta['extraction_rule'] = [
            'type' => 'parent_rows',
            'parent_table' => 'admins',
            'foreign_key' => 'admin_id',
        ];
        $meta['parent_dependency'] = [
            'table' => 'admins',
            'foreign_key' => 'admin_id',
            'nullable' => false,
        ];
        $meta['integrity_critical'] = true;
    } elseif ($handler === 'seq_country_namespace' && $tableName === 'document_sequences') {
        $meta['ownership_type'] = 'dependent';
        $meta['extraction_rule'] = ['type' => 'special_sequence_namespace'];
        $meta['parent_dependency'] = null;
        $meta['integrity_critical'] = true;
    } elseif ($handler === 'gl_voucher_slots_country' && $tableName === 'orange_gl_voucher_slots') {
        $meta['ownership_type'] = 'country_owned';
        $meta['extraction_rule'] = ['type' => 'country_id', 'column' => 'country_id'];
        $meta['parent_dependency'] = [
            'table' => 'journal_vouchers',
            'foreign_key' => 'journal_voucher_id',
            'nullable' => false,
        ];
        $meta['integrity_critical'] = true;
    } elseif ($handler === 'expenses_via_accounts' && $tableName === 'expenses') {
        $meta['ownership_type'] = 'dependent';
        // Keep registry custom_sql when present.
        if (($meta['extraction_rule']['type'] ?? '') !== 'custom_sql') {
            $meta['extraction_rule'] = [
                'type' => 'custom_sql',
                'sql' => 'SELECT e.id FROM expenses e INNER JOIN accounts a ON a.id = e.expense_account_id WHERE a.country_id = :country_id',
            ];
        }
        $meta['parent_dependency'] = [
            'table' => 'accounts',
            'foreign_key' => 'expense_account_id',
            'nullable' => true,
        ];
    } elseif ($handler === 'polymorphic_company_documents' && $tableName === 'orange_company_documents') {
        $meta['ownership_type'] = 'dependent';
        $meta['uploads_linked'] = true;
        // Keep registry custom_sql.
    }

    if (isset(ORANGE_CRP_PARENT_FK_OVERRIDES[$tableName])) {
        $override = ORANGE_CRP_PARENT_FK_OVERRIDES[$tableName];
        $meta['parent_dependency'] = $override;
        $rule = is_array($meta['extraction_rule'] ?? null) ? $meta['extraction_rule'] : [];
        if (($rule['type'] ?? '') === 'parent_rows') {
            $rule['parent_table'] = $override['table'];
            $rule['foreign_key'] = $override['foreign_key'];
            $meta['extraction_rule'] = $rule;
        }
    }

    // Hard reject legacy country_scope_or (cross-country OR) for CRP export.
    if (($meta['extraction_rule']['type'] ?? '') === 'country_scope_or') {
        throw new RuntimeException(
            'country_scope_or extraction forbidden under frozen boundary policy for table ' . $tableName
        );
    }

    return $meta;
}

/**
 * @param array<string, mixed> $matrix
 * @return array<int, list<string>>
 */
function orange_country_boundary_matrix_restore_batches(array $matrix): array
{
    $batches = [];
    /** @var array<string, array<string, mixed>> $tables */
    $tables = is_array($matrix['tables'] ?? null) ? $matrix['tables'] : [];
    foreach ($tables as $name => $meta) {
        if (!(bool) ($meta['exportable'] ?? false)) {
            continue;
        }
        $b = (int) ($meta['restore_batch'] ?? 0);
        if ($b < 1) {
            continue;
        }
        if (!isset($batches[$b])) {
            $batches[$b] = [];
        }
        $batches[$b][] = $name;
    }
    foreach ($batches as $b => $list) {
        sort($list);
        $batches[$b] = $list;
    }
    ksort($batches);

    return $batches;
}
