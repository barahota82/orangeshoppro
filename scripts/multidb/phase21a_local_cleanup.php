<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1A S3 repair — cleanup disposable Control DB and principals for one run.
 *
 * Validates ownership_token from state/secrets against ctrl_schema_meta when DB still exists.
 * Preserves similarly-prefixed non-owned objects (exact name ownership only).
 *
 * Usage:
 *   php scripts/multidb/phase21a_local_cleanup.php --run-id=<id>
 *   php scripts/multidb/phase21a_local_cleanup.php --state=<path-to-state.json>
 *
 * Env (same as bootstrap): ORANGE_PHASE21A_MYSQL_ADMIN_USER/PASS/HOST/PORT
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
$statePathArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--run-id=')) {
        $runId = substr($arg, 9);
    }
    if (str_starts_with($arg, '--state=')) {
        $statePathArg = substr($arg, 8);
    }
}

function p21a_cleanup_assert_local_host(string $host): void
{
    $h = strtolower(trim($host));
    if (!in_array($h, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "NON_LOCAL_HOST_REJECTED\n");
        exit(3);
    }
}

function p21a_cleanup_sql_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
        throw new RuntimeException('bad_ident');
    }

    return '`' . $name . '`';
}

function p21a_cleanup_sql_string(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

p21a_cleanup_assert_local_host($host);

$state = null;
$secretsPath = null;
$secrets = null;
if ($statePathArg !== null && $statePathArg !== '') {
    if (!is_file($statePathArg)) {
        fwrite(STDERR, "STATE_FILE_MISSING\n");
        exit(2);
    }
    $raw = file_get_contents($statePathArg);
    $state = is_string($raw) ? json_decode($raw, true) : null;
} elseif ($runId !== null && $runId !== '') {
    $candidate = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21a_state_' . $runId . '.json';
    if (is_file($candidate)) {
        $raw = file_get_contents($candidate);
        $state = is_string($raw) ? json_decode($raw, true) : null;
    } else {
        $state = [
            'run_id' => $runId,
            'control_db' => 'orange_phase21a_control_' . strtolower($runId),
            'schema_admin_user' => 'p21a_sa_' . strtolower($runId),
            'provisioner_user' => 'p21a_pr_' . strtolower($runId),
            'control_runtime_user' => 'p21a_rt_' . strtolower($runId),
            'host_idents' => ['localhost', '127.0.0.1'],
        ];
    }
    $secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21a_secrets_' . $runId . '.json';
} else {
    fwrite(STDERR, "NEED_RUN_ID_OR_STATE\n");
    exit(2);
}

if (!is_array($state)) {
    fwrite(STDERR, "STATE_INVALID\n");
    exit(2);
}

$controlDb = (string) ($state['control_db'] ?? '');
$saUser = (string) ($state['schema_admin_user'] ?? '');
$prUser = (string) ($state['provisioner_user'] ?? '');
$rtUser = (string) ($state['control_runtime_user'] ?? '');
$hostIdents = isset($state['host_idents']) && is_array($state['host_idents'])
    ? $state['host_idents']
    : ['localhost', '127.0.0.1'];
$runIdFinal = (string) ($state['run_id'] ?? ($runId ?? ''));
$ownershipToken = (string) ($state['ownership_token'] ?? '');

if ($secretsPath === null && isset($state['secrets_path'])) {
    $secretsPath = (string) $state['secrets_path'];
}
if ($secretsPath !== null && is_file($secretsPath)) {
    $sraw = file_get_contents($secretsPath);
    $secrets = is_string($sraw) ? json_decode($sraw, true) : null;
    if (is_array($secrets) && $ownershipToken === '' && isset($secrets['ownership_token'])) {
        $ownershipToken = (string) $secrets['ownership_token'];
    }
}

if ($controlDb === '' || !preg_match('/^orange_phase21a_control_[a-z0-9]+$/', $controlDb)) {
    fwrite(STDERR, "CONTROL_DB_INVALID\n");
    exit(2);
}

try {
    $admin = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        $adminUser,
        $adminPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $dbExists = (int) $admin->query(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '
        . p21a_cleanup_sql_string($controlDb)
    )->fetchColumn();

    if ($dbExists === 1) {
        if ($ownershipToken === '' || !preg_match('/^[0-9a-f]{64}$/', $ownershipToken)) {
            fwrite(STDERR, "OWNERSHIP_TOKEN_REQUIRED\n");
            exit(2);
        }
        $admin->exec('USE ' . p21a_cleanup_sql_ident($controlDb));
        $metaTok = $admin->query(
            'SELECT ownership_token, ownership_run_id FROM ctrl_schema_meta WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($metaTok)
            || !hash_equals((string) $metaTok['ownership_token'], $ownershipToken)
            || (string) $metaTok['ownership_run_id'] !== strtolower($runIdFinal)
        ) {
            fwrite(STDERR, "OWNERSHIP_TOKEN_MISMATCH\n");
            echo "CLEANUP_REFUSED_NON_OWNED=1\n";
            exit(5);
        }
    }

    // Drop disposable users (both hosts) — exact owned names only.
    foreach ([$saUser, $prUser, $rtUser] as $u) {
        if ($u === '' || !preg_match('/^p21a_(sa|pr|rt)_[a-z0-9]+$/', $u)) {
            continue;
        }
        foreach ($hostIdents as $hi) {
            try {
                $admin->exec(
                    'DROP USER IF EXISTS ' . p21a_cleanup_sql_string($u) . '@' . p21a_cleanup_sql_string((string) $hi)
                );
            } catch (Throwable $e) {
                // continue
            }
        }
    }

    // Drop only the disposable Control DB (routines/triggers drop with schema).
    $admin->exec('DROP DATABASE IF EXISTS ' . p21a_cleanup_sql_ident($controlDb));
    $admin->exec('FLUSH PRIVILEGES');

    $dbRem = (int) $admin->query(
        'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . p21a_cleanup_sql_string($controlDb)
    )->fetchColumn();

    $userRem = 0;
    foreach ([$saUser, $prUser, $rtUser] as $u) {
        if ($u === '') {
            continue;
        }
        $stmt = $admin->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
        $stmt->execute([$u]);
        $userRem += (int) $stmt->fetchColumn();
    }

    $orangeOk = (int) $admin->query(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'orange_db'"
    )->fetchColumn();

    // Similarly-prefixed non-owned objects must remain (collision-safe proof helper count).
    $nearPrefix = (int) $admin->query(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA
         WHERE SCHEMA_NAME LIKE 'orange_phase21a_controlxx_%'
            OR SCHEMA_NAME LIKE 'orange_phase21a_control\\_%' ESCAPE '\\\\'
            AND SCHEMA_NAME <> " . p21a_cleanup_sql_string($controlDb)
    )->fetchColumn();

    if ($secretsPath !== null && is_file($secretsPath)) {
        @unlink($secretsPath);
    }
    $stateFile = isset($state['state_path']) ? (string) $state['state_path'] : (
        $runIdFinal !== ''
            ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21a_state_' . $runIdFinal . '.json'
            : null
    );
    if ($statePathArg !== null && is_file($statePathArg)) {
        @unlink($statePathArg);
    } elseif ($stateFile !== null && is_file($stateFile)) {
        @unlink($stateFile);
    }

    $secretRem = ($secretsPath !== null && is_file($secretsPath)) ? 1 : 0;

    echo "DISPOSABLE_DATABASE_REMAINDER_COUNT={$dbRem}\n";
    echo "DISPOSABLE_USER_REMAINDER_COUNT={$userRem}\n";
    echo "TEMP_SECRET_REMAINDER_COUNT={$secretRem}\n";
    echo 'ORANGE_DB_UNTOUCHED=' . ($orangeOk > 0 ? '1' : '0') . "\n";
    echo "NEAR_PREFIX_NON_OWNED_PRESERVED_PROBE={$nearPrefix}\n";
    echo 'CLEANUP_OK=' . (($dbRem === 0 && $userRem === 0 && $secretRem === 0) ? '1' : '0') . "\n";
    exit(($dbRem === 0 && $userRem === 0 && $secretRem === 0) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'CLEANUP_FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
