# WP-P4-05 — Live Pre-PONR Lock Ownership & Heartbeat Enforcement

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P4-05** — CPR Lock live pre-PONR path |
| **Artifact-ID** | `CPR-P4-WP05-LOCK_LIVE` |
| **Status** | COMPLETE |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Depends on** | WP-P4-04 · WP-P3-05 · OD-LOCK-CROSS · OD-LOCK-SHADOW · OD-LOCK-TTL |
| **Maps to** | Architecture §15–§16 · P1-05 · P3-05 |

---

## 1. Purpose

Implement the **live pre-PONR CPR lock lifecycle** (acquire / heartbeat / ownership revalidation / stale detect / Super Admin manual clear) after CP4 + OD-PIN (CP1), producing **sealed** lifecycle and manual-clear audit artifacts, fail-closed on missing/corrupt/conflicting/ownership-drifted lock data.

**Explicitly out of scope:**

- Full gate suite live evaluation (WP-P4-06)  
- DELETE / IMPORT / PONR mutation  
- Enablement TRUE  
- Modifying Full DR / C6 / Backup Runner / C3–C8 writers  

---

## 2. Implementation root

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_lock_live.php` | Live lock orchestration |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_LOCK_LIVE_DIRNAME`; scaffold `P4-05-lock-live` |
| `scripts/backup/country_production/self_test_cpr_lock_live.php` | Self-tests |

**Consumed (P3 — not redesigned):** `orange_cpr_lock_acquire` / `heartbeat` / `manual_clear_pre_ponr` / peer observers / `orange_cpr_lock_auto_unlock_attempt`.

---

## 3. Live APIs

| Function | Role |
|----------|------|
| `orange_cpr_lock_live_acquire` | Acquire after CP4+CP1+contract pin; seal lifecycle; G26 |
| `orange_cpr_lock_live_heartbeat` | Lease-validated heartbeat + sealed event |
| `orange_cpr_lock_live_revalidate_ownership` | Pre-gate/authority ownership recheck |
| `orange_cpr_lock_live_detect_stale` | Stale observation (never auto-unlock) |
| `orange_cpr_lock_live_manual_clear` | Super Admin + reason + sealed audit |
| `orange_cpr_lock_live_read_strict` | Missing vs corrupt fail-closed |
| `orange_cpr_lock_live_refuse_auto_unlock` | Hard refuse auto/TTL unlock |

---

## 4. Sequence

```text
CP4 + GLOBAL Maint proven
  → OD-PIN CP1 + contract session pin
  → state cpr_pre_ponr
  → live lock acquire (exclusion gates)
  → heartbeat / ownership revalidate (before gates/authority)
  → (stale) Super Admin manual clear + sealed audit
  ✗ never post-PONR auto-unlock / TTL clear
```

---

## 5. Enforcements

| Rule | Enforcement |
|------|-------------|
| CPR ↔ Full DR | Peer observe → `locklive_blocked_full_dr` |
| CPR ↔ C6 | Peer observe → `locklive_blocked_c6` |
| CPR ↔ Backup Runner | Peer observe → `locklive_blocked_backup_runner` |
| Same job + contract bind | Identity + `contract_revision` checks |
| Heartbeat auditable | Sealed heartbeat records + audit events |
| Pre-PONR stale clear | Super Admin only + reason ≥ 8 + sealed audit |
| No privilege bypass | Country Admin / force / bypass refused |
| Corrupt / missing | `locklive_lock_corrupt` / `locklive_lock_missing` |
| Ownership drift | Lease / worker / package / revision mismatch |
| Post-PONR auto-unlock | Forbidden (OD-LOCK-TTL) |

---

## 6. Artifacts

| File | Content |
|------|---------|
| `lock_live/cpr_lock_live_acquire_{id}.json` | Sealed acquire lifecycle |
| `lock_live/cpr_lock_live_heartbeat_{id}.json` | Sealed heartbeat |
| `lock_live/cpr_lock_live_manual_clear_{id}.json` | Sealed manual-clear audit |
| `lock_live/cpr_lock_live_latest.json` | Latest acquire pointer |
| `{job}/lock_manual_clear_audit/…` | P3 clear audit (unchanged path) |

**Audit events:** `cpr.lock_live_acquire`, `cpr.lock_live_heartbeat`, `cpr.lock_live_manual_clear`.

---

## 7. Deterministic codes (selected)

| Code | Meaning |
|------|---------|
| `ok` | Success |
| `locklive_blocked_full_dr` / `_c6` / `_backup_runner` | Peer exclusion |
| `locklive_conflict` | Other CPR job holds lock |
| `locklive_ownership_drift` | Lease/job/contract drift |
| `locklive_lock_corrupt` / `_missing` | Fail-closed lock data |
| `locklive_clear_reason_required` | Missing/short reason |
| `locklive_post_ponr_clear_forbidden` | Post-PONR clear denied |
| `locklive_auto_unlock_forbidden` | Auto/TTL unlock denied |
| `locklive_bypass_forbidden` | Exclusion bypass |
| `locklive_actor_not_super_admin` | Actor denial |
| `locklive_od_pin_required` / `_maint_required` | Pre-lock prerequisites |

---

## 8. Acceptance Criteria

| # | Criterion | Met |
|---|-----------|-----|
| AC1 | Live pre-PONR lock lifecycle implemented | YES |
| AC2 | Integrated Job / State / Checkpoint / Maint / OD-PIN / Gate / Authority | YES |
| AC3 | CPR↔Full DR / C6 / Backup Runner exclusion | YES |
| AC4 | Ownership bound to same job + execution contract | YES |
| AC5 | Heartbeat validated + auditable (sealed) | YES |
| AC6 | Pre-PONR stale clear Super Admin only + reason + audit | YES |
| AC7 | No privilege bypass | YES |
| AC8 | Fail-closed missing/corrupt/conflict/ownership-drift | YES |
| AC9 | Post-PONR auto-unlock remains forbidden | YES |
| AC10 | Sealed lifecycle + manual-clear artifacts | YES |
| AC11 | Ownership revalidated before gate/authority steps | YES |
| AC12 | Enablement FALSE; no DELETE/IMPORT/PONR/production mutation | YES |
| AC13 | Architecture / OD / C3–C8 / prior WPs unmodified (scaffold test bumps only) | YES |
| AC14 | Self-tests cover required cases; PHP lint clean | YES |

---

## 9. Stop rule

**WP-P4-05 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P4-06 until Owner explicitly reviews and approves.
