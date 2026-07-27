<?php

declare(strict_types=1);

/**
 * One-time pre-launch reset: channels + storefront_copy_lines (full table) + AUTO_INCREMENT=1.
 * Owner 2026-07-27.
 *
 * Usage (from project root):
 *   php scripts/reset_prelaunch_channels_and_storefront_copy_lines.php
 *       -> DRY RUN (read-only)
 *
 *   php scripts/reset_prelaunch_channels_and_storefront_copy_lines.php \
 *       --apply --confirm=RESET_PRELAUNCH_CHANNELS_AND_STOREFRONT_COPY_LINES
 *       -> APPLY (Owner on server only; never from Cursor on Production)
 */

$apply = false;
$confirm = '';
foreach ($argv ?? [] as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    }
    if (str_starts_with((string) $arg, '--confirm=')) {
        $confirm = substr((string) $arg, strlen('--confirm='));
    }
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/catalog_schema.php';
require_once dirname(__DIR__) . '/includes/prelaunch_channels_copy_reset.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

if (!$apply) {
    $inspect = orange_prelaunch_reset_inspect($pdo);
    fwrite(STDOUT, orange_prelaunch_reset_format_report($inspect, 'DRY_RUN'));
    fwrite(STDOUT, "Dry Run only - zero rows modified.\n");
    fwrite(
        STDOUT,
        'To apply: php scripts/reset_prelaunch_channels_and_storefront_copy_lines.php --apply --confirm='
        . orange_prelaunch_reset_confirm_token() . "\n"
    );
    exit(!empty($inspect['apply_allowed']) ? 0 : 2);
}

if ($confirm !== orange_prelaunch_reset_confirm_token()) {
    fwrite(
        STDERR,
        'Refusing APPLY without --confirm=' . orange_prelaunch_reset_confirm_token() . "\n"
    );
    exit(1);
}

$result = orange_prelaunch_reset_apply($pdo);
fwrite(STDOUT, orange_prelaunch_reset_format_report($result['before'], 'APPLY_BEFORE'));
foreach ($result['steps'] as $step) {
    fwrite(STDOUT, 'STEP: ' . $step . "\n");
}
if (is_array($result['after'])) {
    fwrite(STDOUT, orange_prelaunch_reset_format_report($result['after'], 'APPLY_AFTER'));
}
fwrite(STDOUT, ($result['ok'] ? 'OK: ' : 'FAIL: ') . $result['message'] . "\n");
exit($result['ok'] ? 0 : 1);
