<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/arabic_name_duplicate.php';
require_admin_api();

/**
 * تحديث سطر القالب وجميع مقاسات العائلات المربوطة به (مزامنة ثنائية).
 */
function orange_catalog_sync_template_size_to_linked_families(
    PDO $pdo,
    int $templateSizeId,
    string $la,
    string $le,
    string $lf,
    string $lh,
    int $sortOrder
): void {
    $wu = $pdo->prepare(
        'UPDATE size_scheme_template_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=? WHERE id=? LIMIT 1'
    );
    $wu->execute([$la, $le, $lf, $lh, $sortOrder, $templateSizeId]);
    $wf = $pdo->prepare(
        'UPDATE size_family_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=? WHERE scheme_template_size_id=?'
    );
    $wf->execute([$la, $le, $lf, $lh, $sortOrder, $templateSizeId]);
}

function orange_tpl_slugify_key(string $s): string
{
    $s = strtolower(trim($s));
    $s = (string) (preg_replace('/[^a-z0-9_-]+/', '_', $s) ?? '');
    $s = (string) (preg_replace('/_+/', '_', $s) ?? $s);
    $s = trim($s, '_');

    return $s;
}

/**
 * @param array<string,mixed> $famRow
 * @param array<string,mixed> $tplRow
 */
function orange_catalog_family_matches_template_guess(array $famRow, array $tplRow): bool
{
    $tplId = (int) ($tplRow['id'] ?? 0);
    $tplNameEn = (string) ($tplRow['name_en'] ?? '');
    if ($tplId <= 0) {
        return false;
    }

    $tplSlug = orange_tpl_slugify_key($tplNameEn);
    $fallbackTplSlug = 'tpl_' . $tplId;
    if ($tplSlug === '') {
        $tplSlug = $fallbackTplSlug;
    }

    $scheme = orange_tpl_slugify_key((string) ($famRow['size_scheme_key'] ?? ''));
    $cat = orange_tpl_slugify_key((string) ($famRow['sizing_category_key'] ?? ''));
    if ($scheme !== '' && $cat !== '' && strpos($scheme, $cat . '_') === 0) {
        $suffix = substr($scheme, strlen($cat) + 1);
        if ($suffix === $tplSlug || $suffix === $fallbackTplSlug) {
            return true;
        }
    }
    if ($scheme !== '') {
        if ($scheme === $tplSlug || $scheme === $fallbackTplSlug) {
            return true;
        }
        $tail1 = '_' . $tplSlug;
        $tail2 = '_' . $fallbackTplSlug;
        if (strlen($scheme) > strlen($tail1) && substr($scheme, -strlen($tail1)) === $tail1) {
            return true;
        }
        if (strlen($scheme) > strlen($tail2) && substr($scheme, -strlen($tail2)) === $tail2) {
            return true;
        }
    }

    $famEn = strtolower(trim((string) ($famRow['name_en'] ?? '')));
    $tplEn = strtolower(trim($tplNameEn));
    if ($famEn !== '' && $tplEn !== '') {
        if ($famEn === $tplEn) {
            return true;
        }
        $needle = ' - ' . $tplEn;
        if (strlen($famEn) > strlen($needle) && substr($famEn, -strlen($needle)) === $needle) {
            return true;
        }
    }

    return false;
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

    $pdo->beginTransaction();

    $keepIds = [];
    $hasSfLang = orange_table_has_column($pdo, 'size_family_sizes', 'label_fil')
        && orange_table_has_column($pdo, 'size_family_sizes', 'label_hi');
    $hasTplLink = orange_table_exists($pdo, 'size_family_sizes')
        && orange_table_has_column($pdo, 'size_family_sizes', 'scheme_template_size_id');
    $syncTemplateId = (int) ($data['sync_template_id'] ?? 0);
    $hasFamTplRef = orange_table_exists($pdo, 'size_families')
        && orange_table_has_column($pdo, 'size_families', 'size_scheme_template_id');
    $famMeta = null;
    if ($hasFamTplRef) {
        $famMetaStmt = $pdo->prepare(
            'SELECT id, name_en, size_scheme_key, sizing_category_key, COALESCE(size_scheme_template_id,0) AS size_scheme_template_id
             FROM size_families WHERE id = ? LIMIT 1'
        );
        $famMetaStmt->execute([$familyId]);
        $f = $famMetaStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($f)) {
            $famMeta = $f;
        }
    }
    if ($syncTemplateId <= 0 && is_array($famMeta)) {
        $syncTemplateId = (int) ($famMeta['size_scheme_template_id'] ?? 0);
    }
    if ($syncTemplateId <= 0 && orange_table_exists($pdo, 'size_scheme_templates') && is_array($famMeta)) {
        $tplRowsGuess = $pdo->query('SELECT id, name_en FROM size_scheme_templates ORDER BY id ASC');
        foreach (($tplRowsGuess->fetchAll(PDO::FETCH_ASSOC) ?: []) as $tplGuess) {
            if (!is_array($tplGuess)) {
                continue;
            }
            if (orange_catalog_family_matches_template_guess($famMeta, $tplGuess)) {
                $syncTemplateId = (int) ($tplGuess['id'] ?? 0);
                break;
            }
        }
    }
    if ($hasFamTplRef && $syncTemplateId > 0 && is_array($famMeta)) {
        $storedTplId = (int) ($famMeta['size_scheme_template_id'] ?? 0);
        if ($storedTplId <= 0) {
            $pdo->prepare(
                'UPDATE size_families SET size_scheme_template_id = ? WHERE id = ? LIMIT 1'
            )->execute([$syncTemplateId, $familyId]);
        }
    }

    /** ترتيب مقاسات القالب (لربط الصف N من الطلب بسطر القالب N عند غياب scheme_template_size_id في JSON). */
    $tplOrderedIds = [];
    if ($hasTplLink && $syncTemplateId > 0) {
        $famStoredTpl = is_array($famMeta)
            ? (int) ($famMeta['size_scheme_template_id'] ?? 0)
            : 0;
        if ($famStoredTpl <= 0) {
            $famStoredTpl = $syncTemplateId;
        }
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
            'INSERT INTO size_scheme_template_sizes (template_id, label_ar, label_en, label_fil, label_hi, sort_order, is_active) VALUES (?,?,?,?,?,?,1)'
        );
    }

    $ins = $hasSfLang
        ? $pdo->prepare(
            'INSERT INTO size_family_sizes (size_family_id, label_ar, label_en, label_fil, label_hi, sort_order, is_active) VALUES (?,?,?,?,?,?,1)'
        )
        : $pdo->prepare(
            'INSERT INTO size_family_sizes (size_family_id, label_ar, label_en, sort_order, is_active) VALUES (?,?,?,?,1)'
        );
    $upd = $hasSfLang
        ? $pdo->prepare(
            'UPDATE size_family_sizes SET label_ar=?, label_en=?, label_fil=?, label_hi=?, sort_order=? WHERE id=? AND size_family_id=? LIMIT 1'
        )
        : $pdo->prepare(
            'UPDATE size_family_sizes SET label_ar=?, label_en=?, sort_order=? WHERE id=? AND size_family_id=? LIMIT 1'
        );

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
                orange_catalog_sync_template_size_to_linked_families($pdo, $tstId, $la, $le, $lf, $lh, $so);
                $keepIds[] = $sid;
            } else {
                if ($hasSfLang) {
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
                $insTplSz->execute([$syncTemplateId, $la, $le, $lf, $lh, $so]);
                $attachTpl = (int) $pdo->lastInsertId();
            }

            if ($hasSfLang) {
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
