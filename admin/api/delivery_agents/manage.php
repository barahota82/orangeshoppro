<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/delivery_agents.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

/**
 * @param mixed $v
 */
function dag_str191($v): string
{
    $s = trim((string) $v);

    return function_exists('mb_substr') ? mb_substr($s, 0, 191, 'UTF-8') : substr($s, 0, 191);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'delivery_agents')) {
        json_response(['success' => false, 'message' => 'جدول delivery_agents غير جاهز'], 422);
    }

    $countryId = orange_admin_context_country_id($pdo);
    if (isset($data['country_id']) && (int) $data['country_id'] > 0) {
        $countryId = (int) $data['country_id'];
    }

    if ($action === 'list') {
        $statusFilter = isset($data['status']) ? trim((string) $data['status']) : 'all';
        json_response([
            'success' => true,
            'data' => orange_delivery_agents_admin_list($pdo, $countryId > 0 ? $countryId : null, $statusFilter),
        ]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $nameAr = dag_str191($data['name_ar'] ?? '');
        $nameEn = dag_str191($data['name_en'] ?? '');
        $phone = dag_str191($data['phone'] ?? '');
        $status = orange_delivery_agent_status_normalize((string) ($data['status'] ?? 'active'));
        $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم المندوب بالعربي مطلوب'], 422);
        }
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'الدولة غير محددة'], 422);
        }

        if ($id > 0) {
            orange_admin_assert_row_country($pdo, 'delivery_agents', $id);
            if ($status === 'terminated' && orange_delivery_agent_has_orders($pdo, $id)) {
                // مسموح — إنهاء خدمات بدل حذف
            }
            $st = $pdo->prepare(
                'UPDATE delivery_agents SET country_id = ?, name_ar = ?, name_en = ?, phone = ?, status = ?, sort_order = ?, notes = ? WHERE id = ?'
            );
            $st->execute([$countryId, $nameAr, $nameEn, $phone !== '' ? $phone : null, $status, $sortOrder, $notes !== '' ? $notes : null, $id]);
        } else {
            if ($sortOrder <= 0) {
                $sortOrder = orange_delivery_agents_next_sort_order($pdo, $countryId);
            }
            $st = $pdo->prepare(
                'INSERT INTO delivery_agents (country_id, name_ar, name_en, phone, status, sort_order, notes) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([$countryId, $nameAr, $nameEn, $phone !== '' ? $phone : null, $status, $sortOrder, $notes !== '' ? $notes : null]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ المندوب']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر إدارة مناديب التوصيل');
}
