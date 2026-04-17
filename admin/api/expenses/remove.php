<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف المصروف مطلوب'], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM expenses WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $expense = $stmt->fetch();
    if (!$expense) {
        json_response(['success' => false, 'message' => 'المصروف غير موجود'], 404);
    }

    $amount = (float)$expense['amount'];

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
    $pdo->prepare("
        INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
        VALUES (NOW(), 1, 6, ?, ?, ?, 'expense_reversal')
    ")->execute([$amount, 'EXP-DEL-' . $id, 'Expense deleted - reversal entry']);

    $pdo->commit();
    audit_log('expense_delete', 'تم حذف المصروف رقم: ' . $id, 'expenses', $id);
    json_response(['success' => true, 'message' => 'تم حذف المصروف']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر حذف المصروف');
}
