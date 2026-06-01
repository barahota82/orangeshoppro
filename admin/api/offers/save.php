<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/cart_promo_schedule.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    if (empty($data['product_id']) || !isset($data['discount'])) {
        json_response(['success' => false, 'message' => 'بيانات العرض مطلوبة'], 422);
    }

    $bounds = orange_cart_promo_parse_required_admin_dates(
        trim((string) ($data['valid_from'] ?? '')),
        trim((string) ($data['valid_to'] ?? '')),
        $dateErr
    );
    if ($bounds === null) {
        json_response(['success' => false, 'message' => $dateErr ?? 'تواريخ العرض غير صالحة'], 422);
    }

    $pid = (int) $data['product_id'];
    $discount = (float) $data['discount'];
    if ($discount <= 0) {
        json_response(['success' => false, 'message' => 'قيمة الخصم يجب أن تكون أكبر من صفر'], 422);
    }

    $ch = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
    $ch->execute([$pid]);
    if (!$ch->fetchColumn()) {
        json_response(['success' => false, 'message' => 'المنتج غير موجود أو غير نشط'], 422);
    }
    try {
        orange_admin_assert_entity_country($pdo, 'products', $pid);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }
    if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
        json_response([
            'success' => false,
            'message' => 'المنتج غير ضمن الكتالوج الموحّد النشط — لا يمكن ربط عرض به في الوضع الحالي.',
        ], 422);
    }

    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $hasSort = orange_table_has_column($pdo, 'offers', 'sort_order');

    $offerId = (int) ($data['id'] ?? 0);
    if ($offerId > 0) {
        try {
            orange_admin_assert_entity_country($pdo, 'offers', $offerId);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        if ($hasSort) {
            $st = $pdo->prepare(
                'UPDATE offers SET product_id = ?, discount = ?, sort_order = ?, is_active = 1,
                 valid_from = ?, valid_to = ?, auto_paused_at = NULL, auto_paused_reason = NULL
                 WHERE id = ?'
            );
            $st->execute([
                $pid,
                $discount,
                $sortOrder,
                $bounds['valid_from'],
                $bounds['valid_to'],
                $offerId,
            ]);
        } else {
            $st = $pdo->prepare(
                'UPDATE offers SET product_id = ?, discount = ?, is_active = 1,
                 valid_from = ?, valid_to = ?, auto_paused_at = NULL, auto_paused_reason = NULL
                 WHERE id = ?'
            );
            $st->execute([
                $pid,
                $discount,
                $bounds['valid_from'],
                $bounds['valid_to'],
                $offerId,
            ]);
        }
        json_response(['success' => true, 'message' => 'تم تحديث العرض', 'id' => $offerId]);

        return;
    }

    if ($hasSort) {
        $stmt = $pdo->prepare(
            'INSERT INTO offers (product_id, discount, sort_order, is_active, valid_from, valid_to)
             VALUES (?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            $pid,
            $discount,
            $sortOrder,
            $bounds['valid_from'],
            $bounds['valid_to'],
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO offers (product_id, discount, is_active, valid_from, valid_to)
             VALUES (?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            $pid,
            $discount,
            $bounds['valid_from'],
            $bounds['valid_to'],
        ]);
    }

    json_response(['success' => true, 'message' => 'تم حفظ العرض', 'id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ العرض');
}
