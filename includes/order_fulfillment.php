<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/order_stock.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/sales_gl_accounts.php';
require_once __DIR__ . '/gl_pending_movements.php';
require_once __DIR__ . '/journal_write.php';
require_once __DIR__ . '/party_subledger.php';
require_once __DIR__ . '/party_allocations.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/invoice_ancillary_lines.php';

/**
 * تاريخ ترحيل قيد تسليم الطلب.
 * - الأونلاين (INV-O): يُرحَّل بتاريخ «إنشاء القيود» (وقت التشغيل) — سلوك غير متغيّر.
 * - فاتورة الشركة (INV-C نقدي/آجل): يُرحَّل بتاريخ المستند document_date المُدخَل.
 */
function orange_order_delivery_posting_datetime(array $order, bool $isOnline): string
{
    $now = date('Y-m-d H:i:s');
    if ($isOnline) {
        return $now;
    }
    $docDateRaw = trim((string) ($order['document_date'] ?? ''));
    if ($docDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $docDateRaw)) {
        return substr($docDateRaw, 0, 10) . ' ' . substr($now, 11);
    }

    return $now;
}

/**
 * @param list<array<string, mixed>> $extraLines
 */
function orange_order_post_delivery_sale_gl_amount(
    PDO $pdo,
    array $order,
    float $salesAmount,
    array $extraLines,
    int $customerIdForAr,
    int $ofGlCountryId,
    bool $isCredit,
    bool $isOnline,
    ?array $revenueRule,
    int $debitReceivable,
    int $salesId,
    int $saleJtId,
    string $saleDesc,
    string $salePendingSuffix
): void {
    if ($salesAmount <= 0.0001) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $postingAt = orange_order_delivery_posting_datetime($order, $isOnline);
    $orderId = (int) ($order['id'] ?? 0);
    $salePendingKey = orange_gl_pending_source_key('order', $orderId, 'sale-' . $salePendingSuffix);
    $srcLabel = 'ORDER-' . (string) ($order['order_number'] ?? '');
    $receivableTotal = orange_invoice_ancillary_sales_receivable_total($salesAmount, $extraLines);

    if (!$isCredit) {
        if ($isOnline) {
            $memoSaleLeg = 'مبيعات أونلاين — تسجيل على عملاء نقدي (وسيط مشترك)';
            $memoCashLeg = 'مبيعات أونلاين — تحصيل للخزينة';
            if ($revenueRule !== null) {
                $saleFour = orange_gl_bridge_delivery_sale_four_lines(
                    $pdo,
                    $salesAmount,
                    $revenueRule['debit_key'],
                    $revenueRule['credit_key'],
                    $memoSaleLeg,
                    $memoCashLeg
                );
            } else {
                $saleFour = orange_gl_online_delivery_sale_four_lines($pdo, $salesAmount, $memoSaleLeg, $memoCashLeg);
            }
        } else {
            $memoSaleLeg = 'مبيعات نقدي — تسجيل على عملاء نقدي';
            $memoCashLeg = 'مبيعات نقدي — تحصيل نقدي';
            if ($revenueRule !== null) {
                $saleFour = orange_gl_bridge_delivery_sale_four_lines(
                    $pdo,
                    $salesAmount,
                    $revenueRule['debit_key'],
                    $revenueRule['credit_key'],
                    $memoSaleLeg,
                    $memoCashLeg
                );
            } else {
                $saleFour = orange_gl_cash_delivery_sale_four_lines($pdo, $salesAmount, $memoSaleLeg, $memoCashLeg);
            }
        }
        $afterPost = null;
        if ($customerIdForAr > 0) {
            $cashSaleMemo = $isOnline ? 'مبيعات أونلاين — تسليم' : 'مبيعات نقدي — تسليم';
            $cashCollectMemo = $isOnline ? 'تحصيل أونلاين فوري — تسليم' : 'تحصيل نقدي فوري — تسليم';
            $afterPost = [
                'party_subledger_entries' => [
                    [
                        'party_kind' => 'customer',
                        'party_id' => $customerIdForAr,
                        'debit' => $salesAmount,
                        'credit' => 0.0,
                        'ref_type' => 'order',
                        'ref_id' => $orderId,
                        'memo' => $cashSaleMemo,
                    ],
                    [
                        'party_kind' => 'customer',
                        'party_id' => $customerIdForAr,
                        'debit' => 0.0,
                        'credit' => $salesAmount,
                        'ref_type' => 'order',
                        'ref_id' => $orderId,
                        'memo' => $cashCollectMemo,
                    ],
                ],
            ];
        }
        $glB = orange_gl_posting_bundle_apply_sales_ancillary([
            'is_multi' => true,
            'lines' => $saleFour['lines'],
            'debit' => 0,
            'credit' => 0,
            'voucher_description' => $saleDesc,
            'after_post' => $afterPost,
        ], $extraLines, $salesAmount);
        $afterJson = $glB['after_post'] !== null
            ? json_encode($glB['after_post'], JSON_UNESCAPED_UNICODE)
            : null;
        $afterJson = orange_gl_after_post_json_with_country($afterJson, $ofGlCountryId);
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_multi(
                $pdo,
                $glB['lines'],
                $salePendingKey,
                $srcLabel,
                $postingAt,
                $postingAt,
                $glB['voucher_description'],
                'order_delivery_sale',
                $afterJson
            );

            return;
        }
        $vCashSale = orange_voucher_post($pdo, [
            'voucher_date' => $postingAt,
            'document_entered_at' => $now,
            'description' => $glB['voucher_description'],
            'entry_type' => 'order_delivery_sale',
            'journal_type_id' => $saleJtId > 0 ? $saleJtId : null,
            'country_id' => $ofGlCountryId,
        ], $glB['lines']);
        if ($customerIdForAr > 0 && is_int($vCashSale) && $vCashSale > 0) {
            $cashSaleMemoDirect = $isOnline ? 'مبيعات أونلاين — تسليم' : 'مبيعات نقدي — تسليم';
            $cashCollectMemoDirect = $isOnline ? 'تحصيل أونلاين فوري — تسليم' : 'تحصيل نقدي فوري — تسليم';
            orange_party_subledger_record(
                $pdo,
                'customer',
                $customerIdForAr,
                $vCashSale,
                $receivableTotal,
                0.0,
                'order',
                $orderId,
                $cashSaleMemoDirect
            );
            orange_party_subledger_record(
                $pdo,
                'customer',
                $customerIdForAr,
                $vCashSale,
                0.0,
                $receivableTotal,
                'order',
                $orderId,
                $cashCollectMemoDirect
            );
        }

        return;
    }

    $afterPost = null;
    if ($isCredit && $customerIdForAr > 0) {
        $afterPost = [
            'party_subledger' => [
                'party_kind' => 'customer',
                'party_id' => $customerIdForAr,
                'debit' => $salesAmount,
                'credit' => 0.0,
                'ref_type' => 'order',
                'ref_id' => $orderId,
                'memo' => 'مبيعات آجل — تسليم',
            ],
        ];
    }
    $glB = orange_gl_posting_bundle_apply_sales_ancillary([
        'is_multi' => false,
        'lines' => [],
        'debit' => $debitReceivable,
        'credit' => $salesId,
        'voucher_description' => $saleDesc,
        'after_post' => $afterPost,
        'legacy_ar_subledger' => $isCredit && $customerIdForAr > 0,
    ], $extraLines, $salesAmount);
    $afterJson = $glB['after_post'] !== null
        ? json_encode($glB['after_post'], JSON_UNESCAPED_UNICODE)
        : null;
    $afterJson = orange_gl_after_post_json_with_country($afterJson, $ofGlCountryId);

    if (orange_gl_use_pending_queue($pdo)) {
        if ($glB['is_multi']) {
            orange_gl_pending_enqueue_multi(
                $pdo,
                $glB['lines'],
                $salePendingKey,
                $srcLabel,
                $postingAt,
                $postingAt,
                $glB['voucher_description'],
                'order_delivery_sale',
                $afterJson
            );
        } else {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $salePendingKey,
                'source_label' => $srcLabel,
                'movement_at' => $postingAt,
                'voucher_date' => $postingAt,
                'account_debit' => $glB['debit'],
                'account_credit' => $glB['credit'],
                'amount' => $receivableTotal,
                'description' => $glB['voucher_description'],
                'entry_type' => 'order_delivery_sale',
                'after_post_json' => $afterJson,
            ]);
        }

        return;
    }

    if ($glB['is_multi']) {
        $vSale = orange_voucher_post($pdo, [
            'voucher_date' => $postingAt,
            'document_entered_at' => $now,
            'description' => $glB['voucher_description'],
            'entry_type' => 'order_delivery_sale',
            'journal_type_id' => $saleJtId > 0 ? $saleJtId : null,
            'country_id' => $ofGlCountryId,
        ], $glB['lines']);
        orange_gl_apply_voucher_after_post_hooks($pdo, $vSale, $afterJson);

        return;
    }

    $vSale = orange_voucher_post($pdo, [
        'voucher_date' => $postingAt,
        'document_entered_at' => $now,
        'description' => $glB['voucher_description'],
        'entry_type' => 'order_delivery_sale',
        'journal_type_id' => $saleJtId > 0 ? $saleJtId : null,
        'country_id' => $ofGlCountryId,
    ], [
        ['account_id' => (int) $glB['debit'], 'debit' => $receivableTotal, 'credit' => 0.0, 'memo' => $saleDesc],
        ['account_id' => (int) $glB['credit'], 'debit' => 0.0, 'credit' => $salesAmount, 'memo' => $saleDesc],
    ]);
    if ($isCredit && $customerIdForAr > 0 && is_int($vSale) && $vSale > 0) {
        orange_party_subledger_record(
            $pdo,
            'customer',
            $customerIdForAr,
            $vSale,
            $receivableTotal,
            0,
            'order',
            $orderId,
            'مبيعات آجل — تسليم'
        );
    }
}

/**
 * Stock + customer enrichment when an order is marked completed (website or company manual).
 * GL posting is separate — see orange_post_order_delivery_accounting() (§13.4).
 * Caller must have set orders.status = completed before calling.
 */
function orange_complete_order_fulfillment(PDO $pdo, int $orderId): void
{
    orange_catalog_ensure_schema($pdo);

    $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['status'] ?? '') !== 'completed') {
        throw new RuntimeException('الطلب غير مكتمل أو غير موجود');
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $stockCtx = orange_warehouse_context_for_order($pdo, $order);

    $paymentTerms = 'cash';
    if (orange_table_has_column($pdo, 'orders', 'payment_terms')) {
        $paymentTerms = orange_normalize_payment_terms($order['payment_terms'] ?? 'cash');
    }
    $isCredit = ($paymentTerms === 'credit');

    $orderNumber = (string)($order['order_number'] ?? '');
    $ref = orange_order_stock_reference($orderNumber);
    // Web checkout already decremented stock; do not deduct again on complete.
    $stockAlreadyReserved = $orderNumber !== ''
        && orange_order_has_pending_stock_reservation($pdo, $orderNumber);

    if ($orderNumber !== '' && orange_order_stock_fulfillment_already_done($pdo, $orderNumber, $stockAlreadyReserved)) {
        return;
    }

    // س15: كل عميل (نقدي/آجل/أونلاين) يظهر بشاشة العملاء عند التسليم — مع إثراء البيانات من الطلب.
    $customerIdForAr = 0;
    if (orange_table_exists($pdo, 'customers')) {
        $orderProfile = [
            'area' => (string) ($order['area'] ?? ''),
            'delivery_area_id' => isset($order['delivery_area_id']) && (int) $order['delivery_area_id'] > 0
                ? (int) $order['delivery_area_id'] : null,
            'address' => (string) ($order['address'] ?? ''),
            'email' => isset($order['customer_email']) ? (string) $order['customer_email'] : '',
            'phone_country_dial' => orange_table_has_column($pdo, 'orders', 'phone_country_dial')
                ? (isset($order['phone_country_dial']) ? (string) $order['phone_country_dial'] : null) : null,
            'phone_national' => orange_table_has_column($pdo, 'orders', 'phone_national')
                ? (isset($order['phone_national']) ? (string) $order['phone_national'] : null) : null,
        ];
        $customerIdForAr = orange_ensure_customer_with_profile(
            $pdo,
            (string) ($order['customer_name'] ?? ''),
            (string) ($order['phone'] ?? ''),
            $orderProfile
        );
        if ($customerIdForAr > 0 && orange_table_has_column($pdo, 'orders', 'customer_id')) {
            $pdo->prepare('UPDATE orders SET customer_id = ? WHERE id = ?')->execute([
                $customerIdForAr,
                (int) $order['id'],
            ]);
        }
    }

    if ($isCredit) {
        $civilChk = orange_customer_credit_sale_civil_check(
            $pdo,
            $customerIdForAr,
            (string) ($order['phone'] ?? '')
        );
        if (!$civilChk['ok']) {
            throw new RuntimeException($civilChk['message']);
        }
    }

    $creditSaleTotal = 0.0;
    foreach ($items as $item) {
        $creditSaleTotal = round($creditSaleTotal + orange_order_item_line_net($item), 4);
    }
    if ($isCredit && $customerIdForAr > 0 && $creditSaleTotal > 0.0001) {
        $lim = orange_party_customer_credit_limit($pdo, $customerIdForAr);
        if ($lim !== null) {
            $bal = orange_party_balance_customer($pdo, $customerIdForAr);
            if ($bal + $creditSaleTotal > $lim + 0.02) {
                throw new RuntimeException(
                    'تجاوز حد الائتمان للعميل (الحد: ' . number_format($lim, 3)
                    . ' — الرصيد الحالي: ' . number_format($bal, 3)
                    . ' — إضافة التسليم: ' . number_format($creditSaleTotal, 3) . ').'
                );
            }
        }
    }

    $stockCtx = orange_warehouse_context_for_order($pdo, $order);

    foreach ($items as $item) {
        $variant = orange_order_resolve_variant_from_item($pdo, $item);

        if ($variant && !$stockAlreadyReserved) {
            $qty = (int) $item['qty'];
            $pidForStock = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            $stockChange = orange_warehouse_apply_variant_delta(
                $pdo,
                $stockCtx['warehouse_id'],
                (int) $variant['id'],
                -$qty,
                0
            );
            orange_stock_movement_insert($pdo, [
                'product_id' => $pidForStock,
                'variant_id' => (int) $variant['id'],
                'type' => 'delivered_order',
                'qty' => $qty,
                'old_stock' => $stockChange['old'],
                'new_stock' => $stockChange['new'],
                'reason' => 'Order delivered',
                'reference' => $ref,
                'country_id' => $stockCtx['country_id'],
                'warehouse_id' => $stockCtx['warehouse_id'],
            ]);
        }
    }

    if ($stockAlreadyReserved) {
        $pdo->prepare(
            "UPDATE stock_movements SET type = 'pending_order_fulfilled'
             WHERE reference = ? AND type = 'pending_order'"
        )->execute([$ref]);
    }
}

/**
 * هل اكتمل تأكيد مخزون التسليم (حجز ويب مُنجَز أو خصم delivered_order)؟
 */
function orange_order_stock_fulfillment_already_done(PDO $pdo, string $orderNumber, bool $stockAlreadyReserved): bool
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return false;
    }
    if ($stockAlreadyReserved && orange_order_has_fulfilled_web_reserve($pdo, $orderNumber)) {
        return true;
    }

    return orange_order_has_active_delivered_stock($pdo, $orderNumber);
}

/**
 * §13.7 — ممنوعات تغيير الحالة بعد التسليم.
 */
function orange_order_guard_status_transition(string $prevStatus, string $newStatus): void
{
    if ($prevStatus !== 'completed') {
        return;
    }
    if (in_array($newStatus, ['cancelled', 'rejected', 'pending', 'approved', 'on_the_way'], true)) {
        throw new RuntimeException(
            'لا يمكن تغيير حالة طلب مُسلَّم من هذه الشاشة — استخدم مردود المبيعات للمرتجعات بعد التسليم.'
        );
    }
}

/**
 * GL + party_subledger for a completed order — §13.5 «إنشاء القيود» (outside gl_posting queue).
 * Caller must ensure stock fulfillment already ran (orange_complete_order_fulfillment).
 */
function orange_post_order_delivery_accounting(PDO $pdo, int $orderId): void
{
    orange_catalog_ensure_schema($pdo);

    $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || ($order['status'] ?? '') !== 'completed') {
        throw new RuntimeException('الطلب غير مكتمل أو غير موجود');
    }

    $orderNumber = (string) ($order['order_number'] ?? '');
    $stockCtx = orange_warehouse_context_for_order($pdo, $order);
    $ofGlCountryId = (int) ($stockCtx['country_id'] ?? 0);

    if ($orderNumber !== '' && orange_order_forward_delivery_accounting_exists($pdo, $orderNumber, $ofGlCountryId > 0 ? $ofGlCountryId : null)) {
        return;
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $paymentTerms = 'cash';
    if (orange_table_has_column($pdo, 'orders', 'payment_terms')) {
        $paymentTerms = orange_normalize_payment_terms($order['payment_terms'] ?? 'cash');
    }
    $isCredit = ($paymentTerms === 'credit');
    $isOnline = orange_order_delivery_sale_uses_online_revenue_account($pdo, $order);

    $inventoryId = orange_gl_account_id($pdo, 'inventory', $ofGlCountryId);

    $revenueRule = null;
    if ($isOnline) {
        $revenueRule = orange_gl_order_delivery_setting_keys_from_rule($pdo, 'OSI');
    } elseif ($isCredit) {
        $revenueRule = orange_gl_order_delivery_setting_keys_from_rule($pdo, 'SIN');
    } else {
        $revenueRule = orange_gl_order_delivery_setting_keys_from_rule($pdo, 'CSI');
    }

    $cogsRuleCode = $isOnline ? 'CGO' : ($isCredit ? 'CGT' : 'CGC');
    $cogsRule = orange_gl_order_delivery_setting_keys_from_rule($pdo, $cogsRuleCode);
    $saleJtCode = $isOnline ? 'OSI' : ($isCredit ? 'SIN' : 'CSI');
    $saleJtId = orange_journal_type_id_by_code($pdo, $saleJtCode, $ofGlCountryId);
    $cogsJtId = orange_journal_type_id_by_code($pdo, $cogsRuleCode, $ofGlCountryId);

    if ($isOnline) {
        $debitReceivable = 0;
        $salesId = 0;
    } elseif ($isCredit) {
        if ($revenueRule !== null) {
            $debitReceivable = orange_gl_account_id($pdo, $revenueRule['debit_key'], $ofGlCountryId);
            $salesId = orange_gl_account_id($pdo, $revenueRule['credit_key'], $ofGlCountryId);
        } else {
            $debitReceivable = orange_gl_account_id($pdo, 'ar_credit', $ofGlCountryId);
            $salesId = orange_gl_account_id($pdo, 'sales_revenue_credit', $ofGlCountryId);
        }
    } else {
        $debitReceivable = 0;
        $salesId = 0;
    }

    $cogsDeliveryId = orange_gl_cogs_delivery_account_id($pdo, $ofGlCountryId);
    $cogsDebitId = $cogsDeliveryId;
    $cogsCreditId = $inventoryId;
    if ($cogsRule !== null) {
        $cogsDebitId = orange_gl_account_id($pdo, $cogsRule['debit_key'], $ofGlCountryId);
        $cogsCreditId = orange_gl_account_id($pdo, $cogsRule['credit_key'], $ofGlCountryId);
    }

    $customerIdForAr = 0;
    if (orange_table_has_column($pdo, 'orders', 'customer_id')) {
        $customerIdForAr = (int) ($order['customer_id'] ?? 0);
    }
    if ($customerIdForAr <= 0 && orange_table_exists($pdo, 'customers')) {
        $customerIdForAr = orange_ensure_customer(
            $pdo,
            (string) ($order['customer_name'] ?? ''),
            (string) ($order['phone'] ?? '')
        );
    }

    $extraLines = orange_invoice_ancillary_extra_lines_for_doc(
        $pdo,
        orange_invoice_ancillary_doc_kind_sales(),
        $orderId
    );
    $aggregateSalesGl = $extraLines !== [];
    $orderSalesNet = 0.0;
    foreach ($items as $itemRow) {
        $orderSalesNet += orange_order_item_line_net($itemRow);
    }
    $orderSalesNet = round($orderSalesNet, 4);

    foreach ($items as $idx => $item) {
        $variant = orange_order_resolve_variant_from_item($pdo, $item);
        $salesAmount = orange_order_item_line_net($item);
        $costAmount = $variant ? round((float) $item['cost'] * (int) $item['qty'], 4) : 0.0;

        $now = date('Y-m-d H:i:s');
        $postingAt = orange_order_delivery_posting_datetime($order, $isOnline);
        $lineKey = isset($item['id']) ? (string) (int) $item['id'] : (string) $idx;
        $salePendingKey = orange_gl_pending_source_key('order', (int) $order['id'], 'sale-' . $lineKey);
        $cogsPendingKey = orange_gl_pending_source_key('order', (int) $order['id'], 'cogs-' . $lineKey);
        $saleDesc = $isOnline
            ? 'قيد مبيعات أونلاين — تسليم'
            : ($isCredit ? 'قيد مبيعات آجل — تسليم' : 'قيد مبيعات نقدي — تسليم');
        $cogsDesc = $isOnline
            ? 'قيد تكلفة مبيعات أونلاين — تسليم'
            : ($isCredit ? 'قيد تكلفة مبيعات آجل — تسليم' : 'قيد تكلفة مبيعات نقدي — تسليم');
        $srcLabel = 'ORDER-' . $order['order_number'];

        if ($salesAmount > 0.0001 && !$aggregateSalesGl) {
            if (!$isCredit) {
                if ($isOnline) {
                    $memoSaleLeg = 'مبيعات أونلاين — تسجيل على عملاء نقدي (وسيط مشترك)';
                    $memoCashLeg = 'مبيعات أونلاين — تحصيل للخزينة';
                    if ($revenueRule !== null) {
                        $saleFour = orange_gl_bridge_delivery_sale_four_lines(
                            $pdo,
                            $salesAmount,
                            $revenueRule['debit_key'],
                            $revenueRule['credit_key'],
                            $memoSaleLeg,
                            $memoCashLeg
                        );
                    } else {
                        $saleFour = orange_gl_online_delivery_sale_four_lines($pdo, $salesAmount, $memoSaleLeg, $memoCashLeg);
                    }
                } else {
                    $memoSaleLeg = 'مبيعات نقدي — تسجيل على عملاء نقدي';
                    $memoCashLeg = 'مبيعات نقدي — تحصيل نقدي';
                    if ($revenueRule !== null) {
                        $saleFour = orange_gl_bridge_delivery_sale_four_lines(
                            $pdo,
                            $salesAmount,
                            $revenueRule['debit_key'],
                            $revenueRule['credit_key'],
                            $memoSaleLeg,
                            $memoCashLeg
                        );
                    } else {
                        $saleFour = orange_gl_cash_delivery_sale_four_lines($pdo, $salesAmount, $memoSaleLeg, $memoCashLeg);
                    }
                }
                $cashAfterJson = null;
                if ($customerIdForAr > 0) {
                    $cashSaleMemo = $isOnline
                        ? 'مبيعات أونلاين — تسليم'
                        : 'مبيعات نقدي — تسليم';
                    $cashCollectMemo = $isOnline
                        ? 'تحصيل أونلاين فوري — تسليم'
                        : 'تحصيل نقدي فوري — تسليم';
                    $cashAfterJson = json_encode([
                        'party_subledger_entries' => [
                            [
                                'party_kind' => 'customer',
                                'party_id' => $customerIdForAr,
                                'debit' => $salesAmount,
                                'credit' => 0.0,
                                'ref_type' => 'order',
                                'ref_id' => (int) $order['id'],
                                'memo' => $cashSaleMemo,
                            ],
                            [
                                'party_kind' => 'customer',
                                'party_id' => $customerIdForAr,
                                'debit' => 0.0,
                                'credit' => $salesAmount,
                                'ref_type' => 'order',
                                'ref_id' => (int) $order['id'],
                                'memo' => $cashCollectMemo,
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE);
                }
                $cashAfterJson = orange_gl_after_post_json_with_country($cashAfterJson, $ofGlCountryId);
                if (orange_gl_use_pending_queue($pdo)) {
                    orange_gl_pending_enqueue_multi(
                        $pdo,
                        $saleFour['lines'],
                        $salePendingKey,
                        $srcLabel,
                        $postingAt,
                        $postingAt,
                        $saleDesc,
                        'order_delivery_sale',
                        $cashAfterJson
                    );
                } else {
                    $vCashSale = orange_voucher_post($pdo, [
                        'voucher_date' => $postingAt,
                        'document_entered_at' => $now,
                        'description' => $saleDesc,
                        'entry_type' => 'order_delivery_sale',
                        'journal_type_id' => $saleJtId > 0 ? $saleJtId : null,
                        'country_id' => $ofGlCountryId,
                    ], $saleFour['lines']);
                    if ($customerIdForAr > 0 && is_int($vCashSale) && $vCashSale > 0) {
                        $cashSaleMemoDirect = $isOnline
                            ? 'مبيعات أونلاين — تسليم'
                            : 'مبيعات نقدي — تسليم';
                        $cashCollectMemoDirect = $isOnline
                            ? 'تحصيل أونلاين فوري — تسليم'
                            : 'تحصيل نقدي فوري — تسليم';
                        orange_party_subledger_record(
                            $pdo,
                            'customer',
                            $customerIdForAr,
                            $vCashSale,
                            $salesAmount,
                            0.0,
                            'order',
                            (int) $order['id'],
                            $cashSaleMemoDirect
                        );
                        orange_party_subledger_record(
                            $pdo,
                            'customer',
                            $customerIdForAr,
                            $vCashSale,
                            0.0,
                            $salesAmount,
                            'order',
                            (int) $order['id'],
                            $cashCollectMemoDirect
                        );
                    }
                }
            } elseif (orange_gl_use_pending_queue($pdo)) {
                $afterJson = null;
                if ($isCredit && $customerIdForAr > 0) {
                    $afterJson = json_encode([
                        'party_subledger' => [
                            'party_kind' => 'customer',
                            'party_id' => $customerIdForAr,
                            'debit' => $salesAmount,
                            'credit' => 0.0,
                            'ref_type' => 'order',
                            'ref_id' => (int) $order['id'],
                            'memo' => 'مبيعات آجل — تسليم',
                        ],
                    ], JSON_UNESCAPED_UNICODE);
                }
                orange_gl_pending_enqueue_simple($pdo, [
                    'reference' => $salePendingKey,
                    'source_label' => $srcLabel,
                    'movement_at' => $postingAt,
                    'voucher_date' => $postingAt,
                    'account_debit' => $debitReceivable,
                    'account_credit' => $salesId,
                    'amount' => $salesAmount,
                    'description' => $saleDesc,
                    'entry_type' => 'order_delivery_sale',
                    'after_post_json' => orange_gl_after_post_json_with_country($afterJson, $ofGlCountryId),
                ]);
            } else {
                $vSale = orange_voucher_post($pdo, [
                    'voucher_date' => $postingAt,
                    'document_entered_at' => $now,
                    'description' => $saleDesc,
                    'entry_type' => 'order_delivery_sale',
                    'journal_type_id' => $saleJtId > 0 ? $saleJtId : null,
                    'country_id' => $ofGlCountryId,
                ], [
                    ['account_id' => $debitReceivable, 'debit' => $salesAmount, 'credit' => 0, 'memo' => $saleDesc],
                    ['account_id' => $salesId, 'debit' => 0, 'credit' => $salesAmount, 'memo' => $saleDesc],
                ]);
                if ($isCredit && $customerIdForAr > 0) {
                    orange_party_subledger_record(
                        $pdo,
                        'customer',
                        $customerIdForAr,
                        $vSale,
                        $salesAmount,
                        0,
                        'order',
                        (int) $order['id'],
                        'مبيعات آجل — تسليم'
                    );
                }
            }
        }

        if ($costAmount > 0.0001) {
            if (orange_gl_use_pending_queue($pdo)) {
                orange_gl_pending_enqueue_simple($pdo, [
                    'reference' => $cogsPendingKey,
                    'source_label' => $srcLabel,
                    'movement_at' => $postingAt,
                    'voucher_date' => $postingAt,
                    'account_debit' => $cogsDebitId,
                    'account_credit' => $cogsCreditId,
                    'amount' => $costAmount,
                    'description' => $cogsDesc,
                    'entry_type' => 'order_delivery_cogs',
                    'after_post_json' => orange_gl_after_post_json_with_country(null, $ofGlCountryId),
                ]);
            } else {
                orange_voucher_post($pdo, [
                    'voucher_date' => $postingAt,
                    'document_entered_at' => $now,
                    'description' => $cogsDesc,
                    'entry_type' => 'order_delivery_cogs',
                    'journal_type_id' => $cogsJtId > 0 ? $cogsJtId : null,
                    'country_id' => $ofGlCountryId,
                ], [
                    ['account_id' => $cogsDebitId, 'debit' => $costAmount, 'credit' => 0, 'memo' => $cogsDesc],
                    ['account_id' => $cogsCreditId, 'debit' => 0, 'credit' => $costAmount, 'memo' => $cogsDesc],
                ]);
            }
        }
    }

    if ($aggregateSalesGl && $orderSalesNet > 0.0001) {
        orange_order_post_delivery_sale_gl_amount(
            $pdo,
            $order,
            $orderSalesNet,
            $extraLines,
            $customerIdForAr,
            $ofGlCountryId,
            $isCredit,
            $isOnline,
            $revenueRule,
            $debitReceivable,
            $salesId,
            $saleJtId,
            $isOnline
                ? 'قيد مبيعات أونلاين — تسليم'
                : ($isCredit ? 'قيد مبيعات آجل — تسليم' : 'قيد مبيعات نقدي — تسليم'),
            'agg'
        );
    }
}

/**
 * هل وُجدت محاسبة تسليم للطلب (سندات مرحّلة أو صفوف معلّقة مبيعات/تكلفة)؟
 */
function orange_order_forward_delivery_accounting_exists(PDO $pdo, string $orderNumber, ?int $countryId = null): bool
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return false;
    }
    if (orange_order_fulfillment_vouchers_exist($pdo, $orderNumber, $countryId)) {
        return true;
    }
    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        return false;
    }
    $st = $pdo->prepare(
        'SELECT 1 FROM orange_gl_pending_movements WHERE reference LIKE ? LIMIT 1'
    );
    $st->execute(['ORDER-' . $orderNumber . '-S-%']);

    return (bool) $st->fetchColumn();
}

/**
 * هل سُجّل مردود/عكس تسليم مسبقاً (طابور أو دفتر)؟
 */
/**
 * حجز مخزون ويب صُنِّف كمُنجَز بعد اكتمال الطلب (قبل إلغاء التسليم).
 */
function orange_order_has_fulfilled_web_reserve(PDO $pdo, string $orderNumber): bool
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return false;
    }
    $ref = orange_order_stock_reference($orderNumber);
    $st = $pdo->prepare(
        "SELECT 1 FROM stock_movements WHERE reference = ? AND type = 'pending_order_fulfilled' LIMIT 1"
    );
    $st->execute([$ref]);

    return (bool) $st->fetchColumn();
}

/**
 * تسليم يدوي سجّل خصماً من المخزون ومرجع الطلب (بعد إضافة عمود reference).
 */
function orange_order_has_active_delivered_stock(PDO $pdo, string $orderNumber): bool
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '' || !orange_table_has_column($pdo, 'stock_movements', 'reference')) {
        return false;
    }
    $ref = orange_order_stock_reference($orderNumber);
    $st = $pdo->prepare(
        "SELECT 1 FROM stock_movements WHERE reference = ? AND type = 'delivered_order' LIMIT 1"
    );
    $st->execute([$ref]);

    return (bool) $st->fetchColumn();
}

function orange_order_return_fulfillment_recorded(PDO $pdo, string $orderNumber, ?int $countryId = null): bool
{
    $orderNumber = trim($orderNumber);
    if ($orderNumber === '') {
        return false;
    }
    $like = 'ORDER-' . $orderNumber . '-RS-%';
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        $st = $pdo->prepare(
            'SELECT 1 FROM orange_gl_pending_movements WHERE reference LIKE ? LIMIT 1'
        );
        $st->execute([$like]);
        if ($st->fetchColumn()) {
            return true;
        }
    }
    if (function_exists('orange_journal_vouchers_ready') && orange_journal_vouchers_ready($pdo)) {
        return orange_gl_voucher_reference_like_exists($pdo, $like, $countryId);
    }

    return false;
}

/**
 * عكس تسليم طلب كان مكتملاً عند تحويله إلى ملغى/مرفوض: إرجاع المخزون، وحذف قيود التسليم المعلّقة،
 * وإنشاء قيود مردود إن وُجدت سندات تسليم مرحّلة.
 *
 * @param 'cancelled'|'rejected' $newStatus
 */
function orange_order_reverse_completed_fulfillment(PDO $pdo, int $orderId, string $prevStatus, string $newStatus): void
{
    if ($prevStatus !== 'completed' || !in_array($newStatus, ['cancelled', 'rejected'], true)) {
        return;
    }
    orange_catalog_ensure_schema($pdo);

    $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return;
    }
    $orderNumber = (string) ($order['order_number'] ?? '');
    if ($orderNumber === '') {
        return;
    }
    $orderCountryId = 0;
    if (orange_table_has_country_id($pdo, 'orders')) {
        $orderCountryId = (int) ($order['country_id'] ?? 0);
    }
    if ($orderCountryId <= 0) {
        $stockCtxEarly = orange_warehouse_context_for_order($pdo, $order);
        $orderCountryId = (int) ($stockCtxEarly['country_id'] ?? 0);
    }
    $orderCountryArg = $orderCountryId > 0 ? $orderCountryId : null;
    if (orange_order_return_fulfillment_recorded($pdo, $orderNumber, $orderCountryArg)) {
        return;
    }

    $anyForward = orange_order_forward_delivery_accounting_exists($pdo, $orderNumber, $orderCountryArg)
        || orange_order_has_fulfilled_web_reserve($pdo, $orderNumber)
        || orange_order_has_active_delivered_stock($pdo, $orderNumber);
    orange_gl_pending_remove_forward_fulfillment($pdo, $orderNumber);
    $hadPostedSale = orange_order_fulfillment_vouchers_exist($pdo, $orderNumber, $orderCountryArg);

    if (!$anyForward) {
        return;
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stockCtx = orange_warehouse_context_for_order($pdo, $order);
    $ofGlCountryId = (int) ($stockCtx['country_id'] ?? 0);

    $paymentTerms = 'cash';
    if (orange_table_has_column($pdo, 'orders', 'payment_terms')) {
        $paymentTerms = orange_normalize_payment_terms($order['payment_terms'] ?? 'cash');
    }
    $isCredit = ($paymentTerms === 'credit');
    $isOnline = orange_order_delivery_sale_uses_online_revenue_account($pdo, $order);

    $revenueRuleRev = null;
    if ($isOnline) {
        $revenueRuleRev = orange_gl_order_delivery_setting_keys_from_rule($pdo, 'OSI');
    } elseif ($isCredit) {
        $revenueRuleRev = orange_gl_order_delivery_setting_keys_from_rule($pdo, 'SIN');
    } else {
        $revenueRuleRev = orange_gl_order_delivery_setting_keys_from_rule($pdo, 'CSI');
    }
    $cogsRuleCodeRev = $isOnline ? 'CGO' : ($isCredit ? 'CGT' : 'CGC');
    $cogsRuleRev = orange_gl_order_delivery_setting_keys_from_rule($pdo, $cogsRuleCodeRev);

    $inventoryId = orange_gl_account_id($pdo, 'inventory', $ofGlCountryId);
    $cogsInventoryDebitOnReturn = $inventoryId;
    if ($cogsRuleRev !== null) {
        $cogsInventoryDebitOnReturn = orange_gl_account_id($pdo, $cogsRuleRev['credit_key'], $ofGlCountryId);
    }

    if ($isOnline) {
        $debitReceivable = orange_gl_account_id($pdo, 'cash', $ofGlCountryId);
        $salesId = orange_gl_order_return_sale_debit_account_id($pdo, 'online');
    } elseif ($isCredit) {
        $debitReceivable = $revenueRuleRev !== null
            ? orange_gl_account_id($pdo, $revenueRuleRev['debit_key'], $ofGlCountryId)
            : orange_gl_account_id($pdo, 'ar_credit', $ofGlCountryId);
        $salesId = orange_gl_order_return_sale_debit_account_id($pdo, 'credit');
    } else {
        $debitReceivable = orange_gl_account_id($pdo, 'cash', $ofGlCountryId);
        $salesId = orange_gl_order_return_sale_debit_account_id($pdo, 'cash');
    }
    $cogsReturnId = orange_gl_cogs_return_account_id($pdo);

    $customerIdForAr = 0;
    if ($isCredit && orange_table_exists($pdo, 'customers')) {
        if (orange_table_has_column($pdo, 'orders', 'customer_id')) {
            $customerIdForAr = (int) ($order['customer_id'] ?? 0);
        }
        if ($customerIdForAr <= 0) {
            $orderCountryId = 0;
            if (orange_table_has_country_id($pdo, 'orders')) {
                $orderCountryId = (int) ($order['country_id'] ?? 0);
            }
            $customerIdForAr = orange_ensure_customer(
                $pdo,
                (string) ($order['customer_name'] ?? ''),
                (string) ($order['phone'] ?? ''),
                $orderCountryId > 0 ? $orderCountryId : null
            );
        }
    }

    $srcLabel = 'ORDER-' . $orderNumber;
    $stockRef = orange_order_stock_reference($orderNumber);
    $now = date('Y-m-d H:i:s');

    foreach ($items as $idx => $item) {
        $variant = orange_order_resolve_variant_from_item($pdo, $item);
        $qty = (int) ($item['qty'] ?? 0);
        if ($variant && $qty > 0) {
            $stockChange = orange_warehouse_apply_variant_delta(
                $pdo,
                $stockCtx['warehouse_id'],
                (int) $variant['id'],
                $qty,
                0
            );
            orange_stock_movement_insert($pdo, [
                'product_id' => isset($item['product_id']) ? (int) $item['product_id'] : 0,
                'variant_id' => (int) $variant['id'],
                'type' => 'order_return',
                'qty' => $qty,
                'old_stock' => $stockChange['old'],
                'new_stock' => $stockChange['new'],
                'reason' => 'Order completed reversed',
                'reference' => $stockRef,
                'country_id' => $stockCtx['country_id'],
                'warehouse_id' => $stockCtx['warehouse_id'],
            ]);
        }

        $salesAmount = orange_order_item_line_net($item);
        $costAmount = $variant ? round((float) $item['cost'] * $qty, 4) : 0.0;
        $lineKey = isset($item['id']) ? (string) (int) $item['id'] : (string) $idx;
        $saleRetRef = 'ORDER-' . $orderNumber . '-RS-' . $lineKey;
        $cogsRetRef = 'ORDER-' . $orderNumber . '-RC-' . $lineKey;
        $saleDesc = $isOnline
            ? 'قيد مردود مبيعات أونلاين — إلغاء تسليم'
            : ($isCredit ? 'قيد مردود مبيعات آجل — إلغاء تسليم' : 'قيد مردود مبيعات نقدي — إلغاء تسليم');
        $cogsDesc = $isOnline
            ? 'قيد تكلفة مردود مبيعات أونلاين — إلغاء تسليم'
            : ($isCredit ? 'قيد تكلفة مردود مبيعات آجل — إلغاء تسليم' : 'قيد تكلفة مردود مبيعات نقدي — إلغاء تسليم');

        if (!$hadPostedSale) {
            continue;
        }
        if ($salesAmount <= 0.0001 && $costAmount <= 0.0001) {
            continue;
        }

        if ($salesAmount > 0.0001) {
            if (orange_gl_use_pending_queue($pdo)) {
                $afterJson = null;
                if ($isCredit && $customerIdForAr > 0) {
                    $afterJson = json_encode([
                        'party_subledger' => [
                            'party_kind' => 'customer',
                            'party_id' => $customerIdForAr,
                            'debit' => 0.0,
                            'credit' => $salesAmount,
                            'ref_type' => 'order',
                            'ref_id' => (int) $order['id'],
                            'memo' => 'مردود مبيعات آجل — إلغاء تسليم',
                        ],
                    ], JSON_UNESCAPED_UNICODE);
                }
                orange_gl_pending_enqueue_simple($pdo, [
                    'reference' => $saleRetRef,
                    'source_label' => $srcLabel,
                    'movement_at' => $now,
                    'voucher_date' => $now,
                    'account_debit' => $salesId,
                    'account_credit' => $debitReceivable,
                    'amount' => $salesAmount,
                    'description' => $saleDesc,
                    'entry_type' => 'order_return_sale',
                    'after_post_json' => orange_gl_after_post_json_with_country($afterJson, $ofGlCountryId),
                ]);
            } else {
                $vSale = orange_journal_insert_line($pdo, [
                    'date' => $now,
                    'account_debit' => $salesId,
                    'account_credit' => $debitReceivable,
                    'amount' => $salesAmount,
                    'reference' => $saleRetRef,
                    'description' => $saleDesc,
                    'entry_type' => 'order_return_sale',
                ]);
                if ($isCredit && $customerIdForAr > 0) {
                    orange_party_subledger_record(
                        $pdo,
                        'customer',
                        $customerIdForAr,
                        $vSale,
                        0.0,
                        $salesAmount,
                        'order',
                        (int) $order['id'],
                        'مردود مبيعات آجل — إلغاء تسليم'
                    );
                }
            }
        }

        if ($costAmount > 0.0001) {
            if (orange_gl_use_pending_queue($pdo)) {
                orange_gl_pending_enqueue_simple($pdo, [
                    'reference' => $cogsRetRef,
                    'source_label' => $srcLabel,
                    'movement_at' => $now,
                    'voucher_date' => $now,
                    'account_debit' => $cogsInventoryDebitOnReturn,
                    'account_credit' => $cogsReturnId,
                    'amount' => $costAmount,
                    'description' => $cogsDesc,
                    'entry_type' => 'order_return_cogs',
                    'after_post_json' => orange_gl_after_post_json_with_country(null, $ofGlCountryId),
                ]);
            } else {
                orange_journal_insert_line($pdo, [
                    'date' => $now,
                    'account_debit' => $cogsInventoryDebitOnReturn,
                    'account_credit' => $cogsReturnId,
                    'amount' => $costAmount,
                    'reference' => $cogsRetRef,
                    'description' => $cogsDesc,
                    'entry_type' => 'order_return_cogs',
                ]);
            }
        }
    }

    $reserveRef = orange_order_stock_reference($orderNumber);
    $pdo->prepare(
        "UPDATE stock_movements SET type = 'pending_order_void', reason = ?
         WHERE reference = ? AND type = 'pending_order_fulfilled'"
    )->execute(['إلغاء تسليم — إبطال حجز الويب بعد الاكتمال', $reserveRef]);
    if (orange_table_has_column($pdo, 'stock_movements', 'reference')) {
        $pdo->prepare(
            "UPDATE stock_movements SET type = 'delivered_order_void', reason = ?
             WHERE reference = ? AND type = 'delivered_order'"
        )->execute(['إلغاء تسليم — عكس تسليم يدوي', $reserveRef]);
    }
}
