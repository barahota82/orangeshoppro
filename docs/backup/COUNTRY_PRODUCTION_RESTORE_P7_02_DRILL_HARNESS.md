# WP-P7-02 — Clone Drill Harness & Environment Binding

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P7-02** — Clone Drill Harness & Environment Binding |
| **Artifact-ID** | `CPR-P7-WP02-DRILL_HARNESS` |
| **Phase** | P7 — Clone drills / real-clone proof |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P7-01 (Artifact Index / control plane) |
| **Unlocks** | WP-P7-03 Drill Scenario Execution (Owner approval required) |
| **Scaffold** | `P7-02-drill-harness` |
| **Enablement** | **FALSE** (hard) — no production SQL; no production uploads; no production services |
| **Architecture / OWNER_APPROVED** | **Not modified** |
| **P2 contracts consumed** | P2-03 §3 / H1 (`drill_context` ∈ `clone` \| `shadow_lab` \| `non_production_fixture`) |

---

## 1. Objective

Implement the **Clone Drill Harness** and **isolated Drill Environment binding** only. Bind a CPR job to an approved clone / non-production environment definition. Do **not** execute DS-* scenarios (WP-P7-03). Operate exclusively against the approved clone environment; never interact with production resources.

---

## 2. Hard rules

- Bind environment to: job identity, execution contract, country, schema revision, approved clone environment definition  
- Complete environment isolation; fail-closed on any mismatch  
- No production database access; no production uploads; no production services  
- Deterministic environment validation; production endpoint / marker detection  
- No replay; no privilege bypass; enablement remains FALSE  
- No Architecture changes; no OWNER_APPROVED changes  
- No DS-* scenario execution in this WP  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_drill_harness_live.php` | `orange_cpr_drill_harness_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_DRILL_HARNESS_DIRNAME`; scaffold `P7-02-drill-harness` |
| `includes/backup/country_production/cpr_p7_control_plane.php` | `drill_harness_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_drill_harness_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md` | WP-P7-02 COMPLETE; stop → WP-P7-03 |

### Binding surface

Request must supply `clone_environment` with at least:

- `clone_environment_id`, `drill_context`, `clone_work_root`  
- `schema_revision`, `country_id`, `country_code`, `package_fingerprint`  
- `isolation_confirmed` or `environment_isolated` = true  
- No production markers (`is_production`, `production_*` endpoints/DSNs, `db_role=production`, `allow_production_access`, …)

### Outputs (sealed under `{job}/drill_harness/`)

1. Environment binding report (`drill_environment_binding`)  
2. Drill harness report (`drill_harness_report`)  
3. Audit events: `cpr.drill_harness_live_bind`, `cpr.drill_harness_live_complete`  
4. Recovery metadata (Architecture-required fields; `scenario_execution_not_started=true`)  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Clone Drill Harness implemented | **PASS** |
| AC2 | Isolated Drill Environment binding implemented | **PASS** |
| AC3 | Operates exclusively against approved clone environment | **PASS** |
| AC4 | Never interacts with production resources (DB/uploads/services) | **PASS** |
| AC5 | Binds job identity, execution contract, country, schema revision, clone env definition | **PASS** |
| AC6 | Complete isolation; fail-closed on env/schema/country/contract mismatch | **PASS** |
| AC7 | Production endpoint detection; deterministic validation | **PASS** |
| AC8 | No replay; no privilege bypass | **PASS** |
| AC9 | Sealed environment binding report + sealed harness report | **PASS** |
| AC10 | Audit events + recovery metadata produced | **PASS** |
| AC11 | Enablement FALSE; no production SQL/uploads; Architecture/OD unchanged | **PASS** |
| AC12 | No DS-* scenario execution; WP-P7-03 not started | **PASS** |
| AC13 | Self-tests + PHP lint + full CPR suite green | **PASS** |

---

## 5. Stop rule

**WP-P7-02 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P7-03** until Owner explicitly reviews and approves the next Work Package.

---

*End of CPR-P7-WP02-DRILL_HARNESS.*
