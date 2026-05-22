<?php

declare(strict_types=1);

require_once __DIR__ . '/journal_voucher.php';

/**
 * تقسيم «أعمار» مديونية/التزام الحساب في الدفتر العام وفق أسطر القيد لهذا الحساب فقط،
 * بافتراض تسوية المراكز المتعاكسة بالأقدمية (FIFO) من حركة الحساب فقط — لا ربط بحساب طرف (عميل/مورد).
 *
 * الصافي لكل سطر = مدين − دائن. كل حركة تصفّ أي كوم مفتوح بالأقدمية، ثم تُكمّ الرصيد في كوم الطرف المتبقي؛
 * عند نهاية المسار تُحمَّل الفترات الزمنية على الكوم المتبقي المطابق لإشارة رصيد الحساب إلى تاريخ قطع («إلى الفترة»).
 */

/**
 * تسميات فئات أيام موحّدة مع تقارير الذمم الكلاسيكية (للقراءة المتناسقة بين الشاشات).
 *
 * @return array<string, string>
 */
function orange_gl_account_aging_bucket_labels_ar(): array
{
    return [
        'days_0_30' => 'حتى 30 يوماً',
        'days_31_60' => 'من 31 إلى 60 يوماً',
        'days_61_90' => 'من 61 إلى 90 يوماً',
        'days_91_plus' => 'أكثر من 90 يوماً',
    ];
}

/**
 * تسوية صافي الحركة (مدين − دائن) على كومين مفتوحين: يُستوفى أولاً الكوم المقابل بالأقدمية، ثم يُضاف المتبقي للكوم المطابق.
 *
 * @param list<array{amt:float,date:string}> $debtPieces
 * @param list<array{amt:float,date:string}> $credPieces
 */
function orange_gl_dual_fifo_apply_net(array &$debtPieces, array &$credPieces, float $net, string $vd): void
{
    $net = round($net, 4);
    $eps = 0.0001;
    if (abs($net) <= $eps) {
        return;
    }

    if ($net > $eps) {
        $rem = $net;
        while ($rem > $eps && $credPieces !== []) {
            $take = min($credPieces[0]['amt'], $rem);
            $credPieces[0]['amt'] = round($credPieces[0]['amt'] - $take, 4);
            $rem = round($rem - $take, 4);
            if ($credPieces[0]['amt'] < $eps) {
                array_shift($credPieces);
            }
        }
        if ($rem > $eps) {
            $debtPieces[] = ['amt' => $rem, 'date' => $vd];
        }

        return;
    }

    $rem = abs($net);
    while ($rem > $eps && $debtPieces !== []) {
        $take = min($debtPieces[0]['amt'], $rem);
        $debtPieces[0]['amt'] = round($debtPieces[0]['amt'] - $take, 4);
        $rem = round($rem - $take, 4);
        if ($debtPieces[0]['amt'] < $eps) {
            array_shift($debtPieces);
        }
    }
    if ($rem > $eps) {
        $credPieces[] = ['amt' => $rem, 'date' => $vd];
    }
}

/**
 * @return array<string, mixed>
 */
function orange_gl_account_statement_aging_buckets(PDO $pdo, int $accountId, string $asOfYmd, ?int $countryId = null): array
{
    $labels = orange_gl_account_aging_bucket_labels_ar();
    $empty = [
        'as_of' => $asOfYmd,
        'account_id' => $accountId,
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
        'method' => 'gl_account_fifo',
    ];

    if ($accountId <= 0 || ! orange_table_exists($pdo, 'journal_vouchers')) {
        return $empty;
    }

    $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfYmd) ? $asOfYmd : date('Y-m-d');
    $empty['as_of'] = $asOf;

    $jvBind = orange_gl_voucher_country_bind($pdo, 'jv', $countryId);

    try {
        $stBal = $pdo->prepare(
            'SELECT COALESCE(SUM(jl.debit - jl.credit), 0) AS bal
             FROM journal_lines jl
             INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
             WHERE jl.account_id = ? AND DATE(jv.voucher_date) <= ?' . $jvBind['sql']
        );
        $stBal->execute(array_merge([$accountId, $asOf], $jvBind['params']));
        $balance = round((float) $stBal->fetchColumn(), 4);

        $stL = $pdo->prepare(
            "SELECT ROUND(jl.debit, 4) AS debit, ROUND(jl.credit, 4) AS credit,
                    DATE(jv.voucher_date) AS vd
             FROM journal_lines jl
             INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
             WHERE jl.account_id = ? AND DATE(jv.voucher_date) <= ?" . $jvBind['sql'] . '
             ORDER BY jv.voucher_date ASC, jv.id ASC, jl.line_no ASC'
        );
        $stL->execute(array_merge([$accountId, $asOf], $jvBind['params']));
        $rows = $stL->fetchAll(PDO::FETCH_ASSOC);

        /** @var list<array{amt:float,date:string}> $debtPieces */
        $debtPieces = [];
        /** @var list<array{amt:float,date:string}> $credPieces */
        $credPieces = [];

        foreach ($rows as $r) {
            $d = round((float) ($r['debit'] ?? 0), 4);
            $c = round((float) ($r['credit'] ?? 0), 4);
            $vd = substr((string) ($r['vd'] ?? $asOf), 0, 10);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $vd)) {
                $vd = $asOf;
            }

            orange_gl_dual_fifo_apply_net($debtPieces, $credPieces, round($d - $c, 4), $vd);
        }

        $eps = 0.00015;
        $piecesForBuckets = [];

        if ($balance > $eps) {
            $piecesForBuckets = $debtPieces;
        } elseif ($balance < -$eps) {
            $piecesForBuckets = $credPieces;
        }

        $prepay = 0.0;

        $buckets = [
            'days_0_30' => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'days_91_plus' => 0.0,
        ];
        $asTs = strtotime($asOf . ' 12:00:00') ?: time();
        foreach ($piecesForBuckets as $ch) {
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
            'account_id' => $accountId,
            'balance' => $balance,
            'open_in_buckets' => $bucketTotal,
            'prepayment' => $prepay,
            'buckets' => $buckets,
            'bucket_labels_ar' => $labels,
            'method' => 'gl_account_fifo_dual',
            'basis' => 'journal_lines_until_as_of',
        ];
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_gl_account_statement_aging_buckets: ' . $e->getMessage());
        }

        return $empty;
    }
}
