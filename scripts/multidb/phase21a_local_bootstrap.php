<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1A S3 repair — local disposable Control Trust-Core bootstrap.
 *
 * Loopback MySQL only. Creates Control DB + three principals (× localhost + 127.0.0.1).
 * routine_definer co-located with schema_admin. Secrets only in %TEMP%. Never prints passwords.
 * No Country DB. No Production touch.
 *
 * Env:
 *   ORANGE_PHASE21A_MYSQL_ADMIN_USER (default: root)
 *   ORANGE_PHASE21A_MYSQL_ADMIN_PASS (default: empty)
 *   ORANGE_PHASE21A_MYSQL_HOST (default: 127.0.0.1)
 *   ORANGE_PHASE21A_MYSQL_PORT (default: 3306)
 *
 * Usage:
 *   php scripts/multidb/phase21a_local_bootstrap.php
 *   php scripts/multidb/phase21a_local_bootstrap.php --run-id=abc123
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$host = getenv('ORANGE_PHASE21A_MYSQL_HOST');
$host = ($host !== false && trim((string) $host) !== '') ? trim((string) $host) : '127.0.0.1';
$port = getenv('ORANGE_PHASE21A_MYSQL_PORT');
$port = ($port !== false && trim((string) $port) !== '') ? (int) $port : 3306;
$adminUser = getenv('ORANGE_PHASE21A_MYSQL_ADMIN_USER');
$adminUser = ($adminUser !== false && trim((string) $adminUser) !== '') ? trim((string) $adminUser) : 'root';
$adminPassEnv = getenv('ORANGE_PHASE21A_MYSQL_ADMIN_PASS');
$adminPass = ($adminPassEnv !== false) ? (string) $adminPassEnv : '';

$runId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--run-id=')) {
        $runId = substr($arg, 9);
    }
}

if ($runId === null || $runId === '') {
    $runId = bin2hex(random_bytes(6));
}
if (!preg_match('/^[a-zA-Z0-9]{6,32}$/', $runId)) {
    fwrite(STDERR, "INVALID_RUN_ID\n");
    exit(2);
}

function p21a_assert_local_host(string $host): void
{
    $h = strtolower(trim($host));
    if (!in_array($h, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "NON_LOCAL_HOST_REJECTED\n");
        echo "LOCAL_DATABASE_HOST_PROVEN=0\n";
        echo "PRODUCTION_DATABASE_HOST_USED=1\n";
        echo "REMOTE_DATABASE_HOST_USED=1\n";
        exit(3);
    }
}

function p21a_sql_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
        throw new RuntimeException('bad_ident');
    }

    return '`' . $name . '`';
}

function p21a_sql_string(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

function p21a_random_password(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '._'), '=');
}

/**
 * Attempt Windows ACL lockdown on secrets file; return status label.
 */
function p21a_attempt_secret_acl(string $path): string
{
    if (!is_file($path) || strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        return 'LOCAL_OS_SECRET_ACL_UNKNOWN';
    }
    $user = getenv('USERNAME');
    if ($user === false || trim($user) === '') {
        return 'LOCAL_OS_SECRET_ACL_UNKNOWN';
    }
    $cmd = 'icacls ' . escapeshellarg($path) . ' /inheritance:r /grant:r '
        . escapeshellarg($user . ':(R,W)') . ' 2>&1';
    $out = [];
    $code = 1;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        return 'LOCAL_OS_SECRET_ACL_UNKNOWN';
    }
    // Without an independent ACL read-back prover, do not claim multi-user secure.
    return 'LOCAL_OS_SECRET_ACL_UNKNOWN';
}

p21a_assert_local_host($host);

$controlDb = 'orange_phase21a_control_' . strtolower($runId);
$saUser = 'p21a_sa_' . strtolower($runId);
$prUser = 'p21a_pr_' . strtolower($runId);
$rtUser = 'p21a_rt_' . strtolower($runId);
$saPass = p21a_random_password();
$prPass = p21a_random_password();
$rtPass = p21a_random_password();
$hostIdents = ['localhost', '127.0.0.1'];
$ownershipToken = hash('sha256', random_bytes(32), false);

$tempDir = sys_get_temp_dir();
$statePath = $tempDir . DIRECTORY_SEPARATOR . 'orange_phase21a_state_' . $runId . '.json';
$secretsPath = $tempDir . DIRECTORY_SEPARATOR . 'orange_phase21a_secrets_' . $runId . '.json';

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/includes/control_schema.php';

try {
    $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    $admin = new PDO($adminDsn, $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $ver = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    $product = (stripos($ver, 'mariadb') !== false) ? 'MariaDB' : 'MySQL';
    $lctn = (string) $admin->query("SHOW VARIABLES LIKE 'lower_case_table_names'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $sqlMode = (string) $admin->query('SELECT @@sql_mode')->fetchColumn();

    // Capability probes (contained).
    $admin->exec('CREATE DATABASE IF NOT EXISTS orange_phase21a_capprobe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('USE orange_phase21a_capprobe');
    $admin->exec('DROP TABLE IF EXISTS t_gen_probe');
    $admin->exec("CREATE TABLE t_gen_probe (s VARCHAR(16) NOT NULL, g TINYINT AS (s = 'active') STORED) ENGINE=InnoDB");
    $generatedOk = 1;
    $admin->exec('DROP TABLE t_gen_probe');

    $admin->exec('DROP TABLE IF EXISTS t_col_probe');
    $admin->exec('CREATE TABLE t_col_probe (id INT PRIMARY KEY, a INT, b INT) ENGINE=InnoDB');
    $probeUser = 'p21a_cap_' . strtolower($runId);
    $probePass = p21a_random_password();
    $columnGrantOk = 0;
    $procedureOk = 0;
    $triggerSignalOk = 0;
    $grantExecuteOk = 0;
    try {
        foreach ($hostIdents as $hi) {
            $admin->exec('CREATE USER ' . p21a_sql_string($probeUser) . '@' . p21a_sql_string($hi)
                . ' IDENTIFIED BY ' . p21a_sql_string($probePass));
            $admin->exec('GRANT SELECT (id), UPDATE (a) ON orange_phase21a_capprobe.t_col_probe TO '
                . p21a_sql_string($probeUser) . '@' . p21a_sql_string($hi));
        }
        $admin->exec('FLUSH PRIVILEGES');
        $columnGrantOk = 1;
    } catch (Throwable $e) {
        $columnGrantOk = 0;
    }

    try {
        $admin->exec('DROP PROCEDURE IF EXISTS p_cap_definer');
        $admin->exec(
            "CREATE PROCEDURE p_cap_definer()
             SQL SECURITY DEFINER
             BEGIN
               SELECT CURRENT_USER() AS cu;
             END"
        );
        $definerRow = $admin->query(
            "SELECT DEFINER FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA='orange_phase21a_capprobe' AND ROUTINE_NAME='p_cap_definer'"
        )->fetch(PDO::FETCH_ASSOC);
        $procedureOk = is_array($definerRow) ? 1 : 0;

        $admin->exec('DROP TABLE IF EXISTS t_trig_probe');
        $admin->exec('CREATE TABLE t_trig_probe (id INT PRIMARY KEY, s VARCHAR(16) NOT NULL) ENGINE=InnoDB');
        $admin->exec(
            "CREATE TRIGGER trg_cap_reject BEFORE UPDATE ON t_trig_probe
             FOR EACH ROW
             BEGIN
               IF NEW.s = 'active' THEN
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'trust_mutation_forbidden_direct_sql';
               END IF;
             END"
        );
        $admin->exec("INSERT INTO t_trig_probe VALUES (1,'draft')");
        try {
            $admin->exec("UPDATE t_trig_probe SET s='active' WHERE id=1");
            $triggerSignalOk = 0;
        } catch (PDOException $e) {
            $triggerSignalOk = ((string) ($e->errorInfo[0] ?? '') === '45000') ? 1 : 0;
        }

        foreach ($hostIdents as $hi) {
            $admin->exec(
                'GRANT EXECUTE ON PROCEDURE orange_phase21a_capprobe.p_cap_definer TO '
                . p21a_sql_string($probeUser) . '@' . p21a_sql_string($hi)
            );
        }
        $admin->exec('FLUSH PRIVILEGES');
        $grantExecuteOk = 1;
    } catch (Throwable $e) {
        $procedureOk = 0;
        $triggerSignalOk = 0;
        $grantExecuteOk = 0;
    }

    foreach ($hostIdents as $hi) {
        try {
            $admin->exec('DROP USER IF EXISTS ' . p21a_sql_string($probeUser) . '@' . p21a_sql_string($hi));
        } catch (Throwable $e) {
            // ignore
        }
    }
    $admin->exec('DROP DATABASE IF EXISTS orange_phase21a_capprobe');

    echo "LOCAL_DATABASE_HOST_PROVEN=1\n";
    echo "PRODUCTION_DATABASE_HOST_USED=0\n";
    echo "REMOTE_DATABASE_HOST_USED=0\n";
    echo 'MYSQL_PRODUCT=' . $product . "\n";
    echo 'MYSQL_VERSION_LABEL=' . preg_replace('/[^A-Za-z0-9._-]/', '', $ver) . "\n";
    echo 'LOWER_CASE_TABLE_NAMES=' . $lctn . "\n";
    echo 'SQL_MODE_LABEL=' . preg_replace('/[^A-Za-z0-9_,]/', '', $sqlMode) . "\n";
    echo 'GENERATED_STORED_SUPPORT=' . $generatedOk . "\n";
    echo 'COLUMN_LEVEL_GRANT_SUPPORT=' . $columnGrantOk . "\n";
    echo 'PROCEDURE_DEFINER_SUPPORT=' . $procedureOk . "\n";
    echo 'TRIGGER_SIGNAL_SUPPORT=' . $triggerSignalOk . "\n";
    echo 'GRANT_EXECUTE_SUPPORT=' . $grantExecuteOk . "\n";
    echo "DEFINER_USES_CURRENT_USER_NOT_USER=1\n";
    echo "PRODUCTION_CAPABILITY=LIVE-SERVER-UNKNOWN\n";

    if ($generatedOk !== 1 || $procedureOk !== 1 || $triggerSignalOk !== 1 || $grantExecuteOk !== 1) {
        fwrite(STDERR, "PHASE21A_LOCAL_MYSQL_CAPABILITY_BLOCKER\n");
        exit(4);
    }

    // schema_admin — prefer Control-DB-scoped grants; fall back documented if *.* required for CREATE USER.
    $localOverbroadSa = 0;
    foreach ($hostIdents as $hi) {
        $admin->exec('CREATE USER ' . p21a_sql_string($saUser) . '@' . p21a_sql_string($hi)
            . ' IDENTIFIED BY ' . p21a_sql_string($saPass));
    }
    try {
        foreach ($hostIdents as $hi) {
            $u = p21a_sql_string($saUser) . '@' . p21a_sql_string($hi);
            $admin->exec("GRANT CREATE USER ON *.* TO {$u}");
            $admin->exec("GRANT RELOAD ON *.* TO {$u}");
        }
        $admin->exec('FLUSH PRIVILEGES');
        // CREATE DATABASE still needs privilege — grant via admin then ALL on control DB after create.
    } catch (Throwable $e) {
        $localOverbroadSa = 1;
        foreach ($hostIdents as $hi) {
            $admin->exec('GRANT ALL PRIVILEGES ON *.* TO ' . p21a_sql_string($saUser) . '@' . p21a_sql_string($hi)
                . ' WITH GRANT OPTION');
        }
        $admin->exec('FLUSH PRIVILEGES');
    }

    // Local MySQL with binary logging: allow DEFINER routines/triggers without SUPER.
    // Documented local-only; LIVE-SERVER-UNKNOWN for Production.
    $binlogTrust = 0;
    try {
        $admin->exec('SET GLOBAL log_bin_trust_function_creators = 1');
        $binlogTrust = 1;
    } catch (Throwable $e) {
        $binlogTrust = 0;
    }

    // Admin creates DB (narrow path) then hands to SA.
    $admin->exec('CREATE DATABASE ' . p21a_sql_ident($controlDb) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    foreach ($hostIdents as $hi) {
        $u = p21a_sql_string($saUser) . '@' . p21a_sql_string($hi);
        $db = p21a_sql_ident($controlDb);
        $admin->exec("GRANT ALL PRIVILEGES ON {$db}.* TO {$u} WITH GRANT OPTION");
        // TRIGGER + CREATE ROUTINE on schema (required with binary logging when not SUPER).
        $admin->exec("GRANT TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON {$db}.* TO {$u}");
    }
    $admin->exec('FLUSH PRIVILEGES');

    $sa = new PDO($adminDsn, $saUser, $saPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sa->exec('USE ' . p21a_sql_ident($controlDb));
    if ($binlogTrust !== 1) {
        // Fall back: install as admin so DEFINER=admin, then still fail-closed if SA cannot own routines.
        fwrite(STDERR, "PHASE21A_LOCAL_MYSQL_CAPABILITY_BLOCKER binlog_trust\n");
        exit(4);
    }
    orange_control_ensure_schema($sa, [
        'ownership_token' => $ownershipToken,
        'ownership_run_id' => strtolower($runId),
        'installed_by' => 'phase21a_bootstrap',
        'allow_meta_insert' => true,
    ]);

    $physHash = orange_control_physical_manifest_hash($sa, $controlDb);

    orange_control_seed_reserved_names($sa, [
        $controlDb => 'control',
        'orange_db' => 'legacy_orange',
        'mysql' => 'system',
        'information_schema' => 'system',
        'performance_schema' => 'system',
        'sys' => 'system',
    ]);

    // trust_executor (p21a_pr_*) + control_runtime (p21a_rt_*)
    foreach (
        [
            [$prUser, $prPass],
            [$rtUser, $rtPass],
        ] as [$u, $p]
    ) {
        foreach ($hostIdents as $hi) {
            $sa->exec('CREATE USER ' . p21a_sql_string($u) . '@' . p21a_sql_string($hi)
                . ' IDENTIFIED BY ' . p21a_sql_string($p));
        }
    }

    $db = p21a_sql_ident($controlDb);
    foreach ($hostIdents as $hi) {
        $u = p21a_sql_string($prUser) . '@' . p21a_sql_string($hi);
        // SELECT everywhere; no direct trust DML on sensitive columns/tables.
        $sa->exec("GRANT SELECT ON {$db}.* TO {$u}");
        $sa->exec("GRANT INSERT ON {$db}.ctrl_countries TO {$u}");
        $sa->exec("GRANT INSERT, UPDATE ON {$db}.ctrl_db_host_profiles TO {$u}");
        $sa->exec("GRANT INSERT ON {$db}.ctrl_reserved_database_names TO {$u}");
        $sa->exec("GRANT INSERT, UPDATE ON {$db}.ctrl_secret_refs TO {$u}");
        $sa->exec("GRANT INSERT, UPDATE ON {$db}.ctrl_runtime_principals TO {$u}");
        // Health columns only on registry (no registry_status / database_uuid UPDATE).
        $sa->exec(
            "GRANT UPDATE (health_status, failure_code, last_health_at, last_verified_at)
             ON {$db}.ctrl_country_db_registry TO {$u}"
        );
        $sa->exec("GRANT EXECUTE ON PROCEDURE {$db}.orange_ctrl_register_mapping TO {$u}");
        $sa->exec("GRANT EXECUTE ON PROCEDURE {$db}.orange_ctrl_registry_trust_transition TO {$u}");
        $sa->exec("GRANT EXECUTE ON PROCEDURE {$db}.orange_ctrl_country_lifecycle_transition TO {$u}");
        $sa->exec("GRANT EXECUTE ON PROCEDURE {$db}.orange_ctrl_emit_telemetry TO {$u}");
    }

    foreach ($hostIdents as $hi) {
        $u = p21a_sql_string($rtUser) . '@' . p21a_sql_string($hi);
        $sa->exec("GRANT SELECT ON {$db}.* TO {$u}");
        $sa->exec("GRANT INSERT ON {$db}.ctrl_audit_events TO {$u}");
        $sa->exec("GRANT EXECUTE ON PROCEDURE {$db}.orange_ctrl_emit_telemetry TO {$u}");
    }
    $sa->exec('FLUSH PRIVILEGES');

    $grantModel = 'definer_routines_execute;pr_no_direct_trust_dml;rt_select_plus_audit_telemetry;health_column_update_only;no_orange_db;no_ddl_for_pr_rt';

    $state = [
        'run_id' => $runId,
        'host' => $host,
        'port' => $port,
        'control_db' => $controlDb,
        'schema_admin_user' => $saUser,
        'provisioner_user' => $prUser,
        'trust_executor_user' => $prUser,
        'control_runtime_user' => $rtUser,
        'routine_definer_colocated_with_sa' => 1,
        'host_idents' => $hostIdents,
        'mysql_product' => $product,
        'mysql_version' => $ver,
        'lower_case_table_names' => $lctn,
        'sql_mode' => $sqlMode,
        'generated_stored_support' => $generatedOk,
        'column_level_grant_support' => $columnGrantOk,
        'procedure_definer_support' => $procedureOk,
        'trigger_signal_support' => $triggerSignalOk,
        'grant_execute_support' => $grantExecuteOk,
        'local_overbroad_sa' => $localOverbroadSa,
        'grant_model' => $grantModel,
        'control_schema_revision' => ORANGE_CONTROL_SCHEMA_REVISION,
        'schema_manifest_hash' => $physHash,
        'ownership_token' => $ownershipToken,
        'state_path' => $statePath,
        'secrets_path' => $secretsPath,
        'country_database_created_count' => 0,
        'created_at' => gmdate('c'),
    ];
    $secrets = [
        'run_id' => $runId,
        'schema_admin_password' => $saPass,
        'provisioner_password' => $prPass,
        'trust_executor_password' => $prPass,
        'control_runtime_password' => $rtPass,
        'ownership_token' => $ownershipToken,
        'admin_user_used_for_bootstrap' => $adminUser,
    ];

    file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    file_put_contents($secretsPath, json_encode($secrets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $aclStatus = p21a_attempt_secret_acl($secretsPath);

    echo 'RUN_ID=' . $runId . "\n";
    echo 'CONTROL_DB=' . $controlDb . "\n";
    echo 'SCHEMA_ADMIN_USER=' . $saUser . "\n";
    echo 'PROVISIONER_USER=' . $prUser . "\n";
    echo 'TRUST_EXECUTOR_USER=' . $prUser . "\n";
    echo 'CONTROL_RUNTIME_USER=' . $rtUser . "\n";
    echo "ROUTINE_DEFINER_COLOCATED_SA=1\n";
    echo 'STATE_PATH=' . $statePath . "\n";
    echo 'SECRETS_PATH=' . $secretsPath . "\n";
    echo 'CONTROL_SCHEMA_REVISION=' . ORANGE_CONTROL_SCHEMA_REVISION . "\n";
    echo 'SCHEMA_MANIFEST_HASH=' . $physHash . "\n";
    echo "COUNTRY_DATABASE_CREATED_COUNT=0\n";
    echo 'GRANT_MODEL=' . $grantModel . "\n";
    echo 'LOCAL_OVERBROAD_SA=' . $localOverbroadSa . "\n";
    echo 'LOCAL_BINLOG_TRUST_FUNCTION_CREATORS=' . $binlogTrust . "\n";
    echo 'LOCAL_OS_SECRET_ACL_STATUS=' . $aclStatus . "\n";
    echo "SECRET_OUTPUT_EXPOSURE_COUNT=0\n";
    echo "BOOTSTRAP_OK=1\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'BOOTSTRAP_FAIL code=' . $e->getMessage() . "\n");
    exit(1);
}
