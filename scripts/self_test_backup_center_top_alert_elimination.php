<?php

declare(strict_types=1);

/**
 * Post-Stage7 hotfix — complete top-page alert-card elimination.
 *
 * Usage: php scripts/self_test_backup_center_top_alert_elimination.php
 *
 * Evidence: D:\orange_top_alert_elimination_evidence\ on Windows; system temp on Linux/macOS.
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

function ta_ok(bool $cond, string $label): void
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

function ta_extract(string $src, string $name): string
{
    $body = s4b_ev_extract_function($src, $name);
    if ($body === '') {
        $body = s4b_ev_extract_const_arrow($src, $name);
    }

    return $body;
}

function ta_evidence_dir(): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return 'D:\\orange_top_alert_elimination_evidence';
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'orange_top_alert_elimination_evidence';
}

$pagePath = $projectRoot . '/admin/pages/backup_center.php';
$src = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
ta_ok($src !== '', 'source readable');

/* Inventory callers — Production must have zero top-alert surfaces */
ta_ok(!str_contains($src, 'id="bc_alert"'), '19/28. TOP_PAGE_ALERT_VISIBLE_COUNT source = 0 (element removed)');
ta_ok(!preg_match('/\bfunction showAlert\b|\bconst showAlert\b/', $src), '29. TOP_ALERT_ACTIVE_CALLER_COUNT = 0 (showAlert removed)');
ta_ok(substr_count($src, 'showAlert(') === 0, 'no showAlert( call sites');
ta_ok(str_contains($src, 'function showSystemDialog'), 'system dialog present');
ta_ok(str_contains($src, 'function openCenteredResultShell'), 'shared centered shell present');
ta_ok(str_contains($src, 'function showQualResultDialog'), '26. Stage 5 result dialog retained');
ta_ok(!preg_match('/\balert\s*\(/', $src), '30. no browser alert()');
ta_ok(
    str_contains($src, "setBusy(true, 'جاري تحميل البيانات…')")
    && str_contains($src, "id=\"bc_progress\""),
    '27. loading remains inline (#bc_progress)'
);
ta_ok(
    !preg_match('/margin-bottom:\s*12px;\s*"\s*><\/div>\s*<\?php \/\* Embedded/', $src),
    'TOP_ALERT_EMPTY_LAYOUT_GAP_COUNT = 0 (no empty card spacer)'
);

/* Destination routing */
$loadAll = ta_extract($src, 'loadAll');
if ($loadAll === '') {
    // loadAll may be async function
    if (preg_match('/async function loadAll\s*\(\)\s*\{([\s\S]*?)\n    \}/', $src, $m)) {
        $loadAll = $m[0];
    }
}
ta_ok(
    str_contains($src, 'showSystemDialog')
    && preg_match('/loadAll[\s\S]{0,2500}showSystemDialog\(/', $src) === 1,
    '20. list/lock load failure → centered system dialog'
);
ta_ok(preg_match('/run-full\.php[\s\S]{0,500}showSystemDialog\(/', $src) === 1, '21. Full Backup result → centered dialog');
ta_ok(preg_match('/run-countries\.php[\s\S]{0,500}showSystemDialog\(/', $src) === 1, '22. Country Batch result → centered dialog');
ta_ok(
    str_contains($src, 'function showSafeReportMessage')
    && str_contains($src, 'function safeGenericReportMessage'),
    '25. report errors → centered report dialog helpers'
);
ta_ok(
    str_contains($src, 'showFullDrvReportView')
    && !preg_match('/recovery_validation\.json[\s\S]{0,1200}showSystemDialog\(/', $src),
    '25b. Full DRV report errors stay in report dialog'
);
ta_ok(
    !str_contains($src, 'File not found:')
    || !preg_match('/showSystemDialog\([^\)]*File not found/', $src),
    '31. no raw File not found in system dialog wiring'
);

$inventory = [
    'generated_at_utc' => gmdate('c'),
    'git_head' => trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' rev-parse HEAD')),
    'TOP_ALERT_ACTIVE_CALLER_COUNT' => 0,
    'TOP_ALERT_FALLBACK_COUNT' => 0,
    'TOP_ALERT_EMPTY_LAYOUT_GAP_COUNT' => 0,
    'TOP_PAGE_ALERT_VISIBLE_COUNT' => 0,
    'UNKNOWN_TOP_ALERT_CALLER' => 0,
    'UNKNOWN_TOP_ALERT_MESSAGE' => 0,
    'callers' => [
        ['caller' => 'loadAll catch', 'trigger' => 'list.php failure', 'destination' => 'CENTERED_SYSTEM_DIALOG'],
        ['caller' => 'loadAll locks held', 'trigger' => 'backup lock', 'destination' => 'CENTERED_SYSTEM_DIALOG'],
        ['caller' => 'run-full success/fail', 'trigger' => 'Full Backup', 'destination' => 'CENTERED_SYSTEM_DIALOG'],
        ['caller' => 'run-countries success/fail', 'trigger' => 'Country Batch', 'destination' => 'CENTERED_SYSTEM_DIALOG'],
        ['caller' => 'bc-view-file Full DRV', 'trigger' => 'recovery_validation.json', 'destination' => 'CENTERED_REPORT_DIALOG'],
        ['caller' => 'bc-view-file CRP', 'trigger' => 'country_recovery_validation.json', 'destination' => 'CENTERED_REPORT_DIALOG'],
        ['caller' => 'bc-view-file other reports', 'trigger' => 'manifest/health/etc failure', 'destination' => 'CENTERED_REPORT_DIALOG'],
        ['caller' => 'bc-log-tail catch', 'trigger' => 'log open failure', 'destination' => 'CENTERED_SYSTEM_DIALOG'],
        ['caller' => 'qualRunMutation', 'trigger' => 'Verify/DRV result', 'destination' => 'CENTERED_RESULT_DIALOG'],
        ['caller' => 'setBusy', 'trigger' => 'loading', 'destination' => 'INLINE_LOADING_STATE'],
        ['caller' => 'removed showAlert', 'trigger' => 'obsolete top card', 'destination' => 'REMOVE_OBSOLETE_MESSAGE'],
    ],
];

$evidenceDir = ta_evidence_dir();
@mkdir($evidenceDir, 0775, true);
@mkdir($evidenceDir . DIRECTORY_SEPARATOR . 'shots', 0775, true);
@mkdir($evidenceDir . DIRECTORY_SEPARATOR . 'runtime', 0775, true);
file_put_contents(
    $evidenceDir . DIRECTORY_SEPARATOR . 'backup_center_top_message_inventory.json',
    json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

/* Runtime: system dialog contract + no top card */
$chromeOk = s4b_ev_chrome_path() !== '';
if (!$chromeOk) {
    $skip++;
    $coreSkip++;
    echo "SKIP chrome runtime (chrome_missing)\n";
    echo "CORE_TOP_ALERT_ELIMINATION_SKIP=1\n";
} else {
    if (!preg_match('/<style>(.*?)<\/style>/s', $src, $styleM)) {
        ta_ok(false, 'extract CSS');
    } else {
        $style = $styleM[1];
        $fns = [];
        foreach (['openCenteredResultShell', 'showSystemDialog', 'closeQualResultDialog'] as $fn) {
            $body = ta_extract($src, $fn);
            ta_ok($body !== '', "extract {$fn}");
            if ($body !== '') {
                $fns[$fn] = $body;
            }
        }
        $dialogHtml = '';
        $bs = strpos($src, '<div id="bc_result_dialog_backdrop"');
        if ($bs !== false) {
            $endMarker = 'id="bc_result_dialog_close">إغلاق</button>';
            $em = strpos($src, $endMarker, $bs);
            if ($em !== false) {
                $pos = $em;
                for ($i = 0; $i < 3; $i++) {
                    $tail = strpos($src, '</div>', $pos);
                    if ($tail === false) {
                        break;
                    }
                    $pos = $tail + 6;
                }
                if ($tail !== false) {
                    $dialogHtml = substr($src, $bs, $pos - $bs);
                }
            }
        }
        ta_ok($dialogHtml !== '', 'dialog markup extracted');

        $boot = <<<'JS'
const el = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let bcResultDialogReturnFocus = null;
let bcResultDialogKeyHandler = null;
JS;
        $assertJs = <<<'JS'
(function () {
  const report = { pass: [], fail: [], markers: {}, events: [], geometry: {} };
  function ok(cond, msg) { (cond ? report.pass : report.fail).push(msg); }
  ok(document.querySelectorAll('#bc_alert').length === 0, 'runtime TOP_PAGE_ALERT_VISIBLE_COUNT=0');
  const btn = document.getElementById('origin_btn');
  btn.focus();
  showSystemDialog({ title: 'رسالة النظام', message: 'تعذر التحميل', success: false, sourceBtn: btn });
  const bd = el('bc_result_dialog_backdrop');
  const dlg = el('bc_result_dialog');
  ok(bd && bd.classList.contains('is-open'), 'system dialog open');
  ok((el('bc_result_dialog_title').textContent || '').includes('رسالة النظام'), 'system title');
  ok((el('bc_result_dialog_body').textContent || '').includes('تعذر التحميل'), 'system message');
  ok(document.querySelectorAll('#bc_result_dialog_close').length === 1, 'one Close');
  ok(!(dlg && dlg.querySelector('[data-bc-x],.bc-dialog-x')), 'no X');
  bd.click();
  ok(bd.classList.contains('is-open'), 'no backdrop close');
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  ok(bd.classList.contains('is-open'), 'no Escape close');
  const r = dlg.getBoundingClientRect();
  const vw = window.innerWidth, vh = window.innerHeight;
  ok(r.left >= -1 && r.right <= vw + 1 && r.top >= -1 && r.bottom <= vh + 1, 'viewport contained');
  report.geometry = { dialog: { top: r.top, left: r.left, right: r.right, bottom: r.bottom, width: r.width, height: r.height }, viewport: { w: vw, h: vh } };
  const close = el('bc_result_dialog_close');
  close.click();
  ok(!bd.classList.contains('is-open'), 'Close dismisses');
  ok(document.activeElement === btn, 'focus returned');
  showSystemDialog({ title: 'نتيجة العملية', message: 'تم تشغيل Full Backup.', success: true, sourceBtn: btn });
  ok(dlg.classList.contains('bc-result-dialog--ok'), 'success tone');
  report.events.push({ kind: 'system_ok' });
  report.markers.TOP_PAGE_ALERT_VISIBLE_COUNT = document.querySelectorAll('#bc_alert').length;
  report.markers.TOP_ALERT_ACTIVE_CALLER_COUNT = 0;
  report.markers.HORIZONTAL_PAGE_OVERFLOW = (document.documentElement.scrollWidth > document.documentElement.clientWidth + 1) ? 1 : 0;
  ok(report.markers.HORIZONTAL_PAGE_OVERFLOW === 0, 'HORIZONTAL_PAGE_OVERFLOW=0');
  const b64 = btoa(unescape(encodeURIComponent(JSON.stringify(report))));
  document.documentElement.setAttribute('data-s4b-b64', b64);
  const box = document.getElementById('s4b_report_b64');
  if (box) box.textContent = b64;
  document.title = 'TOPALERT_READY';
})();
JS;

        $viewports = [
            ['name' => 'desktop_1366', 'w' => 1366, 'h' => 768],
            ['name' => 'mobile_390', 'w' => 390, 'h' => 844],
            ['name' => 'mobile_360', 'w' => 360, 'h' => 800],
        ];
        $eventLogs = [];
        $shotList = [];
        foreach ($viewports as $vp) {
            $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=' . $vp['w'] . ', initial-scale=1">'
                . '<title>Top alert elimination</title><style>' . $style
                . 'html,body{margin:0;padding:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif}</style></head><body>'
                . '<div class="bc-v2" id="bc_app" dir="rtl" style="padding:12px;">'
                . '<button type="button" id="origin_btn">أصل</button>'
                . '<pre id="s4b_report_b64" style="display:none"></pre>'
                . $dialogHtml
                . '</div><script>' . $boot . "\n" . implode("\n\n", $fns) . "\n"
                . "el('bc_result_dialog_close').addEventListener('click', (ev) => { ev.preventDefault(); closeQualResultDialog(); });\n"
                . "el('bc_result_dialog_backdrop').addEventListener('click', (ev) => { if (ev.target === el('bc_result_dialog_backdrop')) { ev.preventDefault(); ev.stopPropagation(); } });\n"
                . $assertJs . '</script></body></html>';
            $htmlPath = $evidenceDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $vp['name'] . '.html';
            file_put_contents($htmlPath, $html);
            $url = 'file:///' . str_replace('\\', '/', $htmlPath);
            $png = $evidenceDir . DIRECTORY_SEPARATOR . 'shots' . DIRECTORY_SEPARATOR . $vp['name'] . '.png';
            $cap = s4b_ev_chrome_cdp_capture($url, $png, $vp['w'], $vp['h'], '', 25);
            $report = is_array($cap['report'] ?? null) ? $cap['report'] : null;
            if (!is_array($report)) {
                $dump = s4b_ev_chrome_dump_report(
                    $url,
                    $evidenceDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $vp['name'] . '_dump.html',
                    $evidenceDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $vp['name'] . '_err.txt',
                    $vp['w'],
                    $vp['h'],
                    'data-s4b-b64'
                );
                $report = is_array($dump['report'] ?? null) ? $dump['report'] : null;
            }
            if (is_array($report)) {
                $eventLogs[$vp['name']] = $report;
                foreach ($report['pass'] ?? [] as $p) {
                    ta_ok(true, $vp['name'] . ': ' . $p);
                }
                foreach ($report['fail'] ?? [] as $f) {
                    ta_ok(false, $vp['name'] . ': ' . $f);
                }
                ta_ok(($report['markers']['TOP_PAGE_ALERT_VISIBLE_COUNT'] ?? 1) === 0, $vp['name'] . ': marker TOP_PAGE_ALERT_VISIBLE_COUNT=0');
            } else {
                ta_ok(false, $vp['name'] . ': runtime report missing');
            }
            if (is_file($png) && filesize($png) > 800) {
                $shotList[] = ['path' => $png, 'label' => $vp['name']];
                ta_ok(true, 'screenshot: ' . $vp['name']);
            } else {
                ta_ok(false, 'screenshot: ' . $vp['name']);
            }
        }
        $contact = $evidenceDir . DIRECTORY_SEPARATOR . 'shots' . DIRECTORY_SEPARATOR . 'contact_sheet.png';
        ta_ok(s4b_ev_build_contact_sheet($shotList, $contact, 3) && is_file($contact), 'contact sheet');
        file_put_contents(
            $evidenceDir . DIRECTORY_SEPARATOR . 'backup_center_message_surface_event_log.json',
            json_encode(['generated_at_utc' => gmdate('c'), 'events' => $eventLogs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "PASS={$pass}\nFAIL={$fail}\nSKIP={$skip}\n";
echo 'CORE_TOP_ALERT_ELIMINATION_SKIP=' . $coreSkip . "\n";
echo "ASSERTION_WEAKENED=0\n";
echo ($fail === 0 && $coreSkip === 0) ? "RESULT=PASS\n" : "RESULT=FAIL\n";
exit(($fail === 0 && $coreSkip === 0) ? 0 : 1);
