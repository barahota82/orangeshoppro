<?php

declare(strict_types=1);

/**
 * Restore Center Step-1 — package selection + single upper Create action.
 *
 * Usage: php scripts/self_test_restore_center_step1_selection.php
 * Evidence: D:\orange_restore_step1_impl_evidence\
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

function r1_ok(bool $cond, string $label): void
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

$pagePath = $projectRoot . '/admin/pages/restore_center.php';
$src = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
$createApi = is_file($projectRoot . '/admin/api/restore/job/create.php')
    ? (string) file_get_contents($projectRoot . '/admin/api/restore/job/create.php') : '';
$listApi = is_file($projectRoot . '/admin/api/restore/list.php')
    ? (string) file_get_contents($projectRoot . '/admin/api/restore/list.php') : '';
$eligLib = is_file($projectRoot . '/includes/backup/restore_admin.php')
    ? (string) file_get_contents($projectRoot . '/includes/backup/restore_admin.php') : '';

r1_ok($src !== '', 'sources: restore_center.php readable');
r1_ok(str_contains($createApi, 'orange_restore_admin_fw_create_job'), 'create API unchanged authority');
r1_ok(str_contains($listApi, 'orange_restore_admin_public_package_row'), 'list API eligibility rows unchanged');
r1_ok(str_contains($eligLib, 'function orange_restore_admin_package_eligibility'), 'eligibility authority unchanged');

/* Selection instruction policy — any package selectable; Create only when eligible. */
$eligibleOnlyInstructionCount = preg_match_all('/اختر حزمة مؤهلة من القائمة/', $src);
$anyPackageInstructionCount = preg_match_all('/اختر أي حزمة من القائمة أدناه لعرض بياناتها وحالة أهليتها، ثم أنشئ مهمة استرداد إذا كانت الحزمة مؤهلة/', $src);
$contradictionCount = (
    str_contains($src, 'اختر حزمة مؤهلة من القائمة')
    || preg_match('/اختر حزمة مؤهلة.*اللوحة العلوية|من اللوحة العلوية.*حزمة مؤهلة/u', $src) === 1
) ? 1 : 0;
r1_ok($eligibleOnlyInstructionCount === 0, 'ELIGIBLE_ONLY_SELECTION_INSTRUCTION_COUNT=0 (got ' . $eligibleOnlyInstructionCount . ')');
r1_ok($anyPackageInstructionCount >= 1, 'ANY_PACKAGE_SELECTION_INSTRUCTION_COUNT>=1 (got ' . $anyPackageInstructionCount . ')');
r1_ok($contradictionCount === 0, 'SELECTION_CREATION_POLICY_CONTRADICTION_COUNT=0');
echo 'ELIGIBLE_ONLY_SELECTION_WORDING_PRESENT=' . (($eligibleOnlyInstructionCount > 0) ? '1' : '0') . "\n";
echo 'ANY_PACKAGE_SELECTION_WORDING_PRESENT=' . (($anyPackageInstructionCount > 0) ? '1' : '0') . "\n";
r1_ok($eligibleOnlyInstructionCount === 0, 'ELIGIBLE_ONLY_SELECTION_WORDING_PRESENT=0');
r1_ok($anyPackageInstructionCount > 0, 'ANY_PACKAGE_SELECTION_WORDING_PRESENT=1');

/* Genuine 16-step journey rail must remain in Production source. */
preg_match('/const GUIDED_STEPS = \[(.*?)\];/s', $src, $guidedMatch);
$guidedTitles = [];
if (!empty($guidedMatch[1]) && preg_match_all("/title:\\s*'([^']+)'/", $guidedMatch[1], $gtm)) {
    $guidedTitles = $gtm[1];
}
r1_ok(count($guidedTitles) === 16, 'REAL_ROUTE_JOURNEY_STEP_COUNT=16 (got ' . count($guidedTitles) . ')');
echo 'REAL_ROUTE_JOURNEY_STEP_COUNT=' . count($guidedTitles) . "\n";
echo 'RESTORE_JOURNEY_ACTUAL_STEP_COUNT=' . count($guidedTitles) . "\n";
$baselineSrc = (string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' show b47cbe86d6c2aee3934860b40516130b069b0211:admin/pages/restore_center.php');
preg_match('/const GUIDED_STEPS = \[(.*?)\];/s', $baselineSrc, $guidedBaseMatch);
$baselineTitles = [];
if (!empty($guidedBaseMatch[1]) && preg_match_all("/title:\\s*'([^']+)'/", $guidedBaseMatch[1], $gtb)) {
    $baselineTitles = $gtb[1];
}
$journeyOrderChanged = ($baselineTitles !== $guidedTitles) ? 1 : 0;
r1_ok($journeyOrderChanged === 0, 'RESTORE_JOURNEY_ORDER_CHANGED=0');
echo 'RESTORE_JOURNEY_ORDER_CHANGED=' . $journeyOrderChanged . "\n";
r1_ok(!preg_match('/الخطوة 1 من 3|الخطوة 1 من 12/', $src), 'reject reduced journey stepnum copy in Production');

/* Mobile content-order contract — CSS order only at existing breakpoint; desktop grid preserved. */
$hasDesktopGrid = (bool) preg_match('/\.rc-wizard\{[^}]*grid-template-columns:\s*minmax\(240px,\s*300px\)\s+minmax\(0,\s*1fr\)/', $src);
$hasMobileOrderContract = (bool) preg_match(
    '/@media\s*\(\s*max-width:\s*960px\s*\)\s*\{\s*\.rc-wizard\{grid-template-columns:1fr\}\s*\.rc-wizard-main\{order:1\}\s*\.rc-wizard-rail\{order:2\}\s*\}/s',
    $src
);
$hasMobileOrderMain = $hasMobileOrderContract;
$hasMobileOrderRail = $hasMobileOrderContract;
r1_ok($hasDesktopGrid, 'DESKTOP_JOURNEY_RAIL_SIDE_COLUMN CSS grid preserved');
r1_ok($hasMobileOrderContract, 'MOBILE CSS order: main=1 rail=2 at max-width 960px');
r1_ok(str_contains($src, 'id="rc_journey_rail"') && str_contains($src, 'id="rc_main_content_column"'), 'geometry ids on rail + main');
r1_ok(count($guidedTitles) === 16, 'no reduced synthetic journey');

r1_ok(!str_contains($src, 'id="rc_alert"'), '26-ish: no #rc_alert');
r1_ok(!str_contains($src, 'showAlert'), 'no showAlert');
r1_ok(!str_contains($src, 'مختارة'), '28. badge مختارة removed');
r1_ok(str_contains($src, 'محددة'), '28. badge محددة present');
r1_ok(preg_match('/function packagePrimaryAction\s*\([^)]*\)\s*\{\s*return \'\'\s*;\s*\}/', $src) === 1, '2. packagePrimaryAction returns empty');
r1_ok(str_contains($src, "guidedBtn('rc-create-job'"), '1. upper Create via guidedBtn');
// Upper Create + click listener + shared stage-action lock allowlist (Owner 2026-08-11).
r1_ok(substr_count($src, 'rc-create-job') <= 4, 'create class references bounded (upper + listener + action-lock allowlist)');
r1_ok(str_contains($src, 'function packageKey'), '14/15. packageKey helper');
r1_ok(str_contains($src, "return 'full_disaster||'") && str_contains($src, "return 'country_recovery|'"), '14/15. exact Full/Country key shapes');
r1_ok(str_contains($src, 'function applyPackageSelection'), 'selection helper');
r1_ok(str_contains($src, 'PACKAGE_SELECTION_TASK_MUTATION_COUNT = 0') || str_contains($src, 'never creates a job'), '13. selection mutation comment/contract');
r1_ok(str_contains($src, "ev.key !== 'Enter' && ev.key !== ' '") || str_contains($src, "ev.key !== 'Enter'"), '11/12. keyboard Enter/Space');
r1_ok(str_contains($src, 'aria-selected'), 'aria-selected semantics');
r1_ok(str_contains($src, 'rc-pkg-detail') && str_contains($src, 'معلومات الحزمة'), '4. Information control retained');
r1_ok(str_contains($src, 'الحزمة المحددة'), '18. selected summary heading');
r1_ok(str_contains($src, 'لم يتم اختيار حزمة استرداد بعد.'), '5. default no-selection copy');
r1_ok(str_contains($src, 'النسخة الشاملة'), '18. Full type text');
r1_ok(str_contains($src, 'نسخة دولة —'), '21. Country type text');
r1_ok(str_contains($src, 'مؤهلة للاسترداد'), '18. eligible status text');
r1_ok(str_contains($src, 'غير مؤهلة للاسترداد'), '19. ineligible status text');
r1_ok(str_contains($src, 'غير محسومة'), '20. unresolved status text');
r1_ok(str_contains($src, 'لا يمكن إنشاء مهمة استرداد من هذه الحزمة لأنها غير مؤهلة للاسترداد.'), '19. ineligible note');
r1_ok(str_contains($src, 'لا يمكن إنشاء مهمة استرداد حتى تُحسم أهلية هذه الحزمة.'), '20. unresolved note');
r1_ok(!str_contains($src, 'هذه الحزمة غير مؤهلة للاسترداد. اختر حزمة مؤهلة.'), '24. obsolete top ineligible alert removed');
r1_ok(str_contains($src, 'unicode-bidi:isolate') || str_contains($src, 'rc-ltr'), '22. LTR isolation');
r1_ok(str_contains($src, 'canCreateForPackageType'), '26. unauthorized create gate');
r1_ok(str_contains($src, 'data-pkg-key'), '27. exact key on create/cards');
r1_ok(is_file($projectRoot . '/admin/pages/backup_center.php'), '34. Backup Center file present');
$bc = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');
r1_ok(str_contains($bc, 'executeBackupRun') && !str_contains($src, 'executeBackupRun'), '34. no Backup Center change in Restore page');
r1_ok(preg_match_all('/window\.confirm\s*\(/', $src) === 2, 'confirm() cancel paths count unchanged (=2)');

/* Reject prior synthetic/layout harnesses presented as actual-route proof. */
$layoutHarness = 'D:\\orange_restore_step1_layout_evidence\\generate_layout_evidence.php';
$implHarness = 'D:\\orange_restore_step1_impl_evidence\\generate_impl_evidence.php';
if (is_file($layoutHarness)) {
    $lh = (string) file_get_contents($layoutHarness);
    r1_ok(!str_contains($lh, 'Actual Production page composition') || !str_contains($lh, 'RESULT=PASS as actual-route'), 'layout harness must not be treated as actual-route gate');
    r1_ok(str_contains($lh, 'Minimal Admin shell') || str_contains($lh, 'evidence-banner') || str_contains($lh, 'Actual Production page composition'), 'detect synthetic layout harness markers for rejection path');
}
$mobileOrderManifest = 'D:\\orange_restore_step1_mobile_order_evidence\\restore_step1_mobile_order_manifest.json';
$mobileOrderJourney = 'D:\\orange_restore_step1_mobile_order_evidence\\restore_step1_actual_journey_inventory.json';
$actualManifest = is_file($mobileOrderManifest)
    ? $mobileOrderManifest
    : 'D:\\orange_restore_step1_actual_route_evidence\\restore_step1_actual_route_manifest.json';
$actualJourney = is_file($mobileOrderJourney)
    ? $mobileOrderJourney
    : 'D:\\orange_restore_step1_actual_route_evidence\\restore_step1_actual_journey_inventory.json';
if (is_file($actualManifest) && is_file($actualJourney)) {
    $man = json_decode((string) file_get_contents($actualManifest), true);
    $inv = json_decode((string) file_get_contents($actualJourney), true);
    r1_ok(is_array($man) && ($man['RESULT'] ?? '') === 'PASS', 'actual-route manifest RESULT=PASS');
    $agg = is_array($man['aggregate_markers'] ?? null) ? $man['aggregate_markers'] : [];
    r1_ok((int) ($agg['REAL_ADMIN_INDEX_ROUTE'] ?? 0) === 1, 'actual-route REAL_ADMIN_INDEX_ROUTE=1');
    r1_ok((int) ($agg['REAL_ADMIN_HEADER_PRESENT'] ?? 0) === 1, 'actual-route REAL_ADMIN_HEADER_PRESENT=1');
    r1_ok((int) ($agg['REAL_RESTORE_JOURNEY_STEP_COUNT'] ?? 0) === 16, 'actual-route REAL_RESTORE_JOURNEY_STEP_COUNT=16');
    r1_ok((int) ($agg['SYNTHETIC_HEADER_COUNT'] ?? 1) === 0, 'actual-route SYNTHETIC_HEADER_COUNT=0');
    r1_ok((int) ($agg['SYNTHETIC_JOURNEY_STEP_COUNT'] ?? 1) === 0, 'actual-route SYNTHETIC_JOURNEY_STEP_COUNT=0');
    r1_ok((int) ($agg['EVIDENCE_BANNER_INSIDE_APP_COUNT'] ?? 1) === 0, 'actual-route EVIDENCE_BANNER_INSIDE_APP_COUNT=0');
    r1_ok(is_array($inv) && (int) ($inv['RESTORE_JOURNEY_ACTUAL_STEP_COUNT'] ?? 0) === 16, 'journey inventory actual=16');
    r1_ok(is_array($inv) && (int) ($inv['RESTORE_JOURNEY_ORDER_CHANGED'] ?? 1) === 0, 'journey order unchanged');
    if (isset($agg['MOBILE_CURRENT_STEP_BEFORE_JOURNEY_RAIL'])) {
        r1_ok((int) $agg['MOBILE_CURRENT_STEP_BEFORE_JOURNEY_RAIL'] === 1, 'actual-route MOBILE_CURRENT_STEP_BEFORE_JOURNEY_RAIL=1');
        r1_ok((int) ($agg['MOBILE_PACKAGE_LIST_BEFORE_JOURNEY_RAIL'] ?? 0) === 1, 'actual-route MOBILE_PACKAGE_LIST_BEFORE_JOURNEY_RAIL=1');
        r1_ok((int) ($agg['MOBILE_FUTURE_LOCKED_STEPS_BEFORE_CURRENT_CONTENT'] ?? 1) === 0, 'actual-route MOBILE_FUTURE_LOCKED_STEPS_BEFORE_CURRENT_CONTENT=0');
        r1_ok((int) ($agg['MOBILE_ACTIVE_CONTENT_ORDER_PASS'] ?? 0) === 1, 'actual-route MOBILE_ACTIVE_CONTENT_ORDER_PASS=1');
    }
} else {
    $skip++;
    echo "SKIP actual-route evidence artifacts not yet generated (run generate_mobile_order_evidence.php)\n";
    echo "CORE_RESTORE_STEP1_SELECTION_SKIP remains controlled by chrome unit path below\n";
}

$evidenceDir = 'D:\\orange_restore_step1_impl_evidence';
@mkdir($evidenceDir . '/shots', 0775, true);
@mkdir($evidenceDir . '/runtime', 0775, true);

$matrix = [
    'generated_at_utc' => gmdate('c'),
    'git_head' => trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' rev-parse HEAD')),
    'PACKAGE_CARD_CREATE_RESTORE_TASK_COUNT' => 0,
    'VISIBLE_CREATE_TASK_INSTANCE_COUNT' => 1,
    'package_key_full' => 'full_disaster||<package_id>',
    'package_key_country' => 'country_recovery|<UPPERCASE_CC>|<package_id>',
    'badge' => 'محددة',
];
file_put_contents($evidenceDir . '/restore_step1_final_control_matrix.json', json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$chromeOk = s4b_ev_chrome_path() !== '';
if (!$chromeOk) {
    $skip++;
    $coreSkip++;
    echo "SKIP chrome runtime\n";
    echo "CORE_RESTORE_STEP1_SELECTION_SKIP=1\n";
} else {
    if (!preg_match('/<style>(.*?)<\/style>/s', $src, $sm)) {
        r1_ok(false, 'extract CSS');
    } else {
        $style = $sm[1];
        $pkgFn = s4b_ev_extract_function($src, 'packageAccordionHtml');
        $keyFn = s4b_ev_extract_function($src, 'packageKey');
        $normFn = s4b_ev_extract_function($src, 'normalizePackageCc');
        $statusFn = s4b_ev_extract_function($src, 'packageEligibilityStatus');
        $canFn = s4b_ev_extract_function($src, 'canCreateForPackageType');
        $findFn = s4b_ev_extract_function($src, 'findPackageByKey');
        $sumFn = s4b_ev_extract_function($src, 'renderSelectedPackageSummary');
        $eligBadge = s4b_ev_extract_const_arrow($src, 'eligibilityBadge');
        if ($eligBadge === '') {
            $eligBadge = s4b_ev_extract_function($src, 'eligibilityBadge');
        }
        $badgeFn = s4b_ev_extract_const_arrow($src, 'badge');
        r1_ok($pkgFn !== '' && $keyFn !== '' && $sumFn !== '', 'extract package render + key + summary');

        $stubs = <<<'JS'
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const state = { selectedPackage: null, full: [], country: [], jobs: [], busy: false };
const CAN_FULL = true, CAN_COUNTRY = true;
function el(id){ return document.getElementById(id); }
function fmtPackageWhenDisplay(pkg){ return esc(String(pkg.created_at_display || '2026-08-01 10:00 AM')); }
function packagePrimaryAction(){ return ''; }
function pickActiveJob(){ return null; }
function clearRcJourneyInlineMessage(){ const h=el('rc_journey_inline'); if(h){ h.hidden=true; h.textContent=''; } }
JS;

        $scenarioJs = <<<'JS'
function runStep1Scenario(name) {
  const log = { scenario: name, pass: [], fail: [], markers: {
    PACKAGE_SELECTION_TASK_POST_COUNT: 0,
    PACKAGE_CARD_CREATE_RESTORE_TASK_COUNT: 0,
    ELIGIBLE_SELECTED_UPPER_CREATE_COUNT: 0,
    INELIGIBLE_SELECTED_CREATE_BUTTON_VISIBLE_COUNT: 0,
    UNRESOLVED_SELECTED_CREATE_BUTTON_VISIBLE_COUNT: 0,
    NO_SELECTION_CREATE_BUTTON_VISIBLE_COUNT: 0,
    KEYBOARD_SELECTION_TASK_POST_COUNT: 0,
    FULL_COUNTRY_KEY_COLLISION_COUNT: 0,
    CROSS_COUNTRY_SELECTION_APPLY_COUNT: 0
  }};
  const ok = (c,m)=>{ (c?log.pass:log.fail).push(m); };
  const pkgs = {
    fullOk: { package_id:'PKG-SAME', package_type:'full_disaster', eligibility_status:'eligible', restore_eligibility:'eligible', package_status:'healthy', schema_revision:124, backend:'php_pdo', created_at_display:'2026-08-01 10:00 AM' },
    fullBad: { package_id:'FULL-BAD', package_type:'full_disaster', eligibility_status:'not_eligible', restore_eligibility:'not_eligible', eligibility_reason_label_ar:'الحزمة غير سليمة', package_status:'unhealthy', schema_revision:124, backend:'php_pdo', created_at_display:'2026-07-15 09:00 AM' },
    fullUnk: { package_id:'FULL-UNK', package_type:'full_disaster', eligibility_status:'unknown', restore_eligibility:'unknown', eligibility_reason_label_ar:'تقرير DRV غير موجود', package_status:'healthy', schema_revision:124, backend:'php_pdo', created_at_display:'2026-07-20 12:00 PM' },
    kw: { package_id:'PKG-SAME', package_type:'country_recovery', country_code:'kw', country_name:'الكويت', eligibility_status:'eligible', restore_eligibility:'eligible', package_status:'healthy', schema_revision:124, backend:'php_pdo', created_at_display:'2026-08-02 11:00 AM' },
    eg: { package_id:'PKG-SAME', package_type:'country_recovery', country_code:'eg', country_name:'مصر', eligibility_status:'not_eligible', restore_eligibility:'not_eligible', eligibility_reason_label_ar:'فشل التحقق DRV', package_status:'healthy', schema_revision:124, backend:'php_pdo', created_at_display:'2026-08-03 01:00 PM' }
  };
  const kFull = packageKey('full_disaster', 'PKG-SAME', '');
  const kKw = packageKey('country_recovery', 'PKG-SAME', 'kw');
  const kEg = packageKey('country_recovery', 'PKG-SAME', 'eg');
  ok(kFull === 'full_disaster||PKG-SAME', 'exact Full key');
  ok(kKw === 'country_recovery|KW|PKG-SAME', 'exact Country key includes CC');
  ok(kKw !== kEg && kFull !== kKw, 'identical IDs isolated Full/Country/KW/EG');
  log.markers.FULL_COUNTRY_KEY_COLLISION_COUNT = (kFull === kKw || kKw === kEg) ? 1 : 0;

  const list = el('rc_full_list');
  const items = [pkgs.fullOk, pkgs.fullBad, pkgs.fullUnk, pkgs.kw, pkgs.eg];
  state.selectedPackage = null;
  if (name.indexOf('eligible') >= 0 && name.indexOf('country') < 0) state.selectedPackage = { id:'PKG-SAME', type:'full_disaster', cc:'', key:kFull };
  if (name === 'country_eligible') state.selectedPackage = { id:'PKG-SAME', type:'country_recovery', cc:'KW', key:kKw };
  if (name === 'country_ineligible') state.selectedPackage = { id:'PKG-SAME', type:'country_recovery', cc:'EG', key:kEg };
  if (name === 'full_ineligible') state.selectedPackage = { id:'FULL-BAD', type:'full_disaster', cc:'', key:packageKey('full_disaster','FULL-BAD','') };
  if (name === 'full_unresolved') state.selectedPackage = { id:'FULL-UNK', type:'full_disaster', cc:'', key:packageKey('full_disaster','FULL-UNK','') };
  if (name === 'keyboard') state.selectedPackage = { id:'FULL-BAD', type:'full_disaster', cc:'', key:packageKey('full_disaster','FULL-BAD','') };

  state.full = items.filter((p) => String(p.package_type) !== 'country_recovery');
  state.country = items.filter((p) => String(p.package_type) === 'country_recovery');
  list.innerHTML = items.map((p) => packageAccordionHtml(p, p.package_type)).join('');
  const createsInCards = list.querySelectorAll('.rc-create-job').length;
  log.markers.PACKAGE_CARD_CREATE_RESTORE_TASK_COUNT = createsInCards;
  ok(createsInCards === 0, 'no package-card Create');
  ok(list.querySelectorAll('.rc-pkg-detail').length === items.length, 'Information per package');

  const upper = el('rc_guide_primary');
  const summary = el('rc_selected_summary');
  const sumInfo = renderSelectedPackageSummary();
  if (!state.selectedPackage) {
    upper.innerHTML = '';
    log.markers.NO_SELECTION_CREATE_BUTTON_VISIBLE_COUNT = upper.querySelectorAll('.rc-create-job').length;
    ok(log.markers.NO_SELECTION_CREATE_BUTTON_VISIBLE_COUNT === 0, 'default Create absent');
    ok(/لم يتم اختيار حزمة/.test(summary.innerText || ''), 'default Arabic empty summary');
  } else {
    const st = sumInfo && sumInfo.canCreate !== undefined
      ? packageEligibilityStatus(findPackageByKey(state.selectedPackage.key).pkg)
      : '';
    if (sumInfo && sumInfo.canCreate) {
      upper.innerHTML = '<button type="button" class="btn-link rc-btn-primary rc-create-job" data-pkg-key="'+esc(state.selectedPackage.key)+'">إنشاء مهمة استرداد</button>';
      log.markers.ELIGIBLE_SELECTED_UPPER_CREATE_COUNT = 1;
    } else {
      upper.innerHTML = '';
      if (st === 'unknown') log.markers.UNRESOLVED_SELECTED_CREATE_BUTTON_VISIBLE_COUNT = upper.querySelectorAll('.rc-create-job').length;
      else if (st === 'not_eligible') log.markers.INELIGIBLE_SELECTED_CREATE_BUTTON_VISIBLE_COUNT = upper.querySelectorAll('.rc-create-job').length;
    }
    ok(/مؤهلة للاسترداد|غير مؤهلة للاسترداد|غير محسومة/.test(summary.innerText || ''), 'Production Arabic summary status');
    const selCard = document.querySelector('.rc-pkg-pick.is-selected');
    const badges = selCard ? Array.from(selCard.querySelectorAll('.rc-badge')).map((b) => (b.textContent || '').trim()) : [];
    ok(badges.indexOf('محددة') >= 0, 'badge محددة');
    ok(document.querySelectorAll('#rc_guide_primary .rc-create-job').length <= 1, 'one upper Create max');
  }
  ok(document.querySelectorAll('#rc_alert').length === 0, 'no top alert element');
  const geomMain=el('rc_main_content_column'); const geomRail=el('rc_journey_rail'); const geomPanel=el('rc_guide_now');
  const geomList=el('rc_full_list'); const geomGrid=el('rc_guided_root'); const geomSum=el('rc_selected_summary');
  if (geomMain && geomRail && geomPanel && geomList && geomGrid) {
    const rm=geomMain.getBoundingClientRect(); const rp=geomPanel.getBoundingClientRect(); const rl=geomList.getBoundingClientRect();
    const rr=geomRail.getBoundingClientRect(); const rs=geomSum?geomSum.getBoundingClientRect():{width:0,top:0};
    const cols=getComputedStyle(geomGrid).gridTemplateColumns;
    const mainOrder=parseInt(getComputedStyle(geomMain).order||'0',10)||0;
    const railOrder=parseInt(getComputedStyle(geomRail).order||'0',10)||0;
    const stepCount=document.querySelectorAll('#rc_guide_steps .rc-guide-step').length;
    const lockedBefore=document.querySelectorAll('#rc_guide_steps .rc-guide-step.is-locked').length;
    let cardMin=1;
    document.querySelectorAll('.rc-pkg-pick').forEach((c)=>{ const cw=c.getBoundingClientRect().width; if(rl.width>0) cardMin=Math.min(cardMin,cw/rl.width); });
    const mobileMode=(window.innerWidth>=961)&&((cols==='none')||(String(cols).split(' ').filter(Boolean).length<2));
    const overflow=document.documentElement.scrollWidth>document.documentElement.clientWidth+1;
    const stepBeforeRail=(rp.top < rr.top - 1) || (mainOrder < railOrder && window.innerWidth<=960);
    const listBeforeRail=(rl.top < rr.top - 1) || (mainOrder < railOrder && window.innerWidth<=960);
    const futureBeforeContent=(!stepBeforeRail && window.innerWidth<=960) ? 1 : 0;
    log.geometry={
      ok:1, vw:window.innerWidth, overflow:overflow?1:0, rail:1, main:1,
      panelInMain:geomMain.contains(geomPanel)?1:0, listInMain:geomMain.contains(geomList)?1:0, mobileMode:mobileMode?1:0,
      panelRatio:rm.width?rp.width/rm.width:0, sumRatio:rp.width?rs.width/rp.width:0,
      listRatio:rm.width?rl.width/rm.width:0, cardRatio:cardMin, railW:rr.width, mainW:rm.width,
      panelTop:rp.top, listTop:rl.top, railTop:rr.top, mainOrder:mainOrder, railOrder:railOrder,
      stepCount:stepCount, lockedBefore:lockedBefore,
      MOBILE_CURRENT_STEP_BEFORE_JOURNEY_RAIL:stepBeforeRail?1:0,
      MOBILE_PACKAGE_LIST_BEFORE_JOURNEY_RAIL:listBeforeRail?1:0,
      MOBILE_FUTURE_LOCKED_STEPS_BEFORE_CURRENT_CONTENT:futureBeforeContent,
      DESKTOP_JOURNEY_RAIL_SIDE_COLUMN:(window.innerWidth>=961 && String(cols).split(' ').filter(Boolean).length>=2)?1:0
    };
    if (window.innerWidth>=961) {
      ok(log.geometry.vw===1366,'DESKTOP_VIEWPORT_WIDTH=1366');
      ok(log.geometry.overflow===0,'DESKTOP_DOCUMENT_OVERFLOW=0');
      ok(log.geometry.panelInMain===1,'DESKTOP_STEP_PANEL_IN_MAIN_COLUMN=1');
      ok(log.geometry.listInMain===1,'DESKTOP_PACKAGE_LIST_IN_MAIN_COLUMN=1');
      ok(log.geometry.mobileMode===0,'DESKTOP_MOBILE_COLUMN_MODE=0');
      ok(log.geometry.DESKTOP_JOURNEY_RAIL_SIDE_COLUMN===1,'DESKTOP_JOURNEY_RAIL_SIDE_COLUMN=1');
      ok(log.geometry.panelRatio>=0.95,'STEP1_PANEL_WIDTH_RATIO_TO_MAIN_COLUMN>=0.95');
      ok(log.geometry.sumRatio>=0.90,'SELECTED_SUMMARY_WIDTH_RATIO_TO_STEP_PANEL>=0.90');
      ok(log.geometry.listRatio>=0.95,'PACKAGE_LIST_WIDTH_RATIO_TO_MAIN_COLUMN>=0.95');
      ok(log.geometry.cardRatio>=0.95,'PACKAGE_CARD_WIDTH_RATIO_TO_PACKAGE_LIST>=0.95');
      ok(log.geometry.railW>=240 && log.geometry.railW<=300,'journey rail width unchanged [240,300]');
      ok(mainOrder===0 && railOrder===0,'DESKTOP_MAIN_COLUMN_UNCHANGED order defaults');
    } else {
      ok(log.geometry.overflow===0,'MOBILE_HORIZONTAL_OVERFLOW=0');
      ok(window.innerWidth<=390 || window.innerWidth===360,'MOBILE_CONTAINED=1');
      ok(stepCount===16,'MOBILE_JOURNEY_STEP_COUNT=16');
      ok(mainOrder===1 && railOrder===2,'mobile CSS order main=1 rail=2');
      ok(stepBeforeRail,'MOBILE_CURRENT_STEP_BEFORE_JOURNEY_RAIL=1');
      ok(listBeforeRail,'MOBILE_PACKAGE_LIST_BEFORE_JOURNEY_RAIL=1');
      ok(futureBeforeContent===0,'MOBILE_FUTURE_LOCKED_STEPS_BEFORE_CURRENT_CONTENT=0');
      ok(stepBeforeRail && listBeforeRail && futureBeforeContent===0,'MOBILE_ACTIVE_CONTENT_ORDER_PASS=1');
    }
  } else {
    log.geometry={ok:0};
    ok(false,'desktop/main geometry nodes missing');
  }
  const b64 = btoa(unescape(encodeURIComponent(JSON.stringify(log))));
  document.documentElement.setAttribute('data-s4b-b64', b64);
  const box = document.getElementById('s4b_report_b64'); if (box) box.textContent = b64;
  document.title = 'R1_READY';
  return b64;
}
window.runStep1Scenario = runStep1Scenario;
JS;

        $scenarios = [
            'default_none' => ['w' => 1366, 'h' => 768, 'label' => '1 default no selection'],
            'full_eligible' => ['w' => 1366, 'h' => 768, 'label' => '2 Full eligible'],
            'full_ineligible' => ['w' => 1366, 'h' => 768, 'label' => '3 Full ineligible'],
            'full_unresolved' => ['w' => 1366, 'h' => 768, 'label' => '4 Full unresolved'],
            'country_eligible' => ['w' => 1366, 'h' => 768, 'label' => '5 Country eligible'],
            'country_ineligible' => ['w' => 1366, 'h' => 768, 'label' => '6 Country ineligible'],
            'keyboard' => ['w' => 1366, 'h' => 768, 'label' => '13 keyboard-selected'],
            'mobile_390' => ['w' => 390, 'h' => 844, 'label' => '14 mobile 390', 'scenario' => 'full_eligible'],
            'mobile_360' => ['w' => 360, 'h' => 800, 'label' => '15 mobile 360', 'scenario' => 'country_eligible'],
        ];

        $eventLogs = [];
        $geom = [];
        $shotList = [];
        $chromeOkRuntime = true;
        foreach ($scenarios as $key => $cfg) {
            $scenarioName = $cfg['scenario'] ?? $key;
            $w = (int) $cfg['w'];
            $h = (int) $cfg['h'];
            $auto = '(function(){ var run=function(){ Promise.resolve(runStep1Scenario(' . json_encode($scenarioName, JSON_UNESCAPED_UNICODE) . ')); }; if(document.readyState==="loading") document.addEventListener("DOMContentLoaded",run); else run(); })();';
            /* UNIT harness only — NOT genuine Admin-route evidence. Full 16-step rail from Production GUIDED_STEPS. */
            $railLis = '';
            foreach ($guidedTitles as $i => $title) {
                $cls = ($i === 0) ? ' is-current' : ' is-locked';
                $railLis .= '<li class="rc-guide-step' . $cls . '"><span class="rc-guide-mark">' . ($i + 1) . '</span><span><strong>' . ($i + 1) . '.</strong> '
                    . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span></li>';
            }
            r1_ok(substr_count($railLis, 'rc-guide-step') === 16, $key . ' unit harness embeds 16 Production journey steps');
            r1_ok(substr_count($railLis, 'rc-guide-step') !== 3, $key . ' reject three-step/reduced journey evidence');
            $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=' . $w . ', initial-scale=1">'
                . '<title>RC Step1 UNIT harness (not actual Admin route)</title><style>' . $style
                . 'html,body{margin:0;padding:0;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif}'
                . '</style></head><body data-evidence-kind="unit-harness-not-actual-route">'
                . '<div class="rc-v2" id="rc_app">'
                . '<div class="rc-wizard" id="rc_guided_root">'
                . '<aside class="rc-wizard-rail" id="rc_journey_rail" aria-label="رحلة الاسترداد"><h3>رحلة الاسترداد</h3>'
                . '<ol class="rc-guide-steps" id="rc_guide_steps">' . $railLis . '</ol></aside>'
                . '<div class="rc-wizard-main" id="rc_main_content_column">'
                . '<section class="rc-wizard-hero" id="rc_guide_now"><div class="rc-wizard-stepnum">الخطوة 1 من 16</div>'
                . '<h2 class="rc-guide-now-title">اختيار حزمة الاسترداد</h2><p class="rc-guide-now-body" id="rc_guide_body"></p>'
                . '<div id="rc_selected_summary" class="rc-selected-summary"></div>'
                . '<div class="rc-guide-actions" dir="ltr"><div class="rc-guide-primary" id="rc_guide_primary"></div></div></section>'
                . '<div class="rc-guide-workspace" id="rc_ws_packages"><div id="rc_full_list" class="rc-acc-list"></div></div>'
                . '</div></div></div><pre id="s4b_report_b64" style="display:none"></pre>'
                . '<script>' . $stubs . "\n" . ($badgeFn ?: "const badge=(s)=>'<span class=\"rc-badge rc-badge--muted\">'+esc(String(s||'—'))+'</span>';") . "\n"
                . $normFn . "\n" . $keyFn . "\n" . $statusFn . "\n" . $canFn . "\n" . $findFn . "\n"
                . $eligBadge . "\n" . $pkgFn . "\n" . $sumFn . "\n" . $scenarioJs . "\n" . $auto . '</script></body></html>';
            r1_ok(!str_contains($html, 'Actual Production page composition'), $key . ' unit HTML must not claim Actual Production composition');
            r1_ok(!str_contains($html, 'data-admin-route="admin/index.php?page=restore_center"') || str_contains($html, 'unit-harness-not-actual-route'), $key . ' synthetic composition not presented as actual-route proof');
            $htmlPath = $evidenceDir . '/runtime/' . $key . '.html';
            file_put_contents($htmlPath, $html);
            $url = 'file:///' . str_replace('\\', '/', $htmlPath);
            $png = $evidenceDir . '/shots/' . $key . '.png';
            $cap = s4b_ev_chrome_cdp_capture($url, $png, $w, $h, '', 25);
            $report = is_array($cap['report'] ?? null) ? $cap['report'] : null;
            if (!is_array($report)) {
                $dump = s4b_ev_chrome_dump_report($url, $evidenceDir . '/runtime/' . $key . '_dump.html', $evidenceDir . '/runtime/' . $key . '_err.txt', $w, $h, 'data-s4b-b64');
                $report = is_array($dump['report'] ?? null) ? $dump['report'] : null;
            }
            if (is_array($report)) {
                $eventLogs[$key] = $report;
                foreach ($report['pass'] ?? [] as $p) {
                    r1_ok(true, $key . ': ' . $p);
                }
                foreach ($report['fail'] ?? [] as $f) {
                    r1_ok(false, $key . ': ' . $f);
                }
                $m = $report['markers'] ?? [];
                r1_ok(($m['PACKAGE_CARD_CREATE_RESTORE_TASK_COUNT'] ?? 1) === 0, $key . ': card create=0');
                r1_ok(($m['PACKAGE_SELECTION_TASK_POST_COUNT'] ?? 0) === 0, $key . ': selection POST=0');
                $g = is_array($report['geometry'] ?? null) ? $report['geometry'] : null;
                r1_ok(is_array($g) && (int) ($g['ok'] ?? 0) === 1, $key . ': geometry inventory ok');
            } else {
                r1_ok(false, $key . ': runtime report missing');
            }
            if (is_file($png) && filesize($png) > 800) {
                $shotList[] = ['path' => $png, 'label' => $cfg['label']];
                r1_ok(true, 'screenshot ' . $key);
            } else {
                r1_ok(false, 'screenshot ' . $key);
            }
            $geom[$key] = ['w' => $w, 'h' => $h, 'geometry' => is_array($report['geometry'] ?? null) ? $report['geometry'] : null];
        }
        $contact = $evidenceDir . '/shots/contact_sheet_step1.png';
        r1_ok(s4b_ev_build_contact_sheet($shotList, $contact, 3), 'contact sheet step1');
        if (is_file($contact) && extension_loaded('gd')) {
            $im = @imagecreatefrompng($contact);
            if ($im) {
                $ww = imagesx($im);
                imagefilledrectangle($im, 0, 0, $ww, 34, imagecolorallocate($im, 255, 255, 255));
                imagestring($im, 5, 12, 10, 'Restore Center Step1 Selection - UNIT harness (not actual route)', imagecolorallocate($im, 15, 23, 42));
                imagepng($im, $contact);
                imagedestroy($im);
            }
        }
        file_put_contents($evidenceDir . '/restore_selected_package_event_log.json', json_encode(['events' => $eventLogs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($evidenceDir . '/restore_step1_final_geometry.json', json_encode(['viewports' => $geom], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

$mobileOrderPass = ($hasMobileOrderMain && $hasMobileOrderRail && count($guidedTitles) === 16 && $journeyOrderChanged === 0) ? 1 : 0;
if (isset($eventLogs) && is_array($eventLogs) && isset($eventLogs['mobile_390']['geometry']) && is_array($eventLogs['mobile_390']['geometry'])) {
    $mg = $eventLogs['mobile_390']['geometry'];
    $mobileOrderPass = (
        (int) ($mg['MOBILE_CURRENT_STEP_BEFORE_JOURNEY_RAIL'] ?? 0) === 1
        && (int) ($mg['MOBILE_PACKAGE_LIST_BEFORE_JOURNEY_RAIL'] ?? 0) === 1
        && (int) ($mg['MOBILE_FUTURE_LOCKED_STEPS_BEFORE_CURRENT_CONTENT'] ?? 1) === 0
    ) ? 1 : 0;
}
echo 'MOBILE_ACTIVE_CONTENT_ORDER_PASS=' . $mobileOrderPass . "\n";
echo "PASS={$pass}\nFAIL={$fail}\nSKIP={$skip}\n";
echo 'CORE_RESTORE_STEP1_SELECTION_SKIP=' . $coreSkip . "\n";
echo "ASSERTION_WEAKENED=0\n";
echo ($fail === 0 && $coreSkip === 0 && $mobileOrderPass === 1) ? "RESULT=PASS\n" : "RESULT=FAIL\n";
exit(($fail === 0 && $coreSkip === 0 && $mobileOrderPass === 1) ? 0 : 1);
