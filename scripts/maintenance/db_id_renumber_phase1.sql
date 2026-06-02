-- Orange DB id renumber — Phase 1 (setup / storefront / countries / fiscal)
-- Decision (b): dense ids 1,2,3… per table.
-- Production path: git pull → orange_catalog_migrate_db_id_renumber_phase1_v84 (includes/db_id_renumber.php).
-- This file is a manual/SQL reference; live mapping uses row order id ASC on the server.
-- Source snapshot: scripts/orange_db.sql (2026-06-02)
--
-- Tables touched when gaps exist:
--   analytical_dimension (9,10 -> 3,4)
--   storefront_copy_lines (missing id 6; shift 7..17 down by 1)
-- Tables verified dense (no row updates): countries, fiscal_years,
--   orange_catalog_schema_checkpoint, storefront_home_hero, size_family_advisory_library_map

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1) analytical_dimension: 1,2,9,10 -> 1,2,3,4 (offset avoids unique collisions)
-- ---------------------------------------------------------------------------
UPDATE `analytical_dimension` SET `id` = `id` + 10000 WHERE `id` IN (9, 10);
UPDATE `analytical_dimension` SET `id` = 3 WHERE `id` = 10009;
UPDATE `analytical_dimension` SET `id` = 4 WHERE `id` = 10010;

UPDATE `analytical_dimension_value` SET `dimension_id` = 3 WHERE `dimension_id` = 9;
UPDATE `analytical_dimension_value` SET `dimension_id` = 4 WHERE `dimension_id` = 10;

-- ---------------------------------------------------------------------------
-- 2) storefront_copy_lines: close gap at id 6 (7..17 -> 6..16), high to low
-- ---------------------------------------------------------------------------
UPDATE `storefront_copy_lines` SET `id` = 16 WHERE `id` = 17;
UPDATE `storefront_copy_lines` SET `id` = 15 WHERE `id` = 16;
UPDATE `storefront_copy_lines` SET `id` = 14 WHERE `id` = 15;
UPDATE `storefront_copy_lines` SET `id` = 13 WHERE `id` = 14;
UPDATE `storefront_copy_lines` SET `id` = 12 WHERE `id` = 13;
UPDATE `storefront_copy_lines` SET `id` = 11 WHERE `id` = 12;
UPDATE `storefront_copy_lines` SET `id` = 10 WHERE `id` = 11;
UPDATE `storefront_copy_lines` SET `id` = 9 WHERE `id` = 10;
UPDATE `storefront_copy_lines` SET `id` = 8 WHERE `id` = 9;
UPDATE `storefront_copy_lines` SET `id` = 7 WHERE `id` = 8;
UPDATE `storefront_copy_lines` SET `id` = 6 WHERE `id` = 7;

-- ---------------------------------------------------------------------------
-- 3) AUTO_INCREMENT alignment
-- ---------------------------------------------------------------------------
SET @ad_max := (SELECT COALESCE(MAX(`id`), 0) FROM `analytical_dimension`);
SET @sql_ad := CONCAT('ALTER TABLE `analytical_dimension` AUTO_INCREMENT = ', @ad_max + 1);
PREPARE stmt_ad FROM @sql_ad;
EXECUTE stmt_ad;
DEALLOCATE PREPARE stmt_ad;

SET @scl_max := (SELECT COALESCE(MAX(`id`), 0) FROM `storefront_copy_lines`);
SET @sql_scl := CONCAT('ALTER TABLE `storefront_copy_lines` AUTO_INCREMENT = ', @scl_max + 1);
PREPARE stmt_scl FROM @sql_scl;
EXECUTE stmt_scl;
DEALLOCATE PREPARE stmt_scl;

SET @co_max := (SELECT COALESCE(MAX(`id`), 0) FROM `countries`);
SET @sql_co := CONCAT('ALTER TABLE `countries` AUTO_INCREMENT = ', @co_max + 1);
PREPARE stmt_co FROM @sql_co;
EXECUTE stmt_co;
DEALLOCATE PREPARE stmt_co;

SET @fy_max := (SELECT COALESCE(MAX(`id`), 0) FROM `fiscal_years`);
SET @sql_fy := CONCAT('ALTER TABLE `fiscal_years` AUTO_INCREMENT = ', @fy_max + 1);
PREPARE stmt_fy FROM @sql_fy;
EXECUTE stmt_fy;
DEALLOCATE PREPARE stmt_fy;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- Verification (manual):
-- SELECT id FROM analytical_dimension ORDER BY id;
-- SELECT id FROM storefront_copy_lines ORDER BY id;
-- SELECT MIN(id), MAX(id), COUNT(*) FROM storefront_copy_lines;
