<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/advisory_sizing_library.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_advisory_sizing_library_tables_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول مكتبة الأدلة غير جاهزة؛ حدّث الصفحة أو شغّل الترحيل 031.'], 503);
    }

    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'list_bundles') {
        $rows = $pdo->query(
            'SELECT b.id, b.name_ar, b.name_en, b.commercial_kind_key, b.source_size_family_id, b.sort_order, b.is_active,
                    sf.name_ar AS source_family_ar, sf.name_en AS source_family_en
             FROM advisory_sizing_library_bundles b
             LEFT JOIN size_families sf ON sf.id = b.source_size_family_id
             ORDER BY b.sort_order ASC, b.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        json_response(['success' => true, 'bundles' => $rows]);
    }

    if ($action === 'save_bundle') {
        $id = (int) ($data['id'] ?? 0);
        $nameAr = trim((string) ($data['name_ar'] ?? ''));
        $nameEn = trim((string) ($data['name_en'] ?? ''));
        $ck = trim((string) ($data['commercial_kind_key'] ?? ''));
        if (strlen($ck) > 32) {
            $ck = substr($ck, 0, 32);
        }
        $srcFam = (int) ($data['source_size_family_id'] ?? 0);
        $sort = (int) ($data['sort_order'] ?? 0);
        $active = (int) ($data['is_active'] ?? 1) === 1 ? 1 : 0;
        if ($nameAr === '' && $nameEn === '') {
            json_response(['success' => false, 'message' => 'أدخل اسماً عربياً أو إنجليزياً للحزمة.'], 422);
        }
        if ($srcFam <= 0) {
            json_response(['success' => false, 'message' => 'اختر عائلة المصدر (حيث تُحرَّر الأدلة).'], 422);
        }
        $v = $pdo->prepare('SELECT id FROM size_families WHERE id = ? LIMIT 1');
        $v->execute([$srcFam]);
        if ((int) $v->fetchColumn() <= 0) {
            json_response(['success' => false, 'message' => 'عائلة المصدر غير موجودة.'], 422);
        }
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE advisory_sizing_library_bundles SET
                    name_ar = ?, name_en = ?, commercial_kind_key = ?, source_size_family_id = ?, sort_order = ?, is_active = ?
                 WHERE id = ?'
            )->execute([$nameAr, $nameEn, $ck, $srcFam, $sort, $active, $id]);
            json_response(['success' => true, 'id' => $id]);
        }
        $pdo->prepare(
            'INSERT INTO advisory_sizing_library_bundles
             (name_ar, name_en, commercial_kind_key, source_size_family_id, sort_order, is_active)
             VALUES (?,?,?,?,?,?)'
        )->execute([$nameAr, $nameEn, $ck, $srcFam, $sort, $active]);
        json_response(['success' => true, 'id' => (int) $pdo->lastInsertId()]);
    }

    if ($action === 'delete_bundle') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'معرّف غير صالح.'], 422);
        }
        $cntSt = $pdo->prepare('SELECT COUNT(*) FROM size_family_advisory_library_map WHERE library_bundle_id = ?');
        $cntSt->execute([$id]);
        if ((int) $cntSt->fetchColumn() > 0) {
            json_response(['success' => false, 'message' => 'لا يمكن الحذف: توجد عائلات مربوطة بهذه الحزمة — أزل الربط أولاً.'], 422);
        }
        $pdo->prepare('DELETE FROM advisory_sizing_library_bundles WHERE id = ?')->execute([$id]);
        json_response(['success' => true]);
    }

    if ($action === 'list_maps') {
        $rows = $pdo->query(
            'SELECT m.id, m.consumer_size_family_id, m.library_bundle_id, m.updated_at,
                    cf.name_ar AS consumer_ar, cf.name_en AS consumer_en,
                    b.name_ar AS bundle_ar, b.name_en AS bundle_en
             FROM size_family_advisory_library_map m
             INNER JOIN size_families cf ON cf.id = m.consumer_size_family_id
             INNER JOIN advisory_sizing_library_bundles b ON b.id = m.library_bundle_id
             ORDER BY cf.sort_order ASC, m.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        json_response(['success' => true, 'maps' => $rows]);
    }

    if ($action === 'save_map') {
        $consumer = (int) ($data['consumer_size_family_id'] ?? 0);
        $bundleId = (int) ($data['library_bundle_id'] ?? 0);
        if ($consumer <= 0 || $bundleId <= 0) {
            json_response(['success' => false, 'message' => 'اختر عائلة مستهلك وحزمة مكتبة.'], 422);
        }
        $v = $pdo->prepare('SELECT id FROM size_families WHERE id = ? LIMIT 1');
        $v->execute([$consumer]);
        if ((int) $v->fetchColumn() <= 0) {
            json_response(['success' => false, 'message' => 'عائلة المستهلك غير موجودة.'], 422);
        }
        $v2 = $pdo->prepare('SELECT id, source_size_family_id FROM advisory_sizing_library_bundles WHERE id = ? LIMIT 1');
        $v2->execute([$bundleId]);
        $br = $v2->fetch(PDO::FETCH_ASSOC);
        if (!is_array($br)) {
            json_response(['success' => false, 'message' => 'الحزمة غير موجودة.'], 422);
        }
        if ((int) ($br['source_size_family_id'] ?? 0) === $consumer) {
            json_response(['success' => false, 'message' => 'عائلة المستهلك لا يمكن أن تكون نفس عائلة مصدر الحزمة.'], 422);
        }
        $pdo->prepare(
            'INSERT INTO size_family_advisory_library_map (consumer_size_family_id, library_bundle_id)
             VALUES (?,?)
             ON DUPLICATE KEY UPDATE library_bundle_id = VALUES(library_bundle_id), updated_at = CURRENT_TIMESTAMP'
        )->execute([$consumer, $bundleId]);
        json_response(['success' => true]);
    }

    if ($action === 'delete_map') {
        $consumer = (int) ($data['consumer_size_family_id'] ?? 0);
        if ($consumer <= 0) {
            json_response(['success' => false, 'message' => 'معرّف عائلة غير صالح.'], 422);
        }
        $pdo->prepare('DELETE FROM size_family_advisory_library_map WHERE consumer_size_family_id = ?')->execute([$consumer]);
        json_response(['success' => true]);
    }

    if ($action === 'sync_consumer') {
        $consumer = (int) ($data['consumer_size_family_id'] ?? 0);
        $err = orange_advisory_sizing_library_sync_mapped_consumer($pdo, $consumer);
        if ($err !== null) {
            json_response(['success' => false, 'message' => $err], 422);
        }
        json_response(['success' => true, 'message' => 'تمت المزامنة: نُسخت الأدلة من عائلة مصدر الحزمة إلى عائلة المستهلك.']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف.'], 400);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] advisory_sizing_library/manage: ' . $e->getMessage());
    }
    json_response(['success' => false, 'message' => 'خطأ داخلي.'], 500);
}
