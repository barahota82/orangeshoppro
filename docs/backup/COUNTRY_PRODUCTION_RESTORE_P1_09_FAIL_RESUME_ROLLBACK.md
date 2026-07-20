# Country Production Restore — P1 Failure, Resume & Rollback Decision Contracts

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-09** — Failure, Resume & Rollback Decision Contracts |
| **Artifact-ID** | `CPR-P1-WP09-FAIL_RESUME_ROLLBACK` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-FAIL-DELETE · OD-FAIL-IMPORT · OD-ROLLBACK · OD-PIN; OD-UPLOADS · OD-VERIFY-WARN · Maintenance State) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §11–§13, §31–§33 |
| **Depends on** | WP-P1-03 (pause/Resume/Rollback transitions) · WP-P1-04 · WP-P1-06 (authority/phrase) · WP-P1-07 (Maint State) |
| **Coding** | **No** Resume/Rollback worker PHP in this WP |

---

## 1. Purpose

Specify **fail-pause** event contracts, **Resume eligibility** (safe stage continuation only), and **Rollback authorization** so implementation cannot invent automatic rollback, statement-offset SQL resume, Country Admin recovery actions, or success-with-warnings paths.

This WP does **not** modify Architecture, Owner Decisions, or prior P1 artifacts.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | **No automatic Rollback** under any failure, timeout, crash, or pause | OD-ROLLBACK · OD-FAIL-* |
| H2 | **No statement-offset / SQL byte-offset resume** into a half-applied country slice | OD-FAIL-IMPORT · Architecture §13, §31 |
| H3 | Resume = **safe stage continuation** only, when the stage **safely supports** it; Super Admin authorized | OD-FAIL-* · Architecture §13 |
| H4 | Rollback = dedicated Super Admin **dashboard** action; **never** Country Admin | OD-ROLLBACK · OD-PERM |
| H5 | Rollback UI/action available **only** when session is **paused because of failure** | OD-ROLLBACK |
| H6 | Rollback targets the **current session** Full Backup (OD-PIN) — never an arbitrary older backup | OD-ROLLBACK · OD-PIN |
| H7 | Rollback requires **same security controls** as Production Restore execute: re-auth, phrase `RESTORE`, permissions, complete audit + execution logging | OD-ROLLBACK · OD-PHRASE |
| H8 | On fail-pause: GLOBAL Maint stays **ON**; users must not regain normal access while incomplete | OD-FAIL-* · Maintenance State |
| H9 | Fail-closed: no success-with-warnings; no ignore verification; no best-effort continue | OD-VERIFY-WARN · Integrity |
| H10 | Country Admin can **never** Resume or Rollback | OD-PERM · WP-P1-06 |

---

## 3. Failure classes & pause landing states

Aligned with WP-P1-03 §5.4 and Architecture §12.

| Failure class | OD / Policy | From state(s) | Pause state | Auto-Rollback? |
|---------------|-------------|---------------|-------------|----------------|
| Delete-phase fail | OD-FAIL-DELETE | `cpr_deleting` | `cpr_paused_delete_failed` | **No** |
| Import-phase fail | OD-FAIL-IMPORT | `cpr_importing` | `cpr_paused_import_failed` | **No** |
| Uploads fail | OD-UPLOADS | `cpr_uploads_applying` | `cpr_paused_uploads_failed` | **No** |
| Post-verify FAIL | OD-VERIFY-WARN | `cpr_post_verifying` | `cpr_paused_verify_failed` | **No** |
| Emergency stop post-PONR | Architecture §28 | Active post-PONR stage | Matching `cpr_paused_*` | **No** |
| Rollback worker fail | OD-ROLLBACK | `cpr_rolling_back` | `cpr_paused_rollback_failed` | **No** |
| Pre-PONR gate/authority fail | WP-P1-08 / OD-DUAL | Pre-PONR | Terminal pre-PONR (`cpr_failed_pre_ponr` / cancel) — **not** Rollback | N/A |

**Mandatory pause behavior (post-PONR):** preserve restore state; keep Maint ON; surface reason/phase/status; wait for Super Admin Resume or Rollback.

---

## 4. Failure event contracts

### 4.1 Common envelope (`cpr_failure_event`)

Path (design):  
`{workRoot}/country_production/{job_id}/failures/failure_{uuid}.json`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_failure_event/1"` |
| `event_id` | Y | UUID |
| `job_id` | Y | |
| `country_id` | Y | |
| `package_id` | Y | |
| `failure_class` | Y | `delete` \| `import` \| `uploads` \| `verify` \| `emergency_stop` \| `rollback_worker` \| `other_post_ponr` |
| `od_binding` | Y | e.g. `OD-FAIL-DELETE` |
| `from_state` | Y | WP-P1-03 state before pause |
| `pause_state` | Y | Target `cpr_paused_*` |
| `ponr_crossed` | Y | Must be `true` for post-PONR pause classes |
| `failed_at` | Y | ISO-8601 |
| `failure_reason` | Y | Human + machine code |
| `failure_code` | Y | Stable code |
| `completed_phase` | Y | Phase/stage name at failure |
| `execution_status` | Y | Free-form status summary |
| `progress_percent` | Y* | Required for import; recommended others |
| `completed_batches` | Y* | Required for import |
| `dirty` | Y | `true` after PONR partial mutation |
| `session_full_backup_id` | Y | OD-PIN anchor id (must exist) |
| `session_full_backup_pinned` | Y | Must be `true` |
| `maint_global_on` | Y | Must be `true` |
| `auto_rollback_executed` | Y | **Must be `false`** |
| `statement_offset_resume_attempted` | Y | **Must be `false`** |
| `audit_record_id` | Y | |
| `last_checkpoint_id` | Y | Last committed CP* |
| `evidence_refs` | N | Logs, batch ids |

### 4.2 OD-FAIL-DELETE payload extras

| Field | Required | Notes |
|-------|:--------:|-------|
| `delete_order_position` | Y | Last successful / failed membership batch marker (not a resume SQL offset) |
| `tables_deleted_count` | Y | |
| `resume_mode_if_eligible` | Y | `finish_safe_delete` \| `none` — system hint only; SA decides |

Frozen surface requirements: failure reason, completed phase, execution status; Maint ON; no auto-rollback.

### 4.3 OD-FAIL-IMPORT payload extras

| Field | Required | Notes |
|-------|:--------:|-------|
| `progress_percent` | Y | |
| `completed_batches` | Y | |
| `current_stage` | Y | Import stage label |
| `batch_id_failed` | Y | |
| `resume_mode_if_eligible` | Y | `re_clear_target_slice_and_reimport` \| `none` |
| `sql_byte_offset` | Y | **Must be null / omitted for resume authority** — may be logged for forensics only under `forensic_sql_offset` and **must not** drive Resume |

Frozen surface requirements: progress %, completed batches, failure reason, current stage; Maint ON; no auto-rollback.

### 4.4 Uploads / verify pause extras

| Class | Required fields |
|-------|-----------------|
| Uploads | `uploads_scope_id`, `pre_image_manifest_ref` (if any), `integrity_guaranteed = false` at fail |
| Verify | `verify_pillar_failed` (accounting/ownership/fifo/stock/schema/survivor/global), `waiver_forbidden = true` |

### 4.5 Forbidden failure-handler behaviors

| Behavior | Status |
|----------|--------|
| Auto transition to `cpr_rolling_back` | **Forbidden** |
| Auto release Maint | **Forbidden** |
| Mark `cpr_succeeded` from pause | **Forbidden** |
| Blind continue import from statement offset | **Forbidden** |
| Country Admin Resume/Rollback | **Forbidden** |
| Success with warnings | **Forbidden** |

---

## 5. Resume eligibility rules

### 5.1 Definition

**Resume** = Super Admin–authorized **safe stage continuation**.  
It is **not** blind SQL statement-offset resume into a half-applied country slice (Architecture §13).

### 5.2 Actor & preconditions

| Rule | Value |
|------|-------|
| Actor | **Super Admin alone** |
| Country Admin | **Denied** (`cpr_perm_denied_resume`) |
| Job state | Must be a `cpr_paused_*` failure pause (or pre-PONR safe restart paths below) |
| Maint | Remains ON |
| Contract | Unchanged fingerprints (WP-P1-02); drift → Resume denied → Rollback or abort path |
| Enablement | Still required true for production mutation continuation (WP-P1-08 G01) |

### 5.3 Eligibility matrix (post-PONR)

| Pause state | Resume eligible when | Allowed continuation | Forbidden |
|-------------|----------------------|----------------------|-----------|
| `cpr_paused_delete_failed` | Stage **safely supports** finishing delete (deterministic remaining delete_order work) | Return to `cpr_deleting` (T40); finish safe delete only | Invent partial undelete; auto-rollback |
| `cpr_paused_import_failed` | Super Admin authorizes **re-clear target slice + re-import from contract** (or other documented safe mode) | Return to `cpr_importing` (T41) after safe re-clear | **Any** SQL byte/statement-offset resume |
| `cpr_paused_uploads_failed` | Upload integrity **can be guaranteed** for scoped apply | Return to `cpr_uploads_applying` (T42) | Best-effort / partial accept (OD-UPLOADS) |
| `cpr_paused_verify_failed` | Re-verify is idempotent reads **and** stage supports retry | Return to `cpr_post_verifying` (T43) | Waiver; ignore failed pillar |
| `cpr_paused_rollback_failed` | N/A for “Resume restore” — use **Retry Rollback** (T56) | Re-enter `cpr_rolling_back` | Treat as import Resume |

If `resume_mode_if_eligible = none` or safety proof missing → Resume **DENIED**; Super Admin may only Rollback (or incident close per WP-P1-03 T60 — not a success path).

### 5.4 Pre-PONR / crash “resume” (not OD-FAIL pause)

| Situation | Policy |
|-----------|--------|
| Crash before CP-A | Restart prep; production untouched — not Rollback |
| Crash after Maint ON, before PONR | Re-run pre-PONR checks; safe |
| These paths | Must not expose OD-ROLLBACK action (not paused-on-failure post-PONR) |

### 5.5 Resume authorization schema (`cpr_resume_authorization`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `resume_id` | Y | UUID |
| `job_id` | Y | |
| `authorized_by_admin_id` | Y | Super Admin |
| `authorized_at` | Y | |
| `from_pause_state` | Y | |
| `to_state` | Y | e.g. `cpr_importing` |
| `resume_mode` | Y | Enum from §5.3 |
| `safe_continuation_proof` | Y | Evidence why stage supports Resume |
| `forbids_statement_offset` | Y | Must be `true` |
| `contract_fingerprint` | Y | Must match frozen |
| `failure_event_id` | Y | Links §4 event |
| `audit_record_id` | Y | |

**Reject Resume if** `resume_mode` implies statement-offset or `safe_continuation_proof` empty.

### 5.6 Resume decision algorithm

```
RESUME_REQUEST(job, admin, mode):
  1. Assert admin is Super Admin
  2. Assert job.state in resumable cpr_paused_*
  3. Assert maint GLOBAL ON
  4. Assert mode in allowed set for that pause state
  5. Assert mode != statement_offset / sql_byte_offset
  6. Assert safe_continuation_proof validates
  7. Assert contract fingerprint unchanged
  8. Write cpr_resume_authorization + audit
  9. Transition per WP-P1-03 T40–T43
  10. Else DENY — leave paused
```

---

## 6. Rollback authorization contract (OD-ROLLBACK)

### 6.1 Visibility & availability

| Rule | Value |
|------|-------|
| Visible to | **Super Admin only** |
| Available when | Session is **paused because of failure** (`cpr_paused_delete_failed` \| `cpr_paused_import_failed` \| `cpr_paused_uploads_failed` \| `cpr_paused_verify_failed` \| `cpr_paused_rollback_failed`; also T61 from `cpr_failed_post_ponr` after incident) |
| Hidden when | Pre-PONR; happy-path executing without pause; `cpr_succeeded`; maint released |
| Country Admin | **Never** visible/accessible |

### 6.2 Target

| Field | Rule |
|-------|------|
| Anchor | `session_full_backup_id` from OD-PIN for **this** `job_id` |
| Must be | Created under Maint for this session; verified; retention pinned |
| Forbidden targets | Any pre-existing backup not the session pin; Country-only inverse delete/import as sole rollback |

Primary mechanism: Full DR rollback primitives/patterns against pinned Full backup (Architecture §11). Scoped upload pre-image is **assist only**, not sufficient alone for DB partial failure.

### 6.3 Security controls (parity with Production Restore execute)

Every Rollback execution **must** satisfy:

1. Super Admin permission validation (OD-PERM)  
2. Password re-authentication  
3. Confirmation phrase exact `RESTORE` (OD-PHRASE)  
4. Complete audit logging  
5. Complete execution logging  
6. Explicit Super Admin decision (no scheduler/auto)  

One-time authorization / challenge record may mirror WP-P1-06 `cpr_auth_challenge` with `purpose = rollback`.

### 6.4 Rollback authorization schema (`cpr_rollback_authorization`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `rollback_id` | Y | UUID |
| `job_id` | Y | |
| `authorized_by_admin_id` | Y | Super Admin |
| `authorized_at` | Y | |
| `from_pause_state` | Y | Must be paused-on-failure class |
| `session_full_backup_id` | Y | OD-PIN target |
| `session_full_backup_fingerprint` | Y | |
| `password_reauth_ok` | Y | `true`; no password stored |
| `phrase_accepted` | Y | `true` only if `RESTORE` |
| `auth_challenge_id` | Y | |
| `permission_check_ok` | Y | `true` |
| `automatic` | Y | **Must be `false`** |
| `country_admin_actor` | Y | **Must be `false`** |
| `failure_event_id` | Y | |
| `audit_record_id` | Y | |
| `execution_log_ref` | Y | |

### 6.5 Rollback decision algorithm

```
ROLLBACK_REQUEST(job, admin):
  1. Assert admin is Super Admin
  2. Assert job.state is paused-on-failure (or T61-eligible)
  3. Assert Rollback action visible/available for that state
  4. Assert session_full_backup_pinned for this job
  5. Verify password re-auth + phrase RESTORE
  6. Write cpr_rollback_authorization (automatic=false)
  7. Transition to cpr_rolling_back (T50–T53 / T56 / T61)
  8. Worker restores from session Full Backup; Maint stays ON
  9. On verify OK → cpr_rollback_completed (T54); Maint still ON until SA release + Runbook
  10. On rollback worker fail → cpr_paused_rollback_failed (T55); still no auto path
```

### 6.6 Critical incident: missing pin

If post-PONR failure and session Full anchor missing/unpinned → **Critical operational incident** (Architecture §11); site stays in Maint until manual disaster procedure; still **no** invented auto-rollback.

---

## 7. Interaction with Maint, locks, checkpoints

| Concern | Rule |
|---------|------|
| Maint on pause | `maint_on_paused_failure` (WP-P1-07); never auto-release |
| Maint after rollback completed | ON until Super Admin release + Runbook (WP-P1-06/07) |
| Post-PONR lock | No auto-unlock (WP-P1-05); heartbeat/procedure only |
| Checkpoints | On pause: do not invent success CPs; retain last good CP (WP-P1-04) |
| Timeout | Must not trigger Rollback (WP-P1-07 / OD-TIMEOUT) |

---

## 8. Binding to WP-P1-03 transitions

| Transition | Contract artifact |
|------------|-------------------|
| T30–T34 → pause | `cpr_failure_event` |
| T40–T43 Resume | `cpr_resume_authorization` |
| T50–T53 / T56 / T61 Rollback | `cpr_rollback_authorization` |
| T54 rollback completed | Execution log + verify evidence |
| T60 incident close | Documented; **does not** Rollback; Maint ON |

Forbidden transitions from WP-P1-03 remain binding (auto-rollback, CA Resume/Rollback, pause → succeeded, pause → maint released).

---

## 9. Register / Architecture citation map

| Contract element | OD / Principle | Frozen wording locus | Architecture |
|------------------|----------------|----------------------|--------------|
| Delete fail-pause; no auto-RB; SA Resume/RB | OD-FAIL-DELETE | §15 Frozen | §12, §13, §31 |
| Import fail-pause; no statement-offset | OD-FAIL-IMPORT | §15 Frozen | §12, §13, §31 |
| Dashboard Rollback; pause-only; SA only; security parity; OD-PIN target | OD-ROLLBACK | §15 Frozen | §11, §13 |
| Session Full Backup target | OD-PIN | §15 Frozen | §11 |
| Uploads fail → Maint + SA only | OD-UPLOADS | §15 Frozen | §12 |
| Verify fail-closed | OD-VERIFY-WARN | §15 Frozen | §12, §31 |
| Maint ON while incomplete | Maintenance State | Register Group 3 | §9, §12 |
| Crash / power recovery | (baseline) | — | §32, §33 |

---

## 10. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Specs forbid auto-rollback | **PASS** — H1; §4.5; §6 |
| Specs forbid statement-offset resume | **PASS** — H2; §5.3; §5.5 |
| Rollback visibility only when paused on failure | **PASS** — H5; §6.1 |
| Security controls listed per OD-ROLLBACK | **PASS** — §6.3–§6.4 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| Failure contracts defined | **PASS** — §4 |
| Resume eligibility rules | **PASS** — §5 |
| Rollback authorization contracts | **PASS** — §6 |
| Resume only when stage safely supports | **PASS** — H3; §5.3 |
| Rollback Super Admin only; OD-PIN target | **PASS** — H4–H6 |
| Country Admin never Resume/Rollback | **PASS** — H10 |
| Fail-closed preserved | **PASS** — H8–H9 |
| Architecture / Register / prior WPs unmodified | **PASS** |

---

## 11. Assumptions

1. Uploads apply algorithm detail is WP-P1-10; this WP only defines fail → pause → Resume/Rollback.  
2. Post-apply verify report schemas are WP-P1-11; pause class `verify` is bound here.  
3. Full DR rollback worker internals are reused as primitives; CPR wraps authorization only.  
4. `forensic_sql_offset` may exist for humans; it must never authorize Resume.

---

## 12. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Auto-rollback on fail | Critical | H1; `auto_rollback_executed` must be false |
| Blind SQL offset resume | Critical | H2; §5.3 import row |
| Rollback shown outside pause | High | §6.1 |
| Country Admin recovery UI | High | H10; WP-P1-06 |
| Missing OD-PIN anchor mid-failure | Critical | §6.6 incident |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 13. Out of scope

- PHP Resume/Rollback workers  
- WP-P1-10 uploads apply algorithm  
- WP-P1-11 verify report field catalogs  

---

*End of WP-P1-09. STOP — do not begin WP-P1-10 until Owner review and approval.*
