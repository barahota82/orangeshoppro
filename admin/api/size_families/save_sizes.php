<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_admin_api();

/**
 * تحديث سطر القالب وجميع مقاسات العائلات المربوطة به (مزامنة ثنائية).
 *
 * @param float|null $foot
 */
function orange_catalog_sync_template_size_to_linked_families(
    PDO $pdo,
    int $templateSizeId,
    string $la,
    string $le,
    string $lf,
    string $lh,
    int $sortOrder,
    $foot
): void {
    $wu = $pdo->prepare(
        'UPDATE size_scheme_template_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=?, foot_length_cm=? WHERE id=? LIMIT 1'
    );
    $wu->execute([$la, $le, $lf, $lh, $sortOrder, $foot, $templateSizeId]);
    $wf = $pdo->prepare(
        'UPDATE size_family_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=?, foot_length_cm=? WHERE scheme_template_size_id=?'
    );
    $wf->execute([$la, $le, $lf, $lh, $sortOrder, $foot, $templateSizeId]);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $familyId = (int)($data['family_id'] ?? 0);
    if ($familyId <= 0) {
        json_response(['success' => false, 'message' => 'family_id required'], 422);
    }

    $check = $pdo->prepare('SELECT id FROM size_families WHERE id = ? LIMIT 1');
    $check->execute([$familyId]);
    if (!$check->fetch()) {
        json_response(['success' => false, 'message' => 'Family not found'], 404);
    }

    $rows = $data['sizes'] ?? null;
    if (!is_array($rows)) {
        json_response(['success' => false, 'message' => 'sizes array required'], 422);
    }

    $arSeenInPayload = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $la = trim((string)($row['label_ar'] ?? ''));
        $leDup = trim((string)($row['label_en'] ?? ''));
        if ($la === '' && $leDup !== '') {
            $la = $leDup;
        }
        if ($la === '') {
            continue;
        }
        $normKey = orange_normalize_arabic_name($la);
        if ($normKey === '') {
            continue;
        }
        if (isset($arSeenInPayload[$normKey])) {
            json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 422);
        }
        $arSeenInPayload[$normKey] = true;
    }

    $existingStmt = $pdo->prepare('SELECT id, label_ar FROM size_family_sizes WHERE size_family_id = ?');
    $existingStmt->execute([$familyId]);
    $dbSizeRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
    $dbSizeRows = is_array($dbSizeRows) ? $dbSizeRows : [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sid = (int)($row['id'] ?? 0);
        $la = trim((string)($row['label_ar'] ?? ''));
        $le = trim((string)($row['label_en'] ?? ''));
        if ($la === '' && $le !== '') {
            $la = $le;
        }
        if ($la === '' && $le === '') {
            continue;
        }
        if ($la === '') {
            continue;
        }
        $excludeSid = $sid > 0 ? $sid : null;
        if (orange_rows_normalized_arabic_conflict($dbSizeRows, 'id', 'label_ar', $la, $excludeSid)) {
            json_response(['success' => false, 'message' => orange_arabic_duplicate_blocked_message()], 409);
        }
    }

    $pdo->beginTransaction();

    $keepIds = [];
    $hasFoot = orange_table_has_column($pdo, 'size_family_sizes', 'foot_length_cm');
    $hasSfLang = orange_table_has_column($pdo, 'size_family_sizes', 'label_fil')
        && orange_table_has_column($pdo, 'size_family_sizes', 'label_hi');
    $hasTplLink = orange_table_exists($pdo, 'size_family_sizes')
        && orange_table_has_column($pdo, 'size_family_sizes', 'scheme_template_size_id');
    $syncTemplateId = (int) ($data['sync_template_id'] ?? 0);
    $hasFamTplRef = orange_table_exists($pdo, 'size_families')
        && orange_table_has_column($pdo, 'size_families', 'size_scheme_template_id');

    /** ترتيب مقاسات القالب (لربط الصف N من الطلب بسطر القالب N عند غياب scheme_template_size_id في JSON). */
    $tplOrderedIds = [];
    if ($hasTplLink && $hasFamTplRef && $syncTemplateId > 0) {
        $chkFamTpl = $pdo->prepare('SELECT COALESCE(size_scheme_template_id, 0) FROM size_families WHERE id = ? LIMIT 1');
        $chkFamTpl->execute([$familyId]);
        $famStoredTpl = (int) $chkFamTpl->fetchColumn();
        if ($famStoredTpl === $syncTemplateId) {
            $qTpl = $pdo->prepare(
                'SELECT id FROM size_scheme_template_sizes WHERE template_id = ? ORDER BY sort_order ASC, id ASC'
            );
            $qTpl->execute([$syncTemplateId]);
            $tplOrderedIds = array_map('intval', $qTpl->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }
    }

    $insTplSz = null;
    if ($hasTplLink && $syncTemplateId > 0) {
        $insTplSz = $pdo->prepare(
            'INSERT INTO size_scheme_template_sizes (template_id, label_ar, label_en, label_fil, label_hi, sort_order, foot_length_cm, is_active) VALUES (?,?,?,?,?,?,?,1)'
        );
    }

    $ins = $hasFoot && $hasSfLang
        ? $pdo->prepare(
            'INSERT INTO size_family_sizes (size_family_id, label_ar, label_en, label_fil, label_hi, sort_order, foot_length_cm, is_active) VALUES (?,?,?,?,?,?,?,1)'
        )
        : ($hasFoot
        ? $pdo->prepare(
            'INSERT INTO size_family_sizes (size_family_id, label_ar, label_en, sort_order, foot_length_cm, is_active) VALUES (?,?,?,?,?,1)'
        )
        : ($hasSfLang
            ? $pdo->prepare(
                'INSERT INTO size_family_sizes (size_family_id, label_ar, label_en, label_fil, label_hi, sort_order, is_active) VALUES (?,?,?,?,?,?,1)'
            )
            : $pdo->prepare(
                'INSERT INTO size_family_sizes (size_family_id, label_ar, label_en, sort_order, is_active) VALUES (?,?,?,?,1)'
            )));
    $upd = $hasFoot && $hasSfLang
        ? $pdo->prepare(
            'UPDATE size_family_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=?, foot_length_cm=? WHERE id=? AND size_family_id=? LIMIT 1'
        )
        : ($hasFoot
        ? $pdo->prepare(
            'UPDATE size_family_sizes SET label_ar=?, label_en=?, sort_order=?, foot_length_cm=? WHERE id=? AND size_family_id=? LIMIT 1'
        )
        : ($hasSfLang
            ? $pdo->prepare(
                'UPDATE size_family_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=? WHERE id=? AND size_family_id=? LIMIT 1'
            )
            : $pdo->prepare(
                'UPDATE size_family_sizes SET label_ar=?, label_en=?, sort_order=? WHERE id=? AND size_family_id=? LIMIT 1'
            )));

    $linkStmt = $hasTplLink
        ? $pdo->prepare('UPDATE size_family_sizes SET scheme_template_size_id=? WHERE id=? AND size_family_id=? LIMIT 1')
        : null;
    $unlinkStmt = $hasTplLink
        ? $pdo->prepare('UPDATE size_family_sizes SET scheme_template_size_id=NULL WHERE id=? AND size_family_id=? LIMIT 1')
        : null;

    $ordIdx = 0;
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $sid = (int)($row['id'] ?? 0);
        $la = trim((string)($row['label_ar'] ?? ''));
        $le = trim((string)($row['label_en'] ?? ''));
        $lf = trim((string)($row['label_fil'] ?? ''));
        $lh = trim((string)($row['label_hi'] ?? ''));
        if ($la === '' && $le !== '') {
            $la = $le;
        }
        if ($lf === '' && $le !== '') {
            $lf = $le;
        }
        if ($lh === '' && $le !== '') {
            $lh = $le;
        }
        $so = (int)($row['sort_order'] ?? $i);
        $footRaw = trim((string)($row['foot_length_cm'] ?? ''));
        $foot = null;
        if ($footRaw !== '') {
            if (!is_numeric($footRaw)) {
                json_response(['success' => false, 'message' => 'طول القدم يجب أن يكون رقماً (سم)'], 422);
            }
            $foot = round((float) $footRaw, 2);
        }
        if ($la === '' && $le === '') {
            continue;
        }

        $tstId = $hasTplLink ? (int) ($row['scheme_template_size_id'] ?? 0) : 0;
        if ($tstId <= 0 && $syncTemplateId > 0 && $tplOrderedIds !== [] && isset($tplOrderedIds[$ordIdx])) {
            $tstId = $tplOrderedIds[$ordIdx];
        }
        if ($tstId > 0) {
            $vchk = $pdo->prepare('SELECT id FROM size_scheme_template_sizes WHERE id = ? LIMIT 1');
            $vchk->execute([$tstId]);
            if (!$vchk->fetch()) {
                $pdo->rollBack();
                json_response(['success' => false, 'message' => 'مرجع مقاس القالب غير صالح'], 422);
            }
        }

        if ($sid > 0) {
            if ($hasTplLink && $tstId > 0) {
                if ($linkStmt !== null) {
                    $linkStmt->execute([$tstId, $sid, $familyId]);
                }
                orange_catalog_sync_template_size_to_linked_families($pdo, $tstId, $la, $le, $lf, $lh, $so, $foot);
                $keepIds[] = $sid;
            } else {
                if ($hasFoot && $hasSfLang) {
                    $upd->execute([$la, $le, $lf, $lh, $so, $foot, $sid, $familyId]);
                } elseif ($hasFoot) {
                    $upd->execute([$la, $le, $so, $foot, $sid, $familyId]);
                } elseif ($hasSfLang) {
                    $upd->execute([$la, $le, $lf, $lh, $so, $sid, $familyId]);
                } else {
                    $upd->execute([$la, $le, $so, $sid, $familyId]);
                }
                if ($unlinkStmt !== null) {
                    $unlinkStmt->execute([$sid, $familyId]);
                }
                $keepIds[] = $sid;
            }
        } else {
            $attachTpl = 0;
            if ($hasTplLink && $tstId === 0 && $syncTemplateId > 0 && $insTplSz !== null) {
                $chkTpl = $pdo->prepare('SELECT id FROM size_scheme_templates WHERE id = ? LIMIT 1');
                $chkTpl->execute([$syncTemplateId]);
                if (!$chkTpl->fetch()) {
                    $pdo->rollBack();
                    json_response(['success' => false, 'message' => 'قالب المقاسات غير موجود'], 422);
                }
                $insTplSz->execute([$syncTemplateId, $la, $le, $lf, $lh, $so, $foot]);
                $attachTpl = (int) $pdo->lastInsertId();
            }

            if ($hasFoot && $hasSfLang) {
                $ins->execute([$familyId, $la, $le, $lf, $lh, $so, $foot]);
            } elseif ($hasFoot) {
                $ins->execute([$familyId, $la, $le, $so, $foot]);
            } elseif ($hasSfLang) {
                $ins->execute([$familyId, $la, $le, $lf, $lh, $so]);
            } else {
                $ins->execute([$familyId, $la, $le, $so]);
            }
            $newFamSid = (int) $pdo->lastInsertId();
            $keepIds[] = $newFamSid;

            if ($linkStmt !== null) {
                $linkVal = $tstId > 0 ? $tstId : $attachTpl;
                if ($linkVal > 0) {
                    $linkStmt->execute([$linkVal, $newFamSid, $familyId]);
                }
            }
        }
        $ordIdx++;
    }

    $keepIds = array_values(array_unique(array_filter($keepIds)));

    if ($keepIds) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $pdo->prepare(
            "DELETE FROM size_family_sizes WHERE size_family_id = ? AND id NOT IN ($placeholders)"
        )->execute(array_merge([$familyId], $keepIds));
    } else {
        $pdo->prepare('DELETE FROM size_family_sizes WHERE size_family_id = ?')->execute([$familyId]);
    }

    $pdo->commit();
    json_response(['success' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_admin_api_catch($e, 'تعذر حفظ مقاسات العائلة');
}
