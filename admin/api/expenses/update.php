<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
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

    $stmt = $pdo->prepare("SELECT id, amount FROM expenses WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) {
        json_response(['success' => false, 'message' => 'المصروف غير موجود'], 404);
    }

    $pdo->beginTransaction();
    if ($action === 'delete') {
        $oldAmount = (float)$old['amount'];
        $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
        orange_journal_insert_line($pdo, [
            'date' => date('Y-m-d H:i:s'),
            'account_debit' => 1,
            'account_credit' => 6,
            'amount' => $oldAmount,
            'reference' => 'EXP-DEL-' . $id,
            'description' => 'عكس مصروف — حذف السجل',
            'entry_type' => 'expense_reversal',
        ]);
        $pdo->commit();
        audit_log('expense_delete', 'تم حذف المصروف رقم: ' . $id, 'expenses', $id);
        json_response(['success' => true, 'message' => 'تم حذف المصروف']);
    }

    if ($name === '' || $amount <= 0) {
        json_response(['success' => false, 'message' => 'بيانات المصروف غير صحيحة'], 422);
    }
    $oldAmount = (float)$old['amount'];
    $delta = $amount - $oldAmount;

    $pdo->prepare("UPDATE expenses SET name = ?, amount = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$name, $amount, $id]);

    if (abs($delta) > 0.0001) {
        if ($delta > 0) {
            orange_journal_insert_line($pdo, [
                'date' => date('Y-m-d H:i:s'),
                'account_debit' => 6,
                'account_credit' => 1,
                'amount' => $delta,
                'reference' => 'EXP-ADJ-' . $id,
                'description' => 'تعديل مصروف — زيادة',
                'entry_type' => 'expense_adjustment',
            ]);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => date('Y-m-d H:i:s'),
                'account_debit' => 1,
                'account_credit' => 6,
                'amount' => abs($delta),
                'reference' => 'EXP-ADJ-' . $id,
                'description' => 'تعديل مصروف — نقصان',
                'entry_type' => 'expense_adjustment',
            ]);
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
