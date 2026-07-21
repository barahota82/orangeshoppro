# Country Production Restore — P3 Lock Engine & Concurrency Enforcement

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P3-05** — Lock Engine & Concurrency Enforcement |
| **Artifact-ID** | `CPR-P3-WP05-LOCK_SCAFFOLD` |
| **Status** | COMPLETE (scaffolding) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P3-04; authorized WP-P3-05 |
| **Baselines** | `P0-P0b-Final` · `P1-Design-Baseline` · `P2-Design-Baseline` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` |
| **Primary P1** | `CPR-P1-WP05-LOCK_FORMATS` |
| **Enablement** | **FALSE** (hard) |
| **Mutation** | **None** |

---

## 1. Purpose

Implement CPR lock acquire/heartbeat/release, cross-feature exclusion (Full DR / C6 / Backup Runner), stale classification, Super Admin pre-PONR manual clear with audit, and permanent post-PONR auto-unlock prohibition — per P1-05.

Peer writers (Full DR / C6 / Backup Runner) are **observed only** (not modified). Symmetric `orange_cpr_exclusion_check_for_peer` is provided for later peer integration.

---

## 2. Deliverables

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_lock_engine.php` | Lock + concurrency engine |
| `includes/backup/country_production/cpr_paths.php` | Peer path helpers (additive) |
| `scripts/backup/country_production/self_test_cpr_locks.php` | Self-test |
| This document | Design + AC |

---

## 3. Behavior

| API | Behavior |
|-----|----------|
| `orange_cpr_concurrency_validate` | Full DR / C6 / Backup / other-CPR gates; no bypass |
| `orange_cpr_lock_acquire` | Exclusive create (`fopen x`); same job → heartbeat |
| `orange_cpr_lock_heartbeat` | Refresh; preserve `ponr_crossed`, lease, job_id |
| `orange_cpr_lock_release` | Lease-bound; post-PONR needs `authorized_closeout` |
| `orange_cpr_lock_stale_classify` | Observation only (30s / 90s engineering defaults) |
| `orange_cpr_lock_manual_clear_pre_ponr` | Super Admin + stale + audit artifact §9 |
| `orange_cpr_lock_auto_unlock_attempt` | Always refuse (pre or post) |
| `orange_cpr_exclusion_check_for_peer` | Refuse peer when CPR held |

**Bindings:** job identity, contract revision, phase/state, `last_checkpoint_id`, heartbeat, audit events.

---

## 4. Acceptance criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | CPR lock acquire/release/heartbeat | **PASS** |
| AC2 | Full DR / C6 / Backup Runner exclusion | **PASS** |
| AC3 | Concurrent CPR denied | **PASS** |
| AC4 | Stale pre-PONR detection; no auto-unlock | **PASS** |
| AC5 | Super Admin manual clear + auditable artifact | **PASS** |
| AC6 | Post-PONR protection (no auto / no TTL clear) | **PASS** |
| AC7 | No exclusion bypass | **PASS** |
| AC8 | Bound to job/contract/state/checkpoint/heartbeat/audit | **PASS** |
| AC9 | Enablement FALSE; no mutation engines | **PASS** |
| AC10 | No Architecture / OD / C3–C8 peer writer changes | **PASS** |
| AC11 | Self-tests cover required cases | **PASS** |
| AC12 | P3 Artifact Index WP-P3-05 COMPLETE | **PASS** |

---

## 5. Stop rule

**WP-P3-05 COMPLETE.** STOP — do not begin WP-P3-06 until Owner approval.

---

*End of WP-P3-05.*
