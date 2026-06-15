<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/account_tree.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/gl_settings.php';
require_once __DIR__ . '/gl_pending_movements.php';
require_once __DIR__ . '/admin_settings_country.php';
require_once __DIR__ . '/inventory_cost_layers.php';
require_once __DIR__ . '/inventory_reconciliation.php';

/**
 * سند تعديل الرصيد (Stock Adjustment Voucher).
 *
 * فلسفته: بعد رفع تقرير الجرد للإدارة واتخاذ القرار، يحرّره المحاسب بإضافة/خصم كميات لكل صنف
 * (شبكة كميات بلا حساب)؛ تُحتسب قيمة الفرق من تكلفة FIFO. المعالجة المحاسبية تُسجَّل في **كارت سفلي
 * بشبكة مدين/دائن مرنة على مستوى السند** (الطرف المقابل: أرباح/خسائر أو ذمة موظف، سطر واحد أو أكثر).
 * عند الاعتماد: يُطبَّق الفرق على المخزون (وطبقات التكلفة)، ويُبنى قيد GL واحد = سطر(أسطر) المخزون
 * التلقائية (مدين عند الزيادة/دائن عند النقص بقيمة FIFO) + أسطر المعالجة المرنة، بنوع يومية مستقل
 * «قيد تسوية مخزون» (entry_type=stock_adjustment) مع التحقق من التوازن.
 */

function orange_stock_adjustment_voucher_ensure_schema(PDO $pdo): void
{
    orange_catalog_ensure_schema($pdo);

    if (! orange_table_exists($pdo, 'stock_adjustment_voucher')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE stock_adjustment_voucher (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                warehouse_id INT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'draft\',
                document_date DATE NULL DEFAULT NULL,
                notes VARCHAR(512) NULL DEFAULT NULL,
                journal_voucher_id INT NULL DEFAULT NULL,
                reconciliation_id INT UNSIGNED NULL DEFAULT NULL,
                country_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                approved_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_stk_adj_wh (warehouse_id, status),
                KEY idx_stk_adj_country (country_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('stock_adjustment_voucher');
    }

    if (! orange_table_exists($pdo, 'stock_adjustment_voucher_line')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE stock_adjustment_voucher_line (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                voucher_id INT UNSIGNED NOT NULL,
                variant_id INT UNSIGNED NOT NULL,
                qty_add INT NOT NULL DEFAULT 0,
                qty_deduct INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(15,5) NOT NULL DEFAULT 0,
                treatment_account_id INT UNSIGNED NULL DEFAULT NULL,
                note VARCHAR(255) NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_stk_adj_line_parent (voucher_id),
                KEY idx_stk_adj_line_variant (variant_id),
                CONSTRAINT orange_fk_stk_adj_line_parent FOREIGN KEY (voucher_id)
                    REFERENCES stock_adjustment_voucher (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('stock_adjustment_voucher_line');
    }

    if (orange_table_exists($pdo, 'stock_adjustment_voucher_line')
        && orange_table_exists($pdo, 'product_variants')
        && ! orange_schema_fk_exists($pdo, 'stock_adjustment_voucher_line', 'orange_fk_stk_adj_line_variant')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE stock_adjustment_voucher_line
                ADD CONSTRAINT orange_fk_stk_adj_line_variant FOREIGN KEY (variant_id)
                    REFERENCES product_variants (id)'
        );
    }

    // أسطر المعالجة المحاسبية (شبكة مدين/دائن مرنة على مستوى السند — الطرف المقابل لحركة المخزون).
    if (! orange_table_exists($pdo, 'stock_adjustment_voucher_gl')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE stock_adjustment_voucher_gl (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                voucher_id INT UNSIGNED NOT NULL,
                line_no INT UNSIGNED NOT NULL DEFAULT 0,
                account_id INT UNSIGNED NOT NULL,
                debit DECIMAL(15,4) NOT NULL DEFAULT 0,
                credit DECIMAL(15,4) NOT NULL DEFAULT 0,
                memo VARCHAR(255) NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_stk_adj_gl_parent (voucher_id),
                CONSTRAINT orange_fk_stk_adj_gl_parent FOREIGN KEY (voucher_id)
                    REFERENCES stock_adjustment_voucher (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('stock_adjustment_voucher_gl');
    }
}

function orange_stock_adjustment_voucher_ready(PDO $pdo): bool
{
    orange_stock_adjustment_voucher_ensure_schema($pdo);

    return orange_table_exists($pdo, 'stock_adjustment_voucher')
        && orange_table_exists($pdo, 'stock_adjustment_voucher_line');
}

/**
 * معلومات متغيّر للعرض في منتقي السند (الرصيد الحالي + تكلفة الوحدة الإرشادية).
 *
 * @return array<string, mixed>|null
 */
function orange_stock_adjustment_variant_info(PDO $pdo, int $variantId, int $warehouseId): ?array
{
    if ($variantId <= 0) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT pv.id AS variant_id, pv.color, pv.size, p.id AS product_id, p.name AS product_name,
                COALESCE(p.item_code, \'\') AS item_code
         FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id
         WHERE pv.id = ? LIMIT 1'
    );
    $st->execute([$variantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        return null;
    }

    return [
        'variant_id' => (int) $row['variant_id'],
        'product_id' => (int) $row['product_id'],
        'product_name' => (string) $row['product_name'],
        'color' => (string) ($row['color'] ?? ''),
        'size' => (string) ($row['size'] ?? ''),
        'item_code' => (string) ($row['item_code'] ?? ''),
        'qty_system' => orange_inventory_reconciliation_variant_qty_system($pdo, $warehouseId, $variantId),
        'unit_cost' => orange_inventory_reconciliation_variant_unit_cost($pdo, $variantId, $warehouseId),
    ];
}

/** تسمية حساب المعالجة (كود — اسم) للعرض. */
function orange_stock_adjustment_account_label(PDO $pdo, int $accountId): string
{
    if ($accountId <= 0 || ! orange_table_exists($pdo, 'accounts')) {
        return '';
    }
    $st = $pdo->prepare('SELECT code, name FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$accountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        return '';
    }
    $code = trim((string) ($row['code'] ?? ''));
    $name = trim((string) ($row['name'] ?? ''));

    return ($code !== '' ? $code . ' — ' : '') . $name;
}

/**
 * تطبيع أسطر الإدخال (variant_id, qty_add, qty_deduct, treatment_account_id, note).
 *
 * @param list<array<string, mixed>> $linesIn
 * @return list<array<string, mixed>>
 */
function orange_stock_adjustment_voucher_normalize_lines(PDO $pdo, int $warehouseId, array $linesIn): array
{
    $out = [];
    $seen = [];
    foreach ($linesIn as $ln) {
        if (! is_array($ln)) {
            continue;
        }
        $variantId = (int) ($ln['variant_id'] ?? 0);
        if ($variantId <= 0 || isset($seen[$variantId])) {
            continue;
        }
        $qtyAdd = max(0, (int) ($ln['qty_add'] ?? 0));
        $qtyDeduct = max(0, (int) ($ln['qty_deduct'] ?? 0));
        if ($qtyAdd === 0 && $qtyDeduct === 0) {
            continue;
        }
        $seen[$variantId] = true;
        $info = orange_stock_adjustment_variant_info($pdo, $variantId, $warehouseId);
        if ($info === null) {
            continue;
        }
        $out[] = [
            'variant_id' => $variantId,
            'qty_add' => $qtyAdd,
            'qty_deduct' => $qtyDeduct,
            'note' => trim((string) ($ln['note'] ?? '')),
            'unit_cost' => (float) $info['unit_cost'],
            'qty_system' => (int) $info['qty_system'],
        ];
    }

    return $out;
}

/**
 * تطبيع أسطر المعالجة المحاسبية (شبكة مدين/دائن المرنة).
 * كل سطر: account_id (ورقة ترحيل) + debit/credit (أحدهما موجب) + memo.
 *
 * @param list<array<string, mixed>> $glIn
 * @return list<array{account_id:int,debit:float,credit:float,memo:string}>
 */
function orange_stock_adjustment_voucher_normalize_gl_lines(PDO $pdo, array $glIn): array
{
    $out = [];
    foreach ($glIn as $ln) {
        if (! is_array($ln)) {
            continue;
        }
        $accId = (int) ($ln['account_id'] ?? 0);
        $debit = round(max(0.0, (float) ($ln['debit'] ?? 0)), 4);
        $credit = round(max(0.0, (float) ($ln['credit'] ?? 0)), 4);
        if ($accId <= 0 && $debit <= 0 && $credit <= 0) {
            continue; // سطر فارغ
        }
        $out[] = [
            'account_id' => $accId,
            'debit' => $debit,
            'credit' => $credit,
            'memo' => mb_substr(trim((string) ($ln['memo'] ?? '')), 0, 255),
        ];
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_stock_adjustment_voucher_get_gl_lines(PDO $pdo, int $voucherId): array
{
    if ($voucherId <= 0 || ! orange_table_exists($pdo, 'stock_adjustment_voucher_gl')) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT id, account_id, debit, credit, memo FROM stock_adjustment_voucher_gl
         WHERE voucher_id = ? ORDER BY line_no ASC, id ASC'
    );
    $st->execute([$voucherId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $accId = (int) ($r['account_id'] ?? 0);
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'account_id' => $accId,
            'account_label' => orange_stock_adjustment_account_label($pdo, $accId),
            'debit' => round((float) ($r['debit'] ?? 0), 4),
            'credit' => round((float) ($r['credit'] ?? 0), 4),
            'memo' => (string) ($r['memo'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function orange_stock_adjustment_voucher_get(PDO $pdo, int $id, ?int $countryId = null): ?array
{
    if ($id <= 0 || ! orange_stock_adjustment_voucher_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM stock_adjustment_voucher WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        return null;
    }
    if ($countryId !== null && $countryId > 0 && (int) ($row['country_id'] ?? 0) > 0
        && (int) $row['country_id'] !== $countryId) {
        return null;
    }

    $warehouseId = (int) ($row['warehouse_id'] ?? 0);
    $stL = $pdo->prepare(
        'SELECT sl.id, sl.variant_id, sl.qty_add, sl.qty_deduct, sl.unit_cost, sl.treatment_account_id, sl.note,
                pv.color, pv.size, p.name AS product_name, COALESCE(p.item_code, \'\') AS item_code
         FROM stock_adjustment_voucher_line sl
         INNER JOIN product_variants pv ON pv.id = sl.variant_id
         INNER JOIN products p ON p.id = pv.product_id
         WHERE sl.voucher_id = ?
         ORDER BY sl.id ASC'
    );
    $stL->execute([$id]);
    $rawLines = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lines = [];
    $totalValue = 0.0;
    $incValue = 0.0;
    $decValue = 0.0;
    $approved = (string) ($row['status'] ?? '') === 'approved';
    foreach ($rawLines as $ln) {
        $variantId = (int) ($ln['variant_id'] ?? 0);
        $qtyAdd = (int) ($ln['qty_add'] ?? 0);
        $qtyDeduct = (int) ($ln['qty_deduct'] ?? 0);
        $delta = $qtyAdd - $qtyDeduct;
        // للمسودة: التكلفة لحظية للعرض؛ للمعتمد: التكلفة المخزّنة وقت الاعتماد.
        $unitCost = $approved
            ? (float) ($ln['unit_cost'] ?? 0)
            : orange_inventory_reconciliation_variant_unit_cost($pdo, $variantId, $warehouseId);
        $value = round($delta * $unitCost, 4);
        $totalValue += $value;
        if ($value > 0) {
            $incValue += $value;
        } elseif ($value < 0) {
            $decValue += -$value;
        }
        $accId = (int) ($ln['treatment_account_id'] ?? 0);
        $lines[] = [
            'id' => (int) ($ln['id'] ?? 0),
            'variant_id' => $variantId,
            'product_name' => (string) ($ln['product_name'] ?? ''),
            'color' => (string) ($ln['color'] ?? ''),
            'size' => (string) ($ln['size'] ?? ''),
            'item_code' => (string) ($ln['item_code'] ?? ''),
            'qty_add' => $qtyAdd,
            'qty_deduct' => $qtyDeduct,
            'delta' => $delta,
            'unit_cost' => round($unitCost, 4),
            'value' => $value,
            'treatment_account_id' => $accId,
            'treatment_account_label' => orange_stock_adjustment_account_label($pdo, $accId),
            'note' => (string) ($ln['note'] ?? ''),
            'qty_system' => orange_inventory_reconciliation_variant_qty_system($pdo, $warehouseId, $variantId),
        ];
    }

    $whLabel = '';
    if ($warehouseId > 0) {
        $wh = orange_warehouse_row_by_id($pdo, $warehouseId);
        if ($wh !== null) {
            $whLabel = trim((string) ($wh['name_ar'] ?? '')) ?: trim((string) ($wh['name_en'] ?? ''));
        }
    }

    $countryForGl = (int) ($row['country_id'] ?? 0);
    if ($countryForGl <= 0) {
        $countryForGl = orange_admin_settings_effective_country_id($pdo);
    }
    $inventoryAccountId = orange_gl_account_id($pdo, 'inventory', $countryForGl > 0 ? $countryForGl : null);

    return [
        'header' => $row,
        'warehouse_label' => $whLabel,
        'lines' => $lines,
        'gl_lines' => orange_stock_adjustment_voucher_get_gl_lines($pdo, $id),
        'total_value' => round($totalValue, 4),
        'inc_value' => round($incValue, 4),
        'dec_value' => round($decValue, 4),
        'inventory_account_id' => $inventoryAccountId,
        'inventory_account_label' => orange_stock_adjustment_account_label($pdo, $inventoryAccountId),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function orange_stock_adjustment_voucher_list(PDO $pdo, ?int $countryId = null): array
{
    if (! orange_stock_adjustment_voucher_ready($pdo)) {
        return [];
    }
    $sql = 'SELECT sv.*, w.name_ar AS warehouse_name_ar, w.name_en AS warehouse_name_en,
            (SELECT COUNT(*) FROM stock_adjustment_voucher_line sl WHERE sl.voucher_id = sv.id) AS line_count
            FROM stock_adjustment_voucher sv
            LEFT JOIN warehouses w ON w.id = sv.warehouse_id
            WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0
        && orange_table_has_column($pdo, 'stock_adjustment_voucher', 'country_id')) {
        $sql .= ' AND (sv.country_id IS NULL OR sv.country_id = ?)';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY sv.id DESC';
    if ($params !== []) {
        $st = $pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** رقم السند المتوقَّع (MAX(id)+1) — للعرض فقط. */
function orange_stock_adjustment_voucher_next_no(PDO $pdo, ?int $countryId = null): int
{
    if (! orange_stock_adjustment_voucher_ready($pdo)) {
        return 1;
    }

    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM stock_adjustment_voucher')->fetchColumn();
}

/** تنقّل بين السندات ضمن سياق الدولة. */
function orange_stock_adjustment_voucher_nav(PDO $pdo, string $where, int $currentId, ?int $countryId = null): int
{
    if (! orange_stock_adjustment_voucher_ready($pdo)) {
        return 0;
    }
    $scope = '';
    $params = [];
    if ($countryId !== null && $countryId > 0
        && orange_table_has_column($pdo, 'stock_adjustment_voucher', 'country_id')) {
        $scope = ' AND (country_id IS NULL OR country_id = ?)';
        $params[] = $countryId;
    }
    switch ($where) {
        case 'first':
            $sql = 'SELECT MIN(id) FROM stock_adjustment_voucher WHERE 1=1' . $scope;
            break;
        case 'last':
            $sql = 'SELECT MAX(id) FROM stock_adjustment_voucher WHERE 1=1' . $scope;
            break;
        case 'prev':
            $sql = 'SELECT MAX(id) FROM stock_adjustment_voucher WHERE id < ?' . $scope;
            array_unshift($params, $currentId);
            break;
        case 'next':
            $sql = 'SELECT MIN(id) FROM stock_adjustment_voucher WHERE id > ?' . $scope;
            array_unshift($params, $currentId);
            break;
        default:
            return 0;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * بحث في السندات (نطاق رقم/تاريخ/ملاحظة) — بلا حدّ أسطر.
 *
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function orange_stock_adjustment_voucher_search(PDO $pdo, array $filters, ?int $countryId = null): array
{
    if (! orange_stock_adjustment_voucher_ready($pdo)) {
        return [];
    }
    $sql = 'SELECT sv.id, sv.document_date, sv.status, sv.notes,
            (SELECT COUNT(*) FROM stock_adjustment_voucher_line sl WHERE sl.voucher_id = sv.id) AS line_count
            FROM stock_adjustment_voucher sv WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0
        && orange_table_has_column($pdo, 'stock_adjustment_voucher', 'country_id')) {
        $sql .= ' AND (sv.country_id IS NULL OR sv.country_id = ?)';
        $params[] = $countryId;
    }
    $idFrom = (int) ($filters['id_from'] ?? 0);
    $idTo = (int) ($filters['id_to'] ?? 0);
    if ($idFrom > 0) {
        $sql .= ' AND sv.id >= ?';
        $params[] = $idFrom;
    }
    if ($idTo > 0) {
        $sql .= ' AND sv.id <= ?';
        $params[] = $idTo;
    }
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $sql .= ' AND sv.document_date >= ?';
        $params[] = $dateFrom;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $sql .= ' AND sv.document_date <= ?';
        $params[] = $dateTo;
    }
    $notes = trim((string) ($filters['notes'] ?? ''));
    if ($notes !== '') {
        $sql .= ' AND sv.notes LIKE ?';
        $params[] = '%' . $notes . '%';
    }
    $sql .= ' ORDER BY sv.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * حفظ مسودة سند تعديل رصيد (لا يطبّق مخزوناً ولا يرحّل).
 *
 * @param array<string, mixed> $headerIn
 * @param list<array<string, mixed>> $lines
 */
function orange_stock_adjustment_voucher_save(PDO $pdo, array $headerIn, array $lines, ?int $countryId = null): int
{
    if (! orange_stock_adjustment_voucher_ready($pdo)) {
        throw new RuntimeException('جداول سند تعديل الرصيد غير جاهزة.');
    }

    $id = (int) ($headerIn['id'] ?? 0);
    $documentDate = trim((string) ($headerIn['document_date'] ?? ''));
    $notes = trim((string) ($headerIn['notes'] ?? ''));

    if ($documentDate === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
        throw new InvalidArgumentException('تاريخ السند مطلوب (يوم/شهر/سنة).');
    }
    if ($lines === []) {
        throw new InvalidArgumentException('أضف صنفاً واحداً على الأقل بكمية إضافة أو خصم.');
    }

    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    if ($warehouseId <= 0) {
        throw new RuntimeException('لا يوجد مستودع افتراضي لهذه الدولة.');
    }

    // أسطر المعالجة المحاسبية (الكارت السفلي) — اختيارية في المسودة، إلزامية ومتوازنة عند الاعتماد.
    $glLines = orange_stock_adjustment_voucher_normalize_gl_lines($pdo, $headerIn['gl_lines'] ?? []);
    $inventoryAccountId = orange_gl_account_id($pdo, 'inventory', $countryId > 0 ? $countryId : null);
    foreach ($glLines as $g) {
        if ($g['account_id'] <= 0 || ! orange_accounts_account_is_posting_leaf($pdo, $g['account_id'])) {
            throw new InvalidArgumentException('سطر معالجة بحساب غير صالح (يجب اختيار حساب ورقة-ترحيل).');
        }
        if ($inventoryAccountId > 0 && $g['account_id'] === $inventoryAccountId) {
            throw new InvalidArgumentException('لا تختر حساب المخزون في أسطر المعالجة — يُضاف تلقائياً.');
        }
        $hasD = $g['debit'] > 0;
        $hasC = $g['credit'] > 0;
        if ($hasD === $hasC) {
            throw new InvalidArgumentException('كل سطر معالجة يجب أن يحمل مديناً أو دائناً (أحدهما فقط).');
        }
    }

    if ($id > 0) {
        $existing = orange_stock_adjustment_voucher_get($pdo, $id, $countryId);
        if ($existing === null) {
            throw new InvalidArgumentException('السند غير موجود.');
        }
        if ((string) ($existing['header']['status'] ?? '') === 'approved') {
            throw new InvalidArgumentException('سند معتمد — لا يمكن تعديله.');
        }
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $upd = $pdo->prepare(
                'UPDATE stock_adjustment_voucher SET warehouse_id = ?, document_date = ?, notes = ?, country_id = ?
                 WHERE id = ? AND status = \'draft\''
            );
            $upd->execute([
                $warehouseId,
                $documentDate,
                $notes !== '' ? $notes : null,
                $countryId > 0 ? $countryId : null,
                $id,
            ]);
            if ($upd->rowCount() === 0) {
                // لا تغيّر في الرأس لكن قد تتغيّر الأسطر — لا نعتبره فشلاً إلا إن لم يكن مسودة.
                $chk = $pdo->prepare('SELECT status FROM stock_adjustment_voucher WHERE id = ? LIMIT 1');
                $chk->execute([$id]);
                if ((string) $chk->fetchColumn() !== 'draft') {
                    throw new RuntimeException('تعذّر تحديث السند.');
                }
            }
            $pdo->prepare('DELETE FROM stock_adjustment_voucher_line WHERE voucher_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM stock_adjustment_voucher_gl WHERE voucher_id = ?')->execute([$id]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO stock_adjustment_voucher (warehouse_id, status, document_date, notes, country_id)
                 VALUES (?, \'draft\', ?, ?, ?)'
            );
            $ins->execute([
                $warehouseId,
                $documentDate,
                $notes !== '' ? $notes : null,
                $countryId > 0 ? $countryId : null,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $stLine = $pdo->prepare(
            'INSERT INTO stock_adjustment_voucher_line
                (voucher_id, variant_id, qty_add, qty_deduct, unit_cost, treatment_account_id, note)
             VALUES (?, ?, ?, ?, ?, NULL, ?)'
        );
        foreach ($lines as $ln) {
            $note = trim((string) ($ln['note'] ?? ''));
            $stLine->execute([
                $id,
                (int) $ln['variant_id'],
                (int) $ln['qty_add'],
                (int) $ln['qty_deduct'],
                round((float) ($ln['unit_cost'] ?? 0), 5),
                $note !== '' ? mb_substr($note, 0, 255) : null,
            ]);
        }

        if ($glLines !== []) {
            $stGl = $pdo->prepare(
                'INSERT INTO stock_adjustment_voucher_gl (voucher_id, line_no, account_id, debit, credit, memo)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $no = 0;
            foreach ($glLines as $g) {
                $stGl->execute([
                    $id,
                    ++$no,
                    $g['account_id'],
                    $g['debit'],
                    $g['credit'],
                    $g['memo'] !== '' ? $g['memo'] : null,
                ]);
            }
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
 * اعتماد السند: يطبّق الإضافة/الخصم على المخزون + طبقات FIFO، ثم يرحّل قيداً واحداً =
 * سطر(أسطر) المخزون التلقائية (مدين عند الزيادة/دائن عند النقص) + أسطر المعالجة المرنة،
 * بنوع «قيد تسوية مخزون» (entry_type=stock_adjustment) بعد التحقق من التوازن.
 *
 * @return array{voucher_id:int,total_value:float,queued:bool}
 */
function orange_stock_adjustment_voucher_approve(PDO $pdo, int $id, ?int $countryId = null): array
{
    if (! orange_journal_vouchers_ready($pdo)) {
        throw new RuntimeException('جدول السندات غير جاهز.');
    }
    $sv = orange_stock_adjustment_voucher_get($pdo, $id, $countryId);
    if ($sv === null) {
        throw new InvalidArgumentException('السند غير موجود.');
    }
    $header = $sv['header'];
    if ((string) ($header['status'] ?? '') === 'approved') {
        throw new InvalidArgumentException('السند معتمد مسبقاً.');
    }

    $warehouseId = (int) ($header['warehouse_id'] ?? 0);
    $documentDate = trim((string) ($header['document_date'] ?? ''));
    if ($warehouseId <= 0) {
        throw new RuntimeException('المستودع غير محدد.');
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = (int) ($header['country_id'] ?? 0);
        if ($countryId <= 0) {
            $countryId = orange_admin_settings_effective_country_id($pdo);
        }
    }

    $lines = $sv['lines'];
    if ($lines === []) {
        throw new InvalidArgumentException('السند بلا أسطر.');
    }

    $inventoryAccountId = orange_gl_account_id($pdo, 'inventory', $countryId);
    if ($inventoryAccountId <= 0) {
        throw new RuntimeException('حساب المخزون (inventory) غير مربوط في إعدادات GL.');
    }

    // تحقّق مسبق من أسطر الكميات.
    foreach ($lines as $ln) {
        if ((int) ($ln['delta'] ?? 0) === 0) {
            throw new InvalidArgumentException('سطر بلا كمية إضافة أو خصم.');
        }
    }

    // أسطر المعالجة المحاسبية (الكارت السفلي) — إلزامية عند الاعتماد.
    $treatment = orange_stock_adjustment_voucher_get_gl_lines($pdo, $id);
    if ($treatment === []) {
        throw new InvalidArgumentException('أضف أسطر المعالجة المحاسبية (مدين/دائن) في الكارت السفلي قبل الاعتماد.');
    }
    $treatmentDebit = 0.0;
    $treatmentCredit = 0.0;
    foreach ($treatment as $g) {
        $accId = (int) ($g['account_id'] ?? 0);
        if ($accId <= 0 || ! orange_accounts_account_is_posting_leaf($pdo, $accId)) {
            throw new InvalidArgumentException('سطر معالجة بحساب غير صالح (ورقة ترحيل).');
        }
        if ($accId === $inventoryAccountId) {
            throw new InvalidArgumentException('لا تختر حساب المخزون في أسطر المعالجة — يُضاف تلقائياً.');
        }
        $treatmentDebit += (float) ($g['debit'] ?? 0);
        $treatmentCredit += (float) ($g['credit'] ?? 0);
    }
    $treatmentDebit = round($treatmentDebit, 4);
    $treatmentCredit = round($treatmentCredit, 4);

    $voucherId = 0;
    $queued = false;
    $totalValue = 0.0;
    $ref = 'STK-ADJ-' . $id;
    $postingAt = ($documentDate !== '' ? $documentDate : date('Y-m-d')) . ' 17:00:00';

    $incValue = 0.0;
    $decValue = 0.0;

    $pdo->beginTransaction();
    try {
        $glLines = [];
        foreach ($lines as $ln) {
            $variantId = (int) $ln['variant_id'];
            $delta = (int) $ln['delta'];
            if ($variantId <= 0 || $delta === 0) {
                continue;
            }

            $stVar = $pdo->prepare('SELECT product_id FROM product_variants WHERE id = ? LIMIT 1');
            $stVar->execute([$variantId]);
            $productId = (int) ($stVar->fetchColumn() ?: 0);

            $stockChange = orange_warehouse_apply_variant_delta($pdo, $warehouseId, $variantId, $delta, 0);
            orange_stock_movement_insert($pdo, [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'type' => 'manual_adjustment',
                'qty' => abs($delta),
                'old_stock' => $stockChange['old'],
                'new_stock' => $stockChange['new'],
                'reason' => 'سند تعديل رصيد #' . $id,
                'reference' => $ref,
                'country_id' => $countryId > 0 ? $countryId : null,
                'warehouse_id' => $warehouseId,
            ]);

            $fallbackUnit = orange_inventory_reconciliation_variant_unit_cost($pdo, $variantId, $warehouseId);
            $lineValue = 0.0;
            if ($delta > 0) {
                $unitCost = orange_inventory_cost_layers_current_unit_cost($pdo, $warehouseId, $variantId);
                if ($unitCost <= 0) {
                    $unitCost = $fallbackUnit;
                }
                orange_inventory_cost_layer_add(
                    $pdo,
                    $warehouseId,
                    $variantId,
                    $delta,
                    round($unitCost, 5),
                    'adjust',
                    $id,
                    $countryId > 0 ? $countryId : null,
                    $postingAt,
                    'سند تعديل رصيد #' . $id
                );
                $lineValue = round($delta * round($unitCost, 5), 4);
            } else {
                $consume = orange_inventory_cost_layers_consume_fifo(
                    $pdo,
                    $warehouseId,
                    $variantId,
                    -$delta,
                    'stock_adj',
                    $id,
                    $postingAt
                );
                $cost = (float) $consume['cost'];
                $short = (int) ($consume['shortfall'] ?? 0);
                if ($short > 0) {
                    $cost += $short * $fallbackUnit;
                }
                $lineValue = -round($cost, 4);
            }
            $totalValue += $lineValue;
            if ($lineValue > 0) {
                $incValue += $lineValue;
            } elseif ($lineValue < 0) {
                $decValue += -$lineValue;
            }
        }
        $totalValue = round($totalValue, 4);
        $incValue = round($incValue, 4);
        $decValue = round($decValue, 4);

        // سطر(أسطر) المخزون التلقائية: زيادة ⇒ مدين المخزون، نقص ⇒ دائن المخزون (بقيمة طبقات FIFO).
        $invMemo = 'تسوية مخزون #' . $id . ' — حركة المخزون';
        if ($incValue >= 0.0001) {
            $glLines[] = ['account_id' => $inventoryAccountId, 'debit' => $incValue, 'credit' => 0.0, 'memo' => $invMemo];
        }
        if ($decValue >= 0.0001) {
            $glLines[] = ['account_id' => $inventoryAccountId, 'debit' => 0.0, 'credit' => $decValue, 'memo' => $invMemo];
        }

        // التحقق من توازن أسطر المعالجة مع قيمة حركة المخزون:
        // صافي (دائن − مدين) للمعالجة يجب أن يساوي صافي قيمة المخزون (زيادة − نقص).
        $requiredNet = round($incValue - $decValue, 4);
        $treatmentNet = round($treatmentCredit - $treatmentDebit, 4);
        if (abs($treatmentNet - $requiredNet) > 0.005) {
            throw new InvalidArgumentException(
                'أسطر المعالجة غير متوازنة مع قيمة المخزون. المطلوب صافي (دائن − مدين) = '
                . number_format($requiredNet, 4) . ' بينما المُدخَل = ' . number_format($treatmentNet, 4)
                . '. عدّل المبالغ في كارت المعالجة.'
            );
        }

        foreach ($treatment as $g) {
            $glLines[] = [
                'account_id' => (int) $g['account_id'],
                'debit' => round((float) $g['debit'], 4),
                'credit' => round((float) $g['credit'], 4),
                'memo' => ((string) ($g['memo'] ?? '')) !== '' ? (string) $g['memo'] : ('تسوية مخزون #' . $id),
            ];
        }

        if ($glLines !== []) {
            // تحقّق نهائي من اتزان القيد (مدين = دائن) قبل الترحيل.
            $sumD = 0.0;
            $sumC = 0.0;
            foreach ($glLines as $g) {
                $sumD += (float) $g['debit'];
                $sumC += (float) $g['credit'];
            }
            if (abs(round($sumD - $sumC, 4)) > 0.005) {
                throw new InvalidArgumentException(
                    'القيد غير متوازن: مجموع المدين ' . number_format($sumD, 4)
                    . ' ≠ مجموع الدائن ' . number_format($sumC, 4) . '.'
                );
            }

            $desc = 'قيد تسوية مخزون #' . $id;
            if (orange_gl_use_pending_queue($pdo)) {
                $pendingId = orange_gl_pending_enqueue_multi(
                    $pdo,
                    $glLines,
                    'stock_adj_' . $id,
                    $ref,
                    $postingAt,
                    $postingAt,
                    $desc,
                    'stock_adjustment'
                );
                if ($pendingId <= 0) {
                    throw new RuntimeException('تعذّر إدراج قيد السند في الطابور.');
                }
                $queued = true;
            } else {
                $voucherId = orange_voucher_post($pdo, [
                    'voucher_date' => $postingAt,
                    'description' => $desc,
                    'entry_type' => 'stock_adjustment',
                    'country_id' => $countryId,
                ], $glLines);
            }
        }

        $upd = $pdo->prepare(
            'UPDATE stock_adjustment_voucher SET status = \'approved\', journal_voucher_id = ?, approved_at = NOW()
             WHERE id = ? AND status = \'draft\''
        );
        $upd->execute([$voucherId > 0 ? $voucherId : null, $id]);
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('تعذّر اعتماد السند.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'voucher_id' => $voucherId,
        'total_value' => $totalValue,
        'queued' => $queued,
    ];
}

function orange_stock_adjustment_voucher_delete_draft(PDO $pdo, int $id, ?int $countryId = null): bool
{
    $sv = orange_stock_adjustment_voucher_get($pdo, $id, $countryId);
    if ($sv === null || (string) ($sv['header']['status'] ?? '') !== 'draft') {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM stock_adjustment_voucher WHERE id = ? AND status = \'draft\'');
    $st->execute([$id]);

    return $st->rowCount() > 0;
}
