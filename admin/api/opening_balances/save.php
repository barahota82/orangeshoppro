<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_journal_vouchers_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 500);
    }

    $data = get_json_input();
    $fyId = (int)($data['fiscal_year_id'] ?? 0);
    $statement = trim((string)($data['statement'] ?? ''));
    $linesIn = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];
    if ($fyId <= 0 || count($linesIn) < 2) {
        json_response(['success' => false, 'message' => 'السنة وأسطر الأرصدة (سطران على الأقل) مطلوبة'], 422);
    }
    if ($statement === '') {
        json_response(['success' => false, 'message' => 'البيان مطلوب لقيد رصيد الافتتاح'], 422);
    }

    $fySt = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ? LIMIT 1');
    $fySt->execute([$fyId]);
    $fy = $fySt->fetch(PDO::FETCH_ASSOC);
    if (!$fy) {
        json_response(['success' => false, 'message' => 'السنة غير موجودة'], 404);
    }
    if ((int)$fy['is_closed'] === 1) {
        json_response(['success' => false, 'message' => 'لا يمكن تعديل أرصدة افتتاحية لسنة مغلقة'], 422);
    }

    $norm = [];
    foreach ($linesIn as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $norm[] = [
            'account_id' => (int)($ln['account_id'] ?? 0),
            'debit' => (float)($ln['debit'] ?? 0),
            'credit' => (float)($ln['credit'] ?? 0),
            'memo' => $statement,
        ];
    }

    foreach ($norm as $ln) {
        $aid = (int) ($ln['account_id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        if (($ln['debit'] ?? 0) <= 0 && ($ln['credit'] ?? 0) <= 0) {
            continue;
        }
        if (! orange_accounts_account_is_posting_leaf($pdo, $aid)) {
            json_response([
                'success' => false,
                'message' => 'يُقبل في أرصدة أول المدة المالية حساب فرعي (ورقة ترحيل) فقط — لا جذراً ولا مجلداً.',
            ], 422);
        }
    }

    $pdo->beginTransaction();
    try {
        $ex = $pdo->prepare('SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ?');
        $ex->execute([$fyId, 'opening_balance']);
        foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
            $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([(int)$oldId]);
        }

        orange_voucher_post($pdo, [
            'voucher_date' => $fy['start_date'] . ' 10:00:00',
            'reference' => 'OB-' . $fyId,
            'description' => $statement,
            'entry_type' => 'opening_balance',
        ], $norm);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit_log('opening_balance_save', 'تم حفظ أرصدة افتتاحية للسنة ' . $fyId, 'journal_vouchers', $fyId);
    json_response(['success' => true, 'message' => 'تم حفظ أرصدة أول المدة المالية']);
} catch (Throwable $e) {
    api_error($e, 'تعذر حفظ الأرصدة الافتتاحية');
}
