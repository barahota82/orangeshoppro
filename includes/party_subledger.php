<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/date_format.php';

function orange_party_subledger_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'party_subledger');
}

/**
 * @param 'supplier'|'customer' $partyKind
 */
function orange_party_subledger_record(
    PDO $pdo,
    string $partyKind,
    int $partyId,
    int $voucherId,
    float $debit,
    float $credit,
    ?string $refType,
    ?int $refId,
    ?string $memo
): void {
    orange_catalog_ensure_schema($pdo);
    if (!orange_party_subledger_ready($pdo) || $partyId <= 0 || $voucherId <= 0) {
        return;
    }
    if (!in_array($partyKind, ['supplier', 'customer'], true)) {
        return;
    }
    $debit = round($debit, 4);
    $credit = round($credit, 4);
    if ($debit < 0 || $credit < 0 || ($debit === 0.0 && $credit === 0.0)) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO party_subledger (party_kind, party_id, voucher_id, debit, credit, ref_type, ref_id, memo)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $partyKind,
        $partyId,
        $voucherId,
        $debit,
        $credit,
        $refType,
        $refId,
        $memo === null || $memo === '' ? null : $memo,
    ]);
}

/**
 * رصيد العميل (ذمم مدينة): مدين − دائن (موجب = عليه ذمة لنا).
 */
function orange_party_balance_customer(PDO $pdo, int $customerId): float
{
    if (!orange_party_subledger_ready($pdo) || $customerId <= 0) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(debit - credit), 0) FROM party_subledger WHERE party_kind = ? AND party_id = ?'
    );
    $st->execute(['customer', $customerId]);

    return round((float) $st->fetchColumn(), 4);
}

/**
 * رصيد المورد (ذمم دائنة): دائن − مدين (موجب = لنا ذمة له).
 */
function orange_party_balance_supplier(PDO $pdo, int $supplierId): float
{
    if (!orange_party_subledger_ready($pdo) || $supplierId <= 0) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(credit - debit), 0) FROM party_subledger WHERE party_kind = ? AND party_id = ?'
    );
    $st->execute(['supplier', $supplierId]);

    return round((float) $st->fetchColumn(), 4);
}

/**
 * @return int 0 إذا لا يوجد هاتف
 */
function orange_purchase_record_ap_subledger(
    PDO $pdo,
    int $purchaseId,
    int $supplierId,
    string $purchaseType,
    float $total
): void {
    if ($purchaseType !== 'credit' || $supplierId <= 0 || $total <= 0.0001) {
        return;
    }
    $v = orange_voucher_by_reference($pdo, 'PUR-' . $purchaseId);
    if (!$v) {
        return;
    }
    orange_party_subledger_record(
        $pdo,
        'supplier',
        $supplierId,
        (int) $v['id'],
        0,
        $total,
        'purchase',
        $purchaseId,
        'شراء آجل'
    );
}

/**
 * ذمة المورد بعد ترحيل مردود مشتريات آجل (سند فوري بسطرين) — مدين المورد يقلل الذمة الدائنة.
 */
function orange_purchase_return_record_ap_subledger(
    PDO $pdo,
    int $returnId,
    int $supplierId,
    string $returnType,
    float $total
): void {
    if ($returnType !== 'credit' || $supplierId <= 0 || $total <= 0.0001) {
        return;
    }
    $v = orange_voucher_by_reference($pdo, 'PR-' . $returnId);
    if (!$v) {
        return;
    }
    orange_party_subledger_record(
        $pdo,
        'supplier',
        $supplierId,
        (int) $v['id'],
        $total,
        0.0,
        'purchase_return',
        $returnId,
        'مردود مشتريات آجل'
    );
}

/**
 * ذمة العميل بعد ترحيل مردود مبيعات آجل (سند فوري بسطرين).
 */
function orange_sales_return_record_ar_subledger(
    PDO $pdo,
    int $returnId,
    int $customerId,
    string $channel,
    float $total
): void {
    if ($channel !== 'credit' || $customerId <= 0 || $total <= 0.0001) {
        return;
    }
    $v = orange_voucher_by_reference($pdo, 'SR-' . $returnId . '-RS');
    if (!$v) {
        return;
    }
    orange_party_subledger_record(
        $pdo,
        'customer',
        $customerId,
        (int) $v['id'],
        0.0,
        $total,
        'sales_return',
        $returnId,
        'مردود مبيعات آجل'
    );
}

/**
 * كشف حساب طرف من دفتر الذمم (مرتب زمنياً مع الرصيد الجاري بعد كل سطر).
 *
 * @param 'customer'|'supplier' $partyKind
 * @return list<array<string, mixed>>
 */
function orange_party_statement_lines(PDO $pdo, string $partyKind, int $partyId): array
{
    if (!orange_party_subledger_ready($pdo) || $partyId <= 0 || !in_array($partyKind, ['customer', 'supplier'], true)) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT ps.debit, ps.credit, ps.memo, ps.ref_type, ps.ref_id,
                jv.voucher_date, jv.reference, jv.entry_type, jv.description AS voucher_description
         FROM party_subledger ps
         INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id
         WHERE ps.party_kind = ? AND ps.party_id = ?
         ORDER BY jv.voucher_date ASC, jv.id ASC, ps.id ASC'
    );
    $st->execute([$partyKind, $partyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    $run = 0.0;
    foreach ($rows as $r) {
        $d = round((float) $r['debit'], 4);
        $c = round((float) $r['credit'], 4);
        if ($partyKind === 'customer') {
            $run = round($run + $d - $c, 4);
        } else {
            $run = round($run + $c - $d, 4);
        }
        $vdRaw = (string) ($r['voucher_date'] ?? '');
        $out[] = [
            'voucher_date' => $r['voucher_date'],
            'voucher_date_display' => orange_format_date_dmY($vdRaw),
            'reference' => $r['reference'],
            'entry_type' => $r['entry_type'],
            'debit' => $d,
            'credit' => $c,
            'balance' => $run,
            'memo' => $r['memo'],
            'ref_type' => $r['ref_type'],
            'ref_id' => $r['ref_id'] !== null && $r['ref_id'] !== '' ? (int) $r['ref_id'] : null,
            'voucher_description' => $r['voucher_description'],
        ];
    }

    return $out;
}

/**
 * صفوف دفتر الذمم لطرف (للمعالجة المحاسبية — نفس ترتيب كشف الحساب).
 *
 * @param 'customer'|'supplier' $partyKind
 * @return list<array<string, mixed>>
 */
function orange_party_subledger_movement_rows(PDO $pdo, string $partyKind, int $partyId): array
{
    if (!orange_party_subledger_ready($pdo) || $partyId <= 0 || !in_array($partyKind, ['customer', 'supplier'], true)) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT ps.debit, ps.credit, jv.voucher_date
         FROM party_subledger ps
         INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id
         WHERE ps.party_kind = ? AND ps.party_id = ?
         ORDER BY jv.voucher_date ASC, jv.id ASC, ps.id ASC'
    );
    $st->execute([$partyKind, $partyId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * أعمار الرصيد المفتوح (توزيع على فترات بالأيام) بافتراض تسوية الدفعات بالأقدمية (FIFO).
 * يُستخدم للعملاء (ذمم مدينة) والموردين (ذمم دائنة).
 *
 * @param 'customer'|'supplier' $partyKind
 * @return array<string, mixed>
 */
function orange_party_aging_buckets(PDO $pdo, string $partyKind, int $partyId, ?string $asOfDate = null): array
{
    $labels = [
        'days_0_30' => 'حتى 30 يوماً',
        'days_31_60' => 'من 31 إلى 60 يوماً',
        'days_61_90' => 'من 61 إلى 90 يوماً',
        'days_91_plus' => 'أكثر من 90 يوماً',
    ];
    $empty = [
        'as_of' => $asOfDate ?? date('Y-m-d'),
        'party_kind' => $partyKind,
        'party_id' => $partyId,
        'balance' => 0.0,
        'open_in_buckets' => 0.0,
        'prepayment' => 0.0,
        'buckets' => [
            'days_0_30' => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'days_91_plus' => 0.0,
        ],
        'bucket_labels_ar' => $labels,
        'method' => 'fifo_open_items',
    ];
    if (!orange_party_subledger_ready($pdo) || $partyId <= 0 || !in_array($partyKind, ['customer', 'supplier'], true)) {
        return $empty;
    }
    $asOf = $asOfDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate) ? $asOfDate : date('Y-m-d');
    $empty['as_of'] = $asOf;
    $rows = orange_party_subledger_movement_rows($pdo, $partyKind, $partyId);
    $chunks = [];
    foreach ($rows as $r) {
        $d = round((float) $r['debit'], 4);
        $c = round((float) $r['credit'], 4);
        $vd = substr((string) $r['voucher_date'], 0, 10);
        if ($partyKind === 'customer') {
            if ($d > 0.0001) {
                $chunks[] = ['amt' => $d, 'date' => $vd];
            }
            if ($c > 0.0001) {
                $rem = $c;
                while ($rem > 0.0001 && $chunks !== []) {
                    $take = min($chunks[0]['amt'], $rem);
                    $chunks[0]['amt'] = round($chunks[0]['amt'] - $take, 4);
                    $rem = round($rem - $take, 4);
                    if ($chunks[0]['amt'] < 0.0001) {
                        array_shift($chunks);
                    }
                }
            }
        } else {
            if ($c > 0.0001) {
                $chunks[] = ['amt' => $c, 'date' => $vd];
            }
            if ($d > 0.0001) {
                $rem = $d;
                while ($rem > 0.0001 && $chunks !== []) {
                    $take = min($chunks[0]['amt'], $rem);
                    $chunks[0]['amt'] = round($chunks[0]['amt'] - $take, 4);
                    $rem = round($rem - $take, 4);
                    if ($chunks[0]['amt'] < 0.0001) {
                        array_shift($chunks);
                    }
                }
            }
        }
    }
    $balance = $partyKind === 'customer'
        ? orange_party_balance_customer($pdo, $partyId)
        : orange_party_balance_supplier($pdo, $partyId);
    $openSum = 0.0;
    foreach ($chunks as $ch) {
        if ($ch['amt'] > 0.0001) {
            $openSum = round($openSum + $ch['amt'], 4);
        }
    }
    $prepay = 0.0;
    if ($balance < -0.0001) {
        $prepay = round(abs($balance), 4);
    }
    $buckets = [
        'days_0_30' => 0.0,
        'days_31_60' => 0.0,
        'days_61_90' => 0.0,
        'days_91_plus' => 0.0,
    ];
    $asTs = strtotime($asOf . ' 12:00:00');
    if ($asTs === false) {
        $asTs = time();
    }
    foreach ($chunks as $ch) {
        if ($ch['amt'] < 0.0001) {
            continue;
        }
        $docTs = strtotime($ch['date'] . ' 12:00:00');
        if ($docTs === false) {
            $docTs = $asTs;
        }
        $days = (int) floor(($asTs - $docTs) / 86400);
        if ($days < 0) {
            $days = 0;
        }
        $amt = $ch['amt'];
        if ($days <= 30) {
            $buckets['days_0_30'] = round($buckets['days_0_30'] + $amt, 4);
        } elseif ($days <= 60) {
            $buckets['days_31_60'] = round($buckets['days_31_60'] + $amt, 4);
        } elseif ($days <= 90) {
            $buckets['days_61_90'] = round($buckets['days_61_90'] + $amt, 4);
        } else {
            $buckets['days_91_plus'] = round($buckets['days_91_plus'] + $amt, 4);
        }
    }
    $bucketTotal = round(
        $buckets['days_0_30'] + $buckets['days_31_60'] + $buckets['days_61_90'] + $buckets['days_91_plus'],
        4
    );

    return [
        'as_of' => $asOf,
        'party_kind' => $partyKind,
        'party_id' => $partyId,
        'balance' => round($balance, 4),
        'open_in_buckets' => $bucketTotal,
        'prepayment' => $prepay,
        'buckets' => $buckets,
        'bucket_labels_ar' => $labels,
        'method' => 'fifo_open_items',
    ];
}

/**
 * رسالة موحّدة عند محاولة بيع آجل لعميل بلا رقم مدني مسجّل.
 */
function orange_credit_sale_requires_civil_id_message(): string
{
    return 'لا يمكن حفظ فاتورة آجل: سجّل الرقم المدني للعميل أولاً من شاشة «العملاء».';
}

/**
 * **س15:** فاتورة المبيعات الآجل تتطلّب عميلاً مسجّلاً برقم مدني (الحقل اختياري عند حفظ العميل فقط).
 *
 * @return array{ok: bool, message: string}
 */
function orange_customer_credit_sale_civil_check(PDO $pdo, int $customerId, string $phoneNorm): array
{
    if (!orange_table_exists($pdo, 'customers') || !orange_table_has_column($pdo, 'customers', 'civil_id')) {
        return ['ok' => true, 'message' => ''];
    }

    $row = null;
    if ($customerId > 0) {
        $st = $pdo->prepare('SELECT id, civil_id FROM customers WHERE id = ? LIMIT 1');
        $st->execute([$customerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } elseif (trim($phoneNorm) !== '') {
        $st = $pdo->prepare('SELECT id, civil_id FROM customers WHERE phone = ? LIMIT 1');
        $st->execute([trim($phoneNorm)]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$row || trim((string) ($row['civil_id'] ?? '')) === '') {
        return ['ok' => false, 'message' => orange_credit_sale_requires_civil_id_message()];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * يضمن وجود سجل عميل بالرقم المُطبَّع، ويُحدِّث الاسم.
 *
 * **س15:** الإثراء الاختياري عبر `orange_ensure_customer_with_profile()` لنقل المنطقة/العنوان/البريد
 * مع سياسة عدم الكتابة فوق حقل غير فارغ بقيمة فارغة، وعدم تغيير الكود/الملاحظات.
 */
function orange_ensure_customer(PDO $pdo, string $nameAr, string $phone): int
{
    require_once __DIR__ . '/phone_validation.php';
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'customers')) {
        return 0;
    }
    $phoneNorm = orange_normalize_customer_phone(trim($phone), null);
    if ($phoneNorm === null) {
        return 0;
    }
    $phone = $phoneNorm;
    $nameAr = trim($nameAr);
    $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
    $st->execute([$phone]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE customers SET name_ar = ? WHERE id = ?')->execute([$nameAr !== '' ? $nameAr : 'عميل', (int) $id]);

        return (int) $id;
    }
    $pdo->prepare('INSERT INTO customers (name_ar, phone) VALUES (?, ?)')->execute([$nameAr ?: 'عميل', $phone]);

    return (int) $pdo->lastInsertId();
}

/**
 * **س15:** يضمن سجل عميل وينقل بيانات الملف التعريفي من الطلب/التسجيل إلى جدول customers.
 * - يستخدم نفس مفتاح الهاتف المعرّف الفريد.
 * - لا يكتب فوق حقول `customers` غير الفارغة بقيمة فارغة (يحافظ على ما أدخله الأدمن).
 * - يحدّث `area` + `delivery_area_id` فقط إن وُجدت قيمة مفيدة من المصدر.
 * - لا يتغير `credit_limit` ولا `code` من هنا (إدارة الأدمن فقط).
 *
 * @param array{
 *   area?: string,
 *   delivery_area_id?: int|null,
 *   address?: string,
 *   email?: string,
 *   phone_country_dial?: string|null,
 *   phone_national?: string|null
 * } $profile
 * @return int  معرف العميل بعد الإنشاء/التحديث، 0 لو فشل التطبيع.
 */
function orange_ensure_customer_with_profile(
    PDO $pdo,
    string $nameAr,
    string $phone,
    array $profile = []
): int {
    $customerId = orange_ensure_customer($pdo, $nameAr, $phone);
    if ($customerId <= 0) {
        return 0;
    }

    $sets = [];
    $params = [];
    if (orange_table_has_column($pdo, 'customers', 'area')) {
        $newArea = trim((string) ($profile['area'] ?? ''));
        if ($newArea !== '') {
            $sets[] = 'area = ?';
            $params[] = function_exists('mb_substr') ? mb_substr($newArea, 0, 255, 'UTF-8') : substr($newArea, 0, 255);
        }
    }
    if (orange_table_has_column($pdo, 'customers', 'delivery_area_id')) {
        if (array_key_exists('delivery_area_id', $profile) && $profile['delivery_area_id'] !== null && (int) $profile['delivery_area_id'] > 0) {
            $sets[] = 'delivery_area_id = ?';
            $params[] = (int) $profile['delivery_area_id'];
        }
    }
    if (orange_table_has_column($pdo, 'customers', 'address')) {
        $newAddress = trim((string) ($profile['address'] ?? ''));
        if ($newAddress !== '') {
            $sets[] = 'address = COALESCE(NULLIF(address, \'\'), ?)';
            $params[] = function_exists('mb_substr') ? mb_substr($newAddress, 0, 2000, 'UTF-8') : substr($newAddress, 0, 2000);
        }
    }
    if (orange_table_has_column($pdo, 'customers', 'email')) {
        $newEmail = trim((string) ($profile['email'] ?? ''));
        if ($newEmail !== '' && filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $sets[] = 'email = COALESCE(email, ?)';
            $params[] = function_exists('mb_substr') ? mb_substr($newEmail, 0, 255, 'UTF-8') : substr($newEmail, 0, 255);
        }
    }
    if (orange_table_has_column($pdo, 'customers', 'phone_country_dial')) {
        $newDial = isset($profile['phone_country_dial']) && $profile['phone_country_dial'] !== null
            ? preg_replace('/\D+/', '', (string) $profile['phone_country_dial']) : '';
        if ($newDial !== '') {
            $sets[] = 'phone_country_dial = COALESCE(phone_country_dial, ?)';
            $params[] = substr($newDial, 0, 8);
        }
    }
    if (orange_table_has_column($pdo, 'customers', 'phone_national')) {
        $newNat = isset($profile['phone_national']) && $profile['phone_national'] !== null
            ? preg_replace('/\D+/', '', (string) $profile['phone_national']) : '';
        if ($newNat !== '') {
            $sets[] = 'phone_national = COALESCE(phone_national, ?)';
            $params[] = substr($newNat, 0, 32);
        }
    }

    if ($sets !== []) {
        $params[] = $customerId;
        try {
            $pdo->prepare('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orange_ensure_customer_with_profile: ' . $e->getMessage());
            }
        }
    }

    return $customerId;
}

/**
 * **س15:** مزامنة حساب واجهة (`storefront_accounts`) مع جدول `customers`.
 * يستخدم البيانات المخزّنة في الحساب لإنشاء/تحديث صف العميل ثم يربط `storefront_accounts.customer_id`.
 *
 * يُستدعى عند تأكيد البريد لأول مرة (وعند الحاجة لاحقاً عبر backfill يدوي).
 *
 * @return int معرف العميل أو 0 لو تعذر (هاتف غير صالح / جدول مفقود / ...).
 */
function orange_sync_storefront_account_to_customer(PDO $pdo, int $storefrontAccountId): int
{
    if ($storefrontAccountId <= 0) {
        return 0;
    }
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'storefront_accounts') || !orange_table_exists($pdo, 'customers')) {
        return 0;
    }

    $hasCustomerLink = orange_table_has_column($pdo, 'storefront_accounts', 'customer_id');
    $hasDaCol = orange_table_has_column($pdo, 'storefront_accounts', 'customer_delivery_area_id');
    $hasDial = orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_country_dial');
    $hasNat = orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_national');

    $cols = ['id', 'email', 'customer_name', 'customer_phone', 'customer_area', 'customer_address'];
    if ($hasCustomerLink) {
        $cols[] = 'customer_id';
    }
    if ($hasDaCol) {
        $cols[] = 'customer_delivery_area_id';
    }
    if ($hasDial) {
        $cols[] = 'customer_phone_country_dial';
    }
    if ($hasNat) {
        $cols[] = 'customer_phone_national';
    }
    $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM storefront_accounts WHERE id = ? LIMIT 1');
    $st->execute([$storefrontAccountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    $phone = isset($row['customer_phone']) ? trim((string) $row['customer_phone']) : '';
    $name = isset($row['customer_name']) ? trim((string) $row['customer_name']) : '';
    if ($phone === '') {
        return 0;
    }
    $profile = [
        'area' => isset($row['customer_area']) ? (string) $row['customer_area'] : '',
        'delivery_area_id' => $hasDaCol && isset($row['customer_delivery_area_id']) && (int) $row['customer_delivery_area_id'] > 0
            ? (int) $row['customer_delivery_area_id'] : null,
        'address' => isset($row['customer_address']) ? (string) $row['customer_address'] : '',
        'email' => isset($row['email']) ? (string) $row['email'] : '',
        'phone_country_dial' => $hasDial && isset($row['customer_phone_country_dial']) ? (string) $row['customer_phone_country_dial'] : null,
        'phone_national' => $hasNat && isset($row['customer_phone_national']) ? (string) $row['customer_phone_national'] : null,
    ];
    $customerId = orange_ensure_customer_with_profile($pdo, $name, $phone, $profile);
    if ($customerId > 0 && $hasCustomerLink) {
        $existing = isset($row['customer_id']) ? (int) $row['customer_id'] : 0;
        if ($existing !== $customerId) {
            try {
                $pdo->prepare('UPDATE storefront_accounts SET customer_id = ? WHERE id = ?')
                    ->execute([$customerId, $storefrontAccountId]);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] sync_storefront_account_to_customer link: ' . $e->getMessage());
                }
            }
        }
    }

    return $customerId;
}
