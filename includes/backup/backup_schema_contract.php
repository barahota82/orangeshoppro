<?php

declare(strict_types=1);

/**
 * Shared schema revision contract for backup Verify/DRV/registry/matrix (OR-CURSOR-022-R1).
 * Loads the catalog revision constant only — does not call ensure_schema or touch the DB.
 */

if (!defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'catalog_schema.php';
}

/**
 * Authoritative expected schema revision for backup/restore qualification gates.
 */
function orange_backup_expected_schema_revision(): int
{
    if (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
        return (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION;
    }
    if (function_exists('orange_recovery_validation_expected_schema_revision')) {
        return orange_recovery_validation_expected_schema_revision();
    }
    if (defined('ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION')) {
        return (int) ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION;
    }

    return 125;
}

/**
 * Round id for test-round baseline pin / framework_job_id suffix.
 * Unified charset: letters, digits, dot, underscore, hyphen — no colon.
 */
function orange_backup_validate_baseline_round_id(string $roundId): void
{
    $roundId = trim($roundId);
    if ($roundId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $roundId)) {
        throw new RuntimeException(
            'Invalid round_id for baseline (allowed: A-Z a-z 0-9 . _ -; no colon).'
        );
    }
}

function orange_backup_baseline_framework_job_id(string $roundId): string
{
    orange_backup_validate_baseline_round_id($roundId);

    return 'baseline_' . trim($roundId);
}

/**
 * Derive baseline_state from create + protection stages (OR-CURSOR-022-R2 B2).
 * CREATED_UNPROTECTED = package on disk without pin — not READY / not VERIFIED_CLEAN.
 *
 * @param array<string,mixed> $result
 */
function orange_backup_baseline_state_from_result(array $result): string
{
    $ok = !empty($result['ok']);
    $pinned = (($result['protection_status'] ?? '') === 'pinned') || is_array($result['pin'] ?? null);
    $created = !empty($result['package_created']);
    $snapshot = (string) ($result['snapshot'] ?? '');
    if ($snapshot !== '' && preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $snapshot)) {
        $created = true;
    }
    if ($ok && $pinned) {
        return 'CREATED_UNVERIFIED';
    }
    if ($created) {
        return 'CREATED_UNPROTECTED';
    }

    return 'NOT_CREATED';
}
