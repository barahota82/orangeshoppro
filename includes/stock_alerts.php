<?php

declare(strict_types=1);

/**
 * عتبة تنبيه «قارب على النفاذ» في الأدمن (س5 — مرجع الواجهة).
 * متغيرات بكمية مخزون ≤ هذا الرقم تُدرج في التنبيه (للمنتجات النشطة).
 */
function orange_stock_low_alert_threshold(): int
{
    return 3;
}
