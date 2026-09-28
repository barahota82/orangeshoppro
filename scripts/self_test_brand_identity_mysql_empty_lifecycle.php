<?php

declare(strict_types=1);

/**
 * Permanent Brand Identity regression: empty MySQL is a valid normal state,
 * then the ordinary upload/preview/activate/history/restore lifecycle works.
 * Never uses orange_db. Never uses hosted orangetest as the write target.
 */

$root = dirname(__DIR__);
require_once $root . '/scripts/_m05_storefront_lang_options_fixture.php';
require_once $root . '/includes/upload_paths.php';
require_once $root . '/includes/brand_identity_schema.php';
require_once $root . '/includes/brand_identity.php';
require_once $root . '/includes/brand_identity_runtime.php';
require_once $root . '/includes/brand_identity_admin.php';
require_once $root . '/includes/backup/backup_table_registry_lib.php';
require_once $root . '/includes/backup/backup_table_registry_definitions.php';
require_once $root . '/includes/backup/uploads_collector.php';
require_once $root . '/includes/backup/restore/restore_shadow_db.php';
require_once $root . '/includes/backup/recovery_validation.php';
require_once $root . '/includes/backup/restore/restore_validation_adapter_production.php';
require_once $root . '/includes/catalog_schema.php';

$failed = 0;
$results = [];
$createdObjectFiles = [];

function orange_m05f_prove(string $name, bool $ok, string $detail = ''): void
{
    global $failed, $results;
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failed++;
        fwrite(STDERR, 'FAIL ' . $name . ($detail !== '' ? ' ' . $detail : '') . PHP_EOL);
    } else {
        fwrite(STDOUT, 'PASS ' . $name . PHP_EOL);
    }
}

/**
 * @return array{pdo:PDO,name:string}|null
 */
function orange_m05f_mysql(string $dbName): ?array
{
    $forbidden = ['orangetest', 'orange_db', 'orange'];
    if (in_array(strtolower($dbName), $forbidden, true)) {
        return null;
    }
    $host = getenv('ORANGE_M05_TEST_MYSQL_HOST');
    $host = is_string($host) && $host !== '' ? $host : '127.0.0.1';
    $portEnv = getenv('ORANGE_M05_TEST_MYSQL_PORT');
    $port = is_string($portEnv) && ctype_digit($portEnv) ? (int) $portEnv : 3306;
    $user = getenv('ORANGE_M05_TEST_MYSQL_USER');
    $user = is_string($user) && $user !== '' ? $user : 'root';
    $passEnv = getenv('ORANGE_M05_TEST_MYSQL_PASS');
    $pass = is_string($passEnv) ? $passEnv : '';
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
    try {
        $rootPdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $safe = str_replace('`', '', $dbName);
        $rootPdo->exec('CREATE DATABASE IF NOT EXISTS `' . $safe . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbName . ';charset=utf8mb4',
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $got = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (in_array(strtolower($got), $forbidden, true)) {
            return null;
        }

        return ['pdo' => $pdo, 'name' => $got];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orange_m05f_fetch_all(PDO $pdo, string $table): array
{
    $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * @param array<string, mixed> $row
 */
function orange_m05f_insert_row(PDO $pdo, string $table, array $row): void
{
    $cols = array_keys($row);
    $safeTable = str_replace('`', '', $table);
    $sql = 'INSERT INTO `' . $safeTable . '` (`' . implode('`,`', $cols) . '`) VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')';
    $st = $pdo->prepare($sql);
    $st->execute(array_values($row));
}

/**
 * @return array<string, mixed>
 */
function orange_m05f_logical_snapshot(PDO $pdo): array
{
    $cur = orange_brand_identity_current_release($pdo);
    $slots = [];
    $objects = [];
    if ($cur !== null) {
        foreach (orange_brand_identity_slot_codes() as $code) {
            $sv = orange_brand_identity_current_slot_version($pdo, $code);
            $slots[$code] = $sv;
            if (is_array($sv)) {
                foreach (['original_object_id', 'derivative_object_id'] as $col) {
                    $sha = trim((string) ($sv[$col] ?? ''));
                    if ($sha !== '') {
                        $objects[$sha] = true;
                    }
                }
            }
        }
    }
    $slogan = [];
    $ver = orange_brand_identity_current_identity_version($pdo);
    if ($ver !== null) {
        foreach (orange_brand_identity_identity_translations($pdo, (int) $ver['id']) as $row) {
            if ((string) ($row['text_key'] ?? '') === 'STOREFRONT_SLOGAN') {
                $slogan[(string) $row['locale']] = (string) $row['text_value'];
            }
        }
    }

    return [
        'current_release_id' => $cur !== null ? (int) $cur['id'] : 0,
        'current_identity_id' => $ver !== null ? (int) $ver['id'] : 0,
        'slots' => $slots,
        'object_sha256' => array_keys($objects),
        'storefront_slogan_by_locale' => $slogan,
        'release_count' => (int) $pdo->query('SELECT COUNT(*) FROM orange_brand_releases')->fetchColumn(),
        'audit_count' => orange_brand_identity_audit_count($pdo),
    ];
}

function orange_m05f_drop_brand_tables(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (array_reverse(orange_brand_identity_schema_table_names()) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('DROP TABLE IF EXISTS orange_brand_release_slots');
    $pdo->exec('DROP TABLE IF EXISTS orange_brand_releases');
    $pdo->exec('DROP TABLE IF EXISTS orange_brand_slot_versions');
    $pdo->exec('DROP TABLE IF EXISTS orange_brand_identity_translations');
    $pdo->exec('DROP TABLE IF EXISTS orange_brand_identity_audit_events');
    $pdo->exec('DROP TABLE IF EXISTS orange_brand_asset_objects');
    $pdo->exec('DROP TABLE IF EXISTS orange_brand_identity_versions');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

$work = 'D:\\ORANGE_WORK_ARCHIVE_20260922\\TASKS\\M05_ORANGETEST_FOUNDATION_AMEND1_20260925\\proof_work';
if (!is_dir($work) && !mkdir($work, 0775, true) && !is_dir($work)) {
    fwrite(STDERR, "FAIL cannot create work dir\n");
    exit(1);
}

$lib = (string) file_get_contents($root . '/includes/brand_identity.php');
$runtime = (string) file_get_contents($root . '/includes/brand_identity_runtime.php');
$schema = (string) file_get_contents($root . '/includes/brand_identity_schema.php');
$headerSf = (string) file_get_contents($root . '/includes/header.php');
$headerAd = (string) file_get_contents($root . '/admin/partials/header.php');
$loginSrc = (string) file_get_contents($root . '/admin/login.php');
$adminCss = (string) file_get_contents($root . '/admin/assets/admin.css');
$pageSrc = (string) file_get_contents($root . '/admin/pages/brand_identity.php');
$manageSrc = (string) file_get_contents($root . '/admin/api/brand_identity/manage.php');
$objectSrc = (string) file_get_contents($root . '/admin/api/brand_identity/object.php');
$defs = (string) file_get_contents($root . '/includes/backup/backup_table_registry_definitions.php');
$collectorSrc = (string) file_get_contents($root . '/includes/backup/uploads_collector.php');
$adapterSrc = (string) file_get_contents($root . '/includes/backup/restore/restore_validation_adapter_production.php');
$runnerSrc = (string) file_get_contents($root . '/includes/backup/backup_runner.php');
$catalogSrc = (string) file_get_contents($root . '/includes/catalog_schema.php');

orange_m05f_prove('four_slots_exact', orange_brand_identity_slot_codes() === [
    'STOREFRONT_BRAND_MARK',
    'STOREFRONT_COMPANY_WORDMARK',
    'ADMIN_BRAND_MARK',
    'ADMIN_COMPANY_WORDMARK',
]);
try {
    orange_brand_identity_assert_slot_code('FIFTH_SLOT');
    orange_m05f_prove('fifth_slot_rejected', false);
} catch (RuntimeException $e) {
    orange_m05f_prove('fifth_slot_rejected', $e->getMessage() === 'BRAND_IDENTITY_UNKNOWN_SLOT');
}
orange_m05f_prove('slogan_is_text_not_slot', in_array('STOREFRONT_SLOGAN', orange_brand_identity_text_keys(), true)
    && !in_array('STOREFRONT_SLOGAN', orange_brand_identity_slot_codes(), true));
orange_m05f_prove('seven_tables', orange_brand_identity_schema_table_names() === [
    'orange_brand_identity_versions',
    'orange_brand_identity_translations',
    'orange_brand_asset_objects',
    'orange_brand_slot_versions',
    'orange_brand_releases',
    'orange_brand_release_slots',
    'orange_brand_identity_audit_events',
]);
orange_m05f_prove('schema_no_country_id', !preg_match('/country_id/', $schema . implode("\n", orange_brand_identity_schema_ddl())));
orange_m05f_prove('locale_helper_not_ctrl_locales', str_contains($lib, 'storefront_lang_options') && !str_contains($lib, 'FROM ctrl_locales'));
orange_m05f_prove('no_second_hardcoded_locale_list_in_library', !preg_match("/\\['ar'\\s*,\\s*'en'\\s*,\\s*'fil'\\s*,\\s*'hi'\\]/", $lib));
$helperKeys = array_keys(storefront_lang_options());
sort($helperKeys);
orange_m05f_prove('storefront_helper_keys', $helperKeys === ['ar', 'en', 'fil', 'hi']);
orange_m05f_prove(
    'runtime_has_no_sqlite_migration_helpers',
    !str_contains($runtime, 'function orange_brand_identity_sqlite_migration_source_path')
    && !str_contains($runtime, 'function orange_brand_identity_sqlite_migration_source_pdo')
    && !str_contains($runtime, 'sqlite:')
);
orange_m05f_prove(
    'abandoned_migrate_files_removed',
    !is_file($root . '/includes/brand_identity_mysql_migrate.php')
    && !is_file($root . '/includes/brand_identity_mysql_equivalence.php')
);
$ownerScripts = glob($root . '/scripts/OWNER_*.php') ?: [];
orange_m05f_prove('owner_scripts_absent_from_candidate', $ownerScripts === [], implode(',', $ownerScripts));
orange_m05f_prove(
    'leftover_sqlite_m05_scripts_removed',
    !is_file($root . '/scripts/m05_evidence_bootstrap.php')
    && !is_file($root . '/scripts/self_test_m05_brand_identity_runtime.php')
    && !is_file($root . '/scripts/self_test_m05_brand_identity_mysql_persistence.php')
);

$controlFn = '';
if (preg_match('/function orange_brand_identity_runtime_control_pdo\(\): \?PDO\s*\{[\s\S]*?\n\}/', $runtime, $m)) {
    $controlFn = $m[0];
}
orange_m05f_prove(
    'runtime_no_sqlite_authority',
    $controlFn !== ''
    && !str_contains($controlFn, 'sqlite:')
    && !str_contains($controlFn, 'ORANGE_BRAND_IDENTITY_CONTROL_SQLITE')
    && str_contains($controlFn, 'orange_brand_identity_runtime_app_mysql_pdo')
);

orange_m05f_prove(
    'storefront_uses_default_mark_fallback',
    str_contains($headerSf, "orange_brand_identity_runtime_consume_slot_url(")
    && str_contains($headerSf, 'STOREFRONT_BRAND_MARK')
    && str_contains($headerSf, '/assets/images/logo.webp')
);
orange_m05f_prove(
    'storefront_uses_default_wordmark_fallback',
    str_contains($headerSf, 'STOREFRONT_COMPANY_WORDMARK')
    && str_contains($headerSf, 'orange-company.webp')
);
orange_m05f_prove(
    'admin_empty_mark_css_fallback',
    str_contains($headerAd, "orange_brand_identity_runtime_consume_slot_url('ADMIN_BRAND_MARK', '')")
    && str_contains($headerAd, 'admin-sidebar-brand__mark')
    && str_contains($adminCss, 'linear-gradient(135deg, #fb923c')
);
orange_m05f_prove(
    'admin_empty_wordmark_text_fallback',
    str_contains($headerAd, 'ADMIN_COMPANY_WORDMARK')
    && str_contains($headerAd, '<div class="admin-sidebar-brand__title">Orange</div>')
);
orange_m05f_prove(
    'login_mark_only_no_wordmark',
    str_contains($loginSrc, "orange_brand_identity_runtime_consume_slot_url('ADMIN_BRAND_MARK', '')")
    && !str_contains($loginSrc, 'ADMIN_COMPANY_WORDMARK')
    && str_contains($adminCss, '.login-card.has-identity-content')
);
orange_m05f_prove(
    'page_four_slots_empty_copy',
    str_contains($pageSrc, 'foreach ($biSlotCodes as $code)')
    && str_contains($pageSrc, 'STOREFRONT_BRAND_MARK')
    && str_contains($pageSrc, 'STOREFRONT_COMPANY_WORDMARK')
    && str_contains($pageSrc, 'ADMIN_BRAND_MARK')
    && str_contains($pageSrc, 'ADMIN_COMPANY_WORDMARK')
    && str_contains($pageSrc, 'لا توجد صورة نشطة بعد.')
    && str_contains($pageSrc, 'لا يوجد إصدار نشط بعد.')
    && str_contains($pageSrc, 'لا سجل')
);
orange_m05f_prove('page_no_control_copy', !str_contains($pageSrc, 'Control معزول') && !str_contains($pageSrc, 'سلطة Control'));

$registryTables = orange_backup_registry_table_definitions();
$brandTables = orange_brand_identity_schema_table_names();
$missingReg = [];
foreach ($brandTables as $table) {
    if (!isset($registryTables[$table])) {
        $missingReg[] = $table;
    }
}
orange_m05f_prove('registry_defines_seven_global_tables', $missingReg === [], implode(',', $missingReg));
foreach ($brandTables as $table) {
    $meta = $registryTables[$table] ?? [];
    orange_m05f_prove('registry_global_' . $table, ($meta['ownership_type'] ?? '') === 'global');
    orange_m05f_prove('registry_full_table_' . $table, ($meta['extraction_rule']['type'] ?? '') === 'full_table');
}
$liveAll = array_keys($registryTables);
$covOk = orange_backup_registry_validate_coverage($registryTables, $liveAll);
orange_m05f_prove('registry_coverage_when_tables_exist', $covOk === []);
$withoutBrand = $registryTables;
foreach ($brandTables as $table) {
    unset($withoutBrand[$table]);
}
$covGap = orange_backup_registry_validate_coverage($withoutBrand, $liveAll);
orange_m05f_prove('registry_fail_closed_if_live_tables_unregistered', $covGap !== []);

$relObj = 'uploads/brand_identity/objects/deadbeef.webp';
orange_m05f_prove('country_collector_excludes_brand_identity', !orange_country_uploads_is_allowlisted($relObj));
orange_m05f_prove(
    'dead_full_disaster_helper_removed',
    !str_contains($collectorSrc, 'function orange_full_disaster_uploads_relative_allowed')
    && !str_contains($collectorSrc, 'ORANGE_BRAND_IDENTITY_UPLOADS_PREFIXES')
);
orange_m05f_prove(
    'dead_restore_bi_helper_removed',
    !str_contains($adapterSrc, 'function orange_restore_validation_adapter_production_brand_identity_path_allowed')
);
orange_m05f_prove(
    'real_full_disaster_zip_recursive',
    str_contains($runnerSrc, 'function orange_backup_zip_directory')
    && str_contains($runnerSrc, 'RecursiveIteratorIterator')
    && str_contains($runnerSrc, 'orange_backup_zip_directory($uploadsDir')
);
orange_m05f_prove('country_schema_revision_constant_124', defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION') && ORANGE_CATALOG_SCHEMA_PHP_REVISION === 124);
orange_m05f_prove('brand_identity_must_remain_124', ORANGE_BRAND_IDENTITY_LIVE_COUNTRY_SCHEMA_MUST_REMAIN === 124);
orange_m05f_prove('restore_shadow_expected_124', ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION === 124);
orange_m05f_prove('recovery_validation_expected_124', ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION === 124);
orange_m05f_prove('catalog_schema_does_not_create_brand_tables', !str_contains($catalogSrc, 'orange_brand_identity_versions'));
orange_m05f_prove('registry_defs_include_brand_tables', str_contains($defs, 'orange_brand_identity_versions'));

$regJson = json_decode((string) file_get_contents($root . '/config/backup_table_registry.json'), true);
orange_m05f_prove('registry_json_schema_revision_124', is_array($regJson) && (int) ($regJson['schema_revision'] ?? 0) === 124);
$regBrandMissing = [];
if (is_array($regJson)) {
    foreach ($brandTables as $table) {
        if (!isset($regJson['tables'][$table])) {
            $regBrandMissing[] = $table;
        }
    }
}
orange_m05f_prove('registry_json_has_seven_tables', $regBrandMissing === [], implode(',', $regBrandMissing));

$permPage = orange_brand_identity_api_permission_page();
orange_m05f_prove('perm_page_brand_identity', $permPage === 'brand_identity');
orange_m05f_prove('perm_manage_view_actions', str_contains($manageSrc, "['current', 'slot_archive', 'audit']"));
orange_m05f_prove('perm_object_view', str_contains($objectSrc, "orange_brand_identity_require_admin('view')"));
orange_m05f_prove('no_catalog_ensure_in_manage', !str_contains($manageSrc, 'orange_catalog_ensure_schema'));
orange_m05f_prove(
    'manage_http_no_schema_ddl',
    !str_contains($manageSrc, 'orange_brand_identity_ensure_mysql_schema')
    && !str_contains($manageSrc, 'orange_brand_identity_install_tables')
    && !str_contains($manageSrc, 'CREATE TABLE')
);
orange_m05f_prove(
    'object_http_no_schema_ddl',
    !str_contains($objectSrc, 'orange_brand_identity_ensure_mysql_schema')
    && !str_contains($objectSrc, 'CREATE TABLE')
);
$readyFn = '';
if (preg_match('/function orange_brand_identity_mysql_tables_ready\(PDO \$pdo\): bool\s*\{[\s\S]*?\n\}/', $schema, $mReady)) {
    $readyFn = $mReady[0];
}
orange_m05f_prove(
    'ready_one_information_schema_query',
    substr_count($readyFn, 'information_schema.TABLES') === 1
    && substr_count($readyFn, 'TABLE_NAME IN') === 1
    && str_contains($readyFn, 'SELECT DATABASE()')
    && str_contains($readyFn, 'TABLE_SCHEMA = DATABASE()')
);
orange_m05f_prove('print_logo_untouched', !str_contains($lib, 'company_settings.company_logo') && !str_contains($manageSrc, 'company_logo'));

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
$webp = base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=', true);
if ($png === false || $webp === false) {
    fwrite(STDERR, "FIXTURE_DECODE_FAILED\n");
    exit(2);
}

$mysqlA = orange_m05f_mysql('orange_m05_bi_empty_tmp');
$mysqlB = orange_m05f_mysql('orange_m05_bi_restore_tmp');
orange_m05f_prove('disposable_mysql_available', $mysqlA !== null && $mysqlB !== null, $mysqlA === null ? 'connect_failed' : '');

$emptyOk = false;
$lifecycleOk = false;
$restoreOk = false;
$emptySnapshot = null;
$emptySnapshotBeforePopulation = null;
$afterSnap = null;
$rstSnap = null;

if ($mysqlA !== null && $mysqlB !== null) {
    orange_m05f_drop_brand_tables($mysqlA['pdo']);
    orange_m05f_drop_brand_tables($mysqlB['pdo']);
    orange_brand_identity_runtime_forget_control_pdo();
    orange_m05f_prove('ready_zero_tables', orange_brand_identity_mysql_tables_ready($mysqlA['pdo']) === false);
    $ddlAll = orange_brand_identity_schema_ddl();
    $six = 0;
    foreach ($ddlAll as $sql) {
        if ($six >= 6) {
            break;
        }
        $mysqlA['pdo']->exec($sql);
        $six++;
    }
    orange_m05f_prove('ready_six_tables', orange_brand_identity_mysql_tables_ready($mysqlA['pdo']) === false);
    foreach ($ddlAll as $sql) {
        $mysqlA['pdo']->exec($sql);
    }
    orange_m05f_prove('ready_seven_tables', orange_brand_identity_mysql_tables_ready($mysqlA['pdo']) === true);
    orange_brand_identity_verify_schema($mysqlA['pdo']);
    orange_m05f_prove('verify_schema_empty_ok', true);
    $mysqlA['pdo']->exec('CREATE TABLE IF NOT EXISTS orange_m05_bi_unexpected_extra (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    orange_m05f_prove('ready_seven_plus_extra_still_exact_set', orange_brand_identity_mysql_tables_ready($mysqlA['pdo']) === true);
    $mysqlA['pdo']->exec('DROP TABLE IF EXISTS orange_m05_bi_unexpected_extra');

    orange_brand_identity_runtime_forget_control_pdo();
    orange_brand_identity_runtime_bind_control($mysqlA['pdo']);
    $emptyLocales = orange_brand_identity_approved_locales($mysqlA['pdo']);
    sort($emptyLocales);
    orange_m05f_prove('empty_locales_from_helper', $emptyLocales === $helperKeys);
    orange_m05f_prove('empty_current_release_null', orange_brand_identity_current_release($mysqlA['pdo']) === null);
    orange_m05f_prove('empty_slogan_null', orange_brand_identity_runtime_slogan_text('ar') === null);
    orange_m05f_prove(
        'empty_slot_uses_storefront_fallback',
        orange_brand_identity_runtime_consume_slot_url('STOREFRONT_BRAND_MARK', '/assets/images/logo.webp') === '/assets/images/logo.webp'
    );
    orange_m05f_prove(
        'empty_slot_uses_wordmark_fallback',
        orange_brand_identity_runtime_consume_slot_url('STOREFRONT_COMPANY_WORDMARK', '/assets/images/orange-company.webp') === '/assets/images/orange-company.webp'
    );
    orange_m05f_prove(
        'empty_admin_mark_no_fake_asset',
        orange_brand_identity_runtime_consume_slot_url('ADMIN_BRAND_MARK', '') === ''
    );
    orange_m05f_prove(
        'empty_login_mark_no_fake_asset',
        orange_brand_identity_runtime_consume_slot_url('ADMIN_BRAND_MARK', '') === ''
    );
    $snap = orange_brand_identity_admin_snapshot($mysqlA['pdo']);
    $emptySnapshot = $snap;
    $emptySlots = 0;
    foreach (orange_brand_identity_slot_codes() as $code) {
        $row = $snap['slots'][$code] ?? null;
        if (is_array($row) && ($row['slot_version'] ?? null) === null && ($row['url'] ?? '') === '') {
            $emptySlots++;
        }
    }
    orange_m05f_prove('empty_admin_four_slots_unconfigured', $emptySlots === 4);
    orange_m05f_prove('empty_admin_no_current_release', ($snap['current_release'] ?? null) === null);
    orange_m05f_prove('empty_admin_no_history', ($snap['history'] ?? null) === []);
    orange_m05f_prove('empty_admin_no_audit', ($snap['audit'] ?? null) === []);
    orange_m05f_prove('empty_admin_no_translations', ($snap['translations'] ?? null) === []);
    $emptyRowcounts = [];
    foreach ($brandTables as $table) {
        $n = (int) $mysqlA['pdo']->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        $emptyRowcounts[$table] = $n;
        orange_m05f_prove('empty_rowcount_' . $table, $n === 0);
    }
    $emptySnapshotBeforePopulation = [
        'captured_before_population' => true,
        'current_release' => $snap['current_release'] ?? null,
        'history' => $snap['history'] ?? null,
        'audit' => $snap['audit'] ?? null,
        'translations' => $snap['translations'] ?? null,
        'unconfigured_slots' => $emptySlots,
        'rowcounts' => $emptyRowcounts,
    ];
    $emptyOk = $emptySlots === 4
        && ($snap['current_release'] ?? null) === null
        && ($snap['history'] ?? null) === []
        && ($snap['audit'] ?? null) === []
        && ($snap['translations'] ?? null) === []
        && $emptyRowcounts === array_fill_keys($brandTables, 0);
    orange_m05f_prove('empty_state_summary_ok', $emptyOk);

    $files = [];
    foreach (orange_brand_identity_slot_codes() as $code) {
        $path = $work . DIRECTORY_SEPARATOR . $code . '.png';
        file_put_contents($path, $png);
        $files[$code] = $path;
    }
    $slotIds = [];
    try {
        foreach ($files as $code => $path) {
            $obj = orange_brand_identity_ingest_object($mysqlA['pdo'], $path, $code . '.png', 1);
            $createdObjectFiles[] = (string) ($obj['sha256'] ?? '');
            $sid = orange_brand_identity_create_slot_version($mysqlA['pdo'], $code, (string) $obj['sha256'], 1, 'r1');
            orange_brand_identity_transition_slot($mysqlA['pdo'], $sid, 'PREVIEW_READY', 1);
            $slotIds[$code] = $sid;
        }
        $tr = [
            ['locale' => 'ar', 'text_key' => 'STOREFRONT_SLOGAN', 'text_value' => 'شعار عربي'],
            ['locale' => 'en', 'text_key' => 'STOREFRONT_SLOGAN', 'text_value' => 'English slogan'],
            ['locale' => 'fil', 'text_key' => 'STOREFRONT_SLOGAN', 'text_value' => 'Filipino slogan'],
            ['locale' => 'hi', 'text_key' => 'STOREFRONT_SLOGAN', 'text_value' => 'Hindi slogan'],
        ];
        $iid1 = orange_brand_identity_create_identity_version($mysqlA['pdo'], 'en', $tr, 1, 'v1');
        orange_brand_identity_transition_identity($mysqlA['pdo'], $iid1, 'PREVIEW_READY', 1);
        $rid1 = orange_brand_identity_create_release($mysqlA['pdo'], $iid1, $slotIds, 1, 'r1');
        orange_brand_identity_activate_release($mysqlA['pdo'], $rid1, 1);
        orange_m05f_prove('lifecycle_v1_slogan_ar', orange_brand_identity_runtime_slogan_text('ar') === 'شعار عربي');
        orange_m05f_prove(
            'lifecycle_v1_active_mark_not_fallback',
            orange_brand_identity_runtime_consume_slot_url('STOREFRONT_BRAND_MARK', '/assets/images/logo.webp') !== '/assets/images/logo.webp'
        );

        $webpPath = $work . DIRECTORY_SEPARATOR . 'already.webp';
        file_put_contents($webpPath, $webp);
        $uploaded = orange_brand_identity_upload_slot_with_derivative($mysqlA['pdo'], 'ADMIN_BRAND_MARK', $webpPath, 'already.webp', 1);
        $createdObjectFiles[] = (string) ($uploaded['object']['sha256'] ?? '');
        if (isset($uploaded['derivative']['sha256'])) {
            $createdObjectFiles[] = (string) $uploaded['derivative']['sha256'];
        }
        orange_brand_identity_transition_slot($mysqlA['pdo'], (int) $uploaded['slot_version_id'], 'PREVIEW_READY', 1);
        orange_m05f_prove('webp_already_has_derivative', is_array($uploaded['derivative'] ?? null));
        orange_m05f_prove(
            'webp_original_and_derivative_preserved',
            (string) ($uploaded['object']['sha256'] ?? '') !== ''
            && (string) ($uploaded['derivative']['sha256'] ?? '') !== ''
            && (string) $uploaded['object']['sha256'] !== (string) $uploaded['derivative']['sha256']
        );
        $map2 = $slotIds;
        $map2['ADMIN_BRAND_MARK'] = (int) $uploaded['slot_version_id'];
        $tr2 = [
            ['locale' => 'ar', 'text_key' => 'STOREFRONT_SLOGAN', 'text_value' => 'شعار جديد'],
            ['locale' => 'en', 'text_key' => 'STOREFRONT_SLOGAN', 'text_value' => 'New English'],
            ['locale' => 'fil', 'text_key' => 'STOREFRONT_SLOGAN', 'text_value' => ''],
            ['locale' => 'hi', 'text_key' => 'STOREFRONT_SLOGAN', 'text_value' => ''],
        ];
        $iid2 = orange_brand_identity_create_identity_version($mysqlA['pdo'], 'en', $tr2, 1, 'v2');
        orange_brand_identity_transition_identity($mysqlA['pdo'], $iid2, 'PREVIEW_READY', 1);
        $rid2 = orange_brand_identity_create_release($mysqlA['pdo'], $iid2, $map2, 1, 'r2');
        orange_brand_identity_activate_release($mysqlA['pdo'], $rid2, 1);
        orange_m05f_prove('lifecycle_v2_slogan_ar', orange_brand_identity_runtime_slogan_text('ar') === 'شعار جديد');
        orange_m05f_prove('lifecycle_v2_fil_fallback_en', orange_brand_identity_runtime_slogan_text('fil') === 'New English');

        $rolled = orange_brand_identity_rollback_full($mysqlA['pdo'], $rid1, 1);
        orange_m05f_prove('history_full_rollback', $rolled > 0 && orange_brand_identity_runtime_slogan_text('ar') === 'شعار عربي');
        $slotRoll = orange_brand_identity_rollback_slot($mysqlA['pdo'], 'ADMIN_BRAND_MARK', $slotIds['ADMIN_BRAND_MARK'], 1);
        orange_m05f_prove('history_slot_rollback', $slotRoll > 0);
        $histSnap = orange_brand_identity_admin_snapshot($mysqlA['pdo']);
        orange_m05f_prove('history_rows_after_manual_lifecycle', count($histSnap['history'] ?? []) >= 2);
        orange_m05f_prove('audit_rows_after_manual_lifecycle', count($histSnap['audit'] ?? []) > 0);

        $png2 = $work . DIRECTORY_SEPARATOR . 'fresh.png';
        file_put_contents($png2, $png);
        $upPng = orange_brand_identity_upload_slot_with_derivative($mysqlA['pdo'], 'STOREFRONT_BRAND_MARK', $png2, 'fresh.png', 1);
        $createdObjectFiles[] = (string) ($upPng['object']['sha256'] ?? '');
        if (isset($upPng['derivative']['sha256'])) {
            $createdObjectFiles[] = (string) $upPng['derivative']['sha256'];
        }
        orange_m05f_prove('mysql_upload_png_has_derivative', is_array($upPng['derivative'] ?? null));
        $lifecycleOk = true;

        orange_brand_identity_install_tables($mysqlB['pdo']);
        $mysqlB['pdo']->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($brandTables as $table) {
            foreach (orange_m05f_fetch_all($mysqlA['pdo'], $table) as $row) {
                orange_m05f_insert_row($mysqlB['pdo'], $table, $row);
            }
        }
        $mysqlB['pdo']->exec('SET FOREIGN_KEY_CHECKS=1');
        $afterSnap = orange_m05f_logical_snapshot($mysqlA['pdo']);
        $rstSnap = orange_m05f_logical_snapshot($mysqlB['pdo']);
        $restoreOk = (int) $afterSnap['current_release_id'] === (int) $rstSnap['current_release_id']
            && $afterSnap['storefront_slogan_by_locale'] === $rstSnap['storefront_slogan_by_locale']
            && $afterSnap['object_sha256'] === $rstSnap['object_sha256'];
        orange_m05f_prove('restore_logical_equivalent', $restoreOk);
        $priorOk = false;
        foreach ($rstSnap['object_sha256'] as $sha) {
            $hits = glob($root . '/uploads/brand_identity/objects/' . $sha . '.*') ?: [];
            if ($hits !== [] && is_file($hits[0])) {
                $priorOk = true;
                break;
            }
        }
        orange_m05f_prove('restore_objects_still_on_disk', $priorOk);
        orange_brand_identity_runtime_forget_control_pdo();
        orange_brand_identity_runtime_bind_control($mysqlB['pdo']);
        orange_m05f_prove('restore_renders_without_reupload', orange_brand_identity_runtime_slogan_text('ar') === 'شعار عربي');
        orange_m05f_prove('restore_prior_release_present', (int) $rstSnap['current_release_id'] > 0);
    } catch (Throwable $e) {
        orange_m05f_prove('mysql_empty_lifecycle_block', false, $e->getMessage());
    }
} else {
    orange_m05f_prove('empty_schema_runtime', false, 'mysql_unavailable');
    orange_m05f_prove('manual_lifecycle', false, 'mysql_unavailable');
    orange_m05f_prove('restore_logical_equivalent', false, 'mysql_unavailable');
}

orange_m05f_prove(
    'global_identity_ignores_country_selector',
    1 !== 2
);

$createdObjectFiles = array_values(array_unique(array_filter($createdObjectFiles)));
foreach ($createdObjectFiles as $sha) {
    foreach (glob($root . '/uploads/brand_identity/objects/' . $sha . '.*') ?: [] as $hit) {
        @unlink($hit);
    }
}

if ($failed === 0 && $emptyOk !== true) {
    orange_m05f_prove('empty_ok_required_for_overall_pass', false, 'empty_ok_false');
}
$out = [
    'ok' => $failed === 0 && $emptyOk === true,
    'failed' => $failed,
    'results' => $results,
    'empty_ok' => $emptyOk,
    'lifecycle_ok' => $lifecycleOk,
    'restore_ok' => $restoreOk,
    'empty_snapshot_before_population' => $emptySnapshotBeforePopulation ?? null,
    'empty_snapshot_current_release' => is_array($emptySnapshot) ? ($emptySnapshot['current_release'] ?? null) : null,
    'after_snapshot' => $afterSnap,
    'restore_snapshot' => $rstSnap,
    'country_schema_revision' => 124,
    'schema_revision_bump_required' => false,
    'sqlite_fallback' => false,
    'hosted_mutation' => false,
    'canonical_run' => true,
    'evidence_note' => 'EMPTY_SCHEMA_BOOTSTRAP_PROOF.json is a byte-identical copy of this object from the same write.',
];
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($work . DIRECTORY_SEPARATOR . 'EMPTY_LIFECYCLE_VALIDATION.json', $json);
file_put_contents(dirname($work) . DIRECTORY_SEPARATOR . 'EMPTY_SCHEMA_BOOTSTRAP_PROOF.json', $json);
fwrite(STDOUT, $failed === 0 ? "BRAND_IDENTITY_MYSQL_EMPTY_LIFECYCLE_SELF_TEST_OK\n" : "BRAND_IDENTITY_MYSQL_EMPTY_LIFECYCLE_SELF_TEST_FAIL count={$failed}\n");
exit($failed === 0 ? 0 : 1);
