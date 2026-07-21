# WP-P5-03 — Target-Slice IMPORT Engine (Batches 1→6)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P5-03** — Target-Slice IMPORT Engine (Batches 1→6) |
| **Artifact-ID** | `CPR-P5-WP03-TARGET_SLICE_IMPORT` |
| **Phase** | P5 — Production Apply |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P5-02 COMPLETE (CP6 / DELETE); P4 CP-A path |
| **Unlocks** | WP-P5-04 Special Handlers |
| **Scaffold** | `P5-03-import-live` |
| **Enablement** | **FALSE** (hard) — no production SQL |
| **Architecture / OWNER_APPROVED** | **Not modified** |

---

## 1. Objective

Implement the live Target-Slice IMPORT engine that executes frozen restore **Batches 1→6** (COUNTRY_DEPENDENCY_GRAPH §4 / Architecture §10.2 A) after a valid DELETE (CP6), integrating State (T10), Checkpoint (CP7), Lock, Gate, Authority, Execution Contract, Job identity, OD-PIN / session Full Backup, and schema revision binding.

---

## 2. Normative batch order (do not invent/merge/reorder/skip)

| Batch | Tables (frozen) |
|------:|-----------------|
| 1 | 36 root country-scoped tables (incl. `products`, `storefront_accounts`, …) |
| 2 | 22 dependents (incl. `orders`, `product_channels`, …) |
| 3 | 13 dependents |
| 4 | 7 dependents (incl. `order_items`, …) |
| 5 | `inventory_cost_consumptions` |
| 6 | `document_sequences` (table import only; **special handler behavior → WP-P5-04**) |

Import order version: `c1.1-import_order/1` · dependency graph version: `1`.

Target-slice rows are the intersection of each batch’s frozen table list with the sealed DELETE ledger for the same job/contract/country.

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_import_batches.php` | Frozen batch catalog + RI parent map |
| `includes/backup/country_production/cpr_import_live.php` | Live IMPORT engine `orange_cpr_import_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_IMPORT_LIVE_DIRNAME`; scaffold `P5-03-import-live` |
| `includes/backup/country_production/cpr_p5_control_plane.php` | `production_import_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_import_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md` | WP-P5-03 COMPLETE |

### Integration

- Requires sealed DELETE report + **CP6**
- T10 `cpr_deleting` → `cpr_importing` on start
- Per-batch sealed input manifest, mutation manifest, execution report
- Resume only from last sealed batch boundary (no statement-offset)
- On failure: T31 pause (OD-FAIL-IMPORT); no auto-rollback
- Final sealed IMPORT summary + **CP7**; state remains `cpr_importing` (no auto special/uploads)

### Enablement FALSE path

Mutations apply to job-bound `import_live/target_slice_import_ledger.json` with `production_sql_executed=false`. Production SQL remains gated by OD-ENABLE.

---

## 4. Artifacts

| Artifact | Location |
|----------|----------|
| Per-batch input / mutation / report | `import_live/cpr_import_batch_{N}_*.json` + batch report latest |
| Import ledger | `import_live/target_slice_import_ledger.json` |
| Final summary | `import_live/cpr_import_summary_*.json` + summary latest |
| Checkpoint | `checkpoints/CP7_import_complete.json` |

**Audit:** `cpr.import_live_start` · `cpr.import_live_batch_complete` · `cpr.import_live_batch_fail` · `cpr.import_live_complete`

---

## 5. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Live IMPORT engine Batches 1→6 implemented | **PASS** |
| AC2 | Frozen batch order; no skip/reorder/merge | **PASS** |
| AC3 | Operates only on contract/DELETE-bound target slice + source artifacts | **PASS** |
| AC4 | Integrates State/Checkpoint/Lock/Gate/Authority/Contract/Job/OD-PIN/schema | **PASS** |
| AC5 | IMPORT only after valid DELETE/CP6 | **PASS** |
| AC6 | Binding holds across all six batches | **PASS** |
| AC7 | Per-batch prereqs, manifests, seals; later batch waits on prior seal | **PASS** |
| AC8 | Fail-closed on scope/country/source/lock/RI/count/fingerprint | **PASS** |
| AC9 | Resume only from last sealed batch boundary; invalid resume refused | **PASS** |
| AC10 | Sealed final IMPORT summary + CP7 + audit + recovery metadata | **PASS** |
| AC11 | Special handlers / uploads disabled; enablement FALSE; no production SQL | **PASS** |
| AC12 | Architecture / OWNER_APPROVED unchanged | **PASS** |
| AC13 | Self-tests + PHP lint + full CPR suite green | **PASS** |
| AC14 | P5 Artifact Index WP-P5-03 COMPLETE | **PASS** |

---

## 6. Stop rule

**WP-P5-03 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P5-04** until Owner review and approval.

---

*End of WP-P5-03.*
