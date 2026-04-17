<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/product_channels.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $productId = (int)($data['id'] ?? 0);
    if ($productId <= 0) {
        json_response(['success' => false, 'message' => 'معرف المنتج مطلوب'], 422);
    }

    if (empty($data['name']) || empty($data['category_id']) || !isset($data['price']) || !isset($data['cost'])) {
        json_response(['success' => false, 'message' => 'البيانات الأساسية مطلوبة'], 422);
    }

    $nameEn = trim((string)($data['name_en'] ?? ''));
    $nameFil = trim((string)($data['name_fil'] ?? ''));
    $nameHi = trim((string)($data['name_hi'] ?? ''));
    if ($nameEn === '' || $nameFil === '' || $nameHi === '') {
        json_response(['success' => false, 'message' => 'أسماء المنتج بلغات English / Filipino / Hindi مطلوبة'], 422);
    }

    $nameAr = trim((string)$data['name']);
    $sizeFamilyId = isset($data['size_family_id']) ? (int)$data['size_family_id'] : 0;
    if ($sizeFamilyId <= 0) {
        $sizeFamilyId = null;
    }
    $scope = trim((string)($data['sizing_guide_scope'] ?? 'none'));
    $allowedScopes = ['none', 'upper', 'lower', 'both'];
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'none';
    }
    $hasSizes = (int)($data['has_sizes'] ?? 0) === 1;
    if ($hasSizes && $sizeFamilyId === null) {
        json_response(['success' => false, 'message' => 'يجب اختيار عائلة مقاسات عند تفعيل المقاسات'], 422);
    }
    $sortOrder = (int)($data['sort_order'] ?? 0);

    $prodStmt = $pdo->prepare('SELECT id, name FROM products WHERE category_id = ?');
    $prodStmt->execute([(int)$data['category_id']]);
    $prodRows = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
    if (orange_rows_normalized_arabic_conflict(is_array($prodRows) ? $prodRows : [], 'id', 'name', $nameAr, $productId)) {
        json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
    }

    $stmt = $pdo->prepare("
        UPDATE products
        SET name = ?, name_en = ?, name_fil = ?, name_hi = ?,
            description = ?, description_en = ?, description_fil = ?, description_hi = ?,
            category_id = ?, size_family_id = ?, sizing_guide_scope = ?, price = ?, cost = ?,
            main_image = ?, has_sizes = ?, has_colors = ?, sort_order = ?, is_active = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([
        $nameAr,
        $nameEn,
        $nameFil,
        $nameHi,
        trim((string)($data['description'] ?? '')),
        trim((string)($data['description_en'] ?? '')),
        trim((string)($data['description_fil'] ?? '')),
        trim((string)($data['description_hi'] ?? '')),
        (int)$data['category_id'],
        $sizeFamilyId,
        $scope,
        (float)$data['price'],
        (float)$data['cost'],
        trim((string)($data['main_image'] ?? '')),
        (int)($data['has_sizes'] ?? 0),
        (int)($data['has_colors'] ?? 0),
        $sortOrder,
        isset($data['is_active']) ? (int)$data['is_active'] : 1,
        $productId
    ]);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM products WHERE id = ? LIMIT 1");
        $checkStmt->execute([$productId]);
        if (!$checkStmt->fetch()) {
            json_response(['success' => false, 'message' => 'المنتج غير موجود'], 404);
        }
    }

    orange_product_attach_all_active_channels($pdo, $productId);

    audit_log('product_update', 'تم تحديث المنتج رقم: ' . $productId, 'products', $productId);
    json_response(['success' => true, 'message' => 'تم تحديث المنتج']);
} catch (Throwable $e) {
    api_error($e, 'تعذر تحديث المنتج');
}
