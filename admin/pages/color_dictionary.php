<?php
$pdo = db();

$hasTable = false;
try {
    $hasTable = (bool) $pdo->query("SHOW TABLES LIKE 'color_dictionary'")->fetchColumn();
} catch (Throwable $e) {
    $hasTable = false;
}

$colors = [];
$nextSort = 1;
if ($hasTable) {
    try {
        $colors = $pdo->query('SELECT * FROM color_dictionary ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM color_dictionary')->fetchColumn();
        if ($nextSort <= 0) {
            $nextSort = 1;
        }
    } catch (Throwable $e) {
        $colors = [];
    }
}
?>
<div class="page-title">
    <h1>قاموس الألوان</h1>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>color_dictionary</code> غير متاح. تحقق من اتصال قاعدة البيانات وصلاحيات CREATE/ALTER، ثم حدّث الصفحة.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل لون</h3>
    <input type="hidden" id="color_id" value="0">
    <div class="form-grid cd-form-grid">
        <div class="cd-sort">
            <label>الترتيب (تلقائي)</label>
            <input type="number" id="c_sort" value="<?php echo (int) $nextSort; ?>" disabled style="max-width:140px;">
        </div>
        <div class="cd-ar">
            <label>الاسم العربي</label>
            <input type="text" id="c_name_ar" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
        <div class="cd-fil">
            <label>Filipino</label>
            <input type="text" id="c_name_fil" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
        <div class="cd-en">
            <label>English</label>
            <input type="text" id="c_name_en" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
        <div class="cd-hex">
            <label>Hex (اختياري)</label>
            <input type="text" id="c_hex" placeholder="مثال #FFFFFF أو اتركه فارغاً" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
        <div class="cd-hi">
            <label>Hindi</label>
            <input type="text" id="c_name_hi" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
        <div class="cd-active">
            <label>نشط</label>
            <select id="c_active" <?php echo !$hasTable ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
    </div>
    <div class="actions cd-form-actions" style="margin-top:14px;">
        <button type="button" onclick="saveColor()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ اللون</button>
        <button type="button" class="btn-secondary" onclick="translateColor({ forceFromArabic: true })" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة تلقائية</button>
        <button type="button" class="btn-secondary" onclick="resetColorForm()" <?php echo !$hasTable ? 'disabled' : ''; ?>>جديد</button>
    </div>
</div>

<?php if ($hasTable): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة الألوان</h3>
        <div class="actions">
            <button type="button" class="btn-secondary" onclick="saveColorsOrder()">حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap cat-dep-list-wrap" data-list="colors">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العربي</th>
                    <th>English</th>
                    <th>Filipino</th>
                    <th>Hindi</th>
                    <th>Hex</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th class="admin-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="orange-colors-list-tbody">
                <?php foreach ($colors as $c): ?>
                <tr data-id="<?php echo (int) $c['id']; ?>">
                    <td><?php echo (int) $c['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $c['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $c['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($c['name_fil'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($c['name_hi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($c['hex_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) $c['sort_order']; ?></td>
                    <td><?php echo (int) $c['is_active'] === 1 ? 'ظاهر' : 'مخفي'; ?></td>
                    <td class="admin-ops-cell">
                        <div class="admin-table-ops">
                            <div class="admin-table-ops__arrows">
                                <button type="button" class="btn-secondary admin-btn-reorder cd-btn-reorder" onclick="moveColorRow(this,'up')" aria-label="أعلى">↑</button>
                                <button type="button" class="btn-secondary admin-btn-reorder cd-btn-reorder" onclick="moveColorRow(this,'down')" aria-label="أسفل">↓</button>
                            </div>
                            <div class="admin-table-ops__main">
                                <button type="button" class="btn-secondary cd-edit-btn" data-color-json="<?php echo htmlspecialchars(json_encode([
                                    'id' => (int) $c['id'],
                                    'name_ar' => (string) $c['name_ar'],
                                    'name_en' => (string) $c['name_en'],
                                    'name_fil' => (string) ($c['name_fil'] ?? ''),
                                    'name_hi' => (string) ($c['name_hi'] ?? ''),
                                    'hex_code' => $c['hex_code'] ?? null,
                                    'sort_order' => (int) $c['sort_order'],
                                    'is_active' => (int) $c['is_active'],
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                                <button type="button" class="btn cd-btn-toggle" onclick="toggleColor(<?php echo (int) $c['id']; ?>, <?php echo (int) $c['is_active']; ?>)">
                                    <?php echo (int) $c['is_active'] === 1 ? 'إخفاء' : 'إظهار'; ?>
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
const defaultNextColorSort = <?php echo (int) $nextSort; ?>;
let colorTranslateTimer = null;
let colorEnTranslateTimer = null;
let isSavingColor = false;

function resetColorForm() {
    document.getElementById('color_id').value = '0';
    document.getElementById('c_name_ar').value = '';
    document.getElementById('c_name_en').value = '';
    document.getElementById('c_name_fil').value = '';
    document.getElementById('c_name_hi').value = '';
    document.getElementById('c_hex').value = '';
    document.getElementById('c_sort').value = String(defaultNextColorSort || 1);
    document.getElementById('c_active').value = '1';
}

function editColor(c) {
    document.getElementById('color_id').value = String(c.id != null ? c.id : 0);
    document.getElementById('c_name_ar').value = c.name_ar || '';
    document.getElementById('c_name_en').value = c.name_en || '';
    document.getElementById('c_name_fil').value = c.name_fil || '';
    document.getElementById('c_name_hi').value = c.name_hi || '';
    document.getElementById('c_hex').value = c.hex_code || '';
    document.getElementById('c_sort').value = String(c.sort_order ?? 0);
    document.getElementById('c_active').value = String(c.is_active === 0 ? 0 : 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function translateColor(opts) {
    opts = opts || {};
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const payload = {
            name_ar: document.getElementById('c_name_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('c_name_en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('c_name_en').value = t.name_en;
        if (t.name_fil) document.getElementById('c_name_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('c_name_hi').value = t.name_hi;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function scheduleColorAutoTranslate() {
    const nameAr = document.getElementById('c_name_ar').value.trim();
    if (!nameAr) {
        document.getElementById('c_name_en').value = '';
        document.getElementById('c_name_fil').value = '';
        document.getElementById('c_name_hi').value = '';
        return;
    }
    clearTimeout(colorTranslateTimer);
    colorTranslateTimer = setTimeout(function () {
        translateColor({ silent: true, forceFromArabic: true });
    }, 600);
}

/** بعد تعديل الإنجليزي يدويًا: حدّث الفلبيني والهندي من الإنجليزي (بدون إعادة ترجمة EN من العربي). */
function scheduleColorTranslateFromEnglish() {
    const nameEn = document.getElementById('c_name_en').value.trim();
    if (!nameEn) {
        return;
    }
    clearTimeout(colorEnTranslateTimer);
    colorEnTranslateTimer = setTimeout(function () {
        translateColor({ silent: true, forceFromArabic: false });
    }, 550);
}

async function saveColor() {
    if (isSavingColor) return;
    isSavingColor = true;
    const required = [
        { id: 'c_name_ar', label: 'الاسم العربي' },
        { id: 'c_name_en', label: 'English' },
        { id: 'c_name_fil', label: 'Filipino' },
        { id: 'c_name_hi', label: 'Hindi' }
    ];
    for (var i = 0; i < required.length; i++) {
        var f = required[i];
        if (!document.getElementById(f.id).value.trim()) {
            alert('يجب إضافة خانة ' + f.label + ' قبل الحفظ');
            isSavingColor = false;
            return;
        }
    }
    try {
        var rawId = parseInt(String(document.getElementById('color_id').value || '0').trim(), 10);
        var recordId = (Number.isFinite(rawId) && rawId > 0) ? rawId : 0;
        var payload = {
            name_ar: document.getElementById('c_name_ar').value.trim(),
            name_en: document.getElementById('c_name_en').value.trim(),
            name_fil: document.getElementById('c_name_fil').value.trim(),
            name_hi: document.getElementById('c_name_hi').value.trim(),
            hex_code: document.getElementById('c_hex').value.trim(),
            sort_order: parseInt(document.getElementById('c_sort').value || '0', 10),
            is_active: parseInt(document.getElementById('c_active').value, 10)
        };
        if (recordId > 0) payload.id = recordId;
        var res = await postJSON('/admin/api/colors/save.php', payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        isSavingColor = false;
    }
}

async function toggleColor(id, isActive) {
    var res = await postJSON('/admin/api/colors/toggle.php', {
        id: id,
        is_active: isActive ? 0 : 1
    });
    alert(res.message || (res.success ? 'تم التعديل' : 'فشل التعديل'));
    if (res.success) location.reload();
}

function moveColorRow(btn, dir) {
    var tr = btn.closest('tr');
    if (!tr) return;
    var tbody = document.getElementById('orange-colors-list-tbody');
    if (!tbody) return;
    if (dir === 'up') {
        var prev = tr.previousElementSibling;
        if (prev) tbody.insertBefore(tr, prev);
    } else {
        var next = tr.nextElementSibling;
        if (next) tbody.insertBefore(next, tr);
    }
}

async function saveColorsOrder() {
    var tbody = document.getElementById('orange-colors-list-tbody');
    if (!tbody) return;
    var ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map(function (tr) { return parseInt(tr.getAttribute('data-id') || '0', 10); })
        .filter(function (id) { return id > 0; });
    var res = await postJSON('/admin/api/colors/reorder-save.php', { ordered_ids: ids });
    alert(res.message || (res.success ? 'تم حفظ الترتيب' : 'فشل حفظ الترتيب'));
    if (res.success) location.reload();
}

document.getElementById('c_name_ar').addEventListener('input', scheduleColorAutoTranslate);
document.getElementById('c_name_ar').addEventListener('change', function () {
    if (document.getElementById('c_name_ar').value.trim()) {
        translateColor({ silent: true, forceFromArabic: true });
    }
});
document.getElementById('c_name_en').addEventListener('input', scheduleColorTranslateFromEnglish);

(function () {
    var style = document.createElement('style');
    style.textContent = `
        .cd-form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            grid-template-areas:
                "blank sort"
                "ar ar"
                "fil en"
                "hex hi"
                "active active";
            gap:14px 18px;
            direction:ltr;
        }
        .cd-form-grid .cd-sort{
            grid-area:sort;
            justify-self:end;
            width:100%;
            max-width:180px;
        }
        .cd-form-grid .cd-ar{grid-area:ar}
        .cd-form-grid .cd-en{grid-area:en}
        .cd-form-grid .cd-hi{grid-area:hi}
        .cd-form-grid .cd-fil{grid-area:fil}
        .cd-form-grid .cd-hex{grid-area:hex}
        .cd-form-grid .cd-active{grid-area:active}
        .cd-form-grid label,
        .cd-form-grid input,
        .cd-form-grid select{direction:rtl;text-align:right}
        .cd-form-grid #c_sort{max-width:140px;margin-right:0;margin-left:auto;display:block}
        .cd-form-actions{justify-content:flex-end}
        @media (max-width: 860px){
            .cd-form-grid{grid-template-columns:1fr}
            .cd-form-grid .cd-sort,
            .cd-form-grid .cd-ar,
            .cd-form-grid .cd-en,
            .cd-form-grid .cd-hi,
            .cd-form-grid .cd-fil,
            .cd-form-grid .cd-hex,
            .cd-form-grid .cd-active{grid-column:1}
            .cd-form-grid #c_sort{max-width:100%}
        }
        .cat-dep-list-wrap[data-list="colors"]{
            overflow-x:auto;
            max-width:100%;
            -webkit-overflow-scrolling:touch;
        }
        .cat-dep-list-wrap[data-list="colors"] > table{
            min-width:920px;
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .cat-dep-list-wrap[data-list="colors"] > table th,
        .cat-dep-list-wrap[data-list="colors"] > table td{
            vertical-align:middle;
        }
        .cat-dep-list-wrap[data-list="colors"] table .admin-ops-col,
        .cat-dep-list-wrap[data-list="colors"] table .admin-ops-cell{
            padding:10px 8px !important;
        }
    `;
    document.head.appendChild(style);

    var tbody = document.getElementById('orange-colors-list-tbody');
    if (!tbody) return;
    tbody.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.cd-edit-btn');
        if (!btn || !btn.dataset.colorJson) return;
        try {
            editColor(JSON.parse(btn.dataset.colorJson));
        } catch (err) {
            alert('تعذر قراءة بيانات اللون للتعديل');
        }
    });
})();
</script>
