-- =============================================================================
-- Orange — إنشاء قاعدة بيانات كاملة + نزامة مرجعية قوية (MariaDB / MySQL 8+)
-- =============================================================================
-- نفس اسم القاعدة في المشروع (config.php: DB_NAME) — لا حاجة لتغيير إعدادات PHP.
-- الاسم الافتراضي: orange_db
--
-- مهم — تبويب «SQL» في phpMyAdmin: إن نسخت النص من المتصفح قد يُلصق سطراً مثل
--   «SQL query:» أو «Copy» قبل التعليقات؛ احذف أي سطر ليس تعليقاً (-- ...) أو أمر SQL.
-- الأفضل: تبويب «استيراد» واختيار هذا الملف من القرص (لا لصقاً يدوياً).
-- لقطة dump المحلية scripts/orange_db.sql: راجع scripts/ORANGE_DB_SQL_USAGE.txt (لا تُستبدل القاعدة حتى تستورد).
--
-- قبل الاستيراد: افرّغ القاعدة (احذف كل الجداول أو استورد على قاعدة فارغة من نفس الاسم).
-- الاستيراد: phpMyAdmin → قاعدة orange_db → استيراد هذا الملف
--          أو: mysql -u USER -p orange_db < mysql-create-orange-database-full.sql
--
-- لا يُنشئ مستخدماً افتراضياً — أضف مشرفاً من لوحة التحكم أو من نسخة احتياطية.
-- الترميز: utf8mb4_unicode_ci — متوافق مع includes/catalog_schema.php
-- القيود المحاسبية في القاعدة الجديدة: journal_vouchers + journal_lines فقط (لا جدول journal_entries).
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `orange_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `orange_db`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET UNIQUE_CHECKS = 0;
SET AUTOCOMMIT = 1;

-- -----------------------------------------------------------------------------
-- جداول أساسية (بدون مفاتيح أجنبية — تُضاف لاحقاً)
-- -----------------------------------------------------------------------------

CREATE TABLE `departments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name_en` varchar(191) NOT NULL DEFAULT '',
  `name_ar` varchar(191) NOT NULL DEFAULT '',
  `name_fil` varchar(191) NOT NULL DEFAULT '',
  `name_hi` varchar(191) NOT NULL DEFAULT '',
  `slug` varchar(191) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_slug` (`slug`),
  KEY `idx_departments_sort` (`sort_order`),
  KEY `idx_departments_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `department_id` int DEFAULT NULL,
  `name_en` varchar(191) DEFAULT NULL,
  `name_ar` varchar(191) DEFAULT NULL,
  `name_fil` varchar(191) DEFAULT NULL,
  `name_hi` varchar(191) DEFAULT NULL,
  `slug` varchar(191) NOT NULL,
  `is_active` tinyint DEFAULT 1,
  `sort_order` int DEFAULT 0,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`slug`),
  KEY `idx_categories_department` (`department_id`),
  KEY `idx_categories_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subcategories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `department_id` int DEFAULT NULL,
  `category_id` int NOT NULL,
  `name_ar` varchar(191) NOT NULL,
  `name_en` varchar(191) DEFAULT NULL,
  `name_fil` varchar(191) DEFAULT NULL,
  `name_hi` varchar(191) DEFAULT NULL,
  `slug` varchar(191) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_subcategories_category` (`category_id`),
  KEY `idx_subcategories_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `channels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `path_segment` varchar(64) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `whatsapp_number` varchar(20) DEFAULT NULL,
  `warehouse_number` tinyint UNSIGNED NOT NULL DEFAULT 1,
  `is_active` tinyint DEFAULT 1,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_channels_slug` (`slug`),
  UNIQUE KEY `uq_channels_path_segment` (`path_segment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `color_dictionary` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(191) NOT NULL DEFAULT '',
  `name_en` varchar(191) NOT NULL DEFAULT '',
  `name_fil` varchar(191) NOT NULL DEFAULT '',
  `name_hi` varchar(191) NOT NULL DEFAULT '',
  `hex_code` varchar(16) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `size_families` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name_ar` varchar(191) NOT NULL DEFAULT '',
  `name_en` varchar(191) NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `size_family_sizes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `size_family_id` int NOT NULL,
  `label_ar` varchar(191) NOT NULL DEFAULT '',
  `label_en` varchar(191) NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `foot_length_cm` decimal(6,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_size_family_sizes_family` (`size_family_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `code` varchar(64) DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `is_group` tinyint(1) NOT NULL DEFAULT 0,
  `name_en` varchar(191) NOT NULL DEFAULT '',
  `is_suspended` tinyint(1) NOT NULL DEFAULT 0,
  `normal_balance` varchar(16) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_code` (`code`),
  KEY `idx_accounts_parent_id` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fiscal_years` (
  `id` int NOT NULL AUTO_INCREMENT,
  `label_ar` varchar(160) NOT NULL DEFAULT '',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `closed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fiscal_years_range` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `journal_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name_ar` varchar(255) NOT NULL DEFAULT '',
  `name_en` varchar(255) NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_journal_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `journal_vouchers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `voucher_date` datetime NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `description` text,
  `entry_type` varchar(64) NOT NULL DEFAULT 'general',
  `fiscal_year_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jv_reference` (`reference`),
  KEY `idx_jv_fiscal_year` (`fiscal_year_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `journal_lines` (
  `id` int NOT NULL AUTO_INCREMENT,
  `voucher_id` int NOT NULL,
  `line_no` smallint NOT NULL DEFAULT 0,
  `account_id` int NOT NULL,
  `debit` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `credit` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `memo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_jl_voucher` (`voucher_id`),
  KEY `idx_jl_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- journal_entries — لا يُنشأ في قواعد جديدة. المصدر القياسي للقيود: journal_vouchers +
-- journal_lines فقط. قواعد تراثية قد تحتوي الجدول؛ الترحيل التلقائي في
-- includes/catalog_schema.php، والصيانة الاختيارية في mysql-day1-hardening-part2.sql.

CREATE TABLE `orange_gl_account_settings` (
  `setting_key` varchar(64) NOT NULL,
  `account_id` int NOT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `journal_type_id` int DEFAULT NULL,
  PRIMARY KEY (`setting_key`),
  KEY `idx_gl_set_account` (`account_id`),
  KEY `idx_gl_set_jt` (`journal_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `orange_gl_journal_type_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `journal_type_id` int NOT NULL,
  `payment_terms` varchar(8) NOT NULL DEFAULT '' COMMENT 'cash|credit for PIN/PDN; empty=standard',
  `debit_setting_key` varchar(64) NOT NULL,
  `credit_setting_key` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ojtr_jt_terms` (`journal_type_id`,`payment_terms`),
  KEY `idx_ojtr_debit` (`debit_setting_key`),
  KEY `idx_ojtr_credit` (`credit_setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orange_gl_setting_alloc` (
  `setting_key` varchar(64) NOT NULL,
  `percent_value` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orange_gl_pending_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reference` varchar(100) NOT NULL,
  `source_label` varchar(128) NOT NULL DEFAULT '',
  `movement_at` datetime NOT NULL,
  `voucher_date` datetime NOT NULL,
  `account_debit` int NOT NULL,
  `account_credit` int NOT NULL,
  `amount` decimal(18,4) NOT NULL,
  `description` varchar(512) NOT NULL,
  `entry_type` varchar(64) NOT NULL DEFAULT 'general',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `journal_voucher_id` int DEFAULT NULL,
  `after_post_json` text,
  `multi_line` tinyint(1) NOT NULL DEFAULT 0,
  `voucher_lines_json` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `posted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gl_pending_ref` (`reference`),
  KEY `idx_gl_pending_status` (`status`),
  KEY `idx_gl_pending_movement_at` (`movement_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_sequences` (
  `scope` varchar(64) NOT NULL,
  `last_value` bigint NOT NULL DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orange_schema_migrations` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` varchar(191) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orange_migration` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) DEFAULT NULL,
  `name_ar` varchar(255) NOT NULL DEFAULT '',
  `phone_country_dial` varchar(8) DEFAULT NULL,
  `phone_national` varchar(32) DEFAULT NULL,
  `phone` varchar(32) NOT NULL DEFAULT '',
  `area` varchar(255) NOT NULL DEFAULT '',
  `address` varchar(2000) NOT NULL DEFAULT '',
  `email` varchar(255) DEFAULT NULL,
  `notes` text,
  `credit_limit` decimal(18,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_phone` (`phone`),
  UNIQUE KEY `uq_customers_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(32) DEFAULT NULL,
  `name` varchar(160) NOT NULL DEFAULT '',
  `phone` varchar(40) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `payable_account_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_suppliers_phone` (`phone`),
  UNIQUE KEY `uq_suppliers_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `is_active` tinyint DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_superuser` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `admin_permissions` (
  `admin_id` int NOT NULL,
  `resource_key` varchar(80) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`admin_id`,`resource_key`),
  KEY `idx_admin_permissions_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `session_token` varchar(128) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `admin_sessions_ibfk_1` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `company_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `company_name_ar` varchar(191) NOT NULL DEFAULT '',
  `company_name_en` varchar(191) NOT NULL DEFAULT '',
  `company_logo` varchar(500) NOT NULL DEFAULT '',
  `commercial_register` varchar(191) NOT NULL DEFAULT '',
  `phones` varchar(500) NOT NULL DEFAULT '',
  `address` text,
  `vat_number` varchar(191) NOT NULL DEFAULT '',
  `invoice_footer` text,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  `name_fil` varchar(255) DEFAULT NULL,
  `name_hi` varchar(255) DEFAULT NULL,
  `slug` varchar(180) DEFAULT NULL,
  `description` text,
  `description_en` text,
  `description_fil` text,
  `description_hi` text,
  `category_id` int DEFAULT NULL,
  `subcategory_id` int DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `main_image` varchar(255) DEFAULT NULL,
  `has_sizes` tinyint DEFAULT 0,
  `has_colors` tinyint DEFAULT 0,
  `is_active` tinyint DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `version` int DEFAULT 1,
  `size_family_id` int DEFAULT NULL,
  `sizing_guide_scope` varchar(16) NOT NULL DEFAULT 'none',
  `sort_order` int NOT NULL DEFAULT 0,
  `seo_meta_title_ar` varchar(191) NOT NULL DEFAULT '',
  `seo_meta_title_en` varchar(191) NOT NULL DEFAULT '',
  `seo_meta_title_fil` varchar(191) NOT NULL DEFAULT '',
  `seo_meta_title_hi` varchar(191) NOT NULL DEFAULT '',
  `seo_meta_description_ar` text,
  `seo_meta_description_en` text,
  `seo_meta_description_fil` text,
  `seo_meta_description_hi` text,
  `item_code` varchar(64) DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_products_category_active` (`category_id`,`is_active`),
  KEY `idx_products_subcategory` (`subcategory_id`),
  KEY `idx_products_slug` (`slug`),
  KEY `idx_products_sort` (`sort_order`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `product_colorways` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `primary_color_id` int DEFAULT NULL,
  `secondary_color_id` int DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_product_colorways_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_variants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `size` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `stock_quantity` int NOT NULL DEFAULT 0,
  `product_colorway_id` int DEFAULT NULL,
  `size_family_size_id` int DEFAULT NULL,
  `item_code` varchar(64) DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_variants_ibfk_1` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `product_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `image_path` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `product_images_ibfk_1` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `product_channels` (
  `product_id` int NOT NULL,
  `channel_id` int NOT NULL,
  PRIMARY KEY (`product_id`,`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `offers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `discount` decimal(10,2) NOT NULL,
  `is_active` tinyint DEFAULT 1,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `offers_ibfk_1` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `cart_promotions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `min_subtotal` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `requires_registered_account` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cart_promotions_active_min` (`is_active`,`min_subtotal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `phone_country_dial` varchar(8) DEFAULT NULL,
  `phone_national` varchar(32) DEFAULT NULL,
  `phone` varchar(32) NOT NULL,
  `area` varchar(255) DEFAULT NULL,
  `address` text,
  `notes` text,
  `channel_id` int DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `total` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `version` int DEFAULT 1,
  `order_source` varchar(32) NOT NULL DEFAULT 'website',
  `payment_terms` varchar(16) NOT NULL DEFAULT 'cash',
  `customer_id` int DEFAULT NULL,
  `storefront_account_id` int unsigned DEFAULT NULL,
  `delivery_area_id` int unsigned DEFAULT NULL,
  `cart_promotion_id` int unsigned DEFAULT NULL,
  `cart_promotion_discount` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `invoice_number` varchar(32) DEFAULT NULL,
  `amount_paid` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `customer_email` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_number` (`order_number`),
  UNIQUE KEY `uq_orders_invoice_number` (`invoice_number`),
  KEY `idx_orders_channel` (`channel_id`),
  KEY `idx_orders_customer_id` (`customer_id`),
  KEY `idx_orders_storefront_account_id` (`storefront_account_id`),
  KEY `idx_orders_delivery_area_id` (`delivery_area_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `color` varchar(50) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `qty` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `variant_id` int DEFAULT NULL,
  `line_discount` decimal(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `order_items_ibfk_1` (`order_id`),
  KEY `order_items_ibfk_2` (`product_id`),
  KEY `fk_order_items_variant` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `order_intake_queue` (
  `id` int NOT NULL AUTO_INCREMENT,
  `public_token` char(32) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `payload_json` mediumtext NOT NULL,
  `order_id` int DEFAULT NULL,
  `order_number` varchar(64) DEFAULT NULL,
  `whatsapp_number` varchar(40) DEFAULT NULL,
  `whatsapp_url` text,
  `error_message` varchar(512) DEFAULT NULL,
  `attempts` tinyint UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_intake_token` (`public_token`),
  KEY `idx_order_intake_status_id` (`status`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int DEFAULT NULL,
  `total` decimal(10,2) NOT NULL,
  `type` varchar(20) NOT NULL,
  `notes` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `purchase_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `product_id` int NOT NULL,
  `qty` int NOT NULL,
  `qty_received` int NOT NULL DEFAULT '0',
  `cost` decimal(10,2) NOT NULL,
  `variant_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_items_ibfk_1` (`purchase_id`),
  KEY `purchase_items_ibfk_2` (`product_id`),
  KEY `idx_purchase_items_variant` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `purchase_returns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_number` varchar(32) NOT NULL,
  `purchase_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'credit',
  `total` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `notes` varchar(512) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchase_returns_number` (`return_number`),
  KEY `idx_purchase_returns_supplier` (`supplier_id`),
  KEY `idx_purchase_returns_purchase` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchase_return_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_return_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `qty` int NOT NULL,
  `cost` decimal(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_pri_return` (`purchase_return_id`),
  KEY `idx_pri_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sales_returns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `return_number` varchar(32) NOT NULL,
  `order_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `channel_id` int DEFAULT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'credit',
  `total` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `notes` varchar(512) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_returns_number` (`return_number`),
  KEY `idx_sales_returns_order` (`order_id`),
  KEY `idx_sales_returns_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sales_return_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sales_return_id` int NOT NULL,
  `product_id` int NOT NULL,
  `variant_id` int DEFAULT NULL,
  `qty` int NOT NULL,
  `price` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `line_discount` decimal(18,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_sri_return` (`sales_return_id`),
  KEY `idx_sri_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `stock_movements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int DEFAULT NULL,
  `variant_id` int DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `qty` int NOT NULL,
  `old_stock` int DEFAULT NULL,
  `new_stock` int DEFAULT NULL,
  `reason` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `reference` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_movements_ibfk_1` (`product_id`),
  KEY `stock_movements_ibfk_2` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `party_subledger` (
  `id` int NOT NULL AUTO_INCREMENT,
  `party_kind` varchar(20) NOT NULL,
  `party_id` int NOT NULL,
  `voucher_id` int NOT NULL,
  `debit` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `credit` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `ref_type` varchar(32) DEFAULT NULL,
  `ref_id` int DEFAULT NULL,
  `memo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ps_party` (`party_kind`,`party_id`),
  KEY `idx_ps_voucher` (`voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `party_subledger_allocations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `party_kind` varchar(20) NOT NULL,
  `party_id` int NOT NULL,
  `payment_voucher_id` int NOT NULL,
  `target_ref_type` varchar(32) NOT NULL,
  `target_ref_id` int NOT NULL,
  `amount` decimal(18,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_psa_party` (`party_kind`,`party_id`),
  KEY `idx_psa_payment` (`payment_voucher_id`),
  KEY `idx_psa_target` (`target_ref_type`,`target_ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expenses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `amount` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `expense_account_id` int DEFAULT NULL,
  `notes` varchar(512) NOT NULL DEFAULT '',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_expense_account` (`expense_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `orange_company_documents` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `title_ar` varchar(255) NOT NULL DEFAULT '',
  `doc_type` varchar(48) NOT NULL DEFAULT 'other',
  `reference_number` varchar(128) NOT NULL DEFAULT '',
  `doc_date` date DEFAULT NULL,
  `entity_table` varchar(64) NOT NULL DEFAULT '',
  `entity_id` varchar(64) NOT NULL DEFAULT '',
  `notes` text,
  `storage_path` varchar(512) NOT NULL,
  `original_filename` varchar(255) NOT NULL DEFAULT '',
  `mime_type` varchar(128) NOT NULL DEFAULT '',
  `file_size` bigint UNSIGNED NOT NULL DEFAULT 0,
  `created_by_admin_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ocd_type` (`doc_type`),
  KEY `idx_ocd_entity` (`entity_table`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orange_admin_audit_log` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `admin_id` int DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `message` text NOT NULL,
  `entity_table` varchar(80) NOT NULL DEFAULT '',
  `entity_id` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_orange_audit_created` (`created_at`),
  KEY `idx_orange_audit_admin` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `storefront_accounts` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `registered_channel_slug` varchar(32) DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `verify_token_hash` char(64) NOT NULL DEFAULT '',
  `verify_token_expires_at` datetime DEFAULT NULL,
  `verify_email_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(64) DEFAULT NULL,
  `customer_phone_country_dial` varchar(8) DEFAULT NULL,
  `customer_phone_national` varchar(32) DEFAULT NULL,
  `customer_area` varchar(255) DEFAULT NULL,
  `customer_address` text,
  `customer_notes` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_storefront_accounts_email` (`email`),
  KEY `idx_storefront_accounts_verified` (`email_verified_at`),
  KEY `idx_storefront_accounts_channel` (`registered_channel_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `storefront_phone_merge_requests` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `storefront_account_id` int UNSIGNED NOT NULL,
  `phone_normalized` varchar(64) NOT NULL,
  `proposed_email` varchar(255) NOT NULL,
  `proposed_channel_slug` varchar(32) DEFAULT NULL,
  `proposed_name` varchar(255) DEFAULT NULL,
  `proposed_delivery_area_id` int UNSIGNED DEFAULT NULL,
  `proposed_area` varchar(255) DEFAULT NULL,
  `proposed_address` text,
  `proposed_notes` text,
  `proposed_phone_country_dial` varchar(8) DEFAULT NULL,
  `proposed_phone_national` varchar(32) DEFAULT NULL,
  `merge_token_hash` char(64) NOT NULL,
  `wa_confirmed_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_spmr_account` (`storefront_account_id`),
  KEY `idx_spmr_phone` (`phone_normalized`),
  KEY `idx_spmr_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(50) DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `message` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_logs_admin_created_at` (`admin_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -----------------------------------------------------------------------------
-- مفاتيح أجنبية (نزامة قوية — أسماء متوافقة مع سكربتات التقوية في المشروع)
-- -----------------------------------------------------------------------------

ALTER TABLE `accounts`
  ADD CONSTRAINT `fk_accounts_parent` FOREIGN KEY (`parent_id`) REFERENCES `accounts` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `categories`
  ADD CONSTRAINT `fk_categories_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
  ON DELETE SET NULL;

ALTER TABLE `subcategories`
  ADD CONSTRAINT `fk_subcategories_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `subcategories`
  ADD CONSTRAINT `fk_subcategories_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
  ON DELETE SET NULL;

ALTER TABLE `size_family_sizes`
  ADD CONSTRAINT `fk_sfs_size_family` FOREIGN KEY (`size_family_id`) REFERENCES `size_families` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `journal_vouchers`
  ADD CONSTRAINT `fk_journal_vouchers_fiscal_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `fiscal_years` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `journal_lines`
  ADD CONSTRAINT `orange_fk_jl_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `journal_vouchers` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `journal_lines`
  ADD CONSTRAINT `orange_fk_jl_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `orange_gl_account_settings`
  ADD CONSTRAINT `orange_fk_gl_setting_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`);

ALTER TABLE `orange_gl_account_settings`
  ADD CONSTRAINT `fk_gl_settings_journal_type` FOREIGN KEY (`journal_type_id`) REFERENCES `journal_types` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `orange_gl_journal_type_rules`
  ADD CONSTRAINT `orange_fk_ojtr_jt` FOREIGN KEY (`journal_type_id`) REFERENCES `journal_types` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `orange_gl_pending_movements`
  ADD CONSTRAINT `fk_ogpm_debit_account` FOREIGN KEY (`account_debit`) REFERENCES `accounts` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `orange_gl_pending_movements`
  ADD CONSTRAINT `fk_ogpm_credit_account` FOREIGN KEY (`account_credit`) REFERENCES `accounts` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `orange_gl_pending_movements`
  ADD CONSTRAINT `fk_ogpm_journal_voucher` FOREIGN KEY (`journal_voucher_id`) REFERENCES `journal_vouchers` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `admin_sessions`
  ADD CONSTRAINT `admin_sessions_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `admin_permissions`
  ADD CONSTRAINT `fk_admin_permissions_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `subcategories` (`id`)
  ON DELETE SET NULL;

ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
  ON DELETE SET NULL;

ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_size_family` FOREIGN KEY (`size_family_id`) REFERENCES `size_families` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `product_colorways`
  ADD CONSTRAINT `fk_pcw_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `product_colorways`
  ADD CONSTRAINT `fk_pcw_primary_color` FOREIGN KEY (`primary_color_id`) REFERENCES `color_dictionary` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `product_colorways`
  ADD CONSTRAINT `fk_pcw_secondary_color` FOREIGN KEY (`secondary_color_id`) REFERENCES `color_dictionary` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `product_variants`
  ADD CONSTRAINT `fk_pv_colorway` FOREIGN KEY (`product_colorway_id`) REFERENCES `product_colorways` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `product_variants`
  ADD CONSTRAINT `fk_pv_size_family_size` FOREIGN KEY (`size_family_size_id`) REFERENCES `size_family_sizes` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `product_variants`
  ADD CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `product_images`
  ADD CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `product_channels`
  ADD CONSTRAINT `product_channels_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `product_channels`
  ADD CONSTRAINT `product_channels_ibfk_2` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `offers`
  ADD CONSTRAINT `offers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`)
  ON DELETE SET NULL;

ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_order_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `order_intake_queue`
  ADD CONSTRAINT `fk_order_intake_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
  ON DELETE SET NULL;

ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

ALTER TABLE `purchase_items`
  ADD CONSTRAINT `fk_purchase_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `purchase_returns`
  ADD CONSTRAINT `fk_pr_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `purchase_returns`
  ADD CONSTRAINT `fk_pr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `fk_pri_return` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `fk_pri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `fk_pri_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sales_returns`
  ADD CONSTRAINT `fk_sr_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sales_returns`
  ADD CONSTRAINT `fk_sr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sales_returns`
  ADD CONSTRAINT `fk_sr_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `sales_return_items`
  ADD CONSTRAINT `fk_sri_return` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns` (`id`)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `sales_return_items`
  ADD CONSTRAINT `fk_sri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
  ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sales_return_items`
  ADD CONSTRAINT `fk_sri_variant` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `stock_movements`
  ADD CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

ALTER TABLE `stock_movements`
  ADD CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

ALTER TABLE `party_subledger`
  ADD CONSTRAINT `orange_fk_ps_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `journal_vouchers` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `party_subledger_allocations`
  ADD CONSTRAINT `orange_fk_psa_voucher` FOREIGN KEY (`payment_voucher_id`) REFERENCES `journal_vouchers` (`id`)
  ON DELETE CASCADE;

ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expenses_account` FOREIGN KEY (`expense_account_id`) REFERENCES `accounts` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `orange_company_documents`
  ADD CONSTRAINT `fk_ocd_created_by_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `orange_admin_audit_log`
  ADD CONSTRAINT `fk_orange_audit_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `logs`
  ADD CONSTRAINT `fk_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
  ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- بذور أولية (مرجعية) — عدّل القنوات والسنة حسب عملك
-- -----------------------------------------------------------------------------

INSERT INTO `fiscal_years` (`label_ar`, `start_date`, `end_date`, `is_closed`)
VALUES ('سنة 2026', '2026-01-01', '2026-12-31', 0);

INSERT INTO `company_settings` (
  `company_name_ar`, `company_name_en`, `company_logo`, `commercial_register`,
  `phones`, `address`, `vat_number`, `invoice_footer`
) VALUES ('', '', '', '', '', '', '', NULL);

INSERT INTO `accounts` (`name`, `code`, `parent_id`, `is_group`, `name_en`, `is_suspended`, `normal_balance`) VALUES
('الأصول', '1', NULL, 1, 'Assets', 0, NULL),
('الخصوم', '2', NULL, 1, 'Liabilities', 0, NULL),
('حقوق الملكية', '3', NULL, 1, 'Equity', 0, NULL),
('الإيرادات', '4', NULL, 1, 'Revenue', 0, NULL),
('تكلفة المبيعات', '5', NULL, 1, 'Cost of sales', 0, NULL),
('المصروفات', '6', NULL, 1, 'Expenses', 0, NULL),
('حسابات نظامية (خارج الميزانية)', '7', NULL, 1, 'Off-balance sheet accounts', 0, NULL);

INSERT INTO `journal_types` (`code`, `name_ar`, `name_en`, `sort_order`) VALUES
('OBV', 'سند رصيد افتتاحي', 'Opening balance voucher', 1),
('JE', 'سند قيد', 'Journal entry', 2),
('RV', 'سند قبض', 'Receipt voucher', 3),
('PV', 'سند صرف', 'Payment voucher', 4),
('EXP', 'قيد مصروف', 'Expense voucher', 5),
('YEC', 'قيد الإقفال السنوي', 'Year-end closing entry', 6),
('PIN', 'فاتورة مشتريات', 'Purchase invoice', 7),
('PDN', 'مردود مشتريات', 'Purchase return', 8),
('CSI', 'مبيعات نقدي', 'Cash sales', 9),
('CGC', 'تكلفة مبيعات نقدي', 'Cost of cash sales', 10),
('SCR', 'مردود مبيعات نقدي', 'Cash sales return', 11),
('CSR', 'تكلفة مردود مبيعات نقدي', 'Cost of cash sales return', 12),
('SIN', 'مبيعات أجل', 'Credit sales', 13),
('CGT', 'تكلفة مبيعات أجل', 'Cost of credit sales', 14),
('SRR', 'مردود مبيعات أجل', 'Credit sales return', 15),
('CGR', 'تكلفة مردود مبيعات أجل', 'Cost of credit sales return', 16),
('OSI', 'مبيعات الاونلاين', 'Online sales', 17),
('CGO', 'تكلفة مبيعات الاونلاين', 'Cost of online sales', 18),
('OSR', 'مردود مبيعات الاونلاين', 'Online sales return', 19),
('COR', 'تكلفة مردود مبيعات الاونلاين', 'Cost of online sales return', 20);

INSERT INTO `channels` (`name`, `slug`, `path_segment`, `logo`, `whatsapp_number`, `warehouse_number`, `is_active`) VALUES
('Orange Store', 'tiktok', 'tiktok', 'logo-orange.png', '96500000000', 1, 1),
('Blue Store', 'online', 'online', 'logo-blue.png', '96500000001', 1, 1),
('Black Store', 'web', 'web', 'logo-black.png', '96500000002', 1, 1);

SET FOREIGN_KEY_CHECKS = 1;
SET UNIQUE_CHECKS = 1;

-- =============================================================================
-- بعد الاستيراد:
--   * أنشئ مستخدماً للوحة التحكم (جدول admins فارغ) أو انسخ الصف من النسخة الاحتياطية.
--   * زِر الموقع مرة لتشغيل orange_catalog_ensure_schema (يُكمّل أي عمود نادر).
--   * غيّر أرقام الواتس في channels إن لزم.
--   * لربط المنتجات بالقنوات دفعة واحدة يمكن لاحقاً تشغيل منطق post_deploy أو INSERT IGNORE product_channels.
-- =============================================================================
