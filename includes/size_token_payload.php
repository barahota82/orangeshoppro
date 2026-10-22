<?php

declare(strict_types=1);

/**
 * خانة المقاس الواحدة تُكتب كما أُدخلت في أعمدة التوافق الأربعة.
 * لا تغيير للتهجئة، ولا تحويل XXL إلى 2XL.
 * صف بلا size_label يُبقى الأعمدة الأربعة كما وصلت، بما فيها الفراغات، بلا نسخ من الإنجليزية.
 *
 * @param array<string, mixed> $row
 * @return array{label_ar: string, label_en: string, label_fil: string, label_hi: string, unified: bool, empty: bool}
 */
function orange_size_token_columns(array $row): array
{
    $sizeLabel = array_key_exists('size_label', $row) ? trim((string) $row['size_label']) : '';
    if ($sizeLabel !== '') {
        return [
            'label_ar' => $sizeLabel,
            'label_en' => $sizeLabel,
            'label_fil' => $sizeLabel,
            'label_hi' => $sizeLabel,
            'unified' => true,
            'empty' => false,
        ];
    }

    $la = trim((string) ($row['label_ar'] ?? ''));
    $le = trim((string) ($row['label_en'] ?? ''));
    $lf = trim((string) ($row['label_fil'] ?? ''));
    $lh = trim((string) ($row['label_hi'] ?? ''));

    return [
        'label_ar' => $la,
        'label_en' => $le,
        'label_fil' => $lf,
        'label_hi' => $lh,
        'unified' => false,
        'empty' => ($la === '' && $le === '' && $lf === '' && $lh === ''),
    ];
}
