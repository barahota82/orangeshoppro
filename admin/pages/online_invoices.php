<?php

declare(strict_types=1);

$target = storefront_public_path('/admin/index.php?page=online_sales_invoice');
$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
if ($orderId > 0) {
    $target .= '&order_id=' . $orderId;
}
header('Location: ' . $target, true, 302);
exit;
