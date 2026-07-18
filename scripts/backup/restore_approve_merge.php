<?php

declare(strict_types=1);

/**
 * DISABLED tombstone -- Phase-2 merge approve/reject/cancel CLI (P0-2 / F-ARCH-01 / F-CLI-02).
 *
 * Reason: legacy_restore_entrypoint_disabled
 * Use Restore Center final approval + 3B.4 execution contract / workers.
 *
 * Library retained for isolated tests: orange_restore_orchestrator_approve_for_merge
 * and related helpers in includes/backup/restore/restore_orchestrator.php.
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
