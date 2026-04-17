<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

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

    $pdo->beginTransaction();

    $keepIds = [];
    $hasFoot = orange_table_has_column($pdo, 'size_family_sizes', 'foot_length_cm');

    $ins = $hasFoot
        ? $pdo->prepare(
            'INSERT INTO size_family_sizes (size_family_id, label_ar, label_en, sort_order, foot_length_cm, is_active) VALUES (?,?,?,?,?,1)'
        )
        : $pdo->prepare(
            'INSERT INTO size_family_sizes (size_family_id, label_ar, label_en, sort_order, is_active) VALUES (?,?,?,?,1)'
        );
    $upd = $hasFoot
        ? $pdo->prepare(
            'UPDATE size_family_sizes SET label_ar=?, label_en=?, sort_order=?, foot_length_cm=? WHERE id=? AND size_family_id=? LIMIT 1'
        )
        : $pdo->prepare(
            'UPDATE size_family_sizes SET label_ar=?, label_en=?, sort_order=? WHERE id=? AND size_family_id=? LIMIT 1'
        );

    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $sid = (int)($row['id'] ?? 0);
        $la = trim((string)($row['label_ar'] ?? ''));
        $le = trim((string)($row['label_en'] ?? ''));
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
        if ($sid > 0) {
            if ($hasFoot) {
                $upd->execute([$la, $le, $so, $foot, $sid, $familyId]);
            } else {
                $upd->execute([$la, $le, $so, $sid, $familyId]);
            }
            $keepIds[] = $sid;
        } else {
            if ($hasFoot) {
                $ins->execute([$familyId, $la, $le, $so, $foot]);
            } else {
                $ins->execute([$familyId, $la, $le, $so]);
            }
            $keepIds[] = (int) $pdo->lastInsertId();
        }
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
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
