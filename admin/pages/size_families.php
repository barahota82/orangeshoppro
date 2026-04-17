<?php
$pdo = db();

$hasFamilies = false;
$hasSizes = false;
try {
    $hasFamilies = (bool) $pdo->query("SHOW TABLES LIKE 'size_families'")->fetchColumn();
    $hasSizes = (bool) $pdo->query("SHOW TABLES LIKE 'size_family_sizes'")->fetchColumn();
} catch (Throwable $e) {
    $hasFamilies = false;
    $hasSizes = false;
}

$families = [];
$sizesByFamily = [];
$nextSort = 1;

if ($hasFamilies) {
    try {
        $families = $pdo->query('SELECT * FROM size_families ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM size_families')->fetchColumn();
        if ($nextSort <= 0) {
            $nextSort = 1;
        }
    } catch (Throwable $e) {
        $families = [];
    }
}

if ($hasSizes && $hasFamilies) {
    try {
        $sStmt = $pdo->query('SELECT * FROM size_family_sizes ORDER BY size_family_id ASC, sort_order ASC, id ASC');
        foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $fid = (int) $s['size_family_id'];
            if (!isset($sizesByFamily[$fid])) {
                $sizesByFamily[$fid] = [];
            }
            $sizesByFamily[$fid][] = $s;
        }
    } catch (Throwable $e) {
        $sizesByFamily = [];
    }
}

$tablesReady = $hasFamilies && $hasSizes;
?>
<div class="page-title">
    <h1>عائلات المقاسات</h1>
</div>

<?php if (!$tablesReady): ?>
<div class="card">
    <div class="alert-error">جداول <code>size_families</code> أو <code>size_family_sizes</code> غير متاحة. تحقق من صلاحيات قاعدة البيانات ثم حدّث الصفحة.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل عائلة</h3>
    <input type="hidden" id="fam_id" value="0">
    <div class="form-grid sf-form-grid">
        <div class="sf-sort">
            <label>الترتيب (تلقائي)</label>
            <input type="number" id="fam_sort" value="<?php echo (int) $nextSort; ?>" disabled style="max-width:140px;" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
        </div>
        <div class="sf-ar">
            <label>الاسم العربي</label>
            <input type="text" id="fam_name_ar" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
        </div>
        <div class="sf-en">
            <label>English</label>
            <input type="text" id="fam_name_en" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
        </div>
        <div class="sf-active">
            <label>نشط</label>
            <select id="fam_active" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
    </div>
    <div class="actions sf-form-actions" style="margin-top:14px;">
        <button type="button" onclick="saveFamily()" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>حفظ العائلة</button>
        <button type="button" class="btn-secondary" onclick="translateFamilyEn({ forceFromArabic: true })" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>ترجمة إلى English</button>
        <button type="button" class="btn-secondary" onclick="resetFamilyForm()" <?php echo !$hasFamilies ? 'disabled' : ''; ?>>جديد</button>
    </div>
</div>

<div class="card">
    <h3>مرجع طول القدم للأحذية (تقريبي — سم)</h3>
    <p style="margin:0 0 12px;color:#666;font-size:14px;">للمساعدة عند تعبئة «طول القدم (سم)» في مقاسات عائلات الأحذية. القيم إرشادية؛ اضبطها حسب موديلاتك وعلامتك.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>مقاس أوروبي (مثال)</th>
                    <th>طول القدم تقريبي (سم)</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $shoeRef = [
                    ['35', '22.5'], ['36', '23.0'], ['37', '23.5'], ['38', '24.0'], ['39', '24.5'],
                    ['40', '25.0'], ['41', '25.5'], ['42', '26.0'], ['43', '26.5'], ['44', '27.0'], ['45', '27.5'], ['46', '28.0'],
                ];
                foreach ($shoeRef as $row) {
                    echo '<tr><td>EU ' . htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8') . '</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>مقاسات داخل العائلة</h3>
    <div class="form-grid">
        <div style="grid-column:1/-1;">
            <label>اختر العائلة</label>
            <select id="sizes_family_id" onchange="loadSizesEditor()" <?php echo !$tablesReady ? 'disabled' : ''; ?>>
                <option value="">--</option>
                <?php foreach ($families as $f): ?>
                    <option value="<?php echo (int) $f['id']; ?>"><?php echo htmlspecialchars($f['name_ar'] ?: $f['name_en'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div id="sizesEditor" style="margin-top:12px;"></div>
    <div class="actions sf-sizes-actions" style="margin-top:14px;">
        <button type="button" class="btn-secondary" onclick="addSizeRow()" <?php echo !$tablesReady ? 'disabled' : ''; ?>>+ صف مقاس</button>
        <button type="button" onclick="saveSizesForFamily()" <?php echo !$tablesReady ? 'disabled' : ''; ?>>حفظ المقاسات</button>
    </div>
</div>

<?php if ($hasFamilies): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة العائلات</h3>
        <div class="actions">
            <button type="button" class="btn-secondary" onclick="saveFamiliesOrder()">حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap cat-dep-list-wrap" data-list="size-families">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العربي</th>
                    <th>English</th>
                    <th>عدد المقاسات</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th class="admin-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="orange-families-list-tbody">
                <?php foreach ($families as $f): ?>
                <tr data-id="<?php echo (int) $f['id']; ?>">
                    <td><?php echo (int) $f['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $f['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $f['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo isset($sizesByFamily[(int) $f['id']]) ? count($sizesByFamily[(int) $f['id']]) : 0; ?></td>
                    <td><?php echo (int) $f['sort_order']; ?></td>
                    <td><?php echo (int) $f['is_active'] === 1 ? 'ظاهر' : 'مخفي'; ?></td>
                    <td class="admin-ops-cell">
                        <div class="admin-table-ops">
                            <div class="admin-table-ops__arrows">
                                <button type="button" class="btn-secondary admin-btn-reorder sf-btn-reorder" onclick="moveFamilyRow(this,'up')" aria-label="أعلى">↑</button>
                                <button type="button" class="btn-secondary admin-btn-reorder sf-btn-reorder" onclick="moveFamilyRow(this,'down')" aria-label="أسفل">↓</button>
                            </div>
                            <div class="admin-table-ops__main">
                                <button type="button" class="btn-secondary sf-edit-btn" data-family-json="<?php echo htmlspecialchars(json_encode([
                                    'id' => (int) $f['id'],
                                    'name_ar' => (string) $f['name_ar'],
                                    'name_en' => (string) $f['name_en'],
                                    'sort_order' => (int) $f['sort_order'],
                                    'is_active' => (int) $f['is_active'],
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                                <button type="button" class="btn sf-btn-toggle" onclick="toggleFamily(<?php echo (int) $f['id']; ?>, <?php echo (int) $f['is_active']; ?>)">
                                    <?php echo (int) $f['is_active'] === 1 ? 'إخفاء' : 'إظهار'; ?>
                                </button>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
var ORANGE_SIZES_BY_FAMILY = <?php echo json_encode($sizesByFamily, JSON_UNESCAPED_UNICODE); ?>;
const defaultNextFamilySort = <?php echo (int) $nextSort; ?>;
let familyTranslateTimer = null;
let isSavingFamily = false;

function resetFamilyForm() {
    document.getElementById('fam_id').value = '0';
    document.getElementById('fam_name_ar').value = '';
    document.getElementById('fam_name_en').value = '';
    document.getElementById('fam_sort').value = String(defaultNextFamilySort || 1);
    document.getElementById('fam_active').value = '1';
}

function editFamily(f) {
    document.getElementById('fam_id').value = String(f.id != null ? f.id : 0);
    document.getElementById('fam_name_ar').value = f.name_ar || '';
    document.getElementById('fam_name_en').value = f.name_en || '';
    document.getElementById('fam_sort').value = String(f.sort_order ?? 0);
    document.getElementById('fam_active').value = String(f.is_active === 0 ? 0 : 1);
    document.getElementById('sizes_family_id').value = String(f.id);
    loadSizesEditor();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function translateFamilyEn(opts) {
    opts = opts || {};
    var silent = !!opts.silent;
    var forceFromArabic = !!opts.forceFromArabic;
    try {
        var payload = {
            name_ar: document.getElementById('fam_name_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('fam_name_en').value.trim()
        };
        var res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        var t = res.translations || {};
        if (t.name_en) document.getElementById('fam_name_en').value = t.name_en;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function scheduleFamilyEnTranslate() {
    var nameAr = document.getElementById('fam_name_ar').value.trim();
    if (!nameAr) {
        document.getElementById('fam_name_en').value = '';
        return;
    }
    clearTimeout(familyTranslateTimer);
    familyTranslateTimer = setTimeout(function () {
        translateFamilyEn({ silent: true, forceFromArabic: true });
    }, 600);
}

async function saveFamily() {
    if (isSavingFamily) return;
    isSavingFamily = true;
    if (!document.getElementById('fam_name_ar').value.trim() || !document.getElementById('fam_name_en').value.trim()) {
        alert('يجب تعبئة الاسم العربي والإنجليزي قبل الحفظ');
        isSavingFamily = false;
        return;
    }
    try {
        var rawId = parseInt(String(document.getElementById('fam_id').value || '0').trim(), 10);
        var recordId = (Number.isFinite(rawId) && rawId > 0) ? rawId : 0;
        var payload = {
            name_ar: document.getElementById('fam_name_ar').value.trim(),
            name_en: document.getElementById('fam_name_en').value.trim(),
            sort_order: parseInt(document.getElementById('fam_sort').value || '0', 10),
            is_active: parseInt(document.getElementById('fam_active').value, 10)
        };
        if (recordId > 0) payload.id = recordId;
        var res = await postJSON('/admin/api/size_families/save.php', payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        isSavingFamily = false;
    }
}

async function toggleFamily(id, isActive) {
    var res = await postJSON('/admin/api/size_families/toggle.php', {
        id: id,
        is_active: isActive ? 0 : 1
    });
    alert(res.message || (res.success ? 'تم التعديل' : 'فشل التعديل'));
    if (res.success) location.reload();
}

function moveFamilyRow(btn, dir) {
    var tr = btn.closest('tr');
    if (!tr) return;
    var tbody = document.getElementById('orange-families-list-tbody');
    if (!tbody) return;
    if (dir === 'up') {
        var prev = tr.previousElementSibling;
        if (prev) tbody.insertBefore(tr, prev);
    } else {
        var next = tr.nextElementSibling;
        if (next) tbody.insertBefore(next, tr);
    }
}

async function saveFamiliesOrder() {
    var tbody = document.getElementById('orange-families-list-tbody');
    if (!tbody) return;
    var ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map(function (tr) { return parseInt(tr.getAttribute('data-id') || '0', 10); })
        .filter(function (id) { return id > 0; });
    var res = await postJSON('/admin/api/size_families/reorder-save.php', { ordered_ids: ids });
    alert(res.message || (res.success ? 'تم حفظ الترتيب' : 'فشل حفظ الترتيب'));
    if (res.success) location.reload();
}

function loadSizesEditor() {
    var fid = parseInt(document.getElementById('sizes_family_id').value, 10) || 0;
    var box = document.getElementById('sizesEditor');
    if (!fid) {
        box.innerHTML = '';
        return;
    }
    var rows = ORANGE_SIZES_BY_FAMILY[String(fid)] || ORANGE_SIZES_BY_FAMILY[fid] || [];
    var html = '<div class="table-wrap"><table><thead><tr><th>id</th><th>عربي</th><th>EN</th><th>طول القدم (سم)</th><th>ترتيب</th></tr></thead><tbody>';
    if (!rows.length) {
        html += '<tr class="size-row" data-new="1"><td>0</td><td><input type="text" class="s-la"></td><td><input type="text" class="s-le"></td><td><input type="text" class="s-fl" placeholder="مثال 24.5"></td><td><input type="number" class="s-so" value="0"></td></tr>';
    } else {
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var fl = (r.foot_length_cm != null && r.foot_length_cm !== '') ? String(r.foot_length_cm) : '';
            html += '<tr class="size-row" data-id="' + r.id + '"><td>' + r.id + '</td><td><input type="text" class="s-la" value="' + escapeAttr(r.label_ar) + '"></td><td><input type="text" class="s-le" value="' + escapeAttr(r.label_en) + '"></td><td><input type="text" class="s-fl" placeholder="اختياري" value="' + escapeAttr(fl) + '"></td><td><input type="number" class="s-so" value="' + (Number(r.sort_order) || 0) + '"></td></tr>';
        }
    }
    html += '</tbody></table></div>';
    box.innerHTML = html;
}

function escapeAttr(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
}

function addSizeRow() {
    var fid = parseInt(document.getElementById('sizes_family_id').value, 10) || 0;
    if (!fid) {
        alert('اختر عائلة أولاً');
        return;
    }
    var tbody = document.querySelector('#sizesEditor tbody');
    if (!tbody) return;
    var tr = document.createElement('tr');
    tr.className = 'size-row';
    tr.setAttribute('data-new', '1');
    tr.innerHTML = '<td>0</td><td><input type="text" class="s-la"></td><td><input type="text" class="s-le"></td><td><input type="text" class="s-fl" placeholder="اختياري"></td><td><input type="number" class="s-so" value="0"></td>';
    tbody.appendChild(tr);
}

async function saveSizesForFamily() {
    var familyId = parseInt(document.getElementById('sizes_family_id').value, 10) || 0;
    if (!familyId) {
        alert('اختر عائلة');
        return;
    }
    var rows = [];
    var trs = document.querySelectorAll('#sizesEditor tr.size-row');
    for (var idx = 0; idx < trs.length; idx++) {
        var tr = trs[idx];
        var id = parseInt(tr.getAttribute('data-id') || '0', 10) || 0;
        var laEl = tr.querySelector('.s-la');
        var leEl = tr.querySelector('.s-le');
        var flEl = tr.querySelector('.s-fl');
        var soEl = tr.querySelector('.s-so');
        var la = laEl ? String(laEl.value || '').trim() : '';
        var le = leEl ? String(leEl.value || '').trim() : '';
        var fl = flEl ? String(flEl.value || '').trim() : '';
        var so = soEl ? parseInt(soEl.value || String(idx), 10) : idx;
        if (isNaN(so)) so = idx;
        if (la === '' && le === '') continue;
        var row = { id: id, label_ar: la, label_en: le, sort_order: so };
        if (fl !== '') row.foot_length_cm = fl;
        rows.push(row);
    }
    var res = await postJSON('/admin/api/size_families/save_sizes.php', { family_id: familyId, sizes: rows });
    alert(res.message || (res.success ? 'تم حفظ المقاسات' : 'فشل'));
    if (res.success) location.reload();
}

document.getElementById('fam_name_ar').addEventListener('input', scheduleFamilyEnTranslate);
document.getElementById('fam_name_ar').addEventListener('change', function () {
    if (document.getElementById('fam_name_ar').value.trim()) {
        translateFamilyEn({ silent: true, forceFromArabic: true });
    }
});

(function () {
    var style = document.createElement('style');
    style.textContent = `
        .sf-form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            grid-template-areas:
                "blank sort"
                "ar ar"
                "en en"
                "active active";
            gap:14px 18px;
            direction:ltr;
        }
        .sf-form-grid .sf-sort{
            grid-area:sort;
            justify-self:end;
            width:100%;
            max-width:180px;
        }
        .sf-form-grid .sf-ar{grid-area:ar}
        .sf-form-grid .sf-en{grid-area:en}
        .sf-form-grid .sf-active{grid-area:active}
        .sf-form-grid label,
        .sf-form-grid input,
        .sf-form-grid select{direction:rtl;text-align:right}
        .sf-form-grid #fam_sort{max-width:140px;margin-right:0;margin-left:auto;display:block}
        .sf-form-actions,.sf-sizes-actions{justify-content:flex-end}
        @media (max-width: 860px){
            .sf-form-grid{grid-template-columns:1fr}
            .sf-form-grid .sf-sort,
            .sf-form-grid .sf-ar,
            .sf-form-grid .sf-en,
            .sf-form-grid .sf-active{grid-column:1}
            .sf-form-grid #fam_sort{max-width:100%}
        }
        .cat-dep-list-wrap[data-list="size-families"]{
            overflow-x:auto;
            max-width:100%;
            -webkit-overflow-scrolling:touch;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table{
            min-width:860px;
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .cat-dep-list-wrap[data-list="size-families"] > table th,
        .cat-dep-list-wrap[data-list="size-families"] > table td{
            vertical-align:middle;
        }
        .cat-dep-list-wrap[data-list="size-families"] table .admin-ops-col,
        .cat-dep-list-wrap[data-list="size-families"] table .admin-ops-cell{
            padding:10px 8px !important;
        }
    `;
    document.head.appendChild(style);

    var tbody = document.getElementById('orange-families-list-tbody');
    if (!tbody) return;
    tbody.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.sf-edit-btn');
        if (!btn || !btn.dataset.familyJson) return;
        try {
            editFamily(JSON.parse(btn.dataset.familyJson));
        } catch (err) {
            alert('تعذر قراءة بيانات العائلة للتعديل');
        }
    });
})();
</script>
