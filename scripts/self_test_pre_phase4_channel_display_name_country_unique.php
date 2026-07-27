<?php

declare(strict_types=1);

/**
 * Pre-Phase-4 — Channel «اسم الواجهة» country-scoped uniqueness.
 *
 * Usage: php scripts/self_test_pre_phase4_channel_display_name_country_unique.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function cdn_assert(bool $ok, string $label): void
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

$save = (string) file_get_contents($root . '/admin/api/channels/save.php');
$page = (string) file_get_contents($root . '/admin/pages/channels.php');
$schema = (string) file_get_contents($root . '/includes/catalog_schema.php');
$dbSql = is_file($root . '/scripts/orange_db.sql')
    ? (string) file_get_contents($root . '/scripts/orange_db.sql')
    : '';

/* Exact field mapping */
cdn_assert(str_contains($page, '>اسم الواجهة<') || str_contains($page, 'اسم الواجهة</label>'), 'UI label اسم الواجهة');
cdn_assert(str_contains($page, 'id="channel_name"'), 'input #channel_name');
cdn_assert(str_contains($page, 'name: document.getElementById(\'channel_name\')'), 'JS sends name');
cdn_assert(str_contains($save, 'channels_normalize_display_name'), 'normalize helper');
cdn_assert(str_contains($save, 'channels_display_name_taken_in_country'), 'app duplicate check');
cdn_assert(str_contains($save, 'channel_display_name_duplicate_in_country'), 'stable error code');
cdn_assert(str_contains($save, 'اسم الواجهة مستخدم بالفعل لقناة أخرى داخل هذه الدولة.'), 'Arabic duplicate message');
cdn_assert(str_contains($save, '409'), 'HTTP 409 path');

/* Create/update country authority */
cdn_assert(str_contains($save, 'orange_admin_context_country_id'), 'uses admin country context');
cdn_assert(str_contains($save, 'country_context_required'), 'create requires context');
cdn_assert(str_contains($save, 'SELECT id, slug, path_segment, country_id FROM channels'), 'update loads stored country');
cdn_assert(
    !preg_match('/UPDATE channels SET name = \?[\s\S]{0,200}country_id = \?/', $save),
    'update does not rewrite country_id'
);

/* Schema 124 */
cdn_assert(
    (bool) preg_match("/ORANGE_CATALOG_SCHEMA_PHP_REVISION',\s*124\s*\)/", $schema),
    'schema revision 124'
);
cdn_assert(str_contains($schema, 'function orange_catalog_migrate_channels_country_name_unique_v124'), 'v124 migration fn');
cdn_assert(str_contains($schema, 'uq_channels_country_name'), 'composite unique index name');
cdn_assert(str_contains($schema, 'HAVING cnt > 1'), 'duplicate scan before index');
cdn_assert($dbSql === '' || str_contains($dbSql, 'uq_channels_country_name'), 'orange_db.sql has country+name unique');

/* Unchanged contracts */
cdn_assert(str_contains($save, 'channels_next_unique_slug'), 'slug uniqueness helper remains');
cdn_assert(str_contains($save, 'is_country_default'), 'country default unchanged');
cdn_assert(str_contains($schema, 'uq_channels_country_slug'), 'slug unique index untouched');
cdn_assert(str_contains($schema, 'uq_channels_country_path'), 'path unique index untouched');

$seedBody = (string) file_get_contents($root . '/includes/catalog_bootstrap_store.php');
$seedFn = strpos($seedBody, 'function orange_catalog_seed_default_channels_if_empty');
$seedSnippet = $seedFn !== false ? substr($seedBody, $seedFn, 500) : '';
cdn_assert(!preg_match('/INSERT\s+INTO\s+channels/i', $seedSnippet), 'no channel auto-seed INSERT');

/* SQLite: normalize + uniqueness matrix (mirrors save.php helpers; API file is not includable). */
function channels_normalize_display_name(string $raw): string
{
    return trim($raw);
}

function channels_display_name_taken_in_country(
    PDO $pdo,
    int $countryId,
    string $normalizedName,
    ?int $exceptChannelId = null
): bool {
    if ($countryId <= 0 || $normalizedName === '') {
        return false;
    }
    if ($exceptChannelId !== null && $exceptChannelId > 0) {
        $st = $pdo->prepare(
            'SELECT id FROM channels WHERE country_id = ? AND name = ? AND id <> ? LIMIT 1'
        );
        $st->execute([$countryId, $normalizedName, $exceptChannelId]);
    } else {
        $st = $pdo->prepare(
            'SELECT id FROM channels WHERE country_id = ? AND name = ? LIMIT 1'
        );
        $st->execute([$countryId, $normalizedName]);
    }

    return (bool) $st->fetchColumn();
}

cdn_assert(channels_normalize_display_name('  متجر س  ') === 'متجر س', 'trim stores normalized');
cdn_assert(channels_normalize_display_name('   ') === '', 'whitespace-only → empty');
cdn_assert(channels_normalize_display_name('') === '', 'empty → empty');

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
    $pdo->exec('CREATE UNIQUE INDEX uq_channels_country_name ON channels (country_id, name)');
    $pdo->exec('CREATE UNIQUE INDEX uq_channels_country_slug ON channels (country_id, slug)');
    $pdo->exec('CREATE UNIQUE INDEX uq_channels_country_path ON channels (country_id, path_segment)');

    $ins = $pdo->prepare(
        'INSERT INTO channels (country_id, name, slug, path_segment, is_active) VALUES (?,?,?,?,?)'
    );
    $ins->execute([1, 'س', 'kw-a', 'kw-a', 1]);
    cdn_assert(!channels_display_name_taken_in_country($pdo, 1, 'س', 1), 'A11 own name not duplicate');
    cdn_assert(channels_display_name_taken_in_country($pdo, 1, 'س', null), 'A2 same-country duplicate detected');
    cdn_assert(
        channels_display_name_taken_in_country($pdo, 1, channels_normalize_display_name(' س '), null),
        'A3 trimmed match rejects'
    );
    cdn_assert(!channels_display_name_taken_in_country($pdo, 2, 'س', null), 'A4 Egypt same name allowed (app)');
    cdn_assert(!channels_display_name_taken_in_country($pdo, 3, 'س', null), 'A5 Saudi same name allowed (app)');
    cdn_assert(!channels_display_name_taken_in_country($pdo, 1, 'اسم آخر', null), 'A6 different name allowed');

    $ins->execute([1, 'معطّل', 'kw-off', 'kw-off', 0]);
    cdn_assert(channels_display_name_taken_in_country($pdo, 1, 'معطّل', null), 'B9 inactive reserves name');

    $ins->execute([2, 'س', 'eg-a', 'eg-a', 1]);
    cdn_assert(true, 'D18 cross-country same-name INSERT succeeds');

    $blocked = false;
    try {
        $ins->execute([1, 'س', 'kw-b', 'kw-b', 1]);
    } catch (Throwable $e) {
        $blocked = true;
    }
    cdn_assert($blocked, 'D17 same-country duplicate INSERT fails at DB');

    $raceBlocked = false;
    try {
        $ins->execute([1, 'س', 'kw-c', 'kw-c', 0]);
    } catch (Throwable $e) {
        $raceBlocked = true;
    }
    cdn_assert($raceBlocked, 'D19 race-equivalent second insert blocked by UNIQUE');
} catch (Throwable $e) {
    cdn_assert(false, 'sqlite fixture: ' . $e->getMessage());
}

/* Schema compatibility files */
$reg = (string) file_get_contents($root . '/config/backup_table_registry.json');
$matrix = (string) file_get_contents($root . '/config/country_restore_boundary_matrix.json');
$expect = (string) file_get_contents($root . '/config/country_restore_schema_expectations.json');
$rec = (string) file_get_contents($root . '/includes/backup/recovery_validation.php');
cdn_assert(str_contains($reg, '"schema_revision": 124'), 'F32 registry 124');
cdn_assert(str_contains($matrix, '124'), 'F32 matrix 124');
cdn_assert(str_contains($expect, '"schema_revision": 124'), 'F32 expectations 124');
cdn_assert(str_contains($rec, 'EXPECTED_SCHEMA_REVISION = 124'), 'F35 recovery expected 124');

echo "\n--- summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
