<?php
/**
 * يتطلب في النطاق: $orange_uc من orange_admin_uc_branch_bootstrap.
 * @var array<string,mixed> $orange_uc
 */
$ucDepartments = $orange_uc['departments'] ?? [];
$ucSectionsFlat = $orange_uc['sections_flat'] ?? [];
$ucSecOpts = $orange_uc['section_select_options'] ?? [];
$ucDepsEmpty = !empty($orange_uc['deps_empty_for_sections']);
?>
<div class="card" id="uc_branch_card_sec" style="margin-bottom:14px;">
    <h3 style="margin-top:0;">1 — أقسام داخلية (catalog_sections)</h3>
    <input type="hidden" id="uc_sec_id" value="0">
    <div class="form-grid uc-sec-form-grid">
        <div class="uc-sec-sort admin-sort-field-wrap">
            <label for="uc_sec_sort">الترتيب (تلقائي)</label>
            <input type="number" id="uc_sec_sort" class="admin-sort-field admin-sort-field--muted" min="1" step="1" value="" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sec-active admin-sort-field-wrap">
            <label for="uc_sec_active">نشط</label>
            <select id="uc_sec_active" class="admin-sort-field" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>
                <option value="1">نعم</option>
                <option value="0">لا</option>
            </select>
        </div>
        <div class="uc-sec-dept">
            <label for="uc_sec_department_id">القسم (department)</label>
            <select id="uc_sec_department_id" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>
                <?php if (! $ucDepsEmpty): ?>
                    <option value="">— اختر —</option>
                <?php endif; ?>
                <?php foreach ($ucDepartments as $d): ?>
                    <?php if (! is_array($d)) { continue; } ?>
                    <option value="<?php echo (int) ($d['id'] ?? 0); ?>"><?php echo htmlspecialchars((string) (($d['name_ar'] ?: $d['name_en']) ?: $d['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($ucDepsEmpty): ?>
                <small style="color:#b45309;display:block;margin-top:4px;">لا توجد أقسام نشطة — أضف قسمًا من لوحة <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=departments'), ENT_QUOTES, 'UTF-8'); ?>">الأقسام الرئيسية</a>.</small>
            <?php endif; ?>
        </div>
        <div class="uc-sec-slug">
            <label for="uc_sec_slug">slug</label>
            <input type="text" id="uc_sec_slug" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" maxlength="191" autocomplete="off" readonly <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sec-ar">
            <label for="uc_sec_name_ar">الاسم العربي</label>
            <input type="text" id="uc_sec_name_ar" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sec-en">
            <label for="uc_sec_name_en">English</label>
            <input type="text" id="uc_sec_name_en" dir="ltr" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sec-fil">
            <label for="uc_sec_name_fil">Filipino</label>
            <input type="text" id="uc_sec_name_fil" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>
        </div>
        <div class="uc-sec-hi">
            <label for="uc_sec_name_hi">Hindi</label>
            <input type="text" id="uc_sec_name_hi" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>
        </div>
    </div>
    <div class="actions" style="margin-top:12px;gap:8px;flex-wrap:wrap;">
        <button type="button" onclick="saveUcSection()" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>حفظ القسم الداخلي</button>
        <button type="button" class="btn-secondary" onclick="translateUc('sec')" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>ترجمة</button>
        <button type="button" class="btn-secondary" onclick="resetUcSection()" <?php echo $ucDepsEmpty ? 'disabled' : ''; ?>>جديد</button>
    </div>
    <?php if ($ucSectionsFlat !== []): ?>
    <div style="overflow-x:auto;margin-top:16px;">
        <table class="uc-table"><thead><tr>
            <th>#</th><th>مسار</th><th>slug</th><th>عربي</th><th>ترتيب</th><th>نشط</th><th>إجراء</th>
        </tr></thead><tbody>
        <?php foreach ($ucSectionsFlat as $row): ?>
            <?php if (! is_array($row)) { continue; } ?>
            <tr>
                <td><?php echo (int) ($row['id'] ?? 0); ?></td>
                <td><?php echo htmlspecialchars(trim((string) ($row['dept_label'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                <td dir="ltr"><?php echo htmlspecialchars((string) ($row['slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($row['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                <td><?php echo ((int) ($row['is_active'] ?? 0) === 1) ? '√' : '—'; ?></td>
                <td><button type="button" class="btn-secondary uc-edit-sec" data-json="<?php echo htmlspecialchars(json_encode([
                    'id' => (int) ($row['id'] ?? 0),
                    'department_id' => (int) ($row['department_id'] ?? 0),
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
