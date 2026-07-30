<?php

declare(strict_types=1);

/**
 * FSR D5 — Schema Expectations reconciliation (FSR-D5-EXP-01).
 *
 * Usage: php scripts/self_test_final_review_d5_expectations.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d1_fixture.php';
require_once $root . '/scripts/lib/final_review_d5_runtime.php';
require_once $root . '/includes/backup/country_boundary_matrix_lib.php';
require_once $root . '/includes/backup/country_export.php';
require_once $root . '/includes/backup/country_crp_verify.php';
require_once $root . '/includes/backup/restore/restore_country_shadow.php';
require_once $root . '/includes/backup/restore/restore_country_shadow_final_hardening.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d5e_assert(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

echo 'NOTE  suite=d5_expectations start=' . gmdate('c') . "\n";

$path = $root . '/config/country_restore_schema_expectations.json';
$raw = (string) file_get_contents($path);
$exp = json_decode($raw, true);
d5e_assert(is_array($exp), 'Expectations JSON parses');
d5e_assert((int) ($exp['schema_revision'] ?? 0) === 124, 'schema_revision remains 124');
d5e_assert((string) ($exp['expectations_version'] ?? '') === '1.0', 'expectations_version 1.0');

$tables = is_array($exp['tables'] ?? null) ? $exp['tables'] : [];
$icl = $tables['inventory_cost_layers']['required_columns'] ?? [];
$icc = $tables['inventory_cost_consumptions']['required_columns'] ?? [];
$ds = $tables['document_sequences']['required_columns'] ?? [];
$ap = $tables['admin_permissions']['required_columns'] ?? [];

d5e_assert(!in_array('qty', $icl, true) && in_array('qty_in', $icl, true) && in_array('qty_remaining', $icl, true), 'inventory_cost_layers uses qty_in/qty_remaining');
d5e_assert(!in_array('qty', $icc, true) && in_array('consumed_qty', $icc, true), 'inventory_cost_consumptions uses consumed_qty');
d5e_assert(!in_array('id', $ds, true) && !in_array('next_value', $ds, true) && in_array('scope', $ds, true) && in_array('last_value', $ds, true), 'document_sequences uses scope/last_value');
d5e_assert(!in_array('id', $ap, true) && in_array('admin_id', $ap, true) && in_array('resource_key', $ap, true), 'admin_permissions uses admin_id/resource_key');

// Mutation-proof: stale columns must not be present in committed file.
d5e_assert(!str_contains($raw, '"qty"') || !preg_match('/inventory_cost_[^"]+"\s*:\s*\{[^}]*"qty"/s', $raw), 'no stale qty in inventory expectations blocks');
d5e_assert(!str_contains($raw, 'next_value'), 'no next_value in Expectations JSON');

$eaSrc = (string) file_get_contents($root . '/includes/backup/restore/restore_country_shadow_ea.php');
d5e_assert(str_contains($eaSrc, 'SELECT `scope`, `last_value` FROM document_sequences'), 'EA sequences SQL uses last_value');
d5e_assert(!preg_match('/SELECT\s+`scope`,\s*`next_value`\s+FROM\s+document_sequences/', $eaSrc), 'EA sequences SQL has no next_value column');

$db = 'orange_d5_expv_' . getmypid() . '_' . bin2hex(random_bytes(3));
$mediaTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $db . '_media';
@mkdir($mediaTmp, 0775, true);
$admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$created = false;
try {
    $admin->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $boot = orange_d5_import_schema_and_seed($root, $db, $mediaTmp);
    d5e_assert(!empty($boot['ok']), 'canonical disposable Schema-124 seed');
    /** @var PDO $pdo */
    $pdo = $boot['pdo'];

    $matrix = orange_country_boundary_matrix_load($root);
    $drift1 = orange_country_shadow_verify_schema_drift($pdo, $root, ['schema_revision' => 124], $matrix, []);
    d5e_assert(!empty($drift1['ok']), 'corrected Expectations validate fresh Schema-124 DB');
    d5e_assert(!in_array('schema_column_missing', $drift1['codes'] ?? [], true), 'no schema_column_missing on canonical DB');

    $drift2 = orange_country_shadow_verify_schema_drift($pdo, $root, ['schema_revision' => 124], $matrix, []);
    d5e_assert(($drift1['ok'] ?? null) === ($drift2['ok'] ?? null), 'repeated validation deterministic');

    // Every expectations table validates (columns present).
    $allOk = true;
    foreach (array_keys($tables) as $t) {
        if (!orange_table_exists($pdo, (string) $t)) {
            $allOk = false;
            echo "NOTE  missing_table={$t}\n";
            continue;
        }
        foreach (($tables[$t]['required_columns'] ?? []) as $col) {
            if (!orange_country_shadow_table_has_column($pdo, (string) $t, (string) $col)) {
                $allOk = false;
                echo "NOTE  missing_col={$t}.{$col}\n";
            }
        }
    }
    d5e_assert($allOk, 'every Expectations table/required column present on canonical DB');

    // Wrong schema revision fails (before mutating the disposable DB).
    $badRev = orange_country_shadow_verify_schema_drift($pdo, $root, ['schema_revision' => 123], $matrix, []);
    d5e_assert(empty($badRev['ok']) && in_array('schema_revision_mismatch', $badRev['codes'] ?? [], true), 'wrong package schema_revision fails');

    // Package compatibility: Country packages store schema_revision only (not Expectations content hash).
    $exportSrc = (string) file_get_contents($root . '/includes/backup/country_export.php');
    d5e_assert(str_contains($exportSrc, "'schema_revision'"), 'Country export writes schema_revision');
    d5e_assert(!str_contains($exportSrc, 'country_restore_schema_expectations'), 'Country export does not embed Expectations file');
    $verifySrc = (string) file_get_contents($root . '/includes/backup/country_crp_verify.php');
    d5e_assert(str_contains($verifySrc, 'schema_revision_mismatch'), 'Verify rejects schema_revision mismatch');
    d5e_assert(!str_contains($verifySrc, 'country_restore_schema_expectations'), 'Verify does not hash Expectations file');
    echo "NOTE  package_expectations_contract=REVISION_ONLY\n";
    echo "NOTE  pre_correction_schema124_package=CURRENT_CONTRACT_PACKAGE_ELIGIBLE (revision 124; live C7 uses current Expectations file)\n";
    echo "NOTE  expectations_content_change_does_not_invalidate_package_fingerprint_by_design\n";

    // Stale Expectations file against the still-canonical disposable DB must fail closed.
    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_exp_tmp_' . bin2hex(random_bytes(3));
    @mkdir($tmpRoot . '/config', 0775, true);
    $stale = $exp;
    $stale['tables']['inventory_cost_layers']['required_columns'] = ['id', 'warehouse_id', 'qty'];
    $stale['tables']['document_sequences']['required_columns'] = ['id', 'scope', 'next_value'];
    $stale['tables']['admin_permissions']['required_columns'] = ['id', 'admin_id'];
    file_put_contents(
        $tmpRoot . '/config/country_restore_schema_expectations.json',
        json_encode($stale, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    $staleDrift = orange_country_shadow_verify_schema_drift($pdo, $tmpRoot, ['schema_revision' => 124], $matrix, []);
    d5e_assert(empty($staleDrift['ok']) && in_array('schema_column_missing', $staleDrift['codes'] ?? [], true), 'stale qty/next_value/id Expectations fail against Schema 124');
    d5e_assert(($staleDrift['codes'] ?? []) !== [], 'stale Expectations produce blocking codes');
    @unlink($tmpRoot . '/config/country_restore_schema_expectations.json');
    @rmdir($tmpRoot . '/config');
    @rmdir($tmpRoot);

    // Mutation: remove an expected column → fail closed (last, because it mutates the DB).
    if (orange_table_exists($pdo, 'inventory_cost_layers') && orange_country_shadow_table_has_column($pdo, 'inventory_cost_layers', 'qty_remaining')) {
        $pdo->exec('ALTER TABLE inventory_cost_layers DROP COLUMN qty_remaining');
        $failDrift = orange_country_shadow_verify_schema_drift($pdo, $root, ['schema_revision' => 124], $matrix, ['inventory_cost_layers']);
        d5e_assert(empty($failDrift['ok']) && in_array('schema_column_missing', $failDrift['codes'] ?? [], true), 'removing expected column fails closed');
    } else {
        d5e_assert(false, 'inventory_cost_layers.qty_remaining available for mutation proof');
    }

    d5e_assert(defined('ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED') && ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'Country production cutover remains hard-disabled');
} finally {
    if ($created) {
        try {
            $admin->exec('DROP DATABASE IF EXISTS `' . $db . '`');
        } catch (Throwable) {
        }
    }
    foreach ([$mediaTmp] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D5_EXP01_GAPS\n";
    exit(1);
}
echo "RESULT=FSR_D5_EXP01_REPAIRED\n";
exit(0);
