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
    return true;
}
