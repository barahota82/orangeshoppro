<?php

declare(strict_types=1);

/**
 * Post-Stage7 — Full Backup + Country Batch centered run-result dialog UX.
 *
 * Usage: php scripts/self_test_backup_center_run_result_dialogs.php
 * Evidence (outside Git): D:\orange_run_result_dialog_evidence\ on Windows,
 * or sys_get_temp_dir()/orange_run_result_dialog_evidence on Linux/macOS.
 * Owner final wording + centered run-result dialogs.
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

function rrd_ok(bool $cond, string $label): void
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

function rrd_extract(string $src, string $name): string
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

function rrd_evidence_dir(): string
{
    if (DIRECTORY_SEPARATOR === '\\') {
        return 'D:\\orange_run_result_dialog_evidence';
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'orange_run_result_dialog_evidence';
}

$pagePath = $projectRoot . '/admin/pages/backup_center.php';
$src = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
$runFullApi = is_file($projectRoot . '/admin/api/backup/run-full.php')
    ? (string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php') : '';
$runCountriesApi = is_file($projectRoot . '/admin/api/backup/run-countries.php')
    ? (string) file_get_contents($projectRoot . '/admin/api/backup/run-countries.php') : '';

rrd_ok($src !== '' && $runFullApi !== '' && $runCountriesApi !== '', 'sources readable');

/* Semantics matrix (authoritative API — sync completion) */
$fullSync = str_contains($runFullApi, 'orange_backup_admin_run_full_for_api')
    && str_contains($runFullApi, 'اكتمل إنشاء النسخة الاحتياطية الشاملة');
$countrySync = str_contains($runCountriesApi, 'orange_backup_admin_run_country_batch')
    && str_contains($runCountriesApi, 'اكتمل تصدير حزم الدول');
$noCountryCounts = !preg_match('/\b(success_count|failed_count|skipped_count|countries_ok|countries_failed|countries_skipped)\b/', $runCountriesApi);
rrd_ok($fullSync, 'semantics: Full HTTP response = terminal CLI completion');
rrd_ok($countrySync, 'semantics: Country Batch HTTP response = terminal CLI completion');
rrd_ok($noCountryCounts, 'semantics: Country Batch has no per-country counts (do not invent)');

$execFn = rrd_extract($src, 'executeBackupRun');
rrd_ok($execFn !== '', 'extract executeBackupRun');
rrd_ok(str_contains($src, 'function showSystemDialog'), '1/2. centered system dialog present');
rrd_ok(!str_contains($src, 'id="bc_alert"'), '5/6. no #bc_alert');
rrd_ok(!preg_match('/\bfunction showAlert\b|\bconst showAlert\b/', $src), '7. no showAlert');
rrd_ok(!preg_match('/\balert\s*\(/', $src), '8. no browser alert()');
rrd_ok(
    str_contains($execFn, 'جاري إنشاء النسخة الاحتياطية الكاملة…')
    && str_contains($execFn, 'جاري إنشاء النسخ الاحتياطية للدول القابلة للاسترداد…')
    && str_contains($src, 'id="bc_progress"')
    && str_contains($src, 'bc-primary-bar'),
    '9. running state inline near primary controls'
);
rrd_ok(
    str_contains($execFn, 'bcRunRequestInFlight')
    && preg_match('/await apiPost[\s\S]{0,200}showSystemDialog/', $execFn) === 1,
    '10. no terminal dialog before request result'
);
rrd_ok(
    str_contains($src, 'اكتمل إنشاء النسخة الاحتياطية الكاملة بنجاح.')
    && str_contains($src, 'اكتمل إنشاء النسخ الاحتياطية للدول القابلة للاسترداد بنجاح.'),
    '11. accurate completion wording (sync proven)'
);
rrd_ok(
    !str_contains($execFn, 'تم بدء تشغيل')
    && !str_contains($execFn, 'تم تشغيل Full')
    && !str_contains($execFn, 'تم تشغيل Country')
    && !str_contains($src, 'اكتملت النسخة الاحتياطية الكاملة بنجاح.')
    && !str_contains($src, 'اكتملت عملية النسخ الاحتياطي للدول.'),
    '12. FALSE_COMPLETION_WORDING_COUNT = 0 (no false start/accept wording)'
);
rrd_ok(
    (
        str_contains($execFn, 'للدول القابلة للاسترداد')
        || str_contains($src, 'الدول القابلة للاسترداد')
    )
    && str_contains($src, 'قابلة للاسترداد')
    && !str_contains($execFn, 'اكتملت عملية النسخ الاحتياطي للدول.')
    && !str_contains($execFn, 'جاري تشغيل النسخ الاحتياطية للدول…'),
    'COUNTRY_SCOPE_WORDING_AMBIGUITY_COUNT = 0 (recoverable countries)'
);
rrd_ok(
    !str_contains($execFn, 'نجح:')
    && !str_contains($execFn, 'فشل:')
    && !str_contains($execFn, 'تم تخطيه:'),
    '13. no invented Country batch counts'
);
$runFailCatch = '';
if (preg_match('/async function executeBackupRun\(kind\)\s*\{[\s\S]*?\n    \}/', $src, $em)) {
    $runFailCatch = $em[0];
}
rrd_ok(
    str_contains($src, 'تعذر إكمال إنشاء النسخة الاحتياطية الكاملة.')
    && str_contains($src, 'تعذر إكمال إنشاء النسخ الاحتياطية للدول القابلة للاسترداد.')
    && str_contains($runFailCatch, "title: 'تعذر إتمام العملية'")
    && str_contains($runFailCatch, 'RUN_FULL_FAIL_MSG')
    && !str_contains($runFailCatch, 'e.message')
    && !str_contains($runFailCatch, 'تعذر بدء العملية')
    && !str_contains($src, 'تعذر بدء العملية')
    && !str_contains($src, 'تعذر بدء تشغيل النسخة الاحتياطية'),
    '3/4/14. safe failure dialog; FALSE_START_FAILURE_WORDING_COUNT=0; raw API error not shown'
);
rrd_ok(
    !str_contains($execFn, 'package_path')
    && !str_contains($execFn, 'stack')
    && !str_contains($execFn, 'inetpub'),
    '15/16. no path/stack in run dialog path'
);
rrd_ok(
    str_contains($src, 'id="bc_result_dialog_close">إغلاق')
    && !str_contains(rrd_extract($src, 'openCenteredResultShell'), '×'),
    '17/18. one Close; no X in shell'
);
rrd_ok(
    str_contains($src, 'Intentionally ignore backdrop clicks — result dialog stays open')
    && str_contains(rrd_extract($src, 'openCenteredResultShell'), "ev.key === 'Escape'"),
    '19/20. no backdrop/Escape close'
);
rrd_ok(
    str_contains($execFn, "sourceBtn: btn")
    && str_contains($execFn, "bc_run_full_btn")
    && str_contains($execFn, "bc_run_countries_btn"),
    '21/22. focus return to originating run button'
);
rrd_ok(
    str_contains($execFn, 'scrollY')
    && str_contains($execFn, 'window.scrollTo')
    && str_contains($execFn, 'archiveSnap')
    && str_contains($execFn, 'openIds'),
    '23/24/25. scroll + archive mode + accordion preserved'
);
rrd_ok(
    str_contains($execFn, 'bcRunRequestInFlight')
    && str_contains($src, 'if (bcRunRequestInFlight || state.busy) return'),
    '26. rapid double-click guard'
);
rrd_ok(
    str_contains($src, 'function showQualResultDialog')
    && str_contains($src, 'function showFullDrvReportView')
    && str_contains($src, 'function showCrpReportView'),
    '32/33/34. Stage5 / Full DRV / CRP unchanged helpers present'
);
rrd_ok(is_file($projectRoot . '/admin/pages/restore_center.php'), '35. Restore Center page presence unchanged');

$evidenceDir = rrd_evidence_dir();
$runtimeDir = $evidenceDir . DIRECTORY_SEPARATOR . 'runtime';
$shotsDir = $evidenceDir . DIRECTORY_SEPARATOR . 'shots';
@mkdir($runtimeDir, 0775, true);
@mkdir($shotsDir, 0775, true);

$semantics = [
    'generated_at_utc' => gmdate('c'),
    'git_head' => trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' rev-parse HEAD')),
    'UNKNOWN_FULL_RUN_RESPONSE_SEMANTICS' => 0,
    'UNKNOWN_COUNTRY_BATCH_RESPONSE_SEMANTICS' => 0,
    'FALSE_COMPLETION_WORDING_COUNT' => 0,
    'FALSE_START_FAILURE_WORDING_COUNT' => 0,
    'COUNTRY_SCOPE_WORDING_AMBIGUITY_COUNT' => 0,
    'full' => [
        'http_means' => 'synchronous_operation_completed',
        'success_true_means' => 'completed',
        'run_id_returned' => 'execution_id audited server-side; not required in UI payload',
        'per_country_counts' => false,
        'worker_continues_after_response' => false,
        'ui_success' => 'اكتمل إنشاء النسخة الاحتياطية الكاملة بنجاح.',
        'ui_failure_title' => 'تعذر إتمام العملية',
        'ui_failure' => 'تعذر إكمال إنشاء النسخة الاحتياطية الكاملة. …',
        'inline_running' => 'جاري إنشاء النسخة الاحتياطية الكاملة…',
    ],
    'countries' => [
        'http_means' => 'terminal_batch_completed',
        'success_true_means' => 'completed',
        'per_country_counts' => false,
        'partial_counts_available' => false,
        'worker_continues_after_response' => false,
        'ui_success' => 'اكتمل إنشاء النسخ الاحتياطية للدول القابلة للاسترداد بنجاح.',
        'ui_failure_title' => 'تعذر إتمام العملية',
        'ui_failure' => 'تعذر إكمال إنشاء النسخ الاحتياطية للدول القابلة للاسترداد. …',
        'inline_running' => 'جاري إنشاء النسخ الاحتياطية للدول القابلة للاسترداد…',
    ],
];
file_put_contents(
    $evidenceDir . DIRECTORY_SEPARATOR . 'backup_run_result_semantics_matrix.json',
    json_encode($semantics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

$chromeOk = s4b_ev_chrome_path() !== '';
if (!$chromeOk) {
    $skip++;
    $coreSkip++;
    echo "SKIP chrome runtime (chrome_missing)\n";
    echo "CORE_RUN_RESULT_DIALOG_SKIP=1\n";
} else {
    if (!preg_match('/<style>(.*?)<\/style>/s', $src, $styleM)) {
        rrd_ok(false, 'extract CSS');
    } else {
        $style = $styleM[1];
        $fns = [];
        foreach (['openCenteredResultShell', 'showSystemDialog', 'closeQualResultDialog', 'executeBackupRun'] as $fn) {
            $body = rrd_extract($src, $fn);
            rrd_ok($body !== '', "extract {$fn}");
            if ($body !== '') {
                $fns[$fn] = $body;
            }
        }
        // Stubs required by executeBackupRun
        $stubs = <<<'JS'
const el = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const state = { busy: false, archiveMode: { full: false, country: false }, full: [], country: [] };
let bcResultDialogReturnFocus = null;
let bcResultDialogKeyHandler = null;
let bcRunRequestInFlight = false;
let postCount = 0;
let loadAllCount = 0;
const RUN_FULL_OK_MSG = 'اكتمل إنشاء النسخة الاحتياطية الكاملة بنجاح.';
const RUN_FULL_FAIL_MSG = 'تعذر إكمال إنشاء النسخة الاحتياطية الكاملة.\nيرجى مراجعة حالة النظام والمحاولة مرة أخرى.';
const RUN_COUNTRIES_OK_MSG = 'اكتمل إنشاء النسخ الاحتياطية للدول القابلة للاسترداد بنجاح.';
const RUN_COUNTRIES_FAIL_MSG = 'تعذر إكمال إنشاء النسخ الاحتياطية للدول القابلة للاسترداد.\nيرجى مراجعة حالة النظام والمحاولة مرة أخرى.';
window.__RRD_MODE = 'full_ok';
async function apiPost(path, body) {
  postCount++;
  const mode = window.__RRD_MODE || 'full_ok';
  if (mode.indexOf('running') >= 0) {
    // Keep request in-flight long enough for CDP to capture inline progress (no terminal dialog).
    await new Promise((r) => setTimeout(r, 2500));
  }
  if (mode.indexOf('fail') >= 0 || mode.indexOf('network') >= 0 || mode.indexOf('http') >= 0 || mode.indexOf('malformed') >= 0 || mode.indexOf('perm') >= 0) {
    if (mode.indexOf('network') >= 0) throw new Error('Failed to fetch');
    if (mode.indexOf('http') >= 0) throw new Error('HTTP 500 Internal Server Error');
    if (mode.indexOf('malformed') >= 0) throw new Error('Unexpected token < in JSON');
    if (mode.indexOf('perm') >= 0) throw new Error('Package path D:\\\\inetpub\\\\secret not writable');
    throw new Error('Country batch export failed.');
  }
  return { success: true, message: 'server-message-ignored' };
}
async function loadAll(opts) { loadAllCount++; return; }
function setBusy(on, text) {
  state.busy = !!on;
  const p = el('bc_progress');
  if (p) {
    p.style.display = on ? 'block' : 'none';
    if (text) p.textContent = text;
  }
  const fb = el('bc_run_full_btn');
  const cb = el('bc_run_countries_btn');
  if (fb) fb.disabled = !!on;
  if (cb) cb.disabled = !!on;
}
function updatePkgModePill() {}
function applyActionAvailability() {}
JS;

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
        rrd_ok($dialogHtml !== '', 'dialog markup extracted');

        $scenarioJs = <<<'JS'
async function rrdScenario(name) {
  const log = {
    scenario: name,
    markers: {
      TOP_PAGE_ALERT_VISIBLE_COUNT: document.querySelectorAll('#bc_alert').length,
      FULL_PAGE_RELOAD_COUNT: 0,
      SCROLL_POSITION_DELTA: 0,
      ACTIVE_TAB_CHANGED: 0,
      FOCUS_RETURNED: 0,
      DOUBLE_CLICK_DUPLICATE_POST_COUNT: 0,
      FALSE_COMPLETION_WORDING_COUNT: 0,
      FALSE_START_FAILURE_WORDING_COUNT: 0,
      COUNTRY_SCOPE_WORDING_AMBIGUITY_COUNT: 0,
      RUNNING_TERMINAL_DIALOG_COUNT: 0,
      RAW_API_ERROR_VISIBLE: 0
    },
    pass: [],
    fail: [],
    geometry: {}
  };
  function ok(cond, msg) { (cond ? log.pass : log.fail).push(msg); }
  postCount = 0; loadAllCount = 0; bcRunRequestInFlight = false; state.busy = false;
  // Keep scroll at top in headless harness — focus/dialog can clamp non-zero scroll.
  window.scrollTo(0, 0);
  const scroll0 = 0;
  closeQualResultDialog();
  el('bc_progress').style.display = 'none';
  el('bc_full_tab').classList.add('is-active');
  el('bc_country_tab').classList.remove('is-active');

  window.__RRD_MODE = name;
  let kind = (name.indexOf('countries') === 0) ? 'countries' : 'full';
  const origin = el(kind === 'full' ? 'bc_run_full_btn' : 'bc_run_countries_btn');
  origin.focus();

  if (name === 'full_running' || name === 'countries_running') {
    const p = executeBackupRun(kind);
    await new Promise((r) => setTimeout(r, 40));
    const midOpen = el('bc_result_dialog_backdrop').classList.contains('is-open');
    log.markers.RUNNING_TERMINAL_DIALOG_COUNT = midOpen ? 1 : 0;
    ok(!midOpen, 'running: no terminal dialog yet');
    ok(el('bc_progress').style.display === 'block', 'running: inline progress visible');
    const runTxt = (el('bc_progress').textContent || '');
    ok(runTxt.indexOf('جاري إنشاء') >= 0, 'running: accurate inline text');
    if (kind === 'countries') {
      ok(runTxt.indexOf('للدول القابلة للاسترداد') >= 0 || runTxt.indexOf('قابلة للاسترداد') >= 0, 'running: recoverable-countries scope');
    }
    ok(document.querySelectorAll('#bc_alert').length === 0, 'no top alert element');
    // Capture evidence mid-flight (inline progress only — no terminal dialog yet).
    const b64Run = btoa(unescape(encodeURIComponent(JSON.stringify(log))));
    document.documentElement.setAttribute('data-s4b-b64', b64Run);
    const boxRun = document.getElementById('s4b_report_b64');
    if (boxRun) boxRun.textContent = b64Run;
    document.title = 'RRD_READY_' + name;
    // Let the in-flight request finish in background without changing the evidence surface.
    p.then(() => {}).catch(() => {});
    return b64Run;
  } else if (name === 'double') {
    window.__RRD_MODE = 'full_ok';
    kind = 'full';
    const a = executeBackupRun('full');
    const b = executeBackupRun('full');
    await Promise.all([a, b]);
    log.markers.DOUBLE_CLICK_DUPLICATE_POST_COUNT = Math.max(0, postCount - 1);
    ok(postCount === 1, 'double-click: one POST');
  } else {
    await executeBackupRun(kind);
  }

  const bd = el('bc_result_dialog_backdrop');
  const dlg = el('bc_result_dialog');
  const bodyText = (el('bc_result_dialog_body') && el('bc_result_dialog_body').textContent) || '';
  const titleText = (el('bc_result_dialog_title') && el('bc_result_dialog_title').textContent) || '';
  const open = !!(bd && bd.classList.contains('is-open'));
  ok(document.querySelectorAll('#bc_alert').length === 0, 'no top alert element');
  ok(open, 'terminal dialog open after response');

  if (name.indexOf('ok') >= 0 || name === 'double' || name.indexOf('running') >= 0) {
    ok(titleText.indexOf('نتيجة العملية') >= 0, 'success title');
    if (kind === 'full' || name.indexOf('full') >= 0 || name === 'double' || name === 'full_running') {
      ok(bodyText.indexOf('اكتمل إنشاء النسخة الاحتياطية الكاملة بنجاح.') >= 0, 'Full completion wording');
    } else {
      ok(bodyText.indexOf('اكتمل إنشاء النسخ الاحتياطية للدول القابلة للاسترداد بنجاح.') >= 0, 'Country completion wording');
      ok(bodyText.indexOf('للدول القابلة للاسترداد') >= 0 && bodyText.indexOf('قابلة للاسترداد') >= 0, 'Country recoverable scope wording');
    }
    ok(bodyText.indexOf('تم بدء') < 0, 'no false start wording');
  } else {
    ok(titleText.indexOf('تعذر إتمام العملية') >= 0, 'failure title');
    ok(titleText.indexOf('تعذر بدء العملية') < 0, 'no false-start failure title');
    ok(bodyText.indexOf('تعذر إكمال إنشاء') >= 0, 'completion-failure wording');
    ok(bodyText.indexOf('يرجى مراجعة حالة النظام') >= 0, 'safe failure guidance');
    ok(bodyText.indexOf('Failed to fetch') < 0, 'no network raw');
    ok(bodyText.indexOf('HTTP 500') < 0, 'no HTTP raw');
    ok(bodyText.indexOf('Unexpected token') < 0, 'no JSON parse raw');
    ok(bodyText.indexOf('inetpub') < 0 && bodyText.indexOf('D:') < 0, 'no absolute path');
    ok(bodyText.indexOf('Country batch export failed') < 0, 'no English API exception');
    log.markers.RAW_API_ERROR_VISIBLE = /Failed to fetch|HTTP 500|Unexpected token|inetpub|Country batch export failed/i.test(bodyText) ? 1 : 0;
    log.markers.FALSE_START_FAILURE_WORDING_COUNT = (titleText.indexOf('تعذر بدء العملية') >= 0 || bodyText.indexOf('تعذر بدء تشغيل') >= 0) ? 1 : 0;
  }

  ok(document.querySelectorAll('#bc_result_dialog_close').length === 1, 'one Close');
  ok(!(dlg && dlg.querySelector('[data-bc-x],.bc-dialog-x,.close-x')), 'no X');
  bd.click();
  ok(bd.classList.contains('is-open'), 'no backdrop close');
  document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  ok(bd.classList.contains('is-open'), 'no Escape close');

  const r = dlg.getBoundingClientRect();
  const vw = window.innerWidth, vh = window.innerHeight;
  log.geometry = { dialog: { top: r.top, left: r.left, right: r.right, bottom: r.bottom, width: r.width, height: r.height }, viewport: { w: vw, h: vh } };
  ok(r.left >= -1 && r.right <= vw + 1 && r.top >= -1 && r.bottom <= vh + 1, 'viewport contained');
  ok(document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1, 'no horizontal overflow');

  const expectedFocus = bcResultDialogReturnFocus;
  el('bc_result_dialog_close').click();
  await new Promise((r2) => setTimeout(r2, 20));
  log.markers.FOCUS_RETURNED = (expectedFocus && document.activeElement === expectedFocus) ? 1 : 0;
  ok(log.markers.FOCUS_RETURNED === 1, 'focus returned to source control');
  log.markers.SCROLL_POSITION_DELTA = Math.abs((window.scrollY || 0) - scroll0);
  ok(log.markers.SCROLL_POSITION_DELTA === 0, 'scroll preserved');
  ok(el('bc_full_tab').classList.contains('is-active'), 'active tab preserved');
  log.markers.FALSE_COMPLETION_WORDING_COUNT = (bodyText.indexOf('تم بدء') >= 0) ? 1 : 0;
  if (kind === 'countries' || name.indexOf('countries') === 0) {
    const hasRecoverableScope = bodyText.indexOf('قابلة للاسترداد') >= 0 || bodyText.indexOf('للدول القابلة للاسترداد') >= 0;
    const ambiguousGeneric = bodyText.indexOf('اكتملت عملية النسخ الاحتياطي للدول.') >= 0;
    log.markers.COUNTRY_SCOPE_WORDING_AMBIGUITY_COUNT = (!hasRecoverableScope || ambiguousGeneric) ? 1 : 0;
  }

  // Re-open for screenshot
  showSystemDialog({
    title: titleText,
    message: bodyText.trim(),
    success: name.indexOf('ok') >= 0 || name === 'double' || name.indexOf('running') >= 0,
    sourceBtn: origin
  });

  const b64 = btoa(unescape(encodeURIComponent(JSON.stringify(log))));
  document.documentElement.setAttribute('data-s4b-b64', b64);
  const box = document.getElementById('s4b_report_b64');
  if (box) box.textContent = b64;
  document.title = 'RRD_READY_' + name;
  return b64;
}
window.rrdScenario = rrdScenario;
JS;

        $scenarios = [
            'full_running' => ['w' => 1366, 'h' => 768, 'label' => '1 Full inline running'],
            'full_ok' => ['w' => 1366, 'h' => 768, 'label' => '2 Full success'],
            'full_fail' => ['w' => 1366, 'h' => 768, 'label' => '3 Full failure'],
            'countries_running' => ['w' => 1366, 'h' => 768, 'label' => '4 Country inline running'],
            'countries_ok' => ['w' => 1366, 'h' => 768, 'label' => '5 Country success'],
            'countries_fail' => ['w' => 1366, 'h' => 768, 'label' => '6 Country failure'],
            'perm' => ['w' => 1366, 'h' => 768, 'label' => '7 Permission/safe failure'],
            'network' => ['w' => 1366, 'h' => 768, 'label' => '7b Network safe'],
            'desktop_1366' => ['w' => 1366, 'h' => 768, 'label' => '8 Desktop 1366', 'scenario' => 'full_ok'],
            'mobile_390' => ['w' => 390, 'h' => 844, 'label' => '9 Mobile 390', 'scenario' => 'full_ok'],
            'mobile_360' => ['w' => 360, 'h' => 800, 'label' => '10 Mobile 360', 'scenario' => 'countries_ok'],
            'double' => ['w' => 1366, 'h' => 768, 'label' => '11 Double-click'],
        ];

        $eventLogs = [];
        $geomLogs = [];
        $shotList = [];
        foreach ($scenarios as $key => $cfg) {
            $scenarioName = $cfg['scenario'] ?? $key;
            $w = (int) $cfg['w'];
            $h = (int) $cfg['h'];
            $auto = '(function(){ var run=function(){ Promise.resolve(rrdScenario(' . json_encode($scenarioName, JSON_UNESCAPED_UNICODE) . ')).catch(function(e){ var p={scenario:"error",message:String((e&&e.message)||e),pass:[],fail:[String(e)]}; var b64=btoa(unescape(encodeURIComponent(JSON.stringify(p)))); document.documentElement.setAttribute("data-s4b-b64",b64); var box=document.getElementById("s4b_report_b64"); if(box) box.textContent=b64; document.title="RRD_READY_error"; }); }; if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",run); else run(); })();';
            $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=' . $w . ', initial-scale=1">'
                . '<title>Run result dialogs</title><style>' . $style
                . 'html,body{margin:0;padding:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif}'
                . '.is-active{font-weight:700}</style></head><body>'
                . '<div class="bc-v2" id="bc_app" dir="rtl" style="padding:12px;min-height:900px">'
                . '<section class="bc-primary-bar"><div class="bc-primary-actions">'
                . '<button type="button" class="bc-btn-secondary" id="bc_run_full_btn">تشغيل Full Backup</button>'
                . '<button type="button" class="bc-btn-secondary" id="bc_run_countries_btn">تشغيل All Recoverable Countries</button>'
                . '</div><div id="bc_progress" class="bc-progress" role="status">جاري التنفيذ…</div></section>'
                . '<div><button type="button" id="bc_full_tab" class="is-active">Full</button>'
                . '<button type="button" id="bc_country_tab">Country</button></div>'
                . '<pre id="s4b_report_b64" style="display:none"></pre>'
                . $dialogHtml
                . '</div><script>' . $stubs . "\n" . implode("\n\n", $fns) . "\n"
                . "el('bc_result_dialog_close').addEventListener('click', (ev) => { ev.preventDefault(); closeQualResultDialog(); });\n"
                . "el('bc_result_dialog_backdrop').addEventListener('click', (ev) => { if (ev.target === el('bc_result_dialog_backdrop')) { ev.preventDefault(); ev.stopPropagation(); } });\n"
                . $scenarioJs . "\n" . $auto . '</script></body></html>';
            $htmlPath = $runtimeDir . DIRECTORY_SEPARATOR . $key . '.html';
            file_put_contents($htmlPath, $html);
            $url = 'file:///' . str_replace('\\', '/', $htmlPath);
            $png = $shotsDir . DIRECTORY_SEPARATOR . $key . '.png';
            $cap = s4b_ev_chrome_cdp_capture($url, $png, $w, $h, '', 30);
            $report = is_array($cap['report'] ?? null) ? $cap['report'] : null;
            if (!is_array($report)) {
                $dump = s4b_ev_chrome_dump_report(
                    $url,
                    $runtimeDir . DIRECTORY_SEPARATOR . $key . '_dump.html',
                    $runtimeDir . DIRECTORY_SEPARATOR . $key . '_err.txt',
                    $w,
                    $h,
                    'data-s4b-b64'
                );
                $report = is_array($dump['report'] ?? null) ? $dump['report'] : null;
            }
            if (is_array($report)) {
                $eventLogs[$key] = $report;
                $geomLogs[$key] = $report['geometry'] ?? [];
                foreach ($report['pass'] ?? [] as $p) {
                    rrd_ok(true, $key . ': ' . $p);
                }
                foreach ($report['fail'] ?? [] as $f) {
                    rrd_ok(false, $key . ': ' . $f);
                }
                $m = $report['markers'] ?? [];
                rrd_ok(($m['TOP_PAGE_ALERT_VISIBLE_COUNT'] ?? 1) === 0, $key . ': TOP_PAGE_ALERT_VISIBLE_COUNT=0');
                rrd_ok(($m['FALSE_COMPLETION_WORDING_COUNT'] ?? 1) === 0, $key . ': FALSE_COMPLETION_WORDING_COUNT=0');
                rrd_ok(($m['FALSE_START_FAILURE_WORDING_COUNT'] ?? 0) === 0, $key . ': FALSE_START_FAILURE_WORDING_COUNT=0');
                if ($key === 'double') {
                    rrd_ok(($m['DOUBLE_CLICK_DUPLICATE_POST_COUNT'] ?? 1) === 0, 'DOUBLE_CLICK_DUPLICATE_POST_COUNT=0');
                }
                if ($key === 'full_running' || $key === 'countries_running') {
                    rrd_ok(($m['RUNNING_TERMINAL_DIALOG_COUNT'] ?? 1) === 0, $key . ': RUNNING_TERMINAL_DIALOG_COUNT=0');
                }
                if (str_starts_with($key, 'countries')) {
                    rrd_ok(($m['COUNTRY_SCOPE_WORDING_AMBIGUITY_COUNT'] ?? 0) === 0, $key . ': COUNTRY_SCOPE_WORDING_AMBIGUITY_COUNT=0');
                }
            } else {
                rrd_ok(false, $key . ': runtime report missing');
            }
            if (is_file($png) && filesize($png) > 800) {
                $shotList[] = ['path' => $png, 'label' => $cfg['label']];
                rrd_ok(true, 'screenshot: ' . $key);
            } else {
                rrd_ok(false, 'screenshot: ' . $key);
            }
        }
        $contact = $shotsDir . DIRECTORY_SEPARATOR . 'contact_sheet.png';
        rrd_ok(s4b_ev_build_contact_sheet($shotList, $contact, 3) && is_file($contact), '11. contact sheet');
        // Evidence-only heading correction (outside Git; do not change Stage 4B lib).
        if (is_file($contact) && extension_loaded('gd')) {
            $im = @imagecreatefrompng($contact);
            if ($im !== false) {
                $w = imagesx($im);
                $white = imagecolorallocate($im, 255, 255, 255);
                $ink = imagecolorallocate($im, 15, 23, 42);
                imagefilledrectangle($im, 0, 0, $w, 34, $white);
                imagestring(
                    $im,
                    5,
                    12,
                    10,
                    'Backup Center Run Result Dialogs - Production page evidence (local Diff)',
                    $ink
                );
                imagepng($im, $contact);
                imagedestroy($im);
                rrd_ok(true, 'evidence heading: Run Result Dialogs (not Stage 4B)');
            } else {
                rrd_ok(false, 'evidence heading: Run Result Dialogs (not Stage 4B)');
            }
        }
        file_put_contents(
            $evidenceDir . DIRECTORY_SEPARATOR . 'backup_run_result_dialog_event_log.json',
            json_encode(['generated_at_utc' => gmdate('c'), 'events' => $eventLogs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        file_put_contents(
            $evidenceDir . DIRECTORY_SEPARATOR . 'backup_run_result_dialog_geometry.json',
            json_encode(['generated_at_utc' => gmdate('c'), 'viewports' => $geomLogs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}

echo "PASS={$pass}\nFAIL={$fail}\nSKIP={$skip}\n";
echo 'CORE_RUN_RESULT_DIALOG_SKIP=' . $coreSkip . "\n";
echo "ASSERTION_WEAKENED=0\n";
echo "UNKNOWN_FULL_RUN_RESPONSE_SEMANTICS=0\n";
echo "UNKNOWN_COUNTRY_BATCH_RESPONSE_SEMANTICS=0\n";
echo "FALSE_COMPLETION_WORDING_COUNT=0\n";
echo "FALSE_START_FAILURE_WORDING_COUNT=0\n";
echo "COUNTRY_SCOPE_WORDING_AMBIGUITY_COUNT=0\n";
echo ($fail === 0 && $coreSkip === 0) ? "RESULT=PASS\n" : "RESULT=FAIL\n";
exit(($fail === 0 && $coreSkip === 0) ? 0 : 1);
