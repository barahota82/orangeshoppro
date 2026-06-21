<?php

declare(strict_types=1);

/**
 * نسخ انتقائي (Solution 3) — أنواع اليوميات والدليل المحاسبي بين الدول.
 * للمشرف العام: اختيار صفوف من سياق الدولة الحالي ونسخها إلى دولة هدف (upsert بالكود).
 */

require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/journal_types.php';

/**
 * @return array{success:bool, message:string, inserted:int, updated:int, skipped:int, errors:list<string>}
 */
function orange_country_copy_journal_types_selective(
    PDO $pdo,
    int $sourceCountryId,
    int $targetCountryId,
    array $journalTypeIds
): array {
    $out = [
        'success' => false,
        'message' => '',
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => [],
    ];
    if ($sourceCountryId <= 0 || $targetCountryId <= 0) {
        $out['message'] = 'معرّف الدولة غير صالح.';

        return $out;
    }
    if ($sourceCountryId === $targetCountryId) {
        $out['message'] = 'لا يمكن النسخ إلى نفس الدولة.';

        return $out;
    }
    if (!orange_table_exists($pdo, 'journal_types')
        || !orange_journal_types_has_country_column($pdo)) {
        $out['message'] = 'جدول أنواع اليوميات غير جاهز للنسخ بين الدول.';

        return $out;
    }

    $ids = [];
    foreach ($journalTypeIds as $id) {
        $v = (int) $id;
        if ($v > 0) {
            $ids[$v] = $v;
        }
    }
    if ($ids === []) {
        $out['message'] = 'لم تُحدَّد أنواع يوميات للنسخ.';

        return $out;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        'SELECT id, code, name_ar, name_en, sort_order FROM journal_types
         WHERE country_id = ? AND id IN (' . $placeholders . ')
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute(array_merge([$sourceCountryId], array_values($ids)));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['message'] = 'لا توجد أنواع مطابقة في سياق الدولة المصدر.';

        return $out;
    }

    $stTgt = $pdo->prepare(
        'SELECT id FROM journal_types WHERE country_id = ? AND code = ? LIMIT 1'
    );
    $upd = $pdo->prepare(
        'UPDATE journal_types SET name_ar = ?, name_en = ?, sort_order = ? WHERE id = ? AND country_id = ?'
    );
    $ins = $pdo->prepare(
        'INSERT INTO journal_types (country_id, code, name_ar, name_en, sort_order) VALUES (?,?,?,?,?)'
    );

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = orange_journal_type_normalize_code((string) ($row['code'] ?? ''));
        if ($code === '') {
            ++$out['skipped'];
            $out['errors'][] = 'صف بدون كود — تُخطّى.';

            continue;
        }
        $nameAr = trim((string) ($row['name_ar'] ?? ''));
        $nameEn = trim((string) ($row['name_en'] ?? ''));
        $sort = (int) ($row['sort_order'] ?? 0);
        try {
            $stTgt->execute([$targetCountryId, $code]);
            $existingId = (int) ($stTgt->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $upd->execute([$nameAr, $nameEn, $sort, $existingId, $targetCountryId]);
                ++$out['updated'];
            } else {
                $ins->execute([$targetCountryId, $code, $nameAr, $nameEn, $sort]);
                ++$out['inserted'];
            }
        } catch (Throwable $e) {
            ++$out['skipped'];
            $out['errors'][] = $code . ': ' . $e->getMessage();
            if (function_exists('error_log')) {
                error_log('[orange] selective copy journal_type ' . $code . ': ' . $e->getMessage());
            }
        }
    }

    $total = $out['inserted'] + $out['updated'];
    $out['success'] = $total > 0;
    $out['message'] = $out['success']
        ? ('تم: ' . $out['inserted'] . ' جديد، ' . $out['updated'] . ' محدَّث'
            . ($out['skipped'] > 0 ? '، ' . $out['skipped'] . ' تُخطّى' : '') . '.')
        : ('لم يُنسخ أي نوع.' . ($out['errors'] !== [] ? ' ' . implode(' ', array_slice($out['errors'], 0, 3)) : ''));

    return $out;
}

/**
 * يجمع معرّفات الحسابات المختارة + كل أسلافها (لضمان شجرة صالحة في الهدف).
 *
 * @param list<int> $accountIds
 * @return list<int>
 */
function orange_country_collect_accounts_with_ancestors(PDO $pdo, int $countryId, array $accountIds): array
{
    if ($countryId <= 0 || !orange_table_exists($pdo, 'accounts')
        || !orange_table_has_column($pdo, 'accounts', 'country_id')) {
        return [];
    }
    $st = $pdo->prepare('SELECT id, parent_id FROM accounts WHERE country_id = ?');
    $st->execute([$countryId]);
    $byId = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $byId[$id] = (int) ($row['parent_id'] ?? 0);
        }
    }
    $out = [];
    foreach ($accountIds as $rawId) {
        $id = (int) $rawId;
        while ($id > 0 && !isset($out[$id])) {
            if (!isset($byId[$id])) {
                break;
            }
            $out[$id] = $id;
            $id = (int) $byId[$id];
        }
    }

    return array_values($out);
}

/**
 * @return array{success:bool, message:string, inserted:int, updated:int, skipped:int, ancestors_added:int, errors:list<string>}
 */
function orange_country_copy_accounts_selective(
    PDO $pdo,
    int $sourceCountryId,
    int $targetCountryId,
    array $accountIds
): array {
    $out = [
        'success' => false,
        'message' => '',
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'ancestors_added' => 0,
        'errors' => [],
    ];
    if ($sourceCountryId <= 0 || $targetCountryId <= 0) {
        $out['message'] = 'معرّف الدولة غير صالح.';

        return $out;
    }
    if ($sourceCountryId === $targetCountryId) {
        $out['message'] = 'لا يمكن النسخ إلى نفس الدولة.';

        return $out;
    }
    if (!orange_table_exists($pdo, 'accounts')
        || !orange_table_has_column($pdo, 'accounts', 'country_id')) {
        $out['message'] = 'جدول الحسابات غير جاهز للنسخ بين الدول.';

        return $out;
    }

    $selectedIds = [];
    foreach ($accountIds as $id) {
        $v = (int) $id;
        if ($v > 0) {
            $selectedIds[$v] = $v;
        }
    }
    if ($selectedIds === []) {
        $out['message'] = 'لم تُحدَّد حسابات للنسخ.';

        return $out;
    }

    $expanded = orange_country_collect_accounts_with_ancestors($pdo, $sourceCountryId, array_values($selectedIds));
    $out['ancestors_added'] = max(0, count($expanded) - count($selectedIds));
    if ($expanded === []) {
        $out['message'] = 'لا توجد حسابات مطابقة في سياق الدولة المصدر.';

        return $out;
    }

    require_once __DIR__ . '/country_provision.php';

    $placeholders = implode(',', array_fill(0, count($expanded), '?'));
    $st = $pdo->prepare('SELECT * FROM accounts WHERE country_id = ? AND id IN (' . $placeholders . ')');
    $st->execute(array_merge([$sourceCountryId], $expanded));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        $out['message'] = 'تعذّر قراءة الحسابات من المصدر.';

        return $out;
    }
    $rows = orange_country_accounts_sort_by_depth($rows);

    // خريطة code => id في الهدف (موجودة مسبقاً).
    $tgtByCode = [];
    $stTgtCodes = $pdo->prepare(
        'SELECT id, code FROM accounts WHERE country_id = ? AND code IS NOT NULL AND TRIM(code) <> \'\''
    );
    $stTgtCodes->execute([$targetCountryId]);
    foreach ($stTgtCodes->fetchAll(PDO::FETCH_ASSOC) ?: [] as $tr) {
        if (!is_array($tr)) {
            continue;
        }
        $c = trim((string) ($tr['code'] ?? ''));
        if ($c !== '') {
            $tgtByCode[$c] = (int) ($tr['id'] ?? 0);
        }
    }

    // خريطة id مصدر => code للأب.
    $srcCodeById = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sid = (int) ($row['id'] ?? 0);
        $c = trim((string) ($row['code'] ?? ''));
        if ($sid > 0 && $c !== '') {
            $srcCodeById[$sid] = $c;
        }
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string) ($row['code'] ?? ''));
        if ($code === '') {
            ++$out['skipped'];
            $out['errors'][] = 'حساب بدون كود — تُخطّى.';

            continue;
        }

        $oldParentId = (int) ($row['parent_id'] ?? 0);
        $newParentId = null;
        if ($oldParentId > 0) {
            $parentCode = $srcCodeById[$oldParentId] ?? '';
            if ($parentCode === '' || !isset($tgtByCode[$parentCode])) {
                ++$out['skipped'];
                $out['errors'][] = $code . ': الحساب الأب (' . $parentCode . ') غير موجود في الهدف — تُخطّى.';

                continue;
            }
            $newParentId = (int) $tgtByCode[$parentCode];
        }

        unset($row['id'], $row['updated_at']);
        $row['country_id'] = $targetCountryId;
        $row['parent_id'] = $newParentId;

        try {
            if (isset($tgtByCode[$code]) && (int) $tgtByCode[$code] > 0) {
                $existingId = (int) $tgtByCode[$code];
                $sets = [];
                $vals = [];
                foreach (['name', 'name_en', 'is_group', 'normal_balance', 'account_type', 'report_section', 'report_line_id', 'cashflow_section', 'is_suspended'] as $col) {
                    if (!array_key_exists($col, $row) || !orange_table_has_column($pdo, 'accounts', $col)) {
                        continue;
                    }
                    $sets[] = '`' . $col . '` = ?';
                    $vals[] = $row[$col];
                }
                if ($sets !== []) {
                    $vals[] = $existingId;
                    $vals[] = $targetCountryId;
                    $pdo->prepare(
                        'UPDATE accounts SET ' . implode(', ', $sets) . ' WHERE id = ? AND country_id = ?'
                    )->execute($vals);
                }
                ++$out['updated'];
            } else {
                $cols = array_keys($row);
                $sql = 'INSERT INTO accounts (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($row));
                $newId = (int) $pdo->lastInsertId();
                if ($newId > 0) {
                    $tgtByCode[$code] = $newId;
                    ++$out['inserted'];
                } else {
                    ++$out['skipped'];
                }
            }
        } catch (Throwable $e) {
            ++$out['skipped'];
            $out['errors'][] = $code . ': ' . $e->getMessage();
            if (function_exists('error_log')) {
                error_log('[orange] selective copy account ' . $code . ': ' . $e->getMessage());
            }
        }
    }

    $total = $out['inserted'] + $out['updated'];
    $out['success'] = $total > 0;
    $extra = $out['ancestors_added'] > 0
        ? (' (شمل ' . $out['ancestors_added'] . ' حساباً أباً تلقائياً)')
        : '';
    $out['message'] = $out['success']
        ? ('تم: ' . $out['inserted'] . ' جديد، ' . $out['updated'] . ' محدَّث' . $extra
            . ($out['skipped'] > 0 ? '، ' . $out['skipped'] . ' تُخطّى' : '') . '.')
        : ('لم يُنسخ أي حساب.' . ($out['errors'] !== [] ? ' ' . implode(' ', array_slice($out['errors'], 0, 3)) : ''));

    return $out;
}
