<?php

declare(strict_types=1);

/**
 * Phase 3B.2B — Restore Dry Run Engine.
 *
 * Validates every precondition a real restore would check and writes dry_run_report.json.
 * Never restores DB/uploads, never extracts archives, never executes SQL, never changes schema.
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../recovery_validation.php';
require_once __DIR__ . '/../backup_full.php';
require_once __DIR__ . '/../backup_validate.php';

const ORANGE_RESTORE_DRY_RUN_REPORT_FILE = 'dry_run_report.json';
const ORANGE_RESTORE_DRY_RUN_MIN_FREE_BYTES = 268435456; // 256 MiB
const ORANGE_RESTORE_DRY_RUN_ENGINE_VERSION = '3B.2B-dry-run';

/**
 * @param array{
 *   backup_root:string,
 *   project_root?:string,
 *   disk_free_bytes_override?:int|null,
 *   operator_username?:string
 * } $context
 * @return array<string, mixed>
 */
function orange_restore_dry_run_execute(string $workRoot, string $jobId, array $context): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    $allowedStart = [
        ORANGE_RESTORE_FW_STATUS_WAITING_CONFIRMATION,
        ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED,
        ORANGE_RESTORE_FW_STATUS_DRY_FAILED,
        ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED,
        ORANGE_RESTORE_FW_STATUS_EXECUTION_FAILED,
    ];
    if (!in_array($status, $allowedStart, true)) {
        throw new RuntimeException('Dry run not allowed in status: ' . $status);
    }

    $backupRoot = (string) ($context['backup_root'] ?? '');
    if ($backupRoot === '') {
        throw new RuntimeException('Backup root required for dry run.');
    }

    $operator = (string) ($context['operator_username'] ?? $job['created_by'] ?? '');
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_DRY_RUNNING,
        ORANGE_RESTORE_FW_PHASE_DRY_RUNNING,
        5,
        'Dry run started',
        'Dry Run Started'
    );

    $checks = [];
    $blocking = [];
    $warnings = [];
    $packageType = (string) ($job['package_type'] ?? '');
    $packageId = (string) ($job['package_id'] ?? '');
    $countryCode = (string) ($job['country_code'] ?? '');

    try {
        // --- Package resolution ---
        orange_restore_fw_set_progress($workRoot, $jobId, 10, 'Resolving package');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: package_resolve',
            'result' => 'ok',
            'progress' => 10,
            'operator_username' => $operator,
        ]);

        $packagePath = '';
        $packageExists = false;
        try {
            if ($packageType === 'full_disaster') {
                $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
            } elseif ($packageType === 'country_recovery') {
                $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
            } else {
                throw new RuntimeException('Invalid package_type.');
            }
            $packageExists = is_dir($packagePath);
        } catch (Throwable $e) {
            $checks[] = orange_restore_dry_run_check('package_exists', 'fail', $e->getMessage(), true);
            $blocking[] = $e->getMessage();
        }
        if ($packageExists) {
            $checks[] = orange_restore_dry_run_check('package_exists', 'pass', 'Package directory resolved.', false);
        } elseif ($checks === [] || ($checks[count($checks) - 1]['id'] ?? '') !== 'package_exists') {
            $checks[] = orange_restore_dry_run_check('package_exists', 'fail', 'Package directory missing.', true);
            $blocking[] = 'Package directory missing.';
        }

        // --- Manifest ---
        orange_restore_fw_set_progress($workRoot, $jobId, 20, 'Validating manifest');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: manifest',
            'result' => 'ok',
            'progress' => 20,
            'operator_username' => $operator,
        ]);
        $manifest = null;
        if ($packageExists) {
            $manifestPath = $packagePath . DIRECTORY_SEPARATOR . 'manifest.json';
            if (!is_file($manifestPath)) {
                $checks[] = orange_restore_dry_run_check('manifest', 'fail', 'manifest.json missing.', true);
                $blocking[] = 'manifest.json missing.';
            } else {
                $raw = file_get_contents($manifestPath);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($decoded)) {
                    $checks[] = orange_restore_dry_run_check('manifest', 'fail', 'manifest.json corrupt or invalid JSON.', true);
                    $blocking[] = 'manifest.json corrupt or invalid JSON.';
                } else {
                    $manifest = $decoded;
                    $declaredType = (string) ($manifest['package_type'] ?? '');
                    if ($declaredType !== $packageType) {
                        $checks[] = orange_restore_dry_run_check('manifest', 'fail', 'Package type mismatch in manifest.', true);
                        $blocking[] = 'Package type mismatch in manifest.';
                    } else {
                        $checks[] = orange_restore_dry_run_check('manifest', 'pass', 'manifest.json valid.', false);
                    }
                }
            }
        } else {
            $checks[] = orange_restore_dry_run_check('manifest', 'fail', 'Skipped — package missing.', true);
        }

        // --- Health ---
        orange_restore_fw_set_progress($workRoot, $jobId, 30, 'Validating health');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: health',
            'result' => 'ok',
            'progress' => 30,
            'operator_username' => $operator,
        ]);
        if ($packageExists) {
            $health = orange_backup_admin_read_json_if_exists($packagePath . DIRECTORY_SEPARATOR . 'health.json');
            if ($health === null) {
                $checks[] = orange_restore_dry_run_check('health', 'fail', 'health.json missing.', true);
                $blocking[] = 'health.json missing.';
            } elseif (($health['package_status'] ?? '') !== 'healthy') {
                $statusLabel = (string) ($health['package_status'] ?? 'unknown');
                if ($statusLabel === 'warning') {
                    $checks[] = orange_restore_dry_run_check('health', 'warning', 'Package health is warning.', false);
                    $warnings[] = 'Package health is warning.';
                } else {
                    $checks[] = orange_restore_dry_run_check('health', 'fail', 'Package health not healthy (' . $statusLabel . ').', true);
                    $blocking[] = 'Package health not healthy.';
                }
            } else {
                $checks[] = orange_restore_dry_run_check('health', 'pass', 'Package health is healthy.', false);
            }
        } else {
            $checks[] = orange_restore_dry_run_check('health', 'fail', 'Skipped — package missing.', true);
        }

        // --- Checksums / verify ---
        orange_restore_fw_set_progress($workRoot, $jobId, 40, 'Validating checksums');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: checksum',
            'result' => 'ok',
            'progress' => 40,
            'operator_username' => $operator,
        ]);
        $verify = ['ok' => false, 'errors' => ['Package missing'], 'warnings' => []];
        if ($packageExists) {
            $verify = $packageType === 'country_recovery'
                ? orange_country_export_verify_package($packagePath)
                : orange_backup_verify_full_package($packagePath);
            if (!empty($verify['ok'])) {
                $checks[] = orange_restore_dry_run_check('checksum', 'pass', 'Package verify/checksums passed.', false);
            } else {
                $err = implode('; ', array_map('strval', $verify['errors'] ?? ['verify failed']));
                $checks[] = orange_restore_dry_run_check('checksum', 'fail', $err, true);
                $blocking[] = $err;
            }
            foreach ($verify['warnings'] ?? [] as $w) {
                if (is_string($w) && $w !== '') {
                    $warnings[] = $w;
                }
            }
        } else {
            $checks[] = orange_restore_dry_run_check('checksum', 'fail', 'Skipped — package missing.', true);
        }

        // --- DRV ---
        orange_restore_fw_set_progress($workRoot, $jobId, 55, 'Validating DRV report');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: drv',
            'result' => 'ok',
            'progress' => 55,
            'operator_username' => $operator,
        ]);
        $drvResult = 'missing';
        $drvScore = null;
        if ($packageExists) {
            $recovery = orange_backup_admin_read_recovery_validation_report($packagePath, $packageId);
            if ($recovery === null) {
                $checks[] = orange_restore_dry_run_check('drv', 'fail', 'DRV report missing.', true);
                $blocking[] = 'DRV report missing.';
            } else {
                $drvResult = strtolower(trim((string) ($recovery['overall_result'] ?? '')));
                if (array_key_exists('recovery_score', $recovery) && is_numeric($recovery['recovery_score'])) {
                    $drvScore = (int) $recovery['recovery_score'];
                }
                if ($packageType === 'full_disaster') {
                    if ($drvScore !== null && $drvScore < 70) {
                        $checks[] = orange_restore_dry_run_check('drv', 'fail', 'DRV score below threshold.', true);
                        $blocking[] = 'DRV score below threshold.';
                    } elseif ($drvResult === 'fail') {
                        $checks[] = orange_restore_dry_run_check('drv', 'fail', 'DRV overall_result=fail.', true);
                        $blocking[] = 'DRV overall_result=fail.';
                    } elseif ($drvResult === 'warning') {
                        $checks[] = orange_restore_dry_run_check('drv', 'warning', 'DRV overall_result=warning.', false);
                        $warnings[] = 'DRV overall_result=warning.';
                    } elseif ($drvResult === 'pass' || ($drvScore !== null && $drvScore >= 70)) {
                        $checks[] = orange_restore_dry_run_check('drv', 'pass', 'DRV accepted for dry run.', false);
                    } else {
                        $checks[] = orange_restore_dry_run_check('drv', 'fail', 'DRV result unknown.', true);
                        $blocking[] = 'DRV result unknown.';
                    }
                } else {
                    if ($drvResult !== 'pass') {
                        $checks[] = orange_restore_dry_run_check('drv', 'fail', 'Country DRV requires overall_result=pass.', true);
                        $blocking[] = 'Country DRV requires overall_result=pass.';
                    } else {
                        $checks[] = orange_restore_dry_run_check('drv', 'pass', 'Country DRV pass.', false);
                    }
                }
            }
        } else {
            $checks[] = orange_restore_dry_run_check('drv', 'fail', 'Skipped — package missing.', true);
        }

        // --- Schema / backend / registry / package version ---
        orange_restore_fw_set_progress($workRoot, $jobId, 65, 'Checking compatibility');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: compatibility',
            'result' => 'ok',
            'progress' => 65,
            'operator_username' => $operator,
        ]);
        if (is_array($manifest)) {
            $schema = (int) ($manifest['schema_revision'] ?? 0);
            if ($schema !== ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION) {
                $checks[] = orange_restore_dry_run_check(
                    'schema',
                    'fail',
                    'Schema mismatch (expected ' . ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION . ', got ' . $schema . ').',
                    true
                );
                $blocking[] = 'Schema mismatch.';
            } else {
                $checks[] = orange_restore_dry_run_check('schema', 'pass', 'Schema revision compatible.', false);
            }

            $backend = strtolower(trim((string) ($manifest['export_backend'] ?? '')));
            if ($packageType === 'full_disaster') {
                if ($backend !== '' && $backend !== 'php_pdo') {
                    $checks[] = orange_restore_dry_run_check('backend', 'fail', 'Backend unsupported for restore: ' . $backend, true);
                    $blocking[] = 'Backend unsupported.';
                } else {
                    $checks[] = orange_restore_dry_run_check('backend', 'pass', 'Backend compatible.', false);
                }
            } else {
                $checks[] = orange_restore_dry_run_check('backend', 'pass', 'Country package backend not required.', false);
            }

            $pkgVersion = trim((string) ($manifest['package_version'] ?? ''));
            if ($pkgVersion === '') {
                $checks[] = orange_restore_dry_run_check('package_version', 'warning', 'package_version missing.', false);
                $warnings[] = 'package_version missing.';
            } else {
                $checks[] = orange_restore_dry_run_check('package_version', 'pass', 'package_version present.', false);
            }

            if ($packageType === 'country_recovery') {
                $registry = trim((string) ($manifest['registry_version'] ?? ''));
                if ($registry === '') {
                    $checks[] = orange_restore_dry_run_check('registry', 'fail', 'registry_version missing.', true);
                    $blocking[] = 'registry_version missing.';
                } elseif ($registry !== ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION) {
                    $checks[] = orange_restore_dry_run_check('registry', 'fail', 'Registry version mismatch.', true);
                    $blocking[] = 'Registry version mismatch.';
                } else {
                    $checks[] = orange_restore_dry_run_check('registry', 'pass', 'Registry version compatible.', false);
                }
            } else {
                $checks[] = orange_restore_dry_run_check('registry', 'pass', 'Registry not required for full package.', false);
            }
        } else {
            $checks[] = orange_restore_dry_run_check('schema', 'fail', 'Skipped — manifest unavailable.', true);
            $checks[] = orange_restore_dry_run_check('backend', 'fail', 'Skipped — manifest unavailable.', true);
            $checks[] = orange_restore_dry_run_check('package_version', 'fail', 'Skipped — manifest unavailable.', true);
            if ($packageType === 'country_recovery') {
                $checks[] = orange_restore_dry_run_check('registry', 'fail', 'Skipped — manifest unavailable.', true);
            }
        }

        $checks[] = orange_restore_dry_run_check(
            'restore_engine_version',
            'pass',
            'Dry-run engine ' . ORANGE_RESTORE_DRY_RUN_ENGINE_VERSION . ' / framework ' . ORANGE_RESTORE_FW_VERSION,
            false
        );

        // --- Environment ---
        orange_restore_fw_set_progress($workRoot, $jobId, 75, 'Checking environment');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: environment',
            'result' => 'ok',
            'progress' => 75,
            'operator_username' => $operator,
        ]);

        $diskFree = array_key_exists('disk_free_bytes_override', $context) && $context['disk_free_bytes_override'] !== null
            ? (int) $context['disk_free_bytes_override']
            : (int) (@disk_free_space($workRoot) ?: 0);
        if ($diskFree < ORANGE_RESTORE_DRY_RUN_MIN_FREE_BYTES) {
            $checks[] = orange_restore_dry_run_check('free_disk_space', 'fail', 'Insufficient free disk space for restore workspace.', true);
            $blocking[] = 'Insufficient free disk space.';
        } else {
            $checks[] = orange_restore_dry_run_check('free_disk_space', 'pass', 'Free disk space acceptable.', false);
        }

        if (!is_dir($workRoot) || !is_writable($workRoot)) {
            $checks[] = orange_restore_dry_run_check('writable_directories', 'fail', 'Restore work root not writable.', true);
            $blocking[] = 'Restore work root not writable.';
        } else {
            $checks[] = orange_restore_dry_run_check('writable_directories', 'pass', 'Restore work root writable.', false);
        }

        $fwRoot = orange_restore_fw_root($workRoot);
        if (!is_dir($fwRoot) || !is_writable($fwRoot)) {
            $checks[] = orange_restore_dry_run_check('restore_workspace', 'fail', 'Framework workspace not writable.', true);
            $blocking[] = 'Framework workspace not writable.';
        } else {
            $checks[] = orange_restore_dry_run_check('restore_workspace', 'pass', 'Framework workspace ready.', false);
        }

        // Required files / archive readability / completeness (read-only probes)
        orange_restore_fw_set_progress($workRoot, $jobId, 85, 'Probing archives (read-only)');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: archives',
            'result' => 'ok',
            'progress' => 85,
            'operator_username' => $operator,
        ]);

        $filesWouldChange = 0;
        $sqlObjects = 0;
        $uploadsAffected = 0;
        $estimatedSeconds = 120;

        if ($packageExists && is_array($manifest)) {
            if ($packageType === 'full_disaster') {
                $dumpFile = (string) ($manifest['dump_file'] ?? '');
                $uploadsFile = (string) ($manifest['uploads_file'] ?? '');
                $dumpPath = $dumpFile !== '' ? $packagePath . DIRECTORY_SEPARATOR . $dumpFile : '';
                $uploadsPath = $uploadsFile !== '' ? $packagePath . DIRECTORY_SEPARATOR . $uploadsFile : '';

                if ($dumpPath === '' || !is_file($dumpPath) || !is_readable($dumpPath)) {
                    $checks[] = orange_restore_dry_run_check('required_files', 'fail', 'SQL dump missing or unreadable.', true);
                    $blocking[] = 'SQL dump missing or unreadable.';
                } else {
                    $checks[] = orange_restore_dry_run_check('required_files', 'pass', 'Required dump file readable.', false);
                    $sqlObjects = max(1, (int) ($manifest['table_count'] ?? 0));
                    $estimatedSeconds += (int) min(3600, max(30, filesize($dumpPath) / 1_000_000 * 2));
                }

                if ($uploadsPath === '' || !is_file($uploadsPath) || !is_readable($uploadsPath)) {
                    $checks[] = orange_restore_dry_run_check('uploads_archive', 'fail', 'Uploads archive missing or unreadable.', true);
                    $blocking[] = 'Uploads archive missing or unreadable.';
                } else {
                    $archiveOk = orange_restore_dry_run_archive_readable($uploadsPath);
                    if (!$archiveOk['ok']) {
                        $checks[] = orange_restore_dry_run_check('archive_readability', 'fail', $archiveOk['message'], true);
                        $blocking[] = $archiveOk['message'];
                    } else {
                        $checks[] = orange_restore_dry_run_check('archive_readability', 'pass', 'Uploads archive readable (not extracted).', false);
                        $uploadsAffected = (int) ($archiveOk['entry_count'] ?? 0);
                        $filesWouldChange = $uploadsAffected;
                    }
                }

                $checksumFile = $packagePath . DIRECTORY_SEPARATOR . 'checksums.sha256';
                if (!is_file($checksumFile)) {
                    $checks[] = orange_restore_dry_run_check('package_completeness', 'warning', 'checksums.sha256 missing.', false);
                    $warnings[] = 'checksums.sha256 missing.';
                } else {
                    $checks[] = orange_restore_dry_run_check('package_completeness', 'pass', 'Package completeness markers present.', false);
                }
            } else {
                $sqlDir = $packagePath . DIRECTORY_SEPARATOR . 'sql';
                $sqlFiles = is_dir($sqlDir) ? (glob($sqlDir . DIRECTORY_SEPARATOR . '*.sql') ?: []) : [];
                $uploadZip = $packagePath . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
                $graph = $packagePath . DIRECTORY_SEPARATOR . 'dependency_graph.json';
                $inventory = $packagePath . DIRECTORY_SEPARATOR . 'table_inventory.json';

                if ($sqlFiles === []) {
                    $checks[] = orange_restore_dry_run_check('required_files', 'fail', 'Country SQL files missing.', true);
                    $blocking[] = 'Country SQL files missing.';
                } else {
                    $checks[] = orange_restore_dry_run_check('required_files', 'pass', 'Country SQL files present.', false);
                    $sqlObjects = count($sqlFiles);
                }

                if (!is_file($uploadZip) || !is_readable($uploadZip)) {
                    $checks[] = orange_restore_dry_run_check('uploads_archive', 'fail', 'Country uploads archive missing or unreadable.', true);
                    $blocking[] = 'Country uploads archive missing or unreadable.';
                } else {
                    $archiveOk = orange_restore_dry_run_archive_readable($uploadZip);
                    if (!$archiveOk['ok']) {
                        $checks[] = orange_restore_dry_run_check('archive_readability', 'fail', $archiveOk['message'], true);
                        $blocking[] = $archiveOk['message'];
                    } else {
                        $checks[] = orange_restore_dry_run_check('archive_readability', 'pass', 'Country uploads archive readable (not extracted).', false);
                        $uploadsAffected = (int) ($archiveOk['entry_count'] ?? 0);
                        $filesWouldChange = $uploadsAffected;
                    }
                }

                if (!is_file($graph) || !is_file($inventory)) {
                    $checks[] = orange_restore_dry_run_check('package_completeness', 'fail', 'dependency_graph/table_inventory missing.', true);
                    $blocking[] = 'Country package completeness markers missing.';
                } else {
                    $checks[] = orange_restore_dry_run_check('package_completeness', 'pass', 'Country package completeness OK.', false);
                }
                $estimatedSeconds += 60 + ($sqlObjects * 2);
            }
        } else {
            $checks[] = orange_restore_dry_run_check('required_files', 'fail', 'Skipped — package/manifest unavailable.', true);
            $checks[] = orange_restore_dry_run_check('archive_readability', 'fail', 'Skipped — package unavailable.', true);
            $checks[] = orange_restore_dry_run_check('package_completeness', 'fail', 'Skipped — package unavailable.', true);
        }

        // --- Simulation estimates (never execute) ---
        orange_restore_fw_set_progress($workRoot, $jobId, 95, 'Building simulation estimates');
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Stage: simulation',
            'result' => 'ok',
            'progress' => 95,
            'operator_username' => $operator,
        ]);

        $overall = orange_restore_dry_run_overall($checks);
        $report = [
            'generated_at' => gmdate('c'),
            'job_id' => $jobId,
            'package_id' => $packageId,
            'package_type' => $packageType,
            'country_code' => $countryCode !== '' ? $countryCode : null,
            'dry_run_engine_version' => ORANGE_RESTORE_DRY_RUN_ENGINE_VERSION,
            'framework_version' => ORANGE_RESTORE_FW_VERSION,
            'overall_result' => $overall,
            'checks' => $checks,
            'summary' => [
                'pass_count' => count(array_filter($checks, static fn ($c) => ($c['result'] ?? '') === 'pass')),
                'warning_count' => count(array_filter($checks, static fn ($c) => ($c['result'] ?? '') === 'warning')),
                'fail_count' => count(array_filter($checks, static fn ($c) => ($c['result'] ?? '') === 'fail')),
                'drv_result' => $drvResult,
                'drv_score' => $drvScore,
            ],
            'estimated_restore_steps' => [
                'validate_package',
                'prepare_workspace',
                'restore_database_objects',
                'restore_uploads',
                'post_validation',
            ],
            'estimated_duration' => [
                'seconds' => $estimatedSeconds,
                'human' => orange_restore_dry_run_duration_human($estimatedSeconds),
            ],
            'simulation' => [
                'files_that_would_change' => $filesWouldChange,
                'sql_objects_that_would_execute' => $sqlObjects,
                'uploads_affected' => $uploadsAffected,
                'operations_performed' => 0,
                'note' => 'Estimates only — no restore operations were executed.',
            ],
            'blocking_errors' => array_values(array_unique($blocking)),
            'warnings' => array_values(array_unique($warnings)),
            'execution_performed' => false,
        ];

        $reportPath = orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_DRY_RUN_REPORT_FILE;
        orange_backup_write_json($reportPath, $report);

        $finalStatus = $overall === 'FAIL'
            ? ORANGE_RESTORE_FW_STATUS_DRY_FAILED
            : ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED;
        $finalPhase = $overall === 'FAIL'
            ? ORANGE_RESTORE_FW_PHASE_DRY_FAILED
            : ORANGE_RESTORE_FW_PHASE_DRY_COMPLETED;
        $finalMessage = $overall === 'FAIL' ? 'Dry run failed' : 'Dry run completed';

        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            $finalStatus,
            $finalPhase,
            100,
            $finalMessage,
            $overall === 'FAIL' ? 'Dry Run Failed' : 'Dry Run Completed'
        );
        $job['dry_run_overall_result'] = $overall;
        $job['dry_run_report_file'] = ORANGE_RESTORE_DRY_RUN_REPORT_FILE;
        try {
            require_once __DIR__ . '/restore_execution_orchestrator.php';
            $job['dry_run_fingerprint'] = orange_restore_exec_file_sha256($reportPath);
            $fp = orange_restore_exec_build_package_fingerprint(
                (string) ($context['backup_root'] ?? ''),
                $packageType,
                $packageId,
                $countryCode !== '' ? $countryCode : null
            );
            $job['package_fingerprint'] = (string) ($fp['fingerprint'] ?? '');
        } catch (Throwable) {
            // Fingerprint binding is best-effort at dry-run time; prepare will recompute.
        }
        $job['execution_started'] = false;
        $job['requires_final_approval'] = false;
        orange_restore_fw_write($workRoot, $job);
        orange_restore_fw_release_lock($workRoot, $jobId);

        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Report Written',
            'result' => $overall === 'FAIL' ? 'fail' : 'ok',
            'overall_result' => $overall,
            'operator_username' => $operator,
        ]);

        return [
            'job' => orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId)),
            'report' => orange_restore_dry_run_public_report($report),
        ];
    } catch (Throwable $e) {
        try {
            orange_restore_fw_transition(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_FW_STATUS_DRY_FAILED,
                ORANGE_RESTORE_FW_PHASE_DRY_FAILED,
                100,
                'Dry run failed',
                'Dry Run Failed'
            );
            orange_restore_fw_release_lock($workRoot, $jobId);
        } catch (Throwable) {
            // ignore secondary failure
        }
        orange_restore_fw_audit_append($workRoot, $jobId, [
            'event' => 'Dry Run Exception',
            'result' => 'fail',
            'message' => $e->getMessage(),
            'operator_username' => $operator,
        ]);
        throw $e;
    }
}

/**
 * @return array{id:string,result:string,message:string,blocking:bool}
 */
function orange_restore_dry_run_check(string $id, string $result, string $message, bool $blocking): array
{
    return [
        'id' => $id,
        'result' => $result,
        'message' => $message,
        'blocking' => $blocking,
    ];
}

/**
 * @param list<array<string, mixed>> $checks
 */
function orange_restore_dry_run_overall(array $checks): string
{
    $hasFail = false;
    $hasWarn = false;
    foreach ($checks as $check) {
        $r = (string) ($check['result'] ?? '');
        if ($r === 'fail') {
            $hasFail = true;
        } elseif ($r === 'warning') {
            $hasWarn = true;
        }
    }
    if ($hasFail) {
        return 'FAIL';
    }
    if ($hasWarn) {
        return 'WARNING';
    }

    return 'PASS';
}

/**
 * Read-only ZIP probe — open/list only, never extract.
 *
 * @return array{ok:bool,message:string,entry_count?:int}
 */
function orange_restore_dry_run_archive_readable(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        // Fallback: readable file size probe without extraction.
        $size = @filesize($zipPath);
        if ($size === false || $size < 4) {
            return ['ok' => false, 'message' => 'Archive unreadable or empty.'];
        }

        return ['ok' => true, 'message' => 'Archive file readable (ZipArchive unavailable).', 'entry_count' => 0];
    }

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath, ZipArchive::RDONLY);
    if ($opened !== true) {
        return ['ok' => false, 'message' => 'Archive not readable as ZIP.'];
    }
    $count = (int) $zip->numFiles;
    $zip->close();

    return ['ok' => true, 'message' => 'Archive readable.', 'entry_count' => $count];
}

function orange_restore_dry_run_duration_human(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    $m = (int) floor($seconds / 60);
    $s = $seconds % 60;

    return $m . 'm ' . $s . 's';
}

function orange_restore_dry_run_report_path(string $workRoot, string $jobId): string
{
    return orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . ORANGE_RESTORE_DRY_RUN_REPORT_FILE;
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_dry_run_read_report(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_dry_run_report_path($workRoot, $jobId);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? orange_restore_dry_run_public_report($decoded) : null;
}

/**
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function orange_restore_dry_run_public_report(array $report): array
{
    unset($report['package_path'], $report['absolute_paths']);

    return $report;
}
