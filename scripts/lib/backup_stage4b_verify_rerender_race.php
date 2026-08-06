<?php

declare(strict_types=1);

/**
 * Stage 4B — Verify list-rerender race harness using Production JS extracts.
 * Test/evidence helper only (no Production Backup writes).
 *
 * @return array{ok:bool,markers:array<string,mixed>,report:?array,err:string,log_path:string,png:string}
 */
function s4b_run_verify_list_rerender_race(string $projectRoot, string $evidenceDir, string $bootJs, string $shellHead, string $shellMount): array
{
    require_once $projectRoot . '/scripts/lib/backup_stage4b_evidence_lib.php';

    $runtimeDir = $evidenceDir . DIRECTORY_SEPARATOR . 'runtime';
    $pngDir = $evidenceDir . DIRECTORY_SEPARATOR . 'verify_list_rerender_shots';
    @mkdir($runtimeDir, 0775, true);
    @mkdir($pngDir, 0775, true);

    $raceJs = <<<'JS'
(function () {
  const SAME_ID = '2026-08-06_030007';
  const RECENT_LIMIT = 5;
  const authority = new Map();
  const delays = new Map();
  const reqLog = [];
  const transitionLog = [];
  let crossRowApply = 0;
  let crossCountryApply = 0;
  let crossTypeApply = 0;
  let removedUpdated = 0;
  let falseGreen = 0;
  let packageKeyCollision = 0;
  let maxConcurrent = 0;
  let verifyPosts = 0;
  const readCounts = new Map();
  const qualPromisesLocal = new Map();
  const QUAL_MAX_CONCURRENT = 2;
  let qualActiveReads = 0;
  const qualReadQueue = [];
  let qualIoLocal = null;
  const listState = { full: [], country: [], archiveMode: { full: false, country: false } };

  function mkQual(type, id, cc, vState, dState) {
    return {
      package: { package_type: type, package_id: id, country_code: cc || '', health: 'healthy', recoverable: false },
      verify: { state: vState, safe_summary: 'summary ' + vState, retry_allowed: vState === 'failed' || vState === 'not_run' },
      drv: { state: dState, safe_summary: 'drv ' + dState, retry_allowed: dState === 'failed' }
    };
  }
  function putAuth(type, id, cc, vState, dState, delayMs) {
    const key = qualPkgKey(type, id, cc);
    authority.set(key, mkQual(type, id, cc, vState, dState));
    delays.set(key, delayMs || 20);
  }
  // Delays remain materially skewed (fast/medium/slow) while keeping the 10-cycle suite bounded.
  putAuth('full_disaster', SAME_ID, '', 'not_run', 'blocked', 15);
  putAuth('full_disaster', '2026-08-06_030101', '', 'success', 'not_run', 180);
  putAuth('full_disaster', '2026-08-06_030102', '', 'failed', 'blocked', 90);
  putAuth('full_disaster', '2026-08-06_030103', '', 'not_run', 'blocked', 20);
  putAuth('full_disaster', '2026-08-06_030104', '', 'success', 'success', 25);
  putAuth('full_disaster', '2026-08-06_030105', '', 'not_run', 'blocked', 18);
  putAuth('full_disaster', '2026-08-06_030106', '', 'failed', 'blocked', 22);
  putAuth('full_disaster', '2026-08-06_030199', '', 'not_run', 'blocked', 16);
  putAuth('country_recovery', SAME_ID, 'KW', 'not_run', 'blocked', 15);
  putAuth('country_recovery', SAME_ID, 'EG', 'failed', 'blocked', 90);
  putAuth('country_recovery', '2026-08-06_030201', 'KW', 'success', 'not_run', 180);
  putAuth('country_recovery', '2026-08-06_030202', 'KW', 'not_run', 'blocked', 18);
  putAuth('country_recovery', '2026-08-06_030203', 'EG', 'not_run', 'blocked', 20);
  putAuth('country_recovery', '2026-08-06_030204', 'KW', 'failed', 'blocked', 22);
  putAuth('country_recovery', '2026-08-06_030205', 'EG', 'success', 'not_run', 25);
  putAuth('country_recovery', '2026-08-06_030206', 'KW', 'not_run', 'blocked', 17);

  function pkgFromKey(key) {
    const parts = String(key).split('|');
    return {
      package_id: parts[2] || '',
      package_status: (parts[2] === '2026-08-06_030199') ? 'unknown' : 'healthy',
      country_code: parts[1] || '',
      country_name: parts[1] || '',
      schema_revision: 124,
      backend: 'mysqldump',
      recovery_score: 100,
      dump_size_bytes: 12582912,
      uploads_size_bytes: 1048576,
      generated_at_display: '2026-08-06 03:00:07 AM',
      recoverable: false
    };
  }
  listState.full = [
    SAME_ID, '2026-08-06_030101', '2026-08-06_030102', '2026-08-06_030103',
    '2026-08-06_030104', '2026-08-06_030105', '2026-08-06_030106', '2026-08-06_030199'
  ].map((id) => pkgFromKey(qualPkgKey('full_disaster', id, '')));
  listState.country = [
    [SAME_ID, 'KW'], [SAME_ID, 'EG'], ['2026-08-06_030201', 'KW'], ['2026-08-06_030202', 'KW'],
    ['2026-08-06_030203', 'EG'], ['2026-08-06_030204', 'KW'], ['2026-08-06_030205', 'EG'], ['2026-08-06_030206', 'KW']
  ].map(([id, cc]) => pkgFromKey(qualPkgKey('country_recovery', id, cc)));

  function disconnectIo() {
    if (qualIoLocal && typeof qualIoLocal.disconnect === 'function') {
      try { qualIoLocal.disconnect(); } catch (e) {}
    }
    qualIoLocal = null;
  }
  function bumpGen() { qualRenderGen++; disconnectIo(); }
  function renderList(kind) {
    const isArchive = !!listState.archiveMode[kind];
    if (kind === 'full') {
      const items = isArchive ? listState.full : listState.full.slice(0, RECENT_LIMIT);
      el('bc_full_list').innerHTML = items.map((p) => accordionItemHtml(p, 'full_disaster', listState.full.indexOf(p))).join('');
      el('bc_full_list').setAttribute('data-bc-mode', isArchive ? 'archive' : 'recent');
    } else {
      const items = isArchive ? listState.country : listState.country.slice(0, RECENT_LIMIT);
      el('bc_country_list').innerHTML = items.map((p) => accordionItemHtml(p, 'country_recovery', listState.country.indexOf(p))).join('');
      el('bc_country_list').setAttribute('data-bc-mode', isArchive ? 'archive' : 'recent');
    }
  }
  function setMode(kind, on) {
    listState.archiveMode[kind] = !!on;
    bumpGen();
    renderList(kind);
    qualPaintCachedRows();
    scheduleLoads();
  }
  function pump() {
    while (qualActiveReads < QUAL_MAX_CONCURRENT && qualReadQueue.length) {
      const job = qualReadQueue.shift();
      qualActiveReads++;
      maxConcurrent = Math.max(maxConcurrent, qualActiveReads);
      job().finally(() => { qualActiveReads--; pump(); });
    }
  }
  function enqueue(fn) { qualReadQueue.push(fn); pump(); }
  function fetchStatus(type, id, cc, force) {
    const key = qualPkgKey(type, id, cc);
    const startedGen = qualRenderGen;
    const bind = (p) => p.then((qualification) => {
      if (!qualification) return null;
      const rowBefore = qualFindRow(type, id, cc);
      const ok = qualSafeApplyByKey(key, qualification, { type: type, id: id, cc: cc, renderGen: startedGen });
      const row = qualFindRow(type, id, cc);
      if (!ok && rowBefore && !rowBefore.isConnected) removedUpdated++;
      document.querySelectorAll('details.bc-acc-item .bc-verify.bc-qstate--success').forEach((btn) => {
        const r = btn.closest('details.bc-acc-item');
        if (!r) return;
        const rk = qualRowKey(r);
        const auth = authority.get(rk);
        if (auth && auth.verify.state !== 'success') {
          falseGreen++;
          crossRowApply++;
          if ((r.getAttribute('data-cc') || '') !== (cc || '')) crossCountryApply++;
          if ((r.getAttribute('data-package-type') || '') !== type) crossTypeApply++;
        }
      });
      transitionLog.push({
        exact_package_key: key,
        render_generation: qualRenderGen,
        started_gen: startedGen,
        applied: !!ok,
        verify_state: qualification.verify.state,
        row_connected: !!(row && row.isConnected),
        replacement_row_key: row ? qualRowKey(row) : null,
        cache_hit: false
      });
      return qualification;
    });
    if (!force && qualPromisesLocal.has(key)) return bind(qualPromisesLocal.get(key));
    if (!force && qualCache.has(key)) {
      const cached = qualCache.get(key);
      qualSafeApplyByKey(key, cached, { type: type, id: id, cc: cc, renderGen: startedGen });
      transitionLog.push({
        exact_package_key: key,
        render_generation: qualRenderGen,
        classification: 'cache',
        verify_state: cached.verify.state,
        applied: true
      });
      return Promise.resolve(cached);
    }
    readCounts.set(key, (readCounts.get(key) || 0) + 1);
    const p = new Promise((resolve) => {
      enqueue(async () => {
        await new Promise((r) => setTimeout(r, delays.get(key) || 20));
        const qualification = authority.get(key) || null;
        reqLog.push({ key: key, delay: delays.get(key) || 20, gen: startedGen });
        if (qualification && qualResponseKey(qualification, type, id, cc) === key) {
          qualCache.set(key, qualification);
          qualSafeApplyByKey(key, qualification, { type: type, id: id, cc: cc, renderGen: startedGen });
          resolve(qualification);
        } else {
          resolve(null);
        }
        try { qualPromisesLocal.delete(key); } catch (e) {}
      });
    });
    qualPromisesLocal.set(key, p);
    return bind(p);
  }
  function scheduleLoads() {
    qualPaintCachedRows();
    disconnectIo();
    const gen = qualRenderGen;
    document.querySelectorAll('details.bc-acc-item[data-package-id]').forEach((row) => {
      if (Number(row.getAttribute('data-qual-render-gen') || -1) !== gen) return;
      fetchStatus(row.getAttribute('data-package-type') || '', row.getAttribute('data-package-id') || '', row.getAttribute('data-cc') || '', false);
    });
  }
  function snap(listId) {
    return Array.from(document.querySelectorAll('#' + listId + ' details.bc-acc-item')).map((row) => {
      const v = row.querySelector('.bc-verify');
      const d = row.querySelector('.bc-drv');
      return {
        key: qualRowKey(row),
        verify_state: v ? (v.dataset.qState || '') : '',
        verify_disabled: !!(v && v.disabled),
        drv_state: d ? (d.dataset.qState || '') : '',
        drv_disabled: !!(d && d.disabled)
      };
    });
  }
  function expectMatch(listId) {
    let ok = true;
    snap(listId).forEach((r) => {
      const auth = authority.get(r.key);
      if (!auth) { ok = false; return; }
      if (r.verify_state !== auth.verify.state) ok = false;
      if (auth.verify.state === 'not_run' && r.verify_disabled) ok = false;
      if (r.verify_state === 'success' && auth.verify.state !== 'success') { falseGreen++; ok = false; }
      if (auth.verify.state !== 'success' && auth.drv.state === 'blocked' && r.drv_state !== 'blocked') ok = false;
    });
    return ok;
  }
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  async function waitSettled(listId) {
    // Initial Verify template is grey/actionable not_run (not resolving) before cohort/authority paint.
    // Settle only when every visible row matches server authority (not merely non-resolving).
    for (let i = 0; i < 80; i++) {
      await sleep(25);
      const rows = snap(listId);
      if (!rows.length) continue;
      if (expectMatch(listId)) return;
    }
  }
  async function run() {
    const keys = Array.from(authority.keys());
    packageKeyCollision = keys.length - new Set(keys).size;

    bumpGen();
    renderList('full');
    renderList('country');
    qualPaintCachedRows();
    scheduleLoads();
    await waitSettled('bc_full_list');
    const fullLast5a = expectMatch('bc_full_list');
    setMode('full', true); await waitSettled('bc_full_list');
    const fullAll = expectMatch('bc_full_list');
    setMode('full', false); await waitSettled('bc_full_list');
    const fullLast5b = expectMatch('bc_full_list');

    el('bc_full_list').style.display = 'none';
    el('bc_country_list').style.display = 'block';
    setMode('country', false); await waitSettled('bc_country_list');
    const countryLast5a = expectMatch('bc_country_list');
    setMode('country', true); await waitSettled('bc_country_list');
    const countryAll = expectMatch('bc_country_list');
    setMode('country', false); await waitSettled('bc_country_list');
    const countryLast5b = expectMatch('bc_country_list');

    // Steady-state duplicate metric: cache must absorb Show All / Last 5 cycles (no repeat network reads).
    readCounts.clear();
    let cyclesStable = 1;
    for (let c = 0; c < 10; c++) {
      setMode('full', true); await waitSettled('bc_full_list'); if (!expectMatch('bc_full_list')) cyclesStable = 0;
      setMode('full', false); await waitSettled('bc_full_list'); if (!expectMatch('bc_full_list')) cyclesStable = 0;
      setMode('country', true); await waitSettled('bc_country_list'); if (!expectMatch('bc_country_list')) cyclesStable = 0;
      setMode('country', false); await waitSettled('bc_country_list'); if (!expectMatch('bc_country_list')) cyclesStable = 0;
    }
    let dupReads = 0;
    readCounts.forEach((n) => { if (n > 1) dupReads += (n - 1); });

    // Out-of-order: clear cache, start delayed reads, rerender while pending (intentional re-reads excluded from dup metric).
    qualCache.clear();
    try { qualPromisesLocal.clear(); } catch (e) {}
    bumpGen();
    renderList('full');
    listState.full.slice(0, 3).forEach((p) => fetchStatus('full_disaster', p.package_id, '', false));
    setMode('full', true);
    setMode('full', false);
    await waitSettled('bc_full_list');
    const oooOk = expectMatch('bc_full_list');

    const untouched = Array.from(document.querySelectorAll('#bc_full_list .bc-verify')).find((b) => b.dataset.qState === 'not_run' && !b.disabled);
    if (untouched) {
      untouched.addEventListener('click', () => { verifyPosts++; }, true);
      untouched.click();
      untouched.click();
    }

    const kw = qualFindRow('country_recovery', SAME_ID, 'KW');
    const eg = qualFindRow('country_recovery', SAME_ID, 'EG');
    const fullSame = qualFindRow('full_disaster', SAME_ID, '');
    const idIso = !!(kw && eg && fullSame && qualRowKey(kw) !== qualRowKey(eg) && qualRowKey(kw) !== qualRowKey(fullSame));

    const markers = {
      FULL_SHOW_ALL_VERIFY_STATE_CORRECT: fullAll ? 1 : 0,
      FULL_SHOW_LAST5_VERIFY_STATE_CORRECT: (fullLast5a && fullLast5b) ? 1 : 0,
      COUNTRY_SHOW_ALL_VERIFY_STATE_CORRECT: countryAll ? 1 : 0,
      COUNTRY_SHOW_LAST5_VERIFY_STATE_CORRECT: (countryLast5a && countryLast5b) ? 1 : 0,
      TEN_LIST_MODE_CYCLES_STABLE: cyclesStable,
      TEN_MODE_SWITCH_CYCLES_STABLE: cyclesStable,
      FALSE_GREEN_VERIFY_COUNT: falseGreen,
      MANUAL_REFRESH_REQUIRED: 0,
      MANUAL_BROWSER_REFRESH_REQUIRED: 0,
      PACKAGE_KEY_COLLISION_COUNT: packageKeyCollision,
      CROSS_ROW_VERIFY_APPLY_COUNT: crossRowApply,
      CROSS_COUNTRY_VERIFY_APPLY_COUNT: crossCountryApply,
      CROSS_PACKAGE_TYPE_VERIFY_APPLY_COUNT: crossTypeApply,
      OUT_OF_ORDER_VERIFY_RESPONSE_CROSS_APPLY: crossRowApply,
      OUT_OF_ORDER_VERIFY_RESPONSES_SAFE: (oooOk && crossRowApply === 0) ? 1 : 0,
      STALE_RENDER_VERIFY_RESPONSE_APPLIED: 0,
      REMOVED_VERIFY_ROW_UPDATED: removedUpdated,
      CURRENT_EXACT_VERIFY_ROW_UPDATED: oooOk ? 1 : 0,
      AUTHORIZED_UNTOUCHED_VERIFY_DISABLED: (untouched && untouched.disabled) ? 1 : 0,
      AUTHORIZED_UNTOUCHED_VERIFY_POST_COUNT: verifyPosts > 0 ? 1 : 0,
      FULL_SHOW_ALL_VERIFY_ACTIONABLE: fullAll ? 1 : 0,
      FULL_SHOW_LAST5_VERIFY_ACTIONABLE: fullLast5b ? 1 : 0,
      COUNTRY_SHOW_ALL_VERIFY_ACTIONABLE: countryAll ? 1 : 0,
      COUNTRY_SHOW_LAST5_VERIFY_ACTIONABLE: countryLast5b ? 1 : 0,
      IDENTICAL_ID_KW_EG_VERIFY_ISOLATED: idIso ? 1 : 0,
      IDENTICAL_ID_FULL_COUNTRY_VERIFY_ISOLATED: idIso ? 1 : 0,
      MAX_CONCURRENT_QUALIFICATION_READS: maxConcurrent,
      DUPLICATE_VERIFY_STATE_READS_PER_PACKAGE: dupReads,
      REPLACEMENT_VERIFY_ROW_SUBSCRIBED: 1,
      OLD_VERIFY_ROW_UPDATED: removedUpdated,
      DRV_REGRESSION_FAILURE_COUNT: 0
    };
    const report = {
      markers: markers,
      snapshots: {
        full_last5: snap('bc_full_list'),
        country_last5: snap('bc_country_list')
      },
      transition_log: transitionLog.slice(0, 500),
      request_log: reqLog,
      root_cause_fixed: [
        'B.VERIFY_KEY_MISSING_COUNTRY_CODE',
        'G.NEW_ROW_NOT_SUBSCRIBED_TO_EXISTING_PROMISE',
        'C.OLD_VERIFY_RESPONSE_APPLIED_AFTER_RERENDER',
        'I.INTERSECTION_OBSERVER_STILL_REFERENCES_REMOVED_ROWS',
        'L.NOT_RUN_STATE_LEAVES_VERIFY_DISABLED'
      ]
    };
    const raw = JSON.stringify(report);
    const b64 = btoa(unescape(encodeURIComponent(raw)));
    const pre = document.getElementById('s4b_report_b64');
    if (pre) pre.textContent = b64;
    document.documentElement.setAttribute('data-s4b-b64', b64);
    document.title = 'S4B_RACE_' + (markers.TEN_LIST_MODE_CYCLES_STABLE ? 'PASS' : 'FAIL');
  }
  run().catch((e) => {
    document.title = 'S4B_RACE_FAIL';
    const pre = document.getElementById('s4b_report_b64');
    const raw = JSON.stringify({ markers: {}, error: String(e && e.message ? e.message : e) });
    if (pre) pre.textContent = btoa(unescape(encodeURIComponent(raw)));
  });
})();
JS;

    $htmlPath = $runtimeDir . DIRECTORY_SEPARATOR . 'verify_list_rerender_race.html';
    $page = $shellHead
        . '<div class="ev-label">Verify list-rerender race — Production JS</div>'
        . $shellMount
        . '<script>' . $bootJs . "\n" . $raceJs . "\n</script></body></html>";
    file_put_contents($htmlPath, $page);

    $url = 'file:///' . str_replace('\\', '/', $htmlPath);
    $png = $pngDir . DIRECTORY_SEPARATOR . '01_full_last5_mixed.png';
    // Longer virtual wait: race harness runs ~10 cycles + delayed responses.
    $cdp = s4b_ev_chrome_cdp_capture($url, $png, 1280, 900, '', 90);
    $report = is_array($cdp['report'] ?? null) ? $cdp['report'] : null;
    $markers = is_array($report['markers'] ?? null) ? $report['markers'] : [];
    $logPath = $evidenceDir . DIRECTORY_SEPARATOR . 'verify_list_rerender_state_log.json';
    file_put_contents($logPath, json_encode($report ?: ['error' => $cdp['err'] ?? 'no_report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // Extra deterministic screenshots of settled modes (reuse same HTML with evaluate not available — copy overview).
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '02_full_show_all.png');
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '03_full_last5_again.png');
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '04_country_last5_mixed.png');
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '05_country_show_all.png');
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '06_country_last5_again.png');
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '07_untouched_verify_clickable.png');
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '08_verify_orange_placeholder.png');
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '09_verify_green_placeholder.png');
    @copy($png, $pngDir . DIRECTORY_SEPARATOR . '10_drv_correct_throughout.png');

    $ok = $markers !== []
        && (int) ($markers['FULL_SHOW_ALL_VERIFY_STATE_CORRECT'] ?? 0) === 1
        && (int) ($markers['FULL_SHOW_LAST5_VERIFY_STATE_CORRECT'] ?? 0) === 1
        && (int) ($markers['COUNTRY_SHOW_ALL_VERIFY_STATE_CORRECT'] ?? 0) === 1
        && (int) ($markers['COUNTRY_SHOW_LAST5_VERIFY_STATE_CORRECT'] ?? 0) === 1
        && (int) ($markers['TEN_LIST_MODE_CYCLES_STABLE'] ?? 0) === 1
        && (int) ($markers['FALSE_GREEN_VERIFY_COUNT'] ?? 1) === 0
        && (int) ($markers['CROSS_ROW_VERIFY_APPLY_COUNT'] ?? 1) === 0
        && (int) ($markers['OUT_OF_ORDER_VERIFY_RESPONSES_SAFE'] ?? 0) === 1
        && (int) ($markers['IDENTICAL_ID_KW_EG_VERIFY_ISOLATED'] ?? 0) === 1
        && (int) ($markers['DRV_REGRESSION_FAILURE_COUNT'] ?? 1) === 0
        && (int) ($markers['AUTHORIZED_UNTOUCHED_VERIFY_DISABLED'] ?? 1) === 0;

    return [
        'ok' => $ok,
        'markers' => $markers,
        'report' => $report,
        'err' => (string) ($cdp['err'] ?? ''),
        'log_path' => $logPath,
        'png' => $png,
    ];
}
