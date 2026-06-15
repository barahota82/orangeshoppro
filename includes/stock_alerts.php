<?php

declare(strict_types=1);

/**
 * عتبة تنبيه «قارب على النفاذ» في الأدمن (س5 — مرجع الواجهة).
 * متغيرات بكمية مخزون ≤ هذا الرقم تُدرج في التنبيه (للمنتجات النشطة).
 *
 * تُضبط لكل دولة من «إعدادات الشركة» (company_settings.low_stock_threshold).
 * الافتراضي 3 عند غياب القاعدة/العمود/الصف أو قيمة غير صالحة (توافق رجعي).
 *
 * @param PDO|null $pdo       تمرير الاتصال يُفعّل القراءة من الإعدادات؛ بدونه تُرجع الافتراضي.
 * @param int|null $countryId دولة السياق (تُمرَّر لـ company_settings؛ null = دولة الأدمن الفعّالة).
 */
function orange_stock_low_alert_threshold(?PDO $pdo = null, ?int $countryId = null): int
{
    $default = 3;
    if (!$pdo instanceof PDO) {
        return $default;
    }
    try {
        require_once __DIR__ . '/company_settings.php';
        $row = orange_company_settings_row($pdo, $countryId, false);
        if (is_array($row) && array_key_exists('low_stock_threshold', $row)) {
            $value = (int) ($row['low_stock_threshold'] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }
    } catch (Throwable $e) {
        // أي تعذّر (قاعدة/عمود مفقود) يسقط على الافتراضي
    }

    return $default;
}

/**
 * عتبة إظهار «كمية محدودة» للعميل في صفحة المنتج (منفصلة عن تنبيه الأدمن — قرار المالك 2026-06-16).
 * إشارة تسويقية في الواجهة وليست حد إعادة الطلب الداخلي.
 *
 * تُضبط لكل دولة من «إعدادات الشركة» (company_settings.customer_low_stock_threshold).
 * الافتراضي 5 عند غياب القاعدة/العمود/الصف أو قيمة غير صالحة.
 *
 * @param PDO|null $pdo       تمرير الاتصال يُفعّل القراءة من الإعدادات؛ بدونه تُرجع الافتراضي.
 * @param int|null $countryId دولة الواجهة (تُمرَّر لـ company_settings).
 */
function orange_storefront_low_stock_display_threshold(?PDO $pdo = null, ?int $countryId = null): int
{
    $default = 5;
    if (!$pdo instanceof PDO) {
        return $default;
    }
    try {
        require_once __DIR__ . '/company_settings.php';
        $row = orange_company_settings_row($pdo, $countryId, false);
        if (is_array($row) && array_key_exists('customer_low_stock_threshold', $row)) {
            $value = (int) ($row['customer_low_stock_threshold'] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }
    } catch (Throwable $e) {
        // أي تعذّر يسقط على الافتراضي
    }

    return $default;
}
