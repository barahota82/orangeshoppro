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

orange_admin_render_page_title_with_country('إدارة النسخ الاحتياطي', $pdo);
?>
<style>
/* Orange Enterprise Backup Center V2 — page-scoped only */
.bc-v2{--bc-border:#e2e8f0;--bc-muted:#64748b;--bc-surface:#fff;--bc-soft:#f8fafc;--bc-ink:#0f172a;--bc-ok:#047857;--bc-warn:#b45309;--bc-bad:#b91c1c;--bc-info:#1d4ed8}
.bc-v2 *{box-sizing:border-box}
.bc-header{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;padding:16px 18px;background:var(--bc-surface);border:1px solid var(--bc-border);border-radius:12px}
.bc-header-main{min-width:0;flex:1}
.bc-header-kicker{margin:0 0 4px;font-size:.78rem;font-weight:600;color:var(--bc-muted);letter-spacing:.02em}
.bc-header-sub{margin:0;font-size:.92rem;color:var(--bc-muted);line-height:1.5;max-width:42rem}
.bc-header-status{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.bc-header-status-label{font-size:.75rem;color:var(--bc-muted)}
.bc-overview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}
@media (max-width:1024px){.bc-overview{grid-template-columns:1fr}}
.bc-op-card{background:var(--bc-surface);border:1px solid var(--bc-border);border-radius:12px;padding:14px 16px;min-width:0}
.bc-op-card h3{margin:0 0 12px;font-size:.95rem;font-weight:700;color:var(--bc-ink)}
.bc-op-rows{display:grid;gap:10px}
.bc-op-row{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;padding-bottom:8px;border-bottom:1px solid #f1f5f9}
.bc-op-row:last-child{border-bottom:0;padding-bottom:0}
.bc-op-row dt{margin:0;font-size:.8rem;color:var(--bc-muted)}
.bc-op-row dd{margin:0;font-size:.95rem;font-weight:650;color:var(--bc-ink);text-align:left;direction:ltr;unicode-bidi:isolate}
.bc-op-row dd.bc-rtl{direction:rtl;unicode-bidi:embed;text-align:right}
.bc-primary-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;padding:14px 16px;background:var(--bc-surface);border:1px solid var(--bc-border);border-radius:12px}
.bc-primary-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.bc-primary-hint{margin:0;font-size:.82rem;color:var(--bc-muted);max-width:28rem;line-height:1.45}
.bc-btn-primary{display:inline-flex;align-items:center;justify-content:center;min-height:var(--admin-btn-min-h,36px);padding:var(--admin-btn-pad-y,7px) var(--admin-btn-pad-x,14px);border:0;border-radius:var(--radius-sm,10px);background:var(--primary,#ea580c);color:#fff!important;font-weight:600;cursor:pointer;font:inherit}
.bc-btn-primary:hover{background:var(--primary-hover,#c2410c)}
.bc-btn-primary:disabled,.bc-btn-secondary:disabled,.bc-btn-ghost:disabled{opacity:.55;cursor:not-allowed}
.bc-btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:var(--admin-btn-min-h,36px);padding:var(--admin-btn-pad-y,7px) var(--admin-btn-pad-x,14px);border:1px solid #cbd5e1;border-radius:var(--radius-sm,10px);background:#fff;color:#334155!important;font-weight:600;cursor:pointer;font:inherit}
.bc-btn-secondary:hover{background:#f8fafc;border-color:#94a3b8}
.bc-btn-ghost{display:inline-flex;align-items:center;justify-content:center;min-height:var(--admin-btn-min-h,36px);padding:6px 12px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155!important;font-weight:600;cursor:pointer;font:inherit;font-size:.86rem}
.bc-btn-ghost:hover{background:#f1f5f9}
.bc-btn-danger-soft{border-color:#fecaca;color:#991b1b!important;background:#fff}
.bc-btn-danger-soft:hover{background:#fef2f2}
.bc-section{margin-bottom:16px}
.bc-panel{background:var(--bc-surface);border:1px solid var(--bc-border);border-radius:12px;padding:14px 16px}
.bc-panel-title{margin:0 0 12px;font-size:1rem;font-weight:700}
.bc-seg{display:inline-flex;flex-wrap:wrap;gap:0;margin:0 0 14px;padding:3px;background:var(--bc-soft);border:1px solid var(--bc-border);border-radius:10px}
.bc-tab{padding:8px 16px;border:0;border-radius:8px;background:transparent;color:#475569;cursor:pointer;font-weight:600;font:inherit}
.bc-tab:hover{color:var(--bc-ink);background:#fff}
.bc-tab.is-active{background:#fff;color:var(--primary,#ea580c);box-shadow:0 1px 2px rgba(15,23,42,.08)}
.bc-tab-panel{display:none}
.bc-tab-panel.is-active{display:block}
.bc-status-strip{display:none!important}
.bc-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:999px;font-size:.78rem;font-weight:650;line-height:1.4;border:1px solid transparent;white-space:nowrap}
.bc-badge--success{background:#ecfdf5;color:var(--bc-ok);border-color:#a7f3d0}
.bc-badge--warning{background:#fffbeb;color:var(--bc-warn);border-color:#fde68a}
.bc-badge--failed{background:#fef2f2;color:var(--bc-bad);border-color:#fecaca}
.bc-badge--running{background:#eff6ff;color:var(--bc-info);border-color:#bfdbfe}
.bc-badge--muted{background:#f3f4f6;color:#4b5563;border-color:#e5e7eb}
.bc-root-warning{display:none;margin-bottom:12px;padding:12px 14px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.bc-progress{display:none;margin:0 0 12px;padding:10px 14px;border-radius:10px;background:#eff6ff;color:#1e3a8a;font-weight:600}
.bc-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.bc-table{width:100%;border-collapse:collapse;font-size:.9rem}
.bc-table th,.bc-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:middle}
.bc-table th{font-size:.78rem;font-weight:700;color:var(--bc-muted);background:var(--bc-soft);white-space:nowrap}
.bc-table tbody tr:hover{background:#fafafa}
.bc-table .bc-col-actions{width:1%;white-space:nowrap}
.bc-mono{font-family:ui-monospace,Consolas,monospace;font-size:.82rem;word-break:break-word}
.bc-ts{display:inline-flex;flex-wrap:nowrap;align-items:baseline;gap:.35em;font-family:ui-monospace,Consolas,monospace;font-size:.82rem;line-height:1.45;white-space:nowrap}
.bc-ts-date,.bc-ts-time{white-space:nowrap}
.bc-ts-cell{vertical-align:middle;min-width:0}
@media (max-width:900px){
  .bc-ts{flex-wrap:wrap;gap:0;white-space:normal}
  .bc-ts-date,.bc-ts-time{display:block}
  .bc-hide-sm{display:none!important}
  .bc-table th,.bc-table td{padding:8px 10px}
}
.bc-collapsible{border:1px solid var(--bc-border);border-radius:12px;background:var(--bc-surface);margin-bottom:16px}
.bc-collapsible>summary{cursor:pointer;list-style:none;padding:14px 16px;font-weight:700;font-size:.95rem;display:flex;align-items:center;justify-content:space-between;gap:10px}
.bc-collapsible>summary::-webkit-details-marker{display:none}
.bc-collapsible>summary::after{content:'▾';color:var(--bc-muted);font-size:.85rem}
.bc-collapsible[open]>summary::after{content:'▴'}
.bc-collapsible-body{padding:0 16px 16px;border-top:1px solid #f1f5f9}
.bc-collapsible .card-hint{margin:10px 0 0}
.bc-sec-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.bc-sec-nav-btn{padding:7px 12px;border:1px solid var(--bc-border);border-radius:999px;background:#fff;color:#475569;font-weight:600;cursor:pointer;font:inherit;font-size:.86rem}
.bc-sec-nav-btn.is-active{border-color:var(--primary,#ea580c);color:var(--primary,#ea580c);background:var(--primary-soft,rgba(234,88,12,.1))}
.bc-storage{display:flex;flex-direction:column;gap:14px}
.bc-storage-path-row{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px}
.bc-storage-path{margin:0;font-family:ui-monospace,Consolas,monospace;font-size:.82rem;line-height:1.55;color:#334155;word-break:break-all;overflow-wrap:anywhere;max-width:100%;flex:1 1 200px;min-width:0}
.bc-storage-path--ellipsis{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.bc-storage-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.bc-kpi-card{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:88px;padding:12px;background:var(--bc-soft);border:1px solid var(--bc-border);border-radius:10px}
.bc-kpi-card h4{margin:0 0 8px;font-size:.82rem;color:var(--bc-muted);font-weight:650}
.bc-kpi-card .bc-val{font-size:1.05rem;font-weight:700;word-break:break-word;direction:ltr;unicode-bidi:isolate}
@media (max-width:1024px){.bc-storage-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:640px){.bc-storage-kpis{grid-template-columns:1fr 1fr}.bc-primary-bar{flex-direction:column;align-items:stretch}}
.bc-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:5000;padding:16px}
.bc-modal{background:#fff;border-radius:12px;max-width:520px;width:100%;padding:18px;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.bc-modal h3{margin:0 0 10px}
.bc-pre{max-height:360px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:.78rem;white-space:pre-wrap;word-break:break-word}
.bc-drawer-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.4);display:none;z-index:5100}
.bc-drawer{position:fixed;top:0;bottom:0;left:0;width:min(440px,94vw);background:#fff;box-shadow:8px 0 32px rgba(15,23,42,.18);z-index:5200;display:none;flex-direction:column;overflow:hidden}
.bc-drawer.is-open,.bc-drawer-backdrop.is-open{display:flex}
.bc-drawer-backdrop.is-open{display:block}
.bc-drawer-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--bc-border)}
.bc-drawer-head h3{margin:0;font-size:1.05rem}
.bc-drawer-body{padding:16px 18px;overflow:auto;flex:1}
.bc-drawer-group{margin-bottom:18px}
.bc-drawer-group h4{margin:0 0 10px;font-size:.82rem;font-weight:700;color:var(--bc-muted);text-transform:none;letter-spacing:.01em}
.bc-drawer-meta{display:grid;gap:8px;margin:0}
.bc-drawer-meta div{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:.9rem}
.bc-drawer-meta dt{margin:0;color:var(--bc-muted)}
.bc-drawer-meta dd{margin:0;font-weight:600;text-align:left;direction:ltr;unicode-bidi:isolate;word-break:break-word}
.bc-action-grid{display:flex;flex-wrap:wrap;gap:8px}
.bc-action-grid .btn-link,.bc-action-grid button.btn-link,.bc-action-grid .bc-btn-ghost{margin:0}
.bc-actions{display:flex;flex-wrap:wrap;gap:8px}
#bc_logs_table .btn-link,#bc_storage_copy_btn{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;min-height:32px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155!important;font-weight:600;cursor:pointer;text-decoration:none}
#bc_logs_table .btn-link:hover,#bc_storage_copy_btn:hover{background:#f1f5f9}
.bc-adv-block{display:none;margin-top:12px;padding-top:12px;border-top:1px dashed #e2e8f0}
.bc-adv-block.is-open{display:block}
.bc-muted{color:var(--bc-muted)}
.bc-overview-hidden{display:none!important}
#bc_root_health{display:none!important}
</style>

<div class="bc-v2" id="bc_app">
    <div id="bc_progress" class="bc-progress" role="status" aria-live="polite">جاري التنفيذ…</div>
    <div id="bc_alert" class="card" style="display:none;margin-bottom:12px;"></div>
    <?php /* Embedded for UI self-test + progressive disclosure fallback; JS overwrites from API when shown. */ ?>
    <div id="bc_root_warning" class="bc-root-warning" role="status" aria-live="polite">مسار النسخ الاحتياطي قابل للقراءة لكنه غير قابل للكتابة بواسطة PHP الخاص بالموقع. يمكن عرض النسخ الحالية، لكن التشغيل اليدوي متوقف حتى يتم ضبط صلاحيات المجلد.</div>

    <!-- SECTION 1 — Header -->
    <header class="bc-header">
        <div class="bc-header-main">
            <p class="bc-header-kicker">Orange Enterprise Backup Center V2</p>
            <p class="bc-header-sub">لوحة تشغيل موحّدة لصحة النسخ الاحتياطي، الحماية، والتخزين — للمشرف الأعلى.</p>
        </div>
        <div class="bc-header-status">
            <span class="bc-header-status-label">حالة النظام</span>
            <span id="bc_overall_status" class="bc-badge bc-badge--muted">…</span>
        </div>
    </header>

    <!-- Hidden legacy root-health mount (still updated for API parity / progressive disclosure) -->
    <dl id="bc_root_health" aria-hidden="true">
        <div><dt>المسار موجود</dt><dd>…</dd></div>
        <div><dt>قابل للقراءة</dt><dd>…</dd></div>
        <div><dt>قابل للكتابة</dt><dd>…</dd></div>
        <div><dt>التشغيل اليدوي</dt><dd>…</dd></div>
    </dl>

    <!-- SECTION 2 — Operational overview -->
    <section class="bc-section" aria-label="نظرة تشغيلية">
        <div id="bc_overview" class="bc-overview" aria-live="polite">
            <article class="bc-op-card">
                <h3>صحة النسخ</h3>
                <div class="bc-op-rows"><div class="bc-op-row"><dt>جاري التحميل…</dt><dd>—</dd></div></div>
            </article>
        </div>
        <!-- Preserve flat overview values for engineering/advanced (filled by JS) -->
        <div id="bc_overview_flat" class="bc-overview-hidden" aria-hidden="true"></div>
    </section>

    <!-- SECTION 3 — Primary actions -->
    <section class="bc-primary-bar" aria-label="إجراءات رئيسية">
        <div class="bc-primary-actions">
            <?php if ($canRun): ?>
            <button type="button" class="bc-btn-primary" id="bc_run_full_btn" data-action="run_full">تشغيل Full Backup</button>
            <button type="button" class="bc-btn-primary bc-btn-danger-soft" id="bc_run_countries_btn" data-action="run_countries">تشغيل All Recoverable Countries</button>
            <?php endif; ?>
            <button type="button" class="bc-btn-secondary" id="bc_refresh_btn">تحديث البيانات</button>
        </div>
        <p class="bc-primary-hint">عمليات التشغيل الثقيلة تتطلب تأكيداً. تأكد من صحة Backup Root قبل التشغيل اليدوي.</p>
    </section>

    <!-- Secondary section switcher: history vs storage/logs -->
    <nav class="bc-sec-nav" aria-label="أقسام ثانوية">
        <button type="button" class="bc-sec-nav-btn is-active" id="bc_sec_history_btn" data-bc-sec="history">سجل النسخ</button>
        <button type="button" class="bc-sec-nav-btn" id="bc_sec_storage_btn" data-bc-sec="storage">التخزين والسجلات</button>
    </nav>

    <div id="bc_sec_history" class="bc-sec-panel">
        <!-- SECTION 4 — Backup type tabs -->
        <section class="bc-section bc-panel">
            <div class="bc-seg" role="tablist" aria-label="نوع النسخ الاحتياطي">
                <button type="button" class="bc-tab is-active" role="tab" id="bc_tab_full_btn" aria-controls="bc_tab_full" aria-selected="true" data-bc-tab="full">النسخ الشامل (Full)</button>
                <button type="button" class="bc-tab" role="tab" id="bc_tab_country_btn" aria-controls="bc_tab_country" aria-selected="false" data-bc-tab="country">نسخ الدول (Country)</button>
            </div>

            <div id="bc_tab_full" class="bc-tab-panel is-active" role="tabpanel" aria-labelledby="bc_tab_full_btn">
                <dl id="bc_latest_full" class="bc-status-strip" aria-hidden="true">
                    <div><dt>آخر Full</dt><dd>…</dd></div>
                    <div><dt>الحالة</dt><dd>…</dd></div>
                    <div><dt>Schema</dt><dd>…</dd></div>
                    <div><dt>DRV Score</dt><dd>…</dd></div>
                </dl>
                <div class="bc-table-wrap">
                    <table class="bc-table" id="bc_full_table">
                        <thead>
                            <tr>
                                <th>التاريخ والوقت</th>
                                <th>الحالة</th>
                                <th>قابلية الاستخدام</th>
                                <th>Schema</th>
                                <th class="bc-hide-sm">الحجم</th>
                                <th>DRV</th>
                                <th class="bc-col-actions">التفاصيل</th>
                            </tr>
                        </thead>
                        <tbody><tr><td colspan="7" class="bc-muted">…</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div id="bc_tab_country" class="bc-tab-panel" role="tabpanel" aria-labelledby="bc_tab_country_btn" hidden>
                <dl id="bc_country_discovery" class="bc-status-strip" aria-hidden="true">
                    <div><dt>دول قابلة للاسترداد</dt><dd>…</dd></div>
                    <div><dt>آخر Country Batch</dt><dd>…</dd></div>
                    <div><dt>حزم مسجّلة</dt><dd>…</dd></div>
                </dl>
                <div class="bc-table-wrap">
                    <table class="bc-table" id="bc_country_table">
                        <thead>
                            <tr>
                                <th>الدولة</th>
                                <th>التاريخ والوقت</th>
                                <th class="bc-hide-sm">معرّف الحزمة</th>
                                <th>الحالة</th>
                                <th>قابلية الاستخدام</th>
                                <th>Schema</th>
                                <th>DRV</th>
                                <th class="bc-col-actions">التفاصيل</th>
                            </tr>
                        </thead>
                        <tbody><tr><td colspan="8" class="bc-muted">…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div id="bc_sec_storage" class="bc-sec-panel" hidden>
        <!-- SECTION 10 — Storage and logs -->
        <section class="bc-section bc-panel">
            <h3 class="bc-panel-title">التخزين والاحتفاظ</h3>
            <div class="bc-storage" id="bc_storage">
                <div>
                    <h4 style="margin:0 0 8px;font-size:.9rem;">Backup Root</h4>
                    <div class="bc-storage-path-row">
                        <p id="bc_storage_path" class="bc-storage-path" title="">—</p>
                        <button type="button" class="btn-link bc-storage-copy" id="bc_storage_copy_btn" hidden>نسخ المسار</button>
                    </div>
                </div>
                <div class="bc-storage-kpis" id="bc_storage_kpis">
                    <div class="bc-kpi-card"><h4>Snapshots</h4><div class="bc-val">—</div></div>
                </div>
            </div>
        </section>

        <section class="bc-section bc-panel">
            <h3 class="bc-panel-title">السجلات</h3>
            <div class="bc-table-wrap">
                <table class="bc-table" id="bc_logs_table">
                    <thead><tr><th>الملف</th><th>النوع</th><th>الحجم</th><th>آخر تعديل</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- SECTION 9 — Scheduled tasks (collapsed) -->
    <details class="bc-collapsible" id="bc_schedule_details">
        <summary>
            <span>المهام المجدولة / Scheduled Operations</span>
        </summary>
        <div class="bc-collapsible-body">
            <div class="bc-table-wrap">
                <table class="bc-table" id="bc_schedule_table">
                    <thead><tr><th>المهمة</th><th>الجدولة</th><th>المسار</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <p class="card-hint">لا يتم تعديل مهام Plesk Scheduled Tasks من Orange.</p>
        </div>
    </details>
</div>

<!-- SECTION 6/7 — Details drawer -->
<div id="bc_drawer_backdrop" class="bc-drawer-backdrop" aria-hidden="true"></div>
<aside id="bc_details_drawer" class="bc-drawer" role="dialog" aria-modal="true" aria-labelledby="bc_drawer_title" aria-hidden="true">
    <div class="bc-drawer-head">
        <div>
            <h3 id="bc_drawer_title">تفاصيل النسخة</h3>
            <p id="bc_drawer_sub" class="bc-muted" style="margin:4px 0 0;font-size:.82rem;"></p>
        </div>
        <button type="button" class="bc-btn-secondary" id="bc_drawer_close">إغلاق</button>
    </div>
    <div class="bc-drawer-body" id="bc_drawer_body"></div>
</aside>

<div id="bc_confirm_modal" class="bc-modal-backdrop" aria-hidden="true">
    <div class="bc-modal" role="dialog" aria-modal="true" aria-labelledby="bc_confirm_title">
        <h3 id="bc_confirm_title">تأكيد</h3>
        <p id="bc_confirm_text"></p>
        <div class="admin-form-actions" style="margin-top:12px;">
            <button type="button" class="bc-btn-primary" id="bc_confirm_ok">تأكيد</button>
            <button type="button" class="bc-btn-secondary" id="bc_confirm_cancel">إلغاء</button>
        </div>
    </div>
</div>

<div id="bc_view_modal" class="bc-modal-backdrop" aria-hidden="true">
    <div class="bc-modal" role="dialog" aria-modal="true" style="max-width:760px;">
        <h3 id="bc_view_title">عرض</h3>
        <pre id="bc_view_pre" class="bc-pre"></pre>
        <div class="admin-form-actions"><button type="button" class="bc-btn-secondary" id="bc_view_close">إغلاق</button></div>
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
    let lastOverview = null;
    let lastRootHealth = null;

    const el = (id) => document.getElementById(id);
    const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    const fmtBytes = (n) => {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    };
    const fmtTimestampDisplay = (raw) => {
        const s = String(raw || '').trim();
        if (!s) return '—';
        const m = s.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})?$/);
        if (!m) {
            return '<time class="bc-ts bc-ts--raw" datetime="' + esc(s) + '" title="' + esc(s) + '">' + esc(s) + '</time>';
        }
        let offset = m[3] || '';
        if (offset === 'Z') offset = '+00:00';
        const timePart = m[2] + (offset ? ' ' + offset : '');
        return '<time class="bc-ts" datetime="' + esc(s) + '" title="' + esc(s) + '"><span class="bc-ts-date">' + esc(m[1]) + '</span><span class="bc-ts-time">' + esc(timePart) + '</span></time>';
    };
    const statusTone = (status) => {
        const s = String(status || '').toLowerCase();
        if (s === 'healthy' || s === 'success' || s === 'pass' || s === 'ok' || s === 'ready') return 'success';
        if (s === 'warning' || s === 'warn') return 'warning';
        if (s === 'failed' || s === 'fail' || s === 'error') return 'failed';
        if (s === 'running') return 'running';
        return 'muted';
    };
    const badge = (status) => {
        const tone = statusTone(status);
        return '<span class="bc-badge bc-badge--' + tone + '">' + esc(status || '—') + '</span>';
    };
    /** Recoverability label from existing package_status only — no new calculation. */
    const recoverabilityBadge = (status) => {
        const s = String(status || '').toLowerCase();
        let label = '—';
        let tone = 'muted';
        if (s === 'healthy' || s === 'success' || s === 'pass') {
            label = 'قابل للاستخدام';
            tone = 'success';
        } else if (s === 'warning' || s === 'warn') {
            label = 'يحتاج مراجعة';
            tone = 'warning';
        } else if (s === 'failed' || s === 'fail' || s === 'error') {
            label = 'غير سليم';
            tone = 'failed';
        } else if (status) {
            label = String(status);
            tone = statusTone(status);
        }
        return '<span class="bc-badge bc-badge--' + tone + '">' + esc(label) + '</span>';
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
        lastRootHealth = h;
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

    function renderOverallStatus(ov, h) {
        const root = String(ov.backup_root_status || '').toLowerCase();
        const last = ov.last_successful_full || ov.latest_full || {};
        const lastSt = String(last.package_status || '').toLowerCase();
        let label = 'غير معروف';
        let tone = 'muted';
        if (h && h.exists === false) {
            label = 'مسار غير متاح';
            tone = 'failed';
        } else if (h && h.readable && !h.writable) {
            label = 'قراءة فقط — تشغيل يدوي محدود';
            tone = 'warning';
        } else if (root === 'failed' || lastSt === 'failed' || lastSt === 'fail' || lastSt === 'error') {
            label = 'يتطلب انتباه';
            tone = 'failed';
        } else if (root === 'warning' || lastSt === 'warning' || lastSt === 'warn' || !manualActionsAvailable) {
            label = 'يحتاج مراجعة';
            tone = 'warning';
        } else if (root === 'healthy' || root === 'ok' || lastSt === 'healthy' || lastSt === 'success' || lastSt === 'pass') {
            label = 'سليم';
            tone = 'success';
        } else if (root || last.package_status) {
            label = ov.backup_root_status || last.package_status || 'معلوماتي';
            tone = statusTone(root || lastSt);
        }
        const node = el('bc_overall_status');
        if (node) {
            node.className = 'bc-badge bc-badge--' + tone;
            node.textContent = label;
        }
    }

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
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF }, body || {}))
        });
        const j = await parseApiJsonResponse(r);
        if (!j.success) throw new Error(j.message || 'Request failed');
        return j;
    }

    function rowHtml(label, valueHtml, rtl) {
        return '<div class="bc-op-row"><dt>' + label + '</dt><dd class="' + (rtl ? 'bc-rtl' : '') + '">' + valueHtml + '</dd></div>';
    }

    function renderOverview(o) {
        const ov = o.overview || {};
        lastOverview = ov;
        const last = ov.last_successful_full || ov.latest_full || {};
        const latestFull = (o.full_snapshots && o.full_snapshots[0]) ? o.full_snapshots[0] : last;
        const countryBatch = ov.latest_country_batch || {};
        const countryCount = Array.isArray(o.country_packages) ? o.country_packages.length : 0;
        const st = ov.storage || {};
        const h = lastRootHealth || o.backup_root_health || {};

        // Flat legacy values preserved (engineering / progressive disclosure)
        const flatCards = [
            ['آخر Full ناجح', fmtTimestampDisplay(last.generated_at)],
            ['حالة Full الأخير', esc(last.package_status || '—')],
            ['Country Batch الأخير', fmtTimestampDisplay(countryBatch.generated_at)],
            ['دول قابلة للاسترداد', esc(String(ov.recoverable_countries ?? '—'))],
            ['BackupRoot', esc(ov.backup_root_status || '—')],
            ['Retention (يوم)', esc(String(ov.retention_days ?? '—'))],
            ['Backend', esc(ov.selected_backend || '—')],
            ['DRV Score', esc(String(ov.latest_recovery_score ?? 0))],
            ['إجمالي التخزين', esc((ov.storage || {}).total_human || '—')]
        ];
        el('bc_overview_flat').innerHTML = flatCards.map(([t, v]) =>
            '<div class="bc-card"><h4>' + t + '</h4><div class="bc-val">' + v + '</div></div>'
        ).join('');

        renderOverallStatus(ov, h);
        const overallNode = el('bc_overall_status');
        const overallCardBadge = overallNode
            ? '<span class="' + String(overallNode.className || 'bc-badge bc-badge--muted').replace(/"/g, '') + '">' + esc(overallNode.textContent || '—') + '</span>'
            : badge(ov.backup_root_status);

        el('bc_overview').innerHTML =
            '<article class="bc-op-card"><h3>صحة النسخ</h3><div class="bc-op-rows">' +
                rowHtml('الحالة العامة', overallCardBadge, true) +
                rowHtml('Backup Root', badge(ov.backup_root_status || (h.readable ? 'ok' : '—')), true) +
                rowHtml('موجود / قراءة / كتابة',
                    healthBadge(!!h.exists, 'موجود', 'غير موجود') + ' ' +
                    healthBadge(!!h.readable, 'قراءة', 'لا قراءة') + ' ' +
                    healthBadge(!!h.writable, 'كتابة', 'لا كتابة'), true) +
                rowHtml('التشغيل اليدوي', healthBadge(manualActionsAvailable, 'متاح', 'غير متاح'), true) +
                rowHtml('Backend', esc(ov.selected_backend || '—')) +
            '</div></article>' +
            '<article class="bc-op-card"><h3>الحماية</h3><div class="bc-op-rows">' +
                rowHtml('آخر Full ناجح', fmtTimestampDisplay(last.generated_at)) +
                rowHtml('حالة Full الأخير', badge(last.package_status), true) +
                rowHtml('آخر Country Batch', fmtTimestampDisplay(countryBatch.generated_at)) +
                rowHtml('دول قابلة للاسترداد', esc(String(ov.recoverable_countries ?? '—')), true) +
                rowHtml('حزم دول مسجّلة', esc(String(countryCount)), true) +
                rowHtml('DRV Score', esc(String(ov.latest_recovery_score ?? 0))) +
            '</div></article>' +
            '<article class="bc-op-card"><h3>التخزين والاحتفاظ</h3><div class="bc-op-rows">' +
                rowHtml('إجمالي التخزين', esc(st.total_human || '—')) +
                rowHtml('Snapshots', esc(st.snapshots_human || '—')) +
                rowHtml('Country Packages', esc(st.country_packages_human || '—')) +
                rowHtml('Logs', esc(st.logs_human || '—')) +
                rowHtml('Retention', esc((ov.retention_days !== undefined && ov.retention_days !== null && ov.retention_days !== '') ? (String(ov.retention_days) + ' يوم') : '—'), true) +
            '</div></article>';

        el('bc_latest_full').innerHTML =
            '<div><dt>آخر Full</dt><dd>' + fmtTimestampDisplay(latestFull.generated_at) + '</dd></div>' +
            '<div><dt>الحالة</dt><dd>' + badge(latestFull.package_status || last.package_status) + '</dd></div>' +
            '<div><dt>Schema</dt><dd>' + esc(String(latestFull.schema_revision ?? '—')) + '</dd></div>' +
            '<div><dt>DRV Score</dt><dd>' + esc(String(latestFull.recovery_score ?? ov.latest_recovery_score ?? 0)) + '</dd></div>';
        el('bc_country_discovery').innerHTML =
            '<div><dt>دول قابلة للاسترداد</dt><dd>' + esc(String(ov.recoverable_countries ?? '—')) + '</dd></div>' +
            '<div><dt>آخر Country Batch</dt><dd>' + (fmtTimestampDisplay(countryBatch.generated_at)) + '</dd></div>' +
            '<div><dt>حزم مسجّلة</dt><dd>' + countryCount + '</dd></div>';

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
            '<div class="bc-kpi-card"><h4>' + esc(t) + '</h4><div class="bc-val" dir="ltr">' + esc(v) + '</div></div>'
        ).join('');
        const sched = ov.scheduled_tasks || [];
        el('bc_schedule_table').querySelector('tbody').innerHTML = sched.map((row) =>
            '<tr><td>' + esc(row.task) + '</td><td>' + esc(row.schedule) + '</td><td><code class="bc-mono">' + esc(row.script) + '</code></td></tr>'
        ).join('');
    }

    function actionButtons(pkg, type) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        let html = '';
        const viewFiles = type === 'full_disaster' || type === 'full'
            ? [['manifest.json', 'Manifest'], ['health.json', 'Health'], ['recovery_validation.json', 'DRV Report']]
            : [['manifest.json', 'Manifest'], ['health.json', 'Health'], ['dependency_graph.json', 'Graph'], ['table_inventory.json', 'Inventory'], ['country_verify_report.json', 'Verify'], ['country_recovery_validation.json', 'Country DRV']];
        viewFiles.forEach(([file, label]) => {
            html += '<button type="button" class="btn-link bc-btn-ghost bc-view-file" data-type="' + esc(type) + '" data-id="' + esc(id) + '" data-cc="' + esc(cc) + '" data-file="' + esc(file) + '">' + label + '</button> ';
        });
        if (CAN_VERIFY) {
            html += '<button type="button" class="btn-link bc-btn-ghost bc-verify" data-type="' + esc(type) + '" data-id="' + esc(id) + '" data-cc="' + esc(cc) + '">Verify</button> ';
            html += '<button type="button" class="btn-link bc-btn-ghost bc-drv" data-type="' + esc(type) + '" data-id="' + esc(id) + '" data-cc="' + esc(cc) + '">DRV</button>';
        }
        return html;
    }

    function sizeSummary(pkg) {
        const dump = Number(pkg.dump_size_bytes) || 0;
        const uploads = Number(pkg.uploads_size_bytes) || 0;
        const total = dump + uploads;
        if (total > 0) return fmtBytes(total);
        if (dump > 0) return fmtBytes(dump);
        if (uploads > 0) return fmtBytes(uploads);
        return '—';
    }

    function closeDrawer() {
        const d = el('bc_details_drawer');
        const b = el('bc_drawer_backdrop');
        if (d) {
            d.classList.remove('is-open');
            d.setAttribute('aria-hidden', 'true');
        }
        if (b) {
            b.classList.remove('is-open');
            b.setAttribute('aria-hidden', 'true');
        }
    }

    function openDetails(pkg, type) {
        const isFull = type === 'full_disaster';
        const title = isFull ? 'تفاصيل Full Backup' : 'تفاصيل Country Package';
        el('bc_drawer_title').textContent = title;
        el('bc_drawer_sub').textContent = pkg.package_id || '';
        const validationFiles = isFull
            ? [['manifest.json', 'Manifest'], ['health.json', 'Health'], ['recovery_validation.json', 'DRV Report']]
            : [['manifest.json', 'Manifest'], ['health.json', 'Health'], ['dependency_graph.json', 'Graph'], ['table_inventory.json', 'Inventory'], ['country_verify_report.json', 'Verify'], ['country_recovery_validation.json', 'Country DRV']];
        let validationHtml = '';
        validationFiles.forEach(([file, label]) => {
            validationHtml += '<button type="button" class="btn-link bc-btn-ghost bc-view-file" data-type="' + esc(type) + '" data-id="' + esc(pkg.package_id) + '" data-cc="' + esc(pkg.country_code || '') + '" data-file="' + esc(file) + '">' + label + '</button>';
        });
        let runHtml = '';
        if (CAN_VERIFY) {
            runHtml += '<button type="button" class="btn-link bc-btn-ghost bc-verify" data-type="' + esc(type) + '" data-id="' + esc(pkg.package_id) + '" data-cc="' + esc(pkg.country_code || '') + '">Verify</button>';
            runHtml += '<button type="button" class="btn-link bc-btn-ghost bc-drv" data-type="' + esc(type) + '" data-id="' + esc(pkg.package_id) + '" data-cc="' + esc(pkg.country_code || '') + '">DRV</button>';
        }

        el('bc_drawer_body').innerHTML =
            '<div class="bc-drawer-group"><h4>الملخص / SUMMARY</h4><dl class="bc-drawer-meta">' +
                '<div><dt>التاريخ</dt><dd>' + fmtTimestampDisplay(pkg.generated_at) + '</dd></div>' +
                '<div><dt>النوع</dt><dd>' + esc(isFull ? 'full_disaster' : 'country_recovery') + '</dd></div>' +
                (isFull ? '' : '<div><dt>الدولة</dt><dd>' + esc((pkg.country_code || '') + (pkg.country_name ? ' — ' + pkg.country_name : '')) + '</dd></div>') +
                '<div><dt>الحالة</dt><dd>' + badge(pkg.package_status) + '</dd></div>' +
                '<div><dt>قابلية الاستخدام</dt><dd>' + recoverabilityBadge(pkg.package_status) + '</dd></div>' +
                '<div><dt>Schema</dt><dd>' + esc(String(pkg.schema_revision ?? '—')) + '</dd></div>' +
                '<div><dt>Backend</dt><dd>' + esc(pkg.backend || '—') + '</dd></div>' +
                '<div><dt>Dump</dt><dd>' + esc(fmtBytes(pkg.dump_size_bytes)) + '</dd></div>' +
                '<div><dt>Uploads</dt><dd>' + esc(fmtBytes(pkg.uploads_size_bytes)) + '</dd></div>' +
                '<div><dt>DRV Score</dt><dd>' + esc(String(pkg.recovery_score || 0)) + '</dd></div>' +
                '<div><dt>Registry</dt><dd>' + esc(pkg.registry_version || '—') + '</dd></div>' +
                '<div><dt>Package ID</dt><dd class="bc-mono">' + esc(pkg.package_id || '—') + '</dd></div>' +
            '</dl></div>' +
            '<div class="bc-drawer-group"><h4>التحقق والصحة / VALIDATION &amp; HEALTH</h4>' +
                '<div class="bc-action-grid">' + validationHtml + runHtml + '</div></div>' +
            '<div class="bc-drawer-group"><h4>ملفات وتقني / FILES &amp; TECHNICAL</h4>' +
                '<p class="bc-muted" style="margin:0 0 8px;font-size:.82rem;">عرض الملفات التقنية عبر الأزرار أعلاه (Manifest / Health / Graph / Inventory / Reports).</p>' +
                '<dl class="bc-drawer-meta">' +
                '<div><dt>Dump size</dt><dd>' + esc(fmtBytes(pkg.dump_size_bytes)) + '</dd></div>' +
                '<div><dt>Uploads size</dt><dd>' + esc(fmtBytes(pkg.uploads_size_bytes)) + '</dd></div>' +
                '<div><dt>Backend</dt><dd>' + esc(pkg.backend || '—') + '</dd></div>' +
                '<div><dt>Registry</dt><dd>' + esc(pkg.registry_version || '—') + '</dd></div>' +
                '</dl></div>' +
            '<div class="bc-drawer-group"><h4>سجلات وتدقيق / LOGS &amp; AUDIT</h4>' +
                '<p class="bc-muted" style="margin:0;font-size:.82rem;">تقارير DRV/Verify تُعرض من أزرار Validation. سجلات النظام من قسم «التخزين والسجلات».</p></div>';

        el('bc_details_drawer').classList.add('is-open');
        el('bc_details_drawer').setAttribute('aria-hidden', 'false');
        el('bc_drawer_backdrop').classList.add('is-open');
        el('bc_drawer_backdrop').setAttribute('aria-hidden', 'false');
        applyActionAvailability();
    }

    function renderTables(data) {
        state.full = data.full_snapshots || [];
        state.country = data.country_packages || [];
        el('bc_full_table').querySelector('tbody').innerHTML = state.full.length
            ? state.full.map((p, idx) =>
                '<tr data-bc-pkg-idx="' + idx + '" data-bc-pkg-type="full_disaster">' +
                '<td class="bc-ts-cell">' + fmtTimestampDisplay(p.generated_at) + '</td>' +
                '<td>' + badge(p.package_status) + '</td>' +
                '<td>' + recoverabilityBadge(p.package_status) + '</td>' +
                '<td>' + esc(String(p.schema_revision ?? '—')) + '</td>' +
                '<td class="bc-hide-sm" dir="ltr">' + esc(sizeSummary(p)) + '</td>' +
                '<td dir="ltr">' + esc(String(p.recovery_score || 0)) + '</td>' +
                '<td class="bc-col-actions"><button type="button" class="bc-btn-ghost bc-open-details" data-idx="' + idx + '" data-type="full_disaster">التفاصيل</button>' +
                '<span class="bc-actions" hidden aria-hidden="true">' + actionButtons(p, 'full_disaster') + '</span></td></tr>'
            ).join('')
            : '<tr><td colspan="7" class="bc-muted">لا توجد لقطات.</td></tr>';
        el('bc_country_table').querySelector('tbody').innerHTML = state.country.length
            ? state.country.map((p, idx) =>
                '<tr data-bc-pkg-idx="' + idx + '" data-bc-pkg-type="country_recovery">' +
                '<td>' + esc((p.country_code || '') + (p.country_name ? ' — ' + p.country_name : '')) + '</td>' +
                '<td class="bc-ts-cell">' + fmtTimestampDisplay(p.generated_at) + '</td>' +
                '<td class="bc-hide-sm bc-mono">' + esc(p.package_id || '') + '</td>' +
                '<td>' + badge(p.package_status) + '</td>' +
                '<td>' + recoverabilityBadge(p.package_status) + '</td>' +
                '<td>' + esc(String(p.schema_revision ?? '—')) + '</td>' +
                '<td dir="ltr">' + esc(String(p.recovery_score || 0)) + '</td>' +
                '<td class="bc-col-actions"><button type="button" class="bc-btn-ghost bc-open-details" data-idx="' + idx + '" data-type="country_recovery">التفاصيل</button>' +
                '<span class="bc-actions" hidden aria-hidden="true">' + actionButtons(p, 'country_recovery') + '</span></td></tr>'
            ).join('')
            : '<tr><td colspan="8" class="bc-muted">لا توجد حزم دول.</td></tr>';
        el('bc_logs_table').querySelector('tbody').innerHTML = (data.logs || []).map((log) =>
            '<tr><td><code class="bc-mono">' + esc(log.name) + '</code></td><td>' + esc(log.category) + '</td><td dir="ltr">' + esc(fmtBytes(log.size_bytes)) + '</td><td class="bc-ts-cell">' + fmtTimestampDisplay(new Date(log.mtime * 1000).toISOString()) + '</td><td><button type="button" class="btn-link bc-log-tail" data-log="' + esc(log.name) + '">عرض</button></td></tr>'
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
    el('bc_drawer_close').addEventListener('click', closeDrawer);
    el('bc_drawer_backdrop').addEventListener('click', closeDrawer);

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

        if (t.classList.contains('bc-open-details')) {
            const idx = Number(t.dataset.idx);
            const type = t.dataset.type || 'full_disaster';
            const pkg = type === 'full_disaster' ? state.full[idx] : state.country[idx];
            if (pkg) openDetails(pkg, type);
            return;
        }

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

    function switchSec(name) {
        const hist = el('bc_sec_history');
        const stor = el('bc_sec_storage');
        const isHist = name === 'history';
        hist.hidden = !isHist;
        stor.hidden = isHist;
        el('bc_sec_history_btn').classList.toggle('is-active', isHist);
        el('bc_sec_storage_btn').classList.toggle('is-active', !isHist);
    }
    document.querySelectorAll('[data-bc-sec]').forEach((btn) => {
        btn.addEventListener('click', () => switchSec(btn.getAttribute('data-bc-sec') || 'history'));
    });

    loadAll();
})();
</script>
