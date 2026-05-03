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
    <input type="hidden" id="sst_id" value="0">
    <div class="form-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;">
        <div>
            <label>اسم القالب عربي</label>
            <input type="text" id="sst_name_ar" maxlength="191" autocomplete="off">
        </div>
        <div>
            <label>اسم القالب English</label>
            <input type="text" id="sst_name_en" maxlength="191" autocomplete="off">
        </div>
        <div>
            <label>الترتيب</label>
            <input type="number" id="sst_sort" class="admin-sort-field" value="<?php echo (int) $nextSort; ?>">
        </div>
        <div>
            <label>نشط</label>
            <select id="sst_active" class="admin-sort-field">
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
    </div>
    <h4 style="margin:18px 0 8px;font-size:1rem;">مقاسات داخل القالب</h4>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>عربي</th>
                    <th>EN</th>
                    <th>Fil</th>
                    <th>Hi</th>
                    <th>طول القدم (سم)</th>
                    <th>ترتيب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="sst_sizes_tbody"></tbody>
        </table>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;">
        <button type="button" onclick="sstAddSizeRow()">+ صف مقاس</button>
        <button type="button" class="btn-secondary" onclick="sstTranslateHeader()">ترجمة إلى English</button>
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

function sstEscapeAttr(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

function sstAddSizeRow() {
    var tb = document.getElementById('sst_sizes_tbody');
    if (!tb) return;
    var tr = document.createElement('tr');
    tr.className = 'sst-size-row';
    tr.innerHTML = '<td><input type="text" class="sst-la" maxlength="191"></td>' +
        '<td><input type="text" class="sst-le" maxlength="191"></td>' +
        '<td><input type="text" class="sst-lf" maxlength="191" placeholder="Fil"></td>' +
        '<td><input type="text" class="sst-lh" maxlength="191" placeholder="Hi"></td>' +
        '<td><input type="text" class="sst-fl" placeholder="اختياري"></td>' +
        '<td><input type="number" class="sst-so" value="0"></td>' +
        '<td><button type="button" class="btn-secondary" onclick="this.closest(\'tr\').remove()">حذف الصف</button></td>';
    tb.appendChild(tr);
}

function sstCollectSizes() {
    var rows = [];
    document.querySelectorAll('#sst_sizes_tbody tr.sst-size-row').forEach(function (tr, idx) {
        var la = tr.querySelector('.sst-la');
        var le = tr.querySelector('.sst-le');
        var lf = tr.querySelector('.sst-lf');
        var lh = tr.querySelector('.sst-lh');
        var fl = tr.querySelector('.sst-fl');
        var so = tr.querySelector('.sst-so');
        var o = {
            label_ar: la ? String(la.value || '').trim() : '',
            label_en: le ? String(le.value || '').trim() : '',
            label_fil: lf ? String(lf.value || '').trim() : '',
            label_hi: lh ? String(lh.value || '').trim() : '',
            sort_order: so ? parseInt(so.value || '0', 10) || 0 : 0
        };
        var flv = fl ? String(fl.value || '').trim() : '';
        if (flv !== '') {
            o.foot_length_cm = flv;
        }
        if (o.label_ar === '' && o.label_en === '') {
            return;
        }
        if (o.sort_order <= 0) {
            o.sort_order = idx + 1;
        }
        rows.push(o);
    });
    return rows;
}

function sstResetForm() {
    document.getElementById('sst_id').value = '0';
    document.getElementById('sst_name_ar').value = '';
    document.getElementById('sst_name_en').value = '';
    document.getElementById('sst_sort').value = String(SST_NEXT_SORT || 1);
    document.getElementById('sst_active').value = '1';
    var tb = document.getElementById('sst_sizes_tbody');
    if (tb) {
        tb.innerHTML = '';
    }
    sstAddSizeRow();
}

async function sstTranslateHeader() {
    var ar = document.getElementById('sst_name_ar').value.trim();
    if (!ar) {
        alert('أدخل الاسم العربي للقالب أولاً');
        return;
    }
    try {
        var res = await postJSON('/admin/api/translate/names.php', { name_ar: ar, name_en: '' });
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        var t = res.translations || {};
        if (t.name_en) {
            document.getElementById('sst_name_en').value = t.name_en;
        }
    } catch (e) {
        alert('فشل طلب الترجمة');
    }
}

async function sstSave() {
    var id = parseInt(document.getElementById('sst_id').value || '0', 10) || 0;
    var payload = {
        action: 'save',
        id: id,
        name_ar: document.getElementById('sst_name_ar').value.trim(),
        name_en: document.getElementById('sst_name_en').value.trim(),
        sort_order: parseInt(document.getElementById('sst_sort').value || '0', 10) || 0,
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
        document.getElementById('sst_sort').value = String(t.sort_order != null ? t.sort_order : 0);
        document.getElementById('sst_active').value = (parseInt(t.is_active, 10) === 0 ? '0' : '1');
        var tb = document.getElementById('sst_sizes_tbody');
        tb.innerHTML = '';
        var sizes = res.sizes || [];
        if (!sizes.length) {
            sstAddSizeRow();
        } else {
            sizes.forEach(function (r) {
                var tr = document.createElement('tr');
                tr.className = 'sst-size-row';
                var fl = (r.foot_length_cm != null && r.foot_length_cm !== '') ? String(r.foot_length_cm) : '';
                tr.innerHTML = '<td><input type="text" class="sst-la" maxlength="191" value="' + sstEscapeAttr(r.label_ar) + '"></td>' +
                    '<td><input type="text" class="sst-le" maxlength="191" value="' + sstEscapeAttr(r.label_en) + '"></td>' +
                    '<td><input type="text" class="sst-lf" maxlength="191" value="' + sstEscapeAttr(r.label_fil) + '"></td>' +
                    '<td><input type="text" class="sst-lh" maxlength="191" value="' + sstEscapeAttr(r.label_hi) + '"></td>' +
                    '<td><input type="text" class="sst-fl" value="' + sstEscapeAttr(fl) + '"></td>' +
                    '<td><input type="number" class="sst-so" value="' + (Number(r.sort_order) || 0) + '"></td>' +
                    '<td><button type="button" class="btn-secondary" onclick="this.closest(\'tr\').remove()">حذف الصف</button></td>';
                tb.appendChild(tr);
            });
        }
        document.getElementById('sst_form_card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
        alert('خطأ شبكة');
    }
}

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
