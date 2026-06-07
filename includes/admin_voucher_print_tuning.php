<?php

declare(strict_types=1);

/**
 * وضع ضبط شكل طباعة السندات — معاينة window.print() بدون حفظ في القاعدة.
 *
 * الوضع الأساسي (§10): false — الطباعة بعد الحفظ فقط.
 * أثناء ضبط CSS/الترويسة: true — انظر docs/archive/ORANGE_ADMIN_ACCOUNTING_REPORTS_STATUS.txt §9.
 */
function orange_admin_voucher_print_tuning_mode(): bool
{
    return true;
}
