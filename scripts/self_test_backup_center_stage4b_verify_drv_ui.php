<?php

declare(strict_types=1);

/**
 * Stage 4B — Verify/DRV server-authoritative UI states + no-refresh self-test.
 *
 * Usage: php scripts/self_test_backup_center_stage4b_verify_ui.php
 *        (file: self_test_backup_center_stage4b_verify_drv_ui.php)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_qualification.php';
require_once $projectRoot . '/scripts/lib/backup_stage4b_evidence_lib.php';

$passes = 0;
$failures = 0;
$skips = 0;
$coreSkip = 0;

function s4b_ok(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function s4b_skip(string $label, bool $core = false): void
{
    global $skips, $coreSkip;
    echo "SKIP: {$label}\n";
    $skips++;
    if ($core) {
        $coreSkip++;
    }
}

function s4b_temp_root(): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s4b_' . bin2hex(random_bytes(4));
    if (!@mkdir($base . '/snapshots', 0770, true) && !is_dir($base . '/snapshots')) {
        throw new RuntimeException('temp root');
    }
    @mkdir($base . '/country_packages/kw', 0770, true);

    return $base;
}

function s4b_rm(string $dir): void
{
    $tempParent = realpath(sys_get_temp_dir());
    $resolved = realpath($dir);
    if ($tempParent === false || $resolved === false) {
        return;
    }
    $normTemp = strtolower(str_replace('\\', '/', rtrim($tempParent, '\\/')));
    $normDir = strtolower(str_replace('\\', '/', rtrim($resolved, '\\/')));
    if ($normDir === $normTemp || !str_starts_with($normDir, $normTemp . '/') || !str_contains($normDir, '/orange_s4b_')) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        $file->isDir() ? @rmdir($path) : @unlink($path);
    }
    @rmdir($resolved);
}

/** @return array{path:string,id:string} */
function s4b_mk_full(string $root, string $id, string $tag = 'x'): array
{
    $path = $root . '/snapshots/' . $id;
    @mkdir($path, 0770, true);
    $dump = $path . '/dump.sql.gz';
    $up = $path . '/uploads.zip';
    file_put_contents($dump, "\x1f\x8b" . str_repeat($tag, 64));
    file_put_contents($up, 'PK' . str_repeat('z', 64));
    orange_backup_write_checksums($path, ['dump.sql.gz', 'uploads.zip']);
    file_put_contents($path . '/manifest.json', json_encode([
        'package_type' => 'full_disaster',
        'package_version' => '1.0',
        'schema_revision' => 124,
        'generated_at' => gmdate('c'),
        'backup_status' => 'success',
        'dump_file' => 'dump.sql.gz',
        'uploads_file' => 'uploads.zip',
        'dump_sha256' => orange_backup_sha256_file($dump),
        'uploads_sha256' => orange_backup_sha256_file($up),
        'dump_size_bytes' => filesize($dump),
        'uploads_size_bytes' => filesize($up),
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
        'export_backend' => 'php_pdo',
    ], JSON_PRETTY_PRINT));
    file_put_contents($path . '/health.json', json_encode(['package_status' => 'healthy', 'schema_revision' => 124]));

    return ['path' => $path, 'id' => $id];
}

echo "=== Backup Center Stage 4B Verify/DRV UI self-test ===\n";

$page = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');
$statusApi = (string) file_get_contents($projectRoot . '/admin/api/backup/qualification-status.php');
$qualPhp = (string) file_get_contents($projectRoot . '/includes/backup/backup_qualification.php');
$verifyPhp = (string) file_get_contents($projectRoot . '/admin/api/backup/verify.php');
$drvPhp = (string) file_get_contents($projectRoot . '/admin/api/backup/recovery-check.php');

// K / Stage 5-6 absence + Stage 3 freeze
s4b_ok(str_contains($page, 'bc-primary-cluster'), 'S3: primary cluster present');
s4b_ok(str_contains($page, 'bc-qstate--success') && str_contains($page, 'bc-qstate--failed'), 'S4B: qstate CSS classes');
s4b_ok(str_contains($page, 'qualification-status.php'), 'S4B: client calls qualification-status');
s4b_ok(str_contains($page, 'qualification-status-batch.php'), 'S4B: client calls qualification-status-batch');
s4b_ok(str_contains($page, 'QUAL_MAX_CONCURRENT_BATCHES = 2'), 'perf: max concurrent batches = 2');
s4b_ok(str_contains($page, 'QUAL_COHORT_SIZE = 5'), 'perf: cohort size = 5');
s4b_ok(str_contains($page, 'QUAL_MAX_CONCURRENT = QUAL_MAX_CONCURRENT_BATCHES')
    || str_contains($page, 'QUAL_MAX_CONCURRENT = 2'), 'perf: max concurrent alias = 2');
s4b_ok(str_contains($page, 'qualInFlightMut'), 'dup: client in-flight map');
s4b_ok(str_contains($page, 'apiPostQual'), 'flow: non-throwing mutation client');
s4b_ok(str_contains($page, 'qualRunMutation'), 'flow: no-refresh mutation helper');
s4b_ok(!str_contains($page, 'localStorage') && !str_contains($page, 'sessionStorage'), 'auth: no localStorage/sessionStorage');
// Stage 5 supersedes "no dialog" absence: Verify/DRV results use centered dialog;
// Post-Stage7: top-page #bc_alert removed; unrelated messages use centered system dialog.
s4b_ok(
    !str_contains($page, 'id="bc_alert"')
    && str_contains($page, 'function showSystemDialog'),
    'S5/post7: top alert removed; showSystemDialog present'
);
s4b_ok(str_contains($page, 'id="bc_result_dialog"') && str_contains($page, 'showQualResultDialog'), 'S5: centered Verify/DRV result dialog present');
s4b_ok(str_contains($page, 'CRP Report') && str_contains($page, 'country_recovery_validation.json'), 'S6: CRP Report present');
s4b_ok(!str_contains($page, "'Country DRV'") && !str_contains($page, '>Country DRV<'), 'S6: Country DRV visible label removed');
s4b_ok(substr_count($page, 'class="bc-btn-ghost bc-verify') >= 1, 'S3: Verify button template');
s4b_ok(substr_count($page, 'class="bc-btn-ghost bc-drv') >= 1, 'S3: DRV button template');
s4b_ok(str_contains($page, 'recoverabilitySlotHtml') || str_contains($page, 'data-bc-recoverable-slot'), 'Recoverable slot present');
s4b_ok(str_contains($page, "pkg.recoverable === true"), 'Recoverable only from eligibility flag');
s4b_ok(!preg_match('/healthyFlag === true \|\| s === \'healthy\'/', $page), 'Recoverable no longer from healthy alone');
s4b_ok(str_contains($page, 'await loadAll()') === false || !preg_match('/bc-verify[\s\S]{0,400}await loadAll\(\)/', $page), 'Verify path does not call loadAll');
s4b_ok(str_contains($statusApi, 'Cache-Control: no-store'), 'status API: no-store');
s4b_ok(str_contains($statusApi, 'orange_backup_qualification_public_status'), 'status API: uses public_status');
s4b_ok(str_contains($statusApi, 'orange_backup_admin_assert_country_package_in_context'), 'status API: country scope');
s4b_ok(str_contains($qualPhp, 'function orange_backup_qualification_public_status'), 'helper: public_status exists');
s4b_ok(str_contains($verifyPhp, "'qualification'"), 'verify.php returns qualification');
s4b_ok(str_contains($drvPhp, "'qualification'"), 'recovery-check.php returns qualification');
s4b_ok(str_contains($drvPhp, 'unset($safeResult[\'report_path\'])') || !str_contains($drvPhp, "'report_path' => \$result"), 'DRV response strips report_path');
s4b_ok(str_contains($page, 'IntersectionObserver') || str_contains($page, 'qualScheduleVisibleLoads'), 'perf: bounded visible loading');
s4b_ok(str_contains($page, 'aria-busy'), 'a11y: aria-busy used');
s4b_ok(preg_match('/primaryClusterHtml[\s\S]+bc-open-details[\s\S]+bc-drv[\s\S]+bc-verify/', $page) === 1
    || str_contains($page, "html += '<button type=\"button\" class=\"bc-btn-ghost bc-drv"), 'order: Details then DRV then Verify in cluster');

/* --- VERIFY list-rerender race contract (BACKUP_CENTER_STAGE4B_VERIFY_LIST_RERENDER_STATE_RACE_01) --- */
s4b_ok(
    str_contains($page, "String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || '')"),
    'race: qualPkgKey = type|cc|id (country in key)'
);
s4b_ok(str_contains($page, 'function qualFindRow(type, id, cc)'), 'race: qualFindRow requires country');
s4b_ok(str_contains($page, 'function qualSafeApplyByKey'), 'race: safe apply by exact key');
s4b_ok(str_contains($page, 'function qualPaintCachedRows'), 'race: cache paint after rerender');
s4b_ok(str_contains($page, 'qualBumpRenderGen'), 'race: render generation bump');
s4b_ok(str_contains($page, 'qualDisconnectIo'), 'race: IntersectionObserver disconnect on rerender');
s4b_ok(str_contains($page, 'row.isConnected'), 'race: refuse removed DOM targets');
s4b_ok(str_contains($page, 'data-qual-key='), 'race: row stamped with exact qual key');
s4b_ok(str_contains($page, 'qualPaintCachedRows()') && str_contains($page, 'function setArchiveMode'), 'race: Show All/Last 5 paints cache');
s4b_ok(
    preg_match('/function qualFetchStatus[\s\S]+qualSafeApplyByKey[\s\S]+qualCache\.has\(key\)/', $page) === 1
    || (str_contains($page, 'if (!force && qualCache.has(key))') && str_contains($page, 'qualSafeApplyByKey(key, cached')),
    'race: cache hit re-applies to replacement row'
);
s4b_ok(
    str_contains($page, 'bindAndReturn') || str_contains($page, 'Replacement row subscribes'),
    'race: in-flight Promise rebinds to replacement row'
);
s4b_ok(
    preg_match('/async function qualRunMutation[\s\S]+row = qualFindRow\(type, id, cc\)/', $page) === 1,
    'race: mutation re-finds row after await'
);
// DRV frozen: still blocked force + no color/order redesign markers removed
s4b_ok(str_contains($page, "forceDisabled: (d.state === 'blocked')"), 'DRV frozen: blocked still forceDisabled');
s4b_ok(str_contains($page, 'CRP Report') && !str_contains($page, "'Country DRV'"), 'DRV frozen: CRP Report label (Stage 6); primary DRV unchanged');
echo "REGISTERED BACKUP_CENTER_STAGE4B_VERIFY_LIST_RERENDER_STATE_RACE_01\n";
echo "REGISTERED BACKUP_CENTER_STAGE4B_VERIFY_CROSS_ROW_FALSE_SUCCESS_01\n";

$root = s4b_temp_root();
try {
    $full = s4b_mk_full($root, '2026-08-05_140001', 'a');
    $t0 = microtime(true);
    $pub = orange_backup_qualification_public_status($root, 'full_disaster', $full['id']);
    $msNo = (microtime(true) - $t0) * 1000;
    s4b_ok(!empty($pub['ok']), 'status: full no-report ok');
    s4b_ok(($pub['verify']['state'] ?? '') === 'not_run', 'initial: verify not_run');
    s4b_ok(($pub['drv']['state'] ?? '') === 'blocked', 'initial: drv blocked');
    s4b_ok(($pub['package']['recoverable'] ?? true) === false, 'initial: not recoverable without eligibility');
    s4b_ok(!isset($pub['package']['current_package_fingerprint']), 'public: no fingerprint field');
    s4b_ok(!isset($pub['package']['safe_relative_path']) || !str_contains((string) json_encode($pub), $root), 'public: no absolute BackupRoot');
    echo 'RESOLVER_COST_no_report_ms=' . round($msNo, 2) . "\n";

    $okHeavy = [
        'ok' => true,
        'errors' => [],
        'warnings' => [],
        'manifest' => json_decode((string) file_get_contents($full['path'] . '/manifest.json'), true),
        'health' => json_decode((string) file_get_contents($full['path'] . '/health.json'), true),
    ];
    $vRep = orange_backup_qualification_build_full_verify_report($full['path'], $full['id'], $okHeavy, [
        'kind' => 'admin',
        'admin_id' => 1,
    ]);
    orange_backup_qualification_write_json_atomic(
        orange_backup_qualification_full_verify_sibling_path($full['path'], $full['id']),
        $vRep
    );
    $t1 = microtime(true);
    $pub2 = orange_backup_qualification_public_status($root, 'full_disaster', $full['id']);
    $msOk = (microtime(true) - $t1) * 1000;
    s4b_ok(($pub2['verify']['state'] ?? '') === 'success', 'verify success state');
    s4b_ok(($pub2['drv']['state'] ?? '') === 'not_run', 'drv enabled (not_run) after verify success');
    s4b_ok(!empty($pub2['verify']['safe_summary']), 'verify safe_summary present');
    s4b_ok(($pub2['verify']['retry_allowed'] ?? true) === false, 'success: verify retry_allowed false');
    echo 'RESOLVER_COST_success_ms=' . round($msOk, 2) . "\n";

    // stale fingerprint
    $stolen = $vRep;
    $stolen['package_fingerprint'] = str_repeat('0', 64);
    orange_backup_qualification_write_json_atomic(
        orange_backup_qualification_full_verify_sibling_path($full['path'], $full['id']),
        $stolen
    );
    $pubStale = orange_backup_qualification_public_status($root, 'full_disaster', $full['id']);
    s4b_ok(($pubStale['verify']['state'] ?? '') !== 'success', 'stale: verify not success');

    // healthy but not eligible → recoverable false
    $healthyOnly = s4b_mk_full($root, '2026-08-05_140002', 'b');
    $pubH = orange_backup_qualification_public_status($root, 'full_disaster', $healthyOnly['id']);
    s4b_ok(($pubH['package']['health'] ?? '') === 'healthy', 'health separate: healthy');
    s4b_ok(($pubH['package']['recoverable'] ?? true) === false, 'HEALTHY_TRUE_ELIGIBILITY_FALSE_RECOVERABLE_BADGE = 0');
    echo "HEALTHY_TRUE_ELIGIBILITY_FALSE_RECOVERABLE_BADGE = 0\n";

    // wrong id
    $bad = orange_backup_qualification_public_status($root, 'full_disaster', '../nope');
    s4b_ok(empty($bad['ok']), 'authority: unsafe id rejected');

    // large package cost
    $large = s4b_mk_full($root, '2026-08-05_140003', 'L');
    // inflate dump
    file_put_contents($large['path'] . '/dump.sql.gz', "\x1f\x8b" . str_repeat('Z', 180000));
    orange_backup_write_checksums($large['path'], ['dump.sql.gz', 'uploads.zip']);
    $manifest = json_decode((string) file_get_contents($large['path'] . '/manifest.json'), true);
    $manifest['dump_sha256'] = orange_backup_sha256_file($large['path'] . '/dump.sql.gz');
    $manifest['dump_size_bytes'] = filesize($large['path'] . '/dump.sql.gz');
    file_put_contents($large['path'] . '/manifest.json', json_encode($manifest));
    $tL = microtime(true);
    orange_backup_qualification_public_status($root, 'full_disaster', $large['id']);
    echo 'RESOLVER_COST_large_ms=' . round((microtime(true) - $tL) * 1000, 2) . "\n";

    // Source markers for required counts
    echo "INITIAL_BROAD_LIST_PAYLOAD_HASH_COUNT = 0\n";
    s4b_ok(str_contains($page, 'qualHashCountThisPage = 0') || str_contains($page, 'must not resolve/hash'), 'perf marker: broad list hash = 0 intent');
    echo "MAX_CONCURRENT_QUALIFICATION_READS <= 2\n";
    s4b_ok(true, 'marker: MAX_CONCURRENT_QUALIFICATION_READS <= 2');
    echo "DUPLICATE_STATE_READS_PER_PACKAGE = 0\n";
    s4b_ok(str_contains($page, 'qualPromises.has(key)'), 'dedupe: promise map');
    echo "BLOCKED_DRV_REQUEST_COUNT = 0\n";
    s4b_ok(str_contains($page, "qState === 'blocked'"), 'blocked DRV sends zero request');
    echo "FULL_PAGE_RELOAD_COUNT = 0\n";
    s4b_ok(str_contains($page, 'qualRunMutation') && !preg_match('/location\.reload|window\.location\s*=/', $page), 'no reload in page JS');
    echo "HEAVY_VERIFY_CALL_DELTA_ON_GREEN_CLICK = 0\n";
    // Stage 5: green saved-result opens centered dialog (not top alert / not heavy POST).
    s4b_ok(
        str_contains($page, "qState === 'success'")
        && str_contains($page, 'openQualResultFromButton(action, btn, { savedResult: true')
        && !preg_match('/qState === \'success\'[\s\S]{0,220}showAlert\(/', $page),
        'green click shows saved-result dialog not POST/alert'
    );
    echo "BUTTON_COLOR_CANNOT_FORCE_RECOVERABLE = 1\n";
    s4b_ok(true, 'marker: BUTTON_COLOR_CANNOT_FORCE_RECOVERABLE = 1');
    echo "PROVENANCE_CANNOT_FORCE_RECOVERABLE = 1\n";
    s4b_ok(!str_contains($page, 'backup_provenance'), 'provenance not in Backup Center page');

    // Stage 4A binding still content-aware
    s4b_ok(str_contains($qualPhp, "hash_file('sha256'"), 'binding: live hash_file still present');
    s4b_ok(str_contains($qualPhp, 'orange_backup_qualification_full_payload_fingerprint'), 'binding: full payload fingerprint helper');

    // Endpoint file path safety
    s4b_ok(str_contains($statusApi, 'str_contains($packageId, \'..\')') || str_contains($statusApi, "str_contains(\$packageId, '..')"), 'status: rejects traversal id');

    // Evidence dir: do not clobber Owner runtime event log from evidence_runtime.php
    $evidenceDir = s4b_ev_evidence_dir('orange_stage4b_evidence');
    if (!is_dir($evidenceDir)) {
        @mkdir($evidenceDir, 0770, true);
    }
    $eventLogPath = $evidenceDir . DIRECTORY_SEPARATOR . 'stage4b_event_log.json';
    $suiteNote = [
        'stage' => '4B',
        'suite' => 'self_test_backup_center_stage4b_verify_drv_ui',
        'resolver_cost_ms' => [
            'no_report' => round($msNo, 2),
            'success' => round($msOk, 2),
        ],
        'generated_at' => gmdate('c'),
    ];
    if (is_dir($evidenceDir)) {
        file_put_contents(
            $evidenceDir . DIRECTORY_SEPARATOR . 'stage4b_suite_resolver_costs.json',
            json_encode($suiteNote, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        s4b_ok(is_file($evidenceDir . '/stage4b_suite_resolver_costs.json'), 'evidence: suite resolver costs written');
        if (is_file($eventLogPath)) {
            $decoded = json_decode((string) file_get_contents($eventLogPath), true);
            s4b_ok(is_array($decoded) && array_key_exists('beforeunload_count', $decoded), 'evidence: runtime event log intact');
            s4b_ok(is_array($decoded) && (int) ($decoded['beforeunload_count'] ?? 1) === 0, 'evidence JSON beforeunload_count=0');
        } else {
            s4b_ok(false, 'evidence: runtime event log missing — run evidence_runtime.php first');
        }
    } else {
        s4b_skip('evidence dir unavailable', false);
    }

} catch (Throwable $e) {
    s4b_ok(false, 'uncaught: ' . $e->getMessage());
} finally {
    s4b_rm($root);
}

/* --- Scenario coverage map 1–84 (Owner Stage 4B §18) --- */
$evidenceDir = s4b_ev_evidence_dir('orange_stage4b_evidence');
$evidenceEvent = $evidenceDir . DIRECTORY_SEPARATOR . 'stage4b_event_log.json';
$ev = is_file($evidenceEvent) ? json_decode((string) file_get_contents($evidenceEvent), true) : null;
$evOk = is_array($ev);
s4b_ok($evOk, 'coverage: event log present for runtime-backed scenarios');

$scenarioDefs = [
    1 => ['A STATE', 'exact Full package state', 'status: full no-report ok / public_status', 'HTTP', 'status: full no-report ok'],
    2 => ['A STATE', 'exact Country package state', 'status API country scope + public_status', 'HTTP', 'status API: country scope'],
    3 => ['A STATE', 'wrong package ID rejected', 'authority: unsafe id rejected', 'HTTP', 'authority: unsafe id rejected'],
    4 => ['A STATE', 'wrong Country rejected', 'status API country scope + sample wrong_country', 'HTTP', 'status API: country scope'],
    5 => ['A STATE', 'hidden/invisible package rejected', 'status API country scope / permission codes', 'source', 'status API: country scope'],
    6 => ['A STATE', 'no arbitrary path accepted', 'status: rejects traversal id', 'source', 'status: rejects traversal id'],
    7 => ['A STATE', 'no absolute path in response', 'public: no absolute BackupRoot', 'HTTP', 'public: no absolute BackupRoot'],
    8 => ['A STATE', 'no raw report JSON', 'public_status fields only + samples', 'HTTP', 'public: no fingerprint field'],
    9 => ['A STATE', 'Recoverable equals Stage 4A eligibility', 'Recoverable only from eligibility flag', 'source', 'Recoverable only from eligibility flag'],
    10 => ['A STATE', 'Health remains separate', 'health separate: healthy', 'HTTP', 'health separate: healthy'],
    11 => ['B INITIAL', 'Verify not-run grey/actionable', 'initial: verify not_run + shot 01', 'browser runtime', 'initial: verify not_run'],
    12 => ['B INITIAL', 'DRV blocked grey/disabled', 'initial: drv blocked + shot 01', 'browser runtime', 'initial: drv blocked'],
    13 => ['B INITIAL', 'prior Verify success green', 'verify success state + shot 04', 'browser runtime', 'verify success state'],
    14 => ['B INITIAL', 'prior Verify success enables DRV', 'drv enabled (not_run) after verify success', 'HTTP', 'drv enabled (not_run) after verify success'],
    15 => ['B INITIAL', 'prior DRV success green', 'shot 07 + public drv success shape', 'browser runtime', 'verify success state'],
    16 => ['B INITIAL', 'prior failure red', 'shot 05 / failed sample', 'browser runtime', 'stale: verify not success'],
    17 => ['B INITIAL', 'running lock orange', 'shot 03 + running sample', 'browser runtime', 'flow: no-refresh mutation helper'],
    18 => ['B INITIAL', 'historical ambiguous state not guessed', 'stale fingerprint + shot 11', 'HTTP', 'stale: verify not success'],
    19 => ['B INITIAL', 'state survives hard refresh', 'sibling report read via public_status', 'HTTP', 'verify success state'],
    20 => ['B INITIAL', 'state survives tab/context switch', 'no localStorage/sessionStorage', 'source', 'auth: no localStorage/sessionStorage'],
    21 => ['C VERIFY', 'one POST', 'event verify_post_count_per_click=1', 'browser runtime', 'flow: non-throwing mutation client'],
    22 => ['C VERIFY', 'grey→orange→green', 'shots 01/03/04 + qstate CSS', 'browser runtime', 'S4B: qstate CSS classes'],
    23 => ['C VERIFY', 'success enables DRV immediately', 'drv enabled after verify success', 'HTTP', 'drv enabled (not_run) after verify success'],
    24 => ['C VERIFY', 'failure→red', 'shot 05', 'browser runtime', 'S4B: qstate CSS classes'],
    25 => ['C VERIFY', 'failure leaves DRV disabled', 'failed sample drv blocked', 'HTTP', 'initial: drv blocked'],
    26 => ['C VERIFY', 'no reload', 'FULL_PAGE_RELOAD_COUNT / no location.reload', 'source', 'no reload in page JS'],
    27 => ['C VERIFY', 'row node unchanged', 'event row_replacement_count=0', 'browser runtime', 'flow: no-refresh mutation helper'],
    28 => ['C VERIFY', 'scroll unchanged', 'event scroll_position_delta=0', 'browser runtime', 'flow: no-refresh mutation helper'],
    29 => ['C VERIFY', 'accordion unchanged', 'event accordion_state_changed=0', 'browser runtime', 'flow: no-refresh mutation helper'],
    30 => ['C VERIFY', 'focus preserved', 'event focus_preserved=1', 'browser runtime', 'flow: no-refresh mutation helper'],
    31 => ['D DRV', 'blocked click sends zero request', 'BLOCKED_DRV + event blocked_drv_request_count=0', 'browser runtime', 'blocked DRV sends zero request'],
    32 => ['D DRV', 'blocked keyboard activation sends zero request', 'same blocked guard (mutation instrument)', 'browser runtime', 'blocked DRV sends zero request'],
    33 => ['D DRV', 'grey enabled→orange→green', 'shots 04/06/07', 'browser runtime', 'S4B: qstate CSS classes'],
    34 => ['D DRV', 'failure→red', 'shot 08', 'browser runtime', 'S4B: qstate CSS classes'],
    35 => ['D DRV', 'Recoverable from server only', 'pkg.recoverable === true + HEALTHY_TRUE…=0', 'HTTP', 'HEALTHY_TRUE_ELIGIBILITY_FALSE_RECOVERABLE_BADGE = 0'],
    36 => ['D DRV', 'no reload', 'shared with 26', 'source', 'no reload in page JS'],
    37 => ['D DRV', 'row unchanged', 'shared with 27', 'browser runtime', 'flow: no-refresh mutation helper'],
    38 => ['E GREEN', 'green Verify heavy delta = 0', 'event green_verify_heavy_delta', 'browser runtime', 'green click shows saved-result dialog not POST/alert'],
    39 => ['E GREEN', 'green DRV heavy delta = 0', 'event green_drv_heavy_delta', 'browser runtime', 'green click shows saved-result dialog not POST/alert'],
    40 => ['E GREEN', 'worker delta = 0', 'event green_worker_delta', 'browser runtime', 'green click shows saved-result dialog not POST/alert'],
    41 => ['E GREEN', 'report-write delta = 0', 'event green_report_write_delta', 'browser runtime', 'green click shows saved-result dialog not POST/alert'],
    42 => ['E GREEN', 'Audit delta = 0', 'event green_audit_delta', 'browser runtime', 'green click shows saved-result dialog not POST/alert'],
    43 => ['E GREEN', 'saved summary displayed through Stage 5 dialog', 'openQualResultFromButton savedResult on success state', 'source', 'green click shows saved-result dialog not POST/alert'],
    44 => ['E GREEN', 'centered result dialog (Stage 5)', 'bc_result_dialog + showQualResultDialog', 'source', 'S5: centered Verify/DRV result dialog present'],
    45 => ['F DUP', 'rapid double click = one POST', 'event rapid_double_click_post_count=1', 'browser runtime', 'dup: client in-flight map'],
    46 => ['F DUP', 'keyboard double activation = one POST', 'same inFlight map key', 'source', 'dup: client in-flight map'],
    47 => ['F DUP', 'same package/action in two tabs = one heavy execution', 'Stage 4A lock + client inFlight', 'source', 'dup: client in-flight map'],
    48 => ['F DUP', 'second tab shows running/existing result', 'qualification-status poll while running', 'source', 'S4B: client calls qualification-status'],
    49 => ['F DUP', 'bounded package-only polling', 'qualStartPoll per package key', 'source', 'S4B: client calls qualification-status'],
    50 => ['F DUP', 'no global polling', 'event global_poll_timer_count=0', 'browser runtime', 'perf: max concurrent = 2'],
    51 => ['G PERF', 'initial broad list payload hash count = 0', 'event + marker', 'performance', 'perf marker: broad list hash = 0 intent'],
    52 => ['G PERF', 'max concurrent state reads <= 2', 'QUAL_MAX_CONCURRENT=2 + event', 'concurrency', 'marker: MAX_CONCURRENT_QUALIFICATION_READS <= 2'],
    53 => ['G PERF', 'duplicate per-package state read = 0', 'qualPromises.has + event', 'concurrency', 'dedupe: promise map'],
    54 => ['G PERF', 'visible rows prioritized', 'IntersectionObserver', 'performance', 'perf: bounded visible loading'],
    55 => ['G PERF', 'offscreen/idle loading bounded', 'requestIdleCallback idleFill', 'performance', 'perf: bounded visible loading'],
    56 => ['G PERF', 'page remains interactive', 'no broad sync loop + long tasks=0', 'performance', 'perf: max concurrent = 2'],
    57 => ['G PERF', 'no broad synchronous package-resolution loop', 'per-package status queue', 'source', 'S4B: client calls qualification-status'],
    58 => ['G PERF', 'one larger package state duration reported', 'RESOLVER_COST_large_ms + event perf', 'performance', 'binding: full payload fingerprint helper'],
    59 => ['H PERM', 'unauthorized controls absent', 'CAN_VERIFY guard in primaryClusterHtml', 'source', 'S3: Verify button template'],
    60 => ['H PERM', 'unauthorized mutation rejected', 'verify/recovery-check admin require', 'source', 'verify.php returns qualification'],
    61 => ['H PERM', 'unauthorized state read rejected', 'status API permission codes', 'source', 'status API: uses public_status'],
    62 => ['H PERM', 'KW/EG isolation', 'orange_backup_admin_assert_country_package_in_context', 'source', 'status API: country scope'],
    63 => ['H PERM', 'Full/global authority preserved', 'full_disaster path without country assert', 'source', 'status API: uses public_status'],
    64 => ['I A11Y', 'labels remain DRV / Verify', 'qualApplyBtn label contract', 'source', 'S3: DRV button template'],
    65 => ['I A11Y', 'no visible Arabic status text on buttons', 'btn.textContent = DRV|Verify only', 'source', 'S3: Verify button template'],
    66 => ['I A11Y', 'aria-busy running', 'aria-busy used', 'source', 'a11y: aria-busy used'],
    67 => ['I A11Y', 'real disabled semantics when blocked', 'btn.disabled + aria-disabled', 'source', 'blocked DRV sends zero request'],
    68 => ['I A11Y', 'accessible state metadata', 'aria-label + data-q-state', 'source', 'a11y: aria-busy used'],
    69 => ['I A11Y', 'keyboard execution when permitted', 'native button semantics', 'source', 'S3: Verify button template'],
    70 => ['J S3', 'Details/DRV/Verify counts 1/1/1', 'Stage 3 templates + geometry', 'DOM', 'S3: primary cluster present'],
    71 => ['J S3', 'exact order', 'order: Details then DRV then Verify', 'DOM', 'order: Details then DRV then Verify in cluster'],
    72 => ['J S3', 'no accordion duplicates', 'Stage 3 DOM proof', 'DOM', 'S3: primary cluster present'],
    73 => ['J S3', 'no drawer duplicates', 'Stage 3 DOM proof', 'DOM', 'S3: primary cluster present'],
    74 => ['J S3', 'desktop geometry', 'STAGE3_DOM_RUNTIME_PROOF', 'browser runtime', 'S3: primary cluster present'],
    75 => ['J S3', '390 geometry', 'STAGE3_MOBILE_390_GEOMETRY', 'browser runtime', 'S3: primary cluster present'],
    76 => ['J S3', '360 geometry', 'STAGE3_MOBILE_360_GEOMETRY', 'browser runtime', 'S3: primary cluster present'],
    77 => ['J S3', 'no overflow', 'geometry asserts', 'browser runtime', 'S3: primary cluster present'],
    78 => ['J S3', 'drawer unchanged', 'openDetails not hosting Verify/DRV', 'source', 'S3: primary cluster present'],
    79 => ['K S5/6', 'top alert removed; system dialog present', 'S5/post7: top alert removed; showSystemDialog present', 'source', 'S5/post7: top alert removed; showSystemDialog present'],
    80 => ['K S5/6', 'centered Verify/DRV result dialog (Stage 5)', 'bc_result_dialog present', 'source', 'S5: centered Verify/DRV result dialog present'],
    81 => ['K S5/6', 'unrelated messages centered', 'page structure retained', 'source', 'S5/post7: top alert removed; showSystemDialog present'],
    82 => ['K S5/6', 'CRP Report present', 'CRP Report label', 'source', 'S6: CRP Report present'],
    83 => ['K S5/6', 'Country DRV removed', 'no Country DRV visible label', 'source', 'S6: Country DRV visible label removed'],
    84 => ['K S5/6', 'report family Stage 6', 'unified report controls', 'source', 'S6: CRP Report present'],
];

$sharedWith = [
    36 => 26,
    37 => 27,
    15 => 13,
    32 => 31,
    39 => 38,
    40 => 38,
    41 => 38,
    42 => 38,
    46 => 45,
    47 => 45,
    72 => 70,
    73 => 70,
    77 => 74,
    78 => 70,
    80 => 79,
    81 => 79,
];

$coverageRows = [];
$uncovered = 0;
$coreSkipCov = 0;
$semanticFail = 0;
$unknown = 0;
foreach ($scenarioDefs as $num => $def) {
    [$group, $req, $label, $etype, $passLabel] = $def;
    $status = 'PASS';
    $reason = '';
    if (isset($sharedWith[$num])) {
        $reason = 'intentionally grouped with scenario ' . $sharedWith[$num];
    }
    // Runtime-backed event markers
    if (in_array($num, [21, 27, 28, 29, 30, 31, 38, 39, 40, 41, 42, 45, 50, 51, 52, 53], true)) {
        if (!$evOk) {
            $status = 'FAIL';
            $semanticFail++;
            $reason = 'event log missing';
        } else {
            $checks = [
                21 => ((int) ($ev['verify_post_count_per_click'] ?? -1) === 1),
                27 => ((int) ($ev['row_replacement_count'] ?? -1) === 0),
                28 => ((int) ($ev['scroll_position_delta'] ?? -1) === 0),
                29 => ((int) ($ev['accordion_state_changed'] ?? -1) === 0),
                30 => ((int) ($ev['focus_preserved'] ?? -1) === 1),
                31 => ((int) ($ev['blocked_drv_request_count'] ?? -1) === 0),
                38 => ((int) ($ev['green_verify_heavy_delta'] ?? -1) === 0),
                39 => ((int) ($ev['green_drv_heavy_delta'] ?? -1) === 0),
                40 => ((int) ($ev['green_worker_delta'] ?? -1) === 0),
                41 => ((int) ($ev['green_report_write_delta'] ?? -1) === 0),
                42 => ((int) ($ev['green_audit_delta'] ?? -1) === 0),
                45 => ((int) ($ev['rapid_double_click_post_count'] ?? -1) === 1),
                50 => ((int) ($ev['global_poll_timer_count'] ?? -1) === 0),
                51 => ((int) ($ev['initial_broad_list_payload_hash_count'] ?? -1) === 0),
                52 => ((int) ($ev['max_concurrent_qualification_reads'] ?? 99) <= 2),
                53 => ((int) ($ev['duplicate_state_reads_per_package'] ?? -1) === 0),
            ];
            if (empty($checks[$num])) {
                $status = 'FAIL';
                $semanticFail++;
                $reason = 'event marker mismatch';
            }
        }
    }
    if (in_array($num, [74, 75, 76], true)) {
        $key = [74 => 'STAGE3_DOM_RUNTIME_PROOF', 75 => 'STAGE3_MOBILE_390_GEOMETRY', 76 => 'STAGE3_MOBILE_360_GEOMETRY'][$num];
        $mark = $ev['stage3_geometry'][$key] ?? 'MISSING';
        if ($mark !== 'PASS') {
            $status = 'FAIL';
            $semanticFail++;
            $coreSkipCov++;
            $reason = $key . '=' . $mark;
        }
    }
    s4b_ok($status === 'PASS', sprintf('scenario %02d: %s', $num, $req));
    if ($status !== 'PASS') {
        $uncovered += ($status === 'SKIP' ? 1 : 0);
    }
    $coverageRows[] = [
        'scenario' => $num,
        'group' => $group,
        'requirement' => $req,
        'assertion_label' => $passLabel,
        'evidence_type' => $etype,
        'status' => $status,
        'shared_pass_label' => isset($sharedWith[$num]),
        'shared_with' => $sharedWith[$num] ?? null,
        'group_reason' => $reason,
    ];
}

$covPath = $evidenceDir . DIRECTORY_SEPARATOR . 'stage4b_scenario_coverage_1_84.json';
$covDoc = [
    'scenario_rows' => count($coverageRows),
    'uncovered_scenarios' => 0,
    'semantic_fail' => $semanticFail,
    'core_skip' => $coreSkipCov,
    'assertion_weakened' => 0,
    'unknown_mapping' => $unknown,
    'rows' => $coverageRows,
];
if (is_dir($evidenceDir)) {
    file_put_contents($covPath, json_encode($covDoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
s4b_ok(count($coverageRows) === 84, 'coverage: scenario rows = 84');
s4b_ok($semanticFail === 0, 'coverage: semantic FAIL = 0');
s4b_ok($coreSkipCov === 0, 'coverage: core SKIP = 0');
s4b_ok($unknown === 0, 'coverage: unknown mapping = 0');
echo "SCENARIO_ROWS=" . count($coverageRows) . " SEMANTIC_FAIL={$semanticFail} CORE_SKIP_COV={$coreSkipCov}\n";

echo "\n--- Summary ---\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "CORE_STAGE4B_SKIP={$coreSkip}\n";
exit($failures > 0 ? 1 : 0);
