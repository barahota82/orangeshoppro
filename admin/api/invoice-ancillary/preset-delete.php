<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرّف البند غير صالح'], 422);
    }
    if (!orange_table_exists($pdo, 'orange_invoice_line_presets')) {
        json_response(['success' => false, 'message' => 'جدول البنود غير جاهز'], 422);
    }

    $countryId = orange_admin_context_country_id($pdo);
    // الحذف آمن: orange_invoice_extra_lines.preset_id مضبوط ON DELETE SET NULL،
    // والأسطر المحفوظة تحتفظ بـ account_id الخاص بها.
    $st = $pdo->prepare('DELETE FROM orange_invoice_line_presets WHERE id = ? AND country_id = ?');
    $st->execute([$id, $countryId]);

    if ($st->rowCount() <= 0) {
        json_response(['success' => false, 'message' => 'البند غير موجود في سياق هذه الدولة'], 404);
    }

    // إزالة فراغات sort_order الناتجة عن حذف بند وسطي (إعادة ترقيم 1..N).
    orange_invoice_ancillary_presets_normalize_sort($pdo, $countryId);

    json_response(['success' => true, 'message' => 'تم حذف البند']);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حذف البند');
}
