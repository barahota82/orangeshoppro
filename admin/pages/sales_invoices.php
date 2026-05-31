<?php

declare(strict_types=1);

$orderId = (int) ($_GET['order_id'] ?? $_GET['id'] ?? 0);
$base = storefront_public_path('/admin/index.php');
if ($orderId > 0) {
    header('Location: ' . $base . '?page=company_sales_invoice&order_id=' . $orderId, true, 302);
} else {
    header('Location: ' . $base . '?page=company_sales_invoice', true, 302);
}
exit;
