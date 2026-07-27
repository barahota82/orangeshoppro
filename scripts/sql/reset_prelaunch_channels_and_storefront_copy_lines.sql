-- =============================================================================
-- Orange — Pre-launch SAFE reset: channels + product_channels + storefront_copy_lines
-- Owner runs manually once. Cursor must NOT execute this against Production.
-- Default = DRY RUN (no DELETE, no ALTER).
--
-- How to use:
--   1) Run this file as-is  -> Preflight only (FINAL_RESULT=DRY_RUN_ONLY)
--   2) If APPLY_ALLOWED=YES and you intend to reset, change ONLY:
--        SET @ORANGE_EXECUTE_RESET = 1;
--      keep confirmation exact, then re-run the whole file.
-- =============================================================================

SET NAMES utf8mb4;
SET @ORANGE_EXECUTE_RESET = 0;
SET @ORANGE_CONFIRM = 'RESET_PRELAUNCH_CHANNELS_AND_STOREFRONT_COPY_LINES';
SET @ORANGE_CONFIRM_EXPECTED = 'RESET_PRELAUNCH_CHANNELS_AND_STOREFRONT_COPY_LINES';

SET @DATABASE_NAME = DATABASE();
SET @EXECUTION_MODE = IF(@ORANGE_EXECUTE_RESET = 1, 'APPLY', 'DRY_RUN');

-- ---------------------------------------------------------------------------
-- Schema presence
-- ---------------------------------------------------------------------------
SET @has_channels = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels'
);
SET @has_product_channels = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_channels'
);
SET @has_copy_lines = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_copy_lines'
);
SET @has_orders = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
);
SET @has_sales_returns = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_returns'
);
SET @has_accounts = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_accounts'
);
SET @has_merge = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_phone_merge_requests'
);
SET @has_legacy_hero = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_home_hero'
);
SET @has_products = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
);
SET @has_promo = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_promo_messages'
);

SET @col_orders_channel = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'channel_id'
);
SET @col_sr_channel = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_returns' AND COLUMN_NAME = 'channel_id'
);
SET @col_acc_slug = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_accounts' AND COLUMN_NAME = 'registered_channel_slug'
);
SET @col_merge_slug = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_phone_merge_requests' AND COLUMN_NAME = 'proposed_channel_slug'
);
SET @col_copy_scope = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_copy_lines' AND COLUMN_NAME = 'scope'
);

-- ---------------------------------------------------------------------------
-- Preflight counts (0 when table missing)
-- ---------------------------------------------------------------------------
SET @CHANNELS_BEFORE = IF(@has_channels = 1, (SELECT COUNT(*) FROM channels), -1);
SET @PRODUCT_CHANNELS_BEFORE = IF(@has_product_channels = 1, (SELECT COUNT(*) FROM product_channels), -1);
SET @COPY_LINES_BEFORE = IF(@has_copy_lines = 1, (SELECT COUNT(*) FROM storefront_copy_lines), -1);
SET @COPY_HEADER_BEFORE = IF(@has_copy_lines = 1 AND @col_copy_scope = 1,
  (SELECT COUNT(*) FROM storefront_copy_lines WHERE scope = 'header_tagline'), -1);
SET @COPY_HERO_BEFORE = IF(@has_copy_lines = 1 AND @col_copy_scope = 1,
  (SELECT COUNT(*) FROM storefront_copy_lines WHERE scope = 'home_hero'), -1);
SET @COPY_OTHER_BEFORE = IF(@has_copy_lines = 1 AND @col_copy_scope = 1,
  (SELECT COUNT(*) FROM storefront_copy_lines WHERE scope NOT IN ('header_tagline','home_hero')), -1);

SET @CHANNELS_AI_BEFORE = (
  SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels' LIMIT 1
);
SET @COPY_AI_BEFORE = (
  SELECT AUTO_INCREMENT FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_copy_lines' LIMIT 1
);

SET @ORDERS_WITH_CHANNEL = IF(@has_orders = 1 AND @col_orders_channel = 1,
  (SELECT COUNT(*) FROM orders WHERE channel_id IS NOT NULL AND channel_id > 0), 0);
SET @SR_WITH_CHANNEL = IF(@has_sales_returns = 1 AND @col_sr_channel = 1,
  (SELECT COUNT(*) FROM sales_returns WHERE channel_id IS NOT NULL AND channel_id > 0), 0);

SET @SOFT_ACCOUNTS = IF(@has_accounts = 1 AND @col_acc_slug = 1,
  (SELECT COUNT(*) FROM storefront_accounts
   WHERE registered_channel_slug IS NOT NULL AND TRIM(registered_channel_slug) <> ''), 0);
SET @SOFT_MERGE = IF(@has_merge = 1 AND @col_merge_slug = 1,
  (SELECT COUNT(*) FROM storefront_phone_merge_requests
   WHERE proposed_channel_slug IS NOT NULL AND TRIM(proposed_channel_slug) <> ''), 0);

SET @ORDERS_ALL_BEFORE = IF(@has_orders = 1, (SELECT COUNT(*) FROM orders), 0);
SET @SR_ALL_BEFORE = IF(@has_sales_returns = 1, (SELECT COUNT(*) FROM sales_returns), 0);
SET @PRODUCTS_ALL_BEFORE = IF(@has_products = 1, (SELECT COUNT(*) FROM products), 0);
SET @PROMO_ALL_BEFORE = IF(@has_promo = 1, (SELECT COUNT(*) FROM storefront_promo_messages), 0);
SET @LEGACY_ROWS = IF(@has_legacy_hero = 1, (SELECT COUNT(*) FROM storefront_home_hero), 0);

-- Legacy visible text (known columns from orange_db.sql)
SET @LEGACY_VISIBLE = 0;
SET @sql_legacy = IF(
  @has_legacy_hero = 1,
  'SELECT COUNT(*) INTO @LEGACY_VISIBLE FROM storefront_home_hero WHERE
     TRIM(COALESCE(line_1_ar,'''')) <> '''' OR TRIM(COALESCE(line_1_en,'''')) <> '''' OR
     TRIM(COALESCE(line_1_fil,'''')) <> '''' OR TRIM(COALESCE(line_1_hi,'''')) <> '''' OR
     TRIM(COALESCE(line_2_ar,'''')) <> '''' OR TRIM(COALESCE(line_2_en,'''')) <> '''' OR
     TRIM(COALESCE(line_2_fil,'''')) <> '''' OR TRIM(COALESCE(line_2_hi,'''')) <> '''' OR
     TRIM(COALESCE(line_3_ar,'''')) <> '''' OR TRIM(COALESCE(line_3_en,'''')) <> '''' OR
     TRIM(COALESCE(line_3_fil,'''')) <> '''' OR TRIM(COALESCE(line_3_hi,'''')) <> '''' OR
     TRIM(COALESCE(header_tagline_ar,'''')) <> '''' OR TRIM(COALESCE(header_tagline_en,'''')) <> '''' OR
     TRIM(COALESCE(header_tagline_fil,'''')) <> '''' OR TRIM(COALESCE(header_tagline_hi,'''')) <> ''''',
  'SELECT 0 INTO @LEGACY_VISIBLE'
);
PREPARE stmt_legacy FROM @sql_legacy;
EXECUTE stmt_legacy;
DEALLOCATE PREPARE stmt_legacy;

-- Unknown FK children of channels.id (anything other than known tables)
SET @UNKNOWN_FK_CHILDREN = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
    AND REFERENCED_TABLE_NAME = 'channels'
    AND REFERENCED_COLUMN_NAME = 'id'
    AND TABLE_NAME NOT IN ('product_channels', 'orders', 'sales_returns')
);

-- ---------------------------------------------------------------------------
-- Guards / APPLY_ALLOWED
-- ---------------------------------------------------------------------------
SET @BLOCK_REASON = '';
SET @APPLY_ALLOWED = 1;

SET @schema_ok = (
  @has_channels = 1 AND @has_product_channels = 1 AND @has_copy_lines = 1 AND @col_copy_scope = 1
);
SET @APPLY_ALLOWED = IF(@schema_ok = 1, 1, 0);
SET @BLOCK_REASON = IF(@schema_ok = 1, '', 'MISSING_EXPECTED_TABLE_OR_COLUMN');

SET @APPLY_ALLOWED = IF(@ORDERS_WITH_CHANNEL > 0, 0, @APPLY_ALLOWED);
SET @BLOCK_REASON = IF(@ORDERS_WITH_CHANNEL > 0 AND @BLOCK_REASON = '', 'BUSINESS_ORDERS_CHANNEL', @BLOCK_REASON);

SET @APPLY_ALLOWED = IF(@SR_WITH_CHANNEL > 0, 0, @APPLY_ALLOWED);
SET @BLOCK_REASON = IF(@SR_WITH_CHANNEL > 0 AND @BLOCK_REASON = '', 'BUSINESS_SALES_RETURNS_CHANNEL', @BLOCK_REASON);

SET @APPLY_ALLOWED = IF(@SOFT_ACCOUNTS > 0, 0, @APPLY_ALLOWED);
SET @BLOCK_REASON = IF(@SOFT_ACCOUNTS > 0 AND @BLOCK_REASON = '', 'SOFT_REF_STOREFRONT_ACCOUNTS', @BLOCK_REASON);

SET @APPLY_ALLOWED = IF(@SOFT_MERGE > 0, 0, @APPLY_ALLOWED);
SET @BLOCK_REASON = IF(@SOFT_MERGE > 0 AND @BLOCK_REASON = '', 'SOFT_REF_MERGE_REQUESTS', @BLOCK_REASON);

SET @APPLY_ALLOWED = IF(@COPY_OTHER_BEFORE > 0, 0, @APPLY_ALLOWED);
SET @BLOCK_REASON = IF(@COPY_OTHER_BEFORE > 0 AND @BLOCK_REASON = '', 'COPY_LINES_OTHER_SCOPE', @BLOCK_REASON);

SET @APPLY_ALLOWED = IF(@UNKNOWN_FK_CHILDREN > 0, 0, @APPLY_ALLOWED);
SET @BLOCK_REASON = IF(@UNKNOWN_FK_CHILDREN > 0 AND @BLOCK_REASON = '', 'UNKNOWN_FK_CHILD', @BLOCK_REASON);

SET @APPLY_ALLOWED = IF(@LEGACY_VISIBLE > 0, 0, @APPLY_ALLOWED);
SET @BLOCK_REASON = IF(@LEGACY_VISIBLE > 0 AND @BLOCK_REASON = '', 'LEGACY_HERO_FALLBACK_BLOCKER', @BLOCK_REASON);

SET @CONFIRM_OK = IF(@ORANGE_CONFIRM = @ORANGE_CONFIRM_EXPECTED, 1, 0);

-- Preflight report (always)
SELECT
  @DATABASE_NAME AS DATABASE_NAME,
  @EXECUTION_MODE AS EXECUTION_MODE,
  @CHANNELS_BEFORE AS CHANNELS_BEFORE,
  @CHANNELS_AI_BEFORE AS CHANNELS_AI_BEFORE,
  @PRODUCT_CHANNELS_BEFORE AS PRODUCT_CHANNELS_BEFORE,
  @COPY_LINES_BEFORE AS COPY_LINES_BEFORE,
  @COPY_HEADER_BEFORE AS COPY_LINES_HEADER,
  @COPY_HERO_BEFORE AS COPY_LINES_HERO,
  @COPY_OTHER_BEFORE AS COPY_LINES_OTHER_SCOPES,
  @COPY_AI_BEFORE AS COPY_LINES_AI_BEFORE,
  @ORDERS_WITH_CHANNEL AS BUSINESS_ORDERS_CHANNEL,
  @SR_WITH_CHANNEL AS BUSINESS_SALES_RETURNS_CHANNEL,
  @SOFT_ACCOUNTS AS SOFT_REF_STOREFRONT_ACCOUNTS,
  @SOFT_MERGE AS SOFT_REF_MERGE_REQUESTS,
  @UNKNOWN_FK_CHILDREN AS UNKNOWN_FK_CHILDREN,
  @LEGACY_ROWS AS LEGACY_HERO_ROWS,
  @LEGACY_VISIBLE AS LEGACY_HERO_VISIBLE,
  @APPLY_ALLOWED AS APPLY_ALLOWED_FLAG,
  IF(@APPLY_ALLOWED = 1, 'YES', 'NO') AS APPLY_ALLOWED,
  @BLOCK_REASON AS BLOCK_REASON,
  @CONFIRM_OK AS CONFIRM_OK,
  @ORANGE_EXECUTE_RESET AS EXECUTE_FLAG;

SELECT TABLE_NAME AS FK_CHILD_TABLE, COLUMN_NAME AS FK_CHILD_COLUMN, CONSTRAINT_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME = 'channels'
  AND REFERENCED_COLUMN_NAME = 'id'
ORDER BY TABLE_NAME, COLUMN_NAME;

SET @sql_scopes = IF(
  @has_copy_lines = 1 AND @col_copy_scope = 1,
  'SELECT scope AS COPY_SCOPE, COUNT(*) AS ROWS_N FROM storefront_copy_lines GROUP BY scope ORDER BY scope',
  'SELECT ''NA'' AS COPY_SCOPE, 0 AS ROWS_N'
);
PREPARE stmt_scopes FROM @sql_scopes;
EXECUTE stmt_scopes;
DEALLOCATE PREPARE stmt_scopes;

-- ---------------------------------------------------------------------------
-- Temporary procedure: transactional apply (dropped at end of file)
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS orange_tmp_prelaunch_reset_once;

DELIMITER $$
CREATE PROCEDURE orange_tmp_prelaunch_reset_once()
BEGIN
  DECLARE v_do_apply TINYINT DEFAULT 0;
  DECLARE v_ch INT DEFAULT -1;
  DECLARE v_pc INT DEFAULT -1;
  DECLARE v_copy INT DEFAULT -1;
  DECLARE v_orders_after INT DEFAULT 0;
  DECLARE v_sr_after INT DEFAULT 0;
  DECLARE v_prod_after INT DEFAULT 0;
  DECLARE v_promo_after INT DEFAULT 0;
  DECLARE v_legacy_after INT DEFAULT 0;
  DECLARE v_ai_ch BIGINT DEFAULT NULL;
  DECLARE v_ai_copy BIGINT DEFAULT NULL;
  DECLARE v_final VARCHAR(64) DEFAULT 'DRY_RUN_ONLY';

  SET v_do_apply = 0;

  IF @ORANGE_EXECUTE_RESET <> 1 THEN
    SET v_final = 'DRY_RUN_ONLY';
  ELSEIF @CONFIRM_OK <> 1 THEN
    SET v_final = 'CONFIRMATION_REQUIRED';
  ELSEIF @APPLY_ALLOWED <> 1 THEN
    SET v_final = 'RESET_BLOCKED';
  ELSE
    SET v_do_apply = 1;
    START TRANSACTION;
    DELETE FROM product_channels;
    DELETE FROM channels;
    DELETE FROM storefront_copy_lines;

    SELECT COUNT(*) INTO v_pc FROM product_channels;
    SELECT COUNT(*) INTO v_ch FROM channels;
    SELECT COUNT(*) INTO v_copy FROM storefront_copy_lines;

    IF v_pc = 0 AND v_ch = 0 AND v_copy = 0 THEN
      COMMIT;
      ALTER TABLE channels AUTO_INCREMENT = 1;
      ALTER TABLE storefront_copy_lines AUTO_INCREMENT = 1;
      SET v_final = 'RESET_EXECUTED_SUCCESSFULLY';
    ELSE
      ROLLBACK;
      SET v_final = 'RESET_BLOCKED';
      SET @BLOCK_REASON = 'POST_DELETE_COUNT_NOT_ZERO';
    END IF;
  END IF;

  IF @has_channels = 1 THEN SELECT COUNT(*) INTO v_ch FROM channels; END IF;
  IF @has_product_channels = 1 THEN SELECT COUNT(*) INTO v_pc FROM product_channels; END IF;
  IF @has_copy_lines = 1 THEN SELECT COUNT(*) INTO v_copy FROM storefront_copy_lines; END IF;
  IF @has_orders = 1 THEN SELECT COUNT(*) INTO v_orders_after FROM orders; END IF;
  IF @has_sales_returns = 1 THEN SELECT COUNT(*) INTO v_sr_after FROM sales_returns; END IF;
  IF @has_products = 1 THEN SELECT COUNT(*) INTO v_prod_after FROM products; END IF;
  IF @has_promo = 1 THEN SELECT COUNT(*) INTO v_promo_after FROM storefront_promo_messages; END IF;
  IF @has_legacy_hero = 1 THEN SELECT COUNT(*) INTO v_legacy_after FROM storefront_home_hero; END IF;

  SELECT AUTO_INCREMENT INTO v_ai_ch FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'channels' LIMIT 1;
  SELECT AUTO_INCREMENT INTO v_ai_copy FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_copy_lines' LIMIT 1;

  SET @CHANNELS_AFTER = v_ch;
  SET @PRODUCT_CHANNELS_AFTER = v_pc;
  SET @COPY_LINES_AFTER = v_copy;
  SET @CHANNELS_NEXT_AI = v_ai_ch;
  SET @COPY_LINES_NEXT_AI = v_ai_copy;
  SET @ORDERS_UNCHANGED = IF(v_orders_after = @ORDERS_ALL_BEFORE, 'YES', 'NO');
  SET @SALES_RETURNS_UNCHANGED = IF(v_sr_after = @SR_ALL_BEFORE, 'YES', 'NO');
  SET @PRODUCTS_UNCHANGED = IF(v_prod_after = @PRODUCTS_ALL_BEFORE, 'YES', 'NO');
  SET @PROMO_UNCHANGED = IF(v_promo_after = @PROMO_ALL_BEFORE, 'YES', 'NO');
  SET @LEGACY_UNCHANGED = IF(v_legacy_after = @LEGACY_ROWS, 'YES', 'NO');
  SET @FINAL_RESULT = v_final;
  SET @MEDIA_FILES_DELETED = 0;
END$$
DELIMITER ;

CALL orange_tmp_prelaunch_reset_once();
DROP PROCEDURE IF EXISTS orange_tmp_prelaunch_reset_once;

-- Final report
SELECT
  @DATABASE_NAME AS DATABASE_NAME,
  @EXECUTION_MODE AS EXECUTION_MODE,
  @CHANNELS_BEFORE AS CHANNELS_BEFORE,
  @CHANNELS_AFTER AS CHANNELS_AFTER,
  @PRODUCT_CHANNELS_BEFORE AS PRODUCT_CHANNELS_BEFORE,
  @PRODUCT_CHANNELS_AFTER AS PRODUCT_CHANNELS_AFTER,
  @COPY_LINES_BEFORE AS COPY_LINES_BEFORE,
  @COPY_LINES_AFTER AS COPY_LINES_AFTER,
  @CHANNELS_NEXT_AI AS CHANNELS_NEXT_AI,
  @COPY_LINES_NEXT_AI AS COPY_LINES_NEXT_AI,
  @ORDERS_UNCHANGED AS ORDERS_UNCHANGED,
  @SALES_RETURNS_UNCHANGED AS SALES_RETURNS_UNCHANGED,
  @PRODUCTS_UNCHANGED AS PRODUCTS_UNCHANGED,
  @PROMO_UNCHANGED AS PROMO_UNCHANGED,
  @LEGACY_UNCHANGED AS STOREFRONT_HOME_HERO_UNCHANGED,
  @MEDIA_FILES_DELETED AS MEDIA_FILES_DELETED,
  IF(@APPLY_ALLOWED = 1, 'YES', 'NO') AS APPLY_ALLOWED,
  @BLOCK_REASON AS BLOCK_REASON,
  @FINAL_RESULT AS FINAL_RESULT;
