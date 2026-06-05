<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/gl_settings.php';
require_once __DIR__ . '/../../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../../includes/journal_write.php';
require_once __DIR__ . '/../../../includes/journal_voucher.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/sales_return_helpers.php';
require_once __DIR__ . '/../../../includes/sales_return_analytics.php';
require_once __DIR__ . '/../../../includes/purchase_helpers.php';
require_once __DIR__ . '/../../../includes/sales_gl_accounts.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/invoice_ancillary_lines.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $customerId = (int) ($data['customer_id'] ?? 0);
    $channel = trim((string) ($data['channel'] ?? $data['payment_channel'] ?? ''));
    if ($channel === '') {
        $channel = 'credit';
    }
    $orderIdOpt = (int) ($data['order_id'] ?? 0);
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    $notes = trim((string) ($data['notes'] ?? ''));
    $extraInput = orange_invoice_ancillary_parse_request_lines(
        $data,
        orange_invoice_ancillary_doc_kind_sales_return()
    );

    if (!in_array($channel, ['cash', 'online', 'credit'], true) || $items === []) {
        json_response(['success' => false, 'message' => 'بيانات مردود المبيعات غير صحيحة'], 422);
    }

    if ($channel === 'credit' && $customerId <= 0) {
        json_response(['success' => false, 'message' => 'مردود مبيعات آجل يتطلّب عميلاً.'], 422);
    }

    $pdo->beginTransaction();

    $returnCountryId = orange_admin_context_country_id($pdo);

    if ($orderIdOpt > 0) {
        $chk = $pdo->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
        $chk->execute([$orderIdOpt]);
        if (!$chk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'الطلب المرجعي غير موجود'], 422);
        }
        try {
            orange_admin_assert_entity_country($pdo, 'orders', $orderIdOpt);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        if (orange_table_has_country_id($pdo, 'orders')) {
            $oc = $pdo->prepare('SELECT country_id FROM orders WHERE id = ? LIMIT 1');
            $oc->execute([$orderIdOpt]);
            $returnCountryId = (int) ($oc->fetchColumn() ?: $returnCountryId);
        }
    }

    if ($channel === 'credit') {
        if (!orange_table_exists($pdo, 'customers')) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 422);
        }
        $cchk = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $cchk->execute([$customerId]);
        if (!$cchk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'العميل غير موجود'], 422);
        }
        try {
            orange_admin_assert_entity_country($pdo, 'customers', $customerId);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        if (orange_table_has_country_id($pdo, 'customers')) {
            $cc = $pdo->prepare('SELECT country_id FROM customers WHERE id = ? LIMIT 1');
            $cc->execute([$customerId]);
            $returnCountryId = (int) ($cc->fetchColumn() ?: $returnCountryId);
        }
    }

    $revenueTotal = 0.0;
    $cogsTotal = 0.0;
    $normalizedItems = [];
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر مردود غير مكتمل');
        }
        try {
            orange_admin_assert_entity_country($pdo, 'products', $productId);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage());
        }
        $variantId = orange_purchase_resolve_variant_id(
            $pdo,
            $productId,
            (int) ($item['variant_id'] ?? 0)
        );
        $line = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty' => $qty,
            'price' => (float) ($item['price'] ?? 0),
            'line_discount' => (float) ($item['line_discount'] ?? 0),
            'cost' => (float) ($item['cost'] ?? -1),
        ];
        $net = orange_sales_return_line_net($line);
        if ($net <= 0.0001) {
            throw new RuntimeException('صافي سطر المردود غير صالح (سعر/كمية/خصم)');
        }
        $unitCost = orange_sales_return_resolve_unit_cost($pdo, $productId, (float) $line['cost']);
        $lineCost = round($unitCost * $qty, 4);
        $revenueTotal += $net;
        $cogsTotal += $lineCost;
        $line['line_net'] = $net;
        $line['line_cogs'] = $lineCost;
        $normalizedItems[] = $line;
    }
    $revenueTotal = round($revenueTotal, 4);
    $cogsTotal = round($cogsTotal, 4);

    if ($orderIdOpt > 0) {
        orange_sales_return_assert_qty_against_order($pdo, $orderIdOpt, $normalizedItems);
    }

    $tmpNum = 'SR-TMP-' . bin2hex(random_bytes(6));
    $pdo->prepare(
        'INSERT INTO sales_returns (return_number, order_id, customer_id, channel_id, type, total, notes)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $tmpNum,
        $orderIdOpt > 0 ? $orderIdOpt : null,
        $customerId > 0 ? $customerId : null,
        null,
        $channel,
        $revenueTotal,
        $notes !== '' ? $notes : null,
    ]);
    $returnId = (int) $pdo->lastInsertId();
    if (orange_table_has_column($pdo, 'sales_returns', 'currency_code')) {
        $retCur = orange_gl_functional_currency_code($pdo, $returnCountryId);
        $pdo->prepare('UPDATE sales_returns SET currency_code = ? WHERE id = ?')->execute([$retCur, $returnId]);
    }
    $retNum = orange_country_document_ref($pdo, 'SR', $returnId, $returnCountryId);
    $pdo->prepare('UPDATE sales_returns SET return_number = ? WHERE id = ?')->execute([$retNum, $returnId]);

    if (orange_table_has_column($pdo, 'sales_returns', 'country_id') && $returnCountryId > 0) {
        $pdo->prepare('UPDATE sales_returns SET country_id = ? WHERE id = ?')->execute([$returnCountryId, $returnId]);
    }
    orange_sales_return_sync_analytics_for_return($pdo, $returnId, $orderIdOpt, $returnCountryId);

    $hasVariant = orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
    foreach ($normalizedItems as $line) {
        orange_sales_return_add_line_stock($pdo, $line['product_id'], $line['variant_id'], $line['qty']);
        if ($hasVariant) {
            $pdo->prepare(
                'INSERT INTO sales_return_items (sales_return_id, product_id, variant_id, qty, price, line_discount)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $returnId,
                $line['product_id'],
                $line['variant_id'],
                $line['qty'],
                $line['price'],
                $line['line_discount'],
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO sales_return_items (sales_return_id, product_id, qty, price, line_discount)
                 VALUES (?,?,?,?,?)'
            )->execute([
                $returnId,
                $line['product_id'],
                $line['qty'],
                $line['price'],
                $line['line_discount'],
            ]);
        }
    }

    if (orange_invoice_ancillary_tables_ready($pdo)) {
        $extraInput = orange_invoice_ancillary_merge_auto_vat(
            $pdo,
            orange_invoice_ancillary_doc_kind_sales_return(),
            $returnCountryId,
            (float) $revenueTotal,
            $extraInput
        );
        orange_invoice_ancillary_extra_lines_replace_for_doc(
            $pdo,
            orange_invoice_ancillary_doc_kind_sales_return(),
            $returnId,
            $returnCountryId,
            $extraInput
        );
    }
    $extraRows = orange_invoice_ancillary_extra_lines_journal_rows($extraInput);
    /* أثر البنود على المبلغ المردود للعميل: عكس اتجاه البيع (دائن−مدين). */
    $extraTotals = orange_invoice_ancillary_extra_lines_totals($extraInput);
    $extraDelta = round((float) $extraTotals['credit'] - (float) $extraTotals['debit'], 4);
    $customerRefundTotal = round($revenueTotal + $extraDelta, 4);

    $now = date('Y-m-d H:i:s');
    $pendingRev = orange_gl_pending_source_key('sales_return', $returnId, 'sale');
    $pendingCogs = orange_gl_pending_source_key('sales_return', $returnId, 'cogs');

    if ($revenueTotal > 0.0001) {
        $glRev = orange_gl_sales_return_revenue_bundle($pdo, $channel, $customerId, $returnId, $revenueTotal);
        $afterJson = $glRev['after_post'] !== null
            ? json_encode($glRev['after_post'], JSON_UNESCAPED_UNICODE)
            : null;
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $pendingRev,
                'source_label' => $retNum,
                'movement_at' => $now,
                'voucher_date' => $now,
                'account_debit' => $glRev['debit'],
                'account_credit' => $glRev['credit'],
                'amount' => $revenueTotal,
                'description' => $glRev['voucher_description'],
                'entry_type' => 'order_return_sale',
                'after_post_json' => $afterJson,
            ]);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $glRev['debit'],
                'account_credit' => $glRev['credit'],
                'amount' => $revenueTotal,
                'description' => $glRev['voucher_description'],
                'entry_type' => 'order_return_sale',
            ]);
            if ($glRev['legacy_ar_subledger']) {
                orange_sales_return_record_ar_subledger($pdo, $returnId, $customerId, $channel, $customerRefundTotal);
            }
        }

        /* بنود إضافية (VAT/شحن/خصم) — أسطر GL عكسية مقابل حساب النقد/الذمة نفسه. */
        foreach ($extraRows as $jr) {
            $accId = (int) ($jr['account_id'] ?? 0);
            $memo = trim((string) ($jr['memo'] ?? 'بند مردود'));
            if ($accId <= 0) {
                continue;
            }
            if ((float) ($jr['credit'] ?? 0) > 0.0001) {
                $exDebit = $accId;
                $exCredit = (int) $glRev['credit'];
                $exAmount = round((float) $jr['credit'], 4);
            } elseif ((float) ($jr['debit'] ?? 0) > 0.0001) {
                $exDebit = (int) $glRev['credit'];
                $exCredit = $accId;
                $exAmount = round((float) $jr['debit'], 4);
            } else {
                continue;
            }
            if ($exDebit === $exCredit || $exAmount <= 0.0001) {
                continue;
            }
            if (orange_gl_use_pending_queue($pdo)) {
                orange_gl_pending_enqueue_simple($pdo, [
                    'reference' => $pendingRev . '-EX' . $accId,
                    'source_label' => $retNum,
                    'movement_at' => $now,
                    'voucher_date' => $now,
                    'account_debit' => $exDebit,
                    'account_credit' => $exCredit,
                    'amount' => $exAmount,
                    'description' => 'مردود — ' . $memo,
                    'entry_type' => 'order_return_sale',
                ]);
            } else {
                orange_journal_insert_line($pdo, [
                    'date' => $now,
                    'account_debit' => $exDebit,
                    'account_credit' => $exCredit,
                    'amount' => $exAmount,
                    'description' => 'مردود — ' . $memo,
                    'entry_type' => 'order_return_sale',
                ]);
            }
        }
    }

    if ($cogsTotal > 0.0001) {
        $glCogs = orange_gl_sales_return_cogs_accounts($pdo, $channel);
        $cogsDesc = 'مردود تكلفة مبيعات — مستند مردود';
        if (orange_gl_use_pending_queue($pdo)) {
            orange_gl_pending_enqueue_simple($pdo, [
                'reference' => $pendingCogs,
                'source_label' => $retNum,
                'movement_at' => $now,
                'voucher_date' => $now,
                'account_debit' => $glCogs['debit'],
                'account_credit' => $glCogs['credit'],
                'amount' => $cogsTotal,
                'description' => $cogsDesc,
                'entry_type' => 'order_return_cogs',
            ]);
        } else {
            orange_journal_insert_line($pdo, [
                'date' => $now,
                'account_debit' => $glCogs['debit'],
                'account_credit' => $glCogs['credit'],
                'amount' => $cogsTotal,
                'description' => $cogsDesc,
                'entry_type' => 'order_return_cogs',
            ]);
        }
    }

    $pdo->commit();
    orange_edit_lock_register_sales_return(
        $pdo,
        $returnId,
        $returnCountryId > 0 ? $returnCountryId : null,
        $revenueTotal,
        $retNum,
        $now
    );
    audit_log('sales_return_create', 'تم إنشاء مردود مبيعات رقم: ' . $returnId, 'sales_returns', $returnId);
    $revJtCode = $channel === 'credit' ? 'SRR' : ($channel === 'online' ? 'OSR' : 'SCR');
    $cogsJtCode = $channel === 'credit' ? 'CGR' : ($channel === 'online' ? 'COR' : 'CSR');
    $voucherLinks = orange_gl_posting_voucher_links($pdo, 'sales_return', $returnId, [
        ['entry_type' => 'order_return_sale', 'journal_type_code' => $revJtCode, 'label' => 'قيد مردود المبيعات', 'suffix' => 'sale'],
        ['entry_type' => 'order_return_cogs', 'journal_type_code' => $cogsJtCode, 'label' => 'قيد تكلفة المردود', 'suffix' => 'cogs'],
    ], $returnCountryId);
    json_response([
        'success' => true,
        'message' => 'تم حفظ مردود المبيعات',
        'sales_return_id' => $returnId,
        'voucher_links' => $voucherLinks,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر حفظ مردود المبيعات');
}
