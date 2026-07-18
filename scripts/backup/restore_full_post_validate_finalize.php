<?php

declare(strict_types=1);

/**
 * DISABLED tombstone -- Phase-2 post-validation finalize CLI (P0-2 / F-ARCH-01 / F-CLI-02).
 *
 * Reason: legacy_restore_entrypoint_disabled
 * Use approved 3B.4 worker: scripts/backup/restore_finalize.php --job=JOB_ID
 *
 * Library retained: orange_restore_orchestrator_post_validation_finalize
 * in includes/backup/restore/restore_orchestrator.php.
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
