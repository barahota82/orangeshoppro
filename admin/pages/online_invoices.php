<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/delivery_agents.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$adminCountryId = orange_admin_context_country_id($pdo);
$ordersMoney = orange_admin_currency_context($pdo);
$hasInvCol = orange_table_has_column($pdo, 'orders', 'invoice_number');

$q = trim((string) ($_GET['q'] ?? ''));
$navId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$rows = [];
if ($hasInvCol) {
    $sql = "
        SELECT o.id, o.order_number, o.invoice_number, o.customer_name, o.phone, o.total, o.completed_at, o.created_at
        FROM orders o
        WHERE o.invoice_number IS NOT NULL
          AND o.invoice_number <> ''
          AND o.invoice_number LIKE 'INV-O-%'
          AND o.status = 'completed'
    ";
    $params = [];
    $cf = orange_sql_filter_country_id($pdo, 'orders', 'o', $adminCountryId);
    if ($cf !== null) {
        $sql .= $cf['sql'];
        $params[] = $cf['param'];
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' AND (o.invoice_number LIKE ? OR o.order_number LIKE ? OR o.customer_name LIKE ? OR o.phone LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= ' ORDER BY o.invoice_number DESC, o.id DESC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$current = null;
$prevId = 0;
$nextId = 0;
if ($navId > 0) {
    foreach ($rows as $i => $row) {
        if ((int) ($row['id'] ?? 0) === $navId) {
            $current = $row;
            if ($i > 0) {
                $prevId = (int) ($rows[$i - 1]['id'] ?? 0);
            }
            if ($i < count($rows) - 1) {
                $nextId = (int) ($rows[$i + 1]['id'] ?? 0);
            }
            break;
        }
    }
}

$indexBase = storefront_public_path('/admin/index.php');
?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">فواتير أونلاين (INV-O)</h1>
    <p class="admin-fy-shell__lead">
        فواتير بعد «إنشاء القيود» — بحث وتصفّح.
        <a href="<?php echo htmlspecialchars($indexBase . '?page=online_orders_final_posting', ENT_QUOTES, 'UTF-8'); ?>">طلبات أونلاين — القيود</a>
    </p>

<div class="card admin-fy-card">
    <form method="get" action="<?php echo htmlspecialchars($indexBase, ENT_QUOTES, 'UTF-8'); ?>" class="admin-toolbar" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:12px;">
        <input type="hidden" name="page" value="online_invoices">
        <label class="admin-toolbar__field" style="flex:1;min-width:240px;">
            <span>بحث (رقم فاتورة / طلب / عميل / هاتف)</span>
            <input type="search" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
        </label>
        <button type="submit">بحث</button>
    </form>

    <?php if (!$hasInvCol): ?>
    <div class="alert-error">عمود invoice_number غير موجود — حدّث المخطط.</div>
    <?php elseif ($rows === []): ?>
    <p class="muted">لا توجد فواتير أونلاين (INV-O) بعد «إنشاء القيود».</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>رقم الفاتورة</th>
                    <th>رقم الطلب</th>
                    <th>العميل</th>
                    <th>الهاتف</th>
                    <th>الإجمالي</th>
                    <th>تاريخ التسليم</th>
                    <th>عرض</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $o): ?>
                <?php $oid = (int) ($o['id'] ?? 0); ?>
                <tr<?php echo $oid === $navId ? ' style="background:#f0fdf4;"' : ''; ?>>
                    <td><?php echo htmlspecialchars((string) ($o['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo orange_format_money_for_context($ordersMoney, (float) ($o['total'] ?? 0)); ?></td>
                    <td><?php
                        $ca = (string) ($o['completed_at'] ?? '');
                        echo $ca !== '' ? htmlspecialchars($ca, ENT_QUOTES, 'UTF-8') : '—';
                    ?></td>
                    <td><a href="<?php echo htmlspecialchars($indexBase . '?page=online_invoices&id=' . $oid . ($q !== '' ? '&q=' . rawurlencode($q) : ''), ENT_QUOTES, 'UTF-8'); ?>">← →</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($current): ?>
    <div class="card-hint" style="margin-top:16px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;">
        <p><strong>عرض:</strong> <?php echo htmlspecialchars((string) ($current['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            — <?php echo htmlspecialchars((string) ($current['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;">
            <?php if ($prevId > 0): ?>
            <a class="btn-secondary" href="<?php echo htmlspecialchars($indexBase . '?page=online_invoices&id=' . $prevId . ($q !== '' ? '&q=' . rawurlencode($q) : ''), ENT_QUOTES, 'UTF-8'); ?>">← السابق</a>
            <?php endif; ?>
            <?php if ($nextId > 0): ?>
            <a class="btn-secondary" href="<?php echo htmlspecialchars($indexBase . '?page=online_invoices&id=' . $nextId . ($q !== '' ? '&q=' . rawurlencode($q) : ''), ENT_QUOTES, 'UTF-8'); ?>">التالي →</a>
            <?php endif; ?>
            <a class="btn" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice&order_id=' . (int) ($current['id'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">طباعة الفاتورة</a>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>
