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

    // اختياري: مسار سجل العمليات الحرجة (JSON lines — فشل audit_log، إلخ). خارج جذر الموقع.
    // الافتراضي: مجلد orange_logs بجانب المشروع، مثلاً D:\orange_logs\operational.log
    // 'ORANGE_OPERATIONAL_LOG_PATH' => 'D:\\orange_logs\\operational.log',

    // اختياري — PR-SEC-02: حدود محاولات تسجيل دخول الأدمن (أساس DB؛ غير موصول بـ admin/login.php بعد).
    // 'ORANGE_ADMIN_LOGIN_MAX_ATTEMPTS_USERNAME' => 5,
    // 'ORANGE_ADMIN_LOGIN_MAX_ATTEMPTS_IP' => 30,
    // 'ORANGE_ADMIN_LOGIN_WINDOW_SECONDS' => 900,
    // 'ORANGE_ADMIN_LOGIN_LOCK_SECONDS' => 900,

    // اختياري: تقليل كاش HTML أثناء التطوير (1 أو true)
    // 'DISABLE_HTML_CACHE' => true,

    // اختياري: إجبار روابط طويلة /pages/... إذا الـ rewrite لا يعمل أو تظهر 404 متقطعة على IIS
    // 'STOREFRONT_FORCE_LONG_URLS' => true,

    // اختياري: بريد «من» لإرسال روابط تأكيد التسجيل (صفحة التسجيل بالإيميل). بدونها لن يُرسل البريد.
    // 'MAIL_FROM' => 'noreply@your-domain.com',
    // 'MAIL_FROM_NAME' => 'Orange',

    // اختياري: مفتاح سري لـ health.php — بدونه تُرجع الصفحة «OK» فقط بدون فحص DB (يمنع كشف حالة السيرفر للعامة).
    // عند التعيين: افتح /health.php?key=نفس_القيمة لرؤية تفاصيل الفحص.
    // 'HEALTH_CHECK_KEY' => 'غيّر_هذا_إلى_سلسلة_عشوائية_طويلة',

    // اختياري (قديم): رابط يدوي بـ ?sf_preview= — روابط الجدول في الأدمن تستخدم جلسة بدون إظهار سر في عنوان المتجر
    // 'ORANGE_STOREFRONT_PREVIEW_TOKEN' => 'غيّر_إلى_سلسلة_عشوائية_طويلة_لا_تشاركها_علناً',

    // اختياري: تجاوز Geo على جذر الموقع / للتطوير المحلي (kw، eg، …)
    // 'ORANGE_STOREFRONT_GEO_OVERRIDE' => 'kw',

    // اختياري — ترحيل المخطط (راجع docs/archive/ORANGE_STOREFRONT_PERFORMANCE_ROLLOUT.txt):
    // سلسلة صارمة 001.sql…NNN.sql مع تحديث orange_schema_meta بعد كل ملف (كل DDL في SQL). الافتراضي false.
    // 'ORANGE_STRICT_NUMBERED_SQL_MIGRATIONS' => true,
    // عند true: فشل البوابة يعرّف ORANGE_SCHEMA_DEGRADED بدلاً من إيقاف الطلب (سياسة المالك).
    // 'ORANGE_SCHEMA_CATCH_BOOTSTRAP_FAILURE' => true,
    // ثوانٍ APCu لتخطي بوابة الترحيل بعد نجاح (0 أو عدم التعيين = معطّل). متغير بيئة السيرفر ORANGE_SCHEMA_APCU_GATE_SECONDS
    // علم ملف اختياري: المسار في متغير بيئة ORANGE_SCHEMA_OK_FLAG_PATH؛ السطر الأول = رقم ORANGE_SCHEMA_CODE_VERSION بعد نشر ناجح.

    // اختياري: مسار النسخ الاحتياطي خارج جذر الموقع (Phase 1A — Full Disaster Backup).
    // يجب ألا يكون داخل httpdocs أو مجلد uploads. مثال: D:\orange_backups
    // 'ORANGE_BACKUP_ROOT' => 'D:\\orange_backups',

    // ==================================================================
    // بوابة الدفع الإلكتروني (المرحلة 2) — أسرار السيرفر فقط، لا تُرفع لـ Git.
    // معطّلة افتراضياً: بلا هذه المفاتيح + بلا تفعيل «الدفع بالبطاقة» per دولة = لا تعمل.
    // راجع docs/archive/ORANGE_ONLINE_PAYMENT_READINESS.txt
    // ------------------------------------------------------------------
    // المزوّد (افتراضي myfatoorah):
    // 'PAYMENT_GATEWAY_PROVIDER' => 'myfatoorah',
    // الوضع: test أو live (يحدّد BaseURL الافتراضي):
    // 'PAYMENT_GATEWAY_MODE' => 'test',
    // اختياري: تجاوز عنوان البوابة (وإلا يُشتق من الوضع):
    // 'PAYMENT_MYF_BASE_URL' => 'https://apitest.myfatoorah.com',
    // توكن API من لوحة MyFatoorah (test ثم live عند الإطلاق):
    // 'PAYMENT_MYF_TOKEN' => 'ضع_توكن_MyFatoorah',
    // سر التحقق من توقيع الـ webhook (إن وُفِّر من المزوّد) — طبقة منع تلاعب:
    // 'PAYMENT_MYF_WEBHOOK_SECRET' => 'سر_عشوائي_طويل',
    // ملاحظة: عنوان الـ webhook لتسجيله في لوحة المزوّد: https://نطاقك/api/payments/gateway-webhook.php
];
