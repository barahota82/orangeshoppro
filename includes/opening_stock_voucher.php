<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/acc10_schema.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/admin_time.php';
require_once __DIR__ . '/admin_settings_country.php';
require_once __DIR__ . '/opening_stock_lock.php';
require_once __DIR__ . '/inventory_reconciliation.php';

/**
 * سند أرصدة أول المدة المخزنية (Opening Stock Voucher).
 *
 * فلسفته: مستند بنمط سند القيد لكن للكميات — تختار المنتج (المتغيّر) بدل الحساب،
 * وتُدخل الكمية الافتتاحية بدل المبالغ. عند الاعتماد تُضبَط كمية كل متغيّر في المستودع
 * الافتراضي للدولة كرصيد افتتاحي (حركة opening_balance)، بلا تكلفة ولا قيد GL
 * (الجانب المالي يُعالَج في «أرصدة أول المدة المالية» المنفصلة). يُمنع الاعتماد إذا كان
 * إقفال الرصيد الافتتاحي مفعّلاً للدولة.
 */

function orange_opening_stock_voucher_ensure_schema(PDO $pdo): void
{
    orange_catalog_ensure_schema($pdo);

    if (! orange_table_exists($pdo, 'opening_stock_voucher')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE opening_stock_voucher (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                warehouse_id INT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'draft\',
                document_date DATE NULL DEFAULT NULL,
                notes VARCHAR(512) NULL DEFAULT NULL,
                country_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                approved_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_osv_wh (warehouse_id, status),
                KEY idx_osv_country (country_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('opening_stock_voucher');
    }

    if (! orange_table_exists($pdo, 'opening_stock_voucher_line')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE opening_stock_voucher_line (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                voucher_id INT UNSIGNED NOT NULL,
                variant_id INT UNSIGNED NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_osv_line_parent (voucher_id),
                KEY idx_osv_line_variant (variant_id),
                CONSTRAINT orange_fk_osv_line_parent FOREIGN KEY (voucher_id)
                    REFERENCES opening_stock_voucher (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('opening_stock_voucher_line');
    }

    if (orange_table_exists($pdo, 'opening_stock_voucher_line')
        && orange_table_exists($pdo, 'product_variants')
        && ! orange_schema_fk_exists($pdo, 'opening_stock_voucher_line', 'orange_fk_osv_line_variant')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE opening_stock_voucher_line
                ADD CONSTRAINT orange_fk_osv_line_variant FOREIGN KEY (variant_id)
                    REFERENCES product_variants (id)'
        );
    }
}

function orange_opening_stock_voucher_ready(PDO $pdo): bool
{
    orange_opening_stock_voucher_ensure_schema($pdo);

    return orange_table_exists($pdo, 'opening_stock_voucher')
        && orange_table_exists($pdo, 'opening_stock_voucher_line');
}

/**
 * معلومات متغيّر للعرض في منتقي السند (الرصيد الحالي في المستودع).
 *
 * @return array<string, mixed>|null
 */
function orange_opening_stock_variant_info(PDO $pdo, int $variantId, int $warehouseId): ?array
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
    ];
}

/**
 * تطبيع أسطر الإدخال (variant_id, quantity, note).
 *
 * @param list<array<string, mixed>> $linesIn
 * @return list<array<string, mixed>>
 */
function orange_opening_stock_voucher_normalize_lines(PDO $pdo, int $warehouseId, array $linesIn): array
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
        $quantity = max(0, (int) ($ln['quantity'] ?? 0));
        $seen[$variantId] = true;
        $info = orange_opening_stock_variant_info($pdo, $variantId, $warehouseId);
        if ($info === null) {
            continue;
        }
        $out[] = [
            'variant_id' => $variantId,
            'quantity' => $quantity,
            'note' => trim((string) ($ln['note'] ?? '')),
        ];
    }

    return $out;
}

/** رقم السند المتوقَّع (MAX(id)+1) ضمن الدولة — للعرض فقط. */
function orange_opening_stock_voucher_next_no(PDO $pdo, ?int $countryId = null): int
{
    if (! orange_opening_stock_voucher_ready($pdo)) {
        return 1;
    }

    return (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM opening_stock_voucher')->fetchColumn();
}

/**
 * السند الوحيد لرصيد افتتاحي مخزني ضمن الدولة (مثل رصيد أول المدة المالي — سند واحد فقط).
 *
 * @return array<string, mixed>|null
 */
function orange_opening_stock_voucher_get_singleton(PDO $pdo, ?int $countryId = null): ?array
{
    if (! orange_opening_stock_voucher_ready($pdo)) {
        return null;
    }
    require_once __DIR__ . '/warehouses.php';
    orange_inventory_normalize_null_country_ids($pdo, 'opening_stock_voucher');
    $sql = 'SELECT id FROM opening_stock_voucher WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0
        && orange_table_has_column($pdo, 'opening_stock_voucher', 'country_id')) {
        $sql .= ' AND country_id = ?';
        $params[] = $countryId;
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $id = (int) ($st->fetchColumn() ?: 0);
    if ($id <= 0) {
        return null;
    }

    return orange_opening_stock_voucher_get($pdo, $id, $countryId);
}

/** مرجع السند للعرض: OSV-رمز الدولة-رقم السند. */
function orange_opening_stock_voucher_reference(PDO $pdo, int $voucherId, ?int $countryId = null): string
{
    if ($voucherId <= 0) {
        return '';
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }
    $code = '';
    if ($countryId > 0 && function_exists('orange_country_row_by_id')) {
        $row = orange_country_row_by_id($pdo, $countryId, false);
        if ($row !== null) {
            $code = orange_countries_display_code((string) ($row['code'] ?? ''));
        }
    }
    if ($code === '') {
        $code = 'XX';
    }

    return 'OSV-' . $code . '-' . $voucherId;
}

/** معاينة مرجع سند جديد قبل الحفظ. */
function orange_opening_stock_voucher_reference_preview(PDO $pdo, ?int $countryId = null): string
{
    $next = orange_opening_stock_voucher_next_no($pdo, $countryId);

    return orange_opening_stock_voucher_reference($pdo, $next, $countryId);
}

/**
 * @return array<string, mixed>|null
 */
function orange_opening_stock_voucher_get(PDO $pdo, int $id, ?int $countryId = null): ?array
{
    if ($id <= 0 || ! orange_opening_stock_voucher_ready($pdo)) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM opening_stock_voucher WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        return null;
    }
    if ($countryId !== null && $countryId > 0) {
        $rowCid = (int) ($row['country_id'] ?? 0);
        if ($rowCid <= 0 || $rowCid !== $countryId) {
            return null;
        }
    }

    $warehouseId = (int) ($row['warehouse_id'] ?? 0);
    $stL = $pdo->prepare(
        'SELECT sl.id, sl.variant_id, sl.quantity, sl.note,
                pv.color, pv.size, p.name AS product_name, COALESCE(p.item_code, \'\') AS item_code
         FROM opening_stock_voucher_line sl
         INNER JOIN product_variants pv ON pv.id = sl.variant_id
         INNER JOIN products p ON p.id = pv.product_id
         WHERE sl.voucher_id = ?
         ORDER BY sl.id ASC'
    );
    $stL->execute([$id]);
    $rawLines = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lines = [];
    $totalQty = 0;
    foreach ($rawLines as $ln) {
        $variantId = (int) ($ln['variant_id'] ?? 0);
        $qty = (int) ($ln['quantity'] ?? 0);
        $totalQty += $qty;
        $lines[] = [
            'id' => (int) ($ln['id'] ?? 0),
            'variant_id' => $variantId,
            'product_name' => (string) ($ln['product_name'] ?? ''),
            'color' => (string) ($ln['color'] ?? ''),
            'size' => (string) ($ln['size'] ?? ''),
            'item_code' => (string) ($ln['item_code'] ?? ''),
            'quantity' => $qty,
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

    $recCountry = (int) ($row['country_id'] ?? 0);
    if ($recCountry <= 0 && $warehouseId > 0) {
        try {
            $recCountry = orange_warehouse_authority_country_id($pdo, $warehouseId);
        } catch (Throwable $e) {
            $recCountry = 0;
        }
    }
    $createdUnix = null;
    try {
        $createdUnix = orange_admin_time_mysql_timestamp_session_wall_to_unix(
            $pdo,
            isset($row['created_at']) ? (string) $row['created_at'] : null
        );
    } catch (Throwable $e) {
        $createdUnix = null;
    }
    $createdApi = orange_admin_time_api_instant_from_unix($pdo, $createdUnix, $recCountry, 'ar', 'datetime');
    $row['created_at_utc'] = $createdApi['utc'];
    $row['created_at_display'] = $createdApi['display'];
    $approvedRaw = trim((string) ($row['approved_at'] ?? ''));
    $row['approved_at_utc'] = $approvedRaw !== ''
        ? (static function () use ($approvedRaw): string {
            try {
                return orange_admin_time_parse_mysql_utc_datetime($approvedRaw)->format('c');
            } catch (Throwable $e) {
                return '';
            }
        })()
        : '';
    $row['approved_at_display'] = orange_admin_time_display_mysql_utc_for_record(
        $pdo,
        $approvedRaw !== '' ? $approvedRaw : null,
        $recCountry,
        'ar',
        'datetime'
    );

    return [
        'header' => $row,
        'warehouse_label' => $whLabel,
        'lines' => $lines,
        'total_qty' => $totalQty,
        'reference' => orange_opening_stock_voucher_reference($pdo, $id, $countryId),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function orange_opening_stock_voucher_list(PDO $pdo, ?int $countryId = null): array
{
    if (! orange_opening_stock_voucher_ready($pdo)) {
        return [];
    }
    require_once __DIR__ . '/warehouses.php';
    orange_inventory_normalize_null_country_ids($pdo, 'opening_stock_voucher');
    $sql = 'SELECT sv.*,
            (SELECT COUNT(*) FROM opening_stock_voucher_line sl WHERE sl.voucher_id = sv.id) AS line_count
            FROM opening_stock_voucher sv
            WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0
        && orange_table_has_column($pdo, 'opening_stock_voucher', 'country_id')) {
        $sql .= ' AND sv.country_id = ?';
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

/**
 * تنقّل بين السندات ضمن سياق الدولة.
 */
function orange_opening_stock_voucher_nav(PDO $pdo, string $where, int $currentId, ?int $countryId = null): int
{
    if (! orange_opening_stock_voucher_ready($pdo)) {
        return 0;
    }
    $scope = '';
    $params = [];
    if ($countryId !== null && $countryId > 0
        && orange_table_has_column($pdo, 'opening_stock_voucher', 'country_id')) {
        $scope = ' AND country_id = ?';
        $params[] = $countryId;
    }
    switch ($where) {
        case 'first':
            $sql = 'SELECT MIN(id) FROM opening_stock_voucher WHERE 1=1' . $scope;
            break;
        case 'last':
            $sql = 'SELECT MAX(id) FROM opening_stock_voucher WHERE 1=1' . $scope;
            break;
        case 'prev':
            $sql = 'SELECT MAX(id) FROM opening_stock_voucher WHERE id < ?' . $scope;
            array_unshift($params, $currentId);
            break;
        case 'next':
            $sql = 'SELECT MIN(id) FROM opening_stock_voucher WHERE id > ?' . $scope;
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
function orange_opening_stock_voucher_search(PDO $pdo, array $filters, ?int $countryId = null): array
{
    if (! orange_opening_stock_voucher_ready($pdo)) {
        return [];
    }
    $sql = 'SELECT sv.id, sv.document_date, sv.status, sv.notes,
            (SELECT COUNT(*) FROM opening_stock_voucher_line sl WHERE sl.voucher_id = sv.id) AS line_count
            FROM opening_stock_voucher sv WHERE 1=1';
    $params = [];
    if ($countryId !== null && $countryId > 0
        && orange_table_has_column($pdo, 'opening_stock_voucher', 'country_id')) {
        $sql .= ' AND sv.country_id = ?';
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
 * حفظ مسودة سند رصيد افتتاحي (لا يطبّق مخزوناً).
 *
 * @param array<string, mixed> $headerIn
 * @param list<array<string, mixed>> $linesIn
 */
function orange_opening_stock_voucher_save(PDO $pdo, array $headerIn, array $linesIn, ?int $countryId = null): int
{
    if (! orange_opening_stock_voucher_ready($pdo)) {
        throw new RuntimeException('جداول سند أرصدة أول المدة غير جاهزة.');
    }

    $id = (int) ($headerIn['id'] ?? 0);
    $documentDate = trim((string) ($headerIn['document_date'] ?? ''));
    $notes = trim((string) ($headerIn['notes'] ?? ''));

    $documentDate = orange_admin_time_date_only_normalize($documentDate);
    if ($documentDate === '') {
        throw new InvalidArgumentException('تاريخ السند مطلوب (يوم/شهر/سنة).');
    }

    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_settings_effective_country_id($pdo);
    }
    if ($countryId <= 0) {
        throw new InvalidArgumentException('admin_time_country_id_required');
    }

    // سند واحد لكل دولة — لا يُنشأ سند ثانٍ.
    if ($id <= 0) {
        $singleton = orange_opening_stock_voucher_get_singleton($pdo, $countryId);
        if ($singleton !== null) {
            $singletonId = (int) ($singleton['header']['id'] ?? 0);
            $singletonStatus = (string) ($singleton['header']['status'] ?? '');
            if ($singletonStatus === 'approved') {
                throw new InvalidArgumentException('يوجد سند رصيد افتتاحي معتمد — لا يُنشأ سند ثانٍ لهذه الدولة.');
            }
            if ($singletonId > 0) {
                $id = $singletonId;
            }
        }
    }

    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    if ($warehouseId <= 0) {
        throw new RuntimeException('لا يوجد مستودع افتراضي لهذه الدولة.');
    }
    orange_stock_movement_assert_country_matches_warehouse($pdo, $countryId, $warehouseId);

    $lines = orange_opening_stock_voucher_normalize_lines($pdo, $warehouseId, $linesIn);
    if ($lines === []) {
        throw new InvalidArgumentException('أضف صنفاً واحداً على الأقل بكمية افتتاحية.');
    }

    if ($id > 0) {
        $existing = orange_opening_stock_voucher_get($pdo, $id, $countryId);
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
                'UPDATE opening_stock_voucher SET warehouse_id = ?, document_date = ?, notes = ?, country_id = ?
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
                $chk = $pdo->prepare('SELECT status FROM opening_stock_voucher WHERE id = ? LIMIT 1');
                $chk->execute([$id]);
                if ((string) $chk->fetchColumn() !== 'draft') {
                    throw new RuntimeException('تعذّر تحديث السند.');
                }
            }
            $pdo->prepare('DELETE FROM opening_stock_voucher_line WHERE voucher_id = ?')->execute([$id]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO opening_stock_voucher (warehouse_id, status, document_date, notes, country_id, created_at)
                 VALUES (?, \'draft\', ?, ?, ?, ' . orange_admin_time_sql_from_unix() . ')'
            );
            $ins->execute([
                $warehouseId,
                $documentDate,
                $notes !== '' ? $notes : null,
                $countryId > 0 ? $countryId : null,
                orange_admin_time_unix_now(),
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $stLine = $pdo->prepare(
            'INSERT INTO opening_stock_voucher_line (voucher_id, variant_id, quantity, note)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($lines as $ln) {
            $note = trim((string) ($ln['note'] ?? ''));
            $stLine->execute([
                $id,
                (int) $ln['variant_id'],
                (int) $ln['quantity'],
                $note !== '' ? mb_substr($note, 0, 255) : null,
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
 * اعتماد السند: يضبط كمية كل متغيّر في المستودع كرصيد افتتاحي (opening_balance). كميات فقط — بلا تكلفة/قيد.
 *
 * @return array{voucher_id:int,total_qty:int,lines:int}
 */
function orange_opening_stock_voucher_approve(PDO $pdo, int $id, ?int $countryId = null): array
{
    $sv = orange_opening_stock_voucher_get($pdo, $id, $countryId);
    if ($sv === null) {
        throw new InvalidArgumentException('السند غير موجود.');
    }
    $header = $sv['header'];
    if ((string) ($header['status'] ?? '') === 'approved') {
        throw new InvalidArgumentException('السند معتمد مسبقاً.');
    }

    $warehouseId = (int) ($header['warehouse_id'] ?? 0);
    if ($warehouseId <= 0) {
        throw new RuntimeException('المستودع غير محدد.');
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = (int) ($header['country_id'] ?? 0);
        if ($countryId <= 0) {
            $countryId = orange_admin_settings_effective_country_id($pdo);
        }
    }

    if (orange_opening_stock_is_locked($pdo, $countryId > 0 ? $countryId : null)) {
        throw new RuntimeException('رصيد المخزون الافتتاحي مقفول لهذه الدولة — افتح الإقفال أولاً.');
    }

    $lines = $sv['lines'];
    if ($lines === []) {
        throw new InvalidArgumentException('السند بلا أسطر.');
    }

    $ref = 'OPEN-STK-' . $id;
    $totalQty = 0;

    $pdo->beginTransaction();
    try {
        foreach ($lines as $ln) {
            $variantId = (int) $ln['variant_id'];
            $qty = (int) $ln['quantity'];
            if ($variantId <= 0) {
                continue;
            }

            $stVar = $pdo->prepare('SELECT product_id FROM product_variants WHERE id = ? LIMIT 1');
            $stVar->execute([$variantId]);
            $productId = (int) ($stVar->fetchColumn() ?: 0);

            $stockChange = orange_warehouse_set_variant_quantity($pdo, $warehouseId, $variantId, $qty);
            orange_stock_movement_insert($pdo, [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'type' => 'opening_balance',
                'qty' => abs($stockChange['new'] - $stockChange['old']),
                'old_stock' => $stockChange['old'],
                'new_stock' => $stockChange['new'],
                'reason' => 'سند رصيد افتتاحي #' . $id,
                'reference' => $ref,
                'country_id' => $countryId > 0 ? $countryId : null,
                'warehouse_id' => $warehouseId,
            ]);
            $totalQty += $qty;
        }

        $upd = $pdo->prepare(
            'UPDATE opening_stock_voucher SET status = \'approved\', approved_at = ?
             WHERE id = ? AND status = \'draft\''
        );
        $upd->execute([orange_admin_time_utc_now_mysql(), $id]);
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
        'voucher_id' => $id,
        'total_qty' => $totalQty,
        'lines' => count($lines),
    ];
}

function orange_opening_stock_voucher_delete_draft(PDO $pdo, int $id, ?int $countryId = null): bool
{
    $sv = orange_opening_stock_voucher_get($pdo, $id, $countryId);
    if ($sv === null || (string) ($sv['header']['status'] ?? '') !== 'draft') {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM opening_stock_voucher WHERE id = ? AND status = \'draft\'');
    $st->execute([$id]);

    return $st->rowCount() > 0;
}
