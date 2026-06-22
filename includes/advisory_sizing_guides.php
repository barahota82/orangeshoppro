<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_labels.php';

/**
 * @return list<string>
 */
function orange_advisory_sizing_product_scope_kinds(string $productScope): array
{
    $s = strtolower(trim($productScope));

    return match ($s) {
        'upper' => ['upper'],
        'lower' => ['lower'],
        'both' => ['upper', 'lower'],
        'single' => ['single'],
        default => [],
    };
}

/**
 * @param array<string, mixed> $row
 */
function orange_advisory_sizing_label_from_row(array $row, string $lang): string
{
    return orange_size_display_label(
        [
            'label_ar' => (string) ($row['label_ar'] ?? ''),
            'label_en' => (string) ($row['label_en'] ?? ''),
            'label_fil' => (string) ($row['label_fil'] ?? ''),
            'label_hi' => (string) ($row['label_hi'] ?? ''),
        ],
        $lang
    );
}

/**
 * Parses a cell value stored as centimeters (decimal comma/dot allowed).
 */
function orange_advisory_parse_stored_cm(string $raw): ?float
{
    $s = trim(str_replace(["\u{00A0}", "\u{202F}", '٫', '،'], [' ', ' ', '.', '.'], $raw));
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/\s+/u', '', $s) ?? $s;
    $s = preg_replace('/[^0-9.\-]/u', '', $s) ?? '';
    if ($s === '' || $s === '-' || $s === '.') {
        return null;
    }
    if (!is_numeric($s)) {
        return null;
    }
    $n = (float) $s;

    return round($n, 6);
}

/**
 * يفسّر خلية عمود «تخزين بالسم»: رقم واحد أو نطاق min–max (شرطة عادية أو en dash).
 *
 * @return array{0: float, 1: float}|null [lo, hi] بالسم؛ رقم واحد => lo === hi
 */
function orange_advisory_parse_stored_cm_span(string $raw): ?array
{
    $rawTrim = trim($raw);
    if ($rawTrim === '') {
        return null;
    }
    $s = preg_replace('/\s*[–—−]\s*/u', '-', $rawTrim) ?? $rawTrim;
    if (preg_match('/^(.+)-(.+)$/u', $s, $m)) {
        $a = orange_advisory_parse_stored_cm(trim($m[1]));
        $b = orange_advisory_parse_stored_cm(trim($m[2]));
        if ($a === null || $b === null) {
            return null;
        }
        $lo = min($a, $b);
        $hi = max($a, $b);

        return [$lo, $hi];
    }
    $one = orange_advisory_parse_stored_cm($rawTrim);
    if ($one === null) {
        return null;
    }

    return [$one, $one];
}

/**
 * @param float $lo
 * @param float $hi
 */
function orange_advisory_format_cm_measure(float $lo, float $hi): string
{
    $f = static function (float $n): string {
        $s = number_format($n, 2, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');

        return $s;
    };
    if (abs($lo - $hi) < 1e-9) {
        return $f($lo);
    }

    return $f($lo) . '–' . $f($hi);
}

/**
 * كود تجميع أعمدة العرض للعميل (مثل eu، uk، us، cn). فارغ = عمود عام يظهر مع أي نظام.
 * يُسمح بأحرف إنجليزية صغيرة وأرقام وشرطة سفلية فقط؛ حتى 32 حرفاً (يتوافق مع عمود القاعدة).
 *
 * @return non-empty-lowercase-alnum-underscore string, or ''
 */
function orange_advisory_normalize_display_system(string $raw): string
{
    $s = strtolower(trim($raw));
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/[^a-z0-9_]/', '', $s) ?? '';
    if ($s === '') {
        return '';
    }

    return substr($s, 0, 32);
}

/**
 * تسمية خيار «نظام العرض» في المتجر: مفتاح ترجمة sizing_display_system_{code} إن وُجد، وإلا عرض الكود بشكل مقروء.
 */
function orange_advisory_display_system_storefront_label(string $sysCode): string
{
    $code = orange_advisory_normalize_display_system($sysCode);
    if ($code === '') {
        return '';
    }
    if (!function_exists('t')) {
        return strtoupper(str_replace('_', ' ', $code));
    }
    $tk = 'sizing_display_system_' . $code;
    $lab = t($tk);
    if ($lab !== $tk) {
        return $lab;
    }

    return strtoupper(str_replace('_', ' ', $code));
}

/**
 * @return ''|'length_cm'
 */
function orange_advisory_normalize_storage_measure(string $raw): string
{
    $s = strtolower(trim($raw));

    return $s === 'length_cm' ? 'length_cm' : '';
}

/**
 * @return array<int, array<string, mixed>>
 */
function orange_advisory_sizing_load_size_rows_map(PDO $pdo, int $familyId, array $sizeIds): array
{
    $sizeIds = array_values(array_unique(array_filter(array_map('intval', $sizeIds))));
    if ($sizeIds === [] || $familyId <= 0) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($sizeIds), '?'));
    $st = $pdo->prepare(
        "SELECT id, label_ar, label_en, label_fil, label_hi
         FROM size_family_sizes
         WHERE size_family_id = ? AND id IN ($ph) AND is_active = 1"
    );
    $st->execute(array_merge([$familyId], $sizeIds));
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (is_array($r) && isset($r['id'])) {
            $out[(int) $r['id']] = $r;
        }
    }

    return $out;
}

/**
 * هل يوجد دليل ديناميكي واحد على الأقل للعائلة ضمن الأنواع المطلوبة وجاهز للعرض؟
 *
 * @param list<string> $kinds
 */
function orange_advisory_sizing_has_ready_section(PDO $pdo, int $familyId, array $kinds): bool
{
    if ($familyId <= 0 || $kinds === [] || !orange_table_exists($pdo, 'advisory_sizing_guides')) {
        return false;
    }
    $ph = implode(',', array_fill(0, count($kinds), '?'));
    $st = $pdo->prepare(
        "SELECT g.id FROM advisory_sizing_guides g
         WHERE g.size_family_id = ? AND g.is_active = 1 AND g.scope_kind IN ($ph)
           AND EXISTS (SELECT 1 FROM advisory_sizing_guide_columns c WHERE c.guide_id = g.id)
           AND EXISTS (SELECT 1 FROM advisory_sizing_guide_rows r WHERE r.guide_id = g.id)
         LIMIT 1"
    );
    $st->execute(array_merge([$familyId], $kinds));

    return (bool) $st->fetchColumn();
}

/**
 * يبني مصفوفة قسم واحد لدليل محمّل مسبقاً (عرض للواجهة).
 *
 * @param array<string, mixed> $guide صف advisory_sizing_guides
 * @return array{scope_kind: string, columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}|null
 */
function orange_advisory_sizing_build_section_array_for_guide(PDO $pdo, array $guide, string $lang, string $panelFilter = ''): ?array
{
    if (!isset($guide['id']) || !orange_table_exists($pdo, 'advisory_sizing_guides')) {
        return null;
    }
    $gid = (int) $guide['id'];
    $familyId = (int) ($guide['size_family_id'] ?? 0);
    if ($gid <= 0 || $familyId <= 0) {
        return null;
    }
    $panelFilter = strtolower(trim($panelFilter));
    if (!in_array($panelFilter, ['upper', 'lower', 'single'], true)) {
        $panelFilter = '';
    }
    // scope_kind للعرض: عند تصفية لوحة (dual) نتبع اللوحة؛ وإلا scope_kind المحفوظ.
    if ($panelFilter !== '') {
        $scopeOut = $panelFilter;
    } else {
        $scopeOut = strtolower(trim((string) ($guide['scope_kind'] ?? '')));
        if (!in_array($scopeOut, ['upper', 'lower', 'single'], true)) {
            $scopeOut = 'single';
        }
    }
    $colHasPanel = orange_table_has_column($pdo, 'advisory_sizing_guide_columns', 'panel_kind');
    $rowHasPanel = orange_table_has_column($pdo, 'advisory_sizing_guide_rows', 'panel_kind');
    $colWhere = 'guide_id = ?';
    $colParams = [$gid];
    if ($panelFilter !== '' && $colHasPanel) {
        $colWhere .= ' AND panel_kind = ?';
        $colParams[] = $panelFilter;
    }
    $cSt = $pdo->prepare(
        'SELECT id, label_ar, label_en, label_fil, label_hi, unit_hint, value_kind, storage_measure, display_system
         FROM advisory_sizing_guide_columns
         WHERE ' . $colWhere . '
         ORDER BY sort_order ASC, id ASC'
    );
    $cSt->execute($colParams);
    $columns = $cSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($columns === []) {
        return null;
    }
    $rowWhere = 'guide_id = ?';
    $rowParams = [$gid];
    if ($panelFilter !== '' && $rowHasPanel) {
        $rowWhere .= ' AND panel_kind = ?';
        $rowParams[] = $panelFilter;
    }
    $rSt = $pdo->prepare(
        'SELECT id, row_kind, size_family_size_id, label_ar, label_en, label_fil, label_hi
         FROM advisory_sizing_guide_rows
         WHERE ' . $rowWhere . '
         ORDER BY sort_order ASC, id ASC'
    );
    $rSt->execute($rowParams);
    $rows = $rSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return null;
    }
    $sizeIdsNeeded = [];
    foreach ($rows as $rw) {
        $sid = isset($rw['size_family_size_id']) ? (int) $rw['size_family_size_id'] : 0;
        if ($sid > 0) {
            $sizeIdsNeeded[] = $sid;
        }
    }
    $sizeMap = orange_advisory_sizing_load_size_rows_map($pdo, $familyId, $sizeIdsNeeded);

    $colMeta = [];
    foreach ($columns as $c) {
        if (!is_array($c) || !isset($c['id'])) {
            continue;
        }
        $uh = trim((string) ($c['unit_hint'] ?? ''));
        $base = orange_advisory_sizing_label_from_row($c, $lang);
        $header = $base !== '' && $uh !== '' ? $base . ' (' . $uh . ')' : ($base !== '' ? $base : $uh);

        $vk = strtolower(trim((string) ($c['value_kind'] ?? 'text')));
        if ($vk !== 'number') {
            $vk = 'text';
        }
        $stMeas = orange_advisory_normalize_storage_measure((string) ($c['storage_measure'] ?? ''));
        $dispSys = orange_advisory_normalize_display_system((string) ($c['display_system'] ?? ''));
        if ($stMeas === 'length_cm') {
            $vk = 'number';
        }
        $colMeta[] = [
            'id' => (int) $c['id'],
            'header' => $header !== '' ? $header : '—',
            'value_kind' => $vk,
            'storage_measure' => $stMeas,
            'display_system' => $dispSys,
        ];
    }
    $firstColId = $colMeta[0]['id'] ?? 0;

    $rowIds = [];
    foreach ($rows as $rw) {
        if (is_array($rw) && isset($rw['id'])) {
            $rowIds[] = (int) $rw['id'];
        }
    }
    $cellMap = [];
    if ($rowIds !== []) {
        $in = implode(',', array_fill(0, count($rowIds), '?'));
        $cellSt = $pdo->prepare(
            "SELECT row_id, column_id, cell_value
             FROM advisory_sizing_guide_cells
             WHERE row_id IN ($in)"
        );
        $cellSt->execute($rowIds);
        while ($ce = $cellSt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($ce)) {
                continue;
            }
            $rid = (int) ($ce['row_id'] ?? 0);
            $cid = (int) ($ce['column_id'] ?? 0);
            if ($rid <= 0 || $cid <= 0) {
                continue;
            }
            if (!isset($cellMap[$rid])) {
                $cellMap[$rid] = [];
            }
            $cellMap[$rid][$cid] = (string) ($ce['cell_value'] ?? '');
        }
    }

    $outRows = [];
    foreach ($rows as $rw) {
        if (!is_array($rw) || !isset($rw['id'])) {
            continue;
        }
        $rid = (int) $rw['id'];
        $rk = strtolower(trim((string) ($rw['row_kind'] ?? 'data')));
        if ($rk === 'label') {
            $lbl = orange_advisory_sizing_label_from_row($rw, $lang);
            $outRows[] = ['kind' => 'label', 'label' => $lbl];

            continue;
        }
        $cells = [];
        $sid = isset($rw['size_family_size_id']) ? (int) $rw['size_family_size_id'] : 0;
        foreach ($colMeta as $cm) {
            $cid = (int) $cm['id'];
            $raw = $cellMap[$rid][$cid] ?? '';
            if ($firstColId > 0 && $cid === $firstColId && $sid > 0 && isset($sizeMap[$sid])) {
                $raw = orange_advisory_sizing_label_from_row($sizeMap[$sid], $lang);
            }
            $cells[] = $raw;
        }
        $outRows[] = ['kind' => 'data', 'cells' => $cells];
    }

    return [
        'scope_kind' => $scopeOut,
        'columns' => $colMeta,
        'rows' => $outRows,
    ];
}

/**
 * أقسام العرض من دليل محدد بالمعرّف (منتج يختار نموذجاً داخلياً).
 *
 * @return array{use_dynamic: bool, sections: list<array<string, mixed>>}
 */
function orange_advisory_sizing_build_sections_for_guide_id(PDO $pdo, int $guideId, int $expectedFamilyId, string $lang): array
{
    if ($guideId <= 0 || !orange_table_exists($pdo, 'advisory_sizing_guides')) {
        return ['use_dynamic' => false, 'sections' => []];
    }
    $st = $pdo->prepare('SELECT * FROM advisory_sizing_guides WHERE id = ? LIMIT 1');
    $st->execute([$guideId]);
    $guide = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($guide) || !isset($guide['id'])) {
        return ['use_dynamic' => false, 'sections' => []];
    }
    if ($expectedFamilyId > 0 && (int) ($guide['size_family_id'] ?? 0) !== $expectedFamilyId) {
        return ['use_dynamic' => false, 'sections' => []];
    }
    if ((int) ($guide['is_active'] ?? 0) !== 1) {
        return ['use_dynamic' => false, 'sections' => []];
    }
    $layoutKind = strtolower(trim((string) ($guide['layout_kind'] ?? 'single')));
    if ($layoutKind === 'dual' && orange_table_has_column($pdo, 'advisory_sizing_guide_columns', 'panel_kind')) {
        $sections = [];
        foreach (['upper', 'lower'] as $pk) {
            $sec = orange_advisory_sizing_build_section_array_for_guide($pdo, $guide, $lang, $pk);
            if ($sec !== null) {
                $sections[] = $sec;
            }
        }
        if ($sections === []) {
            return ['use_dynamic' => false, 'sections' => []];
        }

        return ['use_dynamic' => true, 'sections' => $sections];
    }
    $sec = orange_advisory_sizing_build_section_array_for_guide($pdo, $guide, $lang);
    if ($sec === null) {
        return ['use_dynamic' => false, 'sections' => []];
    }

    return ['use_dynamic' => true, 'sections' => [$sec]];
}

/**
 * سلسلة الأولوية (قرار المالك 2026-06-22): دليل المنتج → دليل نوع المنتج → 0 (يتبعه المتجر بدليل العائلة).
 *
 * @param array<string, mixed> $product صف المنتج (يحوي sizing_advisory_guide_id و product_type_id إن وُجدا)
 * @return int معرّف الدليل الأخصّ النشِط أو 0
 */
function orange_advisory_sizing_resolve_guide_id(PDO $pdo, array $product): int
{
    if (!orange_table_exists($pdo, 'advisory_sizing_guides')) {
        return 0;
    }
    $isActive = static function (int $gid) use ($pdo): bool {
        if ($gid <= 0) {
            return false;
        }
        $st = $pdo->prepare('SELECT id FROM advisory_sizing_guides WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$gid]);

        return (bool) $st->fetchColumn();
    };

    $productGuideId = (int) ($product['sizing_advisory_guide_id'] ?? 0);
    if ($isActive($productGuideId)) {
        return $productGuideId;
    }

    $ptId = (int) ($product['product_type_id'] ?? 0);
    if ($ptId > 0
        && orange_table_exists($pdo, 'product_types')
        && orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id')) {
        $st = $pdo->prepare('SELECT default_advisory_sizing_guide_id FROM product_types WHERE id = ? LIMIT 1');
        $st->execute([$ptId]);
        $typeGuideId = (int) ($st->fetchColumn() ?: 0);
        if ($isActive($typeGuideId)) {
            return $typeGuideId;
        }
    }

    // احتياطي العائلة: الدليل العام (غير المربوط بأي نوع/منتج) النشِط، الأقل ترتيباً ثم الأقدم — اختيار حاسم.
    $familyId = (int) ($product['size_family_id'] ?? 0);
    if ($familyId > 0) {
        return orange_advisory_sizing_family_general_guide_id($pdo, $familyId);
    }

    return 0;
}

/**
 * دليل العائلة العام (غير المربوط بأي نوع منتج أو منتج) النشِط — الأقل ترتيباً ثم الأقدم.
 */
function orange_advisory_sizing_family_general_guide_id(PDO $pdo, int $familyId): int
{
    if ($familyId <= 0 || !orange_table_exists($pdo, 'advisory_sizing_guides')) {
        return 0;
    }
    $notType = '';
    if (orange_table_exists($pdo, 'product_types') && orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id')) {
        $notType = ' AND NOT EXISTS (SELECT 1 FROM product_types pt WHERE pt.default_advisory_sizing_guide_id = g.id)';
    }
    $notProduct = '';
    if (orange_table_exists($pdo, 'products') && orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')) {
        $notProduct = ' AND NOT EXISTS (SELECT 1 FROM products p WHERE p.sizing_advisory_guide_id = g.id)';
    }
    $st = $pdo->prepare(
        'SELECT g.id FROM advisory_sizing_guides g
         WHERE g.size_family_id = ? AND g.is_active = 1' . $notType . $notProduct . '
         ORDER BY g.sort_order ASC, g.id ASC
         LIMIT 1'
    );
    $st->execute([$familyId]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * يبني أقسام العرض للواجهة (عرض فقط).
 *
 * @param list<string> $kinds
 * @return array{use_dynamic: bool, sections: list<array{scope_kind: string, title: string, columns: list<array{id:int,header:string}>, rows: list<array{kind:string,label?:string,cells?:list<string>}>}>}
 */
function orange_advisory_sizing_build_sections(PDO $pdo, int $familyId, array $kinds, string $lang): array
{
    $sections = [];
    if ($familyId <= 0 || $kinds === [] || !orange_table_exists($pdo, 'advisory_sizing_guides')) {
        return ['use_dynamic' => false, 'sections' => []];
    }
    if (!orange_advisory_sizing_has_ready_section($pdo, $familyId, $kinds)) {
        return ['use_dynamic' => false, 'sections' => []];
    }

    foreach ($kinds as $kind) {
        $gSt = $pdo->prepare(
            'SELECT * FROM advisory_sizing_guides
             WHERE size_family_id = ? AND scope_kind = ? AND is_active = 1 LIMIT 1'
        );
        $gSt->execute([$familyId, $kind]);
        $guide = $gSt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($guide) || !isset($guide['id'])) {
            continue;
        }
        $sec = orange_advisory_sizing_build_section_array_for_guide($pdo, $guide, $lang);
        if ($sec !== null) {
            $sections[] = $sec;
        }
    }

    return ['use_dynamic' => $sections !== [], 'sections' => $sections];
}
