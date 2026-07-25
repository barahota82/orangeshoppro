<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/edit_lock_schema.php';
require_once __DIR__ . '/admin_permissions.php';
require_once __DIR__ . '/journal_voucher.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/gl_settings.php';

/**
 * @return array<string, array{label_ar:string, resource:string, filter_group:string}>
 */
function orange_edit_lock_doc_kinds(): array
{
    return [
        'purchase' => ['label_ar' => 'فاتورة شراء', 'resource' => 'warehouse', 'filter_group' => 'warehouse'],
        'purchase_return' => ['label_ar' => 'مردود مشتريات', 'resource' => 'warehouse', 'filter_group' => 'warehouse'],
        'company_sales_invoice' => ['label_ar' => 'فاتورة مبيعات شركة', 'resource' => 'sales', 'filter_group' => 'sales'],
        'online_sales_invoice' => ['label_ar' => 'فاتورة أونلاين', 'resource' => 'sales', 'filter_group' => 'sales'],
        'sales_return' => ['label_ar' => 'مردود مبيعات', 'resource' => 'sales', 'filter_group' => 'sales'],
        'journal_voucher' => ['label_ar' => 'سند قيد', 'resource' => 'accounting', 'filter_group' => 'accounting'],
        'customer_receipt' => ['label_ar' => 'سند قبض عميل', 'resource' => 'partners', 'filter_group' => 'partners'],
        'supplier_payment' => ['label_ar' => 'سند صرف مورد', 'resource' => 'partners', 'filter_group' => 'partners'],
        'opening_balance' => ['label_ar' => 'رصيد افتتاحي', 'resource' => 'accounting', 'filter_group' => 'accounting'],
    ];
}

function orange_edit_lock_kind_label(string $kind): string
{
    $kinds = orange_edit_lock_doc_kinds();
    if (isset($kinds[$kind]['label_ar'])) {
        return $kinds[$kind]['label_ar'];
    }
    $gl = orange_gl_entry_type_label_ar($kind);
    if ($gl !== '' && $gl !== $kind) {
        return $gl;
    }

    return $kind;
}

function orange_edit_lock_kind_is_supported(string $kind): bool
{
    $kind = trim($kind);
    if ($kind === '') {
        return false;
    }
    if (isset(orange_edit_lock_doc_kinds()[$kind])) {
        return true;
    }

    return isset(orange_gl_entry_type_labels_map()[$kind]);
}

function orange_edit_lock_page_for_kind(string $kind): string
{
    $warehouse = ['purchase', 'purchase_return', 'purchase_receive'];
    $sales = ['company_sales_invoice', 'online_sales_invoice', 'sales_return', 'order_delivery_sale', 'order_delivery_cogs', 'order_return_sale', 'order_return_cogs'];
    if (in_array($kind, $warehouse, true)) {
        return $kind === 'purchase_return' ? 'purchase_returns' : 'purchases';
    }
    if (in_array($kind, $sales, true)) {
        if ($kind === 'company_sales_invoice') {
            return 'company_sales_invoice';
        }
        if ($kind === 'online_sales_invoice') {
            return 'online_sales_invoice';
        }

        return 'sales_returns';
    }
    if ($kind === 'customer_receipt') {
        return 'partner_customer_receipt';
    }
    if ($kind === 'supplier_payment') {
        return 'partner_supplier_payment';
    }
    if ($kind === 'opening_balance') {
        return 'opening_balances';
    }
    if ($kind === 'stock_adjustment') {
        return 'stock_adjustment_voucher';
    }

    return 'journal_entries';
}

function orange_edit_lock_resource_for_kind(string $kind): string
{
    return orange_admin_page_resource(orange_edit_lock_page_for_kind($kind));
}

/**
 * @param array{doc_kind:string,entity_id:int,country_id?:int|null,reference?:string,label_ar?:string,amount?:float|null,saved_at?:string,journal_voucher_id?:int|null} $row
 */
function orange_edit_lock_register(PDO $pdo, array $row): void
{
    orange_catalog_ensure_schema($pdo);
    orange_catalog_ensure_edit_lock_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_edit_lock_registry')) {
        return;
    }
    $kind = trim((string) ($row['doc_kind'] ?? ''));
    $entityId = (int) ($row['entity_id'] ?? 0);
    if ($kind === '' || $entityId <= 0) {
        return;
    }
    $countryId = isset($row['country_id']) ? (int) $row['country_id'] : 0;
    if ($countryId <= 0) {
        $countryId = null;
    }
    $reference = trim((string) ($row['reference'] ?? ''));
    $label = trim((string) ($row['label_ar'] ?? ''));
    if ($label === '') {
        $label = orange_edit_lock_kind_label($kind) . ' #' . $entityId;
    }
    $savedAt = trim((string) ($row['saved_at'] ?? ''));
    if ($savedAt === '') {
        $savedAt = date('Y-m-d H:i:s');
    }
    $amount = isset($row['amount']) ? (float) $row['amount'] : null;
    $vid = isset($row['journal_voucher_id']) ? (int) $row['journal_voucher_id'] : 0;
    $vid = $vid > 0 ? $vid : null;

    $sql = 'INSERT INTO orange_edit_lock_registry (
                doc_kind, entity_id, country_id, reference, label_ar, amount, saved_at, journal_voucher_id
            ) VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                reference = VALUES(reference),
                label_ar = VALUES(label_ar),
                amount = VALUES(amount),
                saved_at = VALUES(saved_at),
                journal_voucher_id = COALESCE(VALUES(journal_voucher_id), journal_voucher_id)';
    $pdo->prepare($sql)->execute([
        $kind,
        $entityId,
        $countryId,
        $reference,
        $label,
        $amount,
        $savedAt,
        $vid,
    ]);
}

function orange_edit_lock_is_locked(PDO $pdo, string $kind, int $entityId, ?int $countryId = null): bool
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_edit_lock_registry')) {
        return false;
    }
    $kind = trim($kind);
    if ($kind === '' || $entityId <= 0) {
        return false;
    }
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : null;
    if ($cid === null) {
        $st = $pdo->prepare(
            'SELECT is_locked FROM orange_edit_lock_registry
             WHERE doc_kind = ? AND entity_id = ? AND country_id IS NULL LIMIT 1'
        );
        $st->execute([$kind, $entityId]);
    } else {
        $st = $pdo->prepare(
            'SELECT is_locked FROM orange_edit_lock_registry
             WHERE doc_kind = ? AND entity_id = ? AND country_id = ? LIMIT 1'
        );
        $st->execute([$kind, $entityId, $cid]);
    }
    $v = $st->fetchColumn();

    return $v !== false && (int) $v === 1;
}

/**
 * قفل تلقائي على مستند مسجَّل (مثل قيد الإقفال YEC) — يُستدعى بعد الحفظ الناجح.
 * لا يتطلب صلاحية can_lock لأنه قفل تلقائي تابع لطبيعة المستند، لا إجراء مسؤول يدوي.
 */
function orange_edit_lock_force_lock(PDO $pdo, string $kind, int $entityId, ?int $countryId = null, ?int $adminId = null): void
{
    orange_catalog_ensure_schema($pdo);
    orange_catalog_ensure_edit_lock_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_edit_lock_registry')) {
        return;
    }
    $kind = trim($kind);
    if ($kind === '' || $entityId <= 0) {
        return;
    }
    $aid = ($adminId !== null && $adminId > 0) ? $adminId : null;
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : null;
    if ($cid === null) {
        $st = $pdo->prepare(
            'UPDATE orange_edit_lock_registry
             SET is_locked = 1, locked_at = ?, locked_by_admin_id = ?
             WHERE doc_kind = ? AND entity_id = ? AND (country_id IS NULL OR country_id = 0)'
        );
        require_once __DIR__ . '/admin_time.php';
        $st->execute([orange_admin_time_utc_now_mysql(), $aid, $kind, $entityId]);
    } else {
        $st = $pdo->prepare(
            'UPDATE orange_edit_lock_registry
             SET is_locked = 1, locked_at = ?, locked_by_admin_id = ?
             WHERE doc_kind = ? AND entity_id = ? AND country_id = ?'
        );
        require_once __DIR__ . '/admin_time.php';
        $st->execute([orange_admin_time_utc_now_mysql(), $aid, $kind, $entityId, $cid]);
    }
}

function orange_edit_lock_registry_row(PDO $pdo, string $kind, int $entityId, ?int $countryId = null): ?array
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_edit_lock_registry')) {
        return null;
    }
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : null;
    if ($cid === null) {
        $st = $pdo->prepare(
            'SELECT * FROM orange_edit_lock_registry
             WHERE doc_kind = ? AND entity_id = ? AND country_id IS NULL LIMIT 1'
        );
        $st->execute([$kind, $entityId]);
    } else {
        $st = $pdo->prepare(
            'SELECT * FROM orange_edit_lock_registry
             WHERE doc_kind = ? AND entity_id = ? AND country_id = ? LIMIT 1'
        );
        $st->execute([$kind, $entityId, $cid]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @param 'edit'|'delete' $mutation
 */
function orange_edit_lock_assert_may_mutate(PDO $pdo, array $admin, string $kind, int $entityId, string $mutation, ?int $countryId = null): void
{
    if (orange_admin_has_full_access($admin)) {
        return;
    }
    if (orange_edit_lock_is_locked($pdo, $kind, $entityId, $countryId)) {
        throw new RuntimeException(
            'المستند مقفول — فك القفل أولاً من «إقفال التعديلات» أو من شاشة المستند.'
        );
    }
    $page = orange_edit_lock_page_for_kind($kind);
    if (!orange_admin_may_page($admin, $pdo, $page, $mutation)) {
        throw new RuntimeException('لا تملك صلاحية ' . ($mutation === 'delete' ? 'حذف' : 'تعديل') . ' لهذا المستند.');
    }
}

function orange_admin_may_lock(array $admin, PDO $pdo, string $resourceOrPage): bool
{
    if (orange_admin_has_full_access($admin)) {
        return true;
    }
    $page = orange_admin_page_from_perm_key($resourceOrPage) ?? $resourceOrPage;
    if (!isset(orange_admin_permission_page_labels()[$page])) {
        $page = orange_edit_lock_page_for_kind($resourceOrPage);
    }

    return orange_admin_may_page($admin, $pdo, $page, 'lock');
}

function orange_admin_may_unlock(array $admin, PDO $pdo, string $resourceOrPage): bool
{
    if (orange_admin_has_full_access($admin)) {
        return true;
    }
    $page = orange_admin_page_from_perm_key($resourceOrPage) ?? $resourceOrPage;
    if (!isset(orange_admin_permission_page_labels()[$page])) {
        $page = orange_edit_lock_page_for_kind($resourceOrPage);
    }

    return orange_admin_may_page($admin, $pdo, $page, 'unlock');
}

/**
 * @param list<int> $registryIds
 * @return array{locked: list<int>, unlocked: list<int>, errors: list<string>}
 */
function orange_edit_lock_set_by_registry_ids(PDO $pdo, array $admin, array $registryIds, bool $lock): array
{
    orange_catalog_ensure_schema($pdo);
    $locked = [];
    $unlocked = [];
    $errors = [];
    $adminId = (int) ($admin['id'] ?? 0);
    foreach ($registryIds as $rawId) {
        $id = (int) $rawId;
        if ($id <= 0) {
            continue;
        }
        $st = $pdo->prepare('SELECT * FROM orange_edit_lock_registry WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $errors[] = 'سجل #' . $id . ' غير موجود';
            continue;
        }
        $resource = orange_edit_lock_page_for_kind((string) ($row['doc_kind'] ?? ''));
        if ($lock && !orange_admin_may_lock($admin, $pdo, $resource)) {
            $errors[] = 'لا صلاحية قفل: ' . ($row['reference'] ?? (string) $id);
            continue;
        }
        if (!$lock && !orange_admin_may_unlock($admin, $pdo, $resource)) {
            $errors[] = 'لا صلاحية فك قفل: ' . ($row['reference'] ?? (string) $id);
            continue;
        }
        $cid = isset($row['country_id']) ? (int) $row['country_id'] : 0;
        $docKindRow = (string) ($row['doc_kind'] ?? '');
        if ($cid > 0) {
            try {
                if ($docKindRow === 'opening_balance') {
                    $assertId = (int) ($row['journal_voucher_id'] ?? 0);
                    if ($assertId > 0) {
                        orange_admin_assert_entity_country($pdo, 'journal_vouchers', $assertId);
                    }
                } else {
                    orange_admin_assert_entity_country(
                        $pdo,
                        orange_edit_lock_table_for_kind($docKindRow),
                        (int) $row['entity_id']
                    );
                }
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
                continue;
            }
        }
        if ($lock) {
            require_once __DIR__ . '/admin_time.php';
            $pdo->prepare(
                'UPDATE orange_edit_lock_registry SET is_locked = 1, locked_at = ?, locked_by_admin_id = ? WHERE id = ?'
            )->execute([orange_admin_time_utc_now_mysql(), $adminId > 0 ? $adminId : null, $id]);
            audit_log(
                'edit_lock_lock',
                'قفل تعديل: ' . (string) ($row['doc_kind'] ?? '') . ' #' . (int) ($row['entity_id'] ?? 0)
                    . ' — ' . (string) ($row['reference'] ?? ''),
                'orange_edit_lock_registry',
                $id
            );
            $locked[] = $id;
        } else {
            $pdo->prepare(
                'UPDATE orange_edit_lock_registry SET is_locked = 0, locked_at = NULL, locked_by_admin_id = NULL WHERE id = ?'
            )->execute([$id]);
            audit_log(
                'edit_lock_unlock',
                'فك قفل تعديل: ' . (string) ($row['doc_kind'] ?? '') . ' #' . (int) ($row['entity_id'] ?? 0)
                    . ' — ' . (string) ($row['reference'] ?? ''),
                'orange_edit_lock_registry',
                $id
            );
            $unlocked[] = $id;
        }
    }

    return ['locked' => $locked, 'unlocked' => $unlocked, 'errors' => $errors];
}

function orange_edit_lock_table_for_kind(string $kind): string
{
    return match ($kind) {
        'purchase' => 'purchases',
        'purchase_return' => 'purchase_returns',
        'company_sales_invoice' => 'orders',
        'online_sales_invoice' => 'orders',
        'sales_return' => 'sales_returns',
        'journal_voucher' => 'journal_vouchers',
        'opening_balance' => 'journal_vouchers',
        default => 'journal_vouchers',
    };
}

/**
 * مزامنة مستندات الفترة إلى السجل (للعرض في الشاشة المركزية).
 *
 * @param list<string>|null $entryTypes فلتر أنواع القيد (من نوع اليومية) — null = بدون فلتر
 */
function orange_edit_lock_sync_period(PDO $pdo, ?string $dateFrom, ?string $dateTo, ?string $docKind, ?array $entryTypes = null): void
{
    orange_catalog_ensure_edit_lock_schema($pdo);
    $df = $dateFrom !== null && $dateFrom !== '' ? $dateFrom : null;
    $dt = $dateTo !== null && $dateTo !== '' ? $dateTo : null;
    $syncAll = $docKind === null || $docKind === '' || $docKind === 'all';

    if ($syncAll || $docKind === 'purchase') {
        if (orange_table_exists($pdo, 'purchases')) {
            orange_edit_lock_sync_purchases($pdo, $df, $dt);
        }
    }
    if ($syncAll || $docKind === 'purchase_return') {
        if (orange_table_exists($pdo, 'purchase_returns')) {
            orange_edit_lock_sync_purchase_returns($pdo, $df, $dt);
        }
    }
    if ($syncAll || $docKind === 'sales_return') {
        if (orange_table_exists($pdo, 'sales_returns')) {
            orange_edit_lock_sync_sales_returns($pdo, $df, $dt);
        }
    }
    if ($syncAll || $docKind === 'opening_balance' || $entryTypes !== null) {
        if (orange_table_exists($pdo, 'journal_vouchers')) {
            orange_edit_lock_sync_journal_vouchers($pdo, $df, $dt, $entryTypes);
        }
    }
}

/**
 * @param array<string,mixed> $row
 */
function orange_edit_lock_country_for_purchase_return(PDO $pdo, array $row): ?int
{
    $purchaseId = (int) ($row['purchase_id'] ?? 0);
    if ($purchaseId > 0 && orange_table_has_country_id($pdo, 'purchases')) {
        $st = $pdo->prepare('SELECT country_id FROM purchases WHERE id = ? LIMIT 1');
        $st->execute([$purchaseId]);
        $cid = (int) ($st->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    }
    $supplierId = (int) ($row['supplier_id'] ?? 0);
    if ($supplierId > 0 && orange_table_has_country_id($pdo, 'suppliers')) {
        $st = $pdo->prepare('SELECT country_id FROM suppliers WHERE id = ? LIMIT 1');
        $st->execute([$supplierId]);
        $cid = (int) ($st->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    }
    $ctx = orange_admin_context_country_id($pdo);

    return $ctx > 0 ? $ctx : null;
}

/**
 * @param array<string,mixed> $row
 */
function orange_edit_lock_country_for_sales_return(PDO $pdo, array $row): ?int
{
    $orderId = (int) ($row['order_id'] ?? 0);
    if ($orderId > 0 && orange_table_has_country_id($pdo, 'orders')) {
        $st = $pdo->prepare('SELECT country_id FROM orders WHERE id = ? LIMIT 1');
        $st->execute([$orderId]);
        $cid = (int) ($st->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    }
    $customerId = (int) ($row['customer_id'] ?? 0);
    if ($customerId > 0 && orange_table_has_country_id($pdo, 'customers')) {
        $st = $pdo->prepare('SELECT country_id FROM customers WHERE id = ? LIMIT 1');
        $st->execute([$customerId]);
        $cid = (int) ($st->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    }
    $ctx = orange_admin_context_country_id($pdo);

    return $ctx > 0 ? $ctx : null;
}

function orange_edit_lock_sync_purchases(PDO $pdo, ?string $df, ?string $dt): void
{
    $sql = 'SELECT p.id, p.country_id, p.total, p.created_at FROM purchases p WHERE 1=1';
    $params = [];
    $ctxCid = orange_admin_context_country_id($pdo);
    if ($ctxCid > 0 && orange_table_has_country_id($pdo, 'purchases')) {
        $sql .= ' AND p.country_id = ?';
        $params[] = $ctxCid;
    }
    if ($df !== null) {
        $sql .= ' AND p.created_at >= ?';
        $params[] = $df;
    }
    if ($dt !== null) {
        $sql .= ' AND p.created_at <= ?';
        $params[] = $dt;
    }
    $st = $params !== [] ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params !== []) {
        $st->execute($params);
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) ($row['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $cid = (int) ($row['country_id'] ?? 0);
        $vid = null;
        if (orange_journal_vouchers_ready($pdo)) {
            $v = orange_voucher_find_by_document($pdo, 'purchase', $pid, 'purchase', $cid > 0 ? $cid : null);
            if ($v !== null) {
                $vid = (int) ($v['id'] ?? 0);
            }
        }
        orange_edit_lock_register($pdo, [
            'doc_kind' => 'purchase',
            'entity_id' => $pid,
            'country_id' => $cid > 0 ? $cid : null,
            'reference' => 'PIN-' . $pid,
            'label_ar' => 'فاتورة شراء #' . $pid,
            'amount' => (float) ($row['total'] ?? 0),
            'saved_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
            'journal_voucher_id' => $vid,
        ]);
    }
}

function orange_edit_lock_sync_purchase_returns(PDO $pdo, ?string $df, ?string $dt): void
{
    $ctxCid = orange_admin_context_country_id($pdo);
    $sql = 'SELECT pr.id, pr.total, pr.created_at, pr.return_number, pr.purchase_id, pr.supplier_id
            FROM purchase_returns pr WHERE 1=1';
    $params = [];
    if ($df !== null) {
        $sql .= ' AND pr.created_at >= ?';
        $params[] = $df;
    }
    if ($dt !== null) {
        $sql .= ' AND pr.created_at <= ?';
        $params[] = $dt;
    }
    $st = $params !== [] ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params !== []) {
        $st->execute($params);
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $rid = (int) ($row['id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $cid = orange_edit_lock_country_for_purchase_return($pdo, $row);
        if ($ctxCid > 0 && ($cid === null || $cid !== $ctxCid)) {
            continue;
        }
        $ref = trim((string) ($row['return_number'] ?? ''));
        if ($ref === '') {
            $ref = 'PRTN-' . $rid;
        }
        orange_edit_lock_register($pdo, [
            'doc_kind' => 'purchase_return',
            'entity_id' => $rid,
            'country_id' => $cid,
            'reference' => $ref,
            'label_ar' => 'مردود مشتريات #' . $rid,
            'amount' => (float) ($row['total'] ?? 0),
            'saved_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }
}

function orange_edit_lock_sync_sales_returns(PDO $pdo, ?string $df, ?string $dt): void
{
    $ctxCid = orange_admin_context_country_id($pdo);
    $sql = 'SELECT sr.id, sr.total, sr.created_at, sr.return_number, sr.order_id, sr.customer_id
            FROM sales_returns sr WHERE 1=1';
    $params = [];
    if ($df !== null) {
        $sql .= ' AND sr.created_at >= ?';
        $params[] = $df;
    }
    if ($dt !== null) {
        $sql .= ' AND sr.created_at <= ?';
        $params[] = $dt;
    }
    $st = $params !== [] ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params !== []) {
        $st->execute($params);
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $rid = (int) ($row['id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $cid = orange_edit_lock_country_for_sales_return($pdo, $row);
        if ($ctxCid > 0 && ($cid === null || $cid !== $ctxCid)) {
            continue;
        }
        $ref = trim((string) ($row['return_number'] ?? ''));
        if ($ref === '') {
            $ref = 'SR-' . $rid;
        }
        orange_edit_lock_register($pdo, [
            'doc_kind' => 'sales_return',
            'entity_id' => $rid,
            'country_id' => $cid,
            'reference' => $ref,
            'label_ar' => 'مردود مبيعات #' . $rid,
            'amount' => (float) ($row['total'] ?? 0),
            'saved_at' => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }
}

function orange_edit_lock_sync_journal_vouchers(PDO $pdo, ?string $df, ?string $dt, ?array $entryTypes = null): void
{
    if (!orange_table_exists($pdo, 'journal_vouchers')) {
        return;
    }
    require_once __DIR__ . '/fiscal_years.php';
    $ctxCid = orange_admin_context_country_id($pdo);
    $sql = 'SELECT j.id, j.country_id, j.reference, j.description, j.voucher_date, j.entry_type, j.fiscal_year_id
            FROM journal_vouchers j
            WHERE (j.is_void IS NULL OR j.is_void = 0)';
    $params = [];
    if ($ctxCid > 0 && orange_table_has_country_id($pdo, 'journal_vouchers')) {
        $sql .= ' AND j.country_id = ?';
        $params[] = $ctxCid;
    }
    if ($df !== null) {
        $sql .= ' AND j.voucher_date >= ?';
        $params[] = $df;
    }
    if ($dt !== null) {
        $sql .= ' AND j.voucher_date <= ?';
        $params[] = $dt;
    }
    if ($entryTypes !== null && $entryTypes !== []) {
        if ($entryTypes === ['__orange_unmapped_jt__']) {
            return;
        }
        $ph = implode(',', array_fill(0, count($entryTypes), '?'));
        $sql .= " AND j.entry_type IN ($ph)";
        $params = array_merge($params, $entryTypes);
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $vid = (int) ($row['id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $et = trim((string) ($row['entry_type'] ?? ''));
        if ($et === '') {
            $et = 'manual';
        }
        $cid = (int) ($row['country_id'] ?? 0);
        if ($et === 'opening_balance') {
            $fyId = (int) ($row['fiscal_year_id'] ?? 0);
            if ($fyId <= 0) {
                continue;
            }
            orange_edit_lock_register($pdo, [
                'doc_kind' => 'opening_balance',
                'entity_id' => $fyId,
                'country_id' => $cid > 0 ? $cid : null,
                'reference' => orange_opening_balance_reference($pdo, $fyId, $cid > 0 ? $cid : null),
                'label_ar' => trim((string) ($row['description'] ?? '')) !== ''
                    ? trim((string) $row['description'])
                    : ('رصيد افتتاحي — سنة #' . $fyId),
                'saved_at' => (string) ($row['voucher_date'] ?? date('Y-m-d H:i:s')),
                'journal_voucher_id' => $vid,
            ]);
            continue;
        }
        $ref = trim((string) ($row['reference'] ?? ''));
        if ($ref === '') {
            $ref = 'JV-' . $vid;
        }
        $label = trim((string) ($row['description'] ?? ''));
        if ($label === '') {
            $label = orange_gl_entry_type_label_ar($et) . ' #' . $vid;
        }
        orange_edit_lock_register($pdo, [
            'doc_kind' => $et,
            'entity_id' => $vid,
            'country_id' => $cid > 0 ? $cid : null,
            'reference' => $ref,
            'label_ar' => $label,
            'saved_at' => (string) ($row['voucher_date'] ?? date('Y-m-d H:i:s')),
            'journal_voucher_id' => $vid,
        ]);
    }
}

/**
 * @param list<string>|null $entryTypes
 * @return list<array<string,mixed>>
 */
function orange_edit_lock_list(
    PDO $pdo,
    ?string $dateFrom,
    ?string $dateTo,
    ?string $docKind,
    ?string $lockFilter,
    ?array $entryTypes = null
): array {
    orange_catalog_ensure_edit_lock_schema($pdo);
    orange_edit_lock_sync_period($pdo, $dateFrom, $dateTo, $docKind, $entryTypes);
    if (!orange_table_exists($pdo, 'orange_edit_lock_registry')) {
        return [];
    }
    $sql = 'SELECT * FROM orange_edit_lock_registry WHERE 1=1';
    $params = [];
    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND saved_at >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== null && $dateTo !== '') {
        $sql .= ' AND saved_at <= ?';
        $params[] = $dateTo;
    }
    if ($docKind !== null && $docKind !== '' && $docKind !== 'all') {
        $sql .= ' AND doc_kind = ?';
        $params[] = $docKind;
    } elseif ($entryTypes !== null && $entryTypes !== []) {
        if ($entryTypes === ['__orange_unmapped_jt__']) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($entryTypes), '?'));
        $sql .= " AND doc_kind IN ($ph)";
        foreach ($entryTypes as $et) {
            $params[] = $et;
        }
    }
    if ($lockFilter === 'locked') {
        $sql .= ' AND is_locked = 1';
    } elseif ($lockFilter === 'open') {
        $sql .= ' AND is_locked = 0';
    }
    $ctxCid = orange_admin_context_country_id($pdo);
    if ($ctxCid > 0 && orange_table_has_column($pdo, 'orange_edit_lock_registry', 'country_id')) {
        $sql .= ' AND country_id = ?';
        $params[] = $ctxCid;
    }
    $sql .= ' ORDER BY saved_at DESC, id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $kind = (string) ($row['doc_kind'] ?? '');
        $row['kind_label'] = orange_edit_lock_kind_label($kind);
        $row['is_locked'] = (int) ($row['is_locked'] ?? 0) === 1;
        $rows[] = $row;
    }

    return $rows;
}

/**
 * @return array{lines: list<array<string,mixed>>, meta: array<string,mixed>}
 */
function orange_edit_lock_preview(PDO $pdo, int $registryId): array
{
    orange_catalog_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM orange_edit_lock_registry WHERE id = ? LIMIT 1');
    $st->execute([$registryId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('السجل غير موجود.');
    }
    $ctxCid = orange_admin_context_country_id($pdo);
    $rowCid = (int) ($row['country_id'] ?? 0);
    if ($ctxCid > 0 && orange_table_has_column($pdo, 'orange_edit_lock_registry', 'country_id')
        && $rowCid > 0 && $rowCid !== $ctxCid) {
        throw new RuntimeException('السجل لا يتبع الدولة المختارة في لوحة التحكم.');
    }
    $vid = (int) ($row['journal_voucher_id'] ?? 0);
    $lines = [];
    if ($vid > 0 && orange_journal_vouchers_ready($pdo)) {
        $lnSt = $pdo->prepare(
            'SELECT jl.line_no, jl.debit, jl.credit, jl.memo, a.code, a.name_ar
             FROM journal_lines jl
             INNER JOIN accounts a ON a.id = jl.account_id
             WHERE jl.voucher_id = ?
             ORDER BY jl.line_no ASC, jl.id ASC'
        );
        $lnSt->execute([$vid]);
        while ($ln = $lnSt->fetch(PDO::FETCH_ASSOC)) {
            $lines[] = [
                'line_no' => (int) ($ln['line_no'] ?? 0),
                'code' => (string) ($ln['code'] ?? ''),
                'name' => (string) ($ln['name_ar'] ?? ''),
                'debit' => number_format((float) ($ln['debit'] ?? 0), 2, '.', ''),
                'credit' => number_format((float) ($ln['credit'] ?? 0), 2, '.', ''),
                'memo' => (string) ($ln['memo'] ?? ''),
            ];
        }
    }

    return [
        'lines' => $lines,
        'meta' => [
            'id' => (int) ($row['id'] ?? 0),
            'doc_kind' => (string) ($row['doc_kind'] ?? ''),
            'entity_id' => (int) ($row['entity_id'] ?? 0),
            'reference' => (string) ($row['reference'] ?? ''),
            'label_ar' => (string) ($row['label_ar'] ?? ''),
            'is_locked' => (int) ($row['is_locked'] ?? 0) === 1,
            'saved_at' => (string) ($row['saved_at'] ?? ''),
        ],
    ];
}

/**
 * قفل كل السجلات المطابقة للفلتر (§8.2).
 *
 * @return array{locked: list<int>, errors: list<string>}
 */
function orange_edit_lock_set_filtered(
    PDO $pdo,
    array $admin,
    bool $lock,
    ?string $dateFrom,
    ?string $dateTo,
    ?string $docKind,
    ?array $entryTypes = null
): array {
    $rows = orange_edit_lock_list($pdo, $dateFrom, $dateTo, $docKind, $lock ? 'open' : 'locked', $entryTypes);
    $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    $result = orange_edit_lock_set_by_registry_ids($pdo, $admin, $ids, $lock);

    return [
        'locked' => $result['locked'],
        'unlocked' => $result['unlocked'],
        'errors' => $result['errors'],
    ];
}

/**
 * @return array{entry_types: list<string>|null, filter: array<string,mixed>}
 */
function orange_edit_lock_resolve_journal_type_filter(PDO $pdo, int $journalTypeId, bool $allMovements): array
{
    if ($allMovements || $journalTypeId <= 0) {
        return ['entry_types' => null, 'filter' => ['entry_type_mode' => 'all']];
    }
    require_once __DIR__ . '/journal_types.php';
    $mapped = orange_gl_entry_types_for_journal_type_id($pdo, $journalTypeId);
    if ($mapped === []) {
        return [
            'entry_types' => ['__orange_unmapped_jt__'],
            'filter' => ['entry_type_mode' => 'unmapped_journal_type', 'journal_type_id' => $journalTypeId],
        ];
    }

    return [
        'entry_types' => $mapped,
        'filter' => ['entry_type_mode' => 'mapped', 'journal_type_id' => $journalTypeId],
    ];
}

/**
 * @param array<string,mixed> $data
 * @return array{0:?string,1:?string,2:string,3:list<string>|null}
 */
function orange_edit_lock_filter_args_from_payload(PDO $pdo, array $data): array
{
    $df = trim((string) ($data['date_from'] ?? ''));
    $dt = trim((string) ($data['date_to'] ?? ''));
    $allMovements = !empty($data['all_movements']);
    $journalTypeId = (int) ($data['journal_type_id'] ?? 0);
    $resolved = orange_edit_lock_resolve_journal_type_filter($pdo, $journalTypeId, $allMovements);

    return [
        $df !== '' ? $df : null,
        $dt !== '' ? $dt : null,
        'all',
        $resolved['entry_types'],
    ];
}

function orange_edit_lock_log_mutation(PDO $pdo, string $kind, int $entityId, string $action): void
{
    audit_log(
        'edit_lock_' . $action,
        orange_edit_lock_kind_label($kind) . ' #' . $entityId . ' — ' . $action,
        orange_edit_lock_table_for_kind($kind),
        $entityId
    );
}

function orange_edit_lock_unregister(PDO $pdo, string $kind, int $entityId, ?int $countryId = null): void
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'orange_edit_lock_registry')) {
        return;
    }
    $cid = ($countryId !== null && $countryId > 0) ? $countryId : null;
    if ($cid === null) {
        $pdo->prepare(
            'DELETE FROM orange_edit_lock_registry WHERE doc_kind = ? AND entity_id = ? AND (country_id IS NULL OR country_id = 0)'
        )->execute([$kind, $entityId]);
    } else {
        $pdo->prepare(
            'DELETE FROM orange_edit_lock_registry WHERE doc_kind = ? AND entity_id = ? AND country_id = ?'
        )->execute([$kind, $entityId, $cid]);
    }
}

function orange_edit_lock_register_purchase(PDO $pdo, int $purchaseId, int $countryId, float $total, ?string $savedAt = null): void
{
    $vid = null;
    if (orange_journal_vouchers_ready($pdo)) {
        $v = orange_voucher_find_by_document(
            $pdo,
            'purchase',
            $purchaseId,
            'purchase',
            $countryId > 0 ? $countryId : null
        );
        if ($v !== null) {
            $vid = (int) ($v['id'] ?? 0);
        }
    }
    orange_edit_lock_register($pdo, [
        'doc_kind' => 'purchase',
        'entity_id' => $purchaseId,
        'country_id' => $countryId > 0 ? $countryId : null,
        'reference' => 'PIN-' . $purchaseId,
        'label_ar' => 'فاتورة شراء #' . $purchaseId,
        'amount' => $total,
        'saved_at' => $savedAt,
        'journal_voucher_id' => $vid,
    ]);
}

function orange_edit_lock_kind_for_entry_type(string $entryType): string
{
    $et = trim($entryType);
    if ($et === '') {
        return 'manual';
    }

    return $et;
}

/**
 * @param array<string,mixed> $voucherRow
 */
function orange_edit_lock_register_voucher(PDO $pdo, array $voucherRow): void
{
    $vid = (int) ($voucherRow['id'] ?? 0);
    if ($vid <= 0) {
        return;
    }
    $et = trim((string) ($voucherRow['entry_type'] ?? 'manual'));
    $kind = orange_edit_lock_kind_for_entry_type($et);
    if ($kind === 'opening_balance') {
        $fyId = (int) ($voucherRow['fiscal_year_id'] ?? 0);
        if ($fyId <= 0) {
            return;
        }
        orange_edit_lock_register_opening_balance(
            $pdo,
            $fyId,
            (int) ($voucherRow['country_id'] ?? 0) > 0 ? (int) $voucherRow['country_id'] : null,
            trim((string) ($voucherRow['description'] ?? '')),
            $vid,
            (string) ($voucherRow['voucher_date'] ?? date('Y-m-d H:i:s'))
        );

        return;
    }
    $cid = (int) ($voucherRow['country_id'] ?? 0);
    orange_edit_lock_register($pdo, [
        'doc_kind' => $kind,
        'entity_id' => $vid,
        'country_id' => $cid > 0 ? $cid : null,
        'reference' => trim((string) ($voucherRow['reference'] ?? '')) !== '' ? trim((string) $voucherRow['reference']) : ('JV-' . $vid),
        'label_ar' => trim((string) ($voucherRow['description'] ?? '')) !== '' ? trim((string) $voucherRow['description']) : ('سند #' . $vid),
        'saved_at' => (string) ($voucherRow['voucher_date'] ?? date('Y-m-d H:i:s')),
        'journal_voucher_id' => $vid,
    ]);
}

function orange_edit_lock_register_purchase_return(
    PDO $pdo,
    int $returnId,
    ?int $countryId,
    float $total,
    ?string $reference = null,
    ?string $savedAt = null
): void {
    $ref = $reference !== null && trim($reference) !== '' ? trim($reference) : ('PRTN-' . $returnId);
    orange_edit_lock_register($pdo, [
        'doc_kind' => 'purchase_return',
        'entity_id' => $returnId,
        'country_id' => ($countryId !== null && $countryId > 0) ? $countryId : null,
        'reference' => $ref,
        'label_ar' => 'مردود مشتريات #' . $returnId,
        'amount' => $total,
        'saved_at' => $savedAt,
    ]);
}

function orange_edit_lock_register_sales_return(
    PDO $pdo,
    int $returnId,
    ?int $countryId,
    float $total,
    ?string $reference = null,
    ?string $savedAt = null
): void {
    $ref = $reference !== null && trim($reference) !== '' ? trim($reference) : ('SR-' . $returnId);
    orange_edit_lock_register($pdo, [
        'doc_kind' => 'sales_return',
        'entity_id' => $returnId,
        'country_id' => ($countryId !== null && $countryId > 0) ? $countryId : null,
        'reference' => $ref,
        'label_ar' => 'مردود مبيعات #' . $returnId,
        'amount' => $total,
        'saved_at' => $savedAt,
    ]);
}

function orange_edit_lock_register_opening_balance(
    PDO $pdo,
    int $fiscalYearId,
    ?int $countryId,
    string $label,
    ?int $voucherId = null,
    ?string $savedAt = null
): void {
    require_once __DIR__ . '/fiscal_years.php';
    orange_edit_lock_register($pdo, [
        'doc_kind' => 'opening_balance',
        'entity_id' => $fiscalYearId,
        'country_id' => ($countryId !== null && $countryId > 0) ? $countryId : null,
        'reference' => orange_opening_balance_reference($pdo, $fiscalYearId, ($countryId !== null && $countryId > 0) ? $countryId : null),
        'label_ar' => trim($label) !== '' ? trim($label) : ('رصيد افتتاحي — سنة #' . $fiscalYearId),
        'saved_at' => $savedAt,
        'journal_voucher_id' => ($voucherId !== null && $voucherId > 0) ? $voucherId : null,
    ]);
}
