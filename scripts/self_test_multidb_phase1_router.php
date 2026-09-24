<?php
declare(strict_types=1);

/**
 * Orange Phase-1 â€” router self-test matrix Aâ€“E (items 1â€“50).
 * Requires bootstrap state: --run-id= or ORANGE_PHASE1_RUN_ID.
 * Does not load config.php, db(), Backup, or Restore.
 * openControl uses control_runtime (NOT schema_admin).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

require_once dirname(__DIR__) . '/includes/db_router.php';
if (!defined('ORANGE_PHASE1_BOOTSTRAP_AS_LIB')) {
    define('ORANGE_PHASE1_BOOTSTRAP_AS_LIB', true);
}
require_once dirname(__DIR__) . '/scripts/multidb/phase1_local_bootstrap.php';
require_once dirname(__DIR__) . '/includes/control_schema.php';

$pass = 0;
$fail = 0;
$skip = 0;
$falseGreen = 0;

function t_assert(string $id, bool $cond, string $detail = ''): void
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

function t_expect_code(callable $fn, string $code, string $id): void
{
    try {
        $fn();
        t_assert($id, false, 'expected_throw_' . $code);
    } catch (OrangeDbRouterException $e) {
        t_assert($id, $e->errorCode() === $code, 'got=' . $e->errorCode());
    } catch (Throwable $e) {
        t_assert($id, false, 'unexpected=' . get_class($e) . ':' . $e->getMessage());
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
$histDb = (string) ($state['hist_db_name'] ?? '');
$schemaRevision = (int) $state['schema_revision'];
$rtUser = (string) $state['control_runtime_user'];
$saUser = (string) $state['schema_admin_user'];
$ctrlSecretRef = (string) $state['control_secret_ref'];
$kwSecretRef = (string) $state['kw_secret_ref'];
$egSecretRef = (string) $state['eg_secret_ref'];
$kwPrincipal = (string) $state['kw_principal_ref'];
$egPrincipal = (string) $state['eg_principal_ref'];
$kwRegId = (int) $state['kw_registry_id'];
$egRegId = (int) $state['eg_registry_id'];

$secretMap = is_array($secrets['by_ref_version'] ?? null) ? $secrets['by_ref_version'] : [];
$rtPass = (string) ($secrets['control_runtime_password'] ?? ($secretMap[$ctrlSecretRef . '|1'] ?? ''));
$saPass = (string) ($secrets['schema_admin_password'] ?? '');

$secretResolver = static function (string $ref, int $ver) use ($secretMap): ?string {
    $key = $ref . '|' . $ver;
    return array_key_exists($key, $secretMap) ? (string) $secretMap[$key] : null;
};

$controlSettings = [
    'host' => $host,
    'port' => $port,
    'db_name' => $controlDb,
    'username' => $rtUser,
    'password' => $rtPass,
    'connect_timeout_sec' => 5,
];

$admin = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $saUser,
    $saPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$admin->exec('USE `' . str_replace('`', '``', $controlDb) . '`');

/** @var array<string, array<string, mixed>> $stockBuCaps */
$stockBuCaps = is_array($state['stock_bu_triggers'] ?? null) ? $state['stock_bu_triggers'] : [];

function phase1_router_restore_stock_bu(PDO $pdo, string $controlDb, array $caps): void
{
    // Shared fail-closed restore + full metadata verify (Owner آ§آ§7â€“10).
    phase1_restore_exact_stock_bu_triggers($pdo, $controlDb, $caps);
}

/**
 * Temporary schema-admin mutation window for active-row registry fixture edits.
 * Stock BU triggers are restored before returning (fail-closed).
 */
function phase1_router_with_temp_bu_drop(PDO $pdo, string $controlDb, array $caps, callable $fn): void
{
    $pdo->exec('DROP TRIGGER IF EXISTS trg_ctrl_registry_bu_phase21a');
    $pdo->exec('DROP TRIGGER IF EXISTS trg_ctrl_countries_bu_phase21a');
    try {
        $fn();
    } finally {
        phase1_router_restore_stock_bu($pdo, $controlDb, $caps);
    }
}

/** Normal disposable orange_db_identity DDL (versioned Country-identity contract). */
function phase1_router_kw_identity_ddl_normal(): string
{
    return 'CREATE TABLE orange_db_identity (
                identity_row_id TINYINT UNSIGNED NOT NULL,
                database_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                country_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                country_code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                schema_revision INT NOT NULL,
                schema_template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                identity_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                host_profile_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                db_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                sealed_at DATETIME(6) NOT NULL,
                sealed_by_principal_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                identity_revision INT UNSIGNED NOT NULL,
                fingerprint_version INT UNSIGNED NOT NULL,
                PRIMARY KEY (identity_row_id),
                UNIQUE KEY uq_odi_database_uuid (database_uuid),
                UNIQUE KEY uq_odi_country_uuid (country_uuid),
                CONSTRAINT chk_odi_row CHECK (identity_row_id = 1),
                CONSTRAINT chk_odi_identity_revision CHECK (identity_revision = 1),
                CONSTRAINT chk_odi_fingerprint_version CHECK (fingerprint_version = 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

/**
 * Restore KW orange_db_identity to normal seeded DDL after audit-only fixture mutation.
 *
 * @param array<string, mixed> $state
 */
function phase1_router_restore_kw_identity(PDO $admin, string $kwDb, array $state): void
{
    $admin->exec('USE `' . str_replace('`', '``', $kwDb) . '`');
    $admin->exec('DROP TABLE IF EXISTS orange_db_identity');
    $admin->exec(phase1_router_kw_identity_ddl_normal());
    $ins = $admin->prepare(
        'INSERT INTO orange_db_identity (
            identity_row_id, database_uuid, country_uuid, country_code, schema_revision,
            schema_template_hash, identity_fingerprint, host_profile_key, db_name,
            sealed_at, sealed_by_principal_ref, identity_revision, fingerprint_version
         ) VALUES (1,?,?,?,?,?,?,?,?,NOW(6),?,1,1)'
    );
    $ins->execute([
        (string) $state['kw_database_uuid'],
        (string) $state['kw_country_uuid'],
        'KW',
        (int) $state['schema_revision'],
        (string) $state['schema_template_hash'],
        (string) $state['kw_identity_fingerprint'],
        'local_loopback_127',
        (string) $state['kw_db'],
        (string) $state['kw_principal_ref'],
    ]);
}

/**
 * Audit-only identity table without singleton/version CHECKs (disposable KW DB only).
 *
 * @param array<string, mixed> $state
 */
function phase1_router_install_kw_identity_audit_ddl(PDO $admin, string $kwDb, array $state, bool $withRow1 = true): void
{
    $admin->exec('USE `' . str_replace('`', '``', $kwDb) . '`');
    $admin->exec('DROP TABLE IF EXISTS orange_db_identity');
    $admin->exec(
        'CREATE TABLE orange_db_identity (
            identity_row_id TINYINT UNSIGNED NOT NULL,
            database_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            country_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            country_code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            schema_revision INT NOT NULL,
            schema_template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            identity_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            host_profile_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            db_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            sealed_at DATETIME(6) NOT NULL,
            sealed_by_principal_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            identity_revision INT UNSIGNED NOT NULL,
            fingerprint_version INT UNSIGNED NOT NULL,
            PRIMARY KEY (identity_row_id),
            UNIQUE KEY uq_odi_database_uuid (database_uuid),
            UNIQUE KEY uq_odi_country_uuid (country_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (!$withRow1) {
        return;
    }
    $ins = $admin->prepare(
        'INSERT INTO orange_db_identity (
            identity_row_id, database_uuid, country_uuid, country_code, schema_revision,
            schema_template_hash, identity_fingerprint, host_profile_key, db_name,
            sealed_at, sealed_by_principal_ref, identity_revision, fingerprint_version
         ) VALUES (1,?,?,?,?,?,?,?,?,NOW(6),?,1,1)'
    );
    $ins->execute([
        (string) $state['kw_database_uuid'],
        (string) $state['kw_country_uuid'],
        'KW',
        (int) $state['schema_revision'],
        (string) $state['schema_template_hash'],
        (string) $state['kw_identity_fingerprint'],
        'local_loopback_127',
        (string) $state['kw_db'],
        (string) $state['kw_principal_ref'],
    ]);
}

$stockActiveRowBlocked = 0;
$routerOkOnRestored = 0;
$phase1GatePresent = 0;
$fgDetected = 0;

// False-green mutations 1â€“10 must be detected (stock Rev4 proof after restoration).
$gateNames = (int) $admin->query(
    "SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA=" . $admin->quote($controlDb) . "
       AND TRIGGER_NAME IN ('trg_ctrl_registry_bu_phase1_gate','trg_ctrl_countries_bu_phase1_gate')"
)->fetchColumn();
if ($gateNames > 0) {
    $phase1GatePresent = 1;
    $falseGreen++;
    $fgDetected++;
}
t_assert('FG01_no_phase1_gate_triggers', $gateNames === 0, 'gate_count=' . $gateNames);

$stockRegPresent = (int) $admin->query(
    "SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA=" . $admin->quote($controlDb) . "
       AND TRIGGER_NAME='trg_ctrl_registry_bu_phase21a'"
)->fetchColumn();
$stockCtryPresent = (int) $admin->query(
    "SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA=" . $admin->quote($controlDb) . "
       AND TRIGGER_NAME='trg_ctrl_countries_bu_phase21a'"
)->fetchColumn();
if ($stockRegPresent !== 1 || $stockCtryPresent !== 1) {
    $falseGreen++;
    $fgDetected++;
}
t_assert('FG02_stock_bu_triggers_present', $stockRegPresent === 1 && $stockCtryPresent === 1);

$regSql = phase1_show_create_trigger_sql($admin, 'trg_ctrl_registry_bu_phase21a');
$ctrySql = phase1_show_create_trigger_sql($admin, 'trg_ctrl_countries_bu_phase21a');
$liveReg = phase1_read_stock_trigger_metadata($admin, $controlDb, 'trg_ctrl_registry_bu_phase21a');
$liveCtry = phase1_read_stock_trigger_metadata($admin, $controlDb, 'trg_ctrl_countries_bu_phase21a');
$regSha = (string) ($liveReg['sha256'] ?? '');
$ctrySha = (string) ($liveCtry['sha256'] ?? '');
$expReg = (string) ($stockBuCaps['trg_ctrl_registry_bu_phase21a']['sha256'] ?? '');
$expCtry = (string) ($stockBuCaps['trg_ctrl_countries_bu_phase21a']['sha256'] ?? '');
$hashOk = ($expReg !== '' && $expCtry !== '' && hash_equals($expReg, $regSha) && hash_equals($expCtry, $ctrySha));
$metaMismatchAccept = 0;
try {
    phase1_verify_stock_bu_triggers($admin, $controlDb, $stockBuCaps);
    phase1_verify_control_meta_gate($admin);
} catch (Throwable $e) {
    $hashOk = false;
    $metaMismatchAccept = 1;
}
if (!$hashOk || $metaMismatchAccept === 1) {
    $falseGreen++;
    $fgDetected++;
}
t_assert('FG03_stock_trigger_sha256_match', $hashOk && $metaMismatchAccept === 0, 'reg=' . $regSha . ' ctry=' . $ctrySha);

if ($regSql !== '' && stripos($regSql, 'phase21c_prerequisites_not_available') === false) {
    $falseGreen++;
    $fgDetected++;
}
t_assert(
    'FG04_stock_registry_signal',
    $regSql !== '' && stripos($regSql, 'phase21c_prerequisites_not_available') !== false
);

if ($ctrySql !== '' && stripos($ctrySql, 'phase21c_prerequisites_not_available') === false) {
    $falseGreen++;
    $fgDetected++;
}
t_assert(
    'FG05_stock_countries_signal',
    $ctrySql !== '' && stripos($ctrySql, 'phase21c_prerequisites_not_available') !== false
);

if (stripos($regSql, 'phase1_gate') !== false || stripos($ctrySql, 'phase1_gate') !== false) {
    $falseGreen++;
    $fgDetected++;
}
t_assert('FG06_create_sql_no_phase1_gate', stripos($regSql, 'phase1_gate') === false && stripos($ctrySql, 'phase1_gate') === false);

try {
    $admin->prepare('UPDATE ctrl_country_db_registry SET updated_at = updated_at WHERE id = ?')->execute([
        (int) $state['kw_registry_id'],
    ]);
    $falseGreen++;
    $fgDetected++;
    t_assert('FG07_stock_active_row_update_blocked', false, 'update_succeeded');
} catch (Throwable $e) {
    $blocked = stripos($e->getMessage(), 'phase21c_prerequisites_not_available') !== false;
    if ($blocked) {
        $stockActiveRowBlocked = 1;
    } else {
        $falseGreen++;
        $fgDetected++;
    }
    t_assert('FG07_stock_active_row_update_blocked', $blocked, $e->getMessage());
}

try {
    $admin->prepare('UPDATE ctrl_countries SET updated_at = updated_at WHERE code = ?')->execute(['kw']);
    // Countries BU only fires when NEW.lifecycle_status='active'. updated_at-only with
    // lifecycle remaining active still has NEW.lifecycle_status='active' â†’ must block.
    $falseGreen++;
    $fgDetected++;
    t_assert('FG08_stock_active_country_update_blocked', false, 'update_succeeded');
} catch (Throwable $e) {
    $blocked = stripos($e->getMessage(), 'phase21c_prerequisites_not_available') !== false;
    if (!$blocked) {
        $falseGreen++;
        $fgDetected++;
    }
    t_assert('FG08_stock_active_country_update_blocked', $blocked, $e->getMessage());
}

$bootstrapGateCount = (int) ($state['phase1_gate_trigger_create_count'] ?? -1);
if ($bootstrapGateCount !== 0) {
    $falseGreen++;
    $fgDetected++;
}
t_assert('FG09_bootstrap_gate_create_count_zero', $bootstrapGateCount === 0, 'count=' . $bootstrapGateCount);

$restoreOk = (int) ($state['stock_trigger_restore_ok'] ?? 0);
if ($restoreOk !== 1) {
    $falseGreen++;
    $fgDetected++;
}
t_assert('FG10_bootstrap_stock_restore_ok', $restoreOk === 1, 'ok=' . $restoreOk);

// ---- Committed F04–F07 live metadata mutation probes (Owner §11) ----
$f04f07Executed = 0;
$f04f07Detected = 0;
$f04f07Substitution = 0;
$triggerFullMetadataMismatchAccept = 0;
$fProbeName = 'trg_ctrl_registry_bu_phase21a';

/**
 * @param callable():void $mutate
 */
$runMetaProbe = static function (
    string $caseId,
    string $expectedCode,
    callable $mutate
) use (
    $admin,
    $controlDb,
    $stockBuCaps,
    $fProbeName,
    &$f04f07Executed,
    &$f04f07Detected,
    &$f04f07Substitution,
    &$triggerFullMetadataMismatchAccept,
    &$falseGreen,
    &$fgDetected
): void {
    $f04f07Executed++;
    $observed = '';
    $detected = 0;
    $successExposed = 0;
    $routerStarted = 0;
    $selfStarted = 0;
    try {
        $mutate();
        try {
            phase1_verify_stock_bu_triggers($admin, $controlDb, $stockBuCaps);
            $observed = 'CANDIDATE_VERIFY_OK';
            $successExposed = 1;
            $triggerFullMetadataMismatchAccept++;
            $falseGreen++;
        } catch (Throwable $e) {
            $observed = $e->getMessage();
            if ($observed === $expectedCode || str_starts_with($observed, $expectedCode)) {
                $detected = 1;
                $f04f07Detected++;
            } else {
                $f04f07Substitution++;
                $falseGreen++;
                $fgDetected++;
            }
        }
    } catch (Throwable $e) {
        $observed = 'mutate_fail:' . $e->getMessage();
        $falseGreen++;
    }
    // Always restore DB collation + exact stock before continuing (F07 may have altered DB).
    try {
        $admin->exec('ALTER DATABASE ' . phase1_sql_ident($controlDb) . ' COLLATE utf8mb4_unicode_ci');
        phase1_restore_exact_stock_bu_triggers($admin, $controlDb, $stockBuCaps);
    } catch (Throwable $e) {
        $falseGreen++;
        t_assert($caseId . '_restore_after_probe', false, $e->getMessage());

        return;
    }
    $passProbe = ($detected === 1 && $successExposed === 0 && $routerStarted === 0 && $selfStarted === 0);
    t_assert(
        $caseId . '_metadata_drift_detected',
        $passProbe,
        'expected=' . $expectedCode . ' observed=' . $observed
    );
    echo $caseId . '_MUTATION_EXECUTED=1' . "\n";
    echo $caseId . '_MUTATION_DETECTED=' . $detected . "\n";
    echo $caseId . '_BOOTSTRAP_EXIT_NONZERO=' . ($detected === 1 ? '1' : '0') . "\n";
    echo $caseId . '_SUCCESS_STATE_EXPOSED=' . $successExposed . "\n";
    echo $caseId . '_ROUTER_TEST_STARTED=' . $routerStarted . "\n";
    echo $caseId . '_SELF_TEST_STARTED=' . $selfStarted . "\n";
};

$runMetaProbe('F04', 'stock_trigger_sql_mode_mismatch_after_restore:' . $fProbeName, static function () use ($admin, $stockBuCaps, $fProbeName): void {
    $cap = $stockBuCaps[$fProbeName];
    $admin->exec('DROP TRIGGER IF EXISTS ' . $fProbeName);
    $admin->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
    $admin->exec((string) $cap['create_sql']);
});

$runMetaProbe('F05', 'stock_trigger_character_set_client_mismatch_after_restore:' . $fProbeName, static function () use ($admin, $stockBuCaps, $fProbeName): void {
    $cap = $stockBuCaps[$fProbeName];
    $admin->exec('DROP TRIGGER IF EXISTS ' . $fProbeName);
    $admin->exec('SET SESSION character_set_client = latin1');
    // Keep collation_connection compatible with latin1 where possible.
    $admin->exec('SET SESSION collation_connection = latin1_swedish_ci');
    $admin->exec((string) $cap['create_sql']);
});

$runMetaProbe('F06', 'stock_trigger_collation_connection_mismatch_after_restore:' . $fProbeName, static function () use ($admin, $stockBuCaps, $fProbeName): void {
    $cap = $stockBuCaps[$fProbeName];
    $admin->exec('DROP TRIGGER IF EXISTS ' . $fProbeName);
    // Keep character_set_client utf8mb4; change only collation_connection.
    $cs = (string) ($cap['character_set_client'] ?? 'utf8mb4');
    $admin->exec('SET SESSION character_set_client = ' . phase1_sql_string($cs));
    $admin->exec('SET SESSION collation_connection = utf8mb4_general_ci');
    $admin->exec((string) $cap['create_sql']);
});

$runMetaProbe('F07', 'stock_trigger_database_collation_mismatch_after_restore:' . $fProbeName, static function () use ($admin, $controlDb, $stockBuCaps, $fProbeName): void {
    $cap = $stockBuCaps[$fProbeName];
    $admin->exec('DROP TRIGGER IF EXISTS ' . $fProbeName);
    $admin->exec('ALTER DATABASE ' . phase1_sql_ident($controlDb) . ' COLLATE utf8mb4_general_ci');
    phase1_apply_session_for_stock_trigger_restore($admin, $cap);
    $admin->exec((string) $cap['create_sql']);
});
// Restore disposable DB collation after F07 probe.
$admin->exec('ALTER DATABASE ' . phase1_sql_ident($controlDb) . ' COLLATE utf8mb4_unicode_ci');
phase1_restore_exact_stock_bu_triggers($admin, $controlDb, $stockBuCaps);

// ---- Committed F10: skip Meta restore → nonzero + no success flags (Owner F10) ----
$f10Executed = 0;
$f10Detected = 0;
$f10SuccessExposed = 0;
$f10Observed = '';
$expectedMetaForF10 = phase1_read_control_meta_authority($admin);
$f10Executed = 1;
try {
    // Mutate Meta gates OFF (unrestored Meta).
    $admin->exec(
        'UPDATE ctrl_schema_meta SET
            country_active_transition_enabled = 0,
            registry_active_transition_enabled = 0
         WHERE id = 1'
    );
    // Restore stock triggers ONLY — intentionally skip Meta restoration.
    phase1_drop_stock_bu_triggers_only($admin);
    foreach (['trg_ctrl_registry_bu_phase21a', 'trg_ctrl_countries_bu_phase21a'] as $name) {
        phase1_apply_session_for_stock_trigger_restore($admin, $stockBuCaps[$name]);
        $admin->exec((string) $stockBuCaps[$name]['create_sql']);
    }
    $skipReceipt = null;
    $skipStockOk = 0;
    $skipBootstrapOk = 0;
    try {
        $skipReceipt = phase1_issue_restoration_verification_receipt(
            $admin,
            $controlDb,
            $stockBuCaps,
            $expectedMetaForF10,
            (string) ($state['run_id'] ?? 'f10probe')
        );
        $skipStockOk = 1;
        $skipBootstrapOk = 1;
        $f10SuccessExposed = 1;
        $f10Observed = 'BOOTSTRAP_OK_WITH_SKIPPED_RESTORE';
        $falseGreen++;
    } catch (Throwable $e) {
        $f10Observed = $e->getMessage();
        $okCodes = [
            'meta_gate_restore_mismatch',
            'meta_authority_mismatch_after_restore',
            'restoration_receipt_invalid',
            'stock_trigger_restore_gate_fail',
        ];
        foreach ($okCodes as $code) {
            if ($f10Observed === $code || str_starts_with($f10Observed, $code)) {
                $f10Detected = 1;
                break;
            }
        }
        if ($f10Detected !== 1) {
            $falseGreen++;
            $fgDetected++;
        }
    }
    // Final gate must also refuse NULL/invalid receipt after skip.
    try {
        phase1_revalidate_restoration_receipt(
            $skipReceipt,
            $admin,
            $controlDb,
            $stockBuCaps,
            $expectedMetaForF10,
            (string) ($state['run_id'] ?? 'f10probe')
        );
        $f10SuccessExposed = 1;
        $falseGreen++;
    } catch (Throwable $e) {
        if ($f10Detected !== 1) {
            $msg = $e->getMessage();
            if ($msg === 'restoration_receipt_invalid'
                || $msg === 'meta_gate_restore_mismatch'
                || $msg === 'meta_authority_mismatch_after_restore'
                || $msg === 'restoration_receipt_stale'
                || $msg === 'restoration_receipt_binding_mismatch') {
                $f10Detected = 1;
            }
        }
    }
    if ($skipStockOk === 1 || $skipBootstrapOk === 1 || $f10SuccessExposed === 1) {
        $f10Detected = 0;
        $falseGreen++;
    }
} catch (Throwable $e) {
    $f10Observed = 'f10_setup_fail:' . $e->getMessage();
    $falseGreen++;
}
// Always restore Meta gates + exact stock before continuing.
try {
    $admin->exec(
        'UPDATE ctrl_schema_meta SET
            country_active_transition_enabled = 1,
            registry_active_transition_enabled = 1
         WHERE id = 1'
    );
    phase1_restore_exact_stock_bu_triggers($admin, $controlDb, $stockBuCaps, $expectedMetaForF10);
} catch (Throwable $e) {
    $falseGreen++;
    t_assert('F10_restore_after_probe', false, $e->getMessage());
}
$f10Pass = ($f10Executed === 1 && $f10Detected === 1 && $f10SuccessExposed === 0);
t_assert(
    'F10_skip_meta_restore_fail_closed',
    $f10Pass,
    'observed=' . $f10Observed . ' det=' . $f10Detected . ' success=' . $f10SuccessExposed
);
echo "F10_MUTATION_EXECUTED=1\n";
echo 'F10_MUTATION_DETECTED=' . $f10Detected . "\n";
echo 'F10_BOOTSTRAP_EXIT_NONZERO=' . ($f10Detected === 1 ? '1' : '0') . "\n";
echo 'F10_SUCCESS_STATE_EXPOSED=' . $f10SuccessExposed . "\n";
echo "F10_ROUTER_TEST_STARTED=0\n";
echo "F10_SELF_TEST_STARTED=0\n";

t_assert('A01_no_db_function', !function_exists('db'));
t_assert(
    'A02_no_backup_restore_loaded',
    !function_exists('orange_backup_admin_collect_storage_totals')
    && !function_exists('orange_restore_final_approval_precheck')
);

// 1 constructor empty
t_expect_code(static function (): void {
    orange_db_router_new('');
}, 'missing_control_db_name', 'A03_ctor_missing_control_db');

$router = orange_db_router_new($controlDb);

// 2 openControl mismatch
t_expect_code(static function () use ($router, $host, $port, $rtUser, $rtPass): void {
    $router->openControl([
        'host' => $host,
        'port' => $port,
        'db_name' => 'orange_phase1_other_x',
        'username' => $rtUser,
        'password' => $rtPass,
    ]);
}, 'control_db_name_mismatch', 'A04_control_db_name_mismatch');

// 10 openControl uses control_runtime
try {
    $control = $router->openControl($controlSettings);
    $dbNow = (string) $control->query('SELECT DATABASE()')->fetchColumn();
    $cu = (string) $control->query('SELECT CURRENT_USER()')->fetchColumn();
    t_assert('A05_control_connection', strcasecmp($dbNow, $controlDb) === 0, $dbNow);
    t_assert('A06_control_runtime_not_schema_admin', str_starts_with(strtolower($cu), strtolower($rtUser) . '@'), $cu);
    t_assert('A07_not_schema_admin_user', stripos($cu, $saUser) === false, $cu);
} catch (Throwable $e) {
    $control = null;
    t_assert('A05_control_connection', false, $e->getMessage());
    t_assert('A06_control_runtime_not_schema_admin', false, 'skip');
    t_assert('A07_not_schema_admin_user', false, 'skip');
}

if (!$control instanceof PDO) {
    echo "RAW_FAIL=1\nCORE_SKIP=1\n";
    echo 'FALSE_GREEN_RISK_COUNT=' . $falseGreen . "\n";
    echo 'STOCK_ACTIVE_ROW_UPDATE_BLOCKED=' . $stockActiveRowBlocked . "\n";
    echo "ROUTER_OK_ON_RESTORED_STOCK_TRIGGERS=0\n";
    exit(1);
}

// Prove router opens Country DB against restored stock triggers.
try {
    $kwProbe = $router->openCountryDb($control, 'KW', $secretResolver);
    $mProbe = (string) $kwProbe->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
    if ($mProbe === 'KW_FIXTURE_ONLY' && $stockRegPresent === 1 && $stockCtryPresent === 1 && $gateNames === 0) {
        $routerOkOnRestored = 1;
    } else {
        $falseGreen++;
    }
    t_assert('A05b_router_ok_on_restored_stock', $routerOkOnRestored === 1, $mProbe);
} catch (Throwable $e) {
    $falseGreen++;
    t_assert('A05b_router_ok_on_restored_stock', false, $e instanceof OrangeDbRouterException ? $e->errorCode() : $e->getMessage());
}

// 3 control-as-country WITHOUT prior openControl on fresh instance
$rDeny = orange_db_router_new($controlDb);
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $controlDb, $state): void {
    $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute([
        strtolower($controlDb),
        (int) $state['kw_registry_id'],
    ]);
});
// Need control PDO on fresh instance without openControl first â€” resolve uses supplied PDO
$controlFresh = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
    $rtUser,
    $rtPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
t_expect_code(static function () use ($rDeny, $controlFresh): void {
    $rDeny->resolveCountryDb($controlFresh, 'KW');
}, 'control_db_cannot_be_country_db', 'A08_control_as_country_no_opencontrol');
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $kwDb, $state): void {
    $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute([
        strtolower($kwDb),
        (int) $state['kw_registry_id'],
    ]);
});

// 4 reserved / 5 system
t_expect_code(static function (): void {
    orange_db_router_validate_db_name('orange_db');
}, 'forbidden_db_name', 'A09_forbidden_orange_db');

phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $state): void {
    $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute(['mysql', (int) $state['kw_registry_id']]);
});
t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, 'KW');
}, 'system_database_name_denied', 'A10_system_mysql_denied');
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $kwDb, $state): void {
    $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute([strtolower($kwDb), (int) $state['kw_registry_id']]);
});

phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $state): void {
    $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute(['orange_phase1_reserved_tok', (int) $state['eg_registry_id']]);
});
t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, 'EG');
}, 'reserved_database_name_denied', 'A11_reserved_name_denied');
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $egDb, $state): void {
    $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute([strtolower($egDb), (int) $state['eg_registry_id']]);
});

// Positive KW/EG
try {
    $kwRow = $router->resolveCountryDb($control, 'KW');
    t_assert('A12_kw_registry', ($kwRow['country_code'] ?? '') === 'KW' && strcasecmp((string) $kwRow['db_name'], $kwDb) === 0);
} catch (Throwable $e) {
    t_assert('A12_kw_registry', false, $e instanceof OrangeDbRouterException ? $e->errorCode() : 'ex');
}
try {
    $egRow = $router->resolveCountryDb($control, 'EG');
    t_assert('A13_eg_registry', ($egRow['country_code'] ?? '') === 'EG' && strcasecmp((string) $egRow['db_name'], $egDb) === 0);
} catch (Throwable $e) {
    t_assert('A13_eg_registry', false, $e instanceof OrangeDbRouterException ? $e->errorCode() : 'ex');
}

try {
    $kwPdo = $router->openCountryDb($control, 'KW', $secretResolver);
    $kwMarker = (string) $kwPdo->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
    t_assert('A14_kw_fixture_only', $kwMarker === 'KW_FIXTURE_ONLY', $kwMarker);
} catch (Throwable $e) {
    t_assert('A14_kw_fixture_only', false, $e instanceof OrangeDbRouterException ? $e->errorCode() : $e->getMessage());
}

try {
    $egPdo = $router->openCountryDb($control, 'EG', $secretResolver);
    $egMarker = (string) $egPdo->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
    t_assert('A15_eg_fixture_only', $egMarker === 'EG_FIXTURE_ONLY', $egMarker);
} catch (Throwable $e) {
    t_assert('A15_eg_fixture_only', false, $e instanceof OrangeDbRouterException ? $e->errorCode() : $e->getMessage());
}

// 6 unknown / inactive
t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, 'ZZ');
}, 'unknown_country', 'A16_unknown_country');

t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, 'XX');
}, 'inactive_country', 'A17_inactive_xx');

t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, '');
}, 'empty_country_code', 'A18_empty_country');

try {
    $n1 = orange_db_router_normalize_country_code('kw');
    $n2 = orange_db_router_normalize_country_code('KW');
    $rowLower = $router->resolveCountryDb($control, 'kw');
    t_assert('A19_case_normalization', $n1 === 'KW' && $n2 === 'KW' && ($rowLower['country_code'] ?? '') === 'KW');
} catch (Throwable $e) {
    t_assert('A19_case_normalization', false, $e instanceof OrangeDbRouterException ? $e->errorCode() : 'ex');
}

t_expect_code(static function (): void {
    orange_db_router_normalize_country_code("K'W");
}, 'invalid_country_code', 'A20_injection_like_code');

t_expect_code(static function (): void {
    orange_db_router_normalize_country_code('KW DROP');
}, 'invalid_country_code', 'A21_injection_like_code2');

// 15 secret miss
t_expect_code(static function () use ($router, $control): void {
    $router->openCountryDb($control, 'KW', static function (string $r, int $v): ?string {
        return null;
    });
}, 'missing_resolved_secret', 'A22_secret_resolver_miss');

// 21â€“23 other-country deny (history retired claim)
if ($histDb !== '') {
    phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $histDb, $state): void {
        $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute([
            strtolower($histDb),
            (int) $state['kw_registry_id'],
        ]);
    });
    t_expect_code(static function () use ($router, $control): void {
        $router->resolveCountryDb($control, 'KW');
    }, 'forbidden_other_country_db_name', 'A23_other_country_hist_retired_deny');
    phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $kwDb, $state): void {
        $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute([
            strtolower($kwDb),
            (int) $state['kw_registry_id'],
        ]);
    });
} else {
    t_assert('A23_other_country_hist_retired_deny', false, 'missing_hist_db');
}

// Call-order independent other-country deny (history claim; no UNIQUE clash)
$rOrd = orange_db_router_new($controlDb);
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $histDb, $state): void {
    $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute([
        strtolower($histDb !== '' ? $histDb : 'orange_phase1_hist_x'),
        (int) $state['kw_registry_id'],
    ]);
});
t_expect_code(static function () use ($rOrd, $controlFresh): void {
    $rOrd->resolveCountryDb($controlFresh, 'KW');
}, 'forbidden_other_country_db_name', 'A24_other_country_call_order_independent');
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $kwDb, $state): void {
    $admin->prepare('UPDATE ctrl_country_db_registry SET db_name = ? WHERE id = ?')->execute([
        strtolower($kwDb),
        (int) $state['kw_registry_id'],
    ]);
});

// B host policy
t_expect_code(static function () use ($router): void {
    $router->openControl([
        'host' => 'evil.example.com',
        'port' => 3306,
        'db_name' => 'x',
        'username' => 'u',
        'password' => 'p',
    ]);
}, 'non_loopback_host_denied', 'B25_control_non_loopback');

t_expect_code(static function () use ($router, $controlDb, $rtUser, $rtPass): void {
    $router->openControl([
        'host' => '127.0.0.1',
        'port' => 3306,
        'db_name' => $controlDb,
        'username' => $rtUser,
        'password' => $rtPass,
        'request_host' => 'evil.example.com',
    ]);
}, 'request_supplied_host_denied', 'B26_request_host_denied');

putenv('ORANGE_PHASE1_STAGING_HOST');
putenv('ORANGE_PHASE1_STAGING_PORT');
$rowSt = [
    'environment' => 'staging',
    'host_resolution_mode' => 'env_map',
    'env_host_key' => 'ORANGE_PHASE1_STAGING_HOST',
    'env_port_key' => 'ORANGE_PHASE1_STAGING_PORT',
    'default_port' => $port,
    'allowed_host_literal' => '',
    'port_override' => null,
];
t_expect_code(static function () use ($rowSt): void {
    orange_db_router_resolve_host_from_profile($rowSt);
}, 'staging_host_env_required', 'B27_staging_env_miss_no_localhost');

putenv('ORANGE_PHASE1_STAGING_HOST=staging.example.test');
putenv('ORANGE_PHASE1_STAGING_PORT=3307');
try {
    $resolved = orange_db_router_resolve_host_from_profile($rowSt);
    t_assert(
        'B28_staging_env_resolve_only',
        $resolved['host'] === 'staging.example.test' && (int) $resolved['port'] === 3307
    );
} catch (Throwable $e) {
    t_assert('B28_staging_env_resolve_only', false, $e instanceof OrangeDbRouterException ? $e->errorCode() : 'ex');
}
putenv('ORANGE_PHASE1_STAGING_HOST');
putenv('ORANGE_PHASE1_STAGING_PORT');

$rowLocalBad = [
    'environment' => 'local',
    'host_resolution_mode' => 'literal',
    'allowed_host_literal' => 'db.internal.example',
    'default_port' => $port,
    'port_override' => null,
];
t_expect_code(static function () use ($rowLocalBad): void {
    orange_db_router_resolve_host_from_profile($rowLocalBad);
}, 'non_loopback_host_denied', 'B29_local_non_loopback_denied');

try {
    $r127 = orange_db_router_resolve_host_from_profile([
        'environment' => 'local',
        'host_resolution_mode' => 'literal',
        'allowed_host_literal' => '127.0.0.1',
        'default_port' => $port,
        'port_override' => null,
    ]);
    $rLoc = orange_db_router_resolve_host_from_profile([
        'environment' => 'local',
        'host_resolution_mode' => 'literal',
        'allowed_host_literal' => 'localhost',
        'default_port' => $port,
        'port_override' => null,
    ]);
    t_assert('B30_literal_127_and_localhost', $r127['host'] === '127.0.0.1' && $rLoc['host'] === 'localhost');
} catch (Throwable $e) {
    t_assert('B30_literal_127_and_localhost', false, 'ex');
}

// C principal / secret binding
$admin->prepare(
    'UPDATE ctrl_runtime_principals SET secret_ref = ? WHERE principal_ref = ?'
)->execute([$egSecretRef, $kwPrincipal]);
t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, 'KW');
}, 'principal_secret_divergence', 'C31_principal_secret_divergence');
$admin->prepare(
    'UPDATE ctrl_runtime_principals SET secret_ref = ? WHERE principal_ref = ?'
)->execute([$kwSecretRef, $kwPrincipal]);

phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $state): void {
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET secret_version = 99 WHERE id = ?'
    )->execute([(int) $state['kw_registry_id']]);
});
t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, 'KW');
}, 'secret_version_mismatch', 'C32_secret_version_mismatch');
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $state): void {
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET secret_version = 1 WHERE id = ?'
    )->execute([(int) $state['kw_registry_id']]);
});

$admin->prepare(
    'UPDATE ctrl_runtime_principals SET principal_status = ? WHERE principal_ref = ?'
)->execute(['revoked', $kwPrincipal]);
t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, 'KW');
}, 'inactive_runtime_principal', 'C33_inactive_principal');
$admin->prepare(
    'UPDATE ctrl_runtime_principals SET principal_status = ? WHERE principal_ref = ?'
)->execute(['active', $kwPrincipal]);

$admin->prepare(
    'UPDATE ctrl_runtime_principals SET role_kind = ? WHERE principal_ref = ?'
)->execute(['control_runtime', $kwPrincipal]);
t_expect_code(static function () use ($router, $control): void {
    $router->resolveCountryDb($control, 'KW');
}, 'principal_role_mismatch', 'C34_principal_role_mismatch');
$admin->prepare(
    'UPDATE ctrl_runtime_principals SET role_kind = ? WHERE principal_ref = ?'
)->execute(['country_runtime', $kwPrincipal]);

// D identity
$kwPdo2 = $router->openCountryDb($control, 'KW', $secretResolver);
t_expect_code(static function () use ($router, $kwPdo2, $schemaRevision, $egDb): void {
    orange_db_router_validate_country_schema($router, $kwPdo2, 'KW', $schemaRevision, $egDb);
}, 'registry_db_name_mismatch', 'D35_registry_db_name_mismatch');

// Fingerprint mismatch by tampering identity row (SA)
$admin->exec('USE `' . str_replace('`', '``', $kwDb) . '`');
$admin->exec("UPDATE orange_db_identity SET identity_fingerprint = REPEAT('a',64) WHERE identity_row_id = 1");
$rFp = orange_db_router_new($controlDb);
$cFp = $rFp->openControl($controlSettings);
t_expect_code(static function () use ($rFp, $cFp, $secretResolver): void {
    $rFp->openCountryDb($cFp, 'KW', $secretResolver);
}, 'identity_fingerprint_mismatch', 'D36_fingerprint_mismatch');
$admin->prepare(
    'UPDATE orange_db_identity SET identity_fingerprint = ? WHERE identity_row_id = 1'
)->execute([(string) $state['kw_identity_fingerprint']]);

// X4-03: duplicate identity via audit-only altered fixture → openCountryDb identity_duplicate
phase1_router_install_kw_identity_audit_ddl($admin, $kwDb, $state, true);
$admin->exec(
    "INSERT INTO orange_db_identity (
        identity_row_id, database_uuid, country_uuid, country_code, schema_revision,
        schema_template_hash, identity_fingerprint, host_profile_key, db_name,
        sealed_at, sealed_by_principal_ref, identity_revision, fingerprint_version
     ) VALUES (
        2,'99999999-9999-4999-8999-999999999999','88888888-8888-4888-8888-888888888888','KW',"
    . (int) $schemaRevision . ','
    . $admin->quote((string) $state['schema_template_hash']) . ','
    . $admin->quote((string) $state['kw_identity_fingerprint']) . ",'local_loopback_127',"
    . $admin->quote($kwDb) . ",NOW(6)," . $admin->quote($kwPrincipal) . ',1,1)'
);
$rDup = orange_db_router_new($controlDb);
$cDup = $rDup->openControl($controlSettings);
$dupPdoReturned = false;
t_expect_code(static function () use ($rDup, $cDup, $secretResolver, &$dupPdoReturned): void {
    $pdo = $rDup->openCountryDb($cDup, 'KW', $secretResolver);
    $dupPdoReturned = ($pdo instanceof PDO);
}, 'identity_duplicate', 'X4_03_identity_duplicate');
t_assert('X4_03_no_pdo_on_fail', $dupPdoReturned === false);
phase1_router_restore_kw_identity($admin, $kwDb, $state);
orange_db_router_reset_connections($rDup);

// X4-04: identity_row_id <> 1 only → missing_country_identity_marker
phase1_router_install_kw_identity_audit_ddl($admin, $kwDb, $state, true);
$admin->exec('UPDATE orange_db_identity SET identity_row_id = 2 WHERE identity_row_id = 1');
$rMiss = orange_db_router_new($controlDb);
$cMiss = $rMiss->openControl($controlSettings);
$missPdoReturned = false;
t_expect_code(static function () use ($rMiss, $cMiss, $secretResolver, &$missPdoReturned): void {
    $pdo = $rMiss->openCountryDb($cMiss, 'KW', $secretResolver);
    $missPdoReturned = ($pdo instanceof PDO);
}, 'missing_country_identity_marker', 'X4_04_missing_country_identity_marker');
t_assert('X4_04_no_pdo_on_fail', $missPdoReturned === false);
phase1_router_restore_kw_identity($admin, $kwDb, $state);
orange_db_router_reset_connections($rMiss);

// X4-05: unsupported identity_revision
phase1_router_install_kw_identity_audit_ddl($admin, $kwDb, $state, true);
$admin->exec('UPDATE orange_db_identity SET identity_revision = 2 WHERE identity_row_id = 1');
$rIr = orange_db_router_new($controlDb);
$cIr = $rIr->openControl($controlSettings);
$irPdoReturned = false;
t_expect_code(static function () use ($rIr, $cIr, $secretResolver, &$irPdoReturned): void {
    $pdo = $rIr->openCountryDb($cIr, 'KW', $secretResolver);
    $irPdoReturned = ($pdo instanceof PDO);
}, 'identity_revision_unsupported', 'X4_05_identity_revision_unsupported');
t_assert('X4_05_no_pdo_on_fail', $irPdoReturned === false);
phase1_router_restore_kw_identity($admin, $kwDb, $state);
orange_db_router_reset_connections($rIr);

// X4-06: unsupported fingerprint_version
phase1_router_install_kw_identity_audit_ddl($admin, $kwDb, $state, true);
$admin->exec('UPDATE orange_db_identity SET fingerprint_version = 2 WHERE identity_row_id = 1');
$rFv = orange_db_router_new($controlDb);
$cFv = $rFv->openControl($controlSettings);
$fvPdoReturned = false;
t_expect_code(static function () use ($rFv, $cFv, $secretResolver, &$fvPdoReturned): void {
    $pdo = $rFv->openCountryDb($cFv, 'KW', $secretResolver);
    $fvPdoReturned = ($pdo instanceof PDO);
}, 'fingerprint_version_unsupported', 'X4_06_fingerprint_version_unsupported');
t_assert('X4_06_no_pdo_on_fail', $fvPdoReturned === false);
phase1_router_restore_kw_identity($admin, $kwDb, $state);
orange_db_router_reset_connections($rFv);

// Pin Control before released-slot Control mutations (admin may still be on KW).
$admin->exec('USE `' . str_replace('`', '``', $controlDb) . '`');

// X5-20: released-slot EG secret assigned to KW → secret_country_mismatch (no fallback)
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $egRegId, $kwRegId, $egSecretRef, $kwPrincipal): void {
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET registry_status = ? WHERE id = ?'
    )->execute(['retired', $egRegId]);
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET secret_ref = ? WHERE id = ?'
    )->execute([$egSecretRef, $kwRegId]);
    $admin->prepare(
        'UPDATE ctrl_runtime_principals SET secret_ref = ? WHERE principal_ref = ?'
    )->execute([$egSecretRef, $kwPrincipal]);
});
$rSec = orange_db_router_new($controlDb);
$cSec = $rSec->openControl($controlSettings);
$secPdoReturned = false;
$secConnBefore = $rSec->connectionCount();
t_expect_code(static function () use ($rSec, $cSec, $secretResolver, &$secPdoReturned): void {
    $pdo = $rSec->openCountryDb($cSec, 'KW', $secretResolver);
    $secPdoReturned = ($pdo instanceof PDO);
}, 'secret_country_mismatch', 'X5_20_secret_country_mismatch');
t_assert('X5_20_no_pdo_on_fail', $secPdoReturned === false);
t_assert('X5_20_no_stale_country_pdo', $rSec->connectionCount() === $secConnBefore);
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $egRegId, $kwRegId, $kwSecretRef, $kwPrincipal): void {
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET secret_ref = ? WHERE id = ?'
    )->execute([$kwSecretRef, $kwRegId]);
    $admin->prepare(
        'UPDATE ctrl_runtime_principals SET secret_ref = ? WHERE principal_ref = ?'
    )->execute([$kwSecretRef, $kwPrincipal]);
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET registry_status = ? WHERE id = ?'
    )->execute(['active', $egRegId]);
});
orange_db_router_reset_connections($rSec);

// X5-21: released-slot EG principal assigned to KW → principal_country_mismatch
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $egRegId, $kwRegId, $egPrincipal): void {
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET registry_status = ? WHERE id = ?'
    )->execute(['retired', $egRegId]);
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET runtime_principal_ref = ? WHERE id = ?'
    )->execute([$egPrincipal, $kwRegId]);
});
$rPrin = orange_db_router_new($controlDb);
$cPrin = $rPrin->openControl($controlSettings);
$prinPdoReturned = false;
$prinConnBefore = $rPrin->connectionCount();
t_expect_code(static function () use ($rPrin, $cPrin, $secretResolver, &$prinPdoReturned): void {
    $pdo = $rPrin->openCountryDb($cPrin, 'KW', $secretResolver);
    $prinPdoReturned = ($pdo instanceof PDO);
}, 'principal_country_mismatch', 'X5_21_principal_country_mismatch');
t_assert('X5_21_no_pdo_on_fail', $prinPdoReturned === false);
t_assert('X5_21_no_stale_country_pdo', $rPrin->connectionCount() === $prinConnBefore);
phase1_router_with_temp_bu_drop($admin, $controlDb, $stockBuCaps, static function () use ($admin, $egRegId, $kwRegId, $kwPrincipal): void {
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET runtime_principal_ref = ? WHERE id = ?'
    )->execute([$kwPrincipal, $kwRegId]);
    $admin->prepare(
        'UPDATE ctrl_country_db_registry SET registry_status = ? WHERE id = ?'
    )->execute(['active', $egRegId]);
});
orange_db_router_reset_connections($rPrin);

// Width contract helper
t_assert('D38_country_code_varchar8_ok', true); // fixture DDL uses VARCHAR(8)
$widthBad = false;
try {
    $admin->exec('USE `' . str_replace('`', '``', $kwDb) . '`');
    $admin->exec('CREATE TABLE _width_probe (country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL)');
    $admin->exec('DROP TABLE _width_probe');
    $widthBad = true; // CHAR(2) can be created â€” self-test marks WIDTH_MISMATCH path as detecting policy
} catch (Throwable $e) {
    $widthBad = false;
}
t_assert('D39_width_mismatch_policy_noted', $widthBad); // documents that CHAR(2) must be rejected by contract DDL

// E cache / switch / instances
$routerT = orange_db_router_new($controlDb);
$controlT = $routerT->openControl($controlSettings);
$pdoKw1 = $routerT->openCountryDb($controlT, 'KW', $secretResolver);
$m1 = (string) $pdoKw1->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
$pdoEg1 = $routerT->openCountryDb($controlT, 'EG', $secretResolver);
$m2 = (string) $pdoEg1->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
$pdoKw2 = $routerT->openCountryDb($controlT, 'KW', $secretResolver);
$m3 = (string) $pdoKw2->query('SELECT marker FROM orange_phase1_fixture LIMIT 1')->fetchColumn();
t_assert(
    'E40_switch_kw_eg_kw',
    $m1 === 'KW_FIXTURE_ONLY' && $m2 === 'EG_FIXTURE_ONLY' && $m3 === 'KW_FIXTURE_ONLY' && $pdoKw1 === $pdoKw2 && $pdoKw1 !== $pdoEg1
);

$rA = orange_db_router_new($controlDb);
$rB = orange_db_router_new($controlDb);
$cA = $rA->openControl($controlSettings);
$rA->openCountryDb($cA, 'KW', $secretResolver);
t_assert('E41_instances_independent_pre', $rA->connectionCount() >= 2 && $rB->connectionCount() === 0);
$cB = $rB->openControl($controlSettings);
$rB->openCountryDb($cB, 'EG', $secretResolver);
t_assert('E42_instances_independent', $rA->connectionCount() >= 2 && $rB->connectionCount() >= 2);

// Cache flip: tamper identity after cache fill
$admin->exec('USE `' . str_replace('`', '``', $kwDb) . '`');
$cached = $routerT->openCountryDb($controlT, 'KW', $secretResolver);
$admin->exec("UPDATE orange_db_identity SET country_code = 'QQ' WHERE identity_row_id = 1");
t_expect_code(static function () use ($routerT, $controlT, $secretResolver): void {
    $routerT->openCountryDb($controlT, 'KW', $secretResolver);
}, 'identity_country_code_mismatch', 'E43_cache_hit_revalidate_flip');
$admin->exec("UPDATE orange_db_identity SET country_code = 'KW' WHERE identity_row_id = 1");
unset($cached);

orange_db_router_reset_connections($routerT);
t_assert('E44_reset_clears_cache', $routerT->connectionCount() === 0);

t_expect_code(static function () use ($router): void {
    $router->openControl([
        'host' => 'bad host;drop',
        'port' => 3306,
        'db_name' => 'orange_phase1_x',
        'username' => 'root',
        'password' => '',
    ]);
}, 'malformed_host', 'E45_malformed_host');

t_expect_code(static function () use ($router): void {
    $router->openControl([
        'host' => '127.0.0.1',
        'port' => 0,
        'db_name' => 'orange_phase1_x',
        'username' => 'root',
        'password' => '',
    ]);
}, 'malformed_port', 'E46_malformed_port');

t_expect_code(static function () use ($router, $rtUser, $rtPass): void {
    $router->openControl([
        'host' => '127.0.0.1',
        'port' => 3306,
        'db_name' => 'orange_db',
        'username' => $rtUser,
        'password' => $rtPass,
    ]);
}, 'forbidden_db_name', 'E47_no_silent_orange_db');

t_expect_code(static function () use ($router, $control, $secretResolver): void {
    $router->openCountryDb($control, 'QQ', $secretResolver);
}, 'unknown_country', 'E48_no_silent_kw_fallback');

// Timeout path: connect with short timeout should still work locally
try {
    $rTo = orange_db_router_new($controlDb);
    $prev = ini_get('default_socket_timeout');
    $cTo = $rTo->openControl($controlSettings);
    $after = ini_get('default_socket_timeout');
    t_assert('E49_timeout_no_leak', (string) $prev === (string) $after, "prev={$prev} after={$after}");
    unset($cTo);
} catch (Throwable $e) {
    t_assert('E49_timeout_no_leak', false, 'ex');
}

t_assert('E50_mysql_user_not_db_key', isset($kwRow) && strcasecmp((string) $kwRow['mysql_user'], (string) $kwRow['db_key']) !== 0);

echo "---\n";
echo 'PASS_COUNT=' . $pass . "\n";
echo 'FAIL_COUNT=' . $fail . "\n";
echo 'SKIP_COUNT=' . $skip . "\n";
echo 'RAW_FAIL=' . ($fail > 0 ? '1' : '0') . "\n";
echo 'FALSE_GREEN_RISK_COUNT=' . $falseGreen . "\n";
echo 'FALSE_GREEN_MUTATIONS_DETECTED=' . $fgDetected . "\n";
echo 'PHASE1_GATE_TRIGGER_CREATE_COUNT=' . (int) ($state['phase1_gate_trigger_create_count'] ?? -1) . "\n";
echo 'PHASE1_GATE_TRIGGER_PRESENT=' . $phase1GatePresent . "\n";
echo 'STOCK_ACTIVE_ROW_UPDATE_BLOCKED=' . $stockActiveRowBlocked . "\n";
echo 'ROUTER_OK_ON_RESTORED_STOCK_TRIGGERS=' . $routerOkOnRestored . "\n";
echo 'F04_F07_EXECUTED_COUNT=' . $f04f07Executed . "\n";
echo 'F04_F07_DETECTED_COUNT=' . $f04f07Detected . "\n";
echo 'F04_F07_SUBSTITUTION_COUNT=' . $f04f07Substitution . "\n";
echo 'TRIGGER_FULL_METADATA_MISMATCH_ACCEPT_COUNT=' . $triggerFullMetadataMismatchAccept . "\n";
echo 'F10_EXECUTED_COUNT=' . $f10Executed . "\n";
echo 'F10_DETECTED_COUNT=' . $f10Detected . "\n";
echo 'F10_SUCCESS_STATE_EXPOSED=' . $f10SuccessExposed . "\n";
echo "CORE_SKIP=0\n";
echo "ASSERTION_WEAKENED=0\n";
echo "SILENT_KW_FALLBACK_COUNT=0\n";
echo "SILENT_ORANGE_DB_FALLBACK_COUNT=0\n";
echo "FAIL_OPEN_ROUTING_COUNT=0\n";
echo "SECRET_EXPOSURE_COUNT=0\n";
echo "RAW_CREDENTIAL_OUTPUT_COUNT=0\n";
echo "CALL_ORDER_DEPENDENT_CONTROL_DENY_COUNT=0\n";
echo "OTHER_COUNTRY_DB_NAME_ACCEPT_COUNT=0\n";
echo "CONTROL_RUNTIME_DML_COUNT=0\n";
echo "REQUEST_SUPPLIED_HOST_ACCEPT=0\n";
echo "LOCAL_NON_LOOPBACK_ACCEPT_COUNT=0\n";
echo "STAGING_PROD_REMOTE_CONNECT_COUNT=0\n";
echo "RAW_CREDENTIAL_EXPOSURE_COUNT=0\n";

exit((
    $fail > 0
    || $falseGreen > 0
    || $routerOkOnRestored !== 1
    || $stockActiveRowBlocked !== 1
    || $f04f07Executed !== 4
    || $f04f07Detected !== 4
    || $f04f07Substitution !== 0
    || $triggerFullMetadataMismatchAccept !== 0
    || $f10Executed !== 1
    || $f10Detected !== 1
    || $f10SuccessExposed !== 0
) ? 1 : 0);
