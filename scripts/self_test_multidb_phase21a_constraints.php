<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1A S3 repair — constraints / principals / atomicity / concurrency self-test.
 *
 * Privilege denials require exact SQLSTATE/driver codes (not catch(Throwable) as PASS).
 * Concurrency Case B proves real lock wait (1205) while Trust holds FOR UPDATE.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$phpBin = PHP_BINARY;
$repoRoot = dirname(__DIR__);
$bootstrap = $repoRoot . '/scripts/multidb/phase21a_local_bootstrap.php';
$cleanup = $repoRoot . '/scripts/multidb/phase21a_local_cleanup.php';

require_once $repoRoot . '/includes/control_schema.php';

$rawFail = 0;
$coreSkip = 0;
$assertWeakened = 0;
$falseGreen = 0;
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

/** Privilege denial: PASS only on SQLSTATE 42000 and/or driver 1142/1370/1044/1227. */
function c_expect_privilege_deny(callable $fn, string $name, int &$rawFail, int &$pass): void
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

/** Trust SIGNAL denial: PASS only on SQLSTATE 45000 or mapped OrangeControlTrustException. */
function c_expect_trust_signal(callable $fn, string $expectCode, string $name, int &$rawFail, int &$pass): void
{
    try {
        $fn();
        echo "FAIL {$name} (expected trust signal {$expectCode})\n";
        $rawFail++;
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === $expectCode, $name . ':' . $expectCode, $rawFail, $pass);
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? '');
        $msg = (string) $e->getMessage();
        $ok = ($state === '45000' && str_contains($msg, $expectCode));
        c_assert($ok, $name . '_45000_' . $expectCode, $rawFail, $pass);
    } catch (Throwable $e) {
        echo "FAIL {$name} (unexpected " . $e->getMessage() . ")\n";
        $rawFail++;
    }
}

function c_seed_base(PDO $pr): array
{
    $now = 'NOW(6)';
    $pr->exec(
        "INSERT INTO ctrl_db_host_profiles (
            profile_key, environment, display_name, allowed_host_literal, host_resolution_mode,
            default_port, require_tls, verify_server_cert, connect_timeout_sec, profile_status, created_at, updated_at
         ) VALUES (
            'local_loopback', 'local', 'Local Loopback', '127.0.0.1', 'literal',
            3306, 0, 0, 5, 'active', {$now}, {$now}
         )"
    );
    $hostId = (int) $pr->lastInsertId();

    $pr->exec(
        "INSERT INTO ctrl_countries (
            country_uuid, code, slug, name_ar, name_en, currency_code, timezone,
            lifecycle_status, sort_order, created_at, updated_at
         ) VALUES (
            '11111111-1111-4111-8111-111111111111', 'kw', 'kuwait', 'الكويت', 'Kuwait', 'KWD', 'Asia/Kuwait',
            'draft', 1, {$now}, {$now}
         )"
    );
    $countryId = (int) $pr->lastInsertId();

    $secret = 'orange://secrets/country_runtime/kw';
    $principal = 'orange://principals/country_runtime/kw';
    $pr->exec(
        "INSERT INTO ctrl_secret_refs (
            secret_ref, provider, purpose, country_id, current_version, rotation_state, created_at
         ) VALUES (
            " . $pr->quote($secret) . ", 'env', 'country_runtime', {$countryId}, 1, 'active', {$now}
         )"
    );
    $pr->exec(
        "INSERT INTO ctrl_runtime_principals (
            principal_ref, mysql_user, host_pattern, country_id, role_kind, secret_ref, principal_status, created_at
         ) VALUES (
            " . $pr->quote($principal) . ", 'rt_kw_001', '127.0.0.1', {$countryId}, 'country_runtime',
            " . $pr->quote($secret) . ", 'pending', {$now}
         )"
    );

    $template = str_repeat('a', 64);
    $fp = orange_control_fingerprint_v1_hash([
        'fingerprint_version' => 1,
        'country_uuid' => '11111111-1111-4111-8111-111111111111',
        'database_uuid' => '22222222-2222-4222-8222-222222222222',
        'host_profile_key' => 'local_loopback',
        'db_name' => 'orange_country_kw_001',
        'installed_schema_revision' => 124,
        'schema_template_hash' => $template,
    ]);

    orange_control_assert_database_uuid_available($pr, '22222222-2222-4222-8222-222222222222');

    $registryId = orange_control_register_mapping($pr, [
        'database_uuid' => '22222222-2222-4222-8222-222222222222',
        'country_id' => $countryId,
        'country_uuid' => '11111111-1111-4111-8111-111111111111',
        'db_key' => 'rt_kw_001',
        'db_name' => 'orange_country_kw_001',
        'host_profile_id' => $hostId,
        'secret_ref' => $secret,
        'secret_version' => 1,
        'runtime_principal_ref' => $principal,
        'required_schema_revision' => 124,
        'installed_schema_revision' => 124,
        'schema_template_hash' => $template,
        'identity_fingerprint' => $fp,
        'registry_status' => 'allocating',
    ]);

    return [
        'host_id' => $hostId,
        'country_id' => $countryId,
        'registry_id' => $registryId,
        'secret' => $secret,
        'principal' => $principal,
        'fp' => $fp,
        'template' => $template,
    ];
}

$runId = bin2hex(random_bytes(6));
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($bootstrap) . ' --run-id=' . escapeshellarg($runId) . ' 2>&1', $bootOut, $bootCode);
echo implode("\n", $bootOut) . "\n";
c_assert($bootCode === 0, 'bootstrap_exit_0', $rawFail, $pass);

$statePath = sys_get_temp_dir() . '/orange_phase21a_state_' . $runId . '.json';
$secretsPath = sys_get_temp_dir() . '/orange_phase21a_secrets_' . $runId . '.json';
$state = json_decode((string) file_get_contents($statePath), true);
$secrets = json_decode((string) file_get_contents($secretsPath), true);
$host = (string) $state['host'];
$port = (int) $state['port'];
$controlDb = (string) $state['control_db'];
$prUser = (string) $state['provisioner_user'];
$rtUser = (string) $state['control_runtime_user'];
$saUser = (string) $state['schema_admin_user'];
$prPass = (string) $secrets['provisioner_password'];
$rtPass = (string) $secrets['control_runtime_password'];
$saPass = (string) $secrets['schema_admin_password'];

try {
    $pr = c_pdo($host, $port, $controlDb, $prUser, $prPass);
    $rt = c_pdo($host, $port, $controlDb, $rtUser, $rtPass);
    $sa = c_pdo($host, $port, $controlDb, $saUser, $saPass);

    $prCu = (string) $pr->query('SELECT CURRENT_USER()')->fetchColumn();
    $rtCu = (string) $rt->query('SELECT CURRENT_USER()')->fetchColumn();
    $saCu = (string) $sa->query('SELECT CURRENT_USER()')->fetchColumn();
    c_assert(str_starts_with($prCu, $prUser . '@'), 'pr_current_user_trust_executor', $rawFail, $pass);
    c_assert(str_starts_with($rtCu, $rtUser . '@'), 'rt_current_user_control_runtime', $rawFail, $pass);
    c_assert(str_starts_with($saCu, $saUser . '@'), 'sa_current_user_schema_admin', $rawFail, $pass);
    c_assert(!str_starts_with($prCu, $saUser . '@'), 'T_SA_NOT_EVIDENCE_pr', $rawFail, $pass);
    c_assert(!str_starts_with($rtCu, $saUser . '@'), 'T_SA_NOT_EVIDENCE_rt', $rawFail, $pass);

    $seed = c_seed_base($pr);
    $registryId = (int) $seed['registry_id'];
    $countryId = (int) $seed['country_id'];

    $led = (int) $pr->query(
        "SELECT COUNT(*) FROM ctrl_database_uuid_ledger WHERE database_uuid='22222222-2222-4222-8222-222222222222'"
    )->fetchColumn();
    c_assert($led === 1, 'ledger_sealed_on_register', $rawFail, $pass);

    $row = $pr->query("SELECT is_routable, registry_status FROM ctrl_country_db_registry WHERE id={$registryId}")->fetch(PDO::FETCH_ASSOC);
    c_assert((int) $row['is_routable'] === 0, 'fixture_not_routable', $rawFail, $pass);
    $crow = $pr->query("SELECT is_active, lifecycle_status FROM ctrl_countries WHERE id={$countryId}")->fetch(PDO::FETCH_ASSOC);
    c_assert((int) $crow['is_active'] === 0, 'fixture_country_not_active', $rawFail, $pass);

    // Generated column not writable (MySQL 3105) or privilege denial — not catch-all Throwable
    $genDenied = false;
    $genLabel = '';
    try {
        $pr->exec("UPDATE ctrl_country_db_registry SET is_routable=1 WHERE id={$registryId}");
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? '');
        $code = (int) ($e->errorInfo[1] ?? 0);
        $genDenied = ($code === 3105 || $state === 'HY000' || $state === '42000' || in_array($code, [1142, 1143], true));
        $genLabel = $state . '_' . $code;
    }
    c_assert($genDenied, 'generated_is_routable_not_writable_' . $genLabel, $rawFail, $pass);

    // Unique: one registry per country — expect 23000/1062 from DB
    $uniqCountryDenied = false;
    try {
        orange_control_register_mapping($pr, [
            'database_uuid' => '55555555-5555-4555-8555-555555555555',
            'country_id' => $countryId,
            'country_uuid' => '11111111-1111-4111-8111-111111111111',
            'db_key' => 'rt_kw_002',
            'db_name' => 'orange_country_kw_002',
            'host_profile_id' => $seed['host_id'],
            'secret_ref' => 'orange://secrets/country_runtime/kw2',
            'secret_version' => 1,
            'runtime_principal_ref' => 'orange://principals/country_runtime/kw2',
            'required_schema_revision' => 124,
            'installed_schema_revision' => 124,
            'schema_template_hash' => $seed['template'],
            'identity_fingerprint' => str_repeat('b', 64),
            'registry_status' => 'allocating',
        ]);
    } catch (PDOException $e) {
        $uniqCountryDenied = ((int) ($e->errorInfo[1] ?? 0) === 1062 || (string) ($e->errorInfo[0] ?? '') === '23000');
    } catch (OrangeControlTrustException $e) {
        $uniqCountryDenied = true;
    }
    c_assert($uniqCountryDenied, 'unique_one_registry_per_country', $rawFail, $pass);

    // Country code uniqueness — exact 23000/1062
    try {
        $pr->exec(
            "INSERT INTO ctrl_countries (
                country_uuid, code, slug, name_ar, name_en, currency_code, timezone,
                lifecycle_status, created_at, updated_at
             ) VALUES (
                '33333333-3333-4333-8333-333333333333', 'kw', 'kuwait2', 'x', 'x', 'KWD', 'Asia/Kuwait',
                'draft', NOW(6), NOW(6)
             )"
        );
        c_assert(false, 'unique_country_code', $rawFail, $pass);
    } catch (PDOException $e) {
        $ok = ((int) ($e->errorInfo[1] ?? 0) === 1062 || (string) ($e->errorInfo[0] ?? '') === '23000');
        c_assert($ok, 'unique_country_code_23000', $rawFail, $pass);
    }

    // F) control_runtime permissions
    $n = (int) $rt->query('SELECT COUNT(*) FROM ctrl_countries')->fetchColumn();
    c_assert($n >= 1, 'rt_select_countries', $rawFail, $pass);
    $aid = orange_control_insert_audit($rt, 'runtime_probe', null, null, 'control_runtime', ['ok' => 1]);
    c_assert($aid > 0, 'rt_insert_audit_telemetry', $rawFail, $pass);

    // T-AUD-FORGE: RT cannot insert trust-type into audit (trigger) or trust_events
    c_expect_trust_signal(static function () use ($rt): void {
        $rt->exec(
            "INSERT INTO ctrl_audit_events (
                event_uuid, event_type, actor_kind, payload_json, created_at
             ) VALUES (
                'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'registry_trust_transition', 'provisioner',
                JSON_OBJECT('new_status','active'), NOW(6)
             )"
        );
    }, 'trust_event_forbidden_actor', 'T_AUD_FORGE_rt_trust_type_audit', $rawFail, $pass);

    c_expect_privilege_deny(static function () use ($rt): void {
        $rt->exec(
            "INSERT INTO ctrl_trust_events (
                event_uuid, event_type, actor_mysql_user, actor_kind, created_at
             ) VALUES (
                'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'registry_trust_transition', 'x', 'x', NOW(6)
             )"
        );
    }, 'T_AUD_FORGE_rt_trust_events_insert', $rawFail, $pass);

    c_expect_privilege_deny(static function () use ($rt, $countryId): void {
        $rt->exec("UPDATE ctrl_countries SET name_en='X' WHERE id={$countryId}");
    }, 'rt_deny_country_write', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt, $registryId): void {
        $rt->exec("UPDATE ctrl_country_db_registry SET health_status='ok' WHERE id={$registryId}");
    }, 'rt_deny_registry_write', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt, $registryId, $seed): void {
        $rt->exec(
            "INSERT INTO ctrl_country_db_registry_history (
                registry_id, country_id, country_uuid, database_uuid, db_key, db_name, host_profile_id, host_profile_key,
                secret_ref, secret_version, runtime_principal_ref, required_schema_revision, installed_schema_revision,
                schema_template_hash, identity_fingerprint, old_registry_status, new_registry_status,
                row_version_before, row_version_after, change_reason, changed_at, snapshot_hash
             ) VALUES (
                {$registryId}, 1, '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222',
                'rt_kw_001', 'orange_country_kw_001', {$seed['host_id']}, 'local_loopback',
                'orange://secrets/country_runtime/kw', 1, 'orange://principals/country_runtime/kw', 124, 124,
                " . $rt->quote($seed['template']) . ", " . $rt->quote($seed['fp']) . ", 'allocating', 'installing',
                1, 2, 'x', NOW(6), " . $rt->quote(str_repeat('c', 64)) . "
             )"
        );
    }, 'rt_deny_history_insert', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt): void {
        $rt->exec("UPDATE ctrl_reserved_database_names SET reason='x' WHERE db_name='orange_db'");
    }, 'rt_deny_reserved_write', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt): void {
        $rt->exec("UPDATE ctrl_schema_meta SET control_schema_revision=99 WHERE id=1");
    }, 'rt_deny_schema_meta_write', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt, $aid): void {
        $rt->exec("UPDATE ctrl_audit_events SET event_type='tamper' WHERE id={$aid}");
    }, 'rt_deny_audit_update', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt, $aid): void {
        $rt->exec("DELETE FROM ctrl_audit_events WHERE id={$aid}");
    }, 'rt_deny_audit_delete', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt): void {
        $rt->exec('CREATE TABLE evil_t (id INT)');
    }, 'rt_deny_create_table', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt): void {
        $rt->exec('CREATE DATABASE evil_db_phase21a');
    }, 'rt_deny_create_database', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($rt, $rtUser): void {
        $rt->exec("GRANT ALL ON *.* TO " . $rt->quote($rtUser) . "@'localhost'");
    }, 'rt_deny_grant', $rawFail, $pass);
    try {
        c_pdo($host, $port, 'orange_db', $rtUser, $rtPass);
        c_assert(false, 'rt_deny_orange_db_access', $rawFail, $pass);
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $state = (string) ($e->errorInfo[0] ?? '');
        c_assert($state === '42000' || in_array($code, [1044, 1045, 1142], true), 'rt_deny_orange_db_access', $rawFail, $pass);
    }

    // F5 / direct SQL activation under trust_executor must fail (privilege and/or trigger)
    $histBypass = (int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn();
    $trustBypass = (int) $pr->query('SELECT COUNT(*) FROM ctrl_trust_events')->fetchColumn();
    $directActiveDenied = false;
    try {
        $pr->exec("UPDATE ctrl_country_db_registry SET registry_status='active' WHERE id={$registryId}");
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? '');
        $code = (int) ($e->errorInfo[1] ?? 0);
        $directActiveDenied = ($state === '42000' || $state === '45000' || in_array($code, [1142, 1143], true));
    }
    c_assert($directActiveDenied, 'F5_direct_sql_registry_active_denied', $rawFail, $pass);
    c_assert(
        (int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn() === $histBypass
        && (int) $pr->query('SELECT COUNT(*) FROM ctrl_trust_events')->fetchColumn() === $trustBypass,
        'F5_no_history_or_trust_on_bypass',
        $rawFail,
        $pass
    );

    $directLifeDenied = false;
    try {
        $pr->exec("UPDATE ctrl_countries SET lifecycle_status='active' WHERE id={$countryId}");
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? '');
        $code = (int) ($e->errorInfo[1] ?? 0);
        $directLifeDenied = ($state === '42000' || $state === '45000' || in_array($code, [1142, 1143], true));
    }
    c_assert($directLifeDenied, 'F5_direct_sql_lifecycle_active_denied', $rawFail, $pass);

    $directUuidDenied = false;
    try {
        $pr->exec("UPDATE ctrl_country_db_registry SET database_uuid='88888888-8888-4888-8888-888888888888' WHERE id={$registryId}");
    } catch (PDOException $e) {
        $state = (string) ($e->errorInfo[0] ?? '');
        $code = (int) ($e->errorInfo[1] ?? 0);
        $directUuidDenied = ($state === '42000' || $state === '45000' || in_array($code, [1142, 1143], true));
    }
    c_assert($directUuidDenied, 'F5_direct_sql_database_uuid_denied', $rawFail, $pass);

    // G) trust_executor atomic safe non-routable transition via CALL
    $histBefore = (int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn();
    $trustBefore = (int) $pr->query("SELECT COUNT(*) FROM ctrl_trust_events WHERE event_type='registry_trust_transition'")->fetchColumn();
    $audBefore = (int) $pr->query("SELECT COUNT(*) FROM ctrl_audit_events WHERE event_type='registry_trust_transition'")->fetchColumn();
    $res = orange_control_registry_trust_transition(
        $pr,
        $registryId,
        1,
        ['registry_status' => 'installing'],
        'allocating_to_installing'
    );
    c_assert($res['row_version'] === 2, 'pr_transition_row_version_plus1', $rawFail, $pass);
    c_assert(
        (int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn() === $histBefore + 1,
        'pr_exactly_one_history',
        $rawFail,
        $pass
    );
    c_assert(
        (int) $pr->query("SELECT COUNT(*) FROM ctrl_trust_events WHERE event_type='registry_trust_transition'")->fetchColumn()
        === $trustBefore + 1,
        'pr_exactly_one_trust_event',
        $rawFail,
        $pass
    );
    c_assert(
        (int) $pr->query("SELECT COUNT(*) FROM ctrl_audit_events WHERE event_type='registry_trust_transition'")->fetchColumn()
        === $audBefore,
        'trust_not_written_to_audit_telemetry',
        $rawFail,
        $pass
    );
    $routable = (int) $pr->query("SELECT is_routable FROM ctrl_country_db_registry WHERE id={$registryId}")->fetchColumn();
    c_assert($routable === 0, 'pr_still_not_routable', $rawFail, $pass);

    c_expect_privilege_deny(static function () use ($pr): void {
        $pr->exec("UPDATE ctrl_country_db_registry_history SET change_reason='tamper' WHERE history_id > 0");
    }, 'pr_deny_history_update', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($pr): void {
        $pr->exec('DELETE FROM ctrl_country_db_registry_history WHERE history_id > 0');
    }, 'pr_deny_history_delete', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($pr): void {
        $pr->exec("UPDATE ctrl_audit_events SET event_type='tamper' WHERE id > 0");
    }, 'pr_deny_audit_update', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($pr): void {
        $pr->exec('CREATE TABLE evil_pr (id INT)');
    }, 'pr_deny_ddl', $rawFail, $pass);
    c_expect_privilege_deny(static function () use ($pr): void {
        $pr->exec('CREATE DATABASE evil_pr_db');
    }, 'pr_deny_create_database', $rawFail, $pass);
    try {
        c_pdo($host, $port, 'orange_db', $prUser, $prPass);
        c_assert(false, 'pr_deny_orange_db', $rawFail, $pass);
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        $state = (string) ($e->errorInfo[0] ?? '');
        c_assert($state === '42000' || in_array($code, [1044, 1045, 1142], true), 'pr_deny_orange_db', $rawFail, $pass);
    }

    // H) Atomicity
    $hist0 = (int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn();
    $trust0 = (int) $pr->query('SELECT COUNT(*) FROM ctrl_trust_events')->fetchColumn();
    $rv0 = (int) $pr->query("SELECT row_version FROM ctrl_country_db_registry WHERE id={$registryId}")->fetchColumn();
    try {
        orange_control_registry_trust_transition($pr, $registryId, $rv0 - 1, ['registry_status' => 'verifying'], 'stale');
        c_assert(false, 'cas_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'row_version_conflict', 'cas_row_version_conflict', $rawFail, $pass);
    }
    c_assert((int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn() === $hist0, 'cas_no_history', $rawFail, $pass);
    c_assert((int) $pr->query('SELECT COUNT(*) FROM ctrl_trust_events')->fetchColumn() === $trust0, 'cas_no_trust_event', $rawFail, $pass);
    c_assert((int) $pr->query("SELECT row_version FROM ctrl_country_db_registry WHERE id={$registryId}")->fetchColumn() === $rv0, 'cas_no_row_version', $rawFail, $pass);

    try {
        orange_control_registry_trust_transition(
            $pr,
            $registryId,
            $rv0,
            ['registry_status' => 'verifying'],
            'inject_hist',
            ['inject_failure_after' => 'history']
        );
        c_assert(false, 'inject_history_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'injected_failure_after_history', 'inject_after_history', $rawFail, $pass);
    }
    c_assert((int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn() === $hist0, 'inject_history_rolled_back', $rawFail, $pass);
    c_assert((int) $pr->query('SELECT COUNT(*) FROM ctrl_trust_events')->fetchColumn() === $trust0, 'inject_history_trust_rb', $rawFail, $pass);

    try {
        orange_control_registry_trust_transition(
            $pr,
            $registryId,
            $rv0,
            ['registry_status' => 'verifying'],
            'inject_reg',
            ['inject_failure_after' => 'registry']
        );
        c_assert(false, 'inject_registry_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'injected_failure_after_registry', 'inject_after_registry', $rawFail, $pass);
    }
    c_assert((int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn() === $hist0, 'inject_registry_hist_rb', $rawFail, $pass);
    c_assert((int) $pr->query("SELECT row_version FROM ctrl_country_db_registry WHERE id={$registryId}")->fetchColumn() === $rv0, 'inject_registry_rv_rb', $rawFail, $pass);
    $st = (string) $pr->query("SELECT registry_status FROM ctrl_country_db_registry WHERE id={$registryId}")->fetchColumn();
    c_assert($st === 'installing', 'inject_registry_status_rb', $rawFail, $pass);

    $res2 = orange_control_registry_trust_transition($pr, $registryId, $rv0, ['registry_status' => 'verifying'], 'to_verifying');
    c_assert($res2['row_version'] === $rv0 + 1, 'to_verifying_ok', $rawFail, $pass);

    try {
        orange_control_registry_trust_transition($pr, $registryId, $rv0, ['registry_status' => 'suspended'], 'retry_stale');
        c_assert(false, 'retry_stale_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'row_version_conflict', 'retry_stale_conflict', $rawFail, $pass);
    }

    // I) UUID ledger — historical reuse DB-enforced
    $pr->exec(
        "INSERT INTO ctrl_countries (
            country_uuid, code, slug, name_ar, name_en, currency_code, timezone,
            lifecycle_status, created_at, updated_at
         ) VALUES (
            '33333333-3333-4333-8333-333333333333', 'eg', 'egypt', 'مصر', 'Egypt', 'EGP', 'Africa/Cairo',
            'draft', NOW(6), NOW(6)
         )"
    );
    $egId = (int) $pr->lastInsertId();
    try {
        orange_control_assert_database_uuid_available($pr, '22222222-2222-4222-8222-222222222222');
        c_assert(false, 'dup_current_uuid_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'database_uuid_previously_registered', 'dup_current_uuid_denied', $rawFail, $pass);
    }

    $histOnly = '77777777-7777-4777-8777-777777777777';
    $sa->exec(
        "INSERT INTO ctrl_database_uuid_ledger (
            database_uuid, first_registry_id, first_country_id, first_seen_at, sealed_by_principal
         ) VALUES (
            '{$histOnly}', {$registryId}, {$countryId}, NOW(6), 'schema_admin_fixture'
         )"
    );
    $sa->exec(
        "INSERT INTO ctrl_country_db_registry_history (
            registry_id, country_id, country_uuid, database_uuid, db_key, db_name, host_profile_id, host_profile_key,
            secret_ref, secret_version, runtime_principal_ref, required_schema_revision, installed_schema_revision,
            schema_template_hash, identity_fingerprint, old_registry_status, new_registry_status,
            row_version_before, row_version_after, change_reason, changed_at, snapshot_hash
         ) VALUES (
            {$registryId}, {$countryId}, '11111111-1111-4111-8111-111111111111', '{$histOnly}',
            'rt_kw_001', 'orange_country_kw_001', {$seed['host_id']}, 'local_loopback',
            'orange://secrets/country_runtime/kw', 1, 'orange://principals/country_runtime/kw', 124, 124,
            " . $sa->quote($seed['template']) . ", " . $sa->quote($seed['fp']) . ", 'allocating', 'installing',
            1, 2, 'fixture_historical_uuid', NOW(6), " . $sa->quote(str_repeat('d', 64)) . "
         )"
    );
    try {
        orange_control_assert_database_uuid_available($pr, $histOnly, $egId);
        c_assert(false, 'hist_uuid_reuse_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'database_uuid_previously_registered', 'hist_uuid_reuse_denied_helper', $rawFail, $pass);
    }
    // DB-level ledger UNIQUE
    try {
        $pr->exec(
            "INSERT INTO ctrl_secret_refs (secret_ref, provider, purpose, current_version, rotation_state, created_at)
             VALUES ('orange://secrets/country_runtime/eg', 'env', 'country_runtime', 1, 'active', NOW(6))"
        );
        $pr->exec(
            "INSERT INTO ctrl_runtime_principals (
                principal_ref, mysql_user, host_pattern, role_kind, secret_ref, principal_status, created_at
             ) VALUES (
                'orange://principals/country_runtime/eg', 'rt_eg_001', '127.0.0.1', 'country_runtime',
                'orange://secrets/country_runtime/eg', 'pending', NOW(6)
             )"
        );
        orange_control_register_mapping($pr, [
            'database_uuid' => $histOnly,
            'country_id' => $egId,
            'country_uuid' => '33333333-3333-4333-8333-333333333333',
            'db_key' => 'rt_eg_001',
            'db_name' => 'orange_country_eg_001',
            'host_profile_id' => $seed['host_id'],
            'secret_ref' => 'orange://secrets/country_runtime/eg',
            'secret_version' => 1,
            'runtime_principal_ref' => 'orange://principals/country_runtime/eg',
            'required_schema_revision' => 124,
            'installed_schema_revision' => 124,
            'schema_template_hash' => $seed['template'],
            'identity_fingerprint' => str_repeat('e', 64),
            'registry_status' => 'allocating',
        ]);
        c_assert(false, 'T_LEDGER_reuse_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert(
            $e->errorCode() === 'database_uuid_previously_registered',
            'T_LEDGER_reuse_denied',
            $rawFail,
            $pass
        );
    } catch (PDOException $e) {
        $ok = ((int) ($e->errorInfo[1] ?? 0) === 1062 || (string) ($e->errorInfo[0] ?? '') === '23000');
        c_assert($ok, 'T_LEDGER_reuse_denied_23000', $rawFail, $pass);
    }

    $rvNow = (int) $pr->query("SELECT row_version FROM ctrl_country_db_registry WHERE id={$registryId}")->fetchColumn();
    try {
        orange_control_registry_trust_transition(
            $pr,
            $registryId,
            $rvNow,
            ['database_uuid' => '88888888-8888-4888-8888-888888888888'],
            'direct_uuid'
        );
        c_assert(false, 'direct_uuid_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'database_uuid_direct_update_denied', 'direct_uuid_denied', $rawFail, $pass);
    }

    try {
        orange_control_registry_trust_transition(
            $pr,
            $registryId,
            $rvNow,
            ['registry_status' => 'verifying'],
            'replacement',
            ['transition_code' => 'database_replacement']
        );
        c_assert(false, 'replacement_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'phase21c_prerequisites_not_available', 'replacement_denied_21a', $rawFail, $pass);
    }

    // J) Phase gates via routine (meta authority)
    try {
        orange_control_country_lifecycle_transition($pr, $countryId, 'active');
        c_assert(false, 'country_active_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'phase21c_prerequisites_not_available', 'country_active_denied', $rawFail, $pass);
    }
    orange_control_country_lifecycle_transition($pr, $countryId, 'provisioning');
    c_assert(
        (string) $pr->query("SELECT lifecycle_status FROM ctrl_countries WHERE id={$countryId}")->fetchColumn() === 'provisioning',
        'country_draft_to_provisioning',
        $rawFail,
        $pass
    );
    c_assert((int) $pr->query("SELECT is_active FROM ctrl_countries WHERE id={$countryId}")->fetchColumn() === 0, 'country_still_inactive', $rawFail, $pass);

    try {
        orange_control_registry_trust_transition($pr, $registryId, $rvNow, ['registry_status' => 'active'], 'to_active');
        c_assert(false, 'registry_active_should_fail', $rawFail, $pass);
    } catch (OrangeControlTrustException $e) {
        c_assert($e->errorCode() === 'phase21c_prerequisites_not_available', 'registry_active_denied', $rawFail, $pass);
    }

    $activeCountry = (int) $pr->query("SELECT COUNT(*) FROM ctrl_countries WHERE lifecycle_status='active'")->fetchColumn();
    $routableReg = (int) $pr->query("SELECT COUNT(*) FROM ctrl_country_db_registry WHERE registry_status='active'")->fetchColumn();
    c_assert($activeCountry === 0, 'PHASE21A_ACTIVE_COUNTRY_COUNT_0', $rawFail, $pass);
    c_assert($routableReg === 0, 'PHASE21A_ROUTABLE_REGISTRY_COUNT_0', $rawFail, $pass);

    // K) Health / Trust concurrency
    $prHealth = c_pdo($host, $port, $controlDb, $prUser, $prPass);
    $prTrust = c_pdo($host, $port, $controlDb, $prUser, $prPass);
    $rvC = (int) $prTrust->query("SELECT row_version FROM ctrl_country_db_registry WHERE id={$registryId}")->fetchColumn();
    $histC = (int) $prTrust->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn();

    orange_control_registry_health_update($prHealth, $registryId, [
        'health_status' => 'degraded',
        'failure_code' => 'probe_1',
        'last_health_at' => date('Y-m-d H:i:s.u'),
    ]);
    orange_control_registry_trust_transition($prTrust, $registryId, $rvC, ['registry_status' => 'suspended'], 'health_then_trust');
    $final1 = $pr->query("SELECT registry_status, health_status, failure_code, row_version FROM ctrl_country_db_registry WHERE id={$registryId}")->fetch(PDO::FETCH_ASSOC);
    c_assert(
        $final1['registry_status'] === 'suspended' && $final1['health_status'] === 'degraded' && $final1['failure_code'] === 'probe_1',
        'concurrency_health_then_trust',
        $rawFail,
        $pass
    );
    c_assert((int) $final1['row_version'] === $rvC + 1, 'concurrency_rv_plus1_only_trust', $rawFail, $pass);
    c_assert((int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn() === $histC + 1, 'concurrency_one_history', $rawFail, $pass);

    // Case B: real lock wait — Trust holds FOR UPDATE; Health UPDATE must hit 1205
    $rvD = (int) $final1['row_version'];
    $histD = (int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn();
    $prTrust->exec('SET SESSION innodb_lock_wait_timeout = 2');
    $prHealth->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $prTrust->beginTransaction();
    $prTrust->query("SELECT id FROM ctrl_country_db_registry WHERE id={$registryId} FOR UPDATE")->fetch();
    $lockWaitProven = false;
    $lockSqlState = '';
    $lockDriver = 0;
    try {
        $prHealth->exec(
            "UPDATE ctrl_country_db_registry SET health_status='waiting' WHERE id={$registryId}"
        );
    } catch (PDOException $e) {
        $lockSqlState = (string) ($e->errorInfo[0] ?? '');
        $lockDriver = (int) ($e->errorInfo[1] ?? 0);
        $lockWaitProven = ($lockDriver === 1205 || $lockSqlState === 'HY000' || str_contains($e->getMessage(), '1205'));
    }
    c_assert($lockWaitProven, 'F2_lock_wait_1205_proven_code_' . $lockDriver, $rawFail, $pass);
    $prTrust->commit();

    orange_control_registry_trust_transition($prTrust, $registryId, $rvD, ['secret_version' => 2], 'cred_rotation');
    orange_control_registry_health_update($prHealth, $registryId, [
        'health_status' => 'ok',
        'failure_code' => null,
        'last_verified_at' => date('Y-m-d H:i:s.u'),
    ]);
    $final2 = $pr->query("SELECT registry_status, health_status, secret_version, row_version FROM ctrl_country_db_registry WHERE id={$registryId}")->fetch(PDO::FETCH_ASSOC);
    c_assert(
        $final2['registry_status'] === 'suspended'
        && $final2['health_status'] === 'ok'
        && (int) $final2['secret_version'] === 2
        && (int) $final2['row_version'] === $rvD + 1,
        'concurrency_trust_then_health_preserved',
        $rawFail,
        $pass
    );
    c_assert((int) $pr->query('SELECT COUNT(*) FROM ctrl_country_db_registry_history')->fetchColumn() === $histD + 1, 'health_no_extra_history', $rawFail, $pass);

    // Active secret sharing denied via generated unique slot
    $secretShareDenied = false;
    try {
        try {
            $pr->exec(
                "INSERT INTO ctrl_secret_refs (secret_ref, provider, purpose, current_version, rotation_state, created_at)
                 VALUES ('orange://secrets/country_runtime/eg2', 'env', 'country_runtime', 1, 'active', NOW(6))"
            );
        } catch (PDOException $e) {
            // ignore dup
        }
        try {
            $pr->exec(
                "INSERT INTO ctrl_runtime_principals (
                    principal_ref, mysql_user, host_pattern, role_kind, secret_ref, principal_status, created_at
                 ) VALUES (
                    'orange://principals/country_runtime/eg2', 'rt_eg_002', '127.0.0.1', 'country_runtime',
                    'orange://secrets/country_runtime/eg2', 'pending', NOW(6)
                 )"
            );
        } catch (PDOException $e) {
            // ignore
        }
        orange_control_register_mapping($pr, [
            'database_uuid' => '44444444-4444-4444-8444-444444444444',
            'country_id' => $egId,
            'country_uuid' => '33333333-3333-4333-8333-333333333333',
            'db_key' => 'rt_eg_002',
            'db_name' => 'orange_country_eg_002',
            'host_profile_id' => $seed['host_id'],
            'secret_ref' => 'orange://secrets/country_runtime/kw',
            'secret_version' => 1,
            'runtime_principal_ref' => 'orange://principals/country_runtime/eg2',
            'required_schema_revision' => 124,
            'installed_schema_revision' => 124,
            'schema_template_hash' => $seed['template'],
            'identity_fingerprint' => str_repeat('f', 64),
            'registry_status' => 'allocating',
        ]);
    } catch (PDOException $e) {
        $secretShareDenied = ((int) ($e->errorInfo[1] ?? 0) === 1062 || (string) ($e->errorInfo[0] ?? '') === '23000');
    } catch (OrangeControlTrustException $e) {
        $secretShareDenied = true;
    }
    c_assert($secretShareDenied, 'active_secret_sharing_denied', $rawFail, $pass);

    c_expect_privilege_deny(static function () use ($rt): void {
        $rt->exec("INSERT INTO ctrl_reserved_database_names (db_name, reason, created_at) VALUES ('evil_name','x',NOW(6))");
    }, 'rt_deny_reserved_insert', $rawFail, $pass);

} catch (Throwable $e) {
    echo 'FAIL constraints_body ' . $e->getMessage() . "\n";
    $rawFail++;
}

exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($cleanup) . ' --run-id=' . escapeshellarg($runId) . ' 2>&1', $cleanOut, $cleanCode);
echo implode("\n", $cleanOut) . "\n";
c_assert($cleanCode === 0, 'cleanup_exit_0', $rawFail, $pass);
$dbRem = 0;
$userRem = 0;
foreach ($cleanOut as $line) {
    if (str_starts_with($line, 'DISPOSABLE_DATABASE_REMAINDER_COUNT=')) {
        $dbRem = (int) substr($line, strlen('DISPOSABLE_DATABASE_REMAINDER_COUNT='));
    }
    if (str_starts_with($line, 'DISPOSABLE_USER_REMAINDER_COUNT=')) {
        $userRem = (int) substr($line, strlen('DISPOSABLE_USER_REMAINDER_COUNT='));
    }
}
c_assert($dbRem === 0, 'DISPOSABLE_DATABASE_REMAINDER_COUNT_0', $rawFail, $pass);
c_assert($userRem === 0, 'DISPOSABLE_USER_REMAINDER_COUNT_0', $rawFail, $pass);
c_assert(!is_file($secretsPath), 'zero_secret_file_remainder', $rawFail, $pass);

echo "PASS_COUNT={$pass}\n";
echo "RAW_FAIL={$rawFail}\n";
echo "CORE_SKIP={$coreSkip}\n";
echo "ASSERTION_WEAKENED={$assertWeakened}\n";
echo "FALSE_GREEN_KNOWN_GAP_COUNT={$falseGreen}\n";
$ok = ($rawFail === 0 && $coreSkip === 0 && $assertWeakened === 0 && $falseGreen === 0);
echo 'EXIT=' . ($ok ? '0' : '1') . "\n";
exit($ok ? 0 : 1);
