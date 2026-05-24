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
 * يخصّص INV-O للطلب إن لم يكن له رقم فاتورة بعد.
 */
function orange_order_assign_inv_o_if_needed(PDO $pdo, int $orderId, array $order): string
{
    if (!orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        return '';
    }
    $existing = trim((string) ($order['invoice_number'] ?? ''));
    if ($existing !== '') {
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
        return $existing;
    }
    $countryId = (int) ($order['country_id'] ?? 0);
    $formatted = orange_format_sales_invoice_number($pdo, 'company', $countryId);
    $pdo->prepare('UPDATE orders SET invoice_number = ? WHERE id = ?')->execute([$formatted, $orderId]);

    return $formatted;
}
