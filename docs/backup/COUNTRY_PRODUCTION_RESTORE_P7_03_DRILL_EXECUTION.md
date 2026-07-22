# WP-P7-03 — Drill Scenario Execution (P2-03 DS-*)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P7-03** — Drill Scenario Execution (P2-03 DS-* Catalog) |
| **Artifact-ID** | `CPR-P7-WP03-DRILL_EXECUTION` |
| **Phase** | P7 — Clone drills / real-clone proof |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P7-02 (harness bound); P2-03 catalog; P3–P6 engines (consume) |
| **Unlocks** | WP-P7-04 Evidence Pack Assembly & Seal (Owner approval required) |
| **Scaffold** | `P7-03-drill-execution` |
| **Enablement** | **FALSE** (hard) — no production SQL; no production uploads; no production services |
| **Architecture / OWNER_APPROVED** | **Not modified** |
| **Catalog SoT** | `COUNTRY_PRODUCTION_RESTORE_P2_03_DRILL_SCENARIOS.md` §5 inventory |

---

## 1. Objective

Implement **execution of the approved P2-03 DS-* drill scenarios only**, exclusively inside an approved clone drill environment bound by WP-P7-02. Preserve deterministic catalog order. Do **not** invent, reorder, merge, or skip scenarios. Do **not** assemble/seal evidence packs (WP-P7-04).

---

## 2. Hard rules

- Clone / non-production `drill_context` only (P2-03 H1)  
- No production SQL / uploads / services  
- No replay; no privilege bypass; no cross-country execution  
- Fail-closed on environment, contract, country, schema, or scenario validation failure  
- Enablement FALSE; no Architecture / OWNER_APPROVED changes  
- Integrate harness, state engine, checkpoint engine, recovery metadata, audit, execution contract, job identity, country, schema revision  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_drill_catalog.php` | Frozen DS-* inventory + order assertions |
| `includes/backup/country_production/cpr_drill_execution_live.php` | `orange_cpr_drill_execution_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_DRILL_EXECUTION_DIRNAME`; scaffold |
| `includes/backup/country_production/cpr_p7_control_plane.php` | `drill_execution_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_drill_execution_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md` | WP-P7-03 COMPLETE; stop → WP-P7-04 |

### Flow

1. Preconditions: enablement FALSE · Super Admin · harness binding sealed · clone env re-validated · contract/country/schema match  
2. Scenario list = full frozen catalog (default) or catalog-order prefix (tests only); reject invent/reorder/skip/merge  
3. For each DS-*: sealed scenario report + fingerprint + audit; state/checkpoint integration attested  
4. Seal aggregate drill report + recovery metadata  

### Runtime layout

`{job}/drill_execution/` — per-scenario sealed reports + aggregate latest pointers  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | DS-* catalog execution engine implemented | **PASS** |
| AC2 | Executes only frozen P2-03 scenarios; no invent/reorder/merge/skip | **PASS** |
| AC3 | Deterministic catalog execution order | **PASS** |
| AC4 | Exclusively inside approved clone drill environment | **PASS** |
| AC5 | Integrates harness / state / checkpoint / recovery / audit / contract / job / country / schema | **PASS** |
| AC6 | No production SQL/uploads/services; resources never accessed | **PASS** |
| AC7 | No replay; no privilege bypass; no cross-country; fail-closed | **PASS** |
| AC8 | Sealed per-scenario reports + sealed aggregate | **PASS** |
| AC9 | Audit events + recovery metadata + scenario fingerprints | **PASS** |
| AC10 | Enablement FALSE; Architecture/OD unchanged; no evidence pack (P7-04) | **PASS** |
| AC11 | Self-tests + PHP lint + full CPR suite green | **PASS** |

---

## 5. Stop rule

**WP-P7-03 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P7-04** until Owner explicitly reviews and approves the next Work Package.

---

*End of CPR-P7-WP03-DRILL_EXECUTION.*
