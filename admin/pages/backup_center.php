<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/backup/backup_admin.php';

/** @var array<string, mixed> $admin */
$pdo = orange_admin_page_pdo();

if (!orange_backup_admin_may_view($admin, $pdo)) {
    echo '<div class="card"><div class="alert-error">لا تملك صلاحية عرض إدارة النسخ الاحتياطي.</div></div>';

    return;
}

$canRun = orange_backup_admin_may_run($admin, $pdo);
$canVerify = orange_backup_admin_may_verify($admin, $pdo);
$csrfToken = orange_backup_admin_csrf_token();
$apiBase = storefront_public_path('/admin/api/backup');
?>
<style>
.bc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px}
.bc-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px}
.bc-card h4{margin:0 0 6px;font-size:.95rem}
.bc-card .bc-val{font-size:1.15rem;font-weight:700}
.bc-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8rem;font-weight:600}
.bc-badge--success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.bc-badge--warning{background:#fffbeb;color:#b45309;border:1px solid #fde68a}
.bc-badge--failed{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.bc-badge--running{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.bc-badge--muted{background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb}
.bc-root-warning{display:none;margin-bottom:12px;padding:12px 14px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.bc-root-health{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:0;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
.bc-root-health dt{font-size:.78rem;color:#64748b;margin:0 0 2px}
.bc-root-health dd{margin:0;font-weight:600;font-size:.95rem}
.bc-actions{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}
.bc-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:5000}
.bc-modal{background:#fff;border-radius:12px;max-width:520px;width:92%;padding:18px;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.bc-modal h3{margin:0 0 10px}
.bc-pre{max-height:360px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:.78rem;white-space:pre-wrap;word-break:break-word}
.bc-progress{display:none;margin:12px 0;padding:10px;border-radius:8px;background:#eff6ff;color:#1e3a8a}
.bc-section{margin-bottom:18px}
.bc-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px;border-bottom:1px solid #e5e7eb;padding-bottom:8px}
.bc-tab{padding:8px 14px;border:1px solid #d1d5db;border-radius:8px 8px 0 0;background:#f9fafb;cursor:pointer;font-weight:600;font:inherit}
.bc-tab.is-active{background:#fff;border-bottom-color:#fff;color:#1d4ed8}
.bc-tab-panel{display:none}
.bc-tab-panel.is-active{display:block}
.bc-status-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:12px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
.bc-status-strip dt{font-size:.78rem;color:#64748b;margin:0 0 2px}
.bc-status-strip dd{margin:0;font-weight:600;font-size:.95rem}
.bc-storage{display:flex;flex-direction:column;gap:18px}
.bc-storage-root{margin-bottom:0}
.bc-storage-root-title{margin:0 0 10px;font-size:.95rem;font-weight:600;display:flex;align-items:center;gap:6px}
.bc-storage-path-row{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px}
.bc-storage-path{margin:0;font-family:ui-monospace,Consolas,monospace;font-size:.82rem;line-height:1.55;color:#334155;word-break:break-all;overflow-wrap:anywhere;max-width:100%;flex:1 1 200px;min-width:0}
.bc-storage-path--ellipsis{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-all}
.bc-storage-copy{flex:0 0 auto;white-space:nowrap;align-self:center}
.bc-storage-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
.bc-kpi-card{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:96px}
.bc-kpi-card h4{margin:0 0 8px;width:100%;text-align:center;font-size:.95rem}
.bc-kpi-card .bc-val{font-size:1.15rem;font-weight:700;word-break:break-word;display:flex;align-items:center;justify-content:center;flex:1;width:100%;text-align:center;direction:ltr;unicode-bidi:isolate}
@media (max-width:1024px){.bc-storage-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:768px){.bc-grid{grid-template-columns:1fr}.bc-storage-kpis{grid-template-columns:1fr}}
</style>

<div class="page-title">
    <h1>إدارة النسخ الاحتياطي</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;">شاشة واحدة — النسخ الشامل ونسخ الدول مع أقسام مشتركة (نظرة عامة، الجدولة، التخزين، السجلات).</p>
</div>

<div id="bc_progress" class="bc-progress" role="status" aria-live="polite">جاري التنفيذ…</div>
<div id="bc_alert" class="card" style="display:none;margin-bottom:12px;"></div>

<div id="bc_root_warning" class="bc-root-warning" role="status" aria-live="polite"></div>

<div class="bc-section card">
    <h3>حالة مسار النسخ الاحتياطي</h3>
    <dl id="bc_root_health" class="bc-root-health">
        <div><dt>المسار موجود</dt><dd>…</dd></div>
        <div><dt>قابل للقراءة</dt><dd>…</dd></div>
        <div><dt>قابل للكتابة</dt><dd>…</dd></div>
        <div><dt>التشغيل اليدوي</dt><dd>…</dd></div>
    </dl>
</div>
<div class="bc-section">
    <h3>نظرة عامة</h3>
    <div id="bc_overview" class="bc-grid">
        <div class="bc-card"><h4>جاري التحميل…</h4></div>
    </div>
</div>

<div class="bc-section card">
    <div class="bc-tabs" role="tablist" aria-label="أقسام النسخ الاحتياطي">
        <button type="button" class="bc-tab is-active" role="tab" id="bc_tab_full_btn" aria-controls="bc_tab_full" aria-selected="true" data-bc-tab="full">النسخ الاحتياطي الشامل</button>
        <button type="button" class="bc-tab" role="tab" id="bc_tab_country_btn" aria-controls="bc_tab_country" aria-selected="false" data-bc-tab="country">نسخ الدول</button>
    </div>

    <div id="bc_tab_full" class="bc-tab-panel is-active" role="tabpanel" aria-labelledby="bc_tab_full_btn">
        <h3>النسخ الاحتياطي الشامل</h3>
        <dl id="bc_latest_full" class="bc-status-strip">
            <div><dt>آخر Full</dt><dd>…</dd></div>
            <div><dt>الحالة</dt><dd>…</dd></div>
            <div><dt>Schema</dt><dd>…</dd></div>
            <div><dt>DRV Score</dt><dd>…</dd></div>
        </dl>
        <div class="bc-actions">
            <?php if ($canRun): ?>
            <button type="button" class="btn-primary" id="bc_run_full_btn" data-action="run_full">تشغيل Full Backup</button>
            <?php endif; ?>
            <button type="button" class="btn-secondary" id="bc_refresh_btn">تحديث</button>
        </div>
        <div class="table-wrap">
            <table id="bc_full_table">
                <thead>
                    <tr>
                        <th>الوقت</th>
                        <th>الحالة</th>
                        <th>Schema</th>
                        <th>Backend</th>
                        <th>Dump</th>
                        <th>Uploads</th>
                        <th>DRV</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="8" class="muted">…</td></tr></tbody>
            </table>
        </div>
    </div>

    <div id="bc_tab_country" class="bc-tab-panel" role="tabpanel" aria-labelledby="bc_tab_country_btn" hidden>
        <h3>نسخ الدول</h3>
        <dl id="bc_country_discovery" class="bc-status-strip">
            <div><dt>دول قابلة للاسترداد</dt><dd>…</dd></div>
            <div><dt>آخر Country Batch</dt><dd>…</dd></div>
            <div><dt>حزم مسجّلة</dt><dd>…</dd></div>
        </dl>
        <div class="bc-actions">
            <?php if ($canRun): ?>
            <button type="button" class="btn-primary" id="bc_run_countries_btn" data-action="run_countries">تشغيل All Recoverable Countries</button>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table id="bc_country_table">
                <thead>
                    <tr>
                        <th>الدولة</th>
                        <th>الحزمة</th>
                        <th>الحالة</th>
                        <th>Schema</th>
                        <th>Registry</th>
                        <th>DRV</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="7" class="muted">…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="bc-section card">
    <h3>حالة المهام المجدولة</h3>
    <div class="table-wrap">
        <table id="bc_schedule_table">
            <thead><tr><th>المهمة</th><th>الجدولة</th><th>المسار</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
    <p class="card-hint">لا يتم تعديل مهام Plesk Scheduled Tasks من Orange.</p>
</div>

<div class="bc-section card">
    <h3>التخزين والاحتفاظ</h3>
    <div class="bc-storage" id="bc_storage">
        <div class="bc-card bc-storage-root">
            <h4 class="bc-storage-root-title">📁 Backup Root</h4>
            <div class="bc-storage-path-row">
                <p id="bc_storage_path" class="bc-storage-path" title="">—</p>
                <button type="button" class="btn-link bc-storage-copy" id="bc_storage_copy_btn" hidden>نسخ المسار</button>
            </div>
        </div>
        <div class="bc-storage-kpis" id="bc_storage_kpis">
            <div class="bc-card bc-kpi-card"><h4>Snapshots</h4><div class="bc-val">—</div></div>
        </div>
    </div>
</div>

<div class="bc-section card">
    <h3>السجلات</h3>
    <div class="table-wrap">
        <table id="bc_logs_table">
            <thead><tr><th>الملف</th><th>النوع</th><th>الحجم</th><th>آخر تعديل</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div id="bc_confirm_modal" class="bc-modal-backdrop" aria-hidden="true">
    <div class="bc-modal" role="dialog" aria-modal="true" aria-labelledby="bc_confirm_title">
        <h3 id="bc_confirm_title">تأكيد</h3>
        <p id="bc_confirm_text"></p>
        <div class="admin-form-actions" style="margin-top:12px;">
            <button type="button" class="btn-primary" id="bc_confirm_ok">تأكيد</button>
            <button type="button" class="btn-secondary" id="bc_confirm_cancel">إلغاء</button>
        </div>
    </div>
</div>

<div id="bc_view_modal" class="bc-modal-backdrop" aria-hidden="true">
    <div class="bc-modal" role="dialog" aria-modal="true" style="max-width:760px;">
        <h3 id="bc_view_title">عرض</h3>
        <pre id="bc_view_pre" class="bc-pre"></pre>
        <div class="admin-form-actions"><button type="button" class="btn-secondary" id="bc_view_close">إغلاق</button></div>
    </div>
</div>

<script>
(function () {
    const API_BASE = <?php echo json_encode($apiBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const CSRF = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;
    const CAN_RUN = <?php echo $canRun ? 'true' : 'false'; ?>;
    const CAN_VERIFY = <?php echo $canVerify ? 'true' : 'false'; ?>;

    let state = { full: [], country: [], busy: false, pendingAction: null };
    let manualActionsAvailable = true;
    let recoveryCheckRequiresWrite = true;
    let rootHealthWarning = '';

    const el = (id) => document.getElementById(id);
    const fmtBytes = (n) => {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    };
    const badge = (status) => {
        const s = String(status || '').toLowerCase();
        let cls = 'bc-badge--muted';
        if (s === 'healthy' || s === 'success' || s === 'pass') cls = 'bc-badge--success';
        else if (s === 'warning' || s === 'warn') cls = 'bc-badge--warning';
        else if (s === 'failed' || s === 'fail' || s === 'error') cls = 'bc-badge--failed';
        else if (s === 'running') cls = 'bc-badge--running';
        return '<span class="bc-badge ' + cls + '">' + (status || '—') + '</span>';
    };
    const showAlert = (msg, ok) => {
        const box = el('bc_alert');
        box.style.display = 'block';
        box.innerHTML = '<div class="' + (ok ? 'alert-success' : 'alert-error') + '">' + msg + '</div>';
    };
    const healthBadge = (ok, yesLabel, noLabel) => {
        const label = ok ? yesLabel : noLabel;
        const cls = ok ? 'bc-badge--success' : 'bc-badge--warning';
        return '<span class="bc-badge ' + cls + '">' + label + '</span>';
    };
    const applyActionAvailability = () => {
        const runDisabled = !manualActionsAvailable || state.busy;
        ['bc_run_full_btn', 'bc_run_countries_btn'].forEach((id) => {
            const b = el(id);
            if (!b) {
                return;
            }
            b.disabled = runDisabled;
            if (!manualActionsAvailable) {
                b.title = rootHealthWarning || 'التشغيل اليدوي غير متاح';
            } else {
                b.removeAttribute('title');
            }
        });
        if (el('bc_refresh_btn')) {
            el('bc_refresh_btn').disabled = state.busy;
        }
        document.querySelectorAll('.bc-drv').forEach((btn) => {
            if (recoveryCheckRequiresWrite && !manualActionsAvailable) {
                btn.disabled = true;
                btn.title = rootHealthWarning || 'DRV يتطلب كتابة على مسار النسخ الاحتياطي';
            } else {
                btn.disabled = false;
                btn.removeAttribute('title');
            }
        });
    };
    const setBusy = (on, text) => {
        state.busy = on;
        el('bc_progress').style.display = on ? 'block' : 'none';
        if (text) el('bc_progress').textContent = text;
        applyActionAvailability();
    };
    const renderRootHealth = (data) => {
        const h = data.backup_root_health || {};
        const perms = data.permissions || {};
        manualActionsAvailable = !!(perms.manual_actions_available ?? h.manual_actions_available);
        recoveryCheckRequiresWrite = perms.recovery_check_requires_write !== false;
        rootHealthWarning = h.warning || '';
        const warnBox = el('bc_root_warning');
        if (h.readable && !h.writable && h.warning) {
            warnBox.textContent = h.warning;
            warnBox.style.display = 'block';
        } else {
            warnBox.style.display = 'none';
            warnBox.textContent = '';
        }
        el('bc_root_health').innerHTML =
            '<div><dt>المسار موجود</dt><dd>' + healthBadge(!!h.exists, 'نعم', 'لا') + '</dd></div>' +
            '<div><dt>قابل للقراءة</dt><dd>' + healthBadge(!!h.readable, 'نعم', 'لا') + '</dd></div>' +
            '<div><dt>قابل للكتابة</dt><dd>' + healthBadge(!!h.writable, 'نعم', 'لا') + '</dd></div>' +
            '<div><dt>التشغيل اليدوي</dt><dd>' + healthBadge(manualActionsAvailable, 'متاح', 'غير متاح') + '</dd></div>';
        applyActionAvailability();
    };

    async function apiGet(path) {
        const r = await fetch(API_BASE + '/' + path, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const j = await r.json();
        if (!j.success && r.status >= 400) throw new Error(j.message || 'Request failed');
        return j;
    }
    async function apiPost(path, body) {
        const r = await fetch(API_BASE + '/' + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF }, body || {}))
        });
        const j = await r.json();
        if (!j.success) throw new Error(j.message || 'Request failed');
        return j;
    }

    function renderOverview(o) {
        const ov = o.overview || {};
        const last = ov.last_successful_full || ov.latest_full || {};
        const latestFull = (o.full_snapshots && o.full_snapshots[0]) ? o.full_snapshots[0] : last;
        const countryBatch = ov.latest_country_batch || {};
        const countryCount = Array.isArray(o.country_packages) ? o.country_packages.length : 0;
        const cards = [
            ['آخر Full ناجح', last.generated_at || '—'],
            ['حالة Full الأخير', last.package_status || '—'],
            ['Country Batch الأخير', countryBatch.generated_at || '—'],
            ['دول قابلة للاسترداد', String(ov.recoverable_countries ?? '—')],
            ['BackupRoot', ov.backup_root_status || '—'],
            ['Retention (يوم)', String(ov.retention_days ?? '—')],
            ['Backend', ov.selected_backend || '—'],
            ['DRV Score', String(ov.latest_recovery_score ?? 0)],
            ['إجمالي التخزين', (ov.storage || {}).total_human || '—']
        ];
        el('bc_overview').innerHTML = cards.map(([t, v]) =>
            '<div class="bc-card"><h4>' + t + '</h4><div class="bc-val">' + v + '</div></div>'
        ).join('');
        el('bc_latest_full').innerHTML =
            '<div><dt>آخر Full</dt><dd>' + (latestFull.generated_at || '—') + '</dd></div>' +
            '<div><dt>الحالة</dt><dd>' + badge(latestFull.package_status || last.package_status) + '</dd></div>' +
            '<div><dt>Schema</dt><dd>' + (latestFull.schema_revision ?? '—') + '</dd></div>' +
            '<div><dt>DRV Score</dt><dd>' + (latestFull.recovery_score ?? ov.latest_recovery_score ?? 0) + '</dd></div>';
        el('bc_country_discovery').innerHTML =
            '<div><dt>دول قابلة للاسترداد</dt><dd>' + String(ov.recoverable_countries ?? '—') + '</dd></div>' +
            '<div><dt>آخر Country Batch</dt><dd>' + (countryBatch.generated_at || '—') + '</dd></div>' +
            '<div><dt>حزم مسجّلة</dt><dd>' + countryCount + '</dd></div>';
        const st = ov.storage || {};
        const rootPath = ov.backup_root || '—';
        const pathEl = el('bc_storage_path');
        const copyBtn = el('bc_storage_copy_btn');
        if (pathEl) {
            pathEl.textContent = rootPath;
            pathEl.title = rootPath !== '—' ? rootPath : '';
            pathEl.classList.toggle('bc-storage-path--ellipsis', rootPath.length > 96);
        }
        if (copyBtn) {
            copyBtn.hidden = !rootPath || rootPath === '—';
        }
        const retentionRaw = ov.retention_days;
        const retentionLabel = retentionRaw !== undefined && retentionRaw !== null && retentionRaw !== ''
            ? String(retentionRaw) + ' يوم'
            : '—';
        const kpis = [
            ['Snapshots', st.snapshots_human || '—'],
            ['Country Packages', st.country_packages_human || '—'],
            ['Logs', st.logs_human || '—'],
            ['Total', st.total_human || '—'],
            ['Retention', retentionLabel]
        ];
        el('bc_storage_kpis').innerHTML = kpis.map(([t, v]) =>
            '<div class="bc-card bc-kpi-card"><h4>' + t + '</h4><div class="bc-val" dir="ltr">' + v + '</div></div>'
        ).join('');
        const sched = ov.scheduled_tasks || [];
        el('bc_schedule_table').querySelector('tbody').innerHTML = sched.map((row) =>
            '<tr><td>' + row.task + '</td><td>' + row.schedule + '</td><td><code>' + row.script + '</code></td></tr>'
        ).join('');
    }

    function actionButtons(pkg, type) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        let html = '';
        const viewFiles = type === 'full'
            ? [['manifest.json', 'المانيفست'], ['health.json', 'Health'], ['recovery_validation.json', 'DRV Report']]
            : [['manifest.json', 'المانيفست'], ['health.json', 'Health'], ['dependency_graph.json', 'Graph'], ['table_inventory.json', 'Inventory'], ['recovery_validation.json', 'DRV Report']];
        viewFiles.forEach(([file, label]) => {
            html += '<button type="button" class="btn-link bc-view-file" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '" data-file="' + file + '">' + label + '</button> ';
        });
        if (CAN_VERIFY) {
            html += '<button type="button" class="btn-link bc-verify" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">Verify</button> ';
            html += '<button type="button" class="btn-link bc-drv" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">DRV</button>';
        }
        return html;
    }

    function renderTables(data) {
        state.full = data.full_snapshots || [];
        state.country = data.country_packages || [];
        el('bc_full_table').querySelector('tbody').innerHTML = state.full.length
            ? state.full.map((p) => '<tr><td>' + (p.generated_at || '') + '</td><td>' + badge(p.package_status) + '</td><td>' + p.schema_revision + '</td><td>' + (p.backend || '') + '</td><td>' + fmtBytes(p.dump_size_bytes) + '</td><td>' + fmtBytes(p.uploads_size_bytes) + '</td><td>' + (p.recovery_score || 0) + '</td><td class="bc-actions">' + actionButtons(p, 'full_disaster') + '</td></tr>').join('')
            : '<tr><td colspan="8" class="muted">لا توجد لقطات.</td></tr>';
        el('bc_country_table').querySelector('tbody').innerHTML = state.country.length
            ? state.country.map((p) => '<tr><td>' + (p.country_code || '') + (p.country_name ? ' — ' + p.country_name : '') + '</td><td>' + p.package_id + '</td><td>' + badge(p.package_status) + '</td><td>' + p.schema_revision + '</td><td>' + (p.registry_version || '') + '</td><td>' + (p.recovery_score || 0) + '</td><td class="bc-actions">' + actionButtons(p, 'country_recovery') + '</td></tr>').join('')
            : '<tr><td colspan="7" class="muted">لا توجد حزم دول.</td></tr>';
        el('bc_logs_table').querySelector('tbody').innerHTML = (data.logs || []).map((log) =>
            '<tr><td><code>' + log.name + '</code></td><td>' + log.category + '</td><td>' + fmtBytes(log.size_bytes) + '</td><td>' + new Date(log.mtime * 1000).toLocaleString() + '</td><td><button type="button" class="btn-link bc-log-tail" data-log="' + log.name + '">عرض</button></td></tr>'
        ).join('');
        applyActionAvailability();
    }

    async function loadAll() {
        setBusy(true, 'جاري تحميل البيانات…');
        try {
            const data = await apiGet('list.php');
            renderRootHealth(data);
            renderOverview(data);
            renderTables(data);
            const locks = await apiGet('status.php?action=locks');
            if ((locks.full_lock || {}).held || (locks.country_lock || {}).held) {
                showAlert('هناك عملية نسخ احتياطي قيد التشغيل حالياً.', false);
            }
        } catch (e) {
            el('bc_root_warning').style.display = 'none';
            showAlert(e.message || 'تعذر التحميل', false);
        } finally {
            setBusy(false);
        }
    }

    function confirmAction(title, text, fn) {
        el('bc_confirm_title').textContent = title;
        el('bc_confirm_text').textContent = text;
        el('bc_confirm_modal').style.display = 'flex';
        state.pendingAction = fn;
    }
    function closeConfirm() {
        el('bc_confirm_modal').style.display = 'none';
        state.pendingAction = null;
    }

    el('bc_confirm_cancel').addEventListener('click', closeConfirm);
    el('bc_confirm_ok').addEventListener('click', async () => {
        const fn = state.pendingAction;
        closeConfirm();
        if (typeof fn === 'function') await fn();
    });
    el('bc_view_close').addEventListener('click', () => { el('bc_view_modal').style.display = 'none'; });

    el('bc_refresh_btn').addEventListener('click', loadAll);


    const storageCopyBtn = el('bc_storage_copy_btn');
    if (storageCopyBtn) {
        storageCopyBtn.addEventListener('click', async () => {
            const path = el('bc_storage_path')?.textContent?.trim() || '';
            if (!path || path === '—') {
                return;
            }
            const prevLabel = storageCopyBtn.textContent;
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(path);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = path;
                    ta.setAttribute('readonly', '');
                    ta.style.position = 'absolute';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                storageCopyBtn.textContent = 'تم النسخ';
                setTimeout(() => { storageCopyBtn.textContent = prevLabel; }, 2000);
            } catch (e) {
                showAlert('تعذر نسخ المسار.', false);
            }
        });
    }
    if (CAN_RUN) {
        el('bc_run_full_btn').addEventListener('click', () => confirmAction(
            'تشغيل Full Disaster Backup',
            'سيتم تشغيل النسخ الاحتياطي الكامل عبر محرك Orange المعتمد. هل تريد المتابعة؟',
            async () => {
                setBusy(true, 'تشغيل Full Backup…');
                try {
                    const res = await apiPost('run-full.php', {});
                    showAlert(res.message || 'تم', true);
                    await loadAll();
                } catch (e) {
                    showAlert(e.message || 'فشل التشغيل', false);
                } finally { setBusy(false); }
            }
        ));
        el('bc_run_countries_btn').addEventListener('click', () => confirmAction(
            'تشغيل Country Batch',
            'سيتم تصدير جميع الدول القابلة للاسترداد. قد يستغرق وقتاً. هل تريد المتابعة؟',
            async () => {
                setBusy(true, 'تشغيل Country Batch…');
                try {
                    const res = await apiPost('run-countries.php', {});
                    showAlert(res.message || 'تم', true);
                    await loadAll();
                } catch (e) {
                    showAlert(e.message || 'فشل التشغيل', false);
                } finally { setBusy(false); }
            }
        ));
    }

    document.body.addEventListener('click', async (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLElement)) return;
        if (t.classList.contains('bc-view-file')) {
            const q = new URLSearchParams({
                action: 'view_file',
                package_type: t.dataset.type || '',
                package_id: t.dataset.id || '',
                country_code: t.dataset.cc || '',
                file: t.dataset.file || ''
            });
            try {
                const res = await apiGet('status.php?' + q.toString());
                el('bc_view_title').textContent = t.dataset.file || 'file';
                el('bc_view_pre').textContent = res.data ? JSON.stringify(res.data, null, 2) : (res.raw_text || '');
                el('bc_view_modal').style.display = 'flex';
            } catch (e) { showAlert(e.message, false); }
        }
        if (t.classList.contains('bc-log-tail')) {
            try {
                const res = await apiGet('status.php?action=log_tail&log=' + encodeURIComponent(t.dataset.log || ''));
                el('bc_view_title').textContent = 'Log: ' + (t.dataset.log || '');
                el('bc_view_pre').textContent = res.tail || '';
                el('bc_view_modal').style.display = 'flex';
            } catch (e) { showAlert(e.message, false); }
        }
        if (t.classList.contains('bc-verify') && CAN_VERIFY) {
            setBusy(true, 'Verify…');
            try {
                const res = await apiPost('verify.php', {
                    package_type: t.dataset.type,
                    package_id: t.dataset.id,
                    country_code: t.dataset.cc || ''
                });
                showAlert(res.message || 'تم', true);
                await loadAll();
            } catch (e) { showAlert(e.message, false); }
            finally { setBusy(false); }
        }
        if (t.classList.contains('bc-drv') && CAN_VERIFY) {
            setBusy(true, 'DRV…');
            try {
                const res = await apiPost('recovery-check.php', {
                    package_type: t.dataset.type,
                    package_id: t.dataset.id,
                    country_code: t.dataset.cc || ''
                });
                showAlert(res.message || 'تم', true);
                await loadAll();
            } catch (e) { showAlert(e.message, false); }
            finally { setBusy(false); }
        }
    });

    function switchTab(name) {
        const fullBtn = el('bc_tab_full_btn');
        const countryBtn = el('bc_tab_country_btn');
        const fullPanel = el('bc_tab_full');
        const countryPanel = el('bc_tab_country');
        const isFull = name === 'full';
        fullBtn.classList.toggle('is-active', isFull);
        countryBtn.classList.toggle('is-active', !isFull);
        fullBtn.setAttribute('aria-selected', isFull ? 'true' : 'false');
        countryBtn.setAttribute('aria-selected', isFull ? 'false' : 'true');
        fullPanel.classList.toggle('is-active', isFull);
        countryPanel.classList.toggle('is-active', !isFull);
        fullPanel.hidden = !isFull;
        countryPanel.hidden = isFull;
    }

    document.querySelectorAll('[data-bc-tab]').forEach((btn) => {
        btn.addEventListener('click', () => switchTab(btn.getAttribute('data-bc-tab') || 'full'));
    });

    loadAll();
})();
</script>
