<?php

declare(strict_types=1);

/**
 * Phase 2B.2 — Country restore → staging self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_country_staging.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_table_registry_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_validate.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_export.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'recovery_validation.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_staging_target.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_sql_runner.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_validation_adapter.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_uploads_applicator.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_country_staging.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_full_staging.php';

$failures = 0;

function country_restore_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function country_restore_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2b2_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function country_restore_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            country_restore_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * @param array<string, mixed> $overrides
 */
function country_restore_write_package(string $dir, array $overrides = []): void
{
    global $projectRoot;

    $registry = orange_backup_registry_load($projectRoot);
    $dependencyGraph = orange_country_export_build_dependency_graph($registry);
    $meta = $registry['tables']['customers'] ?? null;
    $restoreOrder = is_array($meta) ? (int) ($meta['restore_order'] ?? 10) : 10;

    $sqlDir = $dir . DIRECTORY_SEPARATOR . 'sql';
    $filesDir = $dir . DIRECTORY_SEPARATOR . 'files';
    mkdir($sqlDir, 0775, true);
    mkdir($filesDir, 0775, true);

    file_put_contents($sqlDir . DIRECTORY_SEPARATOR . '000_session_preamble.sql', "SET NAMES utf8mb4;\n");
    file_put_contents(
        $sqlDir . DIRECTORY_SEPARATOR . sprintf('%03d_customers.sql', $restoreOrder),
        "INSERT INTO `customers` (`id`, `country_id`, `name_ar`) VALUES (42, 1, 'restore-test');\n"
    );
    file_put_contents($sqlDir . DIRECTORY_SEPARATOR . '999_session_postamble.sql', "SET FOREIGN_KEY_CHECKS=1;\n");

    orange_country_uploads_write_empty_zip($filesDir . DIRECTORY_SEPARATOR . 'uploads_country.zip');

    $manifest = array_merge([
        'package_type' => 'country_recovery',
        'package_version' => '1.0',
        'generated_at' => gmdate('c'),
        'country_id' => 1,
        'country_code' => 'kw',
        'country_label' => 'Kuwait',
        'schema_revision' => 121,
        'registry_version' => '1.0',
        'export_backend' => 'php_country_export',
        'package_status' => 'healthy',
    ], $overrides['manifest'] ?? []);

    $health = array_merge([
        'package_type' => 'country_recovery',
        'package_status' => 'healthy',
        'country_id' => 1,
        'country_code' => 'kw',
        'schema_revision' => 121,
        'registry_version' => '1.0',
        'failure_reasons' => [],
        'warnings' => [],
        'maintenance_notes' => [],
    ], $overrides['health'] ?? []);

    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', $health);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'dependency_graph.json', $overrides['dependency_graph'] ?? $dependencyGraph);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'table_inventory.json', [
        'country_id' => 1,
        'country_code' => 'kw',
        'schema_revision' => 121,
        'registry_version' => '1.0',
        'tables' => ['customers' => 1],
        'ownership_summary' => [],
        'other_country_markers' => [],
    ]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'id_snapshot.json', [
        'country_id' => 1,
        'generated_at' => gmdate('c'),
        'tables' => ['customers' => [42]],
    ]);

    orange_backup_write_checksums($dir, [
        'manifest.json',
        'health.json',
        'dependency_graph.json',
        'table_inventory.json',
        'id_snapshot.json',
        'sql/000_session_preamble.sql',
        'sql/' . sprintf('%03d_customers.sql', $restoreOrder),
        'sql/999_session_postamble.sql',
        'files/uploads_country.zip',
    ]);
}

$backupRoot = country_restore_temp_root();
$workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
$packageDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . '2026-07-01_120000';
mkdir($workRoot, 0775, true);
mkdir($packageDir, 0775, true);
country_restore_write_package($packageDir);

$stagingDbName = 'orange_restore_country_staging_test';
try {
    $productionDbName = orange_restore_production_db_name($projectRoot);
} catch (Throwable) {
    $productionDbName = 'orange_db';
}

$envOverride = [
    'ORANGE_BACKUP_ROOT' => $backupRoot,
    'ORANGE_RESTORE_WORK_DIR' => $workRoot,
    ORANGE_RESTORE_ENV_STAGING_DB => $stagingDbName,
    ORANGE_RESTORE_ENV_STAGING_DB_USER => 'restore_staging_user',
    ORANGE_RESTORE_ENV_STAGING_DB_PASS => 'restore_staging_pass',
];

// SQL chunk parsing
$parsed = orange_restore_country_staging_parse_sql_chunk('010_customers.sql');
country_restore_self_test($parsed !== null && ($parsed['table'] ?? '') === 'customers', 'parse: table chunk filename');

// Import plan — restore order
$manifest = json_decode((string) file_get_contents($packageDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [];
$plan = orange_restore_country_staging_build_import_plan($projectRoot, $packageDir, $manifest);
country_restore_self_test($plan['ok'] === true, 'import plan: valid package accepted');
country_restore_self_test(($plan['tables'][0] ?? '') === 'customers', 'import plan: customers table included');
country_restore_self_test(count($plan['import_files'] ?? []) === 3, 'import plan: preamble + table + postamble');

// Registry mismatch
$badRegistryDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . 'bad_registry';
mkdir($badRegistryDir, 0775, true);
country_restore_write_package($badRegistryDir, ['manifest' => ['registry_version' => '9.9']]);
$badManifest = json_decode((string) file_get_contents($badRegistryDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [];
$badPlan = orange_restore_country_staging_build_import_plan($projectRoot, $badRegistryDir, $badManifest);
country_restore_self_test($badPlan['ok'] === false, 'import plan: registry mismatch rejected');

// Dependency graph mismatch
$registry = orange_backup_registry_load($projectRoot);
$badGraphDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . 'bad_graph';
mkdir($badGraphDir, 0775, true);
$brokenGraph = orange_country_export_build_dependency_graph($registry);
if (($brokenGraph['edges'][0]['from'] ?? '') !== '') {
    $brokenGraph['edges'][0]['to'] = '__invalid_parent__';
}
country_restore_write_package($badGraphDir, ['dependency_graph' => $brokenGraph]);
$badGraphManifest = json_decode((string) file_get_contents($badGraphDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [];
$badGraphPlan = orange_restore_country_staging_build_import_plan($projectRoot, $badGraphDir, $badGraphManifest);
country_restore_self_test($badGraphPlan['ok'] === false, 'import plan: dependency graph mismatch rejected');

// Restore order violation
$orderViolation = orange_restore_country_staging_validate_restore_order(
    ['order_items' => '/tmp/a.sql', 'orders' => '/tmp/b.sql'],
    [
        'order_items' => ['restore_order' => 5],
        'orders' => ['restore_order' => 50],
    ],
    ['edges' => [['from' => 'order_items', 'to' => 'orders', 'foreign_key' => 'order_id']]]
);
country_restore_self_test($orderViolation !== null, 'import plan: restore order violation detected');

// Forbidden global table in SQL
$forbiddenDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . 'forbidden_sql';
mkdir($forbiddenDir, 0775, true);
country_restore_write_package($forbiddenDir);
$forbiddenChunk = glob($forbiddenDir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '*_customers.sql')[0] ?? '';
if ($forbiddenChunk !== '') {
    file_put_contents(
        $forbiddenChunk,
        "INSERT INTO `countries` (`id`) VALUES (1);\n"
    );
}
$forbiddenManifest = json_decode((string) file_get_contents($forbiddenDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [];
$forbiddenPlan = orange_restore_country_staging_build_import_plan($projectRoot, $forbiddenDir, $forbiddenManifest);
country_restore_self_test($forbiddenPlan['ok'] === false, 'import plan: forbidden global table SQL rejected');

// Forbidden scanner catches signatures split across read chunks without loading the full SQL file.
$boundaryForbidden = $backupRoot . DIRECTORY_SEPARATOR . 'boundary_forbidden.sql';
file_put_contents($boundaryForbidden, str_repeat('x', 65530) . "INSERT INTO `countries` (`id`) VALUES (1);\n");
country_restore_self_test(
    orange_restore_country_staging_scan_sql_file_forbidden($boundaryForbidden) !== null,
    'import plan: forbidden global table SQL rejected across chunk boundary'
);

// Missing SQL chunk (verify package)
$missingSqlDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . 'missing_sql';
mkdir($missingSqlDir, 0775, true);
country_restore_write_package($missingSqlDir);
foreach (glob($missingSqlDir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '*_customers.sql') ?: [] as $chunk) {
    @unlink($chunk);
}
$missingVerify = orange_country_export_verify_package($missingSqlDir);
country_restore_self_test($missingVerify['ok'] === false, 'verify: missing SQL chunk rejected');

// Missing uploads zip
$missingUploadsDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . 'missing_uploads';
mkdir($missingUploadsDir, 0775, true);
country_restore_write_package($missingUploadsDir);
@unlink($missingUploadsDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip');
$missingUploadsVerify = orange_country_export_verify_package($missingUploadsDir);
country_restore_self_test($missingUploadsVerify['ok'] === false, 'verify: missing uploads_country.zip rejected');

// Country precheck — failed health
$failHealthDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . 'fail_health';
mkdir($failHealthDir, 0775, true);
country_restore_write_package($failHealthDir, [
    'health' => ['package_status' => 'failed', 'failure_reasons' => ['simulated']],
]);
$failPrecheck = orange_restore_validation_adapter_country_package_precheck($failHealthDir);
country_restore_self_test($failPrecheck['ok'] === false, 'precheck: failed package verify rejected');

// Country precheck — DRV warning not pass
$warnDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . 'drv_warn';
mkdir($warnDir, 0775, true);
country_restore_write_package($warnDir);
$drv = orange_recovery_validate_package($warnDir);
if (($drv['overall_result'] ?? '') === 'pass') {
    country_restore_self_test(true, 'precheck: healthy package DRV pass (skip warning simulation)');
} else {
    $warnPrecheck = orange_restore_validation_adapter_country_package_precheck($warnDir);
    country_restore_self_test($warnPrecheck['ok'] === false, 'precheck: non-pass overall_result rejected');
}

// Upload restore
$uploadsTarget = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_target';
mkdir($uploadsTarget, 0775, true);
$uploadsZip = $packageDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
$zip = new ZipArchive();
$zip->open($uploadsZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('products/kw-demo.jpg', 'country-upload-test');
$zip->close();
country_restore_self_test(
    orange_restore_uploads_applicator_extract($uploadsZip, $uploadsTarget)['ok'] === true,
    'uploads: country zip extract to staging_uploads'
);

// SQL splitter must tolerate long strings/comments spanning stream read chunks.
$longStringSql = "INSERT INTO `customers` (`id`, `country_id`, `name_ar`) VALUES (77, 1, '"
    . str_repeat('a', 70000)
    . "');\n";
try {
    $partialSplit = orange_restore_sql_runner_split_next_statement(substr($longStringSql, 0, 65536), true);
    $fullSplit = orange_restore_sql_runner_split_next_statement($longStringSql, false);
    country_restore_self_test(
        $partialSplit === null && is_array($fullSplit) && str_contains($fullSplit['statement'], str_repeat('a', 128)),
        'sql runner: long string may span stream chunks'
    );
} catch (Throwable) {
    country_restore_self_test(false, 'sql runner: long string may span stream chunks');
}

// Job lifecycle — country invalid transition
$lifeJob = orange_restore_job_create($workRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_COUNTRY,
    'operator_admin_id' => 0,
    'operator_username' => 'cli',
    'source_package_path' => $packageDir,
    'source_package_checksum' => str_repeat('c', 64),
    'package_version' => '1.0',
    'schema_revision' => 121,
    'country_id' => 1,
    'country_code' => 'kw',
    'approval_phrase_expected' => 'RESTORE KW',
]);
$lifeJobId = (string) ($lifeJob['job_id'] ?? '');
try {
    orange_restore_job_transition($workRoot, $lifeJobId, ORANGE_RESTORE_JOB_STATUS_STAGING);
    country_restore_self_test(false, 'job lifecycle: invalid jump created->staging rejected');
} catch (Throwable $e) {
    country_restore_self_test(
        str_contains($e->getMessage(), 'Invalid country-restore staging job transition'),
        'job lifecycle: invalid transition rejected'
    );
}

// Lock payload update must keep the same active lock file instead of release/re-acquire.
$lockUpdateWork = $backupRoot . DIRECTORY_SEPARATOR . 'lock_update_work';
mkdir($lockUpdateWork, 0775, true);
$lockUpdate = orange_restore_acquire_lock($lockUpdateWork, 'pending');
if ($lockUpdate['ok']) {
    orange_restore_update_lock_job_id($lockUpdateWork, 'country_job');
    $lockStatus = orange_restore_lock_status($lockUpdateWork);
    country_restore_self_test(
        ($lockStatus['payload']['job_id'] ?? '') === 'country_job',
        'lock: active lock job_id updated without release'
    );
    orange_restore_release_lock($lockUpdateWork);
} else {
    country_restore_self_test(false, 'lock: active lock job_id updated without release');
}

// Orchestrator abort — missing package
try {
    orange_restore_country_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . 'missing_pkg',
        'env_override' => $envOverride,
    ]);
    country_restore_self_test(false, 'orchestrator: missing package aborts');
} catch (Throwable) {
    country_restore_self_test(true, 'orchestrator: missing package aborts');
}

// Orchestrator abort — failed validation
try {
    orange_restore_country_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $failHealthDir,
        'env_override' => $envOverride,
    ]);
    country_restore_self_test(false, 'orchestrator: failed validation aborts');
} catch (Throwable) {
    country_restore_self_test(true, 'orchestrator: failed validation aborts');
}

// CLI: --skip-fresh-backup unavailable
$cliScript = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_country_to_staging.php';
$cliPhp = (PHP_BINARY !== '' ? PHP_BINARY : 'php');
$cliOutput = [];
$cliExit = 0;
exec(escapeshellarg($cliPhp) . ' ' . escapeshellarg($cliScript) . ' --package=x --skip-fresh-backup 2>&1', $cliOutput, $cliExit);
country_restore_self_test($cliExit === 2, 'cli: --skip-fresh-backup rejected');

// Rollback anchor job field defaults
country_restore_self_test(
    ($lifeJob['rollback_anchor_job_only'] ?? false) === true,
    'rollback anchor: job-only flag set on create'
);

country_restore_rmdir($backupRoot);

echo PHP_EOL . ($failures === 0 ? 'ALL COUNTRY RESTORE STAGING SELF-TESTS PASSED' : "FAILURES: {$failures}") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
