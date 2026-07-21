# WP-P4-03 — GLOBAL Maintenance Live (CP4)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P4-03** — GLOBAL Maintenance live path (CP4) |
| **Artifact-ID** | `CPR-P4-WP03-MAINTENANCE_LIVE` |
| **Status** | COMPLETE |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Depends on** | WP-P4-02 · WP-P3-03 · WP-P3-04 · OD-MAINT · OD-MAINT-SCOPE · OD-MAINT-MAX · OD-PIN |
| **Maps to** | Architecture §9 · P1-07 · P1-04 (CP4) · P1-06 §8.3–§8.4 |

---

## 1. Purpose

Implement the **live GLOBAL Maintenance lifecycle** through **write-block proof** and **CP4 activation**, integrated with the P3 State / Lock / Gate / Authority engines, exactly per OD-PIN ordering (**Maint / CP4 before Session Full Backup pin**).

**Explicitly out of scope for this WP:**

- Session Full Backup / CP1 live path (WP-P4-04)  
- Lock live acquire path (WP-P4-05)  
- Full gate suite live evaluation (WP-P4-06)  
- DELETE / IMPORT / PONR mutation  
- Enablement TRUE  
- Wiring Full DR production maintenance enforcement (no production mutation)

---

## 2. Implementation root

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_maintenance_live.php` | Live GLOBAL maint lifecycle + CP4 |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_MAINT_DIRNAME` + `orange_cpr_maint_*()`; scaffold `P4-03-maintenance-live` |
| `scripts/backup/country_production/self_test_cpr_maintenance_live.php` | Self-tests |

**Consumed (P3 — not redesigned):**

- `orange_cpr_transition_apply` (T06 enter; maint_release edges)  
- `orange_cpr_checkpoint_create` / validate (CP4; OD-PIN CP1 refusal)  
- `orange_cpr_lock_read` (conflict observation; `maint_global_required`)  
- `orange_cpr_gate_evaluate_one('G22')`  
- `orange_cpr_auth_seal` / Super Admin actor gates  

---

## 3. Live APIs

| Function | Role |
|----------|------|
| `orange_cpr_maint_live_enter` | Enter GLOBAL Maint (T06); durable `maint_state`; duration estimate (monitoring only) |
| `orange_cpr_maint_live_prove_and_activate_cp4` | Write-block proof + CP4 + G22 PASS |
| `orange_cpr_maint_live_activate_cp4` | Combined enter → prove → CP4 |
| `orange_cpr_maint_live_release` | Super Admin only; Runbook required; never automatic |
| `orange_cpr_maint_live_refuse_auto_release` | Hard refuse auto / timeout / wall-clock release |
| `orange_cpr_maint_live_evidence_slice` | Gate/lock evidence shape (`scope=GLOBAL`, proven flags) |

---

## 4. Lifecycle (P1-07)

```text
maint_off
  → maint_entering / maint_on_unproven   # enter (T06)
  → maint_on_proven                      # write-block + CP4
  → (later WPs: executing / pause …)
  → maint_release_authorized → maint_released   # Super Admin + Runbook only
```

**Enforced:**

| Rule | Enforcement |
|------|-------------|
| GLOBAL only | `maint_scope` const `GLOBAL`; country-only → `maint_country_only_forbidden` |
| Maint before Session Full Backup | Refuse if CP1 exists; CP1 validate still fails in `cpr_maintenance_on` |
| No automatic release | `auto_release` / `timeout_release` / `wall_clock_release` → refuse |
| Super Admin only release | OD-PERM actor assert |
| Runbook before release | `runbook_completed` + `runbook_evidence_ref` |
| Sealed records | `content_sha256` via `orange_cpr_auth_seal` |
| Fail-closed | Any failed check returns deterministic code; no bypass |
| Enablement FALSE | `orange_cpr_assert_enablement_false_for_scaffold` |

---

## 5. Artifacts

| File | Content |
|------|---------|
| `maintenance/maint_state.json` | Sealed durable `cpr_maint_state/1` |
| `maintenance/cpr_maint_enter_{id}.json` | Sealed enter record |
| `maintenance/cpr_maint_cp4_{id}.json` | Sealed CP4 activation record |
| `maintenance/cpr_duration_estimate_{id}.json` | Monitoring-only estimate (`hard_fail_deadline=false`) |
| `maintenance/cpr_maint_release_{id}.json` | Sealed `cpr_maint_release_authorization/1` |
| `checkpoints/CP4_maintenance_verified.json` | Checkpoint engine CP4 |

**Audit events:** `cpr.maint_live_enter`, `cpr.maint_live_cp4_activate`, `cpr.maint_live_release`.

---

## 6. Deterministic outcome codes

| Code | Meaning |
|------|---------|
| `ok` | Success |
| `maint_enablement_forbidden` | Enablement not FALSE |
| `maint_actor_not_super_admin` | Actor / OD-PERM |
| `maint_country_only_forbidden` | Non-GLOBAL scope |
| `maint_state_invalid` | Job/state invalid |
| `maint_contract_invalid` | Contract not frozen |
| `maint_checkpoint_prereq_invalid` | CP0/CP2/CP3 missing |
| `maint_od_pin_order_violation` | CP1 before CP4 / pin order |
| `maint_write_block_proof_invalid` | Empty proof |
| `maint_lock_conflict` | Peer lock / post-PONR lock held |
| `maint_gate_g22_failed` | G22 not PASS after CP4 |
| `maint_state_transition_failed` | State engine refused |
| `maint_cp4_commit_failed` | Checkpoint engine refused CP4 |
| `maint_auto_release_forbidden` | Auto/timeout release attempt |
| `maint_runbook_incomplete` | Runbook missing for release |
| `maint_release_forbidden` | Release from wrong state / incomplete closeout |
| `maint_duplicate_or_persist_failed` | Persist / seal collide |
| `maint_lifecycle_invalid` | Durable lifecycle mismatch |
| `maint_ponr_forbidden` | PONR already crossed |

---

## 7. Enablement / safety

- Scaffold: `ORANGE_CPR_SCAFFOLD_VERSION = P4-03-maintenance-live`  
- Enablement remains **FALSE**  
- No DELETE / IMPORT / PONR mutation APIs  
- Write-block proof is sealed evidence for CP4 / G22 — **not** Full DR production writer mutation  

---

## 8. Acceptance Criteria

| # | Criterion | Met |
|---|-----------|-----|
| AC1 | Live GLOBAL Maintenance lifecycle implemented | YES |
| AC2 | Integrated with State Engine (T06 / release edges) | YES |
| AC3 | Integrated with Lock Engine (conflict observe + `maint_global_required`) | YES |
| AC4 | Integrated with Gate Engine (G22 PASS after CP4) | YES |
| AC5 | Integrated with Authority Engine (Super Admin / seal / Runbook gate) | YES |
| AC6 | CP4 activation per OD-PIN (before Session Full Backup) | YES |
| AC7 | GLOBAL only; country-only forbidden | YES |
| AC8 | Maint enters before Session Full Backup pin | YES |
| AC9 | No automatic maintenance release | YES |
| AC10 | Super Admin only may release | YES |
| AC11 | Runbook completion required before release | YES |
| AC12 | Sealed maintenance records + audit events | YES |
| AC13 | Fail-closed behavior preserved | YES |
| AC14 | Enablement remains FALSE; no DELETE/IMPORT/PONR/production mutation | YES |
| AC15 | Architecture / OWNER_APPROVED / prior WPs unmodified (except this WP artifacts) | YES |
| AC16 | Self-tests cover success + denial paths; PHP lint clean | YES |

---

## 9. Stop rule

**WP-P4-03 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P4-04 until Owner explicitly reviews and approves the next Work Package.
