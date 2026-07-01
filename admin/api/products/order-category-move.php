<?php

declare(strict_types=1);

/*
 * أداة إعادة ترتيب المنتجات (عرض المتجر) — نقل منتج داخل فئته بـ«ترقيم بفجوات».
 * المدخل: {category_id, product_id, prev_id, next_id} — أين يستقرّ المنتج بعد النقل
 * (بين prev_id و next_id ضمن نفس الفئة؛ 0 = الطرف).
 * السلوك: يضبط sort_order للمنتج على منتصف الفجوة بين جاريه (تعديل صفٍّ واحد غالباً).
 * إن لم تتّسع الفجوة (أو تجاوز الحد) يعيد ترقيم منتجات الفئة النشطة بتباعد STEP مرة واحدة.
 * لا يمسّ غير عمود sort_order، ومقيّد بدولة الأدمن والفئة المطلوبة.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/product_preview.php';
require_admin_api();

const ORANGE_PDO_STEP = 1000;

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    $categoryId = (int) ($data['category_id'] ?? 0);
    $productId = (int) ($data['product_id'] ?? 0);
    $prevId = (int) ($data['prev_id'] ?? 0);
    $nextId = (int) ($data['next_id'] ?? 0);

    if ($categoryId <= 0 || $productId <= 0) {
        json_response(['success' => false, 'message' => 'E_INPUT'], 422);
    }

    $adminCountryId = orange_admin_context_country_id($pdo);
    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $adminCountryId);
    $countrySql .= orange_preview_hide_sql($pdo, 'p');

    /* الترتيب الحالي لمنتجات الفئة النشطة (id => sort_order) بترتيب العرض. */
    $sql = 'SELECT p.id, p.sort_order
        FROM products p
        INNER JOIN product_types pt ON pt.id = p.product_type_id
        INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
        INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id
        WHERE ucc.id = :cat AND p.is_active = 1' . $countrySql . '
        ORDER BY p.sort_order ASC, p.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cat' => $categoryId]);
    $catRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $orderedIds = [];
    $sortById = [];
    foreach ($catRows as $r) {
        $id = (int) $r['id'];
        $orderedIds[] = $id;
        $sortById[$id] = (int) $r['sort_order'];
    }

    if (!isset($sortById[$productId])) {
        json_response(['success' => false, 'message' => 'E_NOT_IN_CATEGORY'], 422);
    }
    if ($prevId > 0 && !isset($sortById[$prevId])) {
        $prevId = 0;
    }
    if ($nextId > 0 && !isset($sortById[$nextId])) {
        $nextId = 0;
    }

    $prevS = $prevId > 0 ? $sortById[$prevId] : null;
    $nextS = $nextId > 0 ? $sortById[$nextId] : null;

    $needRebalance = false;
    $newVal = null;
    if ($prevId === 0 && $nextId === 0) {
        $newVal = ORANGE_PDO_STEP;
    } elseif ($prevId === 0) {
        $newVal = (int) $nextS - ORANGE_PDO_STEP;
        if ($newVal < 1) {
            $needRebalance = true;
        }
    } elseif ($nextId === 0) {
        $newVal = (int) $prevS + ORANGE_PDO_STEP;
    } else {
        if (((int) $nextS - (int) $prevS) >= 2) {
            $newVal = intdiv((int) $prevS + (int) $nextS, 2);
        } else {
            $needRebalance = true;
        }
    }

    $pdo->beginTransaction();

    if (!$needRebalance) {
        orange_admin_assert_entity_country($pdo, 'products', $productId);
        $u = $pdo->prepare('UPDATE products SET sort_order = ? WHERE id = ?');
        $u->execute([$newVal, $productId]);
        $pdo->commit();
        json_response(['success' => true, 'rebalanced' => false, 'sort_order' => $newVal, 'message' => 'OK_MOVE']);
    }

    /* إعادة الترقيم: أعِد بناء ترتيب الفئة بإدراج المنتج في موضعه المستهدف ثم باعد بـ STEP. */
    $rebuilt = [];
    foreach ($orderedIds as $id) {
        if ($id !== $productId) {
            $rebuilt[] = $id;
        }
    }
    if ($prevId === 0) {
        array_unshift($rebuilt, $productId);
    } else {
        $insertAt = array_search($prevId, $rebuilt, true);
        if ($insertAt === false) {
            $rebuilt[] = $productId;
        } else {
            array_splice($rebuilt, (int) $insertAt + 1, 0, [$productId]);
        }
    }

    $u = $pdo->prepare('UPDATE products SET sort_order = ? WHERE id = ?');
    $order = [];
    $val = ORANGE_PDO_STEP;
    foreach ($rebuilt as $id) {
        orange_admin_assert_entity_country($pdo, 'products', (int) $id);
        $u->execute([$val, (int) $id]);
        $order[] = ['id' => (int) $id, 'sort_order' => $val];
        $val += ORANGE_PDO_STEP;
    }
    $pdo->commit();
    json_response(['success' => true, 'rebalanced' => true, 'order' => $order, 'message' => 'OK_MOVE']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر نقل المنتج');
}
