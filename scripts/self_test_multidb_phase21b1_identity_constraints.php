<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.1 — Central Identity constraints / principals / authz self-test.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$phpBin = PHP_BINARY;
$repoRoot = dirname(__DIR__);
$bootstrap = $repoRoot . '/scripts/multidb/phase21b1_local_bootstrap.php';
$cleanup = $repoRoot . '/scripts/multidb/phase21b1_local_cleanup.php';
require_once $repoRoot . '/includes/control_schema.php';

$rawFail = 0;
$coreSkip = 0;
// Open false-green risks owned by this harness: F-G1 (SELECT collapse) + F-G2 (last-super).
$falseGreen = 2;
$pass = 0;

function c_assert(bool $cond, string $name, int &$rawFail, int &$pass): void
{
    if ($cond) {
        echo "PASS {$name}\n";
        $pass++;
    } else {
        echo "FAIL {$name}\n";
        $rawFail++;
    }
}

function c_pdo(string $host, int $port, string $db, string $user, string $pass): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function c_expect_priv(callable $fn, string $name, int &$rawFail, int &$pass): void
{
    try {
        $fn();
        echo "FAIL {$name} (expected privilege denial)\n";
        $rawFail++;
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? '');
        $code = (int) ($e->errorInfo[1] ?? 0);
        $ok = ($state === '42000' || $state === '28000' || in_array($code, [1142, 1370, 1044, 1045, 1227, 1143], true));
        c_assert($ok, $name . '_sqlstate_' . $state . '_code_' . $code, $rawFail, $pass);
    } catch (Throwable $e) {
        echo "FAIL {$name} (non-PDO " . $e->getMessage() . ")\n";
        $rawFail++;
    }
}

function c_expect_signal(callable $fn, string $expect, string $name, int &$rawFail, int &$pass): void
{
    try {
        $fn();
        echo "FAIL {$name} (expected {$expect})\n";
        $rawFail++;
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? '');
        $msg = (string) $e->getMessage();
        c_assert($state === '45000' && str_contains($msg, $expect), $name . '_45000', $rawFail, $pass);
    } catch (Throwable $e) {
        echo "FAIL {$name} (unexpected " . $e->getMessage() . ")\n";
        $rawFail++;
    }
}

function c_call(PDO $pdo, string $sql, array $params = []): void
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($st->nextRowset()) {
        // drain
    }
}

$runId = bin2hex(random_bytes(6));
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($bootstrap) . ' --run-id=' . escapeshellarg($runId) . ' 2>&1', $bootOut, $bootCode);
echo implode("\n", $bootOut) . "\n";
c_assert($bootCode === 0, 'bootstrap_exit_0', $rawFail, $pass);

$state = json_decode((string) file_get_contents(sys_get_temp_dir() . "/orange_phase21b1_state_{$runId}.json"), true);
$secrets = json_decode((string) file_get_contents(sys_get_temp_dir() . "/orange_phase21b1_secrets_{$runId}.json"), true);
$host = (string) $state['host'];
$port = (int) $state['port'];
$db = (string) $state['control_db'];
$sessionHash = (string) $state['fixture_session_token_hash'];
$superId = (int) $state['fixture_super_admin_id'];
$countryId = (int) $state['fixture_country_id'];

$sa = c_pdo($host, $port, $db, (string) $state['schema_admin_user'], (string) $secrets['schema_admin_password']);
$tr = c_pdo($host, $port, $db, (string) $state['trust_executor_user'], (string) $secrets['trust_executor_password']);
$id = c_pdo($host, $port, $db, (string) $state['identity_executor_user'], (string) $secrets['identity_executor_password']);
$rt = c_pdo($host, $port, $db, (string) $state['control_runtime_user'], (string) $secrets['control_runtime_password']);

// Principals USER/CURRENT_USER
foreach (
    [
        [$sa, (string) $state['schema_admin_user'], 'sa'],
        [$tr, (string) $state['trust_executor_user'], 'tr'],
        [$id, (string) $state['identity_executor_user'], 'id'],
        [$rt, (string) $state['control_runtime_user'], 'rt'],
    ] as [$pdoX, $uname, $label]
) {
    $cu = (string) $pdoX->query('SELECT CURRENT_USER()')->fetchColumn();
    $u = (string) $pdoX->query('SELECT USER()')->fetchColumn();
    $d = (string) $pdoX->query('SELECT DATABASE()')->fetchColumn();
    c_assert(str_starts_with($cu, $uname . '@') || str_starts_with($u, $uname . '@'), "principal_{$label}_user", $rawFail, $pass);
    c_assert($d === $db, "principal_{$label}_database", $rawFail, $pass);
}

// RT cannot INSERT identity events
c_expect_priv(static function () use ($rt): void {
    $rt->exec("INSERT INTO ctrl_identity_events (
        event_uuid, event_type, invoker_mysql_user, definer_mysql_user, actor_kind, created_at
     ) VALUES (UUID(), 'admin_created', 'x', 'y', 'human', NOW(6))");
}, 'rt_cannot_insert_identity_events', $rawFail, $pass);

// ID cannot EXECUTE emit_event
c_expect_priv(static function () use ($id): void {
    c_call($id, 'CALL orange_ctrl_identity_emit_event(?,?,?,?,?,CAST(? AS JSON))', [
        'admin_created', null, null, 'human', null, '{}',
    ]);
}, 'id_cannot_execute_emit_event', $rawFail, $pass);

// TR cannot EXECUTE identity upsert
c_expect_priv(static function () use ($tr, $sessionHash): void {
    $tr->exec('SET @oid = NULL');
    c_call($tr, 'CALL orange_ctrl_identity_admin_upsert(?,?,?,?,?,?,?,@oid)', [
        $sessionHash, null, 'xuser', password_hash('x', PASSWORD_DEFAULT), 'X', 1, null,
    ]);
}, 'tr_cannot_execute_identity', $rawFail, $pass);

// ID cannot UPDATE password_hash directly
c_expect_priv(static function () use ($id, $superId): void {
    $id->exec('UPDATE ctrl_admins SET password_hash="x" WHERE id=' . (int) $superId);
}, 'id_cannot_direct_update_password', $rawFail, $pass);

// Human actor + valid session path: create ordinary admin
$id->exec('SET @oid = NULL');
c_call($id, 'CALL orange_ctrl_identity_admin_upsert(?,?,?,?,?,?,?,@oid)', [
    $sessionHash, null, 'ordinary1', password_hash('Ordinary1!', PASSWORD_DEFAULT), 'عادي', 1, null,
]);
$ordId = (int) $id->query('SELECT @oid')->fetchColumn();
c_assert($ordId > 0, 'admin_upsert_create_ordinary', $rawFail, $pass);

$ev = (int) $id->query(
    "SELECT COUNT(*) FROM ctrl_identity_events WHERE event_type='admin_created' AND target_admin_id=" . $ordId
    . " AND human_actor_admin_id=" . $superId
)->fetchColumn();
c_assert($ev === 1, 'event_admin_created_human_actor', $rawFail, $pass);

$row = $id->query(
    "SELECT invoker_mysql_user, definer_mysql_user, human_actor_admin_uuid, target_admin_uuid
     FROM ctrl_identity_events WHERE event_type='admin_created' AND target_admin_id={$ordId} ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
c_assert(is_array($row) && str_contains((string) $row['invoker_mysql_user'], 'p21b1_id_'), 'event_invoker_is_id', $rawFail, $pass);
c_assert(is_array($row) && str_contains((string) $row['definer_mysql_user'], 'p21b1_sa_'), 'event_definer_is_sa', $rawFail, $pass);

// Missing session deny
c_expect_signal(static function () use ($id): void {
    $id->exec('SET @oid=NULL');
    c_call($id, 'CALL orange_ctrl_identity_admin_upsert(?,?,?,?,?,?,?,@oid)', [
        str_repeat('0', 64), null, 'nouser', password_hash('x', PASSWORD_DEFAULT), 'n', 1, null,
    ]);
}, 'identity_authorization_denied', 'deny_missing_session', $rawFail, $pass);

// Unicode username deny
c_expect_signal(static function () use ($id, $sessionHash): void {
    $id->exec('SET @oid=NULL');
    c_call($id, 'CALL orange_ctrl_identity_admin_upsert(?,?,?,?,?,?,?,@oid)', [
        $sessionHash, null, 'مدير', password_hash('x', PASSWORD_DEFAULT), 'ok', 1, null,
    ]);
}, 'identity_username_invalid', 'deny_unicode_username', $rawFail, $pass);

// Self-escalation deny
c_expect_signal(static function () use ($id, $sessionHash, $superId): void {
    c_call($id, 'CALL orange_ctrl_identity_set_superuser(?,?,?,?)', [
        $sessionHash, $superId, 1, null,
    ]);
}, 'identity_self_escalation_denied', 'deny_self_superuser', $rawFail, $pass);

// Grant superuser to ordinary then revoke (proof path), keeping fixture as sole active super
c_call($id, 'CALL orange_ctrl_identity_set_superuser(?,?,?,?)', [$sessionHash, $ordId, 1, null]);
$su = (int) $id->query('SELECT is_superuser FROM ctrl_admins WHERE id=' . $ordId)->fetchColumn();
c_assert($su === 1, 'grant_superuser_other', $rawFail, $pass);
c_call($id, 'CALL orange_ctrl_identity_set_superuser(?,?,?,?)', [$sessionHash, $ordId, 0, null]);
$su0 = (int) $id->query('SELECT is_superuser FROM ctrl_admins WHERE id=' . $ordId)->fetchColumn();
c_assert($su0 === 0, 'revoke_superuser_other', $rawFail, $pass);

// Last superuser protection: cannot disable the only active superuser
c_expect_signal(static function () use ($id, $sessionHash): void {
    $id->exec('SET @oid=NULL');
    c_call($id, 'CALL orange_ctrl_identity_admin_upsert(?,?,?,?,?,?,?,@oid)', [
        $sessionHash,
        'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'superfix',
        null,
        'مدير النظام',
        0,
        null,
    ]);
}, 'last_active_superuser_required', 'deny_disable_last_super', $rawFail, $pass);
$still = (int) $id->query('SELECT is_active FROM ctrl_admins WHERE id=' . $superId)->fetchColumn();
c_assert($still === 1, 'last_super_still_active', $rawFail, $pass);

// F-G2: two active supers — demote non-actor via set_superuser ALLOW; actor remains
c_call($id, 'CALL orange_ctrl_identity_set_superuser(?,?,?,?)', [$sessionHash, $ordId, 1, null]);
$suTwo = (int) $id->query(
    'SELECT COUNT(*) FROM ctrl_admins WHERE is_superuser=1 AND is_active=1'
)->fetchColumn();
c_assert($suTwo === 2, 'fg2_two_active_supers', $rawFail, $pass);
c_call($id, 'CALL orange_ctrl_identity_set_superuser(?,?,?,?)', [$sessionHash, $ordId, 0, null]);
$suAfterDemote = (int) $id->query('SELECT is_superuser FROM ctrl_admins WHERE id=' . $ordId)->fetchColumn();
$actorStill = (int) $id->query('SELECT is_superuser, is_active FROM ctrl_admins WHERE id=' . $superId)->fetch(PDO::FETCH_NUM)[0];
$actorActive = (int) $id->query('SELECT is_active FROM ctrl_admins WHERE id=' . $superId)->fetchColumn();
c_assert($suAfterDemote === 0 && $actorStill === 1 && $actorActive === 1, 'fg2_demote_other_allow_actor_remains', $rawFail, $pass);

// F-G2 concurrency: two real PDO connections — SA holds active-super FOR UPDATE;
// identity_executor CALL (DEFINER) must contend on the same ordered lock set.
c_call($id, 'CALL orange_ctrl_identity_set_superuser(?,?,?,?)', [$sessionHash, $ordId, 1, null]);
$idB = c_pdo($host, $port, $db, (string) $state['identity_executor_user'], (string) $secrets['identity_executor_password']);
$idB->exec('SET SESSION innodb_lock_wait_timeout = 1');
$beforeRace = $rawFail;
$lockContended = false;
try {
    $sa->beginTransaction();
    $sa->query(
        'SELECT id FROM ctrl_admins WHERE is_superuser = 1 AND is_active = 1 ORDER BY id FOR UPDATE'
    )->fetchAll();
    try {
        c_call($idB, 'CALL orange_ctrl_identity_set_superuser(?,?,?,?)', [$sessionHash, $ordId, 0, null]);
        $lockContended = false;
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        // 1205 lock wait timeout — proves DEFINER lock path blocked on held active-super locks
        $lockContended = ($code === 1205);
    }
    $sa->commit();
} catch (Throwable $e) {
    if ($sa->inTransaction()) {
        $sa->rollBack();
    }
    echo 'FAIL fg2_concurrency_lock_hold ' . $e->getMessage() . "\n";
    $rawFail++;
}
c_assert($lockContended, 'fg2_concurrency_two_pdo_lock_contention', $rawFail, $pass);
// After SA releases, demote peer must ALLOW (actor remains)
c_call($idB, 'CALL orange_ctrl_identity_set_superuser(?,?,?,?)', [$sessionHash, $ordId, 0, null]);
$afterRaceSu = (int) $id->query('SELECT is_superuser FROM ctrl_admins WHERE id=' . $ordId)->fetchColumn();
c_assert($afterRaceSu === 0, 'fg2_concurrency_demote_after_unlock', $rawFail, $pass);
$activeSupers = (int) $id->query(
    'SELECT COUNT(*) FROM ctrl_admins WHERE is_superuser=1 AND is_active=1'
)->fetchColumn();
c_assert($activeSupers >= 1, 'fg2_concurrency_at_least_one_super', $rawFail, $pass);

$fg2Closed = ($suTwo === 2 && $suAfterDemote === 0 && $actorStill === 1 && $actorActive === 1
    && $still === 1 && $lockContended && $afterRaceSu === 0 && $activeSupers >= 1 && $rawFail === $beforeRace);
if ($fg2Closed) {
    $falseGreen--;
}

$beforeFg1 = $rawFail;
$denyCols = [
    ['ctrl_admins', 'password_hash'],
    ['ctrl_admin_sessions', 'session_token_hash'],
    ['ctrl_admin_sessions', 'token_fingerprint'],
];
foreach (['id' => $id, 'tr' => $tr, 'rt' => $rt] as $label => $pdoX) {
    foreach ($denyCols as [$tbl, $col]) {
        c_expect_priv(static function () use ($pdoX, $tbl, $col): void {
            $pdoX->query("SELECT `{$col}` FROM `{$tbl}` LIMIT 1");
        }, "fg1_{$label}_deny_{$col}", $rawFail, $pass);
    }
}
c_expect_priv(static function () use ($id): void {
    $id->query('SELECT ownership_token FROM ctrl_schema_meta LIMIT 1');
}, 'fg1_id_deny_ownership_token', $rawFail, $pass);
c_assert(
    (string) $id->query('SELECT username FROM ctrl_admins WHERE id=' . $superId)->fetchColumn() === 'superfix',
    'fg1_id_allow_username',
    $rawFail,
    $pass
);
// Collapse negation: cannot read live session_token_hash then forge human CALL
$stolen = null;
try {
    $stolen = $id->query('SELECT session_token_hash FROM ctrl_admin_sessions WHERE revoked_at IS NULL LIMIT 1')->fetchColumn();
} catch (Throwable $e) {
    $stolen = null;
}
c_assert($stolen === null || $stolen === false, 'fg1_collapse_no_hash_read', $rawFail, $pass);
c_expect_signal(static function () use ($id): void {
    $id->exec('SET @oid=NULL');
    c_call($id, 'CALL orange_ctrl_identity_admin_upsert(?,?,?,?,?,?,?,@oid)', [
        str_repeat('f', 64), null, 'forgeduser', password_hash('x', PASSWORD_DEFAULT), 'f', 1, null,
    ]);
}, 'identity_authorization_denied', 'fg1_collapse_forged_hash_denied', $rawFail, $pass);
$id->exec('SET @oid=NULL');
c_call($id, 'CALL orange_ctrl_identity_admin_upsert(?,?,?,?,?,?,?,@oid)', [
    $sessionHash, null, 'fg1pos', password_hash('Fg1Pos!1', PASSWORD_DEFAULT), 'OK', 1, null,
]);
$fg1PosId = (int) $id->query('SELECT @oid')->fetchColumn();
c_assert($fg1PosId > 0, 'fg1_positive_upsert_with_php_hash', $rawFail, $pass);
if ($rawFail === $beforeFg1) {
    $falseGreen--; // F-G1 closed
}

// global_access does not imply matrix bypass — set global on ordinary, no permissions
c_call($id, 'CALL orange_ctrl_identity_set_global_access(?,?,?,?)', [$sessionHash, $ordId, 1, null]);
$ga = (int) $id->query('SELECT is_global_access FROM ctrl_admins WHERE id=' . $ordId)->fetchColumn();
$permCnt = (int) $id->query('SELECT COUNT(*) FROM ctrl_admin_permissions WHERE admin_id=' . $ordId)->fetchColumn();
c_assert($ga === 1 && $permCnt === 0, 'global_access_no_matrix_rows', $rawFail, $pass);

// permissions replace
c_call($id, 'CALL orange_ctrl_identity_permissions_replace(?,?,CAST(? AS JSON),?)', [
    $sessionHash,
    $ordId,
    json_encode([['resource_key' => 'page:products', 'can_view' => 1, 'can_edit' => 1]], JSON_UNESCAPED_UNICODE),
    null,
]);
$pc = (int) $id->query('SELECT COUNT(*) FROM ctrl_admin_permissions WHERE admin_id=' . $ordId)->fetchColumn();
c_assert($pc === 1, 'permissions_replace_ok', $rawFail, $pass);

c_expect_signal(static function () use ($id, $sessionHash, $ordId): void {
    c_call($id, 'CALL orange_ctrl_identity_permissions_replace(?,?,CAST(? AS JSON),?)', [
        $sessionHash, $ordId, json_encode([['resource_key' => 'bad key']], JSON_UNESCAPED_UNICODE), null,
    ]);
}, 'identity_resource_key_invalid', 'deny_bad_resource_key', $rawFail, $pass);

// country access + default
c_call($id, 'CALL orange_ctrl_identity_country_access_set(?,?,?,?,?,?)', [
    $sessionHash, $ordId, $countryId, 1, 1, null,
]);
$def = (int) $id->query(
    'SELECT is_default FROM ctrl_admin_country_access WHERE admin_id=' . $ordId . ' AND country_id=' . $countryId
)->fetchColumn();
c_assert($def === 1, 'country_default_set', $rawFail, $pass);

c_expect_signal(static function () use ($id, $sessionHash, $ordId, $countryId): void {
    c_call($id, 'CALL orange_ctrl_identity_country_access_set(?,?,?,?,?,?)', [
        $sessionHash, $ordId, $countryId, 0, 1, null,
    ]);
}, 'identity_default_requires_access', 'deny_default_without_access', $rawFail, $pass);

// password change emits event + revokes sessions
$before = (int) $id->query("SELECT COUNT(*) FROM ctrl_identity_events WHERE event_type='password_changed' AND target_admin_id={$ordId}")->fetchColumn();
c_call($id, 'CALL orange_ctrl_identity_set_password(?,?,?,?)', [
    $sessionHash, $ordId, password_hash('NewPass1!', PASSWORD_DEFAULT), null,
]);
$after = (int) $id->query("SELECT COUNT(*) FROM ctrl_identity_events WHERE event_type='password_changed' AND target_admin_id={$ordId}")->fetchColumn();
c_assert($after === $before + 1, 'password_changed_event_plus_one', $rawFail, $pass);

// throttle check/record/reset
$id->exec('SET @a=0; SET @l=0; SET @r=0');
c_call($id, 'CALL orange_ctrl_identity_login_throttle_apply(?,?,?,?,?,?,?,@a,@l,@r)', [
    'record_failure', 'throttleuser', '127.0.0.1', 5, 30, 900, 900,
]);
$fc = (int) $id->query(
    "SELECT failed_count FROM ctrl_admin_login_throttle WHERE scope_type='username' AND scope_key='throttleuser'"
)->fetchColumn();
c_assert($fc === 1, 'throttle_record_failure', $rawFail, $pass);
c_call($id, 'CALL orange_ctrl_identity_login_throttle_apply(?,?,?,?,?,?,?,@a,@l,@r)', [
    'reset_success', 'throttleuser', '127.0.0.1', 5, 30, 900, 900,
]);
$gone = (int) $id->query(
    "SELECT COUNT(*) FROM ctrl_admin_login_throttle WHERE scope_type='username' AND scope_key='throttleuser'"
)->fetchColumn();
c_assert($gone === 0, 'throttle_reset_success', $rawFail, $pass);
$et = (int) $id->query("SELECT COUNT(*) FROM ctrl_admin_login_throttle WHERE scope_type='et_mail'")->fetchColumn();
c_assert($et === 0, 'et_mail_absent_from_control', $rawFail, $pass);

// session pair mismatch deny via session_issue
$newHash = hash('sha256', 'issued-token-fixture-1', false);
c_expect_signal(static function () use ($id, $sessionHash, $superId, $countryId, $newHash): void {
    $id->exec('SET @sid=NULL');
    c_call($id, 'CALL orange_ctrl_identity_session_issue(?,?,?,?,?,?,?,?,?,?,@sid)', [
        $newHash, $superId, date('Y-m-d H:i:s', time() + 3600), $countryId, '00000000-0000-4000-8000-000000000000',
        null, null, 'human', $sessionHash, null,
    ]);
}, 'identity_session_country_pair_mismatch', 'deny_session_pair_mismatch', $rawFail, $pass);

c_expect_signal(static function () use ($id, $superId, $newHash): void {
    $id->exec('SET @sid=NULL');
    c_call($id, 'CALL orange_ctrl_identity_session_issue(?,?,?,?,?,?,?,?,?,?,@sid)', [
        $newHash, $superId, date('Y-m-d H:i:s', time() + 3600), null, null,
        null, null, 'human', null, null,
    ]);
}, 'identity_issuer_session_required', 'deny_issuerless_session_issue', $rawFail, $pass);

// Migrate 2→3 via external parent process
$migRun = bin2hex(random_bytes(6));
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_p21b1_rev2_' . $migRun;
mkdir($tmpDir);
file_put_contents($tmpDir . '/control_schema.php', shell_exec('git -C ' . escapeshellarg($repoRoot) . ' show d95580bd:includes/control_schema.php'));
file_put_contents($tmpDir . '/control_fingerprint.php', shell_exec('git -C ' . escapeshellarg($repoRoot) . ' show d95580bd:includes/control_fingerprint.php'));
// Fix require path inside extracted control_schema.php
$cs = (string) file_get_contents($tmpDir . '/control_schema.php');
$cs = str_replace("require_once __DIR__ . '/control_fingerprint.php';", "require_once __DIR__ . '/control_fingerprint.php';", $cs);
file_put_contents($tmpDir . '/control_schema.php', $cs);

$migDb = 'orange_phase21b1_mig_' . $migRun;
$migSa = 'p21b1_msa_' . $migRun;
$migPass = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '._'), '=');
$ownTok = hash('sha256', random_bytes(32), false);
$admin = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $admin->exec('SET GLOBAL log_bin_trust_function_creators = 1');
} catch (Throwable $e) {
    // ignore
}
$admin->exec('CREATE DATABASE `' . $migDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
foreach (['localhost', '127.0.0.1'] as $hi) {
    $admin->exec("CREATE USER '{$migSa}'@'{$hi}' IDENTIFIED BY " . $admin->quote($migPass));
    $admin->exec("GRANT ALL PRIVILEGES ON `{$migDb}`.* TO '{$migSa}'@'{$hi}' WITH GRANT OPTION");
    $admin->exec("GRANT TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON `{$migDb}`.* TO '{$migSa}'@'{$hi}'");
}
$admin->exec('FLUSH PRIVILEGES');

$installer = <<<PHP
<?php
require_once {$tmpDir}/control_schema.php';
\$pdo = new PDO('mysql:host=127.0.0.1;port={$port};dbname={$migDb};charset=utf8mb4', '{$migSa}', {$admin->quote($migPass)}, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
orange_control_ensure_schema(\$pdo, [
  'ownership_token' => '{$ownTok}',
  'ownership_run_id' => '{$migRun}',
  'installed_by' => 'phase21b1_rev2_fixture',
  'allow_meta_insert' => true,
]);
echo 'REV2_OK revision=' . ORANGE_CONTROL_SCHEMA_REVISION . PHP_EOL;
\$h = orange_control_physical_manifest_hash(\$pdo, '{$migDb}');
echo 'HASH=' . \$h . PHP_EOL;
PHP;
// Fix path quoting for Windows
$installer = str_replace(
    "require_once {$tmpDir}/control_schema.php';",
    'require_once ' . var_export(str_replace('\\', '/', $tmpDir) . '/control_schema.php', true) . ';',
    $installer
);
$instPath = $tmpDir . '/install_rev2.php';
file_put_contents($instPath, $installer);
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($instPath) . ' 2>&1', $instOut, $instCode);
echo implode("\n", $instOut) . "\n";
c_assert($instCode === 0, 'migrate_rev2_install_exit_0', $rawFail, $pass);

$migPdo = c_pdo($host, $port, $migDb, $migSa, $migPass);
$meta2 = $migPdo->query('SELECT control_schema_revision, schema_manifest_hash FROM ctrl_schema_meta WHERE id=1')->fetch(PDO::FETCH_ASSOC);
c_assert(is_array($meta2) && (int) $meta2['control_schema_revision'] === 2, 'migrate_source_rev_2', $rawFail, $pass);

// migrate with current code
require_once $repoRoot . '/includes/control_schema.php';
orange_control_ensure_schema($migPdo, [
    'installed_by' => 'phase21b1_migrate_test',
    'allow_meta_insert' => false,
]);
$meta3 = $migPdo->query('SELECT control_schema_revision, schema_manifest_hash FROM ctrl_schema_meta WHERE id=1')->fetch(PDO::FETCH_ASSOC);
c_assert(is_array($meta3) && (int) $meta3['control_schema_revision'] === 3, 'migrate_dest_rev_3', $rawFail, $pass);
$freshHash = (string) $state['schema_manifest_hash'];
$migHash = (string) $meta3['schema_manifest_hash'];
c_assert(hash_equals($freshHash, $migHash), 'fresh_equals_migrate_manifest', $rawFail, $pass);

// orphan reject: drop actor FK, plant orphan pointer, gate must SIGNAL
if (orange_control_identity_fk_exists($migPdo, 'ctrl_countries', 'fk_ctrl_countries_created_by')) {
    $migPdo->exec('ALTER TABLE ctrl_countries DROP FOREIGN KEY fk_ctrl_countries_created_by');
}
$migPdo->exec(
    "INSERT INTO ctrl_countries (
        country_uuid, code, slug, name_ar, name_en, currency_code, timezone,
        lifecycle_status, sort_order, created_at, updated_at, created_by_admin_id
     ) VALUES (
        'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'qa', 'qatar', 'قطر', 'Qatar', 'QAR', 'Asia/Qatar',
        'draft', 20, NOW(6), NOW(6), 999999
     )"
);
$orphanDenied = false;
try {
    orange_control_identity_orphan_gate($migPdo);
} catch (OrangeControlTrustException $e) {
    $orphanDenied = ($e->errorCode() === 'identity_actor_orphan_detected');
}
c_assert($orphanDenied, 'orphan_gate_fail_closed', $rawFail, $pass);

// discard migrate disposable
$admin->exec('DROP DATABASE IF EXISTS `' . $migDb . '`');
foreach (['localhost', '127.0.0.1'] as $hi) {
    try {
        $admin->exec("DROP USER IF EXISTS '{$migSa}'@'{$hi}'");
    } catch (Throwable $e) {
        // ignore
    }
}
$admin->exec('FLUSH PRIVILEGES');
@unlink($instPath);
@unlink($tmpDir . '/control_schema.php');
@unlink($tmpDir . '/control_fingerprint.php');
@rmdir($tmpDir);

// Allowlist: PLAN A six paths tracked vs Phase-2.1A parent; repair touches 5 vs candidate tip.
$expectedVs21a = [
    'includes/control_identity_schema.php',
    'includes/control_schema.php',
    'scripts/multidb/phase21b1_local_bootstrap.php',
    'scripts/multidb/phase21b1_local_cleanup.php',
    'scripts/self_test_multidb_phase21b1_identity_constraints.php',
    'scripts/self_test_multidb_phase21b1_identity_schema.php',
];
exec('git -C ' . escapeshellarg($repoRoot) . ' diff --name-only d95580bd --', $diffVs21a, $diffCode);
$diffVs21a = array_values(array_filter(array_map('trim', $diffVs21a)));
sort($diffVs21a);
sort($expectedVs21a);
c_assert($diffVs21a === $expectedVs21a, 'allowlist_plan_a_six_vs_phase21a', $rawFail, $pass);

$expectedVsCandidate = [
    'includes/control_identity_schema.php',
    'includes/control_schema.php',
    'scripts/multidb/phase21b1_local_bootstrap.php',
    'scripts/self_test_multidb_phase21b1_identity_constraints.php',
    'scripts/self_test_multidb_phase21b1_identity_schema.php',
];
exec(
    'git -C ' . escapeshellarg($repoRoot)
    . ' diff --name-only 8c0835456617117b6fd65409e30c124600768779 --',
    $diffVsCand,
    $diffCandCode
);
$diffVsCand = array_values(array_filter(array_map('trim', $diffVsCand)));
sort($diffVsCand);
sort($expectedVsCandidate);
c_assert($diffVsCand === $expectedVsCandidate, 'allowlist_repair_five_vs_candidate', $rawFail, $pass);
c_assert(
    !in_array('scripts/multidb/phase21b1_local_cleanup.php', $diffVsCand, true),
    'cleanup_php_byte_stable',
    $rawFail,
    $pass
);
c_assert(
    !in_array('includes/control_fingerprint.php', $diffVs21a, true)
    && !in_array('includes/control_fingerprint.php', $diffVsCand, true),
    'fingerprint_untouched',
    $rawFail,
    $pass
);
foreach ($expectedVs21a as $rel) {
    exec('git -C ' . escapeshellarg($repoRoot) . ' ls-files --error-unmatch -- ' . escapeshellarg($rel) . ' 2>&1', $lsOut, $lsCode);
    c_assert($lsCode === 0, 'allowlist_tracked_' . basename($rel), $rawFail, $pass);
}

exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($cleanup) . ' --run-id=' . escapeshellarg($runId) . ' 2>&1', $cOut, $cCode);
echo implode("\n", $cOut) . "\n";
c_assert($cCode === 0, 'cleanup_exit_0', $rawFail, $pass);

echo "PASS_COUNT={$pass}\n";
echo "RAW_FAIL={$rawFail}\n";
echo "CORE_SKIP={$coreSkip}\n";
echo "FALSE_GREEN_RISK_COUNT={$falseGreen}\n";
exit(($rawFail === 0 && $coreSkip === 0 && $falseGreen === 0) ? 0 : 1);
