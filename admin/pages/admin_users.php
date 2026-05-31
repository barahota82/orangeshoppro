<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/admin_password_policy.php';

$dbAu = db();
orange_catalog_ensure_schema($dbAu);
$auPermTree = orange_admin_permission_mega_sections();
$auCountries = orange_countries_admin_list($dbAu);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>المستخدمون والصلاحيات</h1>
        <p class="page-subtitle">المشرف العام فقط يدير الحسابات. اختر صلاحيات كل شاشة (عرض / تعديل / حذف / قفل / فك) — صف المجموعة اختصار لكل الشاشات داخلها؛ وسّع ▼ للضبط الدقيق.</p>
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
            <div style="grid-column:1/-1;" id="au_pass_wrap">
                <label for="au_pass">كلمة المرور (اتركها فارغة عند التعديل إن لم تتغير)</label>
                <input type="password" id="au_pass" autocomplete="new-password">
                <p class="card-hint" style="margin:6px 0 0;font-size:13px;line-height:1.55;"><?php echo htmlspecialchars(orange_admin_password_policy_hint_ar(), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="form-check">
                <label><input type="checkbox" id="au_active" checked> نشط</label>
            </div>
            <div class="form-check">
                <label><input type="checkbox" id="au_super"> مشرف عام (كل الصلاحيات)</label>
            </div>
            <div>
                <label for="au_country">دولة الفريق (فارغ = أدمن عام متعدد الدول)</label>
                <select id="au_country">
                    <option value="">— عام —</option>
                    <?php foreach ($auCountries as $auC): ?>
                    <option value="<?php echo (int) ($auC['id'] ?? 0); ?>">
                        <?php echo htmlspecialchars(trim((string) ($auC['name_ar'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
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
                <thead><tr><th>#</th><th>الدخول</th><th>الاسم</th><th>الدولة</th><th>نشط</th><th>مشرف</th><th></th></tr></thead>
                <tbody id="au_list_tbody"></tbody>
            </table>
        </div>
        <p class="card-hint">بعد الحفظ: اضغط «اختيار» بجانب المستخدم لتحميل بياناته — أو حدّد الصلاحيات في الأسفل قبل/بعد الحفظ.</p>
    </div>
</div>

<div class="card" id="au_perm_card">
    <h3 class="card-title">صلاحيات الشاشات</h3>
    <p class="card-hint muted" id="au_perm_hint">فعّل «عرض / تعديل / حذف / قفل / فك» لكل شاشة. «عرض» = ظهورها في القائمة؛ «تعديل» = إنشاء وحفظ؛ «قفل/فك» للمستندات المحاسبية والمخزنية.</p>
    <input type="hidden" id="perm_target_id" value="0">
    <div class="table-wrap au-perm-table-wrap">
        <table class="au-perm-table">
            <thead>
                <tr>
                    <th>المجموعة / الشاشة</th>
                    <th>عرض</th>
                    <th>تعديل</th>
                    <th>حذف</th>
                    <th>قفل</th>
                    <th>فك قفل</th>
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
var AU_PERM_TREE = <?php echo json_encode($auPermTree, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;

function auIsSuperChecked() {
    var el = document.getElementById('au_super');
    return !!(el && el.checked);
}

function auPermHintForSuper(isSuper) {
    var hint = document.getElementById('au_perm_hint');
    if (!hint) return;
    hint.textContent = isSuper
        ? 'المشرف العام يملك كل الصلاحيات — لا حاجة لتحديد شاشات.'
        : 'فعّل «عرض / تعديل / حذف / قفل / فك» لكل شاشة. صف المجموعة = اختصار لكل الشاشات داخلها؛ وسّع ▼ للضبط الدقيق.';
}

function auPermKey(page) {
    return 'page:' + page;
}

function auPermRowFlags(tr) {
    var v = tr.querySelector('.p-v');
    if (!v) return null;
    return {
        can_view: tr.querySelector('.p-v').checked,
        can_edit: tr.querySelector('.p-e').checked,
        can_delete: tr.querySelector('.p-d').checked,
        can_lock: tr.querySelector('.p-l') ? tr.querySelector('.p-l').checked : false,
        can_unlock: tr.querySelector('.p-u') ? tr.querySelector('.p-u').checked : false
    };
}

function auPermSetRowFlags(tr, flags) {
    if (!tr || !flags) return;
    var v = tr.querySelector('.p-v');
    if (!v) return;
    tr.querySelector('.p-v').checked = !!flags.can_view;
    tr.querySelector('.p-e').checked = !!flags.can_edit;
    tr.querySelector('.p-d').checked = !!flags.can_delete;
    if (tr.querySelector('.p-l')) tr.querySelector('.p-l').checked = !!flags.can_lock;
    if (tr.querySelector('.p-u')) tr.querySelector('.p-u').checked = !!flags.can_unlock;
}

function auPermMakeCheckboxCells(page, flags, extraClass) {
    var ex = flags || {};
    var cls = extraClass || '';
    return '<td><input type="checkbox" class="p-v ' + cls + '" data-page="' + escapeHtml(page) + '"' + (ex.can_view ? ' checked' : '') + '></td>' +
        '<td><input type="checkbox" class="p-e ' + cls + '" data-page="' + escapeHtml(page) + '"' + (ex.can_edit ? ' checked' : '') + '></td>' +
        '<td><input type="checkbox" class="p-d ' + cls + '" data-page="' + escapeHtml(page) + '"' + (ex.can_delete ? ' checked' : '') + '></td>' +
        '<td><input type="checkbox" class="p-l ' + cls + '" data-page="' + escapeHtml(page) + '"' + (ex.can_lock ? ' checked' : '') + '></td>' +
        '<td><input type="checkbox" class="p-u ' + cls + '" data-page="' + escapeHtml(page) + '"' + (ex.can_unlock ? ' checked' : '') + '></td>';
}

function auPermResolveExisting(existing, page) {
    if (!existing) return {};
    var pk = auPermKey(page);
    if (existing[pk]) return existing[pk];
    if (existing[page]) return existing[page];
    return {};
}

function auPermPagesInMega(mega) {
    if (mega.page) return [mega.page];
    var pages = [];
    (mega.subgroups || []).forEach(function (sg) {
        (sg.pages || []).forEach(function (p) {
            if (p.page && pages.indexOf(p.page) < 0) pages.push(p.page);
        });
    });
    return pages;
}

function auPermAggregateFlags(pageRows) {
    var agg = { can_view: true, can_edit: true, can_delete: true, can_lock: true, can_unlock: true };
    var any = false;
    pageRows.forEach(function (tr) {
        var f = auPermRowFlags(tr);
        if (!f) return;
        any = true;
        ['can_view', 'can_edit', 'can_delete', 'can_lock', 'can_unlock'].forEach(function (k) {
            if (!f[k]) agg[k] = false;
        });
    });
    if (!any) {
        return { can_view: false, can_edit: false, can_delete: false, can_lock: false, can_unlock: false };
    }
    return agg;
}

function auPermSyncMegaFromPages(megaId) {
    var megaRow = document.querySelector('tr.au-perm-mega[data-mega="' + megaId + '"]');
    if (!megaRow) return;
    var pageRows = Array.prototype.slice.call(document.querySelectorAll('tr.au-perm-page[data-mega="' + megaId + '"]'));
    auPermSetRowFlags(megaRow, auPermAggregateFlags(pageRows));
}

function auPermBindMatrixEvents() {
    var tb = document.getElementById('perm_matrix_tbody');
    if (!tb || tb.__auPermBound) return;
    tb.__auPermBound = true;
    tb.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.au-perm-expand');
        if (!btn) return;
        var megaId = btn.getAttribute('data-mega');
        var expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        document.querySelectorAll('tr.au-perm-child[data-mega="' + megaId + '"]').forEach(function (tr) {
            tr.hidden = expanded;
        });
    });
    tb.addEventListener('change', function (ev) {
        var el = ev.target;
        if (!el || el.type !== 'checkbox') return;
        var megaRow = el.closest('tr.au-perm-mega');
        if (megaRow) {
            var megaId = megaRow.getAttribute('data-mega');
            var flags = auPermRowFlags(megaRow);
            document.querySelectorAll('tr.au-perm-page[data-mega="' + megaId + '"]').forEach(function (tr) {
                auPermSetRowFlags(tr, flags);
            });
            return;
        }
        var pageRow = el.closest('tr.au-perm-page');
        if (pageRow) {
            var mid = pageRow.getAttribute('data-mega');
            if (mid) auPermSyncMegaFromPages(mid);
        }
    });
}

function renderPermMatrix(adminId, existing, isSuperOverride) {
    var isSuper = typeof isSuperOverride === 'boolean' ? isSuperOverride : auIsSuperChecked();
    var tb = document.getElementById('perm_matrix_tbody');
    if (!tb) return;
    tb.innerHTML = '';
    auPermBindMatrixEvents();
    if (isSuper) {
        document.getElementById('perm_target_id').value = '0';
        tb.innerHTML = '<tr><td colspan="6" class="muted">مشرف عام — كل الصلاحيات على كل الشاشات.</td></tr>';
        auPermHintForSuper(true);
        return;
    }
    document.getElementById('perm_target_id').value = String(adminId || 0);
    auPermHintForSuper(false);
    (AU_PERM_TREE || []).forEach(function (mega) {
        var megaId = mega.id || '';
        var pages = auPermPagesInMega(mega);
        var megaFlags = { can_view: false, can_edit: false, can_delete: false, can_lock: false, can_unlock: false };
        pages.forEach(function (pg) {
            var ex = auPermResolveExisting(existing, pg);
            ['can_view', 'can_edit', 'can_delete', 'can_lock', 'can_unlock'].forEach(function (k) {
                if (ex[k]) megaFlags[k] = true;
            });
        });
        var megaTr = document.createElement('tr');
        megaTr.className = 'au-perm-mega';
        megaTr.setAttribute('data-mega', megaId);
        var expandBtn = pages.length > 1
            ? '<button type="button" class="au-perm-expand" data-mega="' + escapeHtml(megaId) + '" aria-expanded="false" title="عرض الشاشات">▼</button> '
            : '';
        megaTr.innerHTML =
            '<td class="au-perm-mega-label">' + expandBtn + '<strong>' + escapeHtml(mega.title || megaId) + '</strong>' +
            '<span class="card-hint au-perm-mega-hint">اختصار — ' + pages.length + ' شاشة</span></td>' +
            auPermMakeCheckboxCells('__mega__' + megaId, megaFlags, 'au-perm-mega-cb');
        tb.appendChild(megaTr);

        if (mega.page) {
            megaTr.className = 'au-perm-mega au-perm-page';
            megaTr.setAttribute('data-page', mega.page);
            megaTr.querySelectorAll('input[type=checkbox]').forEach(function (inp) {
                inp.classList.remove('au-perm-mega-cb');
                inp.classList.add('au-perm-page-cb');
                inp.setAttribute('data-page', mega.page);
            });
            tb.appendChild(megaTr);
            return;
        }

        (mega.subgroups || []).forEach(function (sg) {
            var headTr = document.createElement('tr');
            headTr.className = 'au-perm-subhead au-perm-child';
            headTr.setAttribute('data-mega', megaId);
            headTr.hidden = true;
            headTr.innerHTML = '<td colspan="6" class="au-perm-subhead-label">' + escapeHtml(sg.title || '') + '</td>';
            tb.appendChild(headTr);
            (sg.pages || []).forEach(function (p) {
                var pg = p.page || '';
                if (!pg) return;
                var pageTr = document.createElement('tr');
                pageTr.className = 'au-perm-page au-perm-child';
                pageTr.setAttribute('data-mega', megaId);
                pageTr.setAttribute('data-page', pg);
                pageTr.hidden = true;
                pageTr.innerHTML =
                    '<td class="au-perm-page-label">' + escapeHtml(p.label || pg) + '</td>' +
                    auPermMakeCheckboxCells(pg, auPermResolveExisting(existing, pg), 'au-perm-page-cb');
                tb.appendChild(pageTr);
            });
        });
        auPermSyncMegaFromPages(megaId);
    });
}

function collectPermMatrix() {
    var matrix = {};
    document.querySelectorAll('#perm_matrix_tbody tr.au-perm-page').forEach(function (tr) {
        var page = tr.getAttribute('data-page');
        if (!page) return;
        var flags = auPermRowFlags(tr);
        if (!flags) return;
        matrix[auPermKey(page)] = flags;
    });
    return matrix;
}

function auBindSuperToggle() {
    var superEl = document.getElementById('au_super');
    if (!superEl || superEl.__auBound) return;
    superEl.__auBound = true;
    superEl.addEventListener('change', function () {
        var auCountry = document.getElementById('au_country');
        if (auCountry) {
            auCountry.disabled = this.checked;
            if (this.checked) auCountry.value = '';
        }
        var aid = parseInt(document.getElementById('au_id').value, 10) || 0;
        var pm = {};
        if (aid > 0 && window.__permByAdmin) {
            pm = window.__permByAdmin[aid] || window.__permByAdmin[String(aid)] || {};
        }
        renderPermMatrix(aid, pm, this.checked);
    });
}

function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function resetAdminForm() {
    document.getElementById('au_id').value = '0';
    document.getElementById('au_user').value = '';
    document.getElementById('au_name').value = '';
    document.getElementById('au_pass').value = '';
    document.getElementById('au_active').checked = true;
    document.getElementById('au_super').checked = false;
    var auCountry = document.getElementById('au_country');
    if (auCountry) {
        auCountry.value = '';
        auCountry.disabled = false;
    }
    renderPermMatrix(0, {}, false);
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
                '<td>' + escapeHtml(a.country_label || '—') + '</td>' +
                '<td>' + (a.is_active == 1 ? 'نعم' : '') + '</td><td>' + (a.is_superuser == 1 ? 'نعم' : '') + '</td>' +
                '<td><button type="button" class="btn-secondary" onclick="pickAdmin(' + a.id + ')">اختيار</button></td>';
            tb.appendChild(tr);
        });
        window.__permByAdmin = r.permissions_by_admin || {};
        if (r.permission_tree) AU_PERM_TREE = r.permission_tree;
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
        var auCountry = document.getElementById('au_country');
        if (auCountry) {
            auCountry.value = a.is_superuser == 1 ? '' : String(a.country_id || '');
            auCountry.disabled = a.is_superuser == 1;
        }
        auBindSuperToggle();
        var pm = (r.permissions_by_admin && (r.permissions_by_admin[id] || r.permissions_by_admin[String(id)])) ? (r.permissions_by_admin[id] || r.permissions_by_admin[String(id)]) : {};
        if (a.is_superuser == 1) {
            renderPermMatrix(0, {}, true);
        } else {
            renderPermMatrix(id, pm, false);
        }
    });
}

function savePermissionsForAdmin(adminId, matrix, silent) {
    return postJSON('/admin/api/admins/permissions-save.php', { admin_id: adminId, permissions: matrix }).then(function (r) {
        if (!silent) alert(r.message || (r.success ? 'تم حفظ الصلاحيات' : 'فشل'));
        if (r.success) loadAdmins();
        return r;
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
        is_superuser: document.getElementById('au_super').checked,
        country_id: document.getElementById('au_country') ? (parseInt(document.getElementById('au_country').value, 10) || 0) : 0
    };
    if (!payload.username) { alert('اسم الدخول مطلوب'); return; }
    if (id <= 0 || payload.password) {
        if (window.OrangeAdminPasswordPolicy) {
            var pwdErr = window.OrangeAdminPasswordPolicy.validate(payload.password, payload.username);
            if (pwdErr) { alert(pwdErr); return; }
        }
    }
    postJSON('/admin/api/admins/save.php', payload).then(function (r) {
        if (!r.success) {
            alert(r.message || 'فشل');
            return;
        }
        var newId = parseInt(String(r.id || payload.id || '0'), 10) || 0;
        var wasNew = id <= 0;
        if (newId > 0) {
            document.getElementById('au_id').value = String(newId);
        }
        document.getElementById('au_pass').value = '';
        if (!payload.is_superuser && newId > 0) {
            var matrix = collectPermMatrix();
            var hasAny = Object.keys(matrix).some(function (k) {
                var f = matrix[k];
                return f.can_view || f.can_edit || f.can_delete || f.can_lock || f.can_unlock;
            });
            if (hasAny) {
                savePermissionsForAdmin(newId, matrix, true).then(function (pr) {
                    alert((r.message || 'تم حفظ المستخدم') + (pr.success ? ' — وتم حفظ الصلاحيات.' : ' — تعذّر حفظ الصلاحيات: ' + (pr.message || '')));
                    loadAdmins();
                }).catch(function (e) {
                    alert((r.message || 'تم') + ' — خطأ صلاحيات: ' + (e.message || String(e)));
                    loadAdmins();
                });
                return;
            }
            renderPermMatrix(newId, {}, false);
        }
        alert(r.message || (wasNew ? 'تم إنشاء المستخدم — حدّد الصلاحيات واحفظها من الأسفل.' : 'تم'));
        loadAdmins();
    }).catch(function (e) { alert(e.message || String(e)); });
}

function savePermissions() {
    var aid = parseInt(document.getElementById('perm_target_id').value, 10) || 0;
    if (aid <= 0) {
        aid = parseInt(document.getElementById('au_id').value, 10) || 0;
    }
    if (aid <= 0) {
        alert('احفظ المستخدم أولاً (أو اضغط «اختيار» بجانب مستخدم موجود) ثم احفظ الصلاحيات.');
        return;
    }
    if (auIsSuperChecked()) {
        alert('المشرف العام لا يحتاج مصفوفة صلاحيات.');
        return;
    }
    savePermissionsForAdmin(aid, collectPermMatrix(), false).catch(function (e) {
        alert(e.message || String(e));
    });
}

auBindSuperToggle();
renderPermMatrix(0, {}, false);
loadAdmins();
</script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_password_policy.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
if (window.OrangeAdminPasswordPolicy) {
    window.OrangeAdminPasswordPolicy.attachToolbar({
        inputId: 'au_pass',
        usernameInputId: 'au_user',
        wrapId: 'au_pass_wrap'
    });
}
</script>
