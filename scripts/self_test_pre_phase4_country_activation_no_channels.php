<?php

declare(strict_types=1);

/**
 * Pre-Phase-4 — Country activation must not create/copy channels.
 *
 * Usage: php scripts/self_test_pre_phase4_country_activation_no_channels.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function pa_assert(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

$provision = (string) file_get_contents($root . '/includes/country_provision.php');
$manage = (string) file_get_contents($root . '/admin/api/countries/manage.php');

$copyFn = strpos($provision, 'function orange_country_copy_channels_from_source');
pa_assert($copyFn !== false, 'copy_channels function exists');
$copyBody = $copyFn !== false ? substr($provision, $copyFn, 900) : '';
pa_assert(str_contains($copyBody, 'manual_channel_create_only'), 'copy_channels returns manual_only');
pa_assert(!preg_match('/INSERT\s+INTO\s+channels/i', $copyBody), 'copy_channels body has no INSERT INTO channels');

$fullFn = strpos($provision, 'function orange_country_provision_full');
$fullBody = $fullFn !== false ? substr($provision, $fullFn, 4500) : '';
pa_assert($fullFn !== false, 'provision_full exists');
pa_assert(
    str_contains($fullBody, "created_channel'] = false")
    || str_contains($fullBody, "['created_channel'] = false"),
    'provision_full forces created_channel=false'
);
pa_assert(
    !preg_match('/INSERT\s+INTO\s+channels/i', $fullBody),
    'provision_full has no INSERT INTO channels'
);
pa_assert(
    str_contains($fullBody, 'manual_channel_create_only')
    || str_contains($fullBody, 'orange_country_copy_channels_from_source'),
    'provision_full still reports channels_copy via no-op helper'
);

pa_assert(str_contains($manage, 'orange_country_provision_runtime'), 'save still may call runtime provision');
pa_assert(str_contains($manage, 'manual_channel_create_only'), 'manage.php labels manual channel policy');

/* Manual channel create path remains. */
$chSave = (string) file_get_contents($root . '/admin/api/channels/save.php');
pa_assert(str_contains($chSave, 'INSERT INTO channels'), 'manual channels/save.php still inserts');

$bootstrap = (string) file_get_contents($root . '/includes/catalog_bootstrap_store.php');
$seedFn = strpos($bootstrap, 'function orange_catalog_seed_default_channels_if_empty');
$seedBody = $seedFn !== false ? substr($bootstrap, $seedFn, 400) : '';
pa_assert($seedFn !== false, 'empty-table seed function exists');
pa_assert(!preg_match('/INSERT\s+INTO\s+channels/i', $seedBody), 'empty-table seed is no-op (no INSERT)');

echo "\n--- summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
