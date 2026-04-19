-- =============================================================================
-- Orange — أكواد العملاء/الموردين + جداول مردود المشتريات ومردود المبيعات
-- المرجع البرمجي: includes/catalog_schema.php
-- ترميز: UTF-8 بدون BOM
--
-- خطأ شائع على الاستضافة المشتركة:
--   #1044 Access denied ... to database 'information_schema'
--   بعض المستخدمين لا يُسمح لهم بـ SELECT على information_schema؛ لذلك القسم (أ)
--   أدناه لا يستخدمه. القسم (ب) للخوادم التي تمنح صلاحية الاستعلام عن الميتاداتا.
--
-- بدون SQL أصلاً: بعد رفع ملفات PHP المحدثة، أول تحميل يشغّل
--   orange_catalog_ensure_schema() ويُنشئ/يُحدّث المخطط تلقائياً إن وُجدت الصلاحيات.
-- =============================================================================

SET NAMES utf8mb4;


-- =============================================================================
-- (أ) استضافة مقيدة — بدون information_schema ولا PREPARE من ميتاداتا
-- =============================================================================
-- عن خطأ #1060 Duplicate column name على ADD COLUMN:
--   يعني عمود code أصبح موجوداً مسبقاً (مثلاً بعد تشغيل الموقع / catalog_schema).
--   هذا ليس عطلاً — لا تُعِد تشغيل نفس جملة ADD COLUMN؛ انتقل للجملة التالية
--   (CREATE INDEX أو CREATE TABLE).
--
-- نفّذ في phpMyAdmin جملة بجملة أو كتلة «الجداول» فقط إن كانت الأعمدة جاهزة.
--
-- #1061 Duplicate key name 'uq_customers_code' (أو uq_suppliers_code):
--   يعني الفهرس الفريد على code مُنشأ مسبقاً (غالباً من catalog_schema أو تشغيل سابق).
--   ليس عطلاً — لا تعيد نفس جملة CREATE INDEX؛ انتقل مباشرة للجملة التالية
--   (فهرس الموردين إن بقي، ثم أوامر CREATE TABLE IF NOT EXISTS).
--
-- أعمدة code: يُفضّل الصيغة الآمنة لإعادة التشغيل (MySQL 8.0.29+، وMariaDB يدعم IF NOT EXISTS):
ALTER TABLE customers ADD COLUMN IF NOT EXISTS code VARCHAR(32) NULL;
ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS code VARCHAR(32) NULL;

-- إن رفض السيرفر الصيغة أعلاه (خطأ syntax) فأنت على إصدار قديم — استخدم مرة واحدة
-- (وإن ظهر 1060 للعميل أو المورد فقط، تخطّى ذلك السطر وكمّل):
-- ALTER TABLE customers ADD COLUMN code VARCHAR(32) NULL;
-- ALTER TABLE suppliers ADD COLUMN code VARCHAR(32) NULL;

-- فهارس code — (1) MariaDB 10.5.2+ : آمن لإعادة التشغيل (لا يعيد 1061 إن وُجد الفهرس):
CREATE UNIQUE INDEX IF NOT EXISTS uq_customers_code ON customers (code);
CREATE UNIQUE INDEX IF NOT EXISTS uq_suppliers_code ON suppliers (code);

-- (2) إن رفض السيرفر الصيغة أعلاه (MySQL Oracle / إصدار قديم): نفّذ مرة واحدة؛ 1061 = تخطّى:
-- CREATE UNIQUE INDEX uq_customers_code ON customers (code);
-- CREATE UNIQUE INDEX uq_suppliers_code ON suppliers (code);

CREATE TABLE IF NOT EXISTS purchase_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(32) NOT NULL,
    purchase_id INT NULL,
    supplier_id INT NULL,
    type VARCHAR(16) NOT NULL DEFAULT 'credit',
    total DECIMAL(18,4) NOT NULL DEFAULT 0,
    notes VARCHAR(512) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchase_returns_number (return_number),
    KEY idx_purchase_returns_supplier (supplier_id),
    KEY idx_purchase_returns_purchase (purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_return_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    qty INT NOT NULL,
    cost DECIMAL(18,4) NOT NULL DEFAULT 0,
    KEY idx_pri_return (purchase_return_id),
    KEY idx_pri_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(32) NOT NULL,
    order_id INT NULL,
    customer_id INT NULL,
    channel_id INT NULL,
    type VARCHAR(16) NOT NULL DEFAULT 'credit',
    total DECIMAL(18,4) NOT NULL DEFAULT 0,
    notes VARCHAR(512) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_returns_number (return_number),
    KEY idx_sales_returns_order (order_id),
    KEY idx_sales_returns_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sales_return_id INT NOT NULL,
    product_id INT NOT NULL,
    variant_id INT NULL,
    qty INT NOT NULL,
    price DECIMAL(18,4) NOT NULL DEFAULT 0,
    line_discount DECIMAL(18,4) NOT NULL DEFAULT 0,
    KEY idx_sri_return (sales_return_id),
    KEY idx_sri_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- طابور طلبات الواجهة (FIFO) — المرجع: includes/order_intake_queue.php
CREATE TABLE IF NOT EXISTS order_intake_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_token CHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    payload_json MEDIUMTEXT NOT NULL,
    order_id INT NULL,
    order_number VARCHAR(64) NULL,
    whatsapp_number VARCHAR(40) NULL,
    whatsapp_url TEXT NULL,
    error_message VARCHAR(512) NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_intake_token (public_token),
    KEY idx_order_intake_status_id (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================================
-- (ب) خوادم تسمح بالاستعلام عن information_schema — آمن لإعادة تشغيل السكربت كاملاً
-- =============================================================================
-- لا تشغّل هذا القسم إذا ظهر لك خطأ 1044 على information_schema؛ استخدم القسم (أ) فقط.

/*
SET @orange_schema := DATABASE();

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'code';
SET @s := IF(@c = 0,
  'ALTER TABLE customers ADD COLUMN code VARCHAR(32) NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @i FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'customers' AND INDEX_NAME = 'uq_customers_code';
SET @s := IF(@i = 0,
  'CREATE UNIQUE INDEX uq_customers_code ON customers (code)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @c FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'suppliers' AND COLUMN_NAME = 'code';
SET @s := IF(@c = 0,
  'ALTER TABLE suppliers ADD COLUMN code VARCHAR(32) NULL',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @i FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @orange_schema AND TABLE_NAME = 'suppliers' AND INDEX_NAME = 'uq_suppliers_code';
SET @s := IF(@i = 0,
  'CREATE UNIQUE INDEX uq_suppliers_code ON suppliers (code)',
  'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ثم نفس أوامر CREATE TABLE IF NOT EXISTS من القسم (أ) إن لم تكن نفّذتها بعد.
*/
