<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_journal_vouchers_ready($pdo)) {
        json_response(['success' => false, 'message' => 'جداول السندات غير جاهزة'], 500);
    }

    $data = get_json_input();
    $fyId = (int) ($data['fiscal_year_id'] ?? 0);
    if ($fyId <= 0) {
        json_response(['success' => false, 'message' => 'السنة المالية مطلوبة'], 422);
    }

    $fySt = $pdo->prepare('SELECT * FROM fiscal_years WHERE id = ? LIMIT 1');
    $fySt->execute([$fyId]);
    $fy = $fySt->fetch(PDO::FETCH_ASSOC);
    if (!$fy) {
        json_response(['success' => false, 'message' => 'السنة غير موجودة'], 404);
    }
    if ((int) $fy['is_closed'] === 1) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن حذف أرصدة افتتاحية لسنة مغلقة',
            'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
        ], 422);
    }

    $pdo->beginTransaction();
    try {
        $ex = $pdo->prepare('SELECT id FROM journal_vouchers WHERE fiscal_year_id = ? AND entry_type = ?');
        $ex->execute([$fyId, 'opening_balance']);
        $ids = $ex->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $oldId) {
            $pdo->prepare('DELETE FROM journal_vouchers WHERE id = ?')->execute([(int) $oldId]);
        }
        orange_gl_pending_remove_by_reference($pdo, 'OB-' . $fyId);
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
