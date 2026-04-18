
<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $name = trim((string)($data['name'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    if ($name === '' || $amount <= 0) {
        json_response(['success' => false, 'message' => 'بيانات المصروف غير صحيحة'], 422);
    }

    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO expenses (name, amount) VALUES (?, ?)")
        ->execute([$name, $amount]);

    orange_journal_insert_line($pdo, [
        'date' => date('Y-m-d H:i:s'),
        'account_debit' => 6,
        'account_credit' => 1,
        'amount' => $amount,
        'reference' => 'EXP-' . date('YmdHis'),
        'description' => 'تسجيل مصروف من لوحة الإدارة',
        'entry_type' => 'expense',
    ]);

    $pdo->commit();
    audit_log('expense_create', 'تم تسجيل مصروف: ' . $name, 'expenses');
    json_response(['success' => true, 'message' => 'تم حفظ المصروف']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر حفظ المصروف');
}
