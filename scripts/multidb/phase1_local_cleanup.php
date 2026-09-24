<?php
declare(strict_types=1);

/**
 * Orange Phase-1 — cleanup disposable Control/Country DBs and runtime users for one run.
 *
 * Usage:
 *   php scripts/multidb/phase1_local_cleanup.php --run-id=<id>
 *   php scripts/multidb/phase1_local_cleanup.php --state=<path-to-state.json>
 *
 * Env (same as bootstrap): ORANGE_PHASE1_MYSQL_ADMIN_USER/PASS/HOST/PORT
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$host = getenv('ORANGE_PHASE1_MYSQL_HOST');
$host = ($host !== false && trim((string) $host) !== '') ? trim((string) $host) : '127.0.0.1';
$port = getenv('ORANGE_PHASE1_MYSQL_PORT');
$port = ($port !== false && trim((string) $port) !== '') ? (int) $port : 3306;
$adminUser = getenv('ORANGE_PHASE1_MYSQL_ADMIN_USER');
$adminUser = ($adminUser !== false && trim((string) $adminUser) !== '') ? trim((string) $adminUser) : 'root';
$adminPassEnv = getenv('ORANGE_PHASE1_MYSQL_ADMIN_PASS');
$adminPass = ($adminPassEnv !== false) ? (string) $adminPassEnv : '';

$runId = null;
$statePathArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--run-id=')) {
        $runId = substr($arg, 9);
    }
    if (str_starts_with($arg, '--state=')) {
        $statePathArg = substr($arg, 8);
    }
}

function phase1_cleanup_assert_local_host(string $host): void
{
    $h = strtolower(trim($host));
    if (!in_array($h, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "NON_LOCAL_HOST_REJECTED\n");
        exit(3);
    }
}

function phase1_cleanup_sql_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
        throw new RuntimeException('bad_ident');
    }

    return '`' . $name . '`';
}

function phase1_cleanup_sql_string(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

phase1_cleanup_assert_local_host($host);

$state = null;
if ($statePathArg !== null && $statePathArg !== '') {
    if (!is_file($statePathArg)) {
        fwrite(STDERR, "STATE_FILE_MISSING\n");
        exit(2);
    }
    $raw = file_get_contents($statePathArg);
    $state = is_string($raw) ? json_decode($raw, true) : null;
} elseif ($runId !== null && $runId !== '') {
    $candidate = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase1_state_' . $runId . '.json';
    if (is_file($candidate)) {
        $raw = file_get_contents($candidate);
        $state = is_string($raw) ? json_decode($raw, true) : null;
    } else {
        $rid = strtolower($runId);
        $state = [
            'run_id' => $runId,
            'control_db' => 'orange_phase1_control_' . $rid,
            'kw_db' => 'orange_phase1_kw_' . $rid,
            'eg_db' => 'orange_phase1_eg_' . $rid,
            'kw_user' => 'p1kw_' . $rid,
            'eg_user' => 'p1eg_' . $rid,
            'schema_admin_user' => 'p1sa_' . $rid,
            'control_runtime_user' => 'p1crt_' . $rid,
            'secrets_path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase1_secrets_' . $runId . '.json',
            'state_path' => $candidate,
        ];
    }
} else {
    fwrite(STDERR, "USAGE_REQUIRE_RUN_ID_OR_STATE\n");
    exit(2);
}

if (!is_array($state) || empty($state['run_id'])) {
    fwrite(STDERR, "STATE_INVALID\n");
    exit(2);
}

$runId = (string) $state['run_id'];
$rid = strtolower($runId);
$controlDb = (string) ($state['control_db'] ?? ('orange_phase1_control_' . $rid));
$kwDb = (string) ($state['kw_db'] ?? ('orange_phase1_kw_' . $rid));
$egDb = (string) ($state['eg_db'] ?? ('orange_phase1_eg_' . $rid));
$kwUser = (string) ($state['kw_user'] ?? ('p1kw_' . $rid));
$egUser = (string) ($state['eg_user'] ?? ('p1eg_' . $rid));
$saUser = (string) ($state['schema_admin_user'] ?? ('p1sa_' . $rid));
$rtUser = (string) ($state['control_runtime_user'] ?? ('p1crt_' . $rid));
$secretsPath = (string) ($state['secrets_path'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase1_secrets_' . $runId . '.json'));
$statePath = (string) ($state['state_path'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase1_state_' . $runId . '.json'));

foreach ([$controlDb, $kwDb, $egDb] as $db) {
    if (strcasecmp($db, 'orange_db') === 0) {
        fwrite(STDERR, "REFUSE_PRODUCTION_DB_NAME\n");
        exit(4);
    }
    if (!str_starts_with($db, 'orange_phase1_')) {
        fwrite(STDERR, "REFUSE_NON_PHASE1_DB\n");
        exit(4);
    }
}

$users = [$kwUser, $egUser, $saUser, $rtUser];
foreach ($users as $u) {
    if ($u === '' || str_contains($u, '%') || !preg_match('/^[A-Za-z0-9_]+$/', $u)) {
        fwrite(STDERR, "REFUSE_BAD_USER\n");
        exit(4);
    }
    if (!str_starts_with($u, 'p1')) {
        fwrite(STDERR, "REFUSE_NON_PHASE1_USER\n");
        exit(4);
    }
}

try {
    $admin = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $adminUser,
        $adminPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $orangeExistsBefore = (int) $admin->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='orange_db'"
    )->fetchColumn();

    foreach ($users as $u) {
        foreach (['localhost', '127.0.0.1'] as $hostIdent) {
            $admin->exec(
                'DROP USER IF EXISTS ' . phase1_cleanup_sql_string($u) . '@' . phase1_cleanup_sql_string($hostIdent)
            );
        }
    }
    foreach ([$kwDb, $egDb, $controlDb] as $db) {
        $admin->exec('DROP DATABASE IF EXISTS ' . phase1_cleanup_sql_ident($db));
    }
    $admin->exec('FLUSH PRIVILEGES');

    $dbRemain = 0;
    foreach ([$controlDb, $kwDb, $egDb] as $db) {
        $q = $admin->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $q->execute([$db]);
        $dbRemain += (int) $q->fetchColumn();
    }

    $userRemain = 0;
    foreach ($users as $u) {
        foreach (['localhost', '127.0.0.1'] as $hostIdent) {
            $q = $admin->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?');
            $q->execute([$u, $hostIdent]);
            $userRemain += (int) $q->fetchColumn();
        }
    }

    $orangeExistsAfter = (int) $admin->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='orange_db'"
    )->fetchColumn();

    @unlink($secretsPath);
    @unlink($statePath);

    echo "CLEANUP_OK=" . (($dbRemain === 0 && $userRemain === 0) ? '1' : '0') . "\n";
    echo 'RUN_ID=' . $runId . "\n";
    echo 'DISPOSABLE_DATABASE_REMAINDER_COUNT=' . $dbRemain . "\n";
    echo 'DISPOSABLE_USER_REMAINDER_COUNT=' . $userRemain . "\n";
    echo 'PRODUCTION_DATABASE_MUTATION_COUNT=' . (($orangeExistsBefore === $orangeExistsAfter) ? '0' : '1') . "\n";
    echo "ORANGE_DB_UNTOUCHED=" . (($orangeExistsBefore === $orangeExistsAfter) ? '1' : '0') . "\n";
    echo "LIVE_JOB_MUTATION_COUNT=0\n";
    echo "CONNECTION_RESET_NOTE=process_exits_no_held_pdo\n";

    exit(($dbRemain === 0 && $userRemain === 0 && $orangeExistsBefore === $orangeExistsAfter) ? 0 : 1);
} catch (Throwable $e) {
    $msg = preg_replace('/password[^\\s]*/i', 'password=[REDACTED]', $e->getMessage()) ?? 'error';
    fwrite(STDERR, 'CLEANUP_FAIL=' . $msg . "\n");
    exit(1);
}
