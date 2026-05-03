<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_sizing_dictionary.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $sanitizeKey = static function (string $raw, int $maxLen): string {
        $t = strtolower(trim($raw));
        $t = (string) (preg_replace('/[^a-z0-9_-]/', '', $t) ?? '');
        if (strlen($t) > $maxLen) {
            $t = substr($t, 0, $maxLen);
        }

        return $t;
    };

    $sizeScheme = $sanitizeKey((string) ($data['size_scheme_key'] ?? ''), 64);
    $commercialKind = $sanitizeKey((string) ($data['commercial_kind_key'] ?? ''), 32);
    $sizingCategory = $sanitizeKey((string) ($data['sizing_category_key'] ?? ''), 64);

    $id = (int)($data['id'] ?? 0);
    $nameAr = trim((string)($data['name_ar'] ?? ''));
    $nameEn = trim((string)($data['name_en'] ?? ''));
    $sort = (int)($data['sort_order'] ?? 0);
    $active = (int)($data['is_active'] ?? 1) === 0 ? 0 : 1;

    if ($nameAr === '' || $nameEn === '') {
        json_response(['success' => false, 'message' => 'يجب تعبئة الاسم العربي والإنجليزي'], 422);
    }

    if ($sizeScheme !== '' && ($commercialKind === '' || $sizingCategory === '')) {
        json_response([
            'success' => false,
            'message' => 'عند ضبط مخطّط مقاس (size_scheme_key) يجب ملء هرَم المقاس: النوع التجاري commercial_kind_key وفئة القياس sizing_category_key — راجع سياسة الهرم الأربعة في الوثائق.',
        ], 422);
    }

    $dicErr = orange_catalog_validate_size_family_dictionary_consistency($pdo, $commercialKind, $sizingCategory);
    if ($dicErr !== null) {
        json_response(['success' => false, 'message' => $dicErr], 422);
    }

    // تمييز العائلات بـ size_scheme_key (مستوى 3 EN) وليس بالاسم العربي بعد التطبيع — يسمح بأسماء عربية متشابهة إذا اختلف المفتاح.
    if ($sizeScheme !== '') {
        $famRows = $pdo->query('SELECT id, size_scheme_key FROM size_families')->fetchAll(PDO::FETCH_ASSOC);
        $excludeFamId = $id > 0 ? $id : null;
        foreach (is_array($famRows) ? $famRows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rid = (int) ($row['id'] ?? 0);
            if ($excludeFamId !== null && $rid === $excludeFamId) {
                continue;
            }
            $other = $sanitizeKey((string) ($row['size_scheme_key'] ?? ''), 64);
            if ($other !== '' && $other === $sizeScheme) {
                json_response([
                    'success' => false,
                    'message' => 'لا يمكن الحفظ: مفتاح مخطّط المقاس (size_scheme_key) مستخدم بالفعل لعائلة أخرى. غيّر الاسم الإنجليزي أو فئة القياس ليُولَّد مفتاح مختلف.',
                ], 409);
            }
        }
    }

    if ($id <= 0 && $sort <= 0) {
        $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM size_families')->fetchColumn();
        if ($sort <= 0) {
            $sort = 1;
        }
    }

    if ($id > 0) {
        $pdo->prepare(
            'UPDATE size_families SET name_ar=?, name_en=?, size_scheme_key=?, commercial_kind_key=?, sizing_category_key=?, sort_order=?, is_active=? WHERE id=? LIMIT 1'
        )->execute([$nameAr, $nameEn, $sizeScheme, $commercialKind, $sizingCategory, $sort, $active, $id]);
        json_response(['success' => true, 'id' => $id]);
    }

    $pdo->prepare(
        'INSERT INTO size_families (name_ar, name_en, size_scheme_key, commercial_kind_key, sizing_category_key, sort_order, is_active) VALUES (?,?,?,?,?,?,?)'
    )->execute([$nameAr, $nameEn, $sizeScheme, $commercialKind, $sizingCategory, $sort, $active]);
    json_response(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ عائلة المقاسات');
}
