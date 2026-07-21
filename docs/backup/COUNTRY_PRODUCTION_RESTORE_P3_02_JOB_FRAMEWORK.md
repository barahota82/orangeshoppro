# Country Production Restore — P3 Job Framework Scaffolding

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P3-02** — Job Framework Scaffolding |
| **Artifact-ID** | `CPR-P3-WP02-JOB_FRAMEWORK` |
| **Status** | COMPLETE (scaffolding) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P3-01; authorized WP-P3-02 |
| **Baselines** | `P0-P0b-Final` · `P1-Design-Baseline` · `P2-Design-Baseline` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` |
| **Primary P1** | `CPR-P1-WP02-EXECUTION_CONTRACT` · P1-03 (pre-PONR subset) |
| **Enablement** | **FALSE** (hard) |
| **PONR / DELETE / IMPORT** | **Not implemented** |

---

## 1. Purpose

Scaffold the CPR **job framework**: identity, persistence, list, cancel, and initial execution-contract freeze (`pre_pin`) — consistent with P1-02 / P1-03 and P3 charter (**job framework + gates only; no PONR**).

---

## 2. Deliverables

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_paths.php` | CPR work root + job paths |
| `includes/backup/country_production/cpr_enablement.php` | Flag **read**; never writes true |
| `includes/backup/country_production/cpr_job_framework.php` | create / read / list / cancel / contract freeze |
| `scripts/backup/country_production/self_test_cpr_job_framework.php` | Self-test |
| This document | Design + AC |

---

## 3. Behavior summary

| API | Behavior |
|-----|----------|
| `orange_cpr_job_create` | Creates `cpr_pending` job + audit; enablement must be false |
| `orange_cpr_job_read` / `list` | Read-only |
| `orange_cpr_job_cancel` | Pre-PONR cancellable states → `cpr_cancelled_pre_ponr` |
| `orange_cpr_contract_freeze_initial` | Freezes `cpr_execution_contract` at `pre_pin`; C8 SAFE required; no session pin; `ponr_authorized=false` |
| `orange_cpr_forbidden_*` | Hard stubs blocking PONR/DELETE/IMPORT |

**Not in this WP:** Full state machine (WP-P3-03), checkpoints (WP-P3-04), locks (WP-P3-05), gate evaluator (WP-P3-06), authority UI (WP-P3-07), audit catalog complete (WP-P3-08).

Job record reserves `lock_held`, `last_checkpoint_id`, `last_gate_eval_ref` for later WPs.

---

## 4. Consistency map

| Baseline | How satisfied |
|----------|---------------|
| P1-02 identity / idempotency / contract | UUID job_id; idempotency_key; contract fields; freeze rules; no package/country swap |
| P1-03 states | Uses `cpr_pending`, `cpr_contract_frozen`, `cpr_cancelled_pre_ponr`; forbids PONR/apply states |
| P1-04 checkpoints | Not written; field reserved |
| P1-05 locks | Lock path helper only; acquire not implemented |
| P1-08 gates | Not evaluated; field reserved for WP-P3-06 |
| OD-ENABLE / P2 | `enablement_flag_observed=false`; create/freeze refuse if flag true |
| OD-C8 | Freeze rejects non-SAFE |
| OD-DUAL | `workflow` A\|B required |
| C3–C8 | Untouched |

---

## 5. Acceptance criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Design artifact + PHP scaffolding under `includes/backup/country_production/` | **PASS** |
| AC2 | Create / read / list / cancel implemented | **PASS** |
| AC3 | Contract freeze `pre_pin` per P1-02; no pin/PONR | **PASS** |
| AC4 | Enablement FALSE enforced on create/freeze | **PASS** |
| AC5 | No DELETE/IMPORT/PONR engines | **PASS** |
| AC6 | No C3–C8 / Architecture / OD changes | **PASS** |
| AC7 | Self-test script present and runnable | **PASS** |
| AC8 | P3 Artifact Index marks WP-P3-02 COMPLETE | **PASS** |

---

## 6. Stop rule

**WP-P3-02 COMPLETE.** STOP — do not begin WP-P3-03 until Owner approval.

---

*End of WP-P3-02.*
