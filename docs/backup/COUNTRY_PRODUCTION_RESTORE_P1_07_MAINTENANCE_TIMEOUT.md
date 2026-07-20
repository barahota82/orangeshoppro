# Country Production Restore — P1 GLOBAL Maintenance & Duration Monitoring Contracts

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-07** — GLOBAL Maintenance & Duration Monitoring Contracts |
| **Artifact-ID** | `CPR-P1-WP07-MAINTENANCE_TIMEOUT` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-MAINT · OD-MAINT-SCOPE · OD-MAINT-MAX · OD-RTO · OD-TIMEOUT; Maintenance State) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §9, §29 |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` (register wins on conflict) |
| **Depends on** | WP-P1-03 (states) · WP-P1-06 (maint release / Runbook gates) |
| **Coding** | **No** mutation engine / maint PHP in this WP |

---

## 1. Purpose

Specify the **GLOBAL Maintenance lifecycle**, **write-block proof**, **automatic Estimated Duration** model, **progress-aware timeout escalation**, and **failure/Maintenance State** rules so implementation cannot introduce country-only maint, wall-clock auto-fail, or automatic rollback on timeout.

This WP does **not** modify Architecture, Owner Decisions, or prior P1 artifacts.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Maintenance Mode is **mandatory** before any production DELETE / IMPORT / uploads apply; write-block must be **proven** | OD-MAINT |
| H2 | Maintenance scope is **GLOBAL (platform-wide) only** | OD-MAINT-SCOPE |
| H3 | **Country-only maintenance is forbidden** under current architecture | OD-MAINT-SCOPE |
| H4 | **No fixed maximum** maintenance duration; every job gets an **automatic Expected/Estimated Duration** | OD-MAINT-MAX |
| H5 | **No hardcoded RTO**; Estimated Duration is **operational monitoring only** | OD-RTO |
| H6 | Timeout does **NOT** automatically mean failure; exceeding estimate alone must **never** fail the job | OD-TIMEOUT |
| H7 | Timeout must **never** cause **automatic Rollback** | OD-TIMEOUT · OD-ROLLBACK · OD-FAIL-* |
| H8 | On failure pause: Maintenance stays **ON** until Super Admin completes Resume or Rollback path (then release per gates) | Maintenance State |
| H9 | Maintenance remains active until **Super Admin** explicitly releases it | OD-PERM · Global Restore Operational Policy |
| H10 | Release only after **successful Runbook completion** (+ authorized terminal/closeout per WP-P1-03/06) | OD-RUNBOOK · WP-P1-06 |
| H11 | Users must **never** regain normal access while restore remains incomplete | Maintenance State · Global Restore Operational Policy §7 |

---

## 3. Engineering defaults vs OWNER_APPROVED policy

| Topic | Classification | Binding value |
|-------|----------------|---------------|
| Maint mandatory + write-block proven before mutation | **OWNER_APPROVED** | OD-MAINT |
| GLOBAL only; country-only forbidden | **OWNER_APPROVED** | OD-MAINT-SCOPE |
| No fixed max duration; auto estimate per job | **OWNER_APPROVED** | OD-MAINT-MAX |
| No hardcoded RTO; estimate = monitoring only | **OWNER_APPROVED** | OD-RTO |
| Progress-aware ladder; timeout ≠ failure | **OWNER_APPROVED** | OD-TIMEOUT |
| Maint stays ON on failure pause | **OWNER_APPROVED** | Maintenance State |
| Maint release Super Admin + Runbook gate | **OWNER_APPROVED** | OD-PERM · OD-RUNBOOK |
| Platform UX while Maint ON (all storefronts/CA down; SA Restore Management only) | **Ops clarification** aligned to ODs | Global Restore Operational Policy |
| Dashboard-first SA operations | **Ops clarification** | Super Admin Operational Model |
| Warning threshold = **1.0 ×** Estimated Duration elapsed | **Engineering default** | Alert only — not fail |
| Critical threshold = **1.5 ×** Estimated Duration elapsed | **Engineering default** | Escalate investigation — not fail |
| Progress-stall window for “lack of progress” (e.g. no batch/row/heartbeat advance for N intervals) | **Engineering default** | Used only with escalation for Recovery Investigation — never auto-rollback |
| Heartbeat interval ≤ 30s | **Engineering default** | WP-P1-05 / Architecture §15 |

**Rule:** Coding may tune engineering defaults without reopening ODs. Coding must **never** treat thresholds as hard fail/rollback deadlines.

---

## 4. OD-MAINT / OD-MAINT-SCOPE — GLOBAL Maintenance lifecycle

### 4.1 Scope (normative)

| Property | Value |
|----------|-------|
| `maint_scope` | Must equal `"GLOBAL"` for every CPR job |
| Country-scoped / per-country writer unblock | **Forbidden** (`cpr_maint_country_only_forbidden`) |
| Partial storefront reopen for non-target countries | **Forbidden** while CPR Maint ON |
| Future country-isolated model | Requires **new** Owner Decision + architecture change (OD-MAINT-SCOPE) — out of scope |

### 4.2 Lifecycle states (`cpr_maint_lifecycle`)

| State | Meaning |
|-------|---------|
| `maint_off` | Platform not in CPR Global Maintenance for this job |
| `maint_entering` | Super Admin / system starting GLOBAL Maint |
| `maint_on_unproven` | Maint flag ON but write-block **not** yet proven |
| `maint_on_proven` | Write-block proven — OD-PIN / mutation may proceed per gates |
| `maint_on_executing` | Pre-PONR pin or post-PONR mutation / pause / rollback in progress |
| `maint_on_paused_failure` | OD-FAIL-* / verify fail pause — Maintenance State |
| `maint_release_authorized` | Super Admin issued release auth (WP-P1-06 §8.4) after Runbook complete |
| `maint_released` | Writers restored; aligns WP-P1-03 `cpr_maintenance_released` |

### 4.3 Lifecycle sequence (happy path)

```
maint_off
  → maint_entering                    # Super Admin / WF start (SA Operational Model auto-sequence)
  → maint_on_unproven
  → maint_on_proven                   # write-block proof recorded (CP4)
  → maint_on_executing                # OD-PIN then PONR…post-verify
  → (success or rollback completed terminal)
  → maint_release_authorized          # Super Admin only; Runbook gate
  → maint_released
```

OD-PIN order (binding, not redesigned here): **Maint ON → new Full Backup → verify → pin** (Architecture §6 / WP-P1-04).

### 4.4 What GLOBAL Maint blocks / allows

Aligned with Architecture §9 and Global Restore Operational Policy:

| Class | Behavior while Maint ON |
|-------|-------------------------|
| All country storefronts | Maintenance page; no orders/payments/account writes |
| All Country Admin dashboards | Unavailable; no production mutation |
| Payments apply, stock/GL posting, cron mutators | Suspended |
| Backup create / country export | Blocked while CPR lock/maint held (Architecture §16) |
| Queues, integrations, webhooks (write-capable) | Suspended until release |
| Health endpoints | May continue |
| Super Admin Restore Management | **Only** operational user interface (Global Policy §6; SA Model) |

### 4.5 Write-block proof (OD-MAINT)

Before any production DELETE / IMPORT / uploads apply:

| Field | Rule |
|-------|------|
| `global_maintenance_on` | `true` |
| `maint_scope` | `"GLOBAL"` |
| `write_block_proof` | Non-empty evidence id/description of at least one proven blocked write path (Architecture §9) |
| `proven_at` | ISO-8601 |
| `proven_by` | `system` or Super Admin id |

**Forbidden:** Proceed to OD-PIN completion / PONR with `maint_on_unproven`.  
**Forbidden:** Accept `maint_scope = "COUNTRY"` or any equivalent.

### 4.6 Maintenance State (failure pause) — OWNER_APPROVED

When CPR enters a post-PONR (or applicable) **pause** because of failure:

1. Lifecycle → `maint_on_paused_failure`.  
2. GLOBAL Maint **remains ON**.  
3. Customers / Country Admins / writers stay blocked.  
4. Super Admin dashboard remains available (Resume / Rollback / logs — SA Model §4–§5).  
5. **No** automatic Maint release.  
6. **No** automatic Rollback solely due to pause duration.  
7. Exit pause only via Super Admin **Resume** (if stage safely supports) or **Rollback** (WP-P1-09 detail); then Maint release only per §8.

---

## 5. Estimated Duration model (OD-MAINT-MAX / OD-RTO)

### 5.1 Policy

- **No** fixed maximum maintenance duration.  
- **No** hardcoded RTO.  
- **No** mandatory manual duration configuration.  
- System **must** automatically compute `estimated_duration_seconds` per job for **monitoring only**.

### 5.2 Workload inputs (OD-MAINT-MAX — where applicable)

| Input | Field (design) | Notes |
|-------|----------------|-------|
| Package size | `package_bytes` | |
| SQL size | `sql_bytes` | |
| Upload size | `uploads_bytes` | Target-scoped estimate |
| Row count | `row_count_estimate` | |
| Batch count | `batch_count_estimate` | |
| Historical stats | `historical_seconds_p50` / `p90` | Prior similar jobs if available |
| Infrastructure performance | `infra_factor` | Relative throughput factor (engineering) |

### 5.3 Calculation contract (`cpr_duration_estimate`)

| Field | Required | Rule |
|-------|:--------:|------|
| `job_id` | Y | |
| `schema_version` | Y | `"cpr_duration_estimate/1"` |
| `estimated_duration_seconds` | Y | Integer > 0 |
| `estimate_computed_at` | Y | ISO-8601 |
| `inputs` | Y | Object of §5.2 fields present |
| `formula_id` | Y | Engineering identifier of estimator version |
| `monitoring_only` | Y | Must be `true` |
| `hard_fail_deadline` | Y | Must be `false` or absent — **never** `true` |
| `rto_hardcoded` | Y | Must be `false` |

**Normative effects of the estimate:**

| May do | Must not do |
|--------|-------------|
| Drive UI “Estimated duration” | Fail job when elapsed > estimate |
| Drive Warning / Critical **alerts** | Auto-Rollback |
| Inform Recovery Investigation | Auto-release Maint |
| Recalculate on major phase change (optional) | Act as Owner RTO SLA enforcement |

### 5.4 Survivor / RPO note (not a duration rule)

OD-RTO does **not** redefine survivor/Global integrity. Architectural RPO for non-target remains **0** (no survivor data loss tolerance). Duration estimate must not be used to justify partial survivor writes.

---

## 6. Progress-aware monitoring (OD-TIMEOUT)

### 6.1 Escalation ladder (OWNER_APPROVED order)

```
Estimated Duration
  → Warning Threshold
  → Critical Threshold
  → Recovery Investigation
  → Resume (when supported)
```

### 6.2 Progress signals (measurable)

Any of the following counts as **measurable progress** (non-exhaustive):

| Signal | Examples |
|--------|----------|
| Heartbeat | Lock / worker `heartbeat_at` advancing (WP-P1-05) |
| Batches | `completed_batches` increasing |
| Rows | `imported_rows` / `deleted_rows` increasing |
| Phase advance | WP-P1-03 state forward progress |
| Checkpoint commit | New CP6–CP11 (or pin/runbook pre-PONR) committed |
| Backup progress | Session Full Backup create/verify/pin percent |

**Lack of progress:** no listed signal advances within the engineering stall window **and** escalation is at Critical or Recovery Investigation.

### 6.3 Timeout signal schema (`cpr_timeout_signal`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `job_id` | Y | |
| `schema_version` | Y | `"cpr_timeout_signal/1"` |
| `observed_at` | Y | |
| `elapsed_seconds` | Y | Since maint_on or job execute start (define in payload) |
| `estimated_duration_seconds` | Y | From §5 |
| `ladder_level` | Y | `within_estimate` \| `warning` \| `critical` \| `recovery_investigation` |
| `measurable_progress` | Y | Boolean |
| `progress_evidence` | Y | Which signals advanced |
| `auto_fail` | Y | **Must be `false`** always for wall-clock-only |
| `auto_rollback` | Y | **Must be `false`** always from timeout path |
| `recommended_action` | Y | `continue` \| `alert` \| `investigate` \| `await_super_admin_resume` |
| `maint_lifecycle` | Y | Current §4.2 state |

### 6.4 Escalation rules

| Condition | Ladder level | System behavior |
|-----------|--------------|-----------------|
| `elapsed < estimate` | `within_estimate` | Continue; optional info metrics |
| `elapsed ≥ estimate` AND progress | `warning` (eng. default at 1.0×) | **Alert**; **continue**; no fail |
| `elapsed ≥ critical×estimate` AND progress | `critical` | **Page/alert escalate**; **continue**; no fail |
| Escalation + **lack of progress** | `recovery_investigation` | Open investigation; Super Admin may Resume when supported; **still no auto-fail from clock alone**; **no auto-rollback** |
| Stage failure (delete/import/uploads/verify) | N/A (OD-FAIL-*) | Enter pause — **not** a timeout transition |

### 6.5 Explicit timeout ≠ failure rules

| Forbidden automatic action | Status |
|----------------------------|--------|
| Mark job failed because `elapsed > estimate` | **Forbidden** |
| Transition to Rollback because of timeout | **Forbidden** |
| Release Maint because of timeout | **Forbidden** |
| Cancel post-PONR solely on wall-clock | **Forbidden** |
| Steal/clear post-PONR lock on stale timer | **Forbidden** (WP-P1-05) |

Pre-PONR soft cancel of **approvals waiting** (WP-P1-03 / Architecture §29) is a **separate** authority/idle policy — it is **not** “timeout = execution failure” and must **not** trigger Rollback (nothing to roll back pre-PONR).

---

## 7. Failure behavior vs timeout behavior

| Situation | Maint | Job outcome driver | Auto-Rollback? |
|-----------|-------|--------------------|----------------|
| Wall-clock past estimate, progress OK | ON | Continue executing | **No** |
| Wall-clock past estimate, no progress → investigation | ON | Super Admin decision / Resume when supported | **No** (only explicit SA Rollback) |
| Delete/import/uploads/verify **stage failure** | ON (`maint_on_paused_failure`) | OD-FAIL-* pause | **No** — SA Resume or Rollback |
| Success finalize | ON until release | Terminal success | N/A |
| Rollback completed | ON until release | Terminal rollback | N/A (already rolled back by SA) |
| Incomplete job | ON | Users stay blocked | N/A |

**Separation principle:** Timeout/escalation is a **monitoring plane**. Stage failure is a **failure plane** (WP-P1-09). They must not be conflated in code.

---

## 8. Maint release contract (consistency with WP-P1-06 / OD-RUNBOOK)

Release is allowed only when **all** hold:

1. Actor is **Super Admin** (OD-PERM).  
2. `runbook_completed === true` with valid `runbook_evidence_ref` (OD-RUNBOOK / WP-P1-06).  
3. Job is in an **allowed terminal/closeout** per WP-P1-03 (e.g. success, rollback completed, or authorized pre-PONR closeout when Maint was ON).  
4. Not in `maint_on_paused_failure` with incomplete Resume/Rollback.  
5. `cpr_maint_release_authorization` written (WP-P1-06 §8.4).  
6. Lifecycle transitions `maint_release_authorized` → `maint_released`.

**Never** release because Estimated Duration / Warning / Critical fired.

---

## 9. Maint state contract schema (`cpr_maint_state`)

Durable status file (design path):  
`{workRoot}/country_production/{job_id}/maint_state.json`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_maint_state/1"` |
| `job_id` | Y | |
| `maint_scope` | Y | Const `"GLOBAL"` |
| `lifecycle` | Y | §4.2 enum |
| `global_maintenance_on` | Y | Boolean |
| `write_block_proven` | Y | Boolean |
| `write_block_proof` | Y if proven | |
| `entered_at` | Y if ON | |
| `proven_at` | N | |
| `paused_failure` | Y | Boolean |
| `duration_estimate_ref` | Y once computed | Path/id of §5.3 |
| `latest_timeout_signal_ref` | N | §6.3 |
| `runbook_completed` | Y | Boolean |
| `release_authorization_ref` | N | Set at release |
| `released_at` | N | |
| `released_by_admin_id` | N | Super Admin |

Atomic write/rename: same discipline as WP-P1-04/05 (tmp → rename).

---

## 10. Binding to Global Restore Operational Policy & SA Model

| Clarification topic | This contract |
|---------------------|---------------|
| Entire platform Global Maint | §4.1–§4.4 |
| All storefronts / all Country Admins down | §4.4 |
| Writers/queues suspended | §4.4 |
| Super Admin Restore Management only | §4.4 |
| Return to service only after SA explicit release | §8 |
| No normal access while incomplete | H11; §4.6 |
| SA dashboard shows estimated duration / progress | §5–§6; SA Model live screen alignment |
| Auto Maint → backup → pin sequence | Lifecycle + OD-PIN order; SA Model §2 (UX) |

Clarifications do **not** reopen ODs; register wording remains SoT.

---

## 11. Register / Architecture citation map

| Contract element | OD / Principle | Frozen wording locus | Architecture / Policy |
|------------------|----------------|----------------------|------------------------|
| Maint mandatory + write-block | OD-MAINT | §15 Frozen | §9 |
| GLOBAL only; country-only forbidden | OD-MAINT-SCOPE | §15 Frozen | §9; Global Policy §2 |
| Auto estimate; no fixed max | OD-MAINT-MAX | §15 Frozen | §29 |
| No hardcoded RTO; monitoring only | OD-RTO | §15 Frozen | §29 |
| Progress-aware; timeout ≠ failure | OD-TIMEOUT | §15 Frozen | §29 |
| Maint ON on failure pause | Maintenance State | Register Group 3 | §9; Global Policy §7 |
| Release SA + Runbook | OD-PERM · OD-RUNBOOK | §15 Frozen | §9 runbook gate; WP-P1-06 |
| No auto-rollback on timeout | OD-ROLLBACK / OD-FAIL-* | §15 Frozen | §11–§13 |

---

## 12. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Country-only maint forbidden | **PASS** — H3; §4.1 |
| Timeout alone never fails job | **PASS** — H6; §6.5 |
| Maint stays ON until Super Admin release after allowed terminal + Runbook | **PASS** — H8–H10; §4.6; §8 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| OD-MAINT / SCOPE / MAX / RTO / TIMEOUT encoded | **PASS** — §4–§6 |
| Global Maint lifecycle defined | **PASS** — §4 |
| Estimated Duration calculation model | **PASS** — §5 |
| Progress-aware monitoring + escalation | **PASS** — §6 |
| Timeout never auto-rollback | **PASS** — H7; §6.5; §7 |
| Consistency with Global Policy + SA Model + Register | **PASS** — §10–§11 |
| Engineering defaults vs OWNER_APPROVED distinguished | **PASS** — §3 |
| Architecture / Register / prior WPs unmodified | **PASS** |

---

## 13. Assumptions

1. Exact estimator formula coefficients are engineering (`formula_id`); inputs set is OWNER_APPROVED-shaped (OD-MAINT-MAX).  
2. WP-P1-09 owns stage-failure Resume/Rollback algorithms; this WP owns Maint/timeout planes.  
3. WP-P1-12 owns alert channel wiring; this WP defines when signals fire.  
4. Pre-PONR approval soft-timeout remains in WP-P1-03 and is not reclassified as execution failure here.

---

## 14. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Country-only maint creeping back | Critical | H3; schema const `GLOBAL` |
| Wall-clock auto-fail | Critical | H6; `auto_fail` must be false |
| Timeout → auto-rollback | Critical | H7; §7 separation |
| Release Maint to unblock users mid-pause | Critical | H11; §8 |
| Treating eng. 1.5× as Owner RTO | Medium | §3 labeling |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 15. Out of scope

- PHP maintenance framework implementation  
- WP-P1-08 gate predicates  
- WP-P1-09 Resume/Rollback stage details  
- WP-P1-12 notification transport  

---

*End of WP-P1-07. STOP — do not begin WP-P1-08 until Owner review and approval.*
