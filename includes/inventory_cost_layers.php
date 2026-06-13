<?php

declare(strict_types=1);

/**
 * طبقات تكلفة المخزون (FIFO — الوارد أولاً صادر أولاً).
 *
 * المرحلة م1: أساس الدوال (إنشاء/استهلاك/قراءة) — **غير مربوطة بعد** بمسارات COGS/المبيعات.
 * الربط الفعلي يبدأ في م2 (الشراء) و م3 (البيع/التسليم والمردود).
 *
 * مرجع القرار + الخطة: docs/archive/ORANGE_ACCOUNTING_MAPPING_AND_REPORT_HANDOFF.txt
 *   (قرار + خطة تنفيذية 2026-06-13: تقييم المخزون بـ FIFO).
 *
 * مبادئ:
 * - تكلفة الطبقة = صافي تكلفة الوحدة بعد خصم **السطر** فقط (خصم الفاتورة «خصم مكتسب» منفصل لا يدخل هنا).
 * - الاستهلاك من الأقدم (layer_date ثم id) مع قفل صفوف (FOR UPDATE) لمنع سباق الكميات.
 * - بطاقة الصنف (products.cost) إرشادية فقط ولا تُستعمل في التقييم.
 */

if (!function_exists('orange_inventory_cost_layers_table_exists')) {
    function orange_inventory_cost_layers_table_exists(PDO $pdo): bool
    {
        if (!function_exists('orange_table_exists')) {
            return false;
        }

        return orange_table_exists($pdo, 'inventory_cost_layers');
    }
}

if (!function_exists('orange_inventory_cost_layers_consumptions_table_exists')) {
    function orange_inventory_cost_layers_consumptions_table_exists(PDO $pdo): bool
    {
        if (!function_exists('orange_table_exists')) {
            return false;
        }

        return orange_table_exists($pdo, 'inventory_cost_consumptions');
    }
}

if (!function_exists('orange_inventory_cost_layer_add')) {
    /**
     * إضافة طبقة تكلفة واردة (شراء/افتتاحي/إرجاع بيع/تسوية).
     *
     * @return int معرّف الطبقة المُنشأة (0 إذا تعذّر)
     */
    function orange_inventory_cost_layer_add(
        PDO $pdo,
        int $warehouseId,
        int $variantId,
        int $qty,
        float $unitCost,
        string $sourceType,
        ?int $sourceId = null,
        ?int $countryId = null,
        ?string $layerDate = null,
        string $note = ''
    ): int {
        if (!orange_inventory_cost_layers_table_exists($pdo)) {
            return 0;
        }
        if ($warehouseId <= 0 || $variantId <= 0 || $qty <= 0) {
            return 0;
        }
        $unitCost = round(max(0.0, $unitCost), 5);
        $now = date('Y-m-d H:i:s');
        $layerDate = ($layerDate !== null && $layerDate !== '') ? $layerDate : $now;

        $st = $pdo->prepare(
            'INSERT INTO inventory_cost_layers
                (country_id, warehouse_id, variant_id, source_type, source_id, layer_date,
                 qty_in, qty_remaining, unit_cost, note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            ($countryId !== null && $countryId > 0) ? $countryId : null,
            $warehouseId,
            $variantId,
            $sourceType,
            ($sourceId !== null && $sourceId > 0) ? $sourceId : null,
            $layerDate,
            $qty,
            $qty,
            $unitCost,
            mb_substr($note, 0, 191),
            $now,
        ]);

        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists('orange_inventory_cost_layers_consume_fifo')) {
    /**
     * استهلاك الكمية من الطبقات الأقدم (FIFO) وحساب تكلفتها.
     *
     * يجب استدعاؤها داخل معاملة (transaction) لضمان اتساق القفل.
     *
     * @return array{cost: float, consumed: list<array{layer_id:int, qty:int, unit_cost:float}>, shortfall: int}
     *   cost = إجمالي تكلفة الكمية المستهلكة من الطبقات؛ shortfall = الكمية التي لم تجد طبقة (نقص طبقات).
     */
    function orange_inventory_cost_layers_consume_fifo(
        PDO $pdo,
        int $warehouseId,
        int $variantId,
        int $qty,
        string $saleSourceType,
        ?int $saleSourceId = null,
        ?string $consumedAt = null
    ): array {
        $result = ['cost' => 0.0, 'consumed' => [], 'shortfall' => 0];
        if (!orange_inventory_cost_layers_table_exists($pdo)) {
            $result['shortfall'] = max(0, $qty);

            return $result;
        }
        if ($warehouseId <= 0 || $variantId <= 0 || $qty <= 0) {
            return $result;
        }

        $now = date('Y-m-d H:i:s');
        $consumedAt = ($consumedAt !== null && $consumedAt !== '') ? $consumedAt : $now;
        $hasConsTable = orange_inventory_cost_layers_consumptions_table_exists($pdo);

        $remaining = $qty;
        $sel = $pdo->prepare(
            'SELECT id, qty_remaining, unit_cost
             FROM inventory_cost_layers
             WHERE warehouse_id = ? AND variant_id = ? AND qty_remaining > 0
             ORDER BY layer_date ASC, id ASC
             FOR UPDATE'
        );
        $sel->execute([$warehouseId, $variantId]);
        $layers = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $upd = $pdo->prepare(
            'UPDATE inventory_cost_layers SET qty_remaining = qty_remaining - ? WHERE id = ?'
        );
        $insCons = $hasConsTable ? $pdo->prepare(
            'INSERT INTO inventory_cost_consumptions
                (layer_id, warehouse_id, variant_id, consumed_qty, unit_cost,
                 sale_source_type, sale_source_id, consumed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        ) : null;

        $totalCost = 0.0;
        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $layerId = (int) ($layer['id'] ?? 0);
            $avail = (int) ($layer['qty_remaining'] ?? 0);
            $unitCost = round((float) ($layer['unit_cost'] ?? 0), 5);
            if ($layerId <= 0 || $avail <= 0) {
                continue;
            }
            $take = min($avail, $remaining);
            $upd->execute([$take, $layerId]);
            $totalCost += $take * $unitCost;
            $remaining -= $take;
            $result['consumed'][] = ['layer_id' => $layerId, 'qty' => $take, 'unit_cost' => $unitCost];
            if ($insCons !== null) {
                $insCons->execute([
                    $layerId,
                    $warehouseId,
                    $variantId,
                    $take,
                    $unitCost,
                    $saleSourceType,
                    ($saleSourceId !== null && $saleSourceId > 0) ? $saleSourceId : null,
                    $consumedAt,
                ]);
            }
        }

        $result['cost'] = round($totalCost, 5);
        $result['shortfall'] = max(0, $remaining);

        return $result;
    }
}

if (!function_exists('orange_inventory_cost_layers_reduce_for_source')) {
    /**
     * تخفيض الكمية المتبقية من طبقات مصدر معيّن (مثل مردود مشتريات يخفّض طبقة الشراء الأصلية).
     * يخفّض من أحدث طبقات نفس المصدر أولاً (عكس FIFO على نفس فاتورة الشراء).
     *
     * @return int الكمية التي خُفّضت فعلياً
     */
    function orange_inventory_cost_layers_reduce_for_source(
        PDO $pdo,
        string $sourceType,
        int $sourceId,
        int $variantId,
        int $warehouseId,
        int $qty
    ): int {
        if (!orange_inventory_cost_layers_table_exists($pdo)) {
            return 0;
        }
        if ($sourceId <= 0 || $variantId <= 0 || $warehouseId <= 0 || $qty <= 0) {
            return 0;
        }

        $sel = $pdo->prepare(
            'SELECT id, qty_remaining
             FROM inventory_cost_layers
             WHERE source_type = ? AND source_id = ? AND variant_id = ? AND warehouse_id = ? AND qty_remaining > 0
             ORDER BY layer_date DESC, id DESC
             FOR UPDATE'
        );
        $sel->execute([$sourceType, $sourceId, $variantId, $warehouseId]);
        $layers = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $upd = $pdo->prepare(
            'UPDATE inventory_cost_layers SET qty_remaining = qty_remaining - ? WHERE id = ?'
        );

        $remaining = $qty;
        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $layerId = (int) ($layer['id'] ?? 0);
            $avail = (int) ($layer['qty_remaining'] ?? 0);
            if ($layerId <= 0 || $avail <= 0) {
                continue;
            }
            $take = min($avail, $remaining);
            $upd->execute([$take, $layerId]);
            $remaining -= $take;
        }

        return $qty - $remaining;
    }
}

if (!function_exists('orange_inventory_cost_layers_restore_for_source')) {
    /**
     * استرجاع كمية إلى طبقات مصدر معيّن (عكس reduce_for_source) — يُستعمل عند تعديل/حذف مردود
     * مشتريات لإعادة الكمية إلى طبقات فاتورة الشراء الأصلية. لا يتجاوز qty_in الأصلي لكل طبقة.
     *
     * @return int الكمية التي استُرجعت فعلياً
     */
    function orange_inventory_cost_layers_restore_for_source(
        PDO $pdo,
        string $sourceType,
        int $sourceId,
        int $variantId,
        int $warehouseId,
        int $qty
    ): int {
        if (!orange_inventory_cost_layers_table_exists($pdo)) {
            return 0;
        }
        if ($sourceId <= 0 || $variantId <= 0 || $warehouseId <= 0 || $qty <= 0) {
            return 0;
        }

        $sel = $pdo->prepare(
            'SELECT id, qty_in, qty_remaining
             FROM inventory_cost_layers
             WHERE source_type = ? AND source_id = ? AND variant_id = ? AND warehouse_id = ?
             ORDER BY layer_date ASC, id ASC
             FOR UPDATE'
        );
        $sel->execute([$sourceType, $sourceId, $variantId, $warehouseId]);
        $layers = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $upd = $pdo->prepare(
            'UPDATE inventory_cost_layers SET qty_remaining = qty_remaining + ? WHERE id = ?'
        );

        $remaining = $qty;
        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $layerId = (int) ($layer['id'] ?? 0);
            $qtyIn = (int) ($layer['qty_in'] ?? 0);
            $qtyRem = (int) ($layer['qty_remaining'] ?? 0);
            $room = $qtyIn - $qtyRem;
            if ($layerId <= 0 || $room <= 0) {
                continue;
            }
            $give = min($room, $remaining);
            $upd->execute([$give, $layerId]);
            $remaining -= $give;
        }

        return $qty - $remaining;
    }
}

if (!function_exists('orange_inventory_cost_layers_reduce_newest')) {
    /**
     * تخفيض الكمية المتبقية من **أحدث** الطبقات (عكس FIFO) بغضّ النظر عن المصدر —
     * احتياطي لمردود المشتريات عند تعذّر مطابقة طبقة المصدر الأصلية.
     *
     * @return int الكمية التي خُفّضت فعلياً
     */
    function orange_inventory_cost_layers_reduce_newest(
        PDO $pdo,
        int $warehouseId,
        int $variantId,
        int $qty
    ): int {
        if (!orange_inventory_cost_layers_table_exists($pdo)) {
            return 0;
        }
        if ($warehouseId <= 0 || $variantId <= 0 || $qty <= 0) {
            return 0;
        }

        $sel = $pdo->prepare(
            'SELECT id, qty_remaining
             FROM inventory_cost_layers
             WHERE warehouse_id = ? AND variant_id = ? AND qty_remaining > 0
             ORDER BY layer_date DESC, id DESC
             FOR UPDATE'
        );
        $sel->execute([$warehouseId, $variantId]);
        $layers = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $upd = $pdo->prepare(
            'UPDATE inventory_cost_layers SET qty_remaining = qty_remaining - ? WHERE id = ?'
        );

        $remaining = $qty;
        foreach ($layers as $layer) {
            if ($remaining <= 0) {
                break;
            }
            $layerId = (int) ($layer['id'] ?? 0);
            $avail = (int) ($layer['qty_remaining'] ?? 0);
            if ($layerId <= 0 || $avail <= 0) {
                continue;
            }
            $take = min($avail, $remaining);
            $upd->execute([$take, $layerId]);
            $remaining -= $take;
        }

        return $qty - $remaining;
    }
}

if (!function_exists('orange_inventory_cost_layers_delete_for_source')) {
    /**
     * حذف كل طبقات مصدر معيّن (يُستعمل عند تعديل/حذف فاتورة الشراء قبل إعادة بنائها).
     *
     * ملاحظة: آمن في المرحلة م2 لأن الطبقات لا تُستهلَك بعد (الاستهلاك يبدأ في م3). بعد م3 يجب
     * استدعاؤه فقط حين لا يوجد استهلاك على طبقات المصدر، وإلا تُعالَج سلامة التعديل بمنطق أدق.
     *
     * @return int عدد الصفوف المحذوفة
     */
    function orange_inventory_cost_layers_delete_for_source(
        PDO $pdo,
        string $sourceType,
        int $sourceId
    ): int {
        if (!orange_inventory_cost_layers_table_exists($pdo)) {
            return 0;
        }
        if ($sourceId <= 0 || $sourceType === '') {
            return 0;
        }
        $st = $pdo->prepare(
            'DELETE FROM inventory_cost_layers WHERE source_type = ? AND source_id = ?'
        );
        $st->execute([$sourceType, $sourceId]);

        return (int) $st->rowCount();
    }
}

if (!function_exists('orange_inventory_cost_layers_consumption_cost')) {
    /**
     * قراءة (دون تعديل) إجمالي تكلفة وكمية الاستهلاك المسجّل لمصدر بيع معيّن (مثل سطر طلب).
     * تُستعمل في ترحيل قيد تكلفة المبيعات لقراءة تكلفة الطبقات المستهلَكة عند التسليم.
     *
     * @return array{cost: float, qty: int}
     */
    function orange_inventory_cost_layers_consumption_cost(
        PDO $pdo,
        string $saleSourceType,
        int $saleSourceId
    ): array {
        $res = ['cost' => 0.0, 'qty' => 0];
        if (!orange_inventory_cost_layers_consumptions_table_exists($pdo) || $saleSourceId <= 0) {
            return $res;
        }
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(consumed_qty), 0) AS q, COALESCE(SUM(consumed_qty * unit_cost), 0) AS c
             FROM inventory_cost_consumptions
             WHERE sale_source_type = ? AND sale_source_id = ?'
        );
        $st->execute([$saleSourceType, $saleSourceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $res['qty'] = (int) ($row['q'] ?? 0);
        $res['cost'] = round((float) ($row['c'] ?? 0), 5);

        return $res;
    }
}

if (!function_exists('orange_inventory_cost_layers_restore_consumption')) {
    /**
     * عكس استهلاك مصدر بيع (مثل إلغاء تسليم/مردود): يُعيد الكميات إلى طبقاتها الأصلية (بحدّ qty_in)،
     * ويحسب التكلفة المُعادة، ثم يحذف سجلات الاستهلاك. يجب استدعاؤه داخل معاملة.
     *
     * @return array{cost: float, qty: int}
     */
    function orange_inventory_cost_layers_restore_consumption(
        PDO $pdo,
        string $saleSourceType,
        int $saleSourceId
    ): array {
        $res = ['cost' => 0.0, 'qty' => 0];
        if (
            !orange_inventory_cost_layers_consumptions_table_exists($pdo)
            || !orange_inventory_cost_layers_table_exists($pdo)
            || $saleSourceId <= 0
        ) {
            return $res;
        }
        $sel = $pdo->prepare(
            'SELECT id, layer_id, consumed_qty, unit_cost
             FROM inventory_cost_consumptions
             WHERE sale_source_type = ? AND sale_source_id = ?
             FOR UPDATE'
        );
        $sel->execute([$saleSourceType, $saleSourceId]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $updLayer = $pdo->prepare(
            'UPDATE inventory_cost_layers SET qty_remaining = LEAST(qty_in, qty_remaining + ?) WHERE id = ?'
        );
        $delCons = $pdo->prepare('DELETE FROM inventory_cost_consumptions WHERE id = ?');

        $totalCost = 0.0;
        $totalQty = 0;
        foreach ($rows as $r) {
            $consId = (int) ($r['id'] ?? 0);
            $layerId = (int) ($r['layer_id'] ?? 0);
            $cq = (int) ($r['consumed_qty'] ?? 0);
            $uc = round((float) ($r['unit_cost'] ?? 0), 5);
            if ($cq > 0) {
                if ($layerId > 0) {
                    $updLayer->execute([$cq, $layerId]);
                }
                $totalCost += $cq * $uc;
                $totalQty += $cq;
            }
            if ($consId > 0) {
                $delCons->execute([$consId]);
            }
        }

        $res['cost'] = round($totalCost, 5);
        $res['qty'] = $totalQty;

        return $res;
    }
}

if (!function_exists('orange_inventory_cost_layers_value')) {
    /**
     * قيمة المخزون من الطبقات المتبقية = Σ(qty_remaining × unit_cost).
     * يمكن تقييدها بمخزن و/أو variant.
     */
    function orange_inventory_cost_layers_value(
        PDO $pdo,
        ?int $warehouseId = null,
        ?int $variantId = null
    ): float {
        if (!orange_inventory_cost_layers_table_exists($pdo)) {
            return 0.0;
        }

        $sql = 'SELECT COALESCE(SUM(qty_remaining * unit_cost), 0) FROM inventory_cost_layers WHERE qty_remaining > 0';
        $args = [];
        if ($warehouseId !== null && $warehouseId > 0) {
            $sql .= ' AND warehouse_id = ?';
            $args[] = $warehouseId;
        }
        if ($variantId !== null && $variantId > 0) {
            $sql .= ' AND variant_id = ?';
            $args[] = $variantId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);

        return round((float) ($st->fetchColumn() ?: 0), 5);
    }
}

if (!function_exists('orange_inventory_cost_layers_current_unit_cost')) {
    /**
     * تكلفة الوحدة الحالية من الطبقات المتبقية لـ(مخزن، variant) = متوسط مرجّح للطبقات المتبقية
     * (قيمة ÷ كمية). تُستعمل لتقدير تكلفة بنود الزيادة في الجرد. تُعيد 0.0 عند غياب طبقات.
     */
    function orange_inventory_cost_layers_current_unit_cost(
        PDO $pdo,
        int $warehouseId,
        int $variantId
    ): float {
        if (!orange_inventory_cost_layers_table_exists($pdo) || $warehouseId <= 0 || $variantId <= 0) {
            return 0.0;
        }
        $st = $pdo->prepare(
            'SELECT COALESCE(SUM(qty_remaining), 0) AS q, COALESCE(SUM(qty_remaining * unit_cost), 0) AS v
             FROM inventory_cost_layers
             WHERE qty_remaining > 0 AND warehouse_id = ? AND variant_id = ?'
        );
        $st->execute([$warehouseId, $variantId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $q = (int) ($row['q'] ?? 0);
        $v = (float) ($row['v'] ?? 0);

        return $q > 0 ? round($v / $q, 5) : 0.0;
    }
}

if (!function_exists('orange_inventory_cost_layers_gl_balance_check')) {
    /**
     * اختبار اتزان: قيمة طبقات المخزون مقابل رصيد حساب المخزون GL لدولة معيّنة.
     *
     * @return array{layers_value: float, gl_balance: float, diff: float, inventory_account_id: int}
     */
    function orange_inventory_cost_layers_gl_balance_check(PDO $pdo, ?int $countryId = null): array
    {
        require_once __DIR__ . '/gl_settings.php';
        require_once __DIR__ . '/warehouses.php';
        require_once __DIR__ . '/countries.php';
        require_once __DIR__ . '/journal_voucher.php';

        if ($countryId === null || $countryId <= 0) {
            $countryId = orange_countries_default_id($pdo);
        }

        $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
        $layersValue = orange_inventory_cost_layers_value($pdo, $warehouseId > 0 ? $warehouseId : null);

        $inventoryAccountId = (int) (orange_gl_account_id_optional($pdo, 'inventory', $countryId) ?? 0);
        $glBalance = 0.0;
        if ($inventoryAccountId > 0 && orange_journal_vouchers_ready($pdo)) {
            $countryBind = orange_gl_voucher_country_bind($pdo, 'jv', $countryId);
            $sql = 'SELECT COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0)
                    FROM journal_lines jl
                    INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
                    WHERE jl.account_id = ?' . $countryBind['sql'];
            $params = array_merge([$inventoryAccountId], $countryBind['params']);
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $glBalance = round((float) ($st->fetchColumn() ?: 0), 5);
        }

        return [
            'layers_value' => round($layersValue, 5),
            'gl_balance' => $glBalance,
            'diff' => round($layersValue - $glBalance, 5),
            'inventory_account_id' => $inventoryAccountId,
        ];
    }
}
