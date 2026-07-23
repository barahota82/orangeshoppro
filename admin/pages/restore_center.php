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
$displayTimezone = orange_admin_context_timezone($pdo);
$countryContextCode = orange_countries_display_code(orange_admin_context_country_code($pdo));

orange_admin_render_page_title_with_country('إدارة الاسترداد', $pdo);
?>
<style>
/* Orange Enterprise Restore Center V2 — Owner-approved UX/IA — page-scoped only (mirror Backup Center) */
.rc-v2{--rc-border:#e2e8f0;--rc-muted:#64748b;--rc-surface:#fff;--rc-soft:#f8fafc;--rc-ink:#0f172a;--rc-ok:#047857;--rc-warn:#b45309;--rc-bad:#b91c1c;--rc-info:#1d4ed8}
.rc-v2 *{box-sizing:border-box}
.rc-header{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;padding:14px 16px;background:var(--rc-surface);border:1px solid var(--rc-border);border-radius:12px}
.rc-header-main{min-width:0;flex:1}
.rc-header-kicker{margin:0 0 4px;font-size:.78rem;font-weight:600;color:var(--rc-muted)}
.rc-header-sub{margin:0;font-size:.9rem;color:var(--rc-muted);line-height:1.45;max-width:42rem}
.rc-header-status{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.rc-header-status-label{font-size:.75rem;color:var(--rc-muted)}
.rc-tz-label{margin:8px 0 0;font-size:.78rem;color:var(--rc-muted);line-height:1.4}
.rc-tz-label code{font-family:ui-monospace,Consolas,monospace;font-size:.78rem;color:#334155;direction:ltr;unicode-bidi:isolate}
.rc-phase{margin-bottom:14px}
.rc-phase-head{display:flex;flex-wrap:wrap;align-items:baseline;gap:10px;margin:0 0 10px}
.rc-phase-num{display:inline-flex;align-items:center;justify-content:center;width:1.65rem;height:1.65rem;border-radius:999px;background:var(--primary,#ea580c);color:#fff;font-size:.78rem;font-weight:700}
.rc-phase-title{margin:0;font-size:1rem;font-weight:700;color:var(--rc-ink)}
.rc-phase-hint{margin:0;font-size:.8rem;color:var(--rc-muted);flex:1;min-width:12rem}
.rc-panel{background:var(--rc-surface);border:1px solid var(--rc-border);border-radius:12px;padding:12px 14px}
.rc-panel-title{margin:0 0 10px;font-size:.95rem;font-weight:700}
.rc-panel-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.rc-overview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:12px}
@media (max-width:1024px){.rc-overview{grid-template-columns:1fr}}
.rc-op-card{background:var(--rc-surface);border:1px solid var(--rc-border);border-radius:12px;padding:12px 14px;min-width:0}
.rc-op-card h3{margin:0 0 10px;font-size:.92rem;font-weight:700;color:var(--rc-ink)}
.rc-op-rows{display:grid;gap:8px}
.rc-op-row{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;padding-bottom:6px;border-bottom:1px solid #f1f5f9}
.rc-op-row:last-child{border-bottom:0;padding-bottom:0}
.rc-op-row dt{margin:0;font-size:.78rem;color:var(--rc-muted)}
.rc-op-row dd{margin:0;font-size:.9rem;font-weight:650;color:var(--rc-ink);text-align:left;direction:ltr;unicode-bidi:isolate}
.rc-readiness{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;padding:12px 14px;background:var(--rc-soft);border:1px solid var(--rc-border);border-radius:12px}
.rc-readiness-label{margin:0;font-size:.8rem;color:var(--rc-muted)}
.rc-readiness-value{margin:4px 0 0;font-size:1.05rem;font-weight:700;color:var(--rc-ink)}
.rc-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:16px}
@media (max-width:900px){.rc-info-grid{grid-template-columns:1fr}}
.rc-info-box{padding:14px 16px;border-radius:10px;border:1px solid var(--rc-border);background:#fff;line-height:1.55;font-size:.86rem}
.rc-info-box h4{margin:0 0 8px;font-size:.82rem;font-weight:700}
.rc-info-box p{margin:0;line-height:1.6}
.rc-info-box--warn{background:#fffbeb;border-color:#fde68a;color:#92400e}
.rc-info-box--info{background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a}
.rc-info-box--req{background:#f8fafc;border-color:#cbd5e1;color:#334155}
.rc-info-box--blocked{background:#fef2f2;border-color:#fecaca;color:#991b1b}
.rc-btn-primary{display:inline-flex;align-items:center;justify-content:center;min-height:var(--admin-btn-min-h,36px);padding:var(--admin-btn-pad-y,7px) var(--admin-btn-pad-x,14px);border:0;border-radius:var(--radius-sm,10px);background:var(--primary,#ea580c);color:#fff!important;font-weight:600;cursor:pointer;font:inherit;font-size:.86rem}
.rc-btn-primary:hover{background:var(--primary-hover,#c2410c)}
.rc-btn-primary:disabled,.rc-btn-secondary:disabled,.rc-btn-ghost:disabled{opacity:.55;cursor:not-allowed}
.rc-btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:var(--admin-btn-min-h,36px);padding:var(--admin-btn-pad-y,7px) var(--admin-btn-pad-x,14px);border:1px solid #cbd5e1;border-radius:var(--radius-sm,10px);background:#fff;color:#334155!important;font-weight:600;cursor:pointer;font:inherit;font-size:.86rem}
.rc-btn-secondary:hover{background:#f8fafc;border-color:#94a3b8}
.rc-btn-ghost{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:5px 11px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155!important;font-weight:600;cursor:pointer;font:inherit;font-size:.82rem;white-space:nowrap}
.rc-btn-ghost:hover{background:#f1f5f9}
.rc-badge{display:inline-flex;align-items:center;gap:5px;padding:2px 10px;border-radius:999px;font-size:.76rem;font-weight:650;line-height:1.4;border:1px solid transparent;white-space:nowrap}
.rc-badge--success{background:#ecfdf5;color:var(--rc-ok);border-color:#a7f3d0}
.rc-badge--warning{background:#fffbeb;color:var(--rc-warn);border-color:#fde68a}
.rc-badge--failed{background:#fef2f2;color:var(--rc-bad);border-color:#fecaca}
.rc-badge--running{background:#eff6ff;color:var(--rc-info);border-color:#bfdbfe}
.rc-badge--muted{background:#f3f4f6;color:#4b5563;border-color:#e5e7eb}
.rc-status-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:10px;padding:12px;background:var(--rc-soft);border:1px solid var(--rc-border);border-radius:8px}
.rc-status-strip dt{font-size:.78rem;color:var(--rc-muted);margin:0 0 2px}
.rc-status-strip dd{margin:0;font-weight:600;font-size:.92rem}
.rc-progress{display:none;margin:0 0 12px;padding:10px 14px;border-radius:10px;background:#eff6ff;color:#1e3a8a;font-weight:600}
.rc-mono{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;word-break:break-word}
.rc-ts{display:inline-flex;flex-wrap:nowrap;align-items:baseline;gap:.35em;font-family:ui-monospace,Consolas,monospace;font-size:.8rem;line-height:1.4;white-space:nowrap}
.rc-ts-date,.rc-ts-time{white-space:nowrap}
.rc-ts--raw{color:#92400e}
.rc-ts-warn{font-size:.72rem;font-weight:650;color:#b45309}
.rc-acc-when{margin-inline-end:8px;font-weight:700}
@media (max-width:900px){.rc-ts{flex-wrap:wrap;gap:0;white-space:normal}.rc-ts-date,.rc-ts-time{display:block}}
.rc-acc-list{display:flex;flex-direction:column;gap:8px}
.rc-acc-item{border:1px solid var(--rc-border);border-radius:12px;background:var(--rc-surface)}
.rc-acc-item>summary{cursor:pointer;list-style:none;padding:12px 14px;font-weight:650;font-size:.9rem;display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px}
.rc-acc-item>summary::-webkit-details-marker{display:none}
.rc-acc-chevron{display:inline-flex;width:1.1em;color:var(--rc-muted);font-size:.85rem;flex:0 0 auto}
.rc-acc-item>summary .rc-acc-chevron::before{content:'▶'}
.rc-acc-item[open]>summary .rc-acc-chevron::before{content:'▼'}
.rc-acc-title{font-weight:700;color:var(--rc-ink);min-width:7rem}
.rc-acc-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px;flex:1;min-width:0}
.rc-acc-actions-inline{display:flex;align-items:center;gap:8px;margin-inline-start:auto}
.rc-acc-body{padding:10px 14px 12px;border-top:1px solid #f1f5f9}
/* Package expand: header + chevron stay visible; only details scroll */
.rc-acc-item[data-rc-acc="pkg"]>summary{position:relative;z-index:1;background:var(--rc-surface);border-radius:12px}
.rc-acc-item[data-rc-acc="pkg"][open]>summary{border-radius:12px 12px 0 0}
.rc-acc-item[data-rc-acc="pkg"]>.rc-acc-body{max-height:min(240px,42vh);overflow-y:auto;-webkit-overflow-scrolling:touch}
.rc-pkg-id{font-family:ui-monospace,Consolas,monospace;font-size:.72rem;font-weight:500;color:#94a3b8;direction:ltr;unicode-bidi:isolate}
.rc-action-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px}
.rc-action-row .rc-btn-ghost,.rc-action-row .btn-link{flex:0 0 auto}
.rc-action-row--secondary{margin-top:8px;padding-top:8px;border-top:1px solid #f1f5f9}
.rc-action-row--secondary .btn-link,.rc-action-row--secondary .rc-btn-ghost{opacity:.92;font-weight:600;font-size:.8rem}
.rc-stage-idle{color:var(--rc-muted);font-weight:600;font-size:.86rem}
.rc-actions{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0;align-items:center}
.rc-active-job{padding:14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px;margin-bottom:12px}
.rc-active-job h4{margin:0 0 8px;font-size:.95rem}
.rc-active-meta{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center;margin-bottom:8px}
.rc-stage-strip{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.rc-stage-chip{flex:1 1 140px;min-width:120px;padding:10px 12px;border-radius:10px;border:1px solid var(--rc-border);background:var(--rc-soft);text-align:center;cursor:pointer;font:inherit;color:inherit}
.rc-stage-chip:hover{border-color:#94a3b8;background:#fff}
.rc-stage-chip strong{display:block;font-size:.78rem;color:var(--rc-muted);margin-bottom:4px}
.rc-stage-chip span{font-size:.86rem;font-weight:650;color:var(--rc-ink)}
.rc-stage-chip.is-active,.rc-stage-chip.is-selected{border-color:var(--primary,#ea580c);background:rgba(234,88,12,.08)}
.rc-stage-panels{display:flex;flex-direction:column;gap:8px}
.rc-stage-acc.rc-acc-item{background:#fff}
.rc-stage-acc>summary .rc-acc-title{font-size:.9rem}
.rc-val-stack{display:flex;flex-direction:column;gap:8px}
.rc-skeleton{display:block;height:12px;border-radius:6px;background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);background-size:200% 100%;animation:rc-skel 1.2s ease-in-out infinite}
.rc-skeleton--lg{height:18px}
.rc-skeleton-card{padding:14px;border:1px solid var(--rc-border);border-radius:12px;background:#fff}
.rc-skeleton-card+.rc-skeleton-card{margin-top:8px}
@keyframes rc-skel{0%{background-position:200% 0}100%{background-position:-200% 0}}
.rc-empty{padding:28px 18px;text-align:center;border:1px dashed #cbd5e1;border-radius:12px;background:var(--rc-soft)}
.rc-empty h4{margin:0 0 6px;font-size:.95rem;font-weight:700;color:var(--rc-ink)}
.rc-empty p{margin:0;font-size:.86rem;color:var(--rc-muted);line-height:1.5;max-width:28rem;margin-inline:auto}
.rc-jobs-hist-label{margin:14px 0 8px;font-size:.82rem;font-weight:650;color:var(--rc-muted)}
.rc-active-job.is-highlight{box-shadow:0 0 0 1px #93c5fd inset}
.rc-readonly-banner{margin:0 0 10px;padding:10px 12px;border-radius:8px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;line-height:1.55;font-size:.86rem}
.rc-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:5000;padding:16px}
.rc-modal{background:#fff;border-radius:12px;max-width:520px;width:100%;padding:18px;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.rc-modal--wide{max-width:860px}
.rc-modal h3{margin:0 0 10px}
.rc-pre{max-height:360px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:.78rem;white-space:pre-wrap;word-break:break-word}
.rc-drawer-group{margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #f1f5f9}
.rc-drawer-group:last-child{border-bottom:0;margin-bottom:0;padding-bottom:0}
.rc-drawer-group h4{margin:0 0 8px;font-size:.8rem;font-weight:700;color:var(--rc-muted)}
.rc-muted{color:var(--rc-muted)}
.rc-seg{display:inline-flex;flex-wrap:nowrap;gap:0;margin:0;padding:3px;background:var(--rc-soft);border:1px solid var(--rc-border);border-radius:999px}
.rc-tab{padding:7px 16px;border:0;border-radius:999px;background:transparent;color:#475569;cursor:pointer;font-weight:650;font:inherit;font-size:.86rem}
.rc-tab.is-active{background:#fff;color:var(--primary,#ea580c);box-shadow:0 1px 3px rgba(15,23,42,.1)}
.rc-tab-panel{display:none}
.rc-tab-panel.is-active{display:block}
.rc-acc-list[hidden],.rc-tab-panel[hidden]{display:none!important}
@media (max-width:640px){.rc-acc-actions-inline{width:100%;margin-inline-start:0}}
.rc-v2 .rc-action-row .btn-link,.rc-v2 .rc-actions .btn-link,.rc-v2 button.btn-link.rc-btn-ghost{background:#fff;color:#334155!important;-webkit-text-fill-color:#334155;border:1px solid #cbd5e1;border-radius:8px;padding:5px 11px;min-height:32px;font-weight:600}
.rc-v2 .rc-action-row .btn-link:hover,.rc-v2 .rc-actions .btn-link:hover{background:#f1f5f9}
.rc-v2 .rc-acc-actions-inline .btn-link.rc-btn-primary,.rc-v2 .rc-acc-actions-inline button.rc-btn-primary,
.rc-v2 .rc-acc-body .btn-link.rc-btn-primary,.rc-v2 .rc-acc-body button.rc-btn-primary{background:var(--primary,#ea580c);color:#fff!important;-webkit-text-fill-color:#fff;border:0}
.rc-v2 .rc-acc-actions-inline .btn-link.rc-btn-primary:hover,.rc-v2 .rc-acc-body .btn-link.rc-btn-primary:hover{background:var(--primary-hover,#c2410c)}
#rc_refresh_btn{background:#fff;color:#334155!important;border:1px solid #cbd5e1}
#rc_refresh_btn:hover{background:#f8fafc;border-color:#94a3b8}
#rc_view_close,#rc_detail_close{background:#475569;color:#fff!important}
</style>

<div class="rc-v2" id="rc_app">
    <div id="rc_progress" class="rc-progress" role="status" aria-live="polite">جاري التحميل…</div>
    <div id="rc_alert" class="card" style="display:none;margin-bottom:12px;"></div>

    <header class="rc-header">
        <div class="rc-header-main">
            <p class="rc-header-kicker">Orange Enterprise Restore Center V2</p>
            <p class="rc-header-sub">مسار عمل موحّد لجاهزية النظام، اختيار الحزمة، التحقق، تشغيل مهام الاسترداد، ومراحل التنفيذ — للمشرف الأعلى.</p>
            <p class="rc-tz-label" id="rc_tz_label"><?php
            if ($displayTimezone !== '') {
                echo 'جميع التواريخ تُعرض بالتوقيت المحلي للدولة المحددة (12 ساعة AM/PM): <code dir="ltr">'
                    . htmlspecialchars($displayTimezone, ENT_QUOTES, 'UTF-8')
                    . '</code>';
            } else {
                echo 'تحذير: لم تُضبط المنطقة الزمنية (IANA) في إعدادات الدولة الحالية — عرّفها من شاشة الدول قبل الاعتماد على عرض التواريخ المحلية.';
            }
            ?></p>
        </div>
        <div class="rc-header-status">
            <span class="rc-header-status-label">جاهزية النظام</span>
            <span id="rc_readiness_badge" class="rc-badge rc-badge--muted" aria-busy="true"><span class="rc-skeleton" style="width:5.5rem;display:inline-block;vertical-align:middle"></span></span>
        </div>
    </header>

    <section class="rc-phase" aria-labelledby="rc_phase1_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">1</span>
            <h2 class="rc-phase-title" id="rc_phase1_title">System Readiness</h2>
            <p class="rc-phase-hint">ملخص الجاهزية والقفل والصيانة — عرض فقط من بيانات القائمة.</p>
        </div>
        <div class="rc-panel">
            <div class="rc-readiness" id="rc_readiness_summary">
                <div>
                    <p class="rc-readiness-label">الحالة التشغيلية</p>
                    <p class="rc-readiness-value" id="rc_readiness_headline" aria-busy="true"><span class="rc-skeleton rc-skeleton--lg" style="width:10rem;display:inline-block"></span></p>
                </div>
                <button type="button" class="rc-btn-secondary" id="rc_refresh_btn">تحديث</button>
            </div>
            <div class="rc-info-grid" aria-label="تحذيرات ومتطلبات">
                <div class="rc-info-box rc-info-box--warn">
                    <h4>Warnings</h4>
                    <p>بعد تفعيل الصيانة يمكن طلب استيراد قاعدة الإنتاج عبر CLI فقط. <strong>Application files have NOT been switched.</strong></p>
                </div>
                <div class="rc-info-box rc-info-box--info">
                    <h4>Information</h4>
                    <p>لا يوجد تبديل ملفات تطبيق، ولا uploads rename، ولا cutover، ولا rollback، ولا إغلاق صيانة في مرحلة العرض وحدها.</p>
                </div>
                <div class="rc-info-box rc-info-box--req">
                    <h4>Requirements</h4>
                    <p>حزمة مؤهلة + Dry Validation + خطة معتمدة + نسخة احتياطية إلزامية قبل الاسترداد + عمال CLI المعتمدة عند الحاجة.</p>
                </div>
                <div class="rc-info-box rc-info-box--blocked">
                    <h4>Blocked</h4>
                    <p>أي مسار إنتاجي محظور حتى تكتمل البوابات اليدوية؛ القفل العام والصيانة يمنعان التداخل غير الآمن.</p>
                </div>
            </div>
            <div id="rc_overview" class="rc-overview" aria-live="polite" aria-busy="true">
                <article class="rc-op-card"><span class="rc-skeleton rc-skeleton--lg" style="width:55%"></span><div class="rc-skeleton" style="width:30%;margin-top:14px"></div></article>
                <article class="rc-op-card"><span class="rc-skeleton rc-skeleton--lg" style="width:50%"></span><div class="rc-skeleton" style="width:28%;margin-top:14px"></div></article>
                <article class="rc-op-card"><span class="rc-skeleton rc-skeleton--lg" style="width:60%"></span><div class="rc-skeleton" style="width:32%;margin-top:14px"></div></article>
            </div>
            <dl id="rc_lock_maintenance" class="rc-status-strip" aria-busy="true">
                <div><dt>قفل الاسترداد العام</dt><dd><span class="rc-skeleton" style="width:4rem;display:inline-block"></span></dd></div>
                <div><dt>وضع الصيانة</dt><dd><span class="rc-skeleton" style="width:4rem;display:inline-block"></span></dd></div>
            </dl>
        </div>
    </section>

    <section class="rc-phase" aria-labelledby="rc_phase2_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">2</span>
            <h2 class="rc-phase-title" id="rc_phase2_title">Choose Restore Package</h2>
            <p class="rc-phase-hint">قائمة قابلة للتوسيع — إجراء أساسي واحد لكل حزمة.</p>
        </div>
        <div class="rc-panel">
            <div class="rc-panel-head">
                <h3 class="rc-panel-title" style="margin:0">الحزم المتاحة</h3>
                <div class="rc-seg" role="tablist" aria-label="نوع الحزمة">
                    <?php if ($canFull): ?>
                    <button type="button" class="rc-tab is-active" id="rc_tab_full_btn" data-rc-tab="full">Full Backup</button>
                    <?php endif; ?>
                    <?php if ($canCountry): ?>
                    <button type="button" class="rc-tab<?php echo $canFull ? '' : ' is-active'; ?>" id="rc_tab_country_btn" data-rc-tab="country">Country Backup</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($canFull): ?>
            <div id="rc_tab_full" class="rc-tab-panel is-active" role="tabpanel">
                <div id="rc_full_list" class="rc-acc-list" aria-busy="true">
                    <div class="rc-skeleton-card"><span class="rc-skeleton" style="width:35%"></span><div class="rc-skeleton" style="width:70%;margin-top:12px"></div></div>
                    <div class="rc-skeleton-card"><span class="rc-skeleton" style="width:40%"></span><div class="rc-skeleton" style="width:65%;margin-top:12px"></div></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($canCountry): ?>
            <div id="rc_tab_country" class="rc-tab-panel<?php echo $canFull ? '' : ' is-active'; ?>" role="tabpanel"<?php echo $canFull ? ' hidden' : ''; ?>>
                <p class="rc-tz-label" style="margin:0 0 10px;">Country packages — سياق الدولة: <code dir="ltr"><?php echo htmlspecialchars($countryContextCode !== '' ? $countryContextCode : '—', ENT_QUOTES, 'UTF-8'); ?></code></p>
                <div id="rc_country_list" class="rc-acc-list" aria-busy="true">
                    <div class="rc-skeleton-card"><span class="rc-skeleton" style="width:35%"></span><div class="rc-skeleton" style="width:70%;margin-top:12px"></div></div>
                </div>
            </div>
            <?php endif; ?>
            <?php if (!$canFull && !$canCountry): ?>
            <p class="rc-muted" style="margin:0">لا صلاحية لعرض حزم Full أو Country.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="rc-phase" aria-labelledby="rc_phase3_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">3</span>
            <h2 class="rc-phase-title" id="rc_phase3_title">Validation</h2>
            <p class="rc-phase-hint">أقسام قابلة للطي — شهادة الجاهزية وC7/C8 عند صلاحية الدولة.</p>
        </div>
        <div class="rc-val-stack">
            <details class="rc-acc-item rc-val-acc" data-rc-acc="val" id="rc_certification_section">
                <summary>
                    <span class="rc-acc-chevron" aria-hidden="true"></span>
                    <span class="rc-acc-title">شهادة جاهزية الاسترداد</span>
                    <span class="rc-acc-meta"><span class="rc-muted">عرض فقط — CLI drill</span></span>
                </summary>
                <div class="rc-acc-body">
                    <p id="rc_cert_banner" class="rc-readonly-banner" role="status">
                        عرض فقط — لا يُشغَّل تمرين الاسترداد من الواجهة. الأمر CLI: <code>run_restore_dr_drill.php</code>
                    </p>
                    <dl id="rc_cert_status" class="rc-status-strip" aria-busy="true">
                        <div><dt>Full Restore</dt><dd><span class="rc-skeleton" style="width:4rem;display:inline-block"></span></dd></div>
                        <div><dt>تمرين التراجع</dt><dd><span class="rc-skeleton" style="width:3rem;display:inline-block"></span></dd></div>
                        <div><dt>العزل</dt><dd><span class="rc-skeleton" style="width:3rem;display:inline-block"></span></dd></div>
                        <div><dt>Commit</dt><dd><span class="rc-skeleton" style="width:5rem;display:inline-block"></span></dd></div>
                        <div><dt>تاريخ الاختبار</dt><dd><span class="rc-skeleton" style="width:6rem;display:inline-block"></span></dd></div>
                        <div><dt>Country Restore</dt><dd>غير معتمد للإنتاج</dd></div>
                    </dl>
                    <div id="rc_cert_blockers" class="muted" style="margin-top:8px;"></div>
                </div>
            </details>
            <?php if ($canCountry): ?>
            <details class="rc-acc-item rc-val-acc" data-rc-acc="val" id="rc_country_shadow_section">
                <summary>
                    <span class="rc-acc-chevron" aria-hidden="true"></span>
                    <span class="rc-acc-title">Country Shadow Verification (C7)</span>
                    <span class="rc-acc-meta"><span class="rc-muted">عرض فقط</span></span>
                </summary>
                <div class="rc-acc-body">
                    <p class="rc-readonly-banner" role="status">
                        <strong>تحقق ظل الدولة فقط.</strong>
                        لا Import / Restore / Execute / Approval / Maintenance / Rollback / Production enablement.
                        التشغيل عبر CLI: <code>verify_country_shadow.php --job=…</code>
                    </p>
                    <div class="rc-actions">
                        <label for="rc_c7_job_id" class="rc-muted">Job / run_id</label>
                        <input type="text" id="rc_c7_job_id" placeholder="kw_YYYY-MM-DD_HHMMSS" style="min-width:220px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;">
                        <button type="button" class="btn-link rc-btn-ghost" id="rc_c7_load_btn">عرض تقرير التحقق</button>
                    </div>
                    <dl id="rc_country_shadow_verify" class="rc-status-strip">
                        <div><dt>النتيجة</dt><dd>—</dd></div>
                        <div><dt>Readiness</dt><dd>—</dd></div>
                        <div><dt>Target country</dt><dd>—</dd></div>
                        <div><dt>Survivor countries</dt><dd>—</dd></div>
                        <div><dt>Global state</dt><dd>—</dd></div>
                        <div><dt>Accounting</dt><dd>—</dd></div>
                        <div><dt>Stock / FIFO</dt><dd>—</dd></div>
                    </dl>
                    <div id="rc_country_shadow_blockers" class="muted" style="margin-top:8px;"></div>
                </div>
            </details>
            <details class="rc-acc-item rc-val-acc" data-rc-acc="val" id="rc_country_dry_run_section">
                <summary>
                    <span class="rc-acc-chevron" aria-hidden="true"></span>
                    <span class="rc-acc-title">Country Dry Run (C8)</span>
                    <span class="rc-acc-meta"><span class="rc-muted">عرض فقط</span></span>
                </summary>
                <div class="rc-acc-body">
                    <p class="rc-readonly-banner" role="status">
                        <strong>محاكاة استعادة الدولة فقط.</strong>
                        لا كتابة إنتاج، لا كتابة ظل، لا Import / Restore / Execute / Approval / Maintenance / Rollback.
                        التشغيل عبر CLI: <code>country_dry_run.php --job=…</code>
                    </p>
                    <div class="rc-actions">
                        <label for="rc_c8_job_id" class="rc-muted">Job / run_id</label>
                        <input type="text" id="rc_c8_job_id" placeholder="kw_YYYY-MM-DD_HHMMSS" style="min-width:220px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;">
                        <button type="button" class="btn-link rc-btn-ghost" id="rc_c8_load_btn">عرض تقرير Dry Run</button>
                    </div>
                    <dl id="rc_country_dry_run" class="rc-status-strip">
                        <div><dt>النتيجة</dt><dd>—</dd></div>
                        <div><dt>Tables</dt><dd>—</dd></div>
                        <div><dt>Rows insert/delete</dt><dd>—</dd></div>
                        <div><dt>Survivor impact</dt><dd>—</dd></div>
                        <div><dt>Global impact</dt><dd>—</dd></div>
                        <div><dt>Duration</dt><dd>—</dd></div>
                    </dl>
                    <div id="rc_country_dry_run_blockers" class="muted" style="margin-top:8px;"></div>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </section>

    <section class="rc-phase" aria-labelledby="rc_phase4_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">4</span>
            <h2 class="rc-phase-title" id="rc_phase4_title">Create / Operate Restore Jobs</h2>
            <p class="rc-phase-hint">مركز التشغيل — المهمة النشطة أولاً، ثم السجل.</p>
        </div>
        <div class="rc-panel">
            <div id="rc_active_job" class="rc-active-job is-highlight" hidden>
                <h4>المهمة النشطة / الحالية</h4>
                <div class="rc-active-meta" id="rc_active_job_meta"></div>
                <div class="rc-actions" id="rc_active_job_actions"></div>
            </div>
            <h3 class="rc-panel-title">Restore Jobs</h3>
            <p class="rc-jobs-hist-label" id="rc_jobs_hist_label" hidden>سجل المهام</p>
            <div id="rc_jobs_list" class="rc-acc-list" aria-busy="true">
                <div class="rc-skeleton-card"><span class="rc-skeleton" style="width:45%"></span><div class="rc-skeleton" style="width:75%;margin-top:12px"></div></div>
            </div>
            <table id="rc_jobs_table" hidden aria-hidden="true"><tbody></tbody></table>
        </div>
    </section>

    <section class="rc-phase" aria-labelledby="rc_phase5_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">5</span>
            <h2 class="rc-phase-title" id="rc_phase5_title">Execution workflow</h2>
            <p class="rc-phase-hint">الشريط دائماً ظاهر — تفاصيل المرحلة مطوية إلا عند النقر أو عندما تكون المرحلة نشطة.</p>
        </div>
        <div class="rc-panel">
            <div class="rc-stage-strip" id="rc_stage_strip" aria-label="مراحل التنفيذ">
                <button type="button" class="rc-stage-chip" data-stage="maint"><strong>1 · Maintenance</strong><span id="rc_stage_maint_label" class="rc-stage-idle">Waiting</span></button>
                <button type="button" class="rc-stage-chip" data-stage="import"><strong>2 · DB Import</strong><span id="rc_stage_import_label" class="rc-stage-idle">Waiting</span></button>
                <button type="button" class="rc-stage-chip" data-stage="uploads"><strong>3 · Uploads Cutover</strong><span id="rc_stage_uploads_label" class="rc-stage-idle">Waiting</span></button>
                <button type="button" class="rc-stage-chip" data-stage="rollback"><strong>4 · Rollback</strong><span id="rc_stage_rollback_label" class="rc-stage-idle">Waiting</span></button>
                <button type="button" class="rc-stage-chip" data-stage="finalize"><strong>5 · Finalize</strong><span id="rc_stage_finalize_label" class="rc-stage-idle">Waiting</span></button>
            </div>
            <div class="rc-stage-panels">
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="maint" id="rc_maint_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">Production Maintenance</span>
                        <span class="rc-acc-meta"><span class="rc-muted">تفاصيل المرحلة</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_maint_banner" class="rc-readonly-banner" role="status">
                            <strong>Production restore has NOT started.</strong>
                        </p>
                        <dl id="rc_maint_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>الملصق</dt><dd class="rc-stage-idle">Waiting</dd></div>
                            <div><dt>المهمة المرتبطة</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>وقت الطلب</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>وقت التفعيل</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>آخر نبضة</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>Stale</dt><dd class="rc-stage-idle">No activity</dd></div>
                        </dl>
                        <div id="rc_maint_policy" class="muted" style="margin-top:8px;"></div>
                    </div>
                </details>
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="import" id="rc_prod_import_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">Production Database Import</span>
                        <span class="rc-acc-meta"><span class="rc-muted">تفاصيل المرحلة</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_prod_import_banner" class="rc-readonly-banner" role="status">
                            <strong>Application files have NOT been switched.</strong>
                        </p>
                        <dl id="rc_prod_import_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">Waiting</dd></div>
                            <div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>CLI</dt><dd class="rc-stage-idle">No activity</dd></div>
                        </dl>
                    </div>
                </details>
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="uploads" id="rc_uploads_cutover_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">Production Uploads Cutover</span>
                        <span class="rc-acc-meta"><span class="rc-muted">تفاصيل المرحلة</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_uploads_cutover_banner" class="rc-readonly-banner" role="status">
                            <strong>Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.</strong>
                        </p>
                        <dl id="rc_uploads_cutover_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">Waiting</dd></div>
                            <div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>CLI</dt><dd class="rc-stage-idle">No activity</dd></div>
                        </dl>
                    </div>
                </details>
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="rollback" id="rc_rollback_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">Production Rollback</span>
                        <span class="rc-acc-meta"><span class="rc-muted">تفاصيل المرحلة</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_rollback_banner" class="rc-readonly-banner" role="status">
                            <strong>Maintenance remains active. Restore is NOT completed. Rollback anchor retained.</strong>
                        </p>
                        <dl id="rc_rollback_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">Waiting</dd></div>
                            <div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>CLI</dt><dd class="rc-stage-idle">No activity</dd></div>
                        </dl>
                    </div>
                </details>
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="finalize" id="rc_finalize_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">Finalize &amp; Maintenance Release</span>
                        <span class="rc-acc-meta"><span class="rc-muted">تفاصيل المرحلة</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_finalize_banner" class="rc-readonly-banner" role="status">
                            <strong>Finalization releases maintenance after restore or rollback success. Forensic artifacts retained.</strong>
                        </p>
                        <dl id="rc_finalize_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">Waiting</dd></div>
                            <div><dt>الصيانة</dt><dd class="rc-stage-idle">No activity</dd></div>
                            <div><dt>CLI</dt><dd class="rc-stage-idle">No activity</dd></div>
                        </dl>
                    </div>
                </details>
            </div>
        </div>
    </section>

    <section class="rc-phase" aria-labelledby="rc_phase6_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">6</span>
            <h2 class="rc-phase-title" id="rc_phase6_title">Monitoring</h2>
            <p class="rc-phase-hint">تقدم المهمة والتفاصيل — استخدم View داخل قائمة المهام أو المهمة النشطة.</p>
        </div>
        <div class="rc-panel">
            <p class="rc-muted" style="margin:0 0 8px;" id="rc_monitor_hint">بعد التحديث تظهر المهمة النشطة أعلاه؛ تفاصيل JSON عبر أزرار View.</p>
            <div id="rc_monitor_snapshot" class="rc-status-strip">
                <div><dt>Jobs</dt><dd id="rc_mon_jobs">—</dd></div>
                <div><dt>Active</dt><dd id="rc_mon_active">—</dd></div>
                <div><dt>Phase</dt><dd id="rc_mon_phase">—</dd></div>
                <div><dt>Progress</dt><dd id="rc_mon_progress">—</dd></div>
            </div>
        </div>
    </section>
</div>

<div id="rc_view_modal" class="rc-modal-backdrop" aria-hidden="true">
    <div class="rc-modal" role="dialog" aria-modal="true" style="max-width:760px;">
        <h3 id="rc_view_title">عرض</h3>
        <p class="rc-tz-label" id="rc_view_tz_note" style="margin:0 0 8px;"></p>
        <div id="rc_view_structured" style="display:none;"></div>
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
    /** IANA from Country Configuration (countries.timezone) — presentation only; storage stays UTC. */
    const DISPLAY_TZ = <?php echo json_encode($displayTimezone, JSON_UNESCAPED_UNICODE); ?>;
    const COUNTRY_CONTEXT_CODE = <?php echo json_encode($countryContextCode, JSON_UNESCAPED_UNICODE); ?>;

    let state = { full: [], country: [], jobs: [], busy: false, csrf: '', lastOverview: null, lastMaintenance: null, openedStage: '' };

    const el = (id) => document.getElementById(id);
    const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

    const ISO_TS_RE = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/;
    const ISO_TS_GLOBAL_RE = /\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/g;
    const PKG_ID_RE = /^(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})$/;
    const GMDATE_NAIVE_RE = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/;
    const PACKAGE_ID_COUNTRY_WALL_TZ = 'Asia/Kuwait';

    const hasDisplayTz = () => typeof DISPLAY_TZ === 'string' && DISPLAY_TZ.trim() !== '';

    const parseIsoAsWritten = (s) => {
        const m = String(s || '').trim().match(ISO_TS_RE);
        if (!m) return null;
        let offset = m[3];
        if (!offset) return null;
        if (offset !== 'Z' && /^[+-]\d{4}$/.test(offset)) {
            offset = offset.slice(0, 3) + ':' + offset.slice(3);
        }
        const iso = m[1] + 'T' + m[2] + (offset === 'Z' ? 'Z' : offset);
        const d = new Date(iso);
        return Number.isNaN(d.getTime()) ? null : d;
    };

    const parseGmdateNaiveUtc = (s) => {
        const m = String(s || '').trim().match(GMDATE_NAIVE_RE);
        if (!m) return null;
        const d = new Date(m[1] + 'T' + m[2] + 'Z');
        return Number.isNaN(d.getTime()) ? null : d;
    };

    const parseUnixEpoch = (raw) => {
        const s = String(raw || '').trim();
        if (!/^\d+(\.\d+)?$/.test(s)) return null;
        const n = Number(s);
        if (!Number.isFinite(n)) return null;
        const ms = n < 1e12 ? n * 1000 : n;
        const d = new Date(ms);
        return Number.isNaN(d.getTime()) ? null : d;
    };

    const parseWallClockInZone = (dateStr, h, mi, s, wallTz) => {
        if (!wallTz) return null;
        try {
            const guess = new Date(Date.UTC(
                Number(dateStr.slice(0, 4)),
                Number(dateStr.slice(5, 7)) - 1,
                Number(dateStr.slice(8, 10)),
                Number(h), Number(mi), Number(s)
            ));
            if (Number.isNaN(guess.getTime())) return null;
            const fmt = new Intl.DateTimeFormat('en-US', {
                timeZone: wallTz,
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit',
                hour12: false, hourCycle: 'h23'
            });
            const parts = fmt.formatToParts(guess);
            const get = (t) => (parts.find((p) => p.type === t) || {}).value || '';
            const asWall = Date.UTC(
                Number(get('year')), Number(get('month')) - 1, Number(get('day')),
                Number(get('hour')), Number(get('minute')), Number(get('second'))
            );
            const delta = asWall - guess.getTime();
            const instant = new Date(guess.getTime() - delta);
            const check = fmt.formatToParts(instant);
            const cg = (t) => (check.find((p) => p.type === t) || {}).value || '';
            if (
                cg('year') + '-' + cg('month') + '-' + cg('day') !== dateStr
                || cg('hour') !== h || cg('minute') !== mi || cg('second') !== s
            ) {
                const asWall2 = Date.UTC(
                    Number(cg('year')), Number(cg('month')) - 1, Number(cg('day')),
                    Number(cg('hour')), Number(cg('minute')), Number(cg('second'))
                );
                const instant2 = new Date(instant.getTime() - (asWall2 - instant.getTime()));
                return Number.isNaN(instant2.getTime()) ? null : instant2;
            }
            return instant;
        } catch (e) {
            return null;
        }
    };

    const parsePackageIdWall = (raw, wallTz) => {
        const m = String(raw || '').trim().match(PKG_ID_RE);
        if (!m) return null;
        return parseWallClockInZone(m[1], m[2], m[3], m[4], wallTz);
    };

    const parseBackupInstant = (raw, source) => {
        const s = String(raw || '').trim();
        if (!s) return { date: null, error: 'empty', source: source };
        if (source === 'unix' || (source === 'auto' && /^\d+(\.\d+)?$/.test(s))) {
            const d = parseUnixEpoch(s);
            return d ? { date: d, error: null, source: 'unix' } : { date: null, error: 'unix_parse_failed', source: 'unix' };
        }
        if (source === 'package_id_country') {
            const d = parsePackageIdWall(s, PACKAGE_ID_COUNTRY_WALL_TZ);
            return d
                ? { date: d, error: null, source: 'package_id_country' }
                : { date: null, error: 'package_id_wall_parse_failed', source: 'package_id_country' };
        }
        if (source === 'generated_at' || source === 'iso_utc' || source === 'auto') {
            const withOffset = parseIsoAsWritten(s);
            if (withOffset) return { date: withOffset, error: null, source: 'iso_offset' };
            if ((source === 'generated_at' || source === 'iso_utc') && ISO_TS_RE.test(s) && !s.match(/[Zz]|[+-]\d{2}:?\d{2}$/)) {
                const m = s.match(ISO_TS_RE);
                if (m) {
                    const d = new Date(m[1] + 'T' + m[2] + 'Z');
                    if (!Number.isNaN(d.getTime())) return { date: d, error: null, source: 'generated_at_naive_utc' };
                }
            }
            if (source === 'gmdate_naive_utc' || source === 'auto' || source === 'generated_at') {
                const g = parseGmdateNaiveUtc(s);
                if (g) return { date: g, error: null, source: 'gmdate_naive_utc' };
            }
        }
        if (source === 'gmdate_naive_utc') {
            const g = parseGmdateNaiveUtc(s);
            return g ? { date: g, error: null, source: 'gmdate_naive_utc' } : { date: null, error: 'gmdate_parse_failed', source: source };
        }
        return { date: null, error: 'unrecognized_timestamp', source: source };
    };

    const formatInDisplayTz = (date) => {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return null;
        if (!hasDisplayTz()) return null;
        try {
            const parts = new Intl.DateTimeFormat('en-US', {
                timeZone: DISPLAY_TZ,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            }).formatToParts(date);
            const get = (type) => {
                const p = parts.find((x) => x.type === type);
                return p ? p.value : '';
            };
            const dateStr = get('year') + '-' + get('month') + '-' + get('day');
            const hour = get('hour');
            const minute = get('minute');
            const second = get('second');
            const dayPeriod = (get('dayPeriod') || '').toUpperCase();
            const timeStr = hour + ':' + minute + ':' + second + (dayPeriod ? ' ' + dayPeriod : '');
            return { dateStr, timeStr, label: dateStr + ' ' + timeStr };
        } catch (e) {
            console.warn('[restore_center] formatInDisplayTz failed', e, DISPLAY_TZ);
            return null;
        }
    };

    const fmtTimestampRawWarn = (raw, reason) => {
        const s = String(raw || '').trim() || '—';
        if (reason) console.warn('[restore_center] timestamp not converted:', reason, s);
        return '<time class="rc-ts rc-ts--raw" title="' + esc('unconverted: ' + (reason || 'unknown')) + '">'
            + esc(s) + ' <span class="rc-ts-warn">(unconverted)</span></time>';
    };

    const fmtTimestampDisplay = (raw, source) => {
        source = source || 'generated_at';
        const s = String(raw == null ? '' : raw).trim();
        if (!s) return '—';
        if (!hasDisplayTz()) {
            return fmtTimestampRawWarn(s, 'countries.timezone_missing');
        }
        const parsed = parseBackupInstant(s, source);
        if (!parsed.date) {
            return fmtTimestampRawWarn(s, parsed.error || 'parse_failed');
        }
        const local = formatInDisplayTz(parsed.date);
        if (!local) {
            return fmtTimestampRawWarn(s, 'display_tz_format_failed');
        }
        const title = esc(local.label + ' (' + DISPLAY_TZ + ')');
        return '<time class="rc-ts" title="' + title + '"><span class="rc-ts-date">' + esc(local.dateStr)
            + '</span><span class="rc-ts-time">' + esc(local.timeStr) + '</span></time>';
    };

    const fmtTimestampPlain = (raw, source) => {
        source = source || 'generated_at';
        if (!hasDisplayTz()) return String(raw || '').trim();
        const parsed = parseBackupInstant(String(raw || '').trim(), source);
        if (!parsed.date) return String(raw || '').trim();
        const local = formatInDisplayTz(parsed.date);
        return local ? local.label : String(raw || '').trim();
    };

    const fmtPackageWhenDisplay = (pkg, type) => {
        const generated = String((pkg && pkg.generated_at) || '').trim();
        if (generated) return fmtTimestampDisplay(generated, 'generated_at');
        const id = String((pkg && pkg.package_id) || '').trim();
        if (id && type === 'country_recovery' && PKG_ID_RE.test(id)) {
            return fmtTimestampDisplay(id, 'package_id_country');
        }
        if (id) return fmtTimestampRawWarn(id, 'package_id_not_converted_use_generated_at');
        return '—';
    };

    const localizeTimestampsInText = (text) => {
        return String(text || '').replace(ISO_TS_GLOBAL_RE, (match) => {
            if (/[Zz]|[+-]\d{2}:?\d{2}$/.test(match)) {
                return fmtTimestampPlain(match, 'iso_utc');
            }
            return fmtTimestampPlain(match, 'gmdate_naive_utc');
        });
    };

    const badge = (status) => {
        const s = String(status || '').toLowerCase();
        let cls = 'rc-badge--muted';
        if (s === 'healthy' || s === 'success' || s === 'pass' || s === 'eligible' || s === 'completed' || s === 'dry_completed' || s === 'approved_waiting_execution' || s === 'pre_restore_backup_ready' || s === 'shadow_restore_ready' || s === 'shadow_verified' || s === 'shadow_files_ready' || s === 'shadow_smoke_ready' || s === 'cutover_readiness_ready' || s === 'country_shadow_verified' || s === 'ready' || s === 'country_dry_run_safe' || s === 'safe') cls = 'rc-badge--success';
        else if (s === 'warning' || s === 'warn' || s === 'awaiting_owner_approval' || s === 'awaiting_final_approval' || s === 'waiting_confirmation' || s === 'execution_plan_ready' || s === 'pre_restore_backup_pending' || s === 'shadow_restore_pending' || s === 'shadow_not_ready' || s === 'shadow_smoke_pending' || s === 'shadow_smoke_warning' || s === 'cutover_readiness_manual_review' || s === 'country_shadow_warning' || s === 'country_dry_run_warning') cls = 'rc-badge--warning';
        else if (s === 'failed' || s === 'fail' || s === 'error' || s === 'not_eligible' || s === 'dry_failed' || s === 'execution_failed' || s === 'execution_cancelled' || s === 'cancelled' || s === 'pre_restore_backup_failed' || s === 'shadow_restore_failed' || s === 'shadow_files_failed' || s === 'shadow_smoke_failed' || s === 'cutover_readiness_blocked' || s === 'country_shadow_not_ready' || s === 'country_dry_run_failed') cls = 'rc-badge--failed';
        else if (s === 'running' || s.includes('progress') || s.includes('staging') || s.includes('merge') || s === 'execution_precheck' || s === 'dry_running' || s === 'pre_restore_backup_running' || s === 'pre_restore_backup_verifying' || s === 'shadow_restore_running' || s === 'shadow_restore_verifying' || s === 'shadow_verifying' || s === 'shadow_files_running' || s === 'shadow_files_verifying' || s === 'shadow_smoke_running' || s === 'country_shadow_verifying' || s === 'country_dry_run_running') cls = 'rc-badge--running';
        let label = status || '—';
        if (s === 'awaiting_final_approval') label = 'بانتظار الموافقة النهائية';
        if (s === 'approved_waiting_execution') label = 'معتمدة — بانتظار التنفيذ';
        if (s === 'country_shadow_verifying') label = 'جارٍ تحقق ظل الدولة (C7)';
        if (s === 'country_shadow_verified') label = 'ظل الدولة موثّق (C7 READY)';
        if (s === 'country_shadow_warning') label = 'ظل الدولة — تحذير (C7)';
        if (s === 'country_shadow_not_ready') label = 'ظل الدولة غير جاهز (C7)';
        if (s === 'country_dry_run_running') label = 'جارٍ محاكاة Country Dry Run (C8)';
        if (s === 'country_dry_run_safe') label = 'Country Dry Run آمن (SAFE)';
        if (s === 'country_dry_run_warning') label = 'Country Dry Run — تحذير';
        if (s === 'country_dry_run_failed') label = 'Country Dry Run فشل';
        if (s === 'safe') label = 'SAFE';
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

    function deriveReadiness(ov, maint) {
        const counts = (ov && ov.job_counts) || {};
        const lock = (ov && ov.restore_lock) || {};
        const m = maint || (ov && ov.maintenance) || {};
        if (m.maintenance_active || m.active) {
            return { key: 'maintenance', label: 'Maintenance Active', tone: 'failed' };
        }
        if (lock.held) {
            return { key: 'blocked', label: 'Blocked', tone: 'failed' };
        }
        if (Number(counts.awaiting_owner_approval || 0) > 0) {
            return { key: 'waiting', label: 'Waiting Approval', tone: 'warning' };
        }
        if (Number(counts.active_in_progress || 0) > 0) {
            return { key: 'running', label: 'Validation Required', tone: 'running' };
        }
        if (Number(counts.failed_jobs || 0) > 0 && Number(counts.active_in_progress || 0) === 0
            && Number(counts.awaiting_owner_approval || 0) === 0) {
            return { key: 'validation', label: 'Validation Required', tone: 'warning' };
        }
        return { key: 'ready', label: 'System Ready', tone: 'success' };
    }

    function renderOverview(data) {
        const ov = data.overview || {};
        state.lastOverview = ov;
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
            '<article class="rc-op-card"><h3>' + t + '</h3><div class="rc-op-rows"><div class="rc-op-row"><dt>العدد</dt><dd>' + v + '</dd></div></div></article>'
        ).join('');
        const lock = ov.restore_lock || {};
        const maint = ov.maintenance || {};
        el('rc_lock_maintenance').innerHTML =
            '<div><dt>قفل الاسترداد العام</dt><dd>' + (lock.held ? badge('held — ' + (lock.job_id || '')) : badge('متاح')) + '</dd></div>' +
            '<div><dt>وضع الصيانة (قديم/دمج)</dt><dd>' + (maint.active ? badge('active — ' + (maint.job_id || '')) : badge('غير مفعّل')) + '</dd></div>';
        const ready = deriveReadiness(ov, state.lastMaintenance || maint);
        const badgeEl = el('rc_readiness_badge');
        if (badgeEl) {
            badgeEl.className = 'rc-badge rc-badge--' + ready.tone;
            badgeEl.textContent = ready.label;
        }
        const head = el('rc_readiness_headline');
        if (head) head.textContent = ready.label;
    }

    function packageExpandedActions(pkg, type) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        // Primary inside expand: Package Details. Diagnostics stay secondary.
        let html = '<div class="rc-action-row">'
            + '<button type="button" class="btn-link rc-btn-primary rc-pkg-detail" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">Package Details</button>'
            + '</div>';
        let secondary = '';
        const files = type === 'full_disaster'
            ? [['manifest.json', 'Manifest'], ['health.json', 'Health'], ['recovery_validation.json', 'DRV Report']]
            : [['manifest.json', 'Manifest'], ['health.json', 'Health'], ['country_verify_report.json', 'Verify'], ['country_recovery_validation.json', 'Country DRV']];
        files.forEach(([file, label]) => {
            secondary += '<button type="button" class="btn-link rc-btn-ghost rc-view-file" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '" data-file="' + file + '">' + label + '</button> ';
        });
        if ((pkg.eligibility_status || pkg.restore_eligibility) === 'eligible') {
            secondary += '<button type="button" class="btn-link rc-btn-ghost rc-dry-run" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">Dry Validation</button>';
        }
        html += '<div class="rc-action-row rc-action-row--secondary">' + secondary + '</div>';
        return html;
    }

    function packagePrimaryAction(pkg, type) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        if ((pkg.eligibility_status || pkg.restore_eligibility) === 'eligible') {
            return '<button type="button" class="btn-link rc-btn-primary rc-create-job" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">إنشاء مهمة استرداد</button>';
        }
        // Expands the package accordion (details open) — wording Owner 2026-07-23
        return '<button type="button" class="btn-link rc-btn-primary rc-pkg-expand" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">تفاصيل الحزمة</button>';
    }

    /** Capability-preserving action set (expanded + create when eligible). */
    function packageActions(pkg, type) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        let html = packageExpandedActions(pkg, type);
        if ((pkg.eligibility_status || pkg.restore_eligibility) === 'eligible') {
            html += ' <button type="button" class="btn-link rc-create-job" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">إنشاء مهمة</button>';
        }
        return html;
    }

    function emptyStateHtml(title, body) {
        return '<div class="rc-empty"><h4>' + esc(title) + '</h4><p>' + esc(body) + '</p></div>';
    }

    function packageAccordionHtml(pkg, type) {
        const identity = String(pkg.package_id || '').trim();
        const whenHtml = fmtPackageWhenDisplay(pkg, type);
        const countryBit = (!type || type === 'full_disaster')
            ? ''
            : (pkg.country_code ? String(pkg.country_code) + ' · ' : '');
        // Time primary; package_id secondary identifier only
        const idHtml = identity
            ? '<span class="rc-pkg-id" dir="ltr" title="package_id (identifier)">' + esc(countryBit + identity) + '</span>'
            : '';
        return (
            '<details class="rc-acc-item" data-rc-acc="pkg" data-package-id="' + esc(identity) + '">' +
            '<summary>' +
                '<span class="rc-acc-chevron" aria-hidden="true"></span>' +
                '<span class="rc-acc-meta">' +
                    '<span class="rc-acc-when" dir="ltr" title="Time">' + whenHtml + '</span>' +
                    idHtml +
                    badge(pkg.package_status || '—') +
                    eligibilityBadge(pkg) +
                '</span>' +
                '<span class="rc-acc-actions-inline">' + packagePrimaryAction(pkg, type) + '</span>' +
            '</summary>' +
            '<div class="rc-acc-body">' +
                packageExpandedActions(pkg, type) +
                '<p class="rc-muted" style="margin:10px 0 0;font-size:.8rem;"><strong>Metadata</strong> — Schema: ' + esc(String(pkg.schema_revision || '—')) +
                ' · Backend: ' + esc(String(pkg.backend || '—')) +
                ' · DRV: ' + drvCell(pkg) +
                (pkg.registry_version ? (' · Registry: ' + esc(String(pkg.registry_version))) : '') +
                (pkg.country_name ? (' · ' + esc(String(pkg.country_name))) : '') +
                (identity ? (' · ID: <span class="rc-pkg-id">' + esc(identity) + '</span>') : '') +
                '</p>' +
            '</div>' +
            '</details>'
        );
    }

    function jobAccordionHtml(job) {
        const id = String(job.job_id || '');
        const pkgLabel = (job.package_type || '') + (job.country_code ? ' / ' + job.country_code : '') + ' / ' + (job.package_id || '—');
        const dryBadge = job.dry_run_overall_result ? ' ' + dryResultBadge(job.dry_run_overall_result) : '';
        const viewBtn = '<button type="button" class="btn-link rc-btn-primary rc-fw-view" data-id="' + id + '">View</button>';
        const actions = jobActions(job, { omitPrimaryView: true });
        return (
            '<details class="rc-acc-item" data-rc-acc="job" data-job-id="' + esc(id) + '">' +
            '<summary>' +
                '<span class="rc-acc-chevron" aria-hidden="true"></span>' +
                '<span class="rc-acc-title"><code>' + esc(id) + '</code></span>' +
                '<span class="rc-acc-meta">' +
                    '<span class="rc-acc-when" dir="ltr">' + fmtTimestampDisplay(job.created_at, 'generated_at') + '</span>' +
                    badge(job.status) + dryBadge +
                    '<span class="rc-muted">' + esc(String(job.phase || '—')) + ' · ' + esc(String(job.progress ?? 0)) + '%</span>' +
                '</span>' +
                '<span class="rc-acc-actions-inline">' + viewBtn + '</span>' +
            '</summary>' +
            '<div class="rc-acc-body">' +
                '<p class="rc-muted" style="margin:0 0 8px;font-size:.82rem;">Package: ' + esc(pkgLabel) + '</p>' +
                '<p style="margin:0 0 8px;font-size:.86rem;">' + esc(String(job.message || '—')) + '</p>' +
                '<div class="rc-action-row">' + actions + '</div>' +
            '</div>' +
            '</details>'
        );
    }

    function openStagePanel(stageKey) {
        state.openedStage = String(stageKey || '');
        document.querySelectorAll('details.rc-stage-acc').forEach((d) => {
            d.open = state.openedStage !== '' && d.getAttribute('data-stage') === state.openedStage;
        });
        document.querySelectorAll('.rc-stage-chip').forEach((chip) => {
            chip.classList.toggle('is-selected', chip.getAttribute('data-stage') === state.openedStage);
        });
    }

    function detectActiveStage(maint, jobs) {
        const active = pickActiveJob(jobs);
        const st = String((active && active.status) || '').toLowerCase();
        const m = maint || {};
        if (st.includes('finaliz') || (active && (active.is_restore_completed || active.is_rollback_completed))) return 'finalize';
        if (st.includes('rollback') && !st.includes('finaliz')) return 'rollback';
        if (st.includes('uploads_cutover')) return 'uploads';
        if (st.includes('production_import')) return 'import';
        if (m.maintenance_active || m.maintenance_ready || st.includes('maintenance')) return 'maint';
        return '';
    }

    function pickActiveJob(jobs) {
        const list = Array.isArray(jobs) ? jobs : [];
        const active = list.find((j) => {
            const s = String(j.status || '').toLowerCase();
            return j.is_maintenance_active || j.is_maintenance_ready
                || s.includes('running') || s.includes('pending') || s.includes('progress')
                || s === 'awaiting_owner_approval' || s === 'awaiting_final_approval'
                || s === 'approved_waiting_execution' || s === 'execution_precheck'
                || s === 'dry_running';
        });
        return active || list[0] || null;
    }

    function updateStageStrip(maint, jobs) {
        const active = pickActiveJob(jobs);
        const st = String((active && active.status) || '').toLowerCase();
        const m = maint || {};
        const setChip = (stage, label, on) => {
            const chip = document.querySelector('.rc-stage-chip[data-stage="' + stage + '"]');
            const map = {
                maint: 'rc_stage_maint_label',
                import: 'rc_stage_import_label',
                uploads: 'rc_stage_uploads_label',
                rollback: 'rc_stage_rollback_label',
                finalize: 'rc_stage_finalize_label'
            };
            const node = el(map[stage]);
            if (node) node.textContent = label;
            if (chip) chip.classList.toggle('is-active', !!on);
        };
        setChip('maint', m.maintenance_active ? 'Active' : (m.maintenance_ready ? 'Ready' : (m.state || 'Waiting')), !!(m.maintenance_active || m.maintenance_ready || st.includes('maintenance')));
        setChip('import', st.includes('production_import') ? String(active.status || 'Running') : 'Waiting', st.includes('production_import'));
        setChip('uploads', st.includes('uploads_cutover') ? String(active.status || 'Running') : 'Waiting', st.includes('uploads_cutover'));
        setChip('rollback', (st.includes('rollback') && !st.includes('finaliz')) ? String(active.status || 'Running') : 'Waiting', st.includes('rollback') && !st.includes('finaliz'));
        setChip('finalize', (st.includes('finaliz') || st.includes('completed')) ? String((active && active.status) || 'Completed') : 'Waiting', st.includes('finaliz') || !!(active && (active.is_restore_completed || active.is_rollback_completed)));

        const activeStage = detectActiveStage(m, jobs);
        if (activeStage) {
            openStagePanel(activeStage);
        } else if (!state.openedStage) {
            document.querySelectorAll('details.rc-stage-acc').forEach((d) => { d.open = false; });
        }
    }

    function renderActiveJob(jobs) {
        const box = el('rc_active_job');
        const meta = el('rc_active_job_meta');
        const acts = el('rc_active_job_actions');
        const hist = el('rc_jobs_hist_label');
        const list = Array.isArray(jobs) ? jobs : [];
        const job = pickActiveJob(list);
        if (!box || !meta || !acts) return;
        if (!job) {
            box.hidden = true;
            meta.innerHTML = '';
            acts.innerHTML = '';
            if (hist) hist.hidden = true;
            if (el('rc_mon_active')) el('rc_mon_active').textContent = '—';
            if (el('rc_mon_phase')) el('rc_mon_phase').textContent = '—';
            if (el('rc_mon_progress')) el('rc_mon_progress').textContent = '—';
            return;
        }
        box.hidden = false;
        if (hist) hist.hidden = list.length <= 1;
        meta.innerHTML =
            '<span><strong>Job</strong> <code>' + esc(job.job_id || '') + '</code></span>' +
            '<span>' + badge(job.status) + '</span>' +
            '<span><strong>Phase</strong> ' + esc(String(job.phase || '—')) + '</span>' +
            '<span><strong>Progress</strong> ' + esc(String(job.progress ?? 0)) + '%</span>' +
            '<span class="rc-muted">' + esc(String(job.message || '')) + '</span>';
        acts.innerHTML = '<button type="button" class="btn-link rc-btn-primary rc-fw-view" data-id="' + esc(job.job_id || '') + '">View</button> '
            + '<div class="rc-action-row" style="margin:0">' + jobActions(job, { omitPrimaryView: true }) + '</div>';
        if (el('rc_mon_jobs')) el('rc_mon_jobs').textContent = String(list.length);
        if (el('rc_mon_active')) el('rc_mon_active').textContent = job.job_id || '—';
        if (el('rc_mon_phase')) el('rc_mon_phase').textContent = job.phase || '—';
        if (el('rc_mon_progress')) el('rc_mon_progress').textContent = String(job.progress ?? 0) + '%';
    }

    function renderTables(data) {
        state.full = data.full_packages || [];
        state.country = data.country_packages || [];
        state.jobs = data.framework_jobs || data.jobs || [];
        if (data.csrf_token) state.csrf = data.csrf_token;

        if (CAN_FULL && el('rc_full_list')) {
            el('rc_full_list').removeAttribute('aria-busy');
            el('rc_full_list').innerHTML = state.full.length
                ? state.full.map((p) => packageAccordionHtml(p, 'full_disaster')).join('')
                : emptyStateHtml('لا توجد حزم Full', 'بعد إنشاء Full Backup ستظهر الحزم المؤهلة للاسترداد هنا.');
        }
        if (CAN_COUNTRY && el('rc_country_list')) {
            el('rc_country_list').removeAttribute('aria-busy');
            el('rc_country_list').innerHTML = state.country.length
                ? state.country.map((p) => packageAccordionHtml(p, 'country_recovery')).join('')
                : emptyStateHtml('لا توجد حزم دول', 'لا توجد حزم Country ضمن السياق الحالي جاهزة للاسترداد.');
        }
        if (el('rc_jobs_list')) {
            el('rc_jobs_list').removeAttribute('aria-busy');
            el('rc_jobs_list').innerHTML = state.jobs.length
                ? state.jobs.map((j) => jobAccordionHtml(j)).join('')
                : emptyStateHtml(
                    'لا توجد Restore Jobs بعد',
                    'اختر حزمة مؤهلة من القسم 2 ثم أنشئ مهمة استرداد. ستظهر المهمة النشطة والتقدم هنا.'
                );
        }
        if (el('rc_jobs_table') && el('rc_jobs_table').querySelector('tbody')) {
            el('rc_jobs_table').querySelector('tbody').innerHTML = state.jobs.length
                ? state.jobs.map((j) => {
                    const pkgLabel = (j.package_type || '') + (j.country_code ? ' / ' + j.country_code : '') + ' / ' + (j.package_id || '—');
                    const dryBadge = j.dry_run_overall_result ? ' ' + dryResultBadge(j.dry_run_overall_result) : '';
                    return '<tr><td><code>' + j.job_id + '</code></td><td>' + pkgLabel + '</td><td class="rc-ts-cell">' + fmtTimestampDisplay(j.created_at, 'generated_at') + '</td><td>' + badge(j.status) + dryBadge + '</td><td>' + (j.phase || '—') + '</td><td>' + String(j.progress ?? 0) + '%</td><td>' + (j.message || '—') + '</td><td class="rc-actions">' + jobActions(j) + '</td></tr>';
                }).join('')
                : '<tr><td colspan="8" class="muted">لا توجد Restore Jobs.</td></tr>';
        }
        renderActiveJob(state.jobs);
        updateStageStrip(state.lastMaintenance || data.maintenance || {}, state.jobs);
        if (el('rc_mon_jobs')) el('rc_mon_jobs').textContent = String(state.jobs.length);
        if (el('rc_overview')) el('rc_overview').removeAttribute('aria-busy');
        if (el('rc_lock_maintenance')) el('rc_lock_maintenance').removeAttribute('aria-busy');
        if (el('rc_readiness_badge')) el('rc_readiness_badge').removeAttribute('aria-busy');
        if (el('rc_readiness_headline')) el('rc_readiness_headline').removeAttribute('aria-busy');
    }

    function renderStructuredDetail(obj) {
        const sections = [];
        const push = (title, data) => {
            if (data === undefined || data === null || data === '') return;
            if (typeof data === 'object' && !Array.isArray(data) && !Object.keys(data).length) return;
            if (Array.isArray(data) && !data.length) return;
            const text = (typeof data === 'string') ? data : JSON.stringify(data, null, 2);
            sections.push('<div class="rc-drawer-group"><h4>' + esc(title) + '</h4><pre class="rc-pre" style="max-height:180px;">' + esc(localizeTimestampsInText(text)) + '</pre></div>');
        };
        push('Summary', {
            job_id: obj.job_id || obj.id || undefined,
            status: obj.status || obj.status_label || undefined,
            package_id: obj.package_id || (obj.package && obj.package.package_id) || undefined,
            phase: obj.phase || undefined,
            progress: obj.progress,
            message: obj.message || obj.warning || undefined
        });
        push('Validation', obj.validation || obj.dry_run || obj.report || obj.cutover_readiness || undefined);
        push('Diagnostics', {
            cli_needed: obj.cli_needed,
            cli_command: obj.cli_command || ((obj.meta || {}).cli_command),
            highest_checkpoint: obj.highest_checkpoint,
            production_touched: obj.production_touched,
            execution_started: obj.execution_started,
            files_switched: obj.files_switched,
            rollback_executed: obj.rollback_executed
        });
        push('Manifest / Health / DRV', obj.manifest || obj.health || obj.drv || obj.package || undefined);
        push('Logs / Timeline', obj.timeline || obj.checkpoint_history || obj.artifacts || undefined);
        push('Package Metadata', obj.meta || obj.record || obj.contract || obj.maintenance || undefined);
        if (!sections.length) return '';
        return sections.join('');
    }

    function openView(title, content) {
        el('rc_view_title').textContent = title;
        const note = el('rc_view_tz_note');
        if (note) {
            if (!hasDisplayTz()) {
                note.textContent = 'تحذير: countries.timezone غير مضبوط — عُرض النص الخام دون تحويل.';
            } else {
                note.textContent = 'التواريخ في هذا العرض بالتوقيت المحلي (' + DISPLAY_TZ + ') بنظام 12 ساعة — التخزين الداخلي يبقى UTC.';
            }
        }
        const body = String(content || '');
        let structured = '';
        try {
            const obj = JSON.parse(body);
            if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
                structured = renderStructuredDetail(obj);
            }
        } catch (e) { /* plain */ }
        const host = el('rc_view_structured');
        if (structured && host) {
            host.style.display = 'block';
            host.innerHTML = structured;
            el('rc_view_pre').style.display = 'block';
            el('rc_view_pre').textContent = localizeTimestampsInText(body);
        } else {
            if (host) { host.style.display = 'none'; host.innerHTML = ''; }
            el('rc_view_pre').style.display = 'block';
            el('rc_view_pre').textContent = localizeTimestampsInText(body);
        }
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
        const structured = renderStructuredDetail(job);
        const raw = localizeTimestampsInText(renderJobDetail(job));
        el('rc_detail_body').innerHTML =
            (structured || '') +
            '<div class="rc-drawer-group"><h4>Full Detail</h4><pre class="rc-pre">' + esc(raw) + '</pre></div>';
        el('rc_detail_modal').style.display = 'flex';
    }

    function renderMaintenance(m) {
        const st = m || {};
        state.lastMaintenance = st;
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


    function jobActions(job, opts) {
        opts = opts || {};
        const id = job.job_id;
        let html = opts.omitPrimaryView
            ? ''
            : '<button type="button" class="btn-link rc-fw-view" data-id="' + id + '">View</button> ';
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
        if (job.production_import_requestable) {
            html += '<button type="button" class="btn-link rc-prod-import-req" data-id="' + id + '">طلب استيراد قاعدة الإنتاج</button> ';
        }
        if (job.has_production_import || job.is_production_import_ready || job.is_production_import_failed) {
            html += '<button type="button" class="btn-link rc-prod-import-view" data-id="' + id + '">حالة استيراد الإنتاج</button> ';
        }
        if (job.status === 'production_import_pending') {
            html += '<span class="rc-badge rc-badge--warning">Production Import Pending</span> ';
        }
        if (job.status === 'production_import_running') {
            html += '<span class="rc-badge rc-badge--warning">Running</span> ';
        }
        if (job.status === 'production_import_verifying') {
            html += '<span class="rc-badge rc-badge--warning">Verifying</span> ';
        }
        if (job.is_production_import_ready) {
            html += '<span class="rc-badge rc-badge--success">Ready</span> ';
        }
        if (job.is_production_import_failed) {
            html += '<span class="rc-badge rc-badge--failed">Failed</span> ';
        }
        if (job.production_import_requestable || job.has_production_import || job.is_production_import_ready || job.is_production_import_failed) {
            html += '<strong class="muted">Application files have NOT been switched.</strong> ';
        }
        if (job.uploads_cutover_requestable) {
            html += '<button type="button" class="btn-link rc-uploads-cutover-req" data-id="' + id + '">طلب تحويل ملفات الرفع</button> ';
        }
        if (job.has_uploads_cutover || job.is_uploads_cutover_ready || job.is_uploads_cutover_failed) {
            html += '<button type="button" class="btn-link rc-uploads-cutover-view" data-id="' + id + '">حالة تحويل الرفع</button> ';
        }
        if (job.status === 'uploads_cutover_pending') {
            html += '<span class="rc-badge rc-badge--warning">Uploads Cutover Pending</span> ';
        }
        if (job.status === 'uploads_cutover_running') {
            html += '<span class="rc-badge rc-badge--warning">Running</span> ';
        }
        if (job.status === 'uploads_cutover_verifying') {
            html += '<span class="rc-badge rc-badge--warning">Verifying</span> ';
        }
        if (job.is_uploads_cutover_ready) {
            html += '<span class="rc-badge rc-badge--success">Ready</span> ';
        }
        if (job.is_uploads_cutover_failed) {
            html += '<span class="rc-badge rc-badge--failed">Failed</span> ';
        }
        if (job.uploads_cutover_requestable || job.has_uploads_cutover || job.is_uploads_cutover_ready || job.is_uploads_cutover_failed) {
            html += '<strong class="muted">Maintenance remains active. Restore is NOT completed.</strong> ';
        }
        if (job.rollback_requestable) {
            html += '<button type="button" class="btn-link rc-rollback-req" data-id="' + id + '">طلب التراجع الإنتاجي</button> ';
        }
        if (job.has_rollback || job.is_rollback_ready || job.is_rollback_failed) {
            html += '<button type="button" class="btn-link rc-rollback-view" data-id="' + id + '">حالة التراجع</button> ';
        }
        if (job.status === 'rollback_pending') {
            html += '<span class="rc-badge rc-badge--warning">Rollback Pending</span> ';
        }
        if (job.status === 'rollback_database_running' || job.status === 'rollback_database_verifying') {
            html += '<span class="rc-badge rc-badge--warning">Rollback DB</span> ';
        }
        if (job.status === 'rollback_files_running' || job.status === 'rollback_files_verifying') {
            html += '<span class="rc-badge rc-badge--warning">Rollback Files</span> ';
        }
        if (job.is_rollback_ready) {
            html += '<span class="rc-badge rc-badge--success">Rollback Ready</span> ';
        }
        if (job.is_rollback_failed) {
            html += '<span class="rc-badge rc-badge--failed">Rollback Failed</span> ';
        }
        if (job.rollback_requestable || job.has_rollback || job.is_rollback_ready || job.is_rollback_failed) {
            html += '<strong class="muted">Maintenance remains active. Restore is NOT completed. Anchor retained.</strong> ';
        }
        if (job.finalize_requestable) {
            html += '<button type="button" class="btn-link rc-finalize-req" data-id="' + id + '">طلب الإنهاء / إطلاق الصيانة</button> ';
        }
        if (job.has_finalize || job.is_restore_completed || job.is_rollback_completed) {
            html += '<button type="button" class="btn-link rc-finalize-view" data-id="' + id + '">حالة الإنهاء</button> ';
        }
        if (job.status === 'restore_finalizing' || job.status === 'rollback_finalizing') {
            html += '<span class="rc-badge rc-badge--warning">Finalizing</span> ';
        }
        if (job.is_restore_completed) {
            html += '<span class="rc-badge rc-badge--success">Restore Completed</span> ';
        }
        if (job.is_rollback_completed) {
            html += '<span class="rc-badge rc-badge--success">Rollback Completed</span> ';
        }
        if (job.is_maintenance_released) {
            html += '<span class="rc-badge rc-badge--success">Maintenance Released</span> ';
        }
        if (job.is_execution_finished) {
            html += '<span class="rc-badge rc-badge--success">Execution Finished</span> ';
        }
        if (job.execution_plan_cancellable) {
            html += '<button type="button" class="btn-link rc-cancel-exec" data-id="' + id + '">إلغاء الخطة</button> ';
        }
        if (job.cancellable) {
            html += '<button type="button" class="btn-link rc-fw-cancel" data-id="' + id + '">Cancel</button>';
        }
        return html;
    }

    function renderCertification(cert) {
        const box = el('rc_cert_status');
        const blockersEl = el('rc_cert_blockers');
        if (!box) return;
        box.removeAttribute('aria-busy');
        if (!cert || !cert.available) {
            box.innerHTML =
                '<div><dt>Full Restore</dt><dd>لا يوجد تقرير</dd></div>' +
                '<div><dt>تمرين التراجع</dt><dd>—</dd></div>' +
                '<div><dt>العزل</dt><dd>—</dd></div>' +
                '<div><dt>Commit</dt><dd>—</dd></div>' +
                '<div><dt>تاريخ الاختبار</dt><dd>—</dd></div>' +
                '<div><dt>Country Restore</dt><dd>غير معتمد للإنتاج</dd></div>';
            if (blockersEl) {
                blockersEl.textContent = (cert && cert.message) ? String(cert.message) : 'شغّل تمرين CLI على بيئة معزولة أولاً.';
            }
            return;
        }
        const rec = String(cert.production_execution_recommendation || 'NOT_CERTIFIED');
        const full = cert.full_restore_certified ? ('معتمد (' + rec + ')') : ('غير معتمد — ' + rec);
        box.innerHTML =
            '<div><dt>Full Restore</dt><dd>' + full + '</dd></div>' +
            '<div><dt>تمرين التراجع</dt><dd>' + (cert.rollback_drill_ok ? 'ناجح' : 'فشل / غير متوفر') + '</dd></div>' +
            '<div><dt>العزل</dt><dd>' + (cert.production_isolation_ok ? 'مثبت' : 'غير مثبت') + '</dd></div>' +
            '<div><dt>Commit</dt><dd>' + (cert.tested_commit || '—') + '</dd></div>' +
            '<div><dt>تاريخ الاختبار</dt><dd class="rc-ts-cell">' + (cert.tested_at ? fmtTimestampDisplay(cert.tested_at, 'generated_at') : '—') + '</dd></div>' +
            '<div><dt>Country Restore</dt><dd>غير معتمد للإنتاج</dd></div>';
        const blockers = Array.isArray(cert.open_blockers) ? cert.open_blockers : [];
        if (blockersEl) {
            if (!blockers.length) {
                blockersEl.textContent = 'لا توجد عوائق مفتوحة في التقرير.';
            } else {
                blockersEl.innerHTML = '<strong>عوائق مفتوحة:</strong><ul style="margin:6px 0 0 0;">' +
                    blockers.map(function (b) {
                        return '<li>[' + (b.severity || '') + '] ' + (b.code || '') + ' — ' + (b.message || '') + '</li>';
                    }).join('') + '</ul>';
            }
        }
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
            try {
                const certResp = await apiGet('certification.php');
                renderCertification(certResp.certification || {});
            } catch (certErr) {
                renderCertification({ available: false, message: (certErr && certErr.message) ? certErr.message : 'تعذر تحميل الشهادة' });
            }
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

        if (t.classList.contains('rc-prod-import-req')) {
            try {
                setBusy(true, 'جاري طلب استيراد قاعدة الإنتاج…');
                const j = await apiPost('job/request-production-import.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert(
                    (j.message || 'Production Import Pending')
                    + ' — Application files have NOT been switched.'
                    + (j.cli_command ? (' CLI: ' + j.cli_command) : ''),
                    true
                );
                if (el('rc_prod_import_status')) {
                    el('rc_prod_import_status').innerHTML =
                        '<div><dt>الحالة</dt><dd>' + badge('Production Import Pending') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">No activity</dd></div>'
                        + '<div><dt>CLI</dt><dd><code>' + (j.cli_command || 'No activity') + '</code></dd></div>';
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر طلب استيراد الإنتاج', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-prod-import-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/production-import.php?id=' + encodeURIComponent(t.dataset.id || ''));
                if (el('rc_prod_import_status')) {
                    el('rc_prod_import_status').innerHTML =
                        '<div><dt>الحالة</dt><dd>' + badge(j.status_label || ((j.job || {}).status) || 'Waiting') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd>' + (j.highest_checkpoint || '<span class="rc-stage-idle">No activity</span>') + '</dd></div>'
                        + '<div><dt>CLI</dt><dd>' + (((j.meta || {}).cli_command) || '<span class="rc-stage-idle">No activity</span>') + '</dd></div>';
                }
                openView('Production Import — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label: j.status_label || '',
                    job: j.job || {},
                    meta: j.meta || {},
                    report: j.report || {},
                    checkpoint_history: j.checkpoint_history || [],
                    highest_checkpoint: j.highest_checkpoint || '',
                    execution_started: false,
                    files_switched: false,
                    rollback_executed: false,
                    maintenance_released: false,
                    production_cutover_allowed: false,
                    warning: 'Application files have NOT been switched.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-uploads-cutover-req')) {
            try {
                setBusy(true, 'جاري طلب تحويل ملفات الرفع…');
                const j = await apiPost('job/request-uploads-cutover.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert(
                    (j.message || 'Uploads Cutover Pending')
                    + ' — Maintenance remains active. Restore is NOT completed.'
                    + (j.cli_command ? (' CLI: ' + j.cli_command) : ''),
                    true
                );
                if (el('rc_uploads_cutover_status')) {
                    el('rc_uploads_cutover_status').innerHTML =
                        '<div><dt>الحالة</dt><dd>' + badge('Uploads Cutover Pending') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">No activity</dd></div>'
                        + '<div><dt>CLI</dt><dd><code>' + (j.cli_command || 'No activity') + '</code></dd></div>';
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر طلب تحويل الرفع', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-uploads-cutover-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/uploads-cutover.php?id=' + encodeURIComponent(t.dataset.id || ''));
                if (el('rc_uploads_cutover_status')) {
                    el('rc_uploads_cutover_status').innerHTML =
                        '<div><dt>الحالة</dt><dd>' + badge(j.status_label || ((j.job || {}).status) || 'Waiting') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd>' + (j.highest_checkpoint || '<span class="rc-stage-idle">No activity</span>') + '</dd></div>'
                        + '<div><dt>CLI</dt><dd>' + (((j.meta || {}).cli_command) || '<span class="rc-stage-idle">No activity</span>') + '</dd></div>';
                }
                openView('Uploads Cutover — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label: j.status_label || '',
                    job: j.job || {},
                    meta: j.meta || {},
                    report: j.report || {},
                    checkpoint_history: j.checkpoint_history || [],
                    highest_checkpoint: j.highest_checkpoint || '',
                    execution_started: false,
                    database_import_performed: false,
                    rollback_executed: false,
                    maintenance_released: false,
                    restore_completed: false,
                    warning: 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-rollback-req')) {
            try {
                setBusy(true, 'جاري طلب التراجع الإنتاجي…');
                const j = await apiPost('job/request-rollback.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert(
                    (j.message || 'Rollback Pending')
                    + ' — Maintenance remains active. Restore is NOT completed. Anchor retained.'
                    + (j.cli_command ? (' CLI: ' + j.cli_command) : ''),
                    true
                );
                if (el('rc_rollback_status')) {
                    el('rc_rollback_status').innerHTML =
                        '<div><dt>الحالة</dt><dd>' + badge('Rollback Pending') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">No activity</dd></div>'
                        + '<div><dt>CLI</dt><dd><code>' + (j.cli_command || 'No activity') + '</code></dd></div>';
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر طلب التراجع', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-rollback-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/rollback.php?id=' + encodeURIComponent(t.dataset.id || ''));
                if (el('rc_rollback_status')) {
                    el('rc_rollback_status').innerHTML =
                        '<div><dt>الحالة</dt><dd>' + badge(j.status_label || ((j.job || {}).status) || 'Waiting') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd>' + (j.highest_checkpoint || '<span class="rc-stage-idle">No activity</span>') + '</dd></div>'
                        + '<div><dt>CLI</dt><dd>' + (((j.meta || {}).cli_command) || '<span class="rc-stage-idle">No activity</span>') + '</dd></div>';
                }
                openView('Rollback — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label: j.status_label || '',
                    job: j.job || {},
                    meta: j.meta || {},
                    report: j.report || {},
                    checkpoint_history: j.checkpoint_history || [],
                    highest_checkpoint: j.highest_checkpoint || '',
                    execution_started: false,
                    maintenance_released: false,
                    restore_completed: false,
                    rollback_anchor_deleted: false,
                    retention_pin_removed: false,
                    warning: 'Maintenance remains active. Restore is NOT completed. Rollback anchor retained.'
                }, null, 2));
            } catch (e) {
                showAlert(e.message || 'تعذر العرض', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-finalize-req')) {
            try {
                setBusy(true, 'جاري طلب الإنهاء…');
                const j = await apiPost('job/request-finalize.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showAlert(
                    (j.message || 'Finalize Pending')
                    + (j.cli_command ? (' CLI: ' + j.cli_command) : ''),
                    true
                );
                if (el('rc_finalize_status')) {
                    el('rc_finalize_status').innerHTML =
                        '<div><dt>الحالة</dt><dd>' + badge('Finalizing') + '</dd></div>'
                        + '<div><dt>الصيانة</dt><dd>pending release</dd></div>'
                        + '<div><dt>CLI</dt><dd><code>' + (j.cli_command || 'No activity') + '</code></dd></div>';
                }
                await loadAll();
            } catch (e) {
                showAlert(e.message || 'تعذر طلب الإنهاء', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-finalize-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/finalize.php?id=' + encodeURIComponent(t.dataset.id || ''));
                if (el('rc_finalize_status')) {
                    el('rc_finalize_status').innerHTML =
                        '<div><dt>الحالة</dt><dd>' + badge(j.status_label || ((j.job || {}).status) || 'Waiting') + '</dd></div>'
                        + '<div><dt>الصيانة</dt><dd>' + (j.maintenance_released ? 'Maintenance Released' : 'active') + '</dd></div>'
                        + '<div><dt>CLI</dt><dd>' + (((j.meta || {}).cli_command) || '<span class="rc-stage-idle">No activity</span>') + '</dd></div>';
                }
                openView('Finalize — ' + (t.dataset.id || ''), JSON.stringify({
                    status_label: j.status_label || '',
                    job: j.job || {},
                    meta: j.meta || {},
                    report: j.report || {},
                    artifacts: j.artifacts || {},
                    restore_completed: !!j.restore_completed,
                    rollback_completed: !!j.rollback_completed,
                    maintenance_released: !!j.maintenance_released,
                    execution_finished: !!j.execution_finished,
                    rollback_anchor_deleted: false,
                    retention_pin_removed: false,
                    warning: j.warning || ''
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

    async function loadCountryShadowVerify() {
        const jobId = (el('rc_c7_job_id') && el('rc_c7_job_id').value || '').trim();
        const strip = el('rc_country_shadow_verify');
        const blockersEl = el('rc_country_shadow_blockers');
        if (!strip) return;
        if (!jobId) {
            showAlert('أدخل job / run_id لعرض تقرير Country Shadow Verification.', false);
            return;
        }
        try {
            setBusy(true, 'جاري تحميل تحقق ظل الدولة…');
            const j = await apiGet('country-shadow-verify-status.php?job_id=' + encodeURIComponent(jobId));
            const s = j.summary || {};
            const result = String(s.overall_result || (j.report && j.report.overall_result) || '—');
            strip.innerHTML =
                '<div><dt>النتيجة</dt><dd>' + badge(result) + '</dd></div>' +
                '<div><dt>Readiness</dt><dd>' + String(s.readiness_score != null ? s.readiness_score : '—') + '</dd></div>' +
                '<div><dt>Target country</dt><dd>' + badge(s.target_country_integrity || '—') + '</dd></div>' +
                '<div><dt>Survivor countries</dt><dd>' + badge(s.survivor_country_integrity || '—') + '</dd></div>' +
                '<div><dt>Global state</dt><dd>' + badge(s.global_state_integrity || '—') + '</dd></div>' +
                '<div><dt>Accounting</dt><dd>' + badge(s.accounting_integrity || '—') + '</dd></div>' +
                '<div><dt>Stock / FIFO</dt><dd>' + badge(s.stock_fifo_integrity || '—') + '</dd></div>';
            const blockers = Array.isArray(s.blocking_reason_codes) ? s.blocking_reason_codes : [];
            const warnings = Array.isArray(s.warnings) ? s.warnings : [];
            if (blockersEl) {
                blockersEl.textContent = 'Blockers: ' + (blockers.length ? blockers.join(', ') : 'none')
                    + ' | Warnings: ' + (warnings.length ? warnings.join(', ') : 'none')
                    + ' | execution_performed=false | production_db_writes=0';
            }
            openView('Country Shadow Verification — ' + jobId, JSON.stringify({
                status: j.status || '',
                summary: s,
                report: j.report || null,
                execution_performed: false,
                production_db_writes: 0,
                country_production_restore_enabled: false,
                warning: j.warning || 'Country Shadow Verification status only.'
            }, null, 2));
        } catch (e) {
            showAlert(e.message || 'تعذر تحميل تقرير تحقق ظل الدولة', false);
        } finally {
            setBusy(false);
        }
    }

    if (el('rc_c7_load_btn')) {
        el('rc_c7_load_btn').addEventListener('click', function () {
            loadCountryShadowVerify();
        });
    }

    async function loadCountryDryRun() {
        const jobId = (el('rc_c8_job_id') && el('rc_c8_job_id').value || '').trim();
        const strip = el('rc_country_dry_run');
        const blockersEl = el('rc_country_dry_run_blockers');
        if (!strip) return;
        if (!jobId) {
            showAlert('أدخل job / run_id لعرض تقرير Country Dry Run.', false);
            return;
        }
        try {
            setBusy(true, 'جاري تحميل Country Dry Run…');
            const j = await apiGet('country-dry-run-status.php?job_id=' + encodeURIComponent(jobId));
            const s = j.summary || {};
            const result = String(s.overall_result || (j.report && j.report.overall_result) || '—');
            strip.innerHTML =
                '<div><dt>النتيجة</dt><dd>' + badge(result) + '</dd></div>' +
                '<div><dt>Tables</dt><dd>' + String(s.tables_affected_count != null ? s.tables_affected_count : '—') + '</dd></div>' +
                '<div><dt>Rows insert/delete</dt><dd>' + String(s.rows_to_insert != null ? s.rows_to_insert : '—') + ' / ' + String(s.rows_to_delete != null ? s.rows_to_delete : '—') + '</dd></div>' +
                '<div><dt>Survivor impact</dt><dd>' + badge(String(s.survivor_country_impact != null ? s.survivor_country_impact : '—')) + '</dd></div>' +
                '<div><dt>Global impact</dt><dd>' + badge(String(s.global_impact != null ? s.global_impact : '—')) + '</dd></div>' +
                '<div><dt>Duration</dt><dd>' + String(s.estimated_duration || '—') + '</dd></div>';
            const blockers = Array.isArray(s.blocking_reason_codes) ? s.blocking_reason_codes : [];
            const warnings = Array.isArray(s.warnings) ? s.warnings : [];
            if (blockersEl) {
                blockersEl.textContent = 'Blockers: ' + (blockers.length ? blockers.join(', ') : 'none')
                    + ' | Warnings: ' + (warnings.length ? warnings.join(', ') : 'none')
                    + ' | simulation_only | execution_performed=false | writes=0';
            }
            openView('Country Dry Run — ' + jobId, JSON.stringify({
                status: j.status || '',
                summary: s,
                report: j.report || null,
                execution_performed: false,
                production_db_writes: 0,
                shadow_db_writes: 0,
                country_production_restore_enabled: false,
                warning: j.warning || 'Country Dry Run status only — simulation.'
            }, null, 2));
        } catch (e) {
            showAlert(e.message || 'تعذر تحميل تقرير Country Dry Run', false);
        } finally {
            setBusy(false);
        }
    }

    if (el('rc_c8_load_btn')) {
        el('rc_c8_load_btn').addEventListener('click', function () {
            loadCountryDryRun();
        });
    }

    // Accordion groups: one open within pkg / job / stage / val — not across groups
    document.addEventListener('toggle', (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLDetailsElement) || !t.classList.contains('rc-acc-item')) return;
        if (!t.open) {
            if (t.classList.contains('rc-stage-acc') && state.openedStage === t.getAttribute('data-stage')) {
                state.openedStage = '';
            }
            return;
        }
        const group = t.getAttribute('data-rc-acc') || 'pkg';
        document.querySelectorAll('details.rc-acc-item[data-rc-acc="' + group + '"][open]').forEach((other) => {
            if (other !== t) other.open = false;
        });
        if (t.classList.contains('rc-stage-acc')) {
            state.openedStage = t.getAttribute('data-stage') || '';
            document.querySelectorAll('.rc-stage-chip').forEach((chip) => {
                chip.classList.toggle('is-selected', chip.getAttribute('data-stage') === state.openedStage);
            });
        }
    });

    // Keep primary/action clicks from toggling accordion unexpectedly
    document.addEventListener('click', (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLElement)) return;
        const stageChip = t.closest('.rc-stage-chip');
        if (stageChip) {
            ev.preventDefault();
            const stage = stageChip.getAttribute('data-stage') || '';
            if (state.openedStage === stage) {
                openStagePanel('');
            } else {
                openStagePanel(stage);
            }
            return;
        }
        const expandBtn = t.closest('.rc-pkg-expand');
        if (expandBtn) {
            ev.preventDefault();
            ev.stopPropagation();
            const details = expandBtn.closest('details.rc-acc-item[data-rc-acc="pkg"]');
            if (details) {
                document.querySelectorAll('details.rc-acc-item[data-rc-acc="pkg"][open]').forEach((other) => {
                    if (other !== details) other.open = false;
                });
                details.open = true;
                try {
                    details.querySelector('summary')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                } catch (e) { /* ignore */ }
            }
            return;
        }
        if (t.closest('.rc-acc-actions-inline') || t.closest('.rc-action-row')) {
            // do not stopPropagation for handlers — only prevent summary toggle via details default when clicking buttons
            if (t.closest('summary') && (t.closest('button') || t.closest('a'))) {
                ev.preventDefault();
            }
        }
        if (t.matches('.rc-tab') || t.classList.contains('rc-tab')) {
            const tab = t.getAttribute('data-rc-tab');
            if (!tab) return;
            document.querySelectorAll('.rc-tab').forEach((b) => b.classList.toggle('is-active', b.getAttribute('data-rc-tab') === tab));
            const fullPanel = el('rc_tab_full');
            const countryPanel = el('rc_tab_country');
            if (fullPanel) {
                const on = tab === 'full';
                fullPanel.classList.toggle('is-active', on);
                fullPanel.hidden = !on;
            }
            if (countryPanel) {
                const on = tab === 'country';
                countryPanel.classList.toggle('is-active', on);
                countryPanel.hidden = !on;
            }
        }
    }, true);

    loadAll();
})();
</script>
