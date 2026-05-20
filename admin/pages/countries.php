<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$countries = orange_countries_admin_list($pdo);
$hasTable = orange_table_exists($pdo, 'countries');

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
foreach ($countries as $c) {
    if ($editId > 0 && (int) ($c['id'] ?? 0) === $editId) {
        $editRow = $c;
        break;
    }
}
?>
<div class="page-title page-title--stacked">
    <h1>الدول</h1>
    <p class="page-subtitle">أسواق المتجر: عملة، تفعيل للواجهة، وربط القنوات ومناطق التوصيل. حالياً يُفضّل إبقاء <strong>الكويت</strong> فقط نشطة حتى اكتمال التشغيل المحلي، ثم تفعيل مصر والإمارات والسعودية لاحقاً.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>countries</code> غير جاهز — حدّث المخطط من السيرفر.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3><?php echo $editRow ? 'تعديل دولة' : 'إضافة دولة'; ?></h3>
    <input type="hidden" id="ctry_id" value="<?php echo $editRow ? (int) $editRow['id'] : '0'; ?>">
    <div class="form-grid">
        <div>
            <label for="ctry_code">رمز الدولة <span style="color:#b45309;">*</span></label>
            <input type="text" id="ctry_code" dir="ltr" lang="en" maxlength="8" placeholder="kw" autocomplete="off"
                value="<?php echo $editRow ? htmlspecialchars((string) $editRow['code'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                <?php echo $editRow ? 'readonly' : ''; ?>>
        </div>
        <div>
            <label for="ctry_currency">رمز العملة <span style="color:#b45309;">*</span></label>
            <input type="text" id="ctry_currency" dir="ltr" maxlength="8" placeholder="KWD" autocomplete="off"
                value="<?php echo $editRow ? htmlspecialchars((string) $editRow['currency_code'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div>
            <label for="ctry_name_ar">الاسم العربي <span style="color:#b45309;">*</span></label>
            <input type="text" id="ctry_name_ar" maxlength="191" autocomplete="off"
                value="<?php echo $editRow ? htmlspecialchars((string) $editRow['name_ar'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div>
            <label for="ctry_name_en">English</label>
            <input type="text" id="ctry_name_en" dir="ltr" lang="en" maxlength="191" autocomplete="off"
                value="<?php echo $editRow ? htmlspecialchars((string) $editRow['name_en'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div>
            <label for="ctry_sort">الترتيب</label>
            <input type="number" id="ctry_sort" value="<?php echo $editRow ? (int) ($editRow['sort_order'] ?? 0) : '0'; ?>" style="max-width:120px;">
        </div>
        <div style="display:flex;align-items:flex-end;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" id="ctry_is_active" <?php echo !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? 'checked' : ''; ?>>
                نشطة في واجهة المتجر
            </label>
        </div>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;">
        <button type="button" onclick="saveCountry()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="resetCountryForm()">جديد</button>
        <?php if ($editRow): ?>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=countries'), ENT_QUOTES, 'UTF-8'); ?>">إلغاء التعديل</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3>القائمة</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>رمز</th>
                    <th>عربي</th>
                    <th>English</th>
                    <th>عملة</th>
                    <th>ترتيب</th>
                    <th>نشطة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($countries as $row): ?>
                <tr>
                    <td><?php echo (int) $row['id']; ?></td>
                    <td dir="ltr"><code><?php echo htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo htmlspecialchars((string) $row['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) $row['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) $row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                    <td><?php echo (int) ($row['is_active'] ?? 0) === 1 ? 'نعم' : 'لا'; ?></td>
                    <td><a class="btn-secondary" href="?page=countries&amp;edit=<?php echo (int) $row['id']; ?>">تعديل</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function resetCountryForm() {
    window.location.href = <?php echo json_encode(storefront_public_path('/admin/index.php?page=countries'), JSON_UNESCAPED_UNICODE); ?>;
}
async function saveCountry() {
    var res = await postJSON('/admin/api/countries/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('ctry_id').value, 10) || 0,
        code: document.getElementById('ctry_code').value.trim(),
        name_ar: document.getElementById('ctry_name_ar').value.trim(),
        name_en: document.getElementById('ctry_name_en').value.trim(),
        currency_code: document.getElementById('ctry_currency').value.trim(),
        sort_order: parseInt(document.getElementById('ctry_sort').value, 10) || 0,
        is_active: document.getElementById('ctry_is_active').checked ? 1 : 0
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) {
        window.location.reload();
    }
}
</script>
