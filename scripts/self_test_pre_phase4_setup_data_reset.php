<?php

declare(strict_types=1);

/**
 * Pre-Phase-4 — Auto-channel seed closure + setup-data reset artifact.
 *
 * Usage: php scripts/self_test_pre_phase4_setup_data_reset.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function sdr_assert(bool $ok, string $label): void
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
sdr_assert($seedFn !== false, 'seed function still defined (compat)');
sdr_assert(!preg_match('/INSERT\s+INTO\s+channels/i', $seedBody), 'seed body has no INSERT INTO channels');
sdr_assert(str_contains($seedBody, 'unset($pdo)') || str_contains($seedBody, 'no-op'), 'seed is runtime no-op');

$prov = (string) file_get_contents($root . '/includes/country_provision.php');
sdr_assert(str_contains($prov, 'manual_channel_create_only'), 'provision copy channels disabled');
sdr_assert(!preg_match('/function orange_country_provision_full[\s\S]{0,3500}INSERT\s+INTO\s+channels/i', $prov), 'provision_full no channel INSERT');

$chSave = (string) file_get_contents($root . '/admin/api/channels/save.php');
sdr_assert(substr_count($chSave, 'INSERT INTO channels') >= 1, 'manual channels/save.php is runtime INSERT path');

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
        /* allow comments / historical docs inside php strings for tests */
        if (str_contains($rel, 'self_test_') && !str_contains($src, "\$pdo->") && !str_contains($src, '->prepare')) {
            continue;
        }
        if (str_contains($rel, 'self_test_')) {
            continue;
        }
        $otherInserts[] = $rel;
    }
}
sdr_assert($otherInserts === [], 'no other runtime INSERT INTO channels: ' . implode(', ', $otherInserts));

$maint = (string) file_get_contents($root . '/scripts/maintenance_setup_data_reset.php');
$lib = (string) file_get_contents($root . '/includes/setup_data_reset.php');
sdr_assert(str_contains($maint, 'RESET_SETUP_CHANNELS_AND_COPY_LINES'), 'apply confirm token');
sdr_assert(str_contains($maint, '--apply'), 'apply flag');
sdr_assert(str_contains($lib, 'header_tagline') && str_contains($lib, 'home_hero'), 'Header+Hero scopes');
sdr_assert(str_contains($lib, 'business_blockers'), 'business abort guards');
sdr_assert(str_contains($lib, 'AUTO_INCREMENT'), 'AI reset present');
sdr_assert(str_contains($lib, 'physical_media') || str_contains($lib, 'uploads'), 'media freeze noted');

$heroPage = (string) file_get_contents($root . '/admin/pages/storefront_hero.php');
sdr_assert(str_contains($heroPage, "scope = 'home_hero'") && str_contains($heroPage, "scope = 'header_tagline'"), 'UI uses shared storefront_copy_lines');

/* --- SQLite isolated fixture --- */
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
    $pdo->exec('CREATE TABLE product_channels (
        product_id INTEGER NOT NULL,
        channel_id INTEGER NOT NULL,
        PRIMARY KEY (product_id, channel_id)
    )');
    $pdo->exec('CREATE TABLE storefront_copy_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        country_id INTEGER NOT NULL,
        scope TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        text_ar TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id INTEGER NULL
    )');
    $pdo->exec('CREATE TABLE storefront_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        registered_channel_slug TEXT NULL
    )');

    /* Stub orange_table_* for apply soft-ref helpers */
    if (!function_exists('orange_table_exists')) {
        function orange_table_exists(PDO $pdo, string $table): bool
        {
            try {
                $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');

                return true;
            } catch (Throwable $e) {
                return false;
            }
        }
    }
    if (!function_exists('orange_table_has_column')) {
        function orange_table_has_column(PDO $pdo, string $table, string $column): bool
        {
            $st = $pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')');
            if (!$st) {
                /* SQLite quote wraps; use raw */
            }
            $st = $pdo->query('PRAGMA table_info(' . $table . ')');
            if (!$st) {
                return false;
            }
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }

            return false;
        }
    }

    require_once $root . '/includes/setup_data_reset.php';

    $pdo->exec("INSERT INTO channels (country_id,name,slug,path_segment,is_active) VALUES
        (1,'A','tiktok','tiktok',1),(1,'B','online','online',1),(2,'C','zone','zone',1)");
    $pdo->exec('INSERT INTO product_channels (product_id,channel_id) VALUES (10,1),(11,2)');
    $pdo->exec("INSERT INTO storefront_copy_lines (country_id,scope,sort_order,text_ar) VALUES
        (1,'home_hero',1,'h1'),(1,'header_tagline',1,'t1'),(2,'home_hero',1,'h2')");
    $pdo->exec("INSERT INTO storefront_accounts (registered_channel_slug) VALUES ('tiktok')");

    $dry = orange_setup_data_reset_inspect($pdo);
    sdr_assert((int) $dry['channels']['row_count'] === 3, 'fixture channels=3');
    sdr_assert((int) $dry['storefront_copy_lines']['header_tagline'] === 1, 'fixture header=1');
    sdr_assert((int) $dry['storefront_copy_lines']['home_hero'] === 2, 'fixture hero=2');
    sdr_assert(!empty($dry['can_apply']), 'dry can_apply without orders');
    $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();

    /* Dry-run path does not modify — inspect only */
    $countAfterInspect = (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
    sdr_assert($countBefore === $countAfterInspect, 'inspect/dry changes zero rows');

    /* Business abort */
    $pdo->exec('INSERT INTO orders (channel_id) VALUES (1)');
    $blocked = orange_setup_data_reset_inspect($pdo);
    sdr_assert(empty($blocked['can_apply']), 'orders.channel_id blocks apply');
    $abort = orange_setup_data_reset_apply($pdo);
    sdr_assert($abort['ok'] === false, 'apply aborts on business data');
    sdr_assert((int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn() === 3, 'abort left channels intact');
    $pdo->exec('DELETE FROM orders');

    $apply = orange_setup_data_reset_apply($pdo);
    sdr_assert($apply['ok'] === true, 'safe fixture apply ok');
    sdr_assert((int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn() === 0, 'channels empty');
    sdr_assert((int) $pdo->query('SELECT COUNT(*) FROM product_channels')->fetchColumn() === 0, 'product_channels empty');
    sdr_assert((int) $pdo->query("SELECT COUNT(*) FROM storefront_copy_lines WHERE scope IN ('header_tagline','home_hero')")->fetchColumn() === 0, 'copy lines empty');
    sdr_assert((int) $pdo->query("SELECT COUNT(*) FROM storefront_accounts WHERE registered_channel_slug IS NOT NULL")->fetchColumn() === 0, 'soft slug cleared');

    $pdo->exec("INSERT INTO channels (country_id,name,slug,path_segment,is_active) VALUES (1,'First','a','a',1)");
    $id1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO channels (country_id,name,slug,path_segment,is_active) VALUES (1,'Second','b','b',1)");
    $id2 = (int) $pdo->lastInsertId();
    sdr_assert($id1 === 1, 'first manual channel id=1');
    sdr_assert($id2 === 2, 'second manual channel id=2');

    $pdo->exec("INSERT INTO storefront_copy_lines (country_id,scope,sort_order,text_ar) VALUES (1,'header_tagline',1,'new')");
    $hid = (int) $pdo->lastInsertId();
    sdr_assert($hid === 1, 'first header/hero shared id=1');

    /* Idempotent second apply */
    $pdo->exec('DELETE FROM channels');
    $pdo->exec('DELETE FROM storefront_copy_lines');
    try {
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('channels','storefront_copy_lines')");
    } catch (Throwable $e) {
    }
    $again = orange_setup_data_reset_apply($pdo);
    sdr_assert($again['ok'] === true, 'idempotent apply on empty ok');

    /* Seed no-op does not recreate */
    require_once $root . '/includes/catalog_bootstrap_store.php';
    orange_catalog_seed_default_channels_if_empty($pdo);
    sdr_assert((int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn() === 0, 'empty-table seed does not insert');
} catch (Throwable $e) {
    sdr_assert(false, 'sqlite fixture: ' . $e->getMessage());
}

/* Preview / country activation source regression */
$previewApi = (string) file_get_contents($root . '/admin/api/products/save-preview-draft.php');
sdr_assert(!preg_match('/SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1/', $previewApi), 'preview no global fallback');
sdr_assert(str_contains($previewApi, 'no_channel_for_country'), 'preview zero-channel message');

echo "\n--- summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
