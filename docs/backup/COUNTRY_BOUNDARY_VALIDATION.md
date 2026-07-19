# Country Restore Boundary Validation (Phase C1)

**Status:** ARCHITECTURE VALIDATION ONLY — no implementation, no restore engine, no production changes  
**Date:** 2026-07-19  
**Input (C0):** `docs/backup/COUNTRY_RESTORE_ARCHITECTURE.md`  
**Schema truth:** `scripts/orange_db.sql` — **117** tables, **87** FK constraints (ALTER TABLE)  
**Registry:** `config/backup_table_registry.json` — v1.0 / schema_revision **121**  
**Also read:** accounting mapping, unified taxonomy, multi-country vision, stock/FIFO/order policy, Full DR restore design

---

## Validation summary

### Verdict

**C0 as written did NOT fully survive adversarial validation.**

Seven (7) classifications were disproved by schema FK ownership, missing `country_id`, cross-country row shape, or encoded country scope.  
**110 / 117** classifications were confirmed.

Therefore this phase **does not** declare:

> Country Restore Boundary Model validated.

Instead it produces the **Corrected Country Restore Boundary Classification** (section «Corrected classification»). After owner decisions in section «Owner decisions required», that corrected matrix — not unmodified C0 — becomes the candidate source of truth for later Country phases.

### Method (attempt to disprove)

1. Parse every `CREATE TABLE` in `orange_db.sql` for `country_id` nullability.  
2. Parse every `ALTER TABLE … FOREIGN KEY` (87 edges) and join to registry ownership.  
3. Challenge Global→Country FKs, Country tables without `country_id`, Mixed tables with direct `country_id`, and cross-country operational logs.  
4. Cross-check accounting / taxonomy / warehouse / FIFO / users policy archives vs registry.  
5. Inspect `document_sequences` runtime encoding (`_c{countryId}` in `includes/document_sequences.php`).

### Headline findings

| Finding | Impact |
|---------|--------|
| `admin_permissions` is **per-admin grants**, not templates | Global→**Mixed**; composite with `admins` |
| `document_sequences` encodes country in `scope` suffix | Global→**Mixed** + **special handler** |
| `journal_entries` FKs into country `accounts` / `fiscal_years` | Global→**Mixed** but **CRP ignore / Full-only** |
| `expenses`, `orange_company_documents` lack `country_id` | Country→**Mixed** |
| `orange_country_screen_copy_log` is always two-country | Country→**Global** (never CRP mutate) |
| `orange_gl_voucher_slots` has `country_id` | Mixed→**Country Scoped** |
| Widespread **nullable** `country_id` on country_owned tables | Confirmed class, but High leakage risk if predicates allow NULL |

### Counts

| Result | Count |
|--------|------:|
| Validated YES (C0 class stands) | **110** |
| Validated NO (reclassified) | **7** |
| Total tables | **117** |

### Corrected class totals (after C1)

| Classification | Count | Notes |
|----------------|------:|-------|
| Country Scoped | **49** | C0 51 − expenses − company_documents − copy_log + voucher_slots |
| Mixed | **32** | C0 28 + admin_permissions + document_sequences + journal_entries + expenses + company_documents − voucher_slots |
| Global | **36** | C0 38 − admin_permissions − document_sequences − journal_entries + copy_log |

*(Arithmetic check: 49+32+36 = 117.)*

---

## Detection catalog

### Tables marked Country but actually Global

| table | Why |
|-------|-----|
| `orange_country_screen_copy_log` | Every row has `source_country_id` **and** `target_country_id`. Country OR-replace mutates sibling-country history. |

### Tables marked Global but actually Mixed

| table | Why |
|-------|-----|
| `admin_permissions` | FK `admin_id` → `admins`; row is a grant, not a shared dictionary |
| `document_sequences` | Shared physical table; logical slices via `scope` + `_c{countryId}` |
| `journal_entries` | Transactional legacy rows FK to country `accounts` / `fiscal_years` |

### Tables marked Mixed but can become Country

| table | Why |
|-------|-----|
| `orange_gl_voucher_slots` | Direct `country_id` column; can extract by country (still graph-ordered with vouchers) |

### Tables impossible to restore independently

| table | Why |
|-------|-----|
| All Mixed dependents | Require parent Country graph |
| `journal_entries` | Country-linked but unsafe for CRP replace; Full-only |
| `orange_country_screen_copy_log` | Cross-country by construction |
| `document_sequences` | Cannot full-replace or full-ignore without numbering harm |

### Tables requiring composite restore

| Group | Tables |
|-------|--------|
| Admin authz | `admins` (country slice) + `admin_permissions` (grants for those admin ids) |
| Polymorphic docs | `orange_company_documents` + parent entities (`orders`/`purchases`/`customers`/`suppliers`) + uploads |
| Expenses | `expenses` + `accounts` |
| GL voucher identity | `journal_vouchers` + `journal_lines` + `orange_gl_voucher_slots` + `party_subledger*` |
| Stock/FIFO | `warehouses` + `warehouse_variant_stock` + `stock_movements` + `inventory_cost_layers` + `inventory_cost_consumptions` |
| Catalog SKU | `products` + variants/colorways/images/channels/attributes/offers |

### Tables requiring special handler

| table | Handler |
|-------|---------|
| `document_sequences` | Upsert/replace only scopes matching `%_c{target}` (and non-suffixed global scopes policy — owner decision) |
| `admins` | Delete/import `country_id = target` only; never NULL/global admins |
| `orange_company_documents` | Polymorphic EXISTS extract; uploads path bind |
| `journal_entries` | **Never** Country mutate; Full DR only |
| `orange_country_screen_copy_log` | **Never** Country mutate |
| Nullable `country_id` country tables | Predicates must be `country_id = :target` (reject NULL in CRP slice) |

### Registry / schema mismatches (not class changes, but restore hazards)

| child | registry FK column | schema FK column |
|-------|--------------------|------------------|
| `journal_lines` | `journal_voucher_id` | `voucher_id` |
| `bank_reconciliation_line` | `bank_reconciliation_id` | `reconciliation_id` |
| `inventory_reconciliation_line` | `inventory_reconciliation_id` | `reconciliation_id` |
| `orange_gl_voucher_slots` | parent `journal_vouchers` via `journal_voucher_id` | **no FK constraint** in dump (logical only) |

These must be fixed in a future implementation phase; they do not change boundary class but block safe automation.

---

## Tables changed (Validated = NO)

| table | C0 class | New class | CRP restore mode | Reason | Risk |
|-------|----------|-----------|------------------|--------|------|
| `admin_permissions` | Global | **Mixed** | replace (with country admins) | Per-admin grants via `admin_id` | High |
| `document_sequences` | Global | **Mixed** | **special** (scope `_c{N}` only) | Country encoded in scope suffix | High |
| `expenses` | Country Scoped | **Mixed** | replace via accounts | No `country_id` | Medium |
| `journal_entries` | Global | **Mixed** | **ignore** (Full-only) | FK into country accounts/FY | Critical if mutated |
| `orange_company_documents` | Country Scoped | **Mixed** | replace (polymorphic) | No `country_id`; multi-parent | High |
| `orange_country_screen_copy_log` | Country Scoped | **Global** | ignore | Cross-country log | Critical if Country-replaced |
| `orange_gl_voucher_slots` | Mixed | **Country Scoped** | replace | Has `country_id` | Low–Medium |

---

## Tables confirmed (Validated = YES)

110 tables keep their C0 class. Full per-table answers are in the matrix below (`Validated? = YES`).

Notable confirmed groups:

- **Global taxonomy / dictionaries** (`departments`, catalog_*, `product_types`, size/color/pattern/advisory) — shared lookups; ignore stands.  
- **Country sellable catalog** (`products` + Mixed children) — owner per-country catalog; stands.  
- **Warehouses / stock / FIFO** — country separation stands.  
- **GL country chart** (`accounts`, `journal_vouchers`, `journal_lines`, settings) — stands, with nullable `country_id` caveat.  
- **Ephemeral** (`admin_sessions`, `logs`, `order_intake_queue`, `promo_stock_check`, …) — ignore stands (`promo_stock_check` has `country_id` but remains ephemeral).  
- **`storefront_home_hero` Global** vs **`storefront_copy_lines` Country** — stands (legacy vs scoped copy).

---

## Per-table validation (all 117)

Columns: `table` · `current classification` · `Validated?` · `new classification` · `reason` · `risk`

| `accounts` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `admin_permissions` | Global | NO | Mixed | Per-admin grants (admin_id FK → admins), not platform templates; must restore with country admin graph | High — country admin replace without permissions leaves operators unauthorized; CASCADE on admin delete drops grants |
| `admin_sessions` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `admins` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `advisory_sizing_guide_cells` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `advisory_sizing_guide_columns` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `advisory_sizing_guide_rows` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `advisory_sizing_guides` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `advisory_sizing_library_bundles` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `analytical_dimension` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `analytical_dimension_value` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `bank_reconciliation` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `bank_reconciliation_line` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `cart_bogo_promotions` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `cart_combo_promotions` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `cart_gift_promotions` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `cart_promotions` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `catalog_attribute_options` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `catalog_attributes` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `catalog_categories` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `catalog_sections` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `catalog_subcategories` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `channels` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `color_dictionary` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `commercial_kind_dictionary` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `company_bank_accounts` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `company_settings` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `countries` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `customer_addresses` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `customers` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `delivery_agents` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `delivery_areas` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `delivery_fee_promotion_areas` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `delivery_fee_promotion_governorates` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `delivery_fee_promotions` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `delivery_governorates` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `department_countries` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `departments` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `document_public_tokens` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `document_sequences` | Global | NO | Mixed | Shared table but scopes encode country via suffix _c{countryId} (orange_sequence_next); not a pure ignore Global blob for CRP | High — ignoring all sequences after country replace desyncs voucher/document numbering; full replace corrupts other countries |
| `expenses` | Country Scoped | NO | Mixed | No country_id; ownership only via expense_account_id → accounts.country_id | Medium — treating as independent Country Scoped invites incomplete extract/delete |
| `fiscal_years` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `inventory_cost_consumptions` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `inventory_cost_layers` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `inventory_reconciliation` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `inventory_reconciliation_line` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `journal_entries` | Global | NO | Mixed | Legacy transactional table with FKs into country-owned accounts and fiscal_years — not a shared reference dictionary | Critical if CRP ever replaces it; must remain Full-only / ignore for Country apply |
| `journal_lines` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `journal_types` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `journal_vouchers` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `logs` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `loyalty_ledger` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `loyalty_settings` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `offers` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `opening_stock_voucher` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `opening_stock_voucher_line` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_admin_audit_log` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_admin_login_throttle` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_catalog_data_migration_log` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_catalog_schema_checkpoint` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_company_documents` | Country Scoped | NO | Mixed | Polymorphic entity_table/entity_id; no country_id; custom multi-parent SQL | High — incomplete entity allowlist leaks docs or orphans uploads |
| `orange_country_screen_copy_log` | Country Scoped | NO | Global | Rows always bind source_country_id AND target_country_id — inherently cross-country; country OR-delete mutates sibling history | Critical — Country replace deletes/rewrites logs involving other countries |
| `orange_edit_lock_registry` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_gl_account_settings` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_gl_journal_type_rules` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_gl_pending_movements` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_gl_setting_alloc` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_gl_voucher_slots` | Mixed | NO | Country Scoped | Has country_id; can extract by country_id (also links journal_vouchers). Mixed parent-only understates direct country ownership | Low-Medium — NULL country_id rows need policy; still order with vouchers |
| `orange_invoice_extra_lines` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_invoice_line_presets` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_schema_meta` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_schema_migration_failures` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orange_schema_migrations` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `order_intake_queue` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `order_items` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `orders` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `party_subledger` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `party_subledger_allocations` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `pattern_dictionary` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `payment_methods` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `payment_transactions` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `product_attribute_values` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `product_channels` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `product_colorway_images` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `product_colorways` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `product_images` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `product_types` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `product_variants` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `products` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `promo_pause_log` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `promo_stock_check` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `promotion_always_on_history` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `purchase_items` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `purchase_return_items` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `purchase_returns` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `purchases` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `report_line_master` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `sales_return_items` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `sales_returns` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `size_families` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `size_family_advisory_library_map` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `size_family_sizes` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `size_scheme_template_sizes` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `size_scheme_templates` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `sizing_category_dictionary` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `stock_adjustment_voucher` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `stock_adjustment_voucher_gl` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `stock_adjustment_voucher_line` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `stock_movements` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `storefront_accounts` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. Nullable country_id: delete/extract MUST require country_id=:target (never NULL). | Medium — NULL country_id leakage if predicates weak |
| `storefront_copy_lines` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `storefront_home_hero` | Global | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `storefront_phone_merge_requests` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `storefront_promo_messages` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `suppliers` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `warehouse_variant_stock` | Mixed | YES | — | Schema/registry/FK graph supports C0 class. | Low |
| `warehouses` | Country Scoped | YES | — | Schema/registry/FK graph supports C0 class. | Low |

---

## Corrected classification

Use this matrix as the **candidate source of truth** after owner decisions. C0 remains historical design context.

| table | corrected classification | restore mode | replace | merge | ignore | special handler | notes |
|-------|--------------------------|--------------|---------|-------|--------|-----------------|-------|
| `accounts` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `admin_permissions` | Mixed | replace | yes | no | no | composite_with_admins | C1 reclassification |
| `admin_sessions` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `admins` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `advisory_sizing_guide_cells` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `advisory_sizing_guide_columns` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `advisory_sizing_guide_rows` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `advisory_sizing_guides` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `advisory_sizing_library_bundles` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `analytical_dimension` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `analytical_dimension_value` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `bank_reconciliation` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `bank_reconciliation_line` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `cart_bogo_promotions` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `cart_combo_promotions` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `cart_gift_promotions` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `cart_promotions` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `catalog_attribute_options` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `catalog_attributes` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `catalog_categories` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `catalog_sections` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `catalog_subcategories` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `channels` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `color_dictionary` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `commercial_kind_dictionary` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `company_bank_accounts` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `company_settings` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `countries` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `customer_addresses` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `customers` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `delivery_agents` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `delivery_areas` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `delivery_fee_promotion_areas` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `delivery_fee_promotion_governorates` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `delivery_fee_promotions` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `delivery_governorates` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `department_countries` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `departments` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `document_public_tokens` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `document_sequences` | Mixed | special | special | special | no | scope_suffix_handler | C1 reclassification |
| `expenses` | Mixed | replace | yes | no | no | via_accounts | C1 reclassification |
| `fiscal_years` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `inventory_cost_consumptions` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `inventory_cost_layers` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `inventory_reconciliation` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `inventory_reconciliation_line` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `journal_entries` | Mixed | ignore | no | no | yes | full_only_never_crp | C1 reclassification |
| `journal_lines` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `journal_types` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `journal_vouchers` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `logs` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `loyalty_ledger` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `loyalty_settings` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `offers` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `opening_stock_voucher` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `opening_stock_voucher_line` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `orange_admin_audit_log` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `orange_admin_login_throttle` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `orange_catalog_data_migration_log` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `orange_catalog_schema_checkpoint` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `orange_company_documents` | Mixed | replace | yes | no | no | polymorphic_composite | C1 reclassification |
| `orange_country_screen_copy_log` | Global | ignore | no | no | yes | never_crp_mutate | C1 reclassification |
| `orange_edit_lock_registry` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `orange_gl_account_settings` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `orange_gl_journal_type_rules` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `orange_gl_pending_movements` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `orange_gl_setting_alloc` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `orange_gl_voucher_slots` | Country Scoped | replace | yes | no | no | prefer_country_id_plus_voucher_graph | C1 reclassification |
| `orange_invoice_extra_lines` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `orange_invoice_line_presets` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `orange_schema_meta` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `orange_schema_migration_failures` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `orange_schema_migrations` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `order_intake_queue` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `order_items` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `orders` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `party_subledger` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `party_subledger_allocations` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `pattern_dictionary` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `payment_methods` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `payment_transactions` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `product_attribute_values` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `product_channels` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `product_colorway_images` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `product_colorways` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `product_images` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `product_types` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `product_variants` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `products` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `promo_pause_log` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `promo_stock_check` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `promotion_always_on_history` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `purchase_items` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `purchase_return_items` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `purchase_returns` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `purchases` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `report_line_master` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `sales_return_items` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `sales_returns` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `size_families` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `size_family_advisory_library_map` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `size_family_sizes` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `size_scheme_template_sizes` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `size_scheme_templates` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `sizing_category_dictionary` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `stock_adjustment_voucher` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `stock_adjustment_voucher_gl` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `stock_adjustment_voucher_line` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `stock_movements` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `storefront_accounts` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `storefront_copy_lines` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `storefront_home_hero` | Global | ignore | no | no | yes | — | Confirmed from C0 |
| `storefront_phone_merge_requests` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `storefront_promo_messages` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `suppliers` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |
| `warehouse_variant_stock` | Mixed | replace | yes | no | no | — | Confirmed from C0 |
| `warehouses` | Country Scoped | replace | yes | no | no | — | Confirmed from C0 |

### Corrected counts

| Classification | Count |
|----------------|------:|
| Country Scoped | 49 |
| Mixed | 32 |
| Global | 36 |
| **Total** | **117** |

---

## Risk matrix

| ID | Risk | Severity | Likelihood | Detection | Mitigation (design) |
|----|------|----------|------------|-----------|---------------------|
| R1 | Country admin replace without dmin_permissions | High | High | Authz smoke after shadow | Composite restore admins+permissions |
| R2 | document_sequences desync after CRP | High | High | Compare max voucher serial vs sequence | Special scope _c{N} handler |
| R3 | orange_country_screen_copy_log OR-delete hits other country | Critical | Medium | Pre/post counts on sibling country | Reclassify Global / never mutate |
| R4 | NULL country_id rows deleted or left orphan | High | Medium | Leakage probes | Predicate country_id = :target only |
| R5 | journal_entries mistaken CRP replace | Critical | Low | Forbidden SQL / ignore list | Full-only; never CRP |
| R6 | Polymorphic company documents incomplete | High | Medium | Orphan file/entity checks | Explicit entity allowlist + uploads |
| R7 | Registry FK column name drift | High | High | Staging import failures | Fix registry before implementation |
| R8 | Global taxonomy ID drift vs package products | High | Medium | FK closure on shadow | Taxonomy checksum gate (cert) |
| R9 | FIFO/stock cross-country warehouse FK | Critical | Low | Witness country stock counts | Warehouse graph filters |
| R10 | Expenses via wrong account country | Medium | Medium | Join accounts.country_id | Mixed via accounts only |

---

## Confidence score

| Lens | Score (0–100) | Explanation |
|------|---------------|-------------|
| **C0 unmodified as SoT** | **62** | 110/117 confirmed, but 7 material disproofs + nullable country_id systemic risk |
| **C1 corrected matrix as SoT** | **78** | Classes reconciled to schema/FK/runtime; residual risk from NULL country_id, registry FK typos, sequences special handler, owner decisions open |
| **Ready for Country implementation** | **0** | Explicitly out of scope; production Country remains disabled |

Scoring notes: deducted for Critical/High reclass findings, nullable country_id prevalence, and registry FK mismatches. Added credit for complete 117 coverage and clear special-handler list.

---

## Open questions

1. Should NULL country_id rows on ccounts / products / channels / etc. be treated as illegal data (fail cert) or as intentional legacy shared rows (exclude from CRP delete)?  
2. For document_sequences, do any **non-suffixed** scopes still exist in production that are country-meaningful?  
3. Should orange_country_screen_copy_log be retained forever on CRP (ignore) or archived out-of-band?  
4. Is journal_entries still written by any live path, or fully superseded by journal_vouchers/journal_lines?  
5. When replacing country dmins, must Super Admin dual-control identities be proven non-members of that slice?  
6. Who fixes registry FK column mismatches (definitions generator) before C2+?

---

## Owner decisions required

| # | Decision | Options | Recommendation |
|---|----------|---------|----------------|
| D1 | Accept C1 corrected matrix as SoT (superseding C0 for the 7 tables) | Accept / Reject / Amend | **Accept** |
| D2 | NULL country_id policy for CRP | Fail closed / Exclude NULL / Treat NULL as Global | **Fail closed** if NULL count > 0 on integrity-critical tables; else exclude NULL from delete |
| D3 | document_sequences handler | Special _c{N} upsert / Ignore entirely / Full-table forbid only | **Special _c{N} upsert** aligned to package |
| D4 | dmin_permissions with country admins | Composite replace / Ignore permissions | **Composite replace** |
| D5 | orange_country_screen_copy_log | Global ignore / Country OR-replace | **Global ignore** |
| D6 | journal_entries | Mixed ignore Full-only / Attempt country filter | **Mixed + ignore (Full-only)** |

Until D1–D6 are answered, later Country implementation phases must not treat unmodified C0 as final law.

---

## Explicit non-validation statement

Because classifications changed under adversarial review:

**Country Restore Boundary Model validated.** ← **NOT declared.**

**Declared instead:**

**Country Restore Boundary Model challenged; corrected classification produced (Phase C1).**

C0 remains useful design narrative; **boundary SoT for subsequent phases = this document’s corrected matrix**, subject to owner decisions D1–D6.

---

## Appendix — Evidence pointers

| Evidence | Location |
|----------|----------|
| FK Global→Country | dmin_permissions→dmins; journal_entries→ccounts/iscal_years; audit/logs/sessions→dmins |
| Sequence country suffix | includes/document_sequences.php (scope .= '_c' . countryId) |
| Copy log two-country columns | orange_country_screen_copy_log CREATE TABLE |
| Voucher slots country_id | orange_gl_voucher_slots CREATE TABLE |
| C0 matrix | docs/backup/COUNTRY_RESTORE_ARCHITECTURE.md §3 |
| Registry | config/backup_table_registry.json |
| Schema | scripts/orange_db.sql |

---

*End of Phase C1 — Country Restore Boundary Validation (documentation only).*
