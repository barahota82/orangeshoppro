<?php
/**
 * @var array<string,mixed> $orange_uc
 */
$ucSubFlat = $orange_uc['subcats_flat'] ?? [];
$ucCatOpts = $orange_uc['category_select_options'] ?? [];
$ucCatEmpty = !empty($orange_uc['categories_empty_for_subcats']);
?>
<div class="card" style="margin-bottom:14px;">
    <h3 style="margin-top:0;">3 — تصنيفات فرعية (catalog_subcategories)</h3>
    <input type="hidden" id="uc_sub_id" value="0">
    <div class="form-grid uc-sub-form-grid">
        <div class="uc-sub-sort admin-sort-field-wrap">
            <label for="uc_sub_sort">الترتيب (تلقائي)</label>
            <small style="display:block;color:#64748b;font-size:12px;margin-top:2px;line-height:1.4;">بعد الحفظ وتجديد الصفحة يُفرَّغ اختيار الفئة والترتيب؛ اختر الفئة ليظهر الترتيب التالي.</small>
            <input type="number" id="uc_sub_sort" class="admin-sort-field<?php echo $ucCatEmpty ? ' admin-sort-field--muted' : ''; ?>" min="1" step="1" value="" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sub-active admin-sort-field-wrap">
            <label for="uc_sub_active">نشط</label>
            <select id="uc_sub_active" class="admin-sort-field" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div class="uc-sub-dept">
            <label for="uc_sub_category_id">الفئة الموحّدة الأم</label>
            <select id="uc_sub_category_id" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>
                <option value="">— اختر —</option>
                <?php foreach ($ucCatOpts as $opt): ?>
                    <option value="<?php echo (int) $opt['id']; ?>"><?php echo htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($ucCatEmpty): ?>
                <small style="color:#b45309;display:block;margin-top:4px;">أنشئ صفًا ضمن «فئات الموحّد» في البلوك أعلاه أولًا.</small>
            <?php endif; ?>
        </div>
        <div class="uc-sub-slug">
            <label for="uc_sub_slug">slug <span style="font-weight:normal;color:#64748b;">(تلقائي من English — يمكن التعديل)</span></label>
            <input type="text" id="uc_sub_slug" dir="ltr" maxlength="191" autocomplete="off" placeholder="يُملأ تلقائياً" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sub-ar">
            <label for="uc_sub_name_ar">الاسم العربي</label>
            <input type="text" id="uc_sub_name_ar" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sub-en">
            <label for="uc_sub_name_en">English</label>
            <input type="text" id="uc_sub_name_en" dir="ltr" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sub-fil">
            <label for="uc_sub_name_fil">Filipino</label>
            <input type="text" id="uc_sub_name_fil" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sub-hi">
            <label for="uc_sub_name_hi">Hindi</label>
            <input type="text" id="uc_sub_name_hi" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>
        </div>
    </div>
    <div class="actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;">
        <button type="button" onclick="saveUcSubcategory()" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>حفظ التصنيف الفرعي</button>
        <button type="button" class="btn-secondary" onclick="translateUc('sub')" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>ترجمة</button>
        <button type="button" class="btn-secondary" onclick="resetUcSubcategory()" <?php echo $ucCatEmpty ? 'disabled' : ''; ?>>جديد</button>
    </div>
    <?php if ($ucSubFlat !== []): ?>
    <div style="overflow-x:auto;margin-top:16px;">
        <table class="uc-table"><thead><tr>
            <th>#</th><th>مسار</th><th>slug</th><th>عربي</th><th>ترتيب</th><th>نشط</th><th>إجراء</th>
        </tr></thead><tbody>
        <?php foreach ($ucSubFlat as $row): ?>
            <?php if (! is_array($row)) { continue; } ?>
            <tr>
                <td><?php echo (int) ($row['id'] ?? 0); ?></td>
                <td><?php echo htmlspecialchars(trim((string) ($row['dept_label'] ?? '')) . ' ← ' . trim((string) ($row['sec_label'] ?? '')) . ' ← ' . trim((string) ($row['cat_label'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                <td dir="ltr"><?php echo htmlspecialchars((string) ($row['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($row['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                <td><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                <td><button type="button" class="btn-secondary uc-edit-sub" data-json="<?php echo htmlspecialchars(json_encode([
                    'id' => (int) ($row['id'] ?? 0),
                    'catalog_category_id' => (int) ($row['catalog_category_id'] ?? 0),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'name_ar' => (string) ($row['name_ar'] ?? ''),
                    'name_en' => (string) ($row['name_en'] ?? ''),
                    'name_fil' => (string) ($row['name_fil'] ?? ''),
                    'name_hi' => (string) ($row['name_hi'] ?? ''),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'is_active' => (int) ($row['is_active'] ?? 1),
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <?php endif; ?>
</div>
