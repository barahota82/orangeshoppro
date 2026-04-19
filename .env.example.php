<?php

/**
 * انسخ هذا الملف على السيرفر باسم `.env.php` (في جذر المشروع بجانب config.php).
 * لا ترفع `.env.php` إلى Git — هو في .gitignore.
 *
 * عدّل DB_USER و DB_PASS ليطابقا MySQL على الاستضافة.
 * اسم القاعدة يُعرَّف حالياً في config.php كـ DB_NAME (افتراضي: orange_db).
 */
return [
    'DB_USER' => 'ضع_اسم_مستخدم_MySQL',
    'DB_PASS' => 'ضع_كلمة_المرور',

    // اختياري: إن كان الموقع داخل مجلد فرعي في الرابط، مثل https://domain.com/shop/
    // 'PUBLIC_BASE_PATH' => 'shop',

    // اختياري: نسخة ثابتة لكل CSS/JS (مُفضّل بعد نشر واجهة — يحدّث كاش المتصفح/الـ CDN)
    // 'ASSET_VERSION' => '20260419',

    // اختياري: مرجع يدوي أو من الـ CI يظهر في /admin/api/system/deploy-check.php
    // 'ORANGE_BUILD_REF' => 'main-abc1234',

    // اختياري: تقليل كاش HTML أثناء التطوير (1 أو true)
    // 'DISABLE_HTML_CACHE' => true,

    // اختياري: إجبار روابط طويلة /pages/... إذا الـ rewrite لا يعمل
    // 'STOREFRONT_FORCE_LONG_URLS' => true,

    // اختياري: بريد «من» لإرسال روابط تأكيد التسجيل (صفحة التسجيل بالإيميل). بدونها لن يُرسل البريد.
    // 'MAIL_FROM' => 'noreply@your-domain.com',
    // 'MAIL_FROM_NAME' => 'Orange',
];
