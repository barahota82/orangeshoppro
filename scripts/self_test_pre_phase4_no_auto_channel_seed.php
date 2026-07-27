<?php

declare(strict_types=1);

/**
 * Pre-Phase-4 — no automatic channel seed / creation (functional guards only).
 *
 * Usage: php scripts/self_test_pre_phase4_no_auto_channel_seed.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function nac_assert(bool $ok, string $label): void
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

$bootstrap = (string) file_get_contents($root . '/includes/catalog_bootstrap_store.php');
$seedFn = strpos($bootstrap, 'function orange_catalog_seed_default_channels_if_empty');
$seedBody = $seedFn !== false ? substr($bootstrap, $seedFn, 500) : '';
nac_assert($seedFn !== false, 'seed function still defined (compat)');
nac_assert(!preg_match('/INSERT\s+INTO\s+channels/i', $seedBody), 'seed body has no INSERT INTO channels');
nac_assert(str_contains($seedBody, 'unset($pdo)') || str_contains($seedBody, 'no-op'), 'seed is runtime no-op');

$prov = (string) file_get_contents($root . '/includes/country_provision.php');
nac_assert(str_contains($prov, 'manual_channel_create_only'), 'provision copy channels disabled');
nac_assert(!preg_match('/function orange_country_provision_full[\s\S]{0,3500}INSERT\s+INTO\s+channels/i', $prov), 'provision_full no channel INSERT');

$chSave = (string) file_get_contents($root . '/admin/api/channels/save.php');
nac_assert(substr_count($chSave, 'INSERT INTO channels') >= 1, 'manual channels/save.php is runtime INSERT path');

/* No other PHP runtime INSERT INTO channels besides save.php and disabled seed. */
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$otherInserts = [];
foreach ($rii as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/scripts/backup/')
        || str_contains($path, '/vendor/')
        || str_contains($path, '/.git/')
        || str_contains($path, '/_compare_old_copy/')
        || str_contains($path, '/_compare_zip_old/')) {
        continue;
    }
    $rel = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    if ($rel === 'admin/api/channels/save.php') {
        continue;
    }
    $src = (string) file_get_contents($path);
    if (preg_match('/INSERT\s+INTO\s+[`\']?channels[`\']?/i', $src)) {
        if (str_contains($rel, 'self_test_')) {
            continue;
        }
        $otherInserts[] = $rel;
    }
}
nac_assert($otherInserts === [], 'no other runtime INSERT INTO channels: ' . implode(', ', $otherInserts));

/* Completed pre-launch reset: no reset artifact remains in the repository */
nac_assert(!is_file($root . '/includes/setup_data_reset.php'), 'setup_data_reset.php not present');
nac_assert(!is_file($root . '/scripts/maintenance_setup_data_reset.php'), 'maintenance_setup_data_reset.php not present');
nac_assert(!is_file($root . '/scripts/self_test_pre_phase4_setup_data_reset.php'), 'old reset self_test not present');
nac_assert(!is_file($root . '/includes/prelaunch_channels_copy_reset.php'), 'PHP prelaunch reset helper not present');
nac_assert(!is_file($root . '/scripts/reset_prelaunch_channels_and_storefront_copy_lines.php'), 'PHP prelaunch reset CLI not present');
nac_assert(!is_file($root . '/scripts/self_test_prelaunch_channels_copy_reset.php'), 'PHP prelaunch reset self_test not present');
nac_assert(!is_file($root . '/scripts/sql/reset_prelaunch_channels_and_storefront_copy_lines.sql'), 'SQL reset artifact removed');

$heroPage = (string) file_get_contents($root . '/admin/pages/storefront_hero.php');
nac_assert(str_contains($heroPage, "scope = 'home_hero'") && str_contains($heroPage, "scope = 'header_tagline'"), 'UI uses shared storefront_copy_lines');
nac_assert(str_contains($heroPage, 'جمل الـ hero'), 'hero phrases section present');
nac_assert(!str_contains($heroPage, 'storefront_home_hero'), 'admin banner page does not edit storefront_home_hero');

/* SQLite: empty channels + seed no-op */
try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        country_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        slug TEXT NOT NULL,
        path_segment TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    )');
    require_once $root . '/includes/catalog_bootstrap_store.php';
    orange_catalog_seed_default_channels_if_empty($pdo);
    nac_assert((int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn() === 0, 'empty-table seed does not insert');
} catch (Throwable $e) {
    nac_assert(false, 'sqlite fixture: ' . $e->getMessage());
}

$previewApi = (string) file_get_contents($root . '/admin/api/products/save-preview-draft.php');
nac_assert(!preg_match('/SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1/', $previewApi), 'preview no global fallback');
nac_assert(str_contains($previewApi, 'no_channel_for_country'), 'preview zero-channel message');

echo "\n--- summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
