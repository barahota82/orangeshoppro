<?php

declare(strict_types=1);

/**
 * Creates core storefront + admin shell tables when missing (CREATE IF NOT EXISTS).
 * Aligns with scripts/mysql-create-orange-database-full.sql baseline DDL (indexes only; no FK pass).
 *
 * Must run from orange_catalog_ensure_schema() after helpers in catalog_schema.php are loaded.
 */
function orange_catalog_bootstrap_store_tables(PDO $pdo): void
{
    if (orange_table_exists($pdo, 'channels') && !orange_table_has_column($pdo, 'channels', 'path_segment')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE channels ADD COLUMN path_segment VARCHAR(64) NULL DEFAULT NULL AFTER slug');
        orange_catalog_safe_exec(
            $pdo,
            'CREATE UNIQUE INDEX uq_channels_path_segment ON channels (path_segment)'
        );
    }

    $sqls = [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS document_sequences (
  scope VARCHAR(64) NOT NULL,
  last_value BIGINT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS departments (
  id INT NOT NULL AUTO_INCREMENT,
  name_en VARCHAR(191) NOT NULL DEFAULT '',
  name_ar VARCHAR(191) NOT NULL DEFAULT '',
  name_fil VARCHAR(191) NOT NULL DEFAULT '',
  name_hi VARCHAR(191) NOT NULL DEFAULT '',
  slug VARCHAR(191) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_slug (slug),
  KEY idx_departments_sort (sort_order),
  KEY idx_departments_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS channels (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(50) NOT NULL,
  path_segment VARCHAR(64) DEFAULT NULL,
  logo VARCHAR(255) DEFAULT NULL,
  whatsapp_number VARCHAR(20) DEFAULT NULL,
  warehouse_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT DEFAULT 1,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_channels_slug (slug),
  UNIQUE KEY uq_channels_path_segment (path_segment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS customers (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) DEFAULT NULL,
  name_ar VARCHAR(255) NOT NULL DEFAULT '',
  phone VARCHAR(32) NOT NULL DEFAULT '',
  area VARCHAR(255) NOT NULL DEFAULT '',
  address VARCHAR(2000) NOT NULL DEFAULT '',
  email VARCHAR(255) DEFAULT NULL,
  notes TEXT,
  credit_limit DECIMAL(18,4) DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_phone (phone),
  UNIQUE KEY uq_customers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS suppliers (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) DEFAULT NULL,
  name VARCHAR(160) NOT NULL DEFAULT '',
  phone VARCHAR(40) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_phone (phone),
  UNIQUE KEY uq_suppliers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS admins (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(100) DEFAULT NULL,
  is_active TINYINT DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  is_superuser TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS admin_permissions (
  admin_id INT NOT NULL,
  resource_key VARCHAR(80) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  can_delete TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (admin_id, resource_key),
  KEY idx_admin_permissions_admin (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS admin_sessions (
  id INT NOT NULL AUTO_INCREMENT,
  admin_id INT NOT NULL,
  session_token VARCHAR(128) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY session_token (session_token),
  KEY admin_sessions_ibfk_1 (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS company_settings (
  id INT NOT NULL AUTO_INCREMENT,
  company_name_ar VARCHAR(191) NOT NULL DEFAULT '',
  company_name_en VARCHAR(191) NOT NULL DEFAULT '',
  company_logo VARCHAR(500) NOT NULL DEFAULT '',
  commercial_register VARCHAR(191) NOT NULL DEFAULT '',
  phones VARCHAR(500) NOT NULL DEFAULT '',
  address TEXT,
  vat_number VARCHAR(191) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS products (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  name_en VARCHAR(255) DEFAULT NULL,
  name_fil VARCHAR(255) DEFAULT NULL,
  name_hi VARCHAR(255) DEFAULT NULL,
  slug VARCHAR(180) DEFAULT NULL,
  description TEXT,
  description_en TEXT,
  description_fil TEXT,
  description_hi TEXT,
  price DECIMAL(10,2) NOT NULL,
  cost DECIMAL(10,2) NOT NULL,
  main_image VARCHAR(255) DEFAULT NULL,
  has_sizes TINYINT DEFAULT 0,
  has_colors TINYINT DEFAULT 0,
  is_active TINYINT DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  version INT DEFAULT 1,
  size_family_id INT DEFAULT NULL,
  sizing_guide_scope VARCHAR(16) NOT NULL DEFAULT 'none',
  sort_order INT NOT NULL DEFAULT 0,
  seo_meta_title_ar VARCHAR(191) NOT NULL DEFAULT '',
  seo_meta_title_en VARCHAR(191) NOT NULL DEFAULT '',
  seo_meta_title_fil VARCHAR(191) NOT NULL DEFAULT '',
  seo_meta_title_hi VARCHAR(191) NOT NULL DEFAULT '',
  seo_meta_description_ar TEXT,
  seo_meta_description_en TEXT,
  seo_meta_description_fil TEXT,
  seo_meta_description_hi TEXT,
  item_code VARCHAR(64) DEFAULT NULL,
  barcode VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_products_slug (slug),
  KEY idx_products_sort (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS product_variants (
  id INT NOT NULL AUTO_INCREMENT,
  product_id INT NOT NULL,
  size VARCHAR(50) DEFAULT NULL,
  color VARCHAR(191) DEFAULT NULL,
  stock_quantity INT NOT NULL DEFAULT 0,
  product_colorway_id INT DEFAULT NULL,
  size_family_size_id INT DEFAULT NULL,
  item_code VARCHAR(64) DEFAULT NULL,
  barcode VARCHAR(64) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY product_variants_ibfk_1 (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS product_images (
  id INT NOT NULL AUTO_INCREMENT,
  product_id INT NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  PRIMARY KEY (id),
  KEY product_images_ibfk_1 (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS product_channels (
  product_id INT NOT NULL,
  channel_id INT NOT NULL,
  PRIMARY KEY (product_id, channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS offers (
  id INT NOT NULL AUTO_INCREMENT,
  product_id INT NOT NULL,
  discount DECIMAL(10,2) NOT NULL,
  is_active TINYINT DEFAULT 1,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY offers_ibfk_1 (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS orders (
  id INT NOT NULL AUTO_INCREMENT,
  order_number VARCHAR(50) NOT NULL,
  customer_name VARCHAR(255) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  area VARCHAR(255) DEFAULT NULL,
  address TEXT,
  notes TEXT,
  channel_id INT DEFAULT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'pending',
  total DECIMAL(10,2) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  version INT DEFAULT 1,
  order_source VARCHAR(32) NOT NULL DEFAULT 'website',
  payment_terms VARCHAR(16) NOT NULL DEFAULT 'cash',
  customer_id INT DEFAULT NULL,
  invoice_number VARCHAR(32) DEFAULT NULL,
  amount_paid DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  customer_email VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_order_number (order_number),
  UNIQUE KEY uq_orders_invoice_number (invoice_number),
  KEY idx_orders_channel (channel_id),
  KEY idx_orders_customer_id (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS order_items (
  id INT NOT NULL AUTO_INCREMENT,
  order_id INT NOT NULL,
  product_id INT DEFAULT NULL,
  product_name VARCHAR(255) NOT NULL,
  color VARCHAR(50) DEFAULT NULL,
  size VARCHAR(50) DEFAULT NULL,
  qty INT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  cost DECIMAL(10,2) NOT NULL,
  variant_id INT DEFAULT NULL,
  line_discount DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (id),
  KEY order_items_ibfk_1 (order_id),
  KEY order_items_ibfk_2 (product_id),
  KEY fk_order_items_variant (variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS order_intake_queue (
  id INT NOT NULL AUTO_INCREMENT,
  public_token CHAR(32) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  payload_json MEDIUMTEXT NOT NULL,
  order_id INT DEFAULT NULL,
  order_number VARCHAR(64) DEFAULT NULL,
  whatsapp_number VARCHAR(40) DEFAULT NULL,
  whatsapp_url TEXT,
  error_message VARCHAR(512) DEFAULT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_intake_token (public_token),
  KEY idx_order_intake_status_id (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS storefront_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  registered_channel_slug VARCHAR(32) DEFAULT NULL,
  email_verified_at DATETIME DEFAULT NULL,
  verify_token_hash CHAR(64) NOT NULL DEFAULT '',
  verify_token_expires_at DATETIME DEFAULT NULL,
  verify_email_sent_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  customer_name VARCHAR(255) DEFAULT NULL,
  customer_phone VARCHAR(64) DEFAULT NULL,
  customer_area VARCHAR(255) DEFAULT NULL,
  customer_address TEXT,
  customer_notes TEXT,
  PRIMARY KEY (id),
  UNIQUE KEY uq_storefront_accounts_email (email),
  KEY idx_storefront_accounts_verified (email_verified_at),
  KEY idx_storefront_accounts_channel (registered_channel_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        ,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS stock_movements (
  id INT NOT NULL AUTO_INCREMENT,
  product_id INT DEFAULT NULL,
  variant_id INT DEFAULT NULL,
  type VARCHAR(50) NOT NULL,
  qty INT NOT NULL,
  old_stock INT DEFAULT NULL,
  new_stock INT DEFAULT NULL,
  reason TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  reference VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY stock_movements_ibfk_1 (product_id),
  KEY stock_movements_ibfk_2 (variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL
        ,
    ];

    foreach ($sqls as $sql) {
        orange_catalog_safe_exec($pdo, trim($sql));
    }

    orange_catalog_seed_default_channels_if_empty($pdo);
}

/**
 * Inserts the three default storefront channels when the table is empty (matches mysql-create seed).
 */
function orange_catalog_seed_default_channels_if_empty(PDO $pdo): void
{
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, 'channels')) {
        return;
    }
    try {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
        if ($n > 0) {
            return;
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] channels seed count: ' . $e->getMessage());
        }

        return;
    }

    $lock = 'orange_seed_channels';
    try {
        $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 10)')->fetchColumn();
        if ((int) $lk !== 1) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    try {
        $n2 = (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
        if ($n2 > 0) {
            return;
        }
        $st = $pdo->prepare(
            'INSERT INTO channels (name, slug, path_segment, logo, whatsapp_number, warehouse_number, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $rows = [
            ['Orange Store', 'tiktok', 'tiktok', 'logo-orange.png', '96500000000', 1, 1],
            ['Blue Store', 'online', 'online', 'logo-blue.png', '96500000001', 1, 1],
            ['Black Store', 'web', 'web', 'logo-black.png', '96500000002', 1, 1],
        ];
        foreach ($rows as $r) {
            $st->execute($r);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] channels seed: ' . $e->getMessage());
        }
    } finally {
        try {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
        } catch (Throwable $e) {
        }
    }
}
