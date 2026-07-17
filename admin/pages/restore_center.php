<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

/** @var array<string, mixed> $admin */
$pdo = orange_admin_page_pdo();

if (!orange_admin_may_restore_center_view($admin, $pdo)) {
    echo '<div class="card"><div class="alert-error">لا تملك صلاحية عرض إدارة الاسترداد.</div></div>';

    return;
}

$canFull = orange_admin_may_backup_restore_full($admin, $pdo);
$canCountry = orange_admin_may_backup_restore_country($admin, $pdo);
$apiBase = storefront_public_path('/admin/api/restore');

orange_admin_render_page_title_with_country('إدارة الاسترداد', $pdo);
?>
<style>
.rc-readonly-banner{margin:0 0 16px;padding:12px 14px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;line-height:1.65}
.rc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
.rc-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px}
.rc-card h4{margin:0 0 6px;font-size:.95rem}
.rc-card .rc-val{font-size:1.15rem;font-weight:700}
.rc-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8rem;font-weight:600}
.rc-badge--success{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}
.rc-badge--warning{background:#fffbeb;color:#b45309;border:1px solid #fde68a}
.rc-badge--failed{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.rc-badge--running{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.rc-badge--muted{background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb}
.rc-section{margin-bottom:18px}
.rc-status-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:12px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
.rc-status-strip dt{font-size:.78rem;color:#64748b;margin:0 0 2px}
.rc-status-strip dd{margin:0;font-weight:600;font-size:.95rem}
.rc-actions{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}
td.rc-actions{margin:0;flex-wrap:nowrap;align-items:center;width:1%;white-space:nowrap;vertical-align:middle}
td.rc-actions .btn-link,td.rc-actions button.btn-link{flex-shrink:0}
@media (max-width:1024px){td.rc-actions{flex-wrap:wrap;white-space:normal;width:auto}}
.rc-ts{display:inline-flex;flex-wrap:nowrap;align-items:baseline;gap:.35em;font-family:ui-monospace,Consolas,monospace;font-size:.82rem;line-height:1.45;white-space:nowrap}
.rc-ts-date,.rc-ts-time{white-space:nowrap}
.rc-ts-cell{vertical-align:middle;min-width:0}
@media (max-width:1024px){.rc-ts{flex-wrap:wrap;gap:0;white-space:normal}.rc-ts-date{display:block}.rc-ts-time{display:block;white-space:nowrap}}
.rc-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:5000}
.rc-modal{background:#fff;border-radius:12px;max-width:520px;width:92%;padding:18px;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.rc-modal--wide{max-width:860px}
.rc-modal h3{margin:0 0 10px}
.rc-pre{max-height:360px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:.78rem;white-space:pre-wrap;word-break:break-word}
.rc-progress{display:none;margin:12px 0;padding:10px;border-radius:8px;background:#eff6ff;color:#1e3a8a}
.rc-actions .btn-link,.rc-actions button.btn-link,#rc_refresh_btn{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;background:var(--primary,#ea580c);color:#fff!important;-webkit-text-fill-color:#fff;text-decoration:none;padding:var(--admin-btn-pad-y,7px) var(--admin-btn-pad-x,14px);min-height:var(--admin-btn-min-h,32px);border:0;border-radius:var(--radius-sm,10px);font-weight:600;cursor:pointer}
.rc-actions .btn-link:hover,.rc-actions button.btn-link:hover,#rc_refresh_btn:hover{background:var(--primary-hover,#c2410c);color:#fff!important}
#rc_refresh_btn{background:#475569}
#rc_refresh_btn:hover{background:#334155}
#rc_view_close,#rc_detail_close{background:#475569;color:#fff!important}
@media (max-width:768px){.rc-grid{grid-template-columns:1fr}}
</style>

<p class="rc-readonly-banner" role="status">
    <strong>تنبيه:</strong> يمكن تفعيل إطار الصيانة بعد الجاهزية المعزولة.
    <strong>Production restore has NOT started.</strong>
    لا يوجد استيراد/مسح لقاعدة الإنتاج، ولا استعادة ملفات، ولا cutover، ولا rollback في هذه المرحلة.
</p>

<div class="rc-section card" id="rc_maint_section">
    <h3>حالة وضع الصيانة (Production Maintenance)</h3>
    <p id="rc_maint_banner" class="rc-readonly-banner" style="margin:0 0 10px;" role="status">
        <strong>Production restore has NOT started.</strong>
    </p>
    <dl id="rc_maint_status" class="rc-status-strip">
        <div><dt>الحالة</dt><dd>…</dd></div>
        <div><dt>الملصق</dt><dd>…</dd></div>
        <div><dt>المهمة المرتبطة</dt><dd>…</dd></div>
        <div><dt>وقت الطلب</dt><dd>…</dd></div>
        <div><dt>وقت التفعيل</dt><dd>…</dd></div>
        <div><dt>آخر نبضة</dt><dd>…</dd></div>
        <div><dt>Stale</dt><dd>…</dd></div>
    </dl>
    <div id="rc_maint_policy" class="muted" style="margin-top:8px;"></div>
</div>

<div id="rc_progress" class="rc-progress" role="status" aria-live="polite">جاري التحميل…</div>
<div id="rc_alert" class="card" style="display:none;margin-bottom:12px;"></div>

<div class="rc-section card">
    <h3>نظرة عامة</h3>
    <div id="rc_overview" class="rc-grid">
        <div class="rc-card"><h4>جاري التحميل…</h4></div>
    </div>
    <dl id="rc_lock_maintenance" class="rc-status-strip">
        <div><dt>قفل الاسترداد العام</dt><dd>…</dd></div>
        <div><dt>وضع الصيانة</dt><dd>…</dd></div>
    </dl>
</div>

<?php if ($canFull): ?>
<div class="rc-section card">
    <h3>حزم Full Backup المتاحة للاسترداد</h3>
    <div class="table-wrap">
        <table id="rc_full_table">
            <thead>
                <tr>
                    <th>الوقت</th>
                    <th>الحزمة</th>
                    <th>الحالة</th>
                    <th>Schema</th>
                    <th>Backend</th>
                    <th>DRV</th>
                    <th>أهلية الاسترداد</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="8" class="muted">…</td></tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($canCountry): ?>
<div class="rc-section card">
    <h3>حزم Country المتاحة للاسترداد</h3>
    <div class="table-wrap">
        <table id="rc_country_table">
            <thead>
                <tr>
                    <th>الدولة</th>
                    <th>الوقت</th>
                    <th>الحزمة</th>
                    <th>الحالة</th>
                    <th>Schema</th>
                    <th>Registry</th>
                    <th>DRV</th>
                    <th>أهلية الاسترداد</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="9" class="muted">…</td></tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="rc-section card">
    <h3>Restore Jobs</h3>
    <div class="rc-actions">
        <button type="button" class="btn-secondary" id="rc_refresh_btn">تحديث</button>
    </div>
    <div class="table-wrap">
        <table id="rc_jobs_table">
            <thead>
                <tr>
                    <th>Job ID</th>
                    <th>Package</th>
                    <th>Created</th>
                    <th>Status</th>
                    <th>Phase</th>
                    <th>Progress</th>
                    <th>Message</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="8" class="muted">…</td></tr></tbody>
        </table>
    </div>
</div>

<div id="rc_view_modal" class="rc-modal-backdrop" aria-hidden="true">
    <div class="rc-modal" role="dialog" aria-modal="true" style="max-width:760px;">
        <h3 id="rc_view_title">عرض</h3>
        <pre id="rc_view_pre" class="rc-pre"></pre>
        <div class="admin-form-actions"><button type="button" class="btn-secondary" id="rc_view_close">إغلاق</button></div>
    </div>
</div>

<div id="rc_detail_modal" class="rc-modal-backdrop" aria-hidden="true">
    <div class="rc-modal rc-modal--wide" role="dialog" aria-modal="true">
        <h3 id="rc_detail_title">تفاصيل المهمة</h3>
        <div id="rc_detail_body"></div>
        <div class="admin-form-actions"><button type="button" class="btn-secondary" id="rc_detail_close">إغلاق</button></div>
    </div>
</div>

<script>
(function () {
    const API_BASE = <?php echo json_encode($apiBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const CAN_FULL = <?php echo $canFull ? 'true' : 'false'; ?>;
    const CAN_COUNTRY = <?php echo $canCountry ? 'true' : 'false'; ?>;

    let state = { full: [], country: [], jobs: [], busy: false, csrf: '' };

    const el = (id) => document.getElementById(id);
    const fmtTimestampDisplay = (raw) => {
        const s = String(raw || '').trim();
        if (!s) return '—';
        const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        const m = s.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})?$/);
        if (!m) {
            return '<time class="rc-ts rc-ts--raw" datetime="' + esc(s) + '" title="' + esc(s) + '">' + esc(s) + '</time>';
        }
        let offset = m[3] || '';
        if (offset === 'Z') offset = '+00:00';
        const timePart = m[2] + (offset ? ' ' + offset : '');
        return '<time class="rc-ts" datetime="' + esc(s) + '" title="' + esc(s) + '"><span class="rc-ts-date">' + esc(m[1]) + '</span><span class="rc-ts-time">' + esc(timePart) + '</span></time>';
    };
    const badge = (status) => {
        const s = String(status || '').toLowerCase();
        let cls = 'rc-badge--muted';
        if (s === 'healthy' || s === 'success' || s === 'pass' || s === 'eligible' || s === 'completed' || s === 'dry_completed' || s === 'approved_waiting_execution' || s === 'pre_restore_backup_ready' || s === 'shadow_restore_ready' || s === 'shadow_verified' || s === 'shadow_files_ready' || s === 'shadow_smoke_ready' || s === 'cutover_readiness_ready') cls = 'rc-badge--success';
        else if (s === 'warning' || s === 'warn' || s === 'awaiting_owner_approval' || s === 'awaiting_final_approval' || s === 'waiting_confirmation' || s === 'execution_plan_ready' || s === 'pre_restore_backup_pending' || s === 'shadow_restore_pending' || s === 'shadow_not_ready' || s === 'shadow_smoke_pending' || s === 'shadow_smoke_warning' || s === 'cutover_readiness_manual_review') cls = 'rc-badge--warning';
        else if (s === 'failed' || s === 'fail' || s === 'error' || s === 'not_eligible' || s === 'dry_failed' || s === 'execution_failed' || s === 'execution_cancelled' || s === 'cancelled' || s === 'pre_restore_backup_failed' || s === 'shadow_restore_failed' || s === 'shadow_files_failed' || s === 'shadow_smoke_failed' || s === 'cutover_readiness_blocked') cls = 'rc-badge--failed';
        else if (s === 'running' || s.includes('progress') || s.includes('staging') || s.includes('merge') || s === 'execution_precheck' || s === 'dry_running' || s === 'pre_restore_backup_running' || s === 'pre_restore_backup_verifying' || s === 'shadow_restore_running' || s === 'shadow_restore_verifying' || s === 'shadow_verifying' || s === 'shadow_files_running' || s === 'shadow_files_verifying' || s === 'shadow_smoke_running') cls = 'rc-badge--running';
        let label = status || '—';
        if (s === 'awaiting_final_approval') label = 'بانتظار الموافقة النهائية';
        if (s === 'approved_waiting_execution') label = 'معتمدة — بانتظار التنفيذ';
        if (s === 'pre_restore_backup_pending') label = 'بانتظار تشغيل عامل CLI';
        if (s === 'pre_restore_backup_running') label = 'جارٍ إنشاء النسخة الاحتياطية';
        if (s === 'pre_restore_backup_verifying') label = 'جارٍ التحقق';
        if (s === 'pre_restore_backup_ready') label = 'النسخة الاحتياطية جاهزة وآمنة للرجوع';
        if (s === 'pre_restore_backup_failed') label = 'فشل إعداد النسخة الاحتياطية';
        if (s === 'shadow_restore_pending') label = 'بانتظار تشغيل عامل CLI لقاعدة الظل';
        if (s === 'shadow_restore_running') label = 'جارٍ استيراد قاعدة الظل';
        if (s === 'shadow_restore_verifying') label = 'جارٍ التحقق من قاعدة الظل';
        if (s === 'shadow_restore_ready') label = 'قاعدة الظل جاهزة (الإنتاج لم يُمس)';
        if (s === 'shadow_restore_failed') label = 'فشل استعادة قاعدة الظل';
        if (s === 'shadow_verifying') label = 'جارٍ التحقق العميق من قاعدة الظل';
        if (s === 'shadow_verified') label = 'قاعدة الظل موثّقة وجاهزة (بدون قطع إنتاج)';
        if (s === 'shadow_not_ready') label = 'قاعدة الظل غير جاهزة للقطع';
        if (s === 'shadow_files_running') label = 'جارٍ استخراج ملفات الظل';
        if (s === 'shadow_files_verifying') label = 'جارٍ التحقق من ملفات الظل';
        if (s === 'shadow_files_ready') label = 'ملفات الظل جاهزة (الإنتاج لم يُمس)';
        if (s === 'shadow_files_failed') label = 'فشل استخراج ملفات الظل';
        if (s === 'shadow_smoke_pending') label = 'بانتظار تشغيل اختبار CLI';
        if (s === 'shadow_smoke_running') label = 'جارٍ اختبار قاعدة البيانات والملفات المعزولة';
        if (s === 'shadow_smoke_ready') label = 'البيئة المعزولة جاهزة';
        if (s === 'shadow_smoke_warning') label = 'تحتاج مراجعة يدوية';
        if (s === 'shadow_smoke_failed') label = 'البيئة غير جاهزة';
        if (s === 'cutover_readiness_ready') label = 'البيئة المعزولة جاهزة';
        if (s === 'cutover_readiness_manual_review') label = 'تحتاج مراجعة يدوية';
        if (s === 'cutover_readiness_blocked') label = 'البيئة غير جاهزة';
        return '<span class="rc-badge ' + cls + '">' + label + '</span>';
    };
    const eligibilityBadge = (pkg) => {
        const status = String(pkg.eligibility_status || pkg.restore_eligibility || '');
        const labelAr = pkg.eligibility_reason_label_ar || '';
        let text = 'غير مؤهلة';
        let cls = 'rc-badge--failed';
        if (status === 'eligible') {
            text = 'مؤهلة';
            cls = 'rc-badge--success';
        } else if (status === 'unknown') {
            text = 'غير محسومة';
            cls = 'rc-badge--warning';
        }
        const title = labelAr ? ' title="' + String(labelAr).replace(/"/g, '&quot;') + '"' : '';
        return '<span class="rc-badge ' + cls + '"' + title + '>' + text + '</span>';
    };
    const drvCell = (pkg) => {
        const result = String(pkg.drv_result || '').toLowerCase();
        if (result === 'pass') return badge('PASS');
        if (result === 'fail') return badge('FAIL');
        if (result === 'missing') return '—';
        const score = pkg.drv_score;
        if (score === null || score === undefined || score === '') return '—';
        return String(score);
    };
    const showAlert = (msg, ok) => {
        const box = el('rc_alert');
        box.style.display = 'block';
        box.innerHTML = '<div class="' + (ok ? 'alert-success' : 'alert-error') + '">' + msg + '</div>';
    };
    const setBusy = (on, text) => {
        state.busy = on;
        el('rc_progress').style.display = on ? 'block' : 'none';
        if (text) el('rc_progress').textContent = text;
        if (el('rc_refresh_btn')) el('rc_refresh_btn').disabled = on;
    };

    async function parseApiJsonResponse(r) {
        const raw = await r.text();
        const ct = (r.headers.get('Content-Type') || '').toLowerCase();
        const looksHtml = ct.includes('text/html') || /^\s*</.test(raw);
        if (looksHtml) {
            throw new Error('\u0627\u0633\u062a\u062c\u0627\u0628 \u0627\u0644\u062e\u0627\u062f\u0645 \u0628\u0635\u064a\u063a\u0629 \u063a\u064a\u0631 \u0645\u062a\u0648\u0642\u0639\u0629. \u0631\u0627\u062c\u0639 \u0633\u062c\u0644 \u0627\u0644\u0623\u062e\u0637\u0627\u0621.');
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            throw new Error('\u0627\u0633\u062a\u062c\u0627\u0628 \u0627\u0644\u062e\u0627\u062f\u0645 \u0628\u0635\u064a\u063a\u0629 \u063a\u064a\u0631 \u0645\u062a\u0648\u0642\u0639\u0629. \u0631\u0627\u062c\u0639 \u0633\u062c\u0644 \u0627\u0644\u0623\u062e\u0637\u0627\u0621.');
        }
    }

    async function apiGet(path) {
        const r = await fetch(API_BASE + '/' + path, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const j = await parseApiJsonResponse(r);
        if (!j.success && r.status >= 400) throw new Error(j.message || 'Request failed');
        return j;
    }

    async function apiPost(path, body) {
        const r = await fetch(API_BASE + '/' + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {})
        });
        const j = await parseApiJsonResponse(r);
        if (!j.success && r.status >= 400) {
            const err = new Error(j.message || 'Request failed');
            err.code = j.code || '';
            throw err;
        }
        return j;
    }

    function renderOverview(data) {
        const ov = data.overview || {};
        const counts = ov.job_counts || {};
        const cards = [
            ['إجمالي Restore Jobs', String(counts.total_jobs ?? 0)],
            ['بانتظار موافقة المالك', String(counts.awaiting_owner_approval ?? 0)],
            ['معتمد للدمج', String(counts.approved_for_merge ?? 0)],
            ['نشطة / قيد التنفيذ', String(counts.active_in_progress ?? 0)],
            ['فاشلة', String(counts.failed_jobs ?? 0)],
            ['مكتملة', String(counts.completed_jobs ?? 0)],
            ['مسترجعة (rolled back)', String(counts.rolled_back_jobs ?? 0)]
        ];
        el('rc_overview').innerHTML = cards.map(([t, v]) =>
            '<div class="rc-card"><h4>' + t + '</h4><div class="rc-val">' + v + '</div></div>'
        ).join('');
        const lock = ov.restore_lock || {};
        const maint = ov.maintenance || {};
        el('rc_lock_maintenance').innerHTML =
            '<div><dt>قفل الاسترداد العام</dt><dd>' + (lock.held ? badge('held — ' + (lock.job_id || '')) : badge('متاح')) + '</dd></div>' +
            '<div><dt>وضع الصيانة (قديم/دمج)</dt><dd>' + (maint.active ? badge('active — ' + (maint.job_id || '')) : badge('غير مفعّل')) + '</dd></div>';
    }

    function renderMaintenance(m) {
        const st = m || {};
        if (!el('rc_maint_status')) return;
        let label = st.label || st.state || 'inactive';
        if (st.maintenance_active) label = 'Maintenance Active';
        else if (st.maintenance_ready) label = 'Maintenance Ready';
        el('rc_maint_status').innerHTML =
            '<div><dt>الحالة</dt><dd>' + badge(st.state || 'inactive') + '</dd></div>' +
            '<div><dt>الملصق</dt><dd><strong>' + label + '</strong></dd></div>' +
            '<div><dt>المهمة المرتبطة</dt><dd>' + (st.related_job_id || '—') + '</dd></div>' +
            '<div><dt>وقت الطلب</dt><dd class="rc-ts-cell">' + fmtTimestampDisplay(st.requested_at) + '</dd></div>' +
            '<div><dt>وقت التفعيل</dt><dd class="rc-ts-cell">' + fmtTimestampDisplay(st.activated_at) + '</dd></div>' +
            '<div><dt>آخر نبضة</dt><dd class="rc-ts-cell">' + fmtTimestampDisplay(st.heartbeat_at) + '</dd></div>' +
            '<div><dt>Stale</dt><dd>' + (st.stale ? badge('stale — no auto-release') : badge('fresh')) + '</dd></div>';
        if (el('rc_maint_banner')) {
            el('rc_maint_banner').innerHTML = '<strong>Production restore has NOT started.</strong>'
                + (st.stale ? ' <span class="rc-badge rc-badge--warning">Maintenance heartbeat stale — never auto-released.</span>' : '');
        }
        const scopes = Array.isArray(st.blocked_write_scopes) ? st.blocked_write_scopes.join(', ') : '';
        el('rc_maint_policy').textContent = 'سياسة القراءة الآمنة: ' + (st.safe_read_policy || '—')
            + (scopes ? ' | نطاقات الكتابة المحظورة عند التفعيل: ' + scopes : '')
            + ' | auto_release_forbidden=true'
            + ' | ' + (st.warning || 'Production restore has NOT started.');
    }

    function packageActions(pkg, type) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        let html = '';
        const files = type === 'full_disaster'
            ? [['manifest.json', 'Manifest'], ['health.json', 'Health'], ['recovery_validation.json', 'DRV Report']]
            : [['manifest.json', 'Manifest'], ['health.json', 'Health'], ['recovery_validation.json', 'DRV Report']];
        files.forEach(([file, label]) => {
            html += '<button type="button" class="btn-link rc-view-file" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '" data-file="' + file + '">' + label + '</button> ';
        });
        html += '<button type="button" class="btn-link rc-pkg-detail" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">عرض تفاصيل الحزمة</button>';
        if ((pkg.eligibility_status || pkg.restore_eligibility) === 'eligible') {
            html += ' <button type="button" class="btn-link rc-create-job" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">إنشاء مهمة</button>';
            html += ' <button type="button" class="btn-link rc-dry-run" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">Run Dry Validation</button>';
        }
        return html;
    }

    function dryResultBadge(result) {
        const r = String(result || '').toUpperCase();
        if (r === 'PASS') return '<span class="rc-badge rc-badge--success">PASS</span>';
        if (r === 'WARNING') return '<span class="rc-badge rc-badge--warning">WARNING</span>';
        if (r === 'FAIL') return '<span class="rc-badge rc-badge--failed">FAIL</span>';
        return '<span class="rc-badge rc-badge--muted">—</span>';
    }

    function jobActions(job) {
        const id = job.job_id;
        let html = '<button type="button" class="btn-link rc-fw-view" data-id="' + id + '">View</button> ';
        if (job.dry_run_available) {
            html += '<button type="button" class="btn-link rc-dry-run" data-job="' + id + '">Run Dry Validation</button> ';
        }
        if (job.has_dry_run_report) {
            html += '<button type="button" class="btn-link rc-dry-report" data-id="' + id + '">View Dry Report</button> ';
        }
        if (job.prepare_execution_available) {
            html += '<button type="button" class="btn-link rc-prepare-exec" data-id="' + id + '">إعداد خطة الاسترداد</button> ';
        }
        if (job.has_execution_plan) {
            html += '<button type="button" class="btn-link rc-exec-plan" data-id="' + id + '">عرض خطة الاسترداد</button> ';
        }
        if (job.final_approval_available) {
            html += '<button type="button" class="btn-link rc-final-approve" data-id="' + id + '" data-pkg="' + (job.package_id || '') + '">الموافقة النهائية</button> ';
        }
        if (job.is_approved_waiting_execution) {
            html += '<span class="muted">تم اعتماد الخطة، لكن لم يبدأ الاسترداد ولم يتم تفعيل وضع الصيانة.</span> ';
        }
        if (job.has_execution_contract) {
            html += '<button type="button" class="btn-link rc-exec-contract" data-id="' + id + '">View Execution Contract</button> ';
        }
        if (job.pre_restore_backup_requestable) {
            html += '<button type="button" class="btn-link rc-pre-backup-req" data-id="' + id + '">إعداد النسخة الاحتياطية الإلزامية قبل الاسترداد</button> ';
        }
        if (job.has_pre_restore_backup) {
            html += '<button type="button" class="btn-link rc-pre-backup-view" data-id="' + id + '">عرض حالة النسخة الاحتياطية</button> ';
        }
        if (job.shadow_restore_requestable) {
            html += '<button type="button" class="btn-link rc-shadow-req" data-id="' + id + '">استعادة قاعدة الظل (Shadow DB)</button> ';
        }
        if (job.has_shadow_restore) {
            html += '<button type="button" class="btn-link rc-shadow-view" data-id="' + id + '">عرض تقرير قاعدة الظل</button> ';
        }
        if (job.shadow_verification_runnable || job.has_shadow_verification) {
            html += '<button type="button" class="btn-link rc-shadow-verify-view" data-id="' + id + '">عرض تحقق الجاهزية (Shadow)</button> ';
        }
        if (job.shadow_files_runnable || job.has_shadow_files) {
            html += '<button type="button" class="btn-link rc-shadow-files-view" data-id="' + id + '">عرض ملفات الظل</button> ';
        }
        if (job.shadow_smoke_requestable) {
            html += '<button type="button" class="btn-link rc-shadow-smoke-req" data-id="' + id + '">تشغيل اختبارات الجاهزية المعزولة</button> ';
        }
        if (job.has_shadow_smoke || job.has_cutover_readiness) {
            html += '<button type="button" class="btn-link rc-shadow-smoke-view" data-id="' + id + '">عرض اختبار الجاهزية / قرار التحويل</button> ';
        }
        if (job.maintenance_requestable) {
            html += '<button type="button" class="btn-link rc-maint-req" data-id="' + id + '">طلب تفعيل الصيانة</button> ';
        }
        if (job.maintenance_activatable || job.is_maintenance_ready) {
            html += '<button type="button" class="btn-link rc-maint-activate" data-id="' + id + '">تفعيل الصيانة</button> ';
        }
        if (job.is_maintenance_ready) {
            html += '<span class="rc-badge rc-badge--warning">Maintenance Ready</span> ';
            html += '<strong class="muted">Production restore has NOT started.</strong> ';
        }
        if (job.is_maintenance_active) {
            html += '<span class="rc-badge rc-badge--failed">Maintenance Active</span> ';
            html += '<strong>Production restore has NOT started.</strong> ';
        }
        if (job.is_maintenance_ready || job.is_maintenance_active || job.maintenance_requestable) {
            html += '<button type="button" class="btn-link rc-maint-state" data-id="' + id + '">حالة الصيانة</button> ';
        }
        if (job.execution_plan_cancellable) {
            html += '<button type="button" class="btn-link rc-cancel-exec" data-id="' + id + '">إلغاء الخطة</button> ';
        }
        if (job.cancellable) {
            html += '<button type="button" class="btn-link rc-fw-cancel" data-id="' + id + '">Cancel</button>';
        }
        return html;
    }

    function renderTables(data) {
        state.full = data.full_packages || [];
        state.country = data.country_packages || [];
        state.jobs = data.framework_jobs || data.jobs || [];
        if (data.csrf_token) state.csrf = data.csrf_token;

        if (CAN_FULL && el('rc_full_table')) {
            el('rc_full_table').querySelector('tbody').innerHTML = state.full.length
                ? state.full.map((p) => '<tr><td class="rc-ts-cell">' + fmtTimestampDisplay(p.generated_at) + '</td><td>' + p.package_id + '</td><td>' + badge(p.package_status) + '</td><td>' + p.schema_revision + '</td><td>' + (p.backend || '') + '</td><td>' + drvCell(p) + '</td><td>' + eligibilityBadge(p) + '</td><td class="rc-actions">' + packageActions(p, 'full_disaster') + '</td></tr>').join('')
                : '<tr><td colspan="8" class="muted">لا توجد حزم Full.</td></tr>';
        }
        if (CAN_COUNTRY && el('rc_country_table')) {
            el('rc_country_table').querySelector('tbody').innerHTML = state.country.length
                ? state.country.map((p) => '<tr><td>' + (p.country_code || '') + (p.country_name ? ' — ' + p.country_name : '') + '</td><td class="rc-ts-cell">' + fmtTimestampDisplay(p.generated_at) + '</td><td>' + p.package_id + '</td><td>' + badge(p.package_status) + '</td><td>' + p.schema_revision + '</td><td>' + (p.registry_version || '') + '</td><td>' + drvCell(p) + '</td><td>' + eligibilityBadge(p) + '</td><td class="rc-actions">' + packageActions(p, 'country_recovery') + '</td></tr>').join('')
                : '<tr><td colspan="9" class="muted">لا توجد حزم دول.</td></tr>';
        }
        el('rc_jobs_table').querySelector('tbody').innerHTML = state.jobs.length
            ? state.jobs.map((j) => {
                const pkgLabel = (j.package_type || '') + (j.country_code ? ' / ' + j.country_code : '') + ' / ' + (j.package_id || '—');
                const dryBadge = j.dry_run_overall_result ? ' ' + dryResultBadge(j.dry_run_overall_result) : '';
                return '<tr><td><code>' + j.job_id + '</code></td><td>' + pkgLabel + '</td><td class="rc-ts-cell">' + fmtTimestampDisplay(j.created_at) + '</td><td>' + badge(j.status) + dryBadge + '</td><td>' + (j.phase || '—') + '</td><td>' + String(j.progress ?? 0) + '%</td><td>' + (j.message || '—') + '</td><td class="rc-actions">' + jobActions(j) + '</td></tr>';
            }).join('')
            : '<tr><td colspan="8" class="muted">لا توجد Restore Jobs.</td></tr>';
    }

    function openView(title, content) {
        el('rc_view_title').textContent = title;
        el('rc_view_pre').textContent = content;
        el('rc_view_modal').style.display = 'flex';
    }

    function renderJobDetail(job) {
        const lines = [];
        lines.push('Job ID: ' + job.job_id);
        lines.push('Status: ' + job.status);
        lines.push('Package checksum: ' + ((job.package || {}).checksum || '—'));
        lines.push('Staging manifest checksum: ' + ((job.staging || {}).manifest_checksum || '—'));
        lines.push('Rollback anchor checksum: ' + ((job.rollback_anchor || {}).checksum || '—'));
        lines.push('Approval status: ' + ((job.approval || {}).status || '—'));
        lines.push('Token consumed: ' + (((job.approval || {}).token_consumed) ? 'yes' : 'no'));
        lines.push('Maintenance active: ' + (((job.maintenance || {}).active) ? 'yes' : 'no'));
        lines.push('Lock held: ' + (((job.lock || {}).held) ? 'yes' : 'no'));
        lines.push('DB cutover completed: ' + ((job.database_cutover || {}).completed_at || '—'));
        lines.push('Uploads cutover completed: ' + ((job.uploads_cutover || {}).completed_at || '—'));
        lines.push('Post-validation: ' + ((job.post_validation || {}).passed_at || '—'));
        lines.push('\n--- Timeline ---');
        (job.timeline || []).forEach((t) => {
            lines.push((t.at || '') + '  ' + (t.event || '') + (t.result ? ' (' + t.result + ')' : ''));
        });
        lines.push('\n--- Rollback checkpoints ---');
        lines.push(JSON.stringify(job.rollback_checkpoints || {}, null, 2));
        return lines.join('\n');
    }

    function openJobDetail(job) {
        el('rc_detail_title').textContent = 'تفاصيل المهمة — ' + job.job_id;
        el('rc_detail_body').innerHTML = '<pre class="rc-pre">' + renderJobDetail(job).replace(/</g, '&lt;') + '</pre>';
        el('rc_detail_modal').style.display = 'flex';
    }

    async function loadAll() {
        setBusy(true, 'جاري تحميل البيانات…');
        try {
            const data = await apiGet('list.php');
            if (!data.read_only) {
                showAlert('تحذير: الاستجابة ليست للعرض فقط.', false);
            }
            renderOverview(data);
            renderMaintenance(data.maintenance || {});
            renderTables(data);
        } catch (e) {
            showAlert(e.message || 'تعذر التحميل', false);
        } finally {
            setBusy(false);
        }
    }

    document.addEventListener('click', async (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLElement)) return;

        if (t.id === 'rc_refresh_btn') {
            await loadAll();
            return;
        }
        if (t.id === 'rc_view_close') {
            el('rc_view_modal').style.display = 'none';
            return;
        }
        if (t.id === 'rc_detail_close') {
            el('rc_detail_modal').style.display = 'none';
            return;
        }

        if (t.classList.contains('rc-view-file')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const q = 'status.php?action=view_file&package_type=' + encodeURIComponent(t.dataset.type || '') +
                    '&package_id=' + encodeURIComponent(t.dataset.id || '') +
                    '&country_code=' + encodeURIComponent(t.dataset.cc || '') +
                    '&file=' + encodeURIComponent(t.dataset.file || '');
                const j = await apiGet(q);
                const body = j.data ? JSON.stringify(j.data, null, 2) : (j.raw_text || j.errors?.join('\n') || '');
                openView(t.dataset.file || 'file', body);
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-pkg-detail')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const q = 'status.php?action=package_detail&package_type=' + encodeURIComponent(t.dataset.type || '') +
                    '&package_id=' + encodeURIComponent(t.dataset.id || '') +
                    '&country_code=' + encodeURIComponent(t.dataset.cc || '');
                const j = await apiGet(q);
                openView('Package details', JSON.stringify(j.package || {}, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-create-job')) {
            try {
                setBusy(true, 'جاري إنشاء المهمة…');
                const j = await apiPost('job/create.php', {
                    csrf_token: state.csrf,
                    package_type: t.dataset.type || '',
                    package_id: t.dataset.id || '',
                    country_code: t.dataset.cc || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert('تم إنشاء المهمة وتوقفت عند انتظار التأكيد.', true);
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر إنشاء المهمة', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-dry-run')) {
            try {
                setBusy(true, 'جاري Dry Validation…');
                const payload = { csrf_token: state.csrf };
                if (t.dataset.job) {
                    payload.job_id = t.dataset.job;
                } else {
                    payload.package_type = t.dataset.type || '';
                    payload.package_id = t.dataset.id || '';
                    payload.country_code = t.dataset.cc || '';
                }
                const j = await apiPost('job/dry-run.php', payload);
                if (j.csrf_token) state.csrf = j.csrf_token;
                const overall = ((j.report || {}).overall_result || '').toUpperCase();
                showAlert('Dry Validation: ' + (overall || 'DONE'), overall === 'FAIL' ? false : true);
                if (j.report) {
                    openView('Dry Report — ' + ((j.job || {}).job_id || ''), JSON.stringify(j.report, null, 2));
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر Dry Validation', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-dry-report')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/dry-report.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('Dry Report — ' + (t.dataset.id || ''), JSON.stringify(j.report || {}, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-fw-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/view.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('Restore Job — ' + (t.dataset.id || ''), JSON.stringify(j.job || {}, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-prepare-exec')) {
            try {
                setBusy(true, 'جاري إعداد خطة الاسترداد…');
                const j = await apiPost('job/prepare-execution.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert('تم إعداد الخطة — بانتظار الموافقة النهائية. لم يتم تنفيذ أي استرداد حتى الآن.', true);
                if (j.plan) {
                    openView('خطة الاسترداد — ' + (t.dataset.id || ''), JSON.stringify(j.plan, null, 2));
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر إعداد الخطة', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-exec-plan')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/execution-plan.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('خطة الاسترداد — ' + (t.dataset.id || ''), JSON.stringify(j.plan || {}, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-exec-contract')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/execution-contract.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView(
                    'Execution Contract — ' + (t.dataset.id || ''),
                    JSON.stringify({
                        contract: j.contract || {},
                        validation: j.validation || {},
                        execution_started: false,
                        warning: j.warning || ''
                    }, null, 2)
                );
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-pre-backup-req')) {
            try {
                setBusy(true, 'جاري طلب إعداد النسخة الاحتياطية…');
                const j = await apiPost('job/request-pre-restore-backup.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert(
                    (j.message || 'تم الطلب') + (j.cli_needed ? ' — يلزم تشغيل عامل CLI.' : ''),
                    true
                );
                if (j.record) {
                    openView('النسخة الاحتياطية قبل الاسترداد — ' + (t.dataset.id || ''), JSON.stringify({
                        record: j.record,
                        cli_needed: !!j.cli_needed,
                        execution_started: false,
                        warning: j.warning || 'لن يبدأ الاسترداد قبل إنشاء نسخة Full احتياطية موثقة ومثبتة ضد الحذف.'
                    }, null, 2));
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر الطلب', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-pre-backup-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/pre-restore-backup.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('النسخة الاحتياطية قبل الاسترداد — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label_ar: j.status_label_ar || '',
                    record: j.record || {},
                    execution_started: false,
                    warning: j.warning || 'لن يبدأ الاسترداد قبل إنشاء نسخة Full احتياطية موثقة ومثبتة ضد الحذف.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-shadow-req')) {
            try {
                setBusy(true, 'جاري طلب استعادة قاعدة الظل…');
                const j = await apiPost('job/request-shadow-restore.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert(
                    (j.message || 'تم الطلب') + (j.cli_needed ? ' — يلزم تشغيل عامل CLI.' : ''),
                    true
                );
                if (j.meta) {
                    openView('قاعدة الظل — ' + (t.dataset.id || ''), JSON.stringify({
                        meta: j.meta,
                        cli_needed: !!j.cli_needed,
                        production_touched: false,
                        execution_started: false,
                        warning: j.warning || 'Shadow restore only — production database will not be modified.'
                    }, null, 2));
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر الطلب', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-shadow-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/shadow-restore.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('تقرير قاعدة الظل — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label_ar: j.status_label_ar || '',
                    meta: j.meta || {},
                    report: j.report || {},
                    production_touched: false,
                    execution_started: false,
                    warning: j.warning || 'Shadow restore only — production database was not modified.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-shadow-verify-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/shadow-verification.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('تحقق جاهزية قاعدة الظل — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label_ar: j.status_label_ar || '',
                    cli_needed: !!j.cli_needed,
                    cli_command: j.cli_command || '',
                    meta: j.meta || {},
                    report: j.report || {},
                    production_touched: false,
                    execution_started: false,
                    warning: j.warning || 'Shadow verification only — production database was not modified. HTTP is read-only; run CLI to verify.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-shadow-files-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/shadow-files.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('ملفات الظل — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label_ar: j.status_label_ar || '',
                    cli_needed: !!j.cli_needed,
                    cli_command: j.cli_command || '',
                    meta: j.meta || {},
                    report: j.report || {},
                    production_touched: false,
                    directories_renamed: false,
                    execution_started: false,
                    warning: j.warning || 'Shadow file restore only — production filesystem was not modified. HTTP is read-only; run CLI to extract.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-shadow-smoke-req')) {
            try {
                setBusy(true, 'جاري طلب اختبارات الجاهزية المعزولة…');
                const j = await apiPost('job/request-shadow-smoke.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert(
                    (j.message || 'تم الطلب') + (j.cli_needed ? ' — يلزم تشغيل عامل CLI.' : ''),
                    true
                );
                if (j.meta) {
                    openView('اختبار الجاهزية المعزولة — ' + (t.dataset.id || ''), JSON.stringify({
                        meta: j.meta,
                        cli_needed: !!j.cli_needed,
                        production_touched: false,
                        production_cutover_allowed: false,
                        execution_started: false,
                        warning: j.warning || 'لم يتم تعديل قاعدة الإنتاج أو ملفات الإنتاج، ولا يزال التحويل إلى الإنتاج غير مسموح.'
                    }, null, 2));
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر الطلب', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-shadow-smoke-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/shadow-smoke.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('اختبار الجاهزية / قرار التحويل — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label_ar: j.status_label_ar || '',
                    cli_needed: !!j.cli_needed,
                    cli_command: j.cli_command || '',
                    meta: j.meta || {},
                    report: j.report || {},
                    cutover_readiness: j.cutover_readiness || {},
                    production_touched: false,
                    production_cutover_allowed: false,
                    execution_started: false,
                    warning: j.warning || 'لم يتم تعديل قاعدة الإنتاج أو ملفات الإنتاج، ولا يزال التحويل إلى الإنتاج غير مسموح.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-maint-req')) {
            try {
                setBusy(true, 'جاري طلب تفعيل الصيانة…');
                const j = await apiPost('job/request-maintenance.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert((j.message || 'Maintenance Ready') + ' — Production restore has NOT started.', true);
                if (j.challenge && j.challenge.nonce) {
                    state.maintNonce = state.maintNonce || {};
                    state.maintNonce[t.dataset.id || ''] = j.challenge.nonce;
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر طلب الصيانة', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-maint-activate')) {
            try {
                const jobId = t.dataset.id || '';
                let nonce = (state.maintNonce && state.maintNonce[jobId]) || '';
                if (!nonce) {
                    const req = await apiPost('job/request-maintenance.php', {
                        csrf_token: state.csrf,
                        job_id: jobId
                    });
                    if (req.csrf_token) state.csrf = req.csrf_token;
                    nonce = (req.challenge && req.challenge.nonce) || '';
                    state.maintNonce = state.maintNonce || {};
                    state.maintNonce[jobId] = nonce;
                }
                const password = window.prompt('كلمة مرور إعادة التحقق لتفعيل الصيانة (مطلوبة):', '');
                if (password === null || password === '') {
                    showAlert('recent_authentication_not_available', false);
                    return;
                }
                setBusy(true, 'جاري تفعيل إطار الصيانة…');
                const j = await apiPost('job/activate-maintenance.php', {
                    csrf_token: state.csrf,
                    job_id: jobId,
                    password: password,
                    nonce: nonce
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert('Maintenance Active — Production restore has NOT started.', true);
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر تفعيل الصيانة', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-maint-state')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/maintenance-state.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('Maintenance State — ' + (t.dataset.id || ''), JSON.stringify({
                    maintenance: j.maintenance || {},
                    job: j.job || {},
                    record: j.record || {},
                    stale: !!j.stale,
                    auto_release_forbidden: true,
                    execution_started: false,
                    restore_started: false,
                    warning: 'Production restore has NOT started.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-final-approve')) {
            try {
                setBusy(true, 'جاري تجهيز تحدي الموافقة…');
                const ch = await apiPost('job/create-approval-challenge.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (ch.csrf_token) state.csrf = ch.csrf_token;
                const challenge = ch.challenge || {};
                const phrase = challenge.required_confirmation_phrase || '';
                const typed = window.prompt(
                    'أعد كتابة العبارة بالضبط ثم أدخل كلمة مرور إعادة التحقق.\n\nالعبارة:\n' + phrase + '\n\nالصق العبارة هنا:',
                    ''
                );
                if (typed === null) return;
                const password = window.prompt('كلمة مرور إعادة التحقق (مطلوبة):', '');
                if (password === null || password === '') {
                    showAlert('recent_authentication_not_available', false);
                    return;
                }
                setBusy(true, 'جاري اعتماد الخطة…');
                const j = await apiPost('job/final-approve.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || '',
                    package_id: t.dataset.pkg || challenge.package_id || '',
                    confirmation_phrase: typed,
                    nonce: challenge.nonce || '',
                    password: password
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert(j.message || 'تم اعتماد الخطة، لكن لم يبدأ الاسترداد ولم يتم تفعيل وضع الصيانة.', true);
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر الاعتماد', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-cancel-exec')) {
            if (!window.confirm('إلغاء خطة الاسترداد؟ لن يُنفَّذ أي استرداد.')) return;
            try {
                setBusy(true, 'جاري إلغاء الخطة…');
                const j = await apiPost('job/cancel-execution.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert('تم إلغاء الخطة. لم يتم تنفيذ أي استرداد.', true);
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر إلغاء الخطة', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-fw-cancel')) {
            if (!window.confirm('إلغاء مهمة الاسترداد؟')) return;
            try {
                setBusy(true, 'جاري الإلغاء…');
                const j = await apiPost('job/cancel.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert('تم إلغاء المهمة.', true);
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر الإلغاء', false);
            } finally {
                setBusy(false);
            }
        }
    });

    loadAll();
})();
</script>
