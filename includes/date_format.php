<?php

declare(strict_types=1);

/**
 * تنسيق التواريخ للعرض في الواجهة: يوم/شهر/سنة (d/m/Y).
 *
 * يقبل:
 * - Y-m-d أو بادئة Y-m-d من DATETIME/TIMESTAMP
 * - أي سلسلة يفهمها strtotime() (مع منطقة الزمن المضبوطة في config)
 *
 * @return string فارغ إذا لم يُمرَّر تاريخ، أو النص الأصلي إن فشل التحليل
 */
function orange_format_date_dmY(?string $value): string
{
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }
    $t = strtotime($value);

    return $t !== false ? date('d/m/Y', $t) : $value;
}

/**
 * تاريخ ووقت للعرض: يوم/شهر/سنة ساعة:دقيقة (حسب توقيت السيرفر كما خُزّن).
 */
function orange_format_datetime_dmY_hi(?string $value): string
{
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $t = strtotime($value);

    return $t !== false ? date('d/m/Y H:i', $t) : $value;
}
