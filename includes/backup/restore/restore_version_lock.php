<?php

declare(strict_types=1);

/**
 * Phase 3B.3B1 — read-only restore version compatibility lock.
 * Never mutates production; blocks approval when versions cannot be proven compatible.
 */

require_once __DIR__ . '/../recovery_validation.php';
require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_execution_orchestrator.php';
require_once __DIR__ . '/restore_dry_run.php';

const ORANGE_RESTORE_VERSION_LOCK_VERSION = '3B.3B1-version-lock';
const ORANGE_RESTORE_ENGINE_COMPAT_VERSION = '3B.3B1-framework-only';

/**
 * @return array{
 *   ok:bool,
 *   overall:string,
 *   reasons:list<string>,
 *   checks:list<array<string,mixed>>,
 *   versions:array<string,mixed>
 * }
 */
function orange_restore_version_lock_evaluate(
    string $workRoot,
    string $jobId,
    string $backupRoot
): array {
    $job = orange_restore_fw_read($workRoot, $jobId);
    $packageType = (string) ($job['package_type'] ?? '');
    $packageId = (string) ($job['package_id'] ?? '');
    $countryCode = (string) ($job['country_code'] ?? '');

    $checks = [];
    $reasons = [];
    $versions = [
        'version_lock_version' => ORANGE_RESTORE_VERSION_LOCK_VERSION,
        'restore_engine_compat_version' => ORANGE_RESTORE_ENGINE_COMPAT_VERSION,
        'framework_version' => ORANGE_RESTORE_FW_VERSION,
        'orchestrator_version' => ORANGE_RESTORE_EXEC_ORCH_VERSION,
        'plan_version_expected' => ORANGE_RESTORE_EXEC_PLAN_VERSION,
        'drv_engine_expected' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
        'schema_revision_expected' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
        'registry_version_expected' => ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION,
        'dry_run_engine_expected' => ORANGE_RESTORE_DRY_RUN_ENGINE_VERSION,
    ];

    $planOk = false;
    $planVersion = '';
    try {
        $plan = orange_restore_exec_read_plan($workRoot, $jobId);
        $planVersion = (string) ($plan['plan_version'] ?? '');
        $versions['plan_version'] = $planVersion;
        $versions['plan_orchestrator_version'] = (string) ($plan['orchestrator_version'] ?? '');
        if ($planVersion === '') {
            $checks[] = orange_restore_version_lock_check('plan_version', 'fail', 'version_plan_missing');
            $reasons[] = 'version_plan_missing';
        } elseif ($planVersion !== ORANGE_RESTORE_EXEC_PLAN_VERSION) {
            $checks[] = orange_restore_version_lock_check('plan_version', 'fail', 'version_plan_incompatible');
            $reasons[] = 'version_plan_incompatible';
        } else {
            $checks[] = orange_restore_version_lock_check('plan_version', 'pass', 'version_plan_ok');
            $planOk = true;
        }
    } catch (Throwable) {
        $checks[] = orange_restore_version_lock_check('plan_version', 'fail', 'version_plan_missing');
        $reasons[] = 'version_plan_missing';
    }

    $dry = orange_restore_dry_run_read_report($workRoot, $jobId);
    if ($dry === null) {
        $checks[] = orange_restore_version_lock_check('dry_run_engine', 'fail', 'version_dry_run_missing');
        $reasons[] = 'version_dry_run_missing';
    } else {
        $dryEngine = (string) ($dry['dry_run_engine_version'] ?? '');
        $versions['dry_run_engine_version'] = $dryEngine;
        if ($dryEngine === '') {
            $checks[] = orange_restore_version_lock_check('dry_run_engine', 'fail', 'version_dry_run_unknown');
            $reasons[] = 'version_dry_run_unknown';
        } elseif ($dryEngine !== ORANGE_RESTORE_DRY_RUN_ENGINE_VERSION) {
            $checks[] = orange_restore_version_lock_check('dry_run_engine', 'fail', 'version_dry_run_incompatible');
            $reasons[] = 'version_dry_run_incompatible';
        } else {
            $checks[] = orange_restore_version_lock_check('dry_run_engine', 'pass', 'version_dry_run_ok');
        }
    }

    $manifest = null;
    $drvEngine = '';
    try {
        if ($packageType === 'full_disaster') {
            $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
        } elseif ($packageType === 'country_recovery') {
            $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
        } else {
            throw new RuntimeException('Invalid package_type.');
        }
        $manifest = orange_backup_admin_read_json_if_exists($packagePath . DIRECTORY_SEPARATOR . 'manifest.json');
        $drv = orange_backup_admin_read_recovery_validation_report($packagePath, $packageId);
        if (is_array($drv)) {
            $drvEngine = (string) ($drv['engine_version'] ?? $drv['validation_engine_version'] ?? '');
        }
    } catch (Throwable) {
        $manifest = null;
    }

    if (!is_array($manifest)) {
        $checks[] = orange_restore_version_lock_check('manifest', 'fail', 'version_manifest_missing');
        $reasons[] = 'version_manifest_missing';
    } else {
        $pkgVersion = (string) ($manifest['package_version'] ?? '');
        $schema = (int) ($manifest['schema_revision'] ?? 0);
        $backend = strtolower(trim((string) ($manifest['export_backend'] ?? '')));
        $registry = trim((string) ($manifest['registry_version'] ?? ''));
        $versions['package_version'] = $pkgVersion;
        $versions['schema_revision'] = $schema;
        $versions['export_backend'] = $backend;
        $versions['registry_version'] = $registry;

        if ($pkgVersion === '') {
            $checks[] = orange_restore_version_lock_check('package_version', 'fail', 'version_package_unknown');
            $reasons[] = 'version_package_unknown';
        } else {
            $checks[] = orange_restore_version_lock_check('package_version', 'pass', 'version_package_present');
        }

        if ($schema !== ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION) {
            $checks[] = orange_restore_version_lock_check('schema_revision', 'fail', 'version_schema_incompatible');
            $reasons[] = 'version_schema_incompatible';
        } else {
            $checks[] = orange_restore_version_lock_check('schema_revision', 'pass', 'version_schema_ok');
        }

        if ($packageType === 'full_disaster') {
            if ($backend !== '' && $backend !== 'php_pdo') {
                $checks[] = orange_restore_version_lock_check('export_backend', 'fail', 'version_backend_incompatible');
                $reasons[] = 'version_backend_incompatible';
            } else {
                $checks[] = orange_restore_version_lock_check('export_backend', 'pass', 'version_backend_ok');
            }
        } else {
            if ($registry === '') {
                $checks[] = orange_restore_version_lock_check('registry_version', 'fail', 'version_registry_unknown');
                $reasons[] = 'version_registry_unknown';
            } elseif ($registry !== ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION) {
                $checks[] = orange_restore_version_lock_check('registry_version', 'fail', 'version_registry_incompatible');
                $reasons[] = 'version_registry_incompatible';
            } else {
                $checks[] = orange_restore_version_lock_check('registry_version', 'pass', 'version_registry_ok');
            }
        }
    }

    $versions['drv_engine_version'] = $drvEngine;
    if ($drvEngine === '') {
        $checks[] = orange_restore_version_lock_check('drv_engine', 'fail', 'version_drv_unknown');
        $reasons[] = 'version_drv_unknown';
    } elseif ($drvEngine !== ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION) {
        $checks[] = orange_restore_version_lock_check('drv_engine', 'fail', 'version_drv_incompatible');
        $reasons[] = 'version_drv_incompatible';
    } else {
        $checks[] = orange_restore_version_lock_check('drv_engine', 'pass', 'version_drv_ok');
    }

    $ok = $reasons === [] && $planOk;
    $overall = $ok ? 'compatible' : 'incompatible';

    return [
        'ok' => $ok,
        'overall' => $overall,
        'reasons' => array_values(array_unique($reasons)),
        'checks' => $checks,
        'versions' => $versions,
    ];
}

/**
 * @return array{id:string,result:string,code:string}
 */
function orange_restore_version_lock_check(string $id, string $result, string $code): array
{
    return ['id' => $id, 'result' => $result, 'code' => $code];
}
