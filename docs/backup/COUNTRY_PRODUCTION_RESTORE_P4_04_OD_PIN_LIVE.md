# WP-P4-04 — Live Session Full Backup & Pin (CP1)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P4-04** — Session Full Backup & OD-PIN live path (CP1) |
| **Artifact-ID** | `CPR-P4-WP04-OD_PIN_LIVE` |
| **Status** | COMPLETE |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Depends on** | WP-P4-03 · WP-P3-03 · WP-P3-04 · OD-PIN · OD-MAINT |
| **Maps to** | Architecture §6 OD-PIN · §18 · P1-04 (CP1 DAG) |

---

## 1. Purpose

Implement the **live OD-PIN path**: **NEW** Session Full Backup metadata → **mandatory verify** → **immutable pin (CP1)**, bound to CP4 / GLOBAL Maint, job identity, execution contract, and P3 State / Lock / Gate / Authority engines.

**Explicitly out of scope for this WP:**

- Lock live acquire (WP-P4-05)  
- Full gate suite live evaluation (WP-P4-06)  
- DELETE / IMPORT / PONR mutation  
- Enablement TRUE  
- Production mysqldump / Full DR backup runner mutation  

---

## 2. Implementation root

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_od_pin_live.php` | Live create → verify → pin (CP1) |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_OD_PIN_DIRNAME`; scaffold `P4-04-od-pin-live` |
| `scripts/backup/country_production/self_test_cpr_od_pin_live.php` | Self-tests |

**Consumed (not redesigned):**

- `orange_cpr_maint_live_*` / CP4 durable state  
- `orange_cpr_transition_apply` (T07 / T08)  
- `orange_cpr_checkpoint_create` (CP1)  
- `orange_cpr_lock_read`  
- `orange_cpr_gate_evaluate_one('G23')`  
- `orange_cpr_contract_write` pin amend · `orange_cpr_auth_seal`  

---

## 3. Live APIs

| Function | Role |
|----------|------|
| `orange_cpr_od_pin_live_create_session_backup` | NEW sealed backup metadata + T07 |
| `orange_cpr_od_pin_live_verify` | Sealed verify result; fail-closed |
| `orange_cpr_od_pin_live_pin` | Immutable pin + CP1 + contract amend + T08 |
| `orange_cpr_od_pin_live_run` | Combined create → verify → pin |
| `orange_cpr_od_pin_live_refuse_reuse` | Hard refuse prior-backup reuse |
| `orange_cpr_od_pin_live_refuse_continuation_without_pin` | Hard refuse unpinned continuation |

---

## 4. OD-PIN sequence (normative)

```text
CP4 / GLOBAL Maint proven
  → T07 cpr_anchor_pinning
  → NEW session Full Backup metadata (sealed)
  → verify PASS (sealed)     ✗ FAIL → stop (no pin)
  → immutable pin + CP1
  → contract pin amend (session_* + phase pre_ponr)
  → T08 cpr_pre_ponr
  → G23 PASS
```

**Enforced:**

| Rule | Enforcement |
|------|-------------|
| Maint/CP4 before pin | Preconditions fail without CP4 + GLOBAL proven maint |
| NEW backup only | `reused_existing_backup` must be false; reuse request refused |
| Verify before pin | Pin requires verify latest `PASS` |
| Pin immutable | Second pin / CP1 / latest pointer refused |
| Fail-closed verify | FAIL → no CP1 / no T08 |
| No continuation without pin | Helper + state T08 requires `session_full_backup_pinned` |
| Enablement FALSE | Scaffold assert |
| No production mutation | Metadata/verify/pin seals only; engines off |

---

## 5. Artifacts

| File | Content |
|------|---------|
| `od_pin/cpr_session_backup_{id}.json` | Sealed NEW backup metadata |
| `od_pin/cpr_session_backup_latest.json` | Latest backup pointer |
| `od_pin/cpr_session_verify_{id}.json` | Sealed verification result |
| `od_pin/cpr_session_verify_latest.json` | Latest verify pointer |
| `od_pin/cpr_session_pin_{id}.json` | Sealed immutable pin |
| `od_pin/cpr_session_pin_latest.json` | Pin pointer (create-once) |
| `checkpoints/CP1_anchor_pinned.json` | Checkpoint engine CP1 |

**Audit events:** `cpr.od_pin_session_backup_create`, `cpr.od_pin_session_backup_verify`, `cpr.od_pin_session_backup_pin`.

---

## 6. Deterministic outcome codes

| Code | Meaning |
|------|---------|
| `ok` | Success |
| `odpin_enablement_forbidden` | Enablement not FALSE |
| `odpin_actor_not_super_admin` | Actor / OD-PERM |
| `odpin_state_invalid` | Job/state invalid |
| `odpin_contract_invalid` | Contract not frozen |
| `odpin_identity_drift` | Job/contract identity mismatch |
| `odpin_maint_cp4_required` | CP4 / GLOBAL maint not proven |
| `odpin_reuse_forbidden` | Prior backup reuse attempted |
| `odpin_verify_failed` | Verification FAIL / missing |
| `odpin_pin_failed` | Pin step failed |
| `odpin_pin_immutable` | Pin already exists |
| `odpin_lock_conflict` | Peer lock conflict |
| `odpin_gate_g23_failed` | G23 not PASS |
| `odpin_state_transition_failed` | T07/T08 refused |
| `odpin_cp1_commit_failed` | Checkpoint engine refused CP1 |
| `odpin_backup_invalid` | Backup metadata missing/invalid |
| `odpin_duplicate_or_persist_failed` | Persist collide |
| `odpin_ponr_forbidden` | PONR already crossed |
| `odpin_continuation_without_pin` | Continuation without pin |

---

## 7. Enablement / safety

- Scaffold: `ORANGE_CPR_SCAFFOLD_VERSION = P4-04-od-pin-live`  
- Enablement remains **FALSE**  
- No DELETE / IMPORT / PONR mutation APIs  
- Session backup artifacts are sealed live-path evidence — **not** production DB dumps  

---

## 8. Acceptance Criteria

| # | Criterion | Met |
|---|-----------|-----|
| AC1 | Live Session Full Backup workflow implemented | YES |
| AC2 | Live backup verification implemented | YES |
| AC3 | Live pin creation implemented | YES |
| AC4 | Bound to CP4 / GLOBAL Maint | YES |
| AC5 | Bound to job identity + execution contract | YES |
| AC6 | Bound to State Engine (T07/T08) | YES |
| AC7 | Bound to Lock Engine observation | YES |
| AC8 | Bound to Gate Engine (G23) | YES |
| AC9 | Bound to Authority Engine (seal / contract pin amend / OD-PERM) | YES |
| AC10 | NEW Session Full Backup only; reuse forbidden | YES |
| AC11 | Verification before pin mandatory; verify FAIL fail-closed | YES |
| AC12 | Pin immutable after creation | YES |
| AC13 | No continuation without valid session pin | YES |
| AC14 | Sealed backup / verify / pin artifacts + audit events | YES |
| AC15 | OD-PIN order preserved (CP4 before CP1) | YES |
| AC16 | Enablement FALSE; no DELETE/IMPORT/PONR/production mutation | YES |
| AC17 | Architecture / OWNER_APPROVED / prior WPs unmodified (except scaffold version test bump) | YES |
| AC18 | Self-tests + PHP lint clean | YES |

---

## 9. Stop rule

**WP-P4-04 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P4-05 until Owner explicitly reviews and approves the next Work Package.
