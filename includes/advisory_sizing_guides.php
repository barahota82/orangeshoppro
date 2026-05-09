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
 * @param array<string, mixed> $guide
 */
function orange_advisory_sizing_guide_title(array $guide, string $lang): string
{
    return orange_advisory_sizing_label_from_row(
        [
            'label_ar' => (string) ($guide['name_ar'] ?? ''),
            'label_en' => (string) ($guide['name_en'] ?? ''),
            'label_fil' => (string) ($guide['name_fil'] ?? ''),
            'label_hi' => (string) ($guide['name_hi'] ?? ''),
        ],
        $lang
    );
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
        $gid = (int) $guide['id'];
        $cSt = $pdo->prepare(
            'SELECT id, label_ar, label_en, label_fil, label_hi, unit_hint
             FROM advisory_sizing_guide_columns
             WHERE guide_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $cSt->execute([$gid]);
        $columns = $cSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($columns === []) {
            continue;
        }
        $rSt = $pdo->prepare(
            'SELECT id, row_kind, size_family_size_id, label_ar, label_en, label_fil, label_hi
             FROM advisory_sizing_guide_rows
             WHERE guide_id = ?
             ORDER BY sort_order ASC, id ASC'
        );
        $rSt->execute([$gid]);
        $rows = $rSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            continue;
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

            $colMeta[] = [
                'id' => (int) $c['id'],
                'header' => $header !== '' ? $header : '—',
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
                if ($firstColId > 0 && $cid === $firstColId && trim($raw) === '' && $sid > 0 && isset($sizeMap[$sid])) {
                    $raw = orange_advisory_sizing_label_from_row($sizeMap[$sid], $lang);
                }
                $cells[] = $raw;
            }
            $outRows[] = ['kind' => 'data', 'cells' => $cells];
        }

        $sections[] = [
            'scope_kind' => (string) $kind,
            'title' => orange_advisory_sizing_guide_title($guide, $lang),
            'columns' => $colMeta,
            'rows' => $outRows,
        ];
    }

    return ['use_dynamic' => $sections !== [], 'sections' => $sections];
}
