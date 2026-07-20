# Country Production Restore — P1 Pre-PONR Gate Contract (Machine-Checkable PASS List)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-08** — Pre-PONR Gate Contract (Machine-Checkable PASS List) |
| **Artifact-ID** | `CPR-P1-WP08-PRE_PONR_GATES` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-C8 · OD-INV · OD-PIN · OD-FA-* · OD-SCHEMA · OD-LOCK-* · OD-MAINT* · Integrity Principle) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §35–§37, §19 (preconditions) |
| **Depends on** | WP-P1-02 · WP-P1-04 · WP-P1-05 · WP-P1-06 · WP-P1-07 |
| **Coding** | **No** gate-evaluator PHP in this WP |

---

## 1. Purpose

Convert Architecture **§37 Explicit PASS list** into an ordered, **machine-checkable** gate suite with **fail-closed** evaluation so PONR cannot begin unless **every** gate returns PASS.

This WP does **not** modify C3–C8 engines, Architecture, Owner Decisions, or prior P1 artifacts. CPR **consumes** C3–C8 reports as immutable inputs (Architecture §35).

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | **All** §37 items must evaluate PASS — no silent defaults | Architecture §37 |
| H2 | Fail-closed: missing proof / unknown / error → **FAIL** (not skip) | Integrity Principle |
| H3 | **No** WARNING path to PONR; C8 must be **SAFE** only | OD-C8 |
| H4 | **No** waiver, Continue Anyway, Super Admin bypass, or skipped gate | OD-C8 · Integrity |
| H5 | Live DB reads may **only verify** OD-INV snapshot — **never replace** it | OD-INV |
| H6 | OD-PIN order: GLOBAL Maint ON → **NEW** Full Backup → verify → pin; never reuse existing | OD-PIN · WP-P1-04/07 |
| H7 | No concurrent Full DR; no concurrent C6 | OD-LOCK-CROSS · OD-LOCK-SHADOW |
| H8 | Fixture soft-skip on schema gates **forbidden** in Production/Certification | OD-FA-SCHEMA |
| H9 | Ownership guessing / country_id shortcuts **forbidden** | OD-FA-RESOLVER |
| H10 | PONR forbidden unless aggregate `all_gates_pass === true` | Architecture §37 “Only then” |
| H11 | Enablement remains **hard false** until OD-ENABLE path; P1 does not flip the flag | OD-ENABLE · WP-P1-01 |

---

## 3. Evaluation model (fail-closed)

### 3.1 Result enum (per gate)

| Result | Meaning |
|--------|---------|
| `PASS` | Predicate proven true from durable evidence |
| `FAIL` | Predicate false, proof missing, drift, or error |
| `SKIP` | **Forbidden** for any gate in this suite |

### 3.2 Aggregate rules

1. Evaluate gates in **order** G01…G30 (and FA sub-predicates as listed).  
2. On first `FAIL`, record it; **continue evaluating remaining gates** for diagnostics (operator visibility) but aggregate remains FAIL.  
3. Aggregate PASS **only if** every required gate is `PASS`.  
4. Any exception/timeout reading a report → that gate `FAIL` (`gate_eval_error`).  
5. Absent checkpoint / absent report file → `FAIL` (`gate_evidence_missing`).  
6. WARNING / soft / waiver fields in evaluator config → **reject configuration** (design: such knobs must not exist).

### 3.3 When suite must run

| Moment | Required |
|--------|----------|
| Before writing CP0 `gates_passed` | Package/CRP chain + platform subset (see §5 profiles) |
| Before CP-A / PONR (T09) | **Full** suite G01–G30 all PASS |
| Pre-PONR fingerprint re-read | Drift gates re-evaluated; any mismatch FAIL |

### 3.4 Evaluation profiles

| Profile | Gates | Use |
|---------|-------|-----|
| `package_chain` | G07–G19 (+ FA schema/resolver proofs bound to reports) | Early CP0 |
| `pre_ponr_full` | **G01–G30 all** | Immediately before PONR / CP-A |

PONR uses **`pre_ponr_full` only**. Partial profiles must not authorize mutation.

---

## 4. Evidence sources (read-only)

| Source | Role |
|--------|------|
| C4/C5/C6/C7/C8 reports | Immutable hashes in WP-P1-02 contract; re-hash at eval |
| Certified inventory snapshot | OD-INV id + hash |
| `cpr_execution_contract` | Frozen fingerprints |
| Checkpoints CP0–CP5, CP1, CP4, `runbook_pre_ponr` | WP-P1-04 |
| Lock files | WP-P1-05 |
| `maint_state.json` | WP-P1-07 |
| Auth challenge | WP-P1-06 |
| Live SoT | Schema revision, registry, boundary versions — **verify only** |

**Forbidden:** Mutating C3–C8 engines; rewriting inventory snapshot from live SELECT; treating shadow DB as production.

---

## 5. Ordered gate catalog (Architecture §37 → machine predicates)

Each gate: **ID**, §37 #, **predicate** (all must hold), **fail code**, **OD/Arch**.

### A. Platform / policy

#### G01 — Enablement path (§37.1)

| Field | Value |
|-------|-------|
| Predicate | `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED` (or successor) is `true` **only if** OD-ENABLE preconditions are recorded: Certification PASS (Owner), explicit Owner enablement order, implementation completed, Final Enterprise approval; **otherwise flag must remain false and G01 = FAIL for PONR** |
| P1 design note | Until OD-ENABLE path completes, flag stays **hard false** → `pre_ponr_full` **FAIL** (`gate_enablement_false`) — correct fail-closed behavior |
| Fail codes | `gate_enablement_false` · `gate_enablement_preconditions_incomplete` |
| Authority | OD-ENABLE · OD-CERT · OD-PERM |

#### G02 — OD-DUAL model present (§37.2)

| Predicate | Job `workflow` ∈ {`A`,`B`}; no waiver flag; no dual-Super-Admin marker |
| Fail codes | `gate_dual_model_invalid` |
| Authority | OD-DUAL · WP-P1-06 |

#### G03 — OD-PIN mechanism available (§37.3)

| Predicate | Platform can create/verify/pin Full Backup under Maint (capability probe PASS) |
| Fail codes | `gate_pin_mechanism_unavailable` |
| Authority | OD-PIN |

#### G04 — Certification PASS (§37.4)

| Predicate | Country Production certification record `result = PASS` by Owner for current schema/package cert cycle |
| Fail codes | `gate_cert_not_pass` |
| Authority | OD-CERT |

#### G05 — No concurrent Full DR / C6 (§37.5)

| Predicate | Full DR lock family **not held**; C6 shadow lock **not held** (WP-P1-05); Backup Runner not blocking per exclusion matrix |
| Fail codes | `gate_full_dr_active` · `gate_c6_active` · `gate_backup_runner_active` |
| Authority | OD-LOCK-CROSS · OD-LOCK-SHADOW |

#### G06 — Host preflight PASS (§37.6)

| Predicate | ZipArchive available; disk free ≥ engineering minimum; PHP runtime OK; production DB connectivity OK — overall `preflight = PASS` |
| Fail codes | `gate_host_preflight_fail` |
| Authority | Architecture §36 |

---

### B. Package / CRP chain

#### G07 — Package finalized (§37.7)

| Predicate | Package status finalized; `package_id` matches job/contract |
| Fail codes | `gate_package_not_final` |
| Authority | Architecture §37 |

#### G08 — C4 PASS (§37.8)

| Predicate | C4 report `overall = PASS` (exact); report hash = contract `c4_report_hash` |
| Fail codes | `gate_c4_not_pass` · `gate_c4_hash_drift` |
| Authority | Architecture §35 |

#### G09 — C5 PASS (§37.9)

| Predicate | C5 `overall_result = pass` **and** `recovery_score ≥ 85`; hash = contract `c5_report_hash` |
| Fail codes | `gate_c5_not_pass` · `gate_c5_score_low` · `gate_c5_hash_drift` |
| Authority | Architecture §35 |

#### G10 — Package fingerprint stable (§37.10)

| Predicate | Live package fingerprint equals contract `package_fingerprint` and matches C4/C5 binding |
| Fail codes | `gate_package_fingerprint_drift` |
| Authority | Architecture §35 Fingerprint continuity |

#### G11 — C6 PASS (§37.11)

| Predicate | C6 status ∈ {`ready`, success-equivalent}; `production_touched = false`; hash = contract `c6_report_hash` |
| Fail codes | `gate_c6_not_ready` · `gate_c6_production_touched` · `gate_c6_hash_drift` |
| Authority | Architecture §35; OD-LOCK-SHADOW (no concurrent active C6 at G05) |

#### G12 — C7 READY (§37.12)

| Predicate | C7 `overall_result = READY` **and** `readiness_score ≥ 90`; hash = contract `c7_report_hash` |
| Fail codes | `gate_c7_not_ready` · `gate_c7_score_low` · `gate_c7_hash_drift` |
| Authority | Architecture §35 |

#### G13 — C7 survivor integrity (§37.13)

| Predicate | C7 `survivor_country_integrity = PASS` |
| Fail codes | `gate_c7_survivor_fail` |
| Authority | Architecture §37 |

#### G14 — C7 global integrity (§37.14)

| Predicate | C7 `global_state_integrity = PASS` |
| Fail codes | `gate_c7_global_fail` |
| Authority | Architecture §37 |

#### G15 — C7 pillars PASS (§37.15)

| Predicate | C7 accounting / stock_fifo / composite pillars all **PASS**; no unproven live pillars |
| Fail codes | `gate_c7_pillar_fail` · `gate_c7_pillar_unproven` |
| Authority | Architecture §37; OD-FA-STOCK (pre-proof) |

#### G16 — C8 SAFE only (§37.16)

| Predicate | C8 `overall_result = SAFE` **exactly**; reject `WARNING`, `FAIL`, missing, or any waiver bit |
| Fail codes | `gate_c8_not_safe` · `gate_c8_warning_rejected` · `gate_c8_waiver_forbidden` |
| Authority | **OD-C8** |

#### G17 — C8 impact zeros (§37.17)

| Predicate | `survivor_country_impact = 0` **and** `global_impact = 0` **and** JE/full-only impact `= 0` |
| Fail codes | `gate_c8_survivor_impact` · `gate_c8_global_impact` · `gate_c8_je_impact` |
| Authority | OD-C8 · Architecture §37 |

#### G18 — C8 simulation-only (§37.18)

| Predicate | `simulation_only = true` **and** `execution_performed = false` |
| Fail codes | `gate_c8_not_simulation` · `gate_c8_execution_performed` |
| Authority | Architecture §35–§37 |

#### G19 — Version alignment (§37.19)

| Predicate | Boundary / dependency / schema versions match across package manifest, C7, C8, and live SoT; equal contract `schema_revision_expected`, `boundary_policy_version`, `dependency_graph_version`, `registry_revision` |
| Fail codes | `gate_version_mismatch` · `gate_schema_revision_mismatch` |
| Authority | OD-SCHEMA · OD-FA-SCHEMA · Architecture §36 |

---

### C. Job / approvals / anchor

#### G20 — Job identity (§37.20)

| Predicate | CPR job exists for exact contract `package_id` + `country_id` (+ `country_code`) |
| Fail codes | `gate_job_identity_mismatch` |
| Authority | WP-P1-02 |

#### G21 — OD-INV certified inventory (§37.21)

| Predicate | Snapshot present with `certified_read_only = true`; id/hash = contract; immutable; cryptographically bound to session; live SELECT (if any) **verifies** hash only and does **not** replace snapshot id/hash |
| Fail codes | `gate_inv_missing` · `gate_inv_not_certified` · `gate_inv_replaced_by_live` · `gate_inv_hash_drift` |
| Authority | **OD-INV** |

#### G22 — GLOBAL Maint ON + proven (§37.22)

| Predicate | `maint_scope = GLOBAL`; `global_maintenance_on = true`; `write_block_proven = true` (WP-P1-07); CP4 committed |
| Fail codes | `gate_maint_off` · `gate_maint_not_global` · `gate_write_block_unproven` |
| Authority | OD-MAINT · OD-MAINT-SCOPE |

#### G23 — OD-PIN sequence complete (§37.23)

| Predicate | **All** of: (1) Maint was ON before backup create; (2) `session_full_backup_id` is **NEW** for this job; (3) `reused_existing_backup = false`; (4) verified; (5) `session_full_backup_pinned = true`; (6) CP1 after CP4 (WP-P1-04 DAG) |
| Fail codes | `gate_pin_order_violated` · `gate_pin_reused_backup` · `gate_pin_not_verified` · `gate_pin_not_pinned` |
| Authority | **OD-PIN** |

#### G24 — OD-DUAL authority satisfied (§37.24)

| Predicate | WF-A: protections path complete per WP-P1-06; **or** WF-B: Super Admin approval recorded + fingerprint; Country Admin must not be executor |
| Fail codes | `gate_authority_unsatisfied` · `gate_wfb_approval_missing` |
| Authority | OD-DUAL · OD-PERM · WP-P1-06 |

#### G25 — Contract frozen + fingerprint re-read (§37.25)

| Predicate | `contract_frozen = true`; live re-read of package/C4–C8/inventory/DB identity/enablement/C8 SAFE matches frozen set — any drift FAIL |
| Fail codes | `gate_contract_not_frozen` · `gate_contract_fingerprint_drift` |
| Authority | WP-P1-02 · Architecture §35 |

#### G26 — CPR production lock held (§37.26)

| Predicate | CPR lock held by **this** `job_id`; heartbeat present; `ponr_crossed` still false at this gate moment |
| Fail codes | `gate_cpr_lock_not_held` · `gate_cpr_lock_wrong_job` |
| Authority | WP-P1-05 · Architecture §15 |

#### G27 — OD-RUNBOOK complete (§37.27)

| Predicate | `runbook_pre_ponr` committed; minimum fields PASS (WP-P1-04/06); audited |
| Fail codes | `gate_runbook_incomplete` |
| Authority | OD-RUNBOOK |

#### G28 — Pre-PONR witnesses (§37.28)

| Predicate | CP5 committed; witness hashes match expectations / no drift |
| Fail codes | `gate_witness_missing` · `gate_witness_drift` |
| Authority | Architecture §18 CP5 · WP-P1-04 |

#### G29 — OD-PHRASE challenge (§37.29)

| Predicate | Super Admin password re-auth OK; phrase exact `RESTORE`; `one_time_authorization_id` bound and unconsumed |
| Fail codes | `gate_phrase_failed` · `gate_reauth_failed` · `gate_ota_missing` |
| Authority | OD-PHRASE · WP-P1-06 |

#### G30 — Emergency stop clear (§37.30)

| Predicate | `cpr_emergency_stop` is false / clear |
| Fail codes | `gate_emergency_stop_set` |
| Authority | Architecture §28 |

---

## 6. OD-FA-* pre-PONR predicates (mandatory; fail-closed)

These are **first-class gates** evaluated as part of `pre_ponr_full` (and reflected in CP0/FA evidence). They bind FA residuals before execution.

### G-FA-RESOLVER — Ownership matrix (§ OD-FA-RESOLVER)

| Predicate | Engine/config proves certified Ownership Resolver Matrix will be used; **no** country_id-column shortcut override; membership unproven → FAIL |
| Fail codes | `gate_fa_resolver_unproven` · `gate_fa_resolver_shortcut` |
| Note | Fail **before** execution if ownership cannot be proven |

### G-FA-STOCK — Stock/FIFO readiness (§ OD-FA-STOCK)

| Predicate | Pre-PONR proof that stock/FIFO verification predicates are **armed** (warehouse ownership, stock ownership, FIFO integrity, cross-country refs) with **no** soft-warning/ignore mode enabled |
| Fail codes | `gate_fa_stock_unarmed` · `gate_fa_stock_soft_mode` |
| Note | Post-apply failures are OD-VERIFY-WARN / WP-P1-11; pre-PONR forbids disabled predicates |

### G-FA-SCHEMA — Schema strict (§ OD-FA-SCHEMA + OD-SCHEMA)

| Predicate | Live schema revision matches certified expectations; required tables/columns/indexes/constraints checks enabled; **fixture soft-skip = false** in Production/Certification; any mismatch FAIL |
| Fail codes | `gate_fa_schema_mismatch` · `gate_fa_schema_soft_skip` · `gate_schema_cert_invalidated` |
| Note | If live revision changed since cert → OD-SCHEMA invalidation → FAIL until new cert cycle + Owner PASS + Enable |

**Aggregate:** `pre_ponr_full` requires G-FA-RESOLVER, G-FA-STOCK, G-FA-SCHEMA all `PASS` in addition to G01–G30.

---

## 7. Explicit forbidden evaluator behaviors

| Behavior | Status |
|----------|--------|
| Accept C8 `WARNING` with waiver | **Forbidden** |
| Skip any gate | **Forbidden** |
| Super Admin force-PASS | **Forbidden** |
| Best-effort ownership | **Forbidden** |
| Replace OD-INV with live inventory | **Forbidden** |
| Reuse existing Full Backup as pin | **Forbidden** |
| Proceed with concurrent Full DR or C6 | **Forbidden** |
| Country-only maint to satisfy G22 | **Forbidden** |
| Treat enablement false as PASS for PONR | **Forbidden** |
| Soft-skip schema fixture PASS | **Forbidden** |

---

## 8. Evaluation algorithm (normative)

```
EVALUATE_PRE_PONR_GATES(job):
  results = []
  for gate in [G01..G30, G-FA-RESOLVER, G-FA-STOCK, G-FA-SCHEMA]:
      try:
          r = evaluate(gate)   # PASS or FAIL only
      catch:
          r = FAIL(gate_eval_error)
      results.append(r)
  all_pass = every result is PASS
  write cpr_gate_evaluation.json (atomic rename)
  if not all_pass:
      abort pre-PONR; do not write CP-A; do not enter cpr_deleting
  else:
      allow CP-A / PONR path only if WP-P1-03/04/06 also satisfied
  return all_pass
```

---

## 9. Gate evaluation result schema (`cpr_gate_evaluation`)

Path (design):  
`{workRoot}/country_production/{job_id}/gates/cpr_gate_evaluation_{profile}_{iso}.json`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_gate_evaluation/1"` |
| `job_id` | Y | |
| `profile` | Y | `package_chain` \| `pre_ponr_full` |
| `evaluated_at` | Y | |
| `all_gates_pass` | Y | Boolean |
| `gates` | Y | Array of `{gate_id, result, fail_code?, evidence_refs[]}` |
| `c8_overall_result_observed` | Y | Must be `SAFE` for full PASS |
| `inventory_snapshot_id` | Y | |
| `session_full_backup_pinned` | Y | For `pre_ponr_full` |
| `full_dr_active` | Y | Must be false |
| `c6_active` | Y | Must be false |
| `waiver_attempted` | Y | Must be false |
| `evaluator_version` | Y | |

CP0 may be written only when `package_chain` (or fuller) subset required by WP-P1-04 is PASS; **PONR** requires latest `pre_ponr_full` with `all_gates_pass = true`.

---

## 10. Binding to prior WPs

| WP | Binding |
|----|---------|
| WP-P1-02 | Fingerprints / drift / OTA / enablement observed |
| WP-P1-04 | CP0 fields; CP1/CP4 order; runbook; CP5; CP-A blocked without gates |
| WP-P1-05 | G05 / G26 lock predicates |
| WP-P1-06 | G24 / G27 / G29 authority & phrase |
| WP-P1-07 | G22 maint GLOBAL + write-block |

No edits to those artifacts in this WP.

---

## 11. Register / Architecture citation map

| Gate group | OD / Principle | Architecture |
|------------|----------------|--------------|
| C8 SAFE / no WARNING | OD-C8 | §35, §37.16–18 |
| Inventory certified | OD-INV | §37.21 |
| Pin sequence | OD-PIN | §37.23, §6 |
| Schema revision / cert cycle | OD-SCHEMA · OD-FA-SCHEMA | §36, §37.19 |
| Resolver / stock FA | OD-FA-RESOLVER · OD-FA-STOCK | §37.15; FA |
| Locks | OD-LOCK-CROSS · OD-LOCK-SHADOW | §37.5 |
| Maint GLOBAL | OD-MAINT · OD-MAINT-SCOPE | §37.22 |
| Fail-closed / no bypass | Integrity Principle | §37 all |
| Full PASS list | (baseline) | **§37.1–30** |

---

## 12. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| C8 ≠ SAFE blocks start | **PASS** — G16; H3 |
| No WARNING waiver | **PASS** — H3–H4; §7 |
| Live DB reads cannot replace OD-INV snapshot | **PASS** — G21; H5 |
| PONR forbidden unless all gates PASS | **PASS** — H10; §3; §8 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| Full §37 PASS list machine-checkable | **PASS** — G01–G30 |
| C4/C5/C6/C7/C8 + OD-PIN + OD-FA-* + OD-SCHEMA + preflight + no Full DR/C6 | **PASS** — §5–§6 |
| Fail-closed; no skip; no bypass | **PASS** — §2–§3, §7 |
| Architecture / Register / prior WPs unmodified | **PASS** |

---

## 13. Assumptions

1. Exact C4–C8 JSON field names follow immutable CRP report schemas; predicates use Architecture §37 names.  
2. `package_chain` profile aids CP0; only `pre_ponr_full` authorizes PONR.  
3. Post-apply verify gates are WP-P1-11 (OD-VERIFY-WARN); this WP is pre-PONR only.  
4. Enablement flip ceremony is WP-P1-13; G01 enforces fail-closed until enabled.

---

## 14. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| WARNING cutover reintroduced | Critical | G16; §7 |
| Soft-skip schema | Critical | G-FA-SCHEMA |
| Partial gate suite for PONR | Critical | `pre_ponr_full` only |
| Live inventory replace | Critical | G21 |
| Concurrent Full DR/C6 | Critical | G05 |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 15. Out of scope

- PHP gate evaluator implementation  
- WP-P1-09 failure/resume/rollback  
- WP-P1-11 post-apply verify  
- Modifying C3–C8 report engines  

---

*End of WP-P1-08. STOP — do not begin WP-P1-09 until Owner review and approval.*
