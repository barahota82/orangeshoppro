-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Apr 17, 2026 at 06:03 AM
-- Server version: 10.11.13-MariaDB
-- PHP Version: 8.4.19

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `orange_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounts`
--

INSERT INTO `accounts` (`id`, `name`, `updated_at`) VALUES
(1, 'Cash', NULL),
(2, 'Sales', NULL),
(3, 'Inventory', NULL),
(4, 'COGS', NULL),
(5, 'Accounts Payable', NULL),
(6, 'Expenses', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password_hash`, `display_name`, `is_active`, `created_at`) VALUES
(1, 'admin', '$2y$10$UmNhFKJr3dwjQjZwU9DNeOVbqudDleBqmoMXOCwiiR.a72ZczSzIO', 'Super Admin', 1, '2026-04-15 11:55:48');

-- --------------------------------------------------------

--
-- Table structure for table `admin_sessions`
--

CREATE TABLE `admin_sessions` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `session_token` varchar(128) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `name_en` varchar(100) DEFAULT NULL,
  `name_ar` varchar(100) DEFAULT NULL,
  `name_fil` varchar(100) DEFAULT NULL,
  `name_hi` varchar(100) DEFAULT NULL,
  `slug` varchar(100) NOT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `department_id`, `name_en`, `name_ar`, `name_fil`, `name_hi`, `slug`, `is_active`, `sort_order`, `updated_at`) VALUES
(1, 1, 'Women\'s clothes', 'ملابس نسائية', 'Damit pambabae', 'महिलाओं के कपड़े', 'womens-clothes', 1, 1, NULL),
(2, 2, 'Men\'s clothes', 'ملابس رجالية', 'Damit panlalaki', 'पुरुषों के कपड़े', 'mens-clothes', 1, 2, NULL),
(3, 3, 'Women\'s Shoes', 'أحذية نسائية', 'Sapatos pambabae', 'महिलाओं के जूते', 'womens-shoes', 1, 3, NULL),
(4, 4, 'Women\'s Shoes', 'أحذية رجالية', 'Sapatos panlalaki', 'पुरुषों के जूते', 'mens-shoes', 1, 4, NULL),
(5, 5, 'Sleepwear & underwear', 'ملابس نوم ولانجري', 'Damit pangtulog at panloob', 'नाइटवियर और अंतर्वस्त्र', 'sleepwear-underwear', 1, 5, NULL),
(6, 6, 'Jewelry & accessories', 'مجوهرات وإكسسوارات', 'Alahas at aksesorya', 'गहने और सहायक उपकरण', 'jewelry-accessories', 1, 6, NULL),
(7, 7, 'Beauty & health', 'الجمال والصحة', 'Kagandahan at kalusugan', 'सौंदर्य और स्वास्थ्य', 'beauty-health', 1, 7, NULL),
(8, 8, 'Bags & Travel Essentials', 'حقائب ومستلزمات سفر', 'Mga Bag at Gamit sa Biyahe', 'बैग और यात्रा का जरूरी सामान', 'bags-luggage', 1, 8, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `channels`
--

CREATE TABLE `channels` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(50) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `primary_color` varchar(20) DEFAULT NULL,
  `whatsapp_number` varchar(20) DEFAULT NULL,
  `warehouse_number` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `is_active` tinyint(4) DEFAULT 1,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `channels`
--

INSERT INTO `channels` (`id`, `name`, `slug`, `logo`, `primary_color`, `whatsapp_number`, `warehouse_number`, `is_active`, `updated_at`) VALUES
(1, 'Orange Store', 'orange', 'logo-orange.png', '#ff7a00', '96594979938', 1, 1, NULL),
(2, 'Blue Store', 'blue', 'logo-blue.png', '#1d4ed8', '96594979964', 1, 1, NULL),
(3, 'Black Store', 'black', 'logo-black.png', '#111827', '96594979958', 1, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `color_dictionary`
--

CREATE TABLE `color_dictionary` (
  `id` int(11) NOT NULL,
  `name_ar` varchar(191) NOT NULL DEFAULT '',
  `name_en` varchar(191) NOT NULL DEFAULT '',
  `name_fil` varchar(191) NOT NULL DEFAULT '',
  `name_hi` varchar(191) NOT NULL DEFAULT '',
  `hex_code` varchar(16) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_settings`
--

CREATE TABLE `company_settings` (
  `id` int(11) NOT NULL,
  `company_name_ar` varchar(191) NOT NULL DEFAULT '',
  `company_name_en` varchar(191) NOT NULL DEFAULT '',
  `company_logo` varchar(500) NOT NULL DEFAULT '',
  `commercial_register` varchar(191) NOT NULL DEFAULT '',
  `phones` varchar(500) NOT NULL DEFAULT '',
  `address` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name_ar` varchar(120) NOT NULL,
  `name_en` varchar(120) DEFAULT NULL,
  `name_fil` varchar(120) DEFAULT NULL,
  `name_hi` varchar(120) DEFAULT NULL,
  `slug` varchar(140) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name_ar`, `name_en`, `name_fil`, `name_hi`, `slug`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'ملابس نسائية', 'Women\'s clothing', 'Kasuotang pambabae', 'महिलाओं के कपड़े', 'womens-clothing', 1, 1, '2026-04-16 22:37:01', NULL),
(2, 'ملابس رجالية', 'Men\'s clothing', 'Kasuotang Panlalaki', 'पुरुषों के कपड़े', 'mens-clothing', 1, 2, '2026-04-16 22:38:33', NULL),
(3, 'أحذية نسائية', 'Women\'s shoes', 'Mga sapatos na pambabae', 'महिलाओं के जूते', 'womens-shoes', 1, 3, '2026-04-16 22:54:16', NULL),
(4, 'أحذية رجالية', 'Men\'s shoes', 'Mga Sapatos na Panlalaki', 'पुरुषों के जूते', 'mens-shoes', 1, 4, '2026-04-16 22:55:37', NULL),
(5, 'ملابس نوم ولانجري', 'Nightwear and lingerie', 'Pantulog at damit-panloob', 'रात्रि वस्त्र और अधोवस्त्र', 'nightwear-and-lingerie', 1, 5, '2026-04-16 22:59:04', NULL),
(6, 'مجوهرات وإكسسوارات', 'Jewelry and accessories', 'Alahas at Aksesorya', 'आभूषण और सहायक उपकरण', 'jewelry-and-accessories', 1, 6, '2026-04-16 23:00:43', NULL),
(7, 'الجمال والصحة', 'Beauty and health', 'Kagandahan at kalusugan', 'सौंदर्य और स्वास्थ्य', 'beauty-and-health', 1, 7, '2026-04-16 23:01:59', NULL),
(8, 'الحقائب ومستلزمات سفر', 'Bags & travel supplies', 'Mga Bag at Gamit sa Biyahe', 'बैग और यात्रा सामग्री', 'bags-travel-supplies', 1, 8, '2026-04-16 23:07:19', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `date` datetime NOT NULL,
  `account_debit` int(11) NOT NULL,
  `account_credit` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `entry_type` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` int(11) NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `offers`
--

CREATE TABLE `offers` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `discount` decimal(10,2) NOT NULL,
  `is_active` tinyint(4) DEFAULT 1,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `area` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `channel_id` int(11) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `total` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `version` int(11) DEFAULT 1,
  `order_source` varchar(32) NOT NULL DEFAULT 'website'
) ;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `color` varchar(50) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `variant_id` int(11) DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  `name_fil` varchar(255) DEFAULT NULL,
  `name_hi` varchar(255) DEFAULT NULL,
  `slug` varchar(180) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `description_en` text DEFAULT NULL,
  `description_fil` text DEFAULT NULL,
  `description_hi` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `subcategory_id` int(11) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `cost` decimal(10,2) NOT NULL,
  `main_image` varchar(255) DEFAULT NULL,
  `has_sizes` tinyint(4) DEFAULT 0,
  `has_colors` tinyint(4) DEFAULT 0,
  `is_active` tinyint(4) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `version` int(11) DEFAULT 1,
  `size_family_id` int(11) DEFAULT NULL,
  `sizing_guide_scope` varchar(16) NOT NULL DEFAULT 'none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_channels`
--

CREATE TABLE `product_channels` (
  `product_id` int(11) NOT NULL,
  `channel_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_colorways`
--

CREATE TABLE `product_colorways` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `primary_color_id` int(11) DEFAULT NULL,
  `secondary_color_id` int(11) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

CREATE TABLE `product_variants` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `product_colorway_id` int(11) DEFAULT NULL,
  `size_family_size_id` int(11) DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `total` decimal(10,2) NOT NULL,
  `type` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL,
  `cost` decimal(10,2) NOT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `size_families`
--

CREATE TABLE `size_families` (
  `id` int(11) NOT NULL,
  `name_ar` varchar(191) NOT NULL DEFAULT '',
  `name_en` varchar(191) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `size_family_sizes`
--

CREATE TABLE `size_family_sizes` (
  `id` int(11) NOT NULL,
  `size_family_id` int(11) NOT NULL,
  `label_ar` varchar(191) NOT NULL DEFAULT '',
  `label_en` varchar(191) NOT NULL DEFAULT '',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `foot_length_cm` decimal(6,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `qty` int(11) NOT NULL,
  `old_stock` int(11) DEFAULT NULL,
  `new_stock` int(11) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `reference` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subcategories`
--

CREATE TABLE `subcategories` (
  `id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `name_ar` varchar(120) NOT NULL,
  `name_en` varchar(120) DEFAULT NULL,
  `name_fil` varchar(120) DEFAULT NULL,
  `name_hi` varchar(120) DEFAULT NULL,
  `slug` varchar(140) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_accounts_name` (`name`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_categories_slug` (`slug`),
  ADD KEY `idx_categories_slug` (`slug`),
  ADD KEY `idx_categories_active` (`is_active`),
  ADD KEY `idx_categories_department` (`department_id`),
  ADD KEY `idx_categories_department_id` (`department_id`);

--
-- Indexes for table `channels`
--
ALTER TABLE `channels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_channels_slug` (`slug`),
  ADD KEY `idx_channels_slug` (`slug`);

--
-- Indexes for table `color_dictionary`
--
ALTER TABLE `color_dictionary`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_departments_slug` (`slug`),
  ADD KEY `idx_departments_active` (`is_active`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expenses_created_at` (`created_at`),
  ADD KEY `idx_expenses_updated_at` (`updated_at`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `account_debit` (`account_debit`),
  ADD KEY `account_credit` (`account_credit`),
  ADD KEY `idx_journal_entry_type` (`entry_type`),
  ADD KEY `idx_journal_reference` (`reference`),
  ADD KEY `idx_journal_date` (`date`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_logs_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_logs_type_created_at` (`type`,`created_at`),
  ADD KEY `idx_logs_admin_created_at` (`admin_id`,`created_at`),
  ADD KEY `idx_logs_entity_created_at` (`entity_type`,`entity_id`,`created_at`);

--
-- Indexes for table `offers`
--
ALTER TABLE `offers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_offers_active` (`is_active`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_orders_order_number` (`order_number`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_channel` (`channel_id`),
  ADD KEY `idx_orders_order_number` (`order_number`),
  ADD KEY `idx_orders_date` (`created_at`),
  ADD KEY `idx_orders_phone` (`phone`),
  ADD KEY `idx_orders_version` (`version`),
  ADD KEY `idx_orders_updated_at` (`updated_at`),
  ADD KEY `idx_orders_status_created_at` (`status`,`created_at`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `order_items_ibfk_2` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_products_name` (`name`),
  ADD KEY `idx_products_active` (`is_active`),
  ADD KEY `idx_products_category_active` (`category_id`,`is_active`),
  ADD KEY `idx_products_created_at` (`created_at`),
  ADD KEY `idx_products_version` (`version`),
  ADD KEY `idx_products_subcategory` (`subcategory_id`),
  ADD KEY `idx_products_slug` (`slug`);

--
-- Indexes for table `product_channels`
--
ALTER TABLE `product_channels`
  ADD PRIMARY KEY (`product_id`,`channel_id`),
  ADD KEY `channel_id` (`channel_id`);

--
-- Indexes for table `product_colorways`
--
ALTER TABLE `product_colorways`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_colorways_product` (`product_id`);

--
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_product_variant_combo` (`product_id`,`size`,`color`),
  ADD KEY `idx_variants_product` (`product_id`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `idx_purchases_created_at` (`created_at`),
  ADD KEY `idx_purchases_updated_at` (`updated_at`),
  ADD KEY `idx_purchases_type_created` (`type`,`created_at`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `purchase_items_ibfk_2` (`product_id`);

--
-- Indexes for table `size_families`
--
ALTER TABLE `size_families`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `size_family_sizes`
--
ALTER TABLE `size_family_sizes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_size_family_sizes_family` (`size_family_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `variant_id` (`variant_id`),
  ADD KEY `idx_stock_movements_date` (`created_at`),
  ADD KEY `idx_stock_reference` (`reference`);

--
-- Indexes for table `subcategories`
--
ALTER TABLE `subcategories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_subcategories_slug` (`slug`),
  ADD KEY `idx_subcategories_department` (`department_id`),
  ADD KEY `idx_subcategories_category` (`category_id`),
  ADD KEY `idx_subcategories_active` (`is_active`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_suppliers_name` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `channels`
--
ALTER TABLE `channels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `color_dictionary`
--
ALTER TABLE `color_dictionary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `offers`
--
ALTER TABLE `offers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_colorways`
--
ALTER TABLE `product_colorways`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `size_families`
--
ALTER TABLE `size_families`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `size_family_sizes`
--
ALTER TABLE `size_family_sizes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subcategories`
--
ALTER TABLE `subcategories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_sessions`
--
ALTER TABLE `admin_sessions`
  ADD CONSTRAINT `admin_sessions_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `fk_categories_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`account_debit`) REFERENCES `accounts` (`id`),
  ADD CONSTRAINT `journal_entries_ibfk_2` FOREIGN KEY (`account_credit`) REFERENCES `accounts` (`id`);

--
-- Constraints for table `logs`
--
ALTER TABLE `logs`
  ADD CONSTRAINT `fk_logs_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `offers`
--
ALTER TABLE `offers`
  ADD CONSTRAINT `offers_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_subcategory` FOREIGN KEY (`subcategory_id`) REFERENCES `subcategories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_channels`
--
ALTER TABLE `product_channels`
  ADD CONSTRAINT `product_channels_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_channels_ibfk_2` FOREIGN KEY (`channel_id`) REFERENCES `channels` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

--
-- Constraints for table `subcategories`
--
ALTER TABLE `subcategories`
  ADD CONSTRAINT `fk_subcategories_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subcategories_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
