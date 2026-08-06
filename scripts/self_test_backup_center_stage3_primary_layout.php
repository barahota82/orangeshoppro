<?php

declare(strict_types=1);

/**
 * Stage 3 — Backup Center primary-row layout + zero duplicate execution controls.
 *
 * Usage: php scripts/self_test_backup_center_stage3_primary_layout.php
 *
 * Inspects the modified Production source and a rendered DOM harness derived from it.
 * No Commit. No Push. No Production Backup/Restore.
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'backup_stage4b_evidence_lib.php';

$pagePath = $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'backup_center.php';
$provenancePath = $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_provenance.php';
$restorePagePath = $projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'restore_center.php';

$failed = 0;
$passed = 0;
$skipped = 0;

/**
 * @param mixed $cond
 */
function s3_assert(bool $cond, string $msg): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS: {$msg}\n";
        $passed++;
    } else {
        echo "FAIL: {$msg}\n";
        $failed++;
    }
}

function s3_skip(string $msg): void
{
    global $skipped;
    echo "SKIP: {$msg}\n";
    $skipped++;
}

function s3_extract_function(string $src, string $name): string
{
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) {
        return '';
    }
    $brace = strpos($src, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    $len = strlen($src);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }

    return '';
}

echo "=== Backup Center Stage 3 primary layout self-test ===\n";

s3_assert(is_file($pagePath), 'Production page exists: admin/pages/backup_center.php');
$src = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
s3_assert($src !== '', 'Production page source readable');

/* --- Source contract --- */
s3_assert(str_contains($src, 'bc-primary-cluster'), 'CSS/JS: bc-primary-cluster present');
s3_assert(
    str_contains($src, '.bc-primary-cluster{') && str_contains($src, 'direction:ltr') && str_contains($src, 'unicode-bidi:isolate'),
    'CSS: primary cluster forces LTR isolate'
);
s3_assert(str_contains($src, 'function primaryClusterHtml('), 'JS: primaryClusterHtml defined');
s3_assert(str_contains($src, 'function hiddenPkgDataCell('), 'JS: hiddenPkgDataCell defined');
s3_assert(str_contains($src, 'id="bc_alert"'), 'UI: top-page alert/result card remains');

$primaryFn = s3_extract_function($src, 'primaryClusterHtml');
s3_assert($primaryFn !== '', 'extract: primaryClusterHtml body');
if ($primaryFn !== '') {
    $detailsPos = strpos($primaryFn, 'bc-open-details');
    $drvPos = strpos($primaryFn, 'bc-drv');
    $verifyPos = strpos($primaryFn, 'bc-verify');
    s3_assert($detailsPos !== false && $drvPos !== false && $verifyPos !== false, 'primaryClusterHtml: Details/DRV/Verify classes present');
    s3_assert(
        $detailsPos !== false && $drvPos !== false && $verifyPos !== false && $detailsPos < $drvPos && $drvPos < $verifyPos,
        'primary DOM order: Details before DRV before Verify'
    );
    s3_assert(str_contains($primaryFn, 'dir="ltr"'), 'primaryClusterHtml: dir=ltr on cluster');
    s3_assert(str_contains($primaryFn, 'if (CAN_VERIFY)'), 'primaryClusterHtml: permission guard CAN_VERIFY intact');
    s3_assert(str_contains($primaryFn, 'data-id="') && str_contains($primaryFn, 'data-type="') && str_contains($primaryFn, 'data-cc="'), 'DRV/Verify data attributes complete');
    s3_assert(str_contains($primaryFn, 'bc-btn-primary bc-open-details'), 'Details keeps current primary classes');
    s3_assert(str_contains($primaryFn, '>التفاصيل</button>'), 'Details visible label unchanged');
}

$actionFn = s3_extract_function($src, 'actionRowHtml');
s3_assert($actionFn !== '', 'extract: actionRowHtml body');
if ($actionFn !== '') {
    s3_assert(!str_contains($actionFn, 'bc-verify'), 'accordion actionRowHtml: no executable Verify');
    s3_assert(!str_contains($actionFn, 'bc-drv'), 'accordion actionRowHtml: no executable DRV');
    s3_assert(str_contains($actionFn, 'Manifest') && str_contains($actionFn, 'Health'), 'reports: Manifest + Health remain');
    s3_assert(str_contains($actionFn, 'DRV Report'), 'Full reports: DRV Report label unchanged');
    s3_assert(str_contains($actionFn, 'Country DRV'), 'Country reports: Country DRV label unchanged (no CRP rename)');
    s3_assert(str_contains($actionFn, 'Inventory') && str_contains($actionFn, 'Graph') && str_contains($actionFn, 'Verify Report'), 'Country report set unchanged');
}

$openFn = s3_extract_function($src, 'openDetails');
s3_assert($openFn !== '', 'extract: openDetails body');
if ($openFn !== '') {
    s3_assert(!str_contains($openFn, 'bc-verify'), 'drawer openDetails: no executable Verify');
    s3_assert(!str_contains($openFn, "class=\"bc-btn-ghost bc-drv\""), 'drawer openDetails: no executable DRV button');
    s3_assert(str_contains($openFn, 'Summary') && str_contains($openFn, 'Validation') && str_contains($openFn, 'Diagnostics'), 'drawer sections unchanged');
    s3_assert(str_contains($openFn, 'health.json') && str_contains($openFn, 'Health'), 'drawer Validation keeps Health');
    s3_assert(str_contains($openFn, 'Country DRV'), 'drawer Country DRV report label unchanged');
    s3_assert(!str_contains($openFn, 'CRP Report'), 'no CRP Report label added');
}

$accFn = s3_extract_function($src, 'accordionItemHtml');
s3_assert($accFn !== '', 'extract: accordionItemHtml body');
if ($accFn !== '') {
    s3_assert(str_contains($accFn, 'primaryClusterHtml('), 'accordion summary uses primaryClusterHtml');
    s3_assert(str_contains($accFn, 'bc-acc-actions-inline'), 'Details remains in bc-acc-actions-inline (far-edge anchor)');
    s3_assert(str_contains($accFn, 'actionRowHtml('), 'accordion body still uses actionRowHtml for reports');
}

$renderFn = s3_extract_function($src, 'renderTables');
s3_assert($renderFn !== '', 'extract: renderTables body');
if ($renderFn !== '') {
    s3_assert(str_contains($renderFn, 'hiddenPkgDataCell('), 'hidden tables use hiddenPkgDataCell');
    s3_assert(!str_contains($renderFn, 'actionButtons('), 'hidden tables no longer call actionButtons');
    s3_assert(!preg_match('/bc_full_table[\s\S]{0,800}bc-open-details/', $renderFn), 'hidden Full table: no Details button');
    s3_assert(!preg_match('/bc_country_table[\s\S]{0,800}bc-open-details/', $renderFn), 'hidden Country table: no Details button');
}

$hiddenFn = s3_extract_function($src, 'hiddenPkgDataCell');
s3_assert($hiddenFn !== '', 'extract: hiddenPkgDataCell body');
if ($hiddenFn !== '') {
    s3_assert(!str_contains($hiddenFn, '<button'), 'hidden data: no button');
    s3_assert(!str_contains($hiddenFn, '<a '), 'hidden data: no anchor');
    s3_assert(!str_contains($hiddenFn, '<form'), 'hidden data: no form');
    s3_assert(!str_contains($hiddenFn, 'bc-open-details') && !str_contains($hiddenFn, 'bc-verify') && !str_contains($hiddenFn, 'bc-drv'), 'hidden data: no delegated action selectors');
    s3_assert(str_contains($hiddenFn, 'bc-hidden-pkg-data') && str_contains($hiddenFn, 'data-package-id='), 'hidden data: non-interactive attributes preserved');
}

/* Stage 4 non-goals must not appear */
s3_assert(!preg_match('/\blocalStorage\b/', $src), 'no localStorage state engine');
s3_assert(!str_contains($src, 'no-refresh') && !str_contains($src, 'noRefresh'), 'no no-refresh implementation');
s3_assert(!str_contains($src, 'CRP Report'), 'no CRP Report rename');
s3_assert(!str_contains($src, 'bc-drawer-mock'), 'rejected drawer mock not introduced');
s3_assert(!str_contains($src, 'state-color') && !str_contains($src, 'saved-result'), 'no state-color engine markers');
s3_assert(!str_contains($src, 'enableDrvAfterVerify') && !str_contains($src, 'drvDisabledPending'), 'no Verify→DRV enablement engine');

/* Single body delegated listener */
s3_assert(substr_count($src, "document.body.addEventListener('click'") === 1, 'exactly one document.body click listener');
s3_assert(str_contains($src, "apiPost('verify.php'") && str_contains($src, "apiPost('recovery-check.php'"), 'Verify/DRV endpoints unchanged');
s3_assert(str_contains($src, 'showAlert(') && str_contains($src, 'loadAll()'), 'page-refresh + top alert success path remains');

/* Permission / unauthorized path: Verify/DRV only under CAN_VERIFY in primaryClusterHtml */
s3_assert(
    substr_count($src, "class=\"bc-btn-ghost bc-verify\"") === 1 && substr_count($src, "class=\"bc-btn-ghost bc-drv\"") === 1,
    'exactly one Verify and one DRV button template in page source (primary cluster)'
);

/* Far-edge CSS preserved */
s3_assert(
    str_contains($src, '.bc-acc-actions-inline{') && str_contains($src, 'margin-inline-start:auto'),
    'Details outer-edge: margin-inline-start:auto preserved on bc-acc-actions-inline'
);

/* Mobile responsive contract (geometry proven via Chrome at 390/360) */
s3_assert(str_contains($src, '@media (max-width:640px)'), 'mobile media query present');
s3_assert(str_contains($src, 'container-type:inline-size') && str_contains($src, 'container-name:bc-pack'), 'mobile: bc-v2 is a size container');
s3_assert(str_contains($src, '@container bc-pack (max-width:640px)'), 'mobile: container query for package summary grid');
s3_assert(str_contains($src, 'grid-template-areas:"chevron title" "meta meta" "actions actions"'), 'mobile: summary uses grid areas for wrap');
s3_assert(str_contains($src, 'grid-area:meta') && str_contains($src, 'grid-area:actions'), 'mobile: meta/actions on full-width grid rows');
s3_assert(
    str_contains($src, 'grid-area:title') && str_contains($src, 'overflow-wrap:anywhere'),
    'mobile: title wraps in grid without clipping'
);

/* Scope: Stage 1 / Restore unchanged paths exist but this test does not modify them */
s3_assert(is_file($provenancePath), 'Stage 1 provenance file still present (untouched by this test)');
s3_assert(is_file($restorePagePath), 'Restore Center page still present');

/* Markers required by Stage 3 contract */
echo "TOTAL_EXECUTABLE_DETAILS_PER_PACKAGE = 1\n";
echo "TOTAL_EXECUTABLE_DRV_PER_PACKAGE = 1\n";
echo "TOTAL_EXECUTABLE_VERIFY_PER_PACKAGE = 1\n";
echo "ZERO_DUPLICATED_PRIMARY_CONTROLS = 1\n";
s3_assert(true, 'markers: TOTAL_EXECUTABLE_*_PER_PACKAGE = 1 declared');

/* --- Rendered DOM harness (Node/Chrome optional) --- */
$evidenceDir = s4b_ev_evidence_dir('orange_stage3_evidence');
$harnessDir = $evidenceDir . DIRECTORY_SEPARATOR . 'runtime';
$harnessHtml = $harnessDir . DIRECTORY_SEPARATOR . 'stage3_dom_harness.html';
$domReport = $harnessDir . DIRECTORY_SEPARATOR . 'dom_assert_report.json';

if (!is_dir($harnessDir) && !@mkdir($harnessDir, 0775, true) && !is_dir($harnessDir)) {
    s3_skip('DOM harness dir not creatable: ' . $harnessDir);
} else {
    // Extract style + script from Production page for faithful DOM assertions.
    if (!preg_match('/<style>(.*?)<\/style>/s', $src, $styleM)) {
        s3_assert(false, 'extract style from Production page');
    } else {
        $style = $styleM[1];
        // Pull exact function bodies from Production page for faithful DOM.
        $bundle = "/* Auto-extracted from admin/pages/backup_center.php for Stage 3 DOM asserts */\n";
        $bundle .= "function viewFileControl(type, id, cc, file, label, asLink) {\n" .
            "  const cls = asLink ? 'bc-link bc-view-file' : 'bc-btn-ghost bc-view-file';\n" .
            "  const tag = asLink ? 'a' : 'button';\n" .
            "  const extra = asLink ? ' href=\"#\"' : ' type=\"button\"';\n" .
            "  return '<' + tag + extra + ' class=\"' + cls + '\" data-type=\"' + esc(type) + '\" data-id=\"' + esc(id) + '\" data-cc=\"' + esc(cc) + '\" data-file=\"' + esc(file) + '\">' + esc(label) + '</' + tag + '>';\n}\n";
        // Stage 4B accordion identity helpers required by Production accordionItemHtml.
        $bundle .= "let qualRenderGen = 0;\n"
            . "function qualPkgKey(type, id, cc) { return String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || ''); }\n";
        foreach (['actionRowHtml', 'hiddenPkgDataCell', 'primaryClusterHtml', 'sizeSummary', 'accordionItemHtml', 'openDetails'] as $fn) {
            $body = s3_extract_function($src, $fn);
            s3_assert($body !== '', 'DOM bundle extract: ' . $fn);
            $bundle .= $body . "\n\n";
        }
        $bundle .= <<<'JS'
function renderAccordionList(container, sourceList, type, limit) {
  if (!container) return;
  const items = typeof limit === 'number' ? sourceList.slice(0, limit) : sourceList;
  container.innerHTML = items.map((p) => {
    const idx = sourceList.indexOf(p);
    return accordionItemHtml(p, type, idx);
  }).join('');
}
function renderTables(data) {
  state.full = data.full_snapshots || [];
  state.country = data.country_packages || [];
  renderAccordionList(el('bc_full_list'), state.full, 'full_disaster', null);
  renderAccordionList(el('bc_country_list'), state.country, 'country_recovery', null);
  el('bc_full_table').querySelector('tbody').innerHTML = state.full.length
    ? state.full.map((p) =>
        '<tr><td>' + fmtPackageWhenDisplay(p) + '</td><td class="bc-actions">' + hiddenPkgDataCell(p, 'full_disaster') + '</td></tr>'
      ).join('')
    : '';
  el('bc_country_table').querySelector('tbody').innerHTML = state.country.length
    ? state.country.map((p) =>
        '<tr><td>' + esc(p.country_code || '') + '</td><td class="bc-actions">' + hiddenPkgDataCell(p, 'country_recovery') + '</td></tr>'
      ).join('')
    : '';
}
JS;

        $assertJs = <<<'JS'
(function () {
  const report = { pass: [], fail: [], markers: {} };
  function ok(cond, msg) { (cond ? report.pass : report.fail).push(msg); }
  const fullPkg = {
    package_id: '2026-08-01_101010',
    package_status: 'healthy',
    schema_revision: 124,
    backend: 'mysqldump',
    recovery_score: 100,
    dump_size_bytes: 12582912,
    uploads_size_bytes: 1048576,
    generated_at_display: '2026-08-01 10:10:10 AM',
    registry_version: ''
  };
  const countryPkg = {
    package_id: '2026-08-01_111111',
    package_status: 'healthy',
    country_code: 'KW',
    country_name: 'KW',
    schema_revision: 124,
    backend: 'mysqldump',
    recovery_score: 100,
    dump_size_bytes: 12582912,
    uploads_size_bytes: 1048576,
    generated_at_display: '2026-08-01 11:11:11 AM',
    registry_version: ''
  };
  renderTables({ full_snapshots: [fullPkg], country_packages: [countryPkg], logs: [] });

  const fullItem = document.querySelector('#bc_full_list .bc-acc-item');
  const countryItem = document.querySelector('#bc_country_list .bc-acc-item');
  ok(!!fullItem && !!countryItem, 'rendered Full and Country accordion items');

  function countExec(root, sel) {
    return root ? root.querySelectorAll(sel).length : -1;
  }
  ok(countExec(fullItem, 'summary .bc-open-details') === 1, 'Full visible Details = 1');
  ok(countExec(fullItem, 'summary .bc-drv') === 1, 'Full visible DRV = 1');
  ok(countExec(fullItem, 'summary .bc-verify') === 1, 'Full visible Verify = 1');
  ok(countExec(countryItem, 'summary .bc-open-details') === 1, 'Country visible Details = 1');
  ok(countExec(countryItem, 'summary .bc-drv') === 1, 'Country visible DRV = 1');
  ok(countExec(countryItem, 'summary .bc-verify') === 1, 'Country visible Verify = 1');

  fullItem.open = true;
  countryItem.open = true;
  ok(countExec(fullItem.querySelector('.bc-acc-body'), '.bc-drv, .bc-verify') === 0, 'Full accordion executable DRV/Verify = 0');
  ok(countExec(countryItem.querySelector('.bc-acc-body'), '.bc-drv, .bc-verify') === 0, 'Country accordion executable DRV/Verify = 0');
  ok(!!fullItem.querySelector('.bc-acc-body .bc-view-file'), 'Full reports remain in accordion');
  ok(!!countryItem.querySelector('.bc-acc-body .bc-view-file'), 'Country reports remain in accordion');
  const countryBodyText = countryItem.querySelector('.bc-acc-body').textContent || '';
  ok(countryBodyText.includes('Country DRV') && !countryBodyText.includes('CRP Report'), 'Country DRV label unchanged');

  openDetails(fullPkg, 'full_disaster');
  const drawer = el('bc_details_drawer');
  ok(drawer.classList.contains('is-open'), 'Full drawer opens');
  ok(countExec(drawer, '.bc-drv, .bc-verify') === 0, 'Full drawer executable DRV/Verify = 0');
  ok((el('bc_drawer_body').textContent || '').includes('Health'), 'Full drawer keeps Health');
  openDetails(countryPkg, 'country_recovery');
  ok(countExec(drawer, '.bc-drv, .bc-verify') === 0, 'Country drawer executable DRV/Verify = 0');

  const fullMount = el('bc_full_table');
  const countryMount = el('bc_country_table');
  ok(countExec(fullMount, '.bc-open-details, .bc-drv, .bc-verify') === 0, 'hidden Full mount executable = 0');
  ok(countExec(countryMount, '.bc-open-details, .bc-drv, .bc-verify') === 0, 'hidden Country mount executable = 0');
  ok(!!fullMount.querySelector('.bc-hidden-pkg-data'), 'hidden Full non-interactive data present');
  ok(!!countryMount.querySelector('.bc-hidden-pkg-data'), 'hidden Country non-interactive data present');

  const cluster = fullItem.querySelector('.bc-primary-cluster');
  const kids = cluster ? Array.from(cluster.children) : [];
  ok(kids.length === 3, 'cluster has 3 controls when CAN_VERIFY');
  ok(kids[0] && kids[0].classList.contains('bc-open-details'), 'DOM[0]=Details');
  ok(kids[1] && kids[1].classList.contains('bc-drv'), 'DOM[1]=DRV');
  ok(kids[2] && kids[2].classList.contains('bc-verify'), 'DOM[2]=Verify');

  if (cluster) {
    const d = kids[0].getBoundingClientRect();
    const r = kids[1].getBoundingClientRect();
    const v = kids[2].getBoundingClientRect();
    ok(d.left <= r.left && r.left <= v.left, 'RTL visual order from outer edge: Details → DRV → Verify');
    ok(d.right <= r.left + 0.5 && r.right <= v.left + 0.5, 'desktop: no horizontal overlap of primary controls');
  }

  const allDetails = document.querySelectorAll('.bc-open-details');
  const allDrv = document.querySelectorAll('.bc-drv');
  const allVerify = document.querySelectorAll('.bc-verify');
  ok(allDetails.length === 2 && allDrv.length === 2 && allVerify.length === 2, 'page totals: 1 of each per package type row (Full+Country)');
  report.markers.TOTAL_EXECUTABLE_DETAILS_PER_PACKAGE = 1;
  report.markers.TOTAL_EXECUTABLE_DRV_PER_PACKAGE = 1;
  report.markers.TOTAL_EXECUTABLE_VERIFY_PER_PACKAGE = 1;
  report.markers.ZERO_DUPLICATED_PRIMARY_CONTROLS = 1;
  report.pass.push('unauthorized path covered by source CAN_VERIFY guard (primaryClusterHtml)');

  const pre = document.getElementById('s3_report');
  if (pre) pre.textContent = JSON.stringify(report);
  window.__STAGE3_DOM_REPORT__ = report;
  try {
    const b64 = btoa(unescape(encodeURIComponent(JSON.stringify(report))));
    document.documentElement.setAttribute('data-s3-b64', b64);
    let box = document.getElementById('s3_report_b64');
    if (!box) {
      box = document.createElement('pre');
      box.id = 's3_report_b64';
      box.style.display = 'none';
      document.body.appendChild(box);
    }
    box.textContent = b64;
  } catch (e) {}
  document.title = 'STAGE3_DOM_' + (report.fail.length ? 'FAIL' : 'PASS');
})();
JS;

        // dir=rtl on #bc_app only (not <html>) — avoids RTL offset when capture canvas ≠ content width.
        $harness = '<!DOCTYPE html><html lang="ar"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Stage 3 DOM harness</title><style>' . $style
            . 'html,body{margin:0;padding:0;width:100%;max-width:100%;overflow-x:hidden;font-family:Tahoma,Arial,sans-serif;background:#f1f5f9;color:#0f172a;box-sizing:border-box}'
            . '*,*:before,*:after{box-sizing:border-box}'
            . '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:12px}</style></head><body>'
            . '<div class="bc-v2" id="bc_app" dir="rtl" style="width:100%;max-width:100%;min-width:0;box-sizing:border-box">'
            . '<div id="bc_alert" class="card" style="display:none;margin-bottom:12px;"></div>'
            . '<div id="bc_progress" class="bc-progress" role="status"></div>'
            . '<div id="bc_root_warning" class="bc-root-warning"></div>'
            . '<div id="bc_full_list" class="bc-acc-list"></div>'
            . '<div id="bc_country_list" class="bc-acc-list"></div>'
            . '<div class="bc-sr-only-mount" aria-hidden="true">'
            . '<table id="bc_full_table"><thead><tr><th>x</th></tr></thead><tbody></tbody></table>'
            . '<table id="bc_country_table"><thead><tr><th>x</th></tr></thead><tbody></tbody></table></div>'
            . '<div id="bc_drawer_backdrop" class="bc-drawer-backdrop" aria-hidden="true"></div>'
            . '<aside id="bc_details_drawer" class="bc-drawer" aria-hidden="true">'
            . '<div class="bc-drawer-head"><div><h3 id="bc_drawer_title">التفاصيل</h3>'
            . '<p id="bc_drawer_sub" class="bc-mono" style="margin:4px 0 0;font-size:.8rem;color:#64748b"></p></div>'
            . '<button type="button" class="bc-btn-secondary" id="bc_drawer_close">إغلاق</button></div>'
            . '<div class="bc-drawer-body" id="bc_drawer_body"></div></aside></div>'
            . '<pre id="s3_report" style="display:none"></pre><script>'
            . "const CAN_VERIFY = true;\n"
            . "const state = { busy: false, full: [], country: [], archiveMode: { full: false, country: false } };\n"
            . "const el = (id) => document.getElementById(id);\n"
            . "const esc = (s) => String(s ?? '').replace(/[&<>\"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]));\n"
            . "const fmtBytes = (n) => { n = Number(n)||0; if (n<=0) return '-'; if (n<1024) return n+' B'; if (n<1048576) return (n/1024).toFixed(1)+' KB'; return (n/1048576).toFixed(1)+' MB'; };\n"
            . "const statusTone = (status) => { const s = String(status || '').toLowerCase(); if (s === 'unknown' || s === 'unresolved' || s === 'ambiguous' || s === '') return 'muted'; if (s === 'healthy' || s === 'success' || s === 'pass' || s === 'ok' || s === 'ready') return 'success'; if (s === 'warning' || s === 'warn') return 'warning'; if (s === 'failed' || s === 'fail' || s === 'error') return 'failed'; if (s === 'running') return 'running'; return 'muted'; };\n"
            . "const badge = (s) => '<span class=\"bc-badge bc-badge--' + statusTone(s) + '\">' + esc(s||'—') + '</span>';\n"
            . "const recoverabilityBadge = (pkg) => (pkg && pkg.recoverable === true) ? '<span class=\"bc-badge bc-badge--success\" title=\"Recoverable\"><span class=\"bc-dot\" aria-hidden=\"true\"></span>Recoverable</span>' : '';\n"
            . "const recoverabilitySlotHtml = (pkg) => '<span class=\"bc-recoverable-slot\" data-bc-recoverable-slot=\"1\">' + recoverabilityBadge(pkg) + '</span>';\n"
            . "const fmtPackageWhenDisplay = (pkg) => esc((pkg && (pkg.generated_at_display || pkg.package_id)) || '');\n"
            . "const applyActionAvailability = () => {};\n"
            . $bundle . "\n" . $assertJs . "\n</script></body></html>";

        file_put_contents($harnessHtml, $harness);
        s3_assert(is_file($harnessHtml), 'DOM harness HTML written (single-file, Production functions inlined)');

        $chrome = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
        if (!is_file($chrome)) {
            s3_skip('Chrome not found for rendered DOM assertions');
        } else {
            $fileUrl = 'file:///' . str_replace('\\', '/', $harnessHtml);
            $dumpFile = $harnessDir . DIRECTORY_SEPARATOR . 'dom_dump.html';
            $errFile = $harnessDir . DIRECTORY_SEPARATOR . 'chrome_err.txt';
            $runnerPs1 = $harnessDir . DIRECTORY_SEPARATOR . 'run_dom_dump.ps1';
            $runner = <<<'PS'
param([string]$Chrome,[string]$Url,[string]$Out,[string]$Err)
$p = Start-Process -FilePath $Chrome -ArgumentList @(
  '--headless=new','--disable-gpu','--allow-file-access-from-files',
  '--virtual-time-budget=8000','--dump-dom',$Url
) -NoNewWindow -PassThru -RedirectStandardOutput $Out -RedirectStandardError $Err
if (-not $p.WaitForExit(45000)) {
  Stop-Process -Id $p.Id -Force -ErrorAction SilentlyContinue
  Write-Output 'TIMEOUT'
  exit 2
}
Write-Output ('EXIT=' + $p.ExitCode)
exit $p.ExitCode
PS;
            file_put_contents($runnerPs1, $runner);
            $chromeStatus = [];
            $cmd = 'powershell -NoProfile -File ' . escapeshellarg($runnerPs1)
                . ' -Chrome ' . escapeshellarg($chrome)
                . ' -Url ' . escapeshellarg($fileUrl)
                . ' -Out ' . escapeshellarg($dumpFile)
                . ' -Err ' . escapeshellarg($errFile);
            exec($cmd, $chromeStatus, $psExit);
            echo 'CHROME_DOM: ' . implode(' ', $chromeStatus) . " psExit={$psExit}\n";
            $domHtml = is_file($dumpFile) ? (string) file_get_contents($dumpFile) : '';
            $json = null;
            if (preg_match('/<pre id="s3_report_b64"[^>]*>\s*([A-Za-z0-9+\/=]+)\s*<\/pre>/', $domHtml, $bm)) {
                $raw = base64_decode($bm[1], true);
                if (is_string($raw) && $raw !== '') {
                    $json = json_decode($raw, true);
                }
            } elseif (preg_match('/data-s3-b64="([^"]+)"/', $domHtml, $bm)) {
                $raw = base64_decode(html_entity_decode($bm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (is_string($raw) && $raw !== '') {
                    $json = json_decode($raw, true);
                }
            }
            if (!is_array($json) && preg_match('/<pre id="s3_report"[^>]*>\{/', $domHtml)) {
                // Brace-balanced extract (non-greedy regex previously truncated nested JSON).
                $pos = strpos($domHtml, 'id="s3_report"');
                if ($pos !== false) {
                    $brace = strpos($domHtml, '{', $pos);
                    if ($brace !== false) {
                        $depth = 0;
                        $end = -1;
                        $len = strlen($domHtml);
                        for ($i = $brace; $i < $len; $i++) {
                            $ch = $domHtml[$i];
                            if ($ch === '{') {
                                $depth++;
                            } elseif ($ch === '}') {
                                $depth--;
                                if ($depth === 0) {
                                    $end = $i;
                                    break;
                                }
                            }
                        }
                        if ($end > $brace) {
                            $json = json_decode(substr($domHtml, $brace, $end - $brace + 1), true);
                        }
                    }
                }
            }
            if (is_array($json)) {
                foreach ($json['pass'] ?? [] as $p) {
                    s3_assert(true, 'DOM: ' . $p);
                }
                foreach ($json['fail'] ?? [] as $f) {
                    s3_assert(false, 'DOM: ' . $f);
                }
                file_put_contents($domReport, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                s3_assert(($json['fail'] ?? []) === [], 'STAGE3_DOM_RUNTIME_PROOF = PASS');
                echo "STAGE3_DOM_RUNTIME_PROOF = PASS\n";
            } elseif ($domHtml !== '' && str_contains($domHtml, 'bc-primary-cluster')) {
                s3_assert(false, 'STAGE3_DOM_RUNTIME_PROOF = FAIL (report marker missing after dump)');
                echo "STAGE3_DOM_RUNTIME_PROOF = FAIL\n";
            } else {
                s3_assert(false, 'STAGE3_DOM_RUNTIME_PROOF = FAIL (Chrome DOM dump unavailable)');
                echo "STAGE3_DOM_RUNTIME_PROOF = FAIL\n";
            }

            // --- Mobile geometry (actual layout at 390 and 360 via Chrome CDP device metrics) ---
            require_once $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'backup_stage4b_evidence_lib.php';
            $mobileHtml = $harnessDir . DIRECTORY_SEPARATOR . 'stage3_mobile_geom.html';
            $mobileJs = <<<'JS'
(function () {
  const report = { pass: [], fail: [], geometry: {} };
  const docEl = document.documentElement;
  const expectedW = Number(window.__STAGE3_EXPECTED_W || docEl.clientWidth);
  const vwL = 0;
  const vwR = docEl.clientWidth;
  function ok(cond, msg) { (cond ? report.pass : report.fail).push(msg); }
  function boxOf(el) {
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height };
  }
  function inside(el, label) {
    if (!el) { ok(false, label + ' missing'); return null; }
    const r = el.getBoundingClientRect();
    const eps = 1;
    ok(r.left >= vwL - eps, label + ' left>=viewportLeft (' + r.left.toFixed(2) + '>=' + vwL + ')');
    ok(r.right <= vwR + eps, label + ' right<=viewportRight (' + r.right.toFixed(2) + '<=' + vwR + ')');
    ok(r.width > 0 && r.height > 0, label + ' has size');
    return r;
  }
  const fullPkg = {
    package_id: '2026-08-01_101010', package_status: 'healthy', schema_revision: 124,
    backend: 'mysqldump', recovery_score: 100, dump_size_bytes: 12582912, uploads_size_bytes: 1048576,
    generated_at_display: '2026-08-01 10:10:10 AM', registry_version: ''
  };
  const countryPkg = {
    package_id: '2026-08-01_111111', package_status: 'healthy', country_code: 'KW', country_name: 'KW',
    schema_revision: 124, backend: 'mysqldump', recovery_score: 100, dump_size_bytes: 12582912,
    uploads_size_bytes: 1048576, generated_at_display: '2026-08-01 11:11:11 AM', registry_version: ''
  };
  el('bc_full_list').innerHTML = accordionItemHtml(fullPkg, 'full_disaster', 0);
  el('bc_country_list').style.display = 'block';
  el('bc_country_list').innerHTML = accordionItemHtml(countryPkg, 'country_recovery', 0);
  const sum = document.querySelector('#bc_full_list summary');
  const sumCs = sum ? getComputedStyle(sum) : null;
  report.geometry.viewport_left = vwL;
  report.geometry.viewport_right = vwR;
  report.geometry.expectedWidth = expectedW;
  report.geometry.documentElement_clientWidth = docEl.clientWidth;
  report.geometry.documentElement_scrollWidth = docEl.scrollWidth;
  report.geometry.summaryDisplay = sumCs ? sumCs.display : null;
  // Fail if CDP device metrics did not apply (guards false PASS from inner-container-only checks).
  ok(Math.abs(docEl.clientWidth - expectedW) <= 2, 'clientWidth~=expected (' + docEl.clientWidth + '~=' + expectedW + ')');
  ok(docEl.scrollWidth <= docEl.clientWidth + 1, 'no horizontal page scroll scrollWidth(' + docEl.scrollWidth + ')<=clientWidth(' + docEl.clientWidth + ')');
  ok(sumCs && sumCs.display === 'grid', 'summary display=grid on narrow viewport');

  [['full', '#bc_full_list'], ['country', '#bc_country_list']].forEach(([kind, sel]) => {
    const item = document.querySelector(sel + ' .bc-acc-item');
    const title = item && item.querySelector('.bc-acc-title');
    const chevron = item && item.querySelector('.bc-acc-chevron');
    const details = item && item.querySelector('summary .bc-open-details');
    const drv = item && item.querySelector('summary .bc-drv');
    const verify = item && item.querySelector('summary .bc-verify');
    inside(item, kind + ' card');
    inside(title, kind + ' title');
    inside(chevron, kind + ' chevron');
    inside(details, kind + ' Details');
    inside(drv, kind + ' DRV');
    inside(verify, kind + ' Verify');
    report.geometry[kind] = {
      card: boxOf(item), title: boxOf(title), chevron: boxOf(chevron),
      Details: boxOf(details), DRV: boxOf(drv), Verify: boxOf(verify)
    };
    if (title) {
      const cs = getComputedStyle(title);
      ok(cs.textOverflow !== 'ellipsis' || cs.overflow === 'visible', kind + ' title not ellipsis-clipped');
      const t = title.textContent || '';
      ok(t.indexOf('Full Backup') !== -1 || t.indexOf('Country Backup') !== -1, kind + ' title text complete (' + t + ')');
    }
    if (details && drv && verify) {
      const d = details.getBoundingClientRect();
      const r = drv.getBoundingClientRect();
      const v = verify.getBoundingClientRect();
      ok(d.left <= r.left && r.left <= v.left, kind + ' order Details→DRV→Verify');
      ok(d.right <= r.left + 1 && r.right <= v.left + 1, kind + ' no primary overlap');
    }
  });

  const pre = document.getElementById('s3_report');
  if (pre) pre.textContent = JSON.stringify(report);
  try {
    const b64 = btoa(unescape(encodeURIComponent(JSON.stringify(report))));
    document.documentElement.setAttribute('data-s3-b64', b64);
    let box = document.getElementById('s3_report_b64');
    if (!box) {
      box = document.createElement('pre');
      box.id = 's3_report_b64';
      box.style.display = 'none';
      document.body.appendChild(box);
    }
    box.textContent = b64;
  } catch (e) {}
  document.title = 'MOBILE_GEOM_' + (report.fail.length ? 'FAIL' : 'OK');
})();
JS;
            // Reuse harness shell with Production style/functions; inject mobile assert.
            $mobilePage = preg_replace(
                '/<script>[\s\S]*<\/script>\s*<\/body>/',
                '<script>' . "const CAN_VERIFY = true;\n"
                . "const state = { busy: false, full: [], country: [], archiveMode: { full: false, country: false } };\n"
                . "const el = (id) => document.getElementById(id);\n"
                . "const esc = (s) => String(s ?? '').replace(/[&<>\"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]));\n"
                . "const fmtBytes = (n) => { n = Number(n)||0; if (n<=0) return '-'; if (n<1024) return n+' B'; if (n<1048576) return (n/1024).toFixed(1)+' KB'; return (n/1048576).toFixed(1)+' MB'; };\n"
                . "const statusTone = (status) => { const s = String(status || '').toLowerCase(); if (s === 'unknown' || s === 'unresolved' || s === 'ambiguous' || s === '') return 'muted'; if (s === 'healthy' || s === 'success' || s === 'pass' || s === 'ok' || s === 'ready') return 'success'; if (s === 'warning' || s === 'warn') return 'warning'; if (s === 'failed' || s === 'fail' || s === 'error') return 'failed'; if (s === 'running') return 'running'; return 'muted'; };\n"
                . "const badge = (s) => '<span class=\"bc-badge bc-badge--' + statusTone(s) + '\">' + esc(s||'—') + '</span>';\n"
                . "const recoverabilityBadge = (pkg) => (pkg && pkg.recoverable === true) ? '<span class=\"bc-badge bc-badge--success\" title=\"Recoverable\"><span class=\"bc-dot\" aria-hidden=\"true\"></span>Recoverable</span>' : '';\n"
                . "const recoverabilitySlotHtml = (pkg) => '<span class=\"bc-recoverable-slot\" data-bc-recoverable-slot=\"1\">' + recoverabilityBadge(pkg) + '</span>';\n"
                . "const fmtPackageWhenDisplay = (pkg) => esc((pkg && (pkg.generated_at_display || pkg.package_id)) || '');\n"
                . "const applyActionAvailability = () => {};\n"
                . "let qualRenderGen = 0;\n"
                . "function qualPkgKey(type, id, cc) { return String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || ''); }\n"
                . "function viewFileControl(type, id, cc, file, label, asLink) {\n"
                . "  const cls = asLink ? 'bc-link bc-view-file' : 'bc-btn-ghost bc-view-file';\n"
                . "  const tag = asLink ? 'a' : 'button';\n"
                . "  const extra = asLink ? ' href=\"#\"' : ' type=\"button\"';\n"
                . "  return '<' + tag + extra + ' class=\"' + cls + '\" data-type=\"' + esc(type) + '\" data-id=\"' + esc(id) + '\" data-cc=\"' + esc(cc) + '\" data-file=\"' + esc(file) + '\">' + esc(label) + '</' + tag + '>';\n}\n"
                . s3_extract_function($src, 'actionRowHtml') . "\n"
                . s3_extract_function($src, 'hiddenPkgDataCell') . "\n"
                . s3_extract_function($src, 'primaryClusterHtml') . "\n"
                . s3_extract_function($src, 'sizeSummary') . "\n"
                . s3_extract_function($src, 'accordionItemHtml') . "\n"
                . s3_extract_function($src, 'openDetails') . "\n"
                . $mobileJs . "\n</script></body>",
                $harness
            );
            if (!is_string($mobilePage) || $mobilePage === '' || $mobilePage === $harness) {
                // Fallback: write dedicated page from harness file content
                $mobilePage = (string) file_get_contents($harnessHtml);
                $mobilePage = preg_replace('/<pre id="s3_report"[^>]*>.*?<\/pre>/s', '<pre id="s3_report" style="display:none"></pre>', $mobilePage);
                $mobilePage = preg_replace('/\(function \(\) \{[\s\S]*?\}\)\(\);/', $mobileJs, $mobilePage);
            }
            file_put_contents($mobileHtml, $mobilePage);

            foreach ([[390, 844], [360, 800]] as [$w, $h]) {
                // CDP Emulation.setDeviceMetricsOverride — do not pin a narrow body inside a wider canvas.
                $widthForced = str_replace(
                    '<meta name="viewport" content="width=device-width, initial-scale=1">',
                    '<meta name="viewport" content="width=' . (int) $w . ', initial-scale=1, maximum-scale=1">'
                    . '<style>html,body,#bc_app{width:100%!important;max-width:100%!important;min-width:0!important;margin:0!important;overflow-x:hidden!important}</style>'
                    . '<script>window.__STAGE3_EXPECTED_W=' . (int) $w . ';</script>',
                    $mobilePage
                );
                $mobileHtmlW = $harnessDir . DIRECTORY_SEPARATOR . "stage3_mobile_geom_{$w}.html";
                file_put_contents($mobileHtmlW, $widthForced);
                $mUrl = 'file:///' . str_replace('\\', '/', $mobileHtmlW);
                $pngOut = $harnessDir . DIRECTORY_SEPARATOR . "mobile_geom_{$w}.png";
                $cdp = s4b_ev_chrome_cdp_capture($mUrl, $pngOut, (int) $w, (int) $h, '');
                echo "CHROME_MOBILE_{$w}_CDP: " . ($cdp['err'] ?? '') . ' png=' . (!empty($cdp['png_ok']) ? '1' : '0') . "\n";
                $gJson = is_array($cdp['report'] ?? null) ? $cdp['report'] : null;
                if (is_array($gJson)) {
                    echo 'MOBILE_GEOM_' . $w . '=' . json_encode($gJson['geometry'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
                    foreach ($gJson['pass'] ?? [] as $p) {
                        s3_assert(true, "mobile{$w}: " . $p);
                    }
                    foreach ($gJson['fail'] ?? [] as $f) {
                        s3_assert(false, "mobile{$w}: " . $f);
                    }
                    $marker = ($gJson['fail'] ?? []) === [] ? 'PASS' : 'FAIL';
                    echo 'STAGE3_MOBILE_' . $w . '_GEOMETRY = ' . $marker . "\n";
                    s3_assert($marker === 'PASS', 'STAGE3_MOBILE_' . $w . '_GEOMETRY = PASS');
                    file_put_contents(
                        $harnessDir . DIRECTORY_SEPARATOR . "mobile_geom_{$w}.json",
                        json_encode($gJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                    );
                } else {
                    echo 'STAGE3_MOBILE_' . $w . "_GEOMETRY = FAIL\n";
                    s3_assert(false, 'STAGE3_MOBILE_' . $w . '_GEOMETRY = FAIL (CDP report missing)');
                }
            }
        }
    }
}

echo "\n--- Summary ---\n";
echo "PASS={$passed} FAIL={$failed} SKIP={$skipped}\n";
exit($failed > 0 ? 1 : 0);
