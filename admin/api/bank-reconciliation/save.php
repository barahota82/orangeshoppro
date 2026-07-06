<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/bank_reconciliation.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (! orange_bank_reconciliation_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول تسوية البنك غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? 'save'));

    if ($action === 'delete') {
        $id = (int) ($data['id'] ?? 0);
        if (! orange_bank_reconciliation_delete_draft($pdo, $id, $ctxCountryId)) {
            json_response(['success' => false, 'message' => 'تعذّر الحذف — مسودة فقط'], 422);
        }
        audit_log('bank_reconciliation_delete', 'حذف مسودة تسوية بنك #' . $id, 'bank_reconciliation', $id);
        json_response(['success' => true, 'message' => 'تم حذف المسودة']);
    }

    $linesIn = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];
    $lines = orange_bank_reconciliation_normalize_lines($linesIn);

    $id = orange_bank_reconciliation_save($pdo, [
        'id' => (int) ($data['id'] ?? 0),
        'account_id' => (int) ($data['account_id'] ?? 0),
        'fiscal_year_id' => (int) ($data['fiscal_year_id'] ?? 0),
        'period_from' => trim((string) ($data['period_from'] ?? '')),
        'period_to' => trim((string) ($data['period_to'] ?? '')),
        'statement_balance' => (float) ($data['statement_balance'] ?? 0),
        'notes' => trim((string) ($data['notes'] ?? '')),
    ], $lines, $ctxCountryId);

    audit_log('bank_reconciliation_save', 'حفظ تسوية بنك #' . $id, 'bank_reconciliation', $id);

    $rec = orange_bank_reconciliation_get($pdo, $id, $ctxCountryId);
    json_response([
        'success' => true,
        'message' => 'تم حفظ تسوية البنك',
        'id' => $id,
        'reconciliation' => $rec,
    ]);
} catch (InvalidArgumentException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ تسوية البنك');
}
