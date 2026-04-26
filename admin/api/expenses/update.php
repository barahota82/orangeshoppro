<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/expense_gl.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_admin_api();

/**
 * @param array<string, mixed> $row
 * @return array{debit: int, credit: int}
 */
function orange_expense_pair_from_expense_row(PDO $pdo, array $row): array
{
    $oid = (int) ($row['expense_account_id'] ?? 0);

    return orange_expense_gl_accounts($pdo, $oid);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    $action = trim((string)($data['action'] ?? 'update'));
    $name = trim((string)($data['name'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $notes = trim((string)($data['notes'] ?? ''));
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف المصروف مطلوب'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'المصروف غير موجود'], 404);
    }

    $pdo->beginTransaction();
    if ($action === 'delete') {
        $oldAmount = (float) $row['amount'];
        $pair = orange_expense_gl_pair_for_delete_row($pdo, $row);
        $revPair = orange_expense_gl_reversal_pair($pair);
        $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
        orange_gl_pending_remove_by_reference($pdo, 'EXP-' . $id);
        $now = date('Y-m-d H:i:s');
        $refDel = 'EXP-DEL-' . $id;
        $rev = [
            'reference' => $refDel,
            'source_label' => $refDel,
            'movement_at' => $now,
            'voucher_date' => $now,
            'account_debit' => $revPair['debit'],
            'account_credit' => $revPair['credit'],
            'amount' => $oldAmount,
            'description' => 'عكس مصروف — حذف السجل',
            'entry_type' => 'expense_reversal',
        ];
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_simple($pdo, $rev);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $revPair['debit'],
                'account_credit' => $revPair['credit'],
                'amount' => $oldAmount,
                'reference' => $refDel,
                'description' => 'عكس مصروف — حذف السجل',
                'entry_type' => 'expense_reversal',
            ]);
        }
        $pdo->commit();
        audit_log('expense_delete', 'تم حذف المصروف رقم: ' . $id, 'expenses', $id);
        json_response(['success' => true, 'message' => 'تم حذف المصروف']);
    }

    if ($name === '' || $amount <= 0) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'بيانات المصروف غير صحيحة'], 422);
    }

    if (!orange_table_has_column($pdo, 'expenses', 'expense_account_id')) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'قاعدة البيانات تحتاج عمود expense_account_id في جدول المصروفات.'], 422);
    }
    $expAccNew = array_key_exists('expense_account_id', $data) ? (int) $data['expense_account_id'] : (int) ($row['expense_account_id'] ?? 0);
    if ($expAccNew <= 0) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'اختر حساب مصروف من الدليل — إلزامي.'], 422);
    }
    if (!orange_accounts_account_is_posting_leaf($pdo, $expAccNew) || orange_accounts_account_pl_role($pdo, $expAccNew) !== 'expense') {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'حساب المصروف يجب أن يكون ورقة ترحيل ضمن جذر المصروفات.'], 422);
    }
    $oldAcc = (int) ($row['expense_account_id'] ?? 0);
    if ($oldAcc !== $expAccNew && orange_journal_vouchers_ready($pdo) && orange_voucher_by_reference($pdo, 'EXP-' . $id)) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => 'لا يمكن تغيير حساب المصروف بعد ترحيل السند المحاسبي — استخدم قيداً يدوياً أو احذف السند وفق سياسة المنشأة.'], 422);
    }

    $oldAmount = (float) $row['amount'];
    $delta = $amount - $oldAmount;

    if (orange_table_has_column($pdo, 'expenses', 'notes')) {
        $pdo->prepare('UPDATE expenses SET name = ?, amount = ?, notes = ?, expense_account_id = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$name, $amount, $notes, $expAccNew, $id]);
    } else {
        $pdo->prepare('UPDATE expenses SET name = ?, amount = ?, expense_account_id = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$name, $amount, $expAccNew, $id]);
    }

    $rowForPair = $row;
    $rowForPair['expense_account_id'] = $expAccNew;
    $rowForPair['amount'] = $amount;
    $pair = orange_expense_pair_from_expense_row($pdo, $rowForPair);

    if (abs($delta) > 0.0001) {
        $now = date('Y-m-d H:i:s');
        if (orange_gl_use_pending_queue($pdo)) {
            $st = $pdo->prepare(
                'SELECT status FROM orange_gl_pending_movements WHERE reference = ? LIMIT 1'
            );
            $st->execute(['EXP-' . $id]);
            $pend = $st->fetch(PDO::FETCH_ASSOC);
            if ($pend && (string) ($pend['status'] ?? '') === 'pending') {
                orange_gl_pending_remove_by_reference($pdo, 'EXP-' . $id);
                orange_gl_pending_enqueue_simple($pdo, [
                    'reference' => 'EXP-' . $id,
                    'source_label' => 'EXP-' . $id,
                    'movement_at' => $now,
                    'voucher_date' => $now,
                    'account_debit' => $pair['debit'],
                    'account_credit' => $pair['credit'],
                    'amount' => $amount,
                    'description' => 'تسجيل مصروف — بعد تعديل (لم يُرحَّل بعد)',
                    'entry_type' => 'expense',
                ]);
            } else {
                $refAdj = 'EXP-ADJ-' . $id . '-' . str_replace('.', '', (string) microtime(true));
                if ($delta > 0) {
                    orange_gl_pending_enqueue_simple($pdo, [
                        'reference' => $refAdj,
                        'source_label' => 'EXP-' . $id,
                        'movement_at' => $now,
                        'voucher_date' => $now,
                        'account_debit' => $pair['debit'],
                        'account_credit' => $pair['credit'],
                        'amount' => $delta,
                        'description' => 'تعديل مصروف — زيادة',
                        'entry_type' => 'expense_adjustment',
                    ]);
                } else {
                    $revPair = orange_expense_gl_reversal_pair($pair);
                    orange_gl_pending_enqueue_simple($pdo, [
                        'reference' => $refAdj,
                        'source_label' => 'EXP-' . $id,
                        'movement_at' => $now,
                        'voucher_date' => $now,
                        'account_debit' => $revPair['debit'],
                        'account_credit' => $revPair['credit'],
                        'amount' => abs($delta),
                        'description' => 'تعديل مصروف — نقصان',
                        'entry_type' => 'expense_adjustment',
                    ]);
                }
            }
        } else {
            $refAdj = 'EXP-ADJ-' . $id . '-' . str_replace('.', '', (string) microtime(true));
            if ($delta > 0) {
                orange_journal_insert_line($pdo, [
                    'date' => $now,
                    'account_debit' => $pair['debit'],
                    'account_credit' => $pair['credit'],
                    'amount' => $delta,
                    'reference' => $refAdj,
                    'description' => 'تعديل مصروف — زيادة',
                    'entry_type' => 'expense_adjustment',
                ]);
            } else {
                $revPair = orange_expense_gl_reversal_pair($pair);
                orange_journal_insert_line($pdo, [
                    'date' => $now,
                    'account_debit' => $revPair['debit'],
                    'account_credit' => $revPair['credit'],
                    'amount' => abs($delta),
                    'reference' => $refAdj,
                    'description' => 'تعديل مصروف — نقصان',
                    'entry_type' => 'expense_adjustment',
                ]);
            }
        }
    }

    $pdo->commit();
    audit_log('expense_update', 'تم تعديل المصروف رقم: ' . $id, 'expenses', $id);
    json_response(['success' => true, 'message' => 'تم تعديل المصروف']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر تعديل المصروف');
}
