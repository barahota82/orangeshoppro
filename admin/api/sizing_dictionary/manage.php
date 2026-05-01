<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

/** @return string */
$sanitizeKind = static function (string $raw): string {
    $t = strtolower(trim($raw));
    $t = (string) (preg_replace('/[^a-z0-9_-]/', '', $t) ?? '');

    return strlen($t) > 32 ? substr($t, 0, 32) : $t;
};

/** @return string */
$sanitizeCat = static function (string $raw): string {
    $t = strtolower(trim($raw));
    $t = (string) (preg_replace('/[^a-z0-9_-]/', '', $t) ?? '');

    return strlen($t) > 64 ? substr($t, 0, 64) : $t;
};

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    if (!orange_table_exists($pdo, 'commercial_kind_dictionary')
        || !orange_table_exists($pdo, 'sizing_category_dictionary')
    ) {
        json_response(['success' => false, 'message' => 'جدايل القاموس المرجعي غير متاحة؛ حدّث المخطّط.'], 503);
    }

    switch ($action) {
        case 'list_kinds':
            $rows = $pdo->query(
                'SELECT kind_key, label_ar, label_en, sort_order, is_active
                 FROM commercial_kind_dictionary
                 ORDER BY sort_order ASC, kind_key ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'kinds' => is_array($rows) ? $rows : []]);

        case 'list_categories':
            $ck = $sanitizeKind((string) ($data['commercial_kind_key'] ?? ''));
            if ($ck === '') {
                json_response(['success' => false, 'message' => 'حدد النوع التجاري'], 422);
            }
            $st = $pdo->prepare(
                'SELECT kind_key FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1'
            );
            $st->execute([$ck]);
            if (! $st->fetchColumn()) {
                json_response(['success' => false, 'message' => 'النوع التجاري غير موجود'], 404);
            }
            $st2 = $pdo->prepare(
                'SELECT commercial_kind_key, category_key, label_ar, label_en, sort_order, is_active
                 FROM sizing_category_dictionary
                 WHERE commercial_kind_key = ?
                 ORDER BY sort_order ASC, category_key ASC'
            );
            $st2->execute([$ck]);
            $rows = $st2->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'categories' => is_array($rows) ? $rows : []]);
            break;

        case 'save_kind':
            $kindKey = $sanitizeKind((string) ($data['kind_key'] ?? ''));
            $oldKind = $sanitizeKind((string) ($data['old_kind_key'] ?? ''));
            $labelAr = trim((string) ($data['label_ar'] ?? ''));
            $labelEn = trim((string) ($data['label_en'] ?? ''));
            $sort = (int) ($data['sort_order'] ?? 0);
            $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

            if ($kindKey === '') {
                json_response(['success' => false, 'message' => 'مفتاح النوع التجاري (بالإنجليزية) مطلوب'], 422);
            }

            if ($oldKind !== '' && $oldKind !== $kindKey) {
                $chkOld = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $chkOld->execute([$oldKind]);
                if (! $chkOld->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'المفتاح القديم غير موجود'], 404);
                }
                $chkNew = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
                $chkNew->execute([$kindKey]);
                if ($chkNew->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'المفتاح الجديد مستخدم بالفعل'], 409);
                }
                $pdo->beginTransaction();
                try {
                    $uFam = $pdo->prepare(
                        'UPDATE size_families SET commercial_kind_key = ? WHERE commercial_kind_key = ?'
                    );
                    $uFam->execute([$kindKey, $oldKind]);
                    $uKind = $pdo->prepare(
                        'UPDATE commercial_kind_dictionary SET
                            kind_key = ?, label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                         WHERE kind_key = ? LIMIT 1'
                    );
                    $uKind->execute([$kindKey, $labelAr, $labelEn, $sort, $active, $oldKind]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                json_response(['success' => true]);
                break;
            }

            $ex = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
            $ex->execute([$kindKey]);
            if ($ex->fetchColumn()) {
                $pdo->prepare(
                    'UPDATE commercial_kind_dictionary SET label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                     WHERE kind_key = ? LIMIT 1'
                )->execute([$labelAr, $labelEn, $sort, $active, $kindKey]);
            } else {
                if ($sort <= 0) {
                    $sort = (int) $pdo->query(
                        'SELECT COALESCE(MAX(sort_order),0)+1 FROM commercial_kind_dictionary'
                    )->fetchColumn();
                    if ($sort <= 0) {
                        $sort = 1;
                    }
                }
                $pdo->prepare(
                    'INSERT INTO commercial_kind_dictionary (kind_key, label_ar, label_en, sort_order, is_active)
                     VALUES (?,?,?,?,?)'
                )->execute([$kindKey, $labelAr, $labelEn, $sort, $active]);
            }
            json_response(['success' => true]);
            break;

        case 'delete_kind':
            $kindKey = $sanitizeKind((string) ($data['kind_key'] ?? ''));
            if ($kindKey === '') {
                json_response(['success' => false, 'message' => 'حدد نوعاً تجارياً'], 422);
            }
            $cnt = $pdo->prepare(
                'SELECT COUNT(*) FROM size_families WHERE commercial_kind_key = ?'
            );
            $cnt->execute([$kindKey]);
            if ((int) $cnt->fetchColumn() > 0) {
                json_response([
                    'success' => false,
                    'message' => 'لا يمكن حذف هذا النوع لأنه مستخدم في عائلات مقاسات؛ غيّر العائلات أولاً.',
                ], 409);
            }
            $pdo->prepare('DELETE FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1')->execute([$kindKey]);
            json_response(['success' => true]);
            break;

        case 'save_category':
            $ck = $sanitizeKind((string) ($data['commercial_kind_key'] ?? ''));
            $catKey = $sanitizeCat((string) ($data['category_key'] ?? ''));
            $oldCat = $sanitizeCat((string) ($data['old_category_key'] ?? ''));
            $labelAr = trim((string) ($data['label_ar'] ?? ''));
            $labelEn = trim((string) ($data['label_en'] ?? ''));
            $sort = (int) ($data['sort_order'] ?? 0);
            $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

            if ($ck === '' || $catKey === '') {
                json_response(['success' => false, 'message' => 'النوع التجاري ومفتاح فئة القياس مطلوبان'], 422);
            }
            $st = $pdo->prepare('SELECT 1 FROM commercial_kind_dictionary WHERE kind_key = ? LIMIT 1');
            $st->execute([$ck]);
            if (! $st->fetchColumn()) {
                json_response(['success' => false, 'message' => 'النوع التجاري غير موجود في القاموس'], 404);
            }

            if ($oldCat !== '' && $oldCat !== $catKey) {
                $chkOld = $pdo->prepare(
                    'SELECT 1 FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                );
                $chkOld->execute([$ck, $oldCat]);
                if (! $chkOld->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'فئة القياس القديمة غير موجودة'], 404);
                }
                $chkNew = $pdo->prepare(
                    'SELECT 1 FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                );
                $chkNew->execute([$ck, $catKey]);
                if ($chkNew->fetchColumn()) {
                    json_response(['success' => false, 'message' => 'مفتاح فئة القياس الجديد مستخدم بالفعل'], 409);
                }
                $pdo->beginTransaction();
                try {
                    $uFam = $pdo->prepare(
                        'UPDATE size_families SET sizing_category_key = ?
                         WHERE commercial_kind_key = ? AND sizing_category_key = ?'
                    );
                    $uFam->execute([$catKey, $ck, $oldCat]);
                    $uRow = $pdo->prepare(
                        'UPDATE sizing_category_dictionary SET
                            category_key = ?, label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                         WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                    );
                    $uRow->execute([$catKey, $labelAr, $labelEn, $sort, $active, $ck, $oldCat]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                json_response(['success' => true]);
                break;
            }

            $ex = $pdo->prepare(
                'SELECT 1 FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
            );
            $ex->execute([$ck, $catKey]);
            if ($ex->fetchColumn()) {
                $pdo->prepare(
                    'UPDATE sizing_category_dictionary SET label_ar = ?, label_en = ?, sort_order = ?, is_active = ?
                     WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
                )->execute([$labelAr, $labelEn, $sort, $active, $ck, $catKey]);
            } else {
                if ($sort <= 0) {
                    $stMax = $pdo->prepare(
                        'SELECT COALESCE(MAX(sort_order),0)+1 FROM sizing_category_dictionary WHERE commercial_kind_key = ?'
                    );
                    $stMax->execute([$ck]);
                    $sort = (int) $stMax->fetchColumn();
                    if ($sort <= 0) {
                        $sort = 1;
                    }
                }
                $pdo->prepare(
                    'INSERT INTO sizing_category_dictionary
                        (commercial_kind_key, category_key, label_ar, label_en, sort_order, is_active)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$ck, $catKey, $labelAr, $labelEn, $sort, $active]);
            }
            json_response(['success' => true]);
            break;

        case 'delete_category':
            $ck = $sanitizeKind((string) ($data['commercial_kind_key'] ?? ''));
            $catKey = $sanitizeCat((string) ($data['category_key'] ?? ''));
            if ($ck === '' || $catKey === '') {
                json_response(['success' => false, 'message' => 'حددا النوع وفئة القياس'], 422);
            }
            $cnt = $pdo->prepare(
                'SELECT COUNT(*) FROM size_families WHERE commercial_kind_key = ? AND sizing_category_key = ?'
            );
            $cnt->execute([$ck, $catKey]);
            if ((int) $cnt->fetchColumn() > 0) {
                json_response([
                    'success' => false,
                    'message' => 'لا يمكن حذف هذه الفئة لأنها مستخدمة في عائلة مقاسات.',
                ], 409);
            }
            $pdo->prepare(
                'DELETE FROM sizing_category_dictionary WHERE commercial_kind_key = ? AND category_key = ? LIMIT 1'
            )->execute([$ck, $catKey]);
            json_response(['success' => true]);
            break;

        default:
            json_response(['success' => false, 'message' => 'إجراء غير معروف'], 400);
    }
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذّر تنفيذ طلب القاموس المرجعي');
}
