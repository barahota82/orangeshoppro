-- Orange — مواءمة قاعدة موجودة مع includes/catalog_schema.php
-- استخدم على قاعدة فيها بيانات (ليس استيراد mysql-create-orange-database-full.sql من الصفر).
--
-- قبل التنفيذ: نسخة احتياطية كاملة (mysqldump).
-- غيّر اسم القاعدة في USE إن لزم (مثلاً orange_db).
--
-- يصلح:
--   • categories / subcategories: أطوال name_* و slug → VARCHAR(191)
--     (نفس منطق orange_catalog_ensure_schema في includes/catalog_schema.php؛ MODIFY عند الطول < 191)
--   • product_channels: إزالة قيود FK المكررة وتوحيد الاسم إلى orange_fk_pc_*
--   • product_channels: COLLATE utf8mb4_unicode_ci مثل ensure_schema
--
-- يتطلب MariaDB 10.5+ لـ DROP FOREIGN KEY IF EXISTS / DROP INDEX IF EXISTS.
--
-- بعد التنفيذ (إن لم تُشغَّل من قبل): راجع ترتيب الملفات في IBRAHIM_ORANGE_MASTER.txt
--   (day1-hardening → part2 → strong-integrity).

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `orange_db`;

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) فئات — مطابق لـ orange_catalog_ensure_schema()
-- ---------------------------------------------------------------------------
ALTER TABLE `categories`
  MODIFY COLUMN `name_en` VARCHAR(191) NULL DEFAULT NULL,
  MODIFY COLUMN `name_ar` VARCHAR(191) NULL DEFAULT NULL,
  MODIFY COLUMN `name_fil` VARCHAR(191) NULL DEFAULT NULL,
  MODIFY COLUMN `name_hi` VARCHAR(191) NULL DEFAULT NULL,
  MODIFY COLUMN `slug` VARCHAR(191) NOT NULL;

ALTER TABLE `subcategories`
  MODIFY COLUMN `name_ar` VARCHAR(191) NOT NULL,
  MODIFY COLUMN `name_en` VARCHAR(191) NULL DEFAULT NULL,
  MODIFY COLUMN `name_fil` VARCHAR(191) NULL DEFAULT NULL,
  MODIFY COLUMN `name_hi` VARCHAR(191) NULL DEFAULT NULL,
  MODIFY COLUMN `slug` VARCHAR(191) NOT NULL;

-- ---------------------------------------------------------------------------
-- 2) product_channels — إزالة كل FK المعروفة ثم إعادة زوج واحد
-- ---------------------------------------------------------------------------
ALTER TABLE `product_channels`
  DROP FOREIGN KEY IF EXISTS `fk_pc_orange_product`,
  DROP FOREIGN KEY IF EXISTS `fk_pc_orange_channel`,
  DROP FOREIGN KEY IF EXISTS `product_channels_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `product_channels_ibfk_2`,
  DROP FOREIGN KEY IF EXISTS `orange_fk_pc_product`,
  DROP FOREIGN KEY IF EXISTS `orange_fk_pc_channel`;

-- فهارس كانت مرتبطة بالقيود؛ نزيل أسماء شائعة ثم ننشئ فهرس channel_id صريحاً
ALTER TABLE `product_channels`
  DROP INDEX IF EXISTS `fk_pc_orange_channel`,
  DROP INDEX IF EXISTS `product_channels_ibfk_2`;

CREATE INDEX IF NOT EXISTS `idx_product_channels_channel_id` ON `product_channels` (`channel_id`);

ALTER TABLE `product_channels`
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `product_channels`
  ADD CONSTRAINT `orange_fk_pc_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `orange_fk_pc_channel`
    FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE;

COMMIT;
