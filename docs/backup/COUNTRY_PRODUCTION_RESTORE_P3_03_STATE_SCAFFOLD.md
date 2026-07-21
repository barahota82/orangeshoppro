# Country Production Restore — P3 State Engine & Transition Enforcement

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P3-03** — State Engine & Transition Enforcement |
| **Artifact-ID** | `CPR-P3-WP03-STATE_SCAFFOLD` |
| **Status** | COMPLETE (scaffolding) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P3-02; authorized WP-P3-03 |
| **Baselines** | `P0-P0b-Final` · `P1-Design-Baseline` · `P2-Design-Baseline` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` |
| **Primary P1** | `CPR-P1-WP03-STATE_TRANSITION_MATRIX` · `CPR-P1-WP09-FAIL_RESUME_ROLLBACK` |
| **Enablement** | **FALSE** (hard) |
| **PONR / DELETE / IMPORT** | **Not executed** (scaffold state record only) |

---

## 1. Purpose

Implement fail-closed **state transition enforcement** from the frozen WP-P1-03 matrix: legal edges only, deterministic error codes, bindings to job identity / execution contract / checkpoint / PONR / audit — without automatic rollback, without post-PONR auto-unlock, and without mutation engines.

---

## 2. Deliverables

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_state_catalog.php` | State meta + legal transition catalog (P1-03 CSV + T34/T60/T24E) |
| `includes/backup/country_production/cpr_state_engine.php` | Validate / apply / Resume & Rollback eligibility |
| `scripts/backup/country_production/self_test_cpr_state_engine.php` | Self-test suite |
| This document | Design + AC |
| P3 Artifact Index | WP-P3-03 → COMPLETE |

**T24E_***: early pre-PONR cancel edges (`pending` / `gates` / `approvals` / `contract_frozen` → `cancelled_pre_ponr`) aligned with Architecture §28 pre-PONR cancel and WP-P3-02 cancel — not post-PONR invention.

---

## 3. Behavior summary

| API | Behavior |
|-----|----------|
| `orange_cpr_transition_validate` | Fail-closed validation; no mutation |
| `orange_cpr_transition_apply` | Persists job state + `cpr.state_transition` audit; **scaffold_record_only** |
| `orange_cpr_resume_eligibility` | Super Admin + fail-pause + `safe_resume`; Country Admin denied |
| `orange_cpr_rollback_eligibility` | Super Admin + pause/incident; auto-rollback denied; Country Admin denied |
| `orange_cpr_refuse_post_ponr_auto_unlock` | Always fail (`cpr_post_ponr_auto_unlock_forbidden`) |
| `orange_cpr_refuse_auto_rollback` | Always fail (`cpr_auto_rollback_forbidden`) |

**T09 apply:** may set `ponr_crossed=true` on the job record for matrix scaffolding; `ponr_mutation_executed=false`; DELETE engine never invoked. Invoking engines via context flags → `cpr_mutation_engine_forbidden`.

**Maint release:** only Super Admin + `runbook_completed` (T14/T25/T26/T57/T62).

---

## 4. Deterministic error codes

| Code | Meaning |
|------|---------|
| `illegal_cpr_status_transition` | Edge not in P1-03 matrix |
| `cpr_actor_forbidden` | Actor not on transition |
| `cpr_country_admin_forbidden` | Country Admin Resume/Rollback/execute/maint |
| `cpr_enablement_blocks_transition` | Enablement must remain false in P3 |
| `cpr_contract_guard_failed` | Contract / pin / C8 guards |
| `cpr_identity_mismatch` | job/package/country/contract drift |
| `cpr_ponr_invariant_violation` | PONR flag vs edge class |
| `cpr_terminal_job` | Illegal revive from terminal |
| `cpr_workflow_guard_failed` | WF-A/B edge mismatch |
| `cpr_runbook_required` | Maint release / T09 runbook |
| `cpr_resume_not_eligible` | Resume guards |
| `cpr_rollback_not_eligible` | Rollback guards |
| `cpr_timeout_alone_forbidden` | OD-TIMEOUT |
| `cpr_auto_rollback_forbidden` | OD-ROLLBACK |
| `cpr_post_ponr_auto_unlock_forbidden` | OD-LOCK-TTL |
| `cpr_mutation_engine_forbidden` | No DELETE/IMPORT/PONR engines in P3 |
| `cpr_checkpoint_binding_failed` | expected vs `last_checkpoint_id` |

---

## 5. Consistency map

| Baseline | How satisfied |
|----------|---------------|
| P1-03 matrix | Catalog encodes §10 CSV + T34/T60 expansions |
| P1-03 R1–R6 | Auto-rollback / auto-unlock / CA / timeout alone rejected |
| P1-09 | Resume safe-continuation; Rollback pause-only |
| P1-02 | Contract/identity guards on freeze-related edges |
| P3 charter | Enablement false; no live DELETE/IMPORT; T09 scaffold-only |
| C3–C8 / Architecture / OD | Untouched |

---

## 6. Acceptance criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | State catalog + engine under `includes/backup/country_production/` | **PASS** |
| AC2 | Only legal transitions apply; illegal → `illegal_cpr_status_transition` | **PASS** |
| AC3 | No automatic rollback; explicit refuse helper | **PASS** |
| AC4 | No post-PONR automatic unlock; explicit refuse helper | **PASS** |
| AC5 | Maint release Super Admin + Runbook only | **PASS** |
| AC6 | Fail-pause → Resume/Rollback eligibility; Country Admin denied | **PASS** |
| AC7 | Bindings: identity, contract, checkpoint, PONR, audit | **PASS** |
| AC8 | Deterministic error result shape (`ok`/`code`/`message`) | **PASS** |
| AC9 | Self-tests: legal, illegal, cancel, pause, resume, rollback, terminal | **PASS** |
| AC10 | Enablement FALSE; no DELETE/IMPORT/PONR mutation engines | **PASS** |
| AC11 | No C3–C8 / Architecture / OD changes | **PASS** |
| AC12 | P3 Artifact Index marks WP-P3-03 COMPLETE | **PASS** |

---

## 7. Stop rule

**WP-P3-03 COMPLETE.** STOP — do not begin WP-P3-04 until Owner approval.

---

*End of WP-P3-03.*
