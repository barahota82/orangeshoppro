<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/acc10_schema.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/gl_pending_movements.php';
require_once __DIR__ . '/admin_settings_country.php';
require_once __DIR__ . '/inventory_cost_layers.php';
require_once __DIR__ . '/stocktake_archive.php';
require_once __DIR__ . '/delivery_agents.php';

function orange_inventory_reconciliation_ready(PDO $pdo): bool
{
    orange_catalog_ensure_schema($pdo);
    orange_catalog_ensure_acc10_schema($pdo);

    return orange_table_exists($pdo, 'inventory_reconciliation')
        && orange_table_exists($pdo, 'inventory_reconciliation_line');
}

/**
 * @return list<array{id:int,label:string,name_ar:string,name_en:string,is_default:int}>
 */
function orange_inventory_reconciliation_warehouse_options(PDO $pdo, ?int $countryId = null): array
{
    if (! orange_table_exists($pdo, 'warehouses')) {
        return [];
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }
    $sql = 'SELECT id, name_ar, name_en, is_default FROM warehouses WHERE is_active = 1';
    $params = [];
    if ($countryId > 0) {
        $sql .= ' AND country_id = ?';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY is_default DESC, sort_order ASC, id ASC';
    $st = $params !== [] ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params !== []) {
        $st->execute($params);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $nameAr = trim((string) ($row['name_ar'] ?? ''));
        $nameEn = trim((string) ($row['name_en'] ?? ''));
        $label = $nameAr !== '' ? $nameAr : ($nameEn !== '' ? $nameEn : ('#' . $id));
        if ($nameEn !== '' && $nameAr !== '' && $nameEn !== $nameAr) {
            $label .= ' / ' . $nameEn;
        }
        $out[] = [
            'id' => $id,
            'label' => $label,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'is_default' => (int) ($row['is_default'] ?? 0),
        ];
    }

    return $out;
}

function orange_inventory_reconciliation_assert_warehouse_country(PDO $pdo, int $warehouseId, ?int $countryId): void
{
    $wh = orange_warehouse_row_by_id($pdo, $warehouseId);
    if ($wh === null) {
        throw new InvalidArgumentException('المستودع غير موجود.');
    }
    if ($countryId !== null && $countryId > 0 && (int) ($wh['country_id'] ?? 0) !== $countryId) {
        throw new InvalidArgumentException('المستودع لا يتبع دولة الأدمن الحالية.');
    }
}

function orange_inventory_reconciliation_variant_qty_system(PDO $pdo, int $warehouseId, int $variantId): int
{
    if ($warehouseId <= 0 || $variantId <= 0) {
        return 0;
    }
    $wh = orange_warehouse_row_by_id($pdo, $warehouseId);
    $countryId = $wh !== null ? (int) ($wh['country_id'] ?? 0) : 0;
    if (orange_warehouses_table_exists($pdo)) {
        $qty = orange_warehouse_variant_stock_quantity($pdo, $warehouseId, $variantId);
        if ($qty === 0 && orange_warehouse_legacy_stock_fallback_enabled($pdo, $countryId)) {
            $defaultWh = orange_warehouse_default_id_for_country($pdo, $countryId);
            if ($defaultWh === $warehouseId) {
                $st = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? LIMIT 1');
                $st->execute([$variantId]);

                return max(0, (int) ($st->fetchColumn() ?: 0));
            }
        }

        return max(0, $qty);
    }
    if (! orange_table_exists($pdo, 'product_variants')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? LIMIT 1');
    $st->execute([$variantId]);

    return max(0, (int) ($st->fetchColumn() ?: 0));
}

function orange_inventory_reconciliation_variant_unit_cost(PDO $pdo, int $variantId, int $warehouseId = 0): float
{
    if ($variantId <= 0) {
        return 0.0;
    }
    // FIFO م4: تكلفة الوحدة من الطبقات المتبقية (مصدر الحقيقة) عند توفّر المخزن؛ ثم احتياطي تراثي.
    if ($warehouseId > 0) {
        $layerUnit = orange_inventory_cost_layers_current_unit_cost($pdo, $warehouseId, $variantId);
        if ($layerUnit > 0) {
            return round($layerUnit, 4);
        }
    }
    if (orange_table_exists($pdo, 'purchase_items') && orange_table_exists($pdo, 'purchases')) {
        $st = $pdo->prepare(
            'SELECT pi.cost FROM purchase_items pi
             INNER JOIN purchases pu ON pu.id = pi.purchase_id
             WHERE pi.variant_id = ? AND pi.cost > 0
             ORDER BY pu.purchase_date DESC, pi.id DESC LIMIT 1'
        );
        $st->execute([$variantId]);
        $cost = $st->fetchColumn();
        if ($cost !== false && $cost !== null && (float) $cost > 0) {
            return round((float) $cost, 4);
        }
    }
    $st = $pdo->prepare(
        'SELECT p.cost FROM product_variants pv INNER JOIN products p ON p.id = pv.product_id
         WHERE pv.id = ? LIMIT 1'
    );
    $st->execute([$variantId]);
    $cost = $st->fetchColumn();

    return $cost !== false && $cost !== null ? round((float) $cost, 4) : 0.0;
}

/**
 * @return list<array{variant_id:int,product_name:string,color:string,size:string,item_code:string,qty_system:int}>
 */
function orange_inventory_reconciliation_stock_lines_for_warehouse(PDO $pdo, int $warehouseId, ?int $countryId = null): array
{
    orange_inventory_reconciliation_assert_warehouse_country($pdo, $warehouseId, $countryId);
    if ($countryId === null || $countryId <= 0) {
        $wh = orange_warehouse_row_by_id($pdo, $warehouseId);
        $countryId = $wh !== null ? (int) ($wh['country_id'] ?? 0) : orange_admin_settings_effective_country_id($pdo);
    }

    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    if (orange_warehouses_table_exists($pdo)) {
        $wid = (int) $warehouseId;
        $join = ' LEFT JOIN warehouse_variant_stock wvs ON wvs.warehouse_id = ' . $wid . ' AND wvs.variant_id = pv.id ';
        $qtyExpr = orange_warehouse_legacy_stock_fallback_enabled($pdo, $countryId)
            ? 'COALESCE(wvs.quantity, pv.stock_quantity)'
            : 'COALESCE(wvs.quantity, 0)';
    } else {
        $join = '';
        $qtyExpr = 'pv.stock_quantity';
    }

    $sql = 'SELECT pv.id AS variant_id, pv.color, pv.size, p.name AS product_name, COALESCE(p.item_code, \'\') AS item_code,
            (' . $qtyExpr . ') AS qty_system
            FROM product_variants pv
            INNER JOIN products p ON p.id = pv.product_id'
        . $join
        . ' WHERE 1=1' . $countrySql . '
            ORDER BY p.name ASC, pv.color ASC, pv.size ASC, pv.id ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $vid = (int) ($row['variant_id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $out[] = [
            'variant_id' => $vid,
            'product_name' => (string) ($row['product_name'] ?? ''),
            'color' => (string) ($row['color'] ?? ''),
            'size' => (string) ($row['size'] ?? ''),
            'item_code' => (string) ($row['item_code'] ?? ''),
            'qty_system' => (int) ($row['qty_system'] ?? 0),
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rawLines
 *
 * @return array{
 *   lines:list<array<string,mixed>>,
 *   total_qty_variance:int,
 *   total_value_variance:float,
 *   lines_with_variance:int
 * }
 */
function orange_inventory_reconciliation_enrich_lines(PDO $pdo, array $rawLines, int $warehouseId = 0): array
{
    $lines = [];
    $totalQtyVar = 0;
    $totalValue = 0.0;
    $withVar = 0;
    foreach ($rawLines as $ln) {
        $variantId = (int) ($ln['variant_id'] ?? 0);
        $qtySystem = (int) ($ln['qty_system'] ?? 0);
        $qtyCounted = (int) ($ln['qty_counted'] ?? 0);
        $qtyVariance = (int) ($ln['qty_variance'] ?? ($qtyCounted - $qtySystem));
        $unitCost = orange_inventory_reconciliation_variant_unit_cost($pdo, $variantId, $warehouseId);
        $lineValue = round($qtyVariance * $unitCost, 4);
        if ($qtyVariance !== 0) {
            ++$withVar;
        }
        $totalQtyVar += $qtyVariance;
        $totalValue += $lineValue;
        $lines[] = array_merge($ln, [
            'variant_id' => $variantId,
            'qty_system' => $qtySystem,
            'qty_counted' => $qtyCounted,
            'qty_variance' => $qtyVariance,
            'unit_cost' => $unitCost,
            'value_variance' => $lineValue,
            'product_name' => (string) ($ln['product_name'] ?? ''),
            'color' => (string) ($ln['color'] ?? ''),
            'size' => (string) ($ln['size'] ?? ''),
            'item_code' => (string) ($ln['item_code'] ?? ''),
        ]);
    }

    return [
        'lines' => $lines,
        'total_qty_variance' => $totalQtyVar,
        'total_value_variance' => round($totalValue, 4),
        'lines_with_variance' => $withVar,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_inventory_reconciliation_get(PDO $pdo, int $id, ?int $countryId = null): ?array
{
    if ($id <= 0 || ! orange_inventory_reconciliation_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM inventory_reconciliation WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        return null;
    }

    if ($countryId !== null && $countryId > 0 && isset($row['country_id']) && (int) $row['country_id'] > 0) {
        if ((int) $row['country_id'] !== $countryId) {
            return null;
        }
    }

    $warehouseId = (int) ($row['warehouse_id'] ?? 0);
    $stL = $pdo->prepare(
        'SELECT irl.id, irl.variant_id, irl.qty_system, irl.qty_counted, irl.qty_variance,
                pv.color, pv.size, p.name AS product_name, COALESCE(p.item_code, \'\') AS item_code
         FROM inventory_reconciliation_line irl
         INNER JOIN product_variants pv ON pv.id = irl.variant_id
         INNER JOIN products p ON p.id = pv.product_id
         WHERE irl.reconciliation_id = ?
         ORDER BY p.name ASC, pv.color ASC, pv.size ASC, irl.id ASC'
    );
    $stL->execute([$id]);
    $rawLines = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $enriched = orange_inventory_reconciliation_enrich_lines($pdo, $rawLines, $warehouseId);

    $whLabel = '';
    if ($warehouseId > 0) {
        $wh = orange_warehouse_row_by_id($pdo, $warehouseId);
        if ($wh !== null) {
            $whLabel = trim((string) ($wh['name_ar'] ?? ''));
            if ($whLabel === '') {
                $whLabel = trim((string) ($wh['name_en'] ?? ''));
            }
        }
    }

    return [
        'header' => $row,
        'warehouse_label' => $whLabel,
        'lines' => $enriched['lines'],
        'total_qty_variance' => $enriched['total_qty_variance'],
        'total_value_variance' => $enriched['total_value_variance'],
        'lines_with_variance' => $enriched['lines_with_variance'],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function orange_inventory_reconciliation_list(PDO $pdo, ?int $countryId = null, int $limit = 50): array
{
    if (! orange_inventory_reconciliation_ready($pdo)) {
        return [];
    }

    $sql = 'SELECT ir.*, w.name_ar AS warehouse_name_ar, w.name_en AS warehouse_name_en,
            (SELECT COUNT(*) FROM inventory_reconciliation_line irl WHERE irl.reconciliation_id = ir.id) AS line_count,
            (SELECT COALESCE(SUM(irl.qty_variance), 0) FROM inventory_reconciliation_line irl WHERE irl.reconciliation_id = ir.id) AS total_qty_variance
            FROM inventory_reconciliation ir
            LEFT JOIN warehouses w ON w.id = ir.warehouse_id
            WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0 && orange_table_has_column($pdo, 'inventory_reconciliation', 'country_id')) {
        $sql .= ' AND (ir.country_id IS NULL OR ir.country_id = ?)';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY ir.id DESC LIMIT ' . max(1, min(200, $limit));

    if ($params !== []) {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array{variant_id?:int,qty_counted?:int,qty_system?:int}> $linesIn
 *
 * @return list<array{variant_id:int,qty_system:int,qty_counted:int,qty_variance:int}>
 */
function orange_inventory_reconciliation_normalize_lines(PDO $pdo, int $warehouseId, array $linesIn): array
{
    $seen = [];
    $out = [];
    foreach ($linesIn as $ln) {
        if (! is_array($ln)) {
            continue;
        }
        $variantId = (int) ($ln['variant_id'] ?? 0);
        if ($variantId <= 0 || isset($seen[$variantId])) {
            continue;
        }
        $seen[$variantId] = true;
        $qtyCounted = (int) ($ln['qty_counted'] ?? 0);
        if ($qtyCounted < 0) {
            $qtyCounted = 0;
        }
        $qtySystem = orange_inventory_reconciliation_variant_qty_system($pdo, $warehouseId, $variantId);
        $out[] = [
            'variant_id' => $variantId,
            'qty_system' => $qtySystem,
            'qty_counted' => $qtyCounted,
            'qty_variance' => $qtyCounted - $qtySystem,
        ];
    }

    return $out;
}

/**
 * @param list<array{variant_id:int,qty_system:int,qty_counted:int,qty_variance:int}> $lines
 */
function orange_inventory_reconciliation_save(
    PDO $pdo,
    array $headerIn,
    array $lines,
    ?int $countryId = null
): int {
    if (! orange_inventory_reconciliation_ready($pdo)) {
        throw new RuntimeException('جداول تسوية المخزون غير جاهزة.');
    }

    $id = (int) ($headerIn['id'] ?? 0);
    $warehouseId = (int) ($headerIn['warehouse_id'] ?? 0);
    $countedAt = trim((string) ($headerIn['counted_at'] ?? ''));
    $notes = trim((string) ($headerIn['notes'] ?? ''));

    if ($warehouseId <= 0) {
        throw new InvalidArgumentException('اختر المستودع.');
    }
    if ($countedAt === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $countedAt)) {
        throw new InvalidArgumentException('تاريخ الجرد مطلوب (YYYY-MM-DD).');
    }

    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }
    orange_inventory_reconciliation_assert_warehouse_country($pdo, $warehouseId, $countryId);

    if ($id > 0) {
        $existing = orange_inventory_reconciliation_get($pdo, $id, $countryId);
        if ($existing === null) {
            throw new InvalidArgumentException('جلسة الجرد غير موجودة.');
        }
        if ((string) ($existing['header']['status'] ?? '') === 'approved') {
            throw new InvalidArgumentException('جلسة معتمدة — لا يمكن تعديلها.');
        }
    }

    if ($lines === []) {
        throw new InvalidArgumentException('أضف سطراً واحداً على الأقل (حمّل من المستودع أو أدخل كميات).');
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $upd = $pdo->prepare(
                'UPDATE inventory_reconciliation SET warehouse_id = ?, counted_at = ?, notes = ?, country_id = ?
                 WHERE id = ? AND status = \'draft\''
            );
            $upd->execute([
                $warehouseId,
                $countedAt,
                $notes !== '' ? $notes : null,
                $countryId > 0 ? $countryId : null,
                $id,
            ]);
            if ($upd->rowCount() === 0) {
                throw new RuntimeException('تعذّر تحديث جلسة الجرد.');
            }
            $pdo->prepare('DELETE FROM inventory_reconciliation_line WHERE reconciliation_id = ?')->execute([$id]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO inventory_reconciliation (warehouse_id, status, counted_at, notes, country_id)
                 VALUES (?, \'draft\', ?, ?, ?)'
            );
            $ins->execute([
                $warehouseId,
                $countedAt,
                $notes !== '' ? $notes : null,
                $countryId > 0 ? $countryId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $stLine = $pdo->prepare(
            'INSERT INTO inventory_reconciliation_line (reconciliation_id, variant_id, qty_system, qty_counted, qty_variance)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($lines as $ln) {
            $stLine->execute([
                $id,
                $ln['variant_id'],
                $ln['qty_system'],
                $ln['qty_counted'],
                $ln['qty_variance'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $id;
}

/**
 * اعتماد الجرد = **إقفال تقرير الجرد** فقط (للطباعة ورفعه للإدارة بالتوقيعات).
 *
 * قرار المالك (2026-06): الجرد **تقرير عَدّ فقط** — لا يُطبَّق على المخزون ولا يُنشئ قيد GL.
 * التطبيق الفعلي على المخزون والمعالجة المحاسبية يتمّان عبر **«قيد تسوية مخزون»**
 * (`includes/stock_adjustment_voucher.php`) بعد قرار الإدارة. لذا لا حاجة هنا لحساب تسوية.
 *
 * @param int $adjustmentAccountId مُهمَل (متوافق مع التوقيع القديم) — لم يعد يُرحَّل قيد هنا.
 *
 * @return array{voucher_id:int,total_value_variance:float,total_qty_variance:int,queued:bool}
 */
function orange_inventory_reconciliation_approve(
    PDO $pdo,
    int $id,
    int $adjustmentAccountId = 0,
    ?int $countryId = null
): array {
    $rec = orange_inventory_reconciliation_get($pdo, $id, $countryId);
    if ($rec === null) {
        throw new InvalidArgumentException('جلسة الجرد غير موجودة.');
    }
    $header = $rec['header'];
    if ((string) ($header['status'] ?? '') === 'approved') {
        throw new InvalidArgumentException('الجرد مُقفل مسبقاً.');
    }

    $totalValue = (float) ($rec['total_value_variance'] ?? 0);
    $totalQtyVar = (int) ($rec['total_qty_variance'] ?? 0);

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            'UPDATE inventory_reconciliation SET status = \'approved\', journal_voucher_id = NULL, approved_at = NOW()
             WHERE id = ? AND status = \'draft\''
        );
        $upd->execute([$id]);
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('تعذّر إقفال الجرد.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'voucher_id' => 0,
        'total_value_variance' => round($totalValue, 4),
        'total_qty_variance' => $totalQtyVar,
        'queued' => false,
    ];
}

function orange_inventory_reconciliation_delete_draft(PDO $pdo, int $id, ?int $countryId = null): bool
{
    $rec = orange_inventory_reconciliation_get($pdo, $id, $countryId);
    if ($rec === null || (string) ($rec['header']['status'] ?? '') !== 'draft') {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM inventory_reconciliation WHERE id = ? AND status = \'draft\'');
    $st->execute([$id]);

    return $st->rowCount() > 0;
}

/*
 * ============================================================================
 * أرشيف الجرد (قرار المالك 2026-06-16)
 * ----------------------------------------------------------------------------
 * الشاشة صارت أرشيفاً لرفع تقرير الجرد المطبوع الموقّع: سجل = (مخزن + تاريخ + ملاحظة + مرفقات).
 * لا عَدّ رقمي ولا أسطر ولا أثر مخزني/محاسبي — التطبيق عبر «قيد تسوية مخزون» (SAJ).
 * يُعاد استخدام جدول inventory_reconciliation (header) + العمود attachments_json.
 * ============================================================================
 */

/**
 * حفظ/تحديث سجل أرشيف جرد (رأس فقط: مخزن، تاريخ، ملاحظة).
 */
function orange_inventory_reconciliation_archive_save(PDO $pdo, array $headerIn, ?int $countryId = null): int
{
    if (! orange_inventory_reconciliation_ready($pdo)) {
        throw new RuntimeException('جداول الجرد غير جاهزة.');
    }

    $id = (int) ($headerIn['id'] ?? 0);
    $warehouseId = (int) ($headerIn['warehouse_id'] ?? 0);
    $agentId = (int) ($headerIn['delivery_agent_id'] ?? 0);
    $countedAt = trim((string) ($headerIn['counted_at'] ?? ''));
    $notes = trim((string) ($headerIn['notes'] ?? ''));

    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }

    // نطاق الجرد: إمّا مخزن وإمّا عهدة مندوب. عند اختيار مندوب يُسند المخزن للافتراضي (لقيد warehouse_id NOT NULL).
    if ($agentId > 0) {
        if (orange_delivery_agent_row_by_id($pdo, $agentId) === null) {
            throw new InvalidArgumentException('المندوب غير موجود.');
        }
        if ($warehouseId <= 0) {
            $warehouseId = (int) orange_warehouse_default_id_for_country($pdo, $countryId);
        }
    }
    if ($warehouseId <= 0) {
        throw new InvalidArgumentException('اختر المخزن أو المندوب.');
    }
    if ($countedAt === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $countedAt)) {
        throw new InvalidArgumentException('تاريخ الجرد مطلوب (YYYY-MM-DD).');
    }

    orange_inventory_reconciliation_assert_warehouse_country($pdo, $warehouseId, $countryId);

    $hasAgentCol = orange_table_has_column($pdo, 'inventory_reconciliation', 'delivery_agent_id');

    if ($id > 0) {
        $existing = orange_inventory_reconciliation_get($pdo, $id, $countryId);
        if ($existing === null) {
            throw new InvalidArgumentException('سجل الجرد غير موجود.');
        }
        $sql = 'UPDATE inventory_reconciliation SET warehouse_id = ?, counted_at = ?, notes = ?, country_id = ?'
            . ($hasAgentCol ? ', delivery_agent_id = ?' : '')
            . ' WHERE id = ?';
        $args = [
            $warehouseId,
            $countedAt,
            $notes !== '' ? $notes : null,
            $countryId > 0 ? $countryId : null,
        ];
        if ($hasAgentCol) {
            $args[] = $agentId > 0 ? $agentId : null;
        }
        $args[] = $id;
        $pdo->prepare($sql)->execute($args);

        return $id;
    }

    $cols = 'warehouse_id, status, counted_at, notes, country_id';
    $ph = '?, \'archived\', ?, ?, ?';
    $args = [
        $warehouseId,
        $countedAt,
        $notes !== '' ? $notes : null,
        $countryId > 0 ? $countryId : null,
    ];
    if ($hasAgentCol) {
        $cols .= ', delivery_agent_id';
        $ph .= ', ?';
        $args[] = $agentId > 0 ? $agentId : null;
    }
    $ins = $pdo->prepare('INSERT INTO inventory_reconciliation (' . $cols . ') VALUES (' . $ph . ')');
    $ins->execute($args);

    return (int) $pdo->lastInsertId();
}

/**
 * قائمة سجلات أرشيف الجرد (مع عدد المرفقات) ضمن سياق الدولة.
 *
 * @return list<array<string,mixed>>
 */
function orange_inventory_reconciliation_archive_list(PDO $pdo, ?int $countryId = null, int $limit = 100): array
{
    if (! orange_inventory_reconciliation_ready($pdo)) {
        return [];
    }
    $hasAttach = orange_table_has_column($pdo, 'inventory_reconciliation', 'attachments_json');
    $hasAgent = orange_table_has_column($pdo, 'inventory_reconciliation', 'delivery_agent_id');
    $hasAgentTable = orange_table_exists($pdo, 'delivery_agents');
    $cols = 'ir.id, ir.warehouse_id, ir.counted_at, ir.notes, ir.created_at, ir.country_id,
             w.name_ar AS warehouse_name_ar, w.name_en AS warehouse_name_en';
    if ($hasAttach) {
        $cols .= ', ir.attachments_json';
    }
    $join = ' LEFT JOIN warehouses w ON w.id = ir.warehouse_id';
    if ($hasAgent) {
        $cols .= ', ir.delivery_agent_id';
        if ($hasAgentTable) {
            $cols .= ', da.name_ar AS agent_name_ar, da.name_en AS agent_name_en';
            $join .= ' LEFT JOIN delivery_agents da ON da.id = ir.delivery_agent_id';
        }
    }
    $sql = 'SELECT ' . $cols . '
            FROM inventory_reconciliation ir'
            . $join
            . ' WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0 && orange_table_has_column($pdo, 'inventory_reconciliation', 'country_id')) {
        $sql .= ' AND (ir.country_id IS NULL OR ir.country_id = ?)';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY ir.counted_at DESC, ir.id DESC LIMIT ' . max(1, min(300, $limit));

    if ($params !== []) {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    foreach ($rows as &$row) {
        $count = 0;
        if ($hasAttach) {
            $count = count(orange_stocktake_archive_decode_list((string) ($row['attachments_json'] ?? '')));
            unset($row['attachments_json']);
        }
        $row['attachment_count'] = $count;

        $agentName = trim((string) ($row['agent_name_ar'] ?? ''));
        if ($agentName === '') {
            $agentName = trim((string) ($row['agent_name_en'] ?? ''));
        }
        $whName = trim((string) ($row['warehouse_name_ar'] ?? ''));
        if ($whName === '') {
            $whName = trim((string) ($row['warehouse_name_en'] ?? ''));
        }
        if ($whName === '') {
            $whName = '#' . (int) ($row['warehouse_id'] ?? 0);
        }
        $row['scope_label'] = $agentName !== '' ? ('عهدة: ' . $agentName) : $whName;
    }
    unset($row);

    return $rows;
}

/**
 * جلب سجل أرشيف جرد مع مرفقاته.
 *
 * @return array<string,mixed>|null
 */
function orange_inventory_reconciliation_archive_get(PDO $pdo, int $id, ?int $countryId = null): ?array
{
    if ($id <= 0 || ! orange_inventory_reconciliation_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM inventory_reconciliation WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        return null;
    }
    if ($countryId !== null && $countryId > 0 && isset($row['country_id']) && (int) $row['country_id'] > 0
        && (int) $row['country_id'] !== $countryId) {
        return null;
    }

    $warehouseId = (int) ($row['warehouse_id'] ?? 0);
    $whLabel = '';
    if ($warehouseId > 0) {
        $wh = orange_warehouse_row_by_id($pdo, $warehouseId);
        if ($wh !== null) {
            $whLabel = trim((string) ($wh['name_ar'] ?? ''));
            if ($whLabel === '') {
                $whLabel = trim((string) ($wh['name_en'] ?? ''));
            }
        }
    }

    $agentId = (int) ($row['delivery_agent_id'] ?? 0);
    $agentLabel = '';
    if ($agentId > 0) {
        $ag = orange_delivery_agent_row_by_id($pdo, $agentId);
        if ($ag !== null) {
            $agentLabel = orange_delivery_agent_display_name($ag);
        }
    }
    $scopeLabel = $agentLabel !== '' ? ('عهدة المندوب: ' . $agentLabel) : $whLabel;

    return [
        'header' => $row,
        'warehouse_label' => $whLabel,
        'agent_label' => $agentLabel,
        'scope_label' => $scopeLabel,
        'attachments' => orange_stocktake_archive_decode_list((string) ($row['attachments_json'] ?? '')),
    ];
}

/**
 * حذف سجل أرشيف جرد + ملفات مرفقاته من القرص.
 */
function orange_inventory_reconciliation_archive_delete(PDO $pdo, int $id, ?int $countryId = null): bool
{
    $rec = orange_inventory_reconciliation_archive_get($pdo, $id, $countryId);
    if ($rec === null) {
        return false;
    }
    foreach (($rec['attachments'] ?? []) as $att) {
        $abs = orange_stocktake_archive_abs_path((string) ($att['path'] ?? ''));
        if (orange_stocktake_archive_is_within_upload_root($abs) && is_file($abs)) {
            @unlink($abs);
        }
    }
    $dir = orange_stocktake_archive_upload_root() . DIRECTORY_SEPARATOR . $id;
    if (is_dir($dir)) {
        @rmdir($dir);
    }
    $st = $pdo->prepare('DELETE FROM inventory_reconciliation WHERE id = ?');
    $st->execute([$id]);

    return $st->rowCount() > 0;
}
