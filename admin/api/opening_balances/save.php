<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/account_tree.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_journal_vouchers_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);

    $data = get_json_input();
    $statement = trim((string)($data['statement'] ?? ''));
    $linesIn = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];
    if (count($linesIn) < 2) {
        json_response(['success' => false, 'message' => 'أسطر الأرصدة (سطران على الأقل) مطلوبة'], 422);
    }
    if ($statement === '') {
        json_response(['success' => false, 'message' => 'البيان مطلوب لقيد رصيد الافتتاح'], 422);
    }

    $dateIso = trim((string) ($data['voucher_date'] ?? ''));
    if ($dateIso === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIso)) {
        json_response(['success' => false, 'message' => 'تاريخ السند مطلوب بصيغة صحيحة (يوم/شهر/سنة)'], 422);
    }
    try {
        $fyId = orange_fiscal_require_open_for_posting($pdo, $dateIso . ' 12:00:00', $ctxCountryId);
    } catch (Throwable $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 422);
    }

    $admin = current_admin();
    if (!$admin) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    try {
        orange_edit_lock_assert_may_mutate($pdo, $admin, 'opening_balance', $fyId, 'edit', $ctxCountryId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 422);
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
        try {
            orange_admin_assert_entity_country($pdo, 'accounts', $aid);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    $jvCountrySql = '';
    $jvCountryParams = [];
    if ($ctxCountryId > 0 && orange_table_has_country_id($pdo, 'journal_vouchers')) {
        $jvCountrySql = ' AND country_id = ?';
        $jvCountryParams = [$ctxCountryId];
    }

    $pdo->beginTransaction();
    try {
        $useQueue = orange_gl_use_pending_queue($pdo);
        $ex = $pdo->prepare('SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ?' . $jvCountrySql);
        $ex->execute(array_merge([$fyId, 'opening_balance'], $jvCountryParams));
        foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
            $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([(int)$oldId]);
        }

        orange_opening_balance_clear_pending_refs($pdo, $fyId, $ctxCountryId);

        require_once __DIR__ . '/../../../includes/gl_posting_time.php';
        if ($ctxCountryId <= 0) {
            throw new RuntimeException('دولة الأرصدة الافتتاحية مطلوبة');
        }
        $obTimes = orange_gl_posting_times_for_country($pdo, $ctxCountryId, $dateIso);
        $obDate = $obTimes['voucher_date'];
        $obMovement = $obTimes['movement_at'];
        $obPendingKey = orange_gl_pending_source_key('opening_balance', $fyId);
        if ($useQueue) {
            $pendingOb = orange_gl_pending_enqueue_multi(
                $pdo,
                $norm,
                $obPendingKey,
                'OBV-' . orange_opening_balance_country_code($pdo, $ctxCountryId),
                $obMovement,
                $obDate,
                $statement,
                'opening_balance'
            );
            if ($pendingOb <= 0) {
                throw new RuntimeException('تعذر إدراج أرصدة أول المدة في طابور الترحيل.');
            }
        } else {
            orange_voucher_post($pdo, [
                'voucher_date' => $obDate,
                'document_entered_at' => $obMovement,
                'description' => $statement,
                'entry_type' => 'opening_balance',
                'country_id' => $ctxCountryId,
            ], $norm);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $obVid = 0;
    $exOb = $pdo->prepare('SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ?' . $jvCountrySql . ' LIMIT 1');
    $exOb->execute(array_merge([$fyId, 'opening_balance'], $jvCountryParams));
    $obVid = (int) ($exOb->fetchColumn() ?: 0);
    orange_edit_lock_register_opening_balance(
        $pdo,
        $fyId,
        $ctxCountryId,
        $statement,
        $obVid > 0 ? $obVid : null,
        $dateIso . ' 10:00:00'
    );
    orange_edit_lock_log_mutation($pdo, 'opening_balance', $fyId, 'edit');
    orange_edit_lock_force_lock($pdo, 'opening_balance', $fyId, $ctxCountryId, (int) ($admin['id'] ?? 0));
    audit_log(
        'edit_lock_lock',
        'قفل تلقائي بعد الحفظ — رصيد افتتاحي للسنة ' . $fyId,
        'journal_vouchers',
        $fyId
    );

    audit_log('opening_balance_save', 'تم حفظ أرصدة افتتاحية للسنة ' . $fyId, 'journal_vouchers', $fyId);
    $msg = isset($useQueue) && $useQueue
        ? 'تم حفظ أرصدة أول المدة في طابور الترحيل — أكمل من «إقفال الحركات»'
        : 'تم حفظ أرصدة أول المدة المالية';
    json_response(['success' => true, 'message' => $msg]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر حفظ الأرصدة الافتتاحية');
}
