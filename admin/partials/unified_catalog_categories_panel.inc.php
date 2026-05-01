<?php
/**
 * @var array<string,mixed> $orange_uc
 */
$ucCatsFlat = $orange_uc['categories_flat'] ?? [];
$ucSecOpts = $orange_uc['section_select_options'] ?? [];
$ucSecEmpty = !empty($orange_uc['sections_empty_for_categories']);
?>
<div class="card" style="margin-bottom:14px;">
    <h3 style="margin-top:0;">2 — فئات الموحّد (catalog_categories)</h3>
    <input type="hidden" id="uc_cat_id" value="0">
    <div class="uc-form-grid">
        <div style="grid-column:1/-1;">
            <label for="uc_cat_section_id">القسم الداخلي الأم</label>
            <select id="uc_cat_section_id" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>>
                <option value="">— اختر —</option>
                <?php foreach ($ucSecOpts as $opt): ?>
                    <option value="<?php echo (int) $opt['id']; ?>"><?php echo htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($ucSecEmpty): ?>
                <small style="color:#b45309;display:block;margin-top:4px;">أنشئ قسمًا داخليًا أولًا من شاشة <strong>أقسام داخلية</strong>.</small>
            <?php endif; ?>
        </div>
        <div><label for="uc_cat_slug">slug</label><input type="text" id="uc_cat_slug" dir="ltr" maxlength="191" autocomplete="off" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_sort">ترتيب</label><input type="number" id="uc_cat_sort" min="1" step="1" value="" placeholder="تلقائي" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_name_ar">عربي</label><input type="text" id="uc_cat_name_ar" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_name_fil">Filipino</label><input type="text" id="uc_cat_name_fil" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_name_en">English</label><input type="text" id="uc_cat_name_en" dir="ltr" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_name_hi">Hindi</label><input type="text" id="uc_cat_name_hi" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>></div>
        <div><label for="uc_cat_active">نشط</label><select id="uc_cat_active" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>><option value="1">نعم</option><option value="0">لا</option></select></div>
    </div>
    <div class="actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;">
        <button type="button" onclick="saveUcCategory()" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>>حفظ الفئة</button>
        <button type="button" class="btn-secondary" onclick="translateUc('cat')" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>>ترجمة</button>
        <button type="button" class="btn-secondary" onclick="resetUcCategory()" <?php echo $ucSecEmpty ? 'disabled' : ''; ?>>جديد</button>
    </div>
    <?php if ($ucCatsFlat !== []): ?>
    <div style="overflow-x:auto;margin-top:16px;">
        <table class="uc-table"><thead><tr>
            <th>#</th><th>مسار</th><th>slug</th><th>عربي</th><th>ترتيب</th><th>نشط</th><th>إجراء</th>
        </tr></thead><tbody>
        <?php foreach ($ucCatsFlat as $row): ?>
            <?php if (! is_array($row)) { continue; } ?>
            <tr>
                <td><?php echo (int) ($row['id'] ?? 0); ?></td>
                <td><?php echo htmlspecialchars(trim((string) ($row['dept_label'] ?? '')) . ' ← ' . trim((string) ($row['sec_label'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                <td dir="ltr"><?php echo htmlspecialchars((string) ($row['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($row['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                <td><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                <td><button type="button" class="btn-secondary uc-edit-cat" data-json="<?php echo htmlspecialchars(json_encode([
                    'id' => (int) ($row['id'] ?? 0),
                    'catalog_section_id' => (int) ($row['catalog_section_id'] ?? 0),
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
