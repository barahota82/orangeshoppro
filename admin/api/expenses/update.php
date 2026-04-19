<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    $action = trim((string)($data['action'] ?? 'update'));
    $name = trim((string)($data['name'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف المصروف مطلوب'], 422);
    }

    $stmt = $pdo->prepare('SELECT id, amount FROM expenses WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) {
        json_response(['success' => false, 'message' => 'المصروف غير موجود'], 404);
    }

    $pdo->beginTransaction();
    if ($action === 'delete') {
        $oldAmount = (float)$old['amount'];
        $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
        orange_gl_pending_remove_by_reference($pdo, 'EXP-' . $id);
        $now = date('Y-m-d H:i:s');
        $refDel = 'EXP-DEL-' . $id;
        $rev = [
            'reference' => $refDel,
            'source_label' => $refDel,
            'movement_at' => $now,
            'voucher_date' => $now,
            'account_debit' => 1,
            'account_credit' => 6,
            'amount' => $oldAmount,
            'description' => 'عكس مصروف — حذف السجل',
            'entry_type' => 'expense_reversal',
        ];
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_simple($pdo, $rev);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => 1,
                'account_credit' => 6,
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
        json_response(['success' => false, 'message' => 'بيانات المصروف غير صحيحة'], 422);
    }
    $oldAmount = (float)$old['amount'];
    $delta = $amount - $oldAmount;

    $pdo->prepare('UPDATE expenses SET name = ?, amount = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$name, $amount, $id]);

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
                    'account_debit' => 6,
                    'account_credit' => 1,
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
                        'account_debit' => 6,
                        'account_credit' => 1,
                        'amount' => $delta,
                        'description' => 'تعديل مصروف — زيادة',
                        'entry_type' => 'expense_adjustment',
                    ]);
                } else {
                    orange_gl_pending_enqueue_simple($pdo, [
                        'reference' => $refAdj,
                        'source_label' => 'EXP-' . $id,
                        'movement_at' => $now,
                        'voucher_date' => $now,
                        'account_debit' => 1,
                        'account_credit' => 6,
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
                    'account_debit' => 6,
                    'account_credit' => 1,
                    'amount' => $delta,
                    'reference' => $refAdj,
                    'description' => 'تعديل مصروف — زيادة',
                    'entry_type' => 'expense_adjustment',
                ]);
            } else {
                orange_journal_insert_line($pdo, [
                    'date' => $now,
                    'account_debit' => 1,
                    'account_credit' => 6,
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
    api_error($e, 'تعذر تعديل المصروف');
}
