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
require_once __DIR__ . '/../../../includes/edit_lock.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_once __DIR__ . '/../../../includes/inventory_cost_layers.php';
require_once __DIR__ . '/../../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../../includes/loyalty.php';
require_once __DIR__ . '/../../../includes/gl_voucher_slot.php';
require_once __DIR__ . '/../../../includes/fiscal_years.php';
require_admin_api();

function reverse_sales_return_stock(PDO $pdo, int $returnId): void
{
    $hasV = orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
    $cols = 'product_id, qty';
    if ($hasV) {
        $cols .= ', variant_id';
    }
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM sales_return_items WHERE sales_return_id = ?');
    $st->execute([$returnId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $pid = (int) ($row['product_id'] ?? 0);
        $qty = (int) ($row['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) {
            continue;
        }
        $vid = $hasV ? (int) ($row['variant_id'] ?? 0) : 0;
        if ($vid <= 0) {
            $vid = orange_purchase_resolve_variant_id($pdo, $pid, 0);
        }
        orange_sales_return_undo_line_stock($pdo, $pid, $vid, $qty);
    }
}

/**
 * @return array{revenue: float, cogs: float}
 */
function apply_sales_return_items(
    PDO $pdo,
    int $returnId,
    array $items,
    int $warehouseId = 0,
    ?int $returnCountryId = null,
    string $postingAt = ''
): array {
    $hasV = orange_table_has_column($pdo, 'sales_return_items', 'variant_id');
    $revenueTotal = 0.0;
    $cogsTotal = 0.0;
    foreach ($items as $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            throw new RuntimeException('عنصر مردود غير صحيح');
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
            throw new RuntimeException('صافي سطر المردود غير صالح');
        }
        $unitCost = orange_sales_return_resolve_unit_cost($pdo, $productId, (float) $line['cost'], $variantId);
        $lineCost = round($unitCost * $qty, 4);
        $revenueTotal += $net;
        $cogsTotal += $lineCost;
        orange_sales_return_add_line_stock($pdo, $productId, $variantId, $qty);
        // FIFO م3: إعادة طبقة تكلفة بنفس تكلفة الوحدة المُرحَّلة (تساوي مدين المخزون).
        if ($warehouseId > 0) {
            orange_inventory_cost_layer_add(
                $pdo,
                $warehouseId,
                $variantId,
                $qty,
                round($unitCost, 5),
                'sale_return',
                $returnId,
                $returnCountryId,
                $postingAt !== '' ? $postingAt : date('Y-m-d H:i:s'),
                'SR-' . $returnId
            );
        }
        if ($hasV) {
            $pdo->prepare(
                'INSERT INTO sales_return_items (sales_return_id, product_id, variant_id, qty, price, line_discount)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$returnId, $productId, $variantId, $qty, $line['price'], $line['line_discount']]);
        } else {
            $pdo->prepare(
                'INSERT INTO sales_return_items (sales_return_id, product_id, qty, price, line_discount)
                 VALUES (?,?,?,?,?)'
            )->execute([$returnId, $productId, $qty, $line['price'], $line['line_discount']]);
        }
    }

    return ['revenue' => round($revenueTotal, 4), 'cogs' => round($cogsTotal, 4)];
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $returnId = (int) ($data['id'] ?? 0);
    $action = trim((string) ($data['action'] ?? 'update'));
    if ($returnId <= 0) {
        json_response(['success' => false, 'message' => 'معرف مردود المبيعات مطلوب'], 422);
    }

    $st = $pdo->prepare('SELECT * FROM sales_returns WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'غير موجود'], 404);
    }
    $admin = current_admin();
    if (!$admin) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $returnCountryLock = orange_edit_lock_country_for_sales_return($pdo, $row);
    try {
        orange_edit_lock_assert_may_mutate(
            $pdo,
            $admin,
            'sales_return',
            $returnId,
            $action === 'delete' ? 'delete' : 'edit',
            $returnCountryLock
        );
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 422);
    }
    try {
        $srCustomerId = (int) ($row['customer_id'] ?? 0);
        $srOrderId = (int) ($row['order_id'] ?? 0);
        if ($srCustomerId > 0) {
            orange_admin_assert_entity_country($pdo, 'customers', $srCustomerId);
        } elseif ($srOrderId > 0) {
            orange_admin_assert_entity_country($pdo, 'orders', $srOrderId);
        }
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    // فحص إغلاق كل القيود التابعة للمستند (مردود البيع + تكلفة المردود + استرداد نقاط الولاء):
    // قيود التعديل أوتوماتيكية، تُستدعى وتُعاد بناؤها بالخلفية — لكن إن كان أيٌّ منها مرتبطاً بسنة
    // مالية مغلقة يُمنع التعديل/الحذف مع تسمية القيد ورقمه المطلوب فكّه أولاً.
    $lockedVoucherLabel = orange_gl_first_locked_document_voucher_label(
        $pdo,
        'sales_return',
        $returnId,
        [
            ['entry_type' => 'order_return_sale', 'suffix' => 'sale', 'label' => 'قيد مردود المبيعات'],
            ['entry_type' => 'order_return_cogs', 'suffix' => 'cogs', 'label' => 'قيد تكلفة المردود'],
            ['entry_type' => 'loyalty_return_clawback', 'suffix' => 'loyalty-return-clawback', 'label' => 'قيد استرداد نقاط الولاء'],
        ],
        null,
        'SR-' . $returnId . '-RS'
    );
    if ($lockedVoucherLabel !== null) {
        json_response([
            'success' => false,
            'message' => 'لا يمكن التعديل قبل فكّ القيد المغلق: ' . $lockedVoucherLabel,
            'suggest_admin' => orange_gl_suggest_admin_fiscal_years_screen(),
        ], 422);
    }

    $preUpdateOrderIdOpt = 0;
    $preUpdateItems = [];
    if ($action !== 'delete') {
        $preUpdateOrderIdOpt = (int) ($data['order_id'] ?? (int) ($row['order_id'] ?? 0));
        $preUpdateItems = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
    }

    $pdo->beginTransaction();

    if ($action !== 'delete' && $preUpdateOrderIdOpt > 0 && $preUpdateItems !== []) {
        try {
            orange_sales_return_lock_reference_order($pdo, $preUpdateOrderIdOpt);
            orange_sales_return_assert_qty_against_order($pdo, $preUpdateOrderIdOpt, $preUpdateItems, $returnId);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    reverse_sales_return_stock($pdo, $returnId);
    // FIFO م3: حذف طبقات هذا المردود (تُعاد بناءً على البنود الجديدة، أو تبقى محذوفة عند الحذف).
    orange_inventory_cost_layers_delete_for_source($pdo, 'sale_return', $returnId);
    $pdo->prepare('DELETE FROM sales_return_items WHERE sales_return_id = ?')->execute([$returnId]);

    // عكس استرداد نقاط الولاء السابق (إن وُجد): إعادة المتاح لطبقة الكسب + حذف قيد الاسترداد وعلامته.
    // يُعاد احتسابه على القيمة الجديدة في مسار التعديل؛ وفي الحذف يبقى ملغى (لا مردود → لا استرداد).
    orange_loyalty_reverse_clawback_for_return($pdo, $returnId);

    if ($action === 'delete') {
        orange_gl_voucher_slot_delete_document_accounting($pdo, 'sales_return', $returnId);
        orange_sales_return_remove_accounting(
            $pdo,
            $returnId,
            $returnCountryLock > 0 ? $returnCountryLock : null
        );
        $pdo->prepare('DELETE FROM sales_returns WHERE id = ?')->execute([$returnId]);
        orange_edit_lock_unregister($pdo, 'sales_return', $returnId, $returnCountryLock);
        orange_edit_lock_log_mutation($pdo, 'sales_return', $returnId, 'delete');
        $pdo->commit();
        audit_log('sales_return_delete', 'تم حذف مردود مبيعات رقم: ' . $returnId, 'sales_returns', $returnId);
        json_response(['success' => true, 'message' => 'تم حذف مردود المبيعات']);
    }

    $channel = trim((string) ($data['channel'] ?? $data['payment_channel'] ?? (string) ($row['type'] ?? 'credit')));
    if ($channel === '') {
        $channel = 'credit';
    }
    $customerId = (int) ($data['customer_id'] ?? (int) ($row['customer_id'] ?? 0));
    $orderIdOpt = (int) ($data['order_id'] ?? (int) ($row['order_id'] ?? 0));
    $notes = trim((string) ($data['notes'] ?? (string) ($row['notes'] ?? '')));
    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

    if (!in_array($channel, ['cash', 'online', 'credit'], true) || $items === []) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'بيانات التعديل غير صحيحة'], 422);
    }
    if ($channel === 'credit' && $customerId <= 0) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'مردود آجل يتطلّب عميلاً'], 422);
    }
    if ($channel === 'credit' && orange_table_exists($pdo, 'customers')) {
        $cchk = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $cchk->execute([$customerId]);
        if (!$cchk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'العميل غير موجود'], 422);
        }
    }
    if ($orderIdOpt > 0) {
        $chk = $pdo->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
        $chk->execute([$orderIdOpt]);
        if (!$chk->fetch()) {
            $pdo->rollBack();
            json_response(['success' => false, 'message' => 'الطلب المرجعي غير موجود'], 422);
        }
    }

    try {
        if ($customerId > 0) {
            orange_admin_assert_entity_country($pdo, 'customers', $customerId);
        }
        if ($orderIdOpt > 0) {
            orange_admin_assert_entity_country($pdo, 'orders', $orderIdOpt);
        }
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId > 0) {
                orange_admin_assert_entity_country($pdo, 'products', $productId);
            }
        }
    } catch (RuntimeException $e) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $updCountryForLayers = $returnCountryLock > 0 ? $returnCountryLock : orange_admin_context_country_id($pdo);
    $srWarehouseId = orange_warehouse_default_id_for_country($pdo, $updCountryForLayers);
    $srPostingAt = date('Y-m-d H:i:s');
    if (orange_table_has_column($pdo, 'sales_returns', 'document_date')) {
        $docDateRow = trim((string) ($row['document_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $docDateRow)) {
            $srPostingAt = $docDateRow . ' ' . date('H:i:s');
        }
    }
    $totals = apply_sales_return_items($pdo, $returnId, $items, $srWarehouseId, $updCountryForLayers, $srPostingAt);
    $revenueTotal = $totals['revenue'];
    $cogsTotal = $totals['cogs'];

    $pdo->prepare(
        'UPDATE sales_returns SET order_id = ?, customer_id = ?, type = ?, total = ?, notes = ? WHERE id = ?'
    )->execute([
        $orderIdOpt > 0 ? $orderIdOpt : null,
        $customerId > 0 ? $customerId : null,
        $channel,
        $revenueTotal,
        $notes !== '' ? $notes : null,
        $returnId,
    ]);

    $updCountryId = $returnCountryLock > 0 ? $returnCountryLock : orange_admin_context_country_id($pdo);
    orange_sales_return_sync_analytics_for_return($pdo, $returnId, $orderIdOpt, $updCountryId);

    $retNum = 'SR-' . $returnId;
    $now = date('Y-m-d H:i:s');
    $pendingRev = orange_gl_pending_source_key('sales_return', $returnId, 'sale');
    $pendingCogs = orange_gl_pending_source_key('sales_return', $returnId, 'cogs');

    // البنود الإضافية للمردود (VAT/شحن/خصم + سطر استرداد نقاط الولاء النقدي): تُعاد بناؤها على القيمة
    // الجديدة كما في مسار الإنشاء، حتى لا يسقط أثرها المحاسبي ولا تبقى أسطر طباعة قديمة بعد التعديل.
    $extraInput = orange_invoice_ancillary_parse_request_lines(
        $data,
        orange_invoice_ancillary_doc_kind_sales_return()
    );

    // استرداد نقاط الولاء على القيمة الجديدة (السابق عُكِس قبل إعادة البناء): سحب المتاح + عكس التزامه،
    // واسترداد قيمة المصروف نقداً كسطر يقلّل المردود؛ المنتهي يُتجاهَل (لا ظلم).
    if ($orderIdOpt > 0 && orange_loyalty_is_active($pdo, $updCountryId)) {
        $claw = orange_loyalty_clawback_for_return($pdo, $orderIdOpt, $returnId, (float) $revenueTotal, $updCountryId);
        if ((float) $claw['spent_value'] > 0.0001 && (int) $claw['expense_account_id'] > 0) {
            $clawLineOk = false;
            try {
                orange_invoice_ancillary_assert_account_for_line(
                    $pdo,
                    (int) $claw['expense_account_id'],
                    $updCountryId,
                    'sales_debit_contra',
                    orange_invoice_ancillary_doc_kind_sales_return()
                );
                $clawLineOk = true;
            } catch (Throwable $e) {
                error_log('[orange] loyalty return clawback cash line skipped (update): ' . $e->getMessage());
            }
            if ($clawLineOk) {
                $extraInput[] = [
                    'account_id' => (int) $claw['expense_account_id'],
                    'line_kind' => 'sales_debit_contra',
                    'amount' => round((float) $claw['spent_value'], 4),
                    'label_ar' => 'استرداد قيمة نقاط ولاء مصروفة (' . (int) $claw['spent_points'] . ' نقطة)',
                    'show_on_print' => 1,
                    'preset_id' => 0,
                    'auto_loyalty_return' => 1,
                ];
            }
        }
    }

    if (orange_invoice_ancillary_tables_ready($pdo)) {
        $extraInput = orange_invoice_ancillary_merge_auto_vat(
            $pdo,
            orange_invoice_ancillary_doc_kind_sales_return(),
            $updCountryId,
            (float) $revenueTotal,
            $extraInput
        );
        orange_invoice_ancillary_extra_lines_replace_for_doc(
            $pdo,
            orange_invoice_ancillary_doc_kind_sales_return(),
            $returnId,
            $updCountryId,
            $extraInput
        );
    }
    $extraRows = orange_invoice_ancillary_extra_lines_journal_rows($extraInput);
    $extraTotals = orange_invoice_ancillary_extra_lines_totals($extraInput);
    $extraDelta = round((float) $extraTotals['credit'] - (float) $extraTotals['debit'], 4);
    $customerRefundTotal = round($revenueTotal + $extraDelta, 4);

    if (orange_gl_use_pending_queue($pdo)) {
        orange_sales_return_remove_accounting($pdo, $returnId, $updCountryId > 0 ? $updCountryId : null);
        if ($revenueTotal > 0.0001) {
            $glRev = orange_gl_sales_return_revenue_bundle($pdo, $channel, $customerId, $returnId, $revenueTotal);
            $afterJson = $glRev['after_post'] !== null
                ? json_encode($glRev['after_post'], JSON_UNESCAPED_UNICODE)
                : null;
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
            }
        }
        if ($cogsTotal > 0.0001) {
            $glCogs = orange_gl_sales_return_cogs_accounts($pdo, $channel);
            $cogsDesc = 'مردود تكلفة مبيعات — مستند مردود';
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
        }
    } else {
        $glRev = $revenueTotal > 0.0001
            ? orange_gl_sales_return_revenue_bundle($pdo, $channel, $customerId, $returnId, $revenueTotal)
            : [
                'is_multi' => false,
                'lines' => [],
                'debit' => 0,
                'credit' => 0,
                'voucher_description' => '',
                'after_post' => null,
                'legacy_ar_subledger' => false,
            ];
        orange_gl_sales_return_immediate_post_all_slots(
            $pdo,
            $returnId,
            $channel,
            $revenueTotal,
            $cogsTotal,
            $glRev,
            $extraRows,
            $customerRefundTotal,
            $now,
            $now,
            $updCountryId > 0 ? $updCountryId : null
        );
    }

    $pdo->commit();
    $retNumSaved = trim((string) ($row['return_number'] ?? ('SR-' . $returnId)));
    orange_edit_lock_register_sales_return(
        $pdo,
        $returnId,
        orange_edit_lock_country_for_sales_return($pdo, [
            'order_id' => $orderIdOpt > 0 ? $orderIdOpt : null,
            'customer_id' => $customerId > 0 ? $customerId : null,
        ]),
        $revenueTotal,
        $retNumSaved,
        $now
    );
    orange_edit_lock_log_mutation($pdo, 'sales_return', $returnId, 'edit');
    audit_log('sales_return_update', 'تم تعديل مردود مبيعات رقم: ' . $returnId, 'sales_returns', $returnId);
    json_response(['success' => true, 'message' => 'تم تحديث مردود المبيعات']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_gl_api_catch_json($e, 'تعذر تحديث مردود المبيعات');
}
