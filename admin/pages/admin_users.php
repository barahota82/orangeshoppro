<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

$dbAu = db();
orange_catalog_ensure_schema($dbAu);
$labels = orange_admin_resource_labels();
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>المستخدمون والصلاحيات</h1>
        <p class="page-subtitle">المشرف العام فقط يدير الحسابات. المستخدمون غير المشرف يحتاجون صلاحيات صريحة لكل مجموعة وظائف (عرض / تعديل / حذف).</p>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <h3 class="card-title">مستخدم جديد / تعديل</h3>
        <input type="hidden" id="au_id" value="0">
        <div class="form-grid">
            <div>
                <label for="au_user">اسم الدخول</label>
                <input type="text" id="au_user" autocomplete="username">
            </div>
            <div>
                <label for="au_name">الاسم الظاهر</label>
                <input type="text" id="au_name">
            </div>
            <div style="grid-column:1/-1;">
                <label for="au_pass">كلمة المرور (اتركها فارغة عند التعديل إن لم تتغير)</label>
                <input type="password" id="au_pass" autocomplete="new-password">
            </div>
            <div class="form-check">
                <label><input type="checkbox" id="au_active" checked> نشط</label>
            </div>
            <div class="form-check">
                <label><input type="checkbox" id="au_super"> مشرف عام (كل الصلاحيات)</label>
            </div>
        </div>
        <div class="actions" style="margin-top:12px;">
            <button type="button" onclick="saveAdmin()">حفظ المستخدم</button>
            <button type="button" class="btn-secondary" onclick="resetAdminForm()">جديد</button>
        </div>
    </div>
    <div class="card">
        <h3 class="card-title">قائمة المستخدمين</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>الدخول</th><th>الاسم</th><th>نشط</th><th>مشرف</th><th></th></tr></thead>
                <tbody id="au_list_tbody"></tbody>
            </table>
        </div>
        <p class="card-hint">اختر مستخدماً لتحميل مصفوفة الصلاحيات في الأسفل.</p>
    </div>
</div>

<div class="card">
    <h3 class="card-title">صلاحيات المستخدم المختار</h3>
    <p class="card-hint muted">لا تُطبَّق على المشرف العام. «تعديل» يشمل الإنشاء والحفظ عبر واجهات البرمجة؛ «حذف» لعمليات الحذف.</p>
    <input type="hidden" id="perm_target_id" value="0">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>المجموعة</th>
                    <th>عرض</th>
                    <th>تعديل</th>
                    <th>حذف</th>
                </tr>
            </thead>
            <tbody id="perm_matrix_tbody"></tbody>
        </table>
    </div>
    <div class="actions" style="margin-top:12px;">
        <button type="button" onclick="savePermissions()">حفظ الصلاحيات</button>
    </div>
</div>

<script>
var AU_LABELS = <?php echo json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;

function resetAdminForm() {
    document.getElementById('au_id').value = '0';
    document.getElementById('au_user').value = '';
    document.getElementById('au_name').value = '';
    document.getElementById('au_pass').value = '';
    document.getElementById('au_active').checked = true;
    document.getElementById('au_super').checked = false;
    document.getElementById('perm_target_id').value = '0';
    document.getElementById('perm_matrix_tbody').innerHTML = '';
}

function renderPermMatrix(adminId, existing) {
    document.getElementById('perm_target_id').value = String(adminId);
    var tb = document.getElementById('perm_matrix_tbody');
    tb.innerHTML = '';
    Object.keys(AU_LABELS).forEach(function (key) {
        if (key === 'admin_users') return;
        var ex = (existing && existing[key]) ? existing[key] : {};
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td>' + escapeHtml(AU_LABELS[key]) + '</td>' +
            '<td><input type="checkbox" class="p-v" data-k="' + key + '"' + (ex.can_view ? ' checked' : '') + '></td>' +
            '<td><input type="checkbox" class="p-e" data-k="' + key + '"' + (ex.can_edit ? ' checked' : '') + '></td>' +
            '<td><input type="checkbox" class="p-d" data-k="' + key + '"' + (ex.can_delete ? ' checked' : '') + '></td>';
        tb.appendChild(tr);
    });
}

function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function loadAdmins() {
    postJSON('/admin/api/admins/list.php', {}).then(function (r) {
        if (!r.success) { alert(r.message || 'فشل'); return; }
        var tb = document.getElementById('au_list_tbody');
        tb.innerHTML = '';
        (r.admins || []).forEach(function (a) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + a.id + '</td><td>' + escapeHtml(a.username) + '</td><td>' + escapeHtml(a.display_name || '') + '</td>' +
                '<td>' + (a.is_active == 1 ? 'نعم' : '') + '</td><td>' + (a.is_superuser == 1 ? 'نعم' : '') + '</td>' +
                '<td><button type="button" class="btn-secondary" onclick="pickAdmin(' + a.id + ')">اختيار</button></td>';
            tb.appendChild(tr);
        });
        window.__permByAdmin = r.permissions_by_admin || {};
    }).catch(function (e) { alert(e.message || String(e)); });
}

function pickAdmin(id) {
    postJSON('/admin/api/admins/list.php', {}).then(function (r) {
        if (!r.success) return;
        var a = (r.admins || []).find(function (x) { return x.id == id; });
        if (!a) return;
        document.getElementById('au_id').value = String(a.id);
        document.getElementById('au_user').value = a.username;
        document.getElementById('au_name').value = a.display_name || '';
        document.getElementById('au_pass').value = '';
        document.getElementById('au_active').checked = a.is_active == 1;
        document.getElementById('au_super').checked = a.is_superuser == 1;
        var pm = (r.permissions_by_admin && r.permissions_by_admin[id]) ? r.permissions_by_admin[id] : {};
        if (a.is_superuser == 1) {
            document.getElementById('perm_matrix_tbody').innerHTML = '<tr><td colspan="4" class="muted">مشرف عام — كل الصلاحيات.</td></tr>';
            document.getElementById('perm_target_id').value = '0';
        } else {
            renderPermMatrix(id, pm);
        }
    });
}

function saveAdmin() {
    var id = parseInt(document.getElementById('au_id').value, 10) || 0;
    var payload = {
        id: id,
        username: document.getElementById('au_user').value.trim(),
        display_name: document.getElementById('au_name').value.trim(),
        password: document.getElementById('au_pass').value,
        is_active: document.getElementById('au_active').checked,
        is_superuser: document.getElementById('au_super').checked
    };
    if (!payload.username) { alert('اسم الدخول مطلوب'); return; }
    postJSON('/admin/api/admins/save.php', payload).then(function (r) {
        alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) { resetAdminForm(); loadAdmins(); }
    }).catch(function (e) { alert(e.message || String(e)); });
}

function savePermissions() {
    var aid = parseInt(document.getElementById('perm_target_id').value, 10) || 0;
    if (aid <= 0) { alert('اختر مستخدماً غير مشرف أولاً'); return; }
    var matrix = {};
    document.querySelectorAll('#perm_matrix_tbody tr').forEach(function (tr) {
        var v = tr.querySelector('.p-v');
        if (!v) return;
        var k = v.getAttribute('data-k');
        matrix[k] = {
            can_view: tr.querySelector('.p-v').checked,
            can_edit: tr.querySelector('.p-e').checked,
            can_delete: tr.querySelector('.p-d').checked
        };
    });
    postJSON('/admin/api/admins/permissions-save.php', { admin_id: aid, permissions: matrix }).then(function (r) {
        alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) loadAdmins();
    }).catch(function (e) { alert(e.message || String(e)); });
}

loadAdmins();
</script>
