<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/company_documents.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/admin_time.php';

$typeLabels = orange_company_document_type_labels();
$entityPresets = orange_company_document_entity_presets();
$pdo = orange_admin_page_pdo();
$cdDefaultDocDate = '';
try {
    $cdDefaultDocDate = orange_format_date_dmY(
        orange_admin_time_document_date_today_for_admin_context($pdo)
    );
} catch (Throwable $e) {
    $cdDefaultDocDate = '';
}
?>
<div class="page-title">
    <h1>أرشيف المستندات والدورة المستندية</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div class="card">
    <h3 class="card-title">رفع مستند</h3>
    <div class="cd-upload-fields" style="display:flex;flex-direction:column;gap:12px;">
        <div>
            <label for="cd_file">الملف</label>
            <input type="file" id="cd_file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.doc,.docx,.xls,.xlsx">
            <p class="card-hint" style="margin:0.25rem 0 0;">حتى 40 ميجابايت — PDF، صور، Word، Excel، نص.</p>
        </div>
        <div class="form-grid cd-upload-row1 orange-doc-header-row">
            <div>
                <label for="cd_title">عنوان المستند <span style="color:#c00;">*</span></label>
                <input type="text" id="cd_title" placeholder="مثال: عقد إيجار المستودع 2026">
            </div>
            <div>
                <label for="cd_type">تصنيف المستند</label>
                <select id="cd_type">
                    <?php foreach ($typeLabels as $k => $lab): ?>
                        <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="cd_ref">رقم مرجعي / إشاري</label>
                <input type="text" id="cd_ref" placeholder="اختياري">
            </div>
            <div>
                <label for="cd_date">تاريخ المستند</label>
                <input type="text" id="cd_date" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($cdDefaultDocDate, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="cd_entity">ربط بكيان</label>
                <select id="cd_entity">
                    <?php foreach ($entityPresets as $k => $lab): ?>
                        <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="cd_entity_id">معرّف الكيان</label>
                <input type="text" id="cd_entity_id" placeholder="مثال: رقم الطلب أو فاتورة الشراء" lang="en" dir="ltr">
            </div>
        </div>
        <div>
            <label for="cd_notes">ملاحظات داخلية</label>
            <textarea id="cd_notes" rows="2" placeholder="اختياري — لا تظهر للعميل"></textarea>
        </div>
    </div>
    <div class="actions" style="margin-top:12px;">
        <button type="button" class="btn" id="cd_btn_upload">رفع وحفظ</button>
    </div>
</div>

<div class="card">
    <h3 class="card-title">بحث وتصفية</h3>
    <div class="form-grid cd-filter-row orange-doc-header-row" style="align-items:end;">
        <div>
            <label for="cd_q">بحث في العنوان / المرجع / الملاحظات / اسم الملف</label>
            <input type="text" id="cd_q" placeholder="اكتب للبحث">
        </div>
        <div>
            <label for="cd_f_type">التصنيف</label>
            <select id="cd_f_type">
                <option value="">الكل</option>
                <?php foreach ($typeLabels as $k => $lab): ?>
                    <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="cd_f_entity">الربط</label>
            <select id="cd_f_entity">
                <option value="">الكل</option>
                <?php foreach ($entityPresets as $k => $lab): ?>
                    <?php if ($k === '') { continue; } ?>
                    <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="cd_f_from">من تاريخ</label>
            <input type="text" id="cd_f_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" placeholder="اختياري">
        </div>
        <div>
            <label for="cd_f_to">إلى تاريخ</label>
            <input type="text" id="cd_f_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" placeholder="اختياري">
        </div>
        <div class="orange-doc-header-row__action">
            <span class="orange-doc-header-row__action-label" aria-hidden="true">.</span>
            <button type="button" class="btn" id="cd_btn_search">تحديث القائمة</button>
        </div>
    </div>
</div>

<div class="card">
    <h3 class="card-title">المستندات</h3>
    <p class="card-hint" id="cd_total_hint"></p>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>العنوان</th>
                    <th>التصنيف</th>
                    <th>مرجع</th>
                    <th>تاريخ</th>
                    <th>الربط</th>
                    <th>الحجم</th>
                    <th>رفع بواسطة</th>
                    <th>تاريخ الرفع</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="cd_tbody">
                <tr><td colspan="10" class="card-hint">جاري التحميل…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<style>
.form-grid.cd-upload-row1 {
    grid-template-columns:
        minmax(150px, 1.5fr)
        minmax(110px, 1fr)
        minmax(100px, 0.95fr)
        minmax(118px, 0.9fr)
        minmax(110px, 1fr)
        minmax(100px, 0.95fr);
}
.form-grid.cd-filter-row {
    grid-template-columns:
        minmax(240px, 2.2fr)
        minmax(120px, 1fr)
        minmax(120px, 1fr)
        minmax(118px, 0.85fr)
        minmax(118px, 0.85fr)
        auto;
}
.orange-doc-header-row.cd-filter-row .orange-doc-header-row__action .btn {
    white-space: nowrap;
    min-height: var(--input-min-h);
    height: var(--input-min-h);
    box-sizing: border-box;
}
</style>

<script>
(function () {
    function fmtBytes(n) {
        n = parseInt(n, 10) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(2) + ' MB';
    }

    function dlUrl(id) {
        var __pub = typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH.replace(/\/+$/, '') : '';
        return __pub + '/admin/api/company_documents/download.php?id=' + encodeURIComponent(String(id));
    }

    async function loadList() {
        var q = document.getElementById('cd_q').value.trim();
        var docType = document.getElementById('cd_f_type').value;
        var entityTable = document.getElementById('cd_f_entity').value;
        var dateFrom = document.getElementById('cd_f_from').value.trim();
        var dateTo = document.getElementById('cd_f_to').value.trim();
        var res = await postJSON('/admin/api/company_documents/list.php', {
            q: q,
            doc_type: docType,
            entity_table: entityTable,
            date_from: dateFrom,
            date_to: dateTo,
            limit: 200
        });
        var tb = document.getElementById('cd_tbody');
        var hint = document.getElementById('cd_total_hint');
        if (!res.success) {
            tb.innerHTML = '<tr><td colspan="10" class="alert-error">' + (res.message || 'خطأ') + '</td></tr>';
            hint.textContent = '';
            return;
        }
        hint.textContent = 'إجمالي السجلات: ' + (res.total != null ? res.total : (res.rows || []).length);
        var rows = res.rows || [];
        if (rows.length === 0) {
            tb.innerHTML = '<tr><td colspan="10" class="card-hint">لا توجد مستندات.</td></tr>';
            return;
        }
        tb.innerHTML = rows.map(function (r) {
            var id = parseInt(r.id, 10);
            var ent = (r.entity_table && String(r.entity_table)) ? (r.entity_label + ' — ' + String(r.entity_id)) : 'عام';
            var d = (r.doc_date_display && String(r.doc_date_display)) || (r.doc_date ? String(r.doc_date) : '—');
            var who = r.created_by_username ? String(r.created_by_username) : '—';
            var ca = (r.created_at_display && String(r.created_at_display)) || (r.created_at ? String(r.created_at) : '—');
            return '<tr>' +
                '<td>' + id + '</td>' +
                '<td>' + escapeHtml(String(r.title_ar || '')) + '</td>' +
                '<td>' + escapeHtml(String(r.doc_type_label || r.doc_type || '')) + '</td>' +
                '<td>' + escapeHtml(String(r.reference_number || '—')) + '</td>' +
                '<td>' + escapeHtml(d) + '</td>' +
                '<td>' + escapeHtml(ent) + '</td>' +
                '<td>' + fmtBytes(r.file_size) + '</td>' +
                '<td>' + escapeHtml(who) + '</td>' +
                '<td>' + escapeHtml(ca) + '</td>' +
                '<td class="actions">' +
                '<a class="btn btn-secondary" href="' + dlUrl(id) + '">تنزيل</a> ' +
                '<button type="button" class="btn-danger cd-del" data-id="' + id + '">حذف</button>' +
                '</td></tr>';
        }).join('');

        tb.querySelectorAll('.cd-del').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var docId = parseInt(btn.getAttribute('data-id'), 10);
                if (!docId || !confirm('حذف المستند نهائياً من الأرشيف؟')) return;
                var dr = await postJSON('/admin/api/company_documents/delete.php', { id: docId });
                alert(dr.message || (dr.success ? 'تم' : 'فشل'));
                if (dr.success) loadList();
            });
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    document.getElementById('cd_btn_search').addEventListener('click', loadList);
    document.getElementById('cd_q').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); loadList(); }
    });

    document.getElementById('cd_btn_upload').addEventListener('click', async function () {
        var f = document.getElementById('cd_file').files[0];
        if (!f) {
            alert('اختر ملفاً');
            return;
        }
        var title = document.getElementById('cd_title').value.trim();
        if (!title) {
            alert('عنوان المستند مطلوب');
            return;
        }
        var fd = new FormData();
        fd.append('file', f);
        fd.append('title_ar', title);
        fd.append('doc_type', document.getElementById('cd_type').value);
        fd.append('reference_number', document.getElementById('cd_ref').value.trim());
        fd.append('doc_date', document.getElementById('cd_date').value.trim());
        fd.append('entity_table', document.getElementById('cd_entity').value.trim());
        fd.append('entity_id', document.getElementById('cd_entity_id').value.trim());
        fd.append('notes', document.getElementById('cd_notes').value.trim());
        var r = await fetch('/admin/api/company_documents/upload.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        var j = {};
        try { j = await r.json(); } catch (e) { j = {}; }
        if (!j.success) {
            alert(j.message || 'فشل الرفع');
            return;
        }
        alert(j.message || 'تم الحفظ');
        document.getElementById('cd_file').value = '';
        document.getElementById('cd_title').value = '';
        document.getElementById('cd_ref').value = '';
        document.getElementById('cd_date').value = '<?php echo htmlspecialchars($cdDefaultDocDate, ENT_QUOTES, 'UTF-8'); ?>';
        document.getElementById('cd_entity').value = '';
        document.getElementById('cd_entity_id').value = '';
        document.getElementById('cd_notes').value = '';
        loadList();
    });

    loadList();
})();
</script>
