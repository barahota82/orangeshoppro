<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'size_scheme_templates')
        || !orange_table_exists($pdo, 'size_scheme_template_sizes')
    ) {
        json_response(['success' => false, 'message' => 'جداول قوالب المقاسات غير جاهزة؛ حدّث المخطّط بزيارة الأدمن.'], 503);
    }

    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    switch ($action) {
        case 'list':
            $rows = $pdo->query(
                'SELECT t.id, t.name_ar, t.name_en, t.sort_order, t.is_active,
                    (SELECT COUNT(*) FROM size_scheme_template_sizes s WHERE s.template_id = t.id) AS sizes_count
                 FROM size_scheme_templates t
                 ORDER BY t.sort_order ASC, t.id ASC'
            )->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'templates' => is_array($rows) ? $rows : []]);

        case 'get':
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                json_response(['success' => false, 'message' => 'معرّف القالب غير صالح'], 422);
            }
            $st = $pdo->prepare('SELECT * FROM size_scheme_templates WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $tpl = $st->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) {
                json_response(['success' => false, 'message' => 'القالب غير موجود'], 404);
            }
            $st2 = $pdo->prepare(
                'SELECT id, label_ar, label_en, label_fil, label_hi, sort_order, foot_length_cm
                 FROM size_scheme_template_sizes
                 WHERE template_id = ?
                 ORDER BY sort_order ASC, id ASC'
            );
            $st2->execute([$id]);
            $sizes = $st2->fetchAll(PDO::FETCH_ASSOC);
            json_response([
                'success' => true,
                'template' => $tpl,
                'sizes' => is_array($sizes) ? $sizes : [],
            ]);

        case 'save':
            $id = (int) ($data['id'] ?? 0);
            $nameAr = trim((string) ($data['name_ar'] ?? ''));
            $nameEn = trim((string) ($data['name_en'] ?? ''));
            $nameFil = trim((string) ($data['name_fil'] ?? ''));
            $nameHi = trim((string) ($data['name_hi'] ?? ''));
            $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;
            $sizesIn = $data['sizes'] ?? [];
            if (!is_array($sizesIn)) {
                $sizesIn = [];
            }
            if ($nameAr === '' || $nameEn === '') {
                json_response(['success' => false, 'message' => 'عبّئ اسم القالب عربي وEnglish'], 422);
            }

            $normalized = [];
            foreach ($sizesIn as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $la = trim((string) ($row['label_ar'] ?? ''));
                $le = trim((string) ($row['label_en'] ?? ''));
                if ($la === '' && $le === '') {
                    continue;
                }
                if ($la === '') {
                    json_response(['success' => false, 'message' => 'كل صف في القالب يجب أن يحتوي على تسمية عربية للمقاس'], 422);
                }
                $lf = trim((string) ($row['label_fil'] ?? ''));
                $lh = trim((string) ($row['label_hi'] ?? ''));
                $so = (int) ($row['sort_order'] ?? 0);
                $flRaw = $row['foot_length_cm'] ?? null;
                $fl = null;
                if ($flRaw !== null && $flRaw !== '') {
                    $fl = is_numeric($flRaw) ? (float) $flRaw : null;
                }
                $rowTplSizeId = (int) ($row['id'] ?? 0);
                $normalized[] = [
                    'id' => $rowTplSizeId,
                    'label_ar' => $la,
                    'label_en' => $le,
                    'label_fil' => $lf,
                    'label_hi' => $lh,
                    'sort_order' => $so,
                    'foot_length_cm' => $fl,
                ];
            }

            if ($normalized === []) {
                json_response(['success' => false, 'message' => 'أضف صف مقاس واحد على الأقل داخل القالب'], 422);
            }

            $pdo->beginTransaction();
            try {
                if ($id > 0) {
                    $tplId = $id;
                    $chk = $pdo->prepare('SELECT id FROM size_scheme_templates WHERE id = ? LIMIT 1');
                    $chk->execute([$tplId]);
                    if (!$chk->fetch()) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'القالب غير موجود'], 404);
                    }
                    $pdo->prepare(
                        'UPDATE size_scheme_templates SET name_ar=?, name_en=?, name_fil=?, name_hi=?, is_active=? WHERE id=? LIMIT 1'
                    )->execute([$nameAr, $nameEn, $nameFil, $nameHi, $active, $tplId]);
                } else {
                    $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM size_scheme_templates')->fetchColumn();
                    if ($sort <= 0) {
                        $sort = 1;
                    }
                    $pdo->prepare(
                        'INSERT INTO size_scheme_templates (name_ar, name_en, name_fil, name_hi, sort_order, is_active) VALUES (?,?,?,?,?,?)'
                    )->execute([$nameAr, $nameEn, $nameFil, $nameHi, $sort, $active]);
                    $tplId = (int) $pdo->lastInsertId();
                }

                $stOld = $pdo->prepare('SELECT id FROM size_scheme_template_sizes WHERE template_id = ?');
                $stOld->execute([$tplId]);
                $oldTplSizeIds = array_map(
                    static function ($v) {
                        return (int) $v;
                    },
                    array_column($stOld->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id')
                );

                $insTplSz = $pdo->prepare(
                    'INSERT INTO size_scheme_template_sizes
                        (template_id, label_ar, label_en, label_fil, label_hi, sort_order, foot_length_cm, is_active)
                     VALUES (?,?,?,?,?,?,?,1)'
                );
                $updTplSz = $pdo->prepare(
                    'UPDATE size_scheme_template_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=?, foot_length_cm=?
                     WHERE id=? AND template_id=? LIMIT 1'
                );

                $keptTplSizeIds = [];
                foreach ($normalized as $i => $r) {
                    $so = $r['sort_order'] > 0 ? $r['sort_order'] : ($i + 1);
                    $rid = (int) ($r['id'] ?? 0);
                    if ($rid > 0 && in_array($rid, $oldTplSizeIds, true)) {
                        $updTplSz->execute([
                            $r['label_ar'],
                            $r['label_en'],
                            $r['label_fil'],
                            $r['label_hi'],
                            $so,
                            $r['foot_length_cm'],
                            $rid,
                            $tplId,
                        ]);
                        $keptTplSizeIds[] = $rid;
                    } else {
                        $insTplSz->execute([
                            $tplId,
                            $r['label_ar'],
                            $r['label_en'],
                            $r['label_fil'],
                            $r['label_hi'],
                            $so,
                            $r['foot_length_cm'],
                        ]);
                        $keptTplSizeIds[] = (int) $pdo->lastInsertId();
                    }
                }

                $removedTplSizeIds = array_values(array_diff($oldTplSizeIds, $keptTplSizeIds));
                if ($removedTplSizeIds !== []) {
                    $hasFamLink = orange_table_exists($pdo, 'size_family_sizes')
                        && orange_table_has_column($pdo, 'size_family_sizes', 'scheme_template_size_id');
                    if ($hasFamLink) {
                        $phR = implode(',', array_fill(0, count($removedTplSizeIds), '?'));
                        $pdo->prepare(
                            "UPDATE size_family_sizes SET scheme_template_size_id = NULL WHERE scheme_template_size_id IN ($phR)"
                        )->execute($removedTplSizeIds);
                    }
                    $phD = implode(',', array_fill(0, count($removedTplSizeIds), '?'));
                    $pdo->prepare(
                        "DELETE FROM size_scheme_template_sizes WHERE template_id = ? AND id IN ($phD)"
                    )->execute(array_merge([$tplId], $removedTplSizeIds));
                }

                $hasFamLink = orange_table_exists($pdo, 'size_family_sizes')
                    && orange_table_has_column($pdo, 'size_family_sizes', 'scheme_template_size_id');
                if ($hasFamLink && $keptTplSizeIds !== []) {
                    $phK = implode(',', array_fill(0, count($keptTplSizeIds), '?'));
                    $sqlSync = 'UPDATE size_family_sizes sfs
                        INNER JOIN size_scheme_template_sizes tst ON tst.id = sfs.scheme_template_size_id
                        SET sfs.label_ar = tst.label_ar, sfs.label_en = tst.label_en, sfs.label_fil = tst.label_fil,
                            sfs.label_hi = tst.label_hi, sfs.sort_order = tst.sort_order, sfs.foot_length_cm = tst.foot_length_cm
                        WHERE tst.template_id = ? AND sfs.scheme_template_size_id IN (' . $phK . ')';
                    $pdo->prepare($sqlSync)->execute(array_merge([$tplId], $keptTplSizeIds));
                }

                $hasFamTplRef = orange_table_exists($pdo, 'size_families')
                    && orange_table_has_column($pdo, 'size_families', 'size_scheme_template_id');
                if ($hasFamLink && $hasFamTplRef) {
                    $sqlBack = 'UPDATE size_family_sizes sfs
                        INNER JOIN size_families fam ON fam.id = sfs.size_family_id AND fam.size_scheme_template_id = ?
                        INNER JOIN size_scheme_template_sizes tst ON tst.template_id = ? AND tst.sort_order = sfs.sort_order
                        SET sfs.label_ar = tst.label_ar, sfs.label_en = tst.label_en, sfs.label_fil = tst.label_fil,
                            sfs.label_hi = tst.label_hi, sfs.foot_length_cm = tst.foot_length_cm, sfs.scheme_template_size_id = tst.id
                        WHERE sfs.scheme_template_size_id IS NULL';
                    $pdo->prepare($sqlBack)->execute([$tplId, $tplId]);
                }

                /*
                 * مزامنة بالترتيب (الصف N في القالب ↔ الصف N في العائلة): تعالج حالات
                 * scheme_template_size_id = NULL أو اختلاف sort_order بين العائلة والقالب بعد التعديل من شاشة القالب.
                 */
                if ($hasFamLink && $hasFamTplRef) {
                    $tplRowsOrd = $pdo->prepare(
                        'SELECT id, label_ar, label_en, label_fil, label_hi, sort_order, foot_length_cm
                         FROM size_scheme_template_sizes WHERE template_id = ? ORDER BY sort_order ASC, id ASC'
                    );
                    $tplRowsOrd->execute([$tplId]);
                    $tplRowsList = $tplRowsOrd->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    if ($tplRowsList !== []) {
                        $famIdsStmt = $pdo->prepare('SELECT id FROM size_families WHERE size_scheme_template_id = ?');
                        $famIdsStmt->execute([$tplId]);
                        foreach ($famIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $syncFamId) {
                            $syncFamId = (int) $syncFamId;
                            if ($syncFamId <= 0) {
                                continue;
                            }
                            $sfsOrdStmt = $pdo->prepare(
                                'SELECT id FROM size_family_sizes WHERE size_family_id = ? ORDER BY sort_order ASC, id ASC'
                            );
                            $sfsOrdStmt->execute([$syncFamId]);
                            $famSizeIdsList = array_map(
                                static function ($v) {
                                    return (int) $v;
                                },
                                array_column($sfsOrdStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id')
                            );
                            $nPair = min(count($tplRowsList), count($famSizeIdsList));
                            $ordUp = $pdo->prepare(
                                'UPDATE size_family_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=?, foot_length_cm=?, scheme_template_size_id=? WHERE id=? AND size_family_id=? LIMIT 1'
                            );
                            for ($pi = 0; $pi < $nPair; $pi++) {
                                $t = $tplRowsList[$pi];
                                $tstIdRow = (int) ($t['id'] ?? 0);
                                $fSid = $famSizeIdsList[$pi];
                                if ($tstIdRow <= 0 || $fSid <= 0) {
                                    continue;
                                }
                                $soT = (int) ($t['sort_order'] ?? ($pi + 1));
                                $footT = $t['foot_length_cm'] ?? null;
                                $ordUp->execute([
                                    (string) ($t['label_ar'] ?? ''),
                                    (string) ($t['label_en'] ?? ''),
                                    (string) ($t['label_fil'] ?? ''),
                                    (string) ($t['label_hi'] ?? ''),
                                    $soT,
                                    $footT,
                                    $tstIdRow,
                                    $fSid,
                                    $syncFamId,
                                ]);
                            }
                        }
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            json_response(['success' => true, 'id' => $tplId]);

        case 'delete':
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                json_response(['success' => false, 'message' => 'معرّف القالب غير صالح'], 422);
            }
            if (orange_table_exists($pdo, 'size_families')
                && orange_table_has_column($pdo, 'size_families', 'size_scheme_template_id')) {
                $pdo->prepare('UPDATE size_families SET size_scheme_template_id = NULL WHERE size_scheme_template_id = ?')->execute([$id]);
            }
            $stDel = $pdo->prepare('SELECT id FROM size_scheme_template_sizes WHERE template_id = ?');
            $stDel->execute([$id]);
            $delTstIds = array_map(
                static function ($v) {
                    return (int) $v;
                },
                array_column($stDel->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id')
            );
            if ($delTstIds !== [] && orange_table_exists($pdo, 'size_family_sizes')
                && orange_table_has_column($pdo, 'size_family_sizes', 'scheme_template_size_id')) {
                $phDel = implode(',', array_fill(0, count($delTstIds), '?'));
                $pdo->prepare(
                    "UPDATE size_family_sizes SET scheme_template_size_id = NULL WHERE scheme_template_size_id IN ($phDel)"
                )->execute($delTstIds);
            }
            $pdo->prepare('DELETE FROM size_scheme_template_sizes WHERE template_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM size_scheme_templates WHERE id = ? LIMIT 1')->execute([$id]);
            json_response(['success' => true]);

        default:
            json_response(['success' => false, 'message' => 'إجراء غير معروف'], 400);
    }
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنفيذ طلب قوالب المقاسات');
}
