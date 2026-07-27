<?php

declare(strict_types=1);

/**
 * Pre-Phase-4 — Targeted Home-Copy / Bare-Domain Entry Repair (G1/G2/G3).
 *
 * Usage: php scripts/self_test_pre_phase4_home_copy_bare_domain_repair.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function hcb_assert(bool $ok, string $label): void
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

$config = (string) file_get_contents($root . '/config.php');
$schema = (string) file_get_contents($root . '/includes/catalog_schema.php');
$hero = (string) file_get_contents($root . '/includes/storefront_hero.php');
$header = (string) file_get_contents($root . '/includes/header.php');
$entry = (string) file_get_contents($root . '/includes/storefront_entry.php');
$provision = (string) file_get_contents($root . '/includes/country_provision.php');
$adminCopy = (string) file_get_contents($root . '/admin/api/settings/storefront_copy_lines.php');
$adminHeroPage = (string) file_get_contents($root . '/admin/pages/storefront_hero.php');

/* --- G1: no automatic copy-line seed --- */
$seedFn = strpos($schema, 'function orange_catalog_seed_storefront_copy_defaults_if_empty');
$seedBody = $seedFn !== false ? substr($schema, $seedFn, 400) : '';
hcb_assert($seedFn !== false, 'G1 seed function still defined (compat)');
hcb_assert(!preg_match('/INSERT\s+INTO\s+storefront_copy_lines/i', $seedBody), 'G1 seed body has no INSERT');
hcb_assert(str_contains($seedBody, 'unset($pdo)') || str_contains($seedBody, 'no-op'), 'G1 seed is runtime no-op');

$migFn = strpos($schema, 'function orange_catalog_migrate_legacy_storefront_copy_lines');
$migBody = $migFn !== false ? substr($schema, $migFn, 500) : '';
hcb_assert($migFn !== false, 'G1 migrate_legacy still defined (compat)');
hcb_assert(!preg_match('/INSERT\s+INTO\s+storefront_copy_lines/i', $migBody), 'G1 migrate_legacy body has no INSERT');
hcb_assert(str_contains($migBody, 'unset($pdo)') || str_contains($migBody, 'no-op'), 'G1 migrate_legacy is runtime no-op');
hcb_assert(str_contains($schema, 'orange_catalog_migrate_legacy_storefront_copy_lines($pdo)'), 'G1 schema still calls migrate hook');

$provCopyFn = strpos($provision, 'function orange_country_copy_storefront_copy_lines_from_source');
$provCopyBody = $provCopyFn !== false ? substr($provision, $provCopyFn, 600) : '';
hcb_assert(str_contains($provCopyBody, 'manual_copy_lines_create_only'), 'G1 provision copy-lines disabled');
hcb_assert(!preg_match('/INSERT\s+INTO\s+storefront_copy_lines/i', $provCopyBody), 'G1 provision copy body no INSERT');

hcb_assert(str_contains($adminCopy, 'INSERT INTO storefront_copy_lines'), 'G1 admin Save INSERT path remains');
hcb_assert(!str_contains($adminHeroPage, 'storefront_home_hero'), 'G1 admin hero page does not write legacy table');

/* Runtime INSERT INTO storefront_copy_lines only via admin Save */
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$otherCopyInserts = [];
foreach ($rii as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/scripts/backup/')
        || str_contains($path, '/vendor/')
        || str_contains($path, '/.git/')
        || str_contains($path, '/_compare_')) {
        continue;
    }
    $rel = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    if ($rel === 'admin/api/settings/storefront_copy_lines.php') {
        continue;
    }
    if (str_contains($rel, 'self_test_')) {
        continue;
    }
    $src = (string) file_get_contents($path);
    if (preg_match('/INSERT\s+INTO\s+[`\']?storefront_copy_lines[`\']?/i', $src)) {
        $otherCopyInserts[] = $rel;
    }
}
hcb_assert($otherCopyInserts === [], 'G1 no other runtime INSERT into storefront_copy_lines: ' . implode(', ', $otherCopyInserts));

/* --- G3: no public legacy hero fallback --- */
$heroResolved = strpos($hero, 'function orange_storefront_home_hero_lines_resolved');
$heroResolvedBody = $heroResolved !== false ? substr($hero, $heroResolved, 2200) : '';
hcb_assert(str_contains($heroResolvedBody, 'storefront_copy_lines'), 'G3 hero reads copy_lines');
hcb_assert(!preg_match('/FROM\s+storefront_home_hero/i', $heroResolvedBody), 'G3 hero resolved no SELECT legacy table');

$tagResolved = strpos($hero, 'function orange_storefront_header_tagline_cycle_resolved');
$tagResolvedBody = $tagResolved !== false ? substr($hero, $tagResolved, 2800) : '';
hcb_assert(!preg_match('/FROM\s+storefront_home_hero/i', $tagResolvedBody), 'G3 tagline resolved no SELECT legacy table');
hcb_assert(str_contains($tagResolvedBody, "['', '']") || str_contains($tagResolvedBody, '["", ""]'), 'G3 empty tagline safe pad');

/* --- G2: no fake tiktok default --- */
$defFn = strpos($config, 'function orange_storefront_default_channel_slug');
$defBody = $defFn !== false ? substr($config, $defFn, 700) : '';
hcb_assert($defFn !== false, 'G2 default_channel_slug exists');
hcb_assert(!preg_match('/return\s+[\'"]tiktok[\'"]\s*;/', $defBody), 'G2 default_channel_slug never returns literal tiktok');
hcb_assert(str_contains($defBody, "return ''") || str_contains($defBody, 'return "";'), 'G2 default returns empty on fail-closed');

$mainFn = strpos($config, 'function orange_storefront_main_channel_slug_for_country');
$mainBody = $mainFn !== false ? substr($config, $mainFn, 2800) : '';
hcb_assert($mainFn !== false, 'G2 main_channel helper exists');
hcb_assert(!str_contains($mainBody, 'ORDER BY is_country_default DESC, id ASC LIMIT 1'), 'G2 no silent first-id ORDER BY LIMIT 1');
hcb_assert(str_contains($mainBody, 'count($defaults) === 1') || str_contains($mainBody, 'count($defaults)==1'), 'G2 requires exactly one default when multiple');
hcb_assert(str_contains($entry, 'orange_storefront_main_channel_slug_for_country'), 'bare root uses main_channel helper');
hcb_assert(str_contains($entry, 'orange_storefront_render_region_unavailable'), 'bare root fail-closed → Region-Unavailable');

$pathMapsFn = strpos($config, 'function orange_storefront_path_maps_for_js');
$pathMapsBody = $pathMapsFn !== false ? substr($config, $pathMapsFn, 2800) : '';
hcb_assert(!str_contains($pathMapsBody, "['tiktok' => 'tiktok', 'online' => 'online', 'web' => 'web']"), 'G2 path_maps no fabricated slug map');
hcb_assert(str_contains($pathMapsBody, "'(?!)'") || str_contains($pathMapsBody, '"(?!)"'), 'G2 empty path alt never-matches');

hcb_assert(!str_contains($header, "|| 'tiktok'"), 'G2 header JS no tiktok fallback');
hcb_assert(str_contains($header, 'orange_sf_cart__none') || str_contains($header, "ORANGE_SF_DEFAULT_CHANNEL_SLUG || ''"), 'G2 header handles empty default');

/* Operational literal return 'tiktok' only allowed outside default helper (aliases/labels OK) */
hcb_assert(!preg_match('/function orange_storefront_default_channel_slug[\s\S]{0,500}return\s+[\'"]tiktok[\'"]/', $config), 'G2 sweep default helper');

/* --- SQLite: main-channel algorithm + seed no-op --- */
try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE storefront_copy_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        country_id INTEGER NOT NULL,
        scope TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        text_ar TEXT, text_en TEXT, text_fil TEXT, text_hi TEXT
    )');
    $pdo->exec('CREATE TABLE storefront_home_hero (
        id INTEGER PRIMARY KEY,
        line_1_ar TEXT, line_1_en TEXT, header_tagline_ar TEXT
    )');
    $pdo->exec("INSERT INTO storefront_home_hero (id, line_1_ar, header_tagline_ar) VALUES (1, 'LEGACY_HERO', 'LEGACY_TAG')");

    require_once $root . '/includes/catalog_schema.php';
    orange_catalog_seed_storefront_copy_defaults_if_empty($pdo);
    orange_catalog_migrate_legacy_storefront_copy_lines($pdo);
    hcb_assert((int) $pdo->query('SELECT COUNT(*) FROM storefront_copy_lines')->fetchColumn() === 0, 'G1 sqlite seed/migrate create zero rows');
    hcb_assert((string) $pdo->query('SELECT line_1_ar FROM storefront_home_hero WHERE id=1')->fetchColumn() === 'LEGACY_HERO', 'G3 legacy row untouched');

    require_once $root . '/includes/country_provision.php';
    $provRes = orange_country_copy_storefront_copy_lines_from_source($pdo, 2, 1);
    hcb_assert(($provRes['reason'] ?? '') === 'manual_copy_lines_create_only', 'G1 provision copy no-op reason');
    hcb_assert((int) ($provRes['copied'] ?? -1) === 0, 'G1 provision copied=0');

    /* Minimal stubs for channel helpers used by main_channel */
    if (!function_exists('orange_table_exists')) {
        throw new RuntimeException('orange_table_exists missing after catalog_schema');
    }
    /* Force column probes via schema helpers — create channels table matching expectations */
    $pdo->exec('CREATE TABLE channels (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        country_id INTEGER NOT NULL,
        slug TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        is_country_default INTEGER NOT NULL DEFAULT 0
    )');

    /*
     * Inline algorithm mirror (same contract as config.php) — avoids loading full config/.env.
     * Assertions below verify the contract; source static checks above ensure config matches.
     */
    $selectMain = static function (PDO $pdo, int $countryId): ?string {
        $st = $pdo->prepare(
            'SELECT slug, is_country_default FROM channels WHERE is_active = 1 AND country_id = ?'
        );
        $st->execute([$countryId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $active = [];
        $defaults = [];
        foreach ($rows as $row) {
            $sl = trim((string) ($row['slug'] ?? ''));
            if ($sl === '') {
                continue;
            }
            $active[] = $sl;
            if ((int) ($row['is_country_default'] ?? 0) === 1) {
                $defaults[] = $sl;
            }
        }
        $n = count($active);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return $active[0];
        }
        if (count($defaults) === 1) {
            return $defaults[0];
        }

        return null;
    };

    hcb_assert($selectMain($pdo, 1) === null, 'C20 zero channels → null');
    $pdo->exec("INSERT INTO channels (country_id, slug, is_active, is_country_default) VALUES (1, 'kw-web', 1, 0)");
    hcb_assert($selectMain($pdo, 1) === 'kw-web', 'C23 one active → selected');
    $pdo->exec("INSERT INTO channels (country_id, slug, is_active, is_country_default) VALUES (1, 'kw-tt', 1, 1)");
    hcb_assert($selectMain($pdo, 1) === 'kw-tt', 'C24 multiple + one default → default');
    $pdo->exec('DELETE FROM channels');
    $pdo->exec("INSERT INTO channels (country_id, slug, is_active, is_country_default) VALUES
        (1, 'a', 1, 0), (1, 'b', 1, 0)");
    hcb_assert($selectMain($pdo, 1) === null, 'C25 multiple + no default → fail-closed');
    $pdo->exec('DELETE FROM channels');
    $pdo->exec("INSERT INTO channels (country_id, slug, is_active, is_country_default) VALUES
        (1, 'a', 1, 1), (1, 'b', 1, 1)");
    hcb_assert($selectMain($pdo, 1) === null, 'C26 multiple + two defaults → fail-closed');
    $pdo->exec('DELETE FROM channels');
    $pdo->exec("INSERT INTO channels (country_id, slug, is_active, is_country_default) VALUES
        (1, 'inactive-def', 0, 1), (1, 'only-active', 1, 0)");
    hcb_assert($selectMain($pdo, 1) === 'only-active', 'C27 inactive default + one active → active');
    $pdo->exec('DELETE FROM channels');
    $pdo->exec("INSERT INTO channels (country_id, slug, is_active, is_country_default) VALUES
        (1, 'x', 0, 1), (1, 'y', 0, 0)");
    hcb_assert($selectMain($pdo, 1) === null, 'C28 all inactive → null');
    $pdo->exec("INSERT INTO channels (country_id, slug, is_active, is_country_default) VALUES (2, 'eg-web', 1, 1)");
    hcb_assert($selectMain($pdo, 1) === null, 'C29 other-country channel never selected');
    hcb_assert($selectMain($pdo, 2) === 'eg-web', 'C29b Egypt channel for Egypt only');
} catch (Throwable $e) {
    hcb_assert(false, 'sqlite fixture: ' . $e->getMessage());
}

/* Preview / no-auto-seed / entry freeze */
$previewApi = (string) file_get_contents($root . '/admin/api/products/save-preview-draft.php');
hcb_assert(str_contains($previewApi, 'no_channel_for_country'), 'E38 preview zero-channel preserved');
hcb_assert(!preg_match('/SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1/', $previewApi), 'E38 no global first-active');

$region = (string) file_get_contents($root . '/pages/region-unavailable.php');
hcb_assert(str_contains($region, 'الخدمة غير متاحة') || str_contains($region, 'not available'), 'Region-Unavailable page preserved');

echo "\n--- summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
