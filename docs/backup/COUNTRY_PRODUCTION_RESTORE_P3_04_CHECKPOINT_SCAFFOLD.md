# Country Production Restore — P3 Checkpoint Engine & Persistence

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P3-04** — Checkpoint Engine & Persistence Layer |
| **Artifact-ID** | `CPR-P3-WP04-CHECKPOINT_SCAFFOLD` |
| **Status** | COMPLETE (scaffolding) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P3-03; authorized WP-P3-04 |
| **Baselines** | `P0-P0b-Final` · `P1-Design-Baseline` · `P2-Design-Baseline` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` |
| **Primary P1** | `CPR-P1-WP04-CHECKPOINT_SCHEMAS` |
| **Enablement** | **FALSE** (hard) |
| **Mutation** | **None** (no DELETE/IMPORT/production apply) |

---

## 1. Purpose

Implement the P1-04 checkpoint engine: persistence under `checkpoints/`, atomic tmp→rename, load/validate/recover, version + integrity verification, lifecycle (tmp purge), OD-PIN ordering, and pre/post-PONR discipline — fail-closed with **no silent repair**.

---

## 2. Deliverables

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_checkpoint_catalog.php` | IDs, filenames, DAG, payload requirements |
| `includes/backup/country_production/cpr_checkpoint_engine.php` | create/load/validate/recover/integrity/lifecycle |
| `includes/backup/country_production/cpr_paths.php` | Checkpoint dir / atomic rename helpers (additive) |
| `scripts/backup/country_production/self_test_cpr_checkpoints.php` | Self-test |
| This document | Design + AC |

---

## 3. Behavior

| API | Behavior |
|-----|----------|
| `orange_cpr_checkpoint_create` | Validate → write `.tmp` → atomic rename → MANIFEST → job `last_checkpoint_id` → audit |
| `orange_cpr_checkpoint_load` | Missing / corrupt / version / integrity fail-closed |
| `orange_cpr_checkpoint_validate_write` | Prereq DAG, OD-PIN, state binding, identity, PONR discipline |
| `orange_cpr_checkpoint_recover` | Ignore `.tmp`; use finals only; **refuse** silent repair of corrupt finals |
| `orange_cpr_checkpoint_verify_integrity` | `content_sha256` over canonical envelope |
| `orange_cpr_checkpoint_lifecycle_purge_tmp` | Remove staging files only |
| `orange_cpr_checkpoint_refuse_silent_repair` | Explicit hard fail |

**Bindings:** job identity, execution contract, job state (P3-03), `ponr_crossed`, audit `cpr.checkpoint_commit` / `cpr.checkpoint_recover`.

---

## 4. Acceptance criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Catalog + engine implement P1-04 CP0–CP12 / CP-A / runbook | **PASS** |
| AC2 | Atomic write/rename + MANIFEST | **PASS** |
| AC3 | Load + missing/corrupt handling | **PASS** |
| AC4 | Validation (schema, prereq, state, identity) | **PASS** |
| AC5 | Recovery ignores torn tmp; no silent repair | **PASS** |
| AC6 | Version validation (`schema_version=1.0`) | **PASS** |
| AC7 | Integrity verification (`content_sha256`) | **PASS** |
| AC8 | Lifecycle tmp purge | **PASS** |
| AC9 | Pre/post-PONR + OD-PIN order enforced | **PASS** |
| AC10 | Bound to job / contract / state / audit / PONR | **PASS** |
| AC11 | Enablement FALSE; no mutation engines | **PASS** |
| AC12 | No Architecture / OD / C3–C8 changes | **PASS** |
| AC13 | Self-tests cover create/load/recover/corrupt/missing/version/atomic/integrity | **PASS** |
| AC14 | P3 Artifact Index marks WP-P3-04 COMPLETE | **PASS** |

---

## 5. Stop rule

**WP-P3-04 COMPLETE.** STOP — do not begin WP-P3-05 until Owner approval.

---

*End of WP-P3-04.*
