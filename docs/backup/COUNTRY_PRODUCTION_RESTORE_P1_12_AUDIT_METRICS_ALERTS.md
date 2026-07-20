# Country Production Restore — P1 Audit, Metrics & Alerting Event Schemas

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-12** — Audit, Metrics & Alerting Event Schemas |
| **Artifact-ID** | `CPR-P1-WP12-AUDIT_METRICS_ALERTS` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-RUNBOOK · OD-LOCK-TTL · OD-BREAK · OD-PHRASE · OD-PERM; related ODs for event subjects) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §20–§24 |
| **Depends on** | WP-P1-03 … WP-P1-11 |
| **Coding** | **No** — schemas/catalog only; no collectors/notifiers implemented |

---

## 1. Purpose

Define **append-only audit event shapes**, **metrics keys/snapshots**, and **alert condition schemas** required by Architecture §20–§24 so coding phases can emit observables without inventing secret-bearing payloads or skipping Super Admin sensitive-action audits.

This WP does **not** modify Architecture, Owner Decisions, or prior P1 artifacts.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Every Super Admin sensitive action **must** generate an audit event | OD-DUAL protections · OD-PERM · Architecture §20 |
| H2 | Audit / lock / alert payloads **must not** store secrets (passwords, tokens, DB credentials, raw phrase if policy prefers hash-only) | Architecture §20, §23; WP-P1-05/06 |
| H3 | Every alert **must** reference the execution contract (`job_id` + contract fingerprint digest / package binding) | WP-P1-02 |
| H4 | Audit stream is **append-only**; no edit/delete of prior events | Architecture §20 |
| H5 | Post-PONR stale heartbeat produces an **alert** — never auto-unlock | OD-LOCK-TTL · WP-P1-05 |
| H6 | Alerts do **not** authorize waiver, auto-rollback, or Maint auto-release | OD-FAIL-* · OD-ROLLBACK · WP-P1-07/09 |
| H7 | Design only — no collector/notifier implementation in this WP | P1 plan |

---

## 3. Storage layout (design)

```text
{workRoot}/country_production/{job_id}/
  audit/
    audit.jsonl                 # append-only (Architecture §20)
  metrics/
    latest_snapshot.json        # current metrics snapshot (atomic replace)
  alerts/
    alert_{iso}_{uuid}.json     # one file per fired alert (immutable)
```

Platform `audit_log()` mirror allowed for significant events (Architecture §20) with same no-secrets rule.

---

## 4. Common envelopes

### 4.1 Audit event envelope (`cpr_audit_event`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_audit_event/1"` |
| `audit_id` | Y | UUID |
| `at` | Y | ISO-8601 UTC |
| `event_type` | Y | From §5 catalog |
| `actor_admin_id` | Y | Integer or `"system"` |
| `actor_role` | Y | `super_admin` \| `country_admin` \| `owner` \| `system` \| `engineering` |
| `job_id` | Y* | Required when job-scoped |
| `country_id` | Y* | When country-scoped |
| `package_id` | Y* | When package-scoped |
| `workflow` | N | `A` \| `B` |
| `contract_fingerprint_digest` | Y* | Required when job has frozen contract |
| `package_fingerprint` | N | When available |
| `result` | Y | `allowed` \| `denied` \| `completed` \| `failed` \| `info` |
| `denial_code` | N | If denied |
| `evidence_refs` | N | Paths/ids (redacted) |
| `details` | N | Event-specific object — **no secrets** |

**Forbidden in any audit field:** password plaintext, session tokens, API keys, DB passwords, full payment payloads, unredacted absolute private paths, raw `RESTORE` phrase if hash stored instead (`phrase_accepted` boolean / `phrase_submitted_hash` only).

### 4.2 Metrics snapshot envelope (`cpr_metrics_snapshot`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_metrics_snapshot/1"` |
| `job_id` | Y | |
| `observed_at` | Y | |
| `contract_fingerprint_digest` | Y | |
| `current_state` | Y | WP-P1-03 state name |
| `metrics` | Y | Map of metric key → value (§6) |

### 4.3 Alert envelope (`cpr_alert_event`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_alert_event/1"` |
| `alert_id` | Y | UUID |
| `alert_type` | Y | From §7 catalog |
| `severity` | Y | `warning` \| `critical` \| `page` |
| `fired_at` | Y | |
| `job_id` | Y | |
| `contract_fingerprint_digest` | Y | **Mandatory contract reference** |
| `package_id` | Y | |
| `package_fingerprint` | Y | |
| `country_id` | Y | |
| `session_full_backup_id` | N | When pin-related |
| `ponr_crossed` | Y | Boolean |
| `current_state` | Y | |
| `summary` | Y | Short operator text |
| `evidence_refs` | Y | |
| `auto_unlock` | Y | **Must be `false`** for lock/stale alerts |
| `auto_rollback` | Y | **Must be `false`** |
| `suggested_actions` | Y | e.g. `investigate`, `super_admin_resume`, `super_admin_rollback` — never `waive` |

---

## 5. Audit event catalog

### 5.1 Required minimum (Owner execution order)

| `event_type` | When | Actor | Notes / OD |
|--------------|------|-------|------------|
| `cpr.approval` | WF-B approve/reject; WF-A protections ack | Super Admin | OD-DUAL |
| `cpr.phrase_restore` | Phrase challenge result | Super Admin | OD-PHRASE — store `phrase_accepted`, optional hash; **never** raw password |
| `cpr.password_reauth` | Password re-auth result | Super Admin | OD-PHRASE — `password_reauth_ok` only; **never** password |
| `cpr.maint_on` | GLOBAL Maint entered / proven | Super Admin / system | OD-MAINT · WP-P1-07 |
| `cpr.maint_off` | GLOBAL Maint released | Super Admin | OD-PERM · OD-RUNBOOK gate |
| `cpr.session_full_backup` | Create / verify stages of session Full Backup | System / Super Admin orchestration | OD-PIN |
| `cpr.session_full_backup_verify` | Backup integrity verify result | System | OD-PIN |
| `cpr.session_full_backup_pin` | Pin success/fail | System | OD-PIN |
| `cpr.ponr_reached` | First DELETE success or first uploads replace | System | Architecture §10.3 |
| `cpr.pause` | Enter any `cpr_paused_*` | System | OD-FAIL-* · OD-UPLOADS · OD-VERIFY-WARN |
| `cpr.resume` | Resume authorized | Super Admin | WP-P1-09 |
| `cpr.rollback` | Rollback authorized / start / end | Super Admin / system | OD-ROLLBACK |
| `cpr.break_glass` | Break Glass opened | Super Admin | OD-BREAK — reason + notification_ref; non-bypass ack |
| `cpr.lock_manual_clear` | Pre-PONR stale lock clear | Super Admin | OD-LOCK-TTL · WP-P1-05 |
| `cpr.enable` | CPR enablement flag set true (operational) | Super Admin | OD-PERM · OD-ENABLE preconditions |
| `cpr.disable` | CPR enablement flag set false | Super Admin | OD-PERM |

### 5.2 Additional Architecture §20 / prior-WP sensitive events (also required)

| `event_type` | When |
|--------------|------|
| `cpr.job_create` / `cpr.job_cancel` | Job lifecycle |
| `cpr.contract_freeze` | Contract freeze / pin amend |
| `cpr.lock_acquire` / `cpr.lock_release` | CPR lock (normal release paths only) |
| `cpr.checkpoint_write` | Each CP* / runbook evidence commit |
| `cpr.phase_start` / `cpr.phase_end` | Delete/import/uploads/verify |
| `cpr.verify_result` | Post-apply verify PASS/FAIL codes (WP-P1-11) |
| `cpr.emergency_stop` | Emergency stop set |
| `cpr.enablement_flag_read` | Flag read / deny when false |
| `cpr.runbook_complete` | OD-RUNBOOK checklist completed |
| `cpr.auth_challenge` | Combined re-auth+phrase record link |
| `cpr.permission_denied` | Denied sensitive action (incl. Country Admin attempts) |

### 5.3 Super Admin sensitive-action coverage matrix

Every row **must** emit §5.1/§5.2 audit:

| Sensitive action | Audit `event_type` |
|------------------|-------------------|
| Approve / reject WF-B | `cpr.approval` |
| Execute / PONR authorize | `cpr.auth_challenge` + `cpr.phrase_restore` + `cpr.password_reauth` + phase events |
| Maint ON / OFF | `cpr.maint_on` / `cpr.maint_off` |
| Runbook complete | `cpr.runbook_complete` |
| Resume | `cpr.resume` |
| Rollback | `cpr.rollback` |
| Emergency stop | `cpr.emergency_stop` |
| Break Glass | `cpr.break_glass` |
| Manual lock clear | `cpr.lock_manual_clear` |
| Enable / Disable CPR | `cpr.enable` / `cpr.disable` |
| Pin path orchestration | `cpr.session_full_backup*` |

Missing audit for any row → **implementation defect** (coding phase reject).

### 5.4 Event-specific `details` (non-secret)

| Event | Required details (min) |
|-------|------------------------|
| `cpr.approval` | `approval_id`, `decision` (`approved`/`rejected`), `approval_fingerprint` |
| `cpr.phrase_restore` | `challenge_id`, `phrase_accepted`, `phrase_submitted_hash?` |
| `cpr.password_reauth` | `challenge_id`, `password_reauth_ok` |
| `cpr.maint_on` | `maint_scope=GLOBAL`, `write_block_proven`, `proof_ref` |
| `cpr.maint_off` | `release_id`, `runbook_completed=true`, `prior_terminal` |
| `cpr.session_full_backup*` | `session_full_backup_id`, `reused_existing_backup=false`, `pinned?` |
| `cpr.ponr_reached` | `ponr_trigger` (`delete`\|`uploads`), `checkpoint_ref` |
| `cpr.pause` | `pause_state`, `failure_event_id`, `failure_class` |
| `cpr.resume` | `resume_id`, `resume_mode`, `forbids_statement_offset=true` |
| `cpr.rollback` | `rollback_id`, `session_full_backup_id`, `automatic=false` |
| `cpr.break_glass` | `emergency_reason`, `notification_ref`, `non_bypass_ack=true` |
| `cpr.lock_manual_clear` | `prior_lock_sha256`, `ponr_crossed_observed=false`, `reason` |
| `cpr.enable` / `cpr.disable` | `flag_value`, `owner_order_ref?`, `cert_pass_ref?` |

---

## 6. Metrics schemas

### 6.1 Required operational metrics (Owner list + Architecture §22)

| Metric key | Type | Meaning |
|------------|------|---------|
| `cpr_progress_percent` | gauge 0–100 | Overall job progress |
| `cpr_current_state` | label/info | WP-P1-03 state (also in snapshot field) |
| `cpr_phase_duration_seconds{phase}` | gauge/histogram | Duration in current/named phase |
| `cpr_job_elapsed_seconds` | gauge | Wall elapsed (monitoring only — OD-TIMEOUT) |
| `cpr_estimated_duration_seconds` | gauge | From WP-P1-07 estimate |
| `cpr_heartbeat_age_seconds` | gauge | Now − lock `heartbeat_at` |
| `cpr_checkpoint_progress` | gauge | Count of committed CP* / required |
| `cpr_checkpoints_committed_total` | counter | CP commits |
| `cpr_verification_progress_percent` | gauge | Post-apply verify progress |
| `cpr_verify_checks_passed` / `failed` | gauges | WP-P1-11 |
| `cpr_rollback_progress_percent` | gauge | Rollback worker progress |
| `cpr_rows_deleted` / `cpr_rows_inserted` | counters | Architecture §22 |
| `cpr_survivor_witness_drift` | gauge | **Should be 0** |
| `cpr_global_witness_drift` | gauge | **Should be 0** |
| `cpr_time_in_maintenance_seconds` | gauge | Business impact |
| `cpr_jobs_total{result}` | counter | success/fail/rollback/cancel |
| `cpr_emergency_stops_total` | counter | Incidents |
| `cpr_lock_held` | gauge 0/1 | CPR lock present |
| `cpr_ponr_crossed` | gauge 0/1 | PONR flag |
| `cpr_session_pin_present` | gauge 0/1 | OD-PIN present |

### 6.2 Snapshot rules

1. `latest_snapshot.json` updated via atomic replace (WP-P1-04 style).  
2. Metrics must not include secrets.  
3. `cpr_heartbeat_age_seconds` is for monitoring/alerts — **not** auto-unlock.  
4. Duration past estimate increments alert ladder (WP-P1-07) — **not** auto-fail.

---

## 7. Alert catalog

### 7.1 Required alerts (Owner execution order)

| `alert_type` | Severity | Fire when | Contract ref | Must not |
|--------------|----------|-----------|--------------|----------|
| `cpr.alert.post_ponr_failure` | page/critical | Enter post-PONR `cpr_paused_*` / verify FAIL | Y | Auto-rollback |
| `cpr.alert.post_ponr_stale_heartbeat` | page/critical | `ponr_crossed` and heartbeat stale (WP-P1-05/07) | Y | Auto-unlock |
| `cpr.alert.missing_checkpoint` | critical | Required CP missing for current state / DAG break | Y | Skip ahead |
| `cpr.alert.missing_pin` | page/critical | Pin missing at CP1 / pre-PONR when required; or post-PONR pin absent | Y | Invent backup |
| `cpr.alert.witness_drift` | page/critical | Survivor/Global witness drift ≠ 0 | Y | Success-with-warnings |
| `cpr.alert.lock_conflict` | critical | CPR blocked by Full DR / C6 / foreign CPR lock | Y | Bypass exclusion |
| `cpr.alert.verification_failure` | page/critical | WP-P1-11 overall FAIL | Y | Waiver |
| `cpr.alert.emergency_stop` | page | Emergency stop set | Y | Silent continue |

### 7.2 Additional Architecture §24 alerts (also required)

| `alert_type` | Severity | Fire when | Notes |
|--------------|----------|-----------|-------|
| `cpr.alert.rollback_failure` | page | Rollback worker fail → `cpr_paused_rollback_failed` | Still no auto path |
| `cpr.alert.duration_warning` | warning | Elapsed ≥ Estimated Duration with/without progress | OD-MAINT-MAX / OD-TIMEOUT — **monitoring**; not hard max abort |
| `cpr.alert.duration_critical` | critical | Elapsed ≥ critical×estimate or recovery investigation | WP-P1-07 ladder; **not** auto-fail |
| `cpr.alert.break_glass` | critical | Break Glass opened | OD-BREAK notification pairing |

**Clarification (OWNER_APPROVED vs Architecture wording):** OD-MAINT-MAX forbids a fixed maximum duration. Architecture §24 “Maint ON longer than OD-MAINT-MAX” is implemented as **duration_warning / duration_critical** against the **automatic Estimated Duration** (monitoring only), not as a hardcoded abort.

### 7.3 Alert → suggested actions (normative)

| Alert | Allowed suggestions |
|-------|---------------------|
| Post-PONR failure / verify failure | Super Admin Resume (if safe) or Rollback |
| Post-PONR stale heartbeat | Super Admin procedure only — **no** auto lock release |
| Missing pin | Halt PONR / incident — create/verify/pin path or disaster procedure |
| Witness drift | Fail-closed verify path; Resume/Rollback only |
| Lock conflict | Wait / refuse second feature — no bypass |
| Emergency stop | Super Admin decision per WP-P1-03 |

---

## 8. Emission rules (normative)

```
ON sensitive_action:
  write cpr_audit_event to audit.jsonl (append)
  optionally mirror audit_log()

ON metrics_tick / phase_change / heartbeat:
  update metrics snapshot (atomic)

ON alert_condition:
  assert contract_fingerprint_digest present
  write cpr_alert_event (immutable)
  notify channels (coding phase)
  NEVER set auto_unlock/auto_rollback true
```

Denied Country Admin Resume/Rollback/execute attempts → `cpr.permission_denied` audit (required).

---

## 9. Binding to prior WPs

| WP | Observables consumed/emitted |
|----|------------------------------|
| WP-P1-02 | Contract digest on every alert/audit job event |
| WP-P1-03 | `current_state`, pause/resume/rollback transitions |
| WP-P1-04 | Checkpoint progress / missing checkpoint alert |
| WP-P1-05 | Heartbeat age; manual lock clear audit; post-PONR stale alert |
| WP-P1-06 | Phrase/re-auth/Break Glass/Runbook/enable-disable audits |
| WP-P1-07 | Duration metrics/alerts; maint on/off |
| WP-P1-08 | Enablement flag read denies |
| WP-P1-09 | Pause/Resume/Rollback audits |
| WP-P1-10 | Uploads phase + integrity fail → pause alert |
| WP-P1-11 | Verify result audit + verification_failure alert |

---

## 10. Register / Architecture citation map

| Element | OD / Principle | Architecture |
|---------|----------------|--------------|
| Audit stream minimum set | (baseline) · OD-RUNBOOK | §20 |
| Metrics keys | (baseline) | §21–§22 |
| Logging no secrets | (baseline) | §23 |
| Alert conditions | OD-LOCK-TTL · OD-BREAK · OD-VERIFY-WARN | §24 |
| Phrase/re-auth audit | OD-PHRASE | §8, §20 |
| Manual unlock audit | OD-LOCK-TTL | §13, §15 |
| Break Glass audit + notify | OD-BREAK | §8.2 |
| No auto-unlock on stale alert | OD-LOCK-TTL | §15 |

---

## 11. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Every Super Admin sensitive action has an audit event shape | **PASS** — §5.3 |
| Post-PONR stale lock alert exists | **PASS** — `cpr.alert.post_ponr_stale_heartbeat` §7.1 |
| No secret fields in lock/audit payloads | **PASS** — H2; §4.1 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| Audit events listed (approval, phrase, re-auth, maint on/off, backup verify/pin, PONR, pause, resume, rollback, break glass, lock clear, enable/disable) | **PASS** — §5.1 |
| Metrics: progress, state, duration, heartbeat, checkpoint/verify/rollback progress | **PASS** — §6 |
| Alerts: post-PONR failure/stale HB, missing CP/pin, witness drift, lock conflict, verify fail, emergency stop | **PASS** — §7.1 |
| Every alert references execution contract | **PASS** — H3; §4.3 |
| Design only / no code | **PASS** |
| Architecture / Register / prior WPs unmodified | **PASS** |

---

## 12. Assumptions

1. Notification transport (email/SMS/Pager) is coding-phase; this WP defines alert records only.  
2. Metric backend (Prometheus/etc.) is optional; snapshot JSON is the design SoT.  
3. Architecture §24 duration alert is interpreted via OD-MAINT-MAX automatic estimate (WP-P1-07), not a fixed Owner max.

---

## 13. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Missing Break Glass / lock-clear audit fields | High | §5.1 / §5.4 required details |
| Stale HB alert triggers unlock | Critical | H5; `auto_unlock=false` |
| Secrets in audit.jsonl | Critical | H2; forbidden list §4.1 |
| Alert without contract binding | High | H3; required fields |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 14. Out of scope

- Collector/notifier PHP  
- WP-P1-13 enablement/cert hooks  
- External SIEM integration  

---

*End of WP-P1-12. STOP — do not begin WP-P1-13 until Owner review and approval.*
