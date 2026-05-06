<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/catalog_sizing_dictionary.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$sizingDictForPtForm = orange_table_exists($pdo, 'commercial_kind_dictionary')
    && orange_table_exists($pdo, 'sizing_category_dictionary');

/** @var list<array<string,mixed>> */
$ptCommercialKindRows = [];
/** @var array<string, list<array<string,mixed>>> */
$ptSizingCatsByKind = [];
if ($sizingDictForPtForm) {
    try {
        $ptCommercialKindRows = $pdo->query(
            'SELECT kind_key, label_ar, label_en, sort_order, is_active
             FROM commercial_kind_dictionary
             ORDER BY sort_order ASC, kind_key ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $allCats = $pdo->query(
            'SELECT commercial_kind_key, category_key, label_ar, label_en, sort_order, is_active
             FROM sizing_category_dictionary
             ORDER BY commercial_kind_key ASC, sort_order ASC, category_key ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($allCats as $cr) {
            if (! is_array($cr)) {
                continue;
            }
            $k = trim((string) ($cr['commercial_kind_key'] ?? ''));
            if ($k === '') {
                continue;
            }
            if (! isset($ptSizingCatsByKind[$k])) {
                $ptSizingCatsByKind[$k] = [];
            }
            $ptSizingCatsByKind[$k][] = $cr;
        }
    } catch (Throwable $e) {
        $ptCommercialKindRows = [];
        $ptSizingCatsByKind = [];
    }
}
$ptSizingCatsByKindJson = json_encode($ptSizingCatsByKind, JSON_UNESCAPED_UNICODE);
if ($ptSizingCatsByKindJson === false) {
    $ptSizingCatsByKindJson = '{}';
}
$ptCommercialKindsJson = json_encode($ptCommercialKindRows, JSON_UNESCAPED_UNICODE);
if ($ptCommercialKindsJson === false) {
    $ptCommercialKindsJson = '[]';
}

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
                    pt.catalog_subcategory_id,
                    pt.expected_commercial_kind_key, pt.expected_sizing_category_key,
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
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;line-height:1.55;">ورقة قبل SKU تحت التصنيف الفرعي الموحّد؛ ربط المنتجات يتم عبر <code>product_type_id</code>. حدّد <strong>مسار الشجرة</strong> ثم <strong>النوع التجاري</strong> و<strong>فئة القياس</strong> (هرَم المقاس 1–2 من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sizing_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">القاموس المرجعي</a>)؛ عند تسجيل منتج تُعرض عائلات المقاسات المطابقة لهذا الهرم فقط في شاشة المنتجات. الفروع: <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=unified_catalog_branches'), ENT_QUOTES, 'UTF-8'); ?>">فروع شجرة المنتجات</a> — العائلات: <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=size_families'), ENT_QUOTES, 'UTF-8'); ?>">عائلات المقاسات</a>.</p>
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

<?php if ($hasTree && $subOptions === []): ?>
<div class="card" style="margin-bottom:12px;background:#f0f9ff;border:1px solid #bae6fd;">
    <p style="margin:0;color:#0c4a6e;line-height:1.55;"><strong>لا يوجد تصنيف فرعي نشط بعد</strong> على الشجرة الموحّدة، لذلك لا يمكن اختيار «مسار الشجرة» هنا. أنشئ قسماً رئيسياً من
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=departments'), ENT_QUOTES, 'UTF-8'); ?>">الأقسام الرئيسية</a>
        ثم من
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=unified_catalog_branches'), ENT_QUOTES, 'UTF-8'); ?>">فروع شجرة المنتجات</a>
        أضف Section ثم Category ثم Subcategory على الأقل؛ بعدها سيظهر التصنيف الفرعي في القائمة أدناه.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل نوع منتج</h3>
    <input type="hidden" id="pt_id" value="0">
    <div class="form-grid pt-form-grid">
        <div class="pt-sort admin-sort-field-wrap">
            <label for="pt_sort">ترتيب ضمن الفرع</label>
            <input type="number" id="pt_sort" class="admin-sort-field<?php echo $subOptions === [] ? ' admin-sort-field--muted' : ''; ?>" min="1" step="1" value="" placeholder="تلقائي"
                <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div class="pt-slug">
            <label for="pt_slug">slug</label>
            <input type="text" id="pt_slug" dir="ltr" lang="en" maxlength="191" placeholder="women-tshirt" autocomplete="off" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;">إنجليزي صغير، أول محرف حرف أو رقم؛ ثم <code>_</code> أو <code>-</code>.</small>
        </div>
        <div class="pt-active admin-sort-field-wrap">
            <label for="pt_active">نشط</label>
            <select id="pt_active" class="admin-sort-field" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div class="pt-path">
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
        <div class="pt-ck">
            <?php if ($sizingDictForPtForm): ?>
            <label for="pt_expected_commercial_kind_key">النوع التجاري المتوقع (مستوى 1)</label>
            <select id="pt_expected_commercial_kind_key" class="admin-sort-field" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
                <option value="">— بدون نطاق هرَم —</option>
                <?php foreach ($ptCommercialKindRows as $krow): ?>
                    <?php if (! is_array($krow)) {
                        continue;
                    } ?>
                    <?php
                    $kk = htmlspecialchars(trim((string) ($krow['kind_key'] ?? '')), ENT_QUOTES, 'UTF-8');
                    if ($kk === '') {
                        continue;
                    }
                    $klab = trim((string) ($krow['label_ar'] ?? ''));
                    if ($klab === '') {
                        $klab = trim((string) ($krow['label_en'] ?? ''));
                    }
                    if ($klab === '') {
                        $klab = $kk;
                    }
                    $klabDisp = htmlspecialchars($klab . ' (' . $kk . ')', ENT_QUOTES, 'UTF-8');
                    ?>
                    <option value="<?php echo $kk; ?>"
                        data-label-ar="<?php echo htmlspecialchars(trim((string) ($krow['label_ar'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                        data-label-en="<?php echo htmlspecialchars(trim((string) ($krow['label_en'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $klabDisp; ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($ptCommercialKindRows === []): ?>
                <small style="display:block;color:#b45309;margin-top:4px;font-size:0.85rem;">لا توجد صفوف في <code>commercial_kind_dictionary</code> بعد — أضف الأنواع التجارية من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sizing_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">القاموس المرجعي للمقاسات</a> حتى تظهر الخيارات هنا.</small>
            <?php endif; ?>
            <?php else: ?>
            <label for="pt_expected_commercial_kind_key"><code>expected_commercial_kind_key</code></label>
            <input type="text" id="pt_expected_commercial_kind_key" class="admin-sort-field" maxlength="32" placeholder="clothing" dir="ltr" lang="en"
                <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
            <?php endif; ?>
            <small style="display:block;color:#666;margin-top:4px;font-size:0.85rem;">معاً مع «فئة القياس» يحدّدان أي عائلات مقاسات تظهر عند اختيار هذا النوع في <strong>المنتجات</strong> (أي مخطط مقاس مستوى 3 ضمن نفس الفئة).</small>
        </div>
        <div class="pt-sk">
            <?php if ($sizingDictForPtForm): ?>
            <label for="pt_expected_sizing_category_key">فئة القياس المتوقعة (مستوى 2)</label>
            <select id="pt_expected_sizing_category_key" class="admin-sort-field" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
                <option value="">— اختر النوع التجاري أولاً —</option>
            </select>
            <small id="pt_sizing_cat_empty_hint" style="display:none;color:#b45309;margin-top:4px;font-size:0.85rem;">لا توجد فئات قياس مسجّلة لهذا النوع التجاري في القاموس المرجعي — راجع <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=sizing_dictionary'), ENT_QUOTES, 'UTF-8'); ?>">القاموس المرجعي للمقاسات</a>.</small>
            <?php else: ?>
            <label for="pt_expected_sizing_category_key"><code>expected_sizing_category_key</code></label>
            <input type="text" id="pt_expected_sizing_category_key" class="admin-sort-field" maxlength="64" placeholder="tops" dir="ltr" lang="en"
                <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
            <?php endif; ?>
        </div>
        <div class="pt-ar">
            <label for="pt_name_ar">اسم العربي</label>
            <input type="text" id="pt_name_ar" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div class="pt-en">
            <label for="pt_name_en">English</label>
            <input type="text" id="pt_name_en" dir="ltr" lang="en" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div class="pt-fil">
            <label for="pt_name_fil">Filipino</label>
            <input type="text" id="pt_name_fil" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
        <div class="pt-hi">
            <label for="pt_name_hi">Hindi</label>
            <input type="text" id="pt_name_hi" <?php echo $subOptions === [] ? 'disabled' : ''; ?>>
        </div>
    </div>
    <div class="actions pt-form-actions" style="margin-top:14px;gap:8px;flex-wrap:wrap;">
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
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">هرَم المقاس (1–2)</th>
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
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;" dir="ltr" lang="en"><?php
                            $eck = trim((string) ($row['expected_commercial_kind_key'] ?? ''));
                            $esk = trim((string) ($row['expected_sizing_category_key'] ?? ''));
                            echo ($eck !== '' || $esk !== '')
                                ? htmlspecialchars($eck . ' / ' . $esk, ENT_QUOTES, 'UTF-8')
                                : '—';
                            ?></td>
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
                                'expected_commercial_kind_key' => (string) ($row['expected_commercial_kind_key'] ?? ''),
                                'expected_sizing_category_key' => (string) ($row['expected_sizing_category_key'] ?? ''),
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
/* تنسيق الصفوف مثل color_dictionary / pattern_dictionary: LTR grid + ترتيب عربي (الأول في الجملة يمين الشاشة). */
.pt-form-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    grid-template-areas:
        "active active active slug slug slug slug slug slug sort sort sort"
        "path path path path path path path path path path path path"
        "sk sk sk sk sk sk ck ck ck ck ck ck"
        "en en en en en en ar ar ar ar ar ar"
        "hi hi hi hi hi hi fil fil fil fil fil fil";
    gap: 14px 18px;
    direction: ltr;
}
.pt-form-grid .pt-sort {
    grid-area: sort;
    justify-self: end;
    width: 100%;
}
.pt-form-grid .pt-slug {
    grid-area: slug;
    min-width: 0;
}
.pt-form-grid .pt-active {
    grid-area: active;
    justify-self: start;
    width: 100%;
}
.pt-form-grid .pt-path { grid-area: path; min-width: 0; }
.pt-form-grid .pt-ck { grid-area: ck; min-width: 0; }
.pt-form-grid .pt-sk { grid-area: sk; min-width: 0; }
.pt-form-grid .pt-ar { grid-area: ar; }
.pt-form-grid .pt-en { grid-area: en; }
.pt-form-grid .pt-fil { grid-area: fil; }
.pt-form-grid .pt-hi { grid-area: hi; }
.pt-form-grid label,
.pt-form-grid input,
.pt-form-grid select {
    direction: rtl;
    text-align: right;
}
.pt-form-grid #pt_slug,
.pt-form-grid #pt_expected_commercial_kind_key,
.pt-form-grid #pt_expected_sizing_category_key,
.pt-form-grid #pt_name_en {
    text-align: left;
    direction: ltr;
}
.pt-form-grid #pt_sort,
.pt-form-grid #pt_active,
.pt-form-grid select#pt_expected_commercial_kind_key,
.pt-form-grid select#pt_expected_sizing_category_key {
    margin-inline: 0;
    display: block;
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #cbd5e1;
    border-radius: var(--radius-sm, 10px);
    font-size: 14px;
    line-height: calc(var(--input-min-h, 36px) - 2px);
    min-height: var(--input-min-h, 36px);
    height: var(--input-min-h, 36px);
    max-height: var(--input-min-h, 36px);
    padding-block: 0;
    padding-inline: 12px;
}
.pt-form-grid input#pt_sort::-webkit-outer-spin-button,
.pt-form-grid input#pt_sort::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.pt-form-grid input#pt_sort {
    -moz-appearance: textfield;
    appearance: textfield;
}
.pt-form-grid #pt_active,
.pt-form-grid select#pt_expected_commercial_kind_key,
.pt-form-grid select#pt_expected_sizing_category_key {
    -webkit-appearance: none;
    appearance: none;
    background-color: #fff;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M2.75 4.25L6 7.55l3.25-3.3.65.64L6 8.82 2.1 4.9l.65-.65z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-size: 12px;
    background-position: left 12px center;
    padding-inline-end: 32px;
}
.pt-form-grid input#pt_expected_commercial_kind_key,
.pt-form-grid input#pt_expected_sizing_category_key {
    margin-inline: 0;
    display: block;
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #cbd5e1;
    border-radius: var(--radius-sm, 10px);
    font-size: 14px;
    line-height: calc(var(--input-min-h, 36px) - 2px);
    min-height: var(--input-min-h, 36px);
    height: var(--input-min-h, 36px);
    max-height: var(--input-min-h, 36px);
    padding-block: 0;
    padding-inline: 12px;
}
.pt-form-actions { justify-content: flex-end; }
@media (max-width: 860px) {
    .pt-form-grid {
        grid-template-columns: 1fr;
        grid-template-areas:
            "sort"
            "slug"
            "active"
            "path"
            "ck"
            "sk"
            "ar"
            "en"
            "fil"
            "hi";
    }
    .pt-form-grid .pt-sort,
    .pt-form-grid .pt-active {
        justify-self: start;
        max-width: var(--admin-sort-field-max-w, 220px);
    }
}
</style>

<script>
const ptSubOptions = <?php echo $subOptionsJson; ?>;
var PT_SD_API = '/admin/api/sizing_dictionary/manage.php';
var PT_SIZING_DICT_SELECTS = <?php echo $sizingDictForPtForm ? 'true' : 'false'; ?>;
window.PT_BOOTSTRAP_COMMERCIAL_KINDS = <?php echo $ptCommercialKindsJson; ?>;
window.PT_BOOTSTRAP_SIZING_CATS_BY_KIND = <?php echo $ptSizingCatsByKindJson; ?>;
let ptTranslateTimer = null;
let ptEnTranslateTimer = null;
let isSavingPt = false;

function ptEnsureSelectOption(sel, value, label, dataLabels) {
    if (!sel || !value) return;
    var v = String(value);
    if ([].some.call(sel.options, function (o) { return o.value === v; })) return;
    var o = document.createElement('option');
    o.value = v;
    o.textContent = label || v;
    dataLabels = dataLabels || {};
    if (dataLabels.labelAr != null) {
        o.setAttribute('data-label-ar', String(dataLabels.labelAr));
    }
    if (dataLabels.labelEn != null) {
        o.setAttribute('data-label-en', String(dataLabels.labelEn));
    }
    sel.appendChild(o);
}

function ptFillCommercialKindOptionsFromBootstrap(preferredKind) {
    var sel = document.getElementById('pt_expected_commercial_kind_key');
    if (!sel || sel.tagName !== 'SELECT') {
        return Promise.resolve();
    }
    var prev = preferredKind !== undefined && preferredKind !== null ? String(preferredKind) : String(sel.value || '');
    var kinds = Array.isArray(window.PT_BOOTSTRAP_COMMERCIAL_KINDS) ? window.PT_BOOTSTRAP_COMMERCIAL_KINDS : [];
    sel.innerHTML = '';
    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = '— بدون نطاق هرَم —';
    sel.appendChild(opt0);
    kinds.forEach(function (k) {
        if (!k || !k.kind_key) {
            return;
        }
        var o = document.createElement('option');
        o.value = String(k.kind_key || '').trim();
        o.textContent = (k.label_ar || k.kind_key || '') + ' (' + (k.kind_key || '') + ')';
        o.setAttribute('data-label-ar', String(k.label_ar != null ? k.label_ar : ''));
        o.setAttribute('data-label-en', String(k.label_en != null ? k.label_en : ''));
        sel.appendChild(o);
    });
    if (prev) {
        ptEnsureSelectOption(sel, prev, prev + ' (غير مدرَج في القاموس)', { labelAr: prev, labelEn: prev });
        sel.value = prev;
    } else {
        sel.value = '';
    }
    return Promise.resolve();
}

/** إن كانت البيانات المضمّنة فارغة (نادر)، جرّب الـ API. */
async function ptLoadKindsIntoSelect(preferredKind) {
    var kinds = Array.isArray(window.PT_BOOTSTRAP_COMMERCIAL_KINDS) ? window.PT_BOOTSTRAP_COMMERCIAL_KINDS : [];
    if (kinds.length > 0) {
        return ptFillCommercialKindOptionsFromBootstrap(preferredKind);
    }
    var sel = document.getElementById('pt_expected_commercial_kind_key');
    if (!sel || sel.tagName !== 'SELECT' || typeof postJSON !== 'function') {
        return;
    }
    var prev = preferredKind !== undefined && preferredKind !== null ? String(preferredKind) : String(sel.value || '');
    try {
        var res = await postJSON(PT_SD_API, { action: 'list_kinds' });
        if (!res || !res.success) {
            return ptFillCommercialKindOptionsFromBootstrap(preferredKind);
        }
        window.PT_BOOTSTRAP_COMMERCIAL_KINDS = res.kinds || [];
        return ptFillCommercialKindOptionsFromBootstrap(prev);
    } catch (e) {
        return ptFillCommercialKindOptionsFromBootstrap(preferredKind);
    }
}

function ptSizingCatEmptyHint(show) {
    var el = document.getElementById('pt_sizing_cat_empty_hint');
    if (el) {
        el.style.display = show ? 'block' : 'none';
    }
}

async function ptLoadSizingCategoriesIntoSelect(preferredCat) {
    var kindSel = document.getElementById('pt_expected_commercial_kind_key');
    var catSel = document.getElementById('pt_expected_sizing_category_key');
    if (!kindSel || kindSel.tagName !== 'SELECT' || !catSel || catSel.tagName !== 'SELECT') {
        return;
    }
    var ck = String(kindSel.value || '').trim();
    var prev = preferredCat !== undefined && preferredCat !== null ? String(preferredCat) : String(catSel.value || '');
    catSel.innerHTML = '';
    var opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = ck ? '— فئة القياس —' : '— اختر النوع التجاري أولاً —';
    catSel.appendChild(opt0);
    ptSizingCatEmptyHint(false);
    if (!ck) {
        catSel.value = '';
        return;
    }
    var byKind = window.PT_BOOTSTRAP_SIZING_CATS_BY_KIND && typeof window.PT_BOOTSTRAP_SIZING_CATS_BY_KIND === 'object'
        ? window.PT_BOOTSTRAP_SIZING_CATS_BY_KIND
        : {};
    var fromBoot = byKind[ck];
    var list = Array.isArray(fromBoot) ? fromBoot : [];
    function fillFromRows(rows) {
        rows.forEach(function (c) {
            if (!c || !c.category_key) {
                return;
            }
            var o = document.createElement('option');
            o.value = String(c.category_key || '').trim();
            o.textContent = (c.label_ar || c.category_key || '') + ' (' + (c.category_key || '') + ')';
            o.setAttribute('data-label-ar', String(c.label_ar != null ? c.label_ar : ''));
            o.setAttribute('data-label-en', String(c.label_en != null ? c.label_en : ''));
            catSel.appendChild(o);
        });
        if (prev) {
            ptEnsureSelectOption(catSel, prev, prev + ' (غير مدرَج في القاموس)', { labelAr: prev, labelEn: prev });
            catSel.value = prev;
        } else {
            catSel.value = '';
        }
        ptSizingCatEmptyHint(rows.length === 0);
    }
    if (list.length > 0) {
        fillFromRows(list);
        return;
    }
    if (typeof postJSON !== 'function') {
        fillFromRows([]);
        return;
    }
    try {
        var res = await postJSON(PT_SD_API, { action: 'list_categories', commercial_kind_key: ck });
        if (!res || !res.success) {
            fillFromRows([]);
            return;
        }
        var rows = res.categories || [];
        if (rows.length > 0 && !byKind[ck]) {
            byKind[ck] = rows;
            window.PT_BOOTSTRAP_SIZING_CATS_BY_KIND = byKind;
        }
        fillFromRows(rows);
    } catch (e) {
        fillFromRows([]);
    }
}

function ptInitSizingHierarchySelects() {
    if (!PT_SIZING_DICT_SELECTS) return;
    var kindSel = document.getElementById('pt_expected_commercial_kind_key');
    if (!kindSel || kindSel.tagName !== 'SELECT') return;
    kindSel.addEventListener('change', function () {
        void ptLoadSizingCategoriesIntoSelect('');
    });
    void ptLoadKindsIntoSelect('').then(function () {
        return ptLoadSizingCategoriesIntoSelect('');
    });
}

function resetPtForm() {
    document.getElementById('pt_id').value = '0';
    document.getElementById('pt_catalog_subcategory_id').value = '';
    document.getElementById('pt_slug').value = '';
    var ckEl = document.getElementById('pt_expected_commercial_kind_key');
    var skEl = document.getElementById('pt_expected_sizing_category_key');
    if (ckEl) ckEl.value = '';
    if (skEl) skEl.value = '';
    document.getElementById('pt_sort').value = '';
    document.getElementById('pt_name_ar').value = '';
    document.getElementById('pt_name_en').value = '';
    document.getElementById('pt_name_fil').value = '';
    document.getElementById('pt_name_hi').value = '';
    document.getElementById('pt_active').value = '1';
    if (PT_SIZING_DICT_SELECTS) {
        void ptLoadKindsIntoSelect('').then(function () {
            return ptLoadSizingCategoriesIntoSelect('');
        });
    }
}

function editProductType(p) {
    document.getElementById('pt_id').value = String(p.id != null ? p.id : 0);
    document.getElementById('pt_catalog_subcategory_id').value = String(p.catalog_subcategory_id != null ? p.catalog_subcategory_id : '');
    document.getElementById('pt_slug').value = p.slug || '';
    var pCk = p.expected_commercial_kind_key || '';
    var pSk = p.expected_sizing_category_key || '';
    if (PT_SIZING_DICT_SELECTS) {
        void ptLoadKindsIntoSelect(pCk).then(function () {
            return ptLoadSizingCategoriesIntoSelect(pSk);
        });
    } else {
        var ckEl2 = document.getElementById('pt_expected_commercial_kind_key');
        var skEl2 = document.getElementById('pt_expected_sizing_category_key');
        if (ckEl2) ckEl2.value = pCk;
        if (skEl2) skEl2.value = pSk;
    }
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
            expected_commercial_kind_key: (function () {
                var el = document.getElementById('pt_expected_commercial_kind_key');
                return el ? String(el.value || '').trim() : '';
            }()),
            expected_sizing_category_key: (function () {
                var el = document.getElementById('pt_expected_sizing_category_key');
                return el ? String(el.value || '').trim() : '';
            }()),
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
    ptInitSizingHierarchySelects();
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
