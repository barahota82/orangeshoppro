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
        /* تهيئة تلقائية: إن لم تُحفَظ حزم يدوياً، أنشئ/حدّث حزمة سياق (1–2–3) من أول اسم نموذج داخلي للمسودات غير المربوطة بعائلة. */
        if (orange_table_exists($pdo, 'advisory_sizing_guides')) {
            try {
                $draftRows = $pdo->query(
                    'SELECT department_id, size_scheme_template_id, commercial_kind_key, name_ar
                     FROM advisory_sizing_guides
                     WHERE (size_family_id IS NULL OR size_family_id = 0)
                       AND COALESCE(department_id, 0) > 0
                       AND COALESCE(size_scheme_template_id, 0) > 0
                       AND commercial_kind_key <> \'\'
                     ORDER BY COALESCE(department_id, 0) ASC, COALESCE(size_scheme_template_id, 0) ASC,
                              commercial_kind_key ASC, sort_order ASC, id ASC'
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $ctxFirst = [];
                foreach ($draftRows as $dr) {
                    $dept = (int) ($dr['department_id'] ?? 0);
                    $tpl = (int) ($dr['size_scheme_template_id'] ?? 0);
                    $ckx = trim((string) ($dr['commercial_kind_key'] ?? ''));
                    if ($dept <= 0 || $tpl <= 0 || $ckx === '') {
                        continue;
                    }
                    $ctxKey = $dept . '|' . $tpl . '|' . $ckx;
                    if (!isset($ctxFirst[$ctxKey])) {
                        $ctxFirst[$ctxKey] = [
                            'department_id' => $dept,
                            'size_scheme_template_id' => $tpl,
                            'commercial_kind_key' => $ckx,
                            'name_ar' => trim((string) ($dr['name_ar'] ?? '')),
                        ];
                    }
                }
                if ($ctxFirst !== []) {
                    $existingRows = $pdo->query(
                        'SELECT id, department_id, size_scheme_template_id, commercial_kind_key, source_size_family_id, name_ar, name_en, is_active
                         FROM advisory_sizing_library_bundles'
                    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $ctxExisting = [];
                    foreach ($existingRows as $er) {
                        $dept = (int) ($er['department_id'] ?? 0);
                        $tpl = (int) ($er['size_scheme_template_id'] ?? 0);
                        $ckx = trim((string) ($er['commercial_kind_key'] ?? ''));
                        if ($dept <= 0 || $tpl <= 0 || $ckx === '') {
                            continue;
                        }
                        $ctxKey = $dept . '|' . $tpl . '|' . $ckx;
                        $srcFam = (int) ($er['source_size_family_id'] ?? 0);
                        if (!isset($ctxExisting[$ctxKey])) {
                            $ctxExisting[$ctxKey] = $er;
                        } elseif ((int) ($ctxExisting[$ctxKey]['source_size_family_id'] ?? 0) <= 0 && $srcFam > 0) {
                            $ctxExisting[$ctxKey] = $er;
                        }
                    }
                    $mxSt = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM advisory_sizing_library_bundles');
                    $nextSort = (int) ($mxSt ? $mxSt->fetchColumn() : 0);
                    $insSeed = $pdo->prepare(
                        'INSERT INTO advisory_sizing_library_bundles
                         (department_id, size_scheme_template_id, name_ar, name_en, commercial_kind_key, source_size_family_id, sort_order, is_active)
                         VALUES (?,?,?,?,?,?,?,?)'
                    );
                    $updSeed = $pdo->prepare(
                        'UPDATE advisory_sizing_library_bundles
                         SET name_ar = ?, name_en = ?, is_active = 1
                         WHERE id = ?'
                    );
                    foreach ($ctxFirst as $ctxKey => $ctx) {
                        if (!isset($ctxExisting[$ctxKey])) {
                            ++$nextSort;
                            $insSeed->execute([
                                (int) $ctx['department_id'],
                                (int) $ctx['size_scheme_template_id'],
                                (string) $ctx['name_ar'],
                                '',
                                (string) $ctx['commercial_kind_key'],
                                0,
                                $nextSort,
                                1,
                            ]);
                            continue;
                        }
                        $ex = $ctxExisting[$ctxKey];
                        $curAr = trim((string) ($ex['name_ar'] ?? ''));
                        $curEn = trim((string) ($ex['name_en'] ?? ''));
                        $newAr = $curAr !== '' ? $curAr : (string) $ctx['name_ar'];
                        $newEn = $curEn;
                        $needUpdate = ($newAr !== $curAr) || ($newEn !== $curEn) || ((int) ($ex['is_active'] ?? 1) === 0);
                        if ($needUpdate) {
                            $updSeed->execute([$newAr, $newEn, (int) $ex['id']]);
                        }
                    }
                }
            } catch (Throwable $seedErr) {
                if (function_exists('error_log')) {
                    error_log('[orange] advisory_sizing_library/list_bundles seed: ' . $seedErr->getMessage());
                }
            }
        }

        /* اسم العرض: مسودات المكتبة (size_family_id فارغ) تُطابق سياق الحزمة 1–2–3، وليس اشتراط ربط الدليل بعائلة المصدر */
        $sql = 'SELECT b.id, b.name_ar, b.name_en, b.commercial_kind_key, b.source_size_family_id, b.sort_order, b.is_active,
                       b.department_id, b.size_scheme_template_id,
                       sf.name_ar AS source_family_ar, sf.name_en AS source_family_en,
                       COALESCE(
                         (SELECT g.name_ar FROM advisory_sizing_guides g
                           WHERE (g.size_family_id IS NULL OR g.size_family_id = 0)
                             AND COALESCE(g.department_id,0) = COALESCE(b.department_id,0)
                             AND COALESCE(g.size_scheme_template_id,0) = COALESCE(b.size_scheme_template_id,0)
                             AND g.commercial_kind_key = b.commercial_kind_key
                           ORDER BY g.sort_order ASC, g.id ASC LIMIT 1),
                         (SELECT g.name_ar FROM advisory_sizing_guides g
                           WHERE g.size_family_id = b.source_size_family_id
                           ORDER BY g.sort_order ASC, g.id ASC LIMIT 1),
                         b.name_ar
                       ) AS first_guide_name_ar,
                       b.name_en AS first_guide_name_en ';
        if (orange_table_exists($pdo, 'departments')) {
            $sql .= ', d.name_ar AS dept_ar, d.name_en AS dept_en ';
        } else {
            $sql .= ', NULL AS dept_ar, NULL AS dept_en ';
        }
        if (orange_table_exists($pdo, 'size_scheme_templates')) {
            $sql .= ', tpl.name_ar AS tpl_ar, tpl.name_en AS tpl_en ';
        } else {
            $sql .= ', NULL AS tpl_ar, NULL AS tpl_en ';
        }
        $sql .= 'FROM advisory_sizing_library_bundles b
                LEFT JOIN size_families sf ON sf.id = b.source_size_family_id ';
        if (orange_table_exists($pdo, 'departments')) {
            $sql .= 'LEFT JOIN departments d ON d.id = b.department_id ';
        }
        if (orange_table_exists($pdo, 'size_scheme_templates')) {
            $sql .= 'LEFT JOIN size_scheme_templates tpl ON tpl.id = b.size_scheme_template_id ';
        }
        $sql .= 'WHERE COALESCE(b.is_active, 1) = 1 ORDER BY b.sort_order ASC, b.id ASC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
        $deptId = (int) ($data['department_id'] ?? 0);
        $tplId = (int) ($data['size_scheme_template_id'] ?? 0);
        $srcFam = (int) ($data['source_size_family_id'] ?? 0);
        $sortClient = (int) ($data['sort_order'] ?? 0);
        $active = (int) ($data['is_active'] ?? 1) === 1 ? 1 : 0;
        if ($nameAr === '' && $nameEn === '') {
            json_response(['success' => false, 'message' => 'أدخل اسماً عربياً أو إنجليزياً للحزمة.'], 422);
        }
        if ($deptId <= 0) {
            json_response(['success' => false, 'message' => 'اختر القسم الرئيسي (الخطوة 1).'], 422);
        }
        if ($tplId <= 0) {
            json_response(['success' => false, 'message' => 'اختر قالب المقاسات (الخطوة 2).'], 422);
        }
        if ($ck === '') {
            json_response(['success' => false, 'message' => 'اختر النوع التجاري — مستوى 1 (الخطوة 3).'], 422);
        }
        if ($srcFam <= 0) {
            json_response(['success' => false, 'message' => 'اختر عائلة المصدر (الخطوة 4) حيث تُصمَّم الأدلة.'], 422);
        }
        if (orange_table_exists($pdo, 'departments')) {
            $dSt = $pdo->prepare('SELECT id FROM departments WHERE id = ? AND is_active = 1 LIMIT 1');
            $dSt->execute([$deptId]);
            if ((int) $dSt->fetchColumn() <= 0) {
                json_response(['success' => false, 'message' => 'القسم الرئيسي غير موجود أو غير نشط.'], 422);
            }
        }
        if (orange_table_exists($pdo, 'size_scheme_templates')) {
            $tSt = $pdo->prepare('SELECT id FROM size_scheme_templates WHERE id = ? AND is_active = 1 LIMIT 1');
            $tSt->execute([$tplId]);
            if ((int) $tSt->fetchColumn() <= 0) {
                json_response(['success' => false, 'message' => 'قالب المقاسات غير موجود أو غير نشط.'], 422);
            }
        }
        if (orange_table_exists($pdo, 'commercial_kind_dictionary')) {
            $kSt = $pdo->prepare('SELECT kind_key FROM commercial_kind_dictionary WHERE kind_key = ? AND is_active = 1 LIMIT 1');
            $kSt->execute([$ck]);
            if ($kSt->fetchColumn() === false) {
                json_response(['success' => false, 'message' => 'مفتاح النوع التجاري غير معرّف في القاموس أو غير نشط.'], 422);
            }
        }
        $v = $pdo->prepare('SELECT id FROM size_families WHERE id = ? LIMIT 1');
        $v->execute([$srcFam]);
        if ((int) $v->fetchColumn() <= 0) {
            json_response(['success' => false, 'message' => 'عائلة المصدر غير موجودة.'], 422);
        }
        $bundleProbe = [
            'commercial_kind_key' => $ck,
            'size_scheme_template_id' => $tplId,
            'department_id' => $deptId,
        ];
        $align = orange_advisory_sizing_library_validate_size_family_matches_bundle($pdo, $bundleProbe, $srcFam);
        if ($align !== null) {
            json_response(['success' => false, 'message' => $align], 422);
        }
        if ($id > 0) {
            $sort = $sortClient;
            $pdo->prepare(
                'UPDATE advisory_sizing_library_bundles SET
                    department_id = ?, size_scheme_template_id = ?, name_ar = ?, name_en = ?, commercial_kind_key = ?,
                    source_size_family_id = ?, sort_order = ?, is_active = ?
                 WHERE id = ?'
            )->execute([$deptId, $tplId, $nameAr, $nameEn, $ck, $srcFam, $sort, $active, $id]);
            json_response(['success' => true, 'id' => $id]);
        }
        $mxSt = $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM advisory_sizing_library_bundles');
        $sort = (int) ($mxSt ? $mxSt->fetchColumn() : 0) + 1;
        $pdo->prepare(
            'INSERT INTO advisory_sizing_library_bundles
             (department_id, size_scheme_template_id, name_ar, name_en, commercial_kind_key, source_size_family_id, sort_order, is_active)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$deptId, $tplId, $nameAr, $nameEn, $ck, $srcFam, $sort, $active]);
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
                    b.name_ar AS bundle_ar, b.name_en AS bundle_en,
                    COALESCE(
                        (SELECT g.name_ar FROM advisory_sizing_guides g
                         WHERE (g.size_family_id IS NULL OR g.size_family_id = 0)
                           AND COALESCE(g.department_id,0) = COALESCE(b.department_id,0)
                           AND COALESCE(g.size_scheme_template_id,0) = COALESCE(b.size_scheme_template_id,0)
                           AND g.commercial_kind_key = b.commercial_kind_key
                         ORDER BY g.sort_order ASC, g.id ASC LIMIT 1),
                        (SELECT g.name_ar FROM advisory_sizing_guides g
                         WHERE g.size_family_id = b.source_size_family_id
                         ORDER BY g.sort_order ASC, g.id ASC LIMIT 1),
                        b.name_ar,
                        b.name_en
                    ) AS bundle_display_internal
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
        $v2 = $pdo->prepare('SELECT * FROM advisory_sizing_library_bundles WHERE id = ? LIMIT 1');
        $v2->execute([$bundleId]);
        $br = $v2->fetch(PDO::FETCH_ASSOC);
        if (!is_array($br)) {
            json_response(['success' => false, 'message' => 'الحزمة غير موجودة.'], 422);
        }
        if ((int) ($br['source_size_family_id'] ?? 0) === $consumer) {
            json_response(['success' => false, 'message' => 'عائلة المستهلك لا يمكن أن تكون نفس عائلة مصدر الحزمة.'], 422);
        }
        $mapErr = orange_advisory_sizing_library_validate_size_family_matches_bundle($pdo, $br, $consumer);
        if ($mapErr !== null) {
            json_response(['success' => false, 'message' => $mapErr], 422);
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
        json_response(['success' => true, 'message' => 'تمت المزامنة: نُسخت الأدلة من حزمة المكتبة إلى عائلة المستهلك.']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف.'], 400);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] advisory_sizing_library/manage: ' . $e->getMessage());
    }
    json_response(['success' => false, 'message' => 'خطأ داخلي.'], 500);
}
