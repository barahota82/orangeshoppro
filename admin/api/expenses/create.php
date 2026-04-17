
<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();

    $name = trim((string)($data['name'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    if ($name === '' || $amount <= 0) {
        json_response(['success' => false, 'message' => 'بيانات المصروف غير صحيحة'], 422);
    }

    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO expenses (name, amount) VALUES (?, ?)")
        ->execute([$name, $amount]);

    $pdo->prepare("
        INSERT INTO journal_entries (date, account_debit, account_credit, amount, reference, description, entry_type)
        VALUES (NOW(), 6, 1, ?, ?, ?, 'expense')
    ")->execute([$amount, 'EXP-' . date('YmdHis'), 'Expense recorded from admin panel']);

    $pdo->commit();
    audit_log('expense_create', 'تم تسجيل مصروف: ' . $name, 'expenses');
    json_response(['success' => true, 'message' => 'تم حفظ المصروف']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر حفظ المصروف');
}
