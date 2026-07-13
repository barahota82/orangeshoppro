<?php

declare(strict_types=1);

require_once __DIR__ . '/../backup_full.php';
require_once __DIR__ . '/../recovery_validation.php';
require_once __DIR__ . '/../backup_validate.php';
require_once __DIR__ . '/../country_export.php';
require_once __DIR__ . '/../backup_table_registry_lib.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_production_target.php';
require_once __DIR__ . '/restore_merge_precheck.php';
require_once __DIR__ . '/restore_uploads_fs.php';

/**
 * Package verify + DRV pre-check (read-only). Abort caller on failure.
 *
 * @return array{ok:bool,verify:array<string,mixed>,drv:array<string,mixed>,errors:list<string>}
 */
function orange_restore_validation_adapter_package_precheck(string $packagePath): array
{
    $errors = [];

    orange_restore_log('Package verify...');
    $verify = orange_backup_verify_full_package($packagePath);
    if (!$verify['ok']) {
        $errors = array_merge($errors, $verify['errors']);
        orange_restore_log('Package verify... FAIL');

        return ['ok' => false, 'verify' => $verify, 'drv' => [], 'errors' => $errors];
    }
    orange_restore_log('Package verify... OK');

    orange_restore_log('Recovery validation...');
    $drv = orange_recovery_validate_package($packagePath);
    $score = (int) ($drv['recovery_score'] ?? 0);
    if ($score < 70) {
        $errors[] = 'Recovery validation failed (score=' . (string) $score . ')';
        foreach ($drv['errors'] ?? [] as $err) {
            if (is_string($err) && $err !== '') {
                $errors[] = $err;
            }
        }
        orange_restore_log('Recovery validation... FAIL');

        return ['ok' => false, 'verify' => $verify, 'drv' => $drv, 'errors' => $errors];
    }
    orange_restore_log('Recovery validation... OK (score=' . (string) $score . ')');

    return ['ok' => true, 'verify' => $verify, 'drv' => $drv, 'errors' => []];
}

/**
 * Country Recovery Package verify + DRV pre-check. Abort unless overall_result=pass.
 *
 * @return array{ok:bool,verify:array<string,mixed>,drv:array<string,mixed>,errors:list<string>}
 */
function orange_restore_validation_adapter_country_package_precheck(string $packagePath): array
{
    $errors = [];

    orange_restore_log('Country package verify...');
    $verify = orange_country_export_verify_package($packagePath);
    if (!$verify['ok']) {
        $errors = array_merge($errors, $verify['errors']);
        orange_restore_log('Country package verify... FAIL');

        return ['ok' => false, 'verify' => $verify, 'drv' => [], 'errors' => $errors];
    }
    orange_restore_log('Country package verify... OK');

    orange_restore_log('Country recovery validation...');
    $drv = orange_recovery_validate_package($packagePath);
    $overall = (string) ($drv['overall_result'] ?? 'fail');
    if ($overall !== 'pass') {
        $errors[] = 'Country recovery validation overall_result=' . $overall . ' (pass required)';
        foreach ($drv['errors'] ?? [] as $err) {
            if (is_string($err) && $err !== '') {
                $errors[] = $err;
            }
        }
        orange_restore_log('Country recovery validation... FAIL');

        return ['ok' => false, 'verify' => $verify, 'drv' => $drv, 'errors' => $errors];
    }
    orange_restore_log('Country recovery validation... OK (overall_result=pass)');

    return ['ok' => true, 'verify' => $verify, 'drv' => $drv, 'errors' => []];
}

/**
 * Post-restore staging database validation (connectivity + basic sanity vs source manifest).
 *
 * @param array<string, mixed> $sourceManifest
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,table_count:int,database:string}
 */
function orange_restore_validation_adapter_staging_postcheck(
    PDO $pdo,
    string $stagingDb,
    array $sourceManifest
): array {
    $errors = [];
    $warnings = [];

    orange_restore_log('Staging post-validation...');
    orange_restore_staging_assert_safe_target($pdo, $stagingDb);

    $tables = [];
    $st = $pdo->query('SHOW TABLES');
    if ($st !== false) {
        while ($row = $st->fetch(PDO::FETCH_NUM)) {
            if (is_array($row) && isset($row[0])) {
                $tables[] = (string) $row[0];
            }
        }
    }
    if ($tables === []) {
        $errors[] = 'Staging database has no tables after restore.';
    }

    $expectedTables = (int) ($sourceManifest['table_count'] ?? 0);
    if ($expectedTables > 0 && count($tables) < max(1, (int) floor($expectedTables * 0.5))) {
        $warnings[] = 'Staging table count (' . (string) count($tables) . ') is far below manifest table_count (' . (string) $expectedTables . ').';
    }

    try {
        $pdo->query('SELECT 1')->fetchColumn();
    } catch (Throwable $e) {
        $errors[] = 'Staging database connectivity check failed: ' . $e->getMessage();
    }

    $schemaRevision = (int) ($sourceManifest['schema_revision'] ?? 0);
    if ($schemaRevision > 0 && function_exists('orange_backup_schema_revision_live')) {
        $liveRevision = orange_backup_schema_revision_live($pdo);
        if ($liveRevision > 0 && $liveRevision !== $schemaRevision) {
            $warnings[] = 'Staging schema_revision (' . (string) $liveRevision . ') differs from package (' . (string) $schemaRevision . ').';
        }
    }

    $ok = $errors === [];
    orange_restore_log('Staging post-validation... ' . ($ok ? 'OK' : 'FAIL'));

    return [
        'ok' => $ok,
        'errors' => $errors,
        'warnings' => $warnings,
        'table_count' => count($tables),
        'database' => $stagingDb,
    ];
}

/**
 * Build a staging-side DRV-style report payload (filesystem audit artifact; not a backup package).
 *
 * @param array<string, mixed> $sourceDrv
 * @param array<string, mixed> $stagingPostcheck
 * @return array<string, mixed>
 */
function orange_restore_validation_adapter_build_staging_drv_report(
    array $sourceDrv,
    array $stagingPostcheck
): array {
    return [
        'validated_at' => gmdate('c'),
        'validation_target' => 'staging_database',
        'source_package_drv' => [
            'recovery_score' => (int) ($sourceDrv['recovery_score'] ?? 0),
            'overall_result' => (string) ($sourceDrv['overall_result'] ?? ''),
        ],
        'staging_postcheck' => $stagingPostcheck,
        'overall_result' => ($stagingPostcheck['ok'] ?? false) ? 'pass' : 'fail',
    ];
}

/**
 * Post-restore country staging validation (ID preservation + row counts vs package artifacts).
 *
 * @param array<string, mixed> $sourceManifest
 * @param array<string, mixed> $importPlan
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,id_checks:list<array<string,mixed>>,row_count_checks:list<array<string,mixed>>,database:string}
 */
function orange_restore_validation_adapter_country_staging_postcheck(
    PDO $pdo,
    string $stagingDb,
    string $packagePath,
    array $sourceManifest,
    array $importPlan
): array {
    $errors = [];
    $warnings = [];
    $idChecks = [];
    $rowCountChecks = [];

    orange_restore_log('Country staging post-validation...');
    orange_restore_staging_assert_safe_target($pdo, $stagingDb);

    $idSnapshotPath = $packagePath . DIRECTORY_SEPARATOR . 'id_snapshot.json';
    $inventoryPath = $packagePath . DIRECTORY_SEPARATOR . 'table_inventory.json';
    $idSnapshot = is_file($idSnapshotPath)
        ? (json_decode((string) file_get_contents($idSnapshotPath), true) ?: [])
        : [];
    $inventory = is_file($inventoryPath)
        ? (json_decode((string) file_get_contents($inventoryPath), true) ?: [])
        : [];

    $expectedCountryId = (int) ($sourceManifest['country_id'] ?? 0);
    if ($expectedCountryId > 0 && (int) ($idSnapshot['country_id'] ?? -1) !== $expectedCountryId) {
        $errors[] = 'id_snapshot.country_id mismatch with manifest.';
    }

    /** @var array<string, list<int>> $expectedIds */
    $expectedIds = is_array($idSnapshot['tables'] ?? null) ? $idSnapshot['tables'] : [];
    /** @var array<string, int> $expectedRowCounts */
    $expectedRowCounts = is_array($inventory['tables'] ?? null) ? $inventory['tables'] : [];

    foreach ($expectedIds as $tableName => $ids) {
        if (!is_string($tableName) || !is_array($ids) || $ids === []) {
            continue;
        }
        if (!in_array($tableName, $importPlan['tables'] ?? [], true)) {
            continue;
        }
        $quoted = '`' . str_replace('`', '``', $tableName) . '`';
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $quoted . ' WHERE id IN (' . $placeholders . ')');
            $st->execute(array_values(array_map(static fn ($id): int => (int) $id, $ids)));
            $found = (int) ($st->fetchColumn() ?: 0);
            $expected = count($ids);
            $idChecks[] = [
                'table' => $tableName,
                'expected_ids' => $expected,
                'found_ids' => $found,
                'ok' => $found === $expected,
            ];
            if ($found !== $expected) {
                $errors[] = 'ID preservation failed for ' . $tableName . ' (expected ' . (string) $expected . ', found ' . (string) $found . ').';
            }
        } catch (Throwable $e) {
            $errors[] = 'Country ID check failed for ' . $tableName . ': ' . $e->getMessage();
        }
    }

    foreach ($expectedRowCounts as $tableName => $expectedCount) {
        if (!is_string($tableName) || !in_array($tableName, $importPlan['tables'] ?? [], true)) {
            continue;
        }
        $quoted = '`' . str_replace('`', '``', $tableName) . '`';
        try {
            $foundCount = (int) ($pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn() ?: 0);
            $expectedCount = (int) $expectedCount;
            $rowCountChecks[] = [
                'table' => $tableName,
                'expected_rows' => $expectedCount,
                'found_rows' => $foundCount,
                'ok' => $foundCount === $expectedCount,
            ];
            if ($foundCount !== $expectedCount) {
                $errors[] = 'Row count mismatch for ' . $tableName . ' (expected ' . (string) $expectedCount . ', found ' . (string) $foundCount . ').';
            }
        } catch (Throwable $e) {
            $errors[] = 'Row count check failed for ' . $tableName . ': ' . $e->getMessage();
        }
    }

    $schemaRevision = (int) ($sourceManifest['schema_revision'] ?? 0);
    if ($schemaRevision > 0 && function_exists('orange_backup_schema_revision_live')) {
        $liveRevision = orange_backup_schema_revision_live($pdo);
        if ($liveRevision > 0 && $liveRevision !== $schemaRevision) {
            $warnings[] = 'Staging schema_revision (' . (string) $liveRevision . ') differs from package (' . (string) $schemaRevision . ').';
        }
    }

    $ok = $errors === [];
    orange_restore_log('Country staging post-validation... ' . ($ok ? 'OK' : 'FAIL'));

    return [
        'ok' => $ok,
        'errors' => $errors,
        'warnings' => $warnings,
        'id_checks' => $idChecks,
        'row_count_checks' => $rowCountChecks,
        'database' => $stagingDb,
    ];
}

/**
 * @param array<string, mixed> $sourceDrv
 * @param array<string, mixed> $countryStagingPostcheck
 * @return array<string, mixed>
 */
function orange_restore_validation_adapter_build_country_staging_drv_report(
    array $sourceDrv,
    array $countryStagingPostcheck
): array {
    return [
        'validated_at' => gmdate('c'),
        'validation_target' => 'country_staging_database',
        'source_package_drv' => [
            'recovery_score' => (int) ($sourceDrv['recovery_score'] ?? 0),
            'overall_result' => (string) ($sourceDrv['overall_result'] ?? ''),
        ],
        'country_staging_postcheck' => $countryStagingPostcheck,
        'overall_result' => ($countryStagingPostcheck['ok'] ?? false) ? 'pass' : 'fail',
    ];
}

/**
 * Compute SHA-256 checksum of a restore artifact file.
 */
function orange_restore_validation_adapter_file_checksum(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException('Restore artifact missing: ' . $path);
    }

    return orange_backup_sha256_file($path);
}

/**
 * Resolve live package anchor checksum (must match job source_package_checksum).
 *
 * @param array<string, mixed> $manifest
 */
function orange_restore_validation_adapter_live_package_checksum(string $packagePath, array $manifest): string
{
    $checksumFile = $packagePath . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (is_file($checksumFile)) {
        return orange_backup_sha256_file($checksumFile);
    }
    $dumpSha = trim((string) ($manifest['dump_sha256'] ?? ''));
    if ($dumpSha !== '') {
        return $dumpSha;
    }

    throw new RuntimeException('Cannot determine live package checksum.');
}

/**
 * Phase 2C staging validation gate — reads restore_report.json + staging manifest on disk.
 *
 * @return array{
 *   ok:bool,
 *   errors:list<string>,
 *   warnings:list<string>,
 *   report:array<string,mixed>,
 *   manifest_path:string,
 *   manifest_checksum:string,
 *   staging_validation_passed:bool
 * }
 */
function orange_restore_validation_adapter_staging_gate(string $workRoot, array $job): array
{
    $errors = [];
    $warnings = [];
    $jobId = (string) ($job['job_id'] ?? '');

    $reportPath = trim((string) ($job['restore_report_path'] ?? ''));
    if ($reportPath === '' || !is_file($reportPath)) {
        $reportPath = orange_restore_job_report_path($workRoot, $jobId);
    }
    if (!is_file($reportPath)) {
        return [
            'ok' => false,
            'errors' => ['Missing restore_report.json for staging validation gate.'],
            'warnings' => [],
            'report' => [],
            'manifest_path' => '',
            'manifest_checksum' => '',
            'staging_validation_passed' => false,
        ];
    }

    $report = json_decode((string) file_get_contents($reportPath), true);
    if (!is_array($report)) {
        return [
            'ok' => false,
            'errors' => ['Invalid restore_report.json payload.'],
            'warnings' => [],
            'report' => [],
            'manifest_path' => '',
            'manifest_checksum' => '',
            'staging_validation_passed' => false,
        ];
    }

    if ((string) ($report['overall_result'] ?? '') !== 'pass') {
        $errors[] = 'restore_report.overall_result is not pass.';
    }
    if (($report['production_touched'] ?? true) !== false) {
        $errors[] = 'restore_report indicates production_touched=true (forbidden).';
    }

    $jobType = (string) ($job['job_type'] ?? '');
    $stagingPassed = false;
    if ($jobType === ORANGE_RESTORE_JOB_TYPE_FULL) {
        /** @var array<string, mixed> $stagingPost */
        $stagingPost = is_array($report['staging_post_validation'] ?? null) ? $report['staging_post_validation'] : [];
        /** @var array<string, mixed> $stagingDrv */
        $stagingDrv = is_array($report['staging_drv_report'] ?? null) ? $report['staging_drv_report'] : [];
        $stagingPassed = ($stagingPost['ok'] ?? false) === true
            && (string) ($stagingDrv['overall_result'] ?? '') === 'pass';
        if (!$stagingPassed) {
            $errors[] = 'Full staging post-validation or staging DRV report did not pass.';
        }
    } elseif ($jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY) {
        /** @var array<string, mixed> $stagingPost */
        $stagingPost = is_array($report['country_staging_post_validation'] ?? null)
            ? $report['country_staging_post_validation']
            : [];
        /** @var array<string, mixed> $stagingDrv */
        $stagingDrv = is_array($report['country_staging_drv_report'] ?? null)
            ? $report['country_staging_drv_report']
            : [];
        $stagingPassed = ($stagingPost['ok'] ?? false) === true
            && (string) ($stagingDrv['overall_result'] ?? '') === 'pass';
        if (!$stagingPassed) {
            $errors[] = 'Country staging post-validation or staging DRV report did not pass.';
        }
    } else {
        $errors[] = 'Unknown restore job type for staging gate.';
    }

    $manifestPath = trim((string) ($job['staging_restore_manifest_path'] ?? ''));
    if ($manifestPath === '' || !is_file($manifestPath)) {
        $manifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    }
    $manifestChecksum = '';
    if (!is_file($manifestPath)) {
        $errors[] = 'Missing staging_restore_manifest.json.';
    } else {
        $manifestChecksum = orange_restore_validation_adapter_file_checksum($manifestPath);
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'report' => $report,
        'manifest_path' => $manifestPath,
        'manifest_checksum' => $manifestChecksum,
        'staging_validation_passed' => $stagingPassed && $manifestChecksum !== '',
    ];
}
