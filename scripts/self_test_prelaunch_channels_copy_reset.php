<?php

declare(strict_types=1);

/**
 * Fixture tests — pre-launch channels + storefront_copy_lines safe reset.
 *
 * Usage: php scripts/self_test_prelaunch_channels_copy_reset.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function plr_assert(bool $ok, string $label): void
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

$script = (string) file_get_contents($root . '/scripts/reset_prelaunch_channels_and_storefront_copy_lines.php');
$lib = (string) file_get_contents($root . '/includes/prelaunch_channels_copy_reset.php');
plr_assert(str_contains($script, 'RESET_PRELAUNCH_CHANNELS_AND_STOREFRONT_COPY_LINES'), 'confirm token in CLI');
plr_assert(str_contains($lib, 'LEGACY_HERO_FALLBACK_BLOCKER'), 'legacy hero blocker code');
plr_assert(str_contains($lib, 'SOFT_REFERENCE'), 'soft ref class');
plr_assert(str_contains($lib, 'BUSINESS_REFERENCE'), 'business ref class');
plr_assert(!is_file($root . '/includes/setup_data_reset.php'), 'old setup_data_reset not resurrected');

/* Functional contracts still present */
$bootstrap = (string) file_get_contents($root . '/includes/catalog_bootstrap_store.php');
$seedFn = strpos($bootstrap, 'function orange_catalog_seed_default_channels_if_empty');
$seedBody = $seedFn !== false ? substr($bootstrap, $seedFn, 500) : '';
plr_assert(!preg_match('/INSERT\s+INTO\s+channels/i', $seedBody), 'empty-table seed no INSERT');
$prov = (string) file_get_contents($root . '/includes/country_provision.php');
plr_assert(str_contains($prov, 'manual_channel_create_only'), 'country activation no auto channels');
$chSave = (string) file_get_contents($root . '/admin/api/channels/save.php');
plr_assert(substr_count($chSave, 'INSERT INTO channels') >= 1, 'manual channel create path');
$previewApi = (string) file_get_contents($root . '/admin/api/products/save-preview-draft.php');
plr_assert(str_contains($previewApi, 'no_channel_for_country'), 'preview zero-channel message');
plr_assert(!preg_match('/SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1/', $previewApi), 'no global channel fallback');

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

require_once $root . '/includes/prelaunch_channels_copy_reset.php';

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
    $pdo->exec('CREATE TABLE storefront_home_hero (
        id INTEGER PRIMARY KEY,
        line_1_ar TEXT NOT NULL DEFAULT \'\',
        header_tagline_ar TEXT NOT NULL DEFAULT \'\'
    )');
    $pdo->exec('CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id INTEGER NULL
    )');
    $pdo->exec('CREATE TABLE sales_returns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_id INTEGER NULL
    )');
    $pdo->exec('CREATE TABLE storefront_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        registered_channel_slug TEXT NULL
    )');
    $pdo->exec('CREATE TABLE storefront_phone_merge_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        proposed_channel_slug TEXT NULL
    )');
    $pdo->exec('CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL
    )');
    $pdo->exec("INSERT INTO products (name) VALUES ('keep-me')");
    $pdo->exec("INSERT INTO storefront_home_hero (id, line_1_ar, header_tagline_ar) VALUES (1, '', '')");

    $pdo->exec("INSERT INTO channels (country_id,name,slug,path_segment,is_active) VALUES
        (1,'A','tiktok','tiktok',1),(1,'B','online','online',1)");
    $pdo->exec('INSERT INTO product_channels (product_id,channel_id) VALUES (1,1),(1,2)');
    $pdo->exec("INSERT INTO storefront_copy_lines (country_id,scope,sort_order,text_ar) VALUES
        (1,'header_tagline',1,'h'),(1,'home_hero',1,'hero')");

    $dry = orange_prelaunch_reset_inspect($pdo);
    plr_assert(!empty($dry['apply_allowed']), 'safe fixture apply_allowed=YES');
    $chBefore = (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
    $copyBefore = (int) $pdo->query('SELECT COUNT(*) FROM storefront_copy_lines')->fetchColumn();
    $prodBefore = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $legacyBefore = (int) $pdo->query('SELECT COUNT(*) FROM storefront_home_hero')->fetchColumn();
    $report = orange_prelaunch_reset_format_report($dry, 'DRY_RUN');
    plr_assert(str_contains($report, 'APPLY_ALLOWED=YES'), 'dry report APPLY_ALLOWED=YES');
    plr_assert(
        (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn() === $chBefore
        && (int) $pdo->query('SELECT COUNT(*) FROM storefront_copy_lines')->fetchColumn() === $copyBefore,
        '1. Dry Run inspect changes zero rows'
    );

    /* 2. Business order blocker */
    $pdo->exec('INSERT INTO orders (channel_id) VALUES (1)');
    $blockedOrders = orange_prelaunch_reset_inspect($pdo);
    plr_assert(empty($blockedOrders['apply_allowed']), '2. orders.channel_id blocks apply');
    $abortOrders = orange_prelaunch_reset_apply($pdo);
    plr_assert($abortOrders['ok'] === false, '2b. apply aborted on orders');
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn() === 2, '2c. channels intact');
    $pdo->exec('DELETE FROM orders');

    /* 3. Sales returns blocker */
    $pdo->exec('INSERT INTO sales_returns (channel_id) VALUES (2)');
    $blockedSr = orange_prelaunch_reset_inspect($pdo);
    plr_assert(empty($blockedSr['apply_allowed']), '3. sales_returns.channel_id blocks apply');
    $pdo->exec('DELETE FROM sales_returns');

    /* 4. Soft reference blocker */
    $pdo->exec("INSERT INTO storefront_accounts (registered_channel_slug) VALUES ('tiktok')");
    $blockedSoft = orange_prelaunch_reset_inspect($pdo);
    plr_assert(empty($blockedSoft['apply_allowed']), '4. soft slug blocks apply');
    $codes = array_column($blockedSoft['blockers'], 'code');
    plr_assert(in_array('SOFT_REF_STOREFRONT_ACCOUNTS', $codes, true), '4b. soft blocker code');
    $pdo->exec('DELETE FROM storefront_accounts');

    /* 5. Other scope blocker */
    $pdo->exec("INSERT INTO storefront_copy_lines (country_id,scope,sort_order,text_ar) VALUES (1,'promo_banner',1,'x')");
    $blockedScope = orange_prelaunch_reset_inspect($pdo);
    plr_assert(empty($blockedScope['apply_allowed']), '5. other scope blocks apply');
    plr_assert(
        in_array('COPY_LINES_OTHER_SCOPE', array_column($blockedScope['blockers'], 'code'), true),
        '5b. other scope code'
    );
    $pdo->exec("DELETE FROM storefront_copy_lines WHERE scope = 'promo_banner'");

    /* 6. Legacy non-empty hero blocker */
    $pdo->exec("UPDATE storefront_home_hero SET line_1_ar = 'legacy visible' WHERE id = 1");
    $blockedLegacy = orange_prelaunch_reset_inspect($pdo);
    plr_assert(empty($blockedLegacy['apply_allowed']), '6. legacy hero blocks apply');
    plr_assert(
        in_array('LEGACY_HERO_FALLBACK_BLOCKER', array_column($blockedLegacy['blockers'], 'code'), true),
        '6b. LEGACY_HERO_FALLBACK_BLOCKER'
    );
    $pdo->exec("UPDATE storefront_home_hero SET line_1_ar = '' WHERE id = 1");

    /* Safe apply */
    $apply = orange_prelaunch_reset_apply($pdo);
    plr_assert($apply['ok'] === true, 'safe apply ok');
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM product_channels')->fetchColumn() === 0, '7. product_channels empty');
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn() === 0, '8. channels empty');
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM storefront_copy_lines')->fetchColumn() === 0, '9. copy_lines empty');
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM storefront_home_hero')->fetchColumn() === $legacyBefore, '10. home_hero untouched count');
    $legacyRow = $pdo->query('SELECT line_1_ar FROM storefront_home_hero WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    plr_assert(is_array($legacyRow) && trim((string) ($legacyRow['line_1_ar'] ?? '')) === '', '10b. home_hero text untouched');
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === $prodBefore, '11. products untouched');
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0, '11b. orders untouched (empty)');

    $aiCh = orange_prelaunch_reset_auto_increment($pdo, 'channels');
    $aiCopy = orange_prelaunch_reset_auto_increment($pdo, 'storefront_copy_lines');
    plr_assert($aiCh === 1, '12. channels AI=1 (got ' . var_export($aiCh, true) . ')');
    plr_assert($aiCopy === 1, '15. copy_lines AI=1 (got ' . var_export($aiCopy, true) . ')');

    $pdo->exec("INSERT INTO channels (country_id,name,slug,path_segment,is_active) VALUES (1,'First','a','a',1)");
    $id1 = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO channels (country_id,name,slug,path_segment,is_active) VALUES (1,'Second','b','b',1)");
    $id2 = (int) $pdo->lastInsertId();
    plr_assert($id1 === 1, '13. first channel id=1');
    plr_assert($id2 === 2, '14. second channel id=2');

    $pdo->exec("INSERT INTO storefront_copy_lines (country_id,scope,sort_order,text_ar) VALUES (1,'header_tagline',1,'new')");
    $hid = (int) $pdo->lastInsertId();
    plr_assert($hid === 1, '16. first header/hero id=1');

    /* 17. no orphans: product_channels empty; channel ids only new ones */
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM product_channels')->fetchColumn() === 0, '17. no product_channels orphans');

    /* Seed no-op after empty */
    require_once $root . '/includes/catalog_bootstrap_store.php';
    $pdo->exec('DELETE FROM channels');
    try {
        $pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'channels'");
    } catch (Throwable $e) {
    }
    orange_catalog_seed_default_channels_if_empty($pdo);
    plr_assert((int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn() === 0, '19. bootstrap seed no insert');
} catch (Throwable $e) {
    plr_assert(false, 'sqlite fixture: ' . $e->getMessage());
}

echo "\n--- summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
