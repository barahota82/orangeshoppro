-- orange: تحسينات إضافية — نفّذ بعد نسخة احتياطية؛ مرّة واحدة حيث يلزم.
--
-- (1) journal_entries — تراثي فقط. القرار والتفاصيل: IBRAHIM_ORANGE_MASTER.txt قسم (6).
--     يُنفَّذ فقط إن وُجد الجدول (قواعد من mysql-create الحالي بلا journal_entries تتخطاه تلقائياً).
-- (2) تنظيف stock_movements
-- (4) ربط orange_admin_audit_log بـ admins

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- ===========================================================================
-- (1) journal_entries: تصحيح fiscal_year_id اليتيم + FK (إن لم يكن موجوداً) + فهرس
-- ===========================================================================
DELIMITER $$

DROP PROCEDURE IF EXISTS `orange_legacy_journal_entries_hardening`$$

CREATE PROCEDURE `orange_legacy_journal_entries_hardening`()
orange_je: BEGIN
    DECLARE je_cnt INT DEFAULT 0;
    DECLARE fk_cnt INT DEFAULT 0;

    SELECT COUNT(*) INTO je_cnt
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'journal_entries';

    IF je_cnt = 0 THEN
        LEAVE orange_je;
    END IF;

    UPDATE `journal_entries` je
    LEFT JOIN `fiscal_years` fy ON fy.id = je.fiscal_year_id
    SET je.fiscal_year_id = NULL
    WHERE je.fiscal_year_id IS NOT NULL AND fy.id IS NULL;

    SELECT COUNT(*) INTO fk_cnt
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'journal_entries'
      AND constraint_name = 'fk_journal_entries_fiscal_year'
      AND constraint_type = 'FOREIGN KEY';

    IF fk_cnt = 0 THEN
        ALTER TABLE `journal_entries`
            ADD CONSTRAINT `fk_journal_entries_fiscal_year`
            FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`)
            ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;

    SET @__orange_idx := 'CREATE INDEX IF NOT EXISTS `idx_journal_entries_fiscal_year` ON `journal_entries` (`fiscal_year_id`)';
    PREPARE __orange_stmt FROM @__orange_idx;
    EXECUTE __orange_stmt;
    DEALLOCATE PREPARE __orange_stmt;
END$$

DELIMITER ;

CALL `orange_legacy_journal_entries_hardening`();
DROP PROCEDURE IF EXISTS `orange_legacy_journal_entries_hardening`;

-- ===========================================================================
-- (2) stock_movements: إزالة مراجع يتيمة
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
-- (4) orange_admin_audit_log
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
-- استكشاف الأخطاء:
-- * fk_orange_audit_admin مكرر: احذف سطر ALTER المناسب أو أسقط القيد القديم أولاً.
-- * CREATE INDEX IF NOT EXISTS: يتطلب MariaDB 10.5.2+ / إصدار MySQL يدعم الصيغة؛ وإلا أنشئ الفهرس يدوياً.
-- ===========================================================================
