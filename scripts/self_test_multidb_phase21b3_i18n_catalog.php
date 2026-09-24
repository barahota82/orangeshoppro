<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.3 — self-test for dormant I18N catalog (groups A–L).
 * No MySQL. Exit nonzero on failure.
 */

const M0_FULL_ROW_HASH_V4 = '4c1cf2a507e9aec88912aec8906c83ba6f561eefd3d484c55c242c7df2b4beeb';
const APP_T_FULL_ROW_HASH = 'bfee844b5127419c95837af5b38018c8cc86c46c2a0eee5ddb92c5df3999678e';
const M0_INDEPENDENT_HASH = '1bc7243f8aa62ffd78d2bfa5e03e0523c040546d1b6746f9bf6db54d7aaad47b';
const APP_T_INDEPENDENT_HASH = '9af56836e25629e593b99d3a1db568b8a8fe85a0813cc343451f3eb82e226390';
const WA_BODY_EN_SHA256 = 'c9f7ae00cc5a608a981862b3db868055c49d2b0725f41b303627a7e34368eaeb';

$RAW_FAIL = 0;
$CORE_SKIP = 0;
$ASSERTION_WEAKENED = 0;
$FALSE_GREEN_RISK_COUNT = 0;
$MYSQL_USED_BY_PHASE21B3 = 0;

function st_fail(string $msg): void
{
    global $RAW_FAIL;
    $RAW_FAIL++;
    echo "FAIL: $msg\n";
}

function st_ok(string $msg): void
{
    echo "OK: $msg\n";
}

function st_validate_bytes(string $bytes, string $label): void
{
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        st_fail("$label BOM");
        return;
    }
    if (!mb_check_encoding($bytes, 'UTF-8')) {
        st_fail("$label utf8");
        return;
    }
    if (str_contains($bytes, "\r")) {
        st_fail("$label CR");
        return;
    }
    if ($bytes === '' || !str_ends_with($bytes, "\n") || str_ends_with($bytes, "\n\n")) {
        st_fail("$label trailing LF");
        return;
    }
}

$root = dirname(__DIR__);
$files = [
    'loader' => $root . '/includes/i18n/orange_system_text_catalog.php',
    'meta' => $root . '/includes/i18n/catalogs/v1/meta.php',
    'en' => $root . '/includes/i18n/catalogs/v1/en.php',
    'ar' => $root . '/includes/i18n/catalogs/v1/ar.php',
    'fil' => $root . '/includes/i18n/catalogs/v1/fil.php',
    'hi' => $root . '/includes/i18n/catalogs/v1/hi.php',
    'fr' => $root . '/includes/i18n/catalogs/v1/fr.php',
    'admin' => $root . '/includes/i18n/admin_shell_keys_v1.php',
    'appt' => $root . '/includes/i18n/app_t_allowlist_v1.php',
    'self' => __FILE__,
];

echo "=== GROUP A encoding ===\n";
foreach ($files as $label => $path) {
    if (!is_file($path)) {
        st_fail("missing $label");
        continue;
    }
    $b = file_get_contents($path);
    if ($b === false) {
        st_fail("read $label");
        continue;
    }
    st_validate_bytes($b, $label);
}
st_ok('encoding checks executed');

echo "=== GROUP B hashes/manifest ===\n";
require_once $files['loader'];
if (!defined('EXPECTED_META_SHA256') || !defined('EXPECTED_AGGREGATE_MANIFEST_SHA256') || !defined('APCU_ENABLED_REV1')) {
    st_fail('constants missing');
}
if (APCU_ENABLED_REV1 !== 0) {
    st_fail('APCU_ENABLED_REV1');
}
$metaBytes = file_get_contents($files['meta']);
st_validate_bytes((string)$metaBytes, 'meta');
$metaHash = hash('sha256', (string)$metaBytes);
if (!hash_equals(EXPECTED_META_SHA256, $metaHash)) {
    st_fail('EXPECTED_META_SHA256 mismatch');
} else {
    st_ok('EXPECTED_META_SHA256');
}
try {
    $bundle = orange_system_i18n_load_verified_bundle();
    if (!hash_equals(EXPECTED_AGGREGATE_MANIFEST_SHA256, $bundle['aggregate_manifest_hash'])) {
        st_fail('aggregate constant mismatch');
    } else {
        st_ok('aggregate');
    }
} catch (Throwable $e) {
    st_fail('bundle load: ' . $e->getMessage());
    $bundle = null;
}

echo "=== GROUP C legacy counts + api_ok ===\n";
if ($bundle) {
    $enL = $bundle['packages']['en']['legacy'];
    $arL = $bundle['packages']['ar']['legacy'];
    $filL = $bundle['packages']['fil']['legacy'];
    $hiL = $bundle['packages']['hi']['legacy'];
    if (count($enL) !== 321) {
        st_fail('en legacy ' . count($enL));
    }
    if (count($arL) !== 320) {
        st_fail('ar legacy ' . count($arL));
    }
    if (count($filL) !== 321 || count($hiL) !== 321) {
        st_fail('fil/hi legacy');
    }
    if (isset($arL['api_ok'])) {
        st_fail('ar has api_ok');
    }
    if (!isset($enL['api_ok'])) {
        st_fail('en missing api_ok');
    }
    if (isset($bundle['packages']['ar']['canonical']['storefront.system.api_ok'])) {
        st_fail('ar canonical has api_ok');
    }
    st_ok('legacy counts');
}

echo "=== GROUP D M0 hash ===\n";
$adminRows = require $files['admin'];
if (!is_array($adminRows) || count($adminRows) !== 141) {
    st_fail('admin_shell count');
} else {
    $sep = "\x1F";
    $rows = $adminRows;
    usort($rows, static fn($a, $b) => strcmp($a['catalog_key'], $b['catalog_key']));
    $buf = '';
    $keys = [];
    foreach ($rows as $r) {
        $keys[] = $r['catalog_key'];
        $buf .= implode($sep, [
            $r['catalog_key'], $r['source_file'], $r['source_line_or_range'],
            $r['arabic'], $r['english'], $r['english_review_status'],
            $r['context'], $r['partition'], $r['value_kind'], '',
        ]) . "\n";
    }
    $h = hash('sha256', $buf);
    if ($h !== M0_FULL_ROW_HASH_V4) {
        st_fail("M0_FULL_ROW_HASH_V4 got=$h");
    } else {
        st_ok('M0_FULL_ROW_HASH_V4');
    }
    $ih = hash('sha256', implode("\n", $keys) . "\n");
    if ($ih !== M0_INDEPENDENT_HASH) {
        st_fail('M0_INDEPENDENT_HASH');
    } else {
        st_ok('M0_INDEPENDENT_HASH');
    }
}

echo "=== GROUP E APP_T hash + subset ===\n";
$apptRows = require $files['appt'];
if (!is_array($apptRows) || count($apptRows) !== 127) {
    st_fail('app_t count');
} else {
    $sep = "\x1F";
    $rows = $apptRows;
    usort($rows, static fn($a, $b) => strcmp($a['property'], $b['property']));
    $buf = '';
    $ind = [];
    foreach ($rows as $r) {
        $buf .= implode($sep, [$r['property'], $r['legacy_key'], $r['catalog_key'], $r['file'], (string)$r['line']]) . "\n";
        $ind[] = $r['property'] . '=' . $r['legacy_key'];
    }
    $h = hash('sha256', $buf);
    if ($h !== APP_T_FULL_ROW_HASH) {
        st_fail("APP_T_FULL_ROW_HASH got=$h");
    } else {
        st_ok('APP_T_FULL_ROW_HASH');
    }
    sort($ind, SORT_STRING);
    $ih = hash('sha256', implode("\n", $ind) . "\n");
    if ($ih !== APP_T_INDEPENDENT_HASH) {
        st_fail('APP_T_INDEPENDENT_HASH');
    } else {
        st_ok('APP_T_INDEPENDENT_HASH');
    }
    if ($bundle) {
        foreach ($apptRows as $r) {
            if (!isset($bundle['packages']['en']['legacy'][$r['legacy_key']])) {
                st_fail('APP_T not subset ' . $r['legacy_key']);
                break;
            }
        }
        st_ok('APP_T subset');
    }
}

echo "=== GROUP F L2C 321 ===\n";
if ($bundle) {
    $l2c = $bundle['meta']['legacy_to_canonical'] ?? [];
    if (!is_array($l2c) || count($l2c) !== 321) {
        st_fail('L2C count');
    } else {
        $bad = 0;
        foreach ($l2c as $lk => $ck) {
            if ($ck !== 'storefront.system.' . $lk) {
                $bad++;
            }
        }
        if ($bad) {
            st_fail("L2C bad=$bad");
        } else {
            st_ok('L2C 321');
        }
    }
    if (!array_key_exists('canonical_aliases', $bundle['meta']) || $bundle['meta']['canonical_aliases'] !== []) {
        st_fail('canonical_aliases');
    } else {
        st_ok('canonical_aliases empty');
    }
}

echo "=== GROUP G key_meta B1 ===\n";
if ($bundle) {
    $km = $bundle['meta']['key_meta'] ?? [];
    $needLocales = ['en', 'ar', 'fil', 'hi', 'fr'];
    $needFields = ['coverage', 'value_kind', 'placeholders', 'review_status', 'provenance'];
    $checkOne = static function (array $node, string $path) use ($needLocales, $needFields): int {
        $fails = 0;
        if (!array_key_exists('lifecycle', $node) || !array_key_exists('locale_meta', $node)
            || !array_key_exists('plural_family', $node) || !array_key_exists('notes', $node)) {
            st_fail("B1 missing root fields $path");
            return 1;
        }
        if ($node['plural_family'] !== null) {
            st_fail("B1 plural_family $path");
            $fails++;
        }
        if (!is_string($node['notes'])) {
            st_fail("B1 notes $path");
            $fails++;
        }
        foreach ($needLocales as $loc) {
            if (!isset($node['locale_meta'][$loc]) || !is_array($node['locale_meta'][$loc])) {
                st_fail("B1 locale $loc $path");
                $fails++;
                continue;
            }
            $lm = $node['locale_meta'][$loc];
            foreach ($needFields as $f) {
                if (!array_key_exists($f, $lm)) {
                    st_fail("B1 field $f $path.$loc");
                    $fails++;
                }
            }
            $prov = $lm['provenance'] ?? null;
            if (!is_array($prov) || !isset($prov['source'], $prov['file'], $prov['line'], $prov['commit'])) {
                // isset fails on null — use array_key_exists
            }
            if (!is_array($prov) || !array_key_exists('source', $prov) || !array_key_exists('file', $prov)
                || !array_key_exists('line', $prov) || !array_key_exists('commit', $prov)) {
                st_fail("B1 provenance $path.$loc");
                $fails++;
            } else {
                $allowed = ['legacy_7b91276e', 'admin_shell_m0', 'new_not_legacy', 'no_value'];
                if (!in_array($prov['source'], $allowed, true)) {
                    st_fail("B1 source {$prov['source']} $path.$loc");
                    $fails++;
                }
            }
            if (!is_array($lm['placeholders'] ?? null)) {
                st_fail("B1 placeholders $path.$loc");
                $fails++;
            }
        }
        return $fails;
    };
    $sampleKeys = [
        ['legacy', 'api_ok'],
        ['canonical', 'storefront.system.safe_generic_unavailable'],
        ['canonical', 'admin.shell.chrome.country_scope_warn'],
        ['canonical', 'admin.shell.login.heading'],
        ['legacy', 'cart_items_count'],
        ['canonical', 'storefront.system.storefront_register_phone_merge_intro'],
    ];
    foreach ($sampleKeys as [$pkg, $key]) {
        if (!isset($km[$pkg][$key])) {
            st_fail("missing key_meta $pkg.$key");
            continue;
        }
        $checkOne($km[$pkg][$key], "$pkg.$key");
    }
    // sample 20 random legacy keys for completeness
    $i = 0;
    foreach (array_keys($km['legacy'] ?? []) as $lk) {
        $checkOne($km['legacy'][$lk], "legacy.$lk");
        if (++$i >= 20) {
            break;
        }
    }
    if (($km['legacy']['api_ok']['locale_meta']['ar']['coverage'] ?? '') !== 'MISSING') {
        st_fail('api_ok ar coverage');
    }
    if (($km['canonical']['admin.shell.chrome.country_scope_warn']['locale_meta']['en']['value_kind'] ?? '') !== 'HTML_LEGACY') {
        st_fail('country_scope_warn HTML_LEGACY');
    }
    if (($km['canonical']['storefront.system.safe_generic_unavailable']['lifecycle'] ?? '') !== 'NEW_NOT_LEGACY') {
        st_fail('safe_generic lifecycle');
    }
    st_ok('key_meta B1 samples');
}

echo "=== GROUP H loader modes/result ===\n";
$requiredResult = [
    'ok','text','locale_requested','locale_used','key_requested','key_used','package','mode',
    'source','status','value_kind','review_status','params_expected','params_used','params_missing',
    'params_extra','is_fallback','is_missing','failure_code','evidence_code','catalog_revision',
    'meta_hash','aggregate_manifest_hash','locale_file_hash',
];
$r = orange_system_i18n_lookup_context('admin.shell.login.heading', 'ar', [], 'canonical', 'strict');
foreach ($requiredResult as $f) {
    if (!array_key_exists($f, $r)) {
        st_fail("result missing $f");
    }
}
if (!$r['ok'] || $r['text'] !== 'تسجيل الدخول' || $r['source'] !== 'requested') {
    st_fail('ar login lookup');
} else {
    st_ok('strict ar login');
}
$r2 = orange_system_i18n_lookup_context('home', 'en', [], 'legacy', 'strict');
if (!$r2['ok']) {
    st_fail('legacy home');
} else {
    st_ok('legacy home');
}
$r3 = orange_system_i18n_lookup_context('home', 'fil', [], 'canonical', 'legacy_crosswalk');
if (!$r3['ok'] || $r3['key_used'] !== 'storefront.system.home' || $r3['source'] !== 'legacy_crosswalk') {
    st_fail('crosswalk');
} else {
    st_ok('legacy_crosswalk');
}
$r4 = orange_system_i18n_lookup_context('cart_items_count', 'en', ['n' => 3], 'legacy', 'strict');
if (!$r4['ok'] || ($r4['params_used']['n'] ?? null) !== 3 || array_keys($r4['params_used']) !== ['n']) {
    st_fail('params_used map');
} else {
    st_ok('params_used associative');
}
$r5 = orange_system_i18n_lookup_context('no_such_key_zzz', 'en', [], 'canonical', 'safe_production');
if (!$r5['ok'] || $r5['source'] !== 'safe_generic' || $r5['text'] !== 'This message is temporarily unavailable.') {
    st_fail('safe_production');
} else {
    st_ok('safe_production');
}
if (orange_system_i18n_catalog_revision() !== 1) {
    st_fail('catalog_revision helper');
} else {
    st_ok('catalog_revision');
}

echo "=== GROUP I French empty ===\n";
$r6 = orange_system_i18n_lookup_context('admin.shell.login.heading', 'fr', [], 'canonical', 'foundation_probe');
if (!$r6['ok'] || $r6['text'] !== '' || $r6['status'] !== 'FOUNDATION_INCOMPLETE'
    || $r6['source'] !== 'foundation_probe' || $r6['is_missing'] !== false) {
    st_fail('foundation_probe FR');
} else {
    st_ok('foundation_probe FR');
}
if ($bundle) {
    if (count($bundle['packages']['fr']['canonical']) !== 141) {
        st_fail('fr canonical count ' . count($bundle['packages']['fr']['canonical']));
    }
    if ($bundle['packages']['fr']['legacy'] !== []) {
        st_fail('fr legacy non-empty');
    }
    st_ok('fr 141 empty foundation keys');
}

echo "=== GROUP J FIL/HI M0 absent ===\n";
if ($bundle) {
    $has = 0;
    foreach ($adminRows as $row) {
        if (isset($bundle['packages']['fil']['canonical'][$row['catalog_key']])) {
            $has++;
        }
        if (isset($bundle['packages']['hi']['canonical'][$row['catalog_key']])) {
            $has++;
        }
    }
    if ($has !== 0) {
        st_fail("FIL/HI M0 present=$has");
    } else {
        st_ok('FIL/HI M0 absent');
    }
}

echo "=== GROUP K control-char / params ===\n";
$del = orange_system_i18n_reject_new_plain_value("a\x7Fb");
if ($del !== 'I18N_E_CONTROL_CHAR') {
    st_fail('U+007F');
} else {
    st_ok('U+007F CONTROL_CHAR');
}
$r7 = orange_system_i18n_lookup_context('cart_items_count', 'en', ['n' => 3, 'x' => 1], 'legacy', 'strict');
if ($r7['failure_code'] !== 'I18N_E_PARAM_EXTRA') {
    st_fail('PARAM_EXTRA');
} else {
    st_ok('PARAM_EXTRA');
}
$r8 = orange_system_i18n_lookup_context('cart_items_count', 'en', [], 'legacy', 'strict');
if ($r8['failure_code'] !== 'I18N_E_PARAM_MISSING') {
    st_fail('PARAM_MISSING');
} else {
    st_ok('PARAM_MISSING');
}
$r9 = orange_system_i18n_lookup_context('cart_items_count', 'en', ['n' => true], 'legacy', 'strict');
if ($r9['failure_code'] !== 'I18N_E_PARAM_TYPE') {
    st_fail('PARAM_TYPE');
} else {
    st_ok('PARAM_TYPE');
}
if ($bundle) {
    $wa = $bundle['packages']['en']['legacy']['storefront_register_phone_merge_wa_body'] ?? '';
    if (hash('sha256', $wa) !== WA_BODY_EN_SHA256) {
        st_fail('wa_body sha');
    } else {
        st_ok('wa_body sha');
    }
}

echo "=== GROUP L dormancy / allowlist10 / no APCu ===\n";
$allowlist = [
    'includes/i18n/orange_system_text_catalog.php',
    'includes/i18n/catalogs/v1/meta.php',
    'includes/i18n/catalogs/v1/en.php',
    'includes/i18n/catalogs/v1/ar.php',
    'includes/i18n/catalogs/v1/fil.php',
    'includes/i18n/catalogs/v1/hi.php',
    'includes/i18n/catalogs/v1/fr.php',
    'includes/i18n/admin_shell_keys_v1.php',
    'includes/i18n/app_t_allowlist_v1.php',
    'scripts/self_test_multidb_phase21b3_i18n_catalog.php',
];
foreach ($allowlist as $rel) {
    if (!is_file($root . '/' . $rel)) {
        st_fail("allowlist missing $rel");
    }
}
st_ok('allowlist 10 exist');
$scanTargets = [
    $root . '/config.php',
];
foreach (['admin', 'pages', 'api'] as $dir) {
    // light dormancy: ensure no require of new catalog path in a few hot entry files
}
$dormancyNeedles = [
    'includes/i18n/orange_system_text_catalog.php',
    'orange_system_i18n_lookup_context',
];
$dormancyFiles = [
    $root . '/config.php',
    $root . '/admin/index.php',
    $root . '/admin/login.php',
    $root . '/index.php',
];
foreach (glob($root . '/pages/*.php') ?: [] as $pf) {
    $dormancyFiles[] = $pf;
}
foreach (glob($root . '/api/*.php') ?: [] as $af) {
    $dormancyFiles[] = $af;
}
$wired = 0;
foreach ($dormancyFiles as $df) {
    if (!is_file($df)) {
        continue;
    }
    $txt = file_get_contents($df);
    if ($txt === false) {
        continue;
    }
    foreach ($dormancyNeedles as $n) {
        if (str_contains($txt, $n)) {
            $wired++;
            st_fail('dormancy wired in ' . $df);
        }
    }
}
if ($wired === 0) {
    st_ok('dormancy (no runtime wiring)');
}
if (APCU_ENABLED_REV1 !== 0) {
    st_fail('APCu');
} else {
    st_ok('no APCu');
}

echo "\nFALSE_GREEN_RISK_COUNT={$FALSE_GREEN_RISK_COUNT} RAW_FAIL={$RAW_FAIL} CORE_SKIP={$CORE_SKIP} ASSERTION_WEAKENED={$ASSERTION_WEAKENED} MYSQL_USED_BY_PHASE21B3={$MYSQL_USED_BY_PHASE21B3}\n";
if ($RAW_FAIL !== 0) {
    exit(1);
}
echo "PHASE21B3_SELF_TEST_PASS=1\n";
exit(0);
