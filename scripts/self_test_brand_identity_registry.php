<?php

declare(strict_types=1);

/**
 * M03 FINAL — FreshRoot-only persistent brand identity self-test.
 * No live/project MySQL. Synthetic fixtures only.
 * Memory/fake adapter exists only in this file for pure domain proofs.
 */

require_once dirname(__DIR__) . '/includes/upload_paths.php';
require_once dirname(__DIR__) . '/includes/brand_identity_schema.php';
require_once dirname(__DIR__) . '/includes/brand_identity.php';
require_once dirname(__DIR__) . '/includes/control_rev6_brand_identity_migrate.php';

$results = [];
$failed = 0;

function orange_m03_prove(string $name, bool $ok, string $detail = ''): void
{
    global $results, $failed;
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failed++;
        fwrite(STDERR, 'FAIL ' . $name . ($detail !== '' ? ' ' . $detail : '') . PHP_EOL);
    } else {
        fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
    }
}

function orange_m03_expect_code(callable $fn, string $code): bool
{
    try {
        $fn();

        return false;
    } catch (RuntimeException $e) {
        return $e->getMessage() === $code;
    }
}

function orange_m03_write_fixture(string $name, string $bytes): string
{
    $dir = orange_brand_identity_drafts_dir();
    orange_brand_identity_ensure_dir($dir);
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    file_put_contents($path, $bytes);

    return $path;
}

/**
 * Test-only memory adapter for pure domain state rules. Not production authority.
 */
final class OrangeBrandIdentityFakeDomain
{
    /** @var array<string, mixed> */
    public array $row;

    public function __construct(string $state)
    {
        $this->row = [
            'id' => 1,
            'state' => $state,
            'approved_by' => null,
            'previewed_at' => null,
            'activated_at' => null,
            'archived_at' => null,
        ];
    }

    public function transition(string $to, int $actor): void
    {
        $this->row = orange_brand_identity_apply_transition($this->row, $to, $actor);
    }
}

function orange_m03_sqlite_install(PDO $pdo, bool $seedLocales = true): void
{
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(
        'CREATE TABLE ctrl_locales (
            locale_code TEXT PRIMARY KEY,
            is_active_global INTEGER NOT NULL DEFAULT 1
        )'
    );
    if ($seedLocales) {
        foreach (['ar', 'en', 'fil', 'hi'] as $code) {
            $pdo->prepare('INSERT INTO ctrl_locales (locale_code, is_active_global) VALUES (?, 1)')->execute([$code]);
        }
    }
    $pdo->exec(
        'CREATE TABLE orange_brand_identity_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            state TEXT NOT NULL,
            default_locale TEXT NOT NULL,
            change_note TEXT NULL,
            created_by INTEGER NOT NULL,
            approved_by INTEGER NULL,
            created_at TEXT NOT NULL,
            previewed_at TEXT NULL,
            activated_at TEXT NULL,
            archived_at TEXT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE orange_brand_identity_translations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identity_version_id INTEGER NOT NULL,
            locale TEXT NOT NULL,
            text_key TEXT NOT NULL,
            text_value TEXT NOT NULL,
            UNIQUE (identity_version_id, locale, text_key),
            FOREIGN KEY (identity_version_id) REFERENCES orange_brand_identity_versions(id)
                ON DELETE RESTRICT ON UPDATE RESTRICT
        )'
    );
    $pdo->exec(
        'CREATE TABLE orange_brand_asset_objects (
            sha256 TEXT PRIMARY KEY,
            mime TEXT NOT NULL,
            byte_size INTEGER NOT NULL,
            width INTEGER NOT NULL,
            height INTEGER NOT NULL,
            aspect_ratio REAL NOT NULL,
            alpha_flag INTEGER NOT NULL DEFAULT 0,
            alpha_note TEXT NOT NULL DEFAULT \'\',
            relpath TEXT NOT NULL,
            original_filename TEXT NOT NULL DEFAULT \'\',
            created_by INTEGER NOT NULL,
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE orange_brand_slot_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slot_code TEXT NOT NULL,
            original_object_id TEXT NOT NULL,
            derivative_object_id TEXT NULL,
            state TEXT NOT NULL,
            change_note TEXT NULL,
            uploaded_by INTEGER NOT NULL,
            approved_by INTEGER NULL,
            created_at TEXT NOT NULL,
            previewed_at TEXT NULL,
            activated_at TEXT NULL,
            archived_at TEXT NULL,
            FOREIGN KEY (original_object_id) REFERENCES orange_brand_asset_objects(sha256)
                ON DELETE RESTRICT ON UPDATE RESTRICT,
            FOREIGN KEY (derivative_object_id) REFERENCES orange_brand_asset_objects(sha256)
                ON DELETE RESTRICT ON UPDATE RESTRICT
        )'
    );
    $pdo->exec(
        'CREATE TABLE orange_brand_releases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            identity_version_id INTEGER NOT NULL,
            state TEXT NOT NULL,
            note TEXT NULL,
            current_flag INTEGER NULL,
            created_by INTEGER NOT NULL,
            approved_by INTEGER NULL,
            created_at TEXT NOT NULL,
            activated_at TEXT NULL,
            archived_at TEXT NULL,
            UNIQUE (current_flag),
            FOREIGN KEY (identity_version_id) REFERENCES orange_brand_identity_versions(id)
                ON DELETE RESTRICT ON UPDATE RESTRICT
        )'
    );
    $pdo->exec(
        'CREATE TABLE orange_brand_release_slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id INTEGER NOT NULL,
            slot_code TEXT NOT NULL,
            slot_version_id INTEGER NOT NULL,
            UNIQUE (release_id, slot_code),
            FOREIGN KEY (release_id) REFERENCES orange_brand_releases(id)
                ON DELETE RESTRICT ON UPDATE RESTRICT,
            FOREIGN KEY (slot_version_id) REFERENCES orange_brand_slot_versions(id)
                ON DELETE RESTRICT ON UPDATE RESTRICT
        )'
    );
    $pdo->exec(
        'CREATE TABLE orange_brand_identity_audit_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_type TEXT NOT NULL,
            actor_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            entity_table TEXT NOT NULL DEFAULT \'\',
            entity_id TEXT NOT NULL DEFAULT \'\',
            release_id INTEGER NULL,
            slot_code TEXT NULL,
            identity_version_id INTEGER NULL,
            slot_version_id INTEGER NULL,
            before_id TEXT NULL,
            after_id TEXT NULL,
            change_note TEXT NULL
        )'
    );
}

function orange_m03_extract_function(string $src, string $name): string
{
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($src, "\nfunction ", $start + 1);
    if ($next === false) {
        return substr($src, $start);
    }

    return substr($src, $start, $next - $start);
}

function orange_m03_lock_order_ok(string $fnSrc, bool $needsCurrentRead): bool
{
    $begin = strpos($fnSrc, 'beginTransaction');
    $lock = strpos($fnSrc, 'orange_brand_identity_lock_current_release_rows');
    $create = strpos($fnSrc, 'orange_brand_identity_create_release');
    $activate = strpos($fnSrc, 'orange_brand_identity_activate_release');
    if ($begin === false || $lock === false || $create === false || $activate === false) {
        return false;
    }
    if (!($begin < $lock && $lock < $create && $lock < $activate)) {
        return false;
    }
    if ($needsCurrentRead) {
        $cur = strpos($fnSrc, 'orange_brand_identity_current_release');
        $slots = strpos($fnSrc, 'orange_brand_identity_release_slots');
        if ($cur === false || $slots === false) {
            return false;
        }
        if (!($lock < $cur && $cur < $slots && $slots < $create)) {
            return false;
        }
    }

    return true;
}

$png1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$png2 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAYAAAD0In+KAAAAD0lEQVR42mNgYGD4z8DAwMAAAAoAAwH+6qAAAAAASUVORK5CYII=', true);
$png3 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$webp1 = base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=', true);
$jpeg1 = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wAAAQEB/9k=', true);
if ($png1 === false || $png2 === false || $png3 === false || $webp1 === false) {
    fwrite(STDERR, "FIXTURE_DECODE_FAILED\n");
    exit(2);
}

$dbPath = orange_brand_identity_drafts_dir();
orange_brand_identity_ensure_dir($dbPath);
$dbFile = $dbPath . DIRECTORY_SEPARATOR . 'm03_step3_control.sqlite';
if (is_file($dbFile)) {
    unlink($dbFile);
}
$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
orange_m03_sqlite_install($pdo, true);

$codes = orange_brand_identity_slot_codes();
orange_m03_prove('01_exactly_four_slot_codes', count($codes) === 4 && $codes === [
    'STOREFRONT_BRAND_MARK',
    'STOREFRONT_COMPANY_WORDMARK',
    'ADMIN_BRAND_MARK',
    'ADMIN_COMPANY_WORDMARK',
]);

orange_m03_prove('02_unknown_slot_rejected', orange_m03_expect_code(
    static function (): void {
        orange_brand_identity_assert_slot_code('FIFTH_SLOT');
    },
    'BRAND_IDENTITY_UNKNOWN_SLOT'
));

$tables = orange_brand_identity_schema_table_names();
$ddl = implode("\n", orange_brand_identity_schema_ddl());
$fk = orange_brand_identity_schema_fk_matrix();
orange_m03_prove(
    '03_normalized_translations_no_language_columns',
    count($tables) === 7
    && !str_contains($ddl, 'name_ar')
    && !str_contains($ddl, 'name_en')
    && !str_contains($ddl, 'name_fil')
    && !str_contains($ddl, 'name_hi')
    && str_contains($ddl, 'orange_brand_identity_translations')
    && str_contains($ddl, 'UNIQUE KEY uq_obit_version_locale_key')
    && str_contains($ddl, 'UNIQUE KEY uq_obr_current_flag')
    && count($fk) === 6
);

orange_m03_prove('04_unsupported_locale_fails_closed', orange_m03_expect_code(
    static function () use ($pdo): void {
        orange_brand_identity_assert_locale($pdo, 'zz');
    },
    'BRAND_IDENTITY_UNSUPPORTED_LOCALE'
));

$pngPath = orange_m03_write_fixture('syn_a.png', $png1);
$webpPath = orange_m03_write_fixture('syn_b.webp', $webp1);
$objPng = orange_brand_identity_ingest_object($pdo, $pngPath, 'syn_a.png', 1);
$objWebp = orange_brand_identity_ingest_object($pdo, $webpPath, 'syn_b.webp', 1);
orange_m03_prove('05_valid_png_webp_accepted', ($objPng['mime'] ?? '') === 'image/png' && ($objWebp['mime'] ?? '') === 'image/webp');

$badPath = orange_m03_write_fixture('syn_bad.txt', 'not-an-image');
orange_m03_prove('06_invalid_mime_rejected', orange_m03_expect_code(
    static function () use ($pdo, $badPath): void {
        orange_brand_identity_ingest_object($pdo, $badPath, 'syn_bad.txt', 1);
    },
    'BRAND_IDENTITY_INVALID_MIME'
));

$svgPath = orange_m03_write_fixture('syn.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
orange_m03_prove('07_svg_rejected', orange_m03_expect_code(
    static function () use ($pdo, $svgPath): void {
        orange_brand_identity_ingest_object($pdo, $svgPath, 'syn.svg', 1);
    },
    'BRAND_IDENTITY_SVG_REJECTED'
));

$sha1 = (string) $objPng['sha256'];
$again = orange_brand_identity_ingest_object($pdo, $pngPath, 'syn_a_copy.png', 1);
orange_m03_prove('08_sha_identity_stable', $sha1 === hash_file('sha256', $pngPath) && $again['sha256'] === $sha1);

$beforeCount = orange_brand_identity_historical_object_count($pdo);
orange_brand_identity_ingest_object($pdo, $pngPath, 'syn_a_again.png', 1);
$abs1 = orange_brand_identity_object_abs_path($pdo, $sha1);
$mtime1 = $abs1 !== null ? filemtime($abs1) : 0;
clearstatcache();
orange_brand_identity_ingest_object($pdo, $pngPath, 'syn_a_again2.png', 1);
orange_m03_prove(
    '09_duplicate_object_does_not_overwrite_history',
    orange_brand_identity_historical_object_count($pdo) === $beforeCount
    && orange_brand_identity_deleted_object_files() === 0
    && $mtime1 !== 0
);

orange_m03_prove(
    '10_dimensions_aspect_preserved',
    (int) $objPng['width'] === 1
    && (int) $objPng['height'] === 1
    && (float) $objPng['aspect_ratio'] === 1.0
    && isset($objPng['alpha_flag'])
    && isset($objWebp['width'], $objWebp['height'], $objWebp['aspect_ratio'])
);

$fake = new OrangeBrandIdentityFakeDomain('DRAFT');
$fake->transition('PREVIEW_READY', 1);
orange_m03_prove('11_draft_to_preview_ready_valid', (string) $fake->row['state'] === 'PREVIEW_READY');

orange_m03_prove('12_illegal_transition_rejected', orange_m03_expect_code(
    static function () use ($fake): void {
        $fake->transition('DRAFT', 1);
    },
    'BRAND_IDENTITY_ILLEGAL_TRANSITION'
));

$png2Path = orange_m03_write_fixture('syn_c.png', $png2);
$objects = [
    'STOREFRONT_BRAND_MARK' => orange_brand_identity_ingest_object($pdo, $pngPath, 'mark.png', 1),
    'STOREFRONT_COMPANY_WORDMARK' => orange_brand_identity_ingest_object($pdo, $webpPath, 'word.png', 1),
    'ADMIN_BRAND_MARK' => orange_brand_identity_ingest_object($pdo, $png2Path, 'admin-mark.png', 1),
    'ADMIN_COMPANY_WORDMARK' => orange_brand_identity_ingest_object($pdo, $png2Path, 'admin-word.png', 1),
];

$tr = [];
foreach (orange_brand_identity_approved_locales($pdo) as $loc) {
    foreach (orange_brand_identity_text_keys() as $key) {
        $tr[] = ['locale' => $loc, 'text_key' => $key, 'text_value' => 'SYNTHETIC_' . $key . '_' . $loc];
    }
}
$iid1 = orange_brand_identity_create_identity_version($pdo, 'en', $tr, 1, 'v1');
orange_brand_identity_transition_identity($pdo, $iid1, 'PREVIEW_READY', 1);
orange_m03_prove(
    '11b_persistent_preview_ready',
    (string) orange_brand_identity_identity_version($pdo, $iid1)['state'] === 'PREVIEW_READY'
);

$slotIds = [];
foreach ($objects as $code => $obj) {
    $sid = orange_brand_identity_create_slot_version($pdo, $code, (string) $obj['sha256'], 1, 'slot ' . $code);
    orange_brand_identity_transition_slot($pdo, $sid, 'PREVIEW_READY', 1);
    $slotIds[$code] = $sid;
}

$rid1 = orange_brand_identity_create_release($pdo, $iid1, $slotIds, 1, 'release 1');
orange_brand_identity_activate_release($pdo, $rid1, 1);

orange_m03_prove('13_active_archived_mutation_rejected', orange_m03_expect_code(
    static function () use ($pdo, $iid1): void {
        orange_brand_identity_put_translation($pdo, $iid1, 'en', 'PUBLIC_BRAND_NAME', 'MUTATE');
    },
    'BRAND_IDENTITY_IMMUTABLE_RECORD'
));

$three = $slotIds;
unset($three['ADMIN_COMPANY_WORDMARK']);
orange_m03_prove('14_incomplete_three_slot_release_cannot_activate', orange_m03_expect_code(
    static function () use ($pdo, $iid1, $three): void {
        orange_brand_identity_create_release($pdo, $iid1, $three, 1, 'incomplete');
    },
    'BRAND_IDENTITY_RELEASE_INCOMPLETE'
));

$cur = orange_brand_identity_current_release($pdo);
orange_m03_prove('15_complete_four_slot_release_activates', $cur !== null && (int) $cur['id'] === $rid1 && (string) $cur['state'] === 'ACTIVE' && (int) $cur['current_flag'] === 1);

$rs = orange_brand_identity_release_slots($pdo, $rid1);
$slotCodesInRelease = array_values(array_map(static fn (array $r): string => (string) $r['slot_code'], $rs));
sort($slotCodesInRelease);
$expectedCodes = orange_brand_identity_slot_codes();
$sortedExpected = $expectedCodes;
sort($sortedExpected);
orange_m03_prove(
    '16_release_has_identity_and_four_unique_slots',
    (int) $cur['identity_version_id'] === $iid1
    && count($rs) === 4
    && $slotCodesInRelease === $sortedExpected
    && count(array_unique(array_map(static fn (array $r): int => (int) $r['slot_version_id'], $rs))) === 4
);

$tr2 = $tr;
foreach ($tr2 as $i => $row) {
    if ($row['text_key'] === 'PUBLIC_BRAND_NAME' && $row['locale'] === 'en') {
        $tr2[$i]['text_value'] = 'SYNTHETIC_PUBLIC_BRAND_NAME_EN_V2';
    }
}
$rid2 = orange_brand_identity_change_identity_text($pdo, 'en', $tr2, 1, 'text v2');
$cur2 = orange_brand_identity_current_release($pdo);
$iid2 = (int) $cur2['identity_version_id'];
orange_m03_prove(
    '19_identity_text_change_new_version_old_immutable',
    $rid2 !== $rid1
    && $iid2 !== $iid1
    && (string) orange_brand_identity_identity_version($pdo, $iid1)['state'] === 'ARCHIVED'
    && (string) orange_brand_identity_identity_version($pdo, $iid2)['state'] === 'ACTIVE'
    && orange_m03_expect_code(static function () use ($pdo, $iid1): void {
        orange_brand_identity_put_translation($pdo, $iid1, 'en', 'APP_SHORT_NAME', 'X');
    }, 'BRAND_IDENTITY_IMMUTABLE_RECORD')
);

$newMark = orange_brand_identity_create_slot_version(
    $pdo,
    'STOREFRONT_BRAND_MARK',
    (string) $objects['ADMIN_BRAND_MARK']['sha256'],
    1,
    'replacement mark'
);
orange_brand_identity_transition_slot($pdo, $newMark, 'PREVIEW_READY', 1);
$mapNow = [];
foreach (orange_brand_identity_release_slots($pdo, (int) $cur2['id']) as $row) {
    $mapNow[(string) $row['slot_code']] = (int) $row['slot_version_id'];
}
$mapNow['STOREFRONT_BRAND_MARK'] = $newMark;
$rid3 = orange_brand_identity_create_release($pdo, $iid2, $mapNow, 1, 'replace one slot');
orange_brand_identity_activate_release($pdo, $rid3, 1);

$rid4 = orange_brand_identity_rollback_full($pdo, $rid1, 1);
$cur4 = orange_brand_identity_current_release($pdo);
$backSlots = [];
foreach (orange_brand_identity_release_slots($pdo, (int) $cur4['id']) as $row) {
    $backSlots[(string) $row['slot_code']] = (int) $row['slot_version_id'];
}
$origSlots = [];
foreach (orange_brand_identity_release_slots($pdo, $rid1) as $row) {
    $origSlots[(string) $row['slot_code']] = (int) $row['slot_version_id'];
}
orange_m03_prove(
    '17_full_rollback_restores_slots_and_identity',
    $rid4 !== $rid1
    && (int) $cur4['identity_version_id'] === $iid1
    && $backSlots === $origSlots
    && (string) orange_brand_identity_release($pdo, $rid1)['state'] === 'ARCHIVED'
);

$rid5 = orange_brand_identity_rollback_slot($pdo, 'STOREFRONT_BRAND_MARK', $newMark, 1);
$cur5 = orange_brand_identity_current_release($pdo);
$afterSingle = [];
foreach (orange_brand_identity_release_slots($pdo, (int) $cur5['id']) as $row) {
    $afterSingle[(string) $row['slot_code']] = (int) $row['slot_version_id'];
}
$changed = 0;
foreach ($backSlots as $code => $sid) {
    if ($afterSingle[$code] !== $sid) {
        $changed++;
    }
}
orange_m03_prove(
    '18_single_slot_rollback_new_release_one_change',
    $rid5 !== (int) $cur4['id']
    && $changed === 1
    && $afterSingle['STOREFRONT_BRAND_MARK'] === $newMark
    && (int) $cur5['identity_version_id'] === (int) $cur4['identity_version_id']
    && (string) orange_brand_identity_release($pdo, (int) $cur4['id'])['state'] === 'ARCHIVED'
);

$objCount = orange_brand_identity_historical_object_count($pdo);
$objFiles = glob(orange_brand_identity_objects_dir() . DIRECTORY_SEPARATOR . '*') ?: [];
orange_m03_prove(
    '20_rollback_deletes_zero_historical_objects',
    orange_brand_identity_deleted_object_files() === 0
    && $objCount >= 2
    && count($objFiles) >= 2
);

$lib = (string) file_get_contents(dirname(__DIR__) . '/includes/brand_identity.php');
$schema = (string) file_get_contents(dirname(__DIR__) . '/includes/brand_identity_schema.php');
$rev6 = (string) file_get_contents(dirname(__DIR__) . '/includes/control_rev6_brand_identity_migrate.php');
orange_m03_prove(
    '21_no_runtime_wiring_in_candidate',
    !str_contains($lib, 'includes/header.php')
    && !str_contains($lib, 'admin/partials/header.php')
    && !str_contains($lib, 'manifest.php')
    && !str_contains($lib, 'pages/home.php')
    && !str_contains($lib, 'pages/document.php')
    && !str_contains($lib, 'admin/api/brand_identity')
    && !str_contains($schema, "define('ORANGE_CONTROL_SCHEMA_REVISION'")
    && !str_contains($schema, 'orange_catalog_ensure_schema')
    && !str_contains($rev6, "define('ORANGE_CONTROL_SCHEMA_REVISION'")
);

$contract = $lib . "\n" . $schema . "\n" . $ddl;
orange_m03_prove(
    '22_no_domain_country_vendor_brand_authority',
    !str_contains($contract, 'company_settings')
    && !str_contains($contract, 'control_domain')
    && !str_contains($contract, 'vendor_warehouse')
    && !preg_match('/country_id/', $contract)
);

$libHasStaticStore = preg_match('/static\s+\$store\b/', $lib) === 1;
$pdo2 = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2->exec('PRAGMA foreign_keys = ON');
$curReconnect = orange_brand_identity_current_release($pdo2);
orange_m03_prove(
    '23_production_state_is_persistent_not_static_memory',
    !$libHasStaticStore
    && str_contains($lib, 'FROM orange_brand_releases')
    && $curReconnect !== null
    && (int) $curReconnect['id'] === (int) $cur5['id']
    && (int) $curReconnect['identity_version_id'] === (int) $cur5['identity_version_id']
);

$orphanFailed = false;
try {
    $pdo->exec('INSERT INTO orange_brand_identity_translations (identity_version_id, locale, text_key, text_value) VALUES (99999, \'en\', \'PUBLIC_BRAND_NAME\', \'x\')');
} catch (Throwable $e) {
    $orphanFailed = true;
}
orange_m03_prove('24_relational_orphan_insertion_prevented', $orphanFailed);

$twoCurrentFailed = false;
try {
    $pdo->exec('INSERT INTO orange_brand_releases (identity_version_id, state, note, current_flag, created_by, created_at) VALUES (' . (int) $iid1 . ', \'ACTIVE\', \'dup\', 1, 1, \'2026-01-01 00:00:00\')');
} catch (Throwable $e) {
    $twoCurrentFailed = true;
}
$currentCount = (int) $pdo->query('SELECT COUNT(*) FROM orange_brand_releases WHERE current_flag = 1')->fetchColumn();
orange_m03_prove('25_two_current_release_state_prevented', $twoCurrentFailed && $currentCount === 1);

$beforeTxCurrent = (int) orange_brand_identity_current_release($pdo)['id'];
$pdo->exec(
    'CREATE TRIGGER trg_fail_second_current BEFORE UPDATE ON orange_brand_releases
     WHEN NEW.current_flag = 1 AND NEW.id <> ' . $beforeTxCurrent . '
     BEGIN SELECT RAISE(ABORT, \'forced_activation_fail\'); END'
);
$mapFail = [];
foreach (orange_brand_identity_release_slots($pdo, $beforeTxCurrent) as $row) {
    $mapFail[(string) $row['slot_code']] = (int) $row['slot_version_id'];
}
$ridFail = orange_brand_identity_create_release($pdo, (int) orange_brand_identity_current_release($pdo)['identity_version_id'], $mapFail, 1, 'tx fail');
$activateFailed = false;
try {
    orange_brand_identity_activate_release($pdo, $ridFail, 1);
} catch (Throwable $e) {
    $activateFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_fail_second_current');
$afterTxCurrent = orange_brand_identity_current_release($pdo);
orange_m03_prove(
    '26_activation_transaction_rollback_restores_previous_current',
    $activateFailed
    && $afterTxCurrent !== null
    && (int) $afterTxCurrent['id'] === $beforeTxCurrent
    && (int) $pdo->query('SELECT COUNT(*) FROM orange_brand_releases WHERE current_flag = 1')->fetchColumn() === 1
);

orange_m03_prove(
    '27_control_audit_event_is_durable',
    orange_brand_identity_audit_count($pdo) > 0
    && (int) $pdo->query("SELECT COUNT(*) FROM orange_brand_identity_audit_events WHERE event_type = 'brand_identity.release.activate'")->fetchColumn() > 0
);

$emptyDb = $dbPath . DIRECTORY_SEPARATOR . 'm03_step3_empty_locale.sqlite';
if (is_file($emptyDb)) {
    unlink($emptyDb);
}
$emptyPdo = new PDO('sqlite:' . $emptyDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
orange_m03_sqlite_install($emptyPdo, false);
orange_m03_prove('28_missing_locale_authority_fails_closed', orange_m03_expect_code(
    static function () use ($emptyPdo): void {
        orange_brand_identity_approved_locales($emptyPdo);
    },
    'BRAND_IDENTITY_LOCALE_AUTHORITY_UNAVAILABLE'
) && !preg_match("/\\['ar'\\s*,\\s*'en'\\s*,\\s*'fil'\\s*,\\s*'hi'\\]/", $lib));

$objDir = orange_brand_identity_objects_dir();
$objBak = $objDir . '_bak_step3';
$restored = false;
$fileWriteFailed = false;
if (is_dir($objDir)) {
    rename($objDir, $objBak);
}
file_put_contents($objDir, 'not-a-directory');
$png3Path = orange_m03_write_fixture('syn_d.png', $png3);
try {
    orange_brand_identity_ingest_object($pdo, $png3Path, 'syn_d.png', 1);
} catch (RuntimeException $e) {
    $fileWriteFailed = $e->getMessage() === 'BRAND_IDENTITY_STORAGE_UNAVAILABLE';
}
if (is_file($objDir)) {
    unlink($objDir);
}
if (is_dir($objBak)) {
    rename($objBak, $objDir);
    $restored = true;
}
orange_m03_prove('29_object_file_failure_cannot_create_valid_metadata', $fileWriteFailed && $restored && orange_brand_identity_object($pdo, hash_file('sha256', $png3Path)) === null);

$pdo->exec(
    'CREATE TRIGGER trg_fail_object_meta BEFORE INSERT ON orange_brand_asset_objects
     BEGIN SELECT RAISE(ABORT, \'forced_meta_fail\'); END'
);
$metaFailed = false;
try {
    orange_brand_identity_ingest_object($pdo, $png3Path, 'syn_d.png', 1);
} catch (RuntimeException $e) {
    $metaFailed = $e->getMessage() === 'BRAND_IDENTITY_METADATA_FAILED';
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_fail_object_meta');
$sha3 = hash_file('sha256', $png3Path);
orange_m03_prove(
    '30_metadata_failure_cannot_report_successful_ingest',
    $metaFailed && orange_brand_identity_object($pdo, $sha3) === null
);

$goodVerify = true;
try {
    orange_brand_identity_verify_schema($pdo);
} catch (Throwable $e) {
    $goodVerify = false;
}

$badShape = $dbPath . DIRECTORY_SEPARATOR . 'm03_step3a_bad_shape.sqlite';
if (is_file($badShape)) {
    unlink($badShape);
}
$badPdo = new PDO('sqlite:' . $badShape, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$badPdo->exec('PRAGMA foreign_keys = ON');
foreach (orange_brand_identity_schema_table_names() as $t) {
    if ($t === 'orange_brand_identity_versions') {
        $badPdo->exec('CREATE TABLE orange_brand_identity_versions (id INTEGER PRIMARY KEY, default_locale TEXT)');
    } else {
        $badPdo->exec('CREATE TABLE ' . $t . ' (id INTEGER PRIMARY KEY)');
    }
}
orange_m03_prove('31_wrong_shaped_table_fails_schema_verify', $goodVerify && orange_m03_expect_code(
    static function () use ($badPdo): void {
        orange_brand_identity_verify_schema($badPdo);
    },
    'BRAND_IDENTITY_SCHEMA_VERIFY_FAILED'
));

$missUq = $dbPath . DIRECTORY_SEPARATOR . 'm03_step3a_miss_unique.sqlite';
if (is_file($missUq)) {
    unlink($missUq);
}
$uqPdo = new PDO('sqlite:' . $missUq, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
orange_m03_sqlite_install($uqPdo, true);
$uqPdo->exec('DROP TABLE orange_brand_releases');
$uqPdo->exec(
    'CREATE TABLE orange_brand_releases (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        identity_version_id INTEGER NOT NULL,
        state TEXT NOT NULL,
        note TEXT NULL,
        current_flag INTEGER NULL,
        created_by INTEGER NOT NULL,
        approved_by INTEGER NULL,
        created_at TEXT NOT NULL,
        activated_at TEXT NULL,
        archived_at TEXT NULL,
        FOREIGN KEY (identity_version_id) REFERENCES orange_brand_identity_versions(id)
            ON DELETE RESTRICT ON UPDATE RESTRICT
    )'
);
orange_m03_prove('32_missing_required_unique_fails_schema_verify', orange_m03_expect_code(
    static function () use ($uqPdo): void {
        orange_brand_identity_verify_schema($uqPdo);
    },
    'BRAND_IDENTITY_SCHEMA_VERIFY_FAILED'
));

$missFk = $dbPath . DIRECTORY_SEPARATOR . 'm03_step3a_miss_fk.sqlite';
if (is_file($missFk)) {
    unlink($missFk);
}
$fkPdo = new PDO('sqlite:' . $missFk, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
orange_m03_sqlite_install($fkPdo, true);
$fkPdo->exec('DROP TABLE orange_brand_identity_translations');
$fkPdo->exec(
    'CREATE TABLE orange_brand_identity_translations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        identity_version_id INTEGER NOT NULL,
        locale TEXT NOT NULL,
        text_key TEXT NOT NULL,
        text_value TEXT NOT NULL,
        UNIQUE (identity_version_id, locale, text_key)
    )'
);
orange_m03_prove('33_missing_fk_fails_schema_verify', orange_m03_expect_code(
    static function () use ($fkPdo): void {
        orange_brand_identity_verify_schema($fkPdo);
    },
    'BRAND_IDENTITY_SCHEMA_VERIFY_FAILED'
));

$iso = $dbPath . DIRECTORY_SEPARATOR . 'm03_step3a_iso.sqlite';
if (is_file($iso)) {
    unlink($iso);
}
$isoPdo = new PDO('sqlite:' . $iso, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
orange_m03_sqlite_install($isoPdo, true);
$isoPdo->exec(
    "CREATE TRIGGER trg_fail_ingest_audit BEFORE INSERT ON orange_brand_identity_audit_events
     WHEN NEW.event_type = 'brand_identity.object.ingest'
     BEGIN SELECT RAISE(ABORT, 'forced_audit_fail'); END"
);
$auditMetaFailed = false;
try {
    orange_brand_identity_ingest_object($isoPdo, $png3Path, 'syn_d_audit.png', 1);
} catch (Throwable $e) {
    $auditMetaFailed = true;
}
$isoPdo->exec('DROP TRIGGER IF EXISTS trg_fail_ingest_audit');
orange_m03_prove(
    '34_object_audit_failure_leaves_no_metadata',
    $auditMetaFailed && orange_brand_identity_object($isoPdo, hash_file('sha256', $png3Path)) === null
);

$isoObj = orange_brand_identity_ingest_object($isoPdo, $pngPath, 'iso_mark.png', 1);
$isoPdo->exec(
    "CREATE TRIGGER trg_fail_slot_audit BEFORE INSERT ON orange_brand_identity_audit_events
     WHEN NEW.event_type = 'brand_identity.slot.create'
     BEGIN SELECT RAISE(ABORT, 'forced_slot_audit'); END"
);
$slotAuditFailed = false;
try {
    orange_brand_identity_create_slot_version($isoPdo, 'STOREFRONT_BRAND_MARK', (string) $isoObj['sha256'], 1, 'x');
} catch (Throwable $e) {
    $slotAuditFailed = true;
}
$isoPdo->exec('DROP TRIGGER IF EXISTS trg_fail_slot_audit');
$slotLeft = (int) $isoPdo->query('SELECT COUNT(*) FROM orange_brand_slot_versions')->fetchColumn();
orange_m03_prove('35_slot_create_audit_failure_leaves_no_slot_row', $slotAuditFailed && $slotLeft === 0);

$isoTr = [];
foreach (orange_brand_identity_approved_locales($isoPdo) as $loc) {
    foreach (orange_brand_identity_text_keys() as $key) {
        $isoTr[] = ['locale' => $loc, 'text_key' => $key, 'text_value' => 'ISO_' . $key . '_' . $loc];
    }
}
$isoIid = orange_brand_identity_create_identity_version($isoPdo, 'en', $isoTr, 1, 'iso');
$isoPdo->exec(
    "CREATE TRIGGER trg_fail_id_audit BEFORE INSERT ON orange_brand_identity_audit_events
     WHEN NEW.event_type = 'brand_identity.identity.transition'
     BEGIN SELECT RAISE(ABORT, 'forced_id_audit'); END"
);
$trAuditFailed = false;
try {
    orange_brand_identity_transition_identity($isoPdo, $isoIid, 'PREVIEW_READY', 1);
} catch (Throwable $e) {
    $trAuditFailed = true;
}
$isoPdo->exec('DROP TRIGGER IF EXISTS trg_fail_id_audit');
orange_m03_prove(
    '36_transition_audit_failure_restores_prior_state',
    $trAuditFailed && (string) orange_brand_identity_identity_version($isoPdo, $isoIid)['state'] === 'DRAFT'
);

$libNow = (string) file_get_contents(dirname(__DIR__) . '/includes/brand_identity.php');
$rbSlotSrc = orange_m03_extract_function($libNow, 'orange_brand_identity_rollback_slot');
$chgSrc = orange_m03_extract_function($libNow, 'orange_brand_identity_change_identity_text');
$rbFullSrc = orange_m03_extract_function($libNow, 'orange_brand_identity_rollback_full');
orange_m03_prove('37_rollback_slot_locks_before_current_map', orange_m03_lock_order_ok($rbSlotSrc, true));
orange_m03_prove('38_change_identity_text_locks_before_current_map', orange_m03_lock_order_ok($chgSrc, true));
orange_m03_prove('39_full_rollback_locks_before_activation', orange_m03_lock_order_ok($rbFullSrc, false));

$archIdent = (int) $pdo->query(
    "SELECT COUNT(*) FROM orange_brand_identity_audit_events
     WHERE event_type = 'brand_identity.identity.transition'
       AND change_note IN ('ACTIVE->ARCHIVED', 'ARCHIVED->ACTIVE')"
)->fetchColumn();
$archSlot = (int) $pdo->query(
    "SELECT COUNT(*) FROM orange_brand_identity_audit_events
     WHERE event_type = 'brand_identity.slot.transition'
       AND change_note IN ('ACTIVE->ARCHIVED', 'ARCHIVED->ACTIVE')"
)->fetchColumn();
orange_m03_prove(
    '40_activation_archive_reactivate_emits_member_transition_audit',
    $archIdent > 0 && $archSlot > 0
    && str_contains($libNow, 'orange_brand_identity_audit_member_transition')
);

orange_brand_identity_transition_identity($isoPdo, $isoIid, 'PREVIEW_READY', 1);
orange_m03_prove(
    '41_direct_identity_activation_rejected',
    (string) orange_brand_identity_identity_version($isoPdo, $isoIid)['state'] === 'PREVIEW_READY'
    && orange_m03_expect_code(
        static function () use ($isoPdo, $isoIid): void {
            orange_brand_identity_transition_identity($isoPdo, $isoIid, 'ACTIVE', 1);
        },
        'BRAND_IDENTITY_RELEASE_ACTIVATION_REQUIRED'
    )
    && (string) orange_brand_identity_identity_version($isoPdo, $isoIid)['state'] === 'PREVIEW_READY'
);

$isoSid = orange_brand_identity_create_slot_version(
    $isoPdo,
    'STOREFRONT_BRAND_MARK',
    (string) $isoObj['sha256'],
    1,
    'gate slot'
);
orange_brand_identity_transition_slot($isoPdo, $isoSid, 'PREVIEW_READY', 1);
orange_m03_prove(
    '42_direct_slot_activation_rejected',
    (string) orange_brand_identity_slot_version($isoPdo, $isoSid)['state'] === 'PREVIEW_READY'
    && orange_m03_expect_code(
        static function () use ($isoPdo, $isoSid): void {
            orange_brand_identity_transition_slot($isoPdo, $isoSid, 'ACTIVE', 1);
        },
        'BRAND_IDENTITY_RELEASE_ACTIVATION_REQUIRED'
    )
    && (string) orange_brand_identity_slot_version($isoPdo, $isoSid)['state'] === 'PREVIEW_READY'
);

$isoSlots = [];
foreach (orange_brand_identity_slot_codes() as $code) {
    $sid = orange_brand_identity_create_slot_version($isoPdo, $code, (string) $isoObj['sha256'], 1, 'gate ' . $code);
    orange_brand_identity_transition_slot($isoPdo, $sid, 'PREVIEW_READY', 1);
    $isoSlots[$code] = $sid;
}
$isoRid = orange_brand_identity_create_release($isoPdo, $isoIid, $isoSlots, 1, 'gate release');
orange_brand_identity_transition_release($isoPdo, $isoRid, 'PREVIEW_READY', 1);
$isoRelBefore = orange_brand_identity_release($isoPdo, $isoRid);
orange_m03_prove(
    '43_direct_release_activation_rejected',
    (string) $isoRelBefore['state'] === 'PREVIEW_READY'
    && $isoRelBefore['current_flag'] === null
    && orange_m03_expect_code(
        static function () use ($isoPdo, $isoRid): void {
            orange_brand_identity_transition_release($isoPdo, $isoRid, 'ACTIVE', 1);
        },
        'BRAND_IDENTITY_RELEASE_ACTIVATION_REQUIRED'
    )
    && (string) orange_brand_identity_release($isoPdo, $isoRid)['state'] === 'PREVIEW_READY'
    && orange_brand_identity_release($isoPdo, $isoRid)['current_flag'] === null
);

$curNow = orange_brand_identity_current_release($pdo);
$curId = $curNow !== null ? (int) $curNow['id'] : 0;
orange_m03_prove(
    '44_direct_current_release_archive_rejected',
    $curNow !== null
    && (string) $curNow['state'] === 'ACTIVE'
    && (int) $curNow['current_flag'] === 1
    && orange_m03_expect_code(
        static function () use ($pdo, $curId): void {
            orange_brand_identity_transition_release($pdo, $curId, 'ARCHIVED', 1);
        },
        'BRAND_IDENTITY_RELEASE_ACTIVATION_REQUIRED'
    )
    && (string) orange_brand_identity_release($pdo, $curId)['state'] === 'ACTIVE'
    && (int) orange_brand_identity_release($pdo, $curId)['current_flag'] === 1
);

$jpegOk = true;
if (is_string($jpeg1) && $jpeg1 !== '') {
    $jp = orange_m03_write_fixture('syn.jpg', $jpeg1);
    try {
        orange_brand_identity_ingest_object($pdo, $jp, 'syn.jpg', 1);
    } catch (RuntimeException $e) {
        $jpegOk = in_array($e->getMessage(), ['BRAND_IDENTITY_INVALID_IMAGE', 'BRAND_IDENTITY_INVALID_MIME'], true);
    }
}

fwrite(STDOUT, 'JPEG_OPTIONAL=' . ($jpegOk ? 'HANDLED' : 'UNEXPECTED') . PHP_EOL);
fwrite(STDOUT, 'TABLES=' . implode(',', $tables) . PHP_EOL);
fwrite(STDOUT, 'AUDITS=' . orange_brand_identity_audit_count($pdo) . PHP_EOL);
fwrite(STDOUT, 'FUTURE_REV=' . ORANGE_BRAND_IDENTITY_FUTURE_CONTROL_REVISION_BINDING . PHP_EOL);
fwrite(STDOUT, 'LIVE_REV_MUST_REMAIN=' . ORANGE_BRAND_IDENTITY_LIVE_CONTROL_REVISION_MUST_REMAIN . PHP_EOL);

if ($failed > 0) {
    fwrite(STDERR, 'FAILED_COUNT=' . $failed . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "M03_FINAL_SELF_TEST_PASS\n");
exit(0);
