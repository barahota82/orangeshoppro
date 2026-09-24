<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.1 — Central Identity schema / revision / dormancy self-test.
 * Exit 0 only when RAW_FAIL=0 and CORE_SKIP=0 and FALSE_GREEN_RISK_COUNT=0.
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
// Open false-green risk owned by this harness: F-G3 (rev3 silent self-heal).
$falseGreen = 1;
$pass = 0;

function t_assert(bool $cond, string $name, int &$rawFail, int &$pass): void
{
    if ($cond) {
        echo "PASS {$name}\n";
        $pass++;
    } else {
        echo "FAIL {$name}\n";
        $rawFail++;
    }
}

$runId = bin2hex(random_bytes(6));
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($bootstrap) . ' --run-id=' . escapeshellarg($runId);
exec($cmd . ' 2>&1', $bootOut, $bootCode);
echo implode("\n", $bootOut) . "\n";
t_assert($bootCode === 0, 'bootstrap_exit_0', $rawFail, $pass);

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21b1_state_' . $runId . '.json';
$secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21b1_secrets_' . $runId . '.json';
$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
$secrets = is_file($secretsPath) ? json_decode((string) file_get_contents($secretsPath), true) : null;
t_assert(is_array($state) && is_array($secrets), 'state_secrets_loaded', $rawFail, $pass);
t_assert(
    is_array($secrets) && !str_contains(implode("\n", $bootOut), (string) ($secrets['schema_admin_password'] ?? '___')),
    'secret_not_printed',
    $rawFail,
    $pass
);

$host = (string) ($state['host'] ?? '127.0.0.1');
$port = (int) ($state['port'] ?? 3306);
$controlDb = (string) ($state['control_db'] ?? '');
$saUser = (string) ($state['schema_admin_user'] ?? '');
$saPass = (string) ($secrets['schema_admin_password'] ?? '');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $saUser,
        $saPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    t_assert((int) ORANGE_CONTROL_SCHEMA_REVISION === 3, 'code_revision_3', $rawFail, $pass);
    $catSrc = (string) file_get_contents($repoRoot . '/includes/catalog_schema.php');
    t_assert(
        (bool) preg_match('/define\(\s*\'ORANGE_CATALOG_SCHEMA_PHP_REVISION\'\s*,\s*124\s*\)/', $catSrc),
        'country_revision_124_unchanged',
        $rawFail,
        $pass
    );

    $trust = orange_control_trust_table_names();
    $ident = orange_control_identity_table_names();
    t_assert(count($trust) === 11, 'trust_table_count_11', $rawFail, $pass);
    t_assert(count($ident) === 6, 'identity_table_count_6', $rawFail, $pass);
    t_assert(count($trust) + count($ident) === 17, 'total_control_tables_17', $rawFail, $pass);

    $tableCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $pdo->quote($controlDb)
        . " AND TABLE_TYPE='BASE TABLE'"
    )->fetchColumn();
    t_assert($tableCount === 17, 'live_table_count_17', $rawFail, $pass);

    foreach (array_merge($trust, $ident) as $t) {
        $c = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $pdo->quote($controlDb)
            . ' AND TABLE_NAME=' . $pdo->quote($t)
        )->fetchColumn();
        t_assert($c === 1, 'table_exists_' . $t, $rawFail, $pass);
    }

    $meta = $pdo->query(
        'SELECT control_schema_revision, schema_manifest_hash,
                country_active_transition_enabled, registry_active_transition_enabled,
                database_replacement_transition_enabled
         FROM ctrl_schema_meta WHERE id=1'
    )->fetch(PDO::FETCH_ASSOC);
    t_assert(is_array($meta) && (int) $meta['control_schema_revision'] === 3, 'meta_revision_3', $rawFail, $pass);
    t_assert(is_array($meta) && (int) $meta['country_active_transition_enabled'] === 0, 'gate_country_0', $rawFail, $pass);
    t_assert(is_array($meta) && (int) $meta['registry_active_transition_enabled'] === 0, 'gate_registry_0', $rawFail, $pass);

    $phys = orange_control_physical_manifest_hash($pdo, $controlDb, 3);
    t_assert(
        is_array($meta) && hash_equals($phys, (string) $meta['schema_manifest_hash']),
        'physical_manifest_hash_exact',
        $rawFail,
        $pass
    );

    $rtn = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=' . $pdo->quote($controlDb)
        . " AND ROUTINE_NAME LIKE 'orange_ctrl_identity_%'"
    )->fetchColumn();
    t_assert($rtn === 10, 'identity_routines_10', $rawFail, $pass);

    $callable = orange_control_identity_callable_routine_names();
    t_assert(count($callable) === 9, 'callable_identity_routines_9', $rawFail, $pass);
    t_assert(
        !in_array('orange_ctrl_identity_emit_event', $callable, true),
        'emit_event_not_in_callable_list',
        $rawFail,
        $pass
    );

    $rawTokCol = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=" . $pdo->quote($controlDb)
        . " AND TABLE_NAME='ctrl_admin_sessions' AND COLUMN_NAME='session_token'"
    )->fetchColumn();
    t_assert($rawTokCol === 0, 'no_raw_session_token_column', $rawFail, $pass);

    $activeReg = (int) ($state['phase21b1_active_registry_count'] ?? -1);
    t_assert($activeReg === 0, 'active_registry_count_0', $rawFail, $pass);
    t_assert((int) ($state['country_database_created_count'] ?? -1) === 0, 'country_db_created_0', $rawFail, $pass);

    // Dormancy: no require of identity from admin/config
    $dormHits = 0;
    foreach (['admin/login.php', 'config.php'] as $rel) {
        $path = $repoRoot . '/' . $rel;
        if (!is_file($path)) {
            continue;
        }
        $src = (string) file_get_contents($path);
        if (str_contains($src, 'control_identity_schema') || str_contains($src, 'orange_control_ensure_schema')) {
            $dormHits++;
        }
    }
    t_assert($dormHits === 0, 'dormancy_no_login_config_require', $rawFail, $pass);

    // Allowlist path proof vs HEAD^ parent of this branch tip is checked in constraints test.
    t_assert(is_file($repoRoot . '/includes/control_identity_schema.php'), 'identity_schema_file_present', $rawFail, $pass);

    // Fail-closed wrong rev: corrupt meta then re-ensure
    $pdo->exec('UPDATE ctrl_schema_meta SET control_schema_revision = 99 WHERE id=1');
    $denied = false;
    try {
        orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
    } catch (OrangeControlTrustException $e) {
        $denied = ($e->errorCode() === 'schema_meta_revision_mismatch');
    }
    t_assert($denied, 'fail_closed_wrong_revision', $rawFail, $pass);
    $pdo->exec('UPDATE ctrl_schema_meta SET control_schema_revision = 3, schema_manifest_hash=' . $pdo->quote($phys) . ' WHERE id=1');

    $pdo->exec('UPDATE ctrl_schema_meta SET schema_manifest_hash=' . $pdo->quote(str_repeat('a', 64)) . ' WHERE id=1');
    $denied2 = false;
    try {
        orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
    } catch (OrangeControlTrustException $e) {
        $denied2 = ($e->errorCode() === 'schema_manifest_physical_mismatch');
    }
    t_assert($denied2, 'fail_closed_wrong_hash', $rawFail, $pass);
    $pdo->exec('UPDATE ctrl_schema_meta SET schema_manifest_hash=' . $pdo->quote($phys) . ' WHERE id=1');

    // F-G3 / MT1: stable rev3 + DROP identity events → partial fail-closed, NO self-heal
    $beforeFg3 = $rawFail;
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('DROP TABLE ctrl_identity_events');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $partialDenied = false;
    $partialCode = '';
    try {
        orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
    } catch (OrangeControlTrustException $e) {
        $partialCode = $e->errorCode();
        $partialDenied = ($partialCode === 'schema_partial_or_incompatible');
    }
    t_assert($partialDenied, 'fg3_partial_events_throws_schema_partial_or_incompatible', $rawFail, $pass);
    $eventsStillGone = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $pdo->quote($controlDb)
        . " AND TABLE_NAME='ctrl_identity_events' AND TABLE_TYPE='BASE TABLE'"
    )->fetchColumn();
    t_assert($eventsStillGone === 0, 'fg3_no_self_heal_events_table', $rawFail, $pass);

    // MT2: restore events via fresh path is forbidden on rev3 — drop a routine instead on a clone path
    // Recreate events only for routine drop probe via SA DDL outside ensure (test fixture).
    // First restore table structure by reading CREATE from install_tables is heavy; instead
    // drop a required identity procedure and re-verify.
    // Re-install identity tables only through a disposable side channel: use migrate helper
    // on a NEW db is covered in constraints; here drop procedure on current after restoring table.
    orange_control_identity_install_tables($pdo); // restore dropped table for further probes
    // Note: install_tables alone is test-harness repair of our intentional DROP; ensure_schema must not.
    $pdo->exec('DROP PROCEDURE IF EXISTS orange_ctrl_identity_admin_upsert');
    $procDenied = false;
    try {
        orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
    } catch (OrangeControlTrustException $e) {
        $procDenied = ($e->errorCode() === 'schema_partial_or_incompatible');
    }
    t_assert($procDenied, 'fg3_partial_routine_throws_schema_partial_or_incompatible', $rawFail, $pass);
    $procGone = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=' . $pdo->quote($controlDb)
        . " AND ROUTINE_NAME='orange_ctrl_identity_admin_upsert'"
    )->fetchColumn();
    t_assert($procGone === 0, 'fg3_no_self_heal_routine', $rawFail, $pass);

    // Restore package so cleanup ownership path still works (bootstrap-owned DB).
    orange_control_identity_install_routines($pdo);
    $physRestored = orange_control_physical_manifest_hash($pdo, $controlDb, 3);
    $pdo->exec('UPDATE ctrl_schema_meta SET schema_manifest_hash=' . $pdo->quote($physRestored) . ' WHERE id=1');
    // Stable verify must succeed without DDL mutation after restore.
    orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
    t_assert(true, 'fg3_stable_verify_after_harness_restore', $rawFail, $pass);

    if ($rawFail === $beforeFg3) {
        $falseGreen--; // F-G3 closed
    }

} catch (Throwable $e) {
    echo 'FAIL schema_body ' . $e->getMessage() . "\n";
    $rawFail++;
}

$cleanCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($cleanup) . ' --run-id=' . escapeshellarg($runId);
exec($cleanCmd . ' 2>&1', $cleanOut, $cleanCode);
echo implode("\n", $cleanOut) . "\n";
t_assert($cleanCode === 0, 'cleanup_exit_0', $rawFail, $pass);

echo "PASS_COUNT={$pass}\n";
echo "RAW_FAIL={$rawFail}\n";
echo "CORE_SKIP={$coreSkip}\n";
echo "FALSE_GREEN_RISK_COUNT={$falseGreen}\n";
exit(($rawFail === 0 && $coreSkip === 0 && $falseGreen === 0) ? 0 : 1);
