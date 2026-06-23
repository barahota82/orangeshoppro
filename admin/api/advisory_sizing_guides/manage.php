<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/advisory_sizing_guides.php';
require_admin_api();

/**
 * قرار المالك 2026-06-22 (المرجع الحاكم): نموذج واحد + حفظة واحدة.
 * العمود الفقري: عائلة المقاسات (منها يُستنتَج القالب + النوع التجاري). القسم اختياري لربط أنواع المنتج.
 * شكل الدليل single | dual؛ عند dual يُعلَّم كل عمود/صف علوي/سفلي ويُحفظ في panel_kind.
 * الربط (أنواع منتج / منتجات) يتم في نفس الحفظة.
 */

/** @param mixed $raw */
function orange_advisory_row_kind_valid($raw): string
{
    return strtolower(trim((string) $raw)) === 'label' ? 'label' : 'data';
}

/** @param mixed $raw */
function orange_advisory_value_kind_valid($raw): string
{
    return strtolower(trim((string) $raw)) === 'number' ? 'number' : 'text';
}

/** @param mixed $raw */
function orange_advisory_layout_kind_valid($raw): string
{
    return strtolower(trim((string) $raw)) === 'dual' ? 'dual' : 'single';
}

/** @param mixed $raw */
function orange_advisory_panel_kind_valid($raw, string $layoutKind): string
{
    if ($layoutKind !== 'dual') {
        return 'single';
    }
    $s = strtolower(trim((string) $raw));

    return $s === 'lower' ? 'lower' : 'upper';
}

function orange_advisory_api_rollback_safe(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        // لا نرمي للأعلى
    }
}

function orange_advisory_api_trunc_utf8(string $s, int $maxLen): string
{
    if ($maxLen <= 0 || $s === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_strlen($s, 'UTF-8') <= $maxLen ? $s : mb_substr($s, 0, $maxLen, 'UTF-8');
    }

    return strlen($s) <= $maxLen ? $s : substr($s, 0, $maxLen);
}

/**
 * أعمدة يجب أن تكون جاهزة قبل INSERT/UPDATE الحفظ.
 *
 * @return string|null null عند الجاهزية أو رسالة عربية
 */
function orange_advisory_api_save_schema_ready(PDO $pdo): ?string
{
    $need = [
        ['advisory_sizing_guides', 'layout_kind'],
        ['advisory_sizing_guide_columns', 'panel_kind'],
        ['advisory_sizing_guide_columns', 'label_fil'],
        ['advisory_sizing_guide_columns', 'label_hi'],
        ['advisory_sizing_guide_columns', 'storage_measure'],
        ['advisory_sizing_guide_columns', 'display_system'],
        ['advisory_sizing_guide_rows', 'panel_kind'],
        ['advisory_sizing_guide_rows', 'size_family_size_id'],
        ['advisory_sizing_guide_cells', 'cell_value'],
    ];
    foreach ($need as $pair) {
        orange_schema_invalidate_column_check($pair[0], $pair[1]);
    }
    foreach ($need as $pair) {
        [$t, $c] = $pair;
        if (!orange_table_exists($pdo, $t)) {
            return 'جدول ' . $t . ' غير موجود — حدّث المخطط.';
        }
        if (!orange_table_has_column($pdo, $t, $c)) {
            return 'العمود ' . $t . '.' . $c . ' غير موجود — حدّث المخطط (catalog_schema) على السيرفر.';
        }
    }

    return null;
}

/**
 * @return array{tpl:int, ck:string}|null
 */
function orange_advisory_family_meta(PDO $pdo, int $familyId): ?array
{
    $st = $pdo->prepare('SELECT size_scheme_template_id, commercial_kind_key FROM size_families WHERE id = ? LIMIT 1');
    $st->execute([$familyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'tpl' => (int) ($row['size_scheme_template_id'] ?? 0),
        'ck' => trim((string) ($row['commercial_kind_key'] ?? '')),
    ];
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
        case 'list_by_family':
            $fid = (int) ($data['size_family_id'] ?? 0);
            if ($fid <= 0) {
                json_response(['success' => false, 'message' => 'اختر عائلة مقاسات'], 422);
            }
            $st = $pdo->prepare(
                'SELECT g.id, g.name_ar, g.layout_kind, g.is_active, g.sort_order,
                    (SELECT COUNT(*) FROM advisory_sizing_guide_columns c WHERE c.guide_id = g.id) AS columns_count,
                    (SELECT COUNT(*) FROM advisory_sizing_guide_rows r WHERE r.guide_id = g.id AND r.row_kind = \'data\') AS rows_count,
                    (SELECT COUNT(*) FROM product_types pt WHERE pt.default_advisory_sizing_guide_id = g.id) AS types_count,
                    (SELECT COUNT(*) FROM products p WHERE p.sizing_advisory_guide_id = g.id) AS products_count
                 FROM advisory_sizing_guides g
                 WHERE g.size_family_id = ?
                 ORDER BY g.sort_order ASC, g.id ASC'
            );
            $st->execute([$fid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            json_response(['success' => true, 'guides' => is_array($rows) ? $rows : []]);

        case 'list_link_targets':
            $fid = (int) ($data['size_family_id'] ?? 0);
            $deptId = (int) ($data['department_id'] ?? 0);
            if ($fid <= 0) {
                json_response(['success' => false, 'message' => 'اختر عائلة مقاسات'], 422);
            }
            $meta = orange_advisory_family_meta($pdo, $fid);
            if ($meta === null) {
                json_response(['success' => false, 'message' => 'عائلة المقاسات غير موجودة'], 422);
            }
            $famCk = $meta['ck'];

            // أنواع المنتج المرشّحة: نفس النوع التجاري للعائلة + (تفضيلاً) نفس القسم عبر هرم الكتالوج.
            // سقوط تلقائي: إن لم يوجد أي نوع تحت القسم المختار، نعرض المطابق بالنوع التجاري بغض النظر عن القسم
            // (مثلاً عائلة أحذية/حقائب مُسجّلة أنواعها تحت قسمها الخاص لا تحت القسم المختار).
            $types = [];
            $diag = [
                'famCk'        => $famCk,
                'deptId'       => $deptId,
                'total_active' => 0,
                'after_kind'   => 0,
                'after_dept'   => 0,
                'used_fallback'=> false,
            ];
            if (orange_table_exists($pdo, 'product_types')) {
                $hasKindCol = orange_table_has_column($pdo, 'product_types', 'expected_commercial_kind_key');
                $hasTree = orange_table_exists($pdo, 'catalog_subcategories')
                    && orange_table_exists($pdo, 'catalog_categories')
                    && orange_table_exists($pdo, 'catalog_sections');

                $diag['total_active'] = (int) $pdo->query('SELECT COUNT(*) FROM product_types WHERE is_active = 1')->fetchColumn();

                $buildTypes = function (bool $withDept) use ($pdo, $famCk, $deptId, $hasKindCol, $hasTree) {
                    $sql = 'SELECT pt.id, pt.name_ar, pt.name_en, pt.default_advisory_sizing_guide_id
                            FROM product_types pt';
                    $params = [];
                    $where = ['pt.is_active = 1'];
                    if ($famCk !== '' && $hasKindCol) {
                        $where[] = '(pt.expected_commercial_kind_key = ? OR pt.expected_commercial_kind_key = \'\')';
                        $params[] = $famCk;
                    }
                    if ($withDept && $deptId > 0 && $hasTree) {
                        $sql .= ' JOIN catalog_subcategories sc ON sc.id = pt.catalog_subcategory_id
                                  JOIN catalog_categories cc ON cc.id = sc.catalog_category_id
                                  JOIN catalog_sections cs ON cs.id = cc.catalog_section_id';
                        $where[] = 'cs.department_id = ?';
                        $params[] = $deptId;
                    }
                    $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY pt.name_ar ASC, pt.id ASC';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                };

                $kindOnly = $buildTypes(false);
                $diag['after_kind'] = count($kindOnly);

                if ($deptId > 0 && $hasTree) {
                    $types = $buildTypes(true);
                    $diag['after_dept'] = count($types);
                    if (count($types) === 0 && count($kindOnly) > 0) {
                        $types = $kindOnly;
                        $diag['used_fallback'] = true;
                    }
                } else {
                    $types = $kindOnly;
                    $diag['after_dept'] = count($types);
                }
            }

            // المنتجات المرشّحة: نفس عائلة المقاسات.
            $products = [];
            if (orange_table_exists($pdo, 'products') && orange_table_has_column($pdo, 'products', 'size_family_id')) {
                $pSt = $pdo->prepare(
                    'SELECT id, name_ar, name_en, sizing_advisory_guide_id
                     FROM products WHERE size_family_id = ? ORDER BY name_ar ASC, id ASC LIMIT 500'
                );
                $pSt->execute([$fid]);
                $products = $pSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            json_response(['success' => true, 'types' => $types, 'products' => $products, 'diag' => $diag]);

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
            $hasColPanel = orange_table_has_column($pdo, 'advisory_sizing_guide_columns', 'panel_kind');
            $hasRowPanel = orange_table_has_column($pdo, 'advisory_sizing_guide_rows', 'panel_kind');
            $colSel = 'id, sort_order, label_ar, label_en, label_fil, label_hi, value_kind, unit_hint, storage_measure, display_system'
                . ($hasColPanel ? ', panel_kind' : '');
            $cols = $pdo->prepare(
                "SELECT $colSel FROM advisory_sizing_guide_columns WHERE guide_id = ? ORDER BY panel_kind ASC, sort_order ASC, id ASC"
            );
            $cols->execute([$gid]);
            $columns = $cols->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rowSel = 'id, sort_order, row_kind, size_family_size_id, label_ar, label_en, label_fil, label_hi'
                . ($hasRowPanel ? ', panel_kind' : '');
            $rws = $pdo->prepare(
                "SELECT $rowSel FROM advisory_sizing_guide_rows WHERE guide_id = ? ORDER BY panel_kind ASC, sort_order ASC, id ASC"
            );
            $rws->execute([$gid]);
            $rows = $rws->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // خرائط الأعمدة لكل لوحة لإعادة بناء الخلايا بالترتيب الصحيح.
            $colIdsByPanel = [];
            foreach ($columns as $col) {
                $pk = $hasColPanel ? orange_advisory_panel_kind_valid($col['panel_kind'] ?? 'single', 'dual') : 'single';
                $colIdsByPanel[$pk][] = (int) $col['id'];
            }

            $rowIds = [];
            foreach ($rows as $rw) {
                if (is_array($rw) && isset($rw['id'])) {
                    $rowIds[] = (int) $rw['id'];
                }
            }
            $cellsByRow = [];
            if ($rowIds !== []) {
                $in = implode(',', array_fill(0, count($rowIds), '?'));
                $cst = $pdo->prepare("SELECT row_id, column_id, cell_value FROM advisory_sizing_guide_cells WHERE row_id IN ($in)");
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
                    $cellsByRow[$rid][$cid] = (string) ($c['cell_value'] ?? '');
                }
            }

            $outCols = [];
            foreach ($columns as $col) {
                $pk = $hasColPanel ? orange_advisory_panel_kind_valid($col['panel_kind'] ?? 'single', 'dual') : 'single';
                $col['panel_kind'] = $pk;
                $outCols[] = $col;
            }
            $outRows = [];
            foreach ($rows as $rw) {
                if (!is_array($rw) || !isset($rw['id'])) {
                    continue;
                }
                $rid = (int) $rw['id'];
                $rk = orange_advisory_row_kind_valid($rw['row_kind'] ?? 'data');
                $pk = $hasRowPanel ? orange_advisory_panel_kind_valid($rw['panel_kind'] ?? 'single', 'dual') : 'single';
                $cells = [];
                if ($rk === 'data') {
                    foreach (($colIdsByPanel[$pk] ?? []) as $cid) {
                        $cells[] = $cellsByRow[$rid][$cid] ?? '';
                    }
                }
                $outRows[] = [
                    'id' => $rid,
                    'sort_order' => (int) ($rw['sort_order'] ?? 0),
                    'row_kind' => $rk,
                    'panel_kind' => $pk,
                    'size_family_size_id' => (int) ($rw['size_family_size_id'] ?? 0),
                    'label_ar' => (string) ($rw['label_ar'] ?? ''),
                    'label_en' => (string) ($rw['label_en'] ?? ''),
                    'label_fil' => (string) ($rw['label_fil'] ?? ''),
                    'label_hi' => (string) ($rw['label_hi'] ?? ''),
                    'cells' => $cells,
                ];
            }

            $linkedTypeIds = [];
            if (orange_table_exists($pdo, 'product_types')) {
                $lt = $pdo->prepare('SELECT id FROM product_types WHERE default_advisory_sizing_guide_id = ?');
                $lt->execute([$gid]);
                $linkedTypeIds = array_map('intval', $lt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }
            $linkedProductIds = [];
            if (orange_table_exists($pdo, 'products')) {
                $lp = $pdo->prepare('SELECT id FROM products WHERE sizing_advisory_guide_id = ?');
                $lp->execute([$gid]);
                $linkedProductIds = array_map('intval', $lp->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }

            json_response([
                'success' => true,
                'guide' => $guide,
                'columns' => $outCols,
                'rows' => $outRows,
                'linked_product_type_ids' => $linkedTypeIds,
                'linked_product_ids' => $linkedProductIds,
            ]);

        case 'delete':
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                json_response(['success' => false, 'message' => 'معرّف غير صالح'], 422);
            }
            $pdo->beginTransaction();
            try {
                if (orange_table_exists($pdo, 'product_types')) {
                    $pdo->prepare('UPDATE product_types SET default_advisory_sizing_guide_id = NULL WHERE default_advisory_sizing_guide_id = ?')->execute([$id]);
                }
                if (orange_table_exists($pdo, 'products')) {
                    $pdo->prepare('UPDATE products SET sizing_advisory_guide_id = NULL WHERE sizing_advisory_guide_id = ?')->execute([$id]);
                }
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
                orange_advisory_api_rollback_safe($pdo);
                throw $e;
            }
            json_response(['success' => true]);

        case 'save':
            $schemaReady = orange_advisory_api_save_schema_ready($pdo);
            if ($schemaReady !== null) {
                json_response(['success' => false, 'message' => $schemaReady], 503);
            }
            $id = (int) ($data['id'] ?? 0);
            $famId = (int) ($data['size_family_id'] ?? 0);
            if ($famId <= 0) {
                json_response(['success' => false, 'message' => 'اختر عائلة المقاسات (إلزامي).'], 422);
            }
            $meta = orange_advisory_family_meta($pdo, $famId);
            if ($meta === null) {
                json_response(['success' => false, 'message' => 'عائلة المقاسات غير موجودة.'], 422);
            }
            $tplId = $meta['tpl'];
            $ckKey = $meta['ck'];
            $deptId = (int) ($data['department_id'] ?? 0);

            if ($id > 0) {
                $own = $pdo->prepare('SELECT size_family_id FROM advisory_sizing_guides WHERE id = ? LIMIT 1');
                $own->execute([$id]);
                $exRow = $own->fetch(PDO::FETCH_ASSOC);
                if (!is_array($exRow)) {
                    json_response(['success' => false, 'message' => 'الدليل غير موجود'], 404);
                }
            }

            $layoutKind = orange_advisory_layout_kind_valid($data['layout_kind'] ?? 'single');
            $nameAr = orange_advisory_api_trunc_utf8(trim((string) ($data['name_ar'] ?? '')), 191);
            if ($nameAr === '') {
                json_response(['success' => false, 'message' => 'الاسم الداخلي (عربي) إلزامي.'], 422);
            }
            $active = (int) ($data['is_active'] ?? 1) === 0 ? 0 : 1;

            $columnsIn = $data['columns'] ?? [];
            $rowsIn = $data['rows'] ?? [];
            if (!is_array($columnsIn) || !is_array($rowsIn)) {
                json_response(['success' => false, 'message' => 'بيانات أعمدة/صفوف غير صالحة'], 422);
            }

            // تطبيع الأعمدة مع تجميعها حسب اللوحة (panel) للحفاظ على ترتيب الخلايا.
            $panels = $layoutKind === 'dual' ? ['upper', 'lower'] : ['single'];
            $colsByPanel = [];
            foreach ($panels as $pk) {
                $colsByPanel[$pk] = [];
            }
            $soByPanel = [];
            foreach ($columnsIn as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $pk = orange_advisory_panel_kind_valid($c['panel_kind'] ?? 'single', $layoutKind);
                if (!isset($colsByPanel[$pk])) {
                    continue;
                }
                $la = trim((string) ($c['label_ar'] ?? ''));
                $le = trim((string) ($c['label_en'] ?? ''));
                $lf = trim((string) ($c['label_fil'] ?? ''));
                $lh = trim((string) ($c['label_hi'] ?? ''));
                if ($la === '' || $le === '' || $lf === '' || $lh === '') {
                    json_response(['success' => false, 'message' => 'كل عمود يحتاج عربي و EN و Fil و Hi (اللوحة: ' . $pk . ').'], 422);
                }
                $soByPanel[$pk] = ($soByPanel[$pk] ?? 0) + 1;
                $stMeas = orange_advisory_normalize_storage_measure((string) ($c['storage_measure'] ?? ''));
                $dispSys = orange_advisory_normalize_display_system((string) ($c['display_system'] ?? ''));
                $vk = orange_advisory_value_kind_valid($c['value_kind'] ?? 'text');
                if ($stMeas === 'length_cm') {
                    $vk = 'number';
                }
                $colsByPanel[$pk][] = [
                    'panel_kind' => $pk,
                    'label_ar' => orange_advisory_api_trunc_utf8($la, 191),
                    'label_en' => orange_advisory_api_trunc_utf8($le, 191),
                    'label_fil' => orange_advisory_api_trunc_utf8($lf, 191),
                    'label_hi' => orange_advisory_api_trunc_utf8($lh, 191),
                    'value_kind' => $vk,
                    'unit_hint' => orange_advisory_api_trunc_utf8(trim((string) ($c['unit_hint'] ?? '')), 64),
                    'storage_measure' => $stMeas,
                    'display_system' => $dispSys,
                    'sort_order' => $soByPanel[$pk],
                ];
            }
            foreach ($panels as $pk) {
                if ($colsByPanel[$pk] === []) {
                    $label = $pk === 'lower' ? 'السفلي' : ($pk === 'upper' ? 'العلوي' : 'الجدول');
                    json_response(['success' => false, 'message' => 'أضف عموداً واحداً على الأقل (' . $label . ').'], 422);
                }
            }

            // تطبيع الصفوف حسب اللوحة.
            $rowsByPanel = [];
            foreach ($panels as $pk) {
                $rowsByPanel[$pk] = [];
            }
            $rsoByPanel = [];
            foreach ($rowsIn as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $pk = orange_advisory_panel_kind_valid($r['panel_kind'] ?? 'single', $layoutKind);
                if (!isset($rowsByPanel[$pk])) {
                    continue;
                }
                $rk = orange_advisory_row_kind_valid($r['row_kind'] ?? 'data');
                $rsoByPanel[$pk] = ($rsoByPanel[$pk] ?? 0) + 1;
                $ncol = count($colsByPanel[$pk]);
                $sfsId = (int) ($r['size_family_size_id'] ?? 0);
                if ($rk === 'data') {
                    if ($sfsId <= 0) {
                        json_response(['success' => false, 'message' => 'كل صف بيانات يجب ربطه بمقاس من العائلة.'], 422);
                    }
                    $v = $pdo->prepare('SELECT id FROM size_family_sizes WHERE id = ? AND size_family_id = ? LIMIT 1');
                    $v->execute([$sfsId, $famId]);
                    if (!$v->fetchColumn()) {
                        json_response(['success' => false, 'message' => 'مقاس مرتبط لا ينتمي لعائلة المقاسات المختارة.'], 422);
                    }
                }
                $cells = is_array($r['cells'] ?? null) ? $r['cells'] : [];
                $cells = array_slice(array_pad($cells, $ncol, ''), 0, $ncol);
                if ($rk === 'data' && $sfsId > 0 && $cells !== []) {
                    $cells[0] = '';
                }
                $rowsByPanel[$pk][] = [
                    'panel_kind' => $pk,
                    'row_kind' => $rk,
                    'sort_order' => $rsoByPanel[$pk],
                    'size_family_size_id' => $rk === 'data' ? $sfsId : 0,
                    'label_ar' => orange_advisory_api_trunc_utf8(trim((string) ($r['label_ar'] ?? '')), 191),
                    'label_en' => orange_advisory_api_trunc_utf8(trim((string) ($r['label_en'] ?? '')), 191),
                    'label_fil' => orange_advisory_api_trunc_utf8(trim((string) ($r['label_fil'] ?? '')), 191),
                    'label_hi' => orange_advisory_api_trunc_utf8(trim((string) ($r['label_hi'] ?? '')), 191),
                    'cells' => $rk === 'data' ? $cells : [],
                ];
            }
            // تحقّق: لكل لوحة صف بيانات واحد على الأقل + لا تكرار مقاس + خلايا مكتملة.
            foreach ($panels as $pk) {
                $hasData = false;
                $seen = [];
                $dataNum = 0;
                $ncol = count($colsByPanel[$pk]);
                foreach ($rowsByPanel[$pk] as $nr) {
                    if ($nr['row_kind'] !== 'data') {
                        if (trim($nr['label_ar']) === '' && trim($nr['label_en']) === '') {
                            json_response(['success' => false, 'message' => 'صف العنوان يحتاج نصاً عربياً أو إنجليزياً.'], 422);
                        }
                        continue;
                    }
                    $hasData = true;
                    $dataNum++;
                    $sid = (int) $nr['size_family_size_id'];
                    if (isset($seen[$sid])) {
                        json_response(['success' => false, 'message' => 'مقاس مكرر في أكثر من صف (نفس اللوحة).'], 422);
                    }
                    $seen[$sid] = true;
                    for ($jx = 1; $jx < $ncol; $jx++) {
                        if (trim((string) ($nr['cells'][$jx] ?? '')) === '') {
                            json_response(['success' => false, 'message' => 'أكمل خلايا صف البيانات رقم ' . $dataNum . ' (العمود ' . ($jx + 1) . ').'], 422);
                        }
                    }
                }
                if (!$hasData) {
                    $label = $pk === 'lower' ? 'السفلي' : ($pk === 'upper' ? 'العلوي' : 'الجدول');
                    json_response(['success' => false, 'message' => 'أضف صف بيانات واحداً على الأقل (' . $label . ').'], 422);
                }
            }

            $linkTypeIds = [];
            foreach ((array) ($data['link_product_type_ids'] ?? []) as $tid) {
                $tid = (int) $tid;
                if ($tid > 0) {
                    $linkTypeIds[$tid] = true;
                }
            }
            $linkTypeIds = array_keys($linkTypeIds);
            $linkProductIds = [];
            foreach ((array) ($data['link_product_ids'] ?? []) as $pidv) {
                $pidv = (int) $pidv;
                if ($pidv > 0) {
                    $linkProductIds[$pidv] = true;
                }
            }
            $linkProductIds = array_keys($linkProductIds);

            $guideSortIns = 0;
            if ($id <= 0) {
                $sMx = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM advisory_sizing_guides WHERE size_family_id = ?');
                $sMx->execute([$famId]);
                $guideSortIns = (int) $sMx->fetchColumn();
            }

            $pdo->beginTransaction();
            try {
                // منع تكرار الاسم الداخلي لنفس العائلة.
                if ($id <= 0) {
                    $dupN = $pdo->prepare('SELECT id FROM advisory_sizing_guides WHERE size_family_id = ? AND name_ar = ? LIMIT 1');
                    $dupN->execute([$famId, $nameAr]);
                } else {
                    $dupN = $pdo->prepare('SELECT id FROM advisory_sizing_guides WHERE size_family_id = ? AND name_ar = ? AND id <> ? LIMIT 1');
                    $dupN->execute([$famId, $nameAr, $id]);
                }
                if ($dupN->fetchColumn()) {
                    orange_advisory_api_rollback_safe($pdo);
                    json_response(['success' => false, 'message' => 'يوجد دليل بنفس الاسم الداخلي لهذه العائلة.'], 409);
                }

                $deptIns = $deptId > 0 ? $deptId : null;
                $tplIns = $tplId > 0 ? $tplId : null;
                $scopeKind = $layoutKind === 'dual' ? 'upper' : 'single';
                if ($id <= 0) {
                    $ins = $pdo->prepare(
                        'INSERT INTO advisory_sizing_guides
                            (size_family_id, department_id, size_scheme_template_id, commercial_kind_key, scope_kind, layout_kind, name_ar, sort_order, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?)'
                    );
                    $ins->execute([$famId, $deptIns, $tplIns, $ckKey, $scopeKind, $layoutKind, $nameAr, $guideSortIns, $active]);
                    $id = (int) $pdo->lastInsertId();
                } else {
                    $upd = $pdo->prepare(
                        'UPDATE advisory_sizing_guides SET
                            size_family_id = ?, department_id = ?, size_scheme_template_id = ?, commercial_kind_key = ?,
                            scope_kind = ?, layout_kind = ?, name_ar = ?, is_active = ?
                         WHERE id = ?'
                    );
                    $upd->execute([$famId, $deptIns, $tplIns, $ckKey, $scopeKind, $layoutKind, $nameAr, $active, $id]);
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

                // أدرج الأعمدة لكل لوحة واحتفظ بمعرّفاتها بالترتيب.
                $colIdsByPanel = [];
                foreach ($panels as $pk) {
                    $colIdsByPanel[$pk] = [];
                    foreach ($colsByPanel[$pk] as $nc) {
                        $ic = $pdo->prepare(
                            'INSERT INTO advisory_sizing_guide_columns
                                (guide_id, panel_kind, sort_order, label_ar, label_en, label_fil, label_hi, value_kind, unit_hint, storage_measure, display_system)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                        );
                        $ic->execute([
                            $id, $nc['panel_kind'], (int) $nc['sort_order'],
                            $nc['label_ar'], $nc['label_en'], $nc['label_fil'], $nc['label_hi'],
                            $nc['value_kind'], $nc['unit_hint'], $nc['storage_measure'], $nc['display_system'],
                        ]);
                        $colIdsByPanel[$pk][] = (int) $pdo->lastInsertId();
                    }
                }

                // أدرج الصفوف والخلايا (الخلايا تخص أعمدة نفس اللوحة).
                foreach ($panels as $pk) {
                    foreach ($rowsByPanel[$pk] as $nr) {
                        $ir = $pdo->prepare(
                            'INSERT INTO advisory_sizing_guide_rows
                                (guide_id, panel_kind, sort_order, row_kind, size_family_size_id, label_ar, label_en, label_fil, label_hi)
                             VALUES (?,?,?,?,?,?,?,?,?)'
                        );
                        $sfsIns = $nr['row_kind'] === 'data' ? (int) $nr['size_family_size_id'] : 0;
                        if ($sfsIns <= 0) {
                            $sfsIns = null;
                        }
                        $ir->execute([
                            $id, $nr['panel_kind'], (int) $nr['sort_order'], $nr['row_kind'], $sfsIns,
                            $nr['label_ar'], $nr['label_en'], $nr['label_fil'], $nr['label_hi'],
                        ]);
                        $rid = (int) $pdo->lastInsertId();
                        if ($nr['row_kind'] === 'data') {
                            foreach ($colIdsByPanel[$pk] as $ix => $cid) {
                                $val = orange_advisory_api_trunc_utf8((string) ($nr['cells'][$ix] ?? ''), 50000);
                                $pdo->prepare('INSERT INTO advisory_sizing_guide_cells (row_id, column_id, cell_value) VALUES (?,?,?)')
                                    ->execute([$rid, $cid, $val]);
                            }
                        }
                    }
                }

                // الربط: أنواع المنتج.
                if (orange_table_exists($pdo, 'product_types') && orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id')) {
                    if ($linkTypeIds === []) {
                        $pdo->prepare('UPDATE product_types SET default_advisory_sizing_guide_id = NULL WHERE default_advisory_sizing_guide_id = ?')->execute([$id]);
                    } else {
                        $inT = implode(',', array_fill(0, count($linkTypeIds), '?'));
                        $pdo->prepare("UPDATE product_types SET default_advisory_sizing_guide_id = NULL WHERE default_advisory_sizing_guide_id = ? AND id NOT IN ($inT)")
                            ->execute(array_merge([$id], $linkTypeIds));
                        $pdo->prepare("UPDATE product_types SET default_advisory_sizing_guide_id = ? WHERE id IN ($inT)")
                            ->execute(array_merge([$id], $linkTypeIds));
                    }
                }

                // الربط: المنتجات (ضمن نفس العائلة فقط) + ضبط نطاق الدليل.
                if (orange_table_exists($pdo, 'products')
                    && orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')
                    && orange_table_has_column($pdo, 'products', 'size_family_id')) {
                    $prodScope = $layoutKind === 'dual' ? 'both' : 'single';
                    $hasScopeCol = orange_table_has_column($pdo, 'products', 'sizing_guide_scope');
                    if ($linkProductIds === []) {
                        $pdo->prepare('UPDATE products SET sizing_advisory_guide_id = NULL WHERE sizing_advisory_guide_id = ?')->execute([$id]);
                    } else {
                        $inP = implode(',', array_fill(0, count($linkProductIds), '?'));
                        $pdo->prepare("UPDATE products SET sizing_advisory_guide_id = NULL WHERE sizing_advisory_guide_id = ? AND id NOT IN ($inP)")
                            ->execute(array_merge([$id], $linkProductIds));
                        if ($hasScopeCol) {
                            $pdo->prepare("UPDATE products SET sizing_advisory_guide_id = ?, sizing_guide_scope = ? WHERE id IN ($inP) AND size_family_id = ?")
                                ->execute(array_merge([$id, $prodScope], $linkProductIds, [$famId]));
                        } else {
                            $pdo->prepare("UPDATE products SET sizing_advisory_guide_id = ? WHERE id IN ($inP) AND size_family_id = ?")
                                ->execute(array_merge([$id], $linkProductIds, [$famId]));
                        }
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                orange_advisory_api_rollback_safe($pdo);
                if (function_exists('error_log')) {
                    error_log('[orange] advisory_sizing save: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . (string) $e->getLine());
                }
                json_response(['success' => false, 'message' => 'فشل الحفظ — راجع سجل أخطاء PHP على السيرفر (advisory_sizing save).'], 500);
            }

            json_response(['success' => true, 'id' => $id]);

        default:
            json_response(['success' => false, 'message' => 'إجراء غير معروف'], 400);
    }
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] advisory_sizing_guides/manage: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . (string) $e->getLine());
    }
    // تشخيص مؤقت (الـ API محمي للأدمن فقط): نُظهر نص الاستثناء الفعلي لمعرفة السبب الحقيقي.
    json_response([
        'success' => false,
        'message' => 'خطأ خادم: ' . $e->getMessage()
            . ' @' . basename($e->getFile()) . ':' . (string) $e->getLine(),
    ], 500);
}
