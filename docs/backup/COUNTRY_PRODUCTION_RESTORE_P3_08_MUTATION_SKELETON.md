# Country Production Restore — P3 Mutation Engine Skeleton (No Production Mutation)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P3-08** — Mutation Engine Skeleton (No Production Mutation) |
| **Artifact-ID** | `CPR-P3-WP08-MUTATION_SKELETON` |
| **Status** | COMPLETE (scaffolding) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P3-07; authorized WP-P3-08 as mutation skeleton |
| **Baselines** | `P0-P0b-Final` · `P1-Design-Baseline` · `P2-Design-Baseline` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` |
| **Primary Architecture** | §6 Execution pipeline |
| **Enablement** | **FALSE** hard |
| **Mutation** | **None** — all mutation stages stub `Not Implemented Yet` |

**Note (inventory):** Earlier P3-01 inventory titled WP-P3-08 as audit-only scaffolding. Owner redirected this WP to the **mutation-engine skeleton** with embedded audit/checkpoint hooks. Full audit catalog freeze remains available for WP-P3-09 integration review.

---

## 1. Purpose

Build the **internal mutation-engine skeleton only**:

- Execution pipeline framework  
- Orchestration flow  
- Stage dispatch  
- Worker interfaces (callable DI)  
- Execution context  
- Cancellation points  
- Fail-closed error propagation  
- Audit hooks · Checkpoint hooks  
- Integrations: state / lock / gate / authority engines  

**Strictly forbidden:** DELETE execution · IMPORT execution · production business-data writes · PONR execution · enablement · C3–C8 / Architecture / OWNER_APPROVED edits.

---

## 2. Deliverables

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_mutation_catalog.php` | Stage catalog |
| `includes/backup/country_production/cpr_mutation_engine.php` | Pipeline / orchestrate / dispatch / context |
| `scripts/backup/country_production/self_test_cpr_mutation.php` | Self-test |
| This document | Design + AC |

---

## 3. APIs

| API | Behavior |
|-----|----------|
| `orange_cpr_mutation_pipeline_create` | Create stage list; optional persist; no mutation |
| `orange_cpr_mutation_context_create` | Execution context + DI + hooks |
| `orange_cpr_mutation_stage_dispatch` | Cancel point → worker → audit; fail-closed result |
| `orange_cpr_mutation_orchestrate` | Create + dispatch ordered stages; stop on first failure |
| `orange_cpr_mutation_cancel` | Cancellation |
| `orange_cpr_mut_not_implemented` | Canonical stub: `Not Implemented Yet` |

Mutation stages (`ponr_target_slice_delete`, `target_slice_import`, uploads, …) always return stub NIY.

---

## 4. Acceptance criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Mutation-engine skeleton implemented | **PASS** |
| AC2 | Pipeline framework + orchestration + stage dispatch | **PASS** |
| AC3 | Worker interfaces + execution context + DI | **PASS** |
| AC4 | Cancellation points | **PASS** |
| AC5 | Fail-closed error propagation | **PASS** |
| AC6 | Audit + checkpoint hooks | **PASS** |
| AC7 | State / lock / gate / authority integration binds | **PASS** |
| AC8 | All mutation stages stub NIY; no DELETE/IMPORT/PONR/production writes | **PASS** |
| AC9 | Enablement remains FALSE | **PASS** |
| AC10 | No Architecture / OD / C3–C8 changes | **PASS** |
| AC11 | Self-tests cover required cases | **PASS** |
| AC12 | P3 Artifact Index WP-P3-08 COMPLETE | **PASS** |

---

## 5. Stop rule

**WP-P3-08 COMPLETE.** STOP — do not begin WP-P3-09 until Owner approval.

---

*End of WP-P3-08.*
