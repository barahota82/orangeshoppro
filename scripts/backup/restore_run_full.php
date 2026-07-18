<?php

declare(strict_types=1);

/**
 * DISABLED tombstone -- Phase-2 Full E2E start CLI (P0-2 / F-ARCH-01 / F-CLI-02).
 *
 * Reason: legacy_restore_entrypoint_disabled
 * Use approved 3B Restore Center lifecycle + workers:
 *   restore_import_production.php / restore_uploads_cutover.php /
 *   restore_rollback.php / restore_finalize.php (--job= only).
 *
 * Library retained for isolated tests: orange_restore_e2e_start_full
 * in includes/backup/restore/restore_e2e_orchestrator.php (not invoked from this entrypoint).
 *
 * This file must never parse credentials, package paths, or mutate production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "LEGACY_RESTORE_ENTRYPOINT: DISABLED\n";
    echo "REASON: legacy_restore_entrypoint_disabled\n";
    echo "USE: approved_3b_restore_workflow\n";
    exit(1);
}

fwrite(STDERR, "LEGACY_RESTORE_ENTRYPOINT: DISABLED\n");
fwrite(STDERR, "REASON: legacy_restore_entrypoint_disabled\n");
fwrite(STDERR, "USE: approved_3b_restore_workflow\n");
exit(1);
