<?php

declare(strict_types=1);

/**
 * One-time maintenance - setup data reset: channels + header/hero copy lines + AUTO_INCREMENT.
 * Owner decision 2026-07-27.
 *
 * Usage (from project root):
 *   php scripts/maintenance_setup_data_reset.php
 *       -> detailed DRY RUN (no writes)
 *
 *   php scripts/maintenance_setup_data_reset.php --compact
 *       -> short DRY RUN KEY=VALUE (Plesk-safe, no writes)
 *
 *   php scripts/maintenance_setup_data_reset.php --apply --confirm=RESET_SETUP_CHANNELS_AND_COPY_LINES
 *       -> APPLY only with explicit confirm
 *
 * --compact + --apply is rejected (fail closed).
 * Do not run APPLY on Production from Cursor.
 */

$apply = false;
$compact = false;
$confirm = '';
foreach ($argv ?? [] as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    }
    if ($arg === '--compact') {
        $compact = true;
    }
    if (str_starts_with((string) $arg, '--confirm=')) {
        $confirm = substr((string) $arg, strlen('--confirm='));
    }
}

if ($compact && $apply) {
    fwrite(STDERR, "ERROR: --compact cannot be combined with --apply (fail closed)\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/catalog_schema.php';
require_once dirname(__DIR__) . '/includes/setup_data_reset.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

if (!$apply) {
    $inspect = orange_setup_data_reset_inspect($pdo);
    if ($compact) {
        fwrite(STDOUT, orange_setup_data_reset_format_compact($inspect));
        exit(empty($inspect['can_apply']) ? 2 : 0);
    }
    fwrite(STDOUT, orange_setup_data_reset_format_report($inspect, 'DRY_RUN'));
    fwrite(STDOUT, "Dry Run only - zero rows modified.\n");
    fwrite(STDOUT, "To apply: php scripts/maintenance_setup_data_reset.php --apply --confirm=RESET_SETUP_CHANNELS_AND_COPY_LINES\n");
    fwrite(STDOUT, "Compact: php scripts/maintenance_setup_data_reset.php --compact\n");
    exit(empty($inspect['can_apply']) ? 2 : 0);
}

if ($confirm !== 'RESET_SETUP_CHANNELS_AND_COPY_LINES') {
    fwrite(STDERR, "Refusing APPLY without --confirm=RESET_SETUP_CHANNELS_AND_COPY_LINES\n");
    exit(1);
}

$result = orange_setup_data_reset_apply($pdo);
fwrite(STDOUT, orange_setup_data_reset_format_report($result['before'], 'APPLY_BEFORE'));
foreach ($result['steps'] as $step) {
    fwrite(STDOUT, 'STEP: ' . $step . "\n");
}
if (is_array($result['after'])) {
    fwrite(STDOUT, orange_setup_data_reset_format_report($result['after'], 'APPLY_AFTER'));
}
fwrite(STDOUT, ($result['ok'] ? 'OK: ' : 'FAIL: ') . $result['message'] . "\n");
exit($result['ok'] ? 0 : 1);
