-- orange: تحسينات إضافية (1) journal_entries + السنة المالية
--            (2) تنظيف stock_movements (القيود غالباً موجودة من الاستيراد)
--            (4) ربط orange_admin_audit_log بـ admins
--        (3) product_colorways → products — مؤجّل حسب طلبك
-- نفّذ بعد نسخة احتياطية؛ مرّة واحدة.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ===========================================================================
-- (1) journal_entries: سنة مالية صالحة أو NULL
-- ===========================================================================
UPDATE `journal_entries` je
LEFT JOIN `fiscal_years` fy ON fy.id = je.fiscal_year_id
SET je.fiscal_year_id = NULL
WHERE je.fiscal_year_id IS NOT NULL AND fy.id IS NULL;

ALTER TABLE `journal_entries`
  ADD CONSTRAINT `fk_journal_entries_fiscal_year`
  FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- فهرس للاستعلامات (آمن إن وُجد مسبقاً في MariaDB 10.5.2+ / MySQL 8)
CREATE INDEX IF NOT EXISTS `idx_journal_entries_fiscal_year` ON `journal_entries` (`fiscal_year_id`);

-- ===========================================================================
-- (2) stock_movements: إزالة مراجع يتيمة (product_id / variant_id قابلان لـ NULL)
--     جداولك الأصلية غالباً فيها بالفعل stock_movements_ibfk_1 / _2؛
--     لا نضيف قيوداً مكررة هنا — فقط تنظيف البيانات.
-- ===========================================================================
UPDATE `stock_movements` sm
LEFT JOIN `products` p ON p.id = sm.product_id
SET sm.product_id = NULL
WHERE sm.product_id IS NOT NULL AND p.id IS NULL;

UPDATE `stock_movements` sm
LEFT JOIN `product_variants` pv ON pv.id = sm.variant_id
SET sm.variant_id = NULL
WHERE sm.variant_id IS NOT NULL AND pv.id IS NULL;

-- ===========================================================================
-- (4) orange_admin_audit_log: admin_id يشير لمسؤول موجود أو NULL
-- ===========================================================================
UPDATE `orange_admin_audit_log` al
LEFT JOIN `admins` ad ON ad.id = al.admin_id
SET al.admin_id = NULL
WHERE al.admin_id IS NOT NULL AND ad.id IS NULL;

ALTER TABLE `orange_admin_audit_log`
  ADD CONSTRAINT `fk_orange_audit_admin`
  FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
-- إن فشل أحد البنود:
-- * CREATE INDEX IF NOT EXISTS: إن نسخة MySQL أقدم، احذف السطر أو أنشئ الفهرس يدوياً
--   بعد التأكد بعدم وجود idx_journal_entries_fiscal_year.
-- * ADD CONSTRAINT مكرر: القيد موجود — احذف سطر ALTER المناسب.
-- ===========================================================================
