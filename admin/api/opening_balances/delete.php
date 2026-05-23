<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_journal_vouchers_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 500);
    }

    $ctxCountryId = orange_admin_settings_effective_country_id($pdo);

    $data = get_json_input();
    $dateIso = trim((string) ($data['voucher_date'] ?? ''));
    if ($dateIso === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIso)) {
        json_response(['success' => false, 'message' => 'تاريخ السند مطلوب لتحديد السنة المالية'], 422);
    }
    try {
        $fyId = orange_fiscal_require_open_for_posting($pdo, $dateIso . ' 12:00:00', $ctxCountryId);
    } catch (Throwable $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 422);
    }

    $pdo->beginTransaction();
    try {
        $ex = $pdo->prepare('SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ?');
        $ex->execute([$fyId, 'opening_balance']);
        $ids = $ex->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $oldId) {
            $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([(int) $oldId]);
        }
        orange_opening_balance_clear_pending_refs($pdo, $fyId, $ctxCountryId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    audit_log('opening_balance_delete', 'تم حذف سند رصيد افتتاحي للسنة ' . $fyId, 'journal_vouchers', $fyId);
    json_response(['success' => true, 'message' => 'تم حذف سند الرصيد الافتتاحي']);
} catch (Throwable $e) {
    orange_gl_api_catch_json($e, 'تعذر حذف الرصيد الافتتاحي');
}
