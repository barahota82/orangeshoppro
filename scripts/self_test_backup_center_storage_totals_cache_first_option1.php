<?php

declare(strict_types=1);

/**
 * Option 1 — cache-first non-blocking storage totals.
 * Contained fixtures only. No Backup/Verify/DRV/Restore execution. No Production BackupRoot.
 */

$projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$admin = $projectRoot . '/includes/backup/backup_admin.php';
$list = $projectRoot . '/admin/api/backup/list.php';
$ui = $projectRoot . '/admin/pages/backup_center.php';
$config = $projectRoot . '/config.php';
$bootstrap = $projectRoot . '/admin/api/backup/_bootstrap.php';
$isWindows = DIRECTORY_SEPARATOR === '\\';

$pass = 0;
$fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
    if ($cond) {
        echo "PASS $label\n";
        $pass++;
    } else {
        echo "FAIL $label\n";
        $fail++;
    }
};

require_once $admin;

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_opt1_' . bin2hex(random_bytes(6));
mkdir($base, 0777, true);

$mkTree = static function (string $root, int $files): void {
    if (!is_dir($root)) {
        mkdir($root, 0777, true);
    }
    for ($i = 0; $i < $files; $i++) {
        $sub = $root . DIRECTORY_SEPARATOR . 'd' . ($i % 10);
        if (!is_dir($sub)) {
            mkdir($sub, 0777, true);
        }
        // Marker file — any scan of this tree would touch these paths.
        file_put_contents($sub . DIRECTORY_SEPARATOR . 'TRAP_' . $i . '.bin', str_repeat('Z', 256));
    }
};

$inv = static function (): array {
    return [
        'countries_with_packages' => 1,
        'stored_country_packages_total' => 2,
        'full_snapshots_total' => 3,
    ];
};

$sigFor = static function (string $root, array $inventory): array {
    $snapshotsDir = $root . DIRECTORY_SEPARATOR . 'snapshots';
    $countryRoot = $root . DIRECTORY_SEPARATOR . 'country_packages';
    $logsDir = $root . DIRECTORY_SEPARATOR . 'logs';

    return [
        'snapshots_mtime' => is_dir($snapshotsDir) ? (int) (filemtime($snapshotsDir) ?: 0) : 0,
        'country_mtime' => is_dir($countryRoot) ? (int) (filemtime($countryRoot) ?: 0) : 0,
        'logs_mtime' => is_dir($logsDir) ? (int) (filemtime($logsDir) ?: 0) : 0,
        'full_snapshots_total' => $inventory['full_snapshots_total'],
        'stored_country_packages_total' => $inventory['stored_country_packages_total'],
        'countries_with_packages' => $inventory['countries_with_packages'],
    ];
};

$writeCache = static function (string $root, array $signature, array $storage): void {
    $locks = $root . DIRECTORY_SEPARATOR . 'locks';
    if (!is_dir($locks)) {
        mkdir($locks, 0777, true);
    }
    $payload = [
        'version' => 1,
        'signature' => $signature,
        'storage' => $storage,
        'cached_at' => gmdate('c'),
    ];
    file_put_contents(
        $locks . DIRECTORY_SEPARATOR . 'admin_ui_storage_cache.json',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );
};

$completeStorage = [
    'snapshots_bytes' => 111,
    'country_packages_bytes' => 222,
    'logs_bytes' => 333,
    'total_bytes' => 666,
    'snapshots_human' => '111 B',
    'country_packages_human' => '222 B',
    'logs_human' => '333 B',
    'total_human' => '666 B',
];

$timeMs = static function (callable $fn): array {
    $t0 = microtime(true);
    $r = $fn();

    return ['ms' => (int) round((microtime(true) - $t0) * 1000), 'r' => $r];
};

$scanProbe = static function (string $root): int {
    // Count TRAP_* mtimes as a crude "were trees walked?" signal via access after call —
    // Option 1 must never call dir_size; we also assert source text and that trap dirs stay unused.
    $n = 0;
    foreach (['snapshots', 'country_packages', 'logs'] as $d) {
        $path = $root . DIRECTORY_SEPARATOR . $d;
        if (!is_dir($path)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fi) {
            if ($fi->isFile() && str_starts_with($fi->getFilename(), 'TRAP_')) {
                $n++;
            }
        }
    }

    return $n;
};

// --- Static contract proofs ---
$srcAdmin = (string) file_get_contents($admin);
$srcList = (string) file_get_contents($list);
$srcUi = (string) file_get_contents($ui);
$dirSizeFn = '';
if (preg_match('/function orange_backup_admin_dir_size_bytes\(string \$dir\): int\s*\{.*?^\}/ms', $srcAdmin, $m)) {
    $dirSizeFn = $m[0];
}
$collectFn = '';
if (preg_match('/function orange_backup_admin_collect_storage_totals\(string \$backupRoot, \?array \$inventory = null\): array\s*\{.*?^\}/ms', $srcAdmin, $m2)) {
    $collectFn = $m2[0];
}

$ok($dirSizeFn !== '' && str_contains($dirSizeFn, 'RecursiveDirectoryIterator'), 'S1 dir_size still recursive (unchanged role)');
$ok(!str_contains($collectFn, 'orange_backup_admin_dir_size_bytes'), 'S2 collect never calls dir_size');
$ok(!str_contains($collectFn, 'file_put_contents'), 'S3 collect never writes cache');
$ok(!str_contains($collectFn, 'mkdir'), 'S4 collect never mkdir locks');
$ok(str_contains($srcAdmin, 'function orange_backup_admin_storage_totals_neutral'), 'S5 neutral helper present');
$ok(substr_count($srcList, 'orange_backup_admin_collect_storage_totals(') === 1, 'S6 list caller unchanged count');
$ok(str_contains($srcList, 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1'), 'S7 B1 marker still in list.php');
$ok(str_contains($srcUi, "st.total_human || '—'"), 'S8 UI neutral || em-dash for total_human');
$ok(str_contains($srcUi, "st.snapshots_human || '—'"), 'S9 UI neutral snapshots_human');
$ok(str_contains($srcUi, "st.country_packages_human || '—'"), 'S10 UI neutral country_packages_human');
$ok(str_contains($srcUi, "st.logs_human || '—'"), 'S11 UI neutral logs_human');
$ok(!str_contains((string) file_get_contents($config), 'storage_totals_neutral'), 'S12 config untouched');
$ok(!str_contains((string) file_get_contents($bootstrap), 'storage_totals_neutral'), 'S13 bootstrap untouched');

// A. Cache hit
$hitRoot = $base . DIRECTORY_SEPARATOR . 'hit';
$mkTree($hitRoot . DIRECTORY_SEPARATOR . 'snapshots', 40);
$mkTree($hitRoot . DIRECTORY_SEPARATOR . 'country_packages', 40);
$mkTree($hitRoot . DIRECTORY_SEPARATOR . 'logs', 40);
$inventory = $inv();
$sig = $sigFor($hitRoot, $inventory);
$writeCache($hitRoot, $sig, $completeStorage);
$trapBefore = $scanProbe($hitRoot);
$a = $timeMs(static fn () => orange_backup_admin_collect_storage_totals($hitRoot, $inventory));
$ok(($a['r']['storage_status'] ?? '') === 'complete', 'A1 status complete');
$ok(($a['r']['storage_is_complete'] ?? false) === true, 'A2 is_complete');
$ok(($a['r']['storage_source'] ?? '') === 'cache', 'A3 source cache');
$ok(($a['r']['total_bytes'] ?? null) === 666, 'A4 exact total_bytes');
$ok(($a['r']['total_human'] ?? '') === '666 B', 'A5 exact total_human');
$ok($a['ms'] <= 250, 'A6 hit <=250ms got=' . $a['ms']);
$ok(is_file($hitRoot . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'admin_ui_storage_cache.json'), 'A7 cache file still present');
$mtime1 = filemtime($hitRoot . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'admin_ui_storage_cache.json');
usleep(20000);
$a2 = orange_backup_admin_collect_storage_totals($hitRoot, $inventory);
$mtime2 = filemtime($hitRoot . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'admin_ui_storage_cache.json');
$ok($mtime1 === $mtime2, 'A8 no cache rewrite on hit');
$ok($scanProbe($hitRoot) === $trapBefore, 'A9 trap file count unchanged after hit');

// B. Cache missing + large trap tree
$missRoot = $base . DIRECTORY_SEPARATOR . 'miss';
$mkTree($missRoot . DIRECTORY_SEPARATOR . 'snapshots', 200);
$mkTree($missRoot . DIRECTORY_SEPARATOR . 'country_packages', 200);
$mkTree($missRoot . DIRECTORY_SEPARATOR . 'logs', 200);
$b = $timeMs(static fn () => orange_backup_admin_collect_storage_totals($missRoot, $inv()));
$ok(($b['r']['storage_status'] ?? '') === 'unavailable', 'B1 unavailable');
$ok(($b['r']['storage_reason'] ?? '') === 'cache_missing', 'B2 cache_missing');
$ok(($b['r']['storage_is_complete'] ?? true) === false, 'B3 not complete');
$ok(($b['r']['total_human'] ?? 'x') === '', 'B4 neutral human empty');
$ok(array_key_exists('total_bytes', $b['r']) && $b['r']['total_bytes'] === null, 'B5 bytes null not zero');
$ok(array_key_exists('snapshots_bytes', $b['r']) && $b['r']['snapshots_bytes'] === null, 'B6 snapshots_bytes null');
$ok($b['ms'] <= 250, 'B7 miss <=250ms got=' . $b['ms']);
$ok(!is_file($missRoot . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'admin_ui_storage_cache.json'), 'B8 no cache write');

// UI neutral simulation
$uiVal = ($b['r']['total_human'] ?? '') ?: '—';
$ok($uiVal === '—', 'B9 UI would show em-dash');

// C. Stale signature
$staleRoot = $base . DIRECTORY_SEPARATOR . 'stale';
$mkTree($staleRoot . DIRECTORY_SEPARATOR . 'snapshots', 30);
$mkTree($staleRoot . DIRECTORY_SEPARATOR . 'country_packages', 30);
$mkTree($staleRoot . DIRECTORY_SEPARATOR . 'logs', 30);
$badSig = $sigFor($staleRoot, $inv());
$badSig['full_snapshots_total'] = 99999;
$writeCache($staleRoot, $badSig, $completeStorage);
$c = $timeMs(static fn () => orange_backup_admin_collect_storage_totals($staleRoot, $inv()));
$ok(($c['r']['storage_status'] ?? '') === 'stale', 'C1 status stale');
$ok(($c['r']['storage_reason'] ?? '') === 'signature_mismatch', 'C2 signature_mismatch');
$ok(($c['r']['total_human'] ?? 'x') === '', 'C3 stale numbers not in human fields');
$ok(array_key_exists('total_bytes', $c['r']) && $c['r']['total_bytes'] === null, 'C4 stale bytes not presented');
$ok($c['ms'] <= 250, 'C5 stale <=250ms');

// D. Malformed / incomplete
$malRoot = $base . DIRECTORY_SEPARATOR . 'mal';
mkdir($malRoot . DIRECTORY_SEPARATOR . 'locks', 0777, true);
mkdir($malRoot . DIRECTORY_SEPARATOR . 'snapshots', 0777, true);
mkdir($malRoot . DIRECTORY_SEPARATOR . 'country_packages', 0777, true);
mkdir($malRoot . DIRECTORY_SEPARATOR . 'logs', 0777, true);
file_put_contents($malRoot . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'admin_ui_storage_cache.json', '{not-json');
$d1 = orange_backup_admin_collect_storage_totals($malRoot, $inv());
$ok(($d1['storage_reason'] ?? '') === 'cache_malformed', 'D1 malformed');

$incRoot = $base . DIRECTORY_SEPARATOR . 'inc';
mkdir($incRoot . DIRECTORY_SEPARATOR . 'locks', 0777, true);
mkdir($incRoot . DIRECTORY_SEPARATOR . 'snapshots', 0777, true);
mkdir($incRoot . DIRECTORY_SEPARATOR . 'country_packages', 0777, true);
mkdir($incRoot . DIRECTORY_SEPARATOR . 'logs', 0777, true);
$incSig = $sigFor($incRoot, $inv());
$writeCache($incRoot, $incSig, ['total_bytes' => 1, 'total_human' => '1 B']); // incomplete
$d2 = orange_backup_admin_collect_storage_totals($incRoot, $inv());
$ok(($d2['storage_reason'] ?? '') === 'cache_incomplete', 'D2 incomplete payload');
$ok(($d2['storage_is_complete'] ?? true) === false, 'D3 incomplete not complete');

// E. Unreadable — best effort on Windows
$unRoot = $base . DIRECTORY_SEPARATOR . 'unreadable';
$mkTree($unRoot . DIRECTORY_SEPARATOR . 'snapshots', 5);
mkdir($unRoot . DIRECTORY_SEPARATOR . 'country_packages', 0777, true);
mkdir($unRoot . DIRECTORY_SEPARATOR . 'logs', 0777, true);
$unSig = $sigFor($unRoot, $inv());
$writeCache($unRoot, $unSig, $completeStorage);
$cacheFile = $unRoot . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'admin_ui_storage_cache.json';
$user = getenv('USERNAME') ?: 'Everyone';
if ($isWindows) {
    @exec('icacls ' . escapeshellarg($cacheFile) . ' /deny ' . escapeshellarg($user) . ':(R) >NUL 2>&1');
} else {
    @chmod($cacheFile, 0000);
}
$e = orange_backup_admin_collect_storage_totals($unRoot, $inv());
$ok(
    in_array(($e['storage_reason'] ?? ''), ['cache_unreadable', 'cache_malformed', 'cache_incomplete', 'unavailable'], true)
    || (($e['storage_is_complete'] ?? true) === false),
    'E1 unreadable yields non-complete safe status reason=' . ($e['storage_reason'] ?? '')
);
if ($isWindows) {
    @exec('icacls ' . escapeshellarg($cacheFile) . ' /reset >NUL 2>&1');
} else {
    @chmod($cacheFile, 0644);
}

// F. Unwritable locks (file named locks) — no write / no scan
$wf = $base . DIRECTORY_SEPARATOR . 'writefail';
$mkTree($wf . DIRECTORY_SEPARATOR . 'snapshots', 50);
$mkTree($wf . DIRECTORY_SEPARATOR . 'country_packages', 50);
$mkTree($wf . DIRECTORY_SEPARATOR . 'logs', 50);
file_put_contents($wf . DIRECTORY_SEPARATOR . 'locks', 'not-a-dir');
$f = $timeMs(static fn () => orange_backup_admin_collect_storage_totals($wf, $inv()));
$ok(($f['r']['storage_status'] ?? '') === 'unavailable', 'F1 unavailable when no cache');
$ok(!is_dir($wf . DIRECTORY_SEPARATOR . 'locks'), 'F2 locks still not a dir (no mkdir)');
$ok($f['ms'] <= 250, 'F3 writefail path <=250ms');

// G. Traversal trap — inaccessible child would throw if scanned
$trap = $base . DIRECTORY_SEPARATOR . 'trap';
$mkTree($trap . DIRECTORY_SEPARATOR . 'snapshots', 20);
$locked = $trap . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'locked';
$mkTree($locked, 10);
mkdir($trap . DIRECTORY_SEPARATOR . 'country_packages', 0777, true);
mkdir($trap . DIRECTORY_SEPARATOR . 'logs', 0777, true);
if ($isWindows) {
    @exec('icacls ' . escapeshellarg($locked) . ' /deny ' . escapeshellarg($user) . ':(OI)(CI)(R) >NUL 2>&1');
} else {
    @chmod($locked, 0000);
}
$threw = false;
try {
    $g = $timeMs(static fn () => orange_backup_admin_collect_storage_totals($trap, $inv()));
} catch (Throwable $ex) {
    $threw = true;
    $g = ['ms' => 0, 'r' => []];
}
$ok(!$threw, 'G1 no exception on missing cache with inaccessible child');
$ok(($g['r']['storage_reason'] ?? '') === 'cache_missing', 'G2 still cache_missing');
$ok(($g['ms'] ?? 9999) <= 250, 'G3 trap miss bounded');
// stale with trap
$writeCache($trap, ['full_snapshots_total' => -1] + $sigFor($trap, $inv()), $completeStorage);
$threw2 = false;
try {
    $g2 = orange_backup_admin_collect_storage_totals($trap, $inv());
} catch (Throwable $ex) {
    $threw2 = true;
    $g2 = [];
}
$ok(!$threw2 && (($g2['storage_status'] ?? '') === 'stale'), 'G4 stale with trap no throw');

// H. Concurrent-ish sequential requests
$conc = $base . DIRECTORY_SEPARATOR . 'conc';
$mkTree($conc . DIRECTORY_SEPARATOR . 'snapshots', 80);
$mkTree($conc . DIRECTORY_SEPARATOR . 'country_packages', 80);
$mkTree($conc . DIRECTORY_SEPARATOR . 'logs', 80);
$t0 = microtime(true);
$r1 = orange_backup_admin_collect_storage_totals($conc, $inv());
$r2 = orange_backup_admin_collect_storage_totals($conc, $inv());
$r3 = orange_backup_admin_collect_storage_totals($conc, $inv());
$elapsed = (int) round((microtime(true) - $t0) * 1000);
$ok($r1 === $r2 && $r2 === $r3, 'H1 deterministic identical payloads');
$ok(($r1['storage_status'] ?? '') === 'unavailable', 'H2 all unavailable');
$ok($elapsed <= 750, 'H3 three calls bounded got=' . $elapsed);
$ok(json_encode($r1) !== false, 'H4 valid JSON encode');

// I. UI / list / B1 frozen surfaces (source-level)
$ok(!str_contains($srcList, 'storage_totals_neutral'), 'I1 list.php does not reference new helper');
$ok(str_contains($srcList, 'orange_backup_list_b1_shutdown'), 'I2 B1 shutdown still present');
$ok(is_file($ui) && str_contains($srcUi, 'bc_storage_kpis'), 'I3 Backup Center storage KPIs section present');

// J. Signature of collect unchanged
$ok(
    (bool) preg_match('/function orange_backup_admin_collect_storage_totals\(string \$backupRoot, \?array \$inventory = null\): array/', $srcAdmin),
    'J1 signature unchanged'
);

// K. No mutation markers in collect
$ok(!preg_match('/run_full_backup|export_all_recoverable|recovery-check|drv/i', $collectFn), 'K1 no backup engines in collect');

// L. Explicit refresh path (outside list startup) writes the cache.
$refreshRoot = $base . DIRECTORY_SEPARATOR . 'refresh';
$mkTree($refreshRoot . DIRECTORY_SEPARATOR . 'snapshots', 6);
$mkTree($refreshRoot . DIRECTORY_SEPARATOR . 'country_packages', 4);
$mkTree($refreshRoot . DIRECTORY_SEPARATOR . 'logs', 2);
$refresh = orange_backup_admin_refresh_storage_totals_cache($refreshRoot, $inv());
$ok(is_array($refresh) && ($refresh['storage_is_complete'] ?? false) === true, 'L1 refresh writes complete payload');
$ok(is_file($refreshRoot . DIRECTORY_SEPARATOR . 'locks' . DIRECTORY_SEPARATOR . 'admin_ui_storage_cache.json'), 'L2 refresh cache file exists');
$refreshRead = orange_backup_admin_collect_storage_totals($refreshRoot, $inv());
$ok(($refreshRead['storage_source'] ?? '') === 'cache', 'L3 collect reads refreshed cache');
$ok(($refreshRead['total_bytes'] ?? null) === ($refresh['total_bytes'] ?? -1), 'L4 refreshed bytes round-trip');

// Timing route budget on function (list route full HTTP needs env; assert function budgets already)
$ok($a['ms'] <= 250 && $b['ms'] <= 250 && $c['ms'] <= 250, 'PERF function budgets');

// Cleanup
$cleanup = static function (string $path) use (&$cleanup): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path)) {
        @unlink($path);

        return;
    }
    $items = @scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $cleanup($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
};
if ($isWindows) {
    @exec('icacls ' . escapeshellarg($locked) . ' /reset >NUL 2>&1');
} else {
    @chmod($locked, 0777);
}
$cleanup($base);

echo "SUMMARY pass=$pass fail=$fail\n";
exit($fail === 0 ? 0 : 1);
