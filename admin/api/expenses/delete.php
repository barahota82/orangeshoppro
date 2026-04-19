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
    if ($id <= 0) {
        json_response(['success' => false, 'message' => 'معرف المصروف مطلوب'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM expenses WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $expense = $stmt->fetch();
    if (!$expense) {
        json_response(['success' => false, 'message' => 'المصروف غير موجود'], 404);
    }

    $amount = (float)$expense['amount'];

    $pdo->beginTransaction();
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
        'amount' => $amount,
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
            'amount' => $amount,
            'reference' => $refDel,
            'description' => 'عكس مصروف — حذف السجل',
            'entry_type' => 'expense_reversal',
        ]);
    }

    $pdo->commit();
    audit_log('expense_delete', 'تم حذف المصروف رقم: ' . $id, 'expenses', $id);
    json_response(['success' => true, 'message' => 'تم حذف المصروف']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, 'تعذر حذف المصروف');
}
