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
    // علم ملف اختياري: السطر الأول = رقم ORANGE_SCHEMA_CODE_VERSION بعد نشر ناجح.
    // يمكن ضبطه هنا في .env.php أو كمتغير بيئة سيرفر ORANGE_SCHEMA_OK_FLAG_PATH.
    // 'ORANGE_SCHEMA_OK_FLAG_PATH' => 'D:\\orange_schema_ok.txt',

    // اختياري: Phase 1A — Full Disaster Backup (إلزامي على Plesk production).
    // أنشئ المجلد خارج httpdocs/مجلد الموقع. مثال Plesk:
    // 'ORANGE_BACKUP_ROOT' => 'C:\\inetpub\\vhosts\\clickstorekw.com\\private\\orange_backups',
    // 'ORANGE_MYSQLDUMP_PATH' => 'C:\\Program Files (x86)\\Plesk\\MySQL\\bin\\mysqldump.exe',
    // 'ORANGE_BACKUP_POWERSHELL_PATH' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
    // اختياري فقط: مسار مطلق لـ PHP CLI (php.exe وليس php-cgi). ليس مطلوباً من المالك.
    // الاكتشاف التلقائي: شقيق php.exe بجانب PHP_BINARY (php-cgi) أو PHP_BINDIR\\php.exe — بلا PATH/where.
    // 'ORANGE_PHP_CLI' => 'C:\\Program Files (x86)\\Plesk\\Additional\\PleskPHP83\\php.exe',
    // اختياري — مدة الاحتفاظ بالنسخ الاحتياطية (أيام). الافتراضي 30 عند غياب المفتاح.
    // 'ORANGE_BACKUP_RETENTION_DAYS' => 30,

    // Phase 2B.1 — Full Disaster Restore → STAGING (CLI only; server-only secrets).
    // Staging DB/user must differ from production DB_NAME / DB_USER. Staging user: zero production privileges.
    // Supported restore packages: manifest.export_backend=php_pdo only (see ORANGE_BACKUP_RECOVERY_RUNBOOK.md).
    // Optional Step-7 override only (auto job-bound shadow DB is default; never equals DB_NAME).
    // 'ORANGE_RESTORE_STAGING_DB' => 'orange_restore_staging',
    // 'ORANGE_RESTORE_SHADOW_DB' => 'orange_restore_shadow_override',
    // Optional dedicated staging user (≠ DB_USER). If omitted, Step-7 uses trusted app DB credentials for sibling shadow schema only.
    // 'ORANGE_RESTORE_STAGING_DB_USER' => 'orange_restore_staging',
    // 'ORANGE_RESTORE_STAGING_DB_PASS' => 'strong_staging_password',
    // 'ORANGE_RESTORE_WORK_DIR' => 'D:\\orange_backups\\restore_work',

    // Phase 2D.1 — Full Production Merge (ARCHITECTURE APPROVED — NOT IMPLEMENTED).
    // Dedicated production-merge MySQL user ONLY — never DB_USER / DB_PASS, never staging user.
    // Fail closed if missing or equal to DB_USER or ORANGE_RESTORE_STAGING_DB_USER.
    // 'ORANGE_RESTORE_MERGE_DB_USER' => 'orange_restore_merge',
    // 'ORANGE_RESTORE_MERGE_DB_PASS' => 'strong_merge_password',

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
