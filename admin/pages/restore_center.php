<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/backup/restore/restore_job_framework.php';

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
/** @var list<string> $fwTerminalStatuses Authoritative framework terminal statuses for UI journey vs history. */
$fwTerminalStatuses = orange_restore_fw_transition_terminal_statuses();

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
/* RESTORE_CENTER_ALL_STAGE_ACTION_EXECUTION_LOCK_01 — grey busy; keep dimensions; readable contrast */
button.rc-stage-action-busy,
.rc-btn-primary.rc-stage-action-busy,
.rc-btn-ghost.rc-stage-action-busy,
.rc-btn-secondary.rc-stage-action-busy,
.btn-link.rc-stage-action-busy{
    background:#9ca3af!important;
    color:#1f2937!important;
    -webkit-text-fill-color:#1f2937;
    border:1px solid #6b7280;
    cursor:not-allowed;
    opacity:1;
    pointer-events:none;
    box-shadow:none
}
button.rc-stage-action-busy:hover,
.rc-btn-primary.rc-stage-action-busy:hover,
.btn-link.rc-stage-action-busy:hover{
    background:#9ca3af!important;
    color:#1f2937!important;
    -webkit-text-fill-color:#1f2937
}
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
/* Expandable panels — identical model to Backup Center: capped panel + sticky summary */
.rc-acc-item[open]{max-height:min(420px,58vh);overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
.rc-acc-item[open]>summary{position:sticky;top:0;z-index:3;background:var(--rc-surface);box-shadow:0 1px 0 #f1f5f9}
.rc-acc-item[data-rc-acc="pkg"] .rc-acc-chevron{cursor:pointer;padding:4px;margin:-4px;border-radius:6px}
.rc-acc-item[data-rc-acc="pkg"] .rc-acc-chevron:hover{color:var(--rc-ink);background:#f1f5f9}
/* Legacy reference block: same sticky collapse control */
.rc-legacy-ref[open]{max-height:min(420px,58vh);overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
.rc-legacy-ref[open]>summary{position:sticky;top:0;z-index:3;background:var(--rc-soft,#f8fafc);box-shadow:0 1px 0 #e2e8f0}
.rc-pkg-id{font-family:ui-monospace,Consolas,monospace;font-size:.72rem;font-weight:500;color:#94a3b8;direction:ltr;unicode-bidi:isolate}
.rc-action-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px}
.rc-action-row .rc-btn-ghost,.rc-action-row .btn-link{flex:0 0 auto}
.rc-action-row--secondary{margin-top:6px}
.rc-action-row--secondary .btn-link,.rc-action-row--secondary .rc-btn-ghost{opacity:.92;font-weight:600;font-size:.8rem}
.rc-ops-label{margin:12px 0 6px;font-size:.78rem;font-weight:700;color:var(--rc-muted);letter-spacing:.01em}
.rc-ops-hint{margin:0 0 8px;font-size:.76rem;color:#94a3b8;line-height:1.4}
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
/* Restore modals — Backup Center drawer sizing behavior as centered dialog (Owner 2026-07-24) */
.rc-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:5000;padding:16px;overflow:hidden}
.rc-modal{background:#fff;border-radius:12px;max-width:520px;width:100%;max-height:min(88vh,calc(100dvh - 32px),calc(100vh - 32px));display:flex;flex-direction:column;overflow:hidden;padding:0;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.rc-modal--wide{max-width:860px}
.rc-modal-head{flex:0 0 auto;padding:16px 18px;border-bottom:1px solid var(--rc-border);background:#fff}
.rc-modal-head h3{margin:0;font-size:1.05rem}
.rc-modal-head .rc-tz-label,.rc-modal-head .rc-muted{margin:6px 0 0}
.rc-modal-body{flex:1 1 auto;min-height:0;overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;padding:16px 18px}
.rc-modal-foot{flex:0 0 auto;padding:12px 18px;border-top:1px solid var(--rc-border);background:#fff;display:flex;justify-content:flex-start;gap:8px}
.rc-pre{max-height:360px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:.78rem;white-space:pre-wrap;word-break:break-word}
.rc-modal-body .rc-pre{max-height:none}
body.rc-modal-open{overflow:hidden!important}
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
.rc-modal-foot .btn-secondary,#rc_view_close,#rc_detail_close,#rc_orch_diag_close{background:#475569;color:#fff!important;border:0}
/* True step-by-step restore wizard (Owner 2026-07-24) — presentation only */
.rc-wizard{display:grid;grid-template-columns:minmax(240px,300px) minmax(0,1fr);gap:16px;margin-bottom:8px}
/* Mobile: current Step working content BEFORE complete 16-step journey rail (Owner RESTORE_CENTER_MOBILE_ACTIVE_STEP_BELOW_JOURNEY_RAIL_01). Desktop grid columns unchanged. */
@media (max-width:960px){
    .rc-wizard{grid-template-columns:1fr}
    .rc-wizard-main{order:1}
    .rc-wizard-rail{order:2}
}
.rc-wizard-rail{background:var(--rc-surface);border:1px solid var(--rc-border);border-radius:14px;padding:14px;max-height:min(78vh,720px);overflow:auto}
.rc-wizard-rail h3{margin:0 0 4px;font-size:1rem}
.rc-wizard-rail-hint{margin:0 0 12px;font-size:.78rem;color:var(--rc-muted);line-height:1.4}
.rc-guide-steps{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:3px;position:relative}
.rc-guide-steps::before{content:'';position:absolute;top:12px;bottom:12px;inset-inline-start:17px;width:2px;background:#e2e8f0;z-index:0}
.rc-guide-step{display:flex;gap:10px;align-items:flex-start;padding:8px 10px;border-radius:10px;border:1px solid transparent;font-size:.82rem;line-height:1.35;position:relative;z-index:1;background:var(--rc-surface)}
.rc-guide-step.is-done{color:var(--rc-ok);background:#ecfdf5;border-color:#a7f3d0}
.rc-guide-step.is-current{color:var(--rc-ink);background:#fff7ed;border-color:#fdba74;font-weight:700;box-shadow:0 0 0 1px rgba(234,88,12,.12)}
.rc-guide-step.is-locked{color:#94a3b8;background:#f8fafc}
.rc-guide-step.is-blocked{color:var(--rc-bad);background:#fef2f2;border-color:#fecaca}
.rc-guide-mark{flex:0 0 1.5rem;width:1.5rem;height:1.5rem;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:.78rem;background:#fff;border:1px solid currentColor}
.rc-wizard-main{min-width:0;display:flex;flex-direction:column;gap:12px}
.rc-wizard-hero{background:linear-gradient(180deg,#fff 0%,#fffaf5 100%);border:2px solid #fdba74;border-radius:16px;padding:18px 20px;box-shadow:0 10px 28px rgba(15,23,42,.06)}
.rc-wizard-stepnum{display:inline-flex;align-items:center;gap:8px;margin:0 0 10px;padding:4px 10px;border-radius:999px;background:#fff7ed;border:1px solid #fed7aa;font-size:.78rem;font-weight:700;color:#c2410c}
.rc-guide-now-kicker{margin:0 0 4px;font-size:.8rem;color:var(--rc-muted);font-weight:700;letter-spacing:.02em}
.rc-guide-now-title{margin:0 0 10px;font-size:1.35rem;font-weight:800;color:var(--rc-ink);line-height:1.3}
.rc-guide-now-body{margin:0 0 14px;font-size:.95rem;color:#334155;line-height:1.55;max-width:none;white-space:pre-wrap}
.rc-guide-now-block{margin:0 0 14px;padding:12px 14px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:.9rem;line-height:1.55}
/* Workflow action row: Cancel LEFT · Primary RIGHT (Owner 2026-07-24) — LTR row inside RTL page */
.rc-guide-actions{display:flex;flex-direction:row;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;width:100%;direction:ltr}
.rc-guide-cancel{display:flex;flex-wrap:wrap;align-items:center;gap:8px;order:1}
.rc-guide-cancel:empty{display:none}
.rc-guide-primary{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:flex-end;order:2;margin-inline-start:auto}
#rc_guide_primary .rc-create-job{transform:translateY(6px)}
.rc-guide-primary .rc-btn-primary,.rc-guide-primary .btn-link.rc-btn-primary{font-size:1rem;min-height:44px;padding:10px 22px}
.rc-guide-primary .rc-btn-primary:only-child,.rc-guide-primary .btn-link.rc-btn-primary:only-child{min-width:min(100%,280px)}
.rc-guide-cancel .rc-fw-cancel,.rc-guide-cancel .btn-link.rc-fw-cancel{
    display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 18px;
    border:1px solid #cbd5e1;border-radius:var(--radius-sm,10px);background:#fff;color:#334155!important;
    -webkit-text-fill-color:#334155;font-weight:600;font:inherit;font-size:.92rem;cursor:pointer;text-decoration:none;direction:rtl
}
.rc-guide-cancel .rc-fw-cancel:hover{background:#f8fafc;border-color:#94a3b8}
.rc-guide-primary .btn-link,.rc-guide-primary button{direction:rtl}
.rc-wizard-more{margin-top:12px;border-top:1px dashed #e2e8f0;padding-top:8px}
.rc-wizard-more>summary{cursor:pointer;font-size:.8rem;color:var(--rc-muted);font-weight:650;list-style:none}
.rc-wizard-more>summary::-webkit-details-marker{display:none}
.rc-guide-secondary{margin-top:8px;display:flex;flex-wrap:wrap;gap:8px}
.rc-guide-workspace{background:var(--rc-surface);border:1px solid var(--rc-border);border-radius:14px;padding:14px}
.rc-guide-workspace[hidden]{display:none!important}
.rc-guide-workspace-title{margin:0 0 10px;font-size:.92rem;font-weight:700}
.rc-pkg-pick{border:1px solid var(--rc-border);border-radius:12px;padding:12px 14px;background:#fff;cursor:pointer;transition:border-color .15s,box-shadow .15s}
.rc-pkg-pick+ .rc-pkg-pick{margin-top:8px}
.rc-pkg-pick:hover{border-color:#fdba74}
.rc-pkg-pick:focus{outline:2px solid rgba(234,88,12,.45);outline-offset:2px}
.rc-pkg-pick.is-selected{border-color:var(--primary,#ea580c);box-shadow:0 0 0 2px rgba(234,88,12,.15);background:#fff7ed}
.rc-pkg-pick.is-ineligible,.rc-pkg-pick.is-unresolved{opacity:1}
.rc-pkg-pick-top{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px}
.rc-pkg-pick-actions{margin-top:10px;display:flex;flex-wrap:wrap;gap:8px}
.rc-pkg-id{direction:ltr;unicode-bidi:isolate;font-family:ui-monospace,Consolas,monospace;font-size:.8rem;word-break:break-word}
.rc-selected-summary{margin:12px 0 0;padding:12px 14px;border:1px solid #fdba74;border-radius:12px;background:#fff7ed}
.rc-selected-summary h4{margin:0 0 10px;font-size:.92rem;font-weight:700;color:var(--rc-ink)}
.rc-selected-summary-empty{margin:10px 0 0;padding:10px 12px;border:1px dashed var(--rc-border);border-radius:10px;background:var(--rc-soft);color:var(--rc-muted);font-size:.88rem;line-height:1.5}
.rc-selected-summary dl{margin:0;display:grid;gap:0}
.rc-selected-summary .rc-sel-row{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid #ffedd5;font-size:.88rem}
.rc-selected-summary .rc-sel-row:last-child{border-bottom:0}
.rc-selected-summary dt{margin:0;color:var(--rc-muted);font-weight:600}
.rc-selected-summary dd{margin:0;font-weight:650;color:var(--rc-ink);text-align:left;max-width:100%;overflow-wrap:anywhere}
.rc-selected-summary dd.rc-ltr{direction:ltr;unicode-bidi:isolate;font-family:ui-monospace,Consolas,monospace;font-size:.82rem}
.rc-selected-summary-note{margin:10px 0 0;font-size:.84rem;line-height:1.5;color:#9a3412}
.rc-result-dialog-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:5400;padding:16px;box-sizing:border-box}
.rc-result-dialog-backdrop.is-open{display:flex}
.rc-result-dialog{background:#fff;border-radius:12px;max-width:min(760px,100%);width:100%;max-height:min(90vh,calc(100vh - 32px));display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.2);overflow:hidden;min-height:0;box-sizing:border-box}
.rc-result-dialog-head{padding:18px 18px 0;flex:0 0 auto}
.rc-result-dialog-head h3{margin:0 0 10px;font-size:1.05rem;overflow-wrap:anywhere}
.rc-result-dialog-body{padding:0 18px;overflow:auto;flex:1 1 auto;min-height:0;-webkit-overflow-scrolling:touch}
.rc-result-dialog-foot{padding:12px 18px 18px;flex:0 0 auto;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-start;gap:8px}
.rc-result-dialog-summary{white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;font-size:.92rem;line-height:1.55;margin:0 0 4px}
.rc-result-dialog--ok .rc-result-dialog-summary{color:#166534}
.rc-result-dialog--fail .rc-result-dialog-summary{color:#991b1b}
.rc-result-dialog--fail .rc-result-dialog-head h3{color:#991b1b}
/* Step 1 package list modes — mirror Backup Center history footer (Owner 2026-07-24) */
.rc-mode-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:650;border:1px solid var(--rc-border);background:var(--rc-soft,#fff7ed);color:var(--rc-muted)}
.rc-mode-pill.is-active{border-color:var(--primary,#ea580c);color:var(--primary,#ea580c);background:rgba(234,88,12,.1)}
.rc-pkg-list-footer{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;padding-top:10px;border-top:1px solid #f1f5f9}
.rc-pkg-list-footer p{margin:0;font-size:.8rem;color:var(--rc-muted)}
.rc-job-context{padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:12px}
.rc-job-context h4{margin:0 0 8px;font-size:.9rem}
.rc-history{margin-top:16px;background:var(--rc-surface);border:1px solid var(--rc-border);border-radius:14px;padding:14px}
.rc-history h3{margin:0 0 6px;font-size:1rem}
.rc-history-hint{margin:0 0 12px;font-size:.82rem;color:var(--rc-muted);line-height:1.45}
.rc-legacy-ref{margin-top:14px}
.rc-legacy-ref>summary{cursor:pointer;font-weight:650;color:var(--rc-muted);padding:8px 0}
.rc-dash-hide{display:none!important}
</style>

<div class="rc-v2" id="rc_app">
    <div id="rc_progress" class="rc-progress" role="status" aria-live="polite">جاري التحميل…</div>

    <header class="rc-header">
        <div class="rc-header-main">
            <p class="rc-header-kicker">معالج الاسترداد</p>
            <p class="rc-tz-label" id="rc_tz_label"><?php
            if ($displayTimezone !== '') {
                echo 'التواريخ بالتوقيت المحلي (12 ساعة): <code dir="ltr">'
                    . htmlspecialchars($displayTimezone, ENT_QUOTES, 'UTF-8')
                    . '</code>';
            } else {
                echo 'تحذير: لم تُضبط المنطقة الزمنية للدولة الحالية.';
            }
            ?></p>
        </div>
        <div class="rc-header-status">
            <span id="rc_readiness_badge" class="rc-badge rc-badge--muted" aria-busy="true"><span class="rc-skeleton" style="width:5.5rem;display:inline-block;vertical-align:middle"></span></span>
            <button type="button" class="rc-btn-secondary" id="rc_refresh_btn" style="margin-top:6px;">تحديث الحالة</button>
        </div>
    </header>

    <div class="rc-wizard" id="rc_guided_root">
        <aside class="rc-wizard-rail" id="rc_journey_rail" aria-label="رحلة الاسترداد">
            <h3>رحلة الاسترداد</h3>
            <p class="rc-wizard-rail-hint">✔ مكتمل · ▶ مطلوب الآن · 🔒 مقفل · ! محظور</p>
            <ol class="rc-guide-steps" id="rc_guide_steps"></ol>
        </aside>
        <div class="rc-wizard-main" id="rc_main_content_column">
            <section class="rc-wizard-hero" id="rc_guide_now" aria-live="polite">
                <div class="rc-wizard-stepnum" id="rc_wizard_stepnum">الخطوة —</div>
                <p class="rc-guide-now-kicker" id="rc_guide_kicker">▶ مطلوب الآن</p>
                <h2 class="rc-guide-now-title" id="rc_guide_title">جاري تحديد الخطوة التالية…</h2>
                <p class="rc-guide-now-body" id="rc_guide_body"></p>
                <div id="rc_selected_summary" class="rc-selected-summary" hidden aria-live="polite"></div>
                <div class="rc-guide-now-block" id="rc_guide_block" hidden></div>
                <div class="rc-guide-now-block" id="rc_journey_inline" hidden aria-live="polite"></div>
                <div class="rc-guide-actions" id="rc_guide_actions" dir="ltr">
                    <div class="rc-guide-cancel" id="rc_guide_cancel"></div>
                    <div class="rc-guide-primary" id="rc_guide_primary"></div>
                </div>
                <details class="rc-wizard-more">
                    <summary>تفاصيل إضافية (اختيارية)</summary>
                    <div class="rc-guide-secondary" id="rc_guide_secondary"></div>
                </details>
            </section>

            <div class="rc-guide-workspace" id="rc_ws_packages">
                <h3 class="rc-guide-workspace-title">اختر حزمة لهذه الخطوة</h3>
                <div class="rc-panel-head" style="margin-bottom:10px">
                    <div class="rc-seg" role="tablist" aria-label="نوع الحزمة">
                        <?php if ($canFull): ?>
                        <button type="button" class="rc-tab is-active" id="rc_tab_full_btn" data-rc-tab="full">النسخة الكاملة</button>
                        <?php endif; ?>
                        <?php if ($canCountry): ?>
                        <button type="button" class="rc-tab<?php echo $canFull ? '' : ' is-active'; ?>" id="rc_tab_country_btn" data-rc-tab="country">نسخة الدولة</button>
                        <?php endif; ?>
                    </div>
                    <span id="rc_pkg_mode_pill" class="rc-mode-pill is-active">آخر العمليات (5)</span>
                </div>
                <?php if ($canFull): ?>
                <div id="rc_tab_full" class="rc-tab-panel is-active" role="tabpanel">
                    <div id="rc_full_list" class="rc-acc-list" aria-busy="true" data-rc-pkg-mode="latest5">
                        <div class="rc-skeleton-card"><span class="rc-skeleton" style="width:35%"></span><div class="rc-skeleton" style="width:70%;margin-top:12px"></div></div>
                    </div>
                    <div class="rc-pkg-list-footer" id="rc_full_list_footer">
                        <p id="rc_full_list_hint">آخر 5 عمليات</p>
                        <button type="button" class="rc-btn-secondary" id="rc_view_all_full_btn" data-rc-pkg-kind="full">عرض السجل الكامل</button>
                        <button type="button" class="rc-btn-secondary" id="rc_back_latest_full_btn" data-rc-pkg-kind="full" hidden>العودة لآخر العمليات</button>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($canCountry): ?>
                <div id="rc_tab_country" class="rc-tab-panel<?php echo $canFull ? '' : ' is-active'; ?>" role="tabpanel"<?php echo $canFull ? ' hidden' : ''; ?>>
                    <p class="rc-tz-label" style="margin:0 0 10px;">سياق الدولة: <code dir="ltr"><?php echo htmlspecialchars($countryContextCode !== '' ? $countryContextCode : '—', ENT_QUOTES, 'UTF-8'); ?></code></p>
                    <div id="rc_country_list" class="rc-acc-list" aria-busy="true" data-rc-pkg-mode="latest5">
                        <div class="rc-skeleton-card"><span class="rc-skeleton" style="width:35%"></span><div class="rc-skeleton" style="width:70%;margin-top:12px"></div></div>
                    </div>
                    <div class="rc-pkg-list-footer" id="rc_country_list_footer">
                        <p id="rc_country_list_hint">آخر 5 عمليات</p>
                        <button type="button" class="rc-btn-secondary" id="rc_view_all_country_btn" data-rc-pkg-kind="country">عرض السجل الكامل</button>
                        <button type="button" class="rc-btn-secondary" id="rc_back_latest_country_btn" data-rc-pkg-kind="country" hidden>العودة لآخر العمليات</button>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!$canFull && !$canCountry): ?>
                <p class="rc-muted" style="margin:0">لا صلاحية لعرض الحزم.</p>
                <?php endif; ?>
            </div>

            <div class="rc-guide-workspace" id="rc_ws_job" hidden>
                <div id="rc_active_job" class="rc-job-context" hidden>
                    <h4>سياق المهمة الحالية</h4>
                    <div class="rc-active-meta" id="rc_active_job_meta"></div>
                    <div class="rc-actions" id="rc_active_job_actions" hidden aria-hidden="true"></div>
                </div>
            </div>
        </div>
    </div>

    <section class="rc-history" id="rc_history" aria-label="سجل الاسترداد" hidden>
        <h3>سجل الاسترداد</h3>
        <p class="rc-history-hint">مهام منتهية أو ملغاة للمرجعية فقط. عرض التفاصيل أو التشخيص لا يغيّر رحلة الاسترداد الحالية ولا الخطوة النشطة.</p>
        <div id="rc_jobs_list" class="rc-acc-list" aria-busy="true"></div>
        <table id="rc_jobs_table" hidden aria-hidden="true"><tbody></tbody></table>
    </section>

    <div id="rc_readiness_summary" class="rc-dash-hide" aria-hidden="true">
        <p class="rc-readiness-value" id="rc_readiness_headline"></p>
    </div>
    <div id="rc_overview" class="rc-dash-hide" aria-hidden="true"></div>
    <dl id="rc_lock_maintenance" class="rc-dash-hide" aria-hidden="true"></dl>

    <!-- Dashboard/reference mounts kept for API renderers — not operator navigation -->
    <div class="rc-dash-hide" aria-hidden="true">
    <details class="rc-legacy-ref" open>
        <summary>مراجع داخلية</summary>
    <section class="rc-phase" aria-labelledby="rc_phase3_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">i</span>
            <h2 class="rc-phase-title" id="rc_phase3_title">مراجع التحقق</h2>
            <p class="rc-phase-hint">شهادة الجاهزية وتقارير الدولة — للعرض عند الحاجة فقط.</p>
        </div>
        <div class="rc-val-stack">
            <details class="rc-acc-item rc-val-acc" data-rc-acc="val" id="rc_certification_section">
                <summary>
                    <span class="rc-acc-chevron" aria-hidden="true"></span>
                    <span class="rc-acc-title">شهادة جاهزية الاسترداد</span>
                    <span class="rc-acc-meta"><span class="rc-muted">معلومات</span></span>
                </summary>
                <div class="rc-acc-body">
                    <p id="rc_cert_banner" class="rc-readonly-banner" role="status">
                        معلومات الشهادة للعرض من مركز الاسترداد. نتيجة التحقق التشغيلي تظهر هنا بعد اكتمالها.
                    </p>
                    <dl id="rc_cert_status" class="rc-status-strip" aria-busy="true">
                        <div><dt>الاسترداد الكامل</dt><dd><span class="rc-skeleton" style="width:4rem;display:inline-block"></span></dd></div>
                        <div><dt>تحقق التراجع</dt><dd><span class="rc-skeleton" style="width:3rem;display:inline-block"></span></dd></div>
                        <div><dt>العزل</dt><dd><span class="rc-skeleton" style="width:3rem;display:inline-block"></span></dd></div>
                        <div><dt>مرجع الاختبار</dt><dd><span class="rc-skeleton" style="width:5rem;display:inline-block"></span></dd></div>
                        <div><dt>تاريخ الاختبار</dt><dd><span class="rc-skeleton" style="width:6rem;display:inline-block"></span></dd></div>
                        <div><dt>استرداد الدولة</dt><dd>غير معتمد للإنتاج</dd></div>
                    </dl>
                    <div id="rc_cert_blockers" class="muted" style="margin-top:8px;"></div>
                </div>
            </details>
            <?php if ($canCountry): ?>
            <details class="rc-acc-item rc-val-acc" data-rc-acc="val" id="rc_country_shadow_section">
                <summary>
                    <span class="rc-acc-chevron" aria-hidden="true"></span>
                    <span class="rc-acc-title">تحقق ظل الدولة</span>
                    <span class="rc-acc-meta"><span class="rc-muted">معلومات</span></span>
                </summary>
                <div class="rc-acc-body">
                    <p class="rc-readonly-banner" role="status">
                        <strong>تحقق ظل الدولة فقط.</strong>
                        لا استيراد إنتاج، ولا تنفيذ استرداد إنتاجي، ولا موافقة، ولا صيانة، ولا تراجع، ولا تفعيل إنتاج من هذا القسم.
                        اعرض التقرير من مركز الاسترداد بعد إدخال معرّف المهمة.
                    </p>
                    <div class="rc-actions">
                        <label for="rc_c7_job_id" class="rc-muted">معرّف المهمة</label>
                        <input type="text" id="rc_c7_job_id" placeholder="معرّف المهمة" style="min-width:220px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;">
                        <button type="button" class="btn-link rc-btn-ghost" id="rc_c7_load_btn">عرض تقرير التحقق</button>
                    </div>
                    <dl id="rc_country_shadow_verify" class="rc-status-strip">
                        <div><dt>النتيجة</dt><dd>—</dd></div>
                        <div><dt>الجاهزية</dt><dd>—</dd></div>
                        <div><dt>الدولة المستهدفة</dt><dd>—</dd></div>
                        <div><dt>الدول الباقية</dt><dd>—</dd></div>
                        <div><dt>الحالة العامة</dt><dd>—</dd></div>
                        <div><dt>المحاسبة</dt><dd>—</dd></div>
                        <div><dt>المخزون</dt><dd>—</dd></div>
                    </dl>
                    <div id="rc_country_shadow_blockers" class="muted" style="margin-top:8px;"></div>
                </div>
            </details>
            <details class="rc-acc-item rc-val-acc" data-rc-acc="val" id="rc_country_dry_run_section">
                <summary>
                    <span class="rc-acc-chevron" aria-hidden="true"></span>
                    <span class="rc-acc-title">محاكاة استعادة الدولة</span>
                    <span class="rc-acc-meta"><span class="rc-muted">معلومات</span></span>
                </summary>
                <div class="rc-acc-body">
                    <p class="rc-readonly-banner" role="status">
                        <strong>محاكاة استعادة الدولة فقط.</strong>
                        لا كتابة إنتاج، لا كتابة ظل، لا استيراد، لا تنفيذ استرداد إنتاجي، لا موافقة، لا صيانة، لا تراجع.
                        اعرض التقرير من مركز الاسترداد بعد إدخال معرّف المهمة.
                    </p>
                    <div class="rc-actions">
                        <label for="rc_c8_job_id" class="rc-muted">معرّف المهمة</label>
                        <input type="text" id="rc_c8_job_id" placeholder="معرّف المهمة" style="min-width:220px;padding:6px 10px;border:1px solid #cbd5e1;border-radius:8px;">
                        <button type="button" class="btn-link rc-btn-ghost" id="rc_c8_load_btn">عرض تقرير المحاكاة</button>
                    </div>
                    <dl id="rc_country_dry_run" class="rc-status-strip">
                        <div><dt>النتيجة</dt><dd>—</dd></div>
                        <div><dt>الجداول</dt><dd>—</dd></div>
                        <div><dt>صفوف الإضافة/الحذف</dt><dd>—</dd></div>
                        <div><dt>أثر الدول الباقية</dt><dd>—</dd></div>
                        <div><dt>الأثر العام</dt><dd>—</dd></div>
                        <div><dt>المدة</dt><dd>—</dd></div>
                    </dl>
                    <div id="rc_country_dry_run_blockers" class="muted" style="margin-top:8px;"></div>
                </div>
            </details>
            <?php endif; ?>
        </div>
    </section>

    <section class="rc-phase" aria-labelledby="rc_phase5_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">i</span>
            <h2 class="rc-phase-title" id="rc_phase5_title">حالة مراحل الإنتاج (مرجع)</h2>
            <p class="rc-phase-hint">معلومات حالة فقط — الإجراء التالي يظهر أعلى المسار الموجَّه.</p>
        </div>
        <div class="rc-panel">
            <div class="rc-stage-strip" id="rc_stage_strip" aria-label="مراحل التنفيذ">
                <button type="button" class="rc-stage-chip" data-stage="maint"><strong>1 · الصيانة</strong><span id="rc_stage_maint_label" class="rc-stage-idle">بانتظار</span></button>
                <button type="button" class="rc-stage-chip" data-stage="import"><strong>2 · استيراد القاعدة</strong><span id="rc_stage_import_label" class="rc-stage-idle">بانتظار</span></button>
                <button type="button" class="rc-stage-chip" data-stage="uploads"><strong>3 · تحويل الرفع</strong><span id="rc_stage_uploads_label" class="rc-stage-idle">بانتظار</span></button>
                <button type="button" class="rc-stage-chip" data-stage="rollback"><strong>4 · التراجع</strong><span id="rc_stage_rollback_label" class="rc-stage-idle">بانتظار</span></button>
                <button type="button" class="rc-stage-chip" data-stage="finalize"><strong>5 · الإنهاء</strong><span id="rc_stage_finalize_label" class="rc-stage-idle">بانتظار</span></button>
            </div>
            <div class="rc-stage-panels">
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="maint" id="rc_maint_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">صيانة الإنتاج</span>
                        <span class="rc-acc-meta"><span class="rc-muted">معلومات</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_maint_banner" class="rc-readonly-banner" role="status">
                            <strong>استرداد الإنتاج لم يبدأ بعد.</strong>
                        </p>
                        <dl id="rc_maint_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>الملصق</dt><dd class="rc-stage-idle">بانتظار</dd></div>
                            <div><dt>المهمة المرتبطة</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>وقت الطلب</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>وقت التفعيل</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>آخر نبضة</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>انتهاء الصلاحية</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                        </dl>
                        <div id="rc_maint_policy" class="muted" style="margin-top:8px;"></div>
                    </div>
                </details>
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="import" id="rc_prod_import_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">استيراد قاعدة الإنتاج</span>
                        <span class="rc-acc-meta"><span class="rc-muted">معلومات</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_prod_import_banner" class="rc-readonly-banner" role="status">
                            <strong>ملفات التطبيق لم تُبدَّل بعد.</strong>
                        </p>
                        <dl id="rc_prod_import_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">بانتظار</dd></div>
                            <div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>التنفيذ</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                        </dl>
                    </div>
                </details>
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="uploads" id="rc_uploads_cutover_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">تحويل ملفات الرفع</span>
                        <span class="rc-acc-meta"><span class="rc-muted">معلومات</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_uploads_cutover_banner" class="rc-readonly-banner" role="status">
                            <strong>الصيانة ما زالت مفعّلة. الاسترداد لم يكتمل. التراجع لم يُنفَّذ.</strong>
                        </p>
                        <dl id="rc_uploads_cutover_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">بانتظار</dd></div>
                            <div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>التنفيذ</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                        </dl>
                    </div>
                </details>
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="rollback" id="rc_rollback_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">التراجع الإنتاجي</span>
                        <span class="rc-acc-meta"><span class="rc-muted">معلومات</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_rollback_banner" class="rc-readonly-banner" role="status">
                            <strong>الصيانة ما زالت مفعّلة. الاسترداد لم يكتمل. نقطة الارتكاز للتراجع محفوظة.</strong>
                        </p>
                        <dl id="rc_rollback_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">بانتظار</dd></div>
                            <div><dt>أعلى نقطة تحقق</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>التنفيذ</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                        </dl>
                    </div>
                </details>
                <details class="rc-acc-item rc-stage-acc" data-rc-acc="stage" data-stage="finalize" id="rc_finalize_section">
                    <summary>
                        <span class="rc-acc-chevron" aria-hidden="true"></span>
                        <span class="rc-acc-title">الإنهاء وإطلاق الصيانة</span>
                        <span class="rc-acc-meta"><span class="rc-muted">معلومات</span></span>
                    </summary>
                    <div class="rc-acc-body">
                        <p id="rc_finalize_banner" class="rc-readonly-banner" role="status">
                            <strong>الإنهاء يطلق الصيانة بعد نجاح الاسترداد أو التراجع. السجلات التشغيلية تبقى محفوظة.</strong>
                        </p>
                        <dl id="rc_finalize_status" class="rc-status-strip">
                            <div><dt>الحالة</dt><dd class="rc-stage-idle">بانتظار</dd></div>
                            <div><dt>الصيانة</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                            <div><dt>التنفيذ</dt><dd class="rc-stage-idle">لا نشاط</dd></div>
                        </dl>
                    </div>
                </details>
            </div>
        </div>
    </section>

    <section class="rc-phase" aria-labelledby="rc_phase6_title">
        <div class="rc-phase-head">
            <span class="rc-phase-num" aria-hidden="true">i</span>
            <h2 class="rc-phase-title" id="rc_phase6_title">ملخص سريع</h2>
            <p class="rc-phase-hint">أرقام مرجعية فقط.</p>
        </div>
        <div class="rc-panel">
            <p class="rc-muted" style="margin:0 0 8px;" id="rc_monitor_hint">التقدم الفعلي يُدار من المسار الموجَّه أعلاه.</p>
            <div id="rc_monitor_snapshot" class="rc-status-strip">
                <div><dt>المهام</dt><dd id="rc_mon_jobs">—</dd></div>
                <div><dt>النشطة</dt><dd id="rc_mon_active">—</dd></div>
                <div><dt>المرحلة</dt><dd id="rc_mon_phase">—</dd></div>
                <div><dt>التقدم</dt><dd id="rc_mon_progress">—</dd></div>
            </div>
        </div>
    </section>
    </details>
    </div>
</div>

<div id="rc_view_modal" class="rc-modal-backdrop" aria-hidden="true" data-rc-modal="1">
    <div class="rc-modal rc-modal--wide" role="dialog" aria-modal="true" aria-labelledby="rc_view_title">
        <div class="rc-modal-head">
            <h3 id="rc_view_title">عرض</h3>
            <p class="rc-tz-label" id="rc_view_tz_note"></p>
        </div>
        <div class="rc-modal-body">
            <div id="rc_view_structured" style="display:none;"></div>
            <pre id="rc_view_pre" class="rc-pre"></pre>
        </div>
        <div class="rc-modal-foot">
            <button type="button" class="btn-secondary" id="rc_view_close">إغلاق</button>
        </div>
    </div>
</div>

<div id="rc_detail_modal" class="rc-modal-backdrop" aria-hidden="true" data-rc-modal="1">
    <div class="rc-modal rc-modal--wide" role="dialog" aria-modal="true" aria-labelledby="rc_detail_title">
        <div class="rc-modal-head">
            <h3 id="rc_detail_title">تفاصيل المهمة</h3>
        </div>
        <div class="rc-modal-body" id="rc_detail_body"></div>
        <div class="rc-modal-foot">
            <button type="button" class="btn-secondary" id="rc_detail_close">إغلاق</button>
        </div>
    </div>
</div>

<div id="rc_orch_diag_modal" class="rc-modal-backdrop" aria-hidden="true" data-rc-modal="1">
    <div class="rc-modal rc-modal--wide" role="dialog" aria-modal="true" aria-labelledby="rc_orch_diag_title">
        <div class="rc-modal-head">
            <h3 id="rc_orch_diag_title">تشخيص التشغيل</h3>
            <p class="rc-muted">أسباب فشل جدولة المراحل من مركز الاسترداد — معلومات تشغيلية آمنة للمشغّل.</p>
        </div>
        <div class="rc-modal-body" id="rc_orch_diag_body"></div>
        <div class="rc-modal-foot">
            <button type="button" class="btn-secondary" id="rc_orch_diag_close">إغلاق</button>
        </div>
    </div>
</div>

<?php /* Step-1 / message-surface: centered Close-only dialog — no X / backdrop / Escape dismiss */ ?>
<div id="rc_result_dialog_backdrop" class="rc-result-dialog-backdrop" aria-hidden="true" data-rc-result-dialog="1">
    <div id="rc_result_dialog" class="rc-result-dialog" role="dialog" aria-modal="true" aria-labelledby="rc_result_dialog_title" tabindex="-1">
        <div class="rc-result-dialog-head">
            <h3 id="rc_result_dialog_title">نتيجة العملية</h3>
        </div>
        <div class="rc-result-dialog-body" id="rc_result_dialog_body"></div>
        <div class="rc-result-dialog-foot">
            <button type="button" class="btn-secondary" id="rc_result_dialog_close">إغلاق</button>
        </div>
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
    /** Step 1 package list — same conceptual limit as Backup Center RECENT_LIMIT. */
    const PKG_RECENT_LIMIT = 5;

    let state = {
        full: [],
        country: [],
        jobs: [],
        currentJourneyJob: null,
        busy: false,
        csrf: '',
        lastOverview: null,
        lastMaintenance: null,
        openedStage: '',
        guidedAllowCreateJob: false,
        /** @type {{ id:string, type:string, cc:string, key:string }|null} */
        selectedPackage: null,
        /** UI-only Step 1 list mode per tab: 'latest5' | 'all' */
        packageListMode: { full: 'latest5', country: 'latest5' },
        countryContextCode: COUNTRY_CONTEXT_CODE
    };
    let rcResultDialogReturnFocus = null;
    let rcResultDialogKeyHandler = null;
    /** Authoritative framework terminal statuses — same set as orange_restore_fw_transition_terminal_statuses(). */
    const FW_TERMINAL_STATUSES = new Set(<?php echo json_encode(array_values($fwTerminalStatuses), JSON_UNESCAPED_UNICODE); ?>);

    const el = (id) => document.getElementById(id);

    function isTerminalJob(job) {
        if (!job || typeof job !== 'object') return true;
        if (typeof job.is_terminal === 'boolean') return job.is_terminal;
        if (typeof job.is_resumable === 'boolean') return !job.is_resumable;
        const s = String(job.status || '').toLowerCase();
        return s === '' || FW_TERMINAL_STATUSES.has(s);
    }

    function isResumableJob(job) {
        return !!(job && !isTerminalJob(job));
    }

    /** Open/close Restore modals using Backup Center drawer sizing rules (viewport-bound + body scroll). */
    function syncRcModalScrollLock() {
        const anyDrawer = !!document.querySelector('.rc-modal-backdrop[data-rc-modal="1"][style*="flex"]');
        const resultOpen = !!(el('rc_result_dialog_backdrop') && el('rc_result_dialog_backdrop').classList.contains('is-open'));
        document.body.classList.toggle('rc-modal-open', anyDrawer || resultOpen);
    }
    function openRcModal(backdropId) {
        const node = el(backdropId);
        if (!node) return;
        node.style.display = 'flex';
        node.setAttribute('aria-hidden', 'false');
        const body = node.querySelector('.rc-modal-body');
        if (body) body.scrollTop = 0;
        syncRcModalScrollLock();
    }
    function closeRcModal(backdropId) {
        const node = el(backdropId);
        if (!node) return;
        node.style.display = 'none';
        node.setAttribute('aria-hidden', 'true');
        syncRcModalScrollLock();
    }
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

    /**
     * Operator-facing status badge — Arabic labels only.
     * RESTORE_CENTER_ORCH_RAW_STATE_VISIBLE_01: never show raw snake_case tokens in UI.
     * Unmapped internal statuses fail closed to a safe Arabic placeholder.
     */
    const badge = (status) => {
        const raw = String(status || '');
        const s = raw.toLowerCase();
        let cls = 'rc-badge--muted';
        if (s === 'healthy' || s === 'success' || s === 'pass' || s === 'eligible' || s === 'completed' || s === 'dry_completed' || s === 'approved_waiting_execution' || s === 'pre_restore_backup_ready' || s === 'shadow_restore_ready' || s === 'shadow_verified' || s === 'shadow_files_ready' || s === 'shadow_smoke_ready' || s === 'cutover_readiness_ready' || s === 'country_shadow_verified' || s === 'ready' || s === 'country_dry_run_safe' || s === 'safe') cls = 'rc-badge--success';
        else if (s === 'warning' || s === 'warn' || s === 'awaiting_owner_approval' || s === 'awaiting_final_approval' || s === 'waiting_confirmation' || s === 'execution_plan_ready' || s === 'pre_restore_backup_pending' || s === 'shadow_restore_pending' || s === 'shadow_not_ready' || s === 'shadow_smoke_pending' || s === 'shadow_smoke_warning' || s === 'cutover_readiness_manual_review' || s === 'country_shadow_warning' || s === 'country_dry_run_warning') cls = 'rc-badge--warning';
        else if (s === 'failed' || s === 'fail' || s === 'error' || s === 'not_eligible' || s === 'dry_failed' || s === 'execution_failed' || s === 'execution_cancelled' || s === 'cancelled' || s === 'pre_restore_backup_failed' || s === 'shadow_restore_failed' || s === 'shadow_files_failed' || s === 'shadow_smoke_failed' || s === 'cutover_readiness_blocked' || s === 'country_shadow_not_ready' || s === 'country_dry_run_failed') cls = 'rc-badge--failed';
        else if (s === 'running' || s.includes('progress') || s.includes('staging') || s.includes('merge') || s === 'execution_precheck' || s === 'dry_running' || s === 'pre_restore_backup_running' || s === 'pre_restore_backup_verifying' || s === 'shadow_restore_running' || s === 'shadow_restore_verifying' || s === 'shadow_verifying' || s === 'shadow_files_running' || s === 'shadow_files_verifying' || s === 'shadow_smoke_running' || s === 'country_shadow_verifying' || s === 'country_dry_run_running') cls = 'rc-badge--running';
        let label = '';
        if (s === 'awaiting_final_approval') label = 'بانتظار الموافقة النهائية';
        if (s === 'approved_waiting_execution') label = 'معتمدة — بانتظار التنفيذ';
        if (s === 'country_shadow_verifying') label = 'جارٍ تحقق ظل الدولة';
        if (s === 'country_shadow_verified') label = 'ظل الدولة موثّق';
        if (s === 'country_shadow_warning') label = 'ظل الدولة — تحذير';
        if (s === 'country_shadow_not_ready') label = 'ظل الدولة غير جاهز';
        if (s === 'country_dry_run_running') label = 'جارٍ محاكاة استعادة الدولة';
        if (s === 'country_dry_run_safe') label = 'محاكاة استعادة الدولة آمنة';
        if (s === 'country_dry_run_warning') label = 'محاكاة استعادة الدولة — تحذير';
        if (s === 'country_dry_run_failed') label = 'فشل محاكاة استعادة الدولة';
        if (s === 'safe') label = 'آمن';
        if (s === 'cancelled' || s === 'execution_cancelled') label = 'ملغاة';
        if (s === 'completed' || s === 'execution_completed' || s === 'restore_completed') label = 'مكتملة';
        if (s === 'rollback_completed') label = 'اكتمل التراجع';
        if (s === 'failed' || s === 'execution_failed') label = 'فشلت';
        if (s === 'pre_restore_backup_pending') label = 'بانتظار تنفيذ النسخة الاحتياطية';
        if (s === 'pre_restore_backup_running') label = 'جارٍ إنشاء النسخة الاحتياطية';
        if (s === 'pre_restore_backup_verifying') label = 'جارٍ التحقق';
        if (s === 'pre_restore_backup_ready') label = 'النسخة الاحتياطية جاهزة وآمنة للرجوع';
        if (s === 'pre_restore_backup_failed') label = 'فشل إعداد النسخة الاحتياطية';
        if (s === 'shadow_restore_pending') label = 'بانتظار تنفيذ استعادة قاعدة الظل';
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
        if (s === 'shadow_smoke_pending') label = 'بانتظار تنفيذ اختبارات الجاهزية';
        if (s === 'shadow_smoke_running') label = 'جارٍ اختبار قاعدة البيانات والملفات المعزولة';
        if (s === 'shadow_smoke_ready') label = 'البيئة المعزولة جاهزة';
        if (s === 'shadow_smoke_warning') label = 'تحتاج مراجعة يدوية';
        if (s === 'shadow_smoke_failed') label = 'البيئة غير جاهزة';
        if (s === 'cutover_readiness_ready') label = 'البيئة المعزولة جاهزة';
        if (s === 'cutover_readiness_manual_review') label = 'تحتاج مراجعة يدوية';
        if (s === 'cutover_readiness_blocked') label = 'البيئة غير جاهزة';
        if (s === 'maintenance_active') label = 'الصيانة مفعّلة';
        if (s === 'maintenance_ready') label = 'الصيانة جاهزة';
        if (s === 'production_import_pending') label = 'استيراد الإنتاج معلّق';
        if (s === 'production_import_running') label = 'جارٍ استيراد الإنتاج';
        if (s === 'production_import_verifying') label = 'جارٍ التحقق من الاستيراد';
        if (s === 'production_import_ready') label = 'الاستيراد جاهز';
        if (s === 'production_import_failed') label = 'فشل الاستيراد';
        if (s === 'uploads_cutover_pending') label = 'تحويل الرفع معلّق';
        if (s === 'uploads_cutover_running') label = 'جارٍ تحويل الرفع';
        if (s === 'uploads_cutover_verifying') label = 'جارٍ التحقق من التحويل';
        if (s === 'uploads_cutover_ready') label = 'تحويل الرفع جاهز';
        if (s === 'uploads_cutover_failed') label = 'فشل تحويل الرفع';
        if (s === 'rollback_pending') label = 'التراجع معلّق';
        if (s === 'rollback_database_running' || s === 'rollback_database_verifying') label = 'تراجع القاعدة';
        if (s === 'rollback_files_running' || s === 'rollback_files_verifying') label = 'تراجع الملفات';
        if (s === 'rollback_ready') label = 'التراجع جاهز';
        if (s === 'rollback_failed') label = 'فشل التراجع';
        if (s === 'restore_finalizing' || s === 'rollback_finalizing') label = 'جارٍ الإنهاء';
        if (s === 'pass' || s === 'ناجح') label = 'ناجح';
        if (s === 'fail' || s === 'فشل') label = 'فشل';
        if (s === 'running') label = 'جارٍ';
        if (s === 'ready') label = 'جاهز';
        if (s === 'pending') label = 'معلّق';
        if (s === 'inactive') label = 'غير مفعّل';
        if (s === 'بانتظار') label = 'بانتظار';
        // Already-Arabic / short UI labels (not internal tokens)
        if (label === '' && raw !== '' && /[\u0600-\u06FF]/.test(raw)) label = raw;
        if (label === '' && (s === 'ناجح' || s === 'فشل' || s === 'مكتملة' || s === 'ملغاة' || s === 'جاهز' || s === 'معلّق' || s === 'جارٍ')) label = raw;
        // Fail closed: never leak snake_case / internal machine tokens to the operator surface.
        if (label === '') {
            if (s === '' || s === '—' || s === '-') label = '—';
            else label = 'حالة غير معروضة';
        }
        return '<span class="rc-badge ' + cls + '">' + label + '</span>';
    };
    const statusLabelAr = (status) => {
        const html = badge(status);
        const m = String(html).match(/>([^<]+)</);
        return m ? m[1] : String(status || '—');
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
    /** Dry-run overall result badge for history/accordion (PASS / WARNING / FAIL). */
    const dryResultBadge = (result) => {
        const r = String(result || '').toUpperCase();
        if (r === 'PASS') return '<span class="rc-badge rc-badge--success">PASS</span>';
        if (r === 'WARNING') return '<span class="rc-badge rc-badge--warning">WARNING</span>';
        if (r === 'FAIL') return '<span class="rc-badge rc-badge--failed">FAIL</span>';
        return '<span class="rc-badge rc-badge--muted">—</span>';
    };
    const drvCell = (pkg) => {
        const result = String(pkg.drv_result || '').toLowerCase();
        if (result === 'pass') return badge('ناجح');
        if (result === 'fail') return badge('فشل');
        if (result === 'missing') return '—';
        const score = pkg.drv_score;
        if (score === null || score === undefined || score === '') return '—';
        return String(score);
    };

    const operatorMessage = (msg) => {
        return String(msg || '')
            .replace(/Request failed/gi, 'فشل الطلب')
            .replace(/عامل هذه المرحلة/g, 'هذه المرحلة')
            .replace(/عامل/g, 'مرحلة')
            .replace(/\bCLI\b/gi, '')
            .replace(/\bWorker\b/gi, 'مرحلة')
            .replace(/\bFramework\b/gi, '')
            .replace(/\bSSH\b/gi, '')
            .replace(/\bScript\b/gi, '')
            .replace(/\bTerminal\b/gi, '')
            .replace(/Production restore has NOT started\./gi, 'استرداد الإنتاج لم يبدأ بعد.')
            .replace(/Application files have NOT been switched\./gi, 'ملفات التطبيق لم تُبدَّل بعد.')
            .replace(/\s{2,}/g, ' ')
            .trim();
    };
    /** Operator-facing job.message — never leak English dispatch / CLI jargon. */
    const operatorJobMessage = (msg) => {
        let s = String(msg || '');
        if (/Worker\s+dispatch\s+failed/i.test(s) || /retry from Restore Center/i.test(s)) {
            return 'تعذر بدء عامل التنفيذ — يمكن إعادة المحاولة من شاشة الاسترداد.';
        }
        if (/بدون\s*تسليم\s*CLI/i.test(s) || /\bCLI\b/i.test(s) || /no[-_ ]?cli/i.test(s)) {
            return 'يستمر التنفيذ تلقائيًا على الخادم.';
        }
        /* Step-6 shared Full Backup service — map known internal English operator notes. */
        if (/shared\s+Full\s+Backup\s+running/i.test(s) || /shared\s+full\s+backup\s+running/i.test(s)) {
            return 'جارٍ إنشاء النسخة الاحتياطية الكاملة عبر المحرك المعتمد.';
        }
        if (/shared\s+verification\s+running/i.test(s) || /verification\s+running/i.test(s)) {
            return 'جارٍ التحقق من جاهزية النسخة الاحتياطية.';
        }
        if (/Pre-restore\s+Full\s+backup\s+ready/i.test(s) || /retention-?pinned/i.test(s)) {
            return 'النسخة الاحتياطية جاهزة وآمنة للرجوع ومثبتة ضد الحذف.';
        }
        if (/Pre-restore\s+backup\s+pending/i.test(s) || /shared\s+Full\s+Backup\s+service/i.test(s)) {
            return 'بانتظار تنفيذ النسخة الاحتياطية الإلزامية عبر المحرك المعتمد.';
        }
        if (/engine\s+failure/i.test(s)) {
            return 'فشل محرك النسخ الاحتياطي. يمكن إعادة المحاولة من مركز الاسترداد.';
        }
        if (/verification\s+failure/i.test(s) || /verify\s+failed/i.test(s)) {
            return 'فشل التحقق من الحزمة. لن تُعتمد كنسخة جاهزة.';
        }
        if (/binding\s+failure/i.test(s) || /package\s+bind/i.test(s)) {
            return 'تعذر ربط الحزمة بمهمة الاسترداد. لن تُفتح الخطوة التالية.';
        }
        if (/illegal_framework_status_transition/i.test(s) || /retry_state_conflict/i.test(s)
            || /تعذر بدء إعادة المحاولة لأن حالة المهمة/i.test(s)) {
            return 'تعذر بدء إعادة المحاولة لأن حالة المهمة الحالية تتعارض مع بدء تنفيذ جديد. حدّث الحالة ثم أعد المحاولة من نفس الخطوة.';
        }
        s = operatorMessage(s);
        if (/Worker\s+dispatch\s+failed/i.test(s) || /retry from Restore Center/i.test(s)) {
            return 'تعذر بدء عامل التنفيذ — يمكن إعادة المحاولة من شاشة الاسترداد.';
        }
        /* Last resort: never show Latin-only internal notes in the operator context card. */
        if (s !== '' && !/[\u0600-\u06FF]/.test(s) && /[A-Za-z]/.test(s)) {
            return 'تحديث حالة مهمة الاسترداد.';
        }
        return s;
    };
    function closeRcResultDialog() {
        const backdrop = el('rc_result_dialog_backdrop');
        const dlg = el('rc_result_dialog');
        if (!backdrop || !dlg) return;
        backdrop.classList.remove('is-open');
        backdrop.setAttribute('aria-hidden', 'true');
        if (rcResultDialogKeyHandler) {
            document.removeEventListener('keydown', rcResultDialogKeyHandler, true);
            rcResultDialogKeyHandler = null;
        }
        const ret = rcResultDialogReturnFocus;
        rcResultDialogReturnFocus = null;
        syncRcModalScrollLock();
        if (ret && typeof ret.focus === 'function' && document.contains(ret)) {
            try { ret.focus({ preventScroll: true }); } catch (err) { /* ignore */ }
        }
    }
    function openRcCenteredResultShell(opts) {
        opts = opts || {};
        const backdrop = el('rc_result_dialog_backdrop');
        const dlg = el('rc_result_dialog');
        const body = el('rc_result_dialog_body');
        const titleEl = el('rc_result_dialog_title');
        const closeBtn = el('rc_result_dialog_close');
        if (!backdrop || !dlg || !body || !titleEl || !closeBtn) return;
        titleEl.textContent = String(opts.title || 'رسالة النظام');
        body.innerHTML = String(opts.bodyHtml || '');
        const ok = !!opts.success;
        const fail = opts.failure === true || (opts.failure == null && opts.success === false);
        dlg.classList.toggle('rc-result-dialog--ok', ok);
        dlg.classList.toggle('rc-result-dialog--fail', fail && !ok);
        rcResultDialogReturnFocus = opts.sourceBtn && opts.sourceBtn.isConnected
            ? opts.sourceBtn
            : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        backdrop.classList.add('is-open');
        backdrop.setAttribute('aria-hidden', 'false');
        syncRcModalScrollLock();
        if (rcResultDialogKeyHandler) {
            document.removeEventListener('keydown', rcResultDialogKeyHandler, true);
        }
        rcResultDialogKeyHandler = (ev) => {
            if (!backdrop.classList.contains('is-open')) return;
            if (ev.key === 'Escape') {
                ev.preventDefault();
                ev.stopPropagation();
                return;
            }
            if (ev.key !== 'Tab') return;
            ev.preventDefault();
            closeBtn.focus();
        };
        document.addEventListener('keydown', rcResultDialogKeyHandler, true);
        try { closeBtn.focus({ preventScroll: true }); } catch (err) { /* ignore */ }
    }
    /** Dedup Owner failure dialogs: one row per attempt/stage/safe category (OWNER_DUPLICATE_FAILURE_MESSAGE_01). */
    let rcLastFailureDialogKey = '';
    let rcLastFailureDialogAt = 0;
    /** CENTERED_SYSTEM_DIALOG / CENTERED_OPERATION_RESULT_DIALOG — never a top-page card. */
    function showRcTerminalMessage(msg, ok, sourceBtn, dedupKey) {
        clearRcJourneyInlineMessage();
        const success = !!ok;
        const text = operatorMessage(msg);
        if (!success) {
            const key = String(dedupKey || text || '').trim();
            const now = Date.now();
            if (key && key === rcLastFailureDialogKey && (now - rcLastFailureDialogAt) < 8000) {
                return;
            }
            if (key) {
                rcLastFailureDialogKey = key;
                rcLastFailureDialogAt = now;
            }
        } else {
            rcLastFailureDialogKey = '';
            rcLastFailureDialogAt = 0;
        }
        openRcCenteredResultShell({
            title: success ? 'نتيجة العملية' : 'تعذر إتمام العملية',
            bodyHtml: '<p class="rc-result-dialog-summary">' + esc(text || 'تعذر تنفيذ العملية.') + '</p>',
            success: success,
            failure: !success,
            sourceBtn: sourceBtn || null
        });
    }
    /** JOURNEY_STEP_INLINE_MESSAGE — soft step warnings/errors stay in the wizard hero (not centered). */
    function showRcJourneyInlineMessage(msg) {
        const host = el('rc_journey_inline');
        if (!host) return;
        const text = operatorMessage(msg);
        if (!text) {
            host.hidden = true;
            host.textContent = '';
            return;
        }
        host.hidden = false;
        host.textContent = text;
    }
    function clearRcJourneyInlineMessage() {
        const host = el('rc_journey_inline');
        if (!host) return;
        host.hidden = true;
        host.textContent = '';
    }
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
        if (!j.success && r.status >= 400) {
            const err = new Error(operatorMessage(j.message || 'فشل الطلب'));
            err.code = j.code || '';
            err.refresh_error_category = j.refresh_error_category || '';
            throw err;
        }
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
            const err = new Error(operatorMessage(
                (j.diagnostics && j.diagnostics.reason_ar) || j.message || 'فشل الطلب'
            ));
            err.code = j.code || '';
            err.diagnostics = j.diagnostics || null;
            throw err;
        }
        return j;
    }

    function stageNameAr(key) {
        const map = {
            pre_restore_backup: 'النسخة الاحتياطية قبل الاسترداد',
            shadow_db: 'استعادة قاعدة الظل',
            shadow_verify: 'تحقق قاعدة الظل',
            shadow_files: 'ملفات الظل',
            shadow_smoke: 'اختبارات الجاهزية',
            production_import: 'استيراد قاعدة الإنتاج',
            uploads_cutover: 'تحويل ملفات الرفع',
            rollback: 'التراجع',
            finalize: 'الإنهاء'
        };
        return map[String(key || '')] || String(key || '—');
    }

    function formatOrchestratorDiagnostics(diag) {
        if (!diag || typeof diag !== 'object') {
            return '<p class="muted">لا تتوفر بيانات تشخيص.</p>';
        }
        let html = '';
        html += '<div class="rc-status-strip">';
        html += '<div><dt>المهمة</dt><dd class="rc-mono">' + esc(diag.job_id || '—') + '</dd></div>';
        html += '<div><dt>الحالة</dt><dd>' + esc(statusLabelAr(diag.job_status || '—')) + '</dd></div>';
        html += '</div>';
        const latest = diag.latest_attempt_diagnostic && typeof diag.latest_attempt_diagnostic === 'object'
            ? diag.latest_attempt_diagnostic
            : null;
        html += '<h4 style="margin:12px 0 6px;font-size:.9rem;">أحدث محاولة للمرحلة الحالية</h4>';
        if (!latest || latest.missing_current_attempt) {
            html += '<p style="margin:0 0 8px;line-height:1.55;">'
                + esc((latest && latest.reason_ar)
                    ? String(latest.reason_ar)
                    : 'تعذر العثور على تفاصيل المحاولة الحالية في سجل التشغيل.')
                + '</p>';
        } else {
            const safeCode = String(latest.safe_failure_code || latest.code || '').trim();
            const showCode = safeCode && /^(STEP7_|retry_state_conflict|shadow_restore_|pre_restore_backup_|ok)/.test(safeCode);
            html += '<p style="margin:0 0 8px;line-height:1.55;"><strong>'
                + esc(stageNameAr(latest.worker || diag.guided_stage_worker || '')) + '</strong> — '
                + esc(String(latest.reason_ar || 'تحديث المرحلة').replace(/illegal_framework_status_transition[^\\s]*/gi, 'تعارض حالة'))
                + (showCode ? ' <span class="muted">[' + esc(safeCode) + ']</span>' : '')
                + (latest.at ? ' <span class="muted">(' + esc(latest.at) + ')</span>' : '')
                + '</p>';
        }
        const events = Array.isArray(diag.recent_orchestration_events) ? diag.recent_orchestration_events : [];
        html += '<h4 style="margin:12px 0 6px;font-size:.9rem;">أحداث التشغيل الأخيرة</h4>';
        if (!events.length) {
            html += '<p class="muted">لا توجد أحداث تشغيل مسجّلة بعد.</p>';
        } else {
            html += '<ul style="margin:0;padding-inline-start:1.2rem;line-height:1.55;">';
            events.forEach(function (ev) {
                let reason = String(ev.reason_ar || '')
                    .replace(/عامل/g, 'مرحلة')
                    .replace(/CLI/gi, '')
                    .replace(/illegal_framework_status_transition[^\s]*/gi, 'تعارض حالة')
                    .replace(/\s{2,}/g, ' ')
                    .trim();
                const hist = ev.historical_only ? ' <span class="muted">(تاريخي)</span>' : '';
                html += '<li><strong>' + esc(stageNameAr(ev.worker)) + '</strong> — '
                    + esc(reason || 'تعذر التنفيذ')
                    + hist
                    + (ev.at ? ' <span class="muted">(' + esc(ev.at) + ')</span>' : '')
                    + '</li>';
            });
            html += '</ul>';
        }
        const workers = Array.isArray(diag.workers) ? diag.workers : [];
        html += '<h4 style="margin:12px 0 6px;font-size:.9rem;">قابلية التنفيذ حسب المرحلة</h4>';
        if (!workers.length) {
            html += '<p class="muted">—</p>';
        } else {
            html += '<table class="admin-table" style="width:100%;font-size:.82rem;"><thead><tr>'
                + '<th>المرحلة</th><th>قابلة للتنفيذ الآن</th><th>تنفيذ جارٍ</th>'
                + '</tr></thead><tbody>';
            workers.forEach(function (w) {
                html += '<tr><td>' + esc(stageNameAr(w.worker)) + '</td>'
                    + '<td>' + (w.schedulable_now ? 'نعم' : 'لا') + '</td>'
                    + '<td>' + (w.claim_active ? 'نعم' : 'لا') + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        const readiness = diag.step7_shadow_target_readiness && typeof diag.step7_shadow_target_readiness === 'object'
            ? diag.step7_shadow_target_readiness
            : null;
        if (readiness || diag.ready_token || diag.ready_for_controlled_step7_attempt
            || diag.ready_for_private_shadow_provisioning) {
            html += '<h4 style="margin:12px 0 6px;font-size:.9rem;">جاهزية خطوة استعادة قاعدة الظل</h4>';
            const token = String(diag.ready_token || (readiness && readiness.ready_token) || '');
            const readyControlled = !!(diag.ready_for_controlled_step7_attempt
                || (readiness && readiness.ready_for_controlled_step7_attempt)
                || token === 'READY_FOR_CONTROLLED_STEP7_ATTEMPT');
            const readyProvision = !!(diag.ready_for_private_shadow_provisioning
                || (readiness && readiness.ready_for_private_shadow_provisioning)
                || token === 'READY_FOR_PRIVATE_SHADOW_PROVISIONING');
            const tokenLabel = readyControlled
                ? 'READY_FOR_CONTROLLED_STEP7_ATTEMPT'
                : (readyProvision ? 'READY_FOR_PRIVATE_SHADOW_PROVISIONING' : (token || 'NOT_READY'));
            const privateCap = readiness
                ? String(readiness.private_capability
                    || (readiness.private_engine && readiness.private_engine.private_capability)
                    || 'unavailable')
                : 'unavailable';
            const match = readiness ? !!readiness.parent_worker_target_identity_match : false;
            const runtimeMatch = readiness ? !!readiness.parent_worker_runtime_identity_match : match;
            const runtimeSource = readiness
                ? String(readiness.runtime_source || (readiness.private_engine && readiness.private_engine.runtime_source) || 'unavailable')
                : 'unavailable';
            const runtimeVerified = !!(readiness && (readiness.runtime_verified
                || (readiness.private_engine && readiness.private_engine.runtime_verified)));
            const runtimeCompatible = !!(readiness && (readiness.runtime_compatible
                || (readiness.private_engine && readiness.private_engine.runtime_compatible)));
            const toolsReady = !!(readiness && (readiness.tools_root_ready
                || (readiness.private_engine && readiness.private_engine.tools_root_ready)));
            const hostCat = readiness
                ? String(readiness.db_host_category || (readiness.private_engine && readiness.private_engine.db_host_category) || 'UNKNOWN')
                : 'UNKNOWN';
            const pre = (readiness && readiness.retry_preflight && typeof readiness.retry_preflight === 'object')
                ? readiness.retry_preflight
                : (readiness || {});
            const actionEnabled = !!(readiness && readiness.step7_action_enabled)
                || !!(pre.step7_action_enabled);
            const finalReady = String(pre.final_readiness || tokenLabel || 'NOT_READY');
            const exactReason = String(pre.exact_not_ready_reason || readiness.exact_not_ready_reason || '');
            const yn = function (v) { return v ? 'نعم' : 'لا'; };
            html += '<ul style="margin:0;padding-inline-start:1.2rem;line-height:1.55;">'
                + '<li><strong>الجاهزية النهائية: ' + esc(finalReady) + '</strong></li>'
                + (exactReason && finalReady === 'NOT_READY'
                    ? '<li>سبب NOT_READY الدقيق: <code>' + esc(exactReason) + '</code></li>' : '')
                + '<li>الحالة قابلة للطلب: ' + yn(!!(pre.state_requestable || (readiness && readiness.requestable))) + '</li>'
                + '<li>زر الخطوة مفعّل: ' + yn(actionEnabled) + '</li>'
                + '<li>قدرة المحرك الخاص: ' + esc(privateCap) + '</li>'
                + '<li>المحاولة طرفية: ' + yn(!!pre.latest_attempt_terminal) + '</li>'
                + '<li>محاولة نشطة: ' + yn(!!pre.active_attempt) + '</li>'
                + '<li>حالة المطالبة: ' + esc(String(pre.claim_status || pre.claim_state || '—')) + '</li>'
                + '<li>قفل المرحلة: ' + esc(String(pre.stage_mutex_status || pre.stage_mutex_state || '—')) + '</li>'
                + '<li>قفل تثبيت المحرك (منفصل): ' + esc(String(pre.runtime_install_mutex_status || '—')) + '</li>'
                + '<li>حيوية عامل PHP: ' + esc(String(pre.php_worker_liveness_class || pre.php_worker_liveness || '—')) + '</li>'
                + '<li>حيوية محرك DB الخاص: ' + esc(String(pre.private_db_liveness_class || pre.private_db_liveness || '—')) + '</li>'
                + '<li>إثبات غياب العملية: ' + yn(!!pre.process_absence_proven) + '</li>'
                + '<li>فئة مجلد البيانات: ' + esc(String(pre.datadir_category || '—')) + '</li>'
                + '<li>ملكية المجلد مثبتة: ' + yn(!!pre.datadir_ownership_proven) + '</li>'
                + '<li>recovery_required: ' + yn(!!(pre.recovery_required || pre.partial_recovery_required)) + '</li>'
                + '<li>recovery_safe: ' + yn(!!(pre.recovery_safe || pre.partial_recovery_safe)) + '</li>'
                + '<li>recovery_mode: ' + esc(String(pre.recovery_mode || 'none')) + '</li>'
                + '<li>قدرة التقاط حالة المحرك: ' + esc(String(pre.engine_state_capture_capability || '—')) + '</li>'
                + '<li>قدرة التقاط نتيجة التهيئة: ' + esc(String(pre.initialization_result_capture_capability || '—')) + '</li>'
                + '<li>قدرة التقاط أخطاء التهيئة: ' + esc(String(pre.initialization_error_capture_capability || pre.initialization_result_error_capture_capability || '—')) + '</li>'
                + '<li>تطابق هدف الأب/العامل: ' + yn(match || !!pre.parent_worker_target_match) + '</li>'
                + '<li>تطابق محرك الأب/العامل: ' + yn(runtimeMatch || !!pre.parent_worker_runtime_match) + '</li>'
                + '<li>المصدر: ' + esc((readiness && readiness.source) ? String(readiness.source) : '—') + '</li>'
                + '<li>مصدر المحرك الحالي: ' + esc(String(pre.current_runtime_source || runtimeSource)) + '</li>'
                + '<li>المحرك موثّق: ' + yn(runtimeVerified || !!pre.current_runtime_verified) + '</li>'
                + '<li>المحرك متوافق: ' + yn(runtimeCompatible || !!pre.current_runtime_compatible) + '</li>'
                + '<li>هوية المحرك قابلة للتثبيت: ' + yn(!!pre.current_runtime_identity_persistable) + '</li>'
                + '<li>جذر الأدوات جاهز: ' + yn(toolsReady) + '</li>'
                + '<li>حزمة المصدر جاهزة: ' + yn(!!pre.source_package_ready) + '</li>'
                + '<li>الخطوة 8 مقفلة: ' + yn(pre.step8_locked !== false && pre.Step_8_locked !== false) + '</li>'
                + '<li>فئة مضيف قاعدة الإنتاج: ' + esc(hostCat) + '</li>'
                + '</ul>';
        }
        const trace = diag.private_engine_live_trace && typeof diag.private_engine_live_trace === 'object'
            ? diag.private_engine_live_trace
            : null;
        if (trace) {
            html += '<h4 style="margin:12px 0 6px;font-size:.9rem;">آثار محرك قاعدة الظل الخاص</h4>';
            html += '<ul style="margin:0;padding-inline-start:1.2rem;line-height:1.55;">'
                + '<li><strong>التصنيف:</strong> ' + esc(String(trace.classification || '—')) + '</li>'
                + '<li>قراءة فقط: ' + (trace.read_only ? 'نعم' : 'لا') + '</li>';
            const miss = Array.isArray(trace.missing_artifact_categories) ? trace.missing_artifact_categories : [];
            html += '<li>فئات ناقصة: ' + esc(miss.length ? miss.join('، ') : 'لا يوجد') + '</li>';
            const sec = trace.sections && typeof trace.sections === 'object' ? trace.sections : {};
            const fSafe = function (section, key) {
                const row = sec[section] && sec[section][key] ? sec[section][key] : null;
                if (!row || typeof row !== 'object') return '—';
                const v = row.value;
                const st = String(row.status || '');
                let shown = '';
                if (v === null || typeof v === 'undefined') shown = 'غائب';
                else if (typeof v === 'boolean') shown = v ? 'نعم' : 'لا';
                else if (typeof v === 'object') shown = JSON.stringify(v);
                else shown = String(v);
                return esc(shown) + (st ? ' <span class="muted">[' + esc(st) + ']</span>' : '');
            };
            html += '<li>حالة Step7: ' + fSafe('A_job_and_stage', 'current_canonical_job_status') + '</li>'
                + '<li>أحدث رمز آمن: ' + fSafe('B_latest_step7_attempt', 'latest_safe_code') + '</li>'
                + '<li>المطالبة: ' + fSafe('C_control_plane_ownership', 'claim_active_terminal_unknown') + '</li>'
                + '<li>مصدر المحرك: ' + fSafe('D_private_runtime_supply', 'selected_runtime_source_category') + '</li>'
                + '<li>مجلد البيانات: ' + fSafe('E_private_job_environment', 'datadir_state') + '</li>'
                + '<li>فئة التهيئة: ' + fSafe('E_private_job_environment', 'initialization_exit_category') + '</li>'
                + '<li>بدأ الاستيراد: ' + fSafe('F_step7_import_boundary', 'sql_import_started') + '</li>'
                + '<li>قفل الخطوة 8: ' + fSafe('A_job_and_stage', 'step8_lock_state') + '</li>'
                + '</ul>';
            const report = String(trace.arabic_report || '');
            if (report) {
                html += '<p style="margin:8px 0 4px;"><strong>تقرير عربي قابل للنسخ</strong></p>';
                html += '<textarea id="rc_private_engine_trace_report" class="rc-pre" readonly '
                    + 'style="width:100%;min-height:140px;max-height:220px;overflow:auto;white-space:pre-wrap;font-size:.8rem;direction:rtl;">'
                    + esc(report) + '</textarea>';
                html += '<p style="margin:6px 0 0;"><button type="button" class="btn-link rc-btn-ghost" id="rc_private_engine_trace_copy">'
                    + 'نسخ التقرير</button></p>';
            }
        }
        const tails = Array.isArray(diag.log_tails) ? diag.log_tails : [];
        if (tails.length) {
            html += '<h4 style="margin:12px 0 6px;font-size:.9rem;">مقتطفات سجل التشغيل (مُنقّاة)</h4>';
            tails.forEach(function (t) {
                const hist = t.historical_only || t.not_current_cause
                    ? ' <span class="muted">(تاريخي — ليس سبب المحاولة الحالية)</span>'
                    : '';
                html += '<p style="margin:8px 0 4px;"><strong>' + esc(stageNameAr(t.worker)) + '</strong>' + hist + '</p>';
                html += '<pre class="rc-pre" style="max-height:160px;overflow:auto;white-space:pre-wrap;">'
                    + esc(t.tail || '') + '</pre>';
            });
        }
        html += '<ul class="muted" style="margin:10px 0 0;padding-inline-start:1.2rem;font-size:.8rem;">'
            + '<li>التشخيص من مركز الاسترداد فقط.</li>'
            + '<li>لا تُعرض أسرار أو مسارات حساسة.</li>'
            + '<li>يُرفض التنفيذ إذا كانت حالة المهمة لا تسمح بالمرحلة أو إذا كانت المرحلة تعمل.</li>'
            + '<li>READY_FOR_PRIVATE_SHADOW_PROVISIONING: يمكن الضغط لتجهيز المحرك الخاص ثم الاستيراد.</li>'
            + '<li>READY_FOR_CONTROLLED_STEP7_ATTEMPT: المحرك الخاص جاهز — اضغط خطوة استعادة قاعدة الظل مرة واحدة.</li>'
            + '</ul>';
        return html;
    }

    async function openOrchestratorDiagnostics(jobId) {
        if (!jobId) throw new Error('معرّف المهمة غير صالح');
        setBusy(true, 'جاري تحميل تشخيص التشغيل…');
        try {
            const j = await apiGet('job/orchestrator-diagnostics.php?id=' + encodeURIComponent(jobId));
            if (j.csrf_token) state.csrf = j.csrf_token;
            el('rc_orch_diag_title').textContent = 'تشخيص تشغيل مراحل الاسترداد — ' + jobId;
            el('rc_orch_diag_body').innerHTML = formatOrchestratorDiagnostics(j.diagnostics || {});
            const copyBtn = el('rc_private_engine_trace_copy');
            const reportBox = el('rc_private_engine_trace_report');
            if (copyBtn && reportBox) {
                copyBtn.onclick = function () {
                    const text = String(reportBox.value || reportBox.textContent || '');
                    if (!text) return;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).catch(function () {
                            try {
                                reportBox.focus();
                                reportBox.select();
                                document.execCommand('copy');
                            } catch (eCopy) { /* ignore */ }
                        });
                    } else {
                        try {
                            reportBox.focus();
                            reportBox.select();
                            document.execCommand('copy');
                        } catch (eCopy2) { /* ignore */ }
                    }
                };
            }
            openRcModal('rc_orch_diag_modal');
        } finally {
            setBusy(false);
        }
    }

    /** In-flight schedule keys (jobId::worker) — UI guard; server enforces atomic lock. */
    const rcScheduleInFlight = new Set();
    /** Stage mutation locks — RESTORE_CENTER_ACTION_DOUBLE_SUBMIT_PREVENTION_01 */
    const rcStageActionLocks = new Set();
    const RC_STAGE_MUTATION_CLASSES = [
        'rc-create-job', 'rc-dry-run', 'rc-prepare-exec', 'rc-final-approve',
        'rc-pre-backup-req', 'rc-shadow-req', 'rc-shadow-smoke-req',
        'rc-maint-req', 'rc-maint-activate', 'rc-pca-authorize',
        'rc-prod-import-req', 'rc-uploads-cutover-req', 'rc-rollback-req',
        'rc-finalize-req', 'rc-run-worker', 'rc-cancel', 'rc-cancel-exec'
    ];

    function rcStageActionKey(ctrl) {
        if (!ctrl || !ctrl.classList) return '';
        const id = String(ctrl.getAttribute('data-id') || ctrl.getAttribute('data-job') || '');
        const worker = String(ctrl.getAttribute('data-worker') || '');
        let kind = '';
        RC_STAGE_MUTATION_CLASSES.forEach(function (c) {
            if (ctrl.classList.contains(c)) kind = c;
        });
        return kind ? (kind + '::' + id + '::' + worker) : '';
    }

    function lockStageActionControl(ctrl) {
        if (!ctrl) return;
        try { ctrl.disabled = true; } catch (e) { /* ignore */ }
        ctrl.setAttribute('disabled', 'disabled');
        ctrl.setAttribute('aria-disabled', 'true');
        ctrl.setAttribute('aria-busy', 'true');
        ctrl.setAttribute('title', 'التنفيذ جارٍ — انتظر انتهاء العملية');
        ctrl.classList.add('rc-stage-action-busy');
    }

    function unlockStageActionControl(ctrl) {
        if (!ctrl) return;
        try { ctrl.disabled = false; } catch (e) { /* ignore */ }
        ctrl.removeAttribute('disabled');
        ctrl.removeAttribute('aria-disabled');
        ctrl.removeAttribute('aria-busy');
        ctrl.removeAttribute('title');
        ctrl.classList.remove('rc-stage-action-busy');
    }

    function beginStageActionLock(ctrl) {
        const key = rcStageActionKey(ctrl);
        if (!key) return { ok: true, key: '' };
        if (rcStageActionLocks.has(key) || (ctrl.disabled && ctrl.classList.contains('rc-stage-action-busy'))) {
            return { ok: false, key: key };
        }
        rcStageActionLocks.add(key);
        lockStageActionControl(ctrl);
        return { ok: true, key: key };
    }

    function endStageActionLock(key) {
        if (key) rcStageActionLocks.delete(key);
    }

    /**
     * Network ambiguity: never blind re-enable. Reconcile via authoritative list/status.
     * RESTORE_CENTER_ACTION_LOCK_SERVER_RECONCILIATION_01
     */
    async function reconcileAfterStageAmbiguity(ctrl, key) {
        lockStageActionControl(ctrl);
        try {
            showRcJourneyInlineMessage('تعذر تأكيد نتيجة الطلب. جاري مزامنة حالة المهمة من الخادم…');
            await loadAll();
        } catch (e) {
            showRcTerminalMessage('تعذر مزامنة الحالة. استخدم تحديث الحالة قبل أي إعادة محاولة.', false);
        } finally {
            endStageActionLock(key);
            // Button authority comes from server re-render; do not unlock a stale node.
        }
    }

    /** Schedule approved internal worker (detached). One server call — no operator CLI/Plesk/Terminal. */
    async function runRestoreWorker(jobId, workerKey, busyText) {
        const key = String(jobId || '') + '::' + String(workerKey || '');
        if (!jobId || !workerKey) {
            throw new Error('معرّف المهمة أو المرحلة غير صالح');
        }
        if (rcScheduleInFlight.has(key)) {
            throw new Error('هذه المرحلة تعمل بالفعل لهذه المهمة. لن تُشغَّل مجدداً.');
        }
        rcScheduleInFlight.add(key);
        setBusy(true, busyText || 'جاري بدء التنفيذ على الخادم…');
        try {
            const j = await apiPost('job/run-worker.php', {
                csrf_token: state.csrf,
                job_id: jobId,
                worker: workerKey
            });
            if (j.csrf_token) state.csrf = j.csrf_token;
            if (!j.success || !j.scheduled) {
                const fail = new Error(
                    (j.diagnostics && j.diagnostics.reason_ar) || j.message || 'تعذر بدء عامل التنفيذ'
                );
                fail.code = j.code || '';
                fail.diagnostics = j.diagnostics || null;
                throw fail;
            }
            return j;
        } finally {
            rcScheduleInFlight.delete(key);
        }
    }

    /**
     * Self-contained Restore Center action: single authoritative schedule call.
     * Workers self-request from entry statuses — never leave pending without a consumer.
     * RESTORE_CENTER_INTERNAL_WORKER_ORCHESTRATION_REQUIRED_01
     * RESTORE_CENTER_OPERATOR_CLI_HANDOFF_FORBIDDEN_01
     */
    async function requestThenRunWorker(requestPath, workerKey, jobId, busyRequest, busyRun) {
        return runRestoreWorker(
            jobId,
            workerKey,
            busyRun || busyRequest || 'جاري بدء التنفيذ على الخادم…'
        );
    }

    const RC_SCHEDULED_MSG = 'تم بدء التنفيذ على الخادم. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.';
    const RC_PRE_BACKUP_OK_MSG = 'اكتملت النسخة الاحتياطية الإلزامية قبل الاسترداد وهي جاهزة وآمنة للرجوع.';
    const RC_PRE_BACKUP_FAIL_MSG = 'تعذر إكمال النسخة الاحتياطية الإلزامية قبل الاسترداد.\nلم تُربط أي حزمة، ويمكن إعادة المحاولة من شاشة الاسترداد.';
    const RC_SHADOW_SCHEDULED_MSG = 'تم بدء استعادة قاعدة الظل بعد تأكيد الإقلاع. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.';
    const RC_SHADOW_FAIL_MSG = 'تعذر بدء استعادة قاعدة الظل.';

    function deriveReadiness(ov, maint) {
        const counts = (ov && ov.job_counts) || {};
        const lock = (ov && ov.restore_lock) || {};
        const m = maint || (ov && ov.maintenance) || {};
        if (m.maintenance_active || m.active) {
            return { key: 'maintenance', label: 'الصيانة مفعّلة', tone: 'failed' };
        }
        if (lock.held) {
            return { key: 'blocked', label: 'محظور', tone: 'failed' };
        }
        if (Number(counts.awaiting_owner_approval || 0) > 0) {
            return { key: 'waiting', label: 'بانتظار الموافقة', tone: 'warning' };
        }
        if (Number(counts.active_in_progress || 0) > 0) {
            return { key: 'running', label: 'يلزم التحقق', tone: 'running' };
        }
        if (Number(counts.failed_jobs || 0) > 0 && Number(counts.active_in_progress || 0) === 0
            && Number(counts.awaiting_owner_approval || 0) === 0) {
            return { key: 'validation', label: 'يلزم التحقق', tone: 'warning' };
        }
        return { key: 'ready', label: 'النظام جاهز', tone: 'success' };
    }

    function renderOverview(data) {
        const ov = data.overview || {};
        state.lastOverview = ov;
        const counts = ov.job_counts || {};
        const cards = [
            ['إجمالي مهام الاسترداد', String(counts.total_jobs ?? 0)],
            ['بانتظار موافقة المالك', String(counts.awaiting_owner_approval ?? 0)],
            ['معتمد للدمج', String(counts.approved_for_merge ?? 0)],
            ['نشطة / قيد التنفيذ', String(counts.active_in_progress ?? 0)],
            ['فاشلة', String(counts.failed_jobs ?? 0)],
            ['مكتملة', String(counts.completed_jobs ?? 0)],
            ['تم التراجع عنها', String(counts.rolled_back_jobs ?? 0)]
        ];
        el('rc_overview').innerHTML = cards.map(([t, v]) =>
            '<article class="rc-op-card"><h3>' + t + '</h3><div class="rc-op-rows"><div class="rc-op-row"><dt>العدد</dt><dd>' + v + '</dd></div></div></article>'
        ).join('');
        const lock = ov.restore_lock || {};
        const maint = ov.maintenance || {};
        el('rc_lock_maintenance').innerHTML =
            '<div><dt>قفل الاسترداد العام</dt><dd>' + (lock.held ? badge('مقفل — ' + (lock.job_id || '')) : badge('متاح')) + '</dd></div>' +
            '<div><dt>وضع الصيانة</dt><dd>' + (maint.active ? badge('مفعّل — ' + (maint.job_id || '')) : badge('غير مفعّل')) + '</dd></div>';
        // Header badge/headline are owned by renderGuidedWorkflow (operator path), not overview counts.
        void deriveReadiness(ov, state.lastMaintenance || maint);
    }

    function packageExpandedActions(pkg, type) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        // Sole entry point for Package Information + operational inspection tools. Owner 2026-07-23
        let html = '<div class="rc-action-row">'
            + '<button type="button" class="btn-link rc-btn-primary rc-pkg-detail" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '">معلومات الحزمة</button>'
            + '</div>';
        html += '<p class="rc-ops-label">أدوات تشغيلية</p>';
        html += '<p class="rc-ops-hint">مساحة فحص تشغيلية — البيان والصحة وتقرير الاسترداد والتحقق. معلومات الحزمة من الزر أعلاه فقط.</p>';
        let secondary = '';
        const files = type === 'full_disaster'
            ? [['manifest.json', 'البيان'], ['health.json', 'الصحة'], ['recovery_validation.json', 'تقرير الاسترداد']]
            : [['manifest.json', 'البيان'], ['health.json', 'الصحة'], ['country_verify_report.json', 'التحقق'], ['country_recovery_validation.json', 'تقرير استرداد الدولة']];
        files.forEach(([file, label]) => {
            secondary += '<button type="button" class="btn-link rc-btn-ghost rc-view-file" data-type="' + type + '" data-id="' + id + '" data-cc="' + cc + '" data-file="' + file + '">' + label + '</button> ';
        });
        // Package-level dry-run omitted from guided path — dry validation is the job step after create.
        html += '<div class="rc-action-row rc-action-row--secondary">' + secondary + '</div>';
        return html;
    }

    /**
     * Exact package identity (Owner Step-1):
     * Full: full_disaster||<package_id>
     * Country: country_recovery|<UPPERCASE_CC>|<package_id>
     */
    function normalizePackageCc(cc) {
        return String(cc || '').trim().toUpperCase();
    }
    function packageKey(type, id, cc) {
        const t = String(type || '');
        const pid = String(id || '').trim();
        if (t === 'country_recovery') {
            return 'country_recovery|' + normalizePackageCc(cc) + '|' + pid;
        }
        return 'full_disaster||' + pid;
    }
    function packageEligibilityStatus(pkg) {
        return String((pkg && (pkg.eligibility_status || pkg.restore_eligibility)) || '');
    }
    function canCreateForPackageType(type) {
        if (type === 'full_disaster') return !!CAN_FULL;
        if (type === 'country_recovery') return !!CAN_COUNTRY;
        return false;
    }
    function findPackageByKey(key) {
        const k = String(key || '');
        const lists = [state.full || [], state.country || []];
        for (let li = 0; li < lists.length; li++) {
            for (let i = 0; i < lists[li].length; i++) {
                const p = lists[li][i];
                const t = String(p.package_type || (li === 0 ? 'full_disaster' : 'country_recovery'));
                if (packageKey(t, p.package_id || '', p.country_code || '') === k) {
                    return { pkg: p, type: t };
                }
            }
        }
        return null;
    }
    /** Cards never expose Create — upper Step-1 action only. */
    function packagePrimaryAction(pkg, type) {
        return '';
    }

    /** Inspection-only helpers (not wizard primary path). */
    function packageActions(pkg, type) {
        return packageExpandedActions(pkg, type);
    }

    function emptyStateHtml(title, body) {
        return '<div class="rc-empty"><h4>' + esc(title) + '</h4><p>' + esc(body) + '</p></div>';
    }

    function packageAccordionHtml(pkg, type) {
        const identity = String(pkg.package_id || '').trim();
        const cc = String(pkg.country_code || '');
        const key = packageKey(type, identity, cc);
        const whenHtml = fmtPackageWhenDisplay(pkg, type);
        const typeLabel = (type === 'country_recovery')
            ? ('نسخة دولة' + (pkg.country_name || cc
                ? (' — ' + (pkg.country_name ? String(pkg.country_name) : '') + (cc ? (' (' + String(cc).toUpperCase() + ')') : ''))
                : ''))
            : 'النسخة الشاملة';
        const idHtml = identity
            ? '<span class="rc-pkg-id" dir="ltr" title="package_id">' + esc(identity) + '</span>'
            : '';
        const status = packageEligibilityStatus(pkg);
        const eligible = status === 'eligible';
        const unresolved = status === 'unknown';
        const sel = state.selectedPackage;
        const isSelected = !!(sel && sel.key === key);
        // Details stay available for every eligibility state (visibility ≠ create permission).
        const detailBtn = identity
            ? '<button type="button" class="btn-link rc-btn-ghost rc-pkg-detail" data-type="' + esc(type) + '" data-id="' + esc(identity) + '" data-cc="' + esc(cc) + '">معلومات الحزمة</button>'
            : '';
        const actionsHtml = detailBtn
            ? ('<div class="rc-pkg-pick-actions">' + detailBtn + '</div>')
            : '';
        const stateClass = eligible ? '' : (unresolved ? ' is-unresolved' : ' is-ineligible');
        return (
            '<div class="rc-pkg-pick' + (isSelected ? ' is-selected' : '') + stateClass + '"' +
            ' role="option" tabindex="0"' +
            ' data-rc-pkg-pick="1" data-pkg-key="' + esc(key) + '" data-type="' + esc(type) + '" data-id="' + esc(identity) + '" data-cc="' + esc(cc) + '"' +
            ' data-eligible="' + (eligible ? '1' : '0') + '"' +
            ' data-eligibility="' + esc(status || 'not_eligible') + '"' +
            ' aria-selected="' + (isSelected ? 'true' : 'false') + '">' +
                '<div class="rc-pkg-pick-top">' +
                    '<span class="rc-badge rc-badge--muted">' + esc(typeLabel) + '</span>' +
                    '<span class="rc-acc-when" dir="ltr">' + whenHtml + '</span>' +
                    idHtml +
                    badge(pkg.package_status || '—') +
                    eligibilityBadge(pkg) +
                    (isSelected ? '<span class="rc-badge rc-badge--warning">محددة</span>' : '') +
                '</div>' +
                '<p class="rc-muted" style="margin:8px 0 0;font-size:.8rem;">المخطط: ' + esc(String(pkg.schema_revision || '—')) +
                ' · الخلفية: ' + esc(String(pkg.backend || '—')) +
                (pkg.country_name ? (' · ' + esc(String(pkg.country_name))) : '') +
                ((!eligible && pkg.eligibility_reason_label_ar) ? (' · ' + esc(String(pkg.eligibility_reason_label_ar))) : '') +
                '</p>' +
                actionsHtml +
            '</div>'
        );
    }

    function applyPackageSelection(type, id, cc, opts) {
        opts = opts || {};
        clearRcJourneyInlineMessage();
        const key = packageKey(type, id, cc);
        const scrollY = window.scrollY || 0;
        state.selectedPackage = {
            id: String(id || '').trim(),
            type: String(type || 'full_disaster'),
            cc: normalizePackageCc(cc),
            key: key
        };
        // Selection never POSTs / never creates a job (PACKAGE_SELECTION_TASK_MUTATION_COUNT = 0).
        const kind = (state.selectedPackage.type === 'country_recovery') ? 'country' : 'full';
        if (kind === 'full' && CAN_FULL) renderPackageList('full');
        if (kind === 'country' && CAN_COUNTRY) renderPackageList('country');
        renderGuidedWorkflow();
        try { window.scrollTo(0, scrollY); } catch (err) { /* ignore */ }
        if (opts.focus !== false) {
            const node = document.querySelector('[data-pkg-key="' + key.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]')
                || document.querySelector('[data-rc-pkg-pick="1"][data-id="' + String(id || '').replace(/"/g, '') + '"][data-type="' + String(type || '') + '"]');
            // Prefer data-pkg-key via CSS.escape when available
            let focusNode = null;
            try {
                focusNode = document.querySelector('[data-pkg-key="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
            } catch (e2) { focusNode = null; }
            if (focusNode && typeof focusNode.focus === 'function') {
                try { focusNode.focus({ preventScroll: true }); } catch (e3) { /* ignore */ }
            }
        }
    }

    function renderSelectedPackageSummary() {
        const host = el('rc_selected_summary');
        if (!host) return { canCreate: false };
        const stepIsSelect = !pickActiveJob(state.jobs || []);
        if (!stepIsSelect) {
            host.hidden = true;
            host.innerHTML = '';
            return { canCreate: false };
        }
        const sel = state.selectedPackage;
        if (!sel || !sel.key) {
            host.hidden = false;
            host.className = 'rc-selected-summary-empty';
            host.innerHTML = '<strong>لم يتم اختيار حزمة استرداد بعد.</strong><br>اختر أي حزمة من القائمة أدناه لعرض بياناتها وحالة أهليتها، ثم أنشئ مهمة استرداد إذا كانت الحزمة مؤهلة.';
            return { canCreate: false };
        }
        const found = findPackageByKey(sel.key);
        if (!found) {
            host.hidden = false;
            host.className = 'rc-selected-summary-empty';
            host.innerHTML = '<strong>لم يتم اختيار حزمة استرداد بعد.</strong><br>اختر أي حزمة من القائمة أدناه لعرض بياناتها وحالة أهليتها، ثم أنشئ مهمة استرداد إذا كانت الحزمة مؤهلة.';
            state.selectedPackage = null;
            return { canCreate: false };
        }
        const pkg = found.pkg;
        const type = found.type;
        const status = packageEligibilityStatus(pkg);
        const eligible = status === 'eligible';
        const unresolved = status === 'unknown';
        const reason = String(pkg.eligibility_reason_label_ar || '');
        const whenHtml = fmtPackageWhenDisplay(pkg, type);
        let typeText = 'النسخة الشاملة';
        let countryRow = '';
        if (type === 'country_recovery') {
            const cc = normalizePackageCc(pkg.country_code || sel.cc);
            const name = String(pkg.country_name || '').trim();
            typeText = 'نسخة دولة — ' + (name !== '' ? name : '—') + ' (' + (cc || '—') + ')';
            countryRow = '<div class="rc-sel-row"><dt>الدولة</dt><dd>' + esc((name !== '' ? name + ' ' : '') + (cc ? '(' + cc + ')' : '—')) + '</dd></div>';
        }
        let statusText = 'غير مؤهلة للاسترداد';
        let note = 'لا يمكن إنشاء مهمة استرداد من هذه الحزمة لأنها غير مؤهلة للاسترداد.';
        if (eligible) {
            statusText = 'مؤهلة للاسترداد';
            note = '';
        } else if (unresolved) {
            statusText = 'غير محسومة';
            note = 'لا يمكن إنشاء مهمة استرداد حتى تُحسم أهلية هذه الحزمة.';
        }
        const authorized = canCreateForPackageType(type);
        let canCreate = eligible && authorized;
        if (eligible && !authorized) {
            note = 'لا تملك صلاحية إنشاء مهمة استرداد لهذا النوع من الحزم.';
            canCreate = false;
        }
        host.hidden = false;
        host.className = 'rc-selected-summary';
        host.innerHTML =
            '<h4>الحزمة المحددة</h4>' +
            '<dl>' +
            '<div class="rc-sel-row"><dt>نوع النسخة</dt><dd>' + esc(typeText) + '</dd></div>' +
            countryRow +
            '<div class="rc-sel-row"><dt>معرّف الحزمة</dt><dd class="rc-ltr" dir="ltr">' + esc(String(pkg.package_id || sel.id || '—')) + '</dd></div>' +
            '<div class="rc-sel-row"><dt>تاريخ ووقت النسخة</dt><dd class="rc-ltr" dir="ltr">' + whenHtml + '</dd></div>' +
            '<div class="rc-sel-row"><dt>حالة الاسترداد</dt><dd>' + esc(statusText) + '</dd></div>' +
            ((!eligible && reason) ? ('<div class="rc-sel-row"><dt>السبب الآمن</dt><dd>' + esc(reason) + '</dd></div>') : '') +
            '</dl>' +
            (note ? ('<p class="rc-selected-summary-note">' + esc(note) + '</p>') : '');
        return { canCreate: canCreate, sel: sel, pkg: pkg, type: type };
    }

    function jobAccordionHtml(job) {
        const id = String(job.job_id || '');
        const pkgLabel = (job.package_type || '') + (job.country_code ? ' / ' + job.country_code : '') + ' / ' + (job.package_id || '—');
        const dryBadge = job.dry_run_overall_result ? ' ' + dryResultBadge(job.dry_run_overall_result) : '';
        const viewBtn = '<button type="button" class="btn-link rc-btn-ghost rc-fw-view" data-id="' + id + '">عرض التفاصيل</button>';
        const diagBtn = (CAN_FULL && (job.package_type === 'full_disaster' || !job.package_type))
            ? ' <button type="button" class="btn-link rc-btn-ghost rc-orch-diag" data-id="' + id + '">تشخيص تشغيل المهمة</button>'
            : '';
        // History is reference-only — never drives wizard step / primary CTA.
        return (
            '<details class="rc-acc-item" data-rc-acc="job" data-job-id="' + esc(id) + '">' +
            '<summary>' +
                '<span class="rc-acc-chevron" aria-hidden="true"></span>' +
                '<span class="rc-acc-title"><code>' + esc(id) + '</code></span>' +
                '<span class="rc-acc-meta">' +
                    '<span class="rc-acc-when" dir="ltr">' + fmtTimestampDisplay(job.created_at, 'generated_at') + '</span>' +
                    badge(job.status) + dryBadge +
                    '<span class="rc-muted">' + esc(statusLabelAr(job.phase || job.status || '—')) + ' · ' + esc(String(job.progress ?? 0)) + '%</span>' +
                '</span>' +
                '<span class="rc-acc-actions-inline">' + viewBtn + diagBtn + '</span>' +
            '</summary>' +
            '<div class="rc-acc-body">' +
                '<p class="rc-muted" style="margin:0 0 8px;font-size:.82rem;">الحزمة: ' + esc(pkgLabel) + '</p>' +
                '<p style="margin:0;font-size:.86rem;">' + esc(operatorJobMessage(job.message || '—')) + '</p>' +
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

    /**
     * Current wizard journey job: resumable/non-terminal only.
     * Never falls back to newest/cancelled/completed historical jobs (Owner 2026-07-24).
     */
    function pickActiveJob(jobs) {
        if (isResumableJob(state.currentJourneyJob)) {
            const id = String(state.currentJourneyJob.job_id || '');
            const list = Array.isArray(jobs) ? jobs : [];
            const match = list.find((j) => String(j.job_id || '') === id);
            if (match && isResumableJob(match)) return match;
            if (isResumableJob(state.currentJourneyJob)) return state.currentJourneyJob;
        }
        const list = Array.isArray(jobs) ? jobs : [];
        return list.find((j) => isResumableJob(j)) || null;
    }

    /** Owner guided restore path (presentation order). Shadow files included — required by existing gates. */
    const GUIDED_STEPS = [
        { key: 'select_package', title: 'اختيار حزمة الاسترداد' },
        { key: 'create_job', title: 'إنشاء مهمة استرداد' },
        { key: 'dry_validation', title: 'التحقق التشغيلي' },
        { key: 'prepare_plan', title: 'إعداد خطة الاسترداد' },
        { key: 'final_approval', title: 'الموافقة النهائية' },
        { key: 'pre_backup', title: 'النسخة الاحتياطية الإلزامية قبل الاسترداد' },
        { key: 'shadow_restore', title: 'استعادة قاعدة الظل' },
        { key: 'shadow_verify', title: 'تحقق قاعدة الظل' },
        { key: 'shadow_files', title: 'استخراج ملفات الظل' },
        { key: 'shadow_smoke', title: 'اختبارات الجاهزية المعزولة' },
        { key: 'maintenance', title: 'تفعيل الصيانة' },
        { key: 'pca', title: 'تفويض تحويل الإنتاج' },
        { key: 'prod_import', title: 'استيراد قاعدة الإنتاج' },
        { key: 'uploads', title: 'تحويل ملفات الرفع' },
        { key: 'finalize', title: 'إنهاء الاسترداد' },
        { key: 'completed', title: 'اكتمل الاسترداد' }
    ];

    function guidedBtn(cls, attrs, label, primary, opts) {
        opts = opts || {};
        const disabled = !!opts.disabled;
        const a = Object.keys(attrs || {}).map((k) => k + '="' + esc(String(attrs[k])) + '"').join(' ');
        let c = primary ? ('btn-link rc-btn-primary ' + cls) : ('btn-link rc-btn-ghost ' + cls);
        if (disabled) c += ' rc-stage-action-busy';
        const dis = disabled
            ? ' disabled aria-disabled="true" aria-busy="true" title="التنفيذ جارٍ — انتظر انتهاء العملية"'
            : '';
        return '<button type="button" class="' + c + '" ' + a + dis + '>' + esc(label) + '</button>';
    }

    function statusLooksBusy(status, needles) {
        const s = String(status || '').toLowerCase();
        return (needles || []).some(function (n) { return s.indexOf(String(n).toLowerCase()) !== -1; });
    }

    function stIncludes(job, part) {
        return String((job && job.status) || '').toLowerCase().includes(part);
    }

    function isRunningish(job, prefix) {
        const s = String((job && job.status) || '').toLowerCase();
        return s === prefix + '_pending' || s === prefix + '_running' || s === prefix + '_verifying'
            || (prefix === 'shadow_restore' && (s === 'shadow_restore_pending' || s === 'shadow_restore_running' || s === 'shadow_restore_verifying'))
            || (prefix === 'rollback' && s.includes('rollback') && !s.includes('finaliz'));
    }

    /**
     * Authoritative guided-step rank (mirrors orange_restore_fw_guided_status_rank).
     * NEVER uses progress%, list index, optimistic UI, or has_* artifact presence.
     * RESTORE_CENTER_JOURNEY_HYDRATION_STATE_AUTHORITY_01
     * Freeze: APPROVED_CREATE_BUTTON_POSITION_CHANGED=0; APPROVED_STEP1_BEHAVIOR_CHANGED=0; APPROVED_MOBILE_ORDER_CHANGED=0
     */
    function guidedStatusAuthorityRank(status) {
        const s = String(status || '').toLowerCase();
        const R = {
            queued: 10, preparing: 10,
            waiting_confirmation: 20, dry_running: 20, dry_failed: 20,
            dry_completed: 30,
            execution_precheck: 35, execution_plan_ready: 40, awaiting_final_approval: 40,
            approved_waiting_execution: 50,
            pre_restore_backup_pending: 55, pre_restore_backup_running: 55,
            pre_restore_backup_verifying: 55, pre_restore_backup_failed: 55,
            pre_restore_backup_ready: 60,
            shadow_restore_pending: 65, shadow_restore_running: 65,
            shadow_restore_verifying: 65, shadow_restore_failed: 65,
            shadow_restore_ready: 70,
            shadow_verifying: 75, shadow_not_ready: 75,
            shadow_verified: 80,
            shadow_files_running: 85, shadow_files_verifying: 85, shadow_files_failed: 85,
            shadow_files_ready: 90,
            shadow_smoke_pending: 95, shadow_smoke_running: 95, shadow_smoke_failed: 95,
            cutover_readiness_blocked: 95,
            shadow_smoke_ready: 100, shadow_smoke_warning: 100,
            cutover_readiness_ready: 100, cutover_readiness_manual_review: 100,
            maintenance_requested: 105, maintenance_validating: 105,
            maintenance_active: 110,
            production_import_pending: 125, production_import_running: 125,
            production_import_verifying: 125, production_import_failed: 125,
            production_import_ready: 130,
            uploads_cutover_pending: 135, uploads_cutover_running: 135,
            uploads_cutover_verifying: 135, uploads_cutover_failed: 135,
            uploads_cutover_ready: 140,
            rollback_pending: 145, rollback_database_running: 145, rollback_database_verifying: 145,
            rollback_files_running: 145, rollback_files_verifying: 145, rollback_failed: 145,
            rollback_ready: 148,
            restore_finalizing: 150, rollback_finalizing: 150,
            restore_completed: 160, rollback_completed: 160, execution_completed: 160, completed: 160,
            cancelled: 0, failed: 0, execution_cancelled: 0, execution_failed: 0
        };
        return Object.prototype.hasOwnProperty.call(R, s) ? R[s] : null;
    }

    /** Terminal-success thresholds — step is done only at/after these ranks. */
    const GUIDED_DONE_RANK = {
        dry: 30, plan: 40, approved: 50, backup: 60, shadowDb: 70,
        shadowVerify: 80, shadowFiles: 90, smoke: 100, maint: 110,
        pca: 120, import: 130, uploads: 140
    };

    /**
     * Resolve single current guided step + one primary action (UI only).
     * @returns {{current:number,states:string[],blockReason:string,body:string,primaryHtml:string,secondaryHtml:string,showPackages:boolean,showJob:boolean}}
     */
    function resolveGuidedWorkflow(job, packages) {
        const pkgs = Array.isArray(packages) ? packages : [];
        const eligible = pkgs.filter((p) => (p.eligibility_status || p.restore_eligibility) === 'eligible');
        const n = GUIDED_STEPS.length;
        const states = new Array(n).fill('locked');
        let current = 0;
        let blockReason = '';
        let body = '';
        let primaryHtml = '';
        let secondaryHtml = '';
        let showPackages = true;
        let showJob = false;

        const markDone = (idx) => { for (let i = 0; i <= idx && i < n; i++) states[i] = 'done'; };
        const setCurrent = (idx, msg) => {
            current = idx;
            for (let i = 0; i < idx; i++) if (states[i] !== 'blocked') states[i] = 'done';
            states[idx] = 'current';
            for (let i = idx + 1; i < n; i++) states[i] = 'locked';
            body = msg || '';
        };

        // Terminal/historical jobs never drive the wizard — treat as empty journey.
        if (job && isTerminalJob(job)) {
            job = null;
        }

        if (!job) {
            showPackages = true;
            showJob = false;
            // Normalize selection key; do not auto-create tasks. Sole eligible package may be pre-selected for convenience.
            if (!state.selectedPackage && eligible.length === 1) {
                const p = eligible[0];
                const pickType = (String(p.package_type || '') === 'country_recovery')
                    ? 'country_recovery'
                    : 'full_disaster';
                state.selectedPackage = {
                    id: String(p.package_id || ''),
                    type: pickType,
                    cc: normalizePackageCc(p.country_code || ''),
                    key: packageKey(pickType, p.package_id || '', p.country_code || '')
                };
            } else if (state.selectedPackage && !state.selectedPackage.key) {
                state.selectedPackage.key = packageKey(
                    state.selectedPackage.type,
                    state.selectedPackage.id,
                    state.selectedPackage.cc
                );
            }

            if (!pkgs.length) {
                setCurrent(0, 'ابدأ من هنا: لا توجد حزم بعد. أنشئ نسخة احتياطية ثم حدّث الحالة.');
                blockReason = 'لا حزم متاحة في السياق الحالي.';
                states[0] = 'blocked';
                primaryHtml = '';
            } else if (!state.selectedPackage || !state.selectedPackage.key) {
                setCurrent(0, 'اختر أي حزمة من القائمة أدناه لعرض بياناتها وحالة أهليتها، ثم أنشئ مهمة استرداد إذا كانت الحزمة مؤهلة.');
                primaryHtml = '';
            } else {
                const found = findPackageByKey(state.selectedPackage.key);
                const st = found ? packageEligibilityStatus(found.pkg) : '';
                const authorized = found ? canCreateForPackageType(found.type) : false;
                if (found && st === 'eligible' && authorized) {
                    setCurrent(0, 'الحزمة محددة ومؤهلة. اضغط «إنشاء مهمة استرداد» لبدء رحلة استرداد جديدة.');
                    primaryHtml = guidedBtn('rc-create-job', {
                        'data-type': state.selectedPackage.type,
                        'data-id': state.selectedPackage.id,
                        'data-cc': state.selectedPackage.cc || '',
                        'data-pkg-key': state.selectedPackage.key
                    }, 'إنشاء مهمة استرداد', true);
                } else if (found && st === 'eligible' && !authorized) {
                    setCurrent(0, 'الحزمة محددة ومؤهلة، لكن لا تتوفر صلاحية إنشاء مهمة لهذا النوع.');
                    primaryHtml = '';
                } else if (found && st === 'unknown') {
                    setCurrent(0, 'الحزمة محددة وحالة أهليتها غير محسومة — راجع الملخص أدناه.');
                    primaryHtml = '';
                } else if (found) {
                    setCurrent(0, 'الحزمة محددة وغير مؤهلة للاسترداد — راجع الملخص أدناه.');
                    primaryHtml = '';
                } else {
                    setCurrent(0, 'اختر أي حزمة من القائمة أدناه لعرض بياناتها وحالة أهليتها، ثم أنشئ مهمة استرداد إذا كانت الحزمة مؤهلة.');
                    primaryHtml = '';
                    state.selectedPackage = null;
                }
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }

        showPackages = false;
        showJob = true;
        const id = job.job_id || '';
        secondaryHtml = guidedBtn('rc-fw-view', { 'data-id': id }, 'عرض تفاصيل المهمة', false);
        if (CAN_FULL && (job.package_type === 'full_disaster' || !job.package_type)) {
            secondaryHtml += guidedBtn('rc-orch-diag', { 'data-id': id }, 'تشخيص التشغيل', false);
        }

        // Completed happy path
        if (job.is_restore_completed || job.is_execution_finished || String(job.status || '') === 'restore_completed') {
            markDone(n - 1);
            states[n - 1] = 'done';
            current = n - 1;
            body = 'اكتمل مسار الاسترداد. الصيانة تُطلق عبر خطوة الإنهاء إن لم تكن قد أُطلقت.';
            primaryHtml = '';
            if (job.has_finalize || job.is_restore_completed) {
                secondaryHtml += guidedBtn('rc-finalize-view', { 'data-id': id }, 'عرض حالة الإنهاء', false);
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }

        // Rollback branch — guided as blocked finalize with rollback action
        if (job.rollback_requestable || job.status === 'rollback_pending' || job.is_rollback_failed
            || (stIncludes(job, 'rollback') && !stIncludes(job, 'finaliz') && !job.is_rollback_completed)) {
            markDone(13); // through uploads conceptually may vary
            setCurrent(14, 'مسار التراجع نشط. نفّذ أو تابع التراجع ثم الإنهاء حسب الحالة.');
            if (job.rollback_requestable) {
                primaryHtml = guidedBtn('rc-rollback-req', { 'data-id': id }, 'تنفيذ التراجع الإنتاجي', true);
            } else if (job.is_rollback_failed) {
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'rollback' }, 'متابعة التراجع', true);
            } else if (job.status === 'rollback_pending' || (stIncludes(job, 'rollback') && !job.is_rollback_completed && !job.finalize_requestable)) {
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'rollback' }, 'متابعة التراجع', true, { disabled: true });
            } else if (job.finalize_requestable || job.status === 'rollback_finalizing') {
                setCurrent(14, 'التراجع جاهز للإنهاء.');
                if (job.status === 'rollback_finalizing') {
                    primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'finalize' }, 'متابعة الإنهاء', true, { disabled: true });
                } else {
                    primaryHtml = guidedBtn('rc-finalize-req', { 'data-id': id }, 'تنفيذ الإنهاء / إطلاق الصيانة', true);
                }
            } else {
                blockReason = 'التراجع قيد التنفيذ. حدّث الصفحة بعد اكتمال المرحلة.';
                states[14] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }

        // Step progression for happy path
        markDone(1); // job exists => package selected + created
        states[0] = 'done';
        states[1] = 'done';

        // Authority: prefer server guided_journey; else matrix rank. Never treat has_* pending as done.
        const authRank = guidedStatusAuthorityRank(job.status);
        const gj = (job.guided_journey && typeof job.guided_journey === 'object') ? job.guided_journey : null;
        if (authRank === null && !(gj && gj.unknown === false)) {
            setCurrent(2, 'حالة المهمة غير معروفة للنظام. حدّث الصفحة أو راجع التشخيص.');
            blockReason = 'حالة غير معروفة — لن تُعرض الخطوة كمكتملة.';
            states[2] = 'blocked';
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        const rank = (gj && typeof gj.rank === 'number') ? gj.rank : (authRank === null ? -1 : authRank);
        const dryDone = rank >= GUIDED_DONE_RANK.dry;
        const planDone = rank >= GUIDED_DONE_RANK.plan;
        const approved = rank >= GUIDED_DONE_RANK.approved;
        // Step 6 terminal-success ONLY: ready rank / is_pre_restore_backup_ready / later — NOT has_pre_restore_backup (true on pending).
        const backupDone = !!(
            rank >= GUIDED_DONE_RANK.backup
            || job.is_pre_restore_backup_ready
            || job.shadow_restore_requestable
        );
        const shadowDbDone = rank >= GUIDED_DONE_RANK.shadowDb || !!job.is_shadow_restore_ready;
        const shadowVerifyDone = rank >= GUIDED_DONE_RANK.shadowVerify || job.status === 'shadow_verified';
        const shadowFilesDone = rank >= GUIDED_DONE_RANK.shadowFiles || job.status === 'shadow_files_ready';
        const smokeDone = rank >= GUIDED_DONE_RANK.smoke;
        const maintActive = !!(job.is_maintenance_active) || rank >= GUIDED_DONE_RANK.maint;
        const maintReady = !!(job.is_maintenance_ready || job.maintenance_activatable);
        // PCA: maintenance_active alone is NOT enough; need authorization flag or import-stage rank.
        const pcaDone = !!(job.production_cutover_authorized) || rank >= GUIDED_DONE_RANK.pca;
        const importDone = rank >= GUIDED_DONE_RANK.import || !!job.is_production_import_ready;
        const uploadsDone = rank >= GUIDED_DONE_RANK.uploads || !!job.is_uploads_cutover_ready;

        if (!dryDone && job.dry_run_available) {
            setCurrent(2, 'نفّذ التحقق التشغيلي للمهمة. هذا هو الإجراء التالي الوحيد.');
            primaryHtml = guidedBtn('rc-dry-run', { 'data-job': id }, 'تنفيذ التحقق التشغيلي', true);
            if (job.has_dry_run_report) secondaryHtml += guidedBtn('rc-dry-report', { 'data-id': id }, 'عرض تقرير التحقق', false);
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        if (!dryDone && String(job.status || '') === 'dry_running') {
            setCurrent(2, 'التحقق التشغيلي جارٍ. انتظر ثم حدّث الصفحة.');
            blockReason = 'التحقق قيد التنفيذ — لا يوجد زر إضافي الآن.';
            states[2] = 'blocked';
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        if (!dryDone) {
            setCurrent(2, 'بانتظار توفر التحقق التشغيلي.');
            blockReason = 'التحقق التشغيلي غير متاح بعد لهذه المهمة.';
            states[2] = 'blocked';
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[2] = 'done';

        if (!planDone && job.prepare_execution_available) {
            setCurrent(3, 'أعد خطة الاسترداد. هذا هو الإجراء التالي الوحيد.');
            primaryHtml = guidedBtn('rc-prepare-exec', { 'data-id': id }, 'إعداد خطة الاسترداد', true);
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        if (!planDone) {
            setCurrent(3, 'بانتظار إعداد الخطة.');
            blockReason = 'إعداد الخطة غير متاح بعد. أكمل التحقق التشغيلي أولاً.';
            states[3] = 'blocked';
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[3] = 'done';
        if (job.has_execution_plan) secondaryHtml += guidedBtn('rc-exec-plan', { 'data-id': id }, 'عرض خطة الاسترداد', false);

        if (!approved && job.final_approval_available) {
            setCurrent(4, 'قدّم الموافقة النهائية على الخطة. هذا هو الإجراء التالي الوحيد.');
            primaryHtml = guidedBtn('rc-final-approve', { 'data-id': id, 'data-pkg': job.package_id || '' }, 'الموافقة النهائية', true);
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        if (!approved) {
            setCurrent(4, 'بانتظار الموافقة النهائية.');
            blockReason = 'الموافقة النهائية غير متاحة بعد. أكمل إعداد الخطة أولاً.';
            states[4] = 'blocked';
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[4] = 'done';

        if (!backupDone) {
            if (job.pre_restore_backup_requestable) {
                setCurrent(5, 'نفّذ النسخة الاحتياطية الإلزامية قبل الاسترداد. لا تُعتبر هذه الخطوة مكتملة قبل جهوزية النسخة.');
                primaryHtml = guidedBtn('rc-pre-backup-req', { 'data-id': id }, 'تنفيذ النسخة الاحتياطية الإلزامية قبل الاسترداد', true);
            } else if (job.is_pre_restore_backup_failed) {
                let failBody = 'فشل إعداد النسخة الاحتياطية. أعد التنفيذ ثم حدّث الحالة.';
                const mappedFail = operatorJobMessage(job.message || '');
                if (mappedFail && mappedFail !== 'تحديث حالة مهمة الاسترداد.') {
                    failBody = mappedFail;
                }
                setCurrent(5, failBody);
                primaryHtml = guidedBtn('rc-pre-backup-req', { 'data-id': id }, 'متابعة النسخة الاحتياطية', true);
            } else if (job.status === 'pre_restore_backup_pending'
                || stIncludes(job, 'pre_restore_backup_running')
                || stIncludes(job, 'pre_restore_backup_verifying')) {
                // Server-authoritative busy: grey disabled action; Step7 remains locked.
                if (stIncludes(job, 'pre_restore_backup_verifying')) {
                    setCurrent(5, 'اكتمل إنشاء النسخة، وجارٍ التحقق من جاهزيتها للاسترداد.');
                } else if (job.status === 'pre_restore_backup_pending') {
                    setCurrent(5, 'طُلبت النسخة الاحتياطية وما زالت قيد التنفيذ عبر محرك Full Backup المعتمد.');
                } else {
                    setCurrent(5, 'جارٍ تنفيذ النسخة الاحتياطية الإلزامية.\nستظل هذه الخطوة قيد التنفيذ حتى تصبح النسخة جاهزة.');
                }
                primaryHtml = guidedBtn(
                    'rc-pre-backup-req',
                    { 'data-id': id },
                    'متابعة النسخة الاحتياطية',
                    true,
                    { disabled: true }
                );
            } else {
                setCurrent(5, 'بانتظار النسخة الاحتياطية الإلزامية.');
                blockReason = 'خطوة النسخة الاحتياطية غير جاهزة للتنفيذ بعد.';
                states[5] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[5] = 'done';

        if (!shadowDbDone) {
            // Step 7 — one authoritative control → request-shadow-restore.php (atomic schedule).
            // RESTORE_CENTER_STEP7_ONE_BROWSER_REQUEST_01
            // RESTORE_CENTER_STEP7_INTERNAL_WORKER_REQUIRED_01
            if (job.shadow_restore_requestable) {
                setCurrent(6, 'نفّذ استعادة قاعدة الظل.');
                primaryHtml = guidedBtn('rc-shadow-req', { 'data-id': id }, 'تنفيذ استعادة قاعدة الظل', true);
            } else if (job.is_shadow_restore_failed) {
                setCurrent(6, 'تابع استعادة قاعدة الظل.');
                primaryHtml = guidedBtn('rc-shadow-req', { 'data-id': id }, 'إعادة محاولة استعادة قاعدة الظل', true);
            } else if (job.status === 'shadow_restore_pending'
                || stIncludes(job, 'shadow_restore_running')
                || stIncludes(job, 'shadow_restore_verifying')) {
                setCurrent(6, 'استعادة الظل جارية. انتظر ثم حدّث.');
                primaryHtml = guidedBtn('rc-shadow-req', { 'data-id': id }, 'استعادة قاعدة الظل قيد التنفيذ', true, { disabled: true });
            } else {
                setCurrent(6, 'بانتظار استعادة قاعدة الظل.');
                blockReason = 'استعادة الظل غير متاحة بعد. أكمل النسخة الاحتياطية أولاً.';
                states[6] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[6] = 'done';

        if (!shadowVerifyDone) {
            if (job.shadow_verification_runnable) {
                setCurrent(7, 'نفّذ تحقق قاعدة الظل.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'shadow_verify' }, 'تنفيذ تحقق قاعدة الظل', true);
            } else if (job.status === 'shadow_verifying') {
                setCurrent(7, 'تحقق الظل جارٍ. انتظر ثم حدّث.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'shadow_verify' }, 'تنفيذ تحقق قاعدة الظل', true, { disabled: true });
            } else if (job.status === 'shadow_not_ready') {
                setCurrent(7, 'قاعدة الظل غير جاهزة.');
                blockReason = 'التحقق أظهر أن الظل غير جاهز. راجع التقرير ثم أعد المحاولة عند التوفر.';
                states[7] = 'blocked';
                secondaryHtml += guidedBtn('rc-shadow-verify-view', { 'data-id': id }, 'عرض تحقق الجاهزية', false);
            } else {
                setCurrent(7, 'بانتظار تحقق قاعدة الظل.');
                blockReason = 'تحقق الظل غير متاح بعد.';
                states[7] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[7] = 'done';

        if (!shadowFilesDone) {
            if (job.shadow_files_runnable) {
                setCurrent(8, 'نفّذ استخراج ملفات الظل.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'shadow_files' }, 'تنفيذ استخراج ملفات الظل', true);
            } else if (stIncludes(job, 'shadow_files_running') || stIncludes(job, 'shadow_files_verifying')) {
                setCurrent(8, 'استخراج ملفات الظل جارٍ. انتظر ثم حدّث.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'shadow_files' }, 'تنفيذ استخراج ملفات الظل', true, { disabled: true });
            } else if (job.status === 'shadow_files_failed') {
                setCurrent(8, 'فشل استخراج ملفات الظل — أعد التنفيذ.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'shadow_files' }, 'إعادة استخراج ملفات الظل', true);
            } else {
                setCurrent(8, 'بانتظار استخراج ملفات الظل.');
                blockReason = 'استخراج ملفات الظل غير متاح بعد. أكمل تحقق الظل أولاً.';
                states[8] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[8] = 'done';

        if (!smokeDone) {
            if (job.shadow_smoke_requestable) {
                setCurrent(9, 'نفّذ اختبارات الجاهزية المعزولة.');
                primaryHtml = guidedBtn('rc-shadow-smoke-req', { 'data-id': id }, 'تنفيذ اختبارات الجاهزية المعزولة', true);
            } else if (job.status === 'shadow_smoke_failed') {
                setCurrent(9, 'تابع اختبارات الجاهزية.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'shadow_smoke' }, 'متابعة اختبارات الجاهزية', true);
            } else if (job.status === 'shadow_smoke_pending' || stIncludes(job, 'shadow_smoke_running')) {
                setCurrent(9, 'اختبارات الجاهزية جارية. انتظر ثم حدّث.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'shadow_smoke' }, 'متابعة اختبارات الجاهزية', true, { disabled: true });
            } else {
                setCurrent(9, 'بانتظار اختبارات الجاهزية.');
                blockReason = 'اختبارات الجاهزية غير متاحة بعد.';
                states[9] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[9] = 'done';

        if (!maintActive) {
            if (job.maintenance_requestable && !maintReady) {
                setCurrent(10, 'اطلب تفعيل الصيانة.');
                primaryHtml = guidedBtn('rc-maint-req', { 'data-id': id }, 'طلب تفعيل الصيانة', true);
            } else if (maintReady || job.maintenance_activatable) {
                setCurrent(10, 'فعّل الصيانة الآن. استرداد الإنتاج لم يبدأ بعد.');
                primaryHtml = guidedBtn('rc-maint-activate', { 'data-id': id }, 'تفعيل الصيانة', true);
            } else {
                setCurrent(10, 'بانتظار الصيانة.');
                blockReason = 'طلب/تفعيل الصيانة غير متاح بعد. أكمل اختبارات الجاهزية أولاً.';
                states[10] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[10] = 'done';

        if (!pcaDone) {
            setCurrent(11, 'فوض تحويل الإنتاج قبل استيراد قاعدة الإنتاج. استرداد الإنتاج لم يبدأ بعد.');
            primaryHtml = guidedBtn('rc-pca-authorize', { 'data-id': id, 'data-pkg': job.package_id || '' }, 'تفويض تحويل الإنتاج', true);
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[11] = 'done';

        if (!importDone) {
            if (job.production_import_requestable) {
                setCurrent(12, 'نفّذ استيراد قاعدة الإنتاج.');
                primaryHtml = guidedBtn('rc-prod-import-req', { 'data-id': id }, 'تنفيذ استيراد قاعدة الإنتاج', true);
            } else if (job.is_production_import_failed) {
                setCurrent(12, 'تابع استيراد الإنتاج.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'production_import' }, 'متابعة استيراد الإنتاج', true);
            } else if (job.status === 'production_import_pending'
                || stIncludes(job, 'production_import_running')
                || stIncludes(job, 'production_import_verifying')) {
                setCurrent(12, 'استيراد الإنتاج جارٍ. انتظر ثم حدّث.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'production_import' }, 'متابعة استيراد الإنتاج', true, { disabled: true });
            } else {
                setCurrent(12, 'بانتظار استيراد قاعدة الإنتاج.');
                blockReason = 'الاستيراد غير متاح. أكمل تفويض تحويل الإنتاج والصيانة النشطة أولاً.';
                states[12] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[12] = 'done';

        if (!uploadsDone) {
            if (job.uploads_cutover_requestable) {
                setCurrent(13, 'نفّذ تحويل ملفات الرفع.');
                primaryHtml = guidedBtn('rc-uploads-cutover-req', { 'data-id': id }, 'تنفيذ تحويل ملفات الرفع', true);
            } else if (job.is_uploads_cutover_failed) {
                setCurrent(13, 'تابع تحويل الرفع.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'uploads_cutover' }, 'متابعة تحويل الرفع', true);
            } else if (job.status === 'uploads_cutover_pending'
                || stIncludes(job, 'uploads_cutover_running')
                || stIncludes(job, 'uploads_cutover_verifying')) {
                setCurrent(13, 'تحويل الرفع جارٍ. انتظر ثم حدّث.');
                primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'uploads_cutover' }, 'متابعة تحويل الرفع', true, { disabled: true });
            } else {
                setCurrent(13, 'بانتظار تحويل ملفات الرفع.');
                blockReason = 'تحويل الرفع غير متاح بعد. أكمل استيراد الإنتاج أولاً.';
                states[13] = 'blocked';
            }
            return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
        }
        states[13] = 'done';

        if (job.finalize_requestable) {
            setCurrent(14, 'أنهِ الاسترداد وأطلق الصيانة.');
            primaryHtml = guidedBtn('rc-finalize-req', { 'data-id': id }, 'تنفيذ الإنهاء / إطلاق الصيانة', true);
        } else if (job.status === 'restore_finalizing' || job.status === 'rollback_finalizing') {
            setCurrent(14, 'تابع الإنهاء.');
            primaryHtml = guidedBtn('rc-run-worker', { 'data-id': id, 'data-worker': 'finalize' }, 'متابعة الإنهاء', true, { disabled: true });
        } else {
            setCurrent(14, 'بانتظار الإنهاء.');
            blockReason = 'الإنهاء غير متاح بعد. أكمل تحويل الرفع أولاً.';
            states[14] = 'blocked';
        }
        return { current, states, blockReason, body, primaryHtml, secondaryHtml, showPackages, showJob };
    }

    function isPkgEligible(pkg) {
        return (pkg && (pkg.eligibility_status || pkg.restore_eligibility) === 'eligible');
    }

    /**
     * Visible packages for a Step 1 tab (Owner 2026-07-24):
     * show eligible + non-eligible. Country scope / Full global already applied by API.
     * Eligibility only controls selection — not visibility.
     */
    function packagesForKind(kind) {
        return kind === 'country' ? (state.country || []) : (state.full || []);
    }

    function activePackageKind() {
        if (CAN_FULL && CAN_COUNTRY) {
            const countryTab = el('rc_tab_country');
            return (countryTab && countryTab.classList.contains('is-active')) ? 'country' : 'full';
        }
        if (CAN_COUNTRY && !CAN_FULL) return 'country';
        return 'full';
    }

    function resetPackageListModes() {
        state.packageListMode = { full: 'latest5', country: 'latest5' };
    }

    /**
     * Step 1 list modes (Backup Center terminology + pattern):
     * order = country/package scope (server) → newest-first (server) → then latest-5 slice (client).
     * Non-eligible packages stay visible in both modes.
     */
    function renderPackageList(kind) {
        const isAll = state.packageListMode[kind] === 'all';
        const source = packagesForKind(kind);
        const items = isAll ? source : source.slice(0, PKG_RECENT_LIMIT);
        const type = kind === 'country' ? 'country_recovery' : 'full_disaster';
        const listEl = kind === 'country' ? el('rc_country_list') : el('rc_full_list');
        const hintEl = kind === 'country' ? el('rc_country_list_hint') : el('rc_full_list_hint');
        const viewAllBtn = kind === 'country' ? el('rc_view_all_country_btn') : el('rc_view_all_full_btn');
        const backBtn = kind === 'country' ? el('rc_back_latest_country_btn') : el('rc_back_latest_full_btn');
        if (!listEl) return;

        listEl.removeAttribute('aria-busy');
        listEl.setAttribute('data-rc-pkg-mode', isAll ? 'all' : 'latest5');
        if (!source.length) {
            listEl.innerHTML = kind === 'country'
                ? emptyStateHtml('لا توجد حزم دول', 'لا توجد حزم دولة ضمن السياق الحالي.')
                : emptyStateHtml('لا توجد حزم كاملة', 'بعد إنشاء النسخة الكاملة ستظهر الحزم هنا.');
        } else {
            listEl.innerHTML = items.map((p) => packageAccordionHtml(p, type)).join('');
        }

        if (hintEl) {
            if (isAll) {
                hintEl.textContent = kind === 'country'
                    ? ('السجل الكامل للدولة الحالية: ' + source.length)
                    : ('السجل الكامل: ' + source.length);
            } else {
                hintEl.textContent = 'آخر ' + Math.min(PKG_RECENT_LIMIT, source.length) + ' عمليات';
            }
        }
        if (viewAllBtn) viewAllBtn.hidden = isAll || source.length <= PKG_RECENT_LIMIT;
        if (backBtn) backBtn.hidden = !isAll;

        const pill = el('rc_pkg_mode_pill');
        if (pill && activePackageKind() === kind) {
            pill.textContent = isAll
                ? ('السجل الكامل (' + source.length + ')')
                : ('آخر العمليات (' + Math.min(PKG_RECENT_LIMIT, source.length) + ')');
            pill.classList.add('is-active');
        }
    }

    function setPackageListMode(kind, mode) {
        state.packageListMode[kind] = (mode === 'all') ? 'all' : 'latest5';
        renderPackageList(kind);
        renderGuidedWorkflow();
    }

    /** Visible packages for active tab (eligible + non-eligible). Wizard CTA still filters eligible. */
    function wizardPackageList() {
        return packagesForKind(activePackageKind());
    }

    function renderGuidedWorkflow() {
        const job = pickActiveJob(state.jobs || []);
        const pkgs = wizardPackageList();
        const g = resolveGuidedWorkflow(job, pkgs);
        const stepsEl = el('rc_guide_steps');
        if (stepsEl) {
            stepsEl.innerHTML = GUIDED_STEPS.map((step, idx) => {
                const st = g.states[idx] || 'locked';
                let mark = '🔒';
                let cls = 'is-locked';
                if (st === 'done') { mark = '✔'; cls = 'is-done'; }
                if (st === 'current') { mark = '▶'; cls = 'is-current'; }
                if (st === 'blocked') { mark = '!'; cls = 'is-blocked'; }
                return '<li class="rc-guide-step ' + cls + '"><span class="rc-guide-mark" aria-hidden="true">' + mark + '</span><span><strong>' + (idx + 1) + '.</strong> ' + esc(step.title) + '</span></li>';
            }).join('');
        }
        const step = GUIDED_STEPS[g.current] || GUIDED_STEPS[0];
        const doneAll = (g.states[g.current] === 'done' && step.key === 'completed');
        if (el('rc_wizard_stepnum')) {
            el('rc_wizard_stepnum').textContent = doneAll
                ? 'اكتملت الرحلة'
                : ('الخطوة ' + (g.current + 1) + ' من ' + GUIDED_STEPS.length);
        }
        if (el('rc_guide_kicker')) {
            const runningishStep6 = !!(job && !g.blockReason && (
                stIncludes(job, 'pre_restore_backup_running')
                || stIncludes(job, 'pre_restore_backup_verifying')
                || (isRunningish(job, 'pre_restore_backup') && g.current === 5)
            ));
            el('rc_guide_kicker').textContent = doneAll
                ? 'النتيجة'
                : (g.blockReason ? '! محظور الآن' : (runningishStep6 ? '▶ جارٍ التنفيذ' : '▶ مطلوب الآن'));
        }
        if (el('rc_guide_title')) el('rc_guide_title').textContent = step.title;
        if (el('rc_guide_body')) el('rc_guide_body').textContent = g.body || '';
        const block = el('rc_guide_block');
        if (block) {
            if (g.blockReason) {
                block.hidden = false;
                block.textContent = 'محظور: ' + g.blockReason;
            } else {
                block.hidden = true;
                block.textContent = '';
            }
        }
        if (el('rc_guide_primary')) el('rc_guide_primary').innerHTML = g.primaryHtml || '';
        // Cancel LEFT on same workflow-card action row — visibility from framework job.cancellable only
        // (orange_restore_fw_cancellable_statuses / pre-production boundary). No UI status allowlist.
        // Reuses existing rc-fw-cancel → confirm → job/cancel.php.
        if (el('rc_guide_cancel')) {
            const canCancel = !!(job && job.cancellable && String(job.job_id || '').trim() !== '');
            el('rc_guide_cancel').innerHTML = canCancel
                ? '<button type="button" class="btn-link rc-fw-cancel" data-id="' + esc(String(job.job_id || '')) + '">إلغاء المهمة</button>'
                : '';
        }
        if (el('rc_guide_secondary')) el('rc_guide_secondary').innerHTML = g.secondaryHtml || '';
        const more = document.querySelector('#rc_guided_root .rc-wizard-more');
        if (more) more.hidden = !String(g.secondaryHtml || '').trim();
        const wsPkg = el('rc_ws_packages');
        const wsJob = el('rc_ws_job');
        if (wsPkg) wsPkg.hidden = !g.showPackages;
        if (wsJob) wsJob.hidden = !g.showJob;
        if (el('rc_readiness_badge')) {
            const b = el('rc_readiness_badge');
            b.className = 'rc-badge ' + (g.blockReason ? 'rc-badge--failed' : (doneAll ? 'rc-badge--success' : 'rc-badge--warning'));
            b.textContent = doneAll ? 'مكتمل' : ('الخطوة ' + (g.current + 1) + ' من ' + GUIDED_STEPS.length);
        }
        if (el('rc_readiness_headline')) el('rc_readiness_headline').textContent = step.title;
        state.guidedAllowCreateJob = false;
        renderSelectedPackageSummary();
        document.querySelectorAll('.rc-pkg-pick').forEach((node) => {
            const key = node.getAttribute('data-pkg-key') || '';
            const on = !!(state.selectedPackage && state.selectedPackage.key === key);
            node.classList.toggle('is-selected', on);
            node.setAttribute('aria-selected', on ? 'true' : 'false');
            // Keep badge text in sync without full rebuild when only aria toggled.
            let badgeSel = node.querySelector('.rc-badge.rc-badge--warning');
            if (on) {
                if (!badgeSel || badgeSel.textContent !== 'محددة') {
                    if (badgeSel) badgeSel.textContent = 'محددة';
                    else {
                        const top = node.querySelector('.rc-pkg-pick-top');
                        if (top) top.insertAdjacentHTML('beforeend', '<span class="rc-badge rc-badge--warning">محددة</span>');
                    }
                }
            } else if (badgeSel && badgeSel.textContent === 'محددة') {
                badgeSel.remove();
            }
        });
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
        setChip('maint', m.maintenance_active ? 'مفعّلة' : (m.maintenance_ready ? 'جاهزة' : statusLabelAr(m.state || 'بانتظار')), !!(m.maintenance_active || m.maintenance_ready || st.includes('maintenance')));
        setChip('import', st.includes('production_import') ? statusLabelAr(active.status || 'running') : 'بانتظار', st.includes('production_import'));
        setChip('uploads', st.includes('uploads_cutover') ? statusLabelAr(active.status || 'running') : 'بانتظار', st.includes('uploads_cutover'));
        setChip('rollback', (st.includes('rollback') && !st.includes('finaliz')) ? statusLabelAr(active.status || 'running') : 'بانتظار', st.includes('rollback') && !st.includes('finaliz'));
        setChip('finalize', (st.includes('finaliz') || st.includes('completed')) ? statusLabelAr((active && active.status) || 'completed') : 'بانتظار', st.includes('finaliz') || !!(active && (active.is_restore_completed || active.is_rollback_completed)));

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
        const list = Array.isArray(jobs) ? jobs : [];
        const job = pickActiveJob(list);
        if (!box || !meta || !acts) return;
        if (!job) {
            box.hidden = true;
            meta.innerHTML = '';
            acts.innerHTML = '';
            if (el('rc_mon_active')) el('rc_mon_active').textContent = '—';
            if (el('rc_mon_phase')) el('rc_mon_phase').textContent = '—';
            if (el('rc_mon_progress')) el('rc_mon_progress').textContent = '—';
            return;
        }
        box.hidden = false;
        meta.innerHTML =
            '<span><strong>المهمة</strong> <code>' + esc(job.job_id || '') + '</code></span>' +
            '<span>' + badge(job.status) + '</span>' +
            '<span><strong>المرحلة</strong> ' + esc(statusLabelAr(job.phase || job.status || '—')) + '</span>' +
            '<span><strong>التقدم</strong> ' + esc(String(job.progress ?? 0)) + '%</span>' +
            '<span class="rc-muted">' + esc(operatorJobMessage(job.message || '')) + '</span>';
        // Guided workflow owns the single primary action — do not dump stage CTAs here.
        acts.innerHTML = '';
        if (el('rc_mon_jobs')) el('rc_mon_jobs').textContent = String(list.length);
        if (el('rc_mon_active')) el('rc_mon_active').textContent = job.job_id || '—';
        if (el('rc_mon_phase')) el('rc_mon_phase').textContent = statusLabelAr(job.phase || job.status || '—');
        if (el('rc_mon_progress')) el('rc_mon_progress').textContent = String(job.progress ?? 0) + '%';
    }

    function renderHistory(jobs) {
        const hist = el('rc_history');
        const listEl = el('rc_jobs_list');
        const list = Array.isArray(jobs) ? jobs : [];
        // History = terminal/non-resumable only — never controls wizard journey.
        const historical = list.filter((j) => isTerminalJob(j));
        if (!hist || !listEl) return;
        if (!historical.length) {
            hist.hidden = true;
            listEl.innerHTML = '';
            listEl.removeAttribute('aria-busy');
            return;
        }
        hist.hidden = false;
        listEl.removeAttribute('aria-busy');
        listEl.innerHTML = historical.map((j) => jobAccordionHtml(j)).join('');
        if (el('rc_jobs_table') && el('rc_jobs_table').querySelector('tbody')) {
            el('rc_jobs_table').querySelector('tbody').innerHTML = historical.map((j) => {
                const pkgLabel = (j.package_type || '') + (j.country_code ? ' / ' + j.country_code : '') + ' / ' + (j.package_id || '—');
                const dryBadge = j.dry_run_overall_result ? ' ' + dryResultBadge(j.dry_run_overall_result) : '';
                return '<tr><td><code>' + j.job_id + '</code></td><td>' + pkgLabel + '</td><td class="rc-ts-cell">' + fmtTimestampDisplay(j.created_at, 'generated_at') + '</td><td>' + badge(j.status) + dryBadge + '</td><td>' + esc(statusLabelAr(j.phase || j.status || '—')) + '</td><td>' + String(j.progress ?? 0) + '%</td><td>' + esc(operatorJobMessage(j.message || '—')) + '</td><td class="rc-actions">' + jobActions(j) + '</td></tr>';
            }).join('');
        }
    }

    function renderTables(data) {
        const nextCtx = String(data.country_context_code || COUNTRY_CONTEXT_CODE || '');
        // Country context change or fresh load → default Step 1 list mode to latest 5.
        if (nextCtx !== String(state.countryContextCode || '')) {
            resetPackageListModes();
        }
        state.countryContextCode = nextCtx;
        state.full = data.full_packages || [];
        state.country = data.country_packages || [];
        state.jobs = data.framework_jobs || data.jobs || [];
        state.currentJourneyJob = (data.current_journey_job && isResumableJob(data.current_journey_job))
            ? data.current_journey_job
            : pickActiveJob(state.jobs);
        if (data.csrf_token) state.csrf = data.csrf_token;

        if (CAN_FULL) renderPackageList('full');
        if (CAN_COUNTRY) renderPackageList('country');
        renderHistory(state.jobs);
        renderActiveJob(state.jobs);
        updateStageStrip(state.lastMaintenance || data.maintenance || {}, state.jobs);
        renderGuidedWorkflow();
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
        push('الملخص', {
            job_id: obj.job_id || obj.id || undefined,
            status: obj.status || obj.status_label || undefined,
            package_id: obj.package_id || (obj.package && obj.package.package_id) || undefined,
            phase: obj.phase || undefined,
            progress: obj.progress,
            message: obj.message || obj.warning || undefined
        });
        push('التحقق', obj.validation || obj.dry_run || obj.report || obj.cutover_readiness || undefined);
        push('التشغيل', {
            highest_checkpoint: obj.highest_checkpoint,
            production_touched: obj.production_touched,
            execution_started: obj.execution_started,
            files_switched: obj.files_switched,
            rollback_executed: obj.rollback_executed
        });
        push('البيان / الصحة / تقرير الاسترداد', obj.manifest || obj.health || obj.drv || obj.package || undefined);
        push('السجل / الخط الزمني', obj.timeline || obj.checkpoint_history || obj.artifacts || undefined);
        push('بيانات الحزمة', obj.meta || obj.record || obj.contract || obj.maintenance || undefined);
        if (!sections.length) return '';
        return sections.join('');
    }

    function openView(title, content) {
        el('rc_view_title').textContent = title;
        const note = el('rc_view_tz_note');
        if (note) {
            if (!hasDisplayTz()) {
                note.textContent = 'تحذير: المنطقة الزمنية للدولة غير مضبوطة — عُرض النص الخام دون تحويل.';
            } else {
                note.textContent = 'التواريخ في هذا العرض بالتوقيت المحلي (' + DISPLAY_TZ + ') بنظام 12 ساعة.';
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
        openRcModal('rc_view_modal');
    }

    function renderJobDetail(job) {
        const lines = [];
        lines.push('معرّف المهمة: ' + job.job_id);
        lines.push('الحالة: ' + statusLabelAr(job.status));
        lines.push('بصمة الحزمة: ' + ((job.package || {}).checksum || '—'));
        lines.push('بصمة بيان التحضير: ' + ((job.staging || {}).manifest_checksum || '—'));
        lines.push('بصمة نقطة ارتكاز التراجع: ' + ((job.rollback_anchor || {}).checksum || '—'));
        lines.push('حالة الموافقة: ' + ((job.approval || {}).status || '—'));
        lines.push('استُهلكت الموافقة: ' + (((job.approval || {}).token_consumed) ? 'نعم' : 'لا'));
        lines.push('الصيانة مفعّلة: ' + (((job.maintenance || {}).active) ? 'نعم' : 'لا'));
        lines.push('القفل ممسوك: ' + (((job.lock || {}).held) ? 'نعم' : 'لا'));
        lines.push('اكتمل تحويل القاعدة: ' + ((job.database_cutover || {}).completed_at || '—'));
        lines.push('اكتمل تحويل الرفع: ' + ((job.uploads_cutover || {}).completed_at || '—'));
        lines.push('ما بعد التحقق: ' + ((job.post_validation || {}).passed_at || '—'));
        lines.push('\n--- الخط الزمني ---');
        (job.timeline || []).forEach((t) => {
            lines.push((t.at || '') + '  ' + (t.event || '') + (t.result ? ' (' + t.result + ')' : ''));
        });
        lines.push('\n--- نقاط تحقق التراجع ---');
        lines.push(JSON.stringify(job.rollback_checkpoints || {}, null, 2));
        return lines.join('\n');
    }

    function openJobDetail(job) {
        el('rc_detail_title').textContent = 'تفاصيل المهمة — ' + job.job_id;
        const structured = renderStructuredDetail(job);
        const raw = localizeTimestampsInText(renderJobDetail(job));
        el('rc_detail_body').innerHTML =
            (structured || '') +
            '<div class="rc-drawer-group"><h4>التفاصيل الكاملة</h4><pre class="rc-pre">' + esc(raw) + '</pre></div>';
        openRcModal('rc_detail_modal');
    }

    function renderMaintenance(m) {
        const st = m || {};
        state.lastMaintenance = st;
        if (!el('rc_maint_status')) return;
        let label = statusLabelAr(st.label || st.state || 'inactive');
        if (st.maintenance_active) label = 'الصيانة مفعّلة';
        else if (st.maintenance_ready) label = 'الصيانة جاهزة';
        el('rc_maint_status').innerHTML =
            '<div><dt>الحالة</dt><dd>' + badge(st.state || 'inactive') + '</dd></div>' +
            '<div><dt>الملصق</dt><dd><strong>' + label + '</strong></dd></div>' +
            '<div><dt>المهمة المرتبطة</dt><dd>' + (st.related_job_id || '—') + '</dd></div>' +
            '<div><dt>وقت الطلب</dt><dd class="rc-ts-cell">' + fmtTimestampDisplay(st.requested_at) + '</dd></div>' +
            '<div><dt>وقت التفعيل</dt><dd class="rc-ts-cell">' + fmtTimestampDisplay(st.activated_at) + '</dd></div>' +
            '<div><dt>آخر نبضة</dt><dd class="rc-ts-cell">' + fmtTimestampDisplay(st.heartbeat_at) + '</dd></div>' +
            '<div><dt>انتهاء الصلاحية</dt><dd>' + (st.stale ? badge('منتهية الصلاحية — لا إطلاق تلقائي') : badge('سارية')) + '</dd></div>';
        if (el('rc_maint_banner')) {
            el('rc_maint_banner').innerHTML = '<strong>استرداد الإنتاج لم يبدأ بعد.</strong>'
                + (st.stale ? ' <span class="rc-badge rc-badge--warning">نبضة الصيانة منتهية الصلاحية — لا إطلاق تلقائي.</span>' : '');
        }
        const scopes = Array.isArray(st.blocked_write_scopes) ? st.blocked_write_scopes.join(', ') : '';
        const warn = String(st.warning || 'استرداد الإنتاج لم يبدأ بعد.')
            .replace(/Production restore has NOT started\./gi, 'استرداد الإنتاج لم يبدأ بعد.')
            .replace(/CLI/gi, '');
        el('rc_maint_policy').textContent = 'سياسة القراءة الآمنة: ' + (st.safe_read_policy || '—')
            + (scopes ? ' | نطاقات الكتابة المحظورة عند التفعيل: ' + scopes : '')
            + ' | الإطلاق التلقائي محظور'
            + ' | ' + warn;
    }


    function jobActions(job, opts) {
        opts = opts || {};
        const id = job.job_id;
        let html = opts.omitPrimaryView
            ? ''
            : '<button type="button" class="btn-link rc-fw-view" data-id="' + id + '">عرض</button> ';
        if (CAN_FULL && (job.package_type === 'full_disaster' || !job.package_type)) {
            html += '<button type="button" class="btn-link rc-orch-diag" data-id="' + id + '">تشخيص التشغيل</button> ';
        }
        if (job.dry_run_available) {
            html += '<button type="button" class="btn-link rc-dry-run" data-job="' + id + '">تنفيذ التحقق التشغيلي</button> ';
        }
        if (job.has_dry_run_report) {
            html += '<button type="button" class="btn-link rc-dry-report" data-id="' + id + '">عرض تقرير التحقق</button> ';
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
            html += '<button type="button" class="btn-link rc-exec-contract" data-id="' + id + '">عرض عقد التنفيذ</button> ';
        }
        if (job.pre_restore_backup_requestable) {
            html += '<button type="button" class="btn-link rc-pre-backup-req" data-id="' + id + '">تنفيذ النسخة الاحتياطية الإلزامية قبل الاسترداد</button> ';
        }
        if (job.status === 'pre_restore_backup_pending' || job.is_pre_restore_backup_failed) {
            html += '<button type="button" class="btn-link rc-pre-backup-req" data-id="' + id + '">متابعة النسخة الاحتياطية</button> ';
        }
        if (job.has_pre_restore_backup) {
            html += '<button type="button" class="btn-link rc-pre-backup-view" data-id="' + id + '">عرض حالة النسخة الاحتياطية</button> ';
        }
        if (job.shadow_restore_requestable) {
            const step7Ready = !!(job.step7_action_enabled
                || job.ready_for_private_shadow_provisioning
                || job.ready_for_controlled_step7_attempt
                || job.step7_ready_token === 'READY_FOR_PRIVATE_SHADOW_PROVISIONING'
                || job.step7_ready_token === 'READY_FOR_CONTROLLED_STEP7_ATTEMPT');
            if (step7Ready) {
                html += '<button type="button" class="btn-link rc-shadow-req" data-id="' + id + '">تنفيذ استعادة قاعدة الظل</button> ';
            } else {
                html += '<button type="button" class="btn-link rc-shadow-req" data-id="' + id
                    + '" disabled aria-disabled="true" title="الجاهزية NOT_READY — لا يُنشأ طلب جديد">تنفيذ استعادة قاعدة الظل (غير جاهز)</button> ';
            }
        }
        if (job.is_shadow_restore_failed) {
            const step7RetryReady = !!(job.step7_action_enabled
                || job.ready_for_private_shadow_provisioning
                || job.ready_for_controlled_step7_attempt
                || job.step7_ready_token === 'READY_FOR_PRIVATE_SHADOW_PROVISIONING'
                || job.step7_ready_token === 'READY_FOR_CONTROLLED_STEP7_ATTEMPT');
            if (step7RetryReady) {
                html += '<button type="button" class="btn-link rc-shadow-req" data-id="' + id + '">إعادة محاولة استعادة قاعدة الظل</button> ';
            } else {
                html += '<button type="button" class="btn-link rc-shadow-req" data-id="' + id
                    + '" disabled aria-disabled="true" title="الجاهزية NOT_READY — لا يُنشأ طلب جديد">إعادة محاولة استعادة قاعدة الظل (غير جاهز)</button> ';
            }
        }
        if (job.status === 'shadow_restore_pending'
            || job.status === 'shadow_restore_running'
            || job.status === 'shadow_restore_verifying') {
            html += '<button type="button" class="btn-link rc-shadow-req" data-id="' + id + '" disabled aria-disabled="true">استعادة قاعدة الظل قيد التنفيذ</button> ';
        }
        if (job.has_shadow_restore) {
            html += '<button type="button" class="btn-link rc-shadow-view" data-id="' + id + '">عرض تقرير قاعدة الظل</button> ';
        }
        if (job.shadow_verification_runnable) {
            html += '<button type="button" class="btn-link rc-run-worker" data-id="' + id + '" data-worker="shadow_verify">تنفيذ تحقق قاعدة الظل</button> ';
        }
        if (job.shadow_verification_runnable || job.has_shadow_verification) {
            html += '<button type="button" class="btn-link rc-shadow-verify-view" data-id="' + id + '">عرض تحقق الجاهزية</button> ';
        }
        if (job.shadow_files_runnable) {
            html += '<button type="button" class="btn-link rc-run-worker" data-id="' + id + '" data-worker="shadow_files">تنفيذ استخراج ملفات الظل</button> ';
        }
        if (job.shadow_files_runnable || job.has_shadow_files) {
            html += '<button type="button" class="btn-link rc-shadow-files-view" data-id="' + id + '">عرض ملفات الظل</button> ';
        }
        if (job.shadow_smoke_requestable) {
            html += '<button type="button" class="btn-link rc-shadow-smoke-req" data-id="' + id + '">تنفيذ اختبارات الجاهزية المعزولة</button> ';
        }
        if (job.status === 'shadow_smoke_pending') {
            html += '<button type="button" class="btn-link rc-run-worker" data-id="' + id + '" data-worker="shadow_smoke">متابعة اختبارات الجاهزية</button> ';
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
            html += '<span class="rc-badge rc-badge--warning">الصيانة جاهزة</span> ';
            html += '<strong class="muted">استرداد الإنتاج لم يبدأ بعد.</strong> ';
        }
        if (job.is_maintenance_active) {
            html += '<span class="rc-badge rc-badge--failed">الصيانة مفعّلة</span> ';
            html += '<strong>استرداد الإنتاج لم يبدأ بعد.</strong> ';
            html += '<button type="button" class="btn-link rc-btn-primary rc-pca-authorize" data-id="' + id + '" data-pkg="' + (job.package_id || '') + '">تفويض تحويل الإنتاج</button> ';
        }
        if (job.is_maintenance_ready || job.is_maintenance_active || job.maintenance_requestable) {
            html += '<button type="button" class="btn-link rc-maint-state" data-id="' + id + '">عرض حالة الصيانة</button> ';
        }
        if (job.production_import_requestable) {
            html += '<button type="button" class="btn-link rc-prod-import-req" data-id="' + id + '">تنفيذ استيراد قاعدة الإنتاج</button> ';
        }
        if (job.status === 'production_import_pending' || job.is_production_import_failed) {
            html += '<button type="button" class="btn-link rc-run-worker" data-id="' + id + '" data-worker="production_import">متابعة استيراد الإنتاج</button> ';
        }
        if (job.has_production_import || job.is_production_import_ready || job.is_production_import_failed) {
            html += '<button type="button" class="btn-link rc-prod-import-view" data-id="' + id + '">عرض حالة استيراد الإنتاج</button> ';
        }
        if (job.status === 'production_import_pending') {
            html += '<span class="rc-badge rc-badge--warning">استيراد الإنتاج معلّق</span> ';
        }
        if (job.status === 'production_import_running') {
            html += '<span class="rc-badge rc-badge--warning">جارٍ</span> ';
        }
        if (job.status === 'production_import_verifying') {
            html += '<span class="rc-badge rc-badge--warning">جارٍ التحقق</span> ';
        }
        if (job.is_production_import_ready) {
            html += '<span class="rc-badge rc-badge--success">جاهز</span> ';
        }
        if (job.is_production_import_failed) {
            html += '<span class="rc-badge rc-badge--failed">فشل</span> ';
        }
        if (job.production_import_requestable || job.has_production_import || job.is_production_import_ready || job.is_production_import_failed) {
            html += '<strong class="muted">ملفات التطبيق لم تُبدَّل بعد.</strong> ';
        }
        if (job.uploads_cutover_requestable) {
            html += '<button type="button" class="btn-link rc-uploads-cutover-req" data-id="' + id + '">تنفيذ تحويل ملفات الرفع</button> ';
        }
        if (job.status === 'uploads_cutover_pending' || job.is_uploads_cutover_failed) {
            html += '<button type="button" class="btn-link rc-run-worker" data-id="' + id + '" data-worker="uploads_cutover">متابعة تحويل الرفع</button> ';
        }
        if (job.has_uploads_cutover || job.is_uploads_cutover_ready || job.is_uploads_cutover_failed) {
            html += '<button type="button" class="btn-link rc-uploads-cutover-view" data-id="' + id + '">عرض حالة تحويل الرفع</button> ';
        }
        if (job.status === 'uploads_cutover_pending') {
            html += '<span class="rc-badge rc-badge--warning">تحويل الرفع معلّق</span> ';
        }
        if (job.status === 'uploads_cutover_running') {
            html += '<span class="rc-badge rc-badge--warning">جارٍ</span> ';
        }
        if (job.status === 'uploads_cutover_verifying') {
            html += '<span class="rc-badge rc-badge--warning">جارٍ التحقق</span> ';
        }
        if (job.is_uploads_cutover_ready) {
            html += '<span class="rc-badge rc-badge--success">جاهز</span> ';
        }
        if (job.is_uploads_cutover_failed) {
            html += '<span class="rc-badge rc-badge--failed">فشل</span> ';
        }
        if (job.uploads_cutover_requestable || job.has_uploads_cutover || job.is_uploads_cutover_ready || job.is_uploads_cutover_failed) {
            html += '<strong class="muted">الصيانة ما زالت مفعّلة. الاسترداد لم يكتمل.</strong> ';
        }
        if (job.rollback_requestable) {
            html += '<button type="button" class="btn-link rc-rollback-req" data-id="' + id + '">تنفيذ التراجع الإنتاجي</button> ';
        }
        if (job.status === 'rollback_pending' || job.is_rollback_failed) {
            html += '<button type="button" class="btn-link rc-run-worker" data-id="' + id + '" data-worker="rollback">متابعة التراجع</button> ';
        }
        if (job.has_rollback || job.is_rollback_ready || job.is_rollback_failed) {
            html += '<button type="button" class="btn-link rc-rollback-view" data-id="' + id + '">عرض حالة التراجع</button> ';
        }
        if (job.status === 'rollback_pending') {
            html += '<span class="rc-badge rc-badge--warning">التراجع معلّق</span> ';
        }
        if (job.status === 'rollback_database_running' || job.status === 'rollback_database_verifying') {
            html += '<span class="rc-badge rc-badge--warning">تراجع القاعدة</span> ';
        }
        if (job.status === 'rollback_files_running' || job.status === 'rollback_files_verifying') {
            html += '<span class="rc-badge rc-badge--warning">تراجع الملفات</span> ';
        }
        if (job.is_rollback_ready) {
            html += '<span class="rc-badge rc-badge--success">التراجع جاهز</span> ';
        }
        if (job.is_rollback_failed) {
            html += '<span class="rc-badge rc-badge--failed">فشل التراجع</span> ';
        }
        if (job.rollback_requestable || job.has_rollback || job.is_rollback_ready || job.is_rollback_failed) {
            html += '<strong class="muted">الصيانة ما زالت مفعّلة. الاسترداد لم يكتمل. نقطة الارتكاز محفوظة.</strong> ';
        }
        if (job.finalize_requestable) {
            html += '<button type="button" class="btn-link rc-finalize-req" data-id="' + id + '">تنفيذ الإنهاء / إطلاق الصيانة</button> ';
        }
        if (job.status === 'restore_finalizing' || job.status === 'rollback_finalizing') {
            html += '<button type="button" class="btn-link rc-run-worker" data-id="' + id + '" data-worker="finalize">متابعة الإنهاء</button> ';
        }
        if (job.has_finalize || job.is_restore_completed || job.is_rollback_completed) {
            html += '<button type="button" class="btn-link rc-finalize-view" data-id="' + id + '">عرض حالة الإنهاء</button> ';
        }
        if (job.status === 'restore_finalizing' || job.status === 'rollback_finalizing') {
            html += '<span class="rc-badge rc-badge--warning">جارٍ الإنهاء</span> ';
        }
        if (job.is_restore_completed) {
            html += '<span class="rc-badge rc-badge--success">اكتمل الاسترداد</span> ';
        }
        if (job.is_rollback_completed) {
            html += '<span class="rc-badge rc-badge--success">اكتمل التراجع</span> ';
        }
        if (job.is_maintenance_released) {
            html += '<span class="rc-badge rc-badge--success">أُطلقت الصيانة</span> ';
        }
        if (job.is_execution_finished) {
            html += '<span class="rc-badge rc-badge--success">انتهى التنفيذ</span> ';
        }
        if (job.execution_plan_cancellable) {
            html += '<button type="button" class="btn-link rc-cancel-exec" data-id="' + id + '">إلغاء الخطة</button> ';
        }
        if (job.cancellable) {
            html += '<button type="button" class="btn-link rc-fw-cancel" data-id="' + id + '">إلغاء</button>';
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
                '<div><dt>الاسترداد الكامل</dt><dd>لا يوجد تقرير</dd></div>' +
                '<div><dt>تحقق التراجع</dt><dd>—</dd></div>' +
                '<div><dt>العزل</dt><dd>—</dd></div>' +
                '<div><dt>مرجع الاختبار</dt><dd>—</dd></div>' +
                '<div><dt>تاريخ الاختبار</dt><dd>—</dd></div>' +
                '<div><dt>استرداد الدولة</dt><dd>غير معتمد للإنتاج</dd></div>';
            if (blockersEl) {
                const msg = (cert && cert.message)
                    ? String(cert.message).replace(/CLI/gi, '').replace(/drill/gi, 'تحقق').trim()
                    : 'أكمل التحقق التشغيلي على بيئة معزولة أولاً.';
                blockersEl.textContent = msg;
            }
            return;
        }
        const recRaw = String(cert.production_execution_recommendation || 'NOT_CERTIFIED');
        const rec = recRaw === 'NOT_CERTIFIED' ? 'غير معتمد' : (recRaw === 'CERTIFIED' ? 'معتمد' : recRaw);
        const full = cert.full_restore_certified ? ('معتمد (' + rec + ')') : ('غير معتمد — ' + rec);
        box.innerHTML =
            '<div><dt>الاسترداد الكامل</dt><dd>' + full + '</dd></div>' +
            '<div><dt>تحقق التراجع</dt><dd>' + (cert.rollback_drill_ok ? 'ناجح' : 'فشل / غير متوفر') + '</dd></div>' +
            '<div><dt>العزل</dt><dd>' + (cert.production_isolation_ok ? 'مثبت' : 'غير مثبت') + '</dd></div>' +
            '<div><dt>مرجع الاختبار</dt><dd>' + (cert.tested_commit || '—') + '</dd></div>' +
            '<div><dt>تاريخ الاختبار</dt><dd class="rc-ts-cell">' + (cert.tested_at ? fmtTimestampDisplay(cert.tested_at, 'generated_at') : '—') + '</dd></div>' +
            '<div><dt>استرداد الدولة</dt><dd>غير معتمد للإنتاج</dd></div>';
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

    function refreshErrorMessage(err) {
        const raw = String((err && err.message) || '').trim();
        const cat = String((err && err.refresh_error_category) || '').trim();
        if (cat === 'refresh_auth') {
            return 'تعذر تحديث الحالة بسبب جلسة أو صلاحية. أعد تسجيل الدخول ثم حدّث.';
        }
        if (cat === 'refresh_work_root') {
            return 'تعذر تحديث الحالة لأن مجلد عمل الاسترداد غير متاح.';
        }
        if (cat === 'refresh_step7_state') {
            return 'تعذر مزامنة حالة خطوة استعادة قاعدة الظل. حدّث مرة أخرى دون إلغاء المهمة.';
        }
        if (!raw || raw === 'حدث خطأ غير متوقع' || raw === 'تعذر تنفيذ العملية.') {
            return 'تعذر تحديث حالة مركز الاسترداد. أعد المحاولة دون إلغاء المهمة.';
        }
        return raw;
    }

    async function loadAll() {
        setBusy(true, 'جاري تحميل البيانات…');
        try {
            const data = await apiGet('list.php');
            if (!data.read_only) {
                showRcTerminalMessage('تحذير: الاستجابة ليست للقراءة فقط.', false);
            }
            // Server-authoritative button state after refresh/rerender.
            rcStageActionLocks.clear();
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
            showRcTerminalMessage(refreshErrorMessage(e), false, null, 'refresh:' + String((e && e.refresh_error_category) || 'refresh_unexpected'));
        } finally {
            setBusy(false);
        }
    }

    document.addEventListener('click', async (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLElement)) return;

        const isStageMutation = RC_STAGE_MUTATION_CLASSES.some(function (c) {
            return t.classList.contains(c);
        });
        if (isStageMutation) {
            if (t.disabled || t.getAttribute('aria-disabled') === 'true' || t.classList.contains('rc-stage-action-busy')) {
                ev.preventDefault();
                ev.stopPropagation();
                return;
            }
            const lock = beginStageActionLock(t);
            if (!lock.ok) {
                ev.preventDefault();
                ev.stopPropagation();
                showRcJourneyInlineMessage('هذه المرحلة قيد التنفيذ بالفعل. لن يُرسل طلب مكرر.');
                return;
            }
            t.setAttribute('data-rc-lock-key', lock.key || '');
        }

        if (t.id === 'rc_refresh_btn') {
            await loadAll();
            return;
        }
        if (t.id === 'rc_orch_diag_close') {
            closeRcModal('rc_orch_diag_modal');
            return;
        }
        if (t.classList.contains('rc-orch-diag')) {
            try {
                await openOrchestratorDiagnostics(t.dataset.id || '');
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر فتح تشخيص التشغيل');
            }
            return;
        }
        if (t.id === 'rc_view_close') {
            closeRcModal('rc_view_modal');
            return;
        }
        if (t.id === 'rc_detail_close') {
            closeRcModal('rc_detail_modal');
            return;
        }
        // Backdrop click closes — same as Backup Center details drawer.
        if (t.classList.contains('rc-modal-backdrop') && t.getAttribute('data-rc-modal') === '1') {
            closeRcModal(t.id);
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
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
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
                openView('معلومات الحزمة', JSON.stringify(j.package || {}, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-create-job')) {
            try {
                const type = t.dataset.type || '';
                const id = t.dataset.id || '';
                const cc = t.dataset.cc || '';
                const btnKey = t.dataset.pkgKey || packageKey(type, id, cc);
                if (state.selectedPackage && state.selectedPackage.key && state.selectedPackage.key !== btnKey) {
                    showRcTerminalMessage('تعذر إنشاء المهمة: الحزمة المحددة غير متطابقة مع زر الإنشاء.', false, t);
                    return;
                }
                if (!canCreateForPackageType(type)) {
                    showRcTerminalMessage('لا تملك صلاحية إنشاء مهمة استرداد لهذا النوع من الحزم.', false, t);
                    return;
                }
                setBusy(true, 'جاري إنشاء المهمة…');
                const j = await apiPost('job/create.php', {
                    csrf_token: state.csrf,
                    package_type: type,
                    package_id: id,
                    country_code: cc
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showRcTerminalMessage('تم إنشاء المهمة وتوقفت عند انتظار التأكيد.', true, t);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر إنشاء المهمة', false, t);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-dry-run')) {
            try {
                setBusy(true, 'جاري التحقق التشغيلي…');
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
                const overallAr = overall === 'FAIL' ? 'فشل' : (overall === 'PASS' ? 'ناجح' : (overall || 'تم'));
                showRcTerminalMessage('التحقق التشغيلي: ' + overallAr, overall === 'FAIL' ? false : true);
                if (j.report) {
                    openView('تقرير التحقق — ' + ((j.job || {}).job_id || ''), JSON.stringify(j.report, null, 2));
                }
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر التحقق التشغيلي', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-dry-report')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/dry-report.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('تقرير التحقق — ' + (t.dataset.id || ''), JSON.stringify(j.report || {}, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-fw-view')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/view.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('مهمة الاسترداد — ' + (t.dataset.id || ''), JSON.stringify(j.job || {}, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
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
                showRcTerminalMessage('تم إعداد الخطة — بانتظار الموافقة النهائية. لم يتم تنفيذ أي استرداد حتى الآن.', true);
                if (j.plan) {
                    openView('خطة الاسترداد — ' + (t.dataset.id || ''), JSON.stringify(j.plan, null, 2));
                }
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر إعداد الخطة', false);
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
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
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
                    'عقد التنفيذ — ' + (t.dataset.id || ''),
                    JSON.stringify({
                        contract: j.contract || {},
                        validation: j.validation || {},
                        execution_started: false,
                        warning: j.warning || ''
                    }, null, 2)
                );
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-pre-backup-req')) {
            const jobId = t.dataset.id || '';
            const lockKey = t.getAttribute('data-rc-lock-key') || '';
            const inflightKey = String(jobId) + '::pre_restore_backup_shared';
            if (rcScheduleInFlight.has(inflightKey)) {
                showRcTerminalMessage('تنفيذ النسخة الاحتياطية يعمل بالفعل لهذه المهمة. لن يُبدأ تنفيذ مكرر.', false);
                endStageActionLock(lockKey);
                return;
            }
            rcScheduleInFlight.add(inflightKey);
            lockStageActionControl(t);
            let ambiguous = false;
            try {
                setBusy(true, 'جاري إنشاء النسخة الاحتياطية الإلزامية عبر محرك Full Backup المعتمد…');
                const j = await apiPost('job/request-pre-restore-backup.php', {
                    csrf_token: state.csrf,
                    job_id: jobId
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                if (!j.success) {
                    throw new Error(j.message || RC_PRE_BACKUP_FAIL_MSG);
                }
                showRcTerminalMessage(j.message || RC_PRE_BACKUP_OK_MSG, true);
                endStageActionLock(lockKey);
                await loadAll();
            } catch (e) {
                const reason = (e && e.message) ? String(e.message) : '';
                const isNetwork = !!(e && (e.name === 'TypeError' || /failed to fetch|network|timeout/i.test(reason)));
                if (isNetwork) {
                    ambiguous = true;
                    await reconcileAfterStageAmbiguity(t, lockKey);
                } else {
                    showRcTerminalMessage(
                        reason && reason.indexOf('تعمل بالفعل') !== -1
                            ? reason
                            : operatorJobMessage(reason || RC_PRE_BACKUP_FAIL_MSG),
                        false
                    );
                    endStageActionLock(lockKey);
                    await loadAll();
                }
            } finally {
                rcScheduleInFlight.delete(inflightKey);
                setBusy(false);
                if (!ambiguous) {
                    // Authority after loadAll; keep busy class only if server re-rendered it.
                }
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
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-shadow-req')) {
            // One browser POST → authoritative Step-7 endpoint (server atomic request+schedule).
            // Forbidden: request-shadow-restore.php then run-worker.php chain.
            // RESTORE_CENTER_STEP7_ONE_BROWSER_REQUEST_01
            // RESTORE_CENTER_STEP7_SHADOW_DB_ISOLATION_01
            const jobId = t.dataset.id || '';
            const lockKey = t.getAttribute('data-rc-lock-key') || '';
            const inflightKey = String(jobId) + '::shadow_db';
            if (rcScheduleInFlight.has(inflightKey)) {
                showRcTerminalMessage('استعادة قاعدة الظل تعمل بالفعل لهذه المهمة. لن يُبدأ تنفيذ مكرر.', false);
                endStageActionLock(lockKey);
                return;
            }
            rcScheduleInFlight.add(inflightKey);
            lockStageActionControl(t);
            let ambiguous = false;
            try {
                setBusy(true, 'جاري بدء استعادة قاعدة الظل على الخادم…');
                const j = await apiPost('job/request-shadow-restore.php', {
                    csrf_token: state.csrf,
                    job_id: jobId
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                if (!j.success) {
                    throw new Error(j.message || RC_SHADOW_FAIL_MSG);
                }
                if (!j.scheduled && !j.idempotent) {
                    const fail = new Error(
                        (j.diagnostics && j.diagnostics.reason_ar) || j.message || RC_SHADOW_FAIL_MSG
                    );
                    fail.code = j.code || '';
                    throw fail;
                }
                showRcTerminalMessage(j.message || RC_SHADOW_SCHEDULED_MSG, true);
                endStageActionLock(lockKey);
                await loadAll();
            } catch (e) {
                const reason = (e && e.message) ? String(e.message) : '';
                const isNetwork = !!(e && (e.name === 'TypeError' || /failed to fetch|network|timeout/i.test(reason)));
                if (isNetwork) {
                    ambiguous = true;
                    await reconcileAfterStageAmbiguity(t, lockKey);
                } else {
                    const failText = reason && reason.indexOf('تعمل بالفعل') !== -1
                        ? reason
                        : operatorJobMessage(reason || RC_SHADOW_FAIL_MSG);
                    const failCode = (e && e.code) ? String(e.code) : '';
                    showRcTerminalMessage(
                        failText,
                        false,
                        null,
                        String(jobId) + '::shadow_db::' + failCode + '::' + failText
                    );
                    endStageActionLock(lockKey);
                    await loadAll();
                }
            } finally {
                rcScheduleInFlight.delete(inflightKey);
                setBusy(false);
                if (!ambiguous) {
                    // Authority after loadAll.
                }
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
                    warning: 'استعادة الظل فقط — قاعدة الإنتاج لم تُعدَّل.'
                }, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
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
                    meta: j.meta || {},
                    report: j.report || {},
                    production_touched: false,
                    execution_started: false,
                    warning: 'تحقق الظل فقط — قاعدة الإنتاج لم تُعدَّل. نفّذ التحقق من مركز الاسترداد.'
                }, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
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
                    meta: j.meta || {},
                    report: j.report || {},
                    production_touched: false,
                    directories_renamed: false,
                    execution_started: false,
                    warning: 'ملفات الظل فقط — نظام ملفات الإنتاج لم يُعدَّل. نفّذ الاستخراج من مركز الاسترداد.'
                }, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-shadow-smoke-req')) {
            try {
                await requestThenRunWorker(
                    'job/request-shadow-smoke.php',
                    'shadow_smoke',
                    t.dataset.id || '',
                    'جاري طلب اختبارات الجاهزية المعزولة…',
                    'جاري تنفيذ اختبارات الجاهزية من مركز الاسترداد…'
                );
                showRcTerminalMessage(RC_SCHEDULED_MSG, true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر تنفيذ اختبارات الجاهزية', false);
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
                    meta: j.meta || {},
                    report: j.report || {},
                    cutover_readiness: j.cutover_readiness || {},
                    production_touched: false,
                    production_cutover_allowed: false,
                    execution_started: false,
                    warning: 'لم يتم تعديل قاعدة الإنتاج أو ملفات الإنتاج، ولا يزال التحويل إلى الإنتاج غير مسموح.'
                }, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
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
                showRcTerminalMessage((j.message || 'الصيانة جاهزة') + ' — استرداد الإنتاج لم يبدأ بعد.', true);
                if (j.challenge && j.challenge.nonce) {
                    state.maintNonce = state.maintNonce || {};
                    state.maintNonce[t.dataset.id || ''] = j.challenge.nonce;
                }
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر طلب الصيانة', false);
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
                    showRcTerminalMessage('recent_authentication_not_available', false);
                    return;
                }
                setBusy(true, 'جاري تفعيل الصيانة…');
                const j = await apiPost('job/activate-maintenance.php', {
                    csrf_token: state.csrf,
                    job_id: jobId,
                    password: password,
                    nonce: nonce
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showRcTerminalMessage('الصيانة مفعّلة — استرداد الإنتاج لم يبدأ بعد.', true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر تفعيل الصيانة', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-maint-state')) {
            try {
                setBusy(true, 'جاري التحميل…');
                const j = await apiGet('job/maintenance-state.php?id=' + encodeURIComponent(t.dataset.id || ''));
                openView('حالة الصيانة — ' + (t.dataset.id || ''), JSON.stringify({
                    maintenance: j.maintenance || {},
                    job: j.job || {},
                    record: j.record || {},
                    stale: !!j.stale,
                    auto_release_forbidden: true,
                    execution_started: false,
                    restore_started: false,
                    warning: 'استرداد الإنتاج لم يبدأ بعد.'
                }, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-prod-import-req')) {
            try {
                await requestThenRunWorker(
                    'job/request-production-import.php',
                    'production_import',
                    t.dataset.id || '',
                    'جاري طلب استيراد قاعدة الإنتاج…',
                    'جاري تنفيذ استيراد قاعدة الإنتاج من مركز الاسترداد…'
                );
                showRcTerminalMessage(RC_SCHEDULED_MSG, true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر تنفيذ استيراد الإنتاج', false);
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
                        '<div><dt>الحالة</dt><dd>' + badge(j.status_label || ((j.job || {}).status) || 'بانتظار') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd>' + (j.highest_checkpoint || '<span class="rc-stage-idle">لا نشاط</span>') + '</dd></div>'
                        + '<div><dt>التنفيذ</dt><dd>من مركز الاسترداد</dd></div>';
                }
                openView('استيراد الإنتاج — ' + (t.dataset.id || ''), JSON.stringify({
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
                    warning: 'ملفات التطبيق لم تُبدَّل بعد.'
                }, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-uploads-cutover-req')) {
            try {
                await requestThenRunWorker(
                    'job/request-uploads-cutover.php',
                    'uploads_cutover',
                    t.dataset.id || '',
                    'جاري طلب تحويل ملفات الرفع…',
                    'جاري تنفيذ تحويل ملفات الرفع من مركز الاسترداد…'
                );
                showRcTerminalMessage(RC_SCHEDULED_MSG, true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر تنفيذ تحويل الرفع', false);
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
                        '<div><dt>الحالة</dt><dd>' + badge(j.status_label || ((j.job || {}).status) || 'بانتظار') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd>' + (j.highest_checkpoint || '<span class="rc-stage-idle">لا نشاط</span>') + '</dd></div>'
                        + '<div><dt>التنفيذ</dt><dd>من مركز الاسترداد</dd></div>';
                }
                openView('تحويل الرفع — ' + (t.dataset.id || ''), JSON.stringify({
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
                    warning: 'الصيانة ما زالت مفعّلة. الاسترداد لم يكتمل. التراجع لم يُنفَّذ.'
                }, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-rollback-req')) {
            try {
                await requestThenRunWorker(
                    'job/request-rollback.php',
                    'rollback',
                    t.dataset.id || '',
                    'جاري طلب التراجع الإنتاجي…',
                    'جاري تنفيذ التراجع الإنتاجي من مركز الاسترداد…'
                );
                showRcTerminalMessage(RC_SCHEDULED_MSG, true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر تنفيذ التراجع', false);
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
                        '<div><dt>الحالة</dt><dd>' + badge(j.status_label || ((j.job || {}).status) || 'بانتظار') + '</dd></div>'
                        + '<div><dt>أعلى نقطة تحقق</dt><dd>' + (j.highest_checkpoint || '<span class="rc-stage-idle">لا نشاط</span>') + '</dd></div>'
                        + '<div><dt>التنفيذ</dt><dd>من مركز الاسترداد</dd></div>';
                }
                openView('التراجع — ' + (t.dataset.id || ''), JSON.stringify({
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
                    warning: 'الصيانة ما زالت مفعّلة. الاسترداد لم يكتمل. نقطة الارتكاز محفوظة.'
                }, null, 2));
            } catch (e) {
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-finalize-req')) {
            try {
                await requestThenRunWorker(
                    'job/request-finalize.php',
                    'finalize',
                    t.dataset.id || '',
                    'جاري طلب الإنهاء…',
                    'جاري تنفيذ الإنهاء / إطلاق الصيانة من مركز الاسترداد…'
                );
                showRcTerminalMessage(RC_SCHEDULED_MSG, true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر تنفيذ الإنهاء', false);
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-run-worker')) {
            const lockKey = t.getAttribute('data-rc-lock-key') || '';
            lockStageActionControl(t);
            try {
                const worker = t.dataset.worker || '';
                await runRestoreWorker(
                    t.dataset.id || '',
                    worker,
                    'جاري تنفيذ المرحلة من مركز الاسترداد…'
                );
                showRcTerminalMessage(RC_SCHEDULED_MSG, true);
                endStageActionLock(lockKey);
                await loadAll();
            } catch (e) {
                const reason = (e.diagnostics && e.diagnostics.reason_ar) || e.message || 'تعذر تنفيذ المرحلة';
                const isNetwork = !!(e && (e.name === 'TypeError' || /failed to fetch|network|timeout/i.test(String(reason))));
                if (isNetwork) {
                    await reconcileAfterStageAmbiguity(t, lockKey);
                } else {
                    showRcTerminalMessage(reason, false);
                    endStageActionLock(lockKey);
                    await loadAll();
                    if (e.code === 'restore_center_invalid_stage'
                        || e.code === 'restore_center_worker_already_running'
                        || e.code === 'restore_center_spawn_failed'
                        || e.code === 'restore_center_worker_executable_unavailable') {
                        try {
                            await openOrchestratorDiagnostics(t.dataset.id || '');
                        } catch (ignored) { /* alert already shown */ }
                    }
                }
            } finally {
                setBusy(false);
            }
            return;
        }

        if (t.classList.contains('rc-pca-authorize')) {
            try {
                setBusy(true, 'جاري تجهيز تفويض تحويل الإنتاج…');
                const ch = await apiPost('job/create-cutover-authorization-challenge.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || ''
                });
                if (ch.csrf_token) state.csrf = ch.csrf_token;
                const challenge = ch.challenge || {};
                const phrase = challenge.required_confirmation_phrase || '';
                const typed = window.prompt(
                    'تفويض تحويل الإنتاج — أعد كتابة العبارة بالضبط.\n\nالعبارة:\n' + phrase + '\n\nالصق العبارة هنا:',
                    ''
                );
                if (typed === null) return;
                const password = window.prompt('كلمة مرور إعادة التحقق (مطلوبة):', '');
                if (password === null || password === '') {
                    showRcTerminalMessage('recent_authentication_not_available', false);
                    return;
                }
                const reason = window.prompt('سبب التفويض (8 أحرف على الأقل):', '');
                if (reason === null || String(reason).trim().length < 8) {
                    showRcTerminalMessage('authorization_reason_required', false);
                    return;
                }
                setBusy(true, 'جاري اعتماد تفويض تحويل الإنتاج…');
                const j = await apiPost('job/finalize-cutover-authorization.php', {
                    csrf_token: state.csrf,
                    job_id: t.dataset.id || '',
                    package_id: t.dataset.pkg || challenge.package_id || '',
                    confirmation_phrase: typed,
                    nonce: challenge.nonce || '',
                    password: password,
                    authorization_reason: reason
                });
                if (j.csrf_token) state.csrf = j.csrf_token;
                showRcTerminalMessage('تم تفويض تحويل الإنتاج. يمكن الآن تنفيذ استيراد قاعدة الإنتاج من مركز الاسترداد.', true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر تفويض تحويل الإنتاج', false);
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
                        '<div><dt>الحالة</dt><dd>' + badge(j.status_label || ((j.job || {}).status) || 'بانتظار') + '</dd></div>'
                        + '<div><dt>الصيانة</dt><dd>' + (j.maintenance_released ? 'أُطلقت' : 'مفعّلة') + '</dd></div>'
                        + '<div><dt>التنفيذ</dt><dd>من مركز الاسترداد</dd></div>';
                }
                openView('الإنهاء — ' + (t.dataset.id || ''), JSON.stringify({
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
                showRcJourneyInlineMessage(e.message || 'تعذر العرض');
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
                    showRcTerminalMessage('recent_authentication_not_available', false);
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
                showRcTerminalMessage(j.message || 'تم اعتماد الخطة، لكن لم يبدأ الاسترداد ولم يتم تفعيل وضع الصيانة.', true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر الاعتماد', false);
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
                showRcTerminalMessage('تم إلغاء الخطة. لم يتم تنفيذ أي استرداد.', true);
                await loadAll();
            } catch (e) {
                showRcTerminalMessage(e.message || 'تعذر إلغاء الخطة', false);
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
                // Success only: clear client journey so cancelled job cannot remain current.
                // Authoritative reload + active-job rule (resumable only) → Step 1 when none left.
                state.selectedPackage = null;
                state.currentJourneyJob = null;
                state.openedStage = '';
                state.guidedAllowCreateJob = false;
                resetPackageListModes();
                await loadAll();
                showRcTerminalMessage('تم إلغاء المهمة. يمكنك الآن اختيار حزمة استرداد جديدة.', true);
            } catch (e) {
                // Failure: keep current job and wizard step; show API/safe reason only.
                showRcTerminalMessage(e.message || 'تعذر الإلغاء', false);
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
            showRcJourneyInlineMessage('أدخل معرّف المهمة لعرض تقرير تحقق ظل الدولة.');
            return;
        }
        try {
            setBusy(true, 'جاري تحميل تحقق ظل الدولة…');
            const j = await apiGet('country-shadow-verify-status.php?job_id=' + encodeURIComponent(jobId));
            const s = j.summary || {};
            const result = String(s.overall_result || (j.report && j.report.overall_result) || '—');
            strip.innerHTML =
                '<div><dt>النتيجة</dt><dd>' + badge(result) + '</dd></div>' +
                '<div><dt>الجاهزية</dt><dd>' + String(s.readiness_score != null ? s.readiness_score : '—') + '</dd></div>' +
                '<div><dt>الدولة المستهدفة</dt><dd>' + badge(s.target_country_integrity || '—') + '</dd></div>' +
                '<div><dt>الدول الباقية</dt><dd>' + badge(s.survivor_country_integrity || '—') + '</dd></div>' +
                '<div><dt>الحالة العامة</dt><dd>' + badge(s.global_state_integrity || '—') + '</dd></div>' +
                '<div><dt>المحاسبة</dt><dd>' + badge(s.accounting_integrity || '—') + '</dd></div>' +
                '<div><dt>المخزون</dt><dd>' + badge(s.stock_fifo_integrity || '—') + '</dd></div>';
            const blockers = Array.isArray(s.blocking_reason_codes) ? s.blocking_reason_codes : [];
            const warnings = Array.isArray(s.warnings) ? s.warnings : [];
            if (blockersEl) {
                blockersEl.textContent = 'عوائق: ' + (blockers.length ? blockers.join(', ') : 'لا يوجد')
                    + ' | تحذيرات: ' + (warnings.length ? warnings.join(', ') : 'لا يوجد')
                    + ' | لم يُنفَّذ استرداد إنتاجي';
            }
            openView('تحقق ظل الدولة — ' + jobId, JSON.stringify({
                status: j.status || '',
                summary: s,
                report: j.report || null,
                execution_performed: false,
                production_db_writes: 0,
                country_production_restore_enabled: false,
                warning: 'تقرير تحقق ظل الدولة — للعرض من مركز الاسترداد.'
            }, null, 2));
        } catch (e) {
            showRcJourneyInlineMessage(e.message || 'تعذر تحميل تقرير تحقق ظل الدولة');
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
            showRcJourneyInlineMessage('أدخل معرّف المهمة لعرض تقرير محاكاة استعادة الدولة.');
            return;
        }
        try {
            setBusy(true, 'جاري تحميل محاكاة استعادة الدولة…');
            const j = await apiGet('country-dry-run-status.php?job_id=' + encodeURIComponent(jobId));
            const s = j.summary || {};
            const result = String(s.overall_result || (j.report && j.report.overall_result) || '—');
            strip.innerHTML =
                '<div><dt>النتيجة</dt><dd>' + badge(result) + '</dd></div>' +
                '<div><dt>الجداول</dt><dd>' + String(s.tables_affected_count != null ? s.tables_affected_count : '—') + '</dd></div>' +
                '<div><dt>صفوف الإضافة/الحذف</dt><dd>' + String(s.rows_to_insert != null ? s.rows_to_insert : '—') + ' / ' + String(s.rows_to_delete != null ? s.rows_to_delete : '—') + '</dd></div>' +
                '<div><dt>أثر الدول الباقية</dt><dd>' + badge(String(s.survivor_country_impact != null ? s.survivor_country_impact : '—')) + '</dd></div>' +
                '<div><dt>الأثر العام</dt><dd>' + badge(String(s.global_impact != null ? s.global_impact : '—')) + '</dd></div>' +
                '<div><dt>المدة</dt><dd>' + String(s.estimated_duration || '—') + '</dd></div>';
            const blockers = Array.isArray(s.blocking_reason_codes) ? s.blocking_reason_codes : [];
            const warnings = Array.isArray(s.warnings) ? s.warnings : [];
            if (blockersEl) {
                blockersEl.textContent = 'عوائق: ' + (blockers.length ? blockers.join(', ') : 'لا يوجد')
                    + ' | تحذيرات: ' + (warnings.length ? warnings.join(', ') : 'لا يوجد')
                    + ' | محاكاة فقط — بلا كتابة إنتاج';
            }
            openView('محاكاة استعادة الدولة — ' + jobId, JSON.stringify({
                status: j.status || '',
                summary: s,
                report: j.report || null,
                execution_performed: false,
                production_db_writes: 0,
                shadow_db_writes: 0,
                country_production_restore_enabled: false,
                warning: 'تقرير محاكاة استعادة الدولة — للعرض من مركز الاسترداد.'
            }, null, 2));
        } catch (e) {
            showRcJourneyInlineMessage(e.message || 'تعذر تحميل تقرير محاكاة استعادة الدولة');
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
        // Package accordion: open/close ONLY via chevron — never via summary, meta, or primary buttons
        const pkgDetails = t.closest('details.rc-acc-item[data-rc-acc="pkg"]');
        if (pkgDetails) {
            const onSummary = t.closest('summary');
            if (onSummary && onSummary.parentElement === pkgDetails) {
                const onChevron = !!t.closest('.rc-acc-chevron');
                if (!onChevron) {
                    // Buttons/links in header must not toggle; non-chevron summary clicks also must not toggle
                    ev.preventDefault();
                }
            }
        }
        if (t.closest('.rc-acc-actions-inline') || t.closest('.rc-action-row')) {
            if (t.closest('summary') && (t.closest('button') || t.closest('a'))) {
                ev.preventDefault();
            }
        }
        const pkgPick = t.closest('[data-rc-pkg-pick="1"]');
        if (pkgPick) {
            // Information / other controls keep their own handlers; still update selection for context.
            if (t.closest('button') || t.closest('a')) {
                applyPackageSelection(
                    pkgPick.getAttribute('data-type') || 'full_disaster',
                    pkgPick.getAttribute('data-id') || '',
                    pkgPick.getAttribute('data-cc') || '',
                    { focus: false }
                );
                return;
            }
            ev.preventDefault();
            applyPackageSelection(
                pkgPick.getAttribute('data-type') || 'full_disaster',
                pkgPick.getAttribute('data-id') || '',
                pkgPick.getAttribute('data-cc') || '',
                { focus: true }
            );
            return;
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
            // Switching package type clears selection so the wizard stays sequential.
            state.selectedPackage = null;
            if (tab === 'full' || tab === 'country') {
                renderPackageList(tab);
            }
            renderGuidedWorkflow();
            return;
        }
        const pkgKindBtn = t.closest('[data-rc-pkg-kind]');
        if (pkgKindBtn && (pkgKindBtn.id === 'rc_view_all_full_btn' || pkgKindBtn.id === 'rc_view_all_country_btn'
            || pkgKindBtn.id === 'rc_back_latest_full_btn' || pkgKindBtn.id === 'rc_back_latest_country_btn')) {
            ev.preventDefault();
            const kind = pkgKindBtn.getAttribute('data-rc-pkg-kind') || 'full';
            const toAll = (pkgKindBtn.id === 'rc_view_all_full_btn' || pkgKindBtn.id === 'rc_view_all_country_btn');
            setPackageListMode(kind, toAll ? 'all' : 'latest5');
        }
    }, true);

    // Keyboard selection: Enter / Space on any package card (eligible, ineligible, unresolved).
    document.addEventListener('keydown', (ev) => {
        const pkgPick = ev.target instanceof HTMLElement ? ev.target.closest('[data-rc-pkg-pick="1"]') : null;
        if (!pkgPick) return;
        if (ev.target instanceof HTMLElement && (ev.target.closest('button') || ev.target.closest('a') || ev.target.closest('input'))) {
            return;
        }
        if (ev.key !== 'Enter' && ev.key !== ' ') return;
        ev.preventDefault();
        applyPackageSelection(
            pkgPick.getAttribute('data-type') || 'full_disaster',
            pkgPick.getAttribute('data-id') || '',
            pkgPick.getAttribute('data-cc') || '',
            { focus: true }
        );
    });

    if (el('rc_result_dialog_close')) {
        el('rc_result_dialog_close').addEventListener('click', (ev) => {
            ev.preventDefault();
            closeRcResultDialog();
        });
    }
    if (el('rc_result_dialog_backdrop')) {
        el('rc_result_dialog_backdrop').addEventListener('click', (ev) => {
            // Intentionally ignore backdrop clicks — result dialog stays open until Close.
            if (ev.target === el('rc_result_dialog_backdrop')) {
                ev.preventDefault();
                ev.stopPropagation();
            }
        });
    }

    loadAll();
})();
</script>
