<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';

$offersCountryId = orange_admin_context_country_id($pdo);
$offersProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $offersCountryId);

$catalogNavUnified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);
if (
    $catalogNavUnified
    && function_exists('orange_table_exists')
    && orange_table_exists($pdo, 'product_types')
    && orange_table_exists($pdo, 'catalog_subcategories')
) {
    $products = $pdo->query(
        '
        SELECT DISTINCT p.id, p.name
        FROM products p
        INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
        INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
        INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
        INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
        INNER JOIN departments d ON d.id = ucs2.department_id AND d.is_active = 1
        WHERE p.is_active = 1' . $offersProductsCountrySql . '
        ORDER BY p.name ASC
    '
    )->fetchAll();
} else {
    $products = $pdo->query('SELECT id, name FROM products WHERE is_active = 1' . $offersProductsCountrySql . ' ORDER BY name ASC')->fetchAll();
}

$offers = $pdo->query(
    '
    SELECT o.*, p.name AS product_name
    FROM offers o
    INNER JOIN products p ON p.id = o.product_id
    WHERE 1=1' . $offersProductsCountrySql . '
    ORDER BY o.id DESC
'
)->fetchAll();
?>
<div class="page-title">
    <h1>العروض</h1>
</div>

<div class="card">
    <h3>إضافة عرض</h3>
    <div class="form-grid">
        <div>
            <label>المنتج</label>
            <select id="offer_product_id">
                <option value="">اختر المنتج</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string) $p['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>قيمة الخصم</label>
            <input type="number" id="discount" class="admin-inp-money" step="any" min="0" inputmode="decimal" lang="en" dir="ltr">
        </div>
    </div>
    <div class="actions" style="margin-top:14px;">
        <button type="button" onclick="saveOffer()">حفظ العرض</button>
    </div>
</div>

<div class="card">
    <h3>قائمة العروض</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>المنتج</th>
                    <th>الخصم</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($offers as $o): ?>
                <tr>
                    <td><?php echo (int)$o['id']; ?></td>
                    <td><?php echo htmlspecialchars((string) $o['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo number_format((float)$o['discount'], 2); ?></td>
                    <td><?php echo (int)$o['is_active'] === 1 ? 'نشط' : 'مخفي'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
async function saveOffer() {
    const payload = {
        product_id: parseInt(document.getElementById('offer_product_id').value, 10),
        discount: parseFloat(document.getElementById('discount').value || '0')
    };
    const res = await postJSON('/admin/api/offers/save.php', payload);
    alert(res.message || (res.success ? 'تم حفظ العرض' : 'فشل حفظ العرض'));
    if (res.success) location.reload();
}
</script>
