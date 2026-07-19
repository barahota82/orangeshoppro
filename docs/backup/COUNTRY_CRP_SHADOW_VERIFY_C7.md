# Country Recovery Package — Shadow Verification (Phase C7)

**Status:** Country Shadow Verification ONLY  
**Does not implement:** Country Production Restore, Country Production Import, Country Rollback, Country Maintenance, Country Approval, Country Certification, or Country Production Enablement.

**Confirmation:** `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED = false`. Production remains untouched (`production_db_writes = 0`, `execution_performed = false`).

## C6 restore vs C7 verification

| Phase | Responsibility |
|-------|----------------|
| **C6** | Import CRP into isolated Country Shadow DB; write restore report; capture **live survivor** and **Global** baselines **before** target-slice clear (`seeded_multicountry_target_slice`) |
| **C7** | Consume C6 result only — **no re-import**; **live probe** vs baselines (F-01); read-only SQL for accounting/FIFO/composites (F-03); score readiness for Dry Run |

**F-01 / EA-02:** C7 must not treat baseline as current. Missing live `survivor_current` / `global_current` → `survivor_probe_unavailable` / `global_probe_unavailable` (FAIL). Global/`journal_entries` proven by **baseline delta/hash**, never by requiring empty tables.  
**F-03 / EA-03:** Live SQL pillars: accounting, FIFO, composites, dependency, commercial, catalog, sequences, uploads (zip path safety), ID preservation, schema. Inject hooks remain for regression only. Fail closed when a live pillar is unproven.

C7 entry requires C6 status `ready` / `country_shadow_restore_ready` (re-verify also allowed from C7 result states), C4 Verify **PASS**, C5 Country DRV **pass** with score ≥ 85, unchanged package fingerprint / boundary / dependency versions, consistent `country_id`, and proven Shadow DB ≠ production.

## Survivor-country preservation proof

Before C6 clears mutate tables it writes `survivor_baseline.json` (row counts + hashes for non-target country slices).

C7 compares post-restore survivor probe/current values to that baseline:

- counts preserved
- hashes preserved where present
- no deletion / update inject tolerated
- explicit field: `survivor_country_integrity = PASS | FAIL`

A Country shadow cannot be READY without survivor proof.

## Global-state immutability

C6 writes `global_baseline.json` (Global / Full-only / never-export tables such as `journal_entries`, screen-copy log, taxonomy markers).

C7 fails closed on:

- Global table count/hash drift
- `journal_entries` change
- Global taxonomy mutation
- Field: `global_state_integrity`

## Composite verification

Composites A–H are evaluated as units (not table counts alone):

- admins + admin_permissions  
- GL voucher graph  
- warehouses + stock + movements + FIFO  
- polymorphic company documents  
- commercial orders/purchases/returns/payments  
- sellable catalog graph  
- expenses + account ownership  
- document_sequences  

Outcomes per failure mode: incomplete / contaminated / blocked → stable reason codes; `composite_integrity` must be **PASS** for READY.

## Country readiness scoring

Deterministic Country-specific policy:

| Result | Rule |
|--------|------|
| **READY** | score ≥ 90, no blockers, and PASS for target, survivor, global, accounting, stock/FIFO, composite |
| **WARNING** | score 75–89, no leakage / Global mutation / accounting or stock-FIFO blockers; documented warnings only |
| **FAIL** | any leakage, survivor change, Global change, missing composite, accounting uncertainty, stock/FIFO corruption, ID/sequence collision, production identity ambiguity, schema incompatibility |

Warnings never mask blockers.

## Blocking codes (stable examples)

`c6_report_missing`, `c6_not_ready`, `wrong_shadow_db_identity`, `production_db_identity_rejected`, `package_fingerprint_changed`, `target_country_row_missing`, `cross_country_row_inserted`, `survivor_country_row_deleted`, `survivor_country_row_modified`, `global_table_changed`, `journal_entries_changed`, `missing_dependency_parent`, `cross_country_fk`, `incomplete_admin_composite`, `global_admin_changed`, `incomplete_gl_composite`, `gl_graph_unbalanced`, `missing_account`, `stock_warehouse_ownership_mismatch`, `stock_movement_leakage`, `incomplete_fifo_graph`, `fifo_layer_overconsumed`, `missing_order_item`, `payment_orphan`, `global_taxonomy_mutation`, `product_collision`, `unknown_polymorphic_document_owner`, `document_owned_by_another_country`, `sequence_lowered`, `sequence_namespace_collision`, `missing_upload_reference`, `upload_owner_mismatch`, `pk_collision`, `auto_increment_too_low`, `schema_mismatch`, `accounting_boundary_not_proven`

## States

```
country_shadow_restore_ready (C6 ready)
  → country_shadow_verifying
  → country_shadow_verified | country_shadow_warning | country_shadow_not_ready
```

No production execution state is added.

## CLI

```bash
php scripts/backup/verify_country_shadow.php --job=cc_YYYY-MM-DD_HHMMSS
```

CLI only. Job ID only (no DB names / paths). Non-zero exit on non-READY.

## HTTP / UI

- `GET admin/api/restore/country-shadow-verify-status.php?job_id=…`
- `GET admin/api/restore/country-shadow-status.php?run_id=…` includes `verify_summary` when present
- Restore Center: read-only Country Shadow Verification panel (score + integrity badges)

No Import / Restore / Execute / Approval / Maintenance / Rollback / Production enablement buttons for Country.

## Report

`{countryShadowWorkDir}/country_shadow_verification_report.json`

Redacted: no absolute paths, credentials, raw SQL, or personal data.
