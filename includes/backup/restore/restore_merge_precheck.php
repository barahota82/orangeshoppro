<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_production_target.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_orchestrator.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';

/**
 * Resolve live rollback anchor checksum from the job's fresh backup package path.
 */
function orange_restore_merge_precheck_live_rollback_checksum(string $anchorPath): string
{
    if (!is_dir($anchorPath)) {
        throw new RuntimeException('Rollback anchor path does not exist: ' . $anchorPath);
    }

    $checksumFile = $anchorPath . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (is_file($checksumFile)) {
        return orange_backup_sha256_file($checksumFile);
    }

    $manifestPath = $anchorPath . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Rollback anchor missing manifest.json and checksums.sha256.');
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Rollback anchor manifest.json is invalid.');
    }

    $dumpSha = trim((string) ($manifest['dump_sha256'] ?? ''));
    if ($dumpSha !== '') {
        return $dumpSha;
    }

    throw new RuntimeException('Cannot determine live rollback anchor checksum.');
}

/**
 * @param array<string, mixed> $binding
 */
function orange_restore_merge_precheck_assert_binding_checksums(array $job, array $binding): void
{
    $expectedPackage = (string) ($binding['source_package_checksum'] ?? '');
    $expectedManifest = (string) ($binding['staging_restore_manifest_checksum'] ?? '');
    $expectedAnchor = (string) ($binding['rollback_anchor_checksum'] ?? '');

    if ($expectedPackage === '' || $expectedManifest === '' || $expectedAnchor === '') {
        throw new RuntimeException('Approval token binding is missing required checksum fields.');
    }

    if (!hash_equals($expectedPackage, (string) ($job['source_package_checksum'] ?? ''))) {
        throw new RuntimeException('Job source_package_checksum differs from approval binding.');
    }

    if (!hash_equals($expectedAnchor, (string) ($job['fresh_backup_checksum'] ?? ''))) {
        throw new RuntimeException('Job rollback anchor checksum differs from approval binding.');
    }
}

/**
 * Phase 2D.1 foundation — production merge precheck (read-only; no production writes).
 *
 * @param array{
 *   project_root:string,
 *   work_root?:string,
 *   job_id:string,
 *   env_override?:array<string,mixed>,
 *   merge_pdo_override?:?PDO,
 *   staging_pdo_override?:?PDO
 * } $options
 * @return array<string, mixed>
 */
function orange_restore_merge_precheck_run(array $options): array
{
    $projectRoot = (string) ($options['project_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));

    if ($projectRoot === '' || $jobId === '') {
        throw new InvalidArgumentException('project_root and job_id are required.');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }

    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = (string) ($options['work_root'] ?? '');
    if ($workRoot === '') {
        $workRoot = orange_restore_resolve_work_root($env);
    }

    $job = orange_restore_job_read($workRoot, $jobId);

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_merge_event($job, 'merge_precheck_started', 'started', [
        'production_writes' => false,
    ]));

    try {
        if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
            throw new RuntimeException('Merge foundation precheck applies to full_disaster jobs only.');
        }

        $status = (string) ($job['status'] ?? '');
        if ($status !== ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE) {
            throw new RuntimeException('Restore job is not approved_for_merge (status=' . $status . ').');
        }

        orange_restore_orchestrator_assert_approval_window_open($job);

        if (!(bool) ($job['production_merge_approved'] ?? false)) {
            throw new RuntimeException('Job production_merge_approved flag is false.');
        }
        if ((string) ($job['owner_approval_at'] ?? '') === '') {
            throw new RuntimeException('Owner approval timestamp is missing.');
        }
        if ((string) ($job['approval_token_consumed_at'] ?? '') === '') {
            throw new RuntimeException('Approval token has not been consumed.');
        }
        if ((string) ($job['approval_token_hash'] ?? '') === '') {
            throw new RuntimeException('Approval token hash is missing on job.');
        }

        /** @var array<string, mixed> $binding */
        $binding = is_array($job['approval_token_binding'] ?? null) ? $job['approval_token_binding'] : [];
        orange_restore_merge_precheck_assert_binding_checksums($job, $binding);

        orange_restore_orchestrator_assert_rollback_anchor($job);

        orange_restore_orchestrator_verify_live_package_checksum($backupRoot, $job);

        $liveAnchorChecksum = orange_restore_merge_precheck_live_rollback_checksum(
            (string) ($job['fresh_backup_path'] ?? '')
        );
        if (!hash_equals((string) ($job['fresh_backup_checksum'] ?? ''), $liveAnchorChecksum)) {
            throw new RuntimeException('Rollback anchor checksum mismatch (job vs live anchor package).');
        }
        if (!hash_equals((string) ($binding['rollback_anchor_checksum'] ?? ''), $liveAnchorChecksum)) {
            throw new RuntimeException('Rollback anchor checksum mismatch (binding vs live anchor package).');
        }

        $stagingGate = orange_restore_validation_adapter_staging_gate($workRoot, $job);
        if (!$stagingGate['ok']) {
            throw new RuntimeException(
                'Staging validation gate failed: ' . implode('; ', $stagingGate['errors'])
            );
        }

        $liveManifestChecksum = (string) ($stagingGate['manifest_checksum'] ?? '');
        if (!hash_equals((string) ($binding['staging_restore_manifest_checksum'] ?? ''), $liveManifestChecksum)) {
            throw new RuntimeException('Staging manifest checksum mismatch (binding vs live manifest).');
        }

        $productionDb = orange_restore_production_db_name($projectRoot);
        $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
        if (strcasecmp($productionDb, $stagingDb) === 0) {
            throw new RuntimeException('Production and staging database names must differ.');
        }

        orange_restore_merge_credentials($env, $projectRoot);

        $mergePdo = $options['merge_pdo_override'] ?? null;
        $productionTarget = orange_restore_production_verify_target(
            $projectRoot,
            $env,
            $mergePdo instanceof PDO ? $mergePdo : null
        );

        $stagingPdo = $options['staging_pdo_override'] ?? null;
        if ($stagingPdo instanceof PDO) {
            orange_restore_staging_assert_safe_target($stagingPdo, $stagingDb);
            orange_restore_staging_assert_no_production_privileges($stagingPdo, $stagingDb, $productionDb);
            $stagingIdentity = [
                'staging_db' => $stagingDb,
                'session_database' => (string) ($stagingPdo->query('SELECT DATABASE()')->fetchColumn() ?: ''),
            ];
        } else {
            $stagingIdentity = orange_restore_staging_confirm_target($projectRoot, $env);
        }

        if (strcasecmp((string) ($stagingIdentity['staging_db'] ?? ''), $productionDb) === 0) {
            throw new RuntimeException('Staging DB identity equals production DB name.');
        }

        orange_restore_lock_assert_held_by_job($workRoot, $jobId);

        $maintenance = orange_restore_merge_maintenance_status($workRoot);
        if ($maintenance['active']) {
            $activeJob = (string) ($maintenance['payload']['job_id'] ?? '');
            throw new RuntimeException(
                'Restore maintenance mode is already active'
                . ($activeJob !== '' ? ' (job_id=' . $activeJob . ').' : '.')
            );
        }

        $now = gmdate('c');
        $job = orange_restore_job_merge_foundation_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
            [
                'merge_precheck_passed_at' => $now,
                'merge_precheck_production_db' => $productionTarget['production_db'] ?? $productionDb,
                'merge_precheck_staging_db' => $stagingIdentity['staging_db'] ?? $stagingDb,
                'result' => 'merge_precheck_passed',
            ]
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_merge_event($job, 'merge_precheck_passed', 'pass', [
            'production_db' => $productionTarget['production_db'] ?? $productionDb,
            'staging_db' => $stagingIdentity['staging_db'] ?? $stagingDb,
            'source_package_checksum' => (string) ($job['source_package_checksum'] ?? ''),
            'staging_restore_manifest_checksum' => $liveManifestChecksum,
            'rollback_anchor_checksum' => $liveAnchorChecksum,
            'production_writes' => false,
        ]));

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
            'job' => $job,
            'production_target' => $productionTarget,
            'staging_identity' => $stagingIdentity,
            'staging_gate' => $stagingGate,
            'production_writes' => false,
            'merge_executed' => false,
        ];
    } catch (Throwable $e) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_merge_event($job, 'merge_precheck_failed', 'failed', [
            'error' => $e->getMessage(),
            'production_writes' => false,
        ]));
        throw $e;
    }
}
