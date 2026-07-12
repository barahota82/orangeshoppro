<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_table_registry_lib.php';

/**
 * Authoritative Country Backup Inventory table metadata (Phase 1B.1).
 *
 * @return array<string, array<string, mixed>>
 */
function orange_backup_registry_table_definitions(): array
{
    $g = static fn (int $order, bool $critical = false): array => orange_backup_registry_row(
        'global',
        $order,
        orange_backup_registry_full_table(),
        null,
        false,
        $critical
    );

    $c = static fn (int $order, bool $critical = false, bool $uploads = false): array => orange_backup_registry_row(
        'country_owned',
        $order,
        orange_backup_registry_country_id(),
        null,
        $uploads,
        $critical
    );

    $x = static fn (int $order): array => orange_backup_registry_row(
        'excluded_ephemeral',
        $order,
        orange_backup_registry_skip(),
        null,
        false,
        false,
        9999,
        9999
    );

    $d = static fn (string $parent, string $fk, int $order, bool $critical = false, bool $uploads = false, bool $nullable = false): array => orange_backup_registry_dependent(
        $parent,
        $fk,
        $order,
        $critical,
        $uploads,
        null,
        $nullable
    );

    $tables = [
        // --- Global reference (shared catalog / platform) ---
        'countries' => $g(1, true),
        'report_line_master' => $g(2, true),
        'color_dictionary' => $g(10),
        'pattern_dictionary' => $g(11),
        'size_families' => $g(12),
        'size_family_sizes' => $g(13),
        'size_scheme_templates' => $g(14),
        'size_scheme_template_sizes' => $g(15),
        'advisory_sizing_guides' => $g(16),
        'advisory_sizing_guide_columns' => $g(17),
        'advisory_sizing_guide_rows' => $g(18),
        'advisory_sizing_guide_cells' => $g(19),
        'advisory_sizing_library_bundles' => $g(20),
        'size_family_advisory_library_map' => $g(21),
        'commercial_kind_dictionary' => $g(22),
        'sizing_category_dictionary' => $g(23),
        'catalog_attributes' => $g(24),
        'catalog_attribute_options' => $g(25),
        'product_types' => $g(26),
        'departments' => $g(30),
        'catalog_sections' => $g(31),
        'catalog_categories' => $g(32),
        'catalog_subcategories' => $g(33),
        'document_sequences' => $g(34),
        'storefront_home_hero' => $g(35),

        // --- Global schema / admin platform (not country-exported) ---
        'orange_schema_meta' => $g(100),
        'orange_schema_migrations' => $g(101),
        'orange_schema_migration_failures' => $g(102),
        'orange_catalog_schema_checkpoint' => $g(103),
        'orange_catalog_data_migration_log' => $g(104),
        'admin_permissions' => $g(105),
        'journal_entries' => $g(110),
        'orange_admin_audit_log' => $g(111),

        // --- Country-owned masters ---
        'accounts' => $c(50, true),
        'fiscal_years' => $c(51, true),
        'journal_types' => $c(52, true),
        'warehouses' => $c(53, true),
        'channels' => $c(54, true),
        'company_settings' => $c(55, true),
        'company_bank_accounts' => $c(56),
        'admins' => $c(57),
        'customers' => $c(58, true),
        'suppliers' => $c(59, true),
        'products' => $c(60, true, true),
        'delivery_governorates' => $c(61),
        'delivery_areas' => $c(62),
        'delivery_agents' => $c(63),
        'department_countries' => $c(64),
        'analytical_dimension' => $c(65),
        'loyalty_settings' => $c(66),
        'payment_methods' => $c(67),
        'storefront_copy_lines' => $c(68),
        'storefront_promo_messages' => $c(69),
        'storefront_phone_merge_requests' => $c(70),
        'storefront_accounts' => $c(71, true),
        'cart_promotions' => $c(72),
        'cart_gift_promotions' => $c(73),
        'cart_bogo_promotions' => $c(74),
        'cart_combo_promotions' => $c(75),
        'delivery_fee_promotions' => $c(76),
        'promotion_always_on_history' => $c(77),
        'promo_pause_log' => $c(78),
        'orange_gl_account_settings' => $c(79, true),
        'orange_gl_journal_type_rules' => $c(80, true),
        'orange_gl_setting_alloc' => $c(81),
        'orange_invoice_line_presets' => $c(82),
        'orange_invoice_extra_lines' => $c(83),
        'document_public_tokens' => $c(84),
        'orange_edit_lock_registry' => $c(85),
        'orange_country_screen_copy_log' => orange_backup_registry_row(
            'country_owned',
            86,
            orange_backup_registry_country_scope_or(['source_country_id', 'target_country_id']),
            null,
            false,
            false
        ),

        // --- Country-owned transactional headers ---
        'orders' => $c(100, true),
        'purchases' => $c(101, true),
        'purchase_returns' => orange_backup_registry_row(
            'dependent',
            102,
            [
                'type' => 'custom_sql',
                'description' => 'Purchase returns scoped via purchase_id → purchases.country_id or supplier_id → suppliers.country_id',
                'sql' => 'SELECT pr.id FROM purchase_returns pr LEFT JOIN purchases p ON p.id = pr.purchase_id LEFT JOIN suppliers s ON s.id = pr.supplier_id WHERE (p.id IS NOT NULL AND p.country_id = :country_id) OR (p.id IS NULL AND s.country_id = :country_id)',
            ],
            ['table' => 'purchases', 'foreign_key' => 'purchase_id', 'nullable' => true],
            false,
            true
        ),
        'sales_returns' => $c(103, true),
        'journal_vouchers' => $c(104, true),
        'bank_reconciliation' => $c(105, true),
        'opening_stock_voucher' => $c(106, true),
        'stock_adjustment_voucher' => $c(107, true),
        'inventory_reconciliation' => $c(108, true),
        'stock_movements' => $c(109, true),
        'inventory_cost_layers' => $c(110, true),
        'payment_transactions' => $c(111, true, true),
        'loyalty_ledger' => $c(112, true),
        'expenses' => orange_backup_registry_row(
            'country_owned',
            113,
            [
                'type' => 'custom_sql',
                'description' => 'Expenses scoped via expense_account_id → accounts.country_id',
                'sql' => 'SELECT e.id FROM expenses e INNER JOIN accounts a ON a.id = e.expense_account_id WHERE a.country_id = :country_id',
            ],
            null,
            false,
            false
        ),
        'orange_company_documents' => orange_backup_registry_row(
            'country_owned',
            114,
            [
                'type' => 'custom_sql',
                'description' => 'Company documents linked to country-scoped entities via entity_table/entity_id',
                'sql' => 'SELECT ocd.id FROM orange_company_documents ocd WHERE ocd.entity_table IN (\'orders\',\'purchases\',\'customers\',\'suppliers\') AND EXISTS (SELECT 1 FROM orders o WHERE ocd.entity_table = \'orders\' AND o.id = CAST(ocd.entity_id AS UNSIGNED) AND o.country_id = :country_id UNION SELECT 1 FROM purchases p WHERE ocd.entity_table = \'purchases\' AND p.id = CAST(ocd.entity_id AS UNSIGNED) AND p.country_id = :country_id UNION SELECT 1 FROM customers c WHERE ocd.entity_table = \'customers\' AND c.id = CAST(ocd.entity_id AS UNSIGNED) AND c.country_id = :country_id UNION SELECT 1 FROM suppliers s WHERE ocd.entity_table = \'suppliers\' AND s.id = CAST(ocd.entity_id AS UNSIGNED) AND s.country_id = :country_id)',
            ],
            null,
            true,
            false
        ),

        // --- Dependent rows (FK to country-owned parents) ---
        'warehouse_variant_stock' => $d('warehouses', 'warehouse_id', 150, true),
        'product_variants' => $d('products', 'product_id', 151, true),
        'product_images' => $d('products', 'product_id', 152, false, true),
        'product_colorways' => $d('products', 'product_id', 153),
        'product_colorway_images' => $d('product_colorways', 'product_colorway_id', 154, false, true),
        'product_attribute_values' => $d('products', 'product_id', 155),
        'product_channels' => $d('products', 'product_id', 156),
        'offers' => $d('products', 'product_id', 157),
        'customer_addresses' => $d('customers', 'customer_id', 158),
        'order_items' => $d('orders', 'order_id', 159, true),
        'purchase_items' => $d('purchases', 'purchase_id', 160, true),
        'purchase_return_items' => $d('purchase_returns', 'purchase_return_id', 161, true),
        'sales_return_items' => $d('sales_returns', 'sales_return_id', 162, true),
        'journal_lines' => $d('journal_vouchers', 'journal_voucher_id', 163, true),
        'orange_gl_voucher_slots' => $d('journal_vouchers', 'journal_voucher_id', 164, true),
        'orange_gl_pending_movements' => orange_backup_registry_row(
            'dependent',
            165,
            [
                'type' => 'custom_sql',
                'description' => 'Pending GL movements scoped via journal_vouchers.country_id',
                'sql' => 'SELECT ogpm.id FROM orange_gl_pending_movements ogpm INNER JOIN journal_vouchers jv ON jv.id = ogpm.journal_voucher_id WHERE jv.country_id = :country_id',
            ],
            ['table' => 'journal_vouchers', 'foreign_key' => 'journal_voucher_id'],
            false,
            true
        ),
        'party_subledger' => orange_backup_registry_row(
            'dependent',
            166,
            [
                'type' => 'custom_sql',
                'description' => 'Party subledger scoped via journal_vouchers.country_id',
                'sql' => 'SELECT ps.id FROM party_subledger ps INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id WHERE jv.country_id = :country_id',
            ],
            ['table' => 'journal_vouchers', 'foreign_key' => 'voucher_id'],
            false,
            true
        ),
        'party_subledger_allocations' => orange_backup_registry_row(
            'dependent',
            167,
            [
                'type' => 'custom_sql',
                'description' => 'Party subledger allocations scoped via payment journal_vouchers.country_id',
                'sql' => 'SELECT psa.id FROM party_subledger_allocations psa INNER JOIN journal_vouchers jv ON jv.id = psa.payment_voucher_id WHERE jv.country_id = :country_id',
            ],
            ['table' => 'journal_vouchers', 'foreign_key' => 'payment_voucher_id'],
            false,
            true
        ),
        'bank_reconciliation_line' => $d('bank_reconciliation', 'bank_reconciliation_id', 168, true),
        'opening_stock_voucher_line' => $d('opening_stock_voucher', 'voucher_id', 169, true),
        'stock_adjustment_voucher_line' => $d('stock_adjustment_voucher', 'voucher_id', 170, true),
        'stock_adjustment_voucher_gl' => $d('stock_adjustment_voucher', 'voucher_id', 171, true),
        'inventory_reconciliation_line' => $d('inventory_reconciliation', 'inventory_reconciliation_id', 172, true),
        'inventory_cost_consumptions' => orange_backup_registry_row(
            'dependent',
            173,
            [
                'type' => 'custom_sql',
                'description' => 'Cost consumptions scoped via inventory_cost_layers.country_id',
                'sql' => 'SELECT icc.id FROM inventory_cost_consumptions icc INNER JOIN inventory_cost_layers icl ON icl.id = icc.layer_id WHERE icl.country_id = :country_id',
            ],
            ['table' => 'inventory_cost_layers', 'foreign_key' => 'layer_id'],
            false,
            true
        ),
        'analytical_dimension_value' => $d('analytical_dimension', 'dimension_id', 174),
        'delivery_fee_promotion_governorates' => $d('delivery_fee_promotions', 'promotion_id', 175),
        'delivery_fee_promotion_areas' => $d('delivery_fee_promotions', 'promotion_id', 176),

        // --- Excluded ephemeral (not part of country recovery inventory export) ---
        'admin_sessions' => $x(900),
        'orange_admin_login_throttle' => $x(901),
        'logs' => $x(902),
        'order_intake_queue' => $x(903),
        'promo_stock_check' => $x(904),
    ];

    ksort($tables, SORT_STRING);

    return $tables;
}
