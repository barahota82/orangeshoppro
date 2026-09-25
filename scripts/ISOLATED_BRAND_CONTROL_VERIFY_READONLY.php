<?php
declare(strict_types=1);

/**
 * PREPARE ONLY — do not execute in the V2 preparation task.
 *
 * Later Owner-authorized read-only proof of clean start:
 *   php ISOLATED_BRAND_CONTROL_VERIFY_READONLY.php --sqlite=ABS_PATH
 *
 * Opens SQLite read-only. No INSERT/UPDATE/DELETE/CREATE.
 * Never opens MySQL. Never Rev6.
 *
 * @see BRAND_CONTROL_CLEAN_STATE_CONTRACT.md
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$sqlite = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--sqlite=')) {
        $sqlite = (string) substr($arg, 9);
    }
}
$sqlite = trim($sqlite);
if ($sqlite === '' || !is_file($sqlite)) {
    fwrite(STDERR, "REFUSE: existing --sqlite= file required\n");
    exit(3);
}

$pdo = new PDO('sqlite:' . $sqlite, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->query('PRAGMA query_only = ON');

function vcount(PDO $pdo, string $table): int
{
    $st = $pdo->query('SELECT COUNT(*) FROM ' . $table);
    return (int) $st->fetchColumn();
}

$needTables = [
    'ctrl_locales',
    'orange_brand_identity_versions',
    'orange_brand_identity_translations',
    'orange_brand_asset_objects',
    'orange_brand_slot_versions',
    'orange_brand_releases',
    'orange_brand_release_slots',
    'orange_brand_identity_audit_events',
];
$have = [];
$st = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $have[] = (string) $row['name'];
}

$checks = [];
$fail = 0;
$add = static function (array &$checks, int &$fail, string $id, bool $ok, string $detail): void {
    $checks[] = ['id' => $id, 'pass' => $ok, 'detail' => $detail];
    if (!$ok) {
        $fail++;
    }
};

foreach ($needTables as $t) {
    $add($checks, $fail, 'table_' . $t, in_array($t, $have, true), in_array($t, $have, true) ? 'present' : 'MISSING');
}

$loc = $pdo->query('SELECT locale_code, is_active_global FROM ctrl_locales ORDER BY locale_code')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$wantLoc = [
    ['locale_code' => 'ar', 'is_active_global' => 1],
    ['locale_code' => 'en', 'is_active_global' => 1],
    ['locale_code' => 'fil', 'is_active_global' => 1],
    ['locale_code' => 'hi', 'is_active_global' => 1],
];
$locNorm = [];
foreach ($loc as $r) {
    $locNorm[] = ['locale_code' => (string) $r['locale_code'], 'is_active_global' => (int) $r['is_active_global']];
}
$add($checks, $fail, 'locales_exact_four_active', $locNorm === $wantLoc, json_encode($locNorm, JSON_UNESCAPED_UNICODE));

$emptyTables = [
    'orange_brand_identity_versions',
    'orange_brand_identity_translations',
    'orange_brand_asset_objects',
    'orange_brand_slot_versions',
    'orange_brand_releases',
    'orange_brand_release_slots',
    'orange_brand_identity_audit_events',
];
$counts = [];
foreach ($emptyTables as $t) {
    $n = vcount($pdo, $t);
    $counts[$t] = $n;
    $add($checks, $fail, 'empty_' . $t, $n === 0, 'count=' . $n);
}

$cur = 0;
if (in_array('orange_brand_releases', $have, true)) {
    $cur = (int) $pdo->query('SELECT COUNT(*) FROM orange_brand_releases WHERE current_flag = 1')->fetchColumn();
}
$add($checks, $fail, 'zero_current_flag', $cur === 0, 'current_flag_rows=' . $cur);

$active = 0;
if (in_array('orange_brand_releases', $have, true)) {
    $active = (int) $pdo->query("SELECT COUNT(*) FROM orange_brand_releases WHERE state = 'ACTIVE'")->fetchColumn();
}
$add($checks, $fail, 'zero_active_releases', $active === 0, 'active=' . $active);

$out = [
    'ok' => $fail === 0,
    'sqlite' => $sqlite,
    'bytes' => filesize($sqlite),
    'fail_count' => $fail,
    'counts' => $counts,
    'checks' => $checks,
    'writes' => 0,
    'mysql' => 'NOT_USED',
    'rev6' => 'NOT_RUN',
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
exit($fail === 0 ? 0 : 8);
