<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_once __DIR__ . '/../../../includes/payments/payment_core.php';
require_admin_api();

try {
    $pdo = db();
    orange_payments_ensure_schema($pdo);
    $cid = (int) orange_admin_context_country_id($pdo);
    $data = get_json_input();
    $action = (string) ($data['action'] ?? $_GET['action'] ?? 'list');

    if ($action === 'list') {
        json_response([
            'success' => true,
            'bank_method_active' => orange_payment_bank_method_active($pdo, $cid),
            'accounts' => orange_payment_bank_accounts($pdo, $cid, false),
            'default_currency' => orange_country_functional_currency_code($pdo, $cid),
        ]);
    }

    if ($action === 'toggle_method') {
        $active = (int) ($data['active'] ?? 0) === 1;
        orange_payment_set_method_active($pdo, $cid > 0 ? $cid : null, 'bank', 'manual', $active);
        audit_log('payment_method_toggle', 'تحويل بنكي=' . ($active ? '1' : '0'), 'payment_methods', $cid);
        json_response(['success' => true, 'message' => $active ? 'تم تفعيل التحويل البنكي' : 'تم إيقاف التحويل البنكي']);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $bank = trim((string) ($data['bank_name'] ?? ''));
        $accName = trim((string) ($data['account_name'] ?? ''));
        $accNo = trim((string) ($data['account_number'] ?? ''));
        $iban = trim((string) ($data['iban'] ?? ''));
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        $isActive = (int) ($data['is_active'] ?? 1) === 1 ? 1 : 0;
        $sort = (int) ($data['sort_order'] ?? 0);
        if ($bank === '' || ($accNo === '' && $iban === '')) {
            json_response(['success' => false, 'message' => 'اسم البنك ورقم الحساب أو IBAN مطلوبة'], 422);
        }
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = orange_country_functional_currency_code($pdo, $cid);
        }
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT country_id FROM company_bank_accounts WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                json_response(['success' => false, 'message' => 'الحساب غير موجود'], 404);
            }
            $rc = (int) ($row['country_id'] ?? 0);
            if ($cid > 0 && $rc > 0 && $rc !== $cid) {
                json_response(['success' => false, 'message' => 'لا يمكن تعديل حساب دولة أخرى'], 403);
            }
            $pdo->prepare(
                'UPDATE company_bank_accounts SET bank_name=?, account_name=?, account_number=?, iban=?, currency=?, is_active=?, sort_order=? WHERE id=?'
            )->execute([$bank, $accName, $accNo, $iban, $currency, $isActive, $sort, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO company_bank_accounts (country_id, bank_name, account_name, account_number, iban, currency, is_active, sort_order)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([$cid > 0 ? $cid : null, $bank, $accName, $accNo, $iban, $currency, $isActive, $sort]);
            $id = (int) $pdo->lastInsertId();
        }
        audit_log('bank_account_save', 'حساب بنكي #' . $id, 'company_bank_accounts', $id);
        json_response(['success' => true, 'message' => 'تم حفظ الحساب البنكي', 'id' => $id]);
    }

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'معرف غير صالح'], 422);
        }
        $chk = $pdo->prepare('SELECT country_id FROM company_bank_accounts WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $rc = (int) ($row['country_id'] ?? 0);
            if ($cid > 0 && $rc > 0 && $rc !== $cid) {
                json_response(['success' => false, 'message' => 'لا يمكن حذف حساب دولة أخرى'], 403);
            }
            $pdo->prepare('DELETE FROM company_bank_accounts WHERE id = ?')->execute([$id]);
            audit_log('bank_account_delete', 'حساب بنكي #' . $id, 'company_bank_accounts', $id);
        }
        json_response(['success' => true, 'message' => 'تم الحذف']);
    }

    json_response(['success' => false, 'message' => 'Action غير مدعوم'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنفيذ العملية');
}
