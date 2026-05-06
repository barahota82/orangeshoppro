<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$hasTable = orange_table_exists($pdo, 'catalog_attributes');
$attrs = [];
$nextSort = 1;

if ($hasTable) {
    try {
        $attrs = $pdo->query(
            'SELECT id, attribute_key, label_ar, label_en, label_fil, label_hi,
                    input_kind, is_filterable, sort_order, is_active, created_at
             FROM catalog_attributes
             ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $nextSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM catalog_attributes')->fetchColumn();
        if ($nextSort <= 0) {
            $nextSort = 1;
        }
    } catch (Throwable $e) {
        $attrs = [];
        $nextSort = 1;
    }
}
?>
<div class="page-title">
    <h1>سمات الكتالوج</h1>
    <p class="page-subtitle" style="margin:0.35rem 0 0;font-size:0.95rem;color:#555;">تعريف المفاتيح الإنجليزية الثابتة وعناوين العرض؛ <strong>قيم كل منتج</strong> تُحفظ من صفحة <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=products'), ENT_QUOTES, 'UTF-8'); ?>">المنتجات</a> ضمن «صفات الكتالوج». تعيين <code>is_filterable</code> يفعّل المنتج في معاملات الواجهة <code>attr_{key}</code> وواجهة <code>api/products/get-attribute-facets.php</code>. جدول <code>catalog_attribute_options</code> (يُنشأ مع المخطط) لقيم محددة مسبقاً اختيارية.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>catalog_attributes</code> غير موجود بعد تهيئة المخطط.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>إضافة / تعديل سمة</h3>
    <input type="hidden" id="ca_attr_id" value="0">
    <div class="ca-attr-form-grid">
        <div class="ca-row ca-row--r1">
            <div class="ca-sort-wrap admin-sort-field-wrap">
                <label>ترتيب العرض</label>
                <input type="number" id="ca_sort" class="admin-sort-field<?php echo !$hasTable ? ' admin-sort-field--muted' : ''; ?>" min="0" step="1" value=""
                    placeholder="<?php echo htmlspecialchars('مقترح: ' . (string) $nextSort, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo !$hasTable ? 'disabled' : ''; ?>>
                <small class="ca-field-hint">عند الإنشاء: اتركه فارغًا أو 0 ليُطبَّق الترتيب التلقائي في الخادم.</small>
            </div>
            <div class="ca-key-wrap">
                <label>المفتاح الإنجليزي (attribute_key)</label>
                <input type="text" id="ca_key" dir="ltr" lang="en" maxlength="80" placeholder="مثال material أو care_notes" autocomplete="off" <?php echo !$hasTable ? 'disabled' : ''; ?>>
                <small class="ca-field-hint">حرف صغير أولاً، ثم صغيرة/أرقام/<wbr>_ أو -، حتى 80 محرفًا.</small>
            </div>
            <div class="ca-active-wrap admin-sort-field-wrap">
                <label>نشطة</label>
                <select id="ca_active" class="admin-sort-field" <?php echo !$hasTable ? 'disabled' : ''; ?>>
                    <option value="1">نعم</option>
                    <option value="0">لا</option>
                </select>
            </div>
        </div>
        <div class="ca-row ca-row--r2">
            <div class="ca-input-kind-wrap admin-sort-field-wrap">
                <label>نوع الحقل</label>
                <select id="ca_input_kind" class="admin-sort-field" <?php echo !$hasTable ? 'disabled' : ''; ?>>
                    <?php foreach (['text_short' => 'نص قصير', 'text_long' => 'نص طويل', 'enum_single' => 'قائمة واحدة', 'multi' => 'متعدّد القيم', 'boolean' => 'نعم/لا'] as $k => $lbl): ?>
                        <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ca-filter-wrap admin-sort-field-wrap">
                <label>قابلة للتصفية</label>
                <select id="ca_filterable" class="admin-sort-field" <?php echo !$hasTable ? 'disabled' : ''; ?>>
                    <option value="0">لا</option>
                    <option value="1">نعم</option>
                </select>
            </div>
        </div>
        <div class="ca-row ca-row--r3">
            <div class="admin-sort-field-wrap">
                <label for="ca_label_ar">عربي (عنوان العرض)</label>
                <input type="text" id="ca_label_ar" class="admin-sort-field" <?php echo !$hasTable ? 'disabled' : ''; ?>>
            </div>
            <div class="admin-sort-field-wrap">
                <label for="ca_label_en">English</label>
                <input type="text" id="ca_label_en" class="admin-sort-field" dir="ltr" lang="en" <?php echo !$hasTable ? 'disabled' : ''; ?>>
            </div>
        </div>
        <div class="ca-row ca-row--r4">
            <div class="admin-sort-field-wrap">
                <label for="ca_label_fil">Filipino</label>
                <input type="text" id="ca_label_fil" class="admin-sort-field" <?php echo !$hasTable ? 'disabled' : ''; ?>>
            </div>
            <div class="admin-sort-field-wrap">
                <label for="ca_label_hi">Hindi</label>
                <input type="text" id="ca_label_hi" class="admin-sort-field" <?php echo !$hasTable ? 'disabled' : ''; ?>>
            </div>
        </div>
    </div>
    <div class="actions ca-attr-form-actions" style="margin-top:14px;gap:8px;flex-wrap:wrap;">
        <button type="button" onclick="saveCatalogAttribute()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ السمة</button>
        <button type="button" class="btn-secondary" onclick="translateCatalogLabels({ forceFromArabic: true })" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة تلقائية</button>
        <button type="button" class="btn-secondary" onclick="resetCatalogAttrForm()" <?php echo !$hasTable ? 'disabled' : ''; ?>>جديد</button>
    </div>
</div>

<?php if ($hasTable): ?>
<div class="card">
    <h3 style="margin-top:0;">القائمة</h3>
    <?php if ($attrs === []): ?>
        <p style="margin:0;color:#555;">لا توجد سجلات بعد.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="border-collapse:collapse;width:100%;font-size:0.93rem;">
                <thead>
                <tr>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">#</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">المفتاح</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">النوع</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">عربي</th>
                    <th style="padding:10px;text-align:right;border-bottom:1px solid #e8e9ec;">EN</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">فلتر</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">ترتيب</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">نشط</th>
                    <th style="padding:10px;text-align:center;border-bottom:1px solid #e8e9ec;">إجراء</th>
                </tr>
                </thead>
                <tbody id="orange-ca-list-tbody">
                <?php foreach ($attrs as $row): ?>
                    <tr style="vertical-align:top;">
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo (int) ($row['id'] ?? 0); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;" dir="ltr" lang="en"><?php echo htmlspecialchars((string) ($row['attribute_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo htmlspecialchars((string) ($row['input_kind'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;"><?php echo htmlspecialchars((string) ($row['label_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;" dir="ltr" lang="en"><?php echo htmlspecialchars((string) ($row['label_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;"><?php echo ((int) ($row['is_filterable'] ?? 0) === 1) ? '√' : '—'; ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;"><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;"><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                        <td style="padding:10px;border-bottom:1px solid #f0f1f5;text-align:center;">
                            <button type="button" class="btn-secondary ca-edit-btn" data-attribute-json="<?php echo htmlspecialchars(json_encode([
                                'id' => (int) ($row['id'] ?? 0),
                                'attribute_key' => (string) ($row['attribute_key'] ?? ''),
                                'label_ar' => (string) ($row['label_ar'] ?? ''),
                                'label_en' => (string) ($row['label_en'] ?? ''),
                                'label_fil' => (string) ($row['label_fil'] ?? ''),
                                'label_hi' => (string) ($row['label_hi'] ?? ''),
                                'input_kind' => (string) ($row['input_kind'] ?? 'text_short'),
                                'is_filterable' => (int) ($row['is_filterable'] ?? 0),
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
<?php endif; ?>

<style>
    /* تنسيق مطابق لفكرة بطاقة «إضافة / تعديل عائلة» في size_families.php: صفوف شبكة + direction:rtl ليظهر الحقل المذكور أولاً على اليمين */
    /* لا تضف form-grid هنا: admin.css يفرض عمودين فيضع صفّين (r1|r2) و(r3|r4) جنباً بدل أربعة أسطر */
    .ca-attr-form-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 14px 18px;
        direction: ltr;
        align-items: start;
    }
    .ca-attr-form-grid .ca-row {
        display: grid;
        gap: 14px 18px;
        align-items: start;
        min-width: 0;
    }
    .ca-attr-form-grid .ca-row--r1 {
        grid-template-columns: minmax(0, 200px) minmax(0, 1fr) minmax(0, 200px);
        direction: rtl;
    }
    .ca-attr-form-grid .ca-row--r2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        direction: rtl;
    }
    .ca-attr-form-grid .ca-row--r3,
    .ca-attr-form-grid .ca-row--r4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        direction: rtl;
    }
    .ca-attr-form-grid .ca-row > div {
        min-width: 0;
    }
    .ca-attr-form-grid .admin-sort-field-wrap {
        max-width: none;
        width: 100%;
    }
    .ca-attr-form-grid label,
    .ca-attr-form-grid small.ca-field-hint {
        direction: rtl;
        text-align: right;
    }
    .ca-attr-form-grid .ca-field-hint {
        display: block;
        color: #666;
        margin-top: 4px;
        font-size: 0.85rem;
        line-height: 1.4;
    }
    .ca-attr-form-grid #ca_key {
        margin-inline: 0;
        display: block;
        width: 100%;
        max-width: none;
        box-sizing: border-box;
        text-align: left;
        direction: ltr;
    }
    .ca-attr-form-grid #ca_label_en {
        text-align: left;
    }
    .ca-attr-form-grid input.admin-sort-field,
    .ca-attr-form-grid select.admin-sort-field {
        margin-inline: 0;
        display: block;
        width: 100%;
        max-width: none;
        box-sizing: border-box;
    }
    .ca-attr-form-grid .ca-active-wrap select#ca_active,
    .ca-attr-form-grid .ca-input-kind-wrap select#ca_input_kind,
    .ca-attr-form-grid .ca-filter-wrap select#ca_filterable {
        border: 1px solid #cbd5e1;
        border-radius: var(--radius-sm, 10px);
        font-size: 14px;
        line-height: calc(var(--input-min-h, 36px) - 2px);
        min-height: var(--input-min-h, 36px);
        height: var(--input-min-h, 36px);
        max-height: var(--input-min-h, 36px);
        padding-block: 0;
        padding-inline: 12px;
        -webkit-appearance: none;
        appearance: none;
        background-color: #fff;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M2.75 4.25L6 7.55l3.25-3.3.65.64L6 8.82 2.1 4.9l.65-.65z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-size: 12px;
        background-position: left 12px center;
        padding-inline-end: 32px;
    }
    .ca-attr-form-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        justify-content: flex-end;
        flex-direction: row-reverse;
    }
    .ca-attr-form-actions > button {
        min-height: var(--input-min-h, 40px);
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    @media (max-width: 720px) {
        .ca-attr-form-grid .ca-row--r1,
        .ca-attr-form-grid .ca-row--r2,
        .ca-attr-form-grid .ca-row--r3,
        .ca-attr-form-grid .ca-row--r4 {
            grid-template-columns: 1fr;
        }
        .ca-attr-form-grid .ca-row--r1 .ca-sort-wrap,
        .ca-attr-form-grid .ca-row--r1 .ca-active-wrap {
            justify-self: stretch;
            max-width: none;
        }
    }
</style>

<script>
let caTranslateTimer = null;
let caEnTranslateTimer = null;
let isSavingCatalogAttr = false;

function resetCatalogAttrForm() {
    document.getElementById('ca_attr_id').value = '0';
    document.getElementById('ca_key').value = '';
    document.getElementById('ca_input_kind').value = 'text_short';
    document.getElementById('ca_sort').value = '';
    document.getElementById('ca_label_ar').value = '';
    document.getElementById('ca_label_en').value = '';
    document.getElementById('ca_label_fil').value = '';
    document.getElementById('ca_label_hi').value = '';
    document.getElementById('ca_filterable').value = '0';
    document.getElementById('ca_active').value = '1';
}

function editCatalogAttribute(a) {
    document.getElementById('ca_attr_id').value = String(a.id != null ? a.id : 0);
    document.getElementById('ca_key').value = a.attribute_key || '';
    document.getElementById('ca_input_kind').value = a.input_kind || 'text_short';
    document.getElementById('ca_sort').value = String(a.sort_order != null ? a.sort_order : 0);
    document.getElementById('ca_label_ar').value = a.label_ar || '';
    document.getElementById('ca_label_en').value = a.label_en || '';
    document.getElementById('ca_label_fil').value = a.label_fil || '';
    document.getElementById('ca_label_hi').value = a.label_hi || '';
    document.getElementById('ca_filterable').value = String((a.is_filterable === 1 || a.is_filterable === true) ? 1 : 0);
    document.getElementById('ca_active').value = String((a.is_active === 0 || a.is_active === false) ? 0 : 1);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function translateCatalogLabels(opts) {
    opts = opts || {};
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const payload = {
            name_ar: document.getElementById('ca_label_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('ca_label_en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('ca_label_en').value = t.name_en;
        if (t.name_fil) document.getElementById('ca_label_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('ca_label_hi').value = t.name_hi;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
    }
}

function scheduleCatalogAttrAutoTranslate() {
    const ar = document.getElementById('ca_label_ar').value.trim();
    if (!ar) {
        document.getElementById('ca_label_en').value = '';
        document.getElementById('ca_label_fil').value = '';
        document.getElementById('ca_label_hi').value = '';
        return;
    }
    if (caTranslateTimer) clearTimeout(caTranslateTimer);
    caTranslateTimer = setTimeout(function () {
        translateCatalogLabels({ silent: true, forceFromArabic: true });
    }, 700);
}

function scheduleCatalogAttrTranslateFromEnglish() {
    if (caEnTranslateTimer) clearTimeout(caEnTranslateTimer);
    caEnTranslateTimer = setTimeout(function () {
        const en = document.getElementById('ca_label_en').value.trim();
        if (!en) return;
        translateCatalogLabels({ silent: true, forceFromArabic: false });
    }, 650);
}

async function saveCatalogAttribute() {
    if (isSavingCatalogAttr) return;
    isSavingCatalogAttr = true;
    try {
        const recordId = parseInt(document.getElementById('ca_attr_id').value || '0', 10) || 0;
        const sortRaw = document.getElementById('ca_sort').value.trim();
        const sortVal = sortRaw === '' ? 0 : (parseInt(sortRaw, 10) || 0);
        const payload = {
            attribute_key: document.getElementById('ca_key').value.trim(),
            label_ar: document.getElementById('ca_label_ar').value.trim(),
            label_en: document.getElementById('ca_label_en').value.trim(),
            label_fil: document.getElementById('ca_label_fil').value.trim(),
            label_hi: document.getElementById('ca_label_hi').value.trim(),
            input_kind: document.getElementById('ca_input_kind').value,
            is_filterable: parseInt(document.getElementById('ca_filterable').value || '0', 10) ? 1 : 0,
            sort_order: isNaN(sortVal) ? 0 : sortVal,
            is_active: parseInt(document.getElementById('ca_active').value || '1', 10) ? 1 : 0
        };
        if (recordId > 0) payload.id = recordId;
        const res = await postJSON('/admin/api/catalog_attributes/save.php', payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        isSavingCatalogAttr = false;
    }
}

(function () {
    var arEl = document.getElementById('ca_label_ar');
    var enEl = document.getElementById('ca_label_en');
    if (arEl) {
        arEl.addEventListener('input', scheduleCatalogAttrAutoTranslate);
        arEl.addEventListener('change', function () {
            if (arEl.value.trim()) {
                translateCatalogLabels({ silent: true, forceFromArabic: true });
            }
        });
    }
    if (enEl) {
        enEl.addEventListener('input', scheduleCatalogAttrTranslateFromEnglish);
    }
    var tbody = document.getElementById('orange-ca-list-tbody');
    if (tbody) {
        tbody.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.ca-edit-btn');
            if (!btn || !btn.dataset.attributeJson) return;
            try {
                editCatalogAttribute(JSON.parse(btn.dataset.attributeJson));
            } catch (err) {
                alert('تعذر قراءة بيانات السمة للتعديل');
            }
        });
    }
})();
</script>
