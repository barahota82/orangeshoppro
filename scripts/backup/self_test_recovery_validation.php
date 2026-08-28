<?php

declare(strict_types=1);

/**
 * Phase 1C Disaster Recovery Validation self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_recovery_validation.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'recovery_validation.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'restore_self_test_helpers.php';

$failures = 0;

function drv_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

/**
 * @return array{dir:string,cleanup:bool}
 */
function drv_temp_dir(): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_drv_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return ['dir' => $dir, 'cleanup' => true];
}

/**
 * @param array<string, mixed> $overrides
 */
function drv_write_full_package(string $dir, array $overrides = []): void
{
    $sql = "-- Orange Phase 1A PDO SQL export\n"
        . "CREATE TABLE demo (id INT PRIMARY KEY);\n"
        . "INSERT INTO demo VALUES (1);\n"
        . "SET TIME_ZONE=@OLD_TIME_ZONE;\n"
        . "SET SQL_MODE=@OLD_SQL_MODE;\n"
        . "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n";
    $dumpPath = $dir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz';
    $out = gzopen($dumpPath, 'wb9');
    gzwrite($out, $sql);
    gzclose($out);
    restore_self_test_write_empty_zip($dir . DIRECTORY_SEPARATOR . 'uploads.zip');
    $manifest = array_merge([
        'package_type' => 'full_disaster',
        'package_version' => '1.2',
        'generated_at' => gmdate('c'),
        'schema_revision' => 121,
        'export_backend' => 'php_pdo',
        'dump_file' => 'orange_db.sql.gz',
        'uploads_file' => 'uploads.zip',
        'dump_sha256' => orange_backup_sha256_file($dumpPath),
        'uploads_sha256' => orange_backup_sha256_file($dir . DIRECTORY_SEPARATOR . 'uploads.zip'),
        'dump_size_bytes' => filesize($dumpPath),
        'uploads_size_bytes' => filesize($dir . DIRECTORY_SEPARATOR . 'uploads.zip'),
        'backup_status' => 'success',
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
    ], $overrides['manifest'] ?? []);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    $health = array_merge([
        'package_type' => 'full_disaster',
        'package_status' => 'healthy',
        'schema_revision' => 121,
        'export_backend' => 'php_pdo',
        'failure_reasons' => [],
        'warnings' => [],
        'maintenance_notes' => [],
    ], $overrides['health'] ?? []);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', $health);
    orange_backup_write_checksums($dir, ['orange_db.sql.gz', 'uploads.zip', 'manifest.json', 'health.json']);
}

/**
 * @param array<string, mixed> $overrides
 */
function drv_write_crp_package(string $dir, array $overrides = []): void
{
    mkdir($dir . DIRECTORY_SEPARATOR . 'sql');
    mkdir($dir . DIRECTORY_SEPARATOR . 'files');
    file_put_contents(
        $dir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '000_session_preamble.sql',
        "-- Orange Phase 1A PDO SQL export\nSET NAMES utf8mb4;\n"
    );
    file_put_contents(
        $dir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '050_accounts.sql',
        "-- Orange CRP export table=accounts country_id=1\n"
        . "INSERT INTO `accounts` (`id`, `name`) VALUES\n"
        . "(1, 'Cash');\n"
        . "-- rows=1\n"
    );
    file_put_contents(
        $dir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '999_session_postamble.sql',
        "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n"
    );
    restore_self_test_write_empty_zip($dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip');
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
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    $health = array_merge([
        'package_type' => 'country_recovery',
        'package_status' => 'healthy',
        'country_id' => 1,
        'schema_revision' => 121,
        'registry_version' => '1.0',
        'failure_reasons' => [],
        'warnings' => [],
        'maintenance_notes' => [],
        'trial_balance' => ['difference' => 0.0],
        'cross_country_validation' => ['errors' => []],
    ], $overrides['health'] ?? []);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', $health);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'dependency_graph.json', ['nodes' => [], 'edges' => []]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'table_inventory.json', ['country_id' => 1, 'other_country_markers' => [], 'tables' => []]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'id_snapshot.json', ['country_id' => 1, 'tables' => []]);
    orange_backup_write_checksums($dir, [
        'manifest.json',
        'health.json',
        'dependency_graph.json',
        'table_inventory.json',
        'id_snapshot.json',
        'files/uploads_country.zip',
        'sql/000_session_preamble.sql',
        'sql/050_accounts.sql',
        'sql/999_session_postamble.sql',
    ]);
}

$root = drv_temp_dir();
$successDir = $root['dir'] . DIRECTORY_SEPARATOR . 'full_success';
mkdir($successDir);
drv_write_full_package($successDir);
$successReport = orange_recovery_validate_package($successDir);
drv_self_test(($successReport['recovery_score'] ?? 0) === 100, 'package success score 100');
drv_self_test(($successReport['overall_result'] ?? '') === 'pass', 'package success overall pass');

$warningDir = $root['dir'] . DIRECTORY_SEPARATOR . 'full_warning';
mkdir($warningDir);
drv_write_full_package($warningDir, [
    'health' => [
        'package_status' => 'warning',
        'warnings' => ['minor upload note'],
    ],
]);
$warningReport = orange_recovery_validate_package($warningDir);
drv_self_test(($warningReport['recovery_score'] ?? 0) >= 70 && ($warningReport['recovery_score'] ?? 0) < 100, 'package warning score band');
drv_self_test(($warningReport['overall_result'] ?? '') === 'warning', 'package warning overall warning');

$failDir = $root['dir'] . DIRECTORY_SEPARATOR . 'full_failure';
mkdir($failDir);
drv_write_full_package($failDir, [
    'health' => [
        'package_status' => 'failed',
        'failure_reasons' => ['simulated export failure'],
    ],
]);
$failReport = orange_recovery_validate_package($failDir);
drv_self_test(($failReport['recovery_score'] ?? 100) < 70, 'package failure score below 70');
drv_self_test(($failReport['overall_result'] ?? '') === 'fail', 'package failure overall fail');

$missingManifestDir = $root['dir'] . DIRECTORY_SEPARATOR . 'missing_manifest';
mkdir($missingManifestDir);
$missingManifestReport = orange_recovery_validate_package($missingManifestDir);
drv_self_test(($missingManifestReport['recovery_score'] ?? 100) < 70, 'missing manifest fails');

$badJsonDir = $root['dir'] . DIRECTORY_SEPARATOR . 'bad_json';
mkdir($badJsonDir);
file_put_contents($badJsonDir . DIRECTORY_SEPARATOR . 'manifest.json', '{not-json');
$badJsonReport = orange_recovery_validate_package($badJsonDir);
drv_self_test(($badJsonReport['recovery_score'] ?? 100) < 70, 'bad JSON fails');

$missingHealthDir = $root['dir'] . DIRECTORY_SEPARATOR . 'missing_health';
mkdir($missingHealthDir);
drv_write_full_package($missingHealthDir);
@unlink($missingHealthDir . DIRECTORY_SEPARATOR . 'health.json');
$missingHealthReport = orange_recovery_validate_package($missingHealthDir);
drv_self_test(($missingHealthReport['recovery_score'] ?? 100) < 70, 'missing health fails');

$checksumDir = $root['dir'] . DIRECTORY_SEPARATOR . 'bad_checksum';
mkdir($checksumDir);
drv_write_full_package($checksumDir);
file_put_contents($checksumDir . DIRECTORY_SEPARATOR . 'checksums.sha256', str_repeat('a', 64) . "  manifest.json\n");
$checksumReport = orange_recovery_validate_package($checksumDir);
drv_self_test(($checksumReport['recovery_score'] ?? 100) < 70, 'corrupted checksum fails');

$schemaDir = $root['dir'] . DIRECTORY_SEPARATOR . 'schema_mismatch';
mkdir($schemaDir);
drv_write_full_package($schemaDir, ['manifest' => ['schema_revision' => 99]]);
$schemaReport = orange_recovery_validate_package($schemaDir);
drv_self_test(($schemaReport['recovery_score'] ?? 100) < 70, 'schema mismatch fails');

$truncatedDir = $root['dir'] . DIRECTORY_SEPARATOR . 'truncated_sql';
mkdir($truncatedDir);
drv_write_full_package($truncatedDir);
$truncatedDump = $truncatedDir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz';
$out = gzopen($truncatedDump, 'wb9');
gzwrite($out, "INSERT INTO demo VALUES (1)\n");
gzclose($out);
$manifestPath = $truncatedDir . DIRECTORY_SEPARATOR . 'manifest.json';
$manifestData = json_decode((string) file_get_contents($manifestPath), true);
if (is_array($manifestData)) {
    $manifestData['dump_sha256'] = orange_backup_sha256_file($truncatedDump);
    orange_backup_write_json($manifestPath, $manifestData);
}
orange_backup_write_checksums($truncatedDir, ['orange_db.sql.gz', 'uploads.zip', 'manifest.json', 'health.json']);
$truncatedReport = orange_recovery_validate_package($truncatedDir);
drv_self_test(($truncatedReport['recovery_score'] ?? 100) < 70, 'truncated SQL fails');

$brokenZipDir = $root['dir'] . DIRECTORY_SEPARATOR . 'broken_zip';
mkdir($brokenZipDir);
drv_write_full_package($brokenZipDir);
file_put_contents($brokenZipDir . DIRECTORY_SEPARATOR . 'uploads.zip', 'not-a-zip');
orange_backup_write_checksums($brokenZipDir, ['orange_db.sql.gz', 'uploads.zip', 'manifest.json', 'health.json']);
$brokenZipReport = orange_recovery_validate_package($brokenZipDir);
drv_self_test(($brokenZipReport['recovery_score'] ?? 100) < 70, 'broken ZIP fails');

$registryDir = $root['dir'] . DIRECTORY_SEPARATOR . 'registry_mismatch';
mkdir($registryDir);
drv_write_crp_package($registryDir, ['manifest' => ['registry_version' => '9.9']]);
$registryReport = orange_recovery_validate_package($registryDir);
drv_self_test(($registryReport['recovery_score'] ?? 100) < 70, 'registry mismatch fails');

$crpSuccessDir = $root['dir'] . DIRECTORY_SEPARATOR . 'crp_success';
mkdir($crpSuccessDir);
drv_write_crp_package($crpSuccessDir);
$crpReport = orange_recovery_validate_package($crpSuccessDir);
drv_self_test(($crpReport['recovery_score'] ?? 0) === 100, 'CRP package success');
drv_self_test(($crpReport['dependency_graph_valid'] ?? false) === true, 'CRP dependency graph valid');
drv_self_test(($crpReport['registry_valid'] ?? false) === true, 'CRP registry valid');

$reportPath = orange_recovery_write_report_file($successReport);
drv_self_test(is_string($reportPath) && is_file((string) $reportPath), 'recovery_validation.json written beside package');
if (is_string($reportPath) && is_file($reportPath)) {
    drv_self_test(!is_file($successDir . DIRECTORY_SEPARATOR . 'recovery_validation.json'), 'package directory not modified');
}

orange_backup_remove_dir($root['dir']);

drv_self_test(orange_recovery_compute_score([], [])['recovery_score'] === 100, 'score algorithm perfect');
drv_self_test(orange_recovery_compute_score([], ['informational: note'])['recovery_score'] >= 90, 'score algorithm informational');
drv_self_test(orange_recovery_compute_score([], ['health warning: note'])['recovery_score'] >= 70, 'score algorithm warning band');
drv_self_test(orange_recovery_compute_score(['fatal'], [])['recovery_score'] < 70, 'score algorithm fail band');

$validInsert = "INSERT INTO `accounts` (`id`, `name`) VALUES (1, 'Cash');\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($validInsert, '050_accounts.sql') === null,
    'SQL completeness: valid INSERT ending with semicolon'
);

$crpAccountsChunk = "-- Orange CRP export table=accounts country_id=1\n"
    . "INSERT INTO `accounts` (`id`, `name`) VALUES\n"
    . "(1, 'Cash');\n"
    . "-- rows=1\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($crpAccountsChunk, '050_accounts.sql') === null,
    'SQL completeness: CRP accounts chunk with rows footer'
);

$trailingComment = $validInsert . "\n-- export complete\n\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($trailingComment, 'chunk.sql') === null,
    'SQL completeness: trailing comment/newlines after final statement'
);

$emptyCrpChunk = "-- Orange CRP export table=accounts country_id=1\n-- rows=0\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($emptyCrpChunk, '050_accounts.sql') === null,
    'SQL completeness: empty CRP table chunk'
);

$commentOnlyChunk = "-- Orange CRP export table=accounts country_id=1\n-- no data for this slice\n-- rows=0\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($commentOnlyChunk, '050_accounts.sql') === null,
    'SQL completeness: comment-only CRP chunk'
);

$truncatedInsert = "INSERT INTO `accounts` (`id`, `name`) VALUES (1, 'Cash')\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($truncatedInsert, '050_accounts.sql') !== null,
    'SQL completeness: genuinely truncated INSERT detected'
);

$quotedSemi = "INSERT INTO `accounts` (`id`, `name`) VALUES (1, 'a;b');\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($quotedSemi, '050_accounts.sql') === null,
    'SQL completeness: quoted semicolon inside string accepted'
);

$mysqlVersionComment = "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($mysqlVersionComment, 'full_dump.sql') === null,
    'SQL completeness: MySQL executable version comment with semicolon accepted'
);

$truncatedMysqlVersionComment = "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */\n";
drv_self_test(
    orange_recovery_validate_sql_completeness($truncatedMysqlVersionComment, 'full_dump.sql') !== null,
    'SQL completeness: truncated MySQL executable version comment detected'
);

$crpWithMissingUploadWarning = orange_recovery_classify_health_warning('missing upload file: uploads/customers/42/');
drv_self_test(
    str_starts_with($crpWithMissingUploadWarning, 'informational:'),
    'health warning reclassify: missing customer upload folder is informational'
);

$crpInfoScore = orange_recovery_compute_score([], [
    'informational: missing upload file: uploads/suppliers/9/',
    'informational: routine maintenance note',
]);
drv_self_test(($crpInfoScore['recovery_score'] ?? 0) >= 90, 'informational upload notes score band');

$streamDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_drv_stream_' . bin2hex(random_bytes(4));
mkdir($streamDir);
$streamGz = $streamDir . DIRECTORY_SEPARATOR . 'large.sql.gz';
$streamOut = gzopen($streamGz, 'wb9');
$streamPayload = "-- Orange Phase 1A PDO SQL export\n";
for ($i = 0; $i < 5000; $i++) {
    $streamPayload .= "INSERT INTO demo VALUES (" . $i . ");\n";
}
$streamPayload .= "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n";
gzwrite($streamOut, $streamPayload);
gzclose($streamOut);
$streamStart = microtime(true);
$streamResult = orange_recovery_validate_gzip_sql_file($streamGz, 'stream SQL dump');
$streamElapsed = microtime(true) - $streamStart;
drv_self_test(($streamResult['ok'] ?? false) === true, 'gzip SQL streaming validation passes large dump');
drv_self_test($streamElapsed < 5.0, 'gzip SQL streaming validation completes within 5 seconds');
@unlink($streamGz);
@rmdir($streamDir);

exit($failures > 0 ? 1 : 0);
