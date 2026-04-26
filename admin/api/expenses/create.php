<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/expense_gl.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $name = trim((string)($data['name'] ?? ''));
    $amount = (float)($data['amount'] ?? 0);
    $expAccRaw = isset($data['expense_account_id']) ? (int) $data['expense_account_id'] : 0;
    $notes = trim((string)($data['notes'] ?? ''));
    if ($name === '' || $amount <= 0) {
        json_response(['success' => false, 'message' => 'بيانات المصروف غير صحيحة'], 422);
    }

    if (!orange_table_has_column($pdo, 'expenses', 'expense_account_id')) {
        json_response(['success' => false, 'message' => 'قاعدة البيانات تحتاج عمود expense_account_id في جدول المصروفات.'], 422);
    }
    if ($expAccRaw <= 0) {
        json_response(['success' => false, 'message' => 'اختر حساب مصروف من الدليل — إلزامي (لا يُستخدم حساب مصروف مجمع).'], 422);
    }

    $pair = orange_expense_gl_accounts($pdo, $expAccRaw);

    $pdo->beginTransaction();

    if (orange_table_has_column($pdo, 'expenses', 'notes')) {
        $pdo->prepare('INSERT INTO expenses (name, amount, expense_account_id, notes) VALUES (?, ?, ?, ?)')
            ->execute([$name, $amount, $expAccRaw, $notes]);
    } else {
        $pdo->prepare('INSERT INTO expenses (name, amount, expense_account_id) VALUES (?, ?, ?)')
            ->execute([$name, $amount, $expAccRaw]);
    }
    $expenseId = (int) $pdo->lastInsertId();

    $now = date('Y-m-d H:i:s');
    $purRef = 'EXP-' . $expenseId;
    $desc = 'تسجيل مصروف: ' . $name;
    $row = [
        'reference' => $purRef,
        'source_label' => $purRef,
        'movement_at' => $now,
        'voucher_date' => $now,
        'account_debit' => $pair['debit'],
        'account_credit' => $pair['credit'],
        'amount' => $amount,
        'description' => $desc,
        'entry_type' => 'expense',
    ];
    if (orange_gl_use_pending_queue($pdo)) {
        orange_gl_pending_enqueue_simple($pdo, $row);
    } else {
        orange_journal_insert_line($pdo, [
            'date' => $now,
            'account_debit' => $pair['debit'],
            'account_credit' => $pair['credit'],
            'amount' => $amount,
            'reference' => $purRef,
            'description' => $desc,
            'entry_type' => 'expense',
        ]);
    }

    $pdo->commit();
    audit_log('expense_create', 'تم تسجيل مصروف: ' . $name, 'expenses');
    json_response(['success' => true, 'message' => 'تم حفظ المصروف']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر حفظ المصروف');
}
