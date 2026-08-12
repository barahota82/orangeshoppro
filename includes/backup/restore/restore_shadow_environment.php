<?php

declare(strict_types=1);

/**
 * Shared shadow-environment service for Restore Center Steps 7–10.
 *
 * Architecture (selected): C — application-owned private MySQL instance
 * (private shadow engine). One reconnectable environment per restore job.
 *
 * Rejected for live Full Step-7 without Owner manual:
 *   A — repository-native country CRP shadow (country packages only)
 *   B — dedicated ORANGE_RESTORE_STAGING_DB* channel (Owner GRANT/env)
 *
 * Never Production CREATE DATABASE / Production DB_USER provisioning.
 */

require_once __DIR__ . '/restore_private_shadow_engine.php';
require_once __DIR__ . '/restore_shadow_db.php';

const ORANGE_RESTORE_SHADOW_ENVIRONMENT_ARCHITECTURE = 'C_PRIVATE_MYSQL_INSTANCE';
const ORANGE_RESTORE_SHADOW_ENVIRONMENT_VERSION = '1';

/**
 * Bind job context so credential resolution uses the private engine only.
 */
function orange_restore_shadow_environment_bind(string $workRoot, string $jobId): void
{
    $GLOBALS['orange_restore_private_engine_context'] = [
        'work_root' => $workRoot,
        'job_id' => $jobId,
    ];
}

/**
 * @return array{
 *   ok:bool,
 *   code:string,
 *   architecture:string,
 *   shadow_db:string,
 *   identity_hash:string,
 *   engine_ready:bool,
 *   reconnect:bool,
 *   engine_pid:int
 * }
 */
function orange_restore_shadow_environment_ensure(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    bool $allowProvision = false
): array {
    orange_restore_shadow_environment_bind($workRoot, $jobId);
    $meta = orange_restore_shadow_load_meta($workRoot, $jobId) ?? [];
    $env = orange_backup_load_env_array($projectRoot);
    if (isset($GLOBALS['orange_shadow_env_override']) && is_array($GLOBALS['orange_shadow_env_override'])) {
        $env = array_merge($env, $GLOBALS['orange_shadow_env_override']);
    }
    $resolved = orange_restore_shadow_resolve_target($env, $projectRoot, $jobId, $meta);
    if (!($resolved['ok'] ?? false) || trim((string) ($resolved['shadow_db'] ?? '')) === '') {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE,
            'architecture' => ORANGE_RESTORE_SHADOW_ENVIRONMENT_ARCHITECTURE,
            'shadow_db' => '',
            'identity_hash' => '',
            'engine_ready' => false,
            'reconnect' => false,
            'engine_pid' => 0,
        ];
    }
    $shadowDb = (string) $resolved['shadow_db'];
    $identity = (string) ($resolved['identity_hash'] ?? '');

    if (orange_restore_private_engine_runtime_healthy($workRoot, $jobId)) {
        $state = orange_restore_private_engine_load_state($workRoot, $jobId) ?? [];

        return [
            'ok' => true,
            'code' => 'ok',
            'architecture' => ORANGE_RESTORE_SHADOW_ENVIRONMENT_ARCHITECTURE,
            'shadow_db' => $shadowDb,
            'identity_hash' => $identity,
            'engine_ready' => true,
            'reconnect' => true,
            'engine_pid' => (int) ($state['engine_pid'] ?? 0),
        ];
    }

    if (!$allowProvision) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
            'architecture' => ORANGE_RESTORE_SHADOW_ENVIRONMENT_ARCHITECTURE,
            'shadow_db' => $shadowDb,
            'identity_hash' => $identity,
            'engine_ready' => false,
            'reconnect' => false,
            'engine_pid' => 0,
        ];
    }

    $provision = orange_restore_private_engine_provision($projectRoot, $workRoot, $jobId, $shadowDb);
    if (empty($provision['ok']) || empty($provision['ready'])) {
        return [
            'ok' => false,
            'code' => (string) ($provision['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED),
            'architecture' => ORANGE_RESTORE_SHADOW_ENVIRONMENT_ARCHITECTURE,
            'shadow_db' => $shadowDb,
            'identity_hash' => $identity,
            'engine_ready' => false,
            'reconnect' => false,
            'engine_pid' => (int) ($provision['engine_pid'] ?? 0),
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'architecture' => ORANGE_RESTORE_SHADOW_ENVIRONMENT_ARCHITECTURE,
        'shadow_db' => $shadowDb,
        'identity_hash' => $identity,
        'engine_ready' => true,
        'reconnect' => true,
        'engine_pid' => (int) ($provision['engine_pid'] ?? 0),
    ];
}

/**
 * Reconnect to the job's private shadow environment (Steps 8–10).
 * Does not import SQL / does not mutate Production.
 *
 * @throws RuntimeException when environment cannot be reached
 */
function orange_restore_shadow_environment_connect_pdo(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    bool $allowProvisionRestart = true
): PDO {
    $ensured = orange_restore_shadow_environment_ensure(
        $projectRoot,
        $workRoot,
        $jobId,
        $allowProvisionRestart
    );
    if (empty($ensured['ok'])) {
        throw new RuntimeException(
            (string) ($ensured['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY)
        );
    }
    $env = orange_backup_load_env_array($projectRoot);
    if (isset($GLOBALS['orange_shadow_env_override']) && is_array($GLOBALS['orange_shadow_env_override'])) {
        $env = array_merge($env, $GLOBALS['orange_shadow_env_override']);
    }

    return orange_restore_shadow_connect_pdo($projectRoot, $env, (string) $ensured['shadow_db']);
}

/**
 * Public-safe architecture inventory for audits (no secrets).
 *
 * @return array<string, mixed>
 */
function orange_restore_shadow_environment_architecture_inventory(): array
{
    return [
        'selected' => ORANGE_RESTORE_SHADOW_ENVIRONMENT_ARCHITECTURE,
        'version' => ORANGE_RESTORE_SHADOW_ENVIRONMENT_VERSION,
        'shared_steps' => ['7_shadow_db', '8_shadow_verify', '9_shadow_files', '10_shadow_smoke'],
        'rejected' => [
            'A_COUNTRY_CRP_SHADOW' => 'Country CRP C6/C7 is for country_recovery packages; Full Step-7 uses private engine.',
            'B_DEDICATED_STAGING_CHANNEL' => 'ORANGE_RESTORE_STAGING_DB* requires Owner manual env/GRANT — not no-manual safe.',
        ],
        'rules' => [
            'no_production_create_database' => true,
            'no_owner_manual_grant' => true,
            'one_environment_per_job' => true,
            'reconnect_without_reimport' => true,
        ],
    ];
}
