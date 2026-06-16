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
        'attachments' => $editRec['attachments'] ?? [],
    ];
}

$initialJson = json_encode($initial, JSON_UNESCAPED_UNICODE);
if ($initialJson === false) {
    $initialJson = '{}';
}

?>
<div class="admin-fy-shell" dir="rtl" id="stk_arch_app">
    <div class="page-title">
        <h1>أرشيف الجرد</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <div class="card" style="border:1px solid #bfdbfe;background:#eff6ff;margin-bottom:12px;">
        <p style="margin:0;">أرشيف لرفع <strong>تقرير الجرد المطبوع الموقّع</strong> (PDF / صور / Excel / Word) لكل عملية جرد تمّت. كل سجل: المخزن أو المندوب + تاريخ الجرد + ملاحظة + المرفقات. <strong>لا يُطبَّق على المخزون ولا يُنشئ قيداً</strong> — تطبيق فروق الجرد يتم عبر <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock_adjustment_voucher'), ENT_QUOTES, 'UTF-8'); ?>">قيد تسوية مخزون</a>. تقرير الجرد القابل للطباعة من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock_reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير المخزن ← الجرد</a>.</p>
    </div>

    <?php if (! $ready): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">جداول الجرد غير جاهزة — حدّث المخطط (ACC-10 مرحلة 0).</p>
        </div>
    <?php else: ?>

    <p class="actions" style="margin:0 0 16px;">
        <button type="button" class="btn-secondary" id="stk_btn_new">سجل جرد جديد</button>
    </p>

    <div class="card" style="margin-bottom:16px;">
        <h3 class="card-title">سجلات الأرشيف</h3>
        <div class="table-wrap">
            <table class="admin-fy-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>تاريخ الجرد</th>
                        <th>المخزن / المندوب</th>
                        <th>المرفقات</th>
                        <th>ملاحظة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($list === []): ?>
                        <tr><td colspan="6" class="muted">لا سجلات بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $row): ?>
                            <?php $rid = (int) ($row['id'] ?? 0); ?>
                            <tr<?php echo $rid === $editId ? ' style="background:#fff7ed;"' : ''; ?>>
                                <td><?php echo $rid; ?></td>
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

    <div class="card" id="stk_editor_card">
        <h3 class="card-title" id="stk_editor_title">سجل جرد جديد</h3>

        <div class="admin-fy-form-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:16px;">
            <div>
                <label for="stk_scope">المخزن / المندوب</label>
                <select id="stk_scope">
                    <option value="">— اختر —</option>
                    <?php if ($warehouses !== []): ?>
                        <optgroup label="المخازن">
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="w:<?php echo (int) $wh['id']; ?>"><?php echo htmlspecialchars($wh['label'], ENT_QUOTES, 'UTF-8'); ?></option>
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
                <label for="stk_counted_at">تاريخ الجرد</label>
                <input type="text" id="stk_counted_at" class="orange-inp-dmy" dir="ltr" lang="en" required>
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label for="stk_notes">ملاحظة</label>
            <input type="text" id="stk_notes" style="width:100%;max-width:640px;" placeholder="مثال: جرد ربع سنوي — تم بحضور أمين المخزن">
        </div>

        <p class="actions" style="margin-top:4px;">
            <button type="button" id="stk_save_btn">حفظ السجل</button>
            <button type="button" class="btn-danger" id="stk_delete_btn" style="display:none;">حذف السجل ومرفقاته</button>
        </p>

        <div id="stk_attach_section" style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;">
            <h4>مرفقات الجرد</h4>
            <p class="card-hint" id="stk_attach_hint">احفظ بيانات السجل أولاً ثم ارفع تقرير الجرد المطبوع الموقّع (PDF / صورة / Excel / Word). الحد 25 ميجابايت لكل ملف.</p>

            <div id="stk_attach_uploader" style="display:none;margin-bottom:14px;">
                <div style="display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));align-items:end;max-width:760px;">
                    <div>
                        <label for="stk_file">الملف</label>
                        <input type="file" id="stk_file" accept=".pdf,image/*,.xlsx,.xls,.docx,.doc">
                    </div>
                    <div>
                        <label for="stk_file_name">وصف المرفق (اختياري)</label>
                        <input type="text" id="stk_file_name" placeholder="مثال: ورقة المخزن الرئيسي">
                    </div>
                    <div>
                        <button type="button" id="stk_upload_btn">رفع المرفق</button>
                    </div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="admin-fy-table" id="stk_attach_table">
                    <thead>
                        <tr>
                            <th>الوصف</th>
                            <th>النوع</th>
                            <th>الحجم</th>
                            <th>تاريخ الرفع</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="stk_attach_body"></tbody>
                </table>
            </div>
            <p id="stk_attach_empty" class="card-hint" style="display:none;">لا مرفقات بعد.</p>
        </div>

        <p id="stk_msg" class="card-hint" style="margin-top:12px;color:#166534;display:none;"></p>
        <p id="stk_err" class="card-hint" style="margin-top:12px;color:#b91c1c;display:none;"></p>
    </div>

    <?php endif; ?>
</div>
<script>
(function () {
    var API = <?php echo json_encode([
        'save' => $apiBase . '/archive-save.php',
        'delete' => $apiBase . '/archive-delete.php',
        'upload' => $apiBase . '/attachment-upload.php',
        'attDelete' => $apiBase . '/attachment-delete.php',
        'download' => $apiBase . '/attachment-download.php',
    ], JSON_UNESCAPED_UNICODE); ?>;
    var state = <?php echo $initialJson; ?>;

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
        el('stk_editor_title').textContent = state.id ? ('سجل جرد #' + state.id) : 'سجل جرد جديد';
        el('stk_delete_btn').style.display = state.id ? 'inline-block' : 'none';
        var hasId = !!state.id;
        el('stk_attach_uploader').style.display = hasId ? 'block' : 'none';
        el('stk_attach_hint').style.display = hasId ? 'none' : 'block';
        renderAttachments();
    }

    function renderAttachments() {
        var tb = el('stk_attach_body');
        tb.innerHTML = '';
        var list = state.attachments || [];
        list.forEach(function (att) {
            var tr = document.createElement('tr');
            var dlBase = API.download + '?id=' + (state.id || 0) + '&attachment_id=' + encodeURIComponent(att.id);
            tr.innerHTML =
                '<td>' + escapeHtml(att.name || att.original_name || 'مرفق') + '</td>' +
                '<td dir="ltr">' + escapeHtml(att.mime || '') + '</td>' +
                '<td dir="ltr">' + fmtSize(att.size) + '</td>' +
                '<td dir="ltr">' + escapeHtml((att.uploaded_at || '').substring(0, 16)) + '</td>' +
                '<td>' +
                    '<a href="' + dlBase + '&inline=1" target="_blank" rel="noopener">عرض</a> · ' +
                    '<a href="' + dlBase + '">تنزيل</a> · ' +
                    '<a href="#" class="stk-att-del" data-att="' + escapeHtml(att.id) + '" style="color:#b91c1c;">حذف</a>' +
                '</td>';
            tb.appendChild(tr);
        });
        el('stk_attach_empty').style.display = list.length === 0 ? 'block' : 'none';
        tb.querySelectorAll('.stk-att-del').forEach(function (a) {
            a.addEventListener('click', onAttDelete);
        });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
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
            notes: el('stk_notes').value || ''
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

    el('stk_upload_btn') && el('stk_upload_btn').addEventListener('click', async function () {
        showErr('');
        if (!state.id) { showErr('احفظ السجل أولاً'); return; }
        var fileInp = el('stk_file');
        if (!fileInp.files || !fileInp.files.length) { showErr('اختر ملفاً'); return; }
        var fd = new FormData();
        fd.append('id', String(state.id));
        fd.append('attachment_name', el('stk_file_name').value || '');
        fd.append('file', fileInp.files[0]);
        el('stk_upload_btn').disabled = true;
        try {
            var res = await fetch(API.upload, { method: 'POST', credentials: 'same-origin', body: fd });
            var data = await res.json();
            if (!data.success) { showErr(data.message || 'فشل الرفع'); return; }
            state.attachments = data.attachments || [];
            fileInp.value = '';
            el('stk_file_name').value = '';
            showOk('تم رفع المرفق');
            renderAttachments();
        } catch (e) {
            showErr('تعذر الرفع');
        } finally {
            el('stk_upload_btn').disabled = false;
        }
    });

    async function onAttDelete(ev) {
        ev.preventDefault();
        var attId = ev.target.getAttribute('data-att');
        if (!attId || !confirm('حذف هذا المرفق؟')) return;
        var data = await postJson(API.attDelete, { id: state.id, attachment_id: attId });
        if (!data.success) { showErr(data.message); return; }
        state.attachments = data.attachments || [];
        showOk('تم حذف المرفق');
        renderAttachments();
    }

    el('stk_btn_new') && el('stk_btn_new').addEventListener('click', function () {
        window.location.href = '?page=inventory_reconciliation';
    });

    syncFormFromState();
})();
</script>
