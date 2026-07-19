# Country Restore Architecture (Phase C0)

**Status:** HISTORICAL DESIGN INPUT ONLY — superseded as boundary SoT by Phase C1.1  
**Date:** 2026-07-19  
**Schema truth:** `scripts/orange_db.sql` (local mysqldump) — **117** `CREATE TABLE`  
**Authoritative registry:** `config/backup_table_registry.json` — `registry_version` **1.0**, `schema_revision` **121**, `table_count` **117**  
**Ownership summary (registry):** global **33** · country_owned **51** · dependent **28** · excluded_ephemeral **5**  
**Related Full DR (engineering-complete platform):** `docs/backup/RESTORE_EXECUTION_DESIGN.md`, restore engine / rollback / approval / shadow / certification artifacts under `docs/backup/` and `includes/backup/restore/`  
**Policy inputs:** `docs/archive/ORANGE_OWNER_MULTICOUNTRY_VISION.txt`, `ORANGE_ACCOUNTING_MAPPING_AND_REPORT_HANDOFF.txt`, `ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt`, `ORANGE_STOCK_ORDER_POLICY.txt`

**Boundary SoT (frozen — Phase C1.1):** `docs/backup/COUNTRY_RESTORE_BOUNDARY_POLICY.md`  
**Approved classification matrix (D1):** `docs/backup/COUNTRY_BOUNDARY_VALIDATION.md` § Corrected classification  

**Do not** treat this C0 document’s §3 matrix as the Country Restore source of truth. C1 challenged it; C1.1 froze owner policy on the corrected matrix.

**Non-goals of C0:** no Country production enablement, no code patches, no refactor, no feature work. This document is retained as design narrative / history.

**Predecessor constraint (Full DR Round 3):** Country Restore production remains **disabled** until a separate Country certification program closes.

---

## 1. Country restore philosophy

Country Restore (CRP) recovers **one country's operational slice** of a multi-country Orange database and its country-scoped uploads — without treating the platform as a single-tenant Full Disaster Recovery.

### Core principles

1. **Full DR is the platform; Country Restore is a scoped product on that platform.**  
   CRP reuses Full DR controls: maintenance, execution locks, mandatory pre-restore **Full** backup as rollback anchor, staging/shadow, approval, smoke, observability. CRP does **not** invent a second restore engine.

2. **Surgical replace of one `country_id`, never a Full cutover by another name.**  
   Database mutation is delete-then-import (replace semantics) for Country Scoped + Mixed tables that belong to the target country. Global tables are **ignored**. Other countries' rows are **untouched**.

3. **Shared platform state is sacred.**  
   Schema meta, migrations, country master, global taxonomy, report line master, permission templates, document sequences, ephemeral sessions/logs — **out of CRP mutate set**. Corrupting these would damage every country.

4. **Owner multi-country vision is the business boundary.**  
   Per `ORANGE_OWNER_MULTICOUNTRY_VISION.txt`: catalog **per country**; warehouses/stock **separated by country**; channels per country; admins may be global (`NULL` country) or country-scoped. CRP must encode that separation, not reintroduce a “shared SKU / shared stock” model.

5. **Fail closed until certified.**  
   Staging drills and matrix compliance are mandatory. Production Country apply stays disabled until Country certification (section 20) passes. Incomplete delete graphs, cross-country FK ambiguity, or uploads path bleed → abort, never “best effort” apply.

6. **Rollback preference is Full anchor, not partial country undo.**  
   After production touch, the guaranteed recovery path is the pinned pre-restore Full package (DB + uploads). Country-only inverse delete/import is **not** the primary rollback design (section 18).

7. **Packages are data + country uploads only.**  
   Application PHP, `.env.php`, Plesk/IIS config, and schema revision gates are never restored by CRP (same as Full).

8. **Registry is law for table membership.**  
   Every table in schema revision 121 appears in section 3. Ownership types map to Global / Country Scoped / Mixed. Future schema revisions require regenerating this matrix before certifying CRP against that revision.

---

## 2. Definition of Global, Country Scoped, Mixed

| Term | Meaning for Country Restore | Registry `ownership_type` | Default restore mode |
|------|----------------------------|---------------------------|----------------------|
| **Global** | Platform-wide or non-country-owned data. CRP **must not** INSERT/UPDATE/DELETE these tables (or must ignore them even if a malicious package mentions them). Includes ephemeral/runtime tables. | `global`, `excluded_ephemeral` | **ignore** |
| **Country Scoped** | Rows (or whole logical entity set) owned by exactly one `country_id` via a direct country column or an explicit country extraction rule. CRP may **replace** the target country's slice only. | `country_owned` | **replace** |
| **Mixed** | No direct `country_id` (or incomplete), but **logically owned** through a parent Country Scoped graph (FK chain). CRP restores them only as part of that parent graph using registry `parent_dependency` + `delete_order` / `restore_order`. | `dependent` | **replace** (with parent graph) |

### Operational notes

- **replace** = delete target-country slice (or dependent rows reachable from that slice) in registry `delete_order`, then import package rows in `restore_order`, preserving package primary keys where policy requires (section 12).
- **merge** = not the default CRP mode for revision 121. Merge is reserved for a future explicit policy (section 15); matrix column `merge` is **no** for all tables in this design.
- **ignore** = no mutation; package validators reject forbidden Global SQL where listed; staging clear must not touch ignore tables.

### Classification mapping used in section 3

| Registry ownership | Architecture classification |
|--------------------|----------------------------|
| `global` | Global |
| `excluded_ephemeral` | Global (never CRP) |
| `country_owned` | Country Scoped |
| `dependent` | Mixed |

---

## 3. Complete table classification

**Completeness:** All **117** tables from `scripts/orange_db.sql` / `config/backup_table_registry.json` (schema_revision **121**). No omissions.

**Columns:** `table` · `classification` · `restore mode` · `replace` · `merge` · `ignore` · `reason`
| table | classification | restore mode | replace | merge | ignore | reason |
|-------|----------------|--------------|---------|-------|--------|--------|
| `accounts` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `admin_permissions` | Global | ignore | no | no | yes | Global permission templates; not country-replaced |
| `admin_sessions` | Global | ignore | no | no | yes | Ephemeral/runtime; skip in CRP export and restore |
| `admins` | Country Scoped | replace | yes | no | no | Country-scoped admin rows only; never delete global/NULL-scope admins |
| `advisory_sizing_guide_cells` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `advisory_sizing_guide_columns` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `advisory_sizing_guide_rows` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `advisory_sizing_guides` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `advisory_sizing_library_bundles` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `analytical_dimension` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `analytical_dimension_value` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `bank_reconciliation` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `bank_reconciliation_line` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `cart_bogo_promotions` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `cart_combo_promotions` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `cart_gift_promotions` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `cart_promotions` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `catalog_attribute_options` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `catalog_attributes` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `catalog_categories` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `catalog_sections` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `catalog_subcategories` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `channels` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `color_dictionary` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `commercial_kind_dictionary` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `company_bank_accounts` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `company_settings` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `countries` | Global | ignore | no | no | yes | Country master; never delete/replace via CRP; package references id/code only |
| `customer_addresses` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `customers` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `delivery_agents` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `delivery_areas` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `delivery_fee_promotion_areas` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `delivery_fee_promotion_governorates` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `delivery_fee_promotions` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `delivery_governorates` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `department_countries` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `departments` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `document_public_tokens` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `document_sequences` | Global | ignore | no | no | yes | Global counters; mutating would corrupt other countries numbering |
| `expenses` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `fiscal_years` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `inventory_cost_consumptions` | Mixed | replace | yes | no | no | FIFO consumptions dependent on layers; replace with layer graph |
| `inventory_cost_layers` | Country Scoped | replace | yes | no | no | FIFO layers country_owned; replace target country only |
| `inventory_reconciliation` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `inventory_reconciliation_line` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `journal_entries` | Global | ignore | no | no | yes | Legacy/global journal catalog; not CRP payload |
| `journal_lines` | Mixed | replace | yes | no | no | GL lines dependent on vouchers; replace with voucher graph |
| `journal_types` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `journal_vouchers` | Country Scoped | replace | yes | no | no | GL vouchers country_owned; replace target country; preserve voucher identity policy on live ops unrelated to CRP delete/import |
| `logs` | Global | ignore | no | no | yes | Ephemeral/runtime; skip in CRP export and restore |
| `loyalty_ledger` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `loyalty_settings` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `offers` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `opening_stock_voucher` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `opening_stock_voucher_line` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `orange_admin_audit_log` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `orange_admin_login_throttle` | Global | ignore | no | no | yes | Ephemeral/runtime; skip in CRP export and restore |
| `orange_catalog_data_migration_log` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `orange_catalog_schema_checkpoint` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `orange_company_documents` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `orange_country_screen_copy_log` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `orange_edit_lock_registry` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `orange_gl_account_settings` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `orange_gl_journal_type_rules` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `orange_gl_pending_movements` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `orange_gl_setting_alloc` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `orange_gl_voucher_slots` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `orange_invoice_extra_lines` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `orange_invoice_line_presets` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `orange_schema_meta` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `orange_schema_migration_failures` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `orange_schema_migrations` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `order_intake_queue` | Global | ignore | no | no | yes | Ephemeral/runtime; skip in CRP export and restore |
| `order_items` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `orders` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `party_subledger` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `party_subledger_allocations` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `pattern_dictionary` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `payment_methods` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `payment_transactions` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `product_attribute_values` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `product_channels` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `product_colorway_images` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `product_colorways` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `product_images` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `product_types` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `product_variants` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `products` | Country Scoped | replace | yes | no | no | Country-owned SKUs (owner: catalog per country); replace target country only; FKs into Global taxonomy |
| `promo_pause_log` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `promo_stock_check` | Global | ignore | no | no | yes | Ephemeral/runtime; skip in CRP export and restore |
| `promotion_always_on_history` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `purchase_items` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `purchase_return_items` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `purchase_returns` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `purchases` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `report_line_master` | Global | ignore | no | no | yes | Global report mapping master; accounting logic must not use names; never CRP-replaced |
| `sales_return_items` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `sales_returns` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `size_families` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `size_family_advisory_library_map` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `size_family_sizes` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `size_scheme_template_sizes` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `size_scheme_templates` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `sizing_category_dictionary` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `stock_adjustment_voucher` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `stock_adjustment_voucher_gl` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `stock_adjustment_voucher_line` | Mixed | replace | yes | no | no | Registry dependent; delete/import with country-scoped parent graph |
| `stock_movements` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `storefront_accounts` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `storefront_copy_lines` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `storefront_home_hero` | Global | ignore | no | no | yes | Platform/shared reference (registry global); Country Restore must not mutate |
| `storefront_phone_merge_requests` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `storefront_promo_messages` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `suppliers` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |
| `warehouse_variant_stock` | Mixed | replace | yes | no | no | Stock qty per warehouse/variant; scoped via warehouses.country_id; replace with warehouse graph |
| `warehouses` | Country Scoped | replace | yes | no | no | Registry country_owned; replace target country_id slice only |

### 3.1 Counts (this matrix)

| Classification | Restore mode | Count |
|----------------|--------------|-------|
| Country Scoped | replace | **51** |
| Mixed | replace | **28** |
| Global | ignore | **38** |

Forced-never / elevated ignore reasons are called out in the `reason` column and restated in section 17.

---

## 4. Cross-country FK analysis

### 4.1 Risk model

Country Restore fails or corrupts siblings when:

1. A Country Scoped / Mixed row in the package FKs to a **parent in another country** (wrong warehouse, product, account).
2. Delete order removes a parent still referenced by **another country's** child (should be impossible if filters are correct; proves filter bugs).
3. Import order inserts children before parents exist in the **live** Global or target-country graph.
4. Global parents referenced by country children are **missing or ID-shifted** on the target environment (taxonomy / dictionaries).

### 4.2 Allowed FK directions (design)

| From (child) | To (parent) | Allowed? | CRP rule |
|--------------|-------------|----------|----------|
| Country Scoped / Mixed (target) | Global | Yes | Parent must already exist on target; CRP does not recreate Global parents |
| Country Scoped / Mixed (target) | Country Scoped (same country) | Yes | Restored together in graph order |
| Country Scoped / Mixed (target) | Country Scoped (other country) | **No** | Package validation + staging FK probe must fail closed |
| Global | Country Scoped | N/A for CRP mutate | CRP never mutates Global; live Global may reference countries only via `countries` master |

### 4.3 High-risk chains (must be proven in staging)

- `product_variants` / `product_images` / `product_channels` → `products` (country) → `product_types` / taxonomy (Global)
- `warehouse_variant_stock` / `stock_movements` → `warehouses` (country) + variants (via country products)
- `inventory_cost_consumptions` → `inventory_cost_layers` (country)
- `journal_lines` → `journal_vouchers` (country) → `accounts` (country)
- `order_items` → `orders` (country) → products/variants (country)
- `purchase_*` / returns → suppliers / warehouses (country)

### 4.4 Validation obligations (future implementation phases)

1. Registry dependency graph load + cycle check.  
2. Package SQL: reject INSERT into forbidden Global tables.  
3. Staging: after import, FK check for orphans; probe “other country_id leakage” on all country_id columns.  
4. Pre-apply: assert every distinct Global FK id in package exists in production Global tables.  
5. Pre-apply: assert zero package rows whose resolved ownership country ≠ target `country_id`.

---

## 5. Shared catalog analysis

### 5.1 Split: taxonomy vs sellable catalog

Per **unified taxonomy** archive and owner multi-country vision:

| Layer | Tables (examples) | Classification | CRP |
|-------|-------------------|----------------|-----|
| Unified taxonomy tree | `departments`, `catalog_sections`, `catalog_categories`, `catalog_subcategories`, `product_types` | Global | **ignore** |
| Attribute / size / color dictionaries | `catalog_attributes*`, `size_*`, `color_dictionary`, `pattern_dictionary`, advisory sizing*, commercial kind, sizing category | Global | **ignore** |
| Sellable catalog (SKU) | `products` + Mixed product children (`product_variants`, `product_images`, `product_channels`, `product_attribute_values`, …) | Country Scoped / Mixed | **replace** target country |

### 5.2 Design consequences

1. **Products are not a shared global SKU table for CRP.** Owner policy: different catalog per country. Registry: `products` is `country_owned`. CRP replaces only `products.country_id = target`.
2. **Taxonomy is shared platform vocabulary.** Restoring a country must **not** rewrite the tree. Package rows may FK into Global taxonomy IDs that must already match the target environment's schema revision / taxonomy content (certified environments share taxonomy; divergent taxonomy is a deployment problem, not a CRP mutate problem).
3. **Channels are country-owned** (vision §8). CRP replaces target-country channels and product–channel links; never other countries' channels.
4. **Offers / promos / cart rules** that are country-scoped follow Country Scoped / Mixed rules in the matrix; Global promotional dictionaries remain ignore.

### 5.3 Residual risk

If production taxonomy IDs diverge from the backup source (partial taxonomy edit on one env), country product restore can fail FK checks. Mitigation: schema_revision + taxonomy checksum gate in Country certification — not CRP rewriting Global tables.

---

## 6. Shared users

| Entity | Classification | CRP behavior |
|--------|----------------|--------------|
| `admins` with `country_id = target` | Country Scoped | **replace** that slice only |
| `admins` with `country_id IS NULL` (global / super) | Not in CRP extract | **never delete / never replace** |
| `admin_permissions` | Global | **ignore** |
| `admin_sessions` | Global (ephemeral) | **ignore** |
| `storefront_accounts` (and country-scoped account children) | Country Scoped / Mixed | **replace** target country |

### Design rules

1. Global Super Admins and NULL-scope operators are platform identity — CRP must use delete predicates that **require** `country_id = :target` (never `OR country_id IS NULL`).
2. Permission **templates** stay Global; country restore must not wipe capability definitions.
3. Sessions/throttle tables are ephemeral — never imported from CRP packages.
4. Approval / dual-control identities for restore jobs live outside CRP payload (job framework + audit), not inside country SQL.

---

## 7. Shared warehouses

**Design position:** Warehouses are **not** shared across countries.

| Fact | Source |
|------|--------|
| Owner: stock fully separated by country; each country has its own warehouse(s) | Multi-country vision §8 / §13 |
| Registry: `warehouses` = `country_owned` | `backup_table_registry.json` |
| Stock qty: `warehouse_variant_stock` = dependent of warehouse graph | Mixed |

### CRP rules

1. Replace only warehouses where `country_id = target`.  
2. Replace Mixed stock/movement children only via those warehouses / country filters.  
3. **No** “shared DC” restore mode in revision 121. If a future business model introduces cross-country warehouses, this architecture must be revised and re-certified — do not overload CRP merge for that case without a new phase.

---

## 8. Shared accounting

### 8.1 What is Global vs Country Scoped

| Artifact | Classification | CRP |
|----------|----------------|-----|
| `report_line_master` | Global | **ignore** (forbidden in CRP SQL) |
| `journal_entries` (legacy/global catalog) | Global | **ignore** |
| `accounts` | Country Scoped | **replace** target |
| `journal_vouchers` / `journal_lines` | Country Scoped / Mixed | **replace** target graph |
| Partner / AR-AP country tables (as in matrix) | Country Scoped / Mixed | **replace** target graph |
| Fiscal / period tables per matrix | Follow matrix | Follow matrix |

### 8.2 Accounting handoff alignment

- Report logic must **not** key off account **names**; `report_line_master` stays Global and immutable under CRP.  
- Voucher identity preservation for **live** operations remains an accounting policy; CRP **replace** of a country GL slice is a disaster recovery action that intentionally rewrites that country's vouchers/lines to package state — it is not day-to-day posting.  
- CRP must not post new journals; it only delete/imports historical rows for the target country under maintenance.

### 8.3 Cross-country accounting risk

Shared bank/clearing accounts across countries are **out of policy** for current Orange country separation. If any row lacks a resolvable country ownership path, it is **unsafe** and must be classified ignore or blocked until registry/schema fixes — never “guess merge”.

---

## 9. Shared FIFO

| Table | Classification | CRP |
|-------|----------------|-----|
| `inventory_cost_layers` | Country Scoped | **replace** target `country_id` |
| `inventory_cost_consumptions` | Mixed (via layers) | **replace** with layer graph |

### Design rules

1. FIFO layers are **per country**, not a global pool.  
2. Delete consumptions before layers (registry delete_order); restore layers before consumptions.  
3. Post-import probes: no consumption referencing another country's layer; layer quantities coherent with target-country stock movements.  
4. CRP must not recompute FIFO algorithmically during restore — it restores **recorded** layers/consumptions from the package.

---

## 10. Shared stock

| Concern | Design |
|---------|--------|
| Quantity truth | `warehouse_variant_stock` (Mixed) under country warehouses |
| Movements | `stock_movements` (and related) country-filtered via `country_id` and/or warehouse ownership |
| Cross-country deduction | **Forbidden** by owner policy; CRP validation must prove zero leakage |
| Channels | Do not own stock; product–channel links restore with country catalog only |

### CRP rules

1. Stock restore is always **warehouse-graph scoped**, never “all variants globally”.  
2. After import: non-negative aggregates for target country; no stock rows whose warehouse.country_id ≠ target.  
3. Promo/ephemeral stock check tables remain Global ephemeral — ignore.

---

## 11. Shared uploads

| Mode | Archive | Scope |
|------|---------|-------|
| Full DR | `uploads.zip` | Entire uploads tree |
| Country Restore | `files/uploads_country.zip` | Country-scoped paths only |

### Design rules

1. Extract only to staging uploads; never first-write into live tree.  
2. Zip-slip, symlink/reparse/junction blocks — reuse Full uploads FS guards.  
3. Path allowlist: only prefixes bound to target `country_id` / country code; reject bleed into other countries or global-only roots.  
4. Production apply (future): cutover/replace **country path subtree** only — not Full `uploads` rename of the entire tree.  
5. Rollback of uploads after production touch: prefer Full pre-restore uploads snapshot (section 18); country-subtree inverse is optional secondary if proven safe.

---

## 12. ID preservation policy

1. **Preserve primary keys** from the CRP package for Country Scoped and Mixed tables when applying replace. Renumbering breaks FKs inside the country graph and external references (order numbers already separate; internal ids still matter for lines, layers, vouchers).  
2. **Do not remap** Global FK targets during CRP; package must reference existing Global ids.  
3. **id_snapshot** (design): before delete, record max(id) / counts per CRP table for the target country; after import, compare package expected counts and FK closure.  
4. **Collisions:** Replace deletes target slice first; remaining ids belong to other countries or Global. Package ids must not collide with **surviving** rows in the same table. Staging must detect PK collisions before production apply.  
5. **Surrogate keys in Global tables:** never assigned by CRP.

---

## 13. AUTO_INCREMENT policy

1. After successful country import on a table, set `AUTO_INCREMENT` to at least `MAX(id)+1` for that table (same family of policy as Full staging hygiene).  
2. Never lower AI below surviving other-country max ids.  
3. AI adjustments are **per table**, after that table's import chunk, or once after full country graph import — implementation choice deferred; design requires final AI ≥ max surviving id.  
4. Global ignore tables: AI untouched by CRP.

---

## 14. Deletion policy

1. **Delete set** = Country Scoped + Mixed tables in registry `delete_order` for the job, filtered to target country ownership paths.  
2. **Never delete** Global / ignore tables (section 17).  
3. **Admins:** `DELETE … WHERE country_id = :target` only.  
4. **FK-safe order:** children before parents per registry delete_order; disable FK checks only inside controlled staging/apply sessions analogous to Full, re-enable and verify.  
5. **No soft-delete half-measures** for CRP replace: replace means physical removal of target slice then insert from package (audit of the restore job is external).  
6. **Other countries:** zero rows deleted. Pre/post row counts for a witness other country must match.

---

## 15. Merge policy

1. **Default for schema_revision 121: merge is disabled** (`merge = no` for every table in section 3).  
2. Merge (upsert by natural key, keep newer live rows, etc.) is **rejected** for Country production until a future design phase defines per-table natural keys and conflict rules.  
3. Rationale: GL, FIFO, stock, and orders need deterministic disaster rewrite; merge creates silent divergence and undebuggable hybrids.  
4. If a future owner decision enables merge for specific reference-like country tables, update this architecture and re-run Country certification — do not silently merge in code.

---

## 16. Replace policy

1. **Replace** is the only mutate mode for Country Scoped + Mixed tables in this design.  
2. Semantics: **delete target ownership slice → insert package rows** (preserve ids per section 12).  
3. Staging and (future) production apply **must** use the same semantics — no staging-replace / production-merge split.  
4. Replace never expands into Global tables.  
5. Empty package slice for a table means target country ends with **zero** rows in that table after replace (explicit wipe), not “leave live data”.

---

## 17. Tables that MUST NEVER be restored by Country Restore

These must remain **ignore** even if a package wrongly contains them. Validators already forbid a subset in `ORANGE_COUNTRY_EXPORT_FORBIDDEN_SQL_TABLES`; this section is the full design never-list (superset).

### 17.1 Schema / platform control

- `orange_schema_meta`
- `orange_schema_migrations`
- `orange_schema_migration_failures`
- `orange_catalog_schema_checkpoint`
- `orange_catalog_data_migration_log`

### 17.2 Country master and global numbering

- `countries`
- `document_sequences`

### 17.3 Accounting mapping master / legacy global journal catalog

- `report_line_master`
- `journal_entries`

### 17.4 Authz templates and ephemeral security/runtime

- `admin_permissions`
- `admin_sessions`
- `orange_admin_login_throttle`
- `logs`
- `order_intake_queue`
- `promo_stock_check`

### 17.5 Entire Global taxonomy and dictionaries

All registry `global` catalog/taxonomy/sizing/color/pattern/advisory tables (see section 3 matrix where classification = Global and restore mode = ignore), including but not limited to:

- `departments`, `catalog_sections`, `catalog_categories`, `catalog_subcategories`, `product_types`
- `catalog_attributes`, `catalog_attribute_options`
- `size_families`, `size_family_sizes`, `size_scheme_templates`, `size_scheme_template_sizes`, `size_family_advisory_library_map`
- `color_dictionary`, `pattern_dictionary`, `commercial_kind_dictionary`, `sizing_category_dictionary`
- advisory sizing library tables
- `storefront_home_hero` (global storefront chrome)
- `orange_admin_audit_log` (platform audit; restore jobs write new audit — do not replay country package into this as CRP mutate)

### 17.6 Rule

If section 3 marks `ignore = yes`, Country Restore **must never** mutate that table. Export tooling must not emit its row data in CRP SQL (forbidden list + registry skip).

---

## 18. Rollback philosophy

1. **Mandatory pre-restore Full backup** (DB + uploads), verified + DRV gate, pinned as `rollback_anchor` — even when the job is Country Restore (`RESTORE_EXECUTION_DESIGN.md` §3).  
2. **Primary rollback** after irreversible production touch: restore from that Full anchor (staging + cutover / approved Full rollback path) — not a best-effort country re-apply.  
3. Rationale: country apply can corrupt shared state via bugs; only Full anchor guarantees whole-system consistency.  
4. **Before** irreversible boundary: discard staging; no rollback engine required.  
5. **After** boundary: maintenance stays on; automatic or operator-triggered rollback; `rollback_failed` keeps maintenance and forensics.  
6. Country-subtree uploads reverse is optional **supplement** only if Full uploads rollback is impossible — not the design primary.  
7. Rollback does not require dual success of “country undo”; it requires return to pre-job production fingerprint from the anchor.

---

## 19. Shadow strategy

1. Reuse Full DR **Option B**: shadow/staging database + verify before any production country apply.  
2. Baseline staging from production snapshot (or clone), then apply CRP delete/import for target country only.  
3. Shadow uploads: extract `uploads_country.zip` to staging uploads directory; verify path allowlist + checksums.  
4. Shadow success criteria: FK closure, zero cross-country leakage, AI policy, stock/FIFO/GL probes for target country, witness other-country row-count equality, Global table checksum equality (ignore set unchanged).  
5. Production apply (future phase) only from a shadow that passed certification suite for that package + job bindings.  
6. Shadow DB credentials ≠ production; SQL runner must refuse production DB targeting during shadow import (existing Full safety asserts).

---

## 20. Certification strategy

Country Restore is **not** covered by Full DR Round 3 production certification. It needs a **separate Country certification program** before enabling production apply.

### 20.1 Gate artifacts (design)

1. This architecture document signed against schema_revision **121** / registry **1.0**.  
2. Automated matrix test: every live table ∈ section 3; every registry table ∈ schema; no unknown tables.  
3. Forbidden SQL self-test (export + staging import).  
4. Staging drill on a clone of production: one target country replace + witness country unchanged.  
5. Cross-country FK / leakage suite (section 4).  
6. Catalog / warehouse / accounting / FIFO / stock / uploads suites (sections 5–11).  
7. ID + AUTO_INCREMENT suite (sections 12–13).  
8. Rollback drill: intentional failed apply → Full anchor rollback → verify.  
9. Approval controls: phrase `RESTORE {CC}`, re-auth, package/job bind; **dual-control mandatory before Country production** (Full DR already flags dual-control as owner condition).  
10. Deployment preflight + maintenance enforcement reused from Full platform.

### 20.2 Explicit non-certification

- Passing Full DR real-clone validation **does not** certify Country Restore.  
- Staging-only Country paths existing in code **do not** equal production certification.  
- Owner must still decide enablement after engineering Country cert is green.

### 20.3 Exit criteria for “Country Restore production-ready” (future)

All of: architecture current to schema revision; cert suite green on production-like clone; dual-control implemented/enforced; rollback drill signed; uploads path bleed tests green; no open Critical/High Country-specific findings.

---

## Appendix A — Traceability

| Topic | Primary references |
|-------|-------------------|
| Full vs Country scope | `RESTORE_EXECUTION_DESIGN.md` §2.A / §2.B / §7 |
| Registry | `config/backup_table_registry.json`, `includes/backup/backup_table_registry_definitions.php` |
| Forbidden CRP SQL | `ORANGE_COUNTRY_EXPORT_FORBIDDEN_SQL_TABLES` in `includes/backup/backup_validate.php` |
| Country staging (existing, non-production-apply) | `includes/backup/restore/restore_country_staging.php` |
| Multi-country business rules | `docs/archive/ORANGE_OWNER_MULTICOUNTRY_VISION.txt` |
| Taxonomy | `docs/archive/ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt` |
| Accounting mapping | `docs/archive/ORANGE_ACCOUNTING_MAPPING_AND_REPORT_HANDOFF.txt` |
| Full DR cert posture | `docs/backup/ENTERPRISE_FINAL_AUDIT_ROUND3.md` |

## Appendix B — Phase boundary

| In C0 | Out of C0 |
|-------|-----------|
| This design document | Any PHP/SQL implementation |
| Complete 117-table matrix | Enabling Country production |
| Policies for replace/merge/ignore | Dual-control implementation |
| Shadow / cert / rollback philosophy for Country | New CLIs, UI, or patches |

---

*End of Phase C0 — Country Restore Architecture (design only).*