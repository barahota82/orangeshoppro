# WP-P4-06 — Live Gate Evaluation & Pre-PONR Readiness

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P4-06** — Pre-PONR Gate suite live evaluation |
| **Artifact-ID** | `CPR-P4-WP06-GATE_LIVE` |
| **Status** | COMPLETE |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Depends on** | WP-P4-04 · WP-P4-05 · WP-P3-06 · OD-C8 · OD-ENABLE · OD-FA-* · OD-INV · OD-PIN |
| **Maps to** | Architecture §35–§37 · P1-08 · P3-06 |

---

## 1. Purpose

Implement the **live** `pre_ponr_full` gate evaluation flow using the P3 Gate Engine: evaluate **every** mandatory gate (G01–G30 + FA), fail-closed, seal a live report, integrate lock ownership revalidation / OD-PIN / contract / state — with **no** skip, WARNING path, or Super Admin bypass.

**Explicitly out of scope:**

- Authority / Runbook / Phrase live ceremony (WP-P4-07)  
- DELETE / IMPORT / PONR mutation  
- Enablement TRUE (ops flag)  
- C3–C8 engine modifications  

---

## 2. Implementation root

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_gates_live.php` | Live gate orchestration |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_GATES_LIVE_DIRNAME`; scaffold `P4-06-gates-live` |
| `scripts/backup/country_production/self_test_cpr_gates_live.php` | Self-tests |

**Consumed:** `orange_cpr_gate_evaluate` · `orange_cpr_lock_live_revalidate_ownership` · OD-PIN pin · maint evidence · contract / checkpoints.

---

## 3. Live API

`orange_cpr_gates_live_evaluate($env, $jobId, $request)`:

1. Assert ops enablement **FALSE**  
2. Super Admin actor  
3. Refuse bypass / skip / WARNING / replay-without-revalidate  
4. Require `cpr_pre_ponr` + frozen contract + CP4/CP1 + GLOBAL maint + OD-PIN pin  
5. Revalidate lock ownership (P4-05)  
6. Build evidence (contract, checkpoints, lock, session pin, inventory, C4–C8, schema, authority)  
7. Pre-validate evidence vs contract fingerprints  
8. Run P3 `pre_ponr_full` suite  
9. Assert every mandatory gate evaluated (no silent skip)  
10. Seal live report under `gates_live/` + audit  

---

## 4. Consumed inputs only

| Input | Source |
|-------|--------|
| Execution contract | `execution_contract.json` |
| Checkpoints | `checkpoints/` (CP4/CP1 + suite predicates) |
| Lock ownership | Live lock revalidate |
| Session Full Backup | Contract + OD-PIN pin |
| Inventory snapshot | Evidence bound to contract |
| C4–C8 reports | Request evidence (hashes vs contract) |
| Schema revision | `live_sot` vs contract |
| Authority artifacts | Evidence authority slice |

---

## 5. Artifacts

| File | Content |
|------|---------|
| `gates/cpr_gate_evaluation_pre_ponr_full_*.json` | P3 sealed evaluation |
| `gates_live/cpr_gates_live_{id}.json` | Sealed live wrapper |
| `gates_live/cpr_gates_live_latest.json` | Latest pointer |

**Audit:** `cpr.gates_live_evaluate`

---

## 6. Deterministic codes (selected)

| Code | Meaning |
|------|---------|
| `ok` | Full suite PASS + sealed |
| `gatelive_suite_failed` | Suite FAIL (fail-closed) |
| `gatelive_bypass_forbidden` / `_skip_forbidden` / `_warning_path_forbidden` | Unsafe options |
| `gatelive_replay_forbidden` | Cached replay without re-eval |
| `gatelive_lock_invalid` | Lock ownership fail |
| `gatelive_session_pin_missing` | OD-PIN / contract pin missing |
| `gatelive_fingerprint_drift` | Report/package/inventory drift |
| `gatelive_c8_not_safe` | C8 not SAFE |
| `gatelive_inventory_missing` | Inventory missing |
| `gatelive_schema_mismatch` | Schema mismatch |
| `gatelive_evidence_stale` / `_corrupt` | Stale/corrupt evidence |
| `gatelive_mandatory_gates_incomplete` | Not all gates evaluated |

---

## 7. Acceptance Criteria

| # | Criterion | Met |
|---|-----------|-----|
| AC1 | Live gate evaluation uses P3 Gate Engine | YES |
| AC2 | Complete `pre_ponr_full` suite executed | YES |
| AC3 | Consumes only allowed evidence classes | YES |
| AC4 | Every mandatory gate evaluated; no skip | YES |
| AC5 | Fail-closed; no WARNING path; no Super Admin bypass | YES |
| AC6 | No live inventory replacement; no stale/modified acceptance | YES |
| AC7 | Sealed live gate report + deterministic codes + audit | YES |
| AC8 | Integrated State / Lock / Authority / OD-PIN | YES |
| AC9 | Enablement FALSE; no DELETE/IMPORT/PONR/production mutation | YES |
| AC10 | Architecture / OD / C3–C8 / prior WPs unmodified (scaffold test bumps only) | YES |
| AC11 | Self-tests cover required cases; PHP lint clean | YES |

---

## 8. Stop rule

**WP-P4-06 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P4-07 until Owner explicitly reviews and approves.
