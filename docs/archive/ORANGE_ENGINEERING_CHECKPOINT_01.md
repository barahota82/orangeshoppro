# Orange Engineering Checkpoint 01

**Document type:** Engineering Checkpoint  
**Purpose:** Allow any future AI agent to continue the project without reading prior conversations.

---

## 1. Review Progress

- Audit #1 — **Review Status:** Engineering review of Audit #1 issues complete.
- Audit #1 — **Decision Status:** Engineering review decisions recorded for all Audit #1 issues.
- Audit #1 — **Implementation Status:** Incomplete — open items remain (ISSUE-05 deferred; ISSUE-11 partial; ISSUE-12 pre-go-live; PR-SEC/PR-DB items requiring resolution before Production Go-Live not yet implemented).
- Audit #1 — **Verification Status:** Pending — go-live verification not complete where implementation is still open.
- Production Readiness Review — **Review Status:** Complete.
- Security Review — **Review Status:** Complete; **Implementation Status:** Incomplete for items requiring resolution before Production Go-Live.
- Database Review — **Review Status:** Complete; **Implementation Status:** Incomplete for items requiring resolution before Production Go-Live.
- Performance Review — **Review Status:** Complete.
- Configuration Review — **Review Status:** Complete.
- Backup & Recovery Review — **Review Status:** Complete.
- Deployment Review — **Review Status:** Complete.

---

## 2. Implemented Issues

### Implemented

- ISSUE-01
- ISSUE-03
- ISSUE-04
- ISSUE-06
- ISSUE-07
- ISSUE-08
- ISSUE-09
- ISSUE-10

### Won't Fix

**ISSUE-02**

Reason:

Legacy GL Pending Queue. Current Orange architecture uses Immediate Posting.

### Deferred

**ISSUE-05**

Reason:

Standalone FOR UPDATE completion fix was rejected. Safe implementation requires future lock-order harmonization across completion, amend, and cancel flows.

### Partial Architecture Decision

**ISSUE-11**

Reason:

Transaction alone is not a complete solution. Long-term architecture requires transaction, ordered writes, and database invariant.

### Pre-Go-Live

**ISSUE-12**

Reason:

Payment settlement must become atomic before online gateway payments are enabled.

---

## Production Readiness Implementation Progress

### Completed and Approved

**PR-BAK-01**

- **Status:** Implemented
- **Approved**

**PR-BAK-02**

- **Status:** Implemented
- **Approved**

**PR-CFG-01 (Batch 1)**

- **Status:** Implemented
- **Approved**
- **Notes:** Operational logging foundation completed.

**PR-BAK-04 (Batch 1)**

- **Status:** Implemented
- **Approved**
- **Notes:** Audit log failure visibility completed.

**PR-BAK-03 (Batch 2)**

- **Status:** Implemented
- **Approved**
- **Notes:** Migration operational visibility completed. Migration cooldown behavior unchanged.

**PR-SEC-05**

- **Title:** Payment Settlement Atomicity
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Settlement is now executed inside a single database transaction.
  - Order row is locked before payment transaction row.
  - Idempotency repaired.
  - Existing paid transaction now synchronizes unpaid orders.
  - GL hook remains inside the transaction boundary.
  - Public API contract unchanged.
  - No schema changes.
  - No migration changes.

**PR-SEC-02 (Batch 1)**

- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Runtime schema support added.
  - Persistent admin login throttle table added.
  - Username/IP helper foundation implemented.
  - Environment configuration documented.
  - Login flow not yet connected.
  - No business logic changed.

**PR-SEC-02 (Batch 2)**

- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Admin login flow connected to login rate limiter.
  - Rate limit check runs before password verification.
  - Failed login attempts are recorded.
  - Successful login clears throttle state.
  - User-facing error messages remain generic.
  - No username enumeration introduced.
  - No schema changes in Batch 2.

**PR-SEC-06 (Batch 2)**

- **Title:** Payment Proof Authorized Download
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Added authorized admin payment proof download endpoint.
  - Payment proof access now requires admin session.
  - Country scope is enforced.
  - Direct public proof_url values were replaced with authorized endpoint URLs.
  - Payment review UI remains functional.
  - No schema changes.
  - No IIS/web.config blocking implemented in this batch.

**PR-SEC-06 (Batch 1)**

- **Title:** Sensitive Uploads Static Access Blocking Template
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - IIS/Plesk deny-rule deploy fragment added.
  - Uploads access control runbook added.
  - Sensitive upload paths documented.
  - Public upload paths documented and preserved.
  - No PHP code changed in this batch.
  - No schema changes.
  - Live server web.config not modified.
  - Production activation remains pending until the fragment is merged into live IIS web.config.

**PR-SEC-04**

- **Title:** Standalone Admin HTML Permission Enforcement
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Standalone customer print-card endpoint now enforces page permission before loading customer data.
  - Standalone supplier print-card endpoint now enforces page permission before loading supplier data.
  - Existing Orange permission model reused.
  - Country-scope enforcement unchanged.
  - Print layout unchanged.
  - No routing changes.
  - No schema changes.
  - No database migrations.

**PR-SEC-01B (Batch 1)**

- **Title:** Authenticated GET Mutations Hardening
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - bank-accounts.php now allows GET only for the read-only list action.
  - All mutating bank account actions require POST.
  - doc-token/ensure.php is now POST-only.
  - doc_kind and doc_id are no longer read from $_REQUEST.
  - admin_sales_doc_ui.js now requests document tokens using POST with JSON.
  - Existing Orange admin country headers are preserved.
  - QR generation and print workflow remain unchanged.
  - Logout endpoints were intentionally excluded from this batch.
  - No schema changes.
  - No database migrations.

**PR-SEC-03**

- **Title:** Authenticated Diagnostic Endpoint Protection
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - env-check.php now requires the existing Orange admin API authentication.
  - require_admin_api() executes before any diagnostic output.
  - Diagnostic JSON remains unchanged.
  - Existing diagnostic purpose preserved.
  - No new authentication mechanism introduced.
  - No schema changes.
  - No database migrations.

**PR-SEC-07 (Batch 1)**

- **Title:** Application-Level Production Error Boundary
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Application-level error boundary added in config.php.
  - Client-visible display_errors disabled.
  - log_errors enabled.
  - Global uncaught exception handler registered.
  - Global fatal shutdown handler registered.
  - API/JSON requests now receive generic JSON error responses.
  - HTML requests now receive generic error output without internal details.
  - health.php preserves plain text generic failure output.
  - Detailed error information remains internal via error_log.
  - Error boundary no longer depends on json_response().
  - Batch 2 endpoint-specific cleanup is still pending.
  - No schema changes.
  - No database migrations.

**PR-SEC-07 (Batch 2)**

- **Title:** Endpoint-specific Production Error Information Disclosure Cleanup
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - All approved endpoint-specific HTTP 500 catch blocks now use the existing Orange generic error handlers.
  - Admin API endpoints now use orange_admin_api_catch().
  - Storefront API endpoint variant-labels.php now uses api_error().
  - RuntimeException business messages remain unchanged.
  - Validation, 403, 404 and 422 responses remain unchanged.
  - No business logic changed.
  - No schema changes.
  - No database migrations.

**PR-SEC-08**

- **Title:** Production-safe ORANGE_API_DEBUG Gating
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Added centralized orange_api_debug_may_expose_to_client().
  - Client debug output is now disabled by default.
  - Client debug is exposed only when:
    - ORANGE_PRODUCTION is explicitly false.
    - ORANGE_API_DEBUG is enabled.
  - api_error() now uses the centralized gate.
  - orange_gl_api_catch_json() now uses the centralized gate.
  - Internal server logging remains unchanged.
  - No schema changes.
  - No database migrations.

**PR-DB-01**

- **Title:** Warehouse Variant Stock Referential Integrity
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Runtime schema revision updated to 116.
  - Hybrid per-FK migration implemented.
  - Three foreign keys are evaluated independently.
  - Independent orphan audit for each FK.
  - Independent FK existence verification.
  - Independent per-FK migration markers.
  - Constraint existence is the primary source of truth.
  - Optional aggregate completion marker added.
  - Type compatibility validation added before each FK.
  - FK1/FK2 are no longer blocked by FK3 orphan rows.
  - Migration registered before APCu gate inside orange_schema_check_and_bootstrap().
  - Migration also registered in ensure_schema_core() and ensure_schema_fast_path_slice().
  - No runtime_light_hooks dependency.
  - No business logic changes.
  - No manual SQL required.
  - No data modification.

**PR-DB-02**

- **Title:** Inventory Cost Layer Referential Integrity
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Runtime schema revision updated to 117.
  - Hybrid per-FK migration implemented.
  - Five foreign keys are evaluated independently.
  - Independent orphan audit for each FK.
  - Independent FK existence verification.
  - Independent per-FK migration markers.
  - Constraint existence is the primary source of truth.
  - Optional aggregate completion marker added.
  - Type compatibility validation reused before each FK.
  - FK3 correctly validates inventory_cost_consumptions.layer_id against inventory_cost_layers.id.
  - Migration registered before APCu gate inside orange_schema_check_and_bootstrap().
  - Migration also registered in ensure_schema_core() and ensure_schema_fast_path_slice().
  - No runtime_light_hooks dependency.
  - No business logic changes.
  - No manual SQL required.
  - No data modification.

**PR-DB-03**

- **Title:** Variant Deletion Referential Integrity
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Runtime schema revision updated to 118.
  - product_variants.product_id → products.id referential action repaired safely.
  - Existing CASCADE is repaired only when DELETE_RULE is exactly CASCADE.
  - RESTRICT and NO ACTION are treated as acceptable and are not recreated.
  - Missing FK is handled only by FK-A1 normal hybrid ADD path.
  - stock_movements.variant_id → product_variants.id FK added through hybrid path.
  - Constraint metadata / DELETE_RULE is the primary source of truth.
  - Per-step markers are bookkeeping only.
  - Migration registered before APCu gate inside orange_schema_check_and_bootstrap().
  - Migration also registered in ensure_schema_core() and ensure_schema_fast_path_slice().
  - No runtime_light_hooks dependency.
  - No business logic changes.
  - No manual SQL required.
  - No data modification.

**PR-DB-06 Batch 1**

- **Title:** Duplicate Variant Matrix Integrity — Application Fix
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Product create path now uses deduped matrix identity instead of blind variant INSERT loop.
  - Preview draft save path now uses deduped matrix identity.
  - Duplicate payload rows resolve by matrix fingerprint; last payload row wins.
  - Existing orange_product_sync_variants_matrix() is reused.
  - Existing product create stock behavior preserved: initial stock remains zero and payload stock is ignored.
  - Preview draft stock display behavior preserved by re-applying entered preview stock after sync.
  - No schema changes.
  - Optional UNIQUE constraint not implemented.
  - Existing duplicate rows were not modified.
  - No data modification.

**PR-DB-10 Partial Scope**

- **Title:** Go-Live Filter Indexes
- **Status:** Implemented
- **Approved**
- **Implementation Notes:**
  - Runtime schema revision updated to 119.
  - Runtime migration v119 added.
  - Added index:
    delivery_areas.country_id
    Index:
    idx_delivery_areas_country_id
  - Added index:
    inventory_reconciliation.delivery_agent_id
    Index:
    idx_inv_recon_delivery_agent_id
  - Index existence verified using INFORMATION_SCHEMA.STATISTICS.
  - Per-index migration markers implemented.
  - Optional aggregate marker implemented.
  - Registered before APCu gate inside orange_schema_check_and_bootstrap().
  - Registered in ensure_schema_core().
  - Registered in ensure_schema_fast_path_slice().
  - No runtime_light_hooks dependency.
  - No schema changes outside approved scope.
  - No business logic changes.
  - No manual SQL required.
  - No data modification.

---

## 3. Security Engineering Review Results

### PR-SEC-01

- **Current Engineering Classification:** Defense in Depth
- **Current Engineering Severity:** Not Critical/High for the aggregate finding (defense-in-depth gap; original audit Medium reclassified)
- **Current Engineering Review Decision:** Absence of CSRF tokens on session-authenticated POST JSON admin APIs is a missing second control, not absence of the first control. SameSite=Lax on the session cookie addresses the primary cross-site POST CSRF path for standard admin JSON write endpoints. Residual real CSRF-class exposure on GET-accepting mutators and GET logout is tracked under PR-SEC-01B.
- **Implementation Status:** Future

### PR-SEC-01B

- **Current Engineering Classification:** Real Bug / Real CSRF-Class Issue
- **Current Engineering Severity:** Medium (overall)
- **Current Engineering Review Decision:** Confirmed real CSRF-class issue under SameSite=Lax top-level cross-site GET navigation. Must be resolved before Production Go-Live for `bank-accounts.php` GET toggle and `doc-token/ensure.php` GET. Future for `admin/logout.php` GET and `register.php?logout=1` GET.
- **Implementation Status:** Not Started

### PR-SEC-02

- **Current Engineering Classification:** Real Bug / Real Production Security Issue
- **Current Engineering Severity:** High
- **Current Engineering Review Decision:** Real production security issue — unlimited admin login attempts with no throttling or lockout. Must be resolved before Production Go-Live.
- **Implementation Status:** Not Started

### PR-SEC-03

- **Current Engineering Classification:** Real Bug — Information Disclosure
- **Current Engineering Severity:** Low
- **Current Engineering Review Decision:** Confirmed Low-severity information disclosure via unauthenticated `env-check.php`. Fix Immediately for production hygiene.
- **Implementation Status:** Not Started

### PR-SEC-04

- **Current Engineering Classification:** Real Bug — Authorization Bypass
- **Current Engineering Severity:** Medium
- **Current Engineering Review Decision:** Confirmed Medium-severity authorization bypass on `customers/print-card.php` and `suppliers/print-card.php` — missing page-permission checks. Must be resolved before Production Go-Live.
- **Implementation Status:** Not Started

### PR-SEC-05

- **Current Engineering Classification:** Payment Integrity Issue — Real Bug
- **Current Engineering Severity:** Critical (for online gateway path when enabled)
- **Current Engineering Review Decision:** Confirmed Critical payment-integrity defect in `orange_payment_gateway_settle()` — non-atomic writes plus idempotency that skips order updates when txn is already `paid`. Must be resolved before Production Go-Live before enabling online gateway payments.
- **Implementation Status:** Not Started

### PR-SEC-06

- **Current Engineering Classification:** Real Security Issue + Infrastructure / Deployment Issue
- **Current Engineering Severity:** High
- **Current Engineering Review Decision:** Confirmed real security issue — unauthorized direct HTTP access to sensitive uploads under `/uploads/` with no repo-level access rules. Must be resolved before Production Go-Live.
- **Implementation Status:** Not Started

### PR-SEC-07

- **Current Engineering Classification:** Real Security Issue + Defense in Depth + Deployment / Infrastructure Issue
- **Current Engineering Severity:** Medium
- **Current Engineering Review Decision:** Confirmed real security issue — JSON handlers expose `$e->getMessage()` to clients; no application-level production error hardening in `config.php`. Must be resolved before Production Go-Live.
- **Implementation Status:** Not Started

### PR-SEC-08

- **Current Engineering Classification:** Defense in Depth / Deployment Configuration Issue (primary at default config); Real Security Issue (conditional when misconfigured)
- **Current Engineering Severity:** Medium (when `ORANGE_API_DEBUG` enabled); Low as latent misconfiguration risk at default
- **Current Engineering Review Decision:** Valid defense-in-depth / deployment-configuration finding; becomes real security issue only if `ORANGE_API_DEBUG` is enabled in production. Must be resolved before Production Go-Live.
- **Implementation Status:** Not Started

---

## 4. Database Engineering Review Results

### PR-DB-01

- **Current Engineering Classification:** Real Bug (partial scope) + Architecture Decision + Defense in Depth (partial)
- **Current Engineering Severity:** High
- **Current Engineering Review Decision:** Confirmed real integrity gap for `warehouse_variant_stock` (zero FKs) and `stock_movements.warehouse_id` (index only). Must be resolved before Production Go-Live.
- **Implementation Status:** Not Started

### PR-DB-02

- **Current Engineering Classification:** Real Bug + Architecture Decision
- **Current Engineering Severity:** High
- **Current Engineering Review Decision:** Confirmed real bug — `inventory_cost_layers` and `inventory_cost_consumptions` lack FK enforcement on `variant_id`, `warehouse_id`, and `layer_id`. Must be resolved before Production Go-Live.
- **Implementation Status:** Not Started

### PR-DB-03

- **Current Engineering Classification:** Real Bug (DB bypass path) + Architecture Decision + Defense in Depth (app guard on normal path)
- **Current Engineering Severity:** Critical
- **Current Engineering Review Decision:** Confirmed Critical — product delete CASCADE can orphan WVS/cost rows on DB bypass path; app guard mitigates supported API path. Must be resolved before Production Go-Live.
- **Implementation Status:** Not Started

### PR-DB-04

- **Current Engineering Classification:** Lifecycle Decision (+ Architecture Decision). Not a Real Bug in application lifecycle.
- **Current Engineering Severity:** Low (lifecycle lens; original audit High)
- **Current Engineering Review Decision:** Accept current application lifecycle. Payment records are intentionally immutable audit history; all app writers bind to validated orders. Defer / Future Improvement.
- **Implementation Status:** Future

### PR-DB-05

- **Current Engineering Classification:** Lifecycle Decision (+ Architecture Decision). Not a Real Bug in application lifecycle.
- **Current Engineering Severity:** Low (lifecycle lens; latent only if orders deleted outside the app)
- **Current Engineering Review Decision:** Accept current application lifecycle. Orders are never deleted in application code; CASCADE on order delete is dormant. Defer / Future Improvement.
- **Implementation Status:** Future

### PR-DB-06

- **Current Engineering Classification:** Real Bug (+ Architecture Decision for absent DB constraint)
- **Current Engineering Severity:** Medium
- **Current Engineering Review Decision:** Confirmed Real Bug — duplicate variants possible on product create path; update path deduplicates via matrix sync. Must be resolved before Production Go-Live (at minimum for create path).
- **Implementation Status:** Not Started

### PR-DB-07

- **Current Engineering Classification:** Architecture Decision (+ Future Improvement if global scan-grade uniqueness required). Not a Real Bug under current documented owner policy.
- **Current Engineering Severity:** Low (Medium only if external barcode scanning or global SKU lookup required without `variant_id`)
- **Current Engineering Review Decision:** Defer / Future Improvement — aligns with archived owner policy; no UNIQUE on `item_code` is intentional.
- **Implementation Status:** Future

### PR-DB-08

- **Current Engineering Classification:** Architecture Decision (+ Future Improvement). Not a Real Bug on normal production paths.
- **Current Engineering Severity:** Low–Medium (Low for immediate production risk on validated paths)
- **Current Engineering Review Decision:** Application-level integrity on write paths; historical snapshot semantics on orders. Defer (Future) — after order/payment lifecycle FK policy is settled.
- **Implementation Status:** Future

### PR-DB-09

- **Current Engineering Classification:** Architecture Decision + Lifecycle Decision (phased multicountry migration). Not a Real Bug while legacy rows coexist.
- **Current Engineering Severity:** Low
- **Current Engineering Review Decision:** Application scoping via `country_id` filters and admin asserts; no FK to `countries` during phased migration. Defer (Future) — after legacy NULL/0 backfill completes.
- **Implementation Status:** Future

### PR-DB-10

- **Current Engineering Classification:** Performance Issue (subset) + Future Improvement (subset)
- **Current Engineering Severity:** Medium for `delivery_areas.country_id` and `inventory_reconciliation.delivery_agent_id`; Low for `customer_addresses.delivery_area_id` and `inventory_reconciliation.journal_voucher_id`
- **Current Engineering Review Decision:** Must be resolved before Production Go-Live for actively filtered columns at scale (`delivery_areas.country_id`, `inventory_reconciliation.delivery_agent_id`). Defer (Future) for columns with no current filter queries.
- **Implementation Status:** Not Started

---

## 5. Owner Policies Approved During Review

### Orange Preservation Lifecycle

Orange follows a **Preservation Lifecycle Model**. Business history always takes precedence over physical deletion.

### Product Variant Lifecycle

Removing a color or size from the Variant Matrix must never remove historical business data. If the variant has any business footprint (orders, purchases, returns, warehouse variant stock, stock movements, inventory cost layers, inventory cost consumptions, accounting references, or any future business references), it must never be hard deleted. Such variants must become inactive or hidden while preserving historical integrity.

### Variant Matrix Lifecycle

Decision:

Variant Matrix rows must follow the Orange Preservation Lifecycle. Removing a color or size from the matrix must not delete variants with any business, stock, FIFO, order, purchase, return, warehouse, or accounting footprint.

### Product Tree Parent Lifecycle

A parent catalog tree node (Departments, Sections, Categories, Subcategories, Product Types, or any future catalog tree node) must never be hard deleted while child nodes exist. A parent node must never be hard deleted while products exist beneath it. Only completely empty nodes may be hard deleted. Otherwise the node must become inactive or hidden.

### Historical Business Record Preservation

- **Orders:** Never deleted through any application path; lifecycle is status mutation only.
- **Order items:** Deleted or replaced only through controlled amend and invoice edit while the order row survives.
- **Payment transactions:** Append-only audit history; never deleted in application paths.

### Business History always overrides deletion

Whenever preserving historical business data conflicts with deleting catalog or operational entities, historical preservation always wins.

---

## 6. Remaining Work

Security Implementation Status

Production Go-Live Security Work:

Completed

Remaining Future Work:

PR-SEC-01B Batch 2

(Admin logout GET hardening and storefront logout GET hardening.)

Security

- PR-SEC-01B (Batch 2) — Future logout hardening

Database Implementation Status

Production Go-Live Database Work:

Completed

Remaining Future / Architecture Work:

- PR-DB-04 (Lifecycle Decision)
- PR-DB-05 (Lifecycle Decision)
- PR-DB-07 (Architecture Decision)
- PR-DB-08 (Architecture Decision)
- PR-DB-09 (Architecture Decision)

Performance

- Remaining approved Performance implementations

Configuration

- Remaining Configuration implementations

Deployment

- Remaining Deployment implementations
- Production activation of PR-SEC-06 IIS/web.config fragment during deployment verification

Audit

- ISSUE-05
- ISSUE-11
- ISSUE-12

Final Verification

Production Go-Live Verification

---

## 7. Next Session

Continue the Production Readiness Implementation Roadmap.

Next implementation group:

Performance.

---

## 8. Mandatory Continuation References

Any future AI agent must read these files before continuing:

- docs/archive/ORANGE_ENGINEERING_CHECKPOINT_01.md
- docs/archive/ORANGE_SECURITY_DECISION_SUMMARY.md
- docs/archive/ORANGE_DATABASE_DECISION_SUMMARY.md
- docs/archive/ORANGE_AUDIT_PROGRESS.md
- docs/archive/ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt
- IBRAHIM_ORANGE_MASTER.txt
- ORANGE_AGENT_READ_FIRST.txt

---

## 9. Next Work

The next review track is Performance Review.

Not started yet:

- Performance Review
- Configuration Review
- Backup & Recovery Review
- Deployment Review

---

## Production Readiness Review Status

**Production Readiness Review**

- **Review Status:** Completed
- **Decision Status:** Completed
- **Implementation Status:** Not Started
- **Verification Status:** Pending
- **Release Status:** Not Ready
