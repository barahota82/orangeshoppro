# WP-P4-02 — Approvals & Live Pre-PONR Contract

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P4-02** — Approvals & execution-contract `pre_ponr` live path |
| **Artifact-ID** | `CPR-P4-WP02-APPROVALS_CONTRACT_LIVE` |
| **Status** | COMPLETE |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Depends on** | WP-P4-01 · WP-P3-02 · WP-P3-07 · OD-DUAL · OD-PERM · OD-C8 · OD-INV |
| **Maps to** | Architecture §6–§8, §14 · P1-02 · P1-06 |

---

## 1. Purpose

Implement the **live pre-PONR approval flow**: dual-control approval intake, full evidence re-validation, **contract freeze immediately before pre-PONR**, and consumption of the P3 authorization engine — with **deterministic** outcomes and **fail-closed** behavior.

**Explicitly out of scope for this WP:**

- PONR crossing / production mutation  
- DELETE / IMPORT  
- Enablement TRUE  
- Live maint window / OD-PIN / lock / gate creation (later P4 WPs) — this WP **consumes** evidence and fails closed if missing  

---

## 2. Implementation root

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_approvals_live.php` | Live approvals + pre-PONR contract orchestration |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_APPROVALS_DIRNAME` + `orange_cpr_approvals_directory()`; scaffold `P4-02-approvals-live` |
| `scripts/backup/country_production/self_test_cpr_approvals_live.php` | Self-tests |

**Consumed (P3 — not redesigned):**

- `orange_cpr_contract_freeze_pre_ponr()`  
- `orange_cpr_ponr_authorize()`  
- Checkpoint / lock / gate / state engines  

---

## 3. Live flow (ordered)

`orange_cpr_approvals_live_pre_ponr($env, $jobId, $request)`:

1. **Enablement assert** — refuse unless enablement remains FALSE.  
2. **Load job** — fail closed if missing.  
3. **OD-DUAL** — validate WF-A / WF-B approval payload (`orange_cpr_approvals_live_validate_dual`).  
4. **State** — require `cpr_pre_ponr` (Architecture pre-PONR window).  
5. **Re-validate (fail-closed):**  
   - execution contract (+ fingerprints vs re-read)  
   - required checkpoints (CP0, CP2, CP3, CP4, CP1, runbook_pre_ponr, CP5)  
   - inventory snapshot + CP1 session Full Backup pin  
   - lock ownership  
   - gate report (`PASS` required)  
6. **Contract freeze** — `orange_cpr_contract_freeze_pre_ponr` (immediate pre-PONR freeze).  
7. **Authorize** — `orange_cpr_ponr_authorize` (P3 engine; still no PONR mutation).  
8. **Persist** sealed approvals artifact under `{job}/approvals/`.  
9. **Audit** — `cpr.approvals_live_pre_ponr`.  

Outcome always includes `ponr_crossed: false`, `ponr_mutation_executed: false`, and `production_mutation: false`.

---

## 4. Deterministic outcome codes

| Code | Meaning |
|------|---------|
| `ok` | Dual + revalidate + freeze + authorize + persist succeeded |
| `approvals_enablement_forbidden` | Enablement not FALSE |
| `approvals_actor_not_super_admin` | Actor / OD-PERM violation |
| `approvals_workflow_invalid` | Workflow / WF-A ack invalid |
| `approvals_wfb_approval_missing` | WF-B Super Admin approval missing |
| `approvals_state_invalid` | Job / state invalid for pre-PONR |
| `approvals_contract_invalid` | Execution contract missing / not frozen / freeze failed |
| `approvals_fingerprint_drift` | Fingerprint / C8 drift |
| `approvals_checkpoint_invalid` | Required checkpoint missing/invalid |
| `approvals_lock_ownership_drift` | Lock ownership fail |
| `approvals_gate_invalid` | Gate report fail |
| `approvals_inventory_drift` | Inventory snapshot drift / OD-INV |
| `approvals_session_backup_invalid` | Session Full Backup / CP1 pin invalid |
| `approvals_authorization_failed` | P3 authorize refused |
| `approvals_duplicate_live_record` | Sealed live approvals already exists / persist collide |
| `approvals_ponr_forbidden` | PONR already crossed / invariant |

---

## 5. Dual-control (OD-DUAL) — live request shape

```json
{
  "actor_admin_id": 10,
  "actor_is_super_admin": true,
  "wfa_protections_ack": true,
  "phrase": "RESTORE",
  "password_reauth_ok": true,
  "lease_token": "…",
  "worker_id": "…",
  "reread": { }
}
```

**WF-B** additionally requires `super_admin_approval_recorded` and non-empty `super_admin_approval_id`.  
Country Admin as executor (`country_admin_is_executor`) is always refused (OD-PERM).

---

## 6. Artifacts

| File | Content |
|------|---------|
| `approvals/cpr_approvals_live_{approvals_live_id}.json` | Sealed dual + freeze + authorize summary (immutable) |
| `approvals/cpr_approvals_live_latest.json` | Latest pointer |

---

## 7. Enablement / safety

- Scaffold: `ORANGE_CPR_SCAFFOLD_VERSION = P4-02-approvals-live`  
- `ORANGE_CPR_ENABLEMENT_FLAG` / env enablement remains **FALSE**  
- No DELETE / IMPORT / PONR mutation APIs added  

---

## 8. Acceptance Criteria

| # | Criterion | Met |
|---|-----------|-----|
| AC1 | Live pre-PONR approval flow implemented | YES |
| AC2 | Consumes P3 authorization engine | YES — `orange_cpr_ponr_authorize` |
| AC3 | Live contract freeze immediately before pre-PONR authorize | YES — freeze then authorize |
| AC4 | Re-validates contract, fingerprints, state, checkpoint, lock, gate, inventory, Full Backup | YES |
| AC5 | Deterministic authorization / approvals outcome codes | YES |
| AC6 | Fail-closed on any failed check | YES |
| AC7 | Enablement remains FALSE | YES |
| AC8 | No PONR / DELETE / IMPORT | YES |
| AC9 | Architecture / OD decisions unchanged | YES |
| AC10 | Self-tests cover success + dual/state/gate/authorize denial paths | YES |

---

## 9. Stop rule

**WP-P4-02 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P4-03 until Owner explicitly approves the next Work Package.
