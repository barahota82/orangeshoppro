<?php

declare(strict_types=1);

/**
 * وضع ضبط شكل طباعة السندات — **مؤقت فقط**.
 *
 * عند true: الزر مفعّل قبل الحفظ (معاينة بلا تسجيل في القاعدة).
 * عند false: §10 الأصلي — الزر معطّل حتى الحفظ.
 *
 * **لا يلغي** تنفيذ الطباعة الدائم (onclick + orangeAdminOpenPrintDialog في admin.js) —
 * انظر docs/archive/ORANGE_ADMIN_ACCOUNTING_REPORTS_STATUS.txt §9.1 مقابل §9.2.
 */
function orange_admin_voucher_print_tuning_mode(): bool
{
    return false;
}

/**
 * وضع ضبط شكل طباعة الفواتير/المردودات (شراء/بيع شركة/مردود شراء/مردود بيع) — **مؤقت فقط**.
 *
 * عند true: زر الطباعة مفعّل قبل الحفظ (معاينة بلا تسجيل) لضبط CSS/HTML.
 * عند false: السلوك الأصلي — الزر معطّل حتى يُحمَّل/يُحفظ المستند.
 *
 * **يُعاد إلى false** بعد اكتمال تنسيق طباعة الفواتير (خطوة واحدة: غيّر return true → false ثم push).
 */
function orange_admin_invoice_print_tuning_mode(): bool
{
    return true;
}
