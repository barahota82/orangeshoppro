# Country Production Restore — P3 Pre-PONR Gate Evaluation Engine

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P3-06** — Pre-PONR Gate Evaluation Engine |
| **Artifact-ID** | `CPR-P3-WP06-GATE_EVALUATOR` |
| **Status** | COMPLETE (scaffolding) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P3-05; authorized WP-P3-06 |
| **Baselines** | `P0-P0b-Final` · `P1-Design-Baseline` · `P2-Design-Baseline` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` |
| **Primary P1** | `CPR-P1-WP08-PRE_PONR_GATES` |
| **Enablement** | **FALSE** hard in ops; G01 fail-closed until OD-ENABLE evidence |
| **Mutation** | **None** (no DELETE/IMPORT/PONR engines) |

---

## 1. Purpose

Implement fail-closed machine-checkable evaluation of G01–G30 + G-FA-* per P1-08, bind to job/contract/checkpoints/locks/state/fingerprints/inventory/session pin/schema/C4–C8, seal a durable report, and forbid PONR authorization unless `pre_ponr_full` aggregate PASS.

---

## 2. Deliverables

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_gate_catalog.php` | Profiles + ordered gate IDs |
| `includes/backup/country_production/cpr_gate_evaluator.php` | Evaluator + sealed persist + PONR auth helper |
| `scripts/backup/country_production/self_test_cpr_gates.php` | Self-test |
| This document | Design + AC |

---

## 3. Behavior

| API | Behavior |
|-----|----------|
| `orange_cpr_gate_evaluate` | Evaluate profile; continue after FAIL for diagnostics; seal report |
| `orange_cpr_ponr_authorization_allowed` | `true` only for `pre_ponr_full` + all PASS + C8 SAFE + no peers/waiver |
| Bypass / force-PASS / skip | Hard reject `gate_bypass_forbidden` |

Profiles: `package_chain` (G07–G19 + FA) · `pre_ponr_full` (G01–G30 + FA).

---

## 4. Acceptance criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Full gate set G01–G30 + FA implemented fail-closed | **PASS** |
| AC2 | Bindings: job, contract, CP, locks, state, fingerprint, inventory, pin, schema, C4–C8 | **PASS** |
| AC3 | C8 SAFE only; WARNING/waiver rejected | **PASS** |
| AC4 | No skip / no Super Admin bypass | **PASS** |
| AC5 | OD-INV no live replace; OD-PIN order; lock exclusion; FA predicates | **PASS** |
| AC6 | Deterministic fail codes; sealed report persisted | **PASS** |
| AC7 | PONR auth forbidden unless all mandatory gates PASS | **PASS** |
| AC8 | Enablement FALSE ops path fails G01; no mutation engines | **PASS** |
| AC9 | No Architecture / OD / C3–C8 changes | **PASS** |
| AC10 | Self-tests cover required cases | **PASS** |
| AC11 | P3 Artifact Index WP-P3-06 COMPLETE | **PASS** |

---

## 5. Stop rule

**WP-P3-06 COMPLETE.** STOP — do not begin WP-P3-07 until Owner approval.

---

*End of WP-P3-06.*
