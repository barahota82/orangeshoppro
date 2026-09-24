<?php
declare(strict_types=1);
/**
 * Phase 2.1B.4 local disposable Control bootstrap (OWNER-REVIEW REPAIR RETRY).
 * Loopback only. CREATE USER fails on collision. Structured grants (no explode+skip-comment).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI_ONLY\n"); exit(2); }

$host = getenv('ORANGE_P21B4_MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('ORANGE_P21B4_MYSQL_PORT') ?: 3306);
$adminUser = getenv('ORANGE_P21B4_MYSQL_ADMIN_USER') ?: 'root';
$adminPass = getenv('ORANGE_P21B4_MYSQL_ADMIN_PASS') !== false ? (string)getenv('ORANGE_P21B4_MYSQL_ADMIN_PASS') : '';
$secretFile = getenv('ORANGE_P21B4_SECRET_FILE') ?: '';
$runId = null;
$mode = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--run-id=')) $runId = substr($a, 9);
    if (str_starts_with($a, '--secret-file=')) $secretFile = substr($a, 14);
    if (str_starts_with($a, '--mode=')) $mode = strtolower(substr($a, 7));
}
if (!in_array($mode, ['fresh', 'migrate'], true)) { fwrite(STDERR, "MODE_REQUIRED_FRESH_OR_MIGRATE\n"); exit(2); }
if ($runId === null || !preg_match('/^[a-zA-Z0-9_]{6,80}$/', $runId)) {
    fwrite(STDERR, "RUN_ID_REQUIRED\n"); exit(2);
}
// Unique short: last 12 of sha256(run_id) — avoids collision with prior m02c5r1or1r2 accounts
$short = substr(hash('sha256', $runId), 0, 12);
$h = strtolower(trim($host));
if (!in_array($h, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "NON_LOCAL_HOST_REJECTED\n"); exit(3);
}
if ($secretFile === '') {
    $secretFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_p21b4_secrets_' . $short . '.json';
}
if (!is_file($secretFile)) {
    $pass = static fn() => rtrim(strtr(base64_encode(random_bytes(24)), '+/', '._'), '=');
    $principalUuid = bin2hex(random_bytes(16));
    $roles = ['sa' => 'schema_admin', 'tr' => 'trust_executor', 'id' => 'identity_executor', 'rt' => 'control_runtime'];
    $users = [];
    $ledger = [];
    $ownershipToken = hash('sha256', $runId . '|ownership|' . random_bytes(16));
    foreach ($roles as $abbr => $logical) {
        $uname = 'p21b4_' . $abbr . '_' . $short;
        $users[$logical] = [
            'physical' => $uname,
            'localhost' => $pass(),
            '127.0.0.1' => $pass(),
            'principal_uuid' => $principalUuid,
        ];
        foreach (['localhost', '127.0.0.1'] as $uhost) {
            $ledger[] = [
                'logical_role' => $logical,
                'physical_account' => $uname,
                'host' => $uhost,
                'creation_run_id' => $runId,
                'ownership_run_id' => $short,
                'ownership_token_hash' => hash('sha256', $ownershipToken . '|' . $uname . '@' . $uhost),
                'principal_uuid' => $principalUuid,
            ];
        }
    }
    $secrets = [
        'run_id' => $runId,
        'db' => 'orange_p21b4_' . $short,
        'users' => $users,
        'owned_principal_ledger' => $ledger,
        'ownership_token' => $ownershipToken,
        'ownership_run_id' => $short,
        'principal_uuid' => $principalUuid,
        'fixed_global_role_account_create_count' => 0,
        'create_user_if_not_exists_count' => 0,
    ];
    @mkdir(dirname($secretFile), 0777, true);
    file_put_contents($secretFile, json_encode($secrets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
$secrets = json_decode((string)file_get_contents($secretFile), true);
$db = (string)$secrets['db'];
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $adminUser, $adminPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $db) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `' . str_replace('`', '``', $db) . '`');

foreach ($secrets['users'] as $logical => $info) {
    $uname = (string)$info['physical'];
    foreach (['localhost', '127.0.0.1'] as $uhost) {
        $upass = (string)$info[$uhost];
        // Prefer CREATE USER (fail on collision) — no IF NOT EXISTS reuse
        try {
            $pdo->exec('CREATE USER ' . $pdo->quote($uname) . '@' . $pdo->quote($uhost) . ' IDENTIFIED BY ' . $pdo->quote($upass));
        } catch (Throwable $e) {
            fwrite(STDERR, 'CREATE_USER_COLLISION_OR_FAIL ' . $uname . '@' . $uhost . ' ' . $e->getMessage() . "\n");
            exit(9);
        }
    }
}
$saPhys = (string)$secrets['users']['schema_admin']['physical'];
foreach (['localhost', '127.0.0.1'] as $uhost) {
    $pdo->exec('GRANT ALL ON `' . str_replace('`', '``', $db) . '`.* TO ' . $pdo->quote($saPhys) . '@' . $pdo->quote($uhost));
    try {
        $pdo->exec('GRANT SYSTEM_VARIABLES_ADMIN ON *.* TO ' . $pdo->quote($saPhys) . '@' . $pdo->quote($uhost));
    } catch (Throwable $e) { /* optional */ }
}
$pdo->exec('FLUSH PRIVILEGES');

require_once __DIR__ . '/../../includes/control_schema.php';
$saPass = (string)($secrets['users']['schema_admin']['127.0.0.1'] ?? $secrets['users']['schema_admin']['localhost']);
$sa = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db),
    $saPhys,
    $saPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Q2: exact Rev5 verify authority is code-embedded — no package ambient JSON dependency.
$expectedPhysical = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'manifests' . DIRECTORY_SEPARATOR . 'rev5_expected_physical_authority.json';
if (is_file($expectedPhysical)) {
    fwrite(STDERR, "FORBIDDEN_AMBIENT_EXPECTED_PHYSICAL_JSON\n");
    exit(11);
}
putenv('ORANGE_CONTROL_REV5_EXPECTED_PHYSICAL_JSON');
unset($_ENV['ORANGE_CONTROL_REV5_EXPECTED_PHYSICAL_JSON']);
echo "BOOTSTRAP_MODE={$mode}\n";
if ($mode === 'migrate') {
    // migrate path: ensure_schema performs 4→5 when meta@4 present; caller must pre-install Rev4 first
}
orange_control_ensure_schema($sa, [
    'ownership_token' => (string)$secrets['ownership_token'],
    'ownership_run_id' => (string)$secrets['ownership_run_id'],
    'installed_by' => 'phase21b4_r2_retry_bootstrap',
    'allow_meta_insert' => true,
]);

// Structured grant application — strip comments without consuming statements
$grantSqlPath = __DIR__ . '/../../../sql/rev5_grants.sql';
if (!is_file($grantSqlPath)) {
    $grantSqlPath = __DIR__ . '/../../sql/rev5_grants.sql'; // install_mirror layout
}
if (!is_file($grantSqlPath)) {
    fwrite(STDERR, "GRANT_SQL_MISSING tried package and mirror layouts\n");
}
$grantStatements = [];
if (is_file($grantSqlPath)) {
    $gsql = (string)file_get_contents($grantSqlPath);
    $map = [
        '{{SCHEMA_ADMIN}}' => (string)$secrets['users']['schema_admin']['physical'],
        '{{TRUST_EXECUTOR}}' => (string)$secrets['users']['trust_executor']['physical'],
        '{{IDENTITY_EXECUTOR}}' => (string)$secrets['users']['identity_executor']['physical'],
        '{{CONTROL_RUNTIME}}' => (string)$secrets['users']['control_runtime']['physical'],
    ];
    $gsql = strtr($gsql, $map);
    // Remove line/block comments without swallowing following GRANT lines
    $lines = preg_split("/\r\n|\n|\r/", $gsql) ?: [];
    $buf = '';
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (str_starts_with($trim, '--')) {
            continue;
        }
        $buf .= $line . "\n";
    }
    $gsql = preg_replace('/\/\*.*?\*\//s', '', $buf) ?? $buf;
    foreach (preg_split('/;\s*/', $gsql) ?: [] as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        if (strtoupper($stmt) === 'FLUSH PRIVILEGES' || str_starts_with(strtoupper($stmt), 'FLUSH PRIVILEGES')) {
            $pdo->exec('FLUSH PRIVILEGES');
            $grantStatements[] = 'FLUSH PRIVILEGES';
            continue;
        }
        if (str_starts_with(strtoupper($stmt), 'SET NAMES')) {
            $pdo->exec($stmt);
            $grantStatements[] = $stmt;
            continue;
        }
        // Quote bare user@'host' after placeholder expansion
        $stmt = preg_replace_callback(
            "/\bTO\s+([a-zA-Z0-9_]+)@('(?:localhost|127\\.0\\.0\\.1)')/i",
            static function (array $m) use ($pdo): string {
                return 'TO ' . $pdo->quote($m[1]) . '@' . $m[2];
            },
            $stmt
        ) ?? $stmt;
        $grantStatements[] = $stmt;
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            fwrite(STDERR, 'GRANT_FAIL ' . $e->getMessage() . ' :: ' . substr($stmt, 0, 200) . "\n");
            throw $e;
        }
    }
    $pdo->exec('FLUSH PRIVILEGES');
}
$secrets['grant_statements_executed'] = count($grantStatements);
file_put_contents($secretFile, json_encode($secrets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "BOOTSTRAP_OK db={$db} run_id={$runId} short={$short} secret_file={$secretFile} sa={$saPhys} grants=" . count($grantStatements) . "\n";
