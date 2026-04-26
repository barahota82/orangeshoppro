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

/**
 * تحويل إدخال المستخدم (يوم/شهر/سنة أو Y-m-d) إلى Y-m-d للاستعلامات والـ API.
 *
 * @return string فارغ إذا لم يُقبل الإدخال
 */
function orange_parse_admin_date_to_ymd(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if (! checkdate($mo, $d, $y)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $y < 1900 || $y > 2100) {
            return '';
        }
        if (! checkdate($mo, $d, $y)) {
            return '';
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    return '';
}

/**
 * تحويل تاريخ/وقت مُرسل من لوحة التحكم إلى Y-m-d H:i:s للتخزين.
 * سلسلة فارغة = التاريخ والوقت الحاليان. يقبل يوم/شهر/سنة أو Y-m-d مع وقت اختياري بعد مسافة.
 *
 * @return string|null الطبيعي، أو null إن وُجد نص غير فارغ وغير مقبول
 */
function orange_normalize_admin_posted_datetime(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return date('Y-m-d H:i:s');
    }
    $datePart = $raw;
    $timePart = '';
    if (preg_match('/\s/', $raw)) {
        $sp = preg_split('/\s+/', $raw, 2);
        $datePart = (string) ($sp[0] ?? '');
        $timePart = isset($sp[1]) ? trim((string) $sp[1]) : '';
    }
    $ymd = orange_parse_admin_date_to_ymd($datePart);
    if ($ymd === '') {
        return null;
    }
    if ($timePart !== '') {
        $ts = strtotime($ymd . ' ' . $timePart);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    return $ymd . ' 12:00:00';
}
