<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();
    $ids = $data['ordered_ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) json_response(['success'=>false,'message'=>'بيانات غير صحيحة'],422);

    $pdo->beginTransaction();
    $u = $pdo->prepare("UPDATE departments SET sort_order = ? WHERE id = ?");
    $sort = 1;
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $u->execute([$sort, $id]);
            $sort++;
        }
    }
    $pdo->commit();
    json_response(['success'=>true,'message'=>'تم حفظ ترتيب الأقسام']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['success'=>false,'message'=>$e->getMessage()],500);
}
