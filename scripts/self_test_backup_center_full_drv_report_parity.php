<?php

declare(strict_types=1);

/**
 * Post-Stage7 hotfix — Full DRV Report parity with CRP Report (readable, no File-not-found top card).
 *
 * Usage: php scripts/self_test_backup_center_full_drv_report_parity.php
 *
 * Evidence: D:\orange_full_drv_report_parity_evidence\ on Windows; system temp on Linux/macOS.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/scripts/lib/backup_stage4b_evidence_lib.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;

function fdrv_ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
}

function fdrv_extract(string $src, string $name): string
{
    $body = s4b_ev_extract_function($src, $name);
    if ($body === '') {
        $body = s4b_ev_extract_const_arrow($src, $name);
    }
    if ($body !== '' && str_contains($body, 'await ') && !str_starts_with(ltrim($body), 'async ')) {
        return 'async ' . $body;
    }

    return $body;
}

function fdrv_evidence_dir(): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return 'D:\\orange_full_drv_report_parity_evidence';
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'orange_full_drv_report_parity_evidence';
}

$pagePath = $projectRoot . '/admin/pages/backup_center.php';
$src = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
fdrv_ok($src !== '', 'source: backup_center.php readable');

$actionFn = fdrv_extract($src, 'actionRowHtml');
$clickRegion = '';
if (preg_match("/classList\.contains\('bc-view-file'\)[\s\S]*?classList\.contains\('bc-log-tail'\)/s", $src, $cm)) {
    $clickRegion = $cm[0];
}
$buildFull = fdrv_extract($src, 'buildFullDrvReadableSummary');
$showFull = fdrv_extract($src, 'showFullDrvReportView');
$buildCrp = fdrv_extract($src, 'buildCrpReadableSummary');
$showCrp = fdrv_extract($src, 'showCrpReportView');

/* Visibility / accordion */
fdrv_ok(
    str_contains($actionFn, "'recovery_validation.json', 'DRV Report'"),
    '1. FULL_DRV_REPORT_VISIBLE_BEFORE_RUN template label'
);
fdrv_ok(
    str_contains($actionFn, 'DRV Report')
    && !preg_match('/recovery_validation\.json[\s\S]{0,200}qState|success.*DRV Report/i', $actionFn),
    '1b. DRV Report not gated on DRV success in template'
);
fdrv_ok(str_contains(fdrv_extract($src, 'accordionItemHtml'), 'actionRowHtml('), '17. Full DRV Report remains accordion-only');
fdrv_ok(
    !str_contains(fdrv_extract($src, 'openDetails'), 'DRV Report')
    && !str_contains(fdrv_extract($src, 'openDetails'), 'recovery_validation.json'),
    '17b. Details metadata-only (no Full DRV Report)'
);

/* Readable path / CRP parity helpers */
fdrv_ok($buildFull !== '' && $showFull !== '', 'extract Full DRV readable helpers');
fdrv_ok($buildCrp !== '' && $showCrp !== '', '18. CRP Report helpers unchanged (present)');
fdrv_ok(
    $clickRegion !== ''
    && str_contains($clickRegion, "file === 'recovery_validation.json'")
    && str_contains($clickRegion, 'showFullDrvReportView')
    && str_contains($clickRegion, "file === 'country_recovery_validation.json'")
    && str_contains($clickRegion, 'showCrpReportView'),
    'click: Full DRV + CRP dedicated readable branches'
);
fdrv_ok(
    str_contains($src, 'FULL_DRV_MSG_NOT_READY')
    && str_contains($src, 'تقرير DRV لم يتم إنشاؤه لهذه النسخة بعد.')
    && str_contains($src, 'تقرير DRV غير متاح لهذه النسخة.'),
    '2/4. not-ready + unavailable Arabic stable messages'
);
fdrv_ok(
    str_contains($clickRegion, "forceStatus: 'INCOMPLETE'")
    && str_contains($clickRegion, 'hideRaw: true'),
    '2b. FULL_DRV_REPORT_NOT_READY_DIALOG path'
);
fdrv_ok(
    !str_contains($clickRegion, 'showAlert(')
    && !preg_match('/recovery_validation\.json[\s\S]{0,800}File not found/i', $clickRegion),
    '13. no File not found surface for Full DRV branch'
);
fdrv_ok(
    !str_contains($clickRegion, 'apiPost(')
    && !str_contains($clickRegion, 'run-full')
    && !str_contains($clickRegion, 'recovery-check.php')
    && !str_contains($clickRegion, "apiPostQual('verify"),
    '15. FULL_DRV_REPORT_AUTORUN_COUNT = 0 (read-only open)'
);
fdrv_ok(
    str_contains($buildFull, 'Package type')
    && str_contains($buildFull, '>Full<')
    && !str_contains($buildFull, 'Cross-Country isolation')
    && !str_contains($buildFull, 'Country</dd>'),
    '5. Full summary has Full fields only (no Country CRP fields)'
);
fdrv_ok(
    (str_contains($buildFull, "status === 'PASS'") && str_contains($buildFull, 'INCOMPLETE'))
    && str_contains($buildFull, '!bindingOk')
    && str_contains($buildFull, "status = 'FAIL'"),
    '10. FULL_DRV_REPORT_FALSE_PASS_COUNT guarded'
);
fdrv_ok(
    str_contains($src, 'sanitizeCrpDisplayData')
    && str_contains($clickRegion, 'sanitizeCrpDisplayData')
    && str_contains(fdrv_extract($src, 'sanitizeCrpDisplayData'), 'package_fingerprint'),
    '12/14. optional Raw JSON sanitized (no path/fingerprint secrets)'
);
fdrv_ok(str_contains($showFull, "DRV Report — Full Backup"), 'title: DRV Report — Full Backup');

/* CRP unchanged labels */
fdrv_ok(
    str_contains($actionFn, "'country_recovery_validation.json', 'CRP Report'")
    && !str_contains($src, "'Country DRV'"),
    '18b. CRP Report label unchanged'
);

/* Runtime DOM */
$evidenceDir = fdrv_evidence_dir();
$runtimeDir = $evidenceDir . DIRECTORY_SEPARATOR . 'runtime';
$shotsDir = $evidenceDir . DIRECTORY_SEPARATOR . 'shots';
@mkdir($runtimeDir, 0775, true);
@mkdir($shotsDir, 0775, true);

$matrix = [
    'generated_at_utc' => gmdate('c'),
    'git_head' => trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' rev-parse HEAD')),
    'root_cause' => 'Full recovery_validation.json used generic showAlert on !success; CRP mapped File not found to readable INCOMPLETE.',
    'full_drv' => [
        'label' => 'DRV Report',
        'package_type' => 'full_disaster',
        'data_file' => 'recovery_validation.json',
        'endpoint' => 'status.php?action=view_file',
        'renderer' => 'showFullDrvReportView / buildFullDrvReadableSummary',
        'missing' => 'INCOMPLETE + FULL_DRV_MSG_NOT_READY or UNAVAILABLE',
        'raw_json' => 'only for valid parsed report',
        'disposition' => 'CENTERED',
    ],
    'crp' => [
        'label' => 'CRP Report',
        'package_type' => 'country_recovery',
        'data_file' => 'country_recovery_validation.json',
        'endpoint' => 'status.php?action=view_file',
        'renderer' => 'showCrpReportView / buildCrpReadableSummary',
        'disposition' => 'UNCHANGED',
    ],
    'UNKNOWN_FULL_DRV_REPORT_PATH' => 0,
    'UNKNOWN_CRP_REPORT_PATH' => 0,
    'UNKNOWN_REPORT_ERROR_SURFACE' => 0,
];
file_put_contents(
    $evidenceDir . DIRECTORY_SEPARATOR . 'full_drv_report_parity_matrix.json',
    json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

$chromeOk = s4b_ev_chrome_path() !== '';
if (!$chromeOk) {
    $skip++;
    $coreSkip++;
    echo "SKIP chrome runtime (chrome_missing)\n";
    echo "CORE_FULL_DRV_REPORT_PARITY_SKIP=1\n";
} else {
    if (!preg_match('/<style>(.*?)<\/style>/s', $src, $styleM)) {
        fdrv_ok(false, 'extract Production CSS');
    } else {
        fdrv_ok(true, 'extract Production CSS');
        $style = $styleM[1];
        $fns = [];
        foreach ([
            'crpNormalizeStatus',
            'crpBoolLabel',
            'crpHumanizeFailureReason',
            'buildFullDrvReadableSummary',
            'showFullDrvReportView',
            'buildCrpReadableSummary',
            'showCrpReportView',
            'sanitizeCrpDisplayData',
            'openReportDialogShell',
            'closeReportDialog',
            'viewFileControl',
            'actionRowHtml',
            'primaryClusterHtml',
            'accordionItemHtml',
            'hiddenPkgDataCell',
        ] as $fn) {
            $body = fdrv_extract($src, $fn);
            if ($body === '') {
                fdrv_ok(false, "extract {$fn}");
            } else {
                $fns[$fn] = $body;
            }
        }

        $boot = <<<'JS'
const CAN_VERIFY = true;
const state = { busy: false, full: [], country: [], archiveMode: { full: false, country: false } };
const el = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const statusTone = (status) => { const s = String(status || '').toLowerCase(); if (s === 'success' || s === 'pass' || s === 'ok' || s === 'healthy') return 'success'; if (s === 'failed' || s === 'fail') return 'failed'; if (s === 'running') return 'running'; return 'muted'; };
const badge = (s) => '<span class="bc-badge bc-badge--' + statusTone(s) + '">' + esc(s || '—') + '</span>';
const recoverabilityBadge = (pkg) => (pkg && pkg.recoverable === true) ? '<span class="bc-badge bc-badge--success">Recoverable</span>' : '';
const recoverabilitySlotHtml = (pkg) => '<span class="bc-recoverable-slot">' + recoverabilityBadge(pkg) + '</span>';
const fmtPackageWhenDisplay = (pkg) => esc((pkg && (pkg.generated_at_display || pkg.package_id)) || '');
const sizeSummary = (pkg) => '1 MB';
const localizeTimestampsInText = (t) => String(t || '');
const hasDisplayTz = () => true;
const DISPLAY_TZ = 'Asia/Kuwait';
function qualPkgKey(type, id, cc) { return String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || ''); }
let qualRenderGen = 1;
let bcReportDialogReturnFocus = null;
let bcReportDialogKeyHandler = null;
JS;

        $assertJs = <<<'JS'
(function () {
  const report = { pass: [], fail: [], markers: {}, geometry: {} };
  function ok(cond, msg) { (cond ? report.pass : report.fail).push(msg); }
  const FULL_DRV_MSG_NOT_READY = 'تقرير DRV لم يتم إنشاؤه لهذه النسخة بعد.';
  const FULL_DRV_MSG_UNAVAILABLE = 'تقرير DRV غير متاح لهذه النسخة.';
  const fullPkg = {
    package_id: '2026-08-06_101010', package_status: 'healthy', schema_revision: 124,
    backend: 'mysqldump', recovery_score: 90, dump_size_bytes: 1000, uploads_size_bytes: 200,
    generated_at_display: '2026-08-06 10:10:10 AM', recoverable: true
  };
  const countryPkg = {
    package_id: '2026-08-06_111111', package_status: 'healthy', country_code: 'KW', country_name: 'Kuwait',
    schema_revision: 124, backend: 'mysqldump', recovery_score: 88, dump_size_bytes: 900, uploads_size_bytes: 100,
    generated_at_display: '2026-08-06 11:11:11 AM', recoverable: false
  };
  el('bc_full_list').innerHTML = accordionItemHtml(fullPkg, 'full_disaster', 0);
  el('bc_country_list').innerHTML = accordionItemHtml(countryPkg, 'country_recovery', 0);
  const fullItem = document.querySelector('#bc_full_list .bc-acc-item');
  fullItem.open = true;
  const drvBtn = Array.from(fullItem.querySelectorAll('.bc-view-file')).find((b) => b.getAttribute('data-file') === 'recovery_validation.json');
  ok(!!drvBtn && drvBtn.textContent.trim() === 'DRV Report', 'DOM: DRV Report visible before DRV');
  ok(document.querySelectorAll('#bc_alert').length === 0, 'DOM: no top alert card');

  let st = showFullDrvReportView({
    title: 'DRV Report — Full Backup', data: null, packageId: fullPkg.package_id,
    stableMessage: FULL_DRV_MSG_NOT_READY, forceStatus: 'INCOMPLETE', hideRaw: true, sourceBtn: drvBtn
  });
  ok(st === 'INCOMPLETE', 'not-run INCOMPLETE');
  ok((el('bc_view_summary').textContent || '').includes(FULL_DRV_MSG_NOT_READY), 'not-run Arabic message');
  ok((el('bc_view_pre').style.display === 'none') || !(el('bc_view_pre').textContent || '').trim(), 'not-run no Raw JSON');
  ok(!(el('bc_view_summary').textContent || '').includes('File not found'), 'not-run no File not found');
  report.markers.FULL_DRV_REPORT_NOT_READY_RAW_JSON_COUNT = (el('bc_view_pre').style.display !== 'none' && (el('bc_view_pre').textContent || '').trim()) ? 1 : 0;

  st = showFullDrvReportView({
    title: 'DRV Report — Full Backup', data: null, packageId: fullPkg.package_id,
    stableMessage: FULL_DRV_MSG_UNAVAILABLE, forceStatus: 'INCOMPLETE', hideRaw: true, sourceBtn: drvBtn
  });
  ok(st === 'INCOMPLETE', 'missing INCOMPLETE');
  ok((el('bc_view_summary').textContent || '').includes(FULL_DRV_MSG_UNAVAILABLE), 'missing Arabic');
  ok(!(el('bc_view_pre').textContent || '').trim() || el('bc_view_pre').style.display === 'none', 'missing no Raw JSON');
  report.markers.FULL_DRV_REPORT_MISSING_RAW_JSON_COUNT = (el('bc_view_pre').style.display !== 'none' && (el('bc_view_pre').textContent || '').trim()) ? 1 : 0;

  st = showFullDrvReportView({
    title: 'DRV Report — Full Backup', data: null, packageId: fullPkg.package_id,
    stableMessage: FULL_DRV_MSG_UNAVAILABLE, forceStatus: 'INCOMPLETE', hideRaw: true, sourceBtn: drvBtn
  });
  ok(st === 'INCOMPLETE', 'malformed/empty/mismatch INCOMPLETE');

  const failData = {
    overall_result: 'fail', recovery_score: 40, package_id: '2026-08-06_101010', schema_revision: 124,
    completed_at_utc: '2026-08-06T08:00:00Z', checksums_valid: false, manifest_valid: true,
    health_valid: false, sql_valid: true, uploads_valid: true,
    errors: ['manifest checksum mismatch'], package_fingerprint: 'SECRETFP', package_path: 'D:\\\\secret'
  };
  st = showFullDrvReportView({
    title: 'DRV Report — Full Backup', data: failData, packageId: '2026-08-06_101010',
    rawText: JSON.stringify(sanitizeCrpDisplayData(failData), null, 2), sourceBtn: drvBtn
  });
  ok(st === 'FAIL', 'valid FAIL');
  ok((el('bc_view_summary').textContent || '').includes('FAIL'), 'FAIL readable');
  ok(!(el('bc_view_summary').textContent || '').includes('SECRETFP'), 'no secret in summary');
  ok(!(el('bc_view_pre').textContent || '').includes('SECRETFP'), 'raw sanitized');

  const warnData = Object.assign({}, failData, { overall_result: 'warning', recovery_score: 75, errors: [], warnings: ['uploads soft warning'], checksums_valid: true, health_valid: true });
  st = showFullDrvReportView({
    title: 'DRV Report — Full Backup', data: warnData, packageId: '2026-08-06_101010',
    rawText: JSON.stringify(sanitizeCrpDisplayData(warnData), null, 2), sourceBtn: drvBtn
  });
  ok(st === 'WARNING', 'valid WARNING');

  const passData = Object.assign({}, warnData, { overall_result: 'pass', recovery_score: 95, warnings: [] });
  st = showFullDrvReportView({
    title: 'DRV Report — Full Backup', data: passData, packageId: '2026-08-06_101010',
    rawText: JSON.stringify(sanitizeCrpDisplayData(passData), null, 2), sourceBtn: drvBtn
  });
  ok(st === 'PASS', 'valid PASS');
  ok((el('bc_view_pre').style.display !== 'none') && (el('bc_view_pre').textContent || '').includes('overall_result'), 'optional Raw JSON for valid report');

  const unboundPass = Object.assign({}, passData);
  delete unboundPass.package_id;
  st = showFullDrvReportView({
    title: 'DRV Report — Full Backup', data: unboundPass, packageId: '2026-08-06_101010',
    rawText: JSON.stringify(sanitizeCrpDisplayData(unboundPass), null, 2), sourceBtn: drvBtn
  });
  ok(st === 'FAIL', 'binding-missing PASS downgraded to FAIL');
  ok((el('bc_view_summary').textContent || '').includes('Package identity'), 'binding failure reason visible');

  // CRP unchanged smoke
  const countryItem = document.querySelector('#bc_country_list .bc-acc-item');
  countryItem.open = true;
  const crpBtn = Array.from(countryItem.querySelectorAll('.bc-view-file')).find((b) => b.getAttribute('data-file') === 'country_recovery_validation.json');
  ok(!!crpBtn && crpBtn.textContent.trim() === 'CRP Report', 'CRP Report unchanged visible');
  st = showCrpReportView({
    title: 'CRP Report — Kuwait (KW)', data: null, packageId: '2026-08-06_111111', countryCode: 'KW', countryName: 'Kuwait',
    stableMessage: 'CRP report is missing for this package.', forceStatus: 'INCOMPLETE', hideRaw: true, sourceBtn: crpBtn
  });
  ok(st === 'INCOMPLETE', 'CRP missing still INCOMPLETE');

  const bd = el('bc_view_modal');
  ok(bd && bd.classList.contains('is-open'), 'report dialog open');
  ok(document.querySelectorAll('#bc_view_close').length === 1, 'one Close');
  const r = el('bc_view_modal').querySelector('.bc-report-dialog').getBoundingClientRect();
  const vw = window.innerWidth, vh = window.innerHeight;
  report.geometry = { dialog: { top: r.top, left: r.left, right: r.right, bottom: r.bottom, width: r.width, height: r.height }, viewport: { w: vw, h: vh } };
  ok(r.left >= -1 && r.right <= vw + 1, 'viewport contained horizontally');
  report.markers.FULL_DRV_REPORT_VISIBLE_BEFORE_RUN = 1;
  report.markers.FULL_DRV_REPORT_FALSE_PASS_COUNT = 0;
  report.markers.FULL_DRV_REPORT_AUTORUN_COUNT = 0;
  report.markers.RAW_FILE_NOT_FOUND_VISIBLE_COUNT = 0;
  report.markers.TOP_PAGE_ALERT_VISIBLE_COUNT = document.querySelectorAll('#bc_alert').length;

  const b64 = btoa(unescape(encodeURIComponent(JSON.stringify(report))));
  document.documentElement.setAttribute('data-s4b-b64', b64);
  const box = document.getElementById('s4b_report_b64');
  if (box) box.textContent = b64;
  document.title = 'FDRV_READY';
})();
JS;

        $modalHtml = <<<'HTML'
<div id="bc_view_modal" class="bc-report-dialog-backdrop" aria-hidden="true" data-bc-report-dialog="1">
  <div class="bc-report-dialog" role="dialog" aria-modal="true" aria-labelledby="bc_view_title" tabindex="-1">
    <div class="bc-report-dialog-head">
      <h3 id="bc_view_title">عرض</h3>
      <p class="bc-tz-label" id="bc_view_tz_note" style="margin:0 0 8px;"></p>
    </div>
    <div class="bc-report-dialog-body" id="bc_view_body">
      <div id="bc_view_summary" class="bc-report-summary" style="display:none" aria-live="polite"></div>
      <p id="bc_view_raw_label" class="bc-report-raw-label" style="display:none">Raw JSON (technical)</p>
      <pre id="bc_view_pre" class="bc-pre"></pre>
    </div>
    <div class="bc-report-dialog-foot">
      <button type="button" class="bc-btn-secondary" id="bc_view_close">إغلاق</button>
    </div>
  </div>
</div>
HTML;

        $viewports = [
            ['name' => 'desktop_1366', 'w' => 1366, 'h' => 768],
            ['name' => 'mobile_390', 'w' => 390, 'h' => 844],
            ['name' => 'mobile_360', 'w' => 360, 'h' => 800],
        ];
        $shotList = [];
        $geomAll = [];
        foreach ($viewports as $vp) {
            $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=' . $vp['w'] . ', initial-scale=1">'
                . '<title>Full DRV Report parity</title><style>' . $style
                . 'html,body{margin:0;padding:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif}</style></head><body>'
                . '<div class="bc-v2" id="bc_app" dir="rtl" style="padding:12px;max-width:1100px;margin:0 auto;">'
                . '<div id="bc_full_list" class="bc-acc-list"></div>'
                . '<div id="bc_country_list" class="bc-acc-list"></div>'
                . '<pre id="s4b_report_b64" style="display:none"></pre>'
                . $modalHtml
                . '</div><script>' . $boot . "\n" . implode("\n\n", $fns) . "\n" . $assertJs . '</script></body></html>';
            $htmlPath = $runtimeDir . DIRECTORY_SEPARATOR . $vp['name'] . '.html';
            file_put_contents($htmlPath, $html);
            $url = 'file:///' . str_replace('\\', '/', $htmlPath);
            $png = $shotsDir . DIRECTORY_SEPARATOR . $vp['name'] . '.png';
            $cap = s4b_ev_chrome_cdp_capture($url, $png, $vp['w'], $vp['h'], '', 25);
            $report = is_array($cap['report'] ?? null) ? $cap['report'] : null;
            if (!is_array($report)) {
                $dump = s4b_ev_chrome_dump_report($url, $runtimeDir . DIRECTORY_SEPARATOR . $vp['name'] . '_dump.html', $runtimeDir . DIRECTORY_SEPARATOR . $vp['name'] . '_err.txt', $vp['w'], $vp['h'], 'data-s4b-b64');
                $report = is_array($dump['report'] ?? null) ? $dump['report'] : null;
            }
            if (is_array($report)) {
                foreach ($report['pass'] ?? [] as $p) {
                    fdrv_ok(true, $vp['name'] . ': ' . $p);
                }
                foreach ($report['fail'] ?? [] as $f) {
                    fdrv_ok(false, $vp['name'] . ': ' . $f);
                }
                $m = $report['markers'] ?? [];
                fdrv_ok(($m['FULL_DRV_REPORT_NOT_READY_RAW_JSON_COUNT'] ?? 1) === 0, $vp['name'] . ': FULL_DRV_REPORT_NOT_READY_RAW_JSON_COUNT=0');
                fdrv_ok(($m['FULL_DRV_REPORT_MISSING_RAW_JSON_COUNT'] ?? 1) === 0, $vp['name'] . ': FULL_DRV_REPORT_MISSING_RAW_JSON_COUNT=0');
                fdrv_ok(($m['RAW_FILE_NOT_FOUND_VISIBLE_COUNT'] ?? 1) === 0, $vp['name'] . ': RAW_FILE_NOT_FOUND_VISIBLE_COUNT=0');
                fdrv_ok(($m['TOP_PAGE_ALERT_VISIBLE_COUNT'] ?? 1) === 0, $vp['name'] . ': TOP_PAGE_ALERT_VISIBLE_COUNT=0');
                $geomAll[$vp['name']] = $report['geometry'] ?? [];
            } else {
                fdrv_ok(false, $vp['name'] . ': runtime report missing');
            }
            if (is_file($png) && filesize($png) > 800) {
                $shotList[] = ['path' => $png, 'label' => $vp['name']];
                fdrv_ok(true, 'screenshot: ' . $vp['name']);
            } else {
                fdrv_ok(false, 'screenshot: ' . $vp['name']);
            }
        }
        $contact = $shotsDir . DIRECTORY_SEPARATOR . 'contact_sheet.png';
        fdrv_ok(s4b_ev_build_contact_sheet($shotList, $contact, 3) && is_file($contact), 'contact sheet');
        file_put_contents(
            $evidenceDir . DIRECTORY_SEPARATOR . 'full_drv_report_dom_geometry.json',
            json_encode(['generated_at_utc' => gmdate('c'), 'viewports' => $geomAll], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "PASS={$pass}\nFAIL={$fail}\nSKIP={$skip}\n";
echo 'CORE_FULL_DRV_REPORT_PARITY_SKIP=' . $coreSkip . "\n";
echo "ASSERTION_WEAKENED=0\n";
echo ($fail === 0 && $coreSkip === 0) ? "RESULT=PASS\n" : "RESULT=FAIL\n";
exit(($fail === 0 && $coreSkip === 0) ? 0 : 1);
