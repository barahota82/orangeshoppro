<?php

declare(strict_types=1);

/**
 * تصدير طابور طلبات الموقع كـ CSV (UTF-8 مع BOM لبرنامج Excel).
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $stAdmin = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $stAdmin->execute([(int) $_SESSION['admin_id']]);
    $admin = $stAdmin->fetch(PDO::FETCH_ASSOC);
    if (!$admin || !orange_admin_may($admin, $pdo, 'sales', 'view')) {
        json_response(['success' => false, 'message' => 'لا تملك صلاحية التصدير'], 403);
    }

    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        json_response(['success' => false, 'message' => 'جدول الطابور غير موجود'], 503);
    }

    $status = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
    if (!in_array($status, ['all', 'pending', 'failed', 'completed'], true)) {
        $status = 'all';
    }

    $intakeScope = orange_order_intake_sql_country_scope($pdo, 'oiq');
    $sql = 'SELECT oiq.id, oiq.public_token, oiq.status, oiq.order_id, oiq.order_number, oiq.error_message, oiq.attempts, oiq.created_at, oiq.updated_at, oiq.payload_json
            FROM order_intake_queue oiq';
    $params = [];
    if ($intakeScope !== null) {
        $sql .= $intakeScope['join'] . ' WHERE 1=1' . $intakeScope['where'];
        $params = $intakeScope['params'];
    } else {
        $sql .= ' WHERE 1=1';
    }
    if ($status !== 'all') {
        $sql .= ' AND oiq.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY oiq.id DESC LIMIT 8000';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $fname = 'order_intake_queue_' . date('Y-m-d_His');
    if ($status !== 'all') {
        $fname .= '_' . $status;
    }
    $fname .= '.csv';

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: no-store');
    }

    $out = fopen('php://output', 'w');
    if ($out === false) {
        json_response(['success' => false, 'message' => 'تعذّر فتح المخرجات'], 500);
    }

    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'id',
        'status',
        'public_token',
        'order_id',
        'order_number',
        'attempts',
        'created_at',
        'updated_at',
        'error_message',
        'customer_name',
        'phone',
        'channel_id',
        'items_count',
    ]);

    foreach ($rows as $r) {
        $j = json_decode((string) ($r['payload_json'] ?? ''), true);
        $name = is_array($j) ? trim((string) ($j['name'] ?? '')) : '';
        $phone = is_array($j) ? trim((string) ($j['phone'] ?? '')) : '';
        $ch = is_array($j) && isset($j['channel_id']) ? (string) (int) $j['channel_id'] : '';
        $itemsCnt = '';
        if (is_array($j) && isset($j['items']) && is_array($j['items'])) {
            $itemsCnt = (string) count($j['items']);
        }
        fputcsv($out, [
            (string) ($r['id'] ?? ''),
            (string) ($r['status'] ?? ''),
            (string) ($r['public_token'] ?? ''),
            (string) ($r['order_id'] ?? ''),
            (string) ($r['order_number'] ?? ''),
            (string) ($r['attempts'] ?? ''),
            (string) ($r['created_at'] ?? ''),
            (string) ($r['updated_at'] ?? ''),
            (string) ($r['error_message'] ?? ''),
            $name,
            $phone,
            $ch,
            $itemsCnt,
        ]);
    }

    fclose($out);
    audit_log('order_intake_export_csv', 'تصدير طابور طلبات الموقع CSV — ' . count($rows) . ' صف، تصفية: ' . $status, 'order_intake_queue', 0);
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        orange_admin_api_catch($e, 'تعذر تصدير الملف');
    }
    exit;
}
