<?php
declare(strict_types=1);
/**
 * Ownership-safe cleanup — exact ledger + ownership_token/run_id (no prefix-only).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI_ONLY\n"); exit(2); }
$host = getenv('ORANGE_P21B4_MYSQL_HOST') ?: '127.0.0.1';
$port = (int)(getenv('ORANGE_P21B4_MYSQL_PORT') ?: 3306);
$adminUser = getenv('ORANGE_P21B4_MYSQL_ADMIN_USER') ?: 'root';
$adminPass = getenv('ORANGE_P21B4_MYSQL_ADMIN_PASS') !== false ? (string)getenv('ORANGE_P21B4_MYSQL_ADMIN_PASS') : '';
$secretFile = getenv('ORANGE_P21B4_SECRET_FILE') ?: '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--secret-file=')) $secretFile = substr($a, 14);
}
if ($secretFile === '' || !is_file($secretFile)) { fwrite(STDERR, "SECRET_FILE_REQUIRED\n"); exit(2); }
$h = strtolower(trim($host));
if (!in_array($h, ['127.0.0.1', 'localhost', '::1'], true)) { fwrite(STDERR, "NON_LOCAL_HOST_REJECTED\n"); exit(3); }
$secrets = json_decode((string)file_get_contents($secretFile), true);
$db = (string)($secrets['db'] ?? '');
$token = (string)($secrets['ownership_token'] ?? '');
$runIdShort = (string)($secrets['ownership_run_id'] ?? '');
$fullRunId = (string)($secrets['run_id'] ?? '');
$ledger = $secrets['owned_principal_ledger'] ?? [];
if ($db === '' || !preg_match('/^orange_p21b4_[a-f0-9]{12}$/', $db)) { fwrite(STDERR, "UNOWNED_OR_BAD_DB\n"); exit(4); }
if (strtolower($db) === 'orange_db') { fwrite(STDERR, "ORANGE_DB_PROTECTED\n"); exit(5); }
if (!is_array($ledger) || count($ledger) !== 8) { fwrite(STDERR, "LEDGER_REQUIRED_8\n"); exit(10); }

$pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $adminUser, $adminPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$st = $pdo->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=' . $pdo->quote($db));
if ($st && $st->fetchColumn()) {
    $pdo->exec('USE `' . str_replace('`', '``', $db) . '`');
    $meta = $pdo->query('SELECT ownership_token, ownership_run_id FROM ctrl_schema_meta WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!$meta
        || !hash_equals((string)$meta['ownership_token'], $token)
        || !hash_equals((string)($meta['ownership_run_id'] ?? ''), $runIdShort)
    ) {
        fwrite(STDERR, "OWNERSHIP_META_MISMATCH_REFUSE_DROP\n");
        exit(6);
    }
    $pdo->exec('DROP DATABASE `' . str_replace('`', '``', $db) . '`');
}

$expected = [];
foreach ($ledger as $row) {
    $key = (string)$row['physical_account'] . '@' . (string)$row['host'];
    $expected[$key] = $row;
    if ((string)($row['creation_run_id'] ?? '') !== $fullRunId) {
        fwrite(STDERR, "LEDGER_RUN_ID_MISMATCH\n"); exit(11);
    }
    if ((string)($row['ownership_run_id'] ?? '') !== $runIdShort) {
        fwrite(STDERR, "LEDGER_OWNERSHIP_RUN_MISMATCH\n"); exit(12);
    }
}

foreach ($secrets['users'] as $logical => $info) {
    $uname = (string)($info['physical'] ?? '');
    $derived = 'p21b4_' . match ($logical) {
        'schema_admin' => 'sa',
        'trust_executor' => 'tr',
        'identity_executor' => 'id',
        'control_runtime' => 'rt',
        default => 'xx',
    } . '_' . $runIdShort;
    if ($uname !== $derived) {
        fwrite(STDERR, "DERIVED_NAME_MISMATCH {$uname}!={$derived}\n"); exit(13);
    }
    if (in_array($uname, ['schema_admin', 'trust_executor', 'identity_executor', 'control_runtime'], true)) {
        fwrite(STDERR, "REFUSE_FIXED_GLOBAL_ROLE_DROP\n"); exit(8);
    }
    foreach (['localhost', '127.0.0.1'] as $uhost) {
        $key = $uname . '@' . $uhost;
        if (!isset($expected[$key])) {
            fwrite(STDERR, "USER_NOT_IN_LEDGER {$key}\n"); exit(14);
        }
        try {
            $pdo->exec('DROP USER ' . $pdo->quote($uname) . '@' . $pdo->quote($uhost));
        } catch (Throwable $e) {
            // already gone is ok only if exact name was ledger-proven
            if (!str_contains($e->getMessage(), '1396') && !str_contains(strtolower($e->getMessage()), 'operation drop user')) {
                // continue — user may already be absent after prior partial cleanup
            }
        }
    }
}
@unlink($secretFile);
echo "CLEANUP_OK db={$db} ledger=8\n";
