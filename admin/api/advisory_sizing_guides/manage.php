<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/advisory_sizing_guides.php';
require_admin_api();

/**
 * @param mixed $raw
 */
function orange_advisory_scope_kind_valid($raw): ?string
{
    $s = strtolower(trim((string) $raw));
    $ok = ['upper', 'lower', 'single'];

    return in_array($s, $ok, true) ? $s : null;
}

/**
 * @param mixed $raw
 */
function orange_advisory_row_kind_valid($raw): string
{
    $s = strtolower(trim((string) $raw));

    return $s === 'label' ? 'label' : 'data';
}

/**
 * @param mixed $raw
 */
function orange_advisory_value_kind_valid($raw): string
{
    $s = strtolower(trim((string) $raw));

    return $s === 'number' ? 'number' : 'text';
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'advisory_sizing_guides')) {
        json_response(['success' => false, 'message' => 'جداول دليل المقاس الاسترشادي غير جاهزة؛ حدّث الصفحة.'], 503);
    }

    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    switch ($action) {
        case 'list_family_sizes':
            $fid = (int) ($data['size_family_id'] ?? 0);
            if ($fid <= 0 || !orange_table_exists($pdo, 'size_family_sizes')) {
                json_response(['success' => false, 'message' => 'عائلة غير صالحة'], 422);
            }
            $st = $pdo->prepare(
                'SELECT id, label_ar, label_en, sort_order
                 FROM size_family_sizes
                 WHERE size_family_id = ? AND is_active = 1
                 ORDER BY sort_order ASC, id ASC'
            );
            $st->execute([$fid]);
            $sz = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            json_response(['success' => true, 'sizes' => $sz]);

        case 'list_by_family':
            $fid = (int) ($data['size_family_id'] ?? 0);
            if ($fid <= 0) {
                json_response(['success' => false, 'message' => 'اختر عائلة مقاسات'], 422);
            }
            $st = $pdo->prepare(
                'SELECT g.id, g.scope_kind, g.name_ar, g.name_en, g.is_active,
                    (SELECT COUNT(*) FROM advisory_sizing_guide_columns c WHERE c.guide_id = g.id) AS columns_count,
                    (SELECT COUNT(*) FROM advisory_sizing_guide_rows r WHERE r.guide_id = g.id) AS rows_count
                 FROM advisory_sizing_guides g
                 WHERE g.size_family_id = ?
                 ORDER BY CASE g.scope_kind WHEN \'upper\' THEN 1 WHEN \'lower\' THEN 2 WHEN \'single\' THEN 3 ELSE 9 END, g.id ASC'
            );
            $st->execute([$fid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            json_response(['success' => true, 'guides' => is_array($rows) ? $rows : []]);

        case 'get':
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                json_response(['success' => false, 'message' => 'معرّف غير صالح'], 422);
            }
            $st = $pdo->prepare('SELECT * FROM advisory_sizing_guides WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $guide = $st->fetch(PDO::FETCH_ASSOC);
            if (!$guide) {
                json_response(['success' => false, 'message' => 'غير موجود'], 404);
            }
            $gid = (int) $guide['id'];
            $cols = $pdo->prepare(
                'SELECT id, sort_order, label_ar, label_en, label_fil, label_hi, value_kind, unit_hint, storage_measure, display_system
                 FROM advisory_sizing_guide_columns WHERE guide_id = ? ORDER BY sort_order ASC, id ASC'
            );
            $cols->execute([$gid]);
            $columns = $cols->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rws = $pdo->prepare(
                'SELECT id, sort_order, row_kind, size_family_size_id, label_ar, label_en, label_fil, label_hi
                 FROM advisory_sizing_guide_rows WHERE guide_id = ? ORDER BY sort_order ASC, id ASC'
            );
            $rws->execute([$gid]);
            $rows = $rws->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rowIds = [];
            foreach ($rows as $rw) {
                if (is_array($rw) && isset($rw['id'])) {
                    $rowIds[] = (int) $rw['id'];
                }
            }
            $cellsByRow = [];
            if ($rowIds !== []) {
                $in = implode(',', array_fill(0, count($rowIds), '?'));
                $cst = $pdo->prepare(
                    "SELECT row_id, column_id, cell_value FROM advisory_sizing_guide_cells WHERE row_id IN ($in)"
                );
                $cst->execute($rowIds);
                while ($c = $cst->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $rid = (int) ($c['row_id'] ?? 0);
                    $cid = (int) ($c['column_id'] ?? 0);
                    if ($rid <= 0 || $cid <= 0) {
                        continue;
                    }
                    if (!isset($cellsByRow[$rid])) {
                        $cellsByRow[$rid] = [];
                    }
                    $cellsByRow[$rid][$cid] = (string) ($c['cell_value'] ?? '');
                }
            }
            $outRows = [];
            foreach ($rows as $rw) {
                if (!is_array($rw) || !isset($rw['id'])) {
                    continue;
                }
                $rid = (int) $rw['id'];
                $rk = orange_advisory_row_kind_valid($rw['row_kind'] ?? 'data');
                $cells = [];
                if ($rk === 'data') {
                    foreach ($columns as $col) {
                        if (!is_array($col) || !isset($col['id'])) {
                            continue;
                        }
                        $cid = (int) $col['id'];
                        $cells[] = $cellsByRow[$rid][$cid] ?? '';
                    }
                }
                $outRows[] = [
                    'id' => $rid,
                    'sort_order' => (int) ($rw['sort_order'] ?? 0),
                    'row_kind' => $rk,
                    'size_family_size_id' => (int) ($rw['size_family_size_id'] ?? 0),
                    'label_ar' => (string) ($rw['label_ar'] ?? ''),
                    'label_en' => (string) ($rw['label_en'] ?? ''),
                    'label_fil' => (string) ($rw['label_fil'] ?? ''),
                    'label_hi' => (string) ($rw['label_hi'] ?? ''),
                    'cells' => $cells,
                ];
            }

            json_response([
                'success' => true,
                'guide' => $guide,
                'columns' => $columns,
                'rows' => $outRows,
            ]);

        case 'delete':
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                json_response(['success' => false, 'message' => 'معرّف غير صالح'], 422);
            }
            $pdo->beginTransaction();
            try {
                $stR = $pdo->prepare('SELECT id FROM advisory_sizing_guide_rows WHERE guide_id = ?');
                $stR->execute([$id]);
                $rids = $stR->fetchAll(PDO::FETCH_COLUMN);
                if (is_array($rids) && $rids !== []) {
                    $in = implode(',', array_fill(0, count($rids), '?'));
                    $pdo->prepare("DELETE FROM advisory_sizing_guide_cells WHERE row_id IN ($in)")->execute(array_map('intval', $rids));
                }
                $pdo->prepare('DELETE FROM advisory_sizing_guide_rows WHERE guide_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM advisory_sizing_guide_columns WHERE guide_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM advisory_sizing_guides WHERE id = ?')->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            json_response(['success' => true]);

        case 'save':
            $id = (int) ($data['id'] ?? 0);
            $fid = (int) ($data['size_family_id'] ?? 0);
            $scopeKind = orange_advisory_scope_kind_valid($data['scope_kind'] ?? '');
            if ($fid <= 0 || $scopeKind === null) {
                json_response(['success' => false, 'message' => 'عائلة المقاسات ونوع النموذج (علوي/سفلي/مفرد) إلزاميان'], 422);
            }
            $chk = $pdo->prepare('SELECT id FROM size_families WHERE id = ? LIMIT 1');
            $chk->execute([$fid]);
            if (!$chk->fetchColumn()) {
                json_response(['success' => false, 'message' => 'عائلة المقاسات غير موجودة'], 422);
            }
            $nameAr = trim((string) ($data['name_ar'] ?? ''));
            if ($nameAr === '') {
                json_response(['success' => false, 'message' => 'اسم داخلي للنموذج (عربي) إلزامي — للأدمن فقط ولا يُعرض للعميل'], 422);
            }
            $nameEn = '';
            $nameFil = '';
            $nameHi = '';
            $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;
            $columnsIn = $data['columns'] ?? [];
            $rowsIn = $data['rows'] ?? [];
            if (!is_array($columnsIn) || !is_array($rowsIn)) {
                json_response(['success' => false, 'message' => 'بيانات أعمدة/صفوف غير صالحة'], 422);
            }
            $normCols = [];
            $so = 0;
            foreach ($columnsIn as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $la = trim((string) ($c['label_ar'] ?? ''));
                $le = trim((string) ($c['label_en'] ?? ''));
                if ($la === '' && $le === '') {
                    continue;
                }
                ++$so;
                $stMeas = orange_advisory_normalize_storage_measure((string) ($c['storage_measure'] ?? ''));
                $dispSys = orange_advisory_normalize_display_system((string) ($c['display_system'] ?? ''));
                $vk = orange_advisory_value_kind_valid($c['value_kind'] ?? 'text');
                if ($stMeas === 'length_cm') {
                    $vk = 'number';
                }
                $normCols[] = [
                    'label_ar' => $la !== '' ? $la : $le,
                    'label_en' => $le !== '' ? $le : $la,
                    'label_fil' => trim((string) ($c['label_fil'] ?? '')),
                    'label_hi' => trim((string) ($c['label_hi'] ?? '')),
                    'value_kind' => $vk,
                    'unit_hint' => trim((string) ($c['unit_hint'] ?? '')),
                    'storage_measure' => $stMeas,
                    'display_system' => $dispSys,
                    'sort_order' => (int) ($c['sort_order'] ?? 0) > 0 ? (int) $c['sort_order'] : $so,
                ];
            }
            if ($normCols === []) {
                json_response(['success' => false, 'message' => 'أضف عموداً واحداً على الأقل بعناوين'], 422);
            }
            $normRows = [];
            $rso = 0;
            foreach ($rowsIn as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $rk = orange_advisory_row_kind_valid($r['row_kind'] ?? 'data');
                ++$rso;
                $sfsId = (int) ($r['size_family_size_id'] ?? 0);
                if ($sfsId > 0) {
                    $v = $pdo->prepare('SELECT id FROM size_family_sizes WHERE id = ? AND size_family_id = ? LIMIT 1');
                    $v->execute([$sfsId, $fid]);
                    if (!$v->fetchColumn()) {
                        json_response(['success' => false, 'message' => 'مقاس مرتبط غير تابع لنفس العائلة'], 422);
                    }
                } elseif ($rk === 'data') {
                    $sfsId = 0;
                }
                $cells = $r['cells'] ?? [];
                if (!is_array($cells)) {
                    $cells = [];
                }
                if ($rk === 'data' && count($cells) < count($normCols)) {
                    $cells = array_pad($cells, count($normCols), '');
                }
                if ($rk === 'data' && count($cells) > count($normCols)) {
                    $cells = array_slice($cells, 0, count($normCols));
                }
                if ($rk === 'data' && $sfsId > 0 && $cells !== []) {
                    $cells[0] = '';
                }
                $normRows[] = [
                    'row_kind' => $rk,
                    'sort_order' => (int) ($r['sort_order'] ?? 0) > 0 ? (int) $r['sort_order'] : $rso,
                    'size_family_size_id' => $rk === 'data' ? $sfsId : 0,
                    'label_ar' => trim((string) ($r['label_ar'] ?? '')),
                    'label_en' => trim((string) ($r['label_en'] ?? '')),
                    'label_fil' => trim((string) ($r['label_fil'] ?? '')),
                    'label_hi' => trim((string) ($r['label_hi'] ?? '')),
                    'cells' => $rk === 'data' ? $cells : [],
                ];
            }
            if ($normRows === []) {
                json_response(['success' => false, 'message' => 'أضف صفاً واحداً على الأقل'], 422);
            }
            $hasDataRow = false;
            $seenFamilySize = [];
            foreach ($normRows as $nr) {
                if (($nr['row_kind'] ?? '') === 'data') {
                    $hasDataRow = true;
                    $sfsData = (int) ($nr['size_family_size_id'] ?? 0);
                    if ($sfsData <= 0) {
                        json_response(['success' => false, 'message' => 'كل صف بيانات يجب ربطه بمقاس من عائلة المقاسات (لا يُقبل صف بيانات بدون مقاس)'], 422);
                    }
                    if (isset($seenFamilySize[$sfsData])) {
                        json_response(['success' => false, 'message' => 'مقاس العائلة مكرر في أكثر من صف بيانات — اربط كل مقاس مرة واحدة'], 422);
                    }
                    $seenFamilySize[$sfsData] = true;
                }
            }
            if (!$hasDataRow) {
                json_response(['success' => false, 'message' => 'أضف صف بيانات واحداً على الأقل مربوطاً بمقاس من العائلة'], 422);
            }
            foreach ($normRows as $nr) {
                if ($nr['row_kind'] === 'label' && trim($nr['label_ar']) === '' && trim($nr['label_en']) === '') {
                    json_response(['success' => false, 'message' => 'كل صف عنوان يجب أن يحتوي على نص عربي أو English'], 422);
                }
            }

            $pdo->beginTransaction();
            try {
                if ($id <= 0) {
                    $dup = $pdo->prepare(
                        'SELECT id FROM advisory_sizing_guides WHERE size_family_id = ? AND scope_kind = ? LIMIT 1'
                    );
                    $dup->execute([$fid, $scopeKind]);
                    if ($dup->fetchColumn()) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'يوجد بالفعل دليل لنفس العائلة ونفس النوع (علوي/سفلي/مفرد)'], 409);
                    }
                    $ins = $pdo->prepare(
                        'INSERT INTO advisory_sizing_guides
                            (size_family_id, scope_kind, name_ar, name_en, name_fil, name_hi, sort_order, is_active)
                         VALUES (?,?,?,?,?,?,0,?)'
                    );
                    $ins->execute([$fid, $scopeKind, $nameAr, $nameEn, $nameFil, $nameHi, $active]);
                    $id = (int) $pdo->lastInsertId();
                } else {
                    $own = $pdo->prepare('SELECT id, size_family_id, scope_kind FROM advisory_sizing_guides WHERE id = ? LIMIT 1');
                    $own->execute([$id]);
                    $ex = $own->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($ex)) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'غير موجود'], 404);
                    }
                    if ((int) $ex['size_family_id'] !== $fid) {
                        $pdo->rollBack();
                        json_response(['success' => false, 'message' => 'لا يمكن تغيير عائلة المقاسات للسجل الحالي'], 422);
                    }
                    if ((string) $ex['scope_kind'] !== $scopeKind) {
                        $dup = $pdo->prepare(
                            'SELECT id FROM advisory_sizing_guides WHERE size_family_id = ? AND scope_kind = ? AND id <> ? LIMIT 1'
                        );
                        $dup->execute([$fid, $scopeKind, $id]);
                        if ($dup->fetchColumn()) {
                            $pdo->rollBack();
                            json_response(['success' => false, 'message' => 'نوع النموذج يتعارض مع سجل آخر لنفس العائلة'], 409);
                        }
                    }
                    $upd = $pdo->prepare(
                        'UPDATE advisory_sizing_guides SET
                            scope_kind = ?, name_ar = ?, name_en = ?, name_fil = ?, name_hi = ?, is_active = ?
                         WHERE id = ? AND size_family_id = ?'
                    );
                    $upd->execute([$scopeKind, $nameAr, $nameEn, $nameFil, $nameHi, $active, $id, $fid]);
                    $stR2 = $pdo->prepare('SELECT id FROM advisory_sizing_guide_rows WHERE guide_id = ?');
                    $stR2->execute([$id]);
                    $rids2 = $stR2->fetchAll(PDO::FETCH_COLUMN);
                    if (is_array($rids2) && $rids2 !== []) {
                        $in2 = implode(',', array_fill(0, count($rids2), '?'));
                        $pdo->prepare("DELETE FROM advisory_sizing_guide_cells WHERE row_id IN ($in2)")->execute(array_map('intval', $rids2));
                    }
                    $pdo->prepare('DELETE FROM advisory_sizing_guide_rows WHERE guide_id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM advisory_sizing_guide_columns WHERE guide_id = ?')->execute([$id]);
                }

                $colIdMap = [];
                foreach ($normCols as $nc) {
                    $ic = $pdo->prepare(
                        'INSERT INTO advisory_sizing_guide_columns
                            (guide_id, sort_order, label_ar, label_en, label_fil, label_hi, value_kind, unit_hint, storage_measure, display_system)
                         VALUES (?,?,?,?,?,?,?,?,?,?)'
                    );
                    $ic->execute([
                        $id,
                        (int) $nc['sort_order'],
                        $nc['label_ar'],
                        $nc['label_en'],
                        $nc['label_fil'],
                        $nc['label_hi'],
                        $nc['value_kind'],
                        $nc['unit_hint'],
                        $nc['storage_measure'],
                        $nc['display_system'],
                    ]);
                    $colIdMap[] = (int) $pdo->lastInsertId();
                }

                foreach ($normRows as $nr) {
                    $ir = $pdo->prepare(
                        'INSERT INTO advisory_sizing_guide_rows
                            (guide_id, sort_order, row_kind, size_family_size_id, label_ar, label_en, label_fil, label_hi)
                         VALUES (?,?,?,?,?,?,?,?)'
                    );
                    $sfsIns = $nr['row_kind'] === 'data' ? (int) $nr['size_family_size_id'] : 0;
                    if ($sfsIns <= 0) {
                        $sfsIns = null;
                    }
                    $ir->execute([
                        $id,
                        (int) $nr['sort_order'],
                        $nr['row_kind'],
                        $sfsIns,
                        $nr['label_ar'],
                        $nr['label_en'],
                        $nr['label_fil'],
                        $nr['label_hi'],
                    ]);
                    $rid = (int) $pdo->lastInsertId();
                    if ($nr['row_kind'] === 'data') {
                        foreach ($colIdMap as $ix => $cid) {
                            $val = (string) ($nr['cells'][$ix] ?? '');
                            $pdo->prepare(
                                'INSERT INTO advisory_sizing_guide_cells (row_id, column_id, cell_value) VALUES (?,?,?)'
                            )->execute([$rid, $cid, $val]);
                        }
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                if (function_exists('error_log')) {
                    error_log('[orange] advisory_sizing save: ' . $e->getMessage());
                }
                json_response(['success' => false, 'message' => 'فشل الحفظ'], 500);
            }

            json_response(['success' => true, 'id' => $id]);

        default:
            json_response(['success' => false, 'message' => 'إجراء غير معروف'], 400);
    }
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] advisory_sizing_guides/manage: ' . $e->getMessage());
    }
    json_response(['success' => false, 'message' => 'خطأ داخلي'], 500);
}
