<?php

declare(strict_types=1);

/**
 * Ensures catalog tables and columns for colors, size families, colorways, and variant FKs exist.
 * Safe to call multiple times per request (uses static guard).
 */
function orange_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $k = $table . '.' . $column;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if ($exists) {
        $cache[$k] = true;
    }
    return $exists;
}

function orange_catalog_safe_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] catalog_schema: ' . $e->getMessage());
        }
    }
}

function orange_catalog_ensure_schema(PDO $pdo): void
{
    // Per-connection charset (avoids editing config.php; some hosts break PDO::MYSQL_ATTR_INIT_COMMAND).
    static $charsetApplied = false;
    if (!$charsetApplied) {
        orange_catalog_safe_exec($pdo, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $charsetApplied = true;
    }

    static $done = false;
    if ($done) {
        return;
    }

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS color_dictionary (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            hex_code VARCHAR(16) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_families (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_family_sizes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            size_family_id INT NOT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_size_family_sizes_family (size_family_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS product_colorways (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            primary_color_id INT NULL,
            secondary_color_id INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product_colorways_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (!orange_table_has_column($pdo, 'products', 'size_family_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN size_family_id INT NULL');
    }
    if (!orange_table_has_column($pdo, 'products', 'sizing_guide_scope')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE products ADD COLUMN sizing_guide_scope VARCHAR(16) NOT NULL DEFAULT 'none'"
        );
    }
    if (!orange_table_has_column($pdo, 'product_variants', 'product_colorway_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants ADD COLUMN product_colorway_id INT NULL');
    }
    if (!orange_table_has_column($pdo, 'product_variants', 'size_family_size_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants ADD COLUMN size_family_size_id INT NULL');
    }
    if (!orange_table_has_column($pdo, 'order_items', 'variant_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE order_items ADD COLUMN variant_id INT NULL');
    }
    if (!orange_table_has_column($pdo, 'orders', 'order_source')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE orders ADD COLUMN order_source VARCHAR(32) NOT NULL DEFAULT 'website'"
        );
    }
    if (!orange_table_has_column($pdo, 'size_family_sizes', 'foot_length_cm')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE size_family_sizes ADD COLUMN foot_length_cm DECIMAL(6,2) NULL');
    }

    $done = true;
}
