<?php

declare(strict_types=1);

require_once __DIR__ . '/upload_paths.php';
require_once __DIR__ . '/admin_time.php';
require_once __DIR__ . '/countries.php';

/**
 * @return array<string, string>
 */
function orange_company_document_type_labels(): array
{
    return [
        'contract' => 'عقد / اتفاق',
        'tax_invoice' => 'فاتورة / ضريبة',
        'purchase_doc' => 'مستند مشتريات',
        'bank' => 'بنك / مصرف',
        'correspondence' => 'مراسلات',
        'policy' => 'سياسة / إجراء داخلي',
        'hr' => 'موارد بشرية',
        'legal' => 'قانوني',
        'other' => 'أخرى',
    ];
}

/**
 * ربط اختياري بكيان في النظام (للدورة المستندية).
 *
 * @return array<string, string>
 */
function orange_company_document_entity_presets(): array
{
    return [
        '' => 'غير مربوط (أرشيف عام)',
        'orders' => 'طلب مبيعات',
        'purchases' => 'فاتورة شراء',
        'suppliers' => 'مورد',
        'customers' => 'عميل',
        'journal_vouchers' => 'سند قيد / يومية',
    ];
}

/**
 * Resolve country_id from a linked parent entity (orders/purchases/…).
 * Returns 0 when the parent has no country authority.
 */
function orange_company_document_parent_country_id(PDO $pdo, string $entityTable, string $entityId): int
{
    $entityTable = trim($entityTable);
    $entityId = trim($entityId);
    if ($entityTable === '' || $entityId === '' || !ctype_digit($entityId)) {
        return 0;
    }
    $id = (int) $entityId;
    if ($id <= 0) {
        return 0;
    }
    $allowed = array_keys(orange_company_document_entity_presets());
    if (!in_array($entityTable, $allowed, true) || $entityTable === '') {
        return 0;
    }
    if (!orange_table_exists($pdo, $entityTable) || !orange_table_has_column($pdo, $entityTable, 'country_id')) {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT country_id FROM `' . str_replace('`', '``', $entityTable) . '` WHERE id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $cid = (int) $st->fetchColumn();

    return $cid > 0 ? $cid : 0;
}

/**
 * Country ownership for a new upload: Current Country Context, with parent match when linked.
 *
 * @throws RuntimeException on missing context or cross-country parent link
 */
function orange_company_document_resolve_upload_country_id(
    PDO $pdo,
    string $entityTable,
    string $entityId
): int {
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx <= 0) {
        throw new RuntimeException('سياق الدولة مطلوب لرفع المستند');
    }
    $entityTable = trim($entityTable);
    $entityId = trim($entityId);
    if ($entityTable === '') {
        return $ctx;
    }
    $parentCid = orange_company_document_parent_country_id($pdo, $entityTable, $entityId);
    if ($parentCid <= 0) {
        throw new RuntimeException('تعذر تحديد دولة الكيان المرتبط');
    }
    if ($parentCid !== $ctx) {
        throw new RuntimeException('لا يمكن ربط مستند بكيان من دولة أخرى');
    }

    return $ctx;
}

/**
 * @param array<string, mixed> $row
 */
function orange_company_document_absolute_path(array $row): string
{
    $rel = (string) ($row['storage_path'] ?? '');
    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);

    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $rel;
}

/**
 * Assert document belongs to Current Country Context (list/download/delete isolation).
 *
 * @param array<string, mixed> $row
 */
function orange_company_document_assert_context_ownership(PDO $pdo, array $row): void
{
    $ctx = orange_admin_context_country_id($pdo);
    $docCid = (int) ($row['country_id'] ?? 0);
    if ($ctx <= 0 || $docCid <= 0 || $docCid !== $ctx) {
        throw new RuntimeException('المستند غير متاح في سياق الدولة الحالي');
    }
}
