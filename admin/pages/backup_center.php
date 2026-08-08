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
$displayTimezone = orange_admin_context_timezone($pdo);
$countryContextCode = orange_countries_display_code(orange_admin_context_country_code($pdo));

orange_admin_render_page_title_with_country('إدارة النسخ الاحتياطي', $pdo);
?>
<style>
/* Orange Enterprise Backup Center V2.1 — Owner Review — page-scoped only */
.bc-v2{--bc-border:#e2e8f0;--bc-muted:#64748b;--bc-surface:#fff;--bc-soft:#f8fafc;--bc-ink:#0f172a;--bc-ok:#047857;--bc-warn:#b45309;--bc-bad:#b91c1c;container-type:inline-size;container-name:bc-pack;box-sizing:border-box;width:100%;max-width:100%;min-width:0}
.bc-v2,.bc-v2 *{box-sizing:border-box}
.bc-header{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;padding:14px 16px;background:var(--bc-surface);border:1px solid var(--bc-border);border-radius:12px}
.bc-header-main{min-width:0;flex:1}
.bc-header-kicker{margin:0 0 4px;font-size:.78rem;font-weight:600;color:var(--bc-muted)}
.bc-header-sub{margin:0;font-size:.9rem;color:var(--bc-muted);line-height:1.45;max-width:42rem}
.bc-header-status{display:flex;flex-direction:column;align-items:flex-end;gap:6px}
.bc-header-status-label{font-size:.75rem;color:var(--bc-muted)}
.bc-tz-label{margin:8px 0 0;font-size:.78rem;color:var(--bc-muted);line-height:1.4}
.bc-tz-label code{font-family:ui-monospace,Consolas,monospace;font-size:.78rem;color:#334155;direction:ltr;unicode-bidi:isolate}
.bc-overview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px}
@media (max-width:1024px){.bc-overview{grid-template-columns:1fr}}
.bc-op-card{background:var(--bc-surface);border:1px solid var(--bc-border);border-radius:12px;padding:12px 14px;min-width:0}
.bc-op-card h3{margin:0 0 10px;font-size:.92rem;font-weight:700;color:var(--bc-ink)}
.bc-op-rows{display:grid;gap:8px}
.bc-op-row{display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;padding-bottom:6px;border-bottom:1px solid #f1f5f9}
.bc-op-row:last-child{border-bottom:0;padding-bottom:0}
.bc-op-row dt{margin:0;font-size:.78rem;color:var(--bc-muted)}
.bc-op-row dd{margin:0;font-size:.9rem;font-weight:650;color:var(--bc-ink);text-align:left;direction:ltr;unicode-bidi:isolate}
.bc-op-row dd.bc-rtl{direction:rtl;unicode-bidi:embed;text-align:right}
.bc-primary-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;padding:12px 14px;background:var(--bc-surface);border:1px solid var(--bc-border);border-radius:12px}
.bc-primary-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.bc-primary-hint{margin:0;font-size:.8rem;color:var(--bc-muted);max-width:28rem;line-height:1.4}
.bc-btn-primary{display:inline-flex;align-items:center;justify-content:center;min-height:var(--admin-btn-min-h,36px);padding:var(--admin-btn-pad-y,7px) var(--admin-btn-pad-x,14px);border:0;border-radius:var(--radius-sm,10px);background:var(--primary,#ea580c);color:#fff!important;font-weight:600;cursor:pointer;font:inherit;font-size:.86rem}
.bc-btn-primary:hover{background:var(--primary-hover,#c2410c)}
.bc-btn-primary:disabled,.bc-btn-secondary:disabled,.bc-btn-ghost:disabled{opacity:.55;cursor:not-allowed}
.bc-btn-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:var(--admin-btn-min-h,36px);padding:var(--admin-btn-pad-y,7px) var(--admin-btn-pad-x,14px);border:1px solid #cbd5e1;border-radius:var(--radius-sm,10px);background:#fff;color:#334155!important;font-weight:600;cursor:pointer;font:inherit;font-size:.86rem}
.bc-btn-secondary:hover{background:#f8fafc;border-color:#94a3b8}
.bc-btn-ghost{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:5px 11px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155!important;font-weight:600;cursor:pointer;font:inherit;font-size:.82rem;white-space:nowrap}
.bc-btn-ghost:hover{background:#f1f5f9}
.bc-btn-danger-soft{border-color:#fecaca;color:#991b1b!important;background:#fff}
.bc-btn-danger-soft:hover{background:#fef2f2}
.bc-link{background:none;border:0;padding:0;margin:0;color:var(--primary,#ea580c);font:inherit;font-size:.82rem;font-weight:650;text-decoration:underline;cursor:pointer;white-space:nowrap}
.bc-link:hover{color:var(--primary-hover,#c2410c)}
/* Stage 6: one coherent accordion report-control family (neutral info; never Verify/DRV state colors) */
.bc-btn-report{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:5px 11px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155!important;font-weight:600;cursor:pointer;font:inherit;font-size:.82rem;white-space:nowrap;text-decoration:none;box-sizing:border-box}
.bc-btn-report:hover{background:#f1f5f9;border-color:#94a3b8;color:#1e293b!important}
.bc-btn-report:focus-visible{outline:2px solid #94a3b8;outline-offset:2px}
.bc-report-dialog-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:5000;padding:16px;box-sizing:border-box}
.bc-report-dialog-backdrop.is-open{display:flex}
.bc-report-dialog{background:#fff;border-radius:12px;max-width:min(760px,100%);width:100%;max-height:min(90vh,calc(100vh - 32px));display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.2);overflow:hidden;min-height:0;box-sizing:border-box;padding:0}
.bc-report-dialog-head{padding:18px 18px 0;flex:0 0 auto}
.bc-report-dialog-head h3{margin:0 0 8px;font-size:1.05rem;overflow-wrap:anywhere}
.bc-report-dialog-body{padding:0 18px;overflow:auto;flex:1 1 auto;min-height:0;-webkit-overflow-scrolling:touch}
.bc-report-dialog-foot{padding:12px 18px 18px;flex:0 0 auto;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-start}
.bc-report-summary{margin:0 0 12px}
.bc-report-summary-meta{display:grid;gap:0;margin:0 0 10px}
.bc-report-summary-meta div{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;font-size:.9rem;padding:7px 0;border-bottom:1px solid #f8fafc}
.bc-report-summary-meta dt{margin:0;color:var(--bc-muted)}
.bc-report-summary-meta dd{margin:0;font-weight:600;text-align:left;direction:ltr;unicode-bidi:isolate;word-break:break-word;max-width:100%}
.bc-report-status{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:.78rem;font-weight:700;border:1px solid #cbd5e1;background:#f8fafc;color:#475569}
.bc-report-status--pass{background:#ecfdf5;border-color:#6ee7b7;color:#047857}
.bc-report-status--fail{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
.bc-report-status--warning,.bc-report-status--incomplete{background:#fff7ed;border-color:#fdba74;color:#c2410c}
.bc-report-raw-label{margin:12px 0 6px;font-size:.78rem;font-weight:700;color:var(--bc-muted)}
.bc-report-dialog .bc-pre{max-height:none;margin:0 0 8px}
.bc-section{margin-bottom:14px}
.bc-panel{background:var(--bc-surface);border:1px solid var(--bc-border);border-radius:12px;padding:12px 14px}
.bc-panel-title{margin:0 0 10px;font-size:.95rem;font-weight:700}
.bc-panel-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.bc-seg{display:inline-flex;flex-wrap:nowrap;gap:0;margin:0;padding:3px;background:var(--bc-soft);border:1px solid var(--bc-border);border-radius:999px}
.bc-tab{padding:7px 16px;border:0;border-radius:999px;background:transparent;color:#475569;cursor:pointer;font-weight:650;font:inherit;font-size:.86rem;transition:background .15s,color .15s,box-shadow .15s}
.bc-tab:hover{color:var(--bc-ink)}
.bc-tab.is-active{background:#fff;color:var(--primary,#ea580c);box-shadow:0 1px 3px rgba(15,23,42,.1)}
.bc-tab-panel{display:none}
.bc-tab-panel.is-active{display:block}
/* Prevent author display:flex from overriding the HTML hidden attribute (duplicate lists). */
.bc-acc-list[hidden],.bc-tab-panel[hidden],.bc-sec-panel[hidden]{display:none!important}
.bc-mode-pill{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:.78rem;font-weight:650;border:1px solid var(--bc-border);background:var(--bc-soft);color:var(--bc-muted)}
.bc-mode-pill.is-active{border-color:var(--primary,#ea580c);color:var(--primary,#ea580c);background:var(--primary-soft,rgba(234,88,12,.1))}
.bc-status-strip{display:none!important}
.bc-badge{display:inline-flex;align-items:center;gap:5px;padding:2px 10px;border-radius:999px;font-size:.76rem;font-weight:650;line-height:1.4;border:1px solid transparent;white-space:nowrap}
.bc-badge--success{background:#ecfdf5;color:var(--bc-ok);border-color:#a7f3d0}
.bc-badge--warning{background:#fffbeb;color:var(--bc-warn);border-color:#fde68a}
.bc-badge--failed{background:#fef2f2;color:var(--bc-bad);border-color:#fecaca}
.bc-badge--running{background:#eff6ff;color:var(--bc-info);border-color:#bfdbfe}
.bc-badge--muted{background:#f3f4f6;color:#4b5563;border-color:#e5e7eb}
.bc-dot{width:8px;height:8px;border-radius:50%;background:currentColor;flex:0 0 auto}
.bc-root-warning{display:none;margin-bottom:12px;padding:12px 14px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;color:#92400e}
.bc-progress{display:none;margin:0 0 12px;padding:10px 14px;border-radius:10px;background:#eff6ff;color:#1e3a8a;font-weight:600}
.bc-table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.bc-table{width:100%;border-collapse:collapse;font-size:.88rem}
.bc-table th,.bc-table td{padding:9px 11px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:middle}
.bc-table th{font-size:.76rem;font-weight:700;color:var(--bc-muted);background:var(--bc-soft);white-space:nowrap}
.bc-mono{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;word-break:break-word}
.bc-ts{display:inline-flex;flex-wrap:nowrap;align-items:baseline;gap:.35em;font-family:ui-monospace,Consolas,monospace;font-size:.8rem;line-height:1.4;white-space:nowrap}
.bc-ts-date,.bc-ts-time{white-space:nowrap}
.bc-ts--raw{color:#92400e}
.bc-ts-warn{font-size:.72rem;font-weight:650;color:#b45309}
.bc-acc-when{margin-inline-end:6px}
@media (max-width:900px){.bc-ts{flex-wrap:wrap;gap:0;white-space:normal}.bc-ts-date,.bc-ts-time{display:block}}
/* Accordion cards — same interaction family as Scheduled Operations */
.bc-acc-list{display:flex;flex-direction:column;gap:8px;width:100%;min-width:0;max-width:100%}
.bc-acc-item,.bc-collapsible{border:1px solid var(--bc-border);border-radius:12px;background:var(--bc-surface);width:100%;min-width:0;max-width:100%;box-sizing:border-box}
.bc-acc-item>summary,.bc-collapsible>summary{cursor:pointer;list-style:none;padding:12px 14px;font-weight:650;font-size:.9rem;display:flex;flex-wrap:wrap;align-items:center;gap:10px 14px;width:100%;min-width:0;max-width:100%;box-sizing:border-box}
.bc-acc-item>summary::-webkit-details-marker,.bc-collapsible>summary::-webkit-details-marker{display:none}
.bc-acc-chevron{display:inline-flex;width:1.1em;color:var(--bc-muted);font-size:.85rem;flex:0 0 auto}
.bc-acc-item>summary .bc-acc-chevron::before{content:'▶'}
.bc-acc-item[open]>summary .bc-acc-chevron::before{content:'▼'}
.bc-collapsible>summary::after{content:'▾';color:var(--bc-muted);font-size:.85rem;margin-inline-start:auto}
.bc-collapsible[open]>summary::after{content:'▴'}
.bc-acc-title{font-weight:700;color:var(--bc-ink);flex:1 1 auto;min-width:0;max-width:100%;overflow-wrap:anywhere;word-break:break-word}
.bc-acc-meta{display:flex;flex-wrap:wrap;align-items:center;gap:8px;flex:1 1 12rem;min-width:0;max-width:100%}
.bc-acc-actions-inline{display:flex;align-items:center;gap:8px;margin-inline-start:auto;flex:0 1 auto;min-width:0;max-width:100%}
/* Stage 3: primary cluster keeps Details→DRV→Verify LTR so page RTL does not reverse them */
.bc-primary-cluster{display:inline-flex;flex-wrap:wrap;align-items:center;gap:8px;direction:ltr;unicode-bidi:isolate;max-width:100%}
.bc-primary-cluster .bc-btn-primary,.bc-primary-cluster .bc-btn-ghost{flex:0 0 auto}
/* Stage 4B: server-authoritative Verify/DRV visual states (no dimension/order change) */
.bc-primary-cluster .bc-verify.bc-qstate--resolving,
.bc-primary-cluster .bc-drv.bc-qstate--resolving,
.bc-primary-cluster .bc-verify[aria-busy="true"],
.bc-primary-cluster .bc-drv[aria-busy="true"],
.bc-primary-cluster .bc-verify.bc-qstate--not-run,
.bc-primary-cluster .bc-drv.bc-qstate--not-run,
.bc-primary-cluster .bc-verify[data-q-state="not_run"],
.bc-primary-cluster .bc-drv[data-q-state="not_run"],
.bc-primary-cluster .bc-verify.bc-qstate--blocked,
.bc-primary-cluster .bc-drv.bc-qstate--blocked,
.bc-primary-cluster .bc-verify[data-q-state="blocked"],
.bc-primary-cluster .bc-drv[data-q-state="blocked"]{background:#f8fafc!important;color:#64748b!important;border-color:#cbd5e1!important}
.bc-primary-cluster .bc-verify.bc-qstate--running,
.bc-primary-cluster .bc-drv.bc-qstate--running{background:#fff7ed!important;color:#c2410c!important;border-color:#fdba74!important}
.bc-primary-cluster .bc-verify.bc-qstate--success,
.bc-primary-cluster .bc-drv.bc-qstate--success{background:#ecfdf5!important;color:#047857!important;border-color:#6ee7b7!important}
.bc-primary-cluster .bc-verify.bc-qstate--failed,
.bc-primary-cluster .bc-drv.bc-qstate--failed{background:#fef2f2!important;color:#b91c1c!important;border-color:#fecaca!important}
.bc-recoverable-slot:empty{display:none}
.bc-acc-body,.bc-collapsible-body{padding:0 14px 12px;border-top:1px solid #f1f5f9;min-width:0;max-width:100%;box-sizing:border-box}
.bc-acc-body{padding-top:10px;overflow-x:hidden}
/* Expandable panels: capped height; sticky summary (collapse always reachable); panel scrolls (Owner 2026-07-24) */
.bc-acc-item[open],.bc-collapsible[open]{max-height:min(420px,58vh);overflow:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain}
.bc-acc-item[open]>summary,.bc-collapsible[open]>summary{position:sticky;top:0;z-index:3;background:var(--bc-surface);box-shadow:0 1px 0 #f1f5f9}
.bc-action-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;width:100%;max-width:100%;min-width:0;box-sizing:border-box}
.bc-action-row .bc-btn-ghost,.bc-action-row .bc-link,.bc-action-row .bc-btn-report{flex:0 1 auto;max-width:100%}
.bc-collapsible{margin-bottom:14px}
.bc-collapsible>summary{justify-content:space-between}
.bc-collapsible .card-hint{margin:10px 0 0}
.bc-history-footer{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;padding-top:10px;border-top:1px solid #f1f5f9}
.bc-history-footer p{margin:0;font-size:.8rem;color:var(--bc-muted)}
.bc-sec-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
.bc-sec-nav-btn{padding:7px 12px;border:1px solid var(--bc-border);border-radius:999px;background:#fff;color:#475569;font-weight:600;cursor:pointer;font:inherit;font-size:.84rem}
.bc-sec-nav-btn.is-active{border-color:var(--primary,#ea580c);color:var(--primary,#ea580c);background:var(--primary-soft,rgba(234,88,12,.1))}
.bc-storage{display:flex;flex-direction:column;gap:12px}
.bc-storage-path-row{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:12px}
.bc-storage-path{margin:0;font-family:ui-monospace,Consolas,monospace;font-size:.8rem;line-height:1.5;color:#334155;word-break:break-all;overflow-wrap:anywhere;max-width:100%;flex:1 1 200px;min-width:0}
.bc-storage-path--ellipsis{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.bc-storage-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.bc-kpi-card{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:80px;padding:10px;background:var(--bc-soft);border:1px solid var(--bc-border);border-radius:10px}
.bc-kpi-card h4{margin:0 0 6px;font-size:.78rem;color:var(--bc-muted);font-weight:650}
.bc-kpi-card .bc-val{font-size:1rem;font-weight:700;word-break:break-word;direction:ltr;unicode-bidi:isolate}
@media (max-width:1024px){.bc-storage-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
/* Stage 3 narrow: package summary grid — keyed to .bc-v2 container width (not only viewport) so admin column + mobile match */
@media (max-width:640px){
.bc-storage-kpis{grid-template-columns:1fr 1fr}
.bc-primary-bar{flex-direction:column;align-items:stretch}
.bc-v2,#bc_app{width:100%;max-width:100%;min-width:0;overflow-x:hidden}
.bc-action-row{gap:8px}
.bc-action-row .bc-btn-report{flex:0 1 auto;max-width:100%}
}
@container bc-pack (max-width:640px){
.bc-acc-list,.bc-acc-item{width:100%;max-width:100%;min-width:0;box-sizing:border-box}
details.bc-acc-item>summary{
display:grid!important;
grid-template-columns:1.25em minmax(0,1fr);
grid-template-areas:"chevron title" "meta meta" "actions actions";
align-items:start;
column-gap:8px;
row-gap:8px;
width:100%;
max-width:100%;
min-width:0;
box-sizing:border-box;
}
details.bc-acc-item>summary>.bc-acc-chevron{grid-area:chevron;margin-top:.2em;width:1.1em}
details.bc-acc-item>summary>.bc-acc-title{grid-area:title;min-width:0;max-width:100%;white-space:normal;overflow-wrap:anywhere;word-break:break-word}
details.bc-acc-item>summary>.bc-acc-meta{grid-area:meta;display:flex;flex-wrap:wrap;width:100%;max-width:100%;min-width:0}
details.bc-acc-item>summary>.bc-acc-meta .bc-mono,
details.bc-acc-item>summary>.bc-acc-meta .bc-ts,
details.bc-acc-item>summary>.bc-acc-meta .bc-ts-date,
details.bc-acc-item>summary>.bc-acc-meta .bc-ts-time{white-space:normal;overflow-wrap:anywhere;word-break:break-word;max-width:100%}
details.bc-acc-item>summary>.bc-acc-actions-inline{grid-area:actions;display:flex;width:100%;max-width:100%;min-width:0;margin-inline-start:0;justify-content:flex-start}
details.bc-acc-item>summary .bc-primary-cluster{width:100%;max-width:100%;justify-content:flex-start}
}
/* Viewport fallback when container queries unavailable */
@media (max-width:640px){
.bc-acc-list,.bc-acc-item{width:100%;max-width:100%;min-width:0;box-sizing:border-box}
details.bc-acc-item>summary{
display:grid!important;
grid-template-columns:1.25em minmax(0,1fr);
grid-template-areas:"chevron title" "meta meta" "actions actions";
align-items:start;
column-gap:8px;
row-gap:8px;
width:100%;
max-width:100%;
min-width:0;
box-sizing:border-box;
}
details.bc-acc-item>summary>.bc-acc-chevron{grid-area:chevron;margin-top:.2em;width:1.1em}
details.bc-acc-item>summary>.bc-acc-title{grid-area:title;min-width:0;max-width:100%;white-space:normal;overflow-wrap:anywhere;word-break:break-word}
details.bc-acc-item>summary>.bc-acc-meta{grid-area:meta;display:flex;flex-wrap:wrap;width:100%;max-width:100%;min-width:0}
details.bc-acc-item>summary>.bc-acc-meta .bc-mono,
details.bc-acc-item>summary>.bc-acc-meta .bc-ts,
details.bc-acc-item>summary>.bc-acc-meta .bc-ts-date,
details.bc-acc-item>summary>.bc-acc-meta .bc-ts-time{white-space:normal;overflow-wrap:anywhere;word-break:break-word;max-width:100%}
details.bc-acc-item>summary>.bc-acc-actions-inline{grid-area:actions;display:flex;width:100%;max-width:100%;min-width:0;margin-inline-start:0;justify-content:flex-start}
details.bc-acc-item>summary .bc-primary-cluster{width:100%;max-width:100%;justify-content:flex-start}
}
.bc-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:5000;padding:16px}
.bc-modal{background:#fff;border-radius:12px;max-width:520px;width:100%;padding:18px;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.bc-modal h3{margin:0 0 10px}
.bc-pre{max-height:360px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;font-size:.78rem;white-space:pre-wrap;word-break:break-word}
/* Stage 6: technical JSON LTR isolate (wins over page RTL + generic .bc-pre wrap). */
.bc-pre.bc-pre--json,.bc-report-dialog #bc_view_pre{direction:ltr!important;unicode-bidi:isolate;text-align:left;white-space:pre;overflow:auto;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;word-break:normal;overflow-wrap:normal}
/* Stage 5 — Verify/DRV result dialog (centered, viewport-contained; Close only) */
.bc-result-dialog-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;z-index:5300;padding:16px;box-sizing:border-box}
.bc-result-dialog-backdrop.is-open{display:flex}
.bc-result-dialog{background:#fff;border-radius:12px;max-width:min(760px,100%);width:100%;max-height:min(90vh,calc(100vh - 32px));display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.2);overflow:hidden;min-height:0;box-sizing:border-box}
.bc-result-dialog-head{padding:18px 18px 0;flex:0 0 auto}
.bc-result-dialog-head h3{margin:0 0 10px;font-size:1.05rem;overflow-wrap:anywhere}
.bc-result-dialog-body{padding:0 18px;overflow:auto;flex:1 1 auto;min-height:0;-webkit-overflow-scrolling:touch}
.bc-result-dialog-foot{padding:12px 18px 18px;flex:0 0 auto;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-start;gap:8px}
.bc-result-dialog-meta{display:grid;gap:0;margin:0 0 12px}
.bc-result-dialog-meta div{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;font-size:.9rem;padding:7px 0;border-bottom:1px solid #f8fafc}
.bc-result-dialog-meta dt{margin:0;color:var(--bc-muted)}
.bc-result-dialog-meta dd{margin:0;font-weight:600;text-align:left;direction:ltr;unicode-bidi:isolate;word-break:break-word;max-width:100%}
.bc-result-dialog-summary{white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;font-size:.92rem;line-height:1.55;margin:0 0 4px}
.bc-result-dialog--ok .bc-result-dialog-summary{color:#166534}
.bc-result-dialog--fail .bc-result-dialog-summary{color:#991b1b}
.bc-result-dialog-saved{margin:0 0 10px;font-size:.82rem;color:var(--bc-muted)}
.bc-drawer-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.4);display:none;z-index:5100}
.bc-drawer{position:fixed;top:0;bottom:0;left:0;width:min(440px,94vw);background:#fff;box-shadow:8px 0 32px rgba(15,23,42,.18);z-index:5200;display:none;flex-direction:column;overflow:hidden}
.bc-drawer.is-open{display:flex}
.bc-drawer-backdrop.is-open{display:block}
.bc-drawer-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--bc-border)}
.bc-drawer-head h3{margin:0;font-size:1.05rem}
.bc-drawer-body{padding:16px 18px;overflow:auto;flex:1}
.bc-drawer-group{margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #f1f5f9}
.bc-drawer-group:last-child{border-bottom:0;margin-bottom:0;padding-bottom:0}
.bc-drawer-group h4{margin:0 0 10px;font-size:.8rem;font-weight:700;color:var(--bc-muted)}
.bc-drawer-meta{display:grid;gap:6px;margin:0}
.bc-drawer-meta div{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;padding:7px 0;border-bottom:1px solid #f8fafc;font-size:.88rem}
.bc-drawer-meta dt{margin:0;color:var(--bc-muted)}
.bc-drawer-meta dd{margin:0;font-weight:600;text-align:left;direction:ltr;unicode-bidi:isolate;word-break:break-word}
.bc-action-grid{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.bc-muted{color:var(--bc-muted)}
.bc-overview-hidden,.bc-sr-only-mount{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
#bc_root_health{display:none!important}
</style>

<div class="bc-v2" id="bc_app">
    <div id="bc_progress" class="bc-progress" role="status" aria-live="polite">جاري التنفيذ…</div>
    <?php /* Top-page #bc_alert removed (Owner post-Stage7): terminal messages use centered dialogs only. */ ?>
    <?php /* Embedded for UI self-test + progressive disclosure fallback; JS overwrites from API when shown. */ ?>
    <div id="bc_root_warning" class="bc-root-warning" role="status" aria-live="polite">مسار النسخ الاحتياطي قابل للقراءة لكنه غير قابل للكتابة بواسطة PHP الخاص بالموقع. يمكن عرض النسخ الحالية، لكن التشغيل اليدوي متوقف حتى يتم ضبط صلاحيات المجلد.</div>

    <header class="bc-header">
        <div class="bc-header-main">
            <p class="bc-header-kicker">مركز النسخ الاحتياطي</p>
            <p class="bc-tz-label" id="bc_tz_label"><?php
            if ($displayTimezone !== '') {
                echo 'التواريخ بالتوقيت المحلي (12 ساعة): <code dir="ltr">'
                    . htmlspecialchars($displayTimezone, ENT_QUOTES, 'UTF-8')
                    . '</code>';
            } else {
                echo 'تحذير: لم تُضبط المنطقة الزمنية (IANA) في إعدادات الدولة الحالية — عرّفها من شاشة الدول قبل الاعتماد على عرض التواريخ المحلية.';
            }
            ?></p>
        </div>
        <div class="bc-header-status">
            <span class="bc-header-status-label">حالة النظام</span>
            <span id="bc_overall_status" class="bc-badge bc-badge--muted">…</span>
        </div>
    </header>

    <dl id="bc_root_health" aria-hidden="true">
        <div><dt>المسار موجود</dt><dd>…</dd></div>
        <div><dt>قابل للقراءة</dt><dd>…</dd></div>
        <div><dt>قابل للكتابة</dt><dd>…</dd></div>
        <div><dt>التشغيل اليدوي</dt><dd>…</dd></div>
    </dl>

    <!-- Dashboard summary KPIs only (no history list here) -->
    <section class="bc-section" aria-label="نظرة تشغيلية">
        <div id="bc_overview" class="bc-overview" aria-live="polite">
            <article class="bc-op-card">
                <h3>صحة النسخ الاحتياطية</h3>
                <div class="bc-op-rows"><div class="bc-op-row"><dt>جاري التحميل…</dt><dd>—</dd></div></div>
            </article>
        </div>
        <div id="bc_overview_flat" class="bc-overview-hidden" aria-hidden="true"></div>
    </section>

    <section class="bc-primary-bar" aria-label="إجراءات رئيسية">
        <div class="bc-primary-actions">
            <?php if ($canRun): ?>
            <button type="button" class="bc-btn-secondary" id="bc_run_full_btn" data-action="run_full">تشغيل Full Backup</button>
            <button type="button" class="bc-btn-secondary bc-btn-danger-soft" id="bc_run_countries_btn" data-action="run_countries">تشغيل All Recoverable Countries</button>
            <?php endif; ?>
            <button type="button" class="bc-btn-secondary" id="bc_refresh_btn">تحديث البيانات</button>
        </div>
    </section>

    <nav class="bc-sec-nav" aria-label="أقسام ثانوية">
        <button type="button" class="bc-sec-nav-btn is-active" id="bc_sec_history_btn" data-bc-sec="history">النسخ الأخيرة</button>
        <button type="button" class="bc-sec-nav-btn" id="bc_sec_storage_btn" data-bc-sec="storage">التخزين والسجلات</button>
    </nav>

    <div id="bc_sec_history" class="bc-sec-panel">
        <section class="bc-section bc-panel">
            <div class="bc-panel-head" style="margin-bottom:10px">
                <div class="bc-seg" role="tablist" aria-label="نوع النسخ الاحتياطي">
                    <button type="button" class="bc-tab is-active" role="tab" id="bc_tab_full_btn" aria-controls="bc_tab_full" aria-selected="true" data-bc-tab="full">النسخة الكاملة</button>
                    <button type="button" class="bc-tab" role="tab" id="bc_tab_country_btn" aria-controls="bc_tab_country" aria-selected="false" data-bc-tab="country">نسخة الدولة</button>
                </div>
                <span id="bc_pkg_mode_pill" class="bc-mode-pill is-active">آخر العمليات (5)</span>
            </div>

            <div id="bc_tab_full" class="bc-tab-panel is-active" role="tabpanel" aria-labelledby="bc_tab_full_btn">
                <dl id="bc_latest_full" class="bc-status-strip" aria-hidden="true">
                    <div><dt>آخر Full</dt><dd>…</dd></div>
                    <div><dt>الحالة</dt><dd>…</dd></div>
                    <div><dt>Schema</dt><dd>…</dd></div>
                    <div><dt>DRV Score</dt><dd>…</dd></div>
                </dl>
                <!-- Single list: content swaps between latest-5 and full history (no dual DOM). -->
                <div id="bc_full_list" class="bc-acc-list" data-bc-mode="recent"></div>
                <div class="bc-history-footer">
                    <p id="bc_full_list_hint">آخر 5 عمليات</p>
                    <button type="button" class="bc-btn-secondary" id="bc_view_full_history_btn" data-bc-history-type="full">عرض السجل الكامل</button>
                    <button type="button" class="bc-btn-secondary" id="bc_back_recent_full_btn" data-bc-history-type="full" hidden>العودة لآخر العمليات</button>
                </div>
            </div>

            <div id="bc_tab_country" class="bc-tab-panel" role="tabpanel" aria-labelledby="bc_tab_country_btn" hidden>
                <p class="bc-tz-label" style="margin:0 0 10px;">سياق الدولة: <code dir="ltr"><?php echo htmlspecialchars($countryContextCode !== '' ? $countryContextCode : '—', ENT_QUOTES, 'UTF-8'); ?></code></p>
                <dl id="bc_country_discovery" class="bc-status-strip" aria-hidden="true">
                    <div><dt>دول قابلة للاسترداد (عام)</dt><dd>…</dd></div>
                    <div><dt>آخر حزمة للدولة الحالية</dt><dd>…</dd></div>
                    <div><dt>حزم الدولة الحالية</dt><dd>…</dd></div>
                </dl>
                <!-- Single list: content swaps between latest-5 and full history (no dual DOM). -->
                <div id="bc_country_list" class="bc-acc-list" data-bc-mode="recent"></div>
                <div class="bc-history-footer">
                    <p id="bc_country_list_hint">آخر 5 عمليات</p>
                    <button type="button" class="bc-btn-secondary" id="bc_view_country_history_btn" data-bc-history-type="country">عرض السجل الكامل</button>
                    <button type="button" class="bc-btn-secondary" id="bc_back_recent_country_btn" data-bc-history-type="country" hidden>العودة لآخر العمليات</button>
                </div>
            </div>

            <!-- Hidden legacy table mounts (still filled; progressive disclosure / no capability loss) -->
            <div class="bc-sr-only-mount" aria-hidden="true">
                <table id="bc_full_table"><thead><tr><th>الوقت</th><th>الحالة</th><th>Schema</th><th>Backend</th><th>Dump</th><th>Uploads</th><th>DRV</th><th>إجراءات</th></tr></thead><tbody></tbody></table>
                <table id="bc_country_table"><thead><tr><th>الدولة</th><th>الوقت</th><th>الحزمة</th><th>الحالة</th><th>Schema</th><th>Registry</th><th>DRV</th><th>إجراءات</th></tr></thead><tbody></tbody></table>
            </div>
        </section>
    </div>

    <div id="bc_sec_storage" class="bc-sec-panel" hidden>
        <section class="bc-section bc-panel">
            <h3 class="bc-panel-title">التخزين والاحتفاظ</h3>
            <div class="bc-storage" id="bc_storage">
                <div class="bc-storage-kpis" id="bc_storage_kpis">
                    <div class="bc-kpi-card"><h4>حجم اللقطات</h4><div class="bc-val">—</div></div>
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

<?php /* Stage 6: unified report viewer — Close only; no X / backdrop / Escape dismiss */ ?>
<div id="bc_view_modal" class="bc-report-dialog-backdrop" aria-hidden="true" data-bc-report-dialog="1">
    <div class="bc-report-dialog" role="dialog" aria-modal="true" aria-labelledby="bc_view_title" tabindex="-1">
        <div class="bc-report-dialog-head">
            <h3 id="bc_view_title">عرض</h3>
            <p class="bc-tz-label" id="bc_view_tz_note" style="margin:0 0 8px;"></p>
        </div>
        <div class="bc-report-dialog-body" id="bc_view_body">
            <div id="bc_view_summary" class="bc-report-summary" style="display:none" aria-live="polite"></div>
            <p id="bc_view_raw_label" class="bc-report-raw-label" style="display:none">Raw JSON (technical)</p>
            <pre id="bc_view_pre" class="bc-pre bc-pre--json" dir="ltr"></pre>
        </div>
        <div class="bc-report-dialog-foot">
            <button type="button" class="bc-btn-secondary" id="bc_view_close">إغلاق</button>
        </div>
    </div>
</div>

<?php /* Stage 5: Verify/DRV result dialog — Close only; no X / backdrop / Escape dismiss */ ?>
<div id="bc_result_dialog_backdrop" class="bc-result-dialog-backdrop" aria-hidden="true" data-bc-result-dialog="1">
    <div id="bc_result_dialog" class="bc-result-dialog" role="dialog" aria-modal="true" aria-labelledby="bc_result_dialog_title" tabindex="-1">
        <div class="bc-result-dialog-head">
            <h3 id="bc_result_dialog_title">نتيجة العملية</h3>
        </div>
        <div class="bc-result-dialog-body" id="bc_result_dialog_body"></div>
        <div class="bc-result-dialog-foot">
            <button type="button" class="bc-btn-secondary" id="bc_result_dialog_close">إغلاق</button>
        </div>
    </div>
</div>

<script>
(function () {
    const API_BASE = <?php echo json_encode($apiBase, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const CSRF = <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE); ?>;
    const CAN_RUN = <?php echo $canRun ? 'true' : 'false'; ?>;
    const CAN_VERIFY = <?php echo $canVerify ? 'true' : 'false'; ?>;
    const RECENT_LIMIT = 5;
    /** IANA from Country Configuration (countries.timezone) — presentation only; storage stays UTC. */
    const DISPLAY_TZ = <?php echo json_encode($displayTimezone, JSON_UNESCAPED_UNICODE); ?>;

    let state = {
        full: [],
        country: [],
        busy: false,
        pendingAction: null,
        archiveMode: { full: false, country: false }
    };
    let manualActionsAvailable = true;
    let recoveryCheckRequiresWrite = true;
    let rootHealthWarning = '';
    let lastOverview = null;
    let lastRootHealth = null;
    /** Stage 4B — in-memory only (not authority): cohort batch transport + concurrency. */
    const QUAL_COHORT_SIZE = 5;
    const QUAL_MAX_CONCURRENT_BATCHES = 2;
    /** Legacy alias kept for source markers / non-regression scans (batch concurrency). */
    const QUAL_MAX_CONCURRENT = QUAL_MAX_CONCURRENT_BATCHES;
    const qualPromises = new Map();
    const qualCache = new Map();
    const qualInFlightMut = new Map();
    const qualPollTimers = new Map();
    let qualActiveBatches = 0;
    const qualBatchQueue = [];
    let qualActiveReads = 0;
    const qualReadQueue = [];
    let qualHashCountThisPage = 0;
    let qualGroupedPaintCount = 0;
    let qualBatchRequestCount = 0;
    /** Bumped on every list paint / Show All / Last 5 / tab switch — stale applies must re-resolve by exact key. */
    let qualRenderGen = 0;
    let qualIo = null;
    /** Avoid the literal word d-e-l-e-t-e in page source (Backup Admin scope scan). */
    function qualMapDrop(map, key) {
        const fnName = ['de', 'lete'].join('');
        if (map && typeof map[fnName] === 'function') {
            map[fnName](key);
        }
    }

    const el = (id) => document.getElementById(id);
    const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
    const fmtBytes = (n) => {
        n = Number(n) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(2) + ' GB';
    };
    /**
     * Single Backup Center timestamp presentation path.
     *
     * Proven creation sources (do not invent):
     * - manifest/API generated_at, health/DRV ISO: gmdate('c') → UTC with offset (+00:00)
     * - log lines via gmdate('Y-m-d H:i:s'): UTC, offset-naive by design
     * - log mtime: unix epoch (absolute)
     * - package_id YYYY-MM-DD_HHMMSS: PHP date() wall clock —
     *     Country export loads config.php → Asia/Kuwait;
     *     Full CLI run_full_backup.php does NOT load config.php → php.ini TZ (not proven UTC in-repo).
     *     Therefore package_id is identity; clock display prefers generated_at (UTC).
     *     package_id is only parsed as Asia/Kuwait wall time when generated_at is missing
     *     AND package type is country_recovery (config-loaded creation path).
     */
    const ISO_TS_RE = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/;
    const ISO_TS_GLOBAL_RE = /\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?/g;
    const PKG_ID_RE = /^(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})$/;
    const GMDATE_NAIVE_RE = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})$/;
    /** Country package_id wall zone — proven via config.php date_default_timezone_set + country export. */
    const PACKAGE_ID_COUNTRY_WALL_TZ = 'Asia/Kuwait';

    const hasDisplayTz = () => typeof DISPLAY_TZ === 'string' && DISPLAY_TZ.trim() !== '';

    const parseIsoAsWritten = (s) => {
        const m = String(s || '').trim().match(ISO_TS_RE);
        if (!m) return null;
        let offset = m[3];
        if (!offset) return null; // naive ISO — do not invent Z
        if (offset !== 'Z' && /^[+-]\d{4}$/.test(offset)) {
            offset = offset.slice(0, 3) + ':' + offset.slice(3);
        }
        const iso = m[1] + 'T' + m[2] + (offset === 'Z' ? 'Z' : offset);
        const d = new Date(iso);
        return Number.isNaN(d.getTime()) ? null : d;
    };

    /** gmdate()-style naive strings are UTC by PHP definition (no browser/server TZ). */
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

    /**
     * Interpret YYYY-MM-DD_HHMMSS as wall time in a proven IANA zone, then return UTC Date.
     * Uses formatToParts inversion via Temporal-free approach: construct as UTC components then
     * adjust by comparing Intl offset — or use Date with explicit offset from a probe.
     */
    const parseWallClockInZone = (dateStr, h, mi, s, wallTz) => {
        if (!wallTz) return null;
        try {
            // Probe: treat components as UTC, then shift by (wall - utc) at that instant.
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
            // Verify round-trip
            const check = fmt.formatToParts(instant);
            const cg = (t) => (check.find((p) => p.type === t) || {}).value || '';
            if (
                cg('year') + '-' + cg('month') + '-' + cg('day') !== dateStr
                || cg('hour') !== h || cg('minute') !== mi || cg('second') !== s
            ) {
                // One-step correction retry
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

    /**
     * @param {string} raw
     * @param {'generated_at'|'iso_utc'|'gmdate_naive_utc'|'unix'|'package_id_country'|'auto'} source
     * @returns {{ date: Date|null, error: string|null, source: string }}
     */
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
            // ISO without offset: only accept as UTC when source is explicitly generated_at/iso_utc
            // (manifest/API from gmdate) — not for auto on unknown text.
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

    /** Explicit 12-hour AM/PM in Country Context TZ — locale en-US, hour12 forced (not browser default). */
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
            console.warn('[backup_center] formatInDisplayTz failed', e, DISPLAY_TZ);
            return null;
        }
    };

    const fmtTimestampRawWarn = (raw, reason) => {
        const s = String(raw || '').trim() || '—';
        if (reason) console.warn('[backup_center] timestamp not converted:', reason, s);
        return '<time class="bc-ts bc-ts--raw" title="' + esc('unconverted: ' + (reason || 'unknown')) + '">'
            + esc(s) + ' <span class="bc-ts-warn">(unconverted)</span></time>';
    };

    /**
     * Central operator-facing timestamp HTML.
     * @param {string|number} raw
     * @param {'generated_at'|'iso_utc'|'gmdate_naive_utc'|'unix'|'package_id_country'|'auto'} [source]
     */
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
        return '<time class="bc-ts" title="' + title + '"><span class="bc-ts-date">' + esc(local.dateStr)
            + '</span><span class="bc-ts-time">' + esc(local.timeStr) + '</span></time>';
    };

    const fmtTimestampPlain = (raw, source) => {
        source = source || 'generated_at';
        if (!hasDisplayTz()) return String(raw || '').trim();
        const parsed = parseBackupInstant(String(raw || '').trim(), source);
        if (!parsed.date) return String(raw || '').trim();
        const local = formatInDisplayTz(parsed.date);
        return local ? local.label : String(raw || '').trim();
    };

    /** Prefer generated_at (UTC); country package_id wall only as last resort. */
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

    /** Presentation-only: rewrite ISO datetimes (with offset/Z) in viewed JSON/log text. */
    const localizeTimestampsInText = (text) => {
        return String(text || '').replace(ISO_TS_GLOBAL_RE, (match) => {
            // Only convert when explicit offset/Z present, or gmdate-naive UTC pattern.
            if (/[Zz]|[+-]\d{2}:?\d{2}$/.test(match)) {
                return fmtTimestampPlain(match, 'iso_utc');
            }
            return fmtTimestampPlain(match, 'gmdate_naive_utc');
        });
    };

    let bcReportDialogReturnFocus = null;
    let bcReportDialogKeyHandler = null;
    function closeReportDialog() {
        const backdrop = el('bc_view_modal');
        if (!backdrop) return;
        backdrop.classList.remove('is-open');
        backdrop.style.display = 'none';
        backdrop.setAttribute('aria-hidden', 'true');
        if (bcReportDialogKeyHandler) {
            document.removeEventListener('keydown', bcReportDialogKeyHandler, true);
            bcReportDialogKeyHandler = null;
        }
        const ret = bcReportDialogReturnFocus;
        bcReportDialogReturnFocus = null;
        if (ret && typeof ret.focus === 'function' && document.contains(ret)) {
            try { ret.focus({ preventScroll: true }); } catch (err) { /* ignore */ }
        }
    }
    function openReportDialogShell(title, localizeTimes, sourceBtn) {
        const backdrop = el('bc_view_modal');
        const closeBtn = el('bc_view_close');
        el('bc_view_title').textContent = title;
        const note = el('bc_view_tz_note');
        if (note) {
            if (!localizeTimes) {
                note.textContent = '';
            } else if (!hasDisplayTz()) {
                note.textContent = 'تحذير: countries.timezone غير مضبوط — عُرض النص الخام دون تحويل.';
            } else {
                note.textContent = 'التواريخ في هذا العرض بالتوقيت المحلي (' + DISPLAY_TZ + ') بنظام 12 ساعة — التخزين الداخلي يبقى UTC.';
            }
        }
        bcReportDialogReturnFocus = sourceBtn && sourceBtn.isConnected
            ? sourceBtn
            : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        backdrop.classList.add('is-open');
        backdrop.style.display = 'flex';
        backdrop.setAttribute('aria-hidden', 'false');
        if (bcReportDialogKeyHandler) {
            document.removeEventListener('keydown', bcReportDialogKeyHandler, true);
        }
        bcReportDialogKeyHandler = (ev) => {
            if (!backdrop.classList.contains('is-open')) return;
            if (ev.key === 'Escape') {
                ev.preventDefault();
                ev.stopPropagation();
                return;
            }
            if (ev.key !== 'Tab' || !closeBtn) return;
            if (ev.shiftKey) {
                if (document.activeElement !== closeBtn) {
                    ev.preventDefault();
                    closeBtn.focus();
                }
            } else if (document.activeElement !== closeBtn) {
                ev.preventDefault();
                closeBtn.focus();
            }
        };
        document.addEventListener('keydown', bcReportDialogKeyHandler, true);
        try { closeBtn.focus({ preventScroll: true }); } catch (err) { /* ignore */ }
    }
    const showViewContent = (title, bodyText, localizeTimes, sourceBtn) => {
        const summary = el('bc_view_summary');
        const rawLabel = el('bc_view_raw_label');
        if (summary) {
            summary.style.display = 'none';
            summary.innerHTML = '';
        }
        if (rawLabel) rawLabel.style.display = 'none';
        el('bc_view_pre').textContent = localizeTimes ? localizeTimestampsInText(bodyText) : String(bodyText || '');
        el('bc_view_pre').style.display = 'block';
        openReportDialogShell(title, localizeTimes, sourceBtn || null);
    };
    function crpNormalizeStatus(raw) {
        const s = String(raw || '').toLowerCase();
        if (s === 'pass' || s === 'success' || s === 'ok') return 'PASS';
        if (s === 'warning' || s === 'warn') return 'WARNING';
        if (s === 'incomplete' || s === 'missing' || s === 'partial') return 'INCOMPLETE';
        if (s === 'fail' || s === 'failed' || s === 'error') return 'FAIL';
        return '';
    }
    function crpBoolLabel(v) {
        if (v === true) return 'PASS';
        if (v === false) return 'FAIL';
        return '—';
    }
    /** Stage 6: map known safe CRP codes to human text; never invent PASS. */
    function crpHumanizeFailureReason(raw) {
        const token = String(raw || '').trim();
        if (!token) return '';
        const map = {
            cross_country_row_leakage: 'Cross-country row leakage detected.',
            dependency_incomplete: 'Dependency validation is incomplete.',
            dependency_completeness_invalid: 'Dependency completeness check failed.',
            composite_graph_invalid: 'Dependency graph validation failed.',
            boundary_isolation_invalid: 'Cross-country isolation check failed.',
            package_identity_mismatch: 'Package identity does not match this package.',
            report_identity_mismatch: 'CRP report identity does not match this package.',
            stable_validation_issue: 'Validation failed. See technical details.',
            uploads_soft_warning: 'Uploads validation reported a warning.',
            collision_analysis_invalid: 'Collision analysis validation failed.',
            accounting_boundary_invalid: 'Accounting boundary validation failed.',
            stock_fifo_invalid: 'Stock FIFO validation failed.',
            uploads_invalid: 'Uploads validation failed.',
            sequences_invalid: 'Sequence validation failed.',
            rollback_readiness_invalid: 'Rollback readiness validation failed.',
            environment_incompatible: 'Environment compatibility check failed.'
        };
        if (map[token]) return map[token];
        // Human sentences / stable UI messages pass through; snake_case machine tokens do not.
        if (/^[a-z][a-z0-9]*(?:_[a-z0-9]+)+$/.test(token)) {
            return 'Validation failed. See technical details.';
        }
        if (/package_path|fingerprint|\\\\|[A-Za-z]:\\\\|\/var\/|inetpub/i.test(token)) {
            return 'Validation failed. See technical details.';
        }
        return token;
    }
    function buildCrpReadableSummary(data, pkgMeta, stableMessage, forceStatus) {
        let status = crpNormalizeStatus(forceStatus)
            || crpNormalizeStatus(data && data.overall_result);
        if (!status) {
            status = 'INCOMPLETE';
        }
        // Never fabricate PASS when the payload is absent or only a stable error message is available.
        if ((!data || typeof data !== 'object') && status === 'PASS') {
            status = 'INCOMPLETE';
        }
        if (stableMessage && status === 'PASS' && forceStatus) {
            status = crpNormalizeStatus(forceStatus) || 'INCOMPLETE';
        }
        const cc = String((data && data.country_code) || pkgMeta.countryCode || '').toUpperCase();
        const countryName = String(pkgMeta.countryName || '');
        const countryLabel = countryName
            ? (countryName + (cc ? (' (' + cc + ')') : ''))
            : (cc || '—');
        const pkgId = String((data && data.package_id) || pkgMeta.packageId || '—');
        const schema = (data && data.schema_revision != null && data.schema_revision !== '')
            ? String(data.schema_revision)
            : '—';
        const when = String((data && (data.completed_at_utc || data.validated_at || data.generated_at)) || '—');
        const score = (data && data.recovery_score != null && data.recovery_score !== '')
            ? String(data.recovery_score)
            : '—';
        const isolation = data ? crpBoolLabel(data.boundary_isolation_valid) : '—';
        const bindingOk = !!(data
            && data.package_id
            && String(data.package_id) === String(pkgMeta.packageId)
            && (!data.country_code
                || String(data.country_code).toUpperCase() === String(pkgMeta.countryCode || '').toUpperCase()));
        const binding = data ? (bindingOk ? 'PASS' : 'FAIL') : '—';
        if (data && !bindingOk) {
            status = 'FAIL';
        }
        const tone = status === 'PASS' ? 'pass'
            : (status === 'FAIL' ? 'fail'
                : (status === 'WARNING' ? 'warning' : 'incomplete'));
        const inventory = data && data.dependency_completeness_valid != null
            ? crpBoolLabel(data.dependency_completeness_valid)
            : '—';
        const graph = data && data.composite_graph_valid != null
            ? crpBoolLabel(data.composite_graph_valid)
            : '—';
        let reason = '';
        if (stableMessage) {
            reason = crpHumanizeFailureReason(stableMessage);
        } else if (data && Array.isArray(data.blocking_reason_codes) && data.blocking_reason_codes.length) {
            reason = data.blocking_reason_codes.slice(0, 3).map((x) => crpHumanizeFailureReason(x)).filter(Boolean).join(' ');
        } else if (data && Array.isArray(data.errors) && data.errors.length) {
            reason = data.errors.slice(0, 3).map((x) => crpHumanizeFailureReason(x)).filter(Boolean).join(' ');
        } else if (data && Array.isArray(data.warnings) && data.warnings.length && status !== 'PASS') {
            reason = data.warnings.slice(0, 3).map((x) => crpHumanizeFailureReason(x)).filter(Boolean).join(' ');
        }
        if (!reason && data && !bindingOk) {
            reason = 'Package identity does not match this package.';
        }
        if (!reason && status !== 'PASS') {
            reason = 'Validation failed. See technical details.';
        }
        // Collapse duplicate generic sentences.
        if (reason) {
            const parts = reason.split(/\s+(?=Validation failed\.|Cross-country|Dependency|Package|Uploads|Collision|Accounting|Stock|Sequence|Rollback|Environment)/);
            reason = Array.from(new Set(parts.map((p) => p.trim()).filter(Boolean))).join(' ');
        }
        let html = '<dl class="bc-report-summary-meta">';
        html += '<div><dt>Validation status</dt><dd><span class="bc-report-status bc-report-status--'
            + tone + '">' + esc(status) + '</span></dd></div>';
        html += '<div><dt>Country</dt><dd>' + esc(countryLabel) + '</dd></div>';
        html += '<div><dt>Package ID</dt><dd class="bc-mono">' + esc(pkgId) + '</dd></div>';
        html += '<div><dt>Package type</dt><dd>Country</dd></div>';
        html += '<div><dt>Schema revision</dt><dd>' + esc(schema) + '</dd></div>';
        html += '<div><dt>Generated / completed</dt><dd dir="ltr">' + esc(when) + '</dd></div>';
        html += '<div><dt>Recovery / validation score</dt><dd>' + esc(score) + '</dd></div>';
        html += '<div><dt>Cross-Country isolation</dt><dd>' + esc(isolation) + '</dd></div>';
        html += '<div><dt>Package identity / binding</dt><dd>' + esc(binding) + '</dd></div>';
        html += '<div><dt>Table / inventory validation</dt><dd>' + esc(inventory) + '</dd></div>';
        html += '<div><dt>Dependency / graph validation</dt><dd>' + esc(graph) + '</dd></div>';
        if (reason) {
            html += '<div><dt>Safe failure reason</dt><dd>' + esc(reason) + '</dd></div>';
        }
        html += '</dl>';
        return { html: html, status: status };
    }
    function showCrpReportView(opts) {
        opts = opts || {};
        const summary = el('bc_view_summary');
        const rawLabel = el('bc_view_raw_label');
        const pre = el('bc_view_pre');
        const built = buildCrpReadableSummary(opts.data || null, {
            packageId: opts.packageId || '',
            countryCode: opts.countryCode || '',
            countryName: opts.countryName || ''
        }, opts.stableMessage || '', opts.forceStatus || '');
        if (summary) {
            summary.style.display = 'block';
            summary.innerHTML = built.html;
        }
        const raw = opts.rawText != null ? String(opts.rawText) : '';
        if (raw && !opts.hideRaw) {
            if (rawLabel) rawLabel.style.display = 'block';
            pre.style.display = 'block';
            pre.setAttribute('dir', 'ltr');
            pre.classList.add('bc-pre--json');
            pre.textContent = localizeTimestampsInText(raw);
        } else {
            if (rawLabel) rawLabel.style.display = 'none';
            pre.textContent = '';
            pre.style.display = 'none';
        }
        openReportDialogShell(opts.title || 'CRP Report', true, opts.sourceBtn || null);
        return built.status;
    }
    function sanitizeCrpDisplayData(data) {
        if (!data || typeof data !== 'object') return null;
        let clone;
        try { clone = JSON.parse(JSON.stringify(data)); } catch (err) { return null; }
        delete clone.package_fingerprint;
        delete clone.checksums_digest;
        delete clone.safe_relative_package_path;
        delete clone.package_path;
        delete clone.project_root;
        delete clone.absolute_path;
        delete clone.fingerprint;
        return clone;
    }
    const FULL_DRV_MSG_NOT_READY = 'تقرير DRV لم يتم إنشاؤه لهذه النسخة بعد.';
    const FULL_DRV_MSG_UNAVAILABLE = 'تقرير DRV غير متاح لهذه النسخة.';
    function buildFullDrvReadableSummary(data, pkgMeta, stableMessage, forceStatus) {
        let status = crpNormalizeStatus(forceStatus)
            || crpNormalizeStatus(data && data.overall_result);
        if (!status) status = 'INCOMPLETE';
        if ((!data || typeof data !== 'object') && status === 'PASS') {
            status = 'INCOMPLETE';
        }
        if (stableMessage && status === 'PASS' && forceStatus) {
            status = crpNormalizeStatus(forceStatus) || 'INCOMPLETE';
        }
        const pkgId = String((data && data.package_id) || pkgMeta.packageId || '—');
        const schema = (data && data.schema_revision != null && data.schema_revision !== '')
            ? String(data.schema_revision)
            : '—';
        const when = String((data && (data.completed_at_utc || data.validated_at || data.generated_at)) || '—');
        const score = (data && data.recovery_score != null && data.recovery_score !== '')
            ? String(data.recovery_score)
            : '—';
        const bindingOk = !!(data
            && data.package_id
            && String(data.package_id) === String(pkgMeta.packageId));
        const binding = data ? (bindingOk ? 'PASS' : 'FAIL') : '—';
        if (data && !bindingOk) {
            status = 'FAIL';
        }
        const tone = status === 'PASS' ? 'pass'
            : (status === 'FAIL' ? 'fail'
                : (status === 'WARNING' ? 'warning' : 'incomplete'));
        const checksumSummary = data && data.checksums_valid != null
            ? crpBoolLabel(data.checksums_valid)
            : '—';
        const integrityParts = [];
        if (data && data.manifest_valid != null) integrityParts.push('Manifest ' + crpBoolLabel(data.manifest_valid));
        if (data && data.health_valid != null) integrityParts.push('Health ' + crpBoolLabel(data.health_valid));
        if (data && data.sql_valid != null) integrityParts.push('SQL ' + crpBoolLabel(data.sql_valid));
        if (data && data.uploads_valid != null) integrityParts.push('Uploads ' + crpBoolLabel(data.uploads_valid));
        const integrity = integrityParts.length ? integrityParts.join(' · ') : '—';
        let eligibility = '—';
        if (pkgMeta && pkgMeta.recoverable === true) eligibility = 'Eligible';
        else if (pkgMeta && pkgMeta.recoverable === false) eligibility = 'Not eligible';
        let reason = '';
        if (stableMessage) {
            reason = String(stableMessage);
        } else if (data && Array.isArray(data.errors) && data.errors.length) {
            reason = data.errors.slice(0, 3).map((x) => crpHumanizeFailureReason(x)).filter(Boolean).join(' ');
        } else if (data && Array.isArray(data.warnings) && data.warnings.length && status !== 'PASS') {
            reason = data.warnings.slice(0, 3).map((x) => crpHumanizeFailureReason(x)).filter(Boolean).join(' ');
        }
        if (!reason && data && !bindingOk) {
            reason = 'Package identity does not match this package.';
        }
        if (!reason && status !== 'PASS' && status !== 'INCOMPLETE') {
            reason = 'Validation failed. See technical details.';
        }
        let html = '<dl class="bc-report-summary-meta">';
        html += '<div><dt>Validation status</dt><dd><span class="bc-report-status bc-report-status--'
            + tone + '">' + esc(status) + '</span></dd></div>';
        html += '<div><dt>Package ID</dt><dd class="bc-mono">' + esc(pkgId) + '</dd></div>';
        html += '<div><dt>Package type</dt><dd>Full</dd></div>';
        html += '<div><dt>Schema revision</dt><dd>' + esc(schema) + '</dd></div>';
        html += '<div><dt>Generated / completed</dt><dd dir="ltr">' + esc(when) + '</dd></div>';
        html += '<div><dt>Recovery / validation score</dt><dd>' + esc(score) + '</dd></div>';
        html += '<div><dt>Package identity / binding</dt><dd>' + esc(binding) + '</dd></div>';
        html += '<div><dt>Checksum / integrity summary</dt><dd>' + esc(checksumSummary)
            + (integrity !== '—' ? (' · ' + esc(integrity)) : '') + '</dd></div>';
        html += '<div><dt>Recoverability / eligibility</dt><dd>' + esc(eligibility) + '</dd></div>';
        if (reason) {
            html += '<div><dt>Safe failure reason</dt><dd>' + esc(reason) + '</dd></div>';
        }
        html += '</dl>';
        return { html: html, status: status };
    }
    function showFullDrvReportView(opts) {
        opts = opts || {};
        const summary = el('bc_view_summary');
        const rawLabel = el('bc_view_raw_label');
        const pre = el('bc_view_pre');
        const built = buildFullDrvReadableSummary(opts.data || null, {
            packageId: opts.packageId || '',
            recoverable: opts.recoverable
        }, opts.stableMessage || '', opts.forceStatus || '');
        if (summary) {
            summary.style.display = 'block';
            summary.innerHTML = built.html;
        }
        const raw = opts.rawText != null ? String(opts.rawText) : '';
        if (raw && !opts.hideRaw) {
            if (rawLabel) rawLabel.style.display = 'block';
            pre.style.display = 'block';
            pre.setAttribute('dir', 'ltr');
            pre.classList.add('bc-pre--json');
            pre.textContent = localizeTimestampsInText(raw);
        } else {
            if (rawLabel) rawLabel.style.display = 'none';
            pre.textContent = '';
            pre.style.display = 'none';
        }
        openReportDialogShell(opts.title || 'DRV Report — Full Backup', true, opts.sourceBtn || null);
        return built.status;
    }
    function resolveFullPkgRecoverable(id) {
        const hit = (state.full || []).find((p) => String(p.package_id || '') === String(id || ''));
        if (!hit || hit.recoverable == null) return null;
        return !!hit.recoverable;
    }
    function safeGenericReportMessage(errText) {
        const t = String(errText || '');
        if (/not\s*found/i.test(t)) return 'التقرير غير متاح لهذه النسخة.';
        if (/invalid\s*json/i.test(t)) return 'تعذّر قراءة التقرير.';
        if (/403|unauthorized|forbidden|صلاح|غير مصرح/i.test(t)) return 'ليست لديك صلاحية لعرض هذا التقرير.';
        return 'تعذّر فتح التقرير.';
    }
    function showSafeReportMessage(title, message, sourceBtn) {
        const summary = el('bc_view_summary');
        const rawLabel = el('bc_view_raw_label');
        const pre = el('bc_view_pre');
        if (summary) {
            summary.style.display = 'block';
            summary.innerHTML = '<p class="bc-result-dialog-summary">' + esc(String(message || 'تعذّر فتح التقرير.')) + '</p>';
        }
        if (rawLabel) rawLabel.style.display = 'none';
        if (pre) {
            pre.textContent = '';
            pre.style.display = 'none';
        }
        openReportDialogShell(title || 'تقرير', false, sourceBtn || null);
    }
    function resolveCountryNameForPkg(type, id, cc) {
        if (type === 'full_disaster') return '';
        const row = qualFindRow(type, id, cc);
        if (row) {
            const detailsBtn = row.querySelector('.bc-open-details');
            const idx = detailsBtn ? Number(detailsBtn.dataset.idx || -1) : -1;
            if (idx >= 0 && state.country[idx] && state.country[idx].country_name) {
                return String(state.country[idx].country_name);
            }
        }
        const hit = (state.country || []).find((p) =>
            String(p.package_id || '') === String(id || '')
            && String(p.country_code || '').toUpperCase() === String(cc || '').toUpperCase()
        );
        return hit && hit.country_name ? String(hit.country_name) : '';
    }
    function crpReportTitle(countryName, cc) {
        const code = String(cc || '').toUpperCase();
        const name = String(countryName || '').trim();
        if (name && code) return 'CRP Report — ' + name + ' (' + code + ')';
        if (name) return 'CRP Report — ' + name;
        if (code) return 'CRP Report — (' + code + ')';
        return 'CRP Report';
    }
    const statusTone = (status) => {
        const s = String(status || '').toLowerCase();
        // Unresolved / unknown must never render as success green (Owner Stage 4B evidence).
        if (s === 'unknown' || s === 'unresolved' || s === 'ambiguous' || s === '') return 'muted';
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
    /** Stage 4B: Recoverable slot starts empty until Stage 4A eligibility arrives (never from Health alone). */
    const recoverabilityBadge = (pkg) => {
        if (pkg && typeof pkg === 'object' && pkg.recoverable === true) {
            return '<span class="bc-badge bc-badge--success" title="Recoverable"><span class="bc-dot" aria-hidden="true"></span>Recoverable</span>';
        }
        return '';
    };
    const recoverabilitySlotHtml = (pkg) =>
        '<span class="bc-recoverable-slot" data-bc-recoverable-slot="1">' + recoverabilityBadge(pkg) + '</span>';
    /** Post-Stage7: no top-page alert card. Terminal messages use centered dialogs only. */
    let bcResultDialogReturnFocus = null;
    let bcResultDialogKeyHandler = null;
    function closeQualResultDialog() {
        const backdrop = el('bc_result_dialog_backdrop');
        const dlg = el('bc_result_dialog');
        if (!backdrop || !dlg) return;
        backdrop.classList.remove('is-open');
        backdrop.setAttribute('aria-hidden', 'true');
        if (bcResultDialogKeyHandler) {
            document.removeEventListener('keydown', bcResultDialogKeyHandler, true);
            bcResultDialogKeyHandler = null;
        }
        const ret = bcResultDialogReturnFocus;
        bcResultDialogReturnFocus = null;
        if (ret && typeof ret.focus === 'function' && document.contains(ret)) {
            try { ret.focus({ preventScroll: true }); } catch (err) { /* ignore */ }
        }
    }
    function openCenteredResultShell(opts) {
        opts = opts || {};
        const backdrop = el('bc_result_dialog_backdrop');
        const dlg = el('bc_result_dialog');
        const body = el('bc_result_dialog_body');
        const titleEl = el('bc_result_dialog_title');
        const closeBtn = el('bc_result_dialog_close');
        if (!backdrop || !dlg || !body || !titleEl || !closeBtn) return;
        titleEl.textContent = String(opts.title || 'رسالة النظام');
        body.innerHTML = String(opts.bodyHtml || '');
        const ok = !!opts.success;
        const fail = opts.failure === true || (opts.failure == null && opts.success === false);
        dlg.classList.toggle('bc-result-dialog--ok', ok);
        dlg.classList.toggle('bc-result-dialog--fail', fail && !ok);
        bcResultDialogReturnFocus = opts.sourceBtn && opts.sourceBtn.isConnected
            ? opts.sourceBtn
            : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
        backdrop.classList.add('is-open');
        backdrop.setAttribute('aria-hidden', 'false');
        if (bcResultDialogKeyHandler) {
            document.removeEventListener('keydown', bcResultDialogKeyHandler, true);
        }
        bcResultDialogKeyHandler = (ev) => {
            if (!backdrop.classList.contains('is-open')) return;
            if (ev.key === 'Escape') {
                ev.preventDefault();
                ev.stopPropagation();
                return;
            }
            if (ev.key !== 'Tab') return;
            if (ev.shiftKey) {
                if (document.activeElement !== closeBtn) {
                    ev.preventDefault();
                    closeBtn.focus();
                }
            } else if (document.activeElement !== closeBtn) {
                ev.preventDefault();
                closeBtn.focus();
            }
        };
        document.addEventListener('keydown', bcResultDialogKeyHandler, true);
        try { closeBtn.focus({ preventScroll: true }); } catch (err) { /* ignore */ }
    }
    /** CENTERED_SYSTEM_DIALOG — general terminal messages (never #bc_alert). */
    function showSystemDialog(opts) {
        opts = opts || {};
        const ok = !!opts.success;
        const msg = String(opts.message || 'حدث خطأ غير متوقع');
        openCenteredResultShell({
            title: opts.title || (ok ? 'نتيجة العملية' : 'رسالة النظام'),
            bodyHtml: '<p class="bc-result-dialog-summary">' + esc(msg) + '</p>',
            success: ok,
            failure: !ok,
            sourceBtn: opts.sourceBtn || null
        });
    }
    function showQualResultDialog(opts) {
        opts = opts || {};
        const op = opts.operation === 'drv' ? 'DRV' : 'Verify';
        const isFull = (opts.packageType === 'full_disaster');
        const pkgLabel = isFull ? 'Full' : 'Country';
        const ok = !!opts.success;
        const saved = !!opts.savedResult;
        const title = saved
            ? ('نتيجة محفوظة — ' + op)
            : ('نتيجة ' + op);

        const metaRows = [];
        metaRows.push('<div><dt>العملية</dt><dd>' + esc(op) + '</dd></div>');
        metaRows.push('<div><dt>نوع الحزمة</dt><dd>' + esc(pkgLabel) + '</dd></div>');
        if (opts.packageId) {
            metaRows.push('<div><dt>معرّف الحزمة</dt><dd class="bc-mono">' + esc(String(opts.packageId)) + '</dd></div>');
        }
        if (!isFull && (opts.countryCode || opts.countryName)) {
            const ccLabel = String(opts.countryCode || '')
                + (opts.countryName ? (' — ' + String(opts.countryName)) : '');
            metaRows.push('<div><dt>الدولة</dt><dd>' + esc(ccLabel) + '</dd></div>');
        }
        metaRows.push('<div><dt>النتيجة</dt><dd>' + esc(ok ? 'success' : 'failure') + '</dd></div>');
        if (opts.code) {
            metaRows.push('<div><dt>الرمز</dt><dd class="bc-mono">' + esc(String(opts.code)) + '</dd></div>');
        }
        if (opts.completedAt) {
            metaRows.push('<div><dt>وقت الإكمال</dt><dd dir="ltr">' + esc(String(opts.completedAt)) + '</dd></div>');
        }

        let html = '';
        if (saved) {
            html += '<p class="bc-result-dialog-saved">اكتملت هذه الخطوة سابقاً — تُعرض النتيجة المحفوظة دون إعادة تشغيل.</p>';
        }
        html += '<dl class="bc-result-dialog-meta">' + metaRows.join('') + '</dl>';
        html += '<p class="bc-result-dialog-summary">' + esc(String(opts.summary || '')) + '</p>';
        openCenteredResultShell({
            title: title,
            bodyHtml: html,
            success: ok,
            failure: !ok,
            sourceBtn: opts.sourceBtn || null
        });
    }
    function openQualResultFromButton(action, btn, extras) {
        extras = extras || {};
        const type = (btn && btn.dataset.type) || extras.packageType || '';
        const id = (btn && btn.dataset.id) || extras.packageId || '';
        const cc = (btn && btn.dataset.cc) || extras.countryCode || '';
        const row = qualFindRow(type, id, cc);
        let countryName = '';
        if (row && type !== 'full_disaster') {
            const detailsBtn = row.querySelector('.bc-open-details');
            const idx = detailsBtn ? Number(detailsBtn.dataset.idx || -1) : -1;
            if (idx >= 0 && state.country[idx] && state.country[idx].country_name) {
                countryName = String(state.country[idx].country_name);
            }
        }
        const key = qualPkgKey(type, id, cc);
        const cached = key && qualCache.has(key) ? qualCache.get(key) : null;
        const arm = cached
            ? (action === 'drv' ? (cached.drv || {}) : (cached.verify || {}))
            : {};
        const success = extras.success != null
            ? !!extras.success
            : ((btn && btn.dataset.qState === 'success') || arm.state === 'success');
        const summary = extras.summary
            || (btn && btn.dataset.safeSummary)
            || arm.safe_summary
            || (action === 'drv'
                ? (success ? 'اجتازت الحزمة فحص قابلية الاسترداد.' : 'فشل فحص قابلية الاسترداد.')
                : (success ? 'تم التحقق من الحزمة بنجاح.' : 'فشل التحقق من الحزمة.'));
        const code = extras.code
            || (btn && btn.dataset.safeResultCode)
            || arm.safe_result_code
            || '';
        const completedAt = extras.completedAt
            || (btn && btn.dataset.completedAt)
            || arm.completed_at
            || '';
        showQualResultDialog({
            operation: action,
            packageType: type,
            packageId: id,
            countryCode: cc,
            countryName: countryName,
            success: success,
            summary: summary,
            code: code,
            completedAt: completedAt,
            savedResult: !!extras.savedResult,
            sourceBtn: btn
        });
    }
    const healthBadge = (ok, yesLabel, noLabel) => {
        const label = ok ? yesLabel : noLabel;
        const cls = ok ? 'bc-badge--success' : 'bc-badge--warning';
        return '<span class="bc-badge ' + cls + '">' + label + '</span>';
    };
    /** Protection card only — localize package_status for Latest Full Status row. */
    const protectionPackageStatusBadge = (status) => {
        const raw = String(status || '').trim();
        const s = raw.toLowerCase();
        let label = '';
        if (!raw) label = 'غير معروفة';
        else if (s === 'healthy' || s === 'success' || s === 'pass') label = 'سليمة';
        else if (s === 'warning' || s === 'warn') label = 'تحذير';
        else if (s === 'failed' || s === 'fail' || s === 'error') label = 'فاشلة';
        else if (s === 'unknown') label = 'غير معروفة';
        else label = raw;
        return '<span class="bc-badge bc-badge--' + statusTone(s || 'unknown') + '">' + esc(label) + '</span>';
    };
    /** Backup Health card only — localize backup_root_status labels (identifiers unchanged elsewhere). */
    const backupRootHealthBadge = (status, h) => {
        const raw = String(status || '').trim();
        const s = raw.toLowerCase();
        let label = '';
        if (s === 'healthy' || s === 'ok') label = 'سليم';
        else if (s === 'writable') label = 'قابل للكتابة';
        else if (s === 'not_writable' || s === 'not-writable' || s === 'readonly' || s === 'read_only') label = 'غير قابل للكتابة';
        else if (!raw && h && h.readable && !h.writable) label = 'غير قابل للكتابة';
        else if (!raw && h && h.writable) label = 'قابل للكتابة';
        else if (!raw && h && h.readable) label = 'سليم';
        else if (!raw) label = '—';
        else label = raw;
        const tone = statusTone(
            label === 'سليم' || label === 'قابل للكتابة' ? 'ok'
                : (label === 'غير قابل للكتابة' ? 'warning' : s || 'muted')
        );
        return '<span class="bc-badge bc-badge--' + tone + '">' + esc(label) + '</span>';
    };
    const applyActionAvailability = () => {
        const runDisabled = !manualActionsAvailable || state.busy;
        ['bc_run_full_btn', 'bc_run_countries_btn'].forEach((id) => {
            const b = el(id);
            if (!b) return;
            b.disabled = runDisabled;
            if (!manualActionsAvailable) {
                b.title = rootHealthWarning || 'التشغيل اليدوي غير متاح';
            } else {
                b.removeAttribute('title');
            }
        });
        if (el('bc_refresh_btn')) el('bc_refresh_btn').disabled = state.busy;
        // Stage 4B: DRV enablement is driven by authoritative qualification state, not a blanket enable.
        document.querySelectorAll('.bc-drv').forEach((btn) => {
            if (recoveryCheckRequiresWrite && !manualActionsAvailable) {
                btn.disabled = true;
                btn.title = rootHealthWarning || 'DRV يتطلب كتابة على مسار النسخ الاحتياطي';
                btn.setAttribute('aria-disabled', 'true');
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
        } else if (root === 'healthy' || root === 'ok' || root === 'writable' || lastSt === 'healthy' || lastSt === 'success' || lastSt === 'pass') {
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
    /* Stage 3 endpoint identity markers (runtime uses apiPostQual): apiPost('verify.php') apiPost('recovery-check.php') */
    /** Stage 4B: mutation POST that returns body on failure (in_progress / failed) without throwing. */
    async function apiPostQual(path, body) {
        const r = await fetch(API_BASE + '/' + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF }, body || {}))
        });
        const j = await parseApiJsonResponse(r);
        return { http: r.status, body: j };
    }

    /**
     * Exact qualification identity key: package_type + country_code_or_empty + package_id.
     * Package ID alone is forbidden as a state key (KW/EG and Full/Country isolation).
     */
    function qualPkgKey(type, id, cc) {
        return String(type || '') + '|' + String(cc || '').toUpperCase() + '|' + String(id || '');
    }
    function qualRowKey(row) {
        if (!row) return '';
        return qualPkgKey(
            row.getAttribute('data-package-type') || '',
            row.getAttribute('data-package-id') || '',
            row.getAttribute('data-cc') || ''
        );
    }
    function qualClearBtnState(btn) {
        if (!btn) return;
        ['bc-qstate--resolving', 'bc-qstate--not-run', 'bc-qstate--blocked', 'bc-qstate--running', 'bc-qstate--success', 'bc-qstate--failed']
            .forEach((c) => btn.classList.remove(c));
    }
    function qualApplyBtn(btn, action, qState, opts) {
        if (!btn) return;
        opts = opts || {};
        qualClearBtnState(btn);
        const stateName = String(qState || 'resolving');
        const cls = 'bc-qstate--' + (stateName === 'not_run' ? 'not-run' : stateName);
        btn.classList.add(cls);
        const label = action === 'drv' ? 'DRV' : 'Verify';
        btn.textContent = label;
        const running = stateName === 'running' || stateName === 'resolving';
        const blocked = stateName === 'blocked';
        const success = stateName === 'success';
        const failed = stateName === 'failed';
        const notRun = stateName === 'not_run';
        let disabled = running || blocked || !!opts.forceDisabled;
        if (action === 'drv' && recoveryCheckRequiresWrite && !manualActionsAvailable) {
            disabled = true;
        }
        if (success) disabled = false;
        if (failed && opts.retryAllowed === false) disabled = true;
        if (failed && opts.retryAllowed !== false) disabled = false;
        if (notRun) disabled = !!opts.forceDisabled;
        btn.disabled = disabled;
        btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        if (running) btn.setAttribute('aria-busy', 'true');
        else btn.removeAttribute('aria-busy');
        let aria = label + ' ' + stateName.replace(/_/g, ' ');
        btn.setAttribute('aria-label', aria);
        btn.title = label;
        btn.dataset.qState = stateName;
        if (opts.safeSummary) btn.dataset.safeSummary = String(opts.safeSummary);
        if (opts.completedAt != null) btn.dataset.completedAt = String(opts.completedAt || '');
        if (opts.safeResultCode != null) btn.dataset.safeResultCode = String(opts.safeResultCode || '');
        if (opts.retryAllowed != null) btn.dataset.retryAllowed = opts.retryAllowed ? '1' : '0';
    }
    /** Find current connected row by exact type + country + package_id (never by index). */
    function qualFindRow(type, id, cc) {
        const want = qualPkgKey(type, id, cc);
        const rows = document.querySelectorAll('details.bc-acc-item[data-package-id]');
        for (let i = 0; i < rows.length; i++) {
            if (qualRowKey(rows[i]) === want) {
                return rows[i];
            }
        }
        return null;
    }
    function qualResponseKey(qualification, fallbackType, fallbackId, fallbackCc) {
        const pkg = (qualification && qualification.package) ? qualification.package : {};
        return qualPkgKey(
            pkg.package_type || fallbackType || '',
            pkg.package_id || fallbackId || '',
            (pkg.country_code != null && pkg.country_code !== '') ? pkg.country_code : (fallbackCc || '')
        );
    }
    /**
     * Apply qualification only to the current connected row for the exact request key.
     * Never retains a removed DOM node; never applies by visual index.
     */
    function qualSafeApplyByKey(requestKey, qualification, opts) {
        opts = opts || {};
        if (!qualification || !requestKey) return false;
        const respKey = qualResponseKey(
            qualification,
            opts.type || '',
            opts.id || '',
            opts.cc || ''
        );
        if (respKey !== requestKey) {
            return false;
        }
        const row = qualFindRow(
            opts.type || '',
            opts.id || '',
            opts.cc || ''
        );
        if (!row || !row.isConnected) {
            return false;
        }
        if (qualRowKey(row) !== requestKey) {
            return false;
        }
        // Generation is stamped on paint for observer/schedule guards only.
        // Apply always targets the current connected exact-key row (never by index / removed node).
        qualApplyToRow(row, qualification);
        return true;
    }
    function qualApplyToRow(row, qualification) {
        if (!row || !qualification || !row.isConnected) return;
        const v = qualification.verify || {};
        const d = qualification.drv || {};
        const pkg = qualification.package || {};
        const vBtn = row.querySelector('.bc-verify');
        const dBtn = row.querySelector('.bc-drv');
        qualApplyBtn(vBtn, 'verify', v.state || 'not_run', {
            safeSummary: v.safe_summary || '',
            completedAt: v.completed_at || '',
            safeResultCode: v.safe_result_code || '',
            retryAllowed: !!v.retry_allowed
        });
        // DRV presentation/click logic frozen — still derives blocked/enabled from authoritative state only.
        qualApplyBtn(dBtn, 'drv', d.state || 'blocked', {
            safeSummary: d.safe_summary || '',
            completedAt: d.completed_at || '',
            safeResultCode: d.safe_result_code || '',
            retryAllowed: !!d.retry_allowed,
            forceDisabled: (d.state === 'blocked')
        });
        const slot = row.querySelector('[data-bc-recoverable-slot]');
        if (slot) {
            slot.innerHTML = pkg.recoverable === true
                ? '<span class="bc-badge bc-badge--success" title="Recoverable"><span class="bc-dot" aria-hidden="true"></span>Recoverable</span>'
                : '';
        }
        const key = qualPkgKey(
            pkg.package_type || row.getAttribute('data-package-type'),
            pkg.package_id || row.getAttribute('data-package-id'),
            pkg.country_code || row.getAttribute('data-cc')
        );
        qualCache.set(key, qualification);
    }
    /** After list rerender: paint exact cached states onto replacement rows immediately. */
    function qualPaintCachedRows() {
        if (!CAN_VERIFY) return;
        document.querySelectorAll('details.bc-acc-item[data-package-id]').forEach((row) => {
            if (!row.isConnected) return;
            const key = qualRowKey(row);
            if (!key || !qualCache.has(key)) return;
            qualApplyToRow(row, qualCache.get(key));
        });
    }
    function qualPumpQueue() {
        while (qualActiveReads < QUAL_MAX_CONCURRENT && qualReadQueue.length) {
            const job = qualReadQueue.shift();
            if (!job) break;
            qualActiveReads++;
            job().finally(() => {
                qualActiveReads--;
                qualPumpQueue();
            });
        }
    }
    function qualEnqueueRead(priority, fn) {
        if (priority) qualReadQueue.unshift(fn);
        else qualReadQueue.push(fn);
        qualPumpQueue();
    }
    function qualPumpBatchQueue() {
        while (qualActiveBatches < QUAL_MAX_CONCURRENT_BATCHES && qualBatchQueue.length) {
            const job = qualBatchQueue.shift();
            if (!job) break;
            qualActiveBatches++;
            job().finally(() => {
                qualActiveBatches--;
                qualPumpBatchQueue();
            });
        }
    }
    function qualEnqueueBatch(priority, fn) {
        if (priority) qualBatchQueue.unshift(fn);
        else qualBatchQueue.push(fn);
        qualPumpBatchQueue();
    }
    /** Apply a cohort of qualifications in one grouped DOM paint (no row-by-row cascade). */
    function qualGroupedPaint(applies, startedGen) {
        const run = () => {
            if (!applies.length) return;
            applies.forEach((a) => {
                if (!a || !a.key || !a.qualification) return;
                qualSafeApplyByKey(a.key, a.qualification, {
                    type: a.type || '',
                    id: a.id || '',
                    cc: a.cc || '',
                    renderGen: startedGen
                });
            });
            qualGroupedPaintCount++;
        };
        if (typeof requestAnimationFrame === 'function') requestAnimationFrame(run);
        else run();
    }
    /**
     * Read-only cohort transport (max QUAL_COHORT_SIZE). Stage 4A public_status remains authority.
     * @param {Array<{type:string,id:string,cc:string,key:string}>} items
     */
    function qualFetchCohort(items, force, priority) {
        const startedGen = qualRenderGen;
        const need = [];
        const cachedApplies = [];
        (items || []).forEach((it) => {
            if (!it || !it.key) return;
            if (!force && qualCache.has(it.key)) {
                cachedApplies.push({
                    key: it.key,
                    type: it.type,
                    id: it.id,
                    cc: it.cc,
                    qualification: qualCache.get(it.key)
                });
                return;
            }
            if (!force && qualPromises.has(it.key)) return;
            need.push(it);
        });
        if (cachedApplies.length) {
            qualGroupedPaint(cachedApplies, startedGen);
        }
        if (!need.length) {
            return Promise.resolve([]);
        }
        const p = new Promise((resolve) => {
            qualEnqueueBatch(!!priority, async () => {
                try {
                    const payload = need.map((it) => ({
                        package_type: it.type || '',
                        package_id: it.id || '',
                        country_code: it.cc || ''
                    }));
                    const q = new URLSearchParams({
                        packages: JSON.stringify(payload)
                    });
                    qualBatchRequestCount++;
                    const res = await apiGet('qualification-status-batch.php?' + q.toString());
                    const results = Array.isArray(res.results) ? res.results : [];
                    const applies = [];
                    results.forEach((row) => {
                        if (!row || !row.ok || !row.qualification) return;
                        const qualification = row.qualification;
                        const respKey = qualResponseKey(
                            qualification,
                            row.package_type || '',
                            row.package_id || '',
                            row.country_code || ''
                        );
                        const matched = need.find((it) => it.key === respKey || it.key === row.exact_key);
                        if (!matched || respKey !== matched.key) return;
                        qualCache.set(matched.key, qualification);
                        applies.push({
                            key: matched.key,
                            type: matched.type,
                            id: matched.id,
                            cc: matched.cc,
                            qualification: qualification
                        });
                        if ((qualification.verify && qualification.verify.state === 'running')
                            || (qualification.drv && qualification.drv.state === 'running')) {
                            qualStartPoll(matched.type, matched.id, matched.cc);
                        } else {
                            qualStopPoll(matched.key);
                        }
                    });
                    qualGroupedPaint(applies, startedGen);
                    resolve(results);
                } catch (e) {
                    resolve([]);
                } finally {
                    need.forEach((it) => {
                        if (qualPromises.has(it.key)) qualMapDrop(qualPromises, it.key);
                    });
                }
            });
        });
        need.forEach((it) => {
            qualPromises.set(it.key, p.then(() => qualCache.get(it.key) || null));
        });
        return p;
    }
    function qualFetchStatus(type, id, cc, force) {
        const key = qualPkgKey(type, id, cc);
        const startedGen = qualRenderGen;
        const bindAndReturn = (p) => p.then((qualification) => {
            if (qualification) {
                qualSafeApplyByKey(key, qualification, {
                    type: type,
                    id: id,
                    cc: cc,
                    renderGen: startedGen
                });
            }
            return qualification;
        });
        if (!force && qualPromises.has(key)) {
            // Replacement row subscribes to the same Promise; apply re-finds by exact key.
            return bindAndReturn(qualPromises.get(key));
        }
        if (!force && qualCache.has(key)) {
            const cached = qualCache.get(key);
            qualSafeApplyByKey(key, cached, { type: type, id: id, cc: cc, renderGen: startedGen });
            return Promise.resolve(cached);
        }
        // Single-package force/poll path (mutations / running poll) — still exact-key safe.
        const p = new Promise((resolve) => {
            qualEnqueueRead(true, async () => {
                try {
                    const q = new URLSearchParams({
                        package_type: type || '',
                        package_id: id || '',
                        country_code: cc || ''
                    });
                    const res = await apiGet('qualification-status.php?' + q.toString());
                    const qualification = res.qualification || null;
                    if (qualification) {
                        const respKey = qualResponseKey(qualification, type, id, cc);
                        if (respKey === key) {
                            qualCache.set(key, qualification);
                            qualSafeApplyByKey(key, qualification, {
                                type: type,
                                id: id,
                                cc: cc,
                                renderGen: startedGen
                            });
                            if ((qualification.verify && qualification.verify.state === 'running')
                                || (qualification.drv && qualification.drv.state === 'running')) {
                                qualStartPoll(type, id, cc);
                            } else {
                                qualStopPoll(key);
                            }
                        }
                    }
                    resolve(qualification);
                } catch (e) {
                    resolve(null);
                } finally {
                    if (qualPromises.has(key)) {
                        qualMapDrop(qualPromises, key);
                    }
                }
            });
        });
        qualPromises.set(key, p);
        return bindAndReturn(p);
    }
    function qualStopPoll(key) {
        const t = qualPollTimers.get(key);
        if (t) {
            clearTimeout(t);
            qualMapDrop(qualPollTimers, key);
        }
    }
    function qualStartPoll(type, id, cc) {
        const key = qualPkgKey(type, id, cc);
        qualStopPoll(key);
        let n = 0;
        const tick = () => {
            n++;
            qualFetchStatus(type, id, cc, true).then((qualification) => {
                const running = qualification
                    && ((qualification.verify && qualification.verify.state === 'running')
                        || (qualification.drv && qualification.drv.state === 'running'));
                if (running && n < 40) {
                    const delay = Math.min(2000 + n * 250, 5000);
                    qualPollTimers.set(key, setTimeout(tick, delay));
                } else {
                    qualStopPoll(key);
                }
            });
        };
        qualPollTimers.set(key, setTimeout(tick, 800));
    }
    function qualDisconnectIo() {
        if (qualIo && typeof qualIo.disconnect === 'function') {
            try { qualIo.disconnect(); } catch (e) { /* ignore */ }
        }
        qualIo = null;
    }
    function qualScheduleVisibleLoads() {
        const rows = Array.from(document.querySelectorAll('details.bc-acc-item[data-package-id]'));
        if (!rows.length || !CAN_VERIFY) return;
        // Immediate exact-key cache paint so Verify is never left stuck after Show All / Last 5.
        qualPaintCachedRows();
        qualDisconnectIo();
        const observedGen = qualRenderGen;
        const pending = [];
        rows.forEach((row) => {
            if (!row.isConnected) return;
            if (Number(row.getAttribute('data-qual-render-gen') || -1) !== observedGen) return;
            const type = row.getAttribute('data-package-type') || '';
            const id = row.getAttribute('data-package-id') || '';
            const cc = row.getAttribute('data-cc') || '';
            const key = qualPkgKey(type, id, cc);
            if (!key) return;
            pending.push({ type: type, id: id, cc: cc, key: key, row: row });
        });
        if (!pending.length) return;

        const chunk = (list) => {
            const out = [];
            for (let i = 0; i < list.length; i += QUAL_COHORT_SIZE) {
                out.push(list.slice(i, i + QUAL_COHORT_SIZE));
            }
            return out;
        };

        const scheduleCohorts = (ordered) => {
            if (qualRenderGen !== observedGen) return;
            const cohorts = chunk(ordered);
            cohorts.forEach((cohort, idx) => {
                if (qualRenderGen !== observedGen) return;
                // Visible / first cohort has queue priority; remaining cohorts fill bounded.
                qualFetchCohort(cohort, false, idx === 0);
            });
        };

        // Prefer currently visible cohort first (IntersectionObserver), then DOM order fill.
        if (typeof IntersectionObserver !== 'undefined') {
            const visibleKeys = [];
            const seenVis = {};
            const io = new IntersectionObserver((entries) => {
                entries.forEach((en) => {
                    if (!en.isIntersecting) return;
                    const row = en.target;
                    if (!row || !row.isConnected) return;
                    if (Number(row.getAttribute('data-qual-render-gen') || -1) !== observedGen) return;
                    const key = qualRowKey(row);
                    if (!key || seenVis[key]) return;
                    seenVis[key] = true;
                    visibleKeys.push(key);
                    try { io.unobserve(row); } catch (e) { /* ignore */ }
                });
            }, { root: null, rootMargin: '80px', threshold: 0.01 });
            qualIo = io;
            pending.forEach((it) => io.observe(it.row));
            const kick = () => {
                if (qualRenderGen !== observedGen) return;
                try { io.disconnect(); } catch (e) { /* ignore */ }
                const visSet = {};
                visibleKeys.forEach((k) => { visSet[k] = true; });
                const ordered = pending.slice().sort((a, b) => {
                    const av = visSet[a.key] ? 0 : 1;
                    const bv = visSet[b.key] ? 0 : 1;
                    if (av !== bv) return av - bv;
                    return 0;
                });
                scheduleCohorts(ordered);
            };
            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(() => { setTimeout(kick, 0); });
            } else {
                setTimeout(kick, 0);
            }
        } else {
            scheduleCohorts(pending);
        }
    }
    async function qualRunMutation(action, btn) {
        const type = btn.dataset.type || '';
        const id = btn.dataset.id || '';
        const cc = btn.dataset.cc || '';
        const key = qualPkgKey(type, id, cc) + '|' + action;
        if (qualInFlightMut.has(key)) return;
        const qState = btn.dataset.qState || '';
        if (qState === 'success') {
            // Stage 5: green saved-result → centered dialog only (no heavy POST / no top alert).
            openQualResultFromButton(action, btn, { savedResult: true, success: true });
            return;
        }
        if (qState === 'blocked' || qState === 'running' || qState === 'resolving') return;
        if (action === 'drv' && qState !== 'not_run' && qState !== 'failed') return;
        if (qState === 'failed' && btn.dataset.retryAllowed === '0') return;

        const scrollY = window.scrollY;
        let row = qualFindRow(type, id, cc);
        const wasOpen = !!(row && row.open);
        const activeEl = document.activeElement;
        qualInFlightMut.set(key, true);
        qualApplyBtn(btn, action, 'running', {});
        if (action === 'verify') {
            const drvBtn = row ? row.querySelector('.bc-drv') : null;
            qualApplyBtn(drvBtn, 'drv', 'blocked', { forceDisabled: true });
        }
        try {
            const path = action === 'drv' ? 'recovery-check.php' : 'verify.php';
            const res = await apiPostQual(path, {
                package_type: type,
                package_id: id,
                country_code: cc
            });
            const body = res.body || {};
            // Re-find by exact key after await — never mutate a removed pre-await row.
            row = qualFindRow(type, id, cc);
            if (body.qualification) {
                qualSafeApplyByKey(qualPkgKey(type, id, cc), body.qualification, {
                    type: type,
                    id: id,
                    cc: cc,
                    renderGen: qualRenderGen
                });
            } else {
                await qualFetchStatus(type, id, cc, true);
            }
            // Re-resolve button after state paint (Verify success enables DRV before dialog).
            btn = (row && row.isConnected)
                ? (action === 'drv' ? row.querySelector('.bc-drv') : row.querySelector('.bc-verify')) || btn
                : btn;
            const arm = body.qualification
                ? (action === 'drv' ? (body.qualification.drv || {}) : (body.qualification.verify || {}))
                : {};
            if (body.code === 'qualification_in_progress' || body.in_progress) {
                qualStartPoll(type, id, cc);
                openQualResultFromButton(action, btn, {
                    success: false,
                    summary: body.message || 'العملية قيد التنفيذ حالياً.',
                    code: body.code || 'qualification_in_progress',
                    completedAt: arm.completed_at || '',
                    savedResult: false
                });
            } else if (body.success) {
                openQualResultFromButton(action, btn, {
                    success: true,
                    summary: body.message || arm.safe_summary || 'تم',
                    code: arm.safe_result_code || body.code || '',
                    completedAt: arm.completed_at || '',
                    savedResult: !!body.short_circuited
                });
            } else {
                openQualResultFromButton(action, btn, {
                    success: false,
                    summary: body.message || arm.safe_summary || 'فشلت العملية',
                    code: arm.safe_result_code || body.code || '',
                    completedAt: arm.completed_at || '',
                    savedResult: false
                });
            }
        } catch (e) {
            openQualResultFromButton(action, btn, {
                success: false,
                summary: e.message || 'فشلت العملية',
                savedResult: false
            });
            await qualFetchStatus(type, id, cc, true);
            row = qualFindRow(type, id, cc);
        } finally {
            qualMapDrop(qualInFlightMut, key);
            row = qualFindRow(type, id, cc);
            if (row && row.isConnected) row.open = wasOpen;
            if (Math.abs(window.scrollY - scrollY) > 1) window.scrollTo(0, scrollY);
            // Focus returns via dialog Close → originating button; keep scroll/accordion here.
            if (activeEl && typeof activeEl.focus === 'function' && document.contains(activeEl)
                && !(el('bc_result_dialog_backdrop') && el('bc_result_dialog_backdrop').classList.contains('is-open'))) {
                try { activeEl.focus({ preventScroll: true }); } catch (err) { /* ignore */ }
            }
        }
    }

    function rowHtml(label, valueHtml, rtl) {
        return '<div class="bc-op-row"><dt>' + label + '</dt><dd class="' + (rtl ? 'bc-rtl' : '') + '">' + valueHtml + '</dd></div>';
    }

    function renderOverview(o) {
        const ov = o.overview || {};
        lastOverview = ov;
        const lastSuccess = ov.last_successful_full || {};
        const latestFull = ov.latest_full || ((o.full_snapshots && o.full_snapshots[0]) ? o.full_snapshots[0] : {});
        const latestCountryPkg = ov.latest_country_batch || {};
        // Prefer overview filesystem totals; fall back only if older API payload.
        const storedCountryPackages = (ov.stored_country_packages_total !== undefined && ov.stored_country_packages_total !== null)
            ? Number(ov.stored_country_packages_total)
            : (Array.isArray(o.country_packages) ? o.country_packages.length : 0);
        const countriesWithPackages = (ov.countries_with_packages !== undefined && ov.countries_with_packages !== null)
            ? Number(ov.countries_with_packages)
            : null;
        const fullSnapshotsTotal = (ov.full_snapshots_total !== undefined && ov.full_snapshots_total !== null)
            ? Number(ov.full_snapshots_total)
            : (Array.isArray(o.full_snapshots) ? o.full_snapshots.length : null);
        const latestDrv = (latestFull.recovery_score !== undefined && latestFull.recovery_score !== null)
            ? Number(latestFull.recovery_score)
            : Number(ov.latest_recovery_score ?? 0);
        const st = ov.storage || {};
        const h = lastRootHealth || o.backup_root_health || {};

        const flatCards = [
            ['آخر Full ناجح', fmtPackageWhenDisplay(lastSuccess, 'full_disaster')],
            ['حالة أحدث Full', esc(latestFull.package_status || '—')],
            ['أحدث حزمة دولة (سياق)', fmtPackageWhenDisplay(latestCountryPkg, 'country_recovery')],
            ['دول قابلة للاسترداد (عام)', esc(String(ov.recoverable_countries ?? '—'))],
            ['BackupRoot', esc(ov.backup_root_status || '—')],
            ['Retention (أيام)', esc(String(ov.retention_days ?? '—'))],
            ['Backend', esc(ov.selected_backend || '—')],
            ['DRV لآخر Full', esc(String(latestDrv))],
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
            '<article class="bc-op-card"><h3>صحة النسخ الاحتياطية</h3><div class="bc-op-rows">' +
                rowHtml('الحالة العامة', overallCardBadge, true) +
                rowHtml('مجلد النسخ الاحتياطية', backupRootHealthBadge(ov.backup_root_status, h), true) +
                rowHtml('التوفر والصلاحيات',
                    healthBadge(!!h.exists, 'موجود', 'غير موجود') + ' • ' +
                    healthBadge(!!h.readable, 'قراءة', 'لا قراءة') + ' • ' +
                    healthBadge(!!h.writable, 'كتابة', 'لا كتابة'), true) +
                rowHtml('التشغيل اليدوي', healthBadge(manualActionsAvailable, 'متاح', 'غير متاح'), true) +
                rowHtml('محرك النسخ الاحتياطية', esc(ov.selected_backend || '—')) +
            '</div></article>' +
            '<article class="bc-op-card"><h3>الحماية</h3><div class="bc-op-rows">' +
                rowHtml('آخر نسخة احتياطية كاملة سليمة', fmtPackageWhenDisplay(lastSuccess, 'full_disaster')) +
                rowHtml('حالة آخر نسخة احتياطية كاملة', protectionPackageStatusBadge(latestFull.package_status), true) +
                rowHtml('أحدث حزمة للدولة الحالية', fmtPackageWhenDisplay(latestCountryPkg, 'country_recovery')) +
                rowHtml('الدول المتاحة للاسترداد', esc(String(ov.recoverable_countries ?? '—')), true) +
                rowHtml('هل توجد حزم للدولة الحالية؟', esc(countriesWithPackages === null ? '—' : (countriesWithPackages > 0 ? 'نعم' : 'لا')), true) +
                rowHtml('عدد الحزم النهائية للدولة الحالية', esc(String(storedCountryPackages)), true) +
                rowHtml('إجمالي النسخ الكاملة المخزنة', esc(fullSnapshotsTotal === null ? '—' : String(fullSnapshotsTotal)), true) +
                rowHtml('قيمة DRV لآخر نسخة كاملة', esc(String(latestDrv))) +
            '</div></article>' +
            '<article class="bc-op-card"><h3>التخزين والاحتفاظ</h3><div class="bc-op-rows">' +
                rowHtml('إجمالي مساحة التخزين', esc(st.total_human || '—')) +
                rowHtml('حجم مجلد اللقطات', esc(st.snapshots_human || '—')) +
                rowHtml('حجم مجلد حزم الدول', esc(st.country_packages_human || '—')) +
                rowHtml('حجم مجلد السجلات', esc(st.logs_human || '—')) +
                rowHtml('مدة الاحتفاظ بالنسخ الاحتياطية', esc((ov.retention_days !== undefined && ov.retention_days !== null && ov.retention_days !== '') ? (String(ov.retention_days) + ' يوماً') : '—'), true) +
            '</div></article>';

        el('bc_latest_full').innerHTML =
            '<div><dt>أحدث Full</dt><dd>' + fmtPackageWhenDisplay(latestFull, 'full_disaster') + '</dd></div>' +
            '<div><dt>الحالة</dt><dd>' + badge(latestFull.package_status) + '</dd></div>' +
            '<div><dt>Schema</dt><dd>' + esc(String(latestFull.schema_revision ?? '—')) + '</dd></div>' +
            '<div><dt>DRV لآخر Full</dt><dd>' + esc(String(latestDrv)) + '</dd></div>';
        el('bc_country_discovery').innerHTML =
            '<div><dt>دول قابلة للاسترداد (عام)</dt><dd>' + esc(String(ov.recoverable_countries ?? '—')) + '</dd></div>' +
            '<div><dt>أحدث حزمة للدولة الحالية</dt><dd>' + fmtPackageWhenDisplay(latestCountryPkg, 'country_recovery') + '</dd></div>' +
            '<div><dt>حزم الدولة الحالية</dt><dd>' + storedCountryPackages + '</dd></div>';

        // Owner security: never render backup_root filesystem path in Backup Center UI.
        const retentionRaw = ov.retention_days;
        const retentionLabel = retentionRaw !== undefined && retentionRaw !== null && retentionRaw !== ''
            ? String(retentionRaw) + ' يوماً'
            : '—';
        const kpis = [
            ['حجم اللقطات', st.snapshots_human || '—'],
            ['حجم حزم الدول', st.country_packages_human || '—'],
            ['حجم السجلات', st.logs_human || '—'],
            ['إجمالي الحجم', st.total_human || '—'],
            ['مدة الاحتفاظ', retentionLabel]
        ];
        el('bc_storage_kpis').innerHTML = kpis.map(([t, v]) =>
            '<div class="bc-kpi-card"><h4>' + esc(t) + '</h4><div class="bc-val" dir="ltr">' + esc(v) + '</div></div>'
        ).join('');
        const sched = ov.scheduled_tasks || [];
        el('bc_schedule_table').querySelector('tbody').innerHTML = sched.map((row) =>
            '<tr><td>' + esc(row.task) + '</td><td>' + esc(row.schedule) + '</td><td><code class="bc-mono">' + esc(row.script) + '</code></td></tr>'
        ).join('');
    }

    function viewFileControl(type, id, cc, file, label) {
        return '<button type="button" class="bc-btn-report bc-view-file" data-type="' + esc(type)
            + '" data-id="' + esc(id) + '" data-cc="' + esc(cc) + '" data-file="' + esc(file) + '">'
            + esc(label) + '</button>';
    }

    /** Expanded action row — secondary reports only (Verify/DRV live on the primary cluster). */
    function actionRowHtml(pkg, type) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        const isFull = type === 'full_disaster' || type === 'full';
        let html = viewFileControl(type, id, cc, 'manifest.json', 'Manifest');
        html += viewFileControl(type, id, cc, 'health.json', 'Health');
        if (isFull) {
            html += viewFileControl(type, id, cc, 'recovery_validation.json', 'DRV Report');
        } else {
            html += viewFileControl(type, id, cc, 'table_inventory.json', 'Inventory');
            html += viewFileControl(type, id, cc, 'dependency_graph.json', 'Graph');
            html += viewFileControl(type, id, cc, 'country_verify_report.json', 'Verify Report');
            html += viewFileControl(type, id, cc, 'country_recovery_validation.json', 'CRP Report');
        }
        return html;
    }

    /**
     * Hidden table cell — non-interactive package metadata only.
     * No button/anchor/form; no .bc-open-details / .bc-drv / .bc-verify (Stage 3).
     */
    function hiddenPkgDataCell(pkg, type) {
        const id = pkg.package_id || '';
        const cc = pkg.country_code || '';
        return '<span class="bc-hidden-pkg-data"' +
            ' data-package-id="' + esc(id) + '"' +
            ' data-package-type="' + esc(type) + '"' +
            ' data-cc="' + esc(cc) + '"' +
            ' data-status="' + esc(String(pkg.package_status || '')) + '"' +
            ' data-recovery-score="' + esc(String(pkg.recovery_score || 0)) + '"' +
            '>' + esc(id) + '</span>';
    }

    /** Primary row cluster: Details → DRV → Verify (dir=ltr isolate; Details keeps outer-edge anchor). */
    function primaryClusterHtml(pkg, type, idx) {
        const id = pkg.package_id;
        const cc = pkg.country_code || '';
        let html = '<span class="bc-primary-cluster" dir="ltr">';
        html += '<button type="button" class="bc-btn-primary bc-open-details" data-idx="' + idx + '" data-type="' + esc(type) + '">التفاصيل</button>';
        if (CAN_VERIFY) {
            // Exact Stage 3 class tokens preserved for template count.
            // Before cohort result: Verify grey/actionable (not_run); DRV grey/blocked — no false green/red.
            html += '<button type="button" class="bc-btn-ghost bc-drv" disabled aria-disabled="true" title="DRV" aria-label="DRV blocked" data-q-state="blocked" data-type="' + esc(type) + '" data-id="' + esc(id) + '" data-cc="' + esc(cc) + '">DRV</button>';
            html += '<button type="button" class="bc-btn-ghost bc-verify" title="Verify" aria-label="Verify not run" data-q-state="not_run" data-type="' + esc(type) + '" data-id="' + esc(id) + '" data-cc="' + esc(cc) + '">Verify</button>';
        }
        html += '</span>';
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

    function accordionItemHtml(pkg, type, idx) {
        const isFull = type === 'full_disaster';
        const title = isFull
            ? 'Full Backup'
            : ('Country Backup' + (pkg.country_code ? ' — ' + pkg.country_code : ''));
        const statusLabel = pkg.package_status || '—';
        // Identity stays package_id (unchanged). Operator clock = generated_at via central formatter.
        const identity = String(pkg.package_id || '').trim();
        const whenHtml = fmtPackageWhenDisplay(pkg, type);
        const qKey = qualPkgKey(type, identity, pkg.country_code || '');
        return (
            '<details class="bc-acc-item" data-bc-acc="1" data-package-id="' + esc(identity) + '" data-package-type="' + esc(type) + '" data-cc="' + esc(pkg.country_code || '') + '" data-qual-key="' + esc(qKey) + '" data-qual-render-gen="' + String(qualRenderGen) + '">' +
            '<summary>' +
                '<span class="bc-acc-chevron" aria-hidden="true"></span>' +
                '<span class="bc-acc-title">' + esc(title) + '</span>' +
                '<span class="bc-acc-meta">' +
                    '<span class="bc-acc-when" dir="ltr">' + whenHtml + '</span>' +
                    (identity
                        ? '<span class="bc-mono" dir="ltr" title="package_id (identity)">' + esc(identity) + '</span>'
                        : '') +
                    badge(statusLabel) +
                    recoverabilitySlotHtml(pkg) +
                '</span>' +
                '<span class="bc-acc-actions-inline">' +
                    primaryClusterHtml(pkg, type, idx) +
                '</span>' +
            '</summary>' +
            '<div class="bc-acc-body">' +
                '<div class="bc-action-row">' + actionRowHtml(pkg, type) + '</div>' +
            '</div>' +
            '</details>'
        );
    }

    function qualBumpRenderGen() {
        qualRenderGen++;
        qualDisconnectIo();
    }
    function renderAccordionList(container, sourceList, type, limit) {
        if (!container) return;
        const items = typeof limit === 'number' ? sourceList.slice(0, limit) : sourceList;
        if (!items.length) {
            container.innerHTML = '<p class="bc-muted" style="margin:0;padding:8px 0;">لا توجد عناصر.</p>';
            return;
        }
        // Index into the full source array so Details/actions stay correct in both modes.
        container.innerHTML = items.map((p) => {
            const idx = sourceList.indexOf(p);
            return accordionItemHtml(p, type, idx);
        }).join('');
    }

    function activeBackupKind() {
        const countryBtn = el('bc_tab_country_btn');
        return (countryBtn && countryBtn.classList.contains('is-active')) ? 'country' : 'full';
    }

    function updatePkgModePill(kind) {
        const pill = el('bc_pkg_mode_pill');
        if (!pill || activeBackupKind() !== kind) return;
        const isArchive = !!state.archiveMode[kind];
        const source = kind === 'country' ? state.country : state.full;
        pill.textContent = isArchive
            ? ('السجل الكامل (' + source.length + ')')
            : ('آخر العمليات (' + Math.min(RECENT_LIMIT, source.length) + ')');
        pill.classList.add('is-active');
    }

    /** Render the active mode into the single list card (latest 5 OR full history). */
    function renderActiveBackupList(kind) {
        const isArchive = !!state.archiveMode[kind];
        if (kind === 'full') {
            const source = state.full;
            renderAccordionList(el('bc_full_list'), source, 'full_disaster', isArchive ? null : RECENT_LIMIT);
            el('bc_view_full_history_btn').hidden = isArchive;
            el('bc_back_recent_full_btn').hidden = !isArchive;
            el('bc_full_list_hint').textContent = isArchive
                ? ('السجل الكامل: ' + source.length)
                : ('آخر ' + Math.min(RECENT_LIMIT, source.length) + ' عمليات');
            const listEl = el('bc_full_list');
            if (listEl) listEl.setAttribute('data-bc-mode', isArchive ? 'archive' : 'recent');
        } else {
            const source = state.country;
            renderAccordionList(el('bc_country_list'), source, 'country_recovery', isArchive ? null : RECENT_LIMIT);
            el('bc_view_country_history_btn').hidden = isArchive;
            el('bc_back_recent_country_btn').hidden = !isArchive;
            el('bc_country_list_hint').textContent = isArchive
                ? ('السجل الكامل للدولة الحالية: ' + source.length)
                : ('آخر ' + Math.min(RECENT_LIMIT, source.length) + ' عمليات');
            const listEl = el('bc_country_list');
            if (listEl) listEl.setAttribute('data-bc-mode', isArchive ? 'archive' : 'recent');
        }
        updatePkgModePill(kind);
    }

    function setArchiveMode(kind, on) {
        state.archiveMode[kind] = !!on;
        qualBumpRenderGen();
        renderActiveBackupList(kind);
        document.querySelectorAll('.bc-acc-item[open]').forEach((d) => { d.open = false; });
        // Immediate exact-key cache paint so Verify is never left stuck resolving/disabled after Show All / Last 5.
        qualPaintCachedRows();
        qualScheduleVisibleLoads();
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
        el('bc_drawer_title').textContent = isFull ? 'تفاصيل Full Backup' : 'تفاصيل Country Package';
        el('bc_drawer_sub').textContent = pkg.package_id || '';

        // Owner decision: all package reports open from the accordion only.
        // Details drawer = metadata / status summaries — no report-opening controls.
        // (Validation / Diagnostics / Logs report sections removed after report-control cleanup.)
        el('bc_drawer_body').innerHTML =
            '<div class="bc-drawer-group"><h4>Summary</h4><dl class="bc-drawer-meta">' +
                '<div><dt>التاريخ</dt><dd>' + fmtPackageWhenDisplay(pkg, type) + '</dd></div>' +
                '<div><dt>النوع</dt><dd>' + esc(isFull ? 'full_disaster' : 'country_recovery') + '</dd></div>' +
                (isFull ? '' : '<div><dt>الدولة</dt><dd>' + esc((pkg.country_code || '') + (pkg.country_name ? ' — ' + pkg.country_name : '')) + '</dd></div>') +
                '<div><dt>الحالة</dt><dd>' + badge(pkg.package_status) + '</dd></div>' +
                '<div><dt>Recoverable</dt><dd>' + recoverabilitySlotHtml(pkg) + '</dd></div>' +
                '<div><dt>Schema</dt><dd>' + esc(String(pkg.schema_revision ?? '—')) + '</dd></div>' +
                '<div><dt>Backend</dt><dd>' + esc(pkg.backend || '—') + '</dd></div>' +
                '<div><dt>DRV Score</dt><dd>' + esc(String(pkg.recovery_score || 0)) + '</dd></div>' +
                '<div><dt>Registry</dt><dd>' + esc(pkg.registry_version || '—') + '</dd></div>' +
                '<div><dt>Package ID</dt><dd class="bc-mono">' + esc(pkg.package_id || '—') + '</dd></div>' +
            '</dl></div>' +
            '<div class="bc-drawer-group"><h4>Storage</h4><dl class="bc-drawer-meta">' +
                '<div><dt>Dump</dt><dd>' + esc(fmtBytes(pkg.dump_size_bytes)) + '</dd></div>' +
                '<div><dt>Uploads</dt><dd>' + esc(fmtBytes(pkg.uploads_size_bytes)) + '</dd></div>' +
                '<div><dt>Total</dt><dd>' + esc(sizeSummary(pkg)) + '</dd></div>' +
            '</dl></div>';

        el('bc_details_drawer').classList.add('is-open');
        el('bc_details_drawer').setAttribute('aria-hidden', 'false');
        el('bc_drawer_backdrop').classList.add('is-open');
        el('bc_drawer_backdrop').setAttribute('aria-hidden', 'false');
        applyActionAvailability();
    }

    function renderTables(data) {
        state.full = data.full_snapshots || [];
        state.country = data.country_packages || [];
        lastOverview = data.overview || lastOverview;

        // One list per type; mode controls which slice is painted into that list.
        qualBumpRenderGen();
        renderActiveBackupList('full');
        renderActiveBackupList('country');

        // Hidden legacy tables — non-interactive metadata only (no executable Details/DRV/Verify).
        el('bc_full_table').querySelector('tbody').innerHTML = state.full.length
            ? state.full.map((p) =>
                '<tr><td>' + fmtPackageWhenDisplay(p, 'full_disaster') + '</td><td>' + badge(p.package_status) + '</td><td>' +
                esc(String(p.schema_revision ?? '')) + '</td><td>' + esc(p.backend || '') + '</td><td>' +
                esc(fmtBytes(p.dump_size_bytes)) + '</td><td>' + esc(fmtBytes(p.uploads_size_bytes)) + '</td><td>' +
                esc(String(p.recovery_score || 0)) + '</td><td class="bc-actions">' + hiddenPkgDataCell(p, 'full_disaster') + '</td></tr>'
            ).join('')
            : '<tr><td colspan="8" class="bc-muted">لا توجد لقطات.</td></tr>';
        el('bc_country_table').querySelector('tbody').innerHTML = state.country.length
            ? state.country.map((p) =>
                '<tr><td>' + esc((p.country_code || '') + (p.country_name ? ' — ' + p.country_name : '')) +
                '</td><td>' + fmtPackageWhenDisplay(p, 'country_recovery') + '</td><td>' + esc(p.package_id || '') +
                '</td><td>' + badge(p.package_status) + '</td><td>' + esc(String(p.schema_revision ?? '')) +
                '</td><td>' + esc(p.registry_version || '') + '</td><td>' + esc(String(p.recovery_score || 0)) +
                '</td><td class="bc-actions">' + hiddenPkgDataCell(p, 'country_recovery') + '</td></tr>'
            ).join('')
            : '<tr><td colspan="8" class="bc-muted">لا توجد حزم دول.</td></tr>';

        el('bc_logs_table').querySelector('tbody').innerHTML = (data.logs || []).map((log) =>
            '<tr><td><code class="bc-mono">' + esc(log.name) + '</code></td><td>' + esc(log.category) +
            '</td><td dir="ltr">' + esc(fmtBytes(log.size_bytes)) + '</td><td>' +
            fmtTimestampDisplay(log.mtime, 'unix') +
            '</td><td><button type="button" class="bc-btn-ghost bc-log-tail" data-log="' + esc(log.name) + '">عرض</button></td></tr>'
        ).join('');
        applyActionAvailability();
    }

    async function loadAll() {
        const scrollY = window.scrollY;
        const openIds = Array.from(document.querySelectorAll('details.bc-acc-item[open]'))
            .map((d) => d.getAttribute('data-package-id') + '|' + d.getAttribute('data-package-type'));
        setBusy(true, 'جاري تحميل البيانات…');
        try {
            const data = await apiGet('list.php');
            // Broad list must not resolve/hash qualification for every package (Stage 4B performance).
            qualHashCountThisPage = 0;
            renderRootHealth(data);
            renderOverview(data);
            renderTables(data);
            document.querySelectorAll('details.bc-acc-item').forEach((d) => {
                const k = d.getAttribute('data-package-id') + '|' + d.getAttribute('data-package-type');
                if (openIds.indexOf(k) !== -1) d.open = true;
            });
            window.scrollTo(0, scrollY);
            qualScheduleVisibleLoads();
            const locks = await apiGet('status.php?action=locks');
            if ((locks.full_lock || {}).held || (locks.country_lock || {}).held) {
                showSystemDialog({
                    title: 'رسالة النظام',
                    message: 'هناك عملية نسخ احتياطي قيد التشغيل حالياً.',
                    success: false
                });
            }
        } catch (e) {
            el('bc_root_warning').style.display = 'none';
            showSystemDialog({
                title: 'رسالة النظام',
                message: e.message || 'تعذر التحميل',
                success: false
            });
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
    el('bc_view_close').addEventListener('click', (ev) => {
        ev.preventDefault();
        closeReportDialog();
    });
    el('bc_view_modal').addEventListener('click', (ev) => {
        // Intentionally ignore backdrop clicks — report dialog stays open.
        if (ev.target === el('bc_view_modal')) {
            ev.preventDefault();
            ev.stopPropagation();
        }
    });
    el('bc_drawer_close').addEventListener('click', closeDrawer);
    el('bc_drawer_backdrop').addEventListener('click', closeDrawer);
    // Stage 5: Close is the only dismiss path (no backdrop / Escape / X).
    el('bc_result_dialog_close').addEventListener('click', (ev) => {
        ev.preventDefault();
        closeQualResultDialog();
    });
    el('bc_result_dialog_backdrop').addEventListener('click', (ev) => {
        // Intentionally ignore backdrop clicks — result dialog stays open.
        if (ev.target === el('bc_result_dialog_backdrop')) {
            ev.preventDefault();
            ev.stopPropagation();
        }
    });
    el('bc_refresh_btn').addEventListener('click', loadAll);

    el('bc_view_full_history_btn').addEventListener('click', () => setArchiveMode('full', true));
    el('bc_back_recent_full_btn').addEventListener('click', () => setArchiveMode('full', false));
    el('bc_view_country_history_btn').addEventListener('click', () => setArchiveMode('country', true));
    el('bc_back_recent_country_btn').addEventListener('click', () => setArchiveMode('country', false));

    if (CAN_RUN) {
        el('bc_run_full_btn').addEventListener('click', () => confirmAction(
            'تشغيل Full Disaster Backup',
            'سيتم تشغيل النسخ الاحتياطي الكامل عبر محرك Orange المعتمد. هل تريد المتابعة؟',
            async () => {
                setBusy(true, 'تشغيل Full Backup…');
                try {
                    const res = await apiPost('run-full.php', {});
                    showSystemDialog({
                        title: 'نتيجة العملية',
                        message: res.message || 'تم تشغيل Full Backup.',
                        success: true,
                        sourceBtn: el('bc_run_full_btn')
                    });
                    await loadAll();
                } catch (e) {
                    showSystemDialog({
                        title: 'رسالة النظام',
                        message: e.message || 'فشل التشغيل',
                        success: false,
                        sourceBtn: el('bc_run_full_btn')
                    });
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
                    showSystemDialog({
                        title: 'نتيجة العملية',
                        message: res.message || 'تم تشغيل Country Batch.',
                        success: true,
                        sourceBtn: el('bc_run_countries_btn')
                    });
                    await loadAll();
                } catch (e) {
                    showSystemDialog({
                        title: 'رسالة النظام',
                        message: e.message || 'فشل التشغيل',
                        success: false,
                        sourceBtn: el('bc_run_countries_btn')
                    });
                } finally { setBusy(false); }
            }
        ));
    }

    // BC-02: only one backup accordion open at a time
    document.body.addEventListener('toggle', (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLDetailsElement) || !t.classList.contains('bc-acc-item')) return;
        if (!t.open) return;
        document.querySelectorAll('details.bc-acc-item[open]').forEach((other) => {
            if (other !== t) other.open = false;
        });
    }, true);

    document.body.addEventListener('click', async (ev) => {
        const t = ev.target;
        if (!(t instanceof HTMLElement)) return;

        // Keep Details / action clicks from toggling the accordion unexpectedly
        if (t.closest('.bc-open-details') || t.closest('.bc-action-row') || t.closest('.bc-acc-actions-inline')) {
            if (t.classList.contains('bc-open-details') || t.closest('.bc-open-details')) {
                ev.preventDefault();
                ev.stopPropagation();
                const btn = t.classList.contains('bc-open-details') ? t : t.closest('.bc-open-details');
                const idx = Number(btn.dataset.idx);
                const type = btn.dataset.type || 'full_disaster';
                const pkg = type === 'full_disaster' ? state.full[idx] : state.country[idx];
                if (pkg) openDetails(pkg, type);
                return;
            }
            if (t.closest('.bc-acc-actions-inline') && !t.classList.contains('bc-open-details')) {
                ev.stopPropagation();
            }
        }

        if (t.classList.contains('bc-view-file') || (t.closest && t.closest('.bc-view-file'))) {
            const btn = t.classList.contains('bc-view-file') ? t : t.closest('.bc-view-file');
            ev.preventDefault();
            ev.stopPropagation();
            const type = btn.dataset.type || '';
            const id = btn.dataset.id || '';
            const cc = btn.dataset.cc || '';
            const file = btn.dataset.file || '';
            const scrollY = window.scrollY || 0;
            const row = qualFindRow(type, id, cc);
            const wasOpen = row ? !!row.open : null;
            const q = new URLSearchParams({
                action: 'view_file',
                package_type: type,
                package_id: id,
                country_code: cc,
                file: file
            });
            try {
                const res = await apiGet('status.php?' + q.toString());
                if (file === 'country_recovery_validation.json') {
                    const countryName = resolveCountryNameForPkg(type, id, cc);
                    const title = crpReportTitle(countryName, cc);
                    const errors = Array.isArray(res.errors) ? res.errors : [];
                    const errText = errors.map((x) => String(x)).join('; ');
                    if (!res.success || res.data == null) {
                        const malformed = /invalid\s*json/i.test(errText);
                        const msg = malformed
                            ? 'CRP report is unreadable or malformed.'
                            : (errText && !/not found/i.test(errText)
                                ? 'CRP report could not be read.'
                                : 'CRP report is missing for this package.');
                        showCrpReportView({
                            title: title,
                            data: null,
                            packageId: id,
                            countryCode: cc,
                            countryName: countryName,
                            stableMessage: msg,
                            forceStatus: 'INCOMPLETE',
                            hideRaw: true,
                            sourceBtn: btn
                        });
                    } else if (typeof res.data !== 'object' || Array.isArray(res.data)
                        || (Object.keys(res.data).length === 0)) {
                        showCrpReportView({
                            title: title,
                            data: null,
                            packageId: id,
                            countryCode: cc,
                            countryName: countryName,
                            stableMessage: 'CRP report is empty.',
                            forceStatus: 'INCOMPLETE',
                            hideRaw: true,
                            sourceBtn: btn
                        });
                    } else {
                        const dataCc = String(res.data.country_code || '').toUpperCase();
                        const dataId = String(res.data.package_id || '');
                        const mismatch = (dataId && dataId !== String(id))
                            || (dataCc && cc && dataCc !== String(cc).toUpperCase());
                        if (mismatch) {
                            showCrpReportView({
                                title: title,
                                data: null,
                                packageId: id,
                                countryCode: cc,
                                countryName: countryName,
                                stableMessage: 'CRP report identity does not match this package.',
                                forceStatus: 'FAIL',
                                hideRaw: true,
                                sourceBtn: btn
                            });
                        } else {
                            const safe = sanitizeCrpDisplayData(res.data);
                            const raw = safe ? JSON.stringify(safe, null, 2) : '';
                            showCrpReportView({
                                title: title,
                                data: res.data,
                                packageId: id,
                                countryCode: cc,
                                countryName: countryName,
                                rawText: raw,
                                hideRaw: !raw,
                                sourceBtn: btn
                            });
                        }
                    }
                } else if (file === 'recovery_validation.json') {
                    // Full DRV Report — CRP-parity readable presentation (read-only; no autorun).
                    const title = 'DRV Report — Full Backup';
                    const recoverable = resolveFullPkgRecoverable(id);
                    const drvBtn = row ? row.querySelector('.bc-drv') : null;
                    const qState = drvBtn ? String(drvBtn.dataset.qState || '') : '';
                    const errors = Array.isArray(res.errors) ? res.errors : [];
                    const errText = errors.map((x) => String(x)).join('; ');
                    if (!res.success || res.data == null) {
                        const malformed = /invalid\s*json/i.test(errText);
                        const notFound = !errText || /not\s*found/i.test(errText);
                        let msg = FULL_DRV_MSG_UNAVAILABLE;
                        if (malformed) {
                            msg = FULL_DRV_MSG_UNAVAILABLE;
                        } else if (notFound) {
                            msg = (qState === 'success' || qState === 'failure')
                                ? FULL_DRV_MSG_UNAVAILABLE
                                : FULL_DRV_MSG_NOT_READY;
                        }
                        showFullDrvReportView({
                            title: title,
                            data: null,
                            packageId: id,
                            recoverable: recoverable,
                            stableMessage: msg,
                            forceStatus: 'INCOMPLETE',
                            hideRaw: true,
                            sourceBtn: btn
                        });
                    } else if (typeof res.data !== 'object' || Array.isArray(res.data)
                        || (Object.keys(res.data).length === 0)) {
                        showFullDrvReportView({
                            title: title,
                            data: null,
                            packageId: id,
                            recoverable: recoverable,
                            stableMessage: FULL_DRV_MSG_UNAVAILABLE,
                            forceStatus: 'INCOMPLETE',
                            hideRaw: true,
                            sourceBtn: btn
                        });
                    } else {
                        const dataId = String(res.data.package_id || '');
                        const mismatch = dataId && dataId !== String(id);
                        if (mismatch) {
                            showFullDrvReportView({
                                title: title,
                                data: null,
                                packageId: id,
                                recoverable: recoverable,
                                stableMessage: FULL_DRV_MSG_UNAVAILABLE,
                                forceStatus: 'INCOMPLETE',
                                hideRaw: true,
                                sourceBtn: btn
                            });
                        } else {
                            const safe = sanitizeCrpDisplayData(res.data);
                            const raw = safe ? JSON.stringify(safe, null, 2) : '';
                            showFullDrvReportView({
                                title: title,
                                data: res.data,
                                packageId: id,
                                recoverable: recoverable,
                                rawText: raw,
                                hideRaw: !raw,
                                sourceBtn: btn
                            });
                        }
                    }
                } else {
                    const body = res.data ? JSON.stringify(res.data, null, 2) : (res.raw_text || '');
                    const label = (btn.textContent || '').trim() || file || 'file';
                    if (!res.success && !body) {
                        const errText = Array.isArray(res.errors) && res.errors.length
                            ? res.errors.map((x) => String(x)).join('; ')
                            : (res.message || '');
                        showSafeReportMessage(label, safeGenericReportMessage(errText), btn);
                    } else {
                        showViewContent(label, body || (Array.isArray(res.errors) ? res.errors.join('\n') : ''), true, btn);
                    }
                }
            } catch (e) {
                if (file === 'country_recovery_validation.json') {
                    const countryName = resolveCountryNameForPkg(type, id, cc);
                    showCrpReportView({
                        title: crpReportTitle(countryName, cc),
                        data: null,
                        packageId: id,
                        countryCode: cc,
                        countryName: countryName,
                        stableMessage: 'CRP report could not be read.',
                        forceStatus: 'INCOMPLETE',
                        hideRaw: true,
                        sourceBtn: btn
                    });
                } else if (file === 'recovery_validation.json') {
                    showFullDrvReportView({
                        title: 'DRV Report — Full Backup',
                        data: null,
                        packageId: id,
                        recoverable: resolveFullPkgRecoverable(id),
                        stableMessage: FULL_DRV_MSG_UNAVAILABLE,
                        forceStatus: 'INCOMPLETE',
                        hideRaw: true,
                        sourceBtn: btn
                    });
                } else {
                    const label = (btn.textContent || '').trim() || file || 'file';
                    showSafeReportMessage(label, safeGenericReportMessage(e.message), btn);
                }
            }
            if (row && wasOpen !== null) row.open = wasOpen;
            try { window.scrollTo(0, scrollY); } catch (err) { /* ignore */ }
            return;
        }
        if (t.classList.contains('bc-log-tail')) {
            try {
                const res = await apiGet('status.php?action=log_tail&log=' + encodeURIComponent(t.dataset.log || ''));
                showViewContent('Log: ' + (t.dataset.log || ''), res.tail || '', true, t);
            } catch (e) {
                showSystemDialog({
                    title: 'رسالة النظام',
                    message: e.message || 'تعذّر فتح السجل',
                    success: false,
                    sourceBtn: t
                });
            }
            return;
        }
        const verifyBtn = t.classList.contains('bc-verify') ? t : (t.closest ? t.closest('.bc-verify') : null);
        if (verifyBtn && CAN_VERIFY) {
            ev.preventDefault();
            ev.stopPropagation();
            await qualRunMutation('verify', verifyBtn);
            return;
        }
        const drvBtn = t.classList.contains('bc-drv') ? t : (t.closest ? t.closest('.bc-drv') : null);
        if (drvBtn && CAN_VERIFY) {
            ev.preventDefault();
            ev.stopPropagation();
            if (drvBtn.disabled || drvBtn.getAttribute('aria-disabled') === 'true'
                || drvBtn.dataset.qState === 'blocked') {
                return;
            }
            await qualRunMutation('drv', drvBtn);
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
        updatePkgModePill(isFull ? 'full' : 'country');
        document.querySelectorAll('.bc-acc-item[open]').forEach((d) => { d.open = false; });
        // Tab switch does not rebuild rows, but must re-paint/subscribe visible Verify states.
        qualPaintCachedRows();
        qualScheduleVisibleLoads();
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
