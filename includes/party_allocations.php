<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/party_subledger.php';
require_once __DIR__ . '/gl_settings.php';

function orange_party_allocations_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'party_subledger_allocations');
}

function orange_party_target_gross_on_document(PDO $pdo, string $partyKind, int $partyId, string $refType, int $refId): float
{
    if (!orange_party_subledger_ready($pdo) || $partyId <= 0 || $refId <= 0) {
        return 0.0;
    }
    if ($partyKind === 'customer') {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(debit - credit), 0) FROM party_subledger
             WHERE party_kind = ? AND party_id = ? AND ref_type = ? AND ref_id = ?'
        );
    } elseif ($partyKind === 'supplier') {
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(credit - debit), 0) FROM party_subledger
             WHERE party_kind = ? AND party_id = ? AND ref_type = ? AND ref_id = ?'
        );
    } else {
        return 0.0;
    }
    $st->execute([$partyKind, $partyId, $refType, $refId]);

    return round((float) $st->fetchColumn(), 4);
}

function orange_party_target_allocated_sum(PDO $pdo, string $partyKind, int $partyId, string $refType, int $refId): float
{
    if (!orange_party_allocations_ready($pdo)) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM party_subledger_allocations
         WHERE party_kind = ? AND party_id = ? AND target_ref_type = ? AND target_ref_id = ?'
    );
    $st->execute([$partyKind, $partyId, $refType, $refId]);

    return round((float) $st->fetchColumn(), 4);
}

/**
 * رصيد مستند (طلب/شراء) غير المسدد بعد تخصيصات القبض/الدفع.
 */
function orange_party_document_open(PDO $pdo, string $partyKind, int $partyId, string $refType, int $refId): float
{
    $g = orange_party_target_gross_on_document($pdo, $partyKind, $partyId, $refType, $refId);
    $a = orange_party_target_allocated_sum($pdo, $partyKind, $partyId, $refType, $refId);

    return round(max(0.0, $g - $a), 4);
}

function orange_party_assert_allocation_target(PDO $pdo, string $partyKind, int $partyId, string $refType, int $refId): void
{
    if ($refType === 'order') {
        if (!orange_table_exists($pdo, 'orders')) {
            throw new InvalidArgumentException('جدول الطلبات غير متوفر.');
        }
        $st = $pdo->prepare('SELECT id, customer_id FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$refId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) {
            throw new InvalidArgumentException('الطلب غير موجود.');
        }
        $cid = (int) ($o['customer_id'] ?? 0);
        if ($cid > 0 && $cid !== $partyId) {
            throw new InvalidArgumentException('الطلب لا يخص هذا العميل.');
        }
        if ($cid <= 0) {
            $chk = $pdo->prepare(
                'SELECT 1 FROM party_subledger WHERE party_kind = ? AND party_id = ? AND ref_type = \'order\' AND ref_id = ? LIMIT 1'
            );
            $chk->execute(['customer', $partyId, $refId]);
            if (!$chk->fetch()) {
                throw new InvalidArgumentException('لا توجد ذمة مسجلة لهذا الطلب لهذا العميل.');
            }
        }
        return;
    }
    if ($refType === 'purchase') {
        if (!orange_table_exists($pdo, 'purchases')) {
            throw new InvalidArgumentException('جدول المشتريات غير متوفر.');
        }
        $st = $pdo->prepare('SELECT id, supplier_id FROM purchases WHERE id = ? LIMIT 1');
        $st->execute([$refId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            throw new InvalidArgumentException('عملية الشراء غير موجودة.');
        }
        $sid = (int) ($p['supplier_id'] ?? 0);
        if ($sid > 0 && $sid !== $partyId) {
            throw new InvalidArgumentException('المشتريات لا تخص هذا المورد.');
        }
        if ($sid <= 0) {
            $chk = $pdo->prepare(
                'SELECT 1 FROM party_subledger WHERE party_kind = ? AND party_id = ? AND ref_type = \'purchase\' AND ref_id = ? LIMIT 1'
            );
            $chk->execute(['supplier', $partyId, $refId]);
            if (!$chk->fetch()) {
                throw new InvalidArgumentException('لا توجد ذمة مسجلة لهذا الشراء لهذا المورد.');
            }
        }
        return;
    }
    throw new InvalidArgumentException('نوع المستند غير مدعوم للتخصيص.');
}

/**
 * @param list<array{ref_type:string, ref_id:int, amount:float}> $lines
 */
function orange_party_insert_payment_allocations(
    PDO $pdo,
    string $partyKind,
    int $partyId,
    int $paymentVoucherId,
    float $paymentAmount,
    array $lines
): void {
    if ($lines === []) {
        return;
    }
    if (!orange_party_allocations_ready($pdo)) {
        throw new RuntimeException('جدول تخصيص الذمم غير جاهز.');
    }
    $sum = 0.0;
    $normalized = [];
    $openScratch = [];
    foreach ($lines as $ln) {
        $rt = $ln['ref_type'];
        $rid = $ln['ref_id'];
        $amt = round($ln['amount'], 4);
        if ($amt <= 0.0001) {
            continue;
        }
        orange_party_assert_allocation_target($pdo, $partyKind, $partyId, $rt, $rid);
        $k = $rt . ':' . $rid;
        if (!array_key_exists($k, $openScratch)) {
            $openScratch[$k] = orange_party_document_open($pdo, $partyKind, $partyId, $rt, $rid);
        }
        if ($amt > $openScratch[$k] + 0.02) {
            throw new RuntimeException(
                'تخصيص يتجاوز الرصيد المفتوح للمستند ' . $rt . ' #' . $rid . ' (المتاح: ' . number_format($openScratch[$k], 3) . ').'
            );
        }
        $openScratch[$k] = round($openScratch[$k] - $amt, 4);
        $sum = round($sum + $amt, 4);
        $normalized[] = ['ref_type' => $rt, 'ref_id' => $rid, 'amount' => $amt];
    }
    if ($normalized === []) {
        return;
    }
    if ($sum > $paymentAmount + 0.02) {
        throw new RuntimeException('مجموع التخصيصات (' . number_format($sum, 3) . ') يتجاوز مبلغ السند.');
    }
    $ins = $pdo->prepare(
        'INSERT INTO party_subledger_allocations (party_kind, party_id, payment_voucher_id, target_ref_type, target_ref_id, amount)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ($normalized as $row) {
        $ins->execute([
            $partyKind,
            $partyId,
            $paymentVoucherId,
            $row['ref_type'],
            $row['ref_id'],
            $row['amount'],
        ]);
    }
}

/**
 * @return list<array{ref_type:string, ref_id:int, amount:float}>
 */
function orange_party_normalize_allocations_payload($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $rt = strtolower(trim((string) ($item['ref_type'] ?? $item['target_ref_type'] ?? '')));
        $rid = (int) ($item['ref_id'] ?? $item['target_ref_id'] ?? 0);
        $amt = round((float) ($item['amount'] ?? 0), 4);
        if ($rid <= 0 || $amt <= 0.0001) {
            continue;
        }
        if ($rt === 'orders') {
            $rt = 'order';
        }
        if ($rt === 'purchases') {
            $rt = 'purchase';
        }
        if ($rt !== 'order' && $rt !== 'purchase') {
            continue;
        }
        $out[] = ['ref_type' => $rt, 'ref_id' => $rid, 'amount' => $amt];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_party_open_documents(PDO $pdo, string $partyKind, int $partyId): array
{
    if (!orange_party_subledger_ready($pdo) || $partyId <= 0) {
        return [];
    }
    $refType = $partyKind === 'customer' ? 'order' : 'purchase';
    $st = $pdo->prepare(
        'SELECT DISTINCT ref_id FROM party_subledger
         WHERE party_kind = ? AND party_id = ? AND ref_type = ? AND ref_id IS NOT NULL'
    );
    $st->execute([$partyKind, $partyId, $refType]);
    $ids = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $ids[] = (int) $row['ref_id'];
    }
    $out = [];
    foreach ($ids as $rid) {
        $open = orange_party_document_open($pdo, $partyKind, $partyId, $refType, $rid);
        if ($open <= 0.0001) {
            continue;
        }
        $label = '#' . $rid;
        if ($refType === 'order' && orange_table_exists($pdo, 'orders')) {
            $q = $pdo->prepare('SELECT order_number, created_at FROM orders WHERE id = ? LIMIT 1');
            $q->execute([$rid]);
            $o = $q->fetch(PDO::FETCH_ASSOC);
            if ($o) {
                $label = 'طلب ' . ($o['order_number'] !== '' && $o['order_number'] !== null ? $o['order_number'] : '#' . $rid);
                if (!empty($o['created_at'])) {
                    $label .= ' — ' . substr((string) $o['created_at'], 0, 10);
                }
            }
        } elseif ($refType === 'purchase') {
            $label = 'شراء #' . $rid;
        }
        $out[] = [
            'ref_type' => $refType,
            'ref_id' => $rid,
            'label' => $label,
            'open' => $open,
            'gross' => orange_party_target_gross_on_document($pdo, $partyKind, $partyId, $refType, $rid),
        ];
    }
    usort($out, static function (array $a, array $b): int {
        return ($a['ref_id'] <=> $b['ref_id']);
    });

    return $out;
}

function orange_party_customer_credit_limit(PDO $pdo, int $customerId): ?float
{
    if (!orange_table_exists($pdo, 'customers') || !orange_table_has_column($pdo, 'customers', 'credit_limit')) {
        return null;
    }
    if ($customerId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT credit_limit FROM customers WHERE id = ? LIMIT 1');
    $st->execute([$customerId]);
    $v = $st->fetchColumn();
    if ($v === null || $v === '') {
        return null;
    }
    $f = round((float) $v, 4);

    return $f > 0 ? $f : null;
}

/**
 * @return array{as_of:string, customers:list<array<string, mixed>>, suppliers:list<array<string, mixed>>}
 */
function orange_partner_summary_report(PDO $pdo, bool $includeAging): array
{
    orange_catalog_ensure_schema($pdo);

    $custCols = 'id, name_ar, phone';
    if (orange_table_has_column($pdo, 'customers', 'credit_limit')) {
        $custCols .= ', credit_limit';
    }
    $customers = $pdo->query('SELECT ' . $custCols . ' FROM customers ORDER BY name_ar ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $customersOut = [];
    foreach ($customers as $c) {
        $id = (int) $c['id'];
        $bal = orange_party_balance_customer($pdo, $id);
        $limVal = null;
        if (isset($c['credit_limit']) && $c['credit_limit'] !== null && $c['credit_limit'] !== '') {
            $f = round((float) $c['credit_limit'], 4);
            if ($f > 0.0001) {
                $limVal = $f;
            }
        }
        $row = [
            'id' => $id,
            'name_ar' => $c['name_ar'],
            'phone' => $c['phone'],
            'balance' => $bal,
            'credit_limit' => $limVal,
            'over_limit' => $limVal !== null && $bal > $limVal + 0.02,
        ];
        if ($includeAging && abs($bal) > 0.0001) {
            $row['aging'] = orange_party_aging_buckets($pdo, 'customer', $id, date('Y-m-d'));
        }
        $customersOut[] = $row;
    }

    $suppliers = orange_table_exists($pdo, 'suppliers')
        ? $pdo->query('SELECT id, name, phone FROM suppliers ORDER BY name ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC)
        : [];
    $suppliersOut = [];
    foreach ($suppliers as $s) {
        $id = (int) $s['id'];
        $bal = orange_party_balance_supplier($pdo, $id);
        $row = [
            'id' => $id,
            'name' => $s['name'],
            'phone' => $s['phone'] ?? '',
            'balance' => $bal,
        ];
        if ($includeAging && abs($bal) > 0.0001) {
            $row['aging'] = orange_party_aging_buckets($pdo, 'supplier', $id, date('Y-m-d'));
        }
        $suppliersOut[] = $row;
    }

    return [
        'as_of' => date('Y-m-d'),
        'customers' => $customersOut,
        'suppliers' => $suppliersOut,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_partner_gl_reconcile(PDO $pdo, int $fyId): ?array
{
    if ($fyId <= 0 || !orange_journal_vouchers_ready($pdo)) {
        return null;
    }

    $arId = orange_gl_account_id($pdo, 'ar_credit');
    $apId = orange_gl_account_id($pdo, 'accounts_payable');

    $tb = orange_voucher_account_totals($pdo, $fyId, []);
    $arD = (float) ($tb[$arId]['debit'] ?? 0);
    $arC = (float) ($tb[$arId]['credit'] ?? 0);
    $apD = (float) ($tb[$apId]['debit'] ?? 0);
    $apC = (float) ($tb[$apId]['credit'] ?? 0);

    $glArNet = round($arD - $arC, 4);
    $glApNet = round($apC - $apD, 4);

    $subAr = 0.0;
    $subAp = 0.0;
    if (orange_table_exists($pdo, 'party_subledger')) {
        $st = $pdo->query(
            "SELECT COALESCE(SUM(debit - credit), 0) FROM party_subledger WHERE party_kind = 'customer'"
        );
        $subAr = round((float) $st->fetchColumn(), 4);
        $st2 = $pdo->query(
            "SELECT COALESCE(SUM(credit - debit), 0) FROM party_subledger WHERE party_kind = 'supplier'"
        );
        $subAp = round((float) $st2->fetchColumn(), 4);
    }

    return [
        'fiscal_year_id' => $fyId,
        'ar_credit_account_id' => $arId,
        'accounts_payable_account_id' => $apId,
        'gl' => [
            'ar_debit' => $arD,
            'ar_credit' => $arC,
            'ar_net_dr_minus_cr' => $glArNet,
            'ap_debit' => $apD,
            'ap_credit' => $apC,
            'ap_net_cr_minus_dr' => $glApNet,
        ],
        'subledger' => [
            'customers_dr_minus_cr' => $subAr,
            'suppliers_cr_minus_dr' => $subAp,
        ],
        'variance' => [
            'ar' => round($glArNet - $subAr, 4),
            'ap' => round($glApNet - $subAp, 4),
        ],
    ];
}
