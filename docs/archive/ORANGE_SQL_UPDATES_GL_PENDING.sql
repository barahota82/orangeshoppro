-- =============================================================================
-- Orange — حزمة ترقيات مخطط (طابور محاسبة + SEO منتجات)
-- نسخة للمراجعة والرفع اليدوي؛ آمن لإعادة التشغيل (INFORMATION_SCHEMA)
-- المرجع البرمجي: includes/catalog_schema.php
-- للتشغيل على السيرفر: زامن مع D:\orange_sql_updates.sql أو نفّذ من phpMyAdmin بعد نسخة احتياطية
-- ترميز: UTF-8 بدون BOM
-- =============================================================================

SET NAMES utf8mb4;

SET @orange_schema := DATABASE();

CREATE TABLE IF NOT EXISTS orange_gl_pending_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(100) NOT NULL,
    source_label VARCHAR(128) NOT NULL DEFAULT '',
    movement_at DATETIME NOT NULL,
    voucher_date DATETIME NOT NULL,
    account_debit INT NOT NULL,
    account_credit INT NOT NULL,
    amount DECIMAL(18,4) NOT NULL,
    description VARCHAR(512) NOT NULL,
    entry_type VARCHAR(64) NOT NULL DEFAULT 'general',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    journal_voucher_id INT NULL,
    after_post_json TEXT NULL,
    multi_line TINYINT(1) NOT NULL DEFAULT 0,
    voucher_lines_json TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    posted_at DATETIME NULL,
    UNIQUE KEY uq_gl_pending_ref (reference),
    KEY idx_gl_pending_status (status),
    KEY idx_gl_pending_movement_at (movement_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'orange_gl_pending_movements' AND COLUMN_NAME = 'multi_line';
SET @s := IF(@c = 0,
  'ALTER TABLE orange_gl_pending_movements ADD COLUMN multi_line TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'orange_gl_pending_movements' AND COLUMN_NAME = 'voucher_lines_json';
SET @s := IF(@c = 0,
  'ALTER TABLE orange_gl_pending_movements ADD COLUMN voucher_lines_json TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'orange_gl_pending_movements' AND INDEX_NAME = 'uq_gl_pending_ref';
SET @s := IF(@c = 0,
  'ALTER TABLE orange_gl_pending_movements ADD UNIQUE KEY uq_gl_pending_ref (reference)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'orange_gl_pending_movements' AND INDEX_NAME = 'idx_gl_pending_status';
SET @s := IF(@c = 0,
  'ALTER TABLE orange_gl_pending_movements ADD KEY idx_gl_pending_status (status)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'orange_gl_pending_movements' AND INDEX_NAME = 'idx_gl_pending_movement_at';
SET @s := IF(@c = 0,
  'ALTER TABLE orange_gl_pending_movements ADD KEY idx_gl_pending_movement_at (movement_at)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- products: حقول SEO (إن وُجد جدول المنتجات فقط)
-- ---------------------------------------------------------------------------
SELECT COUNT(*) INTO @ptable FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products';

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products' AND COLUMN_NAME = 'seo_meta_title_ar';
SET @s := IF(@ptable > 0 AND @c = 0,
  'ALTER TABLE products ADD COLUMN seo_meta_title_ar VARCHAR(191) NOT NULL DEFAULT \'\'',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products' AND COLUMN_NAME = 'seo_meta_title_en';
SET @s := IF(@ptable > 0 AND @c = 0,
  'ALTER TABLE products ADD COLUMN seo_meta_title_en VARCHAR(191) NOT NULL DEFAULT \'\'',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products' AND COLUMN_NAME = 'seo_meta_title_fil';
SET @s := IF(@ptable > 0 AND @c = 0,
  'ALTER TABLE products ADD COLUMN seo_meta_title_fil VARCHAR(191) NOT NULL DEFAULT \'\'',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products' AND COLUMN_NAME = 'seo_meta_title_hi';
SET @s := IF(@ptable > 0 AND @c = 0,
  'ALTER TABLE products ADD COLUMN seo_meta_title_hi VARCHAR(191) NOT NULL DEFAULT \'\'',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products' AND COLUMN_NAME = 'seo_meta_description_ar';
SET @s := IF(@ptable > 0 AND @c = 0,
  'ALTER TABLE products ADD COLUMN seo_meta_description_ar TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products' AND COLUMN_NAME = 'seo_meta_description_en';
SET @s := IF(@ptable > 0 AND @c = 0,
  'ALTER TABLE products ADD COLUMN seo_meta_description_en TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products' AND COLUMN_NAME = 'seo_meta_description_fil';
SET @s := IF(@ptable > 0 AND @c = 0,
  'ALTER TABLE products ADD COLUMN seo_meta_description_fil TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'products' AND COLUMN_NAME = 'seo_meta_description_hi';
SET @s := IF(@ptable > 0 AND @c = 0,
  'ALTER TABLE products ADD COLUMN seo_meta_description_hi TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- orange_gl_journal_type_rules (يتطلب جدول journal_types مسبقاً)
-- ---------------------------------------------------------------------------
SELECT COUNT(*) INTO @jtexists FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'journal_types';
SELECT COUNT(*) INTO @c FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'orange_gl_journal_type_rules';
SET @s := IF(@jtexists > 0 AND @c = 0,
  'CREATE TABLE orange_gl_journal_type_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_type_id INT NOT NULL,
    debit_setting_key VARCHAR(64) NOT NULL,
    credit_setting_key VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ojtr_journal_type (journal_type_id),
    KEY idx_ojtr_debit (debit_setting_key),
    KEY idx_ojtr_credit (credit_setting_key),
    CONSTRAINT orange_fk_ojtr_jt FOREIGN KEY (journal_type_id) REFERENCES journal_types (id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- فواتير مبيعات + بيانات شركة (مرجع: includes/catalog_schema.php)
-- ---------------------------------------------------------------------------
SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'company_settings' AND COLUMN_NAME = 'vat_number';
SET @s := IF(@c = 0,
  'ALTER TABLE company_settings ADD COLUMN vat_number VARCHAR(191) NOT NULL DEFAULT \'\'',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'company_settings' AND COLUMN_NAME = 'invoice_footer';
SET @s := IF(@c = 0,
  'ALTER TABLE company_settings ADD COLUMN invoice_footer TEXT NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'invoice_number';
SET @s := IF(@c = 0,
  'ALTER TABLE orders ADD COLUMN invoice_number VARCHAR(32) NULL DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'orders' AND INDEX_NAME = 'uq_orders_invoice_number';
SET @s := IF(@c = 0,
  'CREATE UNIQUE INDEX uq_orders_invoice_number ON orders (invoice_number)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- جدول expenses + أعمدة محاسبة (مرجع: includes/catalog_schema.php)
-- ---------------------------------------------------------------------------
SELECT COUNT(*) INTO @c FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'expenses';
SET @s := IF(@c = 0,
  'CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL DEFAULT \'\',
    amount DECIMAL(18,4) NOT NULL DEFAULT 0,
    expense_account_id INT NULL,
    notes VARCHAR(512) NOT NULL DEFAULT \'\',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_expenses_expense_account (expense_account_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'expense_account_id';
SET @s := IF(@c = 0,
  'ALTER TABLE expenses ADD COLUMN expense_account_id INT NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'expenses' AND INDEX_NAME = 'idx_expenses_expense_account';
SET @s := IF(@c = 0,
  'CREATE INDEX idx_expenses_expense_account ON expenses (expense_account_id)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'notes';
SET @s := IF(@c = 0,
  'ALTER TABLE expenses ADD COLUMN notes VARCHAR(512) NOT NULL DEFAULT \'\'',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'expenses' AND COLUMN_NAME = 'updated_at';
SET @s := IF(@c = 0,
  'ALTER TABLE expenses ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
