<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$hasTable = orange_table_exists($pdo, 'pattern_dictionary');

$patterns = [];
$nextSort = 1;
if ($hasTable) {
    try {
        $patterns = $pdo->query('SELECT * FROM pattern_dictionary ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM pattern_dictionary')->fetchColumn();
        if ($nextSort <= 0) {
            $nextSort = 1;
        }
    } catch (Throwable $e) {
        $patterns = [];
    }
}
?>
<div class="page-title">
    <h1>أنماط الألوان</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>pattern_dictionary</code> غير متاح بعد ترحيل المخطط — حدّث الصفحة أو راجع سجلات الخادم.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل نمط</h3>
    <input type="hidden" id="pattern_id" value="0">
    <div class="form-grid pd-form-grid">
        <div class="pd-sort admin-sort-field-wrap">
            <label>الترتيب (تلقائي)</label>
            <input type="number" id="p_sort" class="admin-sort-field admin-sort-field--muted" value="<?php echo (int) $nextSort; ?>" disabled>
        </div>
        <div class="pd-active admin-sort-field-wrap">
            <label>نشط</label>
            <select id="p_active" class="admin-sort-field" <?php echo !$hasTable ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div class="pd-ar">
            <label>الاسم العربي</label>
            <input type="text" id="p_name_ar" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
        <div class="pd-en">
            <label>English</label>
            <input type="text" id="p_name_en" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
        <div class="pd-fil">
            <label>Filipino</label>
            <input type="text" id="p_name_fil" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
        <div class="pd-hi">
            <label>Hindi</label>
            <input type="text" id="p_name_hi" <?php echo !$hasTable ? 'disabled' : ''; ?>>
        </div>
    </div>
    <div class="actions pd-form-actions" style="margin-top:14px;">
        <button type="button" onclick="savePattern()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ النمط</button>
        <button type="button" class="btn-secondary" onclick="translatePattern({ forceFromArabic: true })" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة</button>
        <button type="button" class="btn-secondary" onclick="resetPatternForm()" <?php echo !$hasTable ? 'disabled' : ''; ?>>جديد</button>
    </div>
</div>

<?php if ($hasTable): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h3 style="margin:0;">قائمة الأنماط</h3>
        <div class="actions">
            <button type="button" class="btn-secondary" onclick="savePatternsOrder()">حفظ الترتيب</button>
        </div>
    </div>
    <div class="table-wrap cat-dep-list-wrap" data-list="patterns" style="margin-top:10px;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>العربي</th>
                    <th>English</th>
                    <th>Filipino</th>
                    <th>Hindi</th>
                    <th>الترتيب</th>
                    <th>الحالة</th>
                    <th class="pd-ops-col">إجراءات</th>
                </tr>
            </thead>
            <tbody id="orange-patterns-list-tbody">
                <?php foreach ($patterns as $c): ?>
                <tr data-id="<?php echo (int) $c['id']; ?>">
                    <td><?php echo (int) $c['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $c['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) $c['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($c['name_fil'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($c['name_hi'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) $c['sort_order']; ?></td>
                    <td><?php echo (int) $c['is_active'] === 1 ? 'ظاهر' : 'مخفي'; ?></td>
                    <td class="pd-row-ops">
                        <div class="pd-ops-wrap">
                            <div class="pd-ops-arrows">
                                <button type="button" class="btn-secondary pd-btn-reorder" onclick="movePatternRow(this,'up')" aria-label="أعلى">↑</button>
                                <button type="button" class="btn-secondary pd-btn-reorder" onclick="movePatternRow(this,'down')" aria-label="أسفل">↓</button>
                            </div>
                            <div class="pd-ops-main">
                                <button type="button" class="btn-secondary pd-edit-btn" data-pattern-json="<?php echo htmlspecialchars(json_encode([
                                    'id' => (int) $c['id'],
                                    'name_ar' => (string) $c['name_ar'],
                                    'name_en' => (string) $c['name_en'],
                                    'name_fil' => (string) ($c['name_fil'] ?? ''),
                                    'name_hi' => (string) ($c['name_hi'] ?? ''),
                                    'sort_order' => (int) $c['sort_order'],
                                    'is_active' => (int) $c['is_active'],
                                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                                <button type="button" class="pd-btn-toggle" onclick="togglePattern(<?php echo (int) $c['id']; ?>, <?php echo (int) $c['is_active']; ?>)">
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
const defaultNextPatternSort = <?php echo (int) $nextSort; ?>;
let patternTranslateTimer = null;
let patternEnTranslateTimer = null;
let isSavingPattern = false;

function resetPatternForm() {
    document.getElementById('pattern_id').value = '0';
    document.getElementById('p_name_ar').value = '';
    document.getElementById('p_name_en').value = '';
    document.getElementById('p_name_fil').value = '';
    document.getElementById('p_name_hi').value = '';
    document.getElementById('p_sort').value = String(defaultNextPatternSort || 1);
    document.getElementById('p_active').value = '1';
}

function editPattern(c) {
    document.getElementById('pattern_id').value = String(c.id != null ? c.id : 0);
    document.getElementById('p_name_ar').value = c.name_ar || '';
    document.getElementById('p_name_en').value = c.name_en || '';
    document.getElementById('p_name_fil').value = c.name_fil || '';
    document.getElementById('p_name_hi').value = c.name_hi || '';
    document.getElementById('p_sort').value = String(c.sort_order ?? 0);
    document.getElementById('p_active').value = String(c.is_active === 0 ? 0 : 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function translatePattern(opts) {
    opts = opts || {};
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const payload = {
            name_ar: document.getElementById('p_name_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('p_name_en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('p_name_en').value = t.name_en;
        if (t.name_fil) document.getElementById('p_name_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('p_name_hi').value = t.name_hi;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function schedulePatternAutoTranslate() {
    const nameAr = document.getElementById('p_name_ar').value.trim();
    if (!nameAr) {
        document.getElementById('p_name_en').value = '';
        document.getElementById('p_name_fil').value = '';
        document.getElementById('p_name_hi').value = '';
        return;
    }
    clearTimeout(patternTranslateTimer);
    patternTranslateTimer = setTimeout(function () {
        translatePattern({ silent: true, forceFromArabic: true });
    }, 600);
}

function schedulePatternTranslateFromEnglish() {
    const nameEn = document.getElementById('p_name_en').value.trim();
    if (!nameEn) {
        return;
    }
    clearTimeout(patternEnTranslateTimer);
    patternEnTranslateTimer = setTimeout(function () {
        translatePattern({ silent: true, forceFromArabic: false });
    }, 550);
}

async function savePattern() {
    if (isSavingPattern) return;
    isSavingPattern = true;
    const required = [
        { id: 'p_name_ar', label: 'الاسم العربي' },
        { id: 'p_name_en', label: 'English' },
        { id: 'p_name_fil', label: 'Filipino' },
        { id: 'p_name_hi', label: 'Hindi' }
    ];
    for (let i = 0; i < required.length; i++) {
        const f = required[i];
        if (!document.getElementById(f.id).value.trim()) {
            alert('يجب إضافة خانة ' + f.label + ' قبل الحفظ');
            isSavingPattern = false;
            return;
        }
    }
    try {
        const rawId = parseInt(String(document.getElementById('pattern_id').value || '0').trim(), 10);
        const recordId = (Number.isFinite(rawId) && rawId > 0) ? rawId : 0;
        const payload = {
            name_ar: document.getElementById('p_name_ar').value.trim(),
            name_en: document.getElementById('p_name_en').value.trim(),
            name_fil: document.getElementById('p_name_fil').value.trim(),
            name_hi: document.getElementById('p_name_hi').value.trim(),
            sort_order: parseInt(document.getElementById('p_sort').value || '0', 10),
            is_active: parseInt(document.getElementById('p_active').value, 10)
        };
        if (recordId > 0) payload.id = recordId;
        const res = await postJSON('/admin/api/patterns/save.php', payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        isSavingPattern = false;
    }
}

async function togglePattern(id, isActive) {
    const res = await postJSON('/admin/api/patterns/toggle.php', {
        id: id,
        is_active: isActive ? 0 : 1
    });
    alert(res.message || (res.success ? 'تم التعديل' : 'فشل التعديل'));
    if (res.success) location.reload();
}

function movePatternRow(btn, dir) {
    const tr = btn.closest('tr');
    if (!tr) return;
    const tbody = document.getElementById('orange-patterns-list-tbody');
    if (!tbody) return;
    if (dir === 'up') {
        const prev = tr.previousElementSibling;
        if (prev) tbody.insertBefore(tr, prev);
    } else {
        const next = tr.nextElementSibling;
        if (next) tbody.insertBefore(next, tr);
    }
}

async function savePatternsOrder() {
    const tbody = document.getElementById('orange-patterns-list-tbody');
    if (!tbody) return;
    const ids = Array.from(tbody.querySelectorAll('tr[data-id]'))
        .map(tr => parseInt(tr.getAttribute('data-id') || '0', 10))
        .filter(id => id > 0);
    const res = await postJSON('/admin/api/patterns/reorder-save.php', { ordered_ids: ids });
    alert(res.message || (res.success ? 'تم حفظ الترتيب' : 'فشل حفظ الترتيب'));
    if (res.success) location.reload();
}

const pArEl = document.getElementById('p_name_ar');
const pEnEl = document.getElementById('p_name_en');
if (pArEl) {
    pArEl.addEventListener('input', schedulePatternAutoTranslate);
    pArEl.addEventListener('change', function () {
        if (pArEl.value.trim()) translatePattern({ silent: true, forceFromArabic: true });
    });
}
if (pEnEl) pEnEl.addEventListener('input', schedulePatternTranslateFromEnglish);

(function () {
    const style = document.createElement('style');
    style.textContent = `
        .pd-form-grid{
            display:grid;
            grid-template-columns:repeat(12,minmax(0,1fr));
            grid-template-areas:
                "active active . . . . . . . . sort sort"
                "en en en en en en ar ar ar ar ar ar"
                "hi hi hi hi hi hi fil fil fil fil fil fil";
            gap:14px 18px;
            direction:ltr;
        }
        .pd-form-grid .pd-sort{
            grid-area:sort;
            justify-self:end;
            width:100%;
        }
        .pd-form-grid .pd-active{
            grid-area:active;
            justify-self:start;
            width:100%;
        }
        .pd-form-grid .pd-ar{grid-area:ar}
        .pd-form-grid .pd-en{grid-area:en}
        .pd-form-grid .pd-hi{grid-area:hi}
        .pd-form-grid .pd-fil{grid-area:fil}
        .pd-form-grid label,
        .pd-form-grid input,
        .pd-form-grid select{direction:rtl;text-align:right}
        .pd-form-grid #p_sort,
        .pd-form-grid #p_active{
            margin-inline:0;
            display:block;
            width:100%;
            box-sizing:border-box;
            border:1px solid #cbd5e1;
            border-radius:var(--radius-sm,10px);
            font-size:14px;
            line-height:calc(var(--input-min-h,36px) - 2px);
            min-height:var(--input-min-h,36px);
            height:var(--input-min-h,36px);
            max-height:var(--input-min-h,36px);
            padding-block:0;
            padding-inline:12px;
        }
        .pd-form-grid input#p_sort::-webkit-outer-spin-button,
        .pd-form-grid input#p_sort::-webkit-inner-spin-button{
            -webkit-appearance:none;
            margin:0;
        }
        .pd-form-grid input#p_sort{
            -moz-appearance:textfield;
            appearance:textfield;
        }
        .pd-form-grid #p_active{
            -webkit-appearance:none;
            appearance:none;
            background-color:#fff;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M2.75 4.25L6 7.55l3.25-3.3.65.64L6 8.82 2.1 4.9l.65-.65z'/%3E%3C/svg%3E");
            background-repeat:no-repeat;
            background-size:12px;
            background-position:left 12px center;
            padding-inline-end:32px;
        }
        .pd-form-actions{justify-content:flex-end}
        @media (max-width: 860px){
            .pd-form-grid{
                grid-template-columns:1fr;
                grid-template-areas:
                    "sort"
                    "active"
                    "ar"
                    "en"
                    "fil"
                    "hi";
            }
            .pd-form-grid .pd-sort,
            .pd-form-grid .pd-active{
                justify-self:start;
                max-width:var(--admin-sort-field-max-w,220px)
            }
        }
        .cat-dep-list-wrap[data-list="patterns"]{
            overflow-x:auto;
            max-width:none;
            -webkit-overflow-scrolling:touch;
        }
        .cat-dep-list-wrap[data-list="patterns"] > table{
            min-width:860px;
            width:100%;
            border-collapse:collapse;
            table-layout:fixed;
        }
        .cat-dep-list-wrap[data-list="patterns"] > table th,
        .cat-dep-list-wrap[data-list="patterns"] > table td{
            vertical-align:middle;
        }
        .cat-dep-list-wrap[data-list="patterns"] table .pd-ops-col,
        .cat-dep-list-wrap[data-list="patterns"] table .pd-row-ops{
            width:200px !important;
            min-width:200px !important;
            max-width:200px !important;
            box-sizing:border-box !important;
            text-align:center !important;
            vertical-align:middle !important;
            padding:6px 8px !important;
        }
        .cat-dep-list-wrap[data-list="patterns"] .pd-ops-wrap{
            display:grid;
            grid-template-columns:38px minmax(0,1fr);
            gap:8px;
            align-items:center;
            margin:0 auto;
            max-width:100%;
            direction:rtl;
        }
        .cat-dep-list-wrap[data-list="patterns"] .pd-ops-arrows{
            display:flex;
            flex-direction:column;
            gap:4px;
            align-items:center;
            justify-content:center;
        }
        .cat-dep-list-wrap[data-list="patterns"] .pd-ops-wrap button.pd-btn-reorder{
            width:32px !important;
            min-width:32px !important;
            height:28px !important;
            margin:0 !important;
            padding:0 !important;
            font-size:13px !important;
            line-height:1 !important;
            border-radius:6px !important;
            display:inline-flex !important;
            align-items:center;
            justify-content:center;
        }
        .cat-dep-list-wrap[data-list="patterns"] .pd-ops-main{
            display:flex;
            flex-direction:column;
            gap:5px;
            min-width:0;
        }
        .cat-dep-list-wrap[data-list="patterns"] .pd-ops-main .btn-secondary,
        .cat-dep-list-wrap[data-list="patterns"] .pd-ops-main .pd-btn-toggle{
            width:100% !important;
            margin:0 !important;
            padding:6px 8px !important;
            font-size:12px !important;
            line-height:1.2 !important;
            border-radius:6px !important;
            box-sizing:border-box !important;
            min-height:30px !important;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
    `;
    document.head.appendChild(style);

    const tbody = document.getElementById('orange-patterns-list-tbody');
    if (!tbody) return;
    tbody.addEventListener('click', function (ev) {
        const btn = orangeAdminClosest(ev, '.pd-edit-btn');
        if (!btn || !btn.dataset.patternJson) return;
        try {
            editPattern(JSON.parse(btn.dataset.patternJson));
        } catch (err) {
            alert('تعذر قراءة بيانات النمط للتعديل');
        }
    });
})();
</script>
