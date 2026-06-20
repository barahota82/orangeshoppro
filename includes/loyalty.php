<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/gl_pending_movements.php';

/**
 * نظام ولاء العميل (نموذج التزام مؤجّل — الأدق محاسبياً):
 * - كسب عند التسليم بنسبة ثابتة على صافي المبيعات؛ قيد: مدين «مصروفات برنامج الولاء» / دائن «التزامات نقاط الولاء».
 * - استبدال عند الدفع كبند فاتورة يخصم من «التزامات نقاط الولاء» (يقلّل ما يدفعه العميل) — يستهلك الطبقات FIFO.
 * - انتهاء FIFO حسب مدة الدولة؛ قيد عكسي: مدين «التزامات نقاط الولاء» / دائن «مصروفات برنامج الولاء».
 * - لا تثبيت لأي حساب: كل الحسابات عبر gl_settings (loyalty_program_expense / loyalty_points_liability).
 */
function orange_loyalty_tables_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'loyalty_settings')
        && orange_table_exists($pdo, 'loyalty_ledger')
        && orange_table_exists($pdo, 'customers');
}

/**
 * إعدادات الولاء للدولة (صف أو null). يُسقط على الدولة 0 إن لم يوجد صف للدولة.
 *
 * @return array<string,mixed>|null
 */
function orange_loyalty_settings(PDO $pdo, ?int $countryId = null): ?array
{
    if (!orange_table_exists($pdo, 'loyalty_settings')) {
        return null;
    }
    $cid = $countryId !== null && $countryId > 0 ? $countryId : 0;
    $st = $pdo->prepare('SELECT * FROM loyalty_settings WHERE country_id = ? LIMIT 1');
    $st->execute([$cid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row && $cid !== 0) {
        $st->execute([0]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    return $row ?: null;
}

function orange_loyalty_is_active(PDO $pdo, ?int $countryId = null): bool
{
    $s = orange_loyalty_settings($pdo, $countryId);

    return $s !== null
        && (int) ($s['is_active'] ?? 0) === 1
        && (float) ($s['earn_rate'] ?? 0) > 0
        && (float) ($s['point_value'] ?? 0) > 0;
}

/**
 * رصيد النقاط القابلة للاستخدام (طبقات كسب غير منتهية ولها بقية).
 */
function orange_loyalty_balance_points(PDO $pdo, int $customerId): int
{
    if ($customerId <= 0 || !orange_table_exists($pdo, 'loyalty_ledger')) {
        return 0;
    }
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(points_remaining), 0)
         FROM loyalty_ledger
         WHERE customer_id = ? AND kind = 'earn' AND points_remaining > 0
               AND (expires_at IS NULL OR expires_at > NOW())"
    );
    $st->execute([$customerId]);

    return (int) $st->fetchColumn();
}

/**
 * يرحّل قيد GL بسيط (مدين/دائن) محترماً طابور المعلّق إن كان مفعّلاً.
 */
function orange_loyalty_post_simple_gl(
    PDO $pdo,
    int $debitId,
    int $creditId,
    float $amount,
    int $countryId,
    string $desc,
    string $entryType,
    string $refType,
    int $refId,
    string $pendingSuffix
): void {
    if ($amount <= 0.0001 || $debitId <= 0 || $creditId <= 0) {
        return;
    }
    $now = date('Y-m-d H:i:s');
    $amount = round($amount, 4);
    $lines = [
        ['account_id' => $debitId, 'debit' => $amount, 'credit' => 0.0, 'memo' => $desc],
        ['account_id' => $creditId, 'debit' => 0.0, 'credit' => $amount, 'memo' => $desc],
    ];
    $afterJson = orange_gl_after_post_json_with_country(null, $countryId);
    if (orange_gl_use_pending_queue($pdo)) {
        $key = orange_gl_pending_source_key($refType, $refId, $pendingSuffix);
        orange_gl_pending_enqueue_multi(
            $pdo,
            $lines,
            $key,
            strtoupper($refType) . '-' . $refId,
            $now,
            $now,
            $desc,
            $entryType,
            $afterJson
        );

        return;
    }
    orange_voucher_post($pdo, [
        'voucher_date' => $now,
        'document_entered_at' => $now,
        'description' => $desc,
        'entry_type' => $entryType,
        'journal_type_id' => null,
        'country_id' => $countryId,
    ], $lines);
}

/**
 * كسب نقاط عند تسليم الطلب — مرّة واحدة لكل طلب.
 */
function orange_loyalty_earn_for_order(PDO $pdo, array $order, int $countryId, float $netSales): void
{
    if (!orange_loyalty_tables_ready($pdo) || $netSales <= 0.0001) {
        return;
    }
    $customerId = (int) ($order['customer_id'] ?? 0);
    $orderId = (int) ($order['id'] ?? 0);
    if ($customerId <= 0 || $orderId <= 0) {
        return;
    }
    if (!orange_loyalty_is_active($pdo, $countryId)) {
        return;
    }
    // عدم التكرار: لا كسب إن وُجد قيد كسب لنفس الطلب.
    $chk = $pdo->prepare(
        "SELECT id FROM loyalty_ledger WHERE kind = 'earn' AND ref_type = 'order' AND ref_id = ? LIMIT 1"
    );
    $chk->execute([$orderId]);
    if ($chk->fetchColumn() !== false) {
        return;
    }

    $s = orange_loyalty_settings($pdo, $countryId);
    if ($s === null) {
        return;
    }
    $earnRate = (float) $s['earn_rate'];
    $pointValue = (float) $s['point_value'];
    $expiryMonths = (int) $s['expiry_months'];
    $points = (int) floor($netSales * $earnRate);
    if ($points <= 0) {
        return;
    }
    $expiresAt = $expiryMonths > 0
        ? date('Y-m-d H:i:s', strtotime('+' . $expiryMonths . ' months'))
        : null;

    $ins = $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (?, ?, 'earn', ?, ?, ?, ?, 'order', ?, ?)"
    );
    $ins->execute([
        $countryId > 0 ? $countryId : null,
        $customerId,
        $points,
        $points,
        $pointValue,
        $expiresAt,
        $orderId,
        'كسب نقاط — تسليم الطلب ' . (string) ($order['order_number'] ?? ''),
    ]);

    $value = round($points * $pointValue, 4);
    $expenseId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_program_expense', $countryId) ?? 0);
    $liabilityId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_points_liability', $countryId) ?? 0);
    orange_loyalty_post_simple_gl(
        $pdo,
        $expenseId,
        $liabilityId,
        $value,
        $countryId,
        'قيد كسب نقاط ولاء — تسليم الطلب',
        'loyalty_earn',
        'order',
        $orderId,
        'loyalty-earn'
    );
}

/**
 * أقصى استبدال متاح: نقاط وقيمة، حسب الرصيد والحدّ الأدنى والنسبة القصوى من المبلغ المستحق.
 *
 * @return array{points:int, value:float, balance:int}
 */
function orange_loyalty_redeemable(PDO $pdo, int $customerId, ?int $countryId, float $orderPayable): array
{
    $out = ['points' => 0, 'value' => 0.0, 'balance' => 0];
    if ($customerId <= 0 || !orange_loyalty_is_active($pdo, $countryId)) {
        return $out;
    }
    $s = orange_loyalty_settings($pdo, $countryId);
    if ($s === null) {
        return $out;
    }
    $balance = orange_loyalty_balance_points($pdo, $customerId);
    $out['balance'] = $balance;
    $minRedeem = (int) $s['min_redeem_points'];
    if ($balance <= 0 || ($minRedeem > 0 && $balance < $minRedeem)) {
        return $out;
    }
    $pointValue = (float) $s['point_value'];
    $maxPct = (float) $s['max_redeem_pct'];
    $valueCap = $orderPayable;
    if ($maxPct > 0) {
        $valueCap = min($valueCap, round($orderPayable * $maxPct / 100.0, 4));
    }
    if ($valueCap <= 0.0001 || $pointValue <= 0) {
        return $out;
    }
    $maxPointsByValue = (int) floor($valueCap / $pointValue);
    $points = min($balance, $maxPointsByValue);
    if ($points <= 0 || ($minRedeem > 0 && $points < $minRedeem)) {
        return $out;
    }
    $out['points'] = $points;
    $out['value'] = round($points * $pointValue, 4);

    return $out;
}

/**
 * يستهلك النقاط FIFO ويسجّل صف «استبدال». لا يرحّل GL (الاستبدال بند فاتورة يخصم الالتزام في حزمة البيع).
 *
 * @return array{points:int, value:float}
 */
function orange_loyalty_apply_redemption(
    PDO $pdo,
    int $customerId,
    ?int $countryId,
    int $pointsRequested,
    float $orderPayable,
    string $refType,
    int $refId
): array {
    $result = ['points' => 0, 'value' => 0.0];
    if ($pointsRequested <= 0) {
        return $result;
    }
    $cap = orange_loyalty_redeemable($pdo, $customerId, $countryId, $orderPayable);
    $points = min($pointsRequested, (int) $cap['points']);
    if ($points <= 0) {
        return $result;
    }
    $s = orange_loyalty_settings($pdo, $countryId);
    $pointValue = $s !== null ? (float) $s['point_value'] : 0.0;
    if ($pointValue <= 0) {
        return $result;
    }

    // استهلاك FIFO من طبقات الكسب (الأقدم انتهاءً أولاً).
    $remaining = $points;
    $layers = $pdo->prepare(
        "SELECT id, points_remaining FROM loyalty_ledger
         WHERE customer_id = ? AND kind = 'earn' AND points_remaining > 0
               AND (expires_at IS NULL OR expires_at > NOW())
         ORDER BY (expires_at IS NULL), expires_at ASC, id ASC"
    );
    $layers->execute([$customerId]);
    $rows = $layers->fetchAll(PDO::FETCH_ASSOC);
    $upd = $pdo->prepare('UPDATE loyalty_ledger SET points_remaining = points_remaining - ? WHERE id = ?');
    foreach ($rows as $layer) {
        if ($remaining <= 0) {
            break;
        }
        $avail = (int) $layer['points_remaining'];
        $take = min($avail, $remaining);
        if ($take > 0) {
            $upd->execute([$take, (int) $layer['id']]);
            $remaining -= $take;
        }
    }
    $consumed = $points - $remaining;
    if ($consumed <= 0) {
        return $result;
    }
    $value = round($consumed * $pointValue, 4);
    $ins = $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (?, ?, 'redeem', ?, 0, ?, NULL, ?, ?, ?)"
    );
    $ins->execute([
        $countryId !== null && $countryId > 0 ? $countryId : null,
        $customerId,
        -$consumed,
        $pointValue,
        $refType,
        $refId,
        'استبدال نقاط — خصم على الطلب',
    ]);

    $result['points'] = $consumed;
    $result['value'] = $value;

    return $result;
}

/**
 * يعيد النقاط المستهلَكة لطلب أُلغي/رُفض قبل التسليم (لا قيد GL — البيع لم يُرحَّل بعد). idempotent.
 */
function orange_loyalty_restore_for_order(PDO $pdo, int $orderId): void
{
    if ($orderId <= 0 || !orange_loyalty_tables_ready($pdo)) {
        return;
    }
    $st = $pdo->prepare(
        "SELECT customer_id, country_id, point_value, COALESCE(SUM(-points), 0) AS consumed
         FROM loyalty_ledger
         WHERE kind = 'redeem' AND ref_type = 'order' AND ref_id = ?
         GROUP BY customer_id, country_id, point_value"
    );
    $st->execute([$orderId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return;
    }
    // عدم التكرار: إن وُجد صف استرجاع سابق لهذا الطلب.
    $chk = $pdo->prepare(
        "SELECT id FROM loyalty_ledger
         WHERE kind = 'earn' AND ref_type = 'order_redeem_restore' AND ref_id = ? LIMIT 1"
    );
    $chk->execute([$orderId]);
    if ($chk->fetchColumn() !== false) {
        return;
    }
    $ins = $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (?, ?, 'earn', ?, ?, ?, ?, 'order_redeem_restore', ?, ?)"
    );
    foreach ($rows as $row) {
        $consumed = (int) $row['consumed'];
        if ($consumed <= 0) {
            continue;
        }
        $cid = (int) ($row['country_id'] ?? 0);
        $pointValue = (float) $row['point_value'];
        $s = orange_loyalty_settings($pdo, $cid > 0 ? $cid : null);
        $expiryMonths = $s !== null ? (int) $s['expiry_months'] : 0;
        $expiresAt = $expiryMonths > 0
            ? date('Y-m-d H:i:s', strtotime('+' . $expiryMonths . ' months'))
            : null;
        $ins->execute([
            $cid > 0 ? $cid : null,
            (int) $row['customer_id'],
            $consumed,
            $consumed,
            $pointValue,
            $expiresAt,
            $orderId,
            'استرجاع نقاط — إلغاء/رفض الطلب',
        ]);
    }
}

/**
 * يُنهي طبقات الكسب المنتهية (بقية > 0 وتجاوزت تاريخ الانتهاء) ويرحّل قيداً عكسياً للالتزام.
 *
 * @return array{expired_points:int, expired_value:float, layers:int}
 */
function orange_loyalty_expire_due(PDO $pdo, ?int $countryId = null): array
{
    $out = ['expired_points' => 0, 'expired_value' => 0.0, 'layers' => 0];
    if (!orange_loyalty_tables_ready($pdo)) {
        return $out;
    }
    $sql = "SELECT id, country_id, customer_id, points_remaining, point_value
            FROM loyalty_ledger
            WHERE kind = 'earn' AND points_remaining > 0
                  AND expires_at IS NOT NULL AND expires_at <= NOW()";
    $params = [];
    if ($countryId !== null && $countryId > 0) {
        $sql .= ' AND country_id = ?';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return $out;
    }
    $upd = $pdo->prepare('UPDATE loyalty_ledger SET points_remaining = 0 WHERE id = ?');
    $ins = $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (?, ?, 'expire', ?, 0, ?, NULL, 'loyalty_layer', ?, ?)"
    );
    foreach ($rows as $layer) {
        $pts = (int) $layer['points_remaining'];
        if ($pts <= 0) {
            continue;
        }
        $layerCountry = (int) ($layer['country_id'] ?? 0);
        $pointValue = (float) $layer['point_value'];
        $value = round($pts * $pointValue, 4);
        $upd->execute([(int) $layer['id']]);
        $ins->execute([
            $layerCountry > 0 ? $layerCountry : null,
            (int) $layer['customer_id'],
            -$pts,
            $pointValue,
            (int) $layer['id'],
            'انتهاء صلاحية نقاط ولاء',
        ]);
        $expenseId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_program_expense', $layerCountry) ?? 0);
        $liabilityId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_points_liability', $layerCountry) ?? 0);
        // عكس: مدين الالتزام / دائن المصروف.
        orange_loyalty_post_simple_gl(
            $pdo,
            $liabilityId,
            $expenseId,
            $value,
            $layerCountry,
            'قيد انتهاء نقاط ولاء — عكس الالتزام',
            'loyalty_expire',
            'loyalty_layer',
            (int) $layer['id'],
            'loyalty-expire'
        );
        $out['expired_points'] += $pts;
        $out['expired_value'] = round($out['expired_value'] + $value, 4);
        $out['layers']++;
    }

    return $out;
}
