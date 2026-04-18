<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/journal_voucher.php';

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
        $out[] = [
            'voucher_date' => $r['voucher_date'],
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

function orange_ensure_customer(PDO $pdo, string $nameAr, string $phone): int
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'customers')) {
        return 0;
    }
    $phone = trim($phone);
    if ($phone === '') {
        return 0;
    }
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
