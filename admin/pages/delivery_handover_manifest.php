<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/delivery_agents.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/company_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$adminCountryId = orange_admin_context_country_id($pdo);
$ordersMoney = orange_admin_currency_context($pdo);

$agentId = isset($_GET['agent_id']) ? (int) $_GET['agent_id'] : 0;
$orderIdsRaw = trim((string) ($_GET['order_ids'] ?? ''));
$orderIds = [];
if ($orderIdsRaw !== '') {
    foreach (explode(',', $orderIdsRaw) as $part) {
        $id = (int) trim($part);
        if ($id > 0) {
            $orderIds[] = $id;
        }
    }
    $orderIds = array_values(array_unique($orderIds));
}

$agent = null;
if ($agentId > 0 && orange_table_exists($pdo, 'delivery_agents')) {
    $st = $pdo->prepare('SELECT * FROM delivery_agents WHERE id = ? LIMIT 1');
    $st->execute([$agentId]);
    $agent = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$orders = [];
$pieceTotals = [];
if ($orderIds !== []) {
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $sql = "
        SELECT o.*
        FROM orders o
        WHERE o.id IN ($ph)
    ";
    $params = $orderIds;
    $cf = orange_sql_filter_country_id($pdo, 'orders', 'o', $adminCountryId);
    if ($cf !== null) {
        $sql .= $cf['sql'];
        $params[] = $cf['param'];
    }
    if ($agentId > 0 && orange_table_has_column($pdo, 'orders', 'delivery_agent_id')) {
        $sql .= ' AND o.delivery_agent_id = ?';
        $params[] = $agentId;
    }
    $sql .= ' ORDER BY o.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($orders !== []) {
        $ids = array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $orders);
        $ph2 = implode(',', array_fill(0, count($ids), '?'));
        $it = $pdo->prepare("SELECT order_id, SUM(qty) AS pieces FROM order_items WHERE order_id IN ($ph2) GROUP BY order_id");
        $it->execute($ids);
        while ($row = $it->fetch(PDO::FETCH_ASSOC)) {
            $pieceTotals[(int) ($row['order_id'] ?? 0)] = (int) ($row['pieces'] ?? 0);
        }
    }
}

$countryLabel = '';
if ($adminCountryId > 0) {
    $cst = $pdo->prepare('SELECT name_ar FROM countries WHERE id = ? LIMIT 1');
    $cst->execute([$adminCountryId]);
    $countryLabel = trim((string) ($cst->fetchColumn() ?: ''));
}
$company = orange_company_settings_row($pdo, $adminCountryId > 0 ? $adminCountryId : null);
$companyName = trim((string) (is_array($company) ? ($company['company_name_ar'] ?? '') : ''));
$today = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s')) ?: date('Y-m-d');
?>
<style>
@media print {
    .no-print { display: none !important; }
    .admin-header, .admin-sidebar, .admin-nav { display: none !important; }
    body { background: #fff; }
}
.dhm-doc { max-width: 900px; margin: 0 auto; background: #fff; padding: 1.5rem; }
.dhm-title { text-align: center; margin: 0 0 1rem; }
.dhm-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 1rem; font-size: 0.95rem; }
.dhm-table { width: 100%; border-collapse: collapse; }
.dhm-table th, .dhm-table td { border: 1px solid #cbd5e1; padding: 8px; text-align: right; }
.dhm-table th { background: #f8fafc; }
</style>

<div class="admin-fy-shell" dir="rtl">
    <div class="no-print" style="margin-bottom:12px;">
        <div class="page-title">
            <h1>ورقة المندوب (manifest)</h1>
            <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <p class="page-subtitle">
            طباعة مجمّعة للطلبات المحدّدة — افتح من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=delivery_agent_handover'), ENT_QUOTES, 'UTF-8'); ?>">تسليم المندوب</a>.
        </p>
        <?php if ($orders !== []): ?>
        <button type="button" class="btn" onclick="window.print()">طباعة / PDF</button>
        <?php endif; ?>
    </div>

<?php if ($orderIds === []): ?>
<div class="card no-print"><p class="muted">حدّد طلبات من شاشة تسليم المندوب ثم «طباعة الورقة».</p></div>
<?php elseif ($orders === []): ?>
<div class="card no-print"><div class="alert-error">لا توجد طلبات مطابقة للمعرّفات/المندوب.</div></div>
<?php else: ?>

<div class="dhm-doc">
    <h2 class="dhm-title"><?php echo $companyName !== '' ? htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') : 'Orange'; ?> — ورقة تسليم المندوب</h2>
    <div class="dhm-meta">
        <div><strong>المندوب:</strong> <?php echo $agent ? htmlspecialchars(orange_delivery_agent_display_name($agent), ENT_QUOTES, 'UTF-8') : '—'; ?></div>
        <div><strong>التاريخ:</strong> <?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?></div>
        <div><strong>الدولة:</strong> <?php echo $countryLabel !== '' ? htmlspecialchars($countryLabel, ENT_QUOTES, 'UTF-8') : '—'; ?></div>
        <div><strong>عدد الطلبات:</strong> <?php echo count($orders); ?></div>
    </div>

    <table class="dhm-table">
        <thead>
            <tr>
                <th>#</th>
                <th>رقم الطلب</th>
                <th>العميل</th>
                <th>الهاتف</th>
                <th>المنطقة</th>
                <th>العنوان</th>
                <th>القطع</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            <?php $n = 0; foreach ($orders as $o): ?>
            <?php
            ++$n;
            $oid = (int) ($o['id'] ?? 0);
            ?>
            <tr>
                <td><?php echo $n; ?></td>
                <td><?php echo htmlspecialchars((string) ($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td dir="ltr"><?php echo htmlspecialchars((string) ($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($o['area'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($o['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo (int) ($pieceTotals[$oid] ?? 0); ?></td>
                <td><?php echo orange_format_money_for_context($ordersMoney, (float) ($o['total'] ?? 0)); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</div>
