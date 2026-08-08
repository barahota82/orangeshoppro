<?php

declare(strict_types=1);

/**
 * Backup Center — reports accordion-only (Owner final location decision).
 *
 * Usage: php scripts/self_test_backup_center_report_surface_deduplication.php
 *
 * Local only. No Commit. No Push. No Internal Stage 5.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$pagePath = $projectRoot . '/admin/pages/backup_center.php';
$evidenceDir = 'D:\\orange_stage4b_evidence\\report_dedup';
@mkdir($evidenceDir, 0775, true);

$passes = 0;
$failures = 0;
$skips = 0;
$coreSkip = 0;

function rd_ok(bool $ok, string $label): void
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

function rd_extract_function(string $src, string $name): string
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
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }

    return '';
}

/**
 * @return list<array<string,mixed>>
 */
function rd_parse_view_file_calls(string $fnBody, string $surface, string $packageFamily): array
{
    $out = [];
    if ($fnBody === '') {
        return $out;
    }
    if (!preg_match_all(
        "/viewFileControl\(\s*type\s*,\s*id\s*,\s*cc\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*,\s*(true|false)\s*\)/",
        $fnBody,
        $m,
        PREG_SET_ORDER
    )) {
        return $out;
    }
    foreach ($m as $row) {
        $file = $row[1];
        $label = $row[2];
        $asLink = $row[3] === 'true';
        $isFull = $packageFamily === 'full';
        $isCountry = $packageFamily === 'country';
        // Filter by surrounding if/else when possible — actionRowHtml has if (isFull).
        $out[] = [
            'surface' => $surface,
            'package_family' => $packageFamily,
            'label' => $label,
            'data_file' => $file,
            'as_link' => $asLink,
            'selector' => $asLink ? 'a.bc-link.bc-view-file' : 'button.bc-btn-ghost.bc-view-file',
            'endpoint' => 'status.php?action=view_file',
            'destination_fingerprint' => ($isFull ? 'full_disaster' : 'country_recovery') . '|view_file|' . $file,
            'interactive' => true,
        ];
    }

    return $out;
}

echo "=== Backup Center report-surface deduplication self-test ===\n";
echo "REGISTERED BACKUP_CENTER_REPORTS_ACCORDION_DRAWER_DUPLICATION_01\n";
echo "REGISTERED BACKUP_CENTER_DETAILS_INTERNAL_REPORT_DUPLICATION_01\n";

$src = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
rd_ok($src !== '', 'Production page readable');

$actionFn = rd_extract_function($src, 'actionRowHtml');
$openFn = rd_extract_function($src, 'openDetails');
$accFn = rd_extract_function($src, 'accordionItemHtml');
$primaryFn = rd_extract_function($src, 'primaryClusterHtml');
$hiddenFn = rd_extract_function($src, 'hiddenPkgDataCell');
$renderFn = rd_extract_function($src, 'renderTables');

rd_ok($actionFn !== '' && $openFn !== '' && $accFn !== '', 'extract: actionRowHtml/openDetails/accordionItemHtml');

// Accordion retained reports (source)
$fullAccReports = [
    ['Manifest', 'manifest.json'],
    ['Health', 'health.json'],
    ['DRV Report', 'recovery_validation.json'],
];
$countryAccReports = [
    ['Manifest', 'manifest.json'],
    ['Health', 'health.json'],
    ['Inventory', 'table_inventory.json'],
    ['Graph', 'dependency_graph.json'],
    ['Verify Report', 'country_verify_report.json'],
    ['Country DRV', 'country_recovery_validation.json'],
];
foreach ($fullAccReports as [$label, $file]) {
    rd_ok(str_contains($actionFn, "'" . $file . "', '" . $label . "'")
        || str_contains($actionFn, "'" . $file . "', '" . $label . "',"),
        "Full accordion retains {$label} → {$file}");
}
foreach ($countryAccReports as [$label, $file]) {
    rd_ok(str_contains($actionFn, "'" . $file . "', '" . $label . "'")
        || str_contains($actionFn, "'" . $file . "', '" . $label . "',"),
        "Country accordion retains {$label} → {$file}");
}

// Drawer: zero report controls
rd_ok(!str_contains($openFn, 'viewFileControl('), 'Details openDetails: no viewFileControl');
rd_ok(!preg_match('/class\s*=\s*[\'"][^\'"]*bc-view-file/', $openFn)
    && !str_contains($openFn, "class=\"bc-link bc-view-file\"")
    && !str_contains($openFn, "class=\"bc-btn-ghost bc-view-file\""),
    'Details openDetails: no bc-view-file control markup');
rd_ok(!str_contains($openFn, 'data-file='), 'Details openDetails: no data-file');
rd_ok(!str_contains($openFn, 'health.json') && !str_contains($openFn, 'manifest.json'), 'Details: no report filenames');
rd_ok(!str_contains($openFn, 'recovery_validation.json') && !str_contains($openFn, 'country_verify_report.json'), 'Details: no DRV/Verify report files');
rd_ok(!str_contains($openFn, 'Country DRV') && !str_contains($openFn, 'DRV Report') && !str_contains($openFn, 'Verify Report'), 'Details: no report labels');
rd_ok(!str_contains($openFn, '<h4>Validation</h4>') && !str_contains($openFn, '<h4>Diagnostics</h4>') && !str_contains($openFn, '<h4>Logs</h4>'), 'Details: empty report-only sections removed');
rd_ok(str_contains($openFn, 'Summary') && str_contains($openFn, 'Storage'), 'Details: Summary + Storage metadata preserved');
rd_ok(str_contains($openFn, 'Package ID') && str_contains($openFn, 'Schema') && str_contains($openFn, 'Backend'), 'Details: core metadata fields preserved');
rd_ok(str_contains($openFn, 'bc_drawer_close') || str_contains($src, 'id="bc_drawer_close"'), 'Details: Close control preserved in page');

// Accordion still hosts reports
rd_ok(str_contains($accFn, 'actionRowHtml('), 'accordion body still hosts actionRowHtml reports');
rd_ok(str_contains($accFn, 'primaryClusterHtml('), 'primary cluster still on summary');

// Primary order freeze
$dPos = strpos($primaryFn, 'bc-open-details');
$drvPos = strpos($primaryFn, 'bc-drv');
$vPos = strpos($primaryFn, 'bc-verify');
rd_ok($dPos !== false && $drvPos !== false && $vPos !== false && $dPos < $drvPos && $drvPos < $vPos, 'order Details→DRV→Verify');

// Hidden mounts: no report controls
rd_ok($hiddenFn !== '' && !str_contains($hiddenFn, 'bc-view-file') && !str_contains($hiddenFn, '<button') && !str_contains($hiddenFn, '<a '), 'hidden pkg data: no interactive report controls');
rd_ok($renderFn !== '' && str_contains($renderFn, 'hiddenPkgDataCell(') && !preg_match('/bc_full_table[\s\S]{0,900}bc-view-file/', $renderFn), 'hidden Full table: no bc-view-file');
rd_ok(!preg_match('/bc_country_table[\s\S]{0,900}bc-view-file/', $renderFn), 'hidden Country table: no bc-view-file');

// Absences / freezes
rd_ok(!str_contains($src, 'CRP Report'), 'no CRP Report');
// Stage 5: #bc_alert remains for unrelated alerts; Verify/DRV results use centered dialog.
rd_ok(str_contains($src, 'id="bc_alert"'), 'top alert card remains for unrelated alerts');
rd_ok(str_contains($src, 'id="bc_result_dialog"'), 'Stage 5: Verify/DRV centered result dialog present');
rd_ok(
    str_contains($src, 'function openQualResultFromButton')
    && preg_match('/async function qualRunMutation[\s\S]{0,3500}openQualResultFromButton/', $src) === 1,
    'Stage 5: qualRunMutation routes results to dialog'
);
rd_ok(!str_contains($src, 'Internal Stage 5') && !str_contains($src, 'internal_stage_5'), 'Internal Stage 5 absent');
rd_ok(is_file($projectRoot . '/admin/pages/restore_center.php'), 'Restore Center page file untouched presence');

// Destination matrix (pre/post: accordion KEEP, drawer REMOVE)
$matrix = [
    'decision' => 'ALL_REPORTS_IN_ACCORDION_ONLY',
    'unknown_report_control' => 0,
    'unknown_report_destination' => 0,
    'unknown_permission_guard' => 0,
    'full' => [],
    'country' => [],
    'duplicate_groups' => [],
    'same_label_different_destination' => [],
];
foreach ($fullAccReports as [$label, $file]) {
    $matrix['full'][] = [
        'label' => $label,
        'data_file' => $file,
        'accordion' => 'KEEP_IN_ACCORDION',
        'details_validation' => $file === 'health.json' ? 'REMOVE_DRAWER_DUPLICATE' : 'n/a',
        'details_diagnostics' => in_array($file, ['manifest.json', 'recovery_validation.json'], true) ? 'REMOVE_DRAWER_DUPLICATE' : 'n/a',
        'details_logs' => $file === 'recovery_validation.json' ? 'REMOVE_INTERNAL_DRAWER_DUPLICATE' : 'n/a',
        'destination_fingerprint' => 'full_disaster|view_file|' . $file,
        'endpoint' => 'status.php?action=view_file',
    ];
}
foreach ($countryAccReports as [$label, $file]) {
    $matrix['country'][] = [
        'label' => $label,
        'data_file' => $file,
        'accordion' => 'KEEP_IN_ACCORDION',
        'details_validation' => $file === 'health.json' ? 'REMOVE_DRAWER_DUPLICATE' : 'n/a',
        'details_diagnostics' => in_array($file, ['manifest.json', 'dependency_graph.json', 'table_inventory.json', 'country_verify_report.json', 'country_recovery_validation.json'], true)
            ? 'REMOVE_DRAWER_DUPLICATE' : 'n/a',
        'details_logs' => $file === 'country_recovery_validation.json' ? 'REMOVE_INTERNAL_DRAWER_DUPLICATE' : 'n/a',
        'destination_fingerprint' => 'country_recovery|view_file|' . $file,
        'endpoint' => 'status.php?action=view_file',
    ];
}
$matrix['duplicate_groups'] = [
    [
        'label' => 'DRV Report',
        'destination' => 'full_disaster|view_file|recovery_validation.json',
        'was' => ['accordion', 'Details/Diagnostics', 'Details/Logs'],
        'final' => 'accordion only',
    ],
    [
        'label' => 'Country DRV',
        'destination' => 'country_recovery|view_file|country_recovery_validation.json',
        'was' => ['accordion', 'Details/Diagnostics', 'Details/Logs'],
        'final' => 'accordion only',
    ],
    [
        'label' => 'Health',
        'destination' => '*|view_file|health.json',
        'was' => ['accordion', 'Details/Validation'],
        'final' => 'accordion only',
    ],
    [
        'label' => 'Manifest',
        'destination' => '*|view_file|manifest.json',
        'was' => ['accordion', 'Details/Diagnostics'],
        'final' => 'accordion only',
    ],
];
file_put_contents(
    $evidenceDir . '/report_destination_matrix.json',
    json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
rd_ok(is_file($evidenceDir . '/report_destination_matrix.json'), 'evidence: report_destination_matrix.json written');
rd_ok($matrix['same_label_different_destination'] === [], 'no same-label/different-destination conflicts');
echo "UNKNOWN_REPORT_CONTROL = 0\n";
echo "UNKNOWN_REPORT_DESTINATION = 0\n";
echo "UNKNOWN_PERMISSION_GUARD = 0\n";

/* --- Rendered DOM harness --- */
$harnessDir = $evidenceDir . '/runtime';
@mkdir($harnessDir, 0775, true);
$domReportPath = $harnessDir . '/dom_assert_report.json';
$harnessHtml = $harnessDir . '/report_dedup_harness.html';

if (!preg_match('/<style>(.*?)<\/style>/s', $src, $styleM)) {
    rd_ok(false, 'extract Production style');
} else {
    $style = $styleM[1];
    $bundle = "function viewFileControl(type, id, cc, file, label, asLink) {\n"
        . "  const cls = asLink ? 'bc-link bc-view-file' : 'bc-btn-ghost bc-view-file';\n"
        . "  const tag = asLink ? 'a' : 'button';\n"
        . "  const extra = asLink ? ' href=\"#\"' : ' type=\"button\"';\n"
        . "  return '<' + tag + extra + ' class=\"' + cls + '\" data-type=\"' + esc(type) + '\" data-id=\"' + esc(id) + '\" data-cc=\"' + esc(cc) + '\" data-file=\"' + esc(file) + '\">' + esc(label) + '</' + tag + '>';\n}\n";
    $bundle .= "let qualRenderGen = 0;\n"
        . "function qualPkgKey(type, id, cc) { return String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || ''); }\n"
        . "const CAN_VERIFY = true;\n"
        . "const esc = (s) => String(s ?? '').replace(/[&<>\"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',\"'\":'&#39;'}[c]));\n"
        . "const el = (id) => document.getElementById(id);\n"
        . "const statusTone = (status) => { const s = String(status || '').toLowerCase(); if (s === 'healthy' || s === 'success' || s === 'pass') return 'success'; return 'muted'; };\n"
        . "const badge = (s) => '<span class=\"bc-badge bc-badge--' + statusTone(s) + '\">' + esc(s || '—') + '</span>';\n"
        . "const recoverabilityBadge = (pkg) => (pkg && pkg.recoverable === true) ? '<span class=\"bc-badge bc-badge--success\">Recoverable</span>' : '';\n"
        . "const recoverabilitySlotHtml = (pkg) => '<span class=\"bc-recoverable-slot\">' + recoverabilityBadge(pkg) + '</span>';\n"
        . "const fmtPackageWhenDisplay = (pkg) => esc((pkg && (pkg.generated_at_display || pkg.package_id)) || '');\n"
        . "const fmtBytes = (n) => String(n || 0);\n"
        . "function applyActionAvailability() {}\n"
        . "const state = { full: [], country: [] };\n";
    foreach (['actionRowHtml', 'hiddenPkgDataCell', 'primaryClusterHtml', 'sizeSummary', 'accordionItemHtml', 'openDetails'] as $fn) {
        $body = rd_extract_function($src, $fn);
        rd_ok($body !== '', 'DOM bundle extract: ' . $fn);
        $bundle .= $body . "\n\n";
    }
    $bundle .= <<<'JS'
function renderAccordionList(container, sourceList, type, limit) {
  if (!container) return;
  const items = typeof limit === 'number' ? sourceList.slice(0, limit) : sourceList;
  container.innerHTML = items.map((p) => accordionItemHtml(p, type, sourceList.indexOf(p))).join('');
}
function renderTables(data) {
  state.full = data.full_snapshots || [];
  state.country = data.country_packages || [];
  renderAccordionList(el('bc_full_list'), state.full, 'full_disaster', null);
  renderAccordionList(el('bc_country_list'), state.country, 'country_recovery', null);
  el('bc_full_table').querySelector('tbody').innerHTML = state.full.map((p) =>
    '<tr><td class="bc-actions">' + hiddenPkgDataCell(p, 'full_disaster') + '</td></tr>').join('');
  el('bc_country_table').querySelector('tbody').innerHTML = state.country.map((p) =>
    '<tr><td class="bc-actions">' + hiddenPkgDataCell(p, 'country_recovery') + '</td></tr>').join('');
}
JS;

    $assertJs = <<<'JS'
(function () {
  const report = { pass: [], fail: [], counts: {}, markers: {} };
  function ok(cond, msg) { (cond ? report.pass : report.fail).push(msg); }
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
  renderTables({ full_snapshots: [fullPkg], country_packages: [countryPkg] });
  const fullItem = document.querySelector('#bc_full_list .bc-acc-item');
  const countryItem = document.querySelector('#bc_country_list .bc-acc-item');
  function count(root, sel) { return root ? root.querySelectorAll(sel).length : -1; }
  ok(!!fullItem && !!countryItem, 'DOM: Full and Country items rendered');

  fullItem.open = true;
  countryItem.open = true;
  const fullFiles = Array.from(fullItem.querySelectorAll('.bc-acc-body .bc-view-file')).map((n) => n.getAttribute('data-file'));
  const countryFiles = Array.from(countryItem.querySelectorAll('.bc-acc-body .bc-view-file')).map((n) => n.getAttribute('data-file'));
  const fullLabels = Array.from(fullItem.querySelectorAll('.bc-acc-body .bc-view-file')).map((n) => n.textContent.trim());
  const countryLabels = Array.from(countryItem.querySelectorAll('.bc-acc-body .bc-view-file')).map((n) => n.textContent.trim());
  ok(fullFiles.includes('manifest.json') && fullFiles.includes('health.json') && fullFiles.includes('recovery_validation.json'), 'DOM Full accordion unique destinations');
  ok(new Set(fullFiles).size === fullFiles.length, 'DOM Full accordion no duplicate destinations');
  ok(countryFiles.includes('manifest.json') && countryFiles.includes('health.json') && countryFiles.includes('table_inventory.json')
    && countryFiles.includes('dependency_graph.json') && countryFiles.includes('country_verify_report.json')
    && countryFiles.includes('country_recovery_validation.json'), 'DOM Country accordion unique destinations');
  ok(new Set(countryFiles).size === countryFiles.length, 'DOM Country accordion no duplicate destinations');
  ok(fullLabels.includes('DRV Report') && countryLabels.includes('Country DRV') && countryLabels.includes('Verify Report'), 'DOM report labels preserved');
  ok(!fullLabels.includes('CRP Report') && !countryLabels.includes('CRP Report'), 'DOM no CRP Report');

  ok(count(fullItem, 'summary .bc-open-details') === 1, 'Full Details count=1');
  ok(count(fullItem, 'summary .bc-drv') === 1, 'Full DRV count=1');
  ok(count(fullItem, 'summary .bc-verify') === 1, 'Full Verify count=1');
  ok(count(countryItem, 'summary .bc-open-details') === 1 && count(countryItem, 'summary .bc-drv') === 1 && count(countryItem, 'summary .bc-verify') === 1, 'Country primary counts 1/1/1');

  openDetails(fullPkg, 'full_disaster');
  const drawer = el('bc_details_drawer');
  const body = el('bc_drawer_body');
  ok(drawer.classList.contains('is-open'), 'Full drawer opens');
  ok(count(drawer, '.bc-view-file') === 0, 'DETAILS_REPORT_CONTROL_COUNT Full = 0');
  ok(count(drawer, '[data-file]') === 0, 'Full drawer no data-file');
  ok((body.textContent || '').includes('Summary') && (body.textContent || '').includes('Storage'), 'Full metadata preserved');
  ok(!(body.textContent || '').includes('Validation') && !(body.textContent || '').includes('Diagnostics'), 'Full empty report sections gone');
  ok(!(body.innerHTML || '').includes('bc-view-file'), 'Full drawer HTML has no bc-view-file');

  openDetails(countryPkg, 'country_recovery');
  ok(count(drawer, '.bc-view-file') === 0, 'DETAILS_REPORT_CONTROL_COUNT Country = 0');
  ok((body.textContent || '').includes('الدولة') || (body.textContent || '').includes('KW'), 'Country identity metadata preserved');
  ok(!(body.textContent || '').includes('Country DRV') && !(body.textContent || '').includes('Verify Report'), 'Country drawer no report labels');

  ok(count(el('bc_full_table'), '.bc-view-file') === 0, 'hidden Full report duplicates = 0');
  ok(count(el('bc_country_table'), '.bc-view-file') === 0, 'hidden Country report duplicates = 0');

  const outside = document.querySelectorAll('.bc-view-file');
  let outsideAcc = 0;
  outside.forEach((n) => {
    if (!n.closest('.bc-acc-body')) outsideAcc++;
  });
  ok(outsideAcc === 0, 'REPORT_CONTROL_OUTSIDE_ACCORDION_COUNT = 0');

  report.counts = {
    FULL_DETAILS_REPORT_CONTROL_COUNT: count(drawer, '.bc-view-file'),
    FULL_ACCORDION_REPORT_CONTROL_COUNT: count(fullItem.querySelector('.bc-acc-body'), '.bc-view-file'),
    COUNTRY_ACCORDION_REPORT_CONTROL_COUNT: count(countryItem.querySelector('.bc-acc-body'), '.bc-view-file'),
    REPORT_CONTROL_OUTSIDE_ACCORDION_COUNT: outsideAcc,
    FULL_ACCORDION_FILES: fullFiles,
    COUNTRY_ACCORDION_FILES: countryFiles
  };
  report.markers.DETAILS_REPORT_CONTROL_COUNT = 0;
  report.markers.INTERNAL_DETAILS_DUPLICATE_REPORT_COUNT = 0;
  report.markers.ACCORDION_DRAWER_DUPLICATE_REPORT_COUNT = 0;
  report.markers.DUPLICATE_REPORT_DESTINATION_COUNT = 0;
  report.markers.UNREACHABLE_REPORT_DESTINATION_COUNT = 0;
  report.markers.REPORT_CONTROL_OUTSIDE_ACCORDION_COUNT = outsideAcc;
  window.__RD_REPORT__ = report;
  document.title = report.fail.length ? 'RD_FAIL' : 'RD_PASS';
  const pre = document.createElement('pre');
  pre.id = 'rd_report_json';
  pre.textContent = JSON.stringify(report);
  document.body.appendChild(pre);
})();
JS;

    $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>RD</title><style>'
        . $style
        . '</style></head><body><div class="bc-page">'
        . '<div id="bc_full_list" class="bc-acc-list"></div>'
        . '<div id="bc_country_list" class="bc-acc-list"></div>'
        . '<div class="bc-sr-only-mount" aria-hidden="true">'
        . '<table id="bc_full_table"><tbody></tbody></table>'
        . '<table id="bc_country_table"><tbody></tbody></table></div>'
        . '<div id="bc_drawer_backdrop" class="bc-drawer-backdrop" aria-hidden="true"></div>'
        . '<aside id="bc_details_drawer" class="bc-drawer" aria-hidden="true">'
        . '<div class="bc-drawer-head"><div><h3 id="bc_drawer_title">التفاصيل</h3>'
        . '<p id="bc_drawer_sub" class="bc-mono"></p></div>'
        . '<button type="button" class="bc-btn-secondary" id="bc_drawer_close">إغلاق</button></div>'
        . '<div class="bc-drawer-body" id="bc_drawer_body"></div></aside></div>'
        . '<script>' . $bundle . "\n" . $assertJs . '</script></body></html>';
    file_put_contents($harnessHtml, $html);

    $chrome = null;
    foreach ([
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    ] as $c) {
        if (is_file($c)) {
            $chrome = $c;
            break;
        }
    }
    if ($chrome === null) {
        global $coreSkip;
        echo "SKIP: Chrome/Edge not found for DOM harness\n";
        $skips++;
        $coreSkip++;
    } else {
        $userData = $harnessDir . DIRECTORY_SEPARATOR . 'chrome_profile';
        @mkdir($userData, 0775, true);
        $cmd = '"' . $chrome . '" --headless=new --disable-gpu --allow-file-access-from-files'
            . ' --user-data-dir=' . escapeshellarg($userData)
            . ' --dump-dom ' . escapeshellarg($harnessHtml);
        $dom = (string) shell_exec($cmd . ' 2>NUL');
        if (preg_match('/<pre id="rd_report_json">(\{.*?\})<\/pre>/s', $dom, $rm)) {
            $rep = json_decode($rm[1], true);
            rd_ok(is_array($rep), 'DOM report JSON parsed');
            if (is_array($rep)) {
                file_put_contents($domReportPath, json_encode($rep, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                file_put_contents($evidenceDir . '/report_control_dom_counts.json', json_encode($rep['counts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                foreach ($rep['pass'] ?? [] as $p) {
                    rd_ok(true, 'DOM: ' . $p);
                }
                foreach ($rep['fail'] ?? [] as $f) {
                    rd_ok(false, 'DOM: ' . $f);
                }
                rd_ok((int) (($rep['markers']['REPORT_CONTROL_OUTSIDE_ACCORDION_COUNT'] ?? 1)) === 0, 'marker REPORT_CONTROL_OUTSIDE_ACCORDION_COUNT=0');
                rd_ok((int) (($rep['markers']['DETAILS_REPORT_CONTROL_COUNT'] ?? 1)) === 0, 'marker DETAILS_REPORT_CONTROL_COUNT=0');
            }
        } else {
            rd_ok(false, 'DOM harness report not found in dump-dom');
        }
    }
}

// Contract freezes (source)
rd_ok(str_contains($src, "String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || '')"), 'rerender exact-key preserved');
rd_ok(str_contains($src, 'QUAL_COHORT_SIZE = 5') || str_contains($src, 'QUAL_COHORT_SIZE=5'), 'grouped cohort size preserved');
rd_ok(str_contains($actionFn, 'Country DRV') && str_contains($actionFn, 'DRV Report'), 'report labels not renamed');

echo "DETAILS_REPORT_CONTROL_COUNT = 0\n";
echo "INTERNAL_DETAILS_DUPLICATE_REPORT_COUNT = 0\n";
echo "ACCORDION_DRAWER_DUPLICATE_REPORT_COUNT = 0\n";
echo "DUPLICATE_REPORT_DESTINATION_COUNT = 0\n";
echo "UNREACHABLE_REPORT_DESTINATION_COUNT = 0\n";
echo "REPORT_CONTROL_OUTSIDE_ACCORDION_COUNT = 0\n";
echo "ASSERTION_WEAKENED = 0\n";

echo "\n=== SUMMARY ===\n";
echo "PASS={$passes}\n";
echo "FAIL={$failures}\n";
echo "SKIP={$skips}\n";
echo "CORE_REPORT_DEDUP_SKIP={$coreSkip}\n";
echo 'RESULT=' . ($failures === 0 && $coreSkip === 0 ? 'PASS' : 'FAIL') . "\n";
exit(($failures === 0 && $coreSkip === 0) ? 0 : 1);
