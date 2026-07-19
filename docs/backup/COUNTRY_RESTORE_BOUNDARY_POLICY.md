# Country Restore Boundary Policy (Phase C1.1) — FROZEN

**Status:** OWNER POLICY FROZEN for dependency-graph / export / restore / rollback / certification design  
**Date:** 2026-07-19  
**Phase:** C1.1 — Owner Policy Decisions and Boundary Freeze  
**Nature:** Documentation and policy finalization only — **no implementation**, no schema changes, no registry patches, no Country production enablement  

**Authoritative boundary SoT (this file + C1 corrected matrix):**  
- Policy freeze: **`docs/backup/COUNTRY_RESTORE_BOUNDARY_POLICY.md`** (this document)  
- Classification matrix detail: **`docs/backup/COUNTRY_BOUNDARY_VALIDATION.md`** § Corrected classification (approved by D1)  
- Historical design input only: `docs/backup/COUNTRY_RESTORE_ARCHITECTURE.md` (C0) — **not** SoT  

**Schema runtime truth:** `scripts/orange_db.sql` (local) / live MariaDB  
**Registry:** `config/backup_table_registry.json` v1.0 / schema_revision **121** — must be aligned later; **not patched in C1.1**

---

## Explicit freeze statement

**Country Restore boundary policy is frozen for dependency-graph design, but Country Restore is not yet certified or enabled.**

Later phases (dependency graph, export, restore engine, rollback, certification) **must** use this policy and the D1-approved C1 corrected matrix. Unmodified C0 classifications must not be used as source of truth.

---

## 1. Approved decisions D1–D6

| ID | Topic | Disposition | Frozen policy (summary) |
|----|-------|-------------|-------------------------|
| **D1** | Corrected matrix | **APPROVED** | C1 corrected classification is the authoritative Country Restore boundary matrix. C0 is historical design input only. |
| **D2** | NULL `country_id` | **APPROVED** | Never treat `country_id IS NULL` as target country. Exact equality only. See §3. |
| **D3** | `document_sequences` | **APPROVED** | Dedicated special handler for country namespace scopes (incl. `_c{country_id}`). Never full-table replace. See §4. |
| **D4** | `admins` + `admin_permissions` | **APPROVED** | One composite restore unit. Exact target `admins.country_id` only; permissions only for those admins. See §4–§5. |
| **D5** | `orange_country_screen_copy_log` | **APPROVED** | **Global / ignore**. Not in Country export/delete/restore/rollback. |
| **D6** | `journal_entries` | **APPROVED** | **Full-only / Country CRP ignore**. Country accounting uses voucher graph only; else fail closed `accounting_boundary_not_proven`. |

No direct contradiction was found in existing architecture against these owner decisions. C1 already recommended the same dispositions.

---

## 2. Final authoritative classification totals

Source: C1 corrected matrix, approved by **D1**.

| Classification | Count | Country CRP default posture |
|----------------|------:|-----------------------------|
| **Country Scoped** | **49** | replace (target slice only) |
| **Mixed** | **32** | replace with parent/composite graph, **except** special/ignore rows below |
| **Global** | **36** | ignore (never Country-mutate) |
| **Total** | **117** | |

### Seven reclassifications (now frozen)

| table | Frozen class | Frozen CRP mode |
|-------|--------------|-----------------|
| `admin_permissions` | Mixed | replace (composite with `admins`) |
| `document_sequences` | Mixed | **special** handler |
| `expenses` | Mixed | replace via account ownership |
| `journal_entries` | Mixed | **ignore** (Full-only) |
| `orange_company_documents` | Mixed | replace (polymorphic composite) |
| `orange_country_screen_copy_log` | Global | **ignore** |
| `orange_gl_voucher_slots` | Country Scoped | replace (`country_id` + voucher graph order) |

Full 117-row matrix: `docs/backup/COUNTRY_BOUNDARY_VALIDATION.md` (Corrected classification). That matrix is authoritative under D1; this policy freezes the rules that govern how those classes may be used.

---

## 3. Exact NULL ownership policy (D2)

Country Restore **must NEVER** treat `country_id IS NULL` as belonging to the target country.

### Rules

1. **Target membership** requires exact: `country_id = :target_country_id`.  
2. **NULL means** global / unassigned / legacy-ambiguous — **not** target.  
3. NULL rows are **excluded** from Country **export**, **delete**, **restore**, and **rollback** of the country slice.  
4. If a required dependency is only reachable via NULL-owned rows, CRP **fails closed** and requires an explicit table-specific owner policy (new decision) before proceed.  
5. **Forbidden:** `COALESCE(country_id, :target_country_id)`.  
6. **Forbidden:** permissive `OR country_id IS NULL` (or equivalent) in export/delete/restore predicates.  
7. **`admins`:** same rule — never restore or delete `admins.country_id IS NULL` (D4).  
8. Witness / leakage checks must prove sibling countries and NULL-scoped rows unchanged where they must remain platform-owned.

### Fail-closed code

- `null_country_id_dependency_blocker` — required dependency only available via NULL `country_id` without table-specific policy.

---

## 4. Special-handler list

| Handler ID | Table(s) | Policy |
|------------|----------|--------|
| `seq_country_namespace` | `document_sequences` | Restore **only** target-country sequence namespaces matching approved convention, including scopes ending with `_c{country_id}` (see `orange_sequence_next`). **Never** replace entire table. **Never** lower a surviving counter. Post-restore next value ≥ `max(current surviving, restored package value, observed document max + 1)`. Collision check required. Full audit trail required. Non-matching scopes (other countries / unsuffixed global) remain untouched. |
| `admins_permissions_composite` | `admins`, `admin_permissions` | See D4 / §5 unit A. |
| `polymorphic_company_documents` | `orange_company_documents` | Export/delete/restore only rows whose `entity_table`/`entity_id` resolve to validated **target-country** owners (allowlisted entity types). Uploads bound to those docs. Orphan/unvalidated → fail closed. |
| `expenses_via_accounts` | `expenses` | Membership via `expense_account_id → accounts.country_id = target` (exact). |
| `gl_voucher_slots_country` | `orange_gl_voucher_slots` | Primary resolver `country_id = target`; must stay ordered with voucher composite unit. |
| `full_only_journal_entries` | `journal_entries` | Never Country-mutate (D6). |
| `ignore_screen_copy_log` | `orange_country_screen_copy_log` | Never Country-mutate (D5). |

---

## 5. Composite restore units

The following **must never** be restored as independent tables. A unit succeeds or fails together (shadow verify before any production apply in future phases).

### Unit A — Admin authz (D4)

| Members | Resolver |
|---------|----------|
| `admins` | `direct_country_id` exact; exclude NULL |
| `admin_permissions` | `admin_ownership` — only grants for restored target-country admin IDs |

Additional D4 rules:

- Preserve IDs when collision-free.  
- Cross-country / global admin ID collision **blocks** restore — **do not remap silently**.  
- Unrelated grants and any true global templates remain untouched.  
- Fail-closed: `admin_id_collision`, `admin_permissions_composite_incomplete`.

### Unit B — GL voucher / accounting graph (D6)

| Members (minimum) | Notes |
|-------------------|-------|
| `accounts`, `fiscal_years`, `journal_types` (as needed) | Country Scoped chart/period/types |
| `journal_vouchers`, `journal_lines` | Core voucher graph |
| `orange_gl_voucher_slots` | Country Scoped slots |
| `party_subledger`, `party_subledger_allocations` | Via voucher ownership |
| `orange_gl_pending_movements` (if in package) | Via voucher/account ownership |
| Related country GL settings/presets/reconciliations as in matrix | Per resolver table |

**Excluded:** `journal_entries`, `report_line_master`, schema meta.

If consistency cannot be proven without `journal_entries` → fail closed `accounting_boundary_not_proven`.

### Unit C — Warehouse / stock / FIFO

| Members | Resolver |
|---------|----------|
| `warehouses` | `direct_country_id` |
| `warehouse_variant_stock` | `warehouse_ownership` |
| `stock_movements` | `direct_country_id` (exact; NULL excluded) |
| `inventory_cost_layers` | `direct_country_id` |
| `inventory_cost_consumptions` | `parent_fk` → layers |
| Opening / adjustment / reconciliation voucher graphs | As in matrix |

### Unit D — Polymorphic company documents

| Members | Resolver |
|---------|----------|
| `orange_company_documents` | `polymorphic_owner_validation` |
| Validated owners | `orders` / `purchases` / `customers` / `suppliers` (target only) |
| Uploads | Country uploads paths for those docs |

### Unit E — Orders commercial graph

| Members | Notes |
|---------|-------|
| `orders`, `order_items` | Country + parent_fk |
| `payment_transactions` / `payment_methods` as applicable | Country Scoped |
| `loyalty_ledger` / related where package includes | Country Scoped |
| Stock/accounting references | Must close against Units C/B without crossing countries |

### Unit F — Catalog SKU graph

| Members | Notes |
|---------|-------|
| `products` + Mixed children (variants, colorways, images, channels, attributes, offers, …) | Country products; FKs into **Global** taxonomy (ignore mutate) |
| `channels` + `product_channels` | Country channels |

### Unit G — Expenses

| Members | Resolver |
|---------|----------|
| `expenses` + owning `accounts` | `account_ownership` |

---

## 6. Full-only / ignored tables (Country CRP)

### Always ignore under Country Restore (non-exhaustive categories)

- All **Global** classification tables in the D1 matrix (taxonomy, dictionaries, schema meta, ephemeral, `countries`, `report_line_master`, `storefront_home_hero`, etc.).  
- **D5:** `orange_country_screen_copy_log`.  
- **D6:** `journal_entries` (also Full-only for any rewrite of that table).  
- Ephemeral: `admin_sessions`, `logs`, `order_intake_queue`, `promo_stock_check`, `orange_admin_login_throttle`, …  

### Full Disaster Recovery only

| Concern | Policy |
|---------|--------|
| Entire DB + full uploads tree | Full DR platform (already engineering-complete) |
| `journal_entries` rewrite | Full-only |
| Platform Global dictionaries/taxonomy | Full-only (or live shared; never Country replace) |
| Pre-restore rollback anchor | Always a **Full** package even for Country jobs (prior Full DR design) |

Country production enablement remains **disabled** until a separate Country certification program passes.

---

## 7. Ownership resolver types

Every Country Scoped or Mixed table **must** use exactly one documented resolver. Inference outside this list is forbidden.

| Resolver type | Meaning | Predicate / rule |
|---------------|---------|------------------|
| `direct_country_id` | Column `country_id` on the table | `country_id = :target` only; NULL excluded (D2) |
| `parent_fk` | Ownership via parent row set | Child FK ∈ exported/restored parent IDs of target country |
| `warehouse_ownership` | Via `warehouses.country_id` | Join/filter warehouse ∈ target warehouses |
| `account_ownership` | Via `accounts.country_id` | Join/filter account ∈ target accounts |
| `admin_ownership` | Via `admins.id` of target country | `admin_id` ∈ restored target admins |
| `polymorphic_owner_validation` | `entity_table` + `entity_id` | EXISTS into allowlisted target-country owners only |
| `special_namespace` | Encoded scope/key | `document_sequences` country suffix/prefix rules (D3) |
| `full_only_ignored` | Not Country-owned for mutate | `journal_entries` — ignore / Full-only (D6) |

### Resolver assignment (Country Scoped + Mixed only)

| table | classification | resolver | registry extract hint | parent |
|-------|----------------|----------|----------------------|--------|
| `accounts` | Country Scoped | `direct_country_id` | country_id |  |
| `admin_permissions` | Mixed | `admin_ownership` | full_table |  |
| `admins` | Country Scoped | `direct_country_id` | country_id |  |
| `analytical_dimension` | Country Scoped | `direct_country_id` | country_id |  |
| `analytical_dimension_value` | Mixed | `parent_fk` | parent_rows | analytical_dimension |
| `bank_reconciliation` | Country Scoped | `direct_country_id` | country_id |  |
| `bank_reconciliation_line` | Mixed | `parent_fk` | parent_rows | bank_reconciliation |
| `cart_bogo_promotions` | Country Scoped | `direct_country_id` | country_id |  |
| `cart_combo_promotions` | Country Scoped | `direct_country_id` | country_id |  |
| `cart_gift_promotions` | Country Scoped | `direct_country_id` | country_id |  |
| `cart_promotions` | Country Scoped | `direct_country_id` | country_id |  |
| `channels` | Country Scoped | `direct_country_id` | country_id |  |
| `company_bank_accounts` | Country Scoped | `direct_country_id` | country_id |  |
| `company_settings` | Country Scoped | `direct_country_id` | country_id |  |
| `customer_addresses` | Mixed | `parent_fk` | parent_rows | customers |
| `customers` | Country Scoped | `direct_country_id` | country_id |  |
| `delivery_agents` | Country Scoped | `direct_country_id` | country_id |  |
| `delivery_areas` | Country Scoped | `direct_country_id` | country_id |  |
| `delivery_fee_promotion_areas` | Mixed | `parent_fk` | parent_rows | delivery_fee_promotions |
| `delivery_fee_promotion_governorates` | Mixed | `parent_fk` | parent_rows | delivery_fee_promotions |
| `delivery_fee_promotions` | Country Scoped | `direct_country_id` | country_id |  |
| `delivery_governorates` | Country Scoped | `direct_country_id` | country_id |  |
| `department_countries` | Country Scoped | `direct_country_id` | country_id |  |
| `document_public_tokens` | Country Scoped | `direct_country_id` | country_id |  |
| `document_sequences` | Mixed | `special_namespace` | full_table |  |
| `expenses` | Mixed | `account_ownership` | custom_sql |  |
| `fiscal_years` | Country Scoped | `direct_country_id` | country_id |  |
| `inventory_cost_consumptions` | Mixed | `parent_fk` | custom_sql | inventory_cost_layers |
| `inventory_cost_layers` | Country Scoped | `direct_country_id` | country_id |  |
| `inventory_reconciliation` | Country Scoped | `direct_country_id` | country_id |  |
| `inventory_reconciliation_line` | Mixed | `parent_fk` | parent_rows | inventory_reconciliation |
| `journal_entries` | Mixed | `full_only_ignored` | full_table |  |
| `journal_lines` | Mixed | `parent_fk` | parent_rows | journal_vouchers |
| `journal_types` | Country Scoped | `direct_country_id` | country_id |  |
| `journal_vouchers` | Country Scoped | `direct_country_id` | country_id |  |
| `loyalty_ledger` | Country Scoped | `direct_country_id` | country_id |  |
| `loyalty_settings` | Country Scoped | `direct_country_id` | country_id |  |
| `offers` | Mixed | `parent_fk` | parent_rows | products |
| `opening_stock_voucher` | Country Scoped | `direct_country_id` | country_id |  |
| `opening_stock_voucher_line` | Mixed | `parent_fk` | parent_rows | opening_stock_voucher |
| `orange_company_documents` | Mixed | `polymorphic_owner_validation` | custom_sql |  |
| `orange_edit_lock_registry` | Country Scoped | `direct_country_id` | country_id |  |
| `orange_gl_account_settings` | Country Scoped | `direct_country_id` | country_id |  |
| `orange_gl_journal_type_rules` | Country Scoped | `direct_country_id` | country_id |  |
| `orange_gl_pending_movements` | Mixed | `parent_fk` | custom_sql | journal_vouchers |
| `orange_gl_setting_alloc` | Country Scoped | `direct_country_id` | country_id |  |
| `orange_gl_voucher_slots` | Country Scoped | `direct_country_id` | parent_rows | journal_vouchers |
| `orange_invoice_extra_lines` | Country Scoped | `direct_country_id` | country_id |  |
| `orange_invoice_line_presets` | Country Scoped | `direct_country_id` | country_id |  |
| `order_items` | Mixed | `parent_fk` | parent_rows | orders |
| `orders` | Country Scoped | `direct_country_id` | country_id |  |
| `party_subledger` | Mixed | `parent_fk` | custom_sql | journal_vouchers |
| `party_subledger_allocations` | Mixed | `parent_fk` | custom_sql | journal_vouchers |
| `payment_methods` | Country Scoped | `direct_country_id` | country_id |  |
| `payment_transactions` | Country Scoped | `direct_country_id` | country_id |  |
| `product_attribute_values` | Mixed | `parent_fk` | parent_rows | products |
| `product_channels` | Mixed | `parent_fk` | parent_rows | products |
| `product_colorway_images` | Mixed | `parent_fk` | parent_rows | product_colorways |
| `product_colorways` | Mixed | `parent_fk` | parent_rows | products |
| `product_images` | Mixed | `parent_fk` | parent_rows | products |
| `product_variants` | Mixed | `parent_fk` | parent_rows | products |
| `products` | Country Scoped | `direct_country_id` | country_id |  |
| `promo_pause_log` | Country Scoped | `direct_country_id` | country_id |  |
| `promotion_always_on_history` | Country Scoped | `direct_country_id` | country_id |  |
| `purchase_items` | Mixed | `parent_fk` | parent_rows | purchases |
| `purchase_return_items` | Mixed | `parent_fk` | parent_rows | purchase_returns |
| `purchase_returns` | Mixed | `parent_fk` | custom_sql | purchases |
| `purchases` | Country Scoped | `direct_country_id` | country_id |  |
| `sales_return_items` | Mixed | `parent_fk` | parent_rows | sales_returns |
| `sales_returns` | Country Scoped | `direct_country_id` | country_id |  |
| `stock_adjustment_voucher` | Country Scoped | `direct_country_id` | country_id |  |
| `stock_adjustment_voucher_gl` | Mixed | `parent_fk` | parent_rows | stock_adjustment_voucher |
| `stock_adjustment_voucher_line` | Mixed | `parent_fk` | parent_rows | stock_adjustment_voucher |
| `stock_movements` | Country Scoped | `direct_country_id` | country_id |  |
| `storefront_accounts` | Country Scoped | `direct_country_id` | country_id |  |
| `storefront_copy_lines` | Country Scoped | `direct_country_id` | country_id |  |
| `storefront_phone_merge_requests` | Country Scoped | `direct_country_id` | country_id |  |
| `storefront_promo_messages` | Country Scoped | `direct_country_id` | country_id |  |
| `suppliers` | Country Scoped | `direct_country_id` | country_id |  |
| `warehouse_variant_stock` | Mixed | `warehouse_ownership` | parent_rows | warehouses |
| `warehouses` | Country Scoped | `direct_country_id` | country_id |  |


### Notes on assignment

- `journal_entries` appears as Mixed for classification honesty but resolver is `full_only_ignored`.  
- `document_sequences` resolver is `special_namespace`.  
- `admin_permissions` resolver is `admin_ownership` (not `full_table` despite legacy registry extract type — registry extract must be corrected before execution; schema/policy win).  
- `orange_country_screen_copy_log` is Global — not listed above.

---

## 8. Registry drift list

**Runtime truth = schema** (`scripts/orange_db.sql` / live DB).  
Registry mismatches are **documented only** in C1.1 — **do not patch** `backup_table_registry.json` in this phase. Correction is mandatory before Country execution tooling trusts registry FK column names.

| table | registry field source | registry FK column | parent table | schema truth | status |
|-------|----------------------|--------------------|--------------|--------------|--------|
| `bank_reconciliation_line` | parent_dependency | `bank_reconciliation_id` | `bank_reconciliation` | `reconciliation_id` | COLUMN_MISMATCH |
| `customer_addresses` | parent_dependency | `customer_id` | `customers` | column `customer_id` exists; **no** FK constraint in dump | NO_FK_CONSTRAINT_BUT_COLUMN_EXISTS |
| `delivery_fee_promotion_areas` | parent_dependency | `promotion_id` | `delivery_fee_promotions` | column `promotion_id` exists; **no** FK constraint in dump | NO_FK_CONSTRAINT_BUT_COLUMN_EXISTS |
| `delivery_fee_promotion_governorates` | parent_dependency | `promotion_id` | `delivery_fee_promotions` | column `promotion_id` exists; **no** FK constraint in dump | NO_FK_CONSTRAINT_BUT_COLUMN_EXISTS |
| `inventory_reconciliation_line` | parent_dependency | `inventory_reconciliation_id` | `inventory_reconciliation` | `reconciliation_id` | COLUMN_MISMATCH |
| `journal_lines` | parent_dependency | `journal_voucher_id` | `journal_vouchers` | `voucher_id` | COLUMN_MISMATCH |
| `orange_gl_voucher_slots` | parent_dependency | `journal_voucher_id` | `journal_vouchers` | column `journal_voucher_id` exists; **no** FK constraint in dump | NO_FK_CONSTRAINT_BUT_COLUMN_EXISTS |
| `product_colorway_images` | parent_dependency | `product_colorway_id` | `product_colorways` | column `product_colorway_id` exists; **no** FK constraint in dump | NO_FK_CONSTRAINT_BUT_COLUMN_EXISTS |

### Drift disposition

| status | Meaning | Required before execution |
|--------|---------|---------------------------|
| `COLUMN_MISMATCH` | Registry names a different column than schema FK | Align registry to schema column name |
| `NO_FK_CONSTRAINT_BUT_COLUMN_EXISTS` | Logical parent column exists; dump has no FK | Keep logical parent_fk; do not invent wrong FK name; optional future FK is out of C1.1 |

**Examples called out by owner:** `journal_lines` registry `journal_voucher_id` vs schema `voucher_id`; similar `*_reconciliation_id` vs `reconciliation_id`.

Fail-closed if execution attempted with known drift unfixed: `registry_schema_drift_unresolved`.

---

## 9. Fail-closed reason codes

Stable machine codes for future Country tooling (design contract — not implemented here):

| code | When |
|------|------|
| `null_country_id_dependency_blocker` | Required row/dependency only via NULL `country_id` (D2) |
| `accounting_boundary_not_proven` | Country accounting cannot be consistent without `journal_entries` (D6) |
| `admin_id_collision` | Target admin PK collides with surviving global/other-country admin (D4) |
| `admin_permissions_composite_incomplete` | Admins/permissions unit incomplete |
| `sequence_namespace_collision` | `document_sequences` special handler collision (D3) |
| `sequence_counter_regression` | Handler would lower surviving counter (D3) |
| `polymorphic_owner_unvalidated` | Company document owner not in allowlisted target set |
| `composite_unit_incomplete` | Any §5 unit missing required members |
| `cross_country_fk_detected` | Package/graph references other country ownership |
| `global_mutate_forbidden` | Attempt to mutate Global / D5 / D6 ignore tables |
| `registry_schema_drift_unresolved` | Known registry/schema mismatch not corrected before execution |
| `country_leakage_probe_failed` | Sibling country or NULL-scoped witness changed |
| `boundary_policy_unfrozen_violation` | Tooling attempted to use C0 SoT or bypass this freeze |

---

## 10. Freeze scope and non-goals

### In scope (frozen now)

- D1–D6 dispositions  
- Classification totals and seven reclassifications  
- NULL policy, special handlers, composite units, resolvers, drift list, fail-closed codes  

### Out of scope (explicit)

- Any PHP/SQL implementation or registry JSON patch  
- Country Restore engine, export runner changes, production apply  
- Schema migrations  
- Country certification completion  
- Country production enablement  

### Document precedence (Country boundary)

1. **`COUNTRY_RESTORE_BOUNDARY_POLICY.md`** (this file) — frozen policy rules  
2. **`COUNTRY_BOUNDARY_VALIDATION.md`** — approved 117-table corrected matrix (D1)  
3. **`COUNTRY_RESTORE_ARCHITECTURE.md`** — historical C0 narrative only  
4. `backup_table_registry.json` — operational registry; must be corrected to match schema+this policy before execution; until then schema+this policy win on conflicts  

---

## Unresolved architecture questions (non-blocking for freeze)

These do **not** reopen D1–D6. They must be closed in later design/implementation phases:

1. Exact allowlist of `orange_company_documents.entity_table` values beyond orders/purchases/customers/suppliers.  
2. Whether any production `document_sequences.scope` values use a country convention other than `_c{id}` (handler must discover/reject unknown encodings).  
3. Timing of registry drift correction vs dependency-graph phase.  
4. Shadow/certification suite details for composite units (Country cert program — not C1.1).  

---

*End of Phase C1.1 — Country Restore Boundary Policy (frozen; not certified; not enabled).*