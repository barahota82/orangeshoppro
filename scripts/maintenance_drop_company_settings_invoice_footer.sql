-- ============================================================================
-- صيانة (هدّامة) — إزالة العمود القديم company_settings.invoice_footer
-- التاريخ: 2026-06-16
--
-- الخلفية: العمود invoice_footer (نص قانوني بلغة واحدة) استُبدل بعمودَي
-- invoice_footer_ar / invoice_footer_en (عربي/إنجليزي). الكود لم يعد يقرأ أو
-- يكتب العمود القديم بعد هذا التغيير، فيُزال نهائياً من القاعدة.
--
-- لا يُدمج هذا السكربت في includes/catalog_schema.php (سياسة: السكربتات الهدّامة
-- تبقى مرجعاً تُشغّل يدوياً). شغّله مرة واحدة على قاعدة المتجر المعنية بعد git pull.
--
-- آمن لإعادة التشغيل: يتحقق من وجود العمود قبل أي عملية.
-- ============================================================================

-- (1) أمان: رحّل أي نص قديم متبقٍّ في invoice_footer إلى invoice_footer_ar
--     حين يكون العربي فارغاً (حتى لا يُفقد نص قديم لم يُرحَّل بعد).
SET @has_old := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'company_settings'
      AND COLUMN_NAME = 'invoice_footer'
);
SET @has_ar := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'company_settings'
      AND COLUMN_NAME = 'invoice_footer_ar'
);
SET @sql := IF(
    @has_old = 1 AND @has_ar = 1,
    'UPDATE company_settings
        SET invoice_footer_ar = invoice_footer
      WHERE invoice_footer IS NOT NULL
        AND invoice_footer <> ''''
        AND (invoice_footer_ar IS NULL OR invoice_footer_ar = '''')',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- (2) إسقاط العمود القديم نهائياً.
SET @sql := IF(
    @has_old = 1,
    'ALTER TABLE company_settings DROP COLUMN invoice_footer',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- تحقق (اختياري):
-- SELECT id, country_id, invoice_footer_ar, invoice_footer_en FROM company_settings;
