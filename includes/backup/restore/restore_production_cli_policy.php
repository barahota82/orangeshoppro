<?php

declare(strict_types=1);

/**
 * Phase 3B.4I / P0-2 — Production restore CLI policy (allowlist + legacy tombstones).
 *
 * Single catalog for which scripts/backup restore CLIs may mutate production.
 * Legacy Phase-2 production entry points remain as disabled tombstone filenames only.
 */

const ORANGE_RESTORE_LEGACY_ENTRYPOINT_DISABLED = 'legacy_restore_entrypoint_disabled';

/**
 * Only these CLIs may perform production restore mutations (wipe/import/uploads/rollback/finalize).
 *
 * @return list<string> repo-relative paths using forward slashes
 */
function orange_restore_approved_production_mutation_cli_workers(): array
{
    return [
        'scripts/backup/restore_import_production.php',
        'scripts/backup/restore_uploads_cutover.php',
        'scripts/backup/restore_rollback.php',
        'scripts/backup/restore_finalize.php',
    ];
}

/**
 * Phase-2 production cutover / merge / E2E resume entry points — permanently disabled tombstones.
 *
 * @return list<string>
 */
function orange_restore_legacy_production_cli_tombstones(): array
{
    return [
        'scripts/backup/restore_full_database_cutover.php',
        'scripts/backup/restore_full_uploads_cutover.php',
        'scripts/backup/restore_full_rollback.php',
        'scripts/backup/restore_run_full.php',
        'scripts/backup/restore_resume_full.php',
        'scripts/backup/restore_approve_merge.php',
        'scripts/backup/restore_full_post_validate.php',
        'scripts/backup/restore_full_post_validate_finalize.php',
    ];
}

/**
 * Non-production-mutation restore CLIs that may remain executable (staging / shadow / status / prepare).
 *
 * Explicit allowlist — not a broad glob.
 *
 * @return list<string>
 */
function orange_restore_approved_non_mutation_restore_clis(): array
{
    return [
        'scripts/backup/restore_status_full.php',
        'scripts/backup/restore_job_status.php',
        'scripts/backup/restore_full_to_staging.php',
        'scripts/backup/restore_country_to_staging.php',
        'scripts/backup/restore_country_shadow.php',
        'scripts/backup/restore_shadow_db.php',
        'scripts/backup/restore_shadow_verify.php',
        'scripts/backup/restore_shadow_files.php',
        'scripts/backup/restore_shadow_smoke.php',
        'scripts/backup/restore_self_test_helpers.php',
    ];
}

/**
 * Compact stderr/stdout rejection used by tombstone wrappers.
 */
function orange_restore_legacy_cli_emit_disabled_message(): void
{
    $lines = [
        'LEGACY_RESTORE_ENTRYPOINT: DISABLED',
        'REASON: ' . ORANGE_RESTORE_LEGACY_ENTRYPOINT_DISABLED,
        'USE: approved_3b_restore_workflow',
    ];
    $out = implode("\n", $lines) . "\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $out);
    } else {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $out;
    }
}
