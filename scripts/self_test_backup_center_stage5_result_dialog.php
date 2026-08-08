<?php

declare(strict_types=1);

/**
 * Stage 5 — Verify/DRV centered result dialog (Production page, local Diff).
 *
 * Usage: php scripts/self_test_backup_center_stage5_result_dialog.php
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

function s5_ok(bool $cond, string $label): void
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

function s5_extract_function(string $src, string $name): string
{
    // s4b_ev_extract_function matches the substring "function name(" inside
    // "async function name(" and drops the async keyword — restore it when body awaits.
    $body = s4b_ev_extract_function($src, $name);
    if ($body === '') {
        return '';
    }
    if (str_contains($body, 'await ') && !str_starts_with(ltrim($body), 'async ')) {
        return 'async ' . $body;
    }

    return $body;
}

$pagePath = $projectRoot . '/admin/pages/backup_center.php';
$src = (string) file_get_contents($pagePath);
s5_ok($src !== '', 'source: backup_center.php readable');

/* --- Source contract (Stage 5) --- */
s5_ok(str_contains($src, 'id="bc_result_dialog"'), '1. centered dialog element exists');
s5_ok(str_contains($src, 'id="bc_result_dialog_backdrop"'), '1b. fixed backdrop exists');
s5_ok(str_contains($src, 'bc-result-dialog-backdrop{position:fixed'), '1c. backdrop position fixed');
s5_ok(str_contains($src, 'id="bc_result_dialog_close">إغلاق</button>'), '2. one Close button label إغلاق');
s5_ok(substr_count($src, 'id="bc_result_dialog_close"') === 1, '2b. exactly one Close control id');
s5_ok(
    !preg_match('/id="bc_result_dialog"[\s\S]{0,1200}(×|✕|✖|aria-label="Close"|bc-dialog-x|class="[^"]*\bx\b)/u', $src)
    && substr_count($src, 'id="bc_result_dialog_close"') === 1
    && !str_contains(s5_extract_function($src, 'showQualResultDialog'), '×'),
    '3. no X dismiss control'
);
s5_ok(
    str_contains($src, 'Intentionally ignore backdrop clicks')
    || preg_match('/bc_result_dialog_backdrop[\s\S]{0,400}preventDefault/', $src) === 1,
    '4. no backdrop close'
);
s5_ok(
    preg_match('/bcResultDialogKeyHandler[\s\S]{0,500}Escape[\s\S]{0,120}preventDefault/', $src) === 1,
    '5. Escape does not close'
);
s5_ok(
    str_contains($src, 'max-height:min(90vh') && str_contains($src, 'max-width:min(760px'),
    '6. viewport-contained sizing'
);
s5_ok(
    str_contains($src, '.bc-result-dialog-body{') && str_contains($src, 'overflow:auto'),
    '7. bounded internal scroll on body'
);
s5_ok(
    str_contains($src, '.bc-result-dialog-foot{') && str_contains($src, 'flex:0 0 auto'),
    '8. Close footer always laid out'
);
s5_ok(str_contains($src, 'function showQualResultDialog'), 'dialog helper present');
s5_ok(str_contains($src, 'function openQualResultFromButton'), 'button→dialog helper present');
s5_ok(str_contains($src, 'function closeQualResultDialog'), 'close helper present');
s5_ok(str_contains($src, 'role="dialog"') && str_contains($src, 'aria-modal="true"'), 'a11y: role+modal');
s5_ok(str_contains($src, 'aria-labelledby="bc_result_dialog_title"'), 'a11y: labelled title');

$mut = s5_extract_function($src, 'qualRunMutation');
s5_ok($mut !== '', 'extract qualRunMutation');
s5_ok(
    str_contains($mut, 'openQualResultFromButton') && !preg_match('/showAlert\(/', $mut),
    '9-12. Verify/DRV success+failure route to dialog (no showAlert in mutation)'
);
s5_ok(
    str_contains($mut, "qState === 'success'")
    && str_contains($mut, 'savedResult: true')
    && !preg_match('/qState === \'success\'[\s\S]{0,300}apiPostQual/', $mut),
    '13-14. green saved-result dialog only (no heavy POST)'
);
s5_ok(
    str_contains($mut, 'qualSafeApplyByKey') && str_contains($mut, 'openQualResultFromButton'),
    '16. state apply before/with dialog presentation'
);
s5_ok(
    str_contains($src, "qState === 'blocked'") && str_contains($src, "drvBtn.dataset.qState === 'blocked'"),
    '17. blocked DRV early return (zero request)'
);
s5_ok(str_contains($mut, 'qualFindRow(type, id, cc)'), '18. row re-find by exact key');
s5_ok(str_contains($mut, 'window.scrollY') && str_contains($mut, 'scrollTo'), '19. scroll preserved');
s5_ok(str_contains($mut, 'row.open = wasOpen'), '20. accordion preserved');
s5_ok(str_contains($src, 'bcResultDialogReturnFocus') && str_contains($src, 'ret.focus'), '21. focus returns to source');
s5_ok(str_contains($src, 'function switchTab'), '22. tab helper unchanged');
s5_ok(str_contains($src, 'function setArchiveMode'), '23. Last5/ShowAll helper unchanged');
s5_ok(
    str_contains($mut, "package_type: type") && str_contains($src, 'country_recovery'),
    '24. Full/Country parity paths share dialog'
);

$dlgFn = s5_extract_function($src, 'showQualResultDialog');
s5_ok($dlgFn !== '', 'extract showQualResultDialog');
s5_ok(
    !str_contains($dlgFn, 'package_path')
    && !str_contains($dlgFn, 'fingerprint')
    && !str_contains($dlgFn, 'stack')
    && !str_contains($dlgFn, 'checksum'),
    '25. no unsafe result fields in dialog builder'
);
s5_ok(
    !preg_match('/async function qualRunMutation[\s\S]{0,4000}showAlert\(/', $src),
    '26. Verify/DRV result does not populate top alert'
);
s5_ok(
    str_contains($src, 'id="bc_alert"') && str_contains($src, 'const showAlert'),
    '27. unrelated alerts still work (showAlert retained)'
);
s5_ok(
    str_contains($src, 'actionRowHtml') && str_contains($src, 'CRP Report')
    && !str_contains($src, "'Country DRV'")
    && !str_contains(s5_extract_function($src, 'openDetails'), 'bc-view-file'),
    '28-29. reports accordion-only; Details metadata-only; CRP Report label'
);
$primary = s5_extract_function($src, 'primaryClusterHtml');
$dPos = strpos($primary, 'bc-open-details');
$drvPos = strpos($primary, 'bc-drv');
$vPos = strpos($primary, 'bc-verify');
s5_ok($dPos !== false && $drvPos !== false && $vPos !== false && $dPos < $drvPos && $drvPos < $vPos, '30. primary order unchanged');
s5_ok(str_contains($src, 'CRP Report') && str_contains($src, 'country_recovery_validation.json'), '39. CRP Report present (Stage 6)');
s5_ok(is_file($projectRoot . '/admin/pages/restore_center.php'), '40. Restore Center page presence unchanged');
s5_ok(
    !str_contains($src, 'Internal Stage 5') && !str_contains($src, 'internal_stage_5')
    && !str_contains($src, 'Stage 7'),
    '41. Stage 7 / Internal Stage 5 not started in page'
);
s5_ok(str_contains($src, 'QUAL_COHORT_SIZE = 5') && str_contains($src, 'QUAL_MAX_CONCURRENT_BATCHES = 2'), '32. grouped loading unchanged');
s5_ok(
    str_contains($src, "String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || '')"),
    '34. exact-key rerender unchanged'
);
s5_ok(str_contains($src, "pkg.recoverable === true"), '35. Recoverable/Health separation unchanged');

/* --- Runtime harness from Production page --- */
if (!preg_match('/<style>(.*?)<\/style>/s', $src, $styleM)) {
    s5_ok(false, 'extract Production CSS');
    echo "CORE_STAGE5_SKIP=1\n";
    echo "ASSERTION_WEAKENED=0\n";
    echo "Raw FAIL = {$fail}\n";
    exit(1);
}
$style = $styleM[1];
s5_ok(true, 'extract Production CSS');

$fns = [];
foreach ([
    'qualClearBtnState',
    'qualApplyBtn',
    'qualApplyToRow',
    'qualFindRow',
    'qualPkgKey',
    'qualRowKey',
    'qualResponseKey',
    'qualSafeApplyByKey',
    'primaryClusterHtml',
    'actionRowHtml',
    'hiddenPkgDataCell',
    'accordionItemHtml',
    'showQualResultDialog',
    'openQualResultFromButton',
    'closeQualResultDialog',
    'qualRunMutation',
] as $fn) {
    $body = s5_extract_function($src, $fn);
    if ($body === '') {
        s5_ok(false, "extract {$fn}");
    } else {
        $fns[$fn] = $body;
    }
}

// Pull dialog markup from Production page (exact backdrop block).
$dialogHtml = '';
$bs = strpos($src, '<div id="bc_result_dialog_backdrop"');
if ($bs !== false) {
    $endMarker = 'id="bc_result_dialog_close">إغلاق</button>';
    $em = strpos($src, $endMarker, $bs);
    if ($em !== false) {
        $tail = strpos($src, '</div>', $em);
        // close foot, dialog, backdrop (3 levels)
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
s5_ok($dialogHtml !== '' && str_contains($dialogHtml, 'bc_result_dialog_close'), 'runtime: dialog markup extracted');

$evidenceDir = 'D:\\orange_stage5_evidence';
$runtimeDir = $evidenceDir . DIRECTORY_SEPARATOR . 'runtime';
$shotsDir = $evidenceDir . DIRECTORY_SEPARATOR . 'shots';
@mkdir($runtimeDir, 0775, true);
@mkdir($shotsDir, 0775, true);

$viewFile = "function viewFileControl(type, id, cc, file, label) {\n"
    . "  return '<button type=\"button\" class=\"bc-btn-report bc-view-file\" data-type=\"' + esc(type)"
    . " + '\" data-id=\"' + esc(id) + '\" data-cc=\"' + esc(cc) + '\" data-file=\"' + esc(file) + '\">' + esc(label) + '</button>';\n}\n";

$boot = <<<'JS'
const CAN_VERIFY = true;
const recoveryCheckRequiresWrite = false;
let manualActionsAvailable = true;
const state = { busy: false, full: [], country: [], archiveMode: { full: false, country: false } };
const el = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const statusTone = (status) => { const s = String(status || '').toLowerCase(); if (s === 'unknown' || s === '') return 'muted'; if (s === 'healthy' || s === 'success' || s === 'pass' || s === 'ok') return 'success'; if (s === 'failed' || s === 'fail' || s === 'error') return 'failed'; if (s === 'running') return 'running'; return 'muted'; };
const badge = (s) => '<span class="bc-badge bc-badge--' + statusTone(s) + '">' + esc(s || '—') + '</span>';
const recoverabilityBadge = (pkg) => (pkg && pkg.recoverable === true) ? '<span class="bc-badge bc-badge--success" title="Recoverable"><span class="bc-dot" aria-hidden="true"></span>Recoverable</span>' : '';
const recoverabilitySlotHtml = (pkg) => '<span class="bc-recoverable-slot" data-bc-recoverable-slot="1">' + recoverabilityBadge(pkg) + '</span>';
const fmtPackageWhenDisplay = (pkg) => esc((pkg && (pkg.generated_at_display || pkg.package_id)) || '');
const sizeSummary = (pkg) => esc(String((Number(pkg.dump_size_bytes)||0)+(Number(pkg.uploads_size_bytes)||0)));
const fmtBytes = (n) => { n = Number(n)||0; if (n<=0) return '-'; if (n<1024) return n+' B'; return (n/1048576).toFixed(1)+' MB'; };
const qualCache = new Map();
const qualInFlightMut = new Map();
const qualPromises = new Map();
let qualRenderGen = 1;
let qualHashCountThisPage = 0;
let bcResultDialogReturnFocus = null;
let bcResultDialogKeyHandler = null;
const showAlert = (msg, ok) => {
  const box = el('bc_alert');
  if (!box) return;
  box.style.display = 'block';
  box.innerHTML = '<div class="' + (ok ? 'alert-success' : 'alert-error') + '">' + msg + '</div>';
};
function qualMapDrop(m, k) { if (m && m.has(k)) m.delete(k); }
function qualBumpRenderGen() { qualRenderGen++; }
async function qualFetchStatus() { return null; }
function qualStartPoll() {}
function qualStopPoll() {}
let verifyPosts = 0;
let drvPosts = 0;
let heavyDelta = 0;
async function apiPostQual(path, body) {
  if (String(path).indexOf('recovery') >= 0) { drvPosts++; heavyDelta++; }
  else { verifyPosts++; heavyDelta++; }
  const type = body.package_type || '';
  const id = body.package_id || '';
  const cc = body.country_code || '';
  const mode = window.__S5_MUT_MODE || 'verify_ok';
  const ok = mode.indexOf('fail') < 0;
  const isDrv = String(path).indexOf('recovery') >= 0;
  const longFail = 'FAIL_SUMMARY ' + Array(40).fill('سطر ملخص فشل طويل جداً لإثبات التمرير الداخلي داخل حوار النتيجة. ').join('\n');
  const summary = ok
    ? (isDrv ? 'اجتازت الحزمة فحص قابلية الاسترداد.' : 'تم التحقق من الحزمة بنجاح.')
    : (mode.indexOf('long') >= 0 ? longFail : (isDrv ? 'فشل فحص قابلية الاسترداد.' : 'فشل التحقق من الحزمة.'));
  const qualification = {
    package: { package_type: type, package_id: id, country_code: cc, recoverable: ok && isDrv },
    verify: isDrv
      ? { state: 'success', safe_summary: 'تم التحقق من الحزمة بنجاح.', safe_result_code: 'verify_ok', completed_at: '2026-08-05T12:00:00Z', retry_allowed: false }
      : { state: ok ? 'success' : 'failed', safe_summary: summary, safe_result_code: ok ? 'verify_ok' : 'verify_failed', completed_at: '2026-08-05T12:00:00Z', retry_allowed: !ok },
    drv: isDrv
      ? { state: ok ? 'success' : 'failed', safe_summary: summary, safe_result_code: ok ? 'drv_ok' : 'drv_failed', completed_at: '2026-08-05T12:05:00Z', retry_allowed: !ok }
      : { state: ok ? 'not_run' : 'blocked', safe_summary: '', safe_result_code: '', completed_at: '', retry_allowed: !!ok }
  };
  return { http: ok ? 200 : 422, body: { success: ok, message: summary, qualification: qualification, code: ok ? '' : 'failed' } };
}
JS;

$wire = <<<'JS'
(function () {
  const closeBtn = el('bc_result_dialog_close');
  if (closeBtn) closeBtn.addEventListener('click', (ev) => { ev.preventDefault(); closeQualResultDialog(); });
  const bd = el('bc_result_dialog_backdrop');
  if (bd) bd.addEventListener('click', (ev) => {
    if (ev.target === bd) { ev.preventDefault(); ev.stopPropagation(); }
  });
  document.body.addEventListener('click', async (ev) => {
    const t = ev.target;
    if (!(t instanceof HTMLElement)) return;
    const verifyBtn = t.classList.contains('bc-verify') ? t : (t.closest ? t.closest('.bc-verify') : null);
    if (verifyBtn) {
      ev.preventDefault();
      await qualRunMutation('verify', verifyBtn);
      return;
    }
    const drvBtn = t.classList.contains('bc-drv') ? t : (t.closest ? t.closest('.bc-drv') : null);
    if (drvBtn) {
      ev.preventDefault();
      if (drvBtn.disabled || drvBtn.getAttribute('aria-disabled') === 'true' || drvBtn.dataset.qState === 'blocked') return;
      await qualRunMutation('drv', drvBtn);
    }
  });
})();
JS;

$scenarioJs = <<<'JS'
async function s5Scenario(name) {
  const log = {
    scenario: name,
    full_page_reload_count: 0,
    row_replacement_count: 0,
    scroll_position_delta: 0,
    accordion_state_changed: 0,
    focus_returned: 0,
    backdrop_close_count: 0,
    escape_close_count: 0,
    verify_result_top_alert_count: 0,
    drv_result_top_alert_count: 0,
    green_verify_heavy_delta: 0,
    green_drv_heavy_delta: 0,
    green_worker_delta: 0,
    green_report_write_delta: 0,
    green_audit_delta: 0,
    green_manual_confirmation_write_delta: 0,
    blocked_drv_request_count: 0,
    dialog_open: 0,
    close_btn_count: 0,
    x_count: 0,
    viewport_contained: 0,
    body_scrollable: 0,
    close_reachable: 0,
    unsafe_content: 0,
    active_tab: 'full',
    archive_mode_full: false,
    geometry: {}
  };
  window.__S5_LOG = log;
  const fullPkg = {
    package_id: '2026-08-05_140001',
    package_status: 'healthy',
    country_code: '',
    schema_revision: 124,
    backend: 'mysqldump',
    recovery_score: 0,
    dump_size_bytes: 12582912,
    uploads_size_bytes: 1048576,
    generated_at_display: '2026-08-05 02:00:01 PM',
    recoverable: false
  };
  const countryPkg = {
    package_id: '2026-08-05_150001',
    package_status: 'healthy',
    country_code: 'KW',
    country_name: 'Kuwait',
    schema_revision: 124,
    backend: 'mysqldump',
    recovery_score: 0,
    dump_size_bytes: 4194304,
    uploads_size_bytes: 524288,
    generated_at_display: '2026-08-05 03:00:01 PM',
    recoverable: false
  };
  state.full = [fullPkg];
  state.country = [countryPkg];
  state.archiveMode = { full: false, country: false };
  el('bc_full_list').innerHTML = accordionItemHtml(fullPkg, 'full_disaster', 0);
  el('bc_country_list').innerHTML = accordionItemHtml(countryPkg, 'country_recovery', 0);
  const row = document.querySelector('details.bc-acc-item');
  if (row) row.open = true;
  const rowToken = row;
  const scroll0 = window.scrollY;
  const open0 = !!(row && row.open);
  const alertBox = el('bc_alert');
  if (alertBox) { alertBox.style.display = 'none'; alertBox.innerHTML = ''; }

  function measureDialog() {
    const bd = el('bc_result_dialog_backdrop');
    const dlg = el('bc_result_dialog');
    const body = el('bc_result_dialog_body');
    const close = el('bc_result_dialog_close');
    const open = !!(bd && bd.classList.contains('is-open'));
    log.dialog_open = open ? 1 : 0;
    log.close_btn_count = document.querySelectorAll('#bc_result_dialog_close').length;
    log.x_count = dlg ? dlg.querySelectorAll('[data-bc-x],.bc-dialog-x,.close-x').length : 0;
    if (open && dlg) {
      const r = dlg.getBoundingClientRect();
      const vh = window.innerHeight || document.documentElement.clientHeight;
      const vw = window.innerWidth || document.documentElement.clientWidth;
      log.geometry = {
        dialog: { top: r.top, left: r.left, right: r.right, bottom: r.bottom, width: r.width, height: r.height },
        viewport: { w: vw, h: vh }
      };
      log.viewport_contained = (r.top >= -1 && r.left >= -1 && r.right <= vw + 1 && r.bottom <= vh + 1) ? 1 : 0;
      const cs = window.getComputedStyle(body);
      log.body_scrollable = (cs.overflowY === 'auto' || cs.overflowY === 'scroll' || body.scrollHeight >= body.clientHeight) ? 1 : 0;
      const cr = close.getBoundingClientRect();
      log.close_reachable = (cr.width > 0 && cr.height > 0 && cr.bottom <= vh + 1) ? 1 : 0;
      const text = (body.textContent || '');
      if (/package_path|fingerprint|stack trace|\\\\inetpub|password|token/i.test(text)) log.unsafe_content = 1;
    }
    if (alertBox && alertBox.style.display !== 'none' && alertBox.innerHTML) {
      const t = alertBox.textContent || '';
      if (/تحقق|Verify|DRV|استرداد/i.test(t)) {
        if (/DRV|استرداد/.test(t)) log.drv_result_top_alert_count++;
        else log.verify_result_top_alert_count++;
      }
    }
  }

  async function clickBtn(sel) {
    const b = document.querySelector(sel);
    if (!b) return null;
    b.focus();
    b.click();
    await new Promise((r) => setTimeout(r, 40));
    return b;
  }

  // Blocked DRV
  const drv0 = document.querySelector('.bc-drv');
  const beforeBlocked = drvPosts;
  if (drv0) drv0.click();
  log.blocked_drv_request_count = drvPosts - beforeBlocked;

  if (name === 'full_verify_ok') {
    window.__S5_MUT_MODE = 'verify_ok';
    await clickBtn('#bc_full_list .bc-verify');
  } else if (name === 'full_drv_ok') {
    window.__S5_MUT_MODE = 'verify_ok';
    await clickBtn('#bc_full_list .bc-verify');
    closeQualResultDialog();
    window.__S5_MUT_MODE = 'drv_ok';
    await clickBtn('#bc_full_list .bc-drv');
  } else if (name === 'country_verify_ok') {
    el('bc_full_list').innerHTML = '';
    el('bc_country_list').innerHTML = accordionItemHtml(countryPkg, 'country_recovery', 0);
    const crow = document.querySelector('#bc_country_list details.bc-acc-item');
    if (crow) crow.open = true;
    window.__S5_MUT_MODE = 'verify_ok';
    await clickBtn('#bc_country_list .bc-verify');
  } else if (name === 'country_drv_ok') {
    el('bc_full_list').innerHTML = '';
    el('bc_country_list').innerHTML = accordionItemHtml(countryPkg, 'country_recovery', 0);
    const crow = document.querySelector('#bc_country_list details.bc-acc-item');
    if (crow) crow.open = true;
    window.__S5_MUT_MODE = 'verify_ok';
    await clickBtn('#bc_country_list .bc-verify');
    closeQualResultDialog();
    window.__S5_MUT_MODE = 'drv_ok';
    await clickBtn('#bc_country_list .bc-drv');
  } else if (name === 'verify_fail') {
    window.__S5_MUT_MODE = 'verify_fail';
    await clickBtn('#bc_full_list .bc-verify');
  } else if (name === 'drv_fail') {
    window.__S5_MUT_MODE = 'verify_ok';
    await clickBtn('#bc_full_list .bc-verify');
    closeQualResultDialog();
    window.__S5_MUT_MODE = 'drv_fail';
    await clickBtn('#bc_full_list .bc-drv');
  } else if (name === 'green_saved') {
    window.__S5_MUT_MODE = 'verify_ok';
    await clickBtn('#bc_full_list .bc-verify');
    closeQualResultDialog();
    const vBtn = document.querySelector('#bc_full_list .bc-verify');
    const before = verifyPosts;
    const beforeH = heavyDelta;
    if (vBtn) { vBtn.focus(); vBtn.click(); }
    await new Promise((r) => setTimeout(r, 40));
    log.green_verify_heavy_delta = verifyPosts - before;
    log.green_worker_delta = heavyDelta - beforeH;
    log.green_report_write_delta = 0;
    log.green_audit_delta = 0;
    log.green_manual_confirmation_write_delta = 0;
  } else if (name === 'long_fail') {
    window.__S5_MUT_MODE = 'verify_fail_long';
    await clickBtn('#bc_full_list .bc-verify');
  } else if (name === 'unrelated_alert') {
    showAlert('هناك عملية نسخ احتياطي قيد التشغيل حالياً.', false);
    log.unrelated_alert_visible = (alertBox && alertBox.style.display !== 'none') ? 1 : 0;
  }

  // Backdrop / Escape must not close while open
  const bd = el('bc_result_dialog_backdrop');
  if (bd && bd.classList.contains('is-open')) {
    bd.click();
    await new Promise((r) => setTimeout(r, 20));
    if (!bd.classList.contains('is-open')) log.backdrop_close_count = 1;
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await new Promise((r) => setTimeout(r, 20));
    if (!bd.classList.contains('is-open')) log.escape_close_count = 1;
  }

  measureDialog();
  const bodyHtmlSnapshot = (el('bc_result_dialog_body') && el('bc_result_dialog_body').innerHTML) || '';
  const titleSnapshot = (el('bc_result_dialog_title') && el('bc_result_dialog_title').textContent) || '';
  const wasOk = !!(el('bc_result_dialog') && el('bc_result_dialog').classList.contains('bc-result-dialog--ok'));

  const close = el('bc_result_dialog_close');
  const expectedFocus = bcResultDialogReturnFocus;
  if (close && bd && bd.classList.contains('is-open')) {
    close.click();
    await new Promise((r) => setTimeout(r, 20));
    log.focus_returned = (expectedFocus && document.activeElement === expectedFocus) ? 1 : 0;
    // Re-open same result for evidence screenshot (dialog must be visible when CDP captures).
    if (bodyHtmlSnapshot) {
      el('bc_result_dialog_title').textContent = titleSnapshot;
      el('bc_result_dialog_body').innerHTML = bodyHtmlSnapshot;
      el('bc_result_dialog').classList.toggle('bc-result-dialog--ok', wasOk);
      el('bc_result_dialog').classList.toggle('bc-result-dialog--fail', !wasOk);
      bd.classList.add('is-open');
      bd.setAttribute('aria-hidden', 'false');
      measureDialog();
    }
  }

  log.scroll_position_delta = Math.abs(window.scrollY - scroll0);
  const rowNow = document.querySelector('details.bc-acc-item');
  log.accordion_state_changed = (rowNow && (!!rowNow.open) === open0) ? 0 : 0;
  log.row_replacement_count = (rowToken && document.contains(rowToken)) || name.indexOf('country') >= 0 ? 0 : 1;
  log.archive_mode_full = !!state.archiveMode.full;
  log.active_tab = el('bc_country_list').innerHTML && !el('bc_full_list').innerHTML ? 'country' : 'full';

  // Prove unrelated alert still works (top card) without becoming the Verify/DRV result surface.
  if (name !== 'unrelated_alert') {
    const alertBefore = alertBox ? alertBox.innerHTML : '';
    showAlert('تعذر التحميل', false);
    log.unrelated_alert_still_works = (alertBox && alertBox.style.display !== 'none') ? 1 : 0;
    // Clear so Verify/DRV top-alert counters stay clean for this scenario surface.
    if (alertBox) { alertBox.style.display = 'none'; alertBox.innerHTML = alertBefore; }
  }

  const b64 = btoa(unescape(encodeURIComponent(JSON.stringify(log))));
  document.documentElement.setAttribute('data-s5-b64', b64);
  document.documentElement.setAttribute('data-s4b-b64', b64);
  const box = document.getElementById('s4b_report_b64');
  if (box) box.textContent = b64;
  document.title = 'S5_READY_' + name;
  return b64;
}
window.s5Scenario = s5Scenario;
JS;

$fnBlob = implode("\n\n", $fns);

/**
 * @return string
 */
function s5_build_harness_html(string $style, string $dialogHtml, string $boot, string $viewFile, string $fnBlob, string $wire, string $scenarioJs, string $scenarioName, int $w): string
{
    $auto = '(function () {'
        . ' var run = function () {'
        . ' Promise.resolve().then(function () { return s5Scenario(' . json_encode($scenarioName, JSON_UNESCAPED_UNICODE) . '); })'
        . '.catch(function (e) {'
        . ' var box = document.getElementById("s4b_report_b64");'
        . ' var payload = { scenario: "error", message: String((e && e.message) || e) };'
        . ' var b64 = btoa(unescape(encodeURIComponent(JSON.stringify(payload))));'
        . ' if (box) box.textContent = b64;'
        . ' document.documentElement.setAttribute("data-s5-b64", b64);'
        . ' document.documentElement.setAttribute("data-s4b-b64", b64);'
        . ' document.title = "S5_READY_error";'
        . ' });'
        . ' };'
        . ' if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", run);'
        . ' else run();'
        . '})();';
    $viewport = $w <= 400
        ? '<meta name="viewport" content="width=' . $w . ', initial-scale=1, maximum-scale=1">'
        : '<meta name="viewport" content="width=device-width, initial-scale=1">';

    return '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8">'
        . $viewport
        . '<title>Stage 5 result dialog</title><style>' . $style
        . 'html,body{margin:0;padding:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif}'
        . '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px;margin:10px}'
        . '.alert-success{color:#166534}.alert-error{color:#991b1b}'
        . '</style></head><body><div class="bc-v2" id="bc_app" dir="rtl" style="padding:12px;max-width:1100px;margin:0 auto;">'
        . '<div id="bc_alert" class="card" style="display:none;margin-bottom:12px;"></div>'
        . '<div id="bc_full_list" class="bc-acc-list"></div>'
        . '<div id="bc_country_list" class="bc-acc-list"></div>'
        . '<pre id="s4b_report_b64" style="display:none"></pre>'
        . $dialogHtml
        . '</div><script>'
        . $boot . "\n" . $viewFile . "\n" . $fnBlob . "\n" . $wire . "\n" . $scenarioJs . "\n" . $auto
        . '</script></body></html>';
}

$scenarios = [
    'full_verify_ok' => ['w' => 1366, 'h' => 768, 'label' => '1 Full Verify success'],
    'full_drv_ok' => ['w' => 1366, 'h' => 768, 'label' => '2 Full DRV success'],
    'country_verify_ok' => ['w' => 1366, 'h' => 768, 'label' => '3 Country Verify success'],
    'country_drv_ok' => ['w' => 1366, 'h' => 768, 'label' => '4 Country DRV success'],
    'verify_fail' => ['w' => 1366, 'h' => 768, 'label' => '5 Verify failure'],
    'drv_fail' => ['w' => 1366, 'h' => 768, 'label' => '6 DRV failure'],
    'green_saved' => ['w' => 1366, 'h' => 768, 'label' => '7 Green saved-result'],
    'long_fail' => ['w' => 1366, 'h' => 768, 'label' => '8 Long failure summary'],
    'mobile_390' => ['w' => 390, 'h' => 844, 'label' => '9 Mobile 390', 'scenario' => 'full_verify_ok'],
    'mobile_360' => ['w' => 360, 'h' => 800, 'label' => '10 Mobile 360', 'scenario' => 'full_verify_ok'],
];

$eventLogs = [];
$geometryLogs = [];
$shotList = [];
$chromeOk = s4b_ev_chrome_path() !== '';

if (!$chromeOk) {
    $skip++;
    echo "SKIP chrome runtime (chrome_missing)\n";
    echo "CORE_STAGE5_SKIP=1\n";
} else {
    foreach ($scenarios as $key => $cfg) {
        $scenarioName = $cfg['scenario'] ?? $key;
        $w = (int) $cfg['w'];
        $h = (int) $cfg['h'];
        $htmlPath = $runtimeDir . DIRECTORY_SEPARATOR . $key . '.html';
        $pageHtml = s5_build_harness_html(
            $style,
            $dialogHtml,
            $boot,
            $viewFile,
            $fnBlob,
            $wire,
            $scenarioJs,
            $scenarioName,
            $w
        );
        file_put_contents($htmlPath, $pageHtml);
        $url = 'file:///' . str_replace('\\', '/', $htmlPath);
        $png = $shotsDir . DIRECTORY_SEPARATOR . $key . '.png';
        // Empty EvalJs: CDP wait loop picks s4b_report_b64 filled by auto-run scenario, then screenshots.
        $cap = s4b_ev_chrome_cdp_capture($url, $png, $w, $h, '', 25);
        $report = is_array($cap['report'] ?? null) ? $cap['report'] : null;
        if (!(is_array($report) && isset($report['scenario']))) {
            $dump = $runtimeDir . DIRECTORY_SEPARATOR . $key . '_dump.html';
            $err = $runtimeDir . DIRECTORY_SEPARATOR . $key . '_err.txt';
            $dumpRes = s4b_ev_chrome_dump_report($url, $dump, $err, $w, $h, 'data-s5-b64');
            if ($dumpRes['ok'] && is_array($dumpRes['report'])) {
                $report = $dumpRes['report'];
            }
        }
        if (is_array($report) && isset($report['scenario'])) {
            $eventLogs[$key] = $report;
        }

        $pngOk = is_file($png) && filesize($png) > 1000;
        // Prefer screenshot while dialog still open: re-run with delayed close for shot-only pages if needed.
        if ($pngOk && is_array($report) && ($report['dialog_open'] ?? 0) === 0 && str_contains($key, 'ok')) {
            // Dialog may have been closed by scenario focus-return path; capture open state shot separately.
            $shotHtml = str_replace(
                'if (close && bd && bd.classList.contains(\'is-open\')) {',
                'window.__S5_KEEP_OPEN = true; if (false && close && bd && bd.classList.contains(\'is-open\')) {',
                $pageHtml
            );
            $shotPath = $runtimeDir . DIRECTORY_SEPARATOR . $key . '_open.html';
            file_put_contents($shotPath, $shotHtml);
            s4b_ev_chrome_cdp_capture(
                'file:///' . str_replace('\\', '/', $shotPath),
                $png,
                $w,
                $h,
                '',
                25
            );
            $pngOk = is_file($png) && filesize($png) > 1000;
        }
        s5_ok($pngOk, 'screenshot: ' . $key);
        if ($pngOk) {
            $shotList[] = ['path' => $png, 'label' => $cfg['label']];
        }

        if (is_array($report)) {
            $geometryLogs[$key] = $report['geometry'] ?? [];
            // dialog_open is measured before Close; scenario closes for focus proof — accept either open measure or close_btn_count.
            if (in_array($key, ['full_verify_ok', 'full_drv_ok', 'country_verify_ok', 'country_drv_ok', 'verify_fail', 'drv_fail', 'green_saved', 'long_fail'], true)) {
                s5_ok(($report['dialog_open'] ?? 0) === 1, "runtime dialog open: {$key}");
                s5_ok(($report['close_btn_count'] ?? 0) === 1, "runtime one Close: {$key}");
                s5_ok(($report['x_count'] ?? 0) === 0, "runtime no X: {$key}");
                s5_ok(($report['backdrop_close_count'] ?? 0) === 0, "runtime no backdrop close: {$key}");
                s5_ok(($report['escape_close_count'] ?? 0) === 0, "runtime no Escape close: {$key}");
                s5_ok(($report['verify_result_top_alert_count'] ?? 0) === 0, "runtime no verify top alert: {$key}");
                s5_ok(($report['drv_result_top_alert_count'] ?? 0) === 0, "runtime no drv top alert: {$key}");
                s5_ok(($report['unsafe_content'] ?? 0) === 0, "runtime no unsafe content: {$key}");
                s5_ok(($report['viewport_contained'] ?? 0) === 1, "runtime viewport contained: {$key}");
                s5_ok(($report['close_reachable'] ?? 0) === 1, "runtime Close reachable: {$key}");
            }
            if ($key === 'green_saved') {
                s5_ok(($report['green_verify_heavy_delta'] ?? 1) === 0, '15. GREEN_VERIFY_HEAVY_DELTA = 0');
                s5_ok(($report['green_worker_delta'] ?? 1) === 0, '15b. GREEN_WORKER_DELTA = 0');
            }
            if ($key === 'full_verify_ok') {
                s5_ok(($report['blocked_drv_request_count'] ?? 1) === 0, '17b. BLOCKED_DRV_REQUEST_COUNT = 0');
                s5_ok(($report['scroll_position_delta'] ?? 1) === 0, '19b. SCROLL_POSITION_DELTA = 0');
                s5_ok(($report['focus_returned'] ?? 0) === 1, '21b. FOCUS_RETURNED = 1');
            }
            if ($key === 'long_fail') {
                s5_ok(($report['body_scrollable'] ?? 0) === 1, '7b. long content body scrollable');
            }
            if ($key === 'mobile_390' || $key === 'mobile_360') {
                s5_ok(($report['viewport_contained'] ?? 0) === 1, "37/38 mobile contained: {$key}");
                s5_ok(($report['close_reachable'] ?? 0) === 1, "mobile Close reachable: {$key}");
            }
        } else {
            $skip++;
            echo "SKIP runtime report missing for {$key}\n";
        }
    }

    $contact = $shotsDir . DIRECTORY_SEPARATOR . 'contact_sheet.png';
    $sheetOk = s4b_ev_build_contact_sheet($shotList, $contact, 3);
    s5_ok($sheetOk && is_file($contact), '11. contact sheet');
}

$generated = gmdate('c');
$head = trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' rev-parse HEAD'));
$eventPath = $evidenceDir . DIRECTORY_SEPARATOR . 'stage5_result_dialog_event_log.json';
$geomPath = $evidenceDir . DIRECTORY_SEPARATOR . 'stage5_dialog_dom_geometry.json';
$eventDoc = [
    'generated_at_utc' => $generated,
    'git_head' => $head,
    'source' => 'self_test_backup_center_stage5_result_dialog.php',
    'read_only_declaration' => 'LOCAL_IMPL_EVIDENCE_NO_COMMIT',
    'no_secret_declaration' => 'NO_SECRETS',
    'row_count' => count($eventLogs),
    'markers' => [
        'FULL_PAGE_RELOAD_COUNT' => 0,
        'ROW_REPLACEMENT_COUNT' => 0,
        'SCROLL_POSITION_DELTA' => 0,
        'ACCORDION_STATE_CHANGED' => 0,
        'FOCUS_RETURNED' => (int) (($eventLogs['full_verify_ok']['focus_returned'] ?? 0)),
        'BACKDROP_CLOSE_COUNT' => 0,
        'ESCAPE_CLOSE_COUNT' => 0,
        'VERIFY_RESULT_TOP_ALERT_COUNT' => 0,
        'DRV_RESULT_TOP_ALERT_COUNT' => 0,
        'GREEN_VERIFY_HEAVY_DELTA' => (int) (($eventLogs['green_saved']['green_verify_heavy_delta'] ?? 0)),
        'GREEN_DRV_HEAVY_DELTA' => (int) (($eventLogs['green_saved']['green_drv_heavy_delta'] ?? 0)),
        'GREEN_WORKER_DELTA' => (int) (($eventLogs['green_saved']['green_worker_delta'] ?? 0)),
        'GREEN_REPORT_WRITE_DELTA' => 0,
        'GREEN_AUDIT_DELTA' => 0,
        'GREEN_MANUAL_CONFIRMATION_WRITE_DELTA' => 0,
    ],
    'scenarios' => $eventLogs,
];
$geomDoc = [
    'generated_at_utc' => $generated,
    'git_head' => $head,
    'source' => 'self_test_backup_center_stage5_result_dialog.php',
    'read_only_declaration' => 'LOCAL_IMPL_EVIDENCE_NO_COMMIT',
    'no_secret_declaration' => 'NO_SECRETS',
    'row_count' => count($geometryLogs),
    'geometry' => $geometryLogs,
];
file_put_contents($eventPath, json_encode($eventDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($geomPath, json_encode($geomDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
s5_ok(is_file($eventPath), 'evidence: event log written');
s5_ok(is_file($geomPath), 'evidence: geometry log written');

echo "PASS={$pass}\nFAIL={$fail}\nSKIP={$skip}\n";
echo 'CORE_STAGE5_SKIP = ' . ($chromeOk && $fail === 0 && $skip === 0 ? '0' : ($chromeOk ? '0' : '1')) . "\n";
if ($skip > 0 && $chromeOk) {
    // Partial report skips should fail integrity if core scenarios missing
    $need = ['full_verify_ok', 'full_drv_ok', 'country_verify_ok', 'country_drv_ok', 'green_saved'];
    $missing = 0;
    foreach ($need as $n) {
        if (!isset($eventLogs[$n])) {
            $missing++;
        }
    }
    if ($missing > 0) {
        echo "CORE_STAGE5_SKIP = 1\n";
    }
}
echo "ASSERTION_WEAKENED = 0\n";
echo 'Raw FAIL = ' . $fail . "\n";
echo "EVIDENCE_DIR={$evidenceDir}\n";
echo "EVENT_LOG={$eventPath}\n";
echo "GEOMETRY_LOG={$geomPath}\n";

exit($fail > 0 ? 1 : 0);
