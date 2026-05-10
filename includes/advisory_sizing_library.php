<?php

declare(strict_types=1);

/**
 * مكتبة أدلة المقاسات الاسترشادية: حزمة (عائلة مصدر) + ربط عائلة مستهلك → مزامنسخ للأدلة على عائلة المستهلك.
 *
 * @see docs/archive/ORANGE_ADVISORY_SIZING_LIBRARY_DECISION.md
 */

/**
 * @return list<string>
 */
function orange_advisory_sizing_library_scope_kinds(): array
{
    return ['upper', 'lower', 'single'];
}

function orange_advisory_sizing_library_tables_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'advisory_sizing_library_bundles')
        && orange_table_exists($pdo, 'size_family_advisory_library_map')
        && orange_table_exists($pdo, 'advisory_sizing_guides');
}

/**
 * @return list<int>
 */
function orange_advisory_sizing_library_ordered_size_ids(PDO $pdo, int $familyId): array
{
    if ($familyId <= 0 || !orange_table_exists($pdo, 'size_family_sizes')) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT id FROM size_family_sizes
         WHERE size_family_id = ? AND is_active = 1
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([$familyId]);
    $out = [];
    while ($id = $st->fetchColumn()) {
        $out[] = (int) $id;
    }

    return $out;
}

function orange_advisory_sizing_library_delete_guide_cascade(PDO $pdo, int $guideId): void
{
    if ($guideId <= 0) {
        return;
    }
    $stR = $pdo->prepare('SELECT id FROM advisory_sizing_guide_rows WHERE guide_id = ?');
    $stR->execute([$guideId]);
    $rids = array_map('intval', $stR->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($rids !== []) {
        $in = implode(',', array_fill(0, count($rids), '?'));
        $pdo->prepare("DELETE FROM advisory_sizing_guide_cells WHERE row_id IN ($in)")->execute($rids);
    }
    $pdo->prepare('DELETE FROM advisory_sizing_guide_rows WHERE guide_id = ?')->execute([$guideId]);
    $pdo->prepare('DELETE FROM advisory_sizing_guide_columns WHERE guide_id = ?')->execute([$guideId]);
    $pdo->prepare('DELETE FROM advisory_sizing_guides WHERE id = ?')->execute([$guideId]);
}

/**
 * @param array<int, int> $sizeIdMap مصدر => مستهلك (نفس الترتيب لقائمة المقاسات النشطة)
 *
 * @return int معرّف الدليل الجديد
 */
function orange_advisory_sizing_library_clone_guide_to_family(
    PDO $pdo,
    int $sourceGuideId,
    int $targetFamilyId,
    array $sizeIdMap
): int {
    $gSt = $pdo->prepare('SELECT * FROM advisory_sizing_guides WHERE id = ? LIMIT 1');
    $gSt->execute([$sourceGuideId]);
    $g = $gSt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($g)) {
        throw new RuntimeException('دليل المصدر غير موجود');
    }
    $scope = strtolower(trim((string) ($g['scope_kind'] ?? '')));
    if (!in_array($scope, orange_advisory_sizing_library_scope_kinds(), true)) {
        throw new RuntimeException('نوع نطاق الدليل غير مدعوم');
    }

    $insG = $pdo->prepare(
        'INSERT INTO advisory_sizing_guides
         (size_family_id, scope_kind, name_ar, name_en, name_fil, name_hi, sort_order, is_active)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $insG->execute([
        $targetFamilyId,
        $scope,
        (string) ($g['name_ar'] ?? ''),
        (string) ($g['name_en'] ?? ''),
        (string) ($g['name_fil'] ?? ''),
        (string) ($g['name_hi'] ?? ''),
        (int) ($g['sort_order'] ?? 0),
        (int) ($g['is_active'] ?? 1) ? 1 : 0,
    ]);
    $newGuideId = (int) $pdo->lastInsertId();
    if ($newGuideId <= 0) {
        throw new RuntimeException('فشل إنشاء الدليل على عائلة المستهلك');
    }

    $cSt = $pdo->prepare(
        'SELECT id, sort_order, label_ar, label_en, label_fil, label_hi, value_kind, unit_hint, storage_measure, display_system
         FROM advisory_sizing_guide_columns WHERE guide_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $cSt->execute([$sourceGuideId]);
    $cols = $cSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $colMap = [];
    $insC = $pdo->prepare(
        'INSERT INTO advisory_sizing_guide_columns
         (guide_id, sort_order, label_ar, label_en, label_fil, label_hi, value_kind, unit_hint, storage_measure, display_system)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($cols as $c) {
        if (!is_array($c) || !isset($c['id'])) {
            continue;
        }
        $oldCid = (int) $c['id'];
        $insC->execute([
            $newGuideId,
            (int) ($c['sort_order'] ?? 0),
            (string) ($c['label_ar'] ?? ''),
            (string) ($c['label_en'] ?? ''),
            (string) ($c['label_fil'] ?? ''),
            (string) ($c['label_hi'] ?? ''),
            (string) ($c['value_kind'] ?? 'text'),
            (string) ($c['unit_hint'] ?? ''),
            (string) ($c['storage_measure'] ?? ''),
            (string) ($c['display_system'] ?? ''),
        ]);
        $newCid = (int) $pdo->lastInsertId();
        $colMap[$oldCid] = $newCid;
    }

    $rSt = $pdo->prepare(
        'SELECT id, sort_order, row_kind, size_family_size_id, label_ar, label_en, label_fil, label_hi
         FROM advisory_sizing_guide_rows WHERE guide_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $rSt->execute([$sourceGuideId]);
    $rows = $rSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rowMap = [];
    $insR = $pdo->prepare(
        'INSERT INTO advisory_sizing_guide_rows
         (guide_id, sort_order, row_kind, size_family_size_id, label_ar, label_en, label_fil, label_hi)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    foreach ($rows as $r) {
        if (!is_array($r) || !isset($r['id'])) {
            continue;
        }
        $oldRid = (int) $r['id'];
        $rk = strtolower(trim((string) ($r['row_kind'] ?? 'data')));
        $sid = isset($r['size_family_size_id']) ? (int) $r['size_family_size_id'] : 0;
        $newSid = 0;
        if ($rk === 'label') {
            $newSid = 0;
        } else {
            if ($sid <= 0) {
                throw new RuntimeException('صف بيانات في دليل المصدر بدون ربط مقاس');
            }
            if (!isset($sizeIdMap[$sid])) {
                throw new RuntimeException('مقاس في دليل المصدر لا يطابق خريطة المقاسات بين العائلات');
            }
            $newSid = (int) $sizeIdMap[$sid];
        }
        $insR->execute([
            $newGuideId,
            (int) ($r['sort_order'] ?? 0),
            $rk === 'label' ? 'label' : 'data',
            $newSid,
            (string) ($r['label_ar'] ?? ''),
            (string) ($r['label_en'] ?? ''),
            (string) ($r['label_fil'] ?? ''),
            (string) ($r['label_hi'] ?? ''),
        ]);
        $rowMap[$oldRid] = (int) $pdo->lastInsertId();
    }

    if ($rowMap !== []) {
        $cellSt = $pdo->prepare(
            'SELECT row_id, column_id, cell_value FROM advisory_sizing_guide_cells WHERE row_id IN ('
            . implode(',', array_fill(0, count($rowMap), '?')) . ')'
        );
        $cellSt->execute(array_keys($rowMap));
        $insCell = $pdo->prepare('INSERT INTO advisory_sizing_guide_cells (row_id, column_id, cell_value) VALUES (?,?,?)');
        while ($ce = $cellSt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($ce)) {
                continue;
            }
            $oldR = (int) ($ce['row_id'] ?? 0);
            $oldCol = (int) ($ce['column_id'] ?? 0);
            if ($oldR <= 0 || $oldCol <= 0 || !isset($rowMap[$oldR], $colMap[$oldCol])) {
                continue;
            }
            $insCell->execute([$rowMap[$oldR], $colMap[$oldCol], (string) ($ce['cell_value'] ?? '')]);
        }
    }

    return $newGuideId;
}

/**
 * يبني خريطة id مقاس مصدر => id مقاس مستهلك بنفس ترتيب المقاسات النشطة.
 *
 * @return array<int, int>|string رسالة خطأ عربية أو الخريطة
 */
function orange_advisory_sizing_library_build_size_map(PDO $pdo, int $sourceFamilyId, int $consumerFamilyId): array|string
{
    $src = orange_advisory_sizing_library_ordered_size_ids($pdo, $sourceFamilyId);
    $dst = orange_advisory_sizing_library_ordered_size_ids($pdo, $consumerFamilyId);
    if ($src === [] || $dst === []) {
        return 'عائلة المصدر أو المستهلك بلا مقاسات نشطة — راجع عائلات المقاسات.';
    }
    if (count($src) !== count($dst)) {
        return 'عدد المقاسات النشطة في عائلة المصدر (' . count($src) . ') لا يطابق عائلة المستهلك (' . count($dst)
            . '). عدّل المقاسات أو استخدم عائلات بنفس العدد.';
    }
    $map = [];
    for ($i = 0, $n = count($src); $i < $n; $i++) {
        $map[$src[$i]] = $dst[$i];
    }

    return $map;
}

/**
 * ينسخ كل أدلة النطاق (علوي/سفلي/مفرد) من عائلة المصدر إلى عائلة المستهلك حسب الخريطة.
 *
 * @return string|null رسالة خطأ أو null عند النجاح
 */
function orange_advisory_sizing_library_sync_consumer_from_source(
    PDO $pdo,
    int $sourceFamilyId,
    int $consumerFamilyId
): ?string {
    if (!orange_advisory_sizing_library_tables_ready($pdo)) {
        return 'جداول مكتبة الأدلة غير جاهزة.';
    }
    if ($sourceFamilyId <= 0 || $consumerFamilyId <= 0) {
        return 'معرّف عائلة غير صالح.';
    }
    if ($sourceFamilyId === $consumerFamilyId) {
        return 'عائلة المصدر والمستهلك متطابقتان — لا حاجة للمزامنة.';
    }

    $mapOrErr = orange_advisory_sizing_library_build_size_map($pdo, $sourceFamilyId, $consumerFamilyId);
    if (is_string($mapOrErr)) {
        return $mapOrErr;
    }
    /** @var array<int, int> $map */
    $map = $mapOrErr;

    $scopes = orange_advisory_sizing_library_scope_kinds();
    try {
        $pdo->beginTransaction();
        foreach ($scopes as $scope) {
            $find = $pdo->prepare(
                'SELECT g.id FROM advisory_sizing_guides g
                 WHERE g.size_family_id = ? AND g.scope_kind = ?
                   AND EXISTS (SELECT 1 FROM advisory_sizing_guide_columns c WHERE c.guide_id = g.id)
                   AND EXISTS (SELECT 1 FROM advisory_sizing_guide_rows r WHERE r.guide_id = g.id)
                 LIMIT 1'
            );
            $find->execute([$sourceFamilyId, $scope]);
            $srcGuideId = (int) $find->fetchColumn();
            if ($srcGuideId <= 0) {
                continue;
            }

            $findT = $pdo->prepare(
                'SELECT id FROM advisory_sizing_guides WHERE size_family_id = ? AND scope_kind = ? LIMIT 1'
            );
            $findT->execute([$consumerFamilyId, $scope]);
            $oldT = (int) $findT->fetchColumn();
            if ($oldT > 0) {
                orange_advisory_sizing_library_delete_guide_cascade($pdo, $oldT);
            }

            orange_advisory_sizing_library_clone_guide_to_family($pdo, $srcGuideId, $consumerFamilyId, $map);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('[orange] advisory_sizing_library sync: ' . $e->getMessage());
        }

        return 'فشل المزامنة: ' . $e->getMessage();
    }

    return null;
}

/**
 * مزامنة عائلة مستهلك حسب الخريطة المحفوظة.
 *
 * @return string|null
 */
function orange_advisory_sizing_library_sync_mapped_consumer(PDO $pdo, int $consumerFamilyId): ?string
{
    if ($consumerFamilyId <= 0) {
        return 'عائلة مستهلك غير صالحة.';
    }
    $st = $pdo->prepare(
        'SELECT library_bundle_id FROM size_family_advisory_library_map WHERE consumer_size_family_id = ? LIMIT 1'
    );
    $st->execute([$consumerFamilyId]);
    $bid = (int) $st->fetchColumn();
    if ($bid <= 0) {
        return 'لا يوجد ربط مكتبة لهذه العائلة — احفظ الربط أولاً.';
    }
    $bSt = $pdo->prepare('SELECT source_size_family_id, commercial_kind_key FROM advisory_sizing_library_bundles WHERE id = ? LIMIT 1');
    $bSt->execute([$bid]);
    $b = $bSt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($b)) {
        return 'حزمة المكتبة غير موجودة.';
    }
    $srcFam = (int) ($b['source_size_family_id'] ?? 0);
    if ($srcFam <= 0) {
        return 'عائلة المصدر في الحزمة غير صالحة.';
    }

    $ckBundle = trim((string) ($b['commercial_kind_key'] ?? ''));
    if ($ckBundle !== '' && orange_table_exists($pdo, 'size_families')) {
        $fSt = $pdo->prepare('SELECT commercial_kind_key FROM size_families WHERE id = ? LIMIT 1');
        $fSt->execute([$consumerFamilyId]);
        $ckCons = trim((string) $fSt->fetchColumn());
        if ($ckCons !== '' && $ckCons !== $ckBundle) {
            return 'النوع التجاري لعائلة المستهلك («' . $ckCons . '») لا يطابق مفتاح الحزمة («' . $ckBundle . '»).';
        }
    }

    return orange_advisory_sizing_library_sync_consumer_from_source($pdo, $srcFam, $consumerFamilyId);
}
