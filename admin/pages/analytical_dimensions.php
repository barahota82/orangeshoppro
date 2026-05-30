<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/analytical_dimensions.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';

$pdo = orange_admin_page_pdo();
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$ready = orange_analytical_dimensions_ready($pdo);
$dims = $ready ? orange_analytical_dimensions_list_with_value_counts($pdo, $ctxCountryId, false) : [];

$selectedDimId = isset($_GET['dim']) ? (int) $_GET['dim'] : 0;
if ($selectedDimId <= 0 && $dims !== []) {
    $selectedDimId = (int) ($dims[0]['id'] ?? 0);
}

$values = ($ready && $selectedDimId > 0)
    ? orange_analytical_dimension_values_list($pdo, $selectedDimId, false)
    : [];

$selectedDim = null;
foreach ($dims as $d) {
    if ((int) ($d['id'] ?? 0) === $selectedDimId) {
        $selectedDim = $d;
        break;
    }
}

$apiBase = storefront_public_path('/admin/api/analytical-dimensions');
$dimsJson = json_encode($dims, JSON_UNESCAPED_UNICODE);
if ($dimsJson === false) {
    $dimsJson = '[]';
}
$valuesJson = json_encode($values, JSON_UNESCAPED_UNICODE);
if ($valuesJson === false) {
    $valuesJson = '[]';
}

?>
<div class="admin-fy-shell" dir="rtl" id="ad_app">
    <h1 class="admin-fy-shell__title">الأبعاد التحليلية</h1>

    <?php if (! $ready): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">جداول الأبعاد غير جاهزة — حدّث المخطط (ACC-10 مرحلة 0).</p>
        </div>
    <?php else: ?>

    <p class="card-hint">v1: <strong>فرع</strong> + <strong>قناة</strong> فقط (بذور تلقائية). أضف قيم كل بُعد ثم اخترها اختيارياً على أسطر السندات.</p>

    <div style="display:grid;gap:16px;grid-template-columns:minmax(220px,280px) 1fr;">
        <div class="card">
            <h3 class="card-title">الأبعاد</h3>
            <ul style="list-style:none;margin:0;padding:0;">
                <?php foreach ($dims as $d): ?>
                    <?php $did = (int) ($d['id'] ?? 0); ?>
                    <li style="margin-bottom:8px;">
                        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=analytical_dimensions&dim=' . $did), ENT_QUOTES, 'UTF-8'); ?>"
                           style="display:block;padding:8px 10px;border-radius:6px;text-decoration:none;<?php echo $did === $selectedDimId ? 'background:#eff6ff;font-weight:600;' : 'background:#f8fafc;'; ?>">
                            <?php echo htmlspecialchars(trim((string) ($d['label_ar'] ?? '')) !== '' ? (string) $d['label_ar'] : (string) ($d['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            <span class="muted" dir="ltr">(<?php echo (int) ($d['value_count'] ?? 0); ?>)</span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card">
            <?php if ($selectedDim !== null): ?>
                <h3 class="card-title">تعديل البُعد: <?php echo htmlspecialchars((string) ($selectedDim['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                <div class="admin-fy-form-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:16px;">
                    <div>
                        <label for="ad_dim_label_ar">الاسم العربي</label>
                        <input type="text" id="ad_dim_label_ar" value="<?php echo htmlspecialchars((string) ($selectedDim['label_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label for="ad_dim_label_en">English</label>
                        <input type="text" id="ad_dim_label_en" value="<?php echo htmlspecialchars((string) ($selectedDim['label_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label for="ad_dim_sort">الترتيب</label>
                        <input type="number" id="ad_dim_sort" value="<?php echo (int) ($selectedDim['sort_order'] ?? 0); ?>" dir="ltr">
                    </div>
                    <div>
                        <label for="ad_dim_active">نشط</label>
                        <select id="ad_dim_active">
                            <option value="1"<?php echo (int) ($selectedDim['is_active'] ?? 1) === 1 ? ' selected' : ''; ?>>نعم</option>
                            <option value="0"<?php echo (int) ($selectedDim['is_active'] ?? 1) === 0 ? ' selected' : ''; ?>>لا</option>
                        </select>
                    </div>
                </div>
                <p><button type="button" id="ad_save_dim_btn">حفظ البُعد</button></p>
            <?php endif; ?>

            <h4 style="margin-top:20px;">قيم البُعد</h4>
            <input type="hidden" id="ad_value_id" value="0">
            <div class="admin-fy-form-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));margin-bottom:12px;">
                <div>
                    <label for="ad_val_code">كود (latin)</label>
                    <input type="text" id="ad_val_code" dir="ltr" placeholder="main">
                </div>
                <div>
                    <label for="ad_val_label_ar">الاسم العربي</label>
                    <input type="text" id="ad_val_label_ar">
                </div>
                <div>
                    <label for="ad_val_label_en">English</label>
                    <input type="text" id="ad_val_label_en">
                </div>
                <div>
                    <label for="ad_val_sort">الترتيب</label>
                    <input type="number" id="ad_val_sort" value="0" dir="ltr">
                </div>
                <div>
                    <label for="ad_val_active">نشط</label>
                    <select id="ad_val_active">
                        <option value="1">نعم</option>
                        <option value="0">لا</option>
                    </select>
                </div>
            </div>
            <p class="actions">
                <button type="button" id="ad_save_val_btn">حفظ القيمة</button>
                <button type="button" class="btn-secondary" id="ad_new_val_btn">قيمة جديدة</button>
            </p>

            <div class="table-wrap" style="margin-top:16px;">
                <table class="admin-fy-table">
                    <thead>
                        <tr>
                            <th>كود</th>
                            <th>عربي</th>
                            <th>English</th>
                            <th>ترتيب</th>
                            <th>نشط</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ad_values_body">
                        <?php if ($values === []): ?>
                            <tr><td colspan="6" class="muted">لا قيم — أضف فرعاً أو قناة.</td></tr>
                        <?php else: ?>
                            <?php foreach ($values as $v): ?>
                                <tr>
                                    <td dir="ltr"><?php echo htmlspecialchars((string) ($v['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($v['label_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($v['label_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr"><?php echo (int) ($v['sort_order'] ?? 0); ?></td>
                                    <td><?php echo (int) ($v['is_active'] ?? 0) === 1 ? 'نعم' : 'لا'; ?></td>
                                    <td>
                                        <button type="button" class="btn-secondary ad-edit-val" data-json="<?php echo htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">تعديل</button>
                                        <button type="button" class="btn-danger ad-del-val" data-id="<?php echo (int) ($v['id'] ?? 0); ?>">حذف</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <p id="ad_msg" class="card-hint" style="margin-top:12px;color:#166534;display:none;"></p>
    <p id="ad_err" class="card-hint" style="margin-top:12px;color:#b91c1c;display:none;"></p>

    <?php endif; ?>
</div>
<script>
(function () {
    var API = <?php echo json_encode(['save' => $apiBase . '/save.php'], JSON_UNESCAPED_UNICODE); ?>;
    var dimId = <?php echo (int) $selectedDimId; ?>;

    function el(id) { return document.getElementById(id); }
    function showErr(m) { el('ad_err').textContent = m || ''; el('ad_err').style.display = m ? 'block' : 'none'; if (m) el('ad_msg').style.display = 'none'; }
    function showOk(m) { el('ad_msg').textContent = m || ''; el('ad_msg').style.display = m ? 'block' : 'none'; if (m) el('ad_err').style.display = 'none'; }

    async function postJson(body) {
        var res = await fetch(API.save, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body || {}) });
        return res.json();
    }

    el('ad_save_dim_btn') && el('ad_save_dim_btn').addEventListener('click', async function () {
        showErr('');
        var data = await postJson({
            action: 'save_dimension',
            dimension_id: dimId,
            label_ar: el('ad_dim_label_ar').value.trim(),
            label_en: el('ad_dim_label_en').value.trim(),
            sort_order: parseInt(el('ad_dim_sort').value, 10) || 0,
            is_active: parseInt(el('ad_dim_active').value, 10) || 0
        });
        if (!data.success) { showErr(data.message); return; }
        showOk(data.message);
    });

    function resetValueForm() {
        el('ad_value_id').value = '0';
        el('ad_val_code').value = '';
        el('ad_val_label_ar').value = '';
        el('ad_val_label_en').value = '';
        el('ad_val_sort').value = '0';
        el('ad_val_active').value = '1';
    }

    el('ad_new_val_btn') && el('ad_new_val_btn').addEventListener('click', resetValueForm);

    el('ad_save_val_btn') && el('ad_save_val_btn').addEventListener('click', async function () {
        showErr('');
        var data = await postJson({
            action: 'save_value',
            id: parseInt(el('ad_value_id').value, 10) || 0,
            dimension_id: dimId,
            code: el('ad_val_code').value.trim(),
            label_ar: el('ad_val_label_ar').value.trim(),
            label_en: el('ad_val_label_en').value.trim(),
            sort_order: parseInt(el('ad_val_sort').value, 10) || 0,
            is_active: parseInt(el('ad_val_active').value, 10) || 0
        });
        if (!data.success) { showErr(data.message); return; }
        showOk(data.message);
        location.reload();
    });

    document.querySelectorAll('.ad-edit-val').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var v = JSON.parse(btn.getAttribute('data-json') || '{}');
            el('ad_value_id').value = String(v.id || 0);
            el('ad_val_code').value = v.code || '';
            el('ad_val_label_ar').value = v.label_ar || '';
            el('ad_val_label_en').value = v.label_en || '';
            el('ad_val_sort').value = String(v.sort_order || 0);
            el('ad_val_active').value = String(parseInt(v.is_active, 10) === 1 ? 1 : 0);
        });
    });

    document.querySelectorAll('.ad-del-val').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            if (!confirm('حذف هذه القيمة؟')) return;
            var data = await postJson({ action: 'delete_value', id: parseInt(btn.getAttribute('data-id'), 10) || 0 });
            if (!data.success) { showErr(data.message); return; }
            location.reload();
        });
    });
})();
</script>
