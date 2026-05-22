<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'بيانات غير صحيحة'], 422);
    }
    if (!orange_table_exists($pdo, 'storefront_phone_merge_requests')) {
        json_response(['success' => false, 'message' => 'الجدول غير جاهز'], 503);
    }
    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $hasCountryCol = orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'country_id');
    if ($hasCountryCol && $ctxCountryId > 0) {
        $st = $pdo->prepare(
            'UPDATE storefront_phone_merge_requests SET wa_confirmed_at = NOW()
             WHERE id = ? AND country_id = ? AND consumed_at IS NULL AND expires_at > NOW() AND wa_confirmed_at IS NULL'
        );
        $st->execute([$id, $ctxCountryId]);
    } else {
        $st = $pdo->prepare(
            'UPDATE storefront_phone_merge_requests SET wa_confirmed_at = NOW()
             WHERE id = ? AND consumed_at IS NULL AND expires_at > NOW() AND wa_confirmed_at IS NULL'
        );
        $st->execute([$id]);
    }
    if ($st->rowCount() < 1) {
        json_response(['success' => false, 'message' => 'لا يمكن التأكيد — الطلب غير موجود أو منتهٍ أو مؤكَّد مسبقاً'], 422);
    }
    json_response(['success' => true, 'message' => 'تم تأكيد استلام واتساب']);
} catch (Throwable $e) {
    api_error($e, 'تعذّر التأكيد');
}
