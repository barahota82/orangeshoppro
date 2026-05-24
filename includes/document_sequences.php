<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * تسلسل آمن مع عدة مستخدمين: صف واحد لكل scope، زيادة ذرّية عبر ON DUPLICATE KEY UPDATE.
 * عند تمرير countryId يُضاف لاحقة _c{N} للفصل بين الدول (§13.4).
 */
function orange_sequence_next(PDO $pdo, string $scope, ?int $countryId = null): int
{
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'document_sequences')) {
        throw new RuntimeException('جدول التسلسلات غير جاهز.');
    }
    $scope = preg_replace('/[^a-zA-Z0-9_\-]/', '', $scope);
    if ($scope === '') {
        throw new InvalidArgumentException('scope فارغ');
    }
    if ($countryId !== null && $countryId > 0) {
        $scope .= '_c' . (int) $countryId;
    }
    $pdo->prepare(
        'INSERT INTO document_sequences (scope, last_value) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE last_value = last_value + 1'
    )->execute([$scope]);
    $st = $pdo->prepare('SELECT last_value FROM document_sequences WHERE scope = ? LIMIT 1');
    $st->execute([$scope]);

    return (int) $st->fetchColumn();
}

/**
 * تنسيق فاتورة مبيعات INV-C / INV-O مع كود الدولة (§13.5.7.2).
 */
function orange_format_sales_invoice_number(PDO $pdo, string $kind, int $countryId): string
{
    require_once __DIR__ . '/countries.php';
    $kind = strtolower(trim($kind));
    $scope = $kind === 'online' ? 'sales_invoice_online' : 'sales_invoice_company';
    $prefix = $kind === 'online' ? 'INV-O' : 'INV-C';
    $code = 'KW';
    if ($countryId > 0) {
        $row = orange_country_row_by_id($pdo, $countryId, false);
        if (is_array($row)) {
            $code = orange_countries_normalize_code((string) ($row['code'] ?? 'KW'));
        }
    }
    $next = orange_sequence_next($pdo, $scope, $countryId > 0 ? $countryId : null);

    return $prefix . '-' . $code . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}

/**
 * §13.8 — تنسيق قديم INV-{code}-###### (بدون O/C) قبل ترحيل §13.5.7.2.
 */
function orange_invoice_number_is_legacy_format(string $invoiceNumber): bool
{
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') {
        return false;
    }
    if (str_starts_with($invoiceNumber, 'INV-O-') || str_starts_with($invoiceNumber, 'INV-C-')) {
        return false;
    }

    return preg_match('/^INV-[A-Z]{2,8}-\d{4,8}$/', $invoiceNumber) === 1;
}

/**
 * @param 'online'|'company' $kind
 */
function orange_invoice_number_migrate_legacy(string $invoiceNumber, string $kind): string
{
    $invoiceNumber = trim($invoiceNumber);
    if (!orange_invoice_number_is_legacy_format($invoiceNumber)) {
        return $invoiceNumber;
    }
    $tag = strtolower(trim($kind)) === 'company' ? 'C' : 'O';

    return preg_replace('/^INV-/', 'INV-' . $tag . '-', $invoiceNumber, 1) ?? $invoiceNumber;
}

/**
 * @param 'online'|'company' $kind
 */
function orange_order_invoice_number_kind(array $order): string
{
    return trim((string) ($order['order_source'] ?? 'website')) === 'company' ? 'company' : 'online';
}

/**
 * @param 'online'|'company' $kind
 */
function orange_order_migrate_legacy_invoice_number(PDO $pdo, int $orderId, string $invoiceNumber, string $kind): string
{
    $invoiceNumber = trim($invoiceNumber);
    if (!orange_invoice_number_is_legacy_format($invoiceNumber)) {
        return $invoiceNumber;
    }
    $migrated = orange_invoice_number_migrate_legacy($invoiceNumber, $kind);
    if ($migrated === $invoiceNumber) {
        return $invoiceNumber;
    }
    try {
        $pdo->prepare('UPDATE orders SET invoice_number = ? WHERE id = ?')->execute([$migrated, $orderId]);

        return $migrated;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] legacy invoice migrate order #' . $orderId . ': ' . $e->getMessage());
        }

        return $invoiceNumber;
    }
}

/**
 * §13.8 — ترحيل دفعة واحدة لأرقام INV- القديمة → INV-O / INV-C (idempotent).
 */
function orange_orders_migrate_legacy_invoice_numbers_v1(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'orders') || !orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        return 0;
    }
    require_once __DIR__ . '/schema_migrations.php';
    orange_schema_migrations_ensure_table($pdo);
    $marker = 'php_legacy_invoice_numbers_v1';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return 0;
    }

    $st = $pdo->query(
        "SELECT id, invoice_number, order_source, country_id
         FROM orders
         WHERE invoice_number IS NOT NULL
           AND invoice_number <> ''
           AND invoice_number LIKE 'INV-%'
           AND invoice_number NOT LIKE 'INV-O-%'
           AND invoice_number NOT LIKE 'INV-C-%'
         ORDER BY id ASC"
    );
    if (!$st) {
        return 0;
    }

    $updated = 0;
    $maxSerialByScope = [];
    $upd = $pdo->prepare('UPDATE orders SET invoice_number = ? WHERE id = ?');
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $orderId = (int) ($row['id'] ?? 0);
        $existing = trim((string) ($row['invoice_number'] ?? ''));
        if ($orderId <= 0 || !orange_invoice_number_is_legacy_format($existing)) {
            continue;
        }
        $kind = orange_order_invoice_number_kind($row);
        $migrated = orange_invoice_number_migrate_legacy($existing, $kind);
        if ($migrated === $existing) {
            continue;
        }
        try {
            $upd->execute([$migrated, $orderId]);
            $updated++;
            if (preg_match('/^INV-(O|C)-[A-Z]{2,8}-(\d+)$/', $migrated, $m) === 1) {
                $scopeKind = $m[1] === 'C' ? 'company' : 'online';
                $serial = (int) $m[2];
                $countryId = (int) ($row['country_id'] ?? 0);
                $scopeKey = $scopeKind . ':' . $countryId;
                $maxSerialByScope[$scopeKey] = max($maxSerialByScope[$scopeKey] ?? 0, $serial);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] legacy invoice batch migrate order #' . $orderId . ': ' . $e->getMessage());
            }
        }
    }

    if ($maxSerialByScope !== [] && orange_table_exists($pdo, 'document_sequences')) {
        foreach ($maxSerialByScope as $scopeKey => $maxSerial) {
            [$scopeKind, $countryIdRaw] = explode(':', (string) $scopeKey, 2);
            $countryId = (int) $countryIdRaw;
            $scope = $scopeKind === 'company' ? 'sales_invoice_company' : 'sales_invoice_online';
            if ($countryId > 0) {
                $scope .= '_c' . $countryId;
            }
            try {
                $pdo->prepare(
                    'INSERT INTO document_sequences (scope, last_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE last_value = GREATEST(last_value, VALUES(last_value))'
                )->execute([$scope, $maxSerial]);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] legacy invoice sequence sync ' . $scope . ': ' . $e->getMessage());
                }
            }
        }
    }

    $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
    $ins->execute([$marker]);

    return $updated;
}

/**
 * يخصّص INV-O للطلب إن لم يكن له رقم فاتورة بعد.
 */
function orange_order_assign_inv_o_if_needed(PDO $pdo, int $orderId, array $order): string
{
    if (!orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        return '';
    }
    $existing = trim((string) ($order['invoice_number'] ?? ''));
    if ($existing !== '') {
        if (orange_invoice_number_is_legacy_format($existing)) {
            return orange_order_migrate_legacy_invoice_number($pdo, $orderId, $existing, 'online');
        }

        return $existing;
    }
    $countryId = (int) ($order['country_id'] ?? 0);
    $formatted = orange_format_sales_invoice_number($pdo, 'online', $countryId);
    $pdo->prepare('UPDATE orders SET invoice_number = ? WHERE id = ?')->execute([$formatted, $orderId]);

    return $formatted;
}

/**
 * يخصّص INV-C لطلب شركة إن لم يكن له رقم فاتورة بعد.
 */
function orange_order_assign_inv_c_if_needed(PDO $pdo, int $orderId, array $order): string
{
    if (!orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        return '';
    }
    $existing = trim((string) ($order['invoice_number'] ?? ''));
    if ($existing !== '') {
        if (orange_invoice_number_is_legacy_format($existing)) {
            return orange_order_migrate_legacy_invoice_number($pdo, $orderId, $existing, 'company');
        }

        return $existing;
    }
    $countryId = (int) ($order['country_id'] ?? 0);
    $formatted = orange_format_sales_invoice_number($pdo, 'company', $countryId);
    $pdo->prepare('UPDATE orders SET invoice_number = ? WHERE id = ?')->execute([$formatted, $orderId]);

    return $formatted;
}
