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
