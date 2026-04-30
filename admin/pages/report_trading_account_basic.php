<?php

declare(strict_types=1);

/**
 * زر التنقل «قائمة حسابات المتاجرة» يفتح هذا المسار.
 * الصفحة نفسها هي منطق `report_trading_account.php` بالكامل؛ لا تُترك فارغة؛
 * يُثبَّت $page هنا ليكون العنوان/البارامتر صحيحين حتى لو تغيّر سياق التضمين.
 */
$page = 'report_trading_account_basic';

require __DIR__ . '/report_trading_account.php';
