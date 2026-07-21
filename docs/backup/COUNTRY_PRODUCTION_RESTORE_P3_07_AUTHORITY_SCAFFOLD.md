# Country Production Restore — P3 Pre-PONR Authorization & Contract Freeze Engine

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P3-07** — Pre-PONR Authorization & Contract Freeze Engine |
| **Artifact-ID** | `CPR-P3-WP07-AUTHORITY_SCAFFOLD` |
| **Status** | COMPLETE (scaffolding) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P3-06; authorized WP-P3-07 |
| **Baselines** | `P0-P0b-Final` · `P1-Design-Baseline` · `P2-Design-Baseline` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` |
| **Primary P1** | `CPR-P1-WP06-AUTHORITY_RUNBOOK` · `CPR-P1-WP02-EXECUTION_CONTRACT` |
| **Enablement** | **FALSE** hard (ops / observed) |
| **Mutation** | **None** (no DELETE/IMPORT/PONR engines) |

---

## 1. Purpose

Implement the pre-PONR authorization engine that:

1. Consumes **only** a sealed `pre_ponr_full` **PASS** gate report from WP-P3-06.
2. Freezes the execution contract at **`pre_ponr`** (session Full Backup pin attach) immediately before authorization.
3. Re-reads and verifies all bound fingerprints.
4. Mints a **one-time, immutable, non-transferable, audited** sealed PONR authorization record.
5. Does **not** execute PONR mutation.

---

## 2. Deliverables

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_authority_engine.php` | Freeze + authorize + seal + assert/consume helpers |
| `includes/backup/country_production/cpr_paths.php` | `auth/` directory + scaffold version bump |
| `scripts/backup/country_production/self_test_cpr_authority.php` | Self-test |
| This document | Design + AC |

---

## 3. Behavior

| API | Behavior |
|-----|----------|
| `orange_cpr_contract_freeze_pre_ponr` | Amend frozen contract → `pre_ponr`; attach OD-PIN fields; fail-closed on fingerprint / C8 / pin drift |
| `orange_cpr_ponr_authorize` | Full ceremony; sealed auth + challenge; no `ponr_crossed`; no mutation |
| `orange_cpr_ponr_authorization_assert_usable` | Seal + bind + not consumed |
| `orange_cpr_ponr_authorization_mark_consumed` | One-time consume marker **without** PONR mutation |
| `orange_cpr_ponr_mutation_refuse` | Explicit refuse helper |

### 3.1 Authorization bindings

Job identity · package fingerprint · country · certified inventory snapshot · schema revision · C4–C8 report hashes · session Full Backup + pin · checkpoint state · lock ownership · Super Admin identity · RESTORE phrase + re-auth evidence · Runbook completion evidence · sealed gate PASS.

### 3.2 Fail-closed rejects

| Condition | Code (examples) |
|-----------|-----------------|
| Gate missing / unsealed / stale / not PASS | `auth_gate_*` |
| Fingerprint drift | `auth_fingerprint_drift` |
| Lock ownership changed | `auth_lock_ownership_drift` |
| Invalid checkpoint / state | `auth_checkpoint_invalid` / `auth_state_invalid` |
| C8 not SAFE | `auth_c8_not_safe` |
| Session pin missing | `auth_pin_missing` |
| Runbook incomplete | `auth_runbook_incomplete` |
| Not Super Admin | `auth_actor_not_super_admin` |
| Invalid phrase | `auth_phrase_invalid` |
| Missing re-auth | `auth_reauth_missing` |
| Duplicate / replay | `auth_duplicate` / `auth_replay` |

---

## 4. Acceptance criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Pre-PONR authorization engine implemented | **PASS** |
| AC2 | Consumes only sealed PASS from WP-P3-06 | **PASS** |
| AC3 | Contract freeze immediately before authorization (`pre_ponr`) | **PASS** |
| AC4 | Re-read/verify bound fingerprints before auth | **PASS** |
| AC5 | Bindings listed in §3.1 enforced | **PASS** |
| AC6 | Fail-closed rejects per §3.2 | **PASS** |
| AC7 | Authorization one-time, immutable, non-transferable, audited | **PASS** |
| AC8 | Sealed PONR authorization record produced | **PASS** |
| AC9 | No PONR mutation; no DELETE/IMPORT | **PASS** |
| AC10 | Enablement remains FALSE | **PASS** |
| AC11 | No Architecture / OD / C3–C8 changes | **PASS** |
| AC12 | Self-tests cover required cases | **PASS** |
| AC13 | P3 Artifact Index WP-P3-07 COMPLETE | **PASS** |

---

## 5. Stop rule

**WP-P3-07 COMPLETE.** STOP — do not begin WP-P3-08 until Owner approval.

---

*End of WP-P3-07.*
