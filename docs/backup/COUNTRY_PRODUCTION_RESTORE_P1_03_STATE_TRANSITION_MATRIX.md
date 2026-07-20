# Country Production Restore — P1 State Transition Matrix

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-03** — State Transition Matrix |
| **Artifact-ID** | `CPR-P1-WP03-STATE_TRANSITION_MATRIX` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §12, §13, §17, §28 |
| **Depends on** | WP-P1-02 (`cpr_execution_contract`) |
| **Coding** | **No** mutation engine code in this WP |

---

## 1. Purpose

Make the CPR job **state machine** fully explicit and non-ambiguous: legal transitions, terminal states, emergency-stop, OD-FAIL-* pause states, Super Admin Resume/Rollback, and maintenance release — with **no** hidden automatic Rollback and **no** post-PONR automatic unlock.

Illegal transition code (architectural): `illegal_cpr_status_transition`.

---

## 2. Hard rules (normative)

| ID | Rule | Authority |
|----|------|-----------|
| R1 | **No** transition may execute or imply **automatic Rollback** | OD-ROLLBACK; OD-FAIL-DELETE; OD-FAIL-IMPORT |
| R2 | **No** transition may **automatically release** a post-PONR execution lock | OD-LOCK-TTL |
| R3 | GLOBAL Maintenance release is **only** via Super Admin transition into `cpr_maintenance_released`, and only from allowed terminals after Runbook closeout | OD-PERM; OD-RUNBOOK; OD-MAINT-SCOPE |
| R4 | Every post-PONR failure path lands in a **pause** state (Super Admin Resume and/or Rollback) or enters **explicit** Rollback after Super Admin action — never silent continue-as-success | OD-FAIL-*; OD-VERIFY-WARN; OD-UPLOADS |
| R5 | Timeout / wall-clock alone must **not** transition to failure or Rollback | OD-TIMEOUT |
| R6 | Country Admin must **not** trigger execute / Resume / Rollback / maint-release transitions | OD-PERM; OD-DUAL |
| R7 | Phrase/`RESTORE` + re-auth required on execute and Rollback transitions (same security controls) | OD-PHRASE; OD-ROLLBACK |
| R8 | PONR = first successful production DELETE of target-slice row **or** first production uploads path replacement | Architecture §10.3 |

---

## 3. State catalog

### 3.1 Legend

| Flag | Meaning |
|------|---------|
| **T** | Terminal (job finished for this `job_id`; new attempt requires new job — WP-P1-02) |
| **P** | Pause (non-terminal; waits for Super Admin) |
| **A** | Active / running |
| **M** | GLOBAL Maintenance expected ON |
| **N** | Pre-PONR (production mutation of target slice not yet started) |
| **X** | Post-PONR (PONR entered) |

### 3.2 States

| State | Flags | Description |
|-------|-------|-------------|
| `cpr_pending` | A,N | Job created (WF-A Super Admin or WF-B Country Admin request) |
| `cpr_gates_validating` | A,N | Evaluating package/C3–C8/gates (no production mutation) |
| `cpr_awaiting_approvals` | A,N | **WF-B:** pending Super Admin approval; **WF-A:** may be skipped or instantaneous |
| `cpr_contract_frozen` | A,N | Execution contract frozen (WP-P1-02); pin fields may still be `pre_pin` |
| `cpr_maintenance_on` | A,N,M | GLOBAL Maint ON + write-block proven |
| `cpr_anchor_pinning` | A,N,M | NEW session Full Backup create/verify/pin (OD-PIN) |
| `cpr_pre_ponr` | A,N,M | Runbook checklist, phrase/`RESTORE`, witnesses, CP-A; ready for PONR |
| `cpr_deleting` | A,X,M | Target-slice DELETE in progress (**PONR entered** if first delete succeeds) |
| `cpr_importing` | A,X,M | Target-slice IMPORT batches 1→6 |
| `cpr_uploads_applying` | A,X,M | Scoped uploads apply (OD-UPLOADS) |
| `cpr_post_verifying` | A,X,M | Post-apply verification suite |
| `cpr_succeeded` | T,X,M | Success sealed; **Maint still ON** until release |
| `cpr_paused_delete_failed` | P,X,M | OD-FAIL-DELETE pause |
| `cpr_paused_import_failed` | P,X,M | OD-FAIL-IMPORT pause |
| `cpr_paused_uploads_failed` | P,X,M | OD-UPLOADS fail-closed pause |
| `cpr_paused_verify_failed` | P,X,M | OD-VERIFY-WARN fail-closed pause |
| `cpr_rolling_back` | A,X,M | Explicit Super Admin Rollback to session Full Backup in progress |
| `cpr_rollback_completed` | T,X,M | Rollback finished/verified; **Maint still ON** until release |
| `cpr_failed_pre_ponr` | T,N | Terminal pre-PONR failure (no production slice mutation) |
| `cpr_cancelled_pre_ponr` | T,N | Terminal pre-PONR cancel (incl. emergency stop pre-PONR) |
| `cpr_failed_post_ponr` | T,X,M | Terminal post-PONR failure **only** after pause path closed without success/rollback completion is **forbidden** as auto path — retained as **diagnostic umbrella** reachable only if Super Admin records **abandon-to-incident** after pause (still **no** auto-rollback; Maint remains ON). Default OD-FAIL path uses **pause** states, not this terminal. |
| `cpr_maintenance_released` | T | Writers restored; operational close |

**Note on `cpr_failed_post_ponr`:** Architecture §17 lists this name. Under OWNER_APPROVED OD-FAIL-*/OD-ROLLBACK, the **mandatory** post-PONR failure landing is a **pause** state. `cpr_failed_post_ponr` must **not** be used to skip pause or to auto-rollback. Prefer pause → Resume/Rollback. Umbrella terminal is for documented Super Admin incident closure only (Maint stays ON until `cpr_maintenance_released`).

### 3.3 Architecture §17 name mapping

| Architecture §17 name | This matrix |
|----------------------|-------------|
| `cpr_pending` … `cpr_maintenance_released` | Same |
| (pin step in §6) | `cpr_anchor_pinning` (explicit) |
| Post-PONR fail-pause (§12) | `cpr_paused_*` states |
| `cpr_failed_post_ponr` | Restricted umbrella — see §3.2 note |

---

## 4. Actors who may cause transitions

| Actor | Allowed transition classes |
|-------|----------------------------|
| System / worker | Forward progress, gate fail → pre-PONR terminal, enter pause on stage failure, cooperative emergency halt |
| Super Admin | WF-B approve, execute/authorize PONR path, Resume, Rollback, emergency stop, maint release, pre-PONR cancel |
| Country Admin | Create/request → `cpr_pending` / contribute to gates prep only (WF-B); **no** execute/Resume/Rollback/maint release |

---

## 5. Legal transition matrix

Columns: **From** → **To** · **Trigger** · **Actor** · **Guards (summary)**

### 5.1 Pre-PONR happy path & authority

| # | From | To | Trigger | Actor | Guards |
|---|------|-----|---------|-------|--------|
| T01 | `cpr_pending` | `cpr_gates_validating` | Start validation | System / Super Admin | Job exists; enablement rules per WP-P1-08 |
| T02 | `cpr_gates_validating` | `cpr_awaiting_approvals` | Gates OK; WF-B | System | `workflow=B` |
| T03 | `cpr_gates_validating` | `cpr_contract_frozen` | Gates OK; WF-A ready to freeze | System / Super Admin | `workflow=A`; contract fingerprints OK (WP-P1-02) |
| T04 | `cpr_awaiting_approvals` | `cpr_contract_frozen` | Super Admin approves | Super Admin | WF-B only; approval fingerprint recorded |
| T05 | `cpr_awaiting_approvals` | `cpr_failed_pre_ponr` | Super Admin rejects / timeout cancel policy | Super Admin / System | Pre-PONR only; **no** Rollback |
| T06 | `cpr_contract_frozen` | `cpr_maintenance_on` | Enter GLOBAL Maint | Super Admin / System | Contract frozen; OD-MAINT |
| T07 | `cpr_maintenance_on` | `cpr_anchor_pinning` | Start NEW Full Backup | System | Maint ON proven |
| T08 | `cpr_anchor_pinning` | `cpr_pre_ponr` | Pin verified; contract revision pinned | System | `session_full_backup_pinned=true` (WP-P1-02) |
| T09 | `cpr_pre_ponr` | `cpr_deleting` | Authorize PONR / start DELETE | Super Admin | Runbook complete; phrase `RESTORE` + re-auth; one-time auth; contract `pre_ponr` profile; C8 SAFE; no fingerprint drift |

### 5.2 Post-PONR happy path

| # | From | To | Trigger | Actor | Guards |
|---|------|-----|---------|-------|--------|
| T10 | `cpr_deleting` | `cpr_importing` | Delete complete | System | Dirty clear; contract unchanged |
| T11 | `cpr_importing` | `cpr_uploads_applying` | Import + special handlers complete | System | Batches complete |
| T12 | `cpr_uploads_applying` | `cpr_post_verifying` | Uploads complete | System | OD-UPLOADS integrity OK |
| T13 | `cpr_post_verifying` | `cpr_succeeded` | Verification PASS | System | OD-VERIFY-WARN fail-closed passed |
| T14 | `cpr_succeeded` | `cpr_maintenance_released` | Release GLOBAL Maint | Super Admin | OD-RUNBOOK successfully completed; OD-PERM |

### 5.3 Pre-PONR failure / cancel

| # | From | To | Trigger | Actor | Guards |
|---|------|-----|---------|-------|--------|
| T20 | `cpr_gates_validating` | `cpr_failed_pre_ponr` | Gate fail / C8 not SAFE / drift | System | No production mutation |
| T21 | `cpr_contract_frozen` | `cpr_failed_pre_ponr` | Drift / lock contention / preflight fail | System | Pre-PONR |
| T22 | `cpr_maintenance_on` | `cpr_cancelled_pre_ponr` | Super Admin cancel / emergency stop | Super Admin | Pre-PONR; **no** auto-Rollback |
| T23 | `cpr_anchor_pinning` | `cpr_failed_pre_ponr` | Pin/verify fail | System | Must not enter PONR without pin |
| T24 | `cpr_pre_ponr` | `cpr_cancelled_pre_ponr` | Cancel / emergency stop / auth fail | Super Admin / System | Pre-PONR |
| T25 | `cpr_failed_pre_ponr` | `cpr_maintenance_released` | Release Maint if ON | Super Admin | Runbook/abort closeout; only if Maint was ON |
| T26 | `cpr_cancelled_pre_ponr` | `cpr_maintenance_released` | Release Maint if ON | Super Admin | Same as T25 |

### 5.4 Post-PONR failure → pause (mandatory; no auto-Rollback)

| # | From | To | Trigger | Actor | Guards |
|---|------|-----|---------|-------|--------|
| T30 | `cpr_deleting` | `cpr_paused_delete_failed` | Delete-phase failure | System | OD-FAIL-DELETE; Maint stays ON; preserve state |
| T31 | `cpr_importing` | `cpr_paused_import_failed` | Import-phase failure | System | OD-FAIL-IMPORT; Maint ON |
| T32 | `cpr_uploads_applying` | `cpr_paused_uploads_failed` | Uploads integrity fail | System | OD-UPLOADS; Maint ON |
| T33 | `cpr_post_verifying` | `cpr_paused_verify_failed` | Verification FAIL | System | OD-VERIFY-WARN; Maint ON |
| T34 | `cpr_deleting` / `cpr_importing` / `cpr_uploads_applying` / `cpr_post_verifying` | *(same pause as stage)* | Emergency stop post-PONR | Super Admin / System | Cooperative halt → **pause** (not auto-Rollback) — Architecture §28 |

### 5.5 Pause → Resume (Super Admin only; safe stage continuation)

| # | From | To | Trigger | Actor | Guards |
|---|------|-----|---------|-------|--------|
| T40 | `cpr_paused_delete_failed` | `cpr_deleting` | Resume | Super Admin | Stage safely supports continuation (OD-FAIL-DELETE); **not** statement-offset invent |
| T41 | `cpr_paused_import_failed` | `cpr_importing` | Resume | Super Admin | Safe continuation only (e.g. re-clear + re-import); **no** SQL byte-offset resume (OD-FAIL-IMPORT; Arch §13) |
| T42 | `cpr_paused_uploads_failed` | `cpr_uploads_applying` | Resume | Super Admin | Integrity can be guaranteed (OD-UPLOADS) |
| T43 | `cpr_paused_verify_failed` | `cpr_post_verifying` | Resume | Super Admin | Idempotent re-verify only when supported; else Rollback |

### 5.6 Pause → Rollback (Super Admin only; never automatic)

| # | From | To | Trigger | Actor | Guards |
|---|------|-----|---------|-------|--------|
| T50 | `cpr_paused_delete_failed` | `cpr_rolling_back` | Dashboard Rollback | Super Admin | OD-ROLLBACK: paused on failure; re-auth + phrase `RESTORE` + permissions + audit; target OD-PIN session backup |
| T51 | `cpr_paused_import_failed` | `cpr_rolling_back` | Dashboard Rollback | Super Admin | Same |
| T52 | `cpr_paused_uploads_failed` | `cpr_rolling_back` | Dashboard Rollback | Super Admin | Same |
| T53 | `cpr_paused_verify_failed` | `cpr_rolling_back` | Dashboard Rollback | Super Admin | Same |
| T54 | `cpr_rolling_back` | `cpr_rollback_completed` | Rollback verify OK | System | Maint remains ON |
| T55 | `cpr_rolling_back` | `cpr_paused_verify_failed` | Rollback worker fail / needs decision | System / Super Admin | **Still no auto path out**; Super Admin must Retry Rollback (re-enter `cpr_rolling_back`) — model retry as T50–T53-equivalent from a `cpr_paused_rollback_failed` **or** re-issue Rollback from `cpr_rolling_back` only by Super Admin. **Defined:** add pause `cpr_paused_rollback_failed` (P,X,M). |
| T56 | `cpr_paused_rollback_failed` | `cpr_rolling_back` | Retry Rollback | Super Admin | Same security as OD-ROLLBACK |
| T57 | `cpr_rollback_completed` | `cpr_maintenance_released` | Release GLOBAL Maint | Super Admin | OD-RUNBOOK completed; OD-PERM |

### 5.7 Optional umbrella terminal (restricted)

| # | From | To | Trigger | Actor | Guards |
|---|------|-----|---------|-------|--------|
| T60 | Any `cpr_paused_*` (post-PONR) | `cpr_failed_post_ponr` | Super Admin **incident close** without completing Rollback/success | Super Admin | Documented incident; **Maint stays ON**; **does not** unlock post-PONR locks automatically; **does not** Rollback; recovery continues via disaster/manual procedure — **not** a success path |
| T61 | `cpr_failed_post_ponr` | `cpr_rolling_back` | Super Admin later chooses Rollback | Super Admin | OD-ROLLBACK controls |
| T62 | `cpr_failed_post_ponr` | `cpr_maintenance_released` | Only after platform declared safe by Super Admin + Runbook/incident closeout | Super Admin | Exceptional; must not skip OD-PIN recovery expectations |

---

## 6. Explicitly illegal transitions (reject)

Any transition not listed in §5 is illegal (`illegal_cpr_status_transition`). The following are **called out** because they would violate OWNER_APPROVED policy:

| Illegal pattern | Why forbidden |
|-----------------|---------------|
| `cpr_deleting` / `cpr_importing` / … → `cpr_rolling_back` **without** Super Admin Rollback action | Auto-rollback forbidden (OD-FAIL-*; OD-ROLLBACK) |
| `cpr_deleting` / … → `cpr_maintenance_released` | Maint release while incomplete forbidden |
| Any `cpr_paused_*` → `cpr_succeeded` | Cannot succeed from failure pause without Resume path + verify PASS |
| `cpr_paused_*` → `cpr_maintenance_released` | Users must not regain access while incomplete (OD-FAIL-*; Maintenance State) |
| Any state → unlock post-PONR lock automatically on timeout/crash | OD-LOCK-TTL |
| Country Admin → Resume / Rollback / maint release / PONR execute | OD-PERM |
| Timeout signal alone → `cpr_failed_*` or `cpr_rolling_back` | OD-TIMEOUT |
| `cpr_pre_ponr` → `cpr_importing` (skip delete) | Pipeline integrity |
| `cpr_anchor_pinning` fail → `cpr_deleting` | OD-PIN: never PONR without new pinned Full Backup |
| Reuse terminal job → any active state | WP-P1-02: new job required |
| WF-B `cpr_pending` → `cpr_deleting` without Super Admin approval/freeze/maint/pin/pre_ponr | OD-DUAL |

---

## 7. WF-A vs WF-B entry differences

| Topic | Workflow A | Workflow B |
|-------|------------|------------|
| Who creates `cpr_pending` | Super Admin | Country Admin (request) |
| `cpr_awaiting_approvals` | Optional/skip (T03) | **Required** until Super Admin approves (T02→T04) |
| Who may enter `cpr_pre_ponr` → `cpr_deleting` | Super Admin only | Super Admin only |
| Country Admin after request | No execute/Resume/Rollback | No execute/Resume/Rollback |

---

## 8. Emergency stop (Architecture §28)

| Phase | Transition |
|-------|------------|
| Pre-PONR | → `cpr_cancelled_pre_ponr` (T22/T24) |
| Post-PONR | Cooperative halt → appropriate `cpr_paused_*` (T34); Maint ON; Super Admin Resume or Rollback |

---

## 9. Maintenance & lock invariants by state

| State class | GLOBAL Maint | Post-PONR lock auto-release |
|-------------|--------------|------------------------------|
| Pre-PONR active before `cpr_maintenance_on` | OFF (unless already ON from prior) | N/A |
| From `cpr_maintenance_on` through pause/success/rollback_completed | **ON** | **Forbidden** |
| `cpr_maintenance_released` | OFF | Released only with Super Admin maint release + lock release procedure (pre-PONR stale clear rules in WP-P1-05; post-PONR never auto) |

---

## 10. CSV — compact transition list

```csv
id,from_state,to_state,trigger,actor,auto_rollback,post_ponr_auto_unlock
T01,cpr_pending,cpr_gates_validating,start_validation,system_or_super_admin,no,no
T02,cpr_gates_validating,cpr_awaiting_approvals,gates_ok_wfb,system,no,no
T03,cpr_gates_validating,cpr_contract_frozen,gates_ok_wfa_freeze,system_or_super_admin,no,no
T04,cpr_awaiting_approvals,cpr_contract_frozen,super_admin_approve,super_admin,no,no
T05,cpr_awaiting_approvals,cpr_failed_pre_ponr,reject_or_cancel,super_admin_or_system,no,no
T06,cpr_contract_frozen,cpr_maintenance_on,enter_global_maint,super_admin_or_system,no,no
T07,cpr_maintenance_on,cpr_anchor_pinning,start_od_pin_backup,system,no,no
T08,cpr_anchor_pinning,cpr_pre_ponr,pin_verified,system,no,no
T09,cpr_pre_ponr,cpr_deleting,authorize_ponr_start_delete,super_admin,no,no
T10,cpr_deleting,cpr_importing,delete_complete,system,no,no
T11,cpr_importing,cpr_uploads_applying,import_complete,system,no,no
T12,cpr_uploads_applying,cpr_post_verifying,uploads_complete,system,no,no
T13,cpr_post_verifying,cpr_succeeded,verify_pass,system,no,no
T14,cpr_succeeded,cpr_maintenance_released,super_admin_release_maint,super_admin,no,no
T20,cpr_gates_validating,cpr_failed_pre_ponr,gate_fail,system,no,no
T21,cpr_contract_frozen,cpr_failed_pre_ponr,pre_ponr_fail,system,no,no
T22,cpr_maintenance_on,cpr_cancelled_pre_ponr,cancel_or_estop,super_admin,no,no
T23,cpr_anchor_pinning,cpr_failed_pre_ponr,pin_fail,system,no,no
T24,cpr_pre_ponr,cpr_cancelled_pre_ponr,cancel_or_estop,super_admin_or_system,no,no
T25,cpr_failed_pre_ponr,cpr_maintenance_released,release_maint,super_admin,no,no
T26,cpr_cancelled_pre_ponr,cpr_maintenance_released,release_maint,super_admin,no,no
T30,cpr_deleting,cpr_paused_delete_failed,delete_fail,system,no,no
T31,cpr_importing,cpr_paused_import_failed,import_fail,system,no,no
T32,cpr_uploads_applying,cpr_paused_uploads_failed,uploads_fail,system,no,no
T33,cpr_post_verifying,cpr_paused_verify_failed,verify_fail,system,no,no
T40,cpr_paused_delete_failed,cpr_deleting,resume,super_admin,no,no
T41,cpr_paused_import_failed,cpr_importing,resume,super_admin,no,no
T42,cpr_paused_uploads_failed,cpr_uploads_applying,resume,super_admin,no,no
T43,cpr_paused_verify_failed,cpr_post_verifying,resume,super_admin,no,no
T50,cpr_paused_delete_failed,cpr_rolling_back,rollback_action,super_admin,no,no
T51,cpr_paused_import_failed,cpr_rolling_back,rollback_action,super_admin,no,no
T52,cpr_paused_uploads_failed,cpr_rolling_back,rollback_action,super_admin,no,no
T53,cpr_paused_verify_failed,cpr_rolling_back,rollback_action,super_admin,no,no
T54,cpr_rolling_back,cpr_rollback_completed,rollback_ok,system,no,no
T55,cpr_rolling_back,cpr_paused_rollback_failed,rollback_fail,system,no,no
T56,cpr_paused_rollback_failed,cpr_rolling_back,retry_rollback,super_admin,no,no
T57,cpr_rollback_completed,cpr_maintenance_released,release_maint,super_admin,no,no
T60,cpr_paused_*,cpr_failed_post_ponr,incident_close,super_admin,no,no
T61,cpr_failed_post_ponr,cpr_rolling_back,rollback_action,super_admin,no,no
T62,cpr_failed_post_ponr,cpr_maintenance_released,release_maint_exceptional,super_admin,no,no
```

---

## 11. Register citation table

| Rule | OD / Principle | Register anchor | Architecture § |
|------|----------------|-----------------|----------------|
| No auto-rollback; pause on delete fail | OD-FAIL-DELETE | §15 Frozen | §12 |
| No auto-rollback; pause on import fail | OD-FAIL-IMPORT | §15 Frozen | §12 |
| Rollback dashboard; only when paused; never automatic | OD-ROLLBACK | §15 Frozen | §11, §13 |
| Resume = safe stage continuation | OD-FAIL-*; Arch clarification | OD-FAIL-* §15 | §13 |
| No post-PONR auto unlock | OD-LOCK-TTL | §15 Frozen | §15, §13 |
| Maint GLOBAL; stays on failure | OD-MAINT; OD-MAINT-SCOPE; Maintenance State | §15 Frozen | §9, §12 |
| Maint release Super Admin; Runbook gate | OD-PERM; OD-RUNBOOK | §15 Frozen | §7 A7, §25 |
| Timeout ≠ failure transition | OD-TIMEOUT | §15 Frozen | §29 |
| WF-A/B authority | OD-DUAL | §15 Frozen | §7–§8 |
| Country Admin cannot execute/Resume/Rollback | OD-PERM | §15 Frozen | §27 |
| C8 SAFE before PONR | OD-C8 | §15 Frozen | §6, §37 |
| Pin before continue | OD-PIN | §15 Frozen | §6 |
| Uploads fail → pause Resume/Rollback | OD-UPLOADS | §15 Frozen | §10.2, §12 |
| Verify fail → pause | OD-VERIFY-WARN | §15 Frozen | §12, §19 |
| Emergency stop | (baseline) | — | §28 |

---

## 12. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| No transition implies automatic Rollback | **PASS** — R1; §5.4–5.6; §6 illegal table; CSV `auto_rollback=no` |
| No post-PONR auto-unlock | **PASS** — R2; §9; CSV `post_ponr_auto_unlock=no` |
| Global Maint release only via Super Admin after Runbook | **PASS** — T14, T25, T26, T57, T62; R3 |
| Every fail path lands in pause or terminal per ODs | **PASS** — T20–T24 pre-PONR terminals; T30–T34 pauses; T50–T57 Rollback path |

Additional:

| Check | Result |
|-------|--------|
| WF-A / WF-B entry differences | **PASS** — §7 |
| Emergency stop | **PASS** — §8 |
| OD-TIMEOUT cannot alone fail/rollback | **PASS** — R5 |
| State list covers Arch §17 + OD-FAIL pauses | **PASS** — §3 |

---

## 13. Assumptions

1. `cpr_anchor_pinning` and `cpr_paused_*` / `cpr_paused_rollback_failed` are explicit design states refining Architecture §6/§12/§17 without changing OWNER_APPROVED policy.  
2. Detailed Resume eligibility predicates (when “stage safely supports”) are expanded in WP-P1-09; this matrix only names legal edges.  
3. Lock file mechanics are WP-P1-05; this matrix only forbids auto-unlock transitions.  
4. `cpr_failed_post_ponr` umbrella is restricted (§3.2); normal OD-FAIL path is pause.  

---

## 14. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Hidden auto-rollback in worker | High | Illegal list §6; acceptance R1 |
| Using `cpr_failed_post_ponr` to skip pause | High | §3.2 note; prefer pause |
| Timeout implemented as hard fail transition | Medium | R5 |
| Country Admin Resume UI | High | R6; actor column |

No architectural insufficiency requiring escalation. No changes to WP-P1-01/02 except index status update.

---

## 15. Out of scope

- Checkpoint file schemas (WP-P1-04)  
- Lock formats (WP-P1-05)  
- PHP state machine implementation  

---

*End of WP-P1-03. STOP — do not begin WP-P1-04 until Owner review and approval.*
