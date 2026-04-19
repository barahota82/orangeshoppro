<?php

declare(strict_types=1);

require_once __DIR__ . '/upload_paths.php';

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
 * @param array<string, mixed> $row
 */
function orange_company_document_absolute_path(array $row): string
{
    $rel = (string) ($row['storage_path'] ?? '');
    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);

    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $rel;
}
