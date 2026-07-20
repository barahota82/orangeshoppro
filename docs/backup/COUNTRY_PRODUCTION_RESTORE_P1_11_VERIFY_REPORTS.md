# Country Production Restore — P1 Post-Apply Verification & Report Schemas

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-11** — Post-Apply Verification & Report Schemas |
| **Artifact-ID** | `CPR-P1-WP11-VERIFY_REPORTS` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-VERIFY-WARN · OD-FA-RESOLVER · OD-FA-STOCK · OD-FA-SCHEMA; Integrity Principle) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §19 (also §18 CP10–CP11) |
| **Depends on** | WP-P1-02 · WP-P1-04 · WP-P1-08 · WP-P1-09 · WP-P1-10 |
| **Coding** | **No** — design contracts and report schemas only |

---

## 1. Purpose

Specify the **fail-closed post-apply verification suite**, **pillar predicates** (including OD-FA-*), **survivor/Global witnesses**, and **sealed report schemas** bound to execution-contract fingerprints so integrity failure can never produce Success or “success with warnings.”

This WP does **not** modify Architecture, Owner Decisions, C3–C8 engines, or prior P1 artifacts.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Post-apply verification is **fail-closed** | OD-VERIFY-WARN |
| H2 | Any integrity failure in accounting, ownership, FIFO, stock, schema, survivor, or Global → session **FAILED** immediately | OD-VERIFY-WARN |
| H3 | GLOBAL Maint stays **ON**; storefronts/Country Admin unavailable; Super Admin Restore Management only | OD-VERIFY-WARN |
| H4 | After verify failure: only **Resume** (when safely supported) or **Rollback** | OD-VERIFY-WARN · WP-P1-09 |
| H5 | **Forbidden:** Success with warnings; Ignore verification; Accept anyway; override; integrity waiver | OD-VERIFY-WARN · Integrity |
| H6 | CP10 may be written only when suite overall = `PASS` and `integrity_waiver = false` | WP-P1-04 |
| H7 | Ownership verification uses certified Ownership Resolver Matrix only — no country_id shortcuts | OD-FA-RESOLVER |
| H8 | Stock/FIFO verification mandatory; no soft warning / ignore / best-effort | OD-FA-STOCK |
| H9 | Schema verification mandatory (revision + tables/columns/indexes/constraints); no fixture soft-skip on prod/cert | OD-FA-SCHEMA |
| H10 | Every sealed report binds contract fingerprints, package fingerprint, session Full Backup, inventory snapshot | Architecture §19.12 · WP-P1-02 |

---

## 3. When verification runs

| Moment | State | Required |
|--------|-------|----------|
| Post-apply suite | `cpr_post_verifying` after CP6–CP9 as architecture order requires | Full suite V01–V12 + FA pillars |
| After Resume into verify | Same | Full re-run (idempotent reads preferred) |
| Before CP10 / success | Suite must be `PASS` | Else pause — no CP10/CP11 |

**Pre-PONR gates** remain WP-P1-08; this WP is **post-apply only**.

---

## 4. Verification suite — Architecture §19 → machine checks

Each check returns `PASS` or `FAIL` only. `WARN` / `SKIP` / `WAIVE` are **forbidden** result values.

| ID | §19 # | Check | PASS predicate | Primary OD / Principle |
|----|-------|-------|----------------|------------------------|
| V01 | 1 | Target row counts | Membership-scoped counts match package inventory expectations within contract binding | Integrity · OD-INV (inventory bind) |
| V02 | 2 | Survivor baseline | Survivor count/hash **unchanged** vs CP5 witnesses | Isolation · OD-VERIFY-WARN |
| V03 | 3 | Global / never-export baseline | Global baseline unchanged incl. `journal_entries` vs CP5 | Isolation · OD-VERIFY-WARN |
| V04 | 4 | NULL ownership leakage | Leakage count = **0** on scoped tables | OD-FA-RESOLVER · Integrity |
| V05 | 5 | Composite units A–H | Admins, GL, FIFO, documents, commercial, catalog, expenses, sequences all **PASS** | Integrity |
| V06 | 6 | Accounting | Voucher balance OK; **no JE mutation** vs Global baseline | OD-VERIFY-WARN |
| V07 | 7 | Stock/FIFO | Warehouse ownership, stock ownership, FIFO integrity, **no** cross-country refs | **OD-FA-STOCK** |
| V08 | 8 | Sequences | No foreign scope touch; counters **not lowered** | Integrity · C1.1 |
| V09 | 9 | Uploads | Allowlist + path safety; no survivor path modify (WP-P1-10) | OD-UPLOADS |
| V10 | 10 | Schema | Revision + required tables/columns/indexes/constraints match expectations; soft-skip false | **OD-FA-SCHEMA** · OD-SCHEMA |
| V11 | 11 | Batch order | Import batch order integrity preserved | Architecture §19 |
| V12 | 12 | Production identity | Live production DB identity hash matches contract | WP-P1-02 |

### 4.1 OD-FA-RESOLVER post-apply verification (ownership)

In addition to V04/V05 ownership aspects, explicit FA resolver block:

| Field | Rule |
|-------|------|
| `resolver_matrix_id` / version | Must match certified matrix used at apply |
| `country_id_shortcut_used` | Must be `false` |
| `unproven_membership_count` | Must be `0` |
| `guessed_ownership_count` | Must be `0` |
| Result | Any violation → **FAIL** (session FAILED) |

### 4.2 OD-FA-STOCK post-apply verification

| Predicate | Must |
|-----------|------|
| Warehouse ownership | PASS |
| Stock ownership | PASS |
| FIFO integrity / graph completeness | PASS |
| Cross-country stock references | **0** / PASS |
| Soft warning mode | **Disabled** |
| Ignore mode | **Disabled** |

Any failure → immediate session FAILED (OD-FA-STOCK / OD-VERIFY-WARN).

### 4.3 OD-FA-SCHEMA post-apply verification

| Predicate | Must |
|-----------|------|
| Schema revision matches contract `schema_revision_expected` | PASS |
| Required tables present | PASS |
| Required columns present | PASS |
| Required indexes present | PASS |
| Required constraints present | PASS |
| Fixture soft-skip | **false** (prod/cert) |

Any mismatch → immediate session FAILED.

### 4.4 Survivor-country verification (detail)

| Evidence | Rule |
|----------|------|
| CP5 survivor witness hash | Recomputed live hash **equals** CP5 |
| Survivor row counts (scoped baselines) | Unchanged |
| Survivor uploads paths | Untouched (WP-P1-10) |
| Drift | Any drift → V02 **FAIL** — never Success |

### 4.5 Global consistency verification (detail)

| Evidence | Rule |
|----------|------|
| CP5 global / never-export witness hash | Recomputed equals CP5 |
| `journal_entries` / JE impact | No mutation vs baseline |
| Global tables outside target slice | Unchanged |
| Drift | Any drift → V03/V06 **FAIL** |

---

## 5. Suite evaluation algorithm (fail-closed)

```
EVALUATE_POST_APPLY_VERIFY(job):
  bind = load contract fingerprints + package_fp + session_full_backup_* + inventory_snapshot_*
  results = []
  for check in [V01..V12, FA_RESOLVER, FA_STOCK, FA_SCHEMA]:
      r = run(check)   # PASS or FAIL only
      results.append(r)
  overall = PASS iff all PASS
  if overall == PASS:
      forbid integrity_waiver=true
      write verify report (sealed pending CP11)
      allow CP10
  else:
      mark session FAILED (verify)
      keep GLOBAL Maint ON
      write failure report + cpr_failure_event (WP-P1-09 verify class)
      transition cpr_paused_verify_failed (T33)
      DO NOT write CP10/CP11
      DO NOT set success
      Super Admin: Resume (idempotent re-verify if safe) OR Rollback only
  return overall
```

### 5.1 Forbidden evaluator outcomes

| Outcome | Status |
|---------|--------|
| `SUCCESS_WITH_WARNINGS` | **Forbidden** |
| Overall PASS with any pillar FAIL | **Forbidden** |
| `integrity_waiver = true` | **Forbidden** |
| Ignore / accept anyway / override | **Forbidden** |
| Soft-skip schema fixture PASS | **Forbidden** |
| Auto-rollback on verify FAIL | **Forbidden** (WP-P1-09 — explicit SA Rollback only) |

---

## 6. Report binding envelope (all reports)

Every verification / success / failure report **must** include:

| Field | Required | Binding |
|-------|:--------:|---------|
| `schema_version` | Y | Report-specific |
| `job_id` | Y | WP-P1-02 |
| `country_id` | Y | Contract |
| `package_id` | Y | Contract |
| `package_fingerprint` | Y | Contract / package |
| `contract_revision` | Y | WP-P1-02 |
| `contract_fingerprint_digest` | Y | Digest of frozen fingerprint set |
| `c4_report_hash` … `c8_report_hash` | Y | Contract |
| `inventory_snapshot_id` | Y | OD-INV |
| `inventory_snapshot_hash` | Y | OD-INV |
| `session_full_backup_id` | Y | OD-PIN |
| `session_full_backup_fingerprint` | Y | OD-PIN |
| `session_full_backup_pinned` | Y | Must be `true` |
| `production_db_identity_hash` | Y | Contract |
| `cp5_survivor_witness_hash` | Y | Pre-PONR witness |
| `cp5_global_witness_hash` | Y | Pre-PONR witness |
| `evaluated_at` | Y | ISO-8601 |
| `sealed` | Y | Boolean |
| `report_sha256` | Y | Hash of canonical report body after seal |

**Drift rule:** If live re-bind of any binding field disagrees with contract at verify time → suite **FAIL** (`verify_binding_drift`) before pillar success can be claimed.

---

## 7. Report schemas

Directory (design):

```text
{workRoot}/country_production/{job_id}/reports/
  cpr_post_verify_report.json           # suite result (PASS or FAIL)
  cpr_post_verify_pillars.json          # per-check detail
  cpr_fa_resolver_report.json
  cpr_fa_stock_report.json
  cpr_fa_schema_report.json
  cpr_survivor_global_witness_report.json
  cpr_success_report.json               # only after CP11 path
  cpr_verify_failure_report.json        # on FAIL
```

Atomic write/rename; after `sealed=true`, files are **immutable** (no rewrite; corrections require new report id + audit).

### 7.1 Suite report (`cpr_post_verify_report`)

| Field | Required | Notes |
|-------|:--------:|-------|
| Binding envelope | Y | §6 |
| `overall_result` | Y | `PASS` \| `FAIL` only |
| `integrity_waiver` | Y | **Must be `false`** |
| `success_with_warnings` | Y | **Must be `false`** |
| `checks` | Y | Array `{check_id, result, fail_code?}` for V01–V12 + FA_* |
| `failed_check_ids` | Y | Empty iff PASS |
| `maint_global_on` | Y | Must be `true` at evaluate time |
| `cp10_eligible` | Y | `true` only if overall PASS |
| `next_actions_allowed` | Y | On FAIL: `["resume","rollback"]` only; on PASS: continue finalize |

### 7.2 Pillars detail (`cpr_post_verify_pillars`)

| Field | Required | Notes |
|-------|:--------:|-------|
| Binding envelope | Y | §6 |
| `composites` | Y | A–H each `PASS`/`FAIL` |
| `accounting` | Y | balance OK; `je_mutated=false` |
| `null_ownership_leakage` | Y | Integer; PASS iff 0 |
| `batch_order_ok` | Y | Boolean |
| `uploads_path_safety_ok` | Y | Boolean |

### 7.3 FA resolver report (`cpr_fa_resolver_report`)

| Field | Required | Notes |
|-------|:--------:|-------|
| Binding envelope | Y | |
| `matrix_version` | Y | |
| `country_id_shortcut_used` | Y | Must be false for PASS |
| `unproven_membership_count` | Y | Must be 0 for PASS |
| `result` | Y | `PASS` \| `FAIL` |

### 7.4 FA stock report (`cpr_fa_stock_report`)

| Field | Required | Notes |
|-------|:--------:|-------|
| Binding envelope | Y | |
| `warehouse_ownership` | Y | PASS/FAIL |
| `stock_ownership` | Y | PASS/FAIL |
| `fifo_integrity` | Y | PASS/FAIL |
| `cross_country_refs` | Y | PASS/FAIL (PASS ⇒ none) |
| `soft_warning_mode` | Y | Must be false |
| `result` | Y | PASS/FAIL |

### 7.5 FA schema report (`cpr_fa_schema_report`)

| Field | Required | Notes |
|-------|:--------:|-------|
| Binding envelope | Y | |
| `schema_revision_expected` | Y | |
| `schema_revision_observed` | Y | |
| `tables_ok` / `columns_ok` / `indexes_ok` / `constraints_ok` | Y | Booleans |
| `fixture_soft_skip` | Y | Must be false |
| `result` | Y | PASS/FAIL |

### 7.6 Survivor / Global witness report (`cpr_survivor_global_witness_report`)

| Field | Required | Notes |
|-------|:--------:|-------|
| Binding envelope | Y | |
| `survivor_hash_expected` | Y | From CP5 |
| `survivor_hash_observed` | Y | |
| `survivor_match` | Y | Boolean |
| `global_hash_expected` | Y | From CP5 |
| `global_hash_observed` | Y | |
| `global_match` | Y | Boolean |
| `journal_entries_unchanged` | Y | Boolean |
| `result` | Y | PASS iff all matches |

### 7.7 Verify failure report (`cpr_verify_failure_report`)

| Field | Required | Notes |
|-------|:--------:|-------|
| Binding envelope | Y | |
| `overall_result` | Y | `FAIL` |
| `failed_check_ids` | Y | Non-empty |
| `session_marked_failed` | Y | `true` |
| `maint_remains_on` | Y | `true` |
| `allowed_actions` | Y | `["resume","rollback"]` |
| `forbidden_actions` | Y | Includes success, waiver, maint_release, country_admin_* |
| `failure_event_id` | Y | WP-P1-09 link |
| `pause_state` | Y | `cpr_paused_verify_failed` |

### 7.8 Success report (`cpr_success_report`) — only after suite PASS + finalize

| Field | Required | Notes |
|-------|:--------:|-------|
| Binding envelope | Y | |
| `overall_result` | Y | `PASS` |
| `verify_report_sha256` | Y | Links §7.1 |
| `integrity_waiver` | Y | `false` |
| `success_with_warnings` | Y | `false` |
| `cp10_ref` / `cp11_ref` | Y | Checkpoint ids |
| `reports_sealed` | Y | `true` |

**Reject** success report if any verify check was FAIL or waiver true.

---

## 8. Seal & immutability rules

1. On suite completion, compute `report_sha256` over canonical JSON; set `sealed=true`.  
2. Sealed reports must not be edited in place.  
3. CP11 `reports_sealed=true` lists report ids/hashes (WP-P1-04).  
4. Re-verify after Resume produces a **new** report set with new timestamps/ids; prior FAIL reports retained for audit.  
5. Secrets forbidden in reports (no passwords, tokens, absolute private paths beyond redacted forms).

---

## 9. Binding to states & checkpoints

| Event | WP-P1-03 / CP |
|-------|----------------|
| Suite PASS | Allow CP10; then success finalize → CP11 |
| Suite FAIL | T33 → `cpr_paused_verify_failed`; no CP10/CP11 |
| Resume | T43 → `cpr_post_verifying` if safe; re-run suite |
| Rollback | T53 → OD-PIN session Full Backup (WP-P1-09) |
| Maint | Stays ON through FAIL pause until SA release after allowed terminal + Runbook |

---

## 10. Register / Architecture citation map

| Contract element | OD / Principle | Frozen wording locus | Architecture |
|------------------|----------------|----------------------|--------------|
| Fail-closed; no success-with-warnings; Maint ON; Resume/Rollback only | OD-VERIFY-WARN | §15 Frozen | §19 |
| Resolver matrix / no shortcuts | OD-FA-RESOLVER | §15 Frozen | §19.4–5 |
| Stock/FIFO mandatory | OD-FA-STOCK | §15 Frozen | §19.7 |
| Schema strict / no soft-skip | OD-FA-SCHEMA | §15 Frozen | §19.10 |
| Survivor/Global witnesses | Isolation · OD-VERIFY-WARN | — | §19.2–3 |
| Report bind fingerprints / pin / inventory | (baseline) · OD-INV · OD-PIN | — | §19.12; WP-P1-02 |
| CP10/CP11 | (baseline) | — | §18 |

---

## 11. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Any listed integrity failure → not success | **PASS** — H1–H2; §5 |
| Only Resume/Rollback paths after failure | **PASS** — H4; §5; §7.7 |
| Reports bind to contract fingerprints | **PASS** — H10; §6 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| Post-apply verification contracts (V01–V12 + FA) | **PASS** — §4 |
| Report schemas defined | **PASS** — §7 |
| OD-VERIFY-WARN / FA-RESOLVER / FA-STOCK / FA-SCHEMA / survivor / Global | **PASS** — §4 |
| Success with warnings forbidden; Maint ON on fail | **PASS** — H3–H5 |
| Design only / no code | **PASS** |
| Architecture / Register / prior WPs unmodified | **PASS** |

---

## 12. Assumptions

1. Exact SQL/predicates for composites A–H follow C1.1 / CRP verify semantics; this WP defines pass/fail contracts and reports.  
2. Alerting/metrics transport is WP-P1-12; this WP defines verify outcomes those events may observe.  
3. Uploads path safety details remain WP-P1-10; V09 consumes their success/fail signals.

---

## 13. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| “Success with warnings” UI path | Critical | H5; schema consts false |
| Soft-skip schema on prod | Critical | H9; §7.5 |
| CP10 written on partial FAIL | Critical | §5; WP-P1-04 reject waiver |
| Unbound reports (no pin/inventory) | High | §6 required envelope |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 14. Out of scope

- PHP verification engine implementation  
- WP-P1-12 audit/metrics/alert collectors  
- Modifying C7/C8 report engines  

---

*End of WP-P1-11. STOP — do not begin WP-P1-12 until Owner review and approval.*
