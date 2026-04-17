<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api();

try {
    $pdo = db();
    $data = get_json_input();
    $id = (int)($data['id'] ?? 0);
    $action = trim((string)($data['action'] ?? 'update'));
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف القيد مطلوب'], 422);
    }

    $stmt = $pdo->prepare("SELECT * FROM journal_entries WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    if (!$entry) {
        json_response(['success' => false, 'message' => 'القيد غير موجود'], 404);
    }

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM journal_entries WHERE id = ?")->execute([$id]);
        audit_log('journal_delete', 'تم حذف قيد محاسبي رقم: ' . $id, 'journal_entries', $id);
        json_response(['success' => true, 'message' => 'تم حذف القيد']);
    }

    $amount = (float)($data['amount'] ?? $entry['amount']);
    $reference = trim((string)($data['reference'] ?? $entry['reference']));
    $description = trim((string)($data['description'] ?? $entry['description']));
    $accountDebit = (int)($data['account_debit'] ?? $entry['account_debit']);
    $accountCredit = (int)($data['account_credit'] ?? $entry['account_credit']);
    if ($amount <= 0 || $accountDebit <= 0 || $accountCredit <= 0) {
        json_response(['success' => false, 'message' => 'بيانات القيد غير صحيحة'], 422);
    }

    $pdo->prepare("
        UPDATE journal_entries
        SET account_debit = ?, account_credit = ?, amount = ?, reference = ?, description = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$accountDebit, $accountCredit, $amount, $reference, $description, $id]);

    audit_log('journal_update', 'تم تعديل قيد محاسبي رقم: ' . $id, 'journal_entries', $id);
    json_response(['success' => true, 'message' => 'تم تعديل القيد']);
} catch (Throwable $e) {
    api_error($e, 'تعذر معالجة القيد');
}
