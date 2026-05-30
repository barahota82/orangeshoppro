<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/analytical_dimensions.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_analytical_dimensions_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول الأبعاد التحليلية غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    orange_analytical_dimension_seed_v1($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? 'list'));

    if ($action === 'list') {
        $dimensionId = (int) ($data['dimension_id'] ?? 0);
        $dims = orange_analytical_dimensions_list_with_value_counts($pdo, $ctxCountryId, false);
        $values = $dimensionId > 0
            ? orange_analytical_dimension_values_list($pdo, $dimensionId, false)
            : [];
        json_response([
            'success' => true,
            'dimensions' => $dims,
            'values' => $values,
            'dimension_id' => $dimensionId,
        ]);
    }

    if ($action === 'save_dimension') {
        $dimensionId = (int) ($data['dimension_id'] ?? 0);
        if ($dimensionId <= 0) {
            json_response(['success' => false, 'message' => 'معرّف البُعد مطلوب'], 422);
        }
        orange_analytical_dimension_update_header($pdo, $dimensionId, [
            'label_ar' => trim((string) ($data['label_ar'] ?? '')),
            'label_en' => trim((string) ($data['label_en'] ?? '')),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ], $ctxCountryId);
        audit_log('analytical_dimension_update', 'تحديث بُعد #' . $dimensionId, 'analytical_dimension', $dimensionId);
        json_response(['success' => true, 'message' => 'تم حفظ البُعد']);
    }

    if ($action === 'save_value') {
        $id = orange_analytical_dimension_save_value($pdo, [
            'id' => (int) ($data['id'] ?? 0),
            'dimension_id' => (int) ($data['dimension_id'] ?? 0),
            'code' => trim((string) ($data['code'] ?? '')),
            'label_ar' => trim((string) ($data['label_ar'] ?? '')),
            'label_en' => trim((string) ($data['label_en'] ?? '')),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
        ], $ctxCountryId);
        audit_log('analytical_dimension_value_save', 'حفظ قيمة بُعد #' . $id, 'analytical_dimension_value', $id);
        json_response(['success' => true, 'message' => 'تم حفظ القيمة', 'id' => $id]);
    }

    if ($action === 'delete_value') {
        $valueId = (int) ($data['id'] ?? 0);
        if (! orange_analytical_dimension_delete_value($pdo, $valueId, $ctxCountryId)) {
            json_response(['success' => false, 'message' => 'تعذّر الحذف'], 422);
        }
        audit_log('analytical_dimension_value_delete', 'حذف قيمة بُعد #' . $valueId, 'analytical_dimension_value', $valueId);
        json_response(['success' => true, 'message' => 'تم حذف القيمة']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
