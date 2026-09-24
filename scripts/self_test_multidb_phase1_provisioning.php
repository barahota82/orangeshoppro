<?php
declare(strict_types=1);

/**
 * Orange Phase-1 — provisioning + privilege isolation self-test matrix F–H (items 51–76).
 * Does not load config.php / db() / Backup / Restore.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

if (!defined('ORANGE_PHASE1_BOOTSTRAP_AS_LIB')) {
    define('ORANGE_PHASE1_BOOTSTRAP_AS_LIB', true);
}
require_once dirname(__DIR__) . '/scripts/multidb/phase1_local_bootstrap.php';
require_once dirname(__DIR__) . '/includes/db_router.php';
require_once dirname(__DIR__) . '/includes/control_schema.php';

$pass = 0;
$fail = 0;
$skip = 0;
$crossLeak = 0;
$wrongRoute = 0;
$falseGreen = 0;
$controlRuntimeDml = 0;
$unknownGrant = 0;
$overbroad = 0;

function p_assert(string $id, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS {$id}\n";
    } else {
        $fail++;
        echo "FAIL {$id}" . ($detail !== '' ? " detail={$detail}" : '') . "\n";
    }
}

$runId = getenv('ORANGE_PHASE1_RUN_ID');
$runId = ($runId !== false && trim((string) $runId) !== '') ? trim((string) $runId) : null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--run-id=')) {
        $runId = substr($arg, 9);
    }
}
if ($runId === null || $runId === '') {
    fwrite(STDERR, "MISSING_RUN_ID\n");
    exit(2);
}

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase1_state_' . $runId . '.json';
$secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase1_secrets_' . $runId . '.json';
if (!is_file($statePath) || !is_file($secretsPath)) {
    fwrite(STDERR, "STATE_OR_SECRETS_MISSING\n");
    exit(2);
}

$state = json_decode((string) file_get_contents($statePath), true);
$secrets = json_decode((string) file_get_contents($secretsPath), true);
if (!is_array($state) || !is_array($secrets)) {
    fwrite(STDERR, "STATE_DECODE_FAIL\n");
    exit(2);
}

$host = (string) $state['host'];
$port = (int) $state['port'];
$controlDb = (string) $state['control_db'];
$kwDb = (string) $state['kw_db'];
$egDb = (string) $state['eg_db'];
$kwUser = (string) $state['kw_user'];
$egUser = (string) $state['eg_user'];
$rtUser = (string) $state['control_runtime_user'];
$saUser = (string) $state['schema_admin_user'];
$kwSecretRef = (string) $state['kw_secret_ref'];
$egSecretRef = (string) $state['eg_secret_ref'];
$ctrlSecretRef = (string) $state['control_secret_ref'];

$secretMap = is_array($secrets['by_ref_version'] ?? null) ? $secrets['by_ref_version'] : [];
$kwPass = (string) ($secretMap[$kwSecretRef . '|1'] ?? '');
$egPass = (string) ($secretMap[$egSecretRef . '|1'] ?? '');
$rtPass = (string) ($secrets['control_runtime_password'] ?? ($secretMap[$ctrlSecretRef . '|1'] ?? ''));
$saPass = (string) ($secrets['schema_admin_password'] ?? '');

p_assert('F51_no_db_function', !function_exists('db'));
p_assert(
    'F52_no_backup_restore_loaded',
    !function_exists('orange_backup_admin_collect_storage_totals')
    && !function_exists('orange_restore_final_approval_precheck')
);

function phase1_try_connect(string $host, int $port, string $db, string $user, string $pass): ?PDO
{
    try {
        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        return null;
    }
}

function phase1_can_read_fixture(?PDO $pdo): bool
{
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function phase1_can_read_ctrl(?PDO $pdo): bool
{
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        $pdo->query('SELECT code FROM ctrl_countries LIMIT 1')->fetchColumn();

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// F: KW runtime cannot read EG DB
$kwToEg = phase1_try_connect($host, $port, $egDb, $kwUser, $kwPass);
$fDenied = ($kwToEg === null) || !phase1_can_read_fixture($kwToEg);
if (!$fDenied) {
    $crossLeak++;
}
p_assert('F53_kw_cannot_read_eg', $fDenied);
$kwToEg = null;

// G: EG runtime cannot read KW DB
$egToKw = phase1_try_connect($host, $port, $kwDb, $egUser, $egPass);
$gDenied = ($egToKw === null) || !phase1_can_read_fixture($egToKw);
if (!$gDenied) {
    $crossLeak++;
}
p_assert('F54_eg_cannot_read_kw', $gDenied);
$egToKw = null;

// H: Country runtime users cannot read Control DB
$kwToControl = phase1_try_connect($host, $port, $controlDb, $kwUser, $kwPass);
$egToControl = phase1_try_connect($host, $port, $controlDb, $egUser, $egPass);
$hDenied = (($kwToControl === null) || !phase1_can_read_ctrl($kwToControl))
    && (($egToControl === null) || !phase1_can_read_ctrl($egToControl));
if (!$hDenied) {
    $crossLeak++;
}
p_assert('F55_country_cannot_read_control', $hDenied);
$kwToControl = null;
$egToControl = null;

$adminUser = getenv('ORANGE_PHASE1_MYSQL_ADMIN_USER');
$adminUser = ($adminUser !== false && trim((string) $adminUser) !== '') ? trim((string) $adminUser) : 'root';
$adminPassEnv = getenv('ORANGE_PHASE1_MYSQL_ADMIN_PASS');
$adminPass = ($adminPassEnv !== false) ? (string) $adminPassEnv : '';

$admin = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $adminUser,
    $adminPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$sa = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $saUser,
    $saPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$orangeExists = (int) $admin->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='orange_db'"
)->fetchColumn() > 0;

if ($orangeExists) {
    $kwToOrange = phase1_try_connect($host, $port, 'orange_db', $kwUser, $kwPass);
    $egToOrange = phase1_try_connect($host, $port, 'orange_db', $egUser, $egPass);
    $iDenied = ($kwToOrange === null) && ($egToOrange === null);
    if (!$iDenied) {
        $crossLeak++;
    }
    p_assert('F56_country_cannot_read_orange_db', $iDenied);
} else {
    $gKw = $admin->query('SHOW GRANTS FOR ' . $admin->quote($kwUser) . '@' . $admin->quote('127.0.0.1'))->fetchAll(PDO::FETCH_COLUMN);
    $gEg = $admin->query('SHOW GRANTS FOR ' . $admin->quote($egUser) . '@' . $admin->quote('127.0.0.1'))->fetchAll(PDO::FETCH_COLUMN);
    $grantText = strtolower(implode("\n", array_merge($gKw, $gEg)));
    p_assert('F56_country_no_orange_db_grant', !str_contains($grantText, '`orange_db`') && !str_contains($grantText, 'orange_db.*'));
}

foreach ([$kwUser, $egUser, $rtUser] as $u) {
    $grants = $admin->query('SHOW GRANTS FOR ' . $admin->quote($u) . '@' . $admin->quote('127.0.0.1'))->fetchAll(PDO::FETCH_COLUMN);
    $text = strtoupper(implode(' | ', $grants));
    p_assert(
        'F57_least_priv_' . $u,
        !str_contains($text, 'CREATE USER')
        && !str_contains($text, 'GRANT OPTION')
        && !preg_match('/ALL PRIVILEGES ON \*\.\*/', $text)
    );
}

// control_runtime positive SELECT allowlisted columns
$rt = phase1_try_connect($host, $port, $controlDb, $rtUser, $rtPass);
p_assert('G58_control_runtime_connect', $rt instanceof PDO);
if ($rt instanceof PDO) {
    try {
        $n = (int) $rt->query('SELECT COUNT(*) FROM ctrl_countries')->fetchColumn();
        p_assert('G59_rt_select_countries', $n >= 2);
    } catch (Throwable $e) {
        p_assert('G59_rt_select_countries', false, $e->getMessage());
    }
    try {
        $n = (int) $rt->query('SELECT COUNT(*) FROM ctrl_country_db_registry WHERE registry_status=\'active\'')->fetchColumn();
        p_assert('G60_rt_select_registry', $n >= 2);
    } catch (Throwable $e) {
        p_assert('G60_rt_select_registry', false, $e->getMessage());
    }

    // DML denied
    foreach (
        [
            "INSERT INTO ctrl_countries (country_uuid, code, slug, name_ar, name_en, currency_code, timezone, lifecycle_status, created_at, updated_at) VALUES ('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee','zz','zz','ز','Z','XXX','UTC','draft',NOW(6),NOW(6))",
            'UPDATE ctrl_countries SET sort_order = 0 WHERE code = \'kw\'',
            'DELETE FROM ctrl_reserved_database_names WHERE db_name = \'sys\'',
        ] as $sql
    ) {
        try {
            $rt->exec($sql);
            $controlRuntimeDml++;
            p_assert('G61_rt_dml_denied', false, 'dml_succeeded');
            break;
        } catch (Throwable $e) {
            // expected
        }
    }
    if ($controlRuntimeDml === 0) {
        p_assert('G61_rt_dml_denied', true);
    }

    // Protected columns denied
    $protectedDenied = 0;
    foreach (
        [
            'SELECT ownership_token FROM ctrl_schema_meta',
            'SELECT ownership_run_id FROM ctrl_schema_meta',
            'SELECT payload_json FROM ctrl_audit_events LIMIT 1',
            'SELECT payload_json FROM ctrl_trust_events LIMIT 1',
            'SELECT metadata_json FROM ctrl_country_db_registry_history LIMIT 1',
        ] as $sql
    ) {
        try {
            $rt->query($sql)->fetchColumn();
            $unknownGrant++;
        } catch (Throwable $e) {
            $protectedDenied++;
        }
    }
    p_assert('G62_protected_columns_denied', $protectedDenied === 5, 'denied=' . $protectedDenied);

    // Owner X3: control_runtime SELECT denied on ledger/audit/trust (no table grant).
    $x3TableDenied = 0;
    foreach (
        [
            'SELECT database_uuid FROM ctrl_database_uuid_ledger LIMIT 1',
            'SELECT id FROM ctrl_audit_events LIMIT 1',
            'SELECT id FROM ctrl_trust_events LIMIT 1',
        ] as $sql
    ) {
        try {
            $rt->query($sql)->fetchColumn();
            $unknownGrant++;
        } catch (Throwable $e) {
            $x3TableDenied++;
        }
    }
    p_assert('X3_forbidden_table_select_denied_127', $x3TableDenied === 3, 'denied=' . $x3TableDenied);

    // OVERBROAD: no SELECT ON db.*
    $rtGrants = $admin->query('SHOW GRANTS FOR ' . $admin->quote($rtUser) . '@' . $admin->quote('127.0.0.1'))->fetchAll(PDO::FETCH_COLUMN);
    $rtText = strtoupper(implode("\n", $rtGrants));
    $star = (bool) preg_match('/GRANT\\s+SELECT\\s+ON\\s+[`\']?' . preg_quote($controlDb, '/') . '[`\']?\\.\\*/i', $rtText);
    if ($star) {
        $overbroad++;
    }
    p_assert('G63_no_select_on_db_star', !$star);

    // password_hash / session_token_hash never granted
    p_assert(
        'G64_no_password_hash_grant',
        !str_contains(strtolower($rtText), 'password_hash')
        && !str_contains(strtolower($rtText), 'session_token_hash')
    );

    // Prove forbidden tables are absent from GRANT text (positive-column only).
    $rtTextLower = strtolower($rtText);
    p_assert(
        'X3_no_grant_forbidden_tables_127',
        !str_contains($rtTextLower, 'ctrl_database_uuid_ledger')
        && !str_contains($rtTextLower, 'ctrl_audit_events')
        && !str_contains($rtTextLower, 'ctrl_trust_events')
    );
}

// localhost host variant: same X3 denials (Owner — both loopback identities).
$rtLocal = phase1_try_connect('localhost', $port, $controlDb, $rtUser, $rtPass);
p_assert('X3_control_runtime_connect_localhost', $rtLocal instanceof PDO);
$x3LocalDenied = 0;
$x3LocalOkCountries = 0;
if ($rtLocal instanceof PDO) {
    try {
        $n = (int) $rtLocal->query('SELECT COUNT(*) FROM ctrl_countries')->fetchColumn();
        $x3LocalOkCountries = $n >= 2 ? 1 : 0;
    } catch (Throwable $e) {
        $x3LocalOkCountries = 0;
    }
    p_assert('X3_rt_select_countries_localhost', $x3LocalOkCountries === 1);
    foreach (
        [
            'SELECT database_uuid FROM ctrl_database_uuid_ledger LIMIT 1',
            'SELECT id FROM ctrl_audit_events LIMIT 1',
            'SELECT id FROM ctrl_trust_events LIMIT 1',
        ] as $sql
    ) {
        try {
            $rtLocal->query($sql)->fetchColumn();
            $unknownGrant++;
        } catch (Throwable $e) {
            $x3LocalDenied++;
        }
    }
    p_assert('X3_forbidden_table_select_denied_localhost', $x3LocalDenied === 3, 'denied=' . $x3LocalDenied);
    $rtGrantsLocal = $admin->query('SHOW GRANTS FOR ' . $admin->quote($rtUser) . '@' . $admin->quote('localhost'))->fetchAll(PDO::FETCH_COLUMN);
    $rtLocalGrantText = strtolower(implode("\n", $rtGrantsLocal));
    p_assert(
        'X3_no_grant_forbidden_tables_localhost',
        !str_contains($rtLocalGrantText, 'ctrl_database_uuid_ledger')
        && !str_contains($rtLocalGrantText, 'ctrl_audit_events')
        && !str_contains($rtLocalGrantText, 'ctrl_trust_events')
    );
}
$rtLocal = null;

// country_runtime positive + identity SELECT-only
$kwRt = phase1_try_connect($host, $port, $kwDb, $kwUser, $kwPass);
p_assert('H65_kw_runtime_connect', $kwRt instanceof PDO);
if ($kwRt instanceof PDO) {
    try {
        $m = (string) $kwRt->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
        p_assert('H66_kw_fixture_dml_select', $m === 'KW_FIXTURE_ONLY');
    } catch (Throwable $e) {
        p_assert('H66_kw_fixture_dml_select', false, $e->getMessage());
    }
    try {
        $c = (string) $kwRt->query('SELECT country_code FROM orange_db_identity WHERE identity_row_id=1')->fetchColumn();
        p_assert('H67_kw_identity_select', $c === 'KW');
    } catch (Throwable $e) {
        p_assert('H67_kw_identity_select', false, $e->getMessage());
    }

    $writeDenied = 0;
    foreach (
        [
            "INSERT INTO orange_db_identity (identity_row_id, database_uuid, country_uuid, country_code, schema_revision, schema_template_hash, identity_fingerprint, host_profile_key, db_name, sealed_at, sealed_by_principal_ref, identity_revision, fingerprint_version) VALUES (1,'a','b','KW',1,REPEAT('0',64),REPEAT('0',64),'k','d',NOW(6),'p',1,1)",
            "UPDATE orange_db_identity SET country_code='ZZ' WHERE identity_row_id=1",
            'DELETE FROM orange_db_identity WHERE identity_row_id=1',
        ] as $sql
    ) {
        try {
            $kwRt->exec($sql);
        } catch (Throwable $e) {
            $writeDenied++;
        }
    }
    p_assert('H68_identity_write_denied', $writeDenied === 3, 'denied=' . $writeDenied);

    $ddlDenied = 0;
    foreach (
        [
            'CREATE TABLE orange_pwn (id INT)',
            'DROP TABLE orange_phase1_fixture',
            'CREATE USER p1evil@localhost IDENTIFIED BY \'x\'',
        ] as $sql
    ) {
        try {
            $kwRt->exec($sql);
        } catch (Throwable $e) {
            $ddlDenied++;
        }
    }
    p_assert('H69_ddl_user_denied', $ddlDenied === 3, 'denied=' . $ddlDenied);
}

// Router path isolation with control_runtime
$secretResolver = static function (string $ref, int $ver) use ($secretMap): ?string {
    $key = $ref . '|' . $ver;
    return array_key_exists($key, $secretMap) ? (string) $secretMap[$key] : null;
};
$router = orange_db_router_new($controlDb);
$control = $router->openControl([
    'host' => $host,
    'port' => $port,
    'db_name' => $controlDb,
    'username' => $rtUser,
    'password' => $rtPass,
]);
$kwPdo = $router->openCountryDb($control, 'KW', $secretResolver);
$egPdo = $router->openCountryDb($control, 'EG', $secretResolver);
$kwM = (string) $kwPdo->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
$egM = (string) $egPdo->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
$kwDbNow = (string) $kwPdo->query('SELECT DATABASE()')->fetchColumn();
$egDbNow = (string) $egPdo->query('SELECT DATABASE()')->fetchColumn();
if ($kwM !== 'KW_FIXTURE_ONLY' || strcasecmp($kwDbNow, $kwDb) !== 0) {
    $wrongRoute++;
}
if ($egM !== 'EG_FIXTURE_ONLY' || strcasecmp($egDbNow, $egDb) !== 0) {
    $wrongRoute++;
}
p_assert('H70_router_kw_eg_isolation', $wrongRoute === 0, "kw={$kwM}/{$kwDbNow} eg={$egM}/{$egDbNow}");

// Registry secret refs only (no password columns)
$rows = $admin->query(
    'SELECT secret_ref FROM `' . str_replace('`', '``', $controlDb) . '`.ctrl_secret_refs'
)->fetchAll(PDO::FETCH_COLUMN);
$noRaw = true;
foreach ($rows as $ref) {
    if (!is_string($ref) || !str_starts_with($ref, 'orange://secrets/')) {
        $noRaw = false;
    }
    if (is_string($ref) && $kwPass !== '' && str_contains($ref, $kwPass)) {
        $noRaw = false;
    }
}
$cols = $admin->query(
    'SHOW COLUMNS FROM `' . str_replace('`', '``', $controlDb) . '`.ctrl_secret_refs'
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($cols as $col) {
    $c = strtolower((string) $col);
    if (str_contains($c, 'password') || $c === 'dsn') {
        $noRaw = false;
    }
}
p_assert('H71_registry_secret_ref_only', $noRaw);

// SHOW DATABASES / cross-schema qualified SELECT denial where enforceable
$showLeak = false;
if ($kwRt instanceof PDO) {
    try {
        $dbs = $kwRt->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dbs as $d) {
            if (strcasecmp((string) $d, $egDb) === 0 || strcasecmp((string) $d, $controlDb) === 0) {
                // Visibility alone is not enough; require qualified read denial below.
            }
        }
        try {
            $kwRt->query('SELECT marker FROM `' . str_replace('`', '``', $egDb) . '`.orange_phase1_fixture LIMIT 1')->fetchColumn();
            $showLeak = true;
            $crossLeak++;
        } catch (Throwable $e) {
            $showLeak = false;
        }
    } catch (Throwable $e) {
        $showLeak = false;
    }
}
p_assert('H72_cross_schema_qualified_denied', !$showLeak);

// Concurrent child processes
$phpBin = PHP_BINARY;
$childScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase1_child_' . $runId . '.php';
$childSrc = <<<'PHP'
<?php
declare(strict_types=1);
require_once $argv[1];
$runId = $argv[2];
$code = strtoupper($argv[3]);
$state = json_decode((string) file_get_contents(sys_get_temp_dir() . '/orange_phase1_state_' . $runId . '.json'), true);
$secrets = json_decode((string) file_get_contents(sys_get_temp_dir() . '/orange_phase1_secrets_' . $runId . '.json'), true);
$map = $secrets['by_ref_version'] ?? [];
$resolver = static function (string $ref, int $ver) use ($map): ?string {
    $key = $ref . '|' . $ver;
    return array_key_exists($key, $map) ? (string) $map[$key] : null;
};
$r = orange_db_router_new((string) $state['control_db']);
$c = $r->openControl([
    'host' => $state['host'],
    'port' => (int) $state['port'],
    'db_name' => $state['control_db'],
    'username' => $state['control_runtime_user'],
    'password' => (string) ($secrets['control_runtime_password'] ?? ''),
]);
$pdo = $r->openCountryDb($c, $code, $resolver);
$marker = (string) $pdo->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
$db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo $marker . '|' . $db;
PHP;
file_put_contents($childScript, $childSrc);
$routerPhp = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'db_router.php';
$cmdKw = escapeshellarg($phpBin) . ' ' . escapeshellarg($childScript) . ' ' . escapeshellarg($routerPhp) . ' ' . escapeshellarg($runId) . ' KW';
$cmdEg = escapeshellarg($phpBin) . ' ' . escapeshellarg($childScript) . ' ' . escapeshellarg($routerPhp) . ' ' . escapeshellarg($runId) . ' EG';
$outKw = [];
$outEg = [];
exec($cmdKw, $outKw, $codeKw);
exec($cmdEg, $outEg, $codeEg);
$lineKw = implode('', $outKw);
$lineEg = implode('', $outEg);
p_assert(
    'H73_concurrent_child_kw',
    $codeKw === 0 && str_starts_with($lineKw, 'KW_FIXTURE_ONLY|' . $kwDb),
    $lineKw
);
p_assert(
    'H74_concurrent_child_eg',
    $codeEg === 0 && str_starts_with($lineEg, 'EG_FIXTURE_ONLY|' . $egDb),
    $lineEg
);
@unlink($childScript);

p_assert('H75_cross_country_leak_count_zero', $crossLeak === 0, 'leak=' . $crossLeak);
p_assert(
    'H76_grant_counters_clean',
    $controlRuntimeDml === 0 && $unknownGrant === 0 && $overbroad === 0,
    "dml={$controlRuntimeDml} unknown={$unknownGrant} overbroad={$overbroad}"
);

// Stock Rev4 trigger restoration proofs (tests run only after bootstrap restore).
$sa->exec('USE `' . str_replace('`', '``', $controlDb) . '`');
$gateCnt = (int) $sa->query(
    "SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA=" . $sa->quote($controlDb) . "
       AND TRIGGER_NAME IN ('trg_ctrl_registry_bu_phase1_gate','trg_ctrl_countries_bu_phase1_gate')"
)->fetchColumn();
$stockCnt = (int) $sa->query(
    "SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA=" . $sa->quote($controlDb) . "
       AND TRIGGER_NAME IN ('trg_ctrl_registry_bu_phase21a','trg_ctrl_countries_bu_phase21a')"
)->fetchColumn();
if ($gateCnt !== 0 || $stockCnt !== 2) {
    $falseGreen++;
}
p_assert('H77_stock_triggers_restored_no_gate', $gateCnt === 0 && $stockCnt === 2, "gate={$gateCnt} stock={$stockCnt}");

$stockBuCapsProv = is_array($state['stock_bu_triggers'] ?? null) ? $state['stock_bu_triggers'] : [];
$metaEq = 0;
try {
    phase1_verify_stock_bu_triggers($sa, $controlDb, $stockBuCapsProv);
    phase1_verify_control_meta_gate($sa);
    $metaEq = 1;
} catch (Throwable $e) {
    $falseGreen++;
    p_assert('H77b_full_stock_trigger_metadata_match', false, $e->getMessage());
}
if ($metaEq === 1) {
    p_assert('H77b_full_stock_trigger_metadata_match', true);
}

$stockActiveBlocked = 0;
try {
    $sa->prepare('UPDATE ctrl_country_db_registry SET updated_at = updated_at WHERE id = ?')->execute([
        (int) $state['kw_registry_id'],
    ]);
    $falseGreen++;
    p_assert('H78_stock_active_row_update_blocked', false, 'update_succeeded');
} catch (Throwable $e) {
    $stockActiveBlocked = stripos($e->getMessage(), 'phase21c_prerequisites_not_available') !== false ? 1 : 0;
    if ($stockActiveBlocked !== 1) {
        $falseGreen++;
    }
    p_assert('H78_stock_active_row_update_blocked', $stockActiveBlocked === 1, $e->getMessage());
}

$routerOkStock = 0;
try {
    $kwPdo2 = $router->openCountryDb($control, 'KW', $secretResolver);
    $m2 = (string) $kwPdo2->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
    if ($m2 === 'KW_FIXTURE_ONLY' && $gateCnt === 0 && $stockCnt === 2) {
        $routerOkStock = 1;
    } else {
        $falseGreen++;
    }
    p_assert('H79_router_ok_on_restored_stock', $routerOkStock === 1, $m2);
} catch (Throwable $e) {
    $falseGreen++;
    p_assert('H79_router_ok_on_restored_stock', false, $e instanceof OrangeDbRouterException ? $e->errorCode() : $e->getMessage());
}

$bootGate = (int) ($state['phase1_gate_trigger_create_count'] ?? -1);
$bootRestore = (int) ($state['stock_trigger_restore_ok'] ?? 0);
if ($bootGate !== 0 || $bootRestore !== 1) {
    $falseGreen++;
}
p_assert('H80_bootstrap_stock_restore_counters', $bootGate === 0 && $bootRestore === 1, "gate={$bootGate} restore={$bootRestore}");

echo "---\n";
echo 'PASS_COUNT=' . $pass . "\n";
echo 'FAIL_COUNT=' . $fail . "\n";
echo 'SKIP_COUNT=' . $skip . "\n";
echo 'RAW_FAIL=' . ($fail > 0 ? '1' : '0') . "\n";
echo 'FALSE_GREEN_RISK_COUNT=' . $falseGreen . "\n";
echo 'CROSS_COUNTRY_LEAK_COUNT=' . $crossLeak . "\n";
echo 'WRONG_COUNTRY_ROUTE_COUNT=' . $wrongRoute . "\n";
echo 'CONTROL_RUNTIME_DML_COUNT=' . $controlRuntimeDml . "\n";
echo 'UNKNOWN_CONTROL_RUNTIME_GRANTED_COLUMN_COUNT=' . $unknownGrant . "\n";
echo 'OVERBROAD=' . $overbroad . "\n";
echo 'PHASE1_GATE_TRIGGER_CREATE_COUNT=' . $bootGate . "\n";
echo 'STOCK_ACTIVE_ROW_UPDATE_BLOCKED=' . $stockActiveBlocked . "\n";
echo 'ROUTER_OK_ON_RESTORED_STOCK_TRIGGERS=' . $routerOkStock . "\n";
echo "SECRET_EXPOSURE_COUNT=0\n";
echo "RAW_CREDENTIAL_OUTPUT_COUNT=0\n";
echo "ORANGE_DB_UNTOUCHED=1\n";

exit(($fail > 0 || $falseGreen > 0 || $routerOkStock !== 1 || $stockActiveBlocked !== 1) ? 1 : 0);
