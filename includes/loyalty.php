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
 *
 * عند تمرير $countryId (> 0) يُحصر الرصيد على طبقات تلك الدولة فقط (سياسة تعدّد الدول:
 * النقاط لا تُخلَط بين الدول). إن كان null/<=0 يُحسب الرصيد عبر كل الدول (توافق خلفي).
 */
function orange_loyalty_balance_points(PDO $pdo, int $customerId, ?int $countryId = null): int
{
    if ($customerId <= 0 || !orange_table_exists($pdo, 'loyalty_ledger')) {
        return 0;
    }
    $sql = "SELECT COALESCE(SUM(points_remaining), 0)
         FROM loyalty_ledger
         WHERE customer_id = ? AND kind = 'earn' AND points_remaining > 0
               AND (expires_at IS NULL OR expires_at > NOW())";
    $params = [$customerId];
    if ($countryId !== null && $countryId > 0) {
        $sql .= ' AND country_id = ?';
        $params[] = $countryId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);

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
    try {
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
    } catch (PDOException $e) {
        // الفهرس الفريد (kind, ref_type, ref_id) حارس أخير ضد كسب مكرّر متزامن: تجاهل بصمت.
        if ($e->getCode() === '23000') {
            return;
        }
        throw $e;
    }

    $value = round($points * $pointValue, 4);
    // الاتجاه الافتراضي: مدين «مصروف الولاء» / دائن «التزام نقاط الولاء».
    $debitId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_program_expense', $countryId) ?? 0);
    $creditId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_points_liability', $countryId) ?? 0);
    // إن ضُبطت قاعدة LYE في «ربط نوع اليومية» (القسم ٢) فهي مصدر الحقيقة للحسابين.
    $ruleAcc = orange_gl_rule_accounts_for_code($pdo, 'LYE', $countryId);
    if ($ruleAcc !== null) {
        $debitId = (int) $ruleAcc['debit'];
        $creditId = (int) $ruleAcc['credit'];
    }
    orange_loyalty_post_simple_gl(
        $pdo,
        $debitId,
        $creditId,
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
 * عكس «كسب نقاط» طلب (عند إعادة بناء محاسبة الطلب عند تعديل فاتورة الشركة): يحذف طبقة الكسب وقيدها
 * المُرحَّل ليُعاد إنشاؤها بقيمة صافي البيع الجديدة. حارس أمان: إن استُهلِكت الطبقة (استبدال) أو انتهت
 * أو سُحب منها استرداد مردود (points_remaining ≠ points) لا نحذفها حتى لا نُفسد رصيد العميل — يبقى
 * الكسب القديم كما هو (متسقاً مع ما صُرف) ويُسجَّل تنبيه.
 */
function orange_loyalty_reverse_earn_for_order(PDO $pdo, int $orderId): void
{
    if ($orderId <= 0 || !orange_loyalty_tables_ready($pdo)) {
        return;
    }
    $st = $pdo->prepare(
        "SELECT id, country_id, points, points_remaining
         FROM loyalty_ledger
         WHERE kind = 'earn' AND ref_type = 'order' AND ref_id = ? LIMIT 1 FOR UPDATE"
    );
    $st->execute([$orderId]);
    $layer = $st->fetch(PDO::FETCH_ASSOC);
    if (!$layer) {
        return; // لم يكسب الطلب نقاطاً.
    }
    $layerId = (int) $layer['id'];
    $points = (int) $layer['points'];
    $remaining = (int) $layer['points_remaining'];
    $cid = (int) ($layer['country_id'] ?? 0);

    if ($remaining !== $points) {
        error_log('[orange] loyalty earn reverse skipped (layer consumed/clawed) order=' . $orderId);

        return;
    }
    $expChk = $pdo->prepare(
        "SELECT 1 FROM loyalty_ledger
         WHERE kind = 'expire' AND ref_type = 'loyalty_layer' AND ref_id = ? LIMIT 1"
    );
    $expChk->execute([$layerId]);
    if ($expChk->fetchColumn() !== false) {
        error_log('[orange] loyalty earn reverse skipped (layer expired) order=' . $orderId);

        return;
    }

    // حذف قيد الكسب (السند المُرحَّل عبر الطابور + صف الطابور).
    $v = orange_voucher_find_by_document($pdo, 'order', $orderId, 'loyalty_earn', $cid > 0 ? $cid : null, 'loyalty-earn');
    if ($v !== null) {
        orange_voucher_delete_by_reference($pdo, (string) ($v['reference'] ?? ''), $cid > 0 ? $cid : null);
    }
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $pdo->prepare('DELETE FROM orange_gl_pending_movements WHERE reference = ?')
            ->execute([orange_gl_pending_source_key('order', $orderId, 'loyalty-earn')]);
    }
    // حذف صف الكسب كي يُعاد إنشاؤه بقيمة جديدة عند إعادة الترحيل.
    $pdo->prepare('DELETE FROM loyalty_ledger WHERE id = ?')->execute([$layerId]);
}

/**
 * نواة حساب الاستبدال (FIFO): تمشي على طبقات الكسب من الأقدم انتهاءً، وتُقيّم كل طبقة
 * بسعر نقطتها المسجَّل لحظة الكسب (point_value للطبقة) — لا بسعر الإعدادات الحالي — حتى
 * يتطابق خصم الاستبدال مع الالتزام المُرحَّل ويصفر صافي حساب الالتزام بلا انجراف عند تغيّر
 * سعر النقطة لاحقاً. تحترم: حصر الدولة، الحدّ الأدنى للنقاط، النسبة القصوى من المستحق.
 * لا تعدّل أي صف. عند $lock = true تقفل الطبقات (FOR UPDATE) لمنع سباق الاستبدال المتزامن
 * (يجب استدعاؤها حينئذٍ ضمن معاملة).
 *
 * @return array{points:int, value:float, balance:int, layers:array<int,array{id:int,take:int,pv:float}>}
 */
function orange_loyalty_redemption_compute(
    PDO $pdo,
    int $customerId,
    ?int $countryId,
    float $orderPayable,
    int $maxPoints,
    bool $lock
): array {
    $out = ['points' => 0, 'value' => 0.0, 'balance' => 0, 'layers' => []];
    if ($customerId <= 0 || $maxPoints <= 0 || !orange_loyalty_is_active($pdo, $countryId)) {
        return $out;
    }
    $s = orange_loyalty_settings($pdo, $countryId);
    if ($s === null) {
        return $out;
    }
    $balance = orange_loyalty_balance_points($pdo, $customerId, $countryId);
    $out['balance'] = $balance;
    $minRedeem = (int) $s['min_redeem_points'];
    if ($balance <= 0 || ($minRedeem > 0 && $balance < $minRedeem)) {
        return $out;
    }
    $maxPct = (float) $s['max_redeem_pct'];
    $valueCap = $orderPayable;
    if ($maxPct > 0) {
        $valueCap = min($valueCap, round($orderPayable * $maxPct / 100.0, 4));
    }
    if ($valueCap <= 0.0001) {
        return $out;
    }

    $sql = "SELECT id, points_remaining, point_value FROM loyalty_ledger
            WHERE customer_id = ? AND kind = 'earn' AND points_remaining > 0
                  AND (expires_at IS NULL OR expires_at > NOW())";
    $params = [$customerId];
    if ($countryId !== null && $countryId > 0) {
        $sql .= ' AND country_id = ?';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY (expires_at IS NULL), expires_at ASC, id ASC';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $maxPoints;
    $value = 0.0;
    $points = 0;
    $layers = [];
    foreach ($rows as $layer) {
        if ($remaining <= 0) {
            break;
        }
        $pv = (float) $layer['point_value'];
        $avail = (int) $layer['points_remaining'];
        if ($avail <= 0) {
            continue;
        }
        $take = min($avail, $remaining);
        if ($pv > 0) {
            $room = $valueCap - $value;
            if ($room <= 0.0001) {
                break;
            }
            // أكبر عدد نقاط من هذه الطبقة لا يتجاوز سقف القيمة المتبقّي.
            $takeByValue = (int) floor(($room + 1e-6) / $pv);
            $take = min($take, $takeByValue);
        }
        if ($take <= 0) {
            break;
        }
        $value = round($value + $take * $pv, 4);
        $points += $take;
        $remaining -= $take;
        $layers[] = ['id' => (int) $layer['id'], 'take' => $take, 'pv' => $pv];
    }
    if ($points <= 0 || ($minRedeem > 0 && $points < $minRedeem)) {
        return ['points' => 0, 'value' => 0.0, 'balance' => $balance, 'layers' => []];
    }
    $out['points'] = $points;
    $out['value'] = round($value, 4);
    $out['layers'] = $layers;

    return $out;
}

/**
 * أقصى استبدال متاح: نقاط وقيمة، حسب الرصيد والحدّ الأدنى والنسبة القصوى من المبلغ المستحق.
 * القيمة محسوبة بأسعار الطبقات الفعلية (FIFO) لا بسعر الإعدادات.
 *
 * @return array{points:int, value:float, balance:int}
 */
function orange_loyalty_redeemable(PDO $pdo, int $customerId, ?int $countryId, float $orderPayable): array
{
    $c = orange_loyalty_redemption_compute($pdo, $customerId, $countryId, $orderPayable, PHP_INT_MAX, false);

    return ['points' => (int) $c['points'], 'value' => (float) $c['value'], 'balance' => (int) $c['balance']];
}

/**
 * معاينة قيمة استبدال عدد محدّد من النقاط (للعرض/حساب الإجمالي قبل إدراج الطلب).
 * تقفل الطبقات (FOR UPDATE) حتى تتطابق القيمة مع ما سيُطبَّق فعلياً في نفس المعاملة.
 *
 * @return array{points:int, value:float}
 */
function orange_loyalty_redemption_value_preview(
    PDO $pdo,
    int $customerId,
    ?int $countryId,
    int $points,
    float $orderPayable
): array {
    $c = orange_loyalty_redemption_compute($pdo, $customerId, $countryId, $orderPayable, max(0, $points), true);

    return ['points' => (int) $c['points'], 'value' => (float) $c['value']];
}

/**
 * يستهلك النقاط FIFO ويسجّل صف «استبدال». لا يرحّل GL (الاستبدال بند فاتورة يخصم الالتزام في حزمة البيع).
 * القيمة بأسعار الطبقات المستهلَكة (FIFO). يقفل الطبقات أثناء الخصم لمنع التزامن السلبي.
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
    $plan = orange_loyalty_redemption_compute($pdo, $customerId, $countryId, $orderPayable, $pointsRequested, true);
    if ((int) $plan['points'] <= 0 || $plan['layers'] === []) {
        return $result;
    }

    // حارس تزامن: الخصم مشروط ببقاء رصيد كافٍ في الصف (مع FOR UPDATE لا يُفترض حدوث سباق).
    $upd = $pdo->prepare(
        'UPDATE loyalty_ledger SET points_remaining = points_remaining - ? WHERE id = ? AND points_remaining >= ?'
    );
    $consumed = 0;
    $value = 0.0;
    foreach ($plan['layers'] as $ly) {
        $take = (int) $ly['take'];
        if ($take <= 0) {
            continue;
        }
        $upd->execute([$take, (int) $ly['id'], $take]);
        if ($upd->rowCount() < 1) {
            continue;
        }
        $consumed += $take;
        $value = round($value + $take * (float) $ly['pv'], 4);
    }
    if ($consumed <= 0) {
        return $result;
    }
    // سعر النقطة على صف الاستبدال = متوسط مرجّح للطبقات المستهلَكة (يحفظ القيمة عند الاسترجاع).
    $redeemPv = round($value / $consumed, 6);
    $ins = $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (?, ?, 'redeem', ?, 0, ?, NULL, ?, ?, ?)"
    );
    $ins->execute([
        $countryId !== null && $countryId > 0 ? $countryId : null,
        $customerId,
        -$consumed,
        $redeemPv,
        $refType,
        $refId,
        'استبدال نقاط — خصم على الطلب',
    ]);

    $result['points'] = $consumed;
    $result['value'] = round($value, 4);

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
        // عكس الالتزام: مدين «التزام نقاط الولاء» / دائن «مصروف الولاء» افتراضياً.
        $debitId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_points_liability', $layerCountry) ?? 0);
        $creditId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_program_expense', $layerCountry) ?? 0);
        // إن ضُبطت قاعدة LYX في «ربط نوع اليومية» (القسم ٢) فهي مصدر الحقيقة للحسابين.
        $ruleAcc = orange_gl_rule_accounts_for_code($pdo, 'LYX', $layerCountry);
        if ($ruleAcc !== null) {
            $debitId = (int) $ruleAcc['debit'];
            $creditId = (int) $ruleAcc['credit'];
        }
        orange_loyalty_post_simple_gl(
            $pdo,
            $debitId,
            $creditId,
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

/**
 * استرداد نقاط الكسب عند إرجاع طلب مُسلَّم (قرار مالك 2026-06-27): «النقاط تُكتسب على صافي
 * الإنفاق، والمرتجع يقلّل الصافي». يُوزَّع المسحوب على ثلاث دِلاء بحسب وضع طبقة الكسب للطلب:
 *   - متاح (R): نقاط لم تُصرَف بعد → تُسحب من الرصيد + عكس التزام (مدين التزام/دائن مصروف). لا نقد.
 *   - مصروف (S): استُبدِلت فعلاً (أخذ العميل قيمتها) → تُسترَد نقداً كسطر على فاتورة المرتجع
 *     (يُبنى لدى المستدعي بحساب «مصروف الولاء»). لا تعديل دفتر (النقاط رحلت).
 *   - منتهٍ (E): انتهت بلا استخدام (عُكِس التزامها وقت الانتهاء، لم يأخذ العميل شيئاً) → تُتجاهَل.
 * النقاط المسحوبة متناسبة مع قيمة المُرتَجَع: P = floor(returnedRevenue × earn_rate) محدودةً
 * بمكتسب الطلب (متطابق مع معادلة الكسب)، ثم تُوزَّع على R/S/E بنسبة حصصها من المكتسب.
 * idempotent عبر صف علامة (kind='return_clawback') + الفهرس الفريد (kind, ref_type, ref_id).
 *
 * @return array{available_points:int, available_value:float, spent_points:int, spent_value:float, expired_points:int, expense_account_id:int, point_value:float}
 */
function orange_loyalty_clawback_for_return(
    PDO $pdo,
    int $orderId,
    int $returnId,
    float $returnedRevenue,
    ?int $countryId
): array {
    $zero = [
        'available_points' => 0,
        'available_value' => 0.0,
        'spent_points' => 0,
        'spent_value' => 0.0,
        'expired_points' => 0,
        'expense_account_id' => 0,
        'point_value' => 0.0,
    ];
    if ($orderId <= 0 || $returnId <= 0 || $returnedRevenue <= 0.0001 || !orange_loyalty_tables_ready($pdo)) {
        return $zero;
    }

    // idempotency: لا تكرار لاسترداد نفس المردود.
    $done = $pdo->prepare(
        "SELECT id FROM loyalty_ledger
         WHERE kind = 'return_clawback' AND ref_type = 'sales_return' AND ref_id = ? LIMIT 1"
    );
    $done->execute([$returnId]);
    if ($done->fetchColumn() !== false) {
        return $zero;
    }

    // طبقة الكسب لهذا الطلب (الكسب مرّة واحدة لكل طلب) مع القفل.
    $layerStmt = $pdo->prepare(
        "SELECT id, country_id, customer_id, points, points_remaining, point_value
         FROM loyalty_ledger
         WHERE kind = 'earn' AND ref_type = 'order' AND ref_id = ? LIMIT 1 FOR UPDATE"
    );
    $layerStmt->execute([$orderId]);
    $layer = $layerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$layer) {
        return $zero; // الطلب لم يكسب نقاطاً.
    }
    $layerId = (int) $layer['id'];
    $earned = (int) $layer['points'];
    $remaining = (int) $layer['points_remaining'];
    $pv = (float) $layer['point_value'];
    $layerCountry = (int) ($layer['country_id'] ?? 0);
    $cid = $layerCountry > 0 ? $layerCountry : ($countryId !== null && $countryId > 0 ? $countryId : 0);
    if ($earned <= 0) {
        return $zero;
    }

    // المنتهي من هذه الطبقة (مجموع صفوف الانتهاء المرتبطة بها).
    $expStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(-points), 0) FROM loyalty_ledger
         WHERE kind = 'expire' AND ref_type = 'loyalty_layer' AND ref_id = ?"
    );
    $expStmt->execute([$layerId]);
    $expired = (int) $expStmt->fetchColumn();
    $spent = $earned - $remaining - $expired;
    if ($spent < 0) {
        $spent = 0;
    }

    $s = orange_loyalty_settings($pdo, $cid > 0 ? $cid : null);
    $earnRate = $s !== null ? (float) $s['earn_rate'] : 0.0;
    if ($earnRate <= 0) {
        return $zero;
    }
    $toClaw = (int) floor($returnedRevenue * $earnRate);
    if ($toClaw > $earned) {
        $toClaw = $earned;
    }
    if ($toClaw <= 0) {
        return $zero;
    }

    // توزيع متناسب على الدِلاء بحسب حصصها من المكتسب.
    $clawAvail = (int) round($toClaw * $remaining / $earned);
    if ($clawAvail > $remaining) {
        $clawAvail = $remaining;
    }
    $clawSpent = (int) round($toClaw * $spent / $earned);
    if ($clawSpent > $spent) {
        $clawSpent = $spent;
    }

    // 1) المتاح: سحب من الرصيد + عكس الالتزام (لا نقد).
    $availValue = 0.0;
    if ($clawAvail > 0) {
        $upd = $pdo->prepare(
            'UPDATE loyalty_ledger SET points_remaining = points_remaining - ? WHERE id = ? AND points_remaining >= ?'
        );
        $upd->execute([$clawAvail, $layerId, $clawAvail]);
        if ($upd->rowCount() >= 1) {
            $availValue = round($clawAvail * $pv, 4);
            $debitId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_points_liability', $cid) ?? 0);
            $creditId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_program_expense', $cid) ?? 0);
            $ruleAcc = orange_gl_rule_accounts_for_code($pdo, 'LYX', $cid);
            if ($ruleAcc !== null) {
                $debitId = (int) $ruleAcc['debit'];
                $creditId = (int) $ruleAcc['credit'];
            }
            orange_loyalty_post_simple_gl(
                $pdo,
                $debitId,
                $creditId,
                $availValue,
                $cid,
                'قيد استرداد نقاط ولاء (متاحة) — مردود مبيعات',
                'loyalty_return_clawback',
                'sales_return',
                $returnId,
                'loyalty-return-clawback'
            );
        } else {
            $clawAvail = 0;
        }
    }

    // حساب «مصروف الولاء» للسطر النقدي لدى المستدعي (دائن عند استرداد المصروف).
    $expenseAccId = (int) (orange_gl_account_id_optional($pdo, 'loyalty_program_expense', $cid) ?? 0);
    $ruleAccLye = orange_gl_rule_accounts_for_code($pdo, 'LYE', $cid);
    if ($ruleAccLye !== null) {
        $expenseAccId = (int) $ruleAccLye['debit']; // مدين الكسب = حساب المصروف.
    }
    $spentValue = round($clawSpent * $pv, 4);

    // 2) صف علامة (idempotency + تدقيق): يسجّل ما سُحب (متاح + مصروف) دون التأثير على الرصيد.
    // ملاحظة: نُخزّن «المتاح المسحوب» في points_remaining ليكون الجزء القابل للعكس عند التعديل/الحذف
    // (دالة orange_loyalty_reverse_clawback_for_return). استعلام الرصيد يقتصر على kind='earn' فلا يتأثر.
    $insMark = $pdo->prepare(
        "INSERT INTO loyalty_ledger
            (country_id, customer_id, kind, points, points_remaining, point_value, expires_at, ref_type, ref_id, memo)
         VALUES (?, ?, 'return_clawback', ?, ?, ?, NULL, 'sales_return', ?, ?)"
    );
    try {
        $insMark->execute([
            $cid > 0 ? $cid : null,
            (int) $layer['customer_id'],
            -($clawAvail + $clawSpent),
            $clawAvail,
            $pv,
            $returnId,
            'استرداد نقاط ولاء — مردود مبيعات (متاح ' . $clawAvail . ' / مصروف ' . $clawSpent . ')',
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return $zero; // سُجِّل بالفعل لمردود متزامن.
        }
        throw $e;
    }

    return [
        'available_points' => $clawAvail,
        'available_value' => $availValue,
        'spent_points' => $clawSpent,
        'spent_value' => $spentValue,
        'expired_points' => max(0, $toClaw - $clawAvail - $clawSpent),
        'expense_account_id' => $expenseAccId,
        'point_value' => $pv,
    ];
}

/**
 * عكس استرداد نقاط الولاء لمردود مبيعات (عند تعديله أو حذفه): يُعيد النقاط المتاحة المسحوبة إلى طبقة
 * كسب الطلب، ويحذف قيد استرداد الالتزام (قائمة الانتظار + السند المُرحَّل عبرها)، ثم يحذف صف العلامة
 * كي يُعاد احتساب الاسترداد على القيمة الجديدة عند إعادة البناء. النقاط «المصروفة» لا تُعاد للطبقة
 * (لم تُسحب منها أصلاً — عُولِجت نقداً كسطر على الفاتورة يُلغى تلقائياً بإعادة بناء البنود الإضافية).
 */
function orange_loyalty_reverse_clawback_for_return(PDO $pdo, int $returnId): void
{
    if ($returnId <= 0 || !orange_loyalty_tables_ready($pdo)) {
        return;
    }
    $m = $pdo->prepare(
        "SELECT id, country_id, points_remaining
         FROM loyalty_ledger
         WHERE kind = 'return_clawback' AND ref_type = 'sales_return' AND ref_id = ? LIMIT 1 FOR UPDATE"
    );
    $m->execute([$returnId]);
    $marker = $m->fetch(PDO::FETCH_ASSOC);
    if (!$marker) {
        return; // لا استرداد سابق لهذا المردود.
    }
    $availPoints = (int) ($marker['points_remaining'] ?? 0);
    $cid = (int) ($marker['country_id'] ?? 0);

    // إعادة النقاط المتاحة المسحوبة إلى طبقة كسب الطلب.
    if ($availPoints > 0) {
        $oid = $pdo->prepare('SELECT order_id FROM sales_returns WHERE id = ? LIMIT 1');
        $oid->execute([$returnId]);
        $orderId = (int) ($oid->fetchColumn() ?: 0);
        if ($orderId > 0) {
            $pdo->prepare(
                "UPDATE loyalty_ledger SET points_remaining = points_remaining + ?
                 WHERE kind = 'earn' AND ref_type = 'order' AND ref_id = ?"
            )->execute([$availPoints, $orderId]);
        }
    }

    // حذف قيد استرداد الالتزام (المتاح) — السند المُرحَّل عبر الطابور + صف الطابور نفسه.
    $v = orange_voucher_find_by_document(
        $pdo,
        'sales_return',
        $returnId,
        'loyalty_return_clawback',
        $cid > 0 ? $cid : null,
        'loyalty-return-clawback'
    );
    if ($v !== null) {
        orange_voucher_delete_by_reference($pdo, (string) ($v['reference'] ?? ''), $cid > 0 ? $cid : null);
    }
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $pdo->prepare('DELETE FROM orange_gl_pending_movements WHERE reference = ?')
            ->execute([orange_gl_pending_source_key('sales_return', $returnId, 'loyalty-return-clawback')]);
    }

    // حذف صف العلامة كي يُعاد الاسترداد عند إعادة البناء.
    $pdo->prepare('DELETE FROM loyalty_ledger WHERE id = ?')->execute([(int) $marker['id']]);
}
