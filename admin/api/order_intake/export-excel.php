<?php

declare(strict_types=1);

/**
 * تصدير طابور طلبات الموقع إلى **Excel xlsx حقيقي** (النمط الموحّد عبر includes/report_export.php).
 * يحترم تصفية الحالة الحالية، والصلاحية، ونطاق الدولة للمشرف.
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/company_settings.php';
require_once __DIR__ . '/../../../includes/report_export.php';
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
    $sql .= ' ORDER BY oiq.id DESC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $statusLabel = [
        'pending' => 'قيد الانتظار',
        'failed' => 'فاشل',
        'completed' => 'مكتمل',
    ];
    $statusFilterLabel = [
        'all' => 'الكل',
        'pending' => 'معلّقة',
        'failed' => 'فاشلة',
        'completed' => 'مكتملة',
    ];

    $xlsRows = [];
    foreach ($rows as $r) {
        $j = json_decode((string) ($r['payload_json'] ?? ''), true);
        $name = is_array($j) ? trim((string) ($j['name'] ?? '')) : '';
        $phone = is_array($j) ? trim((string) ($j['phone'] ?? '')) : '';
        $ch = is_array($j) && isset($j['channel_id']) ? (string) (int) $j['channel_id'] : '';
        $itemsCnt = '';
        if (is_array($j) && isset($j['items']) && is_array($j['items'])) {
            $itemsCnt = (string) count($j['items']);
        }
        $stCode = (string) ($r['status'] ?? '');
        $xlsRows[] = [
            (int) ($r['id'] ?? 0),
            $statusLabel[$stCode] ?? $stCode,
            (string) ($r['public_token'] ?? ''),
            (int) ($r['order_id'] ?? 0),
            (string) ($r['order_number'] ?? ''),
            (int) ($r['attempts'] ?? 0),
            (string) ($r['created_at'] ?? ''),
            (string) ($r['updated_at'] ?? ''),
            (string) ($r['error_message'] ?? ''),
            $name,
            $phone,
            $ch,
            $itemsCnt,
        ];
    }

    $subtitle = 'التصفية: ' . ($statusFilterLabel[$status] ?? 'الكل') . ' — عدد الصفوف: ' . count($rows);

    audit_log('order_intake_export_excel', 'تصدير طابور طلبات الموقع Excel — ' . count($rows) . ' صف، تصفية: ' . $status, 'order_intake_queue', 0);

    orange_report_xls_output(
        'طابور طلبات الموقع',
        'طابور طلبات الموقع',
        orange_company_settings_name_ar($pdo),
        $subtitle,
        ['المعرّف', 'الحالة', 'الرمز العام', 'معرّف الطلب', 'رقم الطلب', 'محاولات', 'أنشئ', 'آخر تحديث', 'خطأ / تفاصيل', 'اسم العميل', 'الهاتف', 'القناة', 'عدد الأصناف'],
        $xlsRows,
        [0, 3, 5, 12]
    );
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        orange_admin_api_catch($e, 'تعذر تصدير الملف');
    }
    exit;
}
