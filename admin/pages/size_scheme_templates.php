<?php

declare(strict_types=1);

$pdo = db();
require_once __DIR__ . '/../../includes/catalog_schema.php';

orange_catalog_ensure_schema($pdo);

$tablesReady = orange_table_exists($pdo, 'size_scheme_templates')
    && orange_table_exists($pdo, 'size_scheme_template_sizes');

$templates = [];
$nextSort = 1;
if ($tablesReady) {
    try {
        $templates = $pdo->query(
            'SELECT t.*,
                (SELECT COUNT(*) FROM size_scheme_template_sizes s WHERE s.template_id = t.id) AS sizes_count
             FROM size_scheme_templates t
             ORDER BY t.sort_order ASC, t.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM size_scheme_templates')->fetchColumn();
        if ($nextSort <= 0) {
            $nextSort = 1;
        }
    } catch (Throwable $e) {
        $templates = [];
    }
}
?>
<div class="page-title">
    <h1>قوالب المقاسات</h1>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">جداول قوالب المقاسات غير جاهزة. حدّث الصفحة بعد تهيئة المخطّط.</div>
</div>
<?php else: ?>

<div class="card" id="sst_form_card" tabindex="-1">
    <h3>إضافة / تعديل قالب</h3>
    <p style="margin:0 0 14px;font-size:0.88rem;color:#555;line-height:1.5;"><strong>الترتيب</strong> في القائمة تلقائي (جديد = تالي؛ تعديل = دون تغيير). <strong>Fil / Hi</strong> من <strong>الإنجليزي</strong>.</p>
    <input type="hidden" id="sst_id" value="0">
    <div class="form-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px 14px;align-items:end;">
        <div class="sst-sort-active" style="display:flex;flex-wrap:nowrap;align-items:flex-end;gap:14px;min-width:0;max-width:100%;">
            <div style="flex:0 0 auto;">
                <label style="margin-bottom:2px;">ترتيب (تلقائي)</label>
                <p id="sst_sort_display" title="ترتيب ظهور القالب في القائمة" style="margin:0;padding:6px 8px;font-size:0.88rem;font-weight:600;line-height:1.2;border:1px solid #ddd;border-radius:4px;background:#f9fafb;min-width:2rem;text-align:center;"><?php echo (int) $nextSort; ?></p>
            </div>
            <div style="flex:1 1 7rem;min-width:5.5rem;max-width:10rem;">
                <label for="sst_active">نشط</label>
                <select id="sst_active" class="admin-sort-field" style="width:100%;margin-top:2px;">
                    <option value="1">نعم</option>
                    <option value="0">لا</option>
                </select>
            </div>
        </div>
        <div>
            <label>اسم القالب عربي</label>
            <input type="text" id="sst_name_ar" maxlength="191" autocomplete="off">
        </div>
        <div>
            <label>اسم القالب English</label>
            <input type="text" id="sst_name_en" maxlength="191" autocomplete="off">
        </div>
        <div>
            <label>اسم القالب Filipino</label>
            <input type="text" id="sst_name_fil" maxlength="191" autocomplete="off" title="يُحدَّث من الإنجليزي">
        </div>
        <div>
            <label>اسم القالب Hindi</label>
            <input type="text" id="sst_name_hi" maxlength="191" autocomplete="off" title="يُحدَّث من الإنجليزي">
        </div>
    </div>
    <h4 style="margin:18px 0 8px;font-size:1rem;">مقاسات داخل القالب</h4>
    <p style="margin:0 0 10px;font-size:0.88rem;color:#555;line-height:1.5;">ترتيب المقاسات <strong>تلقائي</strong> حسب ترتيب الصفوف من الأعلى للأسفل (1، 2، 3…). حقول الفلبيني والهندي تُحدَّث من <strong>الإنجليزي</strong> بعد ترجمته من العربي أو عند تعديل الإنجليزي.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:3.2rem;">ترتيب</th>
                    <th>عربي</th>
                    <th>EN</th>
                    <th>Fil</th>
                    <th>Hi</th>
                    <th>طول القدم (سم)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="sst_sizes_tbody"></tbody>
        </table>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" onclick="sstAddSizeRow()">+ صف مقاس</button>
        <button type="button" class="btn-secondary" onclick="sstTranslateAllFromArabic()">ترجمة تلقائية من العربي</button>
        <button type="button" onclick="sstSave()">حفظ القالب</button>
        <button type="button" class="btn-secondary" onclick="sstResetForm()">جديد</button>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">قائمة القوالب</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العربي</th>
                    <th>English</th>
                    <th>عدد المقاسات</th>
                    <th>ترتيب</th>
                    <th>نشط</th>
                    <th>إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $t): ?>
                <tr>
                    <td><?php echo (int) $t['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $t['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $t['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($t['sizes_count'] ?? 0); ?></td>
                    <td><?php echo (int) $t['sort_order']; ?></td>
                    <td><?php echo (int) $t['is_active'] === 1 ? 'نعم' : 'لا'; ?></td>
                    <td>
                        <button type="button" class="btn-secondary" data-sst-edit="<?php echo (int) $t['id']; ?>">تعديل</button>
                        <button type="button" class="btn-danger" data-sst-del="<?php echo (int) $t['id']; ?>">حذف</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const SST_API = '/admin/api/size_scheme_templates/manage.php';
const SST_NEXT_SORT = <?php echo (int) $nextSort; ?>;
let sstHeaderArTimer = null;
let sstHeaderEnTimer = null;

function sstEscapeAttr(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

function sstRefreshSizeRowOrder() {
    document.querySelectorAll('#sst_sizes_tbody tr.sst-size-row').forEach(function (tr, i) {
        var c = tr.querySelector('td.sst-ord');
        if (c) {
            c.textContent = String(i + 1);
        }
    });
}

function sstRemoveSizeRow(btn) {
    var tr = btn.closest ? btn.closest('tr.sst-size-row') : null;
    if (tr) {
        tr.remove();
    }
    sstRefreshSizeRowOrder();
}

function sstAddSizeRow() {
    var tb = document.getElementById('sst_sizes_tbody');
    if (!tb) return;
    var tr = document.createElement('tr');
    tr.className = 'sst-size-row';
    tr.innerHTML = '<td class="sst-ord">1</td>' +
        '<td><input type="text" class="sst-la" maxlength="191"></td>' +
        '<td><input type="text" class="sst-le" maxlength="191"></td>' +
        '<td><input type="text" class="sst-lf" maxlength="191" placeholder="Fil"></td>' +
        '<td><input type="text" class="sst-lh" maxlength="191" placeholder="Hi"></td>' +
        '<td><input type="text" class="sst-fl" placeholder="اختياري"></td>' +
        '<td><button type="button" class="btn-secondary" onclick="sstRemoveSizeRow(this)">حذف الصف</button></td>';
    tb.appendChild(tr);
    sstRefreshSizeRowOrder();
}

function sstCollectSizes() {
    var rows = [];
    document.querySelectorAll('#sst_sizes_tbody tr.sst-size-row').forEach(function (tr, idx) {
        var la = tr.querySelector('.sst-la');
        var le = tr.querySelector('.sst-le');
        var lf = tr.querySelector('.sst-lf');
        var lh = tr.querySelector('.sst-lh');
        var fl = tr.querySelector('.sst-fl');
        var o = {
            label_ar: la ? String(la.value || '').trim() : '',
            label_en: le ? String(le.value || '').trim() : '',
            label_fil: lf ? String(lf.value || '').trim() : '',
            label_hi: lh ? String(lh.value || '').trim() : '',
            sort_order: idx + 1
        };
        var flv = fl ? String(fl.value || '').trim() : '';
        if (flv !== '') {
            o.foot_length_cm = flv;
        }
        if (o.label_ar === '' && o.label_en === '') {
            return;
        }
        rows.push(o);
    });
    return rows;
}

function sstResetForm() {
    document.getElementById('sst_id').value = '0';
    document.getElementById('sst_name_ar').value = '';
    document.getElementById('sst_name_en').value = '';
    document.getElementById('sst_name_fil').value = '';
    document.getElementById('sst_name_hi').value = '';
    var nextSo = SST_NEXT_SORT || 1;
    var sortDisp = document.getElementById('sst_sort_display');
    if (sortDisp) {
        sortDisp.textContent = String(nextSo);
    }
    document.getElementById('sst_active').value = '1';
    var tb = document.getElementById('sst_sizes_tbody');
    if (tb) {
        tb.innerHTML = '';
    }
    sstAddSizeRow();
}

async function sstApplyHeaderFilHiOnly(silent) {
    var en = document.getElementById('sst_name_en').value.trim();
    var filEl = document.getElementById('sst_name_fil');
    var hiEl = document.getElementById('sst_name_hi');
    if (!en) {
        if (filEl) {
            filEl.value = '';
        }
        if (hiEl) {
            hiEl.value = '';
        }
        return true;
    }
    try {
        var res = await postJSON('/admin/api/translate/names.php', {
            name_ar: document.getElementById('sst_name_ar').value.trim(),
            name_en: en
        });
        if (!res || !res.success) {
            if (!silent) {
                alert((res && res.message) ? res.message : 'فشل الترجمة');
            }
            return false;
        }
        var t = res.translations || {};
        if (filEl && t.name_fil) {
            filEl.value = t.name_fil;
        }
        if (hiEl && t.name_hi) {
            hiEl.value = t.name_hi;
        }
        return true;
    } catch (e) {
        if (!silent) {
            alert('فشل طلب الترجمة');
        }
        return false;
    }
}

async function sstTranslateHeaderInternal(opts) {
    opts = opts || {};
    var silent = !!opts.silent;
    var forceFromArabic = !!opts.forceFromArabic;
    if (!forceFromArabic) {
        return sstApplyHeaderFilHiOnly(silent);
    }
    try {
        var res = await postJSON('/admin/api/translate/names.php', {
            name_ar: document.getElementById('sst_name_ar').value.trim(),
            name_en: ''
        });
        if (!res || !res.success) {
            if (!silent) {
                alert((res && res.message) ? res.message : 'فشل الترجمة');
            }
            return false;
        }
        var t = res.translations || {};
        if (t.name_en) {
            document.getElementById('sst_name_en').value = t.name_en;
        }
        return await sstApplyHeaderFilHiOnly(silent);
    } catch (e) {
        if (!silent) {
            alert('فشل طلب الترجمة');
        }
        return false;
    }
}

function scheduleSstHeaderFromAr() {
    var ar = document.getElementById('sst_name_ar').value.trim();
    if (!ar) {
        document.getElementById('sst_name_en').value = '';
        document.getElementById('sst_name_fil').value = '';
        document.getElementById('sst_name_hi').value = '';
        return;
    }
    clearTimeout(sstHeaderArTimer);
    sstHeaderArTimer = setTimeout(function () {
        sstTranslateHeaderInternal({ silent: true, forceFromArabic: true });
    }, 700);
}

function scheduleSstHeaderFromEn() {
    var en = document.getElementById('sst_name_en').value.trim();
    if (!en) {
        return;
    }
    clearTimeout(sstHeaderEnTimer);
    sstHeaderEnTimer = setTimeout(function () {
        sstApplyHeaderFilHiOnly(true);
    }, 600);
}

async function sstApplyRowFilHiOnly(tr, silent) {
    var la = tr.querySelector('.sst-la');
    var le = tr.querySelector('.sst-le');
    var lf = tr.querySelector('.sst-lf');
    var lh = tr.querySelector('.sst-lh');
    if (!le) {
        return true;
    }
    var en = (le.value || '').trim();
    if (!en) {
        if (lf) {
            lf.value = '';
        }
        if (lh) {
            lh.value = '';
        }
        return true;
    }
    try {
        var res = await postJSON('/admin/api/translate/names.php', {
            name_ar: la ? (la.value || '').trim() : '',
            name_en: en
        });
        if (!res || !res.success) {
            if (!silent) {
                alert((res && res.message) ? res.message : 'فشل الترجمة');
            }
            return false;
        }
        var t = res.translations || {};
        if (lf && t.name_fil) {
            lf.value = t.name_fil;
        }
        if (lh && t.name_hi) {
            lh.value = t.name_hi;
        }
        return true;
    } catch (e) {
        if (!silent) {
            alert('فشل طلب الترجمة');
        }
        return false;
    }
}

async function sstTranslateRow(tr, opts) {
    opts = opts || {};
    var silent = !!opts.silent;
    var forceFromArabic = !!opts.forceFromArabic;
    var la = tr.querySelector('.sst-la');
    var le = tr.querySelector('.sst-le');
    if (!la || !le) {
        return true;
    }
    if (!forceFromArabic) {
        return sstApplyRowFilHiOnly(tr, silent);
    }
    try {
        var res = await postJSON('/admin/api/translate/names.php', {
            name_ar: (la.value || '').trim(),
            name_en: ''
        });
        if (!res || !res.success) {
            if (!silent) {
                alert((res && res.message) ? res.message : 'فشل الترجمة');
            }
            return false;
        }
        var t = res.translations || {};
        if (t.name_en) {
            le.value = t.name_en;
        }
        return await sstApplyRowFilHiOnly(tr, silent);
    } catch (e) {
        if (!silent) {
            alert('فشل طلب الترجمة');
        }
        return false;
    }
}

function scheduleSstRowFromAr(tr) {
    var la = tr.querySelector('.sst-la');
    if (!la) {
        return;
    }
    var ar = (la.value || '').trim();
    if (!ar) {
        var le = tr.querySelector('.sst-le');
        var lf = tr.querySelector('.sst-lf');
        var lh = tr.querySelector('.sst-lh');
        if (le) {
            le.value = '';
        }
        if (lf) {
            lf.value = '';
        }
        if (lh) {
            lh.value = '';
        }
        return;
    }
    clearTimeout(tr._sstArTimer);
    tr._sstArTimer = setTimeout(function () {
        sstTranslateRow(tr, { silent: true, forceFromArabic: true });
    }, 700);
}

function scheduleSstRowFromEn(tr) {
    var le = tr.querySelector('.sst-le');
    if (!le || !(le.value || '').trim()) {
        return;
    }
    clearTimeout(tr._sstEnTimer);
    tr._sstEnTimer = setTimeout(function () {
        sstApplyRowFilHiOnly(tr, true);
    }, 600);
}

async function sstTranslateAllFromArabic() {
    var ar = document.getElementById('sst_name_ar').value.trim();
    if (!ar) {
        alert('أدخل الاسم العربي للقالب أولاً');
        return;
    }
    if (!(await sstTranslateHeaderInternal({ silent: false, forceFromArabic: true }))) {
        return;
    }
    var rows = document.querySelectorAll('#sst_sizes_tbody tr.sst-size-row');
    for (var i = 0; i < rows.length; i++) {
        var tr = rows[i];
        var la = tr.querySelector('.sst-la');
        if (!la || !(la.value || '').trim()) {
            continue;
        }
        if (!(await sstTranslateRow(tr, { silent: false, forceFromArabic: true }))) {
            return;
        }
    }
}

async function sstSave() {
    var id = parseInt(document.getElementById('sst_id').value || '0', 10) || 0;
    var payload = {
        action: 'save',
        id: id,
        name_ar: document.getElementById('sst_name_ar').value.trim(),
        name_en: document.getElementById('sst_name_en').value.trim(),
        name_fil: document.getElementById('sst_name_fil').value.trim(),
        name_hi: document.getElementById('sst_name_hi').value.trim(),
        is_active: parseInt(document.getElementById('sst_active').value, 10),
        sizes: sstCollectSizes()
    };
    try {
        var res = await postJSON(SST_API, payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ'));
        if (res.success) {
            location.reload();
        }
    } catch (e) {
        alert('فشل الاتصال بالخادم');
    }
}

async function sstLoadOne(tplId) {
    try {
        var res = await postJSON(SST_API, { action: 'get', id: tplId });
        if (!res || !res.success || !res.template) {
            alert((res && res.message) ? res.message : 'تعذر تحميل القالب');
            return;
        }
        var t = res.template;
        document.getElementById('sst_id').value = String(t.id);
        document.getElementById('sst_name_ar').value = t.name_ar || '';
        document.getElementById('sst_name_en').value = t.name_en || '';
        document.getElementById('sst_name_fil').value = t.name_fil || '';
        document.getElementById('sst_name_hi').value = t.name_hi || '';
        var so = t.sort_order != null ? parseInt(String(t.sort_order), 10) : 0;
        if (!Number.isFinite(so) || so < 1) {
            so = 1;
        }
        var sortDisp = document.getElementById('sst_sort_display');
        if (sortDisp) {
            sortDisp.textContent = String(so);
        }
        document.getElementById('sst_active').value = (parseInt(t.is_active, 10) === 0 ? '0' : '1');
        var tb = document.getElementById('sst_sizes_tbody');
        tb.innerHTML = '';
        var sizes = res.sizes || [];
        if (!sizes.length) {
            sstAddSizeRow();
        } else {
            sizes.forEach(function (r, idx) {
                var tr = document.createElement('tr');
                tr.className = 'sst-size-row';
                var fl = (r.foot_length_cm != null && r.foot_length_cm !== '') ? String(r.foot_length_cm) : '';
                tr.innerHTML = '<td class="sst-ord">' + String(idx + 1) + '</td>' +
                    '<td><input type="text" class="sst-la" maxlength="191" value="' + sstEscapeAttr(r.label_ar) + '"></td>' +
                    '<td><input type="text" class="sst-le" maxlength="191" value="' + sstEscapeAttr(r.label_en) + '"></td>' +
                    '<td><input type="text" class="sst-lf" maxlength="191" value="' + sstEscapeAttr(r.label_fil) + '"></td>' +
                    '<td><input type="text" class="sst-lh" maxlength="191" value="' + sstEscapeAttr(r.label_hi) + '"></td>' +
                    '<td><input type="text" class="sst-fl" value="' + sstEscapeAttr(fl) + '"></td>' +
                    '<td><button type="button" class="btn-secondary" onclick="sstRemoveSizeRow(this)">حذف الصف</button></td>';
                tb.appendChild(tr);
            });
            sstRefreshSizeRowOrder();
        }
        document.getElementById('sst_form_card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
        alert('خطأ شبكة');
    }
}

(function () {
    var card = document.getElementById('sst_form_card');
    if (!card) {
        return;
    }
    card.addEventListener('input', function (ev) {
        var t = ev.target;
        if (!t) {
            return;
        }
        if (t.id === 'sst_name_ar') {
            scheduleSstHeaderFromAr();
            return;
        }
        if (t.id === 'sst_name_en') {
            scheduleSstHeaderFromEn();
            return;
        }
        var tr = t.closest ? t.closest('tr.sst-size-row') : null;
        if (!tr) {
            return;
        }
        if (t.classList && t.classList.contains('sst-la')) {
            scheduleSstRowFromAr(tr);
        } else if (t.classList && t.classList.contains('sst-le')) {
            scheduleSstRowFromEn(tr);
        }
    });
})();

document.addEventListener('click', function (ev) {
    var ed = ev.target.closest('[data-sst-edit]');
    if (ed) {
        var id = parseInt(ed.getAttribute('data-sst-edit') || '0', 10);
        if (id > 0) {
            sstLoadOne(id);
        }
        return;
    }
    var del = ev.target.closest('[data-sst-del]');
    if (del) {
        var did = parseInt(del.getAttribute('data-sst-del') || '0', 10);
        if (did > 0 && confirm('حذف القالب وجميع مقاساته من القائمة المرجعية؟')) {
            postJSON(SST_API, { action: 'delete', id: did }).then(function (res) {
                alert(res.message || (res.success ? 'تم الحذف' : 'فشل الحذف'));
                if (res.success) {
                    location.reload();
                }
            }).catch(function () {
                alert('فشل الاتصال');
            });
        }
    }
});

sstResetForm();
</script>

<?php endif; ?>
