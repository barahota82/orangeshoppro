<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/inventory_reconciliation.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/delivery_agents.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/stocktake_archive.php';

$pdo = orange_admin_page_pdo();
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);

$ready = orange_inventory_reconciliation_ready($pdo);
$warehouses = $ready ? orange_inventory_reconciliation_warehouse_options($pdo, $ctxCountryId) : [];
$agents = $ready ? orange_delivery_agents_admin_list($pdo, $ctxCountryId) : [];
$list = $ready ? orange_inventory_reconciliation_archive_list($pdo, $ctxCountryId, 100) : [];

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editRec = ($editId > 0 && $ready) ? orange_inventory_reconciliation_archive_get($pdo, $editId, $ctxCountryId) : null;

$apiBase = storefront_public_path('/admin/api/inventory-reconciliation');

$defaultWarehouseId = 0;
foreach ($warehouses as $wh) {
    if ((int) ($wh['is_default'] ?? 0) === 1) {
        $defaultWarehouseId = (int) ($wh['id'] ?? 0);
        break;
    }
}
if ($defaultWarehouseId <= 0 && $warehouses !== []) {
    $defaultWarehouseId = (int) ($warehouses[0]['id'] ?? 0);
}

$initial = [
    'id' => 0,
    'warehouse_id' => $defaultWarehouseId,
    'delivery_agent_id' => 0,
    'counted_at' => date('Y-m-d'),
    'notes' => '',
    'sort_order' => 0,
    'attachments' => [],
];

if ($editRec !== null) {
    $h = $editRec['header'];
    $initial = [
        'id' => (int) ($h['id'] ?? 0),
        'warehouse_id' => (int) ($h['warehouse_id'] ?? 0),
        'delivery_agent_id' => (int) ($h['delivery_agent_id'] ?? 0),
        'counted_at' => substr((string) ($h['counted_at'] ?? ''), 0, 10),
        'notes' => (string) ($h['notes'] ?? ''),
        'sort_order' => (int) ($h['sort_order'] ?? 0),
        'attachments' => $editRec['attachments'] ?? [],
    ];
}

$initialJson = json_encode($initial, JSON_UNESCAPED_UNICODE);
if ($initialJson === false) {
    $initialJson = '{}';
}

?>
<style>
.stk-attachments-summary { display:flex; flex-direction:column; gap:6px; max-width:420px; }
.stk-attachments-inline { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:10px; width:100%; }
#stk_attachments_count { max-width:none; width:100%; text-align:center; }
#stk_attachments_manage_btn { width:100%; height:42px; }
.stk-attachments-modal__dialog { width:min(920px, calc(100vw - 24px)); max-height:calc(100vh - 24px); overflow:auto; }
.stk-attachments-toolbar { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto; gap:10px; align-items:end; margin-bottom:10px; }
.stk-attachments-toolbar button { height:42px; }
.stk-attachments-list { border:1px solid #e5e7eb; border-radius:10px; background:#fff; padding:8px; min-height:54px; }
.stk-attachment-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:8px 6px; border-bottom:1px solid #f1f5f9; }
.stk-attachment-row:last-child { border-bottom:0; }
.stk-attachment-main { min-width:0; flex:1 1 auto; }
.stk-attachment-title { font-weight:600; color:#0f172a; line-height:1.35; }
.stk-attachment-meta { margin-top:3px; font-size:12px; color:#64748b; line-height:1.4; }
.stk-attachment-actions { display:flex; align-items:center; gap:8px; flex:0 0 auto; }
@media (max-width:900px) {
    .stk-attachments-inline { grid-template-columns:1fr; }
    .stk-attachments-toolbar { grid-template-columns:1fr; }
    .stk-attachment-row { flex-direction:column; align-items:stretch; }
    .stk-attachment-actions { justify-content:flex-start; }
}
</style>
<div class="admin-fy-shell" dir="rtl" id="stk_arch_app">
    <div class="page-title">
        <h1>أرشيف الجرد</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <?php if (! $ready): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">جداول الجرد غير جاهزة — حدّث المخطط (ACC-10 مرحلة 0).</p>
        </div>
    <?php else: ?>

    <div class="card" id="stk_editor_card">
        <h3 class="card-title" id="stk_editor_title">سجل جرد جديد</h3>

        <div class="stk-top-row" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:12px;">
            <div style="flex:0 0 90px;">
                <label for="stk_sort_order">ترتيب</label>
                <input type="number" id="stk_sort_order" dir="ltr" lang="en" step="1" min="0" value="0" style="width:100%;">
            </div>
            <div style="flex:1 1 220px;min-width:180px;">
                <label for="stk_scope">المخزن / المندوب</label>
                <select id="stk_scope" style="width:100%;">
                    <option value="">— اختر —</option>
                    <?php if ($warehouses !== []): ?>
                        <optgroup label="المخازن">
                            <?php foreach ($warehouses as $wh): ?>
                                <?php
                                $whName = trim((string) ($wh['name_ar'] ?? ''));
                                if ($whName === '') {
                                    $whName = trim((string) ($wh['name_en'] ?? ''));
                                }
                                if ($whName === '') {
                                    $whName = '#' . (int) ($wh['id'] ?? 0);
                                }
                                ?>
                                <option value="w:<?php echo (int) $wh['id']; ?>"><?php echo htmlspecialchars($whName, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ($agents !== []): ?>
                        <optgroup label="المناديب (عهدة)">
                            <?php foreach ($agents as $ag): ?>
                                <option value="a:<?php echo (int) ($ag['id'] ?? 0); ?>"><?php echo htmlspecialchars(orange_delivery_agent_display_name($ag), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div style="flex:0 0 150px;">
                <label for="stk_counted_at">تاريخ الجرد</label>
                <input type="text" id="stk_counted_at" class="orange-inp-dmy" dir="ltr" lang="en" required style="width:100%;">
            </div>
            <div style="flex:0 0 100px;">
                <label for="stk_attachments_count">عدد المرفقات</label>
                <input type="text" id="stk_attachments_count" dir="ltr" lang="en" value="0" readonly style="width:100%;text-align:center;">
            </div>
            <div style="flex:0 0 auto;">
                <label>&nbsp;</label>
                <button type="button" class="btn-secondary" id="stk_attachments_manage_btn" style="display:block;">إدارة المرفقات</button>
            </div>
            <div style="flex:0 0 auto;">
                <label>&nbsp;</label>
                <div style="display:flex;gap:6px;align-items:center;" role="group" aria-label="تنقل بين السجلات">
                    <button type="button" class="btn-secondary" id="stk_nav_first" title="أول سجل" aria-label="أول سجل">&lt;&lt;</button>
                    <button type="button" class="btn-secondary" id="stk_nav_prev" title="السجل السابق" aria-label="السجل السابق">&lt;</button>
                    <button type="button" class="btn-secondary" id="stk_nav_next" title="السجل التالي" aria-label="السجل التالي">&gt;</button>
                    <button type="button" class="btn-secondary" id="stk_nav_last" title="آخر سجل" aria-label="آخر سجل">&gt;&gt;</button>
                    <button type="button" class="btn-secondary" id="stk_nav_search" title="بحث عن سجل">بحث</button>
                </div>
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label for="stk_notes">ملاحظات</label>
            <input type="text" id="stk_notes" style="width:100%;" placeholder="مثال: جرد ربع سنوي — تم بحضور أمين المخزن">
        </div>

        <p id="stk_msg" class="card-hint" style="margin-top:12px;color:#166534;display:none;"></p>
        <p id="stk_err" class="card-hint" style="margin-top:12px;color:#b91c1c;display:none;"></p>

        <div class="actions" style="display:flex;gap:8px;justify-content:flex-start;margin-top:14px;padding-top:12px;border-top:1px solid #e5e7eb;">
            <button type="button" class="btn-secondary" id="stk_btn_new" title="سجل جرد جديد">جديد</button>
            <button type="button" class="btn-danger" id="stk_delete_btn" title="حذف السجل ومرفقاته" style="display:none;">حذف</button>
            <button type="button" id="stk_save_btn">حفظ</button>
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 class="card-title">سجلات الأرشيف (الأحدث)</h3>
        <div class="table-wrap">
            <table class="admin-fy-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ترتيب</th>
                        <th>تاريخ الجرد</th>
                        <th>المخزن / المندوب</th>
                        <th>المرفقات</th>
                        <th>ملاحظة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($list === []): ?>
                        <tr><td colspan="7" class="muted">لا سجلات بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $row): ?>
                            <?php $rid = (int) ($row['id'] ?? 0); ?>
                            <tr<?php echo $rid === $editId ? ' style="background:#fff7ed;"' : ''; ?>>
                                <td><?php echo $rid; ?></td>
                                <td dir="ltr"><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                                <td dir="ltr"><?php echo htmlspecialchars(orange_format_date_dmY(substr((string) ($row['counted_at'] ?? ''), 0, 10)), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['scope_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) ($row['attachment_count'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars((string) ($row['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=inventory_reconciliation&id=' . $rid), ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<div class="gl-pick-modal" id="stk_attachments_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="stk_attachments_backdrop"></div>
    <div class="gl-pick-modal__dialog stk-attachments-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="stk_attachments_title">
        <h3 id="stk_attachments_title" class="gl-pick-modal__title">مرفقات الجرد</h3>
        <p class="gl-pick-modal__hint muted" id="stk_attachments_hint" style="margin:0 0 10px;font-size:0.9rem;">
            PDF / صور / Excel / Word — حتى 20 مرفقاً لكل سجل (حتى 25MB للملف).
        </p>
        <div class="stk-attachments-toolbar">
            <div>
                <label for="stk_attachment_file">اختر ملف</label>
                <input type="file" id="stk_attachment_file" accept=".pdf,image/*,.xlsx,.xls,.docx,.doc">
            </div>
            <div>
                <label for="stk_attachment_name">وصف المرفق</label>
                <input type="text" id="stk_attachment_name" maxlength="191" autocomplete="off" placeholder="اختياري (يؤخذ من اسم الملف)">
            </div>
            <div class="actions" style="margin:0;">
                <button type="button" class="btn-secondary" id="stk_attachment_upload_btn">رفع مرفق</button>
            </div>
        </div>
        <div class="stk-attachments-list" id="stk_attachments_list"></div>
        <div class="actions" style="margin-top:12px;">
            <button type="button" class="btn-secondary" id="stk_attachments_close">إغلاق</button>
        </div>
    </div>
</div>

<div class="gl-pick-modal" id="stk_search_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="stk_search_backdrop"></div>
    <div class="gl-pick-modal__dialog stk-attachments-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="stk_search_title">
        <h3 id="stk_search_title" class="gl-pick-modal__title">بحث عن سجل جرد</h3>
        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));align-items:end;margin-bottom:10px;">
            <div>
                <label for="stk_search_from">تاريخ الجرد — من</label>
                <input type="text" id="stk_search_from" class="orange-inp-dmy admin-inp" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="stk_search_to">تاريخ الجرد — إلى</label>
                <input type="text" id="stk_search_to" class="orange-inp-dmy admin-inp" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="stk_search_scope">المخزن / المندوب</label>
                <select id="stk_search_scope" class="admin-inp" style="width:100%;">
                    <option value="">— الكل —</option>
                    <?php if ($warehouses !== []): ?>
                        <optgroup label="المخازن">
                            <?php foreach ($warehouses as $wh): ?>
                                <?php
                                $whName2 = trim((string) ($wh['name_ar'] ?? ''));
                                if ($whName2 === '') {
                                    $whName2 = trim((string) ($wh['name_en'] ?? ''));
                                }
                                if ($whName2 === '') {
                                    $whName2 = '#' . (int) ($wh['id'] ?? 0);
                                }
                                ?>
                                <option value="w:<?php echo (int) $wh['id']; ?>"><?php echo htmlspecialchars($whName2, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ($agents !== []): ?>
                        <optgroup label="المناديب (عهدة)">
                            <?php foreach ($agents as $ag): ?>
                                <option value="a:<?php echo (int) ($ag['id'] ?? 0); ?>"><?php echo htmlspecialchars(orange_delivery_agent_display_name($ag), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label for="stk_search_notes">ملاحظة (تحتوي النص)</label>
                <input type="text" id="stk_search_notes" class="admin-inp" autocomplete="off" dir="auto">
            </div>
        </div>
        <div class="actions" style="margin:0 0 12px;">
            <button type="button" id="stk_search_run">تنفيذ البحث</button>
        </div>
        <div class="table-wrap">
            <table class="admin-fy-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>ترتيب</th>
                        <th>تاريخ الجرد</th>
                        <th>المخزن / المندوب</th>
                        <th>المرفقات</th>
                        <th>ملاحظة</th>
                    </tr>
                </thead>
                <tbody id="stk_search_results"><tr><td colspan="6" class="muted">حدّد المعايير ثم «تنفيذ البحث» — انقر سجلاً لفتحه.</td></tr></tbody>
            </table>
        </div>
        <p id="stk_search_count" class="card-hint" style="margin-top:8px;"></p>
        <div class="actions" style="margin-top:12px;">
            <button type="button" class="btn-secondary" id="stk_search_close">إغلاق</button>
        </div>
    </div>
</div>
<script>
(function () {
    var API = <?php echo json_encode([
        'save' => $apiBase . '/archive-save.php',
        'delete' => $apiBase . '/archive-delete.php',
        'upload' => $apiBase . '/attachment-upload.php',
        'attDelete' => $apiBase . '/attachment-delete.php',
        'download' => $apiBase . '/attachment-download.php',
        'list' => $apiBase . '/archive-list.php',
        'nav' => $apiBase . '/archive-nav.php',
        'search' => $apiBase . '/archive-search.php',
    ], JSON_UNESCAPED_UNICODE); ?>;
    var state = <?php echo $initialJson; ?>;
    var MAX_ATT = 20;

    function el(id) { return document.getElementById(id); }

    function showErr(msg) {
        el('stk_err').textContent = msg || '';
        el('stk_err').style.display = msg ? 'block' : 'none';
        if (msg) el('stk_msg').style.display = 'none';
    }
    function showOk(msg) {
        el('stk_msg').textContent = msg || '';
        el('stk_msg').style.display = msg ? 'block' : 'none';
        if (msg) el('stk_err').style.display = 'none';
    }

    function fmtSize(bytes) {
        var b = Number(bytes) || 0;
        if (b < 1024) return b + ' B';
        if (b < 1024 * 1024) return (b / 1024).toFixed(1) + ' KB';
        return (b / (1024 * 1024)).toFixed(2) + ' MB';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function downloadUrl(attId, inline) {
        return API.download + '?id=' + (state.id || 0) + '&attachment_id=' + encodeURIComponent(attId) + (inline ? '&inline=1' : '');
    }

    function scopeValue() {
        var wid = parseInt(state.warehouse_id, 10) || 0;
        var aid = parseInt(state.delivery_agent_id, 10) || 0;
        if (aid > 0) return 'a:' + aid;
        if (wid > 0) return 'w:' + wid;
        return '';
    }

    function syncFormFromState() {
        el('stk_scope').value = scopeValue();
        el('stk_counted_at').value = state.counted_at ? orangeIsoDateToDmy(state.counted_at) : '';
        el('stk_notes').value = state.notes || '';
        if (el('stk_sort_order')) el('stk_sort_order').value = String(parseInt(state.sort_order, 10) || 0);
        el('stk_editor_title').textContent = state.id ? ('سجل جرد #' + state.id) : 'سجل جرد جديد';
        el('stk_delete_btn').style.display = state.id ? 'inline-block' : 'none';
        renderAttachments();
    }

    function attRows() { return state.attachments || []; }

    function renderAttachments() {
        var rows = attRows();
        var hasId = !!state.id;
        var countEl = el('stk_attachments_count');
        var manageBtn = el('stk_attachments_manage_btn');
        var upBtn = el('stk_attachment_upload_btn');
        var hint = el('stk_attachments_hint');
        if (countEl) countEl.value = String(rows.length);
        if (manageBtn) {
            manageBtn.disabled = !hasId;
            manageBtn.textContent = rows.length > 0 ? ('إدارة المرفقات (' + rows.length + ')') : 'إدارة المرفقات';
        }
        if (upBtn) upBtn.disabled = !hasId || rows.length >= MAX_ATT;
        if (hint) {
            hint.textContent = !hasId
                ? 'احفظ السجل أولاً ثم افتح إدارة المرفقات.'
                : ('عدد المرفقات: ' + rows.length + ' / ' + MAX_ATT + ' — PDF / صور / Excel / Word، حتى 25MB للملف.');
        }
        var list = el('stk_attachments_list');
        if (!list) return;
        if (rows.length === 0) {
            list.innerHTML = '<div class="card-hint">لا توجد مرفقات حالياً.</div>';
            return;
        }
        list.innerHTML = rows.map(function (item) {
            var title = String(item.name || item.original_name || 'مرفق').trim();
            var meta = [];
            if (item.size > 0) meta.push(fmtSize(item.size));
            if (item.mime) meta.push(String(item.mime));
            if (item.uploaded_at) meta.push(String(item.uploaded_at).replace('T', ' ').substring(0, 16));
            var viewable = /^image\//.test(item.mime || '') || (item.mime || '') === 'application/pdf';
            return ''
                + '<div class="stk-attachment-row">'
                + '  <div class="stk-attachment-main">'
                + '    <div class="stk-attachment-title">' + escapeHtml(title) + '</div>'
                + '    <div class="stk-attachment-meta">' + escapeHtml(meta.join(' — ')) + '</div>'
                + '  </div>'
                + '  <div class="stk-attachment-actions">'
                + (viewable ? ('<a class="btn btn-secondary" target="_blank" rel="noopener" href="' + downloadUrl(item.id, true) + '">عرض</a>') : '')
                + '    <a class="btn btn-secondary" href="' + downloadUrl(item.id, false) + '">تحميل</a>'
                + '    <button type="button" class="btn-danger" data-stk-att-del="' + escapeHtml(item.id) + '">حذف</button>'
                + '  </div>'
                + '</div>';
        }).join('');
        list.querySelectorAll('[data-stk-att-del]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var attId = String(btn.getAttribute('data-stk-att-del') || '').trim();
                if (attId) attDelete(attId);
            });
        });
    }

    function modalOpen() {
        if (!state.id) { showErr('احفظ السجل أولاً ثم أدر المرفقات'); return; }
        var modal = el('stk_attachments_modal');
        if (!modal) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        renderAttachments();
        var f = el('stk_attachment_file');
        if (f) f.focus();
    }
    function modalClose() {
        var modal = el('stk_attachments_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    }

    function payloadFromForm() {
        var sc = el('stk_scope').value || '';
        var wid = 0, aid = 0;
        if (sc.indexOf('w:') === 0) wid = parseInt(sc.substring(2), 10) || 0;
        else if (sc.indexOf('a:') === 0) aid = parseInt(sc.substring(2), 10) || 0;
        return {
            id: state.id || 0,
            warehouse_id: wid,
            delivery_agent_id: aid,
            counted_at: orangeGetDmyValueAsIso(el('stk_counted_at')) || '',
            notes: el('stk_notes').value || '',
            sort_order: parseInt(el('stk_sort_order') ? el('stk_sort_order').value : 0, 10) || 0
        };
    }

    function applyRec(rec) {
        if (!rec || !rec.header) return;
        var h = rec.header;
        state.id = parseInt(h.id, 10) || 0;
        state.warehouse_id = parseInt(h.warehouse_id, 10) || 0;
        state.delivery_agent_id = parseInt(h.delivery_agent_id, 10) || 0;
        state.counted_at = (h.counted_at || '').substring(0, 10);
        state.notes = h.notes || '';
        state.sort_order = parseInt(h.sort_order, 10) || 0;
        state.attachments = rec.attachments || [];
        syncFormFromState();
    }

    async function postJson(url, body) {
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        });
        return res.json();
    }

    el('stk_save_btn') && el('stk_save_btn').addEventListener('click', async function () {
        showErr('');
        var data = await postJson(API.save, payloadFromForm());
        if (!data.success) { showErr(data.message || 'فشل الحفظ'); return; }
        showOk(data.message || 'تم الحفظ');
        applyRec(data.record);
        if (state.id && !window.location.search.includes('id=')) {
            history.replaceState(null, '', '?page=inventory_reconciliation&id=' + state.id);
        }
    });

    el('stk_delete_btn') && el('stk_delete_btn').addEventListener('click', async function () {
        if (!state.id || !confirm('حذف هذا السجل وكل مرفقاته نهائياً؟')) return;
        var data = await postJson(API.delete, { id: state.id });
        if (!data.success) { showErr(data.message); return; }
        window.location.href = '?page=inventory_reconciliation';
    });

    el('stk_attachments_manage_btn') && el('stk_attachments_manage_btn').addEventListener('click', modalOpen);
    el('stk_attachments_close') && el('stk_attachments_close').addEventListener('click', modalClose);
    el('stk_attachments_backdrop') && el('stk_attachments_backdrop').addEventListener('click', modalClose);

    el('stk_attachment_upload_btn') && el('stk_attachment_upload_btn').addEventListener('click', async function () {
        if (!state.id) { alert('احفظ السجل أولاً'); return; }
        if (attRows().length >= MAX_ATT) { alert('بلغت الحد الأقصى للمرفقات'); return; }
        var fileInp = el('stk_attachment_file');
        if (!fileInp.files || !fileInp.files.length) { alert('اختر ملفاً للرفع'); return; }
        var fd = new FormData();
        fd.append('id', String(state.id));
        fd.append('attachment_name', el('stk_attachment_name').value || '');
        fd.append('file', fileInp.files[0]);
        var btn = el('stk_attachment_upload_btn');
        btn.disabled = true;
        try {
            var res = await fetch(API.upload, { method: 'POST', credentials: 'same-origin', body: fd });
            var data = {};
            try { data = await res.json(); } catch (e) { data = {}; }
            if (!data.success) { alert(data.message || 'تعذر رفع المرفق'); return; }
            state.attachments = data.attachments || [];
            fileInp.value = '';
            el('stk_attachment_name').value = '';
            alert(data.message || 'تم رفع المرفق');
        } catch (e) {
            alert('تعذر الرفع');
        } finally {
            btn.disabled = false;
            renderAttachments();
        }
    });

    async function attDelete(attId) {
        if (!attId || !confirm('سيتم حذف المرفق نهائياً. هل تريد المتابعة؟')) return;
        var data = await postJson(API.attDelete, { id: state.id, attachment_id: attId });
        if (!data || !data.success) { alert((data && data.message) || 'تعذر حذف المرفق'); return; }
        state.attachments = data.attachments || [];
        renderAttachments();
        alert(data.message || 'تم حذف المرفق');
    }

    function gotoRecord(id) {
        if (id > 0) window.location.href = '?page=inventory_reconciliation&id=' + id;
    }

    el('stk_btn_new') && el('stk_btn_new').addEventListener('click', function () {
        window.location.href = '?page=inventory_reconciliation';
    });

    // ---- التنقّل بين السجلات (أول/سابق/تالي/آخر) من الخادم ----
    async function navTo(dir) {
        var url = API.nav + '?dir=' + encodeURIComponent(dir) + '&current=' + (parseInt(state.id, 10) || 0);
        try {
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json();
            if (!data.success) { showErr(data.message || 'تعذر التنقّل'); return; }
            if (data.id > 0 && data.id !== (parseInt(state.id, 10) || 0)) {
                gotoRecord(data.id);
            }
        } catch (e) {
            showErr('تعذر التنقّل');
        }
    }
    el('stk_nav_first') && el('stk_nav_first').addEventListener('click', function () { navTo('first'); });
    el('stk_nav_prev') && el('stk_nav_prev').addEventListener('click', function () { navTo('prev'); });
    el('stk_nav_next') && el('stk_nav_next').addEventListener('click', function () { navTo('next'); });
    el('stk_nav_last') && el('stk_nav_last').addEventListener('click', function () { navTo('last'); });

    // ---- نافذة البحث ----
    function searchModalOpen() {
        var m = el('stk_search_modal');
        if (!m) return;
        m.hidden = false;
        m.setAttribute('aria-hidden', 'false');
        var f = el('stk_search_from');
        if (f) setTimeout(function () { f.focus(); }, 50);
    }
    function searchModalClose() {
        var m = el('stk_search_modal');
        if (!m) return;
        m.hidden = true;
        m.setAttribute('aria-hidden', 'true');
    }

    function searchRowHtml(r) {
        var dmy = r.counted_at ? orangeIsoDateToDmy(String(r.counted_at).substring(0, 10)) : '';
        var id = parseInt(r.id, 10) || 0;
        return '<tr class="stk-search-row" data-id="' + id + '" style="cursor:pointer;">'
            + '<td>' + id + '</td>'
            + '<td dir="ltr">' + (parseInt(r.sort_order, 10) || 0) + '</td>'
            + '<td dir="ltr">' + escapeHtml(dmy) + '</td>'
            + '<td>' + escapeHtml(r.scope_label || '') + '</td>'
            + '<td>' + (parseInt(r.attachment_count, 10) || 0) + '</td>'
            + '<td>' + escapeHtml(r.notes || '') + '</td>'
            + '</tr>';
    }

    el('stk_nav_search') && el('stk_nav_search').addEventListener('click', searchModalOpen);
    el('stk_search_close') && el('stk_search_close').addEventListener('click', searchModalClose);
    el('stk_search_backdrop') && el('stk_search_backdrop').addEventListener('click', searchModalClose);

    el('stk_search_run') && el('stk_search_run').addEventListener('click', async function () {
        var from = orangeGetDmyValueAsIso(el('stk_search_from')) || '';
        var to = orangeGetDmyValueAsIso(el('stk_search_to')) || '';
        var scope = el('stk_search_scope') ? el('stk_search_scope').value : '';
        var notes = el('stk_search_notes') ? el('stk_search_notes').value : '';
        var body = el('stk_search_results');
        var cnt = el('stk_search_count');
        body.innerHTML = '<tr><td colspan="6" class="muted">جارٍ التحميل…</td></tr>';
        cnt.textContent = '';
        var url = API.search + '?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to)
            + '&scope=' + encodeURIComponent(scope) + '&notes=' + encodeURIComponent(notes);
        try {
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json();
            if (!data.success) { body.innerHTML = '<tr><td colspan="6" class="muted">' + escapeHtml(data.message || 'تعذر البحث') + '</td></tr>'; return; }
            var rows = data.records || [];
            if (rows.length === 0) {
                body.innerHTML = '<tr><td colspan="6" class="muted">لا نتائج مطابقة.</td></tr>';
            } else {
                body.innerHTML = rows.map(searchRowHtml).join('');
            }
            cnt.textContent = 'عدد النتائج: ' + rows.length;
        } catch (e) {
            body.innerHTML = '<tr><td colspan="6" class="muted">تعذر البحث</td></tr>';
        }
    });

    el('stk_search_results') && el('stk_search_results').addEventListener('click', function (ev) {
        var tr = ev.target && ev.target.closest ? ev.target.closest('.stk-search-row') : null;
        if (!tr) return;
        var id = parseInt(tr.getAttribute('data-id'), 10) || 0;
        if (id > 0) gotoRecord(id);
    });

    syncFormFromState();
})();
</script>
