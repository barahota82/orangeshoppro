<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
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
        $pdo->prepare("
            INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
            VALUES (NOW(), 1, 6, ?, ?, ?, 'expense_reversal')
        ")->execute([$oldAmount, 'EXP-DEL-' . $id, 'Expense deleted - reversal entry']);
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
            $pdo->prepare("
                INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
                VALUES (NOW(), 6, 1, ?, ?, ?, 'expense_adjustment')
            ")->execute([$delta, 'EXP-ADJ-' . $id, 'Expense increased by adjustment']);
        } else {
            $pdo->prepare("
                INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
                VALUES (NOW(), 1, 6, ?, ?, ?, 'expense_adjustment')
            ")->execute([abs($delta), 'EXP-ADJ-' . $id, 'Expense decreased by adjustment']);
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
