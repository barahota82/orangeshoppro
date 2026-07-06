# Orange Database Engineering Decision Summary

**Document type:** Engineering Decision archive (not a bug report, implementation plan, or progress report)  
**Scope:** All reviewed Production Readiness database findings (PR-DB-01 through PR-DB-10) and owner lifecycle decisions reached during related review sessions  
**Source:** Orange Engineering Audit review sessions (engineering review phase; implementation pending where required)  
**Status:** Current approved engineering review decisions as reached in review; no proposed fixes or repository changes recorded here

---

## Purpose

This document preserves the **current approved engineering review classifications, lifecycle conclusions, and decisions** reached during the Orange Production Readiness database integrity and lifecycle reviews. It exists so future agents and developers can continue work without relying on prior chat transcripts.

---

## Finding Outcome Index

| Outcome type | Findings |
|--------------|----------|
| **Real Bug** | PR-DB-01, PR-DB-02, PR-DB-03, PR-DB-06 |
| **Lifecycle Decision** | PR-DB-04, PR-DB-05 |
| **Architecture Decision** | PR-DB-07, PR-DB-08, PR-DB-09 (partial), PR-DB-03 (partial), PR-DB-01/02 (partial) |
| **Future Improvement** | PR-DB-04, PR-DB-05, PR-DB-07, PR-DB-08, PR-DB-09, PR-DB-10 (subset) |
| **Performance Issue** | PR-DB-10 (subset) |

---

## Owner Lifecycle Decisions (Cross-Cutting)

These owner decisions were reached during the audit review sessions and **changed or contextualized** original audit conclusions. They are mandatory policy for future development.

### Orange Preservation Lifecycle Model

**Owner Decision** (archived in `docs/archive/ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt` — Orange Entity Lifecycle Policy):

Orange follows a **Preservation Lifecycle Model**. Business history always takes precedence over physical deletion.

**General Principle:** Whenever preserving historical business data conflicts with deleting catalog or operational entities, historical preservation always wins.

### Product Tree Parent Lifecycle

Applies to: Departments, Sections, Categories, Subcategories, Product Types, and any future catalog tree node.

**Rule:** A parent node must never be hard deleted while child nodes exist. A parent node must never be hard deleted while products exist beneath it. Only completely empty nodes may be hard deleted. Otherwise the node must become inactive or hidden.

### Product Variant Lifecycle

Applies to: colors, sizes, color/size combinations, and future variant dimensions.

**Rule:** Removing a color or size from the Variant Matrix must never remove historical business data. If the variant has **any** business footprint, it must never be hard deleted. Footprint includes orders, purchases, returns, warehouse variant stock, stock movements, inventory cost layers, inventory cost consumptions, accounting references, and any future business references. Such variants must become inactive/hidden while preserving historical integrity.

**Repository behavior (review evidence):** Matrix sync refuses variant row deletion when `stock_movements` exist — zeros stock instead. Product hard delete blocked when business footprint exists (`product_delete_policy.php`, `ORANGE_STOCK_ORDER_POLICY.txt` §16).

### Historical Business Record Preservation

**Orders:** Never deleted through any application path. Zero `DELETE FROM orders` in repository. Lifecycle is status mutation only (cancel, complete, amend). Policy forbids deleting delivered orders after accounting handoff.

**Order items:** Deleted/replaced only through controlled amend and invoice edit while the **order row survives**. Not deleted by order deletion in application behavior because orders are never deleted.

**Payment transactions:** Append-only audit history. Never deleted in application paths. All production writers validate order existence before insert. Immutable payment audit trail is intentional.

**Impact on audit conclusions:** PR-DB-04 and PR-DB-05 original audit severities (High / Must be resolved before Production Go-Live) were **downgraded** after lifecycle review because the described failure modes (order delete orphaning payments, CASCADE erasing line history) **do not occur in application lifecycle**.

### Variant Matrix Lifecycle

**Business intent:** One variant per color/size combination per product (matrix model).

**Update path:** `orange_product_sync_variants_matrix()` deduplicates by `colorwayKey|size_family_size_id` fingerprint — aligns with business intent.

**Create path gap (PR-DB-06):** Product create and preview draft save insert matrix rows in a loop **without** matrix sync — duplicate payload rows can create duplicate DB rows. This is an **application gap**, not a documented owner choice to allow duplicates.

### Stock & Cost Entity Lifecycle (Supporting Context for PR-DB-01–03)

Reviewed separately before FK analysis; conclusions inform database integrity decisions:

| Entity | Deletable in app? | Preservation intent |
|--------|-------------------|---------------------|
| **Warehouse** | No | Persistent country-scoped master data; never removed in-app |
| **Warehouse Variant Stock** | Rows updated/deleted only via stock helpers and guarded product delete | Operational quantity truth |
| **Stock Movement** | No delete path found | Append-only movement history |
| **Inventory Cost Layer** | Consumed/reduced; not casually deleted | FIFO cost history |
| **Inventory Cost Consumption** | Removed only on guarded product hard delete path | COGS audit trail |
| **Product Variant** | Yes on matrix sync (no movements) or guarded product hard delete | Preserved when any stock/order footprint exists |

**Gap noted in lifecycle review:** Matrix variant delete with zero movements can delete variant row without cleaning matching WVS/cost rows — contributes to PR-DB-03 orphan scenario on non-guarded paths.

---

## PR-DB-01 — Stock Ledger Tables Lack Foreign Keys

### Audit finding (original)

`warehouse_variant_stock.warehouse_id` / `.variant_id` and `stock_movements.warehouse_id` indexed but not FK-enforced.

### Current Engineering Classification

**Real Bug** (partial scope) + **Architecture Decision** (application-level integrity) + **Defense in Depth** (partial — `stock_movements` has product/variant FKs)

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Severity** | **High** |
| **Category** | Database Integrity — Referential Integrity (Warehouse Variant Stock + Stock Movements warehouse scope) |
| **Fix Timing** | **Must be resolved before Production Go-Live** |

**Confirmed real integrity gap** for `warehouse_variant_stock` (zero FKs) and `stock_movements.warehouse_id` (index only). Finding **slightly overstated** regarding all of `stock_movements` — product/variant FKs exist in production schema.

### Lifecycle conclusion

Warehouses are never deleted in-app; orphan WVS/movement rows arise primarily if parent rows are removed **outside application lifecycle** (manual SQL). Application assumes warehouses and variants remain valid while stock rows reference them.

### Architecture conclusion

Orange **currently relies on application-level integrity** for warehouse/stock ledgers, with **partial DB enforcement** on `stock_movements → products/product_variants` only. Missing FKs appear **legacy + phased multicountry migration debt**, not a documented permanent rejection of DB constraints.

### Business reasoning

Orphan stock rows after variant/warehouse deletion cause incorrect availability, failed reservations, and reconciliation errors. WVS is operational source of truth for sellable quantity per country/warehouse.

---

## PR-DB-02 — Inventory Cost Ledger Lacks Foreign Keys

### Audit finding (original)

`inventory_cost_layers` and `inventory_cost_consumptions` have no FK on `variant_id`, `warehouse_id`, or `layer_id`.

### Current Engineering Classification

**Real Bug** + **Architecture Decision** (application-orchestrated FIFO without DB enforcement)

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Severity** | **High** |
| **Category** | Database Integrity — Referential Integrity (FIFO Cost Layers & Consumptions) |
| **Fix Timing** | **Must be resolved before Production Go-Live** |

**Confirmed real bug** from database-integrity perspective. FIFO functions use consistent IDs within transactions, but nothing prevents manual SQL or partial failure from leaving inconsistent rows. COGS/GL already depend on these tables — integrity risk is **current**, not deferred.

### Lifecycle conclusion

Cost layers and consumptions are financial history. Application paths treat them as preserved when business footprint exists. Orphan risk is DB-enforcement gap, not intentional deletion lifecycle.

### Architecture conclusion

Cost layer design is application-orchestrated FIFO (`inventory_cost_layers.php`); DB does not enforce referential integrity. Tables added in phased migration (v89) with indexes only.

### Business reasoning

FIFO cost layers and consumptions referencing deleted variants/warehouses corrupt COGS, margin reports, and stock valuation.

---

## PR-DB-03 — Product Delete Cascade Can Orphan Un-FK'd Rows

### Audit finding (original)

`products → product_variants ON DELETE CASCADE` while WVS/cost tables unlinked; app guard in `product_delete_policy.php`.

### Current Engineering Classification

**Real Bug** (DB bypass path) + **Architecture Decision** (block + soft-disable policy) + **Defense in Depth** (app guard on normal path)

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Severity** | **Critical** |
| **Category** | Database Integrity — Cascade / Orphan Data (Product Delete vs Stock & Cost Ledgers) |
| **Fix Timing** | **Must be resolved before Production Go-Live** |

**Yes on DB bypass path:** CASCADE deletes variants while WVS/cost tables have no FK to variants — direct `DELETE FROM products` leaves orphan stock/cost rows.

**App guard mitigates normal path:** `orange_product_delete_history_block_reasons()` blocks delete when footprint exists; allowed hard delete explicitly removes consumptions, layers, and WVS before variants in transaction.

**Gap:** Matrix variant delete with zero movements deletes variant without cleaning WVS/cost rows.

### Lifecycle conclusion

Policy chooses **block + soft-disable** for historical products and **manual ordered delete** for catalog-only products. Explicitly forbids deleting `stock_movements` on product delete. Preservation lifecycle model requires footprint check before hard delete.

### Architecture conclusion

Intentional application-level delete ordering on supported API path; DB CASCADE contradicts preservation model if bypassed.

### Business reasoning

Deleting a product removes variants via CASCADE while stock/cost rows remain — silent inventory inflation and accounting drift.

---

## PR-DB-04 — `payment_transactions.order_id` Has No Foreign Key

### Audit finding (original)

Payment records can exist without valid orders or survive order deletion; breaks reconciliation and audit.

### Current Engineering Classification

**Lifecycle Decision** (primary) + **Architecture Decision** (secondary). **Not a Real Bug** in application lifecycle.

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Classification** | **Lifecycle Decision** (+ Architecture Decision for nullable `order_id` / append-only payments) |
| **Engineering Decision** | Accept current application lifecycle. Payment records are intentionally immutable audit history; all app writers bind to validated orders. Finding describes a **latent out-of-band / schema-enforcement gap**, not a lifecycle defect in running code. |
| **Severity** | **Low** (lifecycle lens; original audit **High** assumed unconstrained DB deletes) |
| **Category** | Historical Business Records — Payment Audit Lifecycle |
| **Fix Timing** | **Defer / Future Improvement** (optional alignment hardening only if out-of-band DB operations are in scope) |

### Lifecycle conclusion

Orders never deleted. Payments never deleted. All production write paths validate order existence. Nullable `order_id` unused by callers. Admin reconciliation joins payments to existing orders.

### Architecture conclusion

Append-only payment audit trail is intentional architecture. FK would be defense against manual SQL, not correction of application lifecycle defect.

### Business reasoning

Original audit assumed order deletion breaking payment links. Application lifecycle preserves both orders and payment audit rows permanently.

### Audit conclusion change

Original audit: **High / Must be resolved before Production Go-Live**. Current engineering review: **Low / Defer** after lifecycle analysis.

---

## PR-DB-05 — `order_items` CASCADE Deletes Historical Sale Lines

### Audit finding (original)

Deleting an order erases line-item history via `ON DELETE CASCADE`.

### Current Engineering Classification

**Lifecycle Decision** + **Architecture Decision** (DB CASCADE vs app non-delete policy). **Not a Real Bug** in application lifecycle.

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Classification** | **Lifecycle Decision** (+ Architecture Decision for DB CASCADE vs app non-delete policy) |
| **Engineering Decision** | Accept current application lifecycle. Orange preserves orders permanently and mutates lines only through controlled amend/invoice edit. CASCADE on order delete is **dormant** in application behavior because orders are never deleted. |
| **Severity** | **Low** (lifecycle lens; latent only if orders deleted outside the app) |
| **Category** | Historical Business Records — Order Line Lifecycle |
| **Fix Timing** | **Defer / Future Improvement** (schema/policy alignment if defense against non-application deletes becomes a requirement) |

### Lifecycle conclusion

Schema has CASCADE, but application has no `DELETE FROM orders` — CASCADE never fires through Orange code. Line history deleted/replaced in amend/invoice edit with order row preserved — separate intentional edit lifecycle.

### Architecture conclusion

DB default CASCADE conflicts with preservation policy only if orders are deleted outside the app.

### Business reasoning

Original audit assumed order deletion erasing sales analysis history. Application never deletes orders; historical line data preserved on order row.

### Audit conclusion change

Original audit: **High / Must be resolved before Production Go-Live**. Current engineering review: **Low / Defer** after lifecycle analysis.

---

## PR-DB-06 — No Unique Constraint on Product Variant Identity

### Audit finding (original)

Duplicate variants (same product + colorway + size) split stock, break pricing, and confuse fulfillment.

### Current Engineering Classification

**Real Bug** (application gap on create/preview paths) + **Architecture Decision** (no DB UNIQUE; dedup delegated to application)

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Classification** | **Real Bug** (+ Architecture Decision for absent DB constraint) |
| **Severity** | **Medium** — duplicate variants split stock, reservations, and FIFO; ambiguous `variant_id` for the same sellable combo |
| **Category** | Catalog Integrity — Variant Matrix / Stock Unit Identity |
| **Fix Timing** | **Must be resolved before Production Go-Live** (at minimum for the create path; update path already aligns with business intent) |

Not a pure lifecycle policy to allow duplicates; duplicates are accidental on some paths, not a documented owner choice.

### Lifecycle conclusion

One variant per color/size combo is business intent. Update path implements deduplication; create path does not. Conflicts with Variant Matrix Lifecycle owner intent.

### Architecture conclusion

No DB UNIQUE on `(product_id, product_colorway_id, size_family_size_id)`. Deduplication delegated to `orange_product_sync_variants_matrix()` on update only.

### Business reasoning

Duplicate variant rows split stock across two IDs for the same sellable combination — breaks reservations, FIFO, and fulfillment identity.

---

## PR-DB-07 — No Unique Constraint on SKU / Barcode / Item Code

### Audit finding (original)

Duplicate barcodes or item codes cause wrong picks, scanner errors, and reporting ambiguity.

### Current Engineering Classification

**Architecture Decision** (primary) + **Future Improvement** (if global scan-grade uniqueness required)

**Not a Real Bug** under current documented owner policy.

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Classification** | **Architecture Decision** (+ **Future Improvement** if global scan-grade uniqueness becomes a requirement) |
| **Severity** | **Low** under current internal-hash + tree-code model; **Medium** only if external barcode scanning or global SKU lookup is required without `variant_id` |
| **Category** | Catalog Integrity — Identifier Policy (item_code / internal barcode) |
| **Fix Timing** | **Defer / Future Improvement** — aligns with archived owner policy; not a production blocker for the current auto-generated identifier model |

### Lifecycle conclusion

Product `item_code` is tree-derived and mutable — owner policy explicitly avoids UNIQUE because tree ordinals can change. Variant barcodes are SHA-256 content hashes including `vid`/`pid` — not retail EAN-13. Operational sellable identity is `variant_id`, not `item_code` alone.

### Architecture conclusion

Documented owner choice in ERD: no UNIQUE on `item_code` to avoid collision errors when tree changes. Picker UI tolerates duplicate codes via disambiguation suffixes at pick-list build time.

### Business reasoning

Original audit assumed global SKU/barcode uniqueness requirement. Owner policy and implementation use derived codes and internal hashes with `variant_id` as authoritative transaction key.

### Audit conclusion change

Original audit: **High / Must be resolved before Production Go-Live**. Current engineering review: **Low / Defer** — Architecture Decision per archived owner policy.

---

## PR-DB-08 — Widespread Missing FKs on Order and Customer Links

### Audit finding (original)

`orders` link columns, `customer_addresses`, `loyalty_ledger.customer_id`, `storefront_accounts.customer_id` — indexed but not FK-enforced.

### Current Engineering Classification

**Architecture Decision** (primary) + **Future Improvement** (defense-in-depth FKs). **Not a Real Bug** on normal production paths.

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Severity** | **Low–Medium** (Medium as audit posture; **Low** for immediate production risk on validated paths) |
| **Category** | Architecture Decision / Application-level integrity |
| **Fix Timing** | **Defer (Future)** — after order/payment lifecycle FK policy is settled; not a production blocker for current validated write paths |

Write-time validation exists for order columns (`storefront_account_id`, `delivery_area_id`, `warehouse_id`, `delivery_agent_id`). Storefront→customer sync and customer delete guards enforce key links in application code. Historical orders intentionally retain IDs after master-data changes (snapshot semantics). Orders never deleted.

### Lifecycle conclusion

Order linked IDs are business facts at order time — must remain stable even if areas/agents/warehouses later change. Customer addresses are append-only history. Loyalty ledger is immutable financial history. Orphan risk is edge-case (manual SQL, incomplete delete guards on `customer_addresses`/`loyalty_ledger`), not checkout/fulfillment hot path.

### Architecture conclusion

Selective FK usage in codebase (catalog tree, journal lines, partial operational tables) vs application validation for order/customer operational tables. Aligns with historical business record preservation and phased multicountry rollout.

### Business reasoning

Original audit listed orphan references causing wrong fees and routing. Application validates on write; historical snapshot IDs on old orders are intentional, not defects.

### Audit conclusion change

Original audit: **Medium / Future**. Current engineering review: **Low–Medium / Defer** — Architecture Decision for validated application paths.

---

## PR-DB-09 — `country_id` Never FK-Enforced to `countries`

### Audit finding (original)

~40 tables carry `country_id`; zero `REFERENCES countries`; invalid IDs propagate without DB rejection.

### Current Engineering Classification

**Architecture Decision** + **Lifecycle Decision** (phased multicountry migration). **Not a Real Bug** while legacy rows coexist.

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Severity** | **Low** |
| **Category** | Architecture Decision / Phased multicountry migration debt |
| **Fix Timing** | **Defer (Future)** — only after legacy NULL/0 backfill completes and `countries` row set is stable |

Application enforces scope via `orange_sql_filter_country_id()`, admin country asserts, and channel-derived storefront country. Legacy Kuwait rows may carry `NULL`/`0` treated as default country in application filters. Premature FK would conflict with active scoping logic.

### Lifecycle conclusion

Multicountry rollout is phased (`ORANGE_OWNER_MULTICOUNTRY_VISION.txt`). `country_id` added incrementally across tables during migration waves.

### Architecture conclusion

Single shared database with `country_id` as tenant boundary — application scoping is the enforced boundary, not DB FK to `countries`.

### Business reasoning

Invalid country IDs could cause wrong tax, currency, and scope — mitigated by application filters and admin asserts in current architecture; DB FK deferred until legacy data normalized.

### Audit conclusion change

Original audit: **Medium / Future**. Current engineering review: **Low / Defer** — Architecture Decision during phased migration.

---

## PR-DB-10 — Missing Indexes on Filter Columns

### Audit finding (original)

`delivery_areas.country_id`, `customer_addresses.delivery_area_id`, `inventory_reconciliation.journal_voucher_id` / `.delivery_agent_id` lack indexes.

### Current Engineering Classification

**Performance Issue** (subset confirmed) + **Future Improvement** (subset — columns not query-filtered in repository)

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Severity** | **Medium** for `delivery_areas.country_id` and `inventory_reconciliation.delivery_agent_id`; **Low** for `customer_addresses.delivery_area_id` and `inventory_reconciliation.journal_voucher_id` |
| **Category** | Performance Issue (subset) / Future Improvement (subset) |
| **Fix Timing** | **`delivery_areas.country_id`:** Must be resolved before Production Go-Live if multicountry checkout/admin lists scale beyond single-country row counts. **`inventory_reconciliation.delivery_agent_id`:** Must be resolved before Production Go-Live if agent-scoped archive search is heavily used. **`customer_addresses.delivery_area_id` and `inventory_reconciliation.journal_voucher_id`:** Defer (Future) — no current filter queries justify urgency |

### Lifecycle conclusion

Not a lifecycle policy issue — indexing gap only where repository actually filters at volume.

### Architecture conclusion

Partial indexing already exists on `inventory_reconciliation` (`warehouse_id, status` and `country_id`). Gap is on agent filter specifically. `delivery_areas.country_id` used in hot checkout/admin paths without dedicated index.

### Business reasoning

Slow admin queries and full table scans as data grows. Audit claim **partially confirmed, partially overstated** — two columns actively filtered without indexes; two columns are storage-only or write-only in current code paths.

### Audit conclusion change

Original audit: **Medium / Must be resolved before Production Go-Live (high-traffic) / Future (others)**. Current engineering review **narrows** must-be-resolved-before-Production-Go-Live timing to `delivery_areas.country_id` and `inventory_reconciliation.delivery_agent_id` only; defers other two columns.

---

## Document Control

This archive records **current approved engineering review decisions only**. Implementation steps, code changes, and proposed fixes are intentionally excluded per Orange Engineering Audit policy.

Lifecycle owner policy text is authoritative in `docs/archive/ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt` (Orange Entity Lifecycle Policy section).
