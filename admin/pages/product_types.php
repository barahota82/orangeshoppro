<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$unifiedActive = orange_catalog_nav_use_unified($pdo);

$hasTree = orange_table_exists($pdo, 'product_types')
    && orange_table_exists($pdo, 'catalog_subcategories')
    && orange_table_exists($pdo, 'catalog_categories')
    && orange_table_exists($pdo, 'catalog_sections')
    && orange_table_exists($pdo, 'departments');

/** @param array<string, mixed> $r */
$branchLabel = static function (array $r): string {
    $p = [];
    foreach (['dept_ar', 'sec_ar', 'cat_ar'] as $k) {
        $v = trim((string) ($r[$k] ?? ''));
        if ($v !== '') {
            $p[] = $v;
        }
    }
    $sub = trim((string) ($r['sub_ar'] ?? ''));
    if ($sub === '') {
        $sub = trim((string) ($r['sub_en'] ?? ''));
    }
    if ($sub === '') {
        $sub = (string) ($r['sub_slug'] ?? '');
    }
    $fallbackId = (int) ($r['catalog_subcategory_id'] ?? 0);
    if ($fallbackId <= 0) {
        $fallbackId = (int) ($r['id'] ?? 0);
    }
    $p[] = $sub !== '' ? $sub : ('#' . $fallbackId);

    return implode(' ← ', array_reverse($p));
};

$subOptions = [];
$typesList = [];

if ($hasTree) {
    try {
        $subOptsStmt = $pdo->query(
            'SELECT csub.id, csub.slug AS sub_slug, csub.name_ar AS sub_ar, csub.name_en AS sub_en,
                    cc.name_ar AS cat_ar, cc.name_en AS cat_en,
                    cs.name_ar AS sec_ar, cs.name_en AS sec_en,
                    d.name_ar AS dept_ar, d.name_en AS dept_en,
                    d.sort_order AS dept_so, d.id AS dept_id,
                    cs.sort_order AS sec_so, cs.id AS sec_id,
                    cc.sort_order AS cc_so, cc.id AS cc_id,
                    csub.sort_order AS sub_so
             FROM catalog_subcategories csub
             INNER JOIN catalog_categories cc ON cc.id = csub.catalog_category_id
             INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
             INNER JOIN departments d ON d.id = cs.department_id
             WHERE csub.is_active = 1 AND cc.is_active = 1 AND cs.is_active = 1 AND d.is_active = 1
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC,
                      cc.sort_order ASC, cc.id ASC, csub.sort_order ASC, csub.id ASC'
        );
        foreach ($subOptsStmt ? ($subOptsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $r) {
            if (! is_array($r)) {
                continue;
            }
            $sid = (int) ($r['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $subOptions[] = [
                'id' => $sid,
                'label' => $branchLabel($r),
            ];
        }
    } catch (Throwable $e) {
        $subOptions = [];
    }

    try {
        $typesList = $pdo->query(
            'SELECT pt.id, pt.slug, pt.name_ar, pt.name_en, pt.name_fil, pt.name_hi,
                    pt.catalog_subcategory_id, pt.expected_size_scheme_key,
                    pt.sort_order, pt.is_active,
                    csub.slug AS sub_slug, csub.name_ar AS sub_ar, csub.name_en AS sub_en,
                    cc.name_ar AS cat_ar, cc.name_en AS cat_en,
                    cs.name_ar AS sec_ar, cs.name_en AS sec_en,
                    d.name_ar AS dept_ar, d.name_en AS dept_en
             FROM product_types pt
             INNER JOIN catalog_subcategories csub ON csub.id = pt.catalog_subcategory_id
             INNER JOIN catalog_categories cc ON cc.id = csub.catalog_category_id
             INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id
             INNER JOIN departments d ON d.id = cs.department_id
             ORDER BY d.sort_order ASC, d.id ASC, cs.sort_order ASC, cs.id ASC,
                      cc.sort_order ASC, cc.id ASC, csub.sort_order ASC, csub.id ASC,
                      pt.sort_order ASC, pt.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($typesList as &$trow) {
            if (! is_array($trow)) {
                continue;
            }
            $trow['_branch'] = $branchLabel($trow);
        }
        unset($trow);
    } catch (Throwable $e) {
        $typesList = [];
    }
}

$subOptionsJson = json_encode($subOptions, JSON_UNESCAPED_UNICODE);
if ($subOptionsJson === false) {
    $subOptionsJson = '[]';
}
?>
<div class="page-title">
    <h1>أنواع المنتجات — الشجرة الموحّدة</h1>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;">ورقة قبل SKU تحت التصنيف الفرعي الموحّد؛ ربط المنتجات يتم عبر <code>product_type_id</code> وفق السياسة (تاسعاً ووثيقة ERD). أنشئ أو راجع الفروع من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=unified_catalog_branches'), ENT_QUOTES, 'UTF-8'); ?>">فروع الشجرة الموحّدة</a>. حقل <strong>expected_size_scheme_key</strong> يضبط مطابقة مخطط المقاس مع <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=size_families'), ENT_QUOTES, 'UTF-8'); ?>">عائلات المقاسات</a>.</p>
</div>

<?php if (!$hasTree): ?>
<div class="card">
    <div class="alert-error">جدايل الشجرة الموحّدة غير مهيّأة بعد (product_types وجداول الأم). تأكّد من تشغيل مخطّط الكتالوج.</div>
</div>
<?php else: ?>

<?php if (! $unifiedActive): ?>
<div class="card" style="margin-bottom:12px;background:#fffbeb;border-color:#fcd34d;">
    <p style="margin:0;color:#92400e;">لم يُفعَّل مسار المتجر الموحّد بعد (ترحيل البيانات). ما زال بالإمكان تهيئة الأوراق ومفتاح مخطّط المقاس؛ منتجاتك تُخيَّر بين الفئة القديمة ونوع المنتج إلى أن يكتمل الخطوة في سجلّ الترحيل.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل نوع منتج</h3>
    <input type="hidden" id="pt_id" value="0">
    <div class="pt-form-grid form-grid">
        <div style="grid-column:1/-1;">
            <label for="pt_catalog_subcategory_id">مسار الشجرة (التصنيف الفرعي الموحّد)</label>
            <select id="pt_catalog_subcategory_id" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
                <option value="">— اختر الفرع —</option>
                <?php foreach ($subOptions as $so): ?>
                    <option value="<?php echo (int) $so['id']; ?>"><?php echo htmlspecialchars((string) $so['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($subOptions === []): ?>
                <small style="display:block;color:#b45309;margin-top:4px;">لا توجد أفرع نشطة؛ أنشئ أقسام الشجرة الموحّدة عبر ترحيل البيانات أو إعداد يدوي.</small>
            <?php endif; ?>
        </div>
        <div>
            <label for="pt_slug">slug</label>
            <input type="text" id="pt_slug" dir="ltr" lang="en" maxlength="191" placeholder="women-tshirt" autocomplete="off" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;">إنجليزي صغير، أول محرف حرف أو رقم؛ ثم <code>_</code> أو <code>-</code>.</small>
        </div>
        <div>
            <label for="pt_expected_size_scheme_key">expected_size_scheme_key</label>
            <input type="text" id="pt_expected_size_scheme_key" dir="ltr" lang="en" maxlength="64" placeholder="clothing_alpha أو فارغ"
                <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;">إن ملأته وفعلت المقاسات على منتج؛ يُلزِم أي عائلة مقاس مستخدمة بتطابق <code>size_scheme_key</code> وكلّ المستويين الفوقيّين على العائلة.</small>
        </div>
        <div>
            <label for="pt_sort">ترتيب ضمن الفرع</label>
            <input type="number" id="pt_sort" min="1" step="1" value="" placeholder="تلقائي"
                <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div>
            <label for="pt_name_ar">اسم العربي</label>
            <input type="text" id="pt_name_ar" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div>
            <label for="pt_name_fil">Filipino</label>
            <input type="text" id="pt_name_fil" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div>
            <label for="pt_name_en">English</label>
            <input type="text" id="pt_name_en" dir="ltr" lang="en" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div>
            <label for="pt_name_hi">Hindi</label>
            <input type="text" id="pt_name_hi" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div>
            <label for="pt_active">نشط</label>
            <select id="pt_active" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
    </div>
    <div class="actions" style="margin-top:14px;gap:8px;flex-wrap:wrap;">
        <button type="button" onclick="saveProductType()" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="translatePtNames({ forceFromArabic: true })" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>ترجمة تلقائية</button>
        <button type="button" class="btn-secondary" onclick="resetPtForm()" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>جديد</button>
    </div>
</div>

<div class="card">
    <h3 style="margin-top:0;">القائمة</h3>
    <?php if ($typesList === []): ?>
        <p style="margin:0;color:#555;">لا توجد أنواع بعد.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="border-collapse:collapse;width:100%;font-size:0.93rem;">
                <thead>
                <tr>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">#</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">المسار</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">slug</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">عربي</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">EN</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">مخطّط مقاس متوقَّع</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">ترتيب</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">نشط</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">إجراء</th>
                </tr>
                </thead>
                <tbody id="orange-pt-list-tbody">
                <?php foreach ($typesList as $row): ?>
                    <?php if (! is_array($row)) { continue; } ?>
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo (int) ($row['id'] ?? 0); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo htmlspecialchars((string) ($row['_branch'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;" dir="ltr" lang="en"><?php echo htmlspecialchars((string) ($row['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo htmlspecialchars((string) ($row['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;" dir="ltr" lang="en"><?php echo htmlspecialchars((string) ($row['name_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;" dir="ltr" lang="en"><?php echo htmlspecialchars(trim((string) ($row['expected_size_scheme_key'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;"><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;"><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;">
                            <button type="button" class="btn-secondary pt-edit-btn" data-pt-json="<?php echo htmlspecialchars(json_encode([
                                'id' => (int) ($row['id'] ?? 0),
                                'catalog_subcategory_id' => (int) ($row['catalog_subcategory_id'] ?? 0),
                                'slug' => (string) ($row['slug'] ?? ''),
                                'name_ar' => (string) ($row['name_ar'] ?? ''),
                                'name_en' => (string) ($row['name_en'] ?? ''),
                                'name_fil' => (string) ($row['name_fil'] ?? ''),
                                'name_hi' => (string) ($row['name_hi'] ?? ''),
                                'expected_size_scheme_key' => (string) ($row['expected_size_scheme_key'] ?? ''),
                                'sort_order' => (int) ($row['sort_order'] ?? 0),
                                'is_active' => (int) ($row['is_active'] ?? 1),
                            ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.pt-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 18px;
    direction: ltr;
}
.pt-form-grid label,
.pt-form-grid input,
.pt-form-grid select { direction: rtl; text-align: right; }
.pt-form-grid #pt_slug,
.pt-form-grid #pt_expected_size_scheme_key,
.pt-form-grid #pt_name_en { text-align: left; direction: ltr; }
@media (max-width: 860px) {
    .pt-form-grid { grid-template-columns: 1fr; }
}
</style>

<script>
const ptSubOptions = <?php echo $subOptionsJson; ?>;
let ptTranslateTimer = null;
let ptEnTranslateTimer = null;
let isSavingPt = false;

function resetPtForm() {
    document.getElementById('pt_id').value = '0';
    document.getElementById('pt_catalog_subcategory_id').value = '';
    document.getElementById('pt_slug').value = '';
    document.getElementById('pt_expected_size_scheme_key').value = '';
    document.getElementById('pt_sort').value = '';
    document.getElementById('pt_name_ar').value = '';
    document.getElementById('pt_name_en').value = '';
    document.getElementById('pt_name_fil').value = '';
    document.getElementById('pt_name_hi').value = '';
    document.getElementById('pt_active').value = '1';
}

function editProductType(p) {
    document.getElementById('pt_id').value = String(p.id != null ? p.id : 0);
    document.getElementById('pt_catalog_subcategory_id').value = String(p.catalog_subcategory_id != null ? p.catalog_subcategory_id : '');
    document.getElementById('pt_slug').value = p.slug || '';
    document.getElementById('pt_expected_size_scheme_key').value = p.expected_size_scheme_key || '';
    document.getElementById('pt_sort').value = p.sort_order != null && p.sort_order > 0 ? String(p.sort_order) : '';
    document.getElementById('pt_name_ar').value = p.name_ar || '';
    document.getElementById('pt_name_en').value = p.name_en || '';
    document.getElementById('pt_name_fil').value = p.name_fil || '';
    document.getElementById('pt_name_hi').value = p.name_hi || '';
    document.getElementById('pt_active').value = String((p.is_active === 0 || p.is_active === false) ? 0 : 1);
    warnIfSubNotInDropdown(p.catalog_subcategory_id);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/** @param {number} cid */
function warnIfSubNotInDropdown(cid) {
    const sel = document.getElementById('pt_catalog_subcategory_id');
    if (!sel || cid == null || !cid) return;
    const v = String(cid);
    const exists = Array.prototype.some.call(sel.options, function (o) { return o.value === v; });
    if (!exists) {
        const hit = Array.isArray(ptSubOptions) ? ptSubOptions.find(function (x) { return String(x.id) === v; }) : null;
        const opt = document.createElement('option');
        opt.value = v;
        opt.textContent = hit && hit.label ? hit.label : ('فرع #' + v + ' (قد يكون غير نشط)');
        sel.insertBefore(opt, sel.options[1] || null);
    }
}

async function translatePtNames(opts) {
    opts = opts || {};
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const payload = {
            name_ar: document.getElementById('pt_name_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('pt_name_en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('pt_name_en').value = t.name_en;
        if (t.name_fil) document.getElementById('pt_name_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('pt_name_hi').value = t.name_hi;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function schedulePtAutoTranslate() {
    const ar = document.getElementById('pt_name_ar').value.trim();
    if (!ar) {
        document.getElementById('pt_name_en').value = '';
        document.getElementById('pt_name_fil').value = '';
        document.getElementById('pt_name_hi').value = '';
        return;
    }
    if (ptTranslateTimer) clearTimeout(ptTranslateTimer);
    ptTranslateTimer = setTimeout(function () {
        translatePtNames({ silent: true, forceFromArabic: true });
    }, 700);
}

function schedulePtTranslateFromEnglish() {
    if (ptEnTranslateTimer) clearTimeout(ptEnTranslateTimer);
    ptEnTranslateTimer = setTimeout(function () {
        const en = document.getElementById('pt_name_en').value.trim();
        if (!en) return;
        translatePtNames({ silent: true, forceFromArabic: false });
    }, 650);
}

async function saveProductType() {
    if (isSavingPt) return;
    isSavingPt = true;
    try {
        const recordId = parseInt(document.getElementById('pt_id').value || '0', 10) || 0;
        const subId = parseInt(document.getElementById('pt_catalog_subcategory_id').value || '0', 10) || 0;
        const sortRaw = document.getElementById('pt_sort').value.trim();
        const sortParsed = sortRaw === '' ? 0 : (parseInt(sortRaw, 10) || 0);
        const payload = {
            catalog_subcategory_id: subId,
            slug: document.getElementById('pt_slug').value.trim(),
            name_ar: document.getElementById('pt_name_ar').value.trim(),
            name_en: document.getElementById('pt_name_en').value.trim(),
            name_fil: document.getElementById('pt_name_fil').value.trim(),
            name_hi: document.getElementById('pt_name_hi').value.trim(),
            expected_size_scheme_key: document.getElementById('pt_expected_size_scheme_key').value.trim(),
            sort_order: sortParsed,
            is_active: parseInt(document.getElementById('pt_active').value || '1', 10) ? 1 : 0
        };
        if (recordId > 0) payload.id = recordId;
        const res = await postJSON('/admin/api/product_types/save.php', payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        isSavingPt = false;
    }
}

(function () {
    var arEl = document.getElementById('pt_name_ar');
    var enEl = document.getElementById('pt_name_en');
    if (arEl) {
        arEl.addEventListener('input', schedulePtAutoTranslate);
        arEl.addEventListener('change', function () {
            if (arEl.value.trim()) {
                translatePtNames({ silent: true, forceFromArabic: true });
            }
        });
    }
    if (enEl) {
        enEl.addEventListener('input', schedulePtTranslateFromEnglish);
    }
    var tbody = document.getElementById('orange-pt-list-tbody');
    if (tbody) {
        tbody.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.pt-edit-btn');
            if (!btn || !btn.dataset.ptJson) return;
            try {
                editProductType(JSON.parse(btn.dataset.ptJson));
            } catch (err) {
                alert('تعذر قراءة بيانات النوع للتعديل');
            }
        });
    }
})();
</script>

<?php endif; ?>
