<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../../includes/loyalty.php';
require_admin_api();

function loyalty_req_data(): array
{
    $data = get_json_input();
    if (is_array($data) && count($data) > 0) {
        return $data;
    }

    return $_POST;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'loyalty_settings') || !orange_table_exists($pdo, 'loyalty_ledger')) {
        json_response(['success' => false, 'message' => 'جداول الولاء غير متوفرة — حدّث المخطط.'], 422);
    }

    $data = loyalty_req_data();
    $action = trim((string) ($data['action'] ?? 'get'));
    $countryId = orange_admin_settings_effective_country_id($pdo);

    if ($action === 'get') {
        $row = orange_loyalty_settings($pdo, $countryId);
        if ($row === null || (int) ($row['country_id'] ?? -1) !== (int) $countryId) {
            $row = [
                'country_id' => $countryId,
                'is_active' => 0,
                'earn_rate' => 0,
                'point_value' => 0,
                'min_redeem_points' => 0,
                'max_redeem_pct' => 0,
                'expiry_months' => 0,
            ];
        }
        json_response(['success' => true, 'data' => $row]);
    }

    if ($action === 'save') {
        $isActive = (int) ($data['is_active'] ?? 0) === 1 ? 1 : 0;
        $earnRate = max(0.0, round((float) ($data['earn_rate'] ?? 0), 6));
        $pointValue = max(0.0, round((float) ($data['point_value'] ?? 0), 6));
        $minRedeem = max(0, (int) ($data['min_redeem_points'] ?? 0));
        $maxPct = (float) ($data['max_redeem_pct'] ?? 0);
        if ($maxPct < 0) {
            $maxPct = 0.0;
        }
        if ($maxPct > 100) {
            $maxPct = 100.0;
        }
        $maxPct = round($maxPct, 2);
        $expiryMonths = max(0, (int) ($data['expiry_months'] ?? 0));

        if ($isActive === 1 && ($earnRate <= 0 || $pointValue <= 0)) {
            json_response(['success' => false, 'message' => 'لتفعيل الولاء يجب إدخال «نقاط لكل وحدة» و«قيمة النقطة» أكبر من صفر.'], 422);
        }

        $exists = $pdo->prepare('SELECT id FROM loyalty_settings WHERE country_id = ? LIMIT 1');
        $exists->execute([$countryId]);
        $id = $exists->fetchColumn();
        if ($id !== false) {
            $pdo->prepare(
                'UPDATE loyalty_settings
                 SET is_active = ?, earn_rate = ?, point_value = ?, min_redeem_points = ?, max_redeem_pct = ?, expiry_months = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([$isActive, $earnRate, $pointValue, $minRedeem, $maxPct, $expiryMonths, (int) $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO loyalty_settings
                    (country_id, is_active, earn_rate, point_value, min_redeem_points, max_redeem_pct, expiry_months)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$countryId, $isActive, $earnRate, $pointValue, $minRedeem, $maxPct, $expiryMonths]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ إعدادات الولاء']);
    }

    if ($action === 'expire_run') {
        $res = orange_loyalty_expire_due($pdo, $countryId > 0 ? $countryId : null);
        json_response([
            'success' => true,
            'message' => 'تم تنفيذ انتهاء النقاط: ' . (int) $res['expired_points'] . ' نقطة في ' . (int) $res['layers'] . ' طبقة.',
            'data' => $res,
        ]);
    }

    if ($action === 'customer_ledger') {
        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($customerId <= 0) {
            json_response(['success' => false, 'message' => 'حدّد العميل.'], 422);
        }
        $balance = orange_loyalty_balance_points($pdo, $customerId);
        $st = $pdo->prepare(
            'SELECT id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo, created_at
             FROM loyalty_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 100'
        );
        $st->execute([$customerId]);
        json_response([
            'success' => true,
            'data' => [
                'balance' => $balance,
                'rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ],
        ]);
    }

    json_response(['success' => false, 'message' => 'Action غير مدعوم'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنفيذ عملية الولاء');
}
