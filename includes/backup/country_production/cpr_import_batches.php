<?php

declare(strict_types=1);

/**
 * Frozen CPR restore-batch catalog (WP-P5-03).
 *
 * Table lists are copied from docs/backup/COUNTRY_DEPENDENCY_GRAPH.md §4
 * (Restore Batches 1→6). Do not invent, merge, reorder, or skip batches.
 *
 * @see docs/backup/COUNTRY_DEPENDENCY_GRAPH.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md §10.2 A
 */

const ORANGE_CPR_IMPORT_ORDER_VERSION = 'c1.1-import_order/1';
const ORANGE_CPR_IMPORT_DEPENDENCY_GRAPH_VERSION = '1';

/**
 * Ordered restore batch numbers (Architecture: batches 1→6).
 *
 * @return list<int>
 */
function orange_cpr_import_batch_numbers(): array
{
    return [1, 2, 3, 4, 5, 6];
}

/**
 * Frozen table lists per restore batch (dependency graph order within batch).
 *
 * @return array<int, list<string>>
 */
function orange_cpr_import_batch_tables_map(): array
{
    return [
        1 => [
            'accounts',
            'admins',
            'analytical_dimension',
            'cart_bogo_promotions',
            'cart_combo_promotions',
            'cart_gift_promotions',
            'cart_promotions',
            'channels',
            'company_bank_accounts',
            'company_settings',
            'customers',
            'delivery_agents',
            'delivery_areas',
            'delivery_fee_promotions',
            'delivery_governorates',
            'department_countries',
            'document_public_tokens',
            'fiscal_years',
            'journal_types',
            'loyalty_ledger',
            'loyalty_settings',
            'opening_stock_voucher',
            'orange_edit_lock_registry',
            'orange_gl_setting_alloc',
            'payment_methods',
            'payment_transactions',
            'products',
            'promo_pause_log',
            'promotion_always_on_history',
            'stock_adjustment_voucher',
            'storefront_accounts',
            'storefront_copy_lines',
            'storefront_phone_merge_requests',
            'storefront_promo_messages',
            'suppliers',
            'warehouses',
        ],
        2 => [
            'admin_permissions',
            'analytical_dimension_value',
            'bank_reconciliation',
            'customer_addresses',
            'delivery_fee_promotion_areas',
            'delivery_fee_promotion_governorates',
            'expenses',
            'inventory_reconciliation',
            'journal_vouchers',
            'offers',
            'opening_stock_voucher_line',
            'orange_gl_account_settings',
            'orange_gl_journal_type_rules',
            'orange_invoice_line_presets',
            'orders',
            'product_attribute_values',
            'product_channels',
            'product_colorways',
            'product_images',
            'purchases',
            'stock_adjustment_voucher_gl',
            'stock_adjustment_voucher_line',
        ],
        3 => [
            'bank_reconciliation_line',
            'inventory_reconciliation_line',
            'journal_lines',
            'orange_company_documents',
            'orange_gl_pending_movements',
            'orange_gl_voucher_slots',
            'orange_invoice_extra_lines',
            'party_subledger',
            'party_subledger_allocations',
            'product_colorway_images',
            'product_variants',
            'purchase_returns',
            'sales_returns',
        ],
        4 => [
            'inventory_cost_layers',
            'order_items',
            'purchase_items',
            'purchase_return_items',
            'sales_return_items',
            'stock_movements',
            'warehouse_variant_stock',
        ],
        5 => [
            'inventory_cost_consumptions',
        ],
        6 => [
            'document_sequences',
        ],
    ];
}

/**
 * @return list<string>
 */
function orange_cpr_import_batch_tables(int $batch): array
{
    $map = orange_cpr_import_batch_tables_map();
    if (!isset($map[$batch])) {
        throw new InvalidArgumentException('Unknown restore batch: ' . (string) $batch);
    }

    return $map[$batch];
}

/**
 * Parent → child referential edges used for fail-closed RI checks inside target slice.
 *
 * @return array<string, list<string>> child_table => parent_tables
 */
function orange_cpr_import_referential_parents(): array
{
    return [
        'order_items' => ['orders'],
        'product_channels' => ['products'],
        'product_attribute_values' => ['products'],
        'product_colorways' => ['products'],
        'product_images' => ['products'],
        'product_variants' => ['products'],
        'product_colorway_images' => ['product_colorways'],
        'customer_addresses' => ['customers'],
        'purchase_items' => ['purchases'],
        'purchase_return_items' => ['purchase_returns'],
        'sales_return_items' => ['sales_returns'],
        'journal_lines' => ['journal_vouchers'],
        'opening_stock_voucher_line' => ['opening_stock_voucher'],
        'stock_adjustment_voucher_line' => ['stock_adjustment_voucher'],
        'stock_adjustment_voucher_gl' => ['stock_adjustment_voucher'],
        'inventory_cost_consumptions' => ['inventory_cost_layers'],
        'admin_permissions' => ['admins'],
    ];
}
