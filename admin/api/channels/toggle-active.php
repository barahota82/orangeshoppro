<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';

require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    $id = (int) ($data['id'] ?? 0);
    $isActive = (int) ($data['is_active'] ?? -1);
    if ($id <= 0 || ($isActive !== 0 && $isActive !== 1)) {
        json_response(['success' => false, 'message' => 'بيانات غير صالحة'], 422);
    }

    $chk = $pdo->prepare('SELECT id FROM channels WHERE id = ? LIMIT 1');
    $chk->execute([$id]);
    if (!$chk->fetch()) {
        json_response(['success' => false, 'message' => 'القناة غير موجودة'], 404);
    }

    $st = $pdo->prepare('UPDATE channels SET is_active = ? WHERE id = ?');
    $st->execute([$isActive, $id]);

    json_response([
        'success' => true,
        'message' => $isActive === 1 ? 'تم تفعيل الواجهة' : 'تم إيقاف ظهور الواجهة للزوار',
        'is_active' => $isActive,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحديث حالة القناة');
}
