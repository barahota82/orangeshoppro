# Country Production Restore Architecture (Phase P0)

| Field | Value |
|-------|--------|
| **Status** | **ARCHITECTURE ONLY** — no implementation, no PHP, no SQL, no CLI/HTTP/UI |
| **Phase** | P0 — Country Production Restore Architecture |
| **Date** | 2026-07-20 |
| **Nature** | **New project.** Not an extension or patch of Country Recovery Platform (C3–C8). |
| **C3–C8 posture** | **Engineering Complete** — **must not be modified** by this program. Consumed only as **immutable gates / inputs**. |
| **Enablement** | Country Production Restore remains **disabled** until owner decisions + certification close. |
| **Schema / boundary SoT** | `scripts/orange_db.sql` (local truth) · C1.1 `COUNTRY_RESTORE_BOUNDARY_POLICY.md` · matrix `COUNTRY_BOUNDARY_VALIDATION.md` · registry `config/backup_table_registry.json` (rev **121**) |
| **Shadow model (input)** | `seeded_multicountry_target_slice` (C6–C8 engines **1.3**) |
| **Full DR design inputs** | `RESTORE_EXECUTION_DESIGN.md`, `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`, `PRODUCTION_IMPORT_SAFETY.md`, `ORANGE_DR_OPERATOR_RUNBOOK.md`, Enterprise Final Audits R1–R3 |
| **CRP design inputs** | `COUNTRY_CRP_*` C3–C8 docs, Final CRP Enterprise Audit (tip `3cb78be2`) |
| **Business policy** | `ORANGE_OWNER_MULTICOUNTRY_VISION.txt` (§13 full country separation) |

**Hard non-goals of P0:** no Restore Engine code, no Import, no Rollback code, no Cutover code, no Maintenance wiring changes, no CLI, no HTTP, no UI, no enablement flag flip, no certification artifact claiming production readiness.

---

## Document control

This document defines the **target architecture** for Country Production Restore (CPR).  
Where Full DR and Country CPR differ, **Country CPR rules in this file win for country-scoped production mutation**. Full DR remains the platform for whole-database disaster recovery.

Frozen C1.1 owner decisions **D1–D6** remain binding. This P0 document does **not** reopen them.

---

## 1. Goals

1. Recover **one target country’s operational slice** (DB rows + country-scoped uploads) into **live production**, after proving safety via C3–C8.
2. Preserve **all survivor countries**, **Global / Full-only** state, and platform meta with **fail-closed** verification.
3. Reuse Full DR **controls** (maintenance, locks, dual-control pattern, audit, pre-restore Full backup as rollback anchor, CLI-only mutation) without inventing a second Full wipe/import engine.
4. Make every production mutation **authorized, observable, resumable by policy, and auditable**.
5. Keep CPR **disabled by default** until owner enablement + Country certification.
6. Provide a clear **operator runbook** and **state machine** suitable for Windows / Plesk / MariaDB / PHP PDO hosting.

---

## 2. Non-goals

1. **Not** Full Disaster Restore (no production schema wipe of all countries).
2. **Not** modifying C3–C8 engines, reports, or shadow DB semantics.
3. **Not** merging / “best effort” restore of ambiguous ownership.
4. **Not** restoring Global tables, `journal_entries`, screen-copy log, schema meta, or NULL-as-target rows (C1.1).
5. **Not** changing `.env.php`, Plesk config, or application PHP via restore.
6. **Not** mid-stream SQL resume into a half-applied country slice (default reject; see §14 / §32).
7. **Not** HTTP-triggered production mutation.
8. **Not** automatic enablement or self-certification.
9. **Not** multi-country restore in one job.
10. **Not** implementation in P0.

---

## 3. Owner policy

### 3.1 Already frozen (must not be re-litigated in implementation)

| ID | Source | Policy |
|----|--------|--------|
| **D1** | C1.1 | Corrected boundary matrix is SoT |
| **D2** | C1.1 | NULL `country_id` never equals target; exact equality only |
| **D3** | C1.1 | `document_sequences` special namespace handler only |
| **D4** | C1.1 | `admins` + `admin_permissions` composite |
| **D5** | C1.1 | `orange_country_screen_copy_log` Global / ignore |
| **D6** | C1.1 | `journal_entries` Full-only / ignore for Country |
| **MV-13** | Multicountry vision | Full operational separation by `country_id` (stock, GL, parties, sequences) |
| **OD-2** | Full DR Audit R3 | Country production restore stays disabled until Country certification |
| **CRP-Final** | CRP Final Audit | C8 SAFE ≠ cutover authorization; production restore not ready for implementation until design closes conditions |

### 3.2 Owner decisions that must be frozen before implementation

See **§ Owner decisions required (catalog)** at the end of this document.  
**Do not guess.** Implementation must not start until each OD-* is recorded as APPROVED / WAIVED / DEFERRED with date and owner identity.

---

## 4. Production safety model

### 4.1 Safety axioms

1. **Fail closed** — any unproven survivor / Global / ownership / accounting / FIFO / schema gate → abort before PONR.
2. **Session identity** — every mutating PDO session must assert `DATABASE() = production_db` **and** job-bound production name; never shadow DSN for production steps.
3. **Shadow ≠ production** — C6–C8 never write production; CPR never writes shadow as a substitute for production apply.
4. **Target-slice only** — production DELETE/INSERT predicates use C1.1 ownership resolvers; never full-table wipe of mutate tables.
5. **Witnesses** — pre-PONR and post-apply survivor + Global baselines/hashes must match outside target.
6. **CLI only** for mutation; Admin UI is status / approval / emergency-stop metadata only.
7. **Enablement gate** — hard constant / config `country_production_restore_enabled` remains false until OD-ENABLE + certification.
8. **Maintenance** — production writers blocked from maintenance entry until success finalize or rollback finalize (OD-MAINT).

### 4.2 Threats explicitly in scope

| Threat | Control |
|--------|---------|
| Survivor-country mutation | Ownership resolvers + pre/post survivor witnesses + abort |
| Global contamination | Never-export list + Global baseline witnesses |
| Wrong DB | Name + `SELECT DATABASE()` + credential role separation |
| Replay / double apply | Job state machine + idempotency tokens + lock |
| Partial apply | Checkpoints + dirty marking + re-apply or Full-anchor rollback |
| HTTP abuse | No mutating endpoints |
| Operator error | Dual control + re-auth + phrase + package/job allowlists |
| Retention deleting rollback anchor | Pin Full pre-restore package (OD-PIN) |

---

## 5. Restore philosophy

**Country Production Restore is a surgical replace of one country’s slice on a live multi-country database.**

| Principle | Meaning |
|-----------|---------|
| **Replace, don’t merge** | Delete target ownership slice → import package rows (preserve PKs per boundary policy) |
| **Shadow proves; production applies** | C6–C8 prove isolability; CPR applies an authorized plan to production |
| **Full DR is the safety chassis** | Maintenance, locks, approval patterns, Full backup rollback anchor |
| **Country CPR is not Full cutover** | No whole-DB wipe; no full `uploads` tree rename as primary path |
| **Boundary matrix is law** | C1.1 + dependency batches 1→6 |
| **Rollback preference** | Pinned **Full** pre-restore backup anchor (DB + uploads), not “inverse country undo” as primary |
| **Certification before enablement** | Separate Country Production certification program |

---

## 6. Execution pipeline

High-level stages (logical; names are architectural):

```
[Package finalize]
  → C3 Export artifacts present
  → C4 Verify PASS
  → C5 Country DRV PASS (score ≥ 85)
  → C6 Country Shadow Restore READY
  → C7 Country Shadow Verify READY (score ≥ 90; survivor+global PASS)
  → C8 Country Dry Run SAFE (or owner-approved WARNING per OD-C8)
  → CPR Job create (metadata only)
  → Production inventory certification (read-only)
  → Pre-restore Full backup + verify + DRV + retention pin  ← rollback anchor
  → Dual-control approvals (OD-DUAL)
  → Execution contract freeze (fingerprints)
  → Maintenance ON + verify writers blocked
  → Acquire CPR production lock
  → Pre-PONR witnesses (survivor + Global + target inventory)
  → CHECKPOINT CP-A (last fully reversible idle point)
  → PONR: target-slice DELETE (production)
  → Target-slice IMPORT (production, batches 1→6)
  → Special handlers (sequences, composites, …)
  → Country uploads apply (scoped paths only)
  → Post-apply verification suite
  → Success finalize OR rollback from Full anchor
  → Maintenance OFF (explicit operator)
  → Audit close
```

**Never** import the raw package into production without C6–C8 chain and contract freeze.  
**Never** use Full DR “wipe entire production schema” for CPR.

---

## 7. Approval pipeline

| Step | Actor | Artifact | Blocks mutation? |
|------|-------|----------|------------------|
| A1 Package eligibility | Operator / system | C4+C5 reports | Yes |
| A2 Shadow chain | Operator | C6 ready + C7 READY + C8 SAFE | Yes |
| A3 Job intent | Operator A | CPR job record (`awaiting_approval`) | Yes |
| A4 Technical approval | Approver role | Signed approval record + fingerprint | Yes |
| A5 Business / owner approval | Owner or delegate (OD-DUAL) | Second approval or waiver record | Yes |
| A6 Cutover authorization | Super Admin re-auth + phrase | `cpr_cutover_authorized` | Yes |
| A7 Post-success acceptance | Operator | Maintenance release authorization | Releases writers |

Approvals bind: `package_id`, `country_id`/`country_code`, schema/boundary/dependency versions, C7/C8 report hashes, Full anchor package id, production DB identity hash, job id.

---

## 8. Dual control

### 8.1 Intent

No single person may both **create** the CPR job and **authorize production PONR** without a second distinct identity (or an **explicit owner waiver** recorded in archive — OD-DUAL).

### 8.2 Architectural requirements

| Requirement | Rule |
|-------------|------|
| Distinct principals | Approver identity ≠ job creator identity unless OD-DUAL = WAIVE |
| Re-auth | Password/re-auth challenge immediately before PONR |
| Phrase | Distinct phrase (e.g. `COUNTRY_RESTORE`) separate from Full DR phrase if OD-PHRASE says so |
| Audit | Both identities, timestamps, fingerprints in immutable audit stream |
| Break-glass | OD-BREAK defines emergency single-control path + mandatory post-incident review |

Align conceptually with Full DR dual-control owner decision (Audit R3 OD-1), but **Country CPR has its own OD-DUAL** — do not silently inherit a Full DR waiver.

---

## 9. Maintenance model

| Topic | Architecture |
|-------|----------------|
| **When ON** | After cutover authorization, **before** any production DELETE/IMPORT/uploads apply |
| **When stays ON** | Until success finalize **or** rollback finalize + operator acceptance |
| **What blocks** | Storefront writes, admin mutating APIs (except emergency CPR controls), payments apply, stock/GL posting, cron mutators, backup create / country export |
| **What may continue** | Health, read-only status, Restore Center CPR status |
| **Scope** | Prefer **platform-wide** maintenance during CPR (OD-MAINT-SCOPE) — country-only maint is riskier and needs owner approval |
| **Proof** | Before PONR: prove at least one blocked write path |

---

## 10. Cutover model

### 10.1 Rejected for Country CPR

| Pattern | Why rejected |
|---------|--------------|
| Full production DB wipe + import | Destroys survivor countries |
| RENAME DATABASE / `.env` DB switch | Same as Full DR rejection; multi-country blast radius |
| Promote Country Shadow DB to production by rename | Host privileges; survivor data lives on production |
| In-place overwrite of entire `uploads/` | Contaminates other countries’ files |

### 10.2 Recommended Country CPR cutover

**A. Database — Target-slice replace on production**

1. Freeze execution contract.  
2. Maintenance ON + lock.  
3. Capture pre-PONR witnesses.  
4. **DELETE** target-country membership rows in registry **delete_order** (batches reverse), using C1.1 resolvers.  
5. **IMPORT** package SQL chunks in restore batches **1→6**.  
6. Run special handlers (sequences, composites).  
7. Post-DB verification (target counts, survivor/Global witnesses, accounting/FIFO/composites).

**B. Files — Country-scoped uploads apply**

1. Materialize allowed files from `uploads_country.zip` into a staging tree under work root.  
2. Apply only allowlisted relative paths for the target country (same allowlist rules as C3/C4).  
3. Prefer: write to `uploads_cpr_next/{country}/…` then atomic per-file or per-subtree replace with pre-image snapshot under work root for rollback assist.  
4. **Do not** two-phase rename the entire `uploads` root (Full DR pattern) unless OD-UPLOADS-FULLTREE explicitly requires it (not recommended).

### 10.3 Point of no return (PONR)

**PONR** = first successful production **DELETE** of a target-slice row **or** first production uploads path replacement for the job — whichever occurs first.

Before PONR: abort is clean (release lock/maint per policy).  
After PONR: only **forward complete** or **rollback from pinned Full anchor**.

---

## 11. Rollback model

| Preference | Mechanism |
|------------|-----------|
| **Primary** | Restore from **pinned Full pre-restore backup** (DB + uploads) using Full DR rollback primitives / patterns — restores entire platform to pre-CPR point |
| **Secondary (assist only)** | Country pre-image snapshots of replaced upload files; **not** sufficient alone for DB partial failure |
| **Not primary** | “Inverse country delete/import” of the package as sole rollback |

**Rule:** If CPR fails after PONR and Full anchor is missing/unpinned → **Severity: Critical operational incident**; site stays in maintenance until manual disaster procedure.

Rollback keeps maintenance **ON** until verified.

---

## 12. Failure recovery

| Failure class | Response |
|---------------|----------|
| Pre-PONR gate fail | Abort; no production mutation; release lock; maint off if turned on only for this job and policy allows |
| Lock contention | Fail `country_production_lock_held`; no steal while heartbeat fresh |
| Delete phase fail | Mark dirty; **do not** import; rollback from Full anchor (or complete delete then decide — OD-FAIL-DELETE) |
| Import phase fail | Mark dirty; **default: no mid-stream resume**; Full-anchor rollback **or** re-delete target slice + re-import from contract (OD-FAIL-IMPORT) |
| Uploads fail after DB OK | Keep maint ON; restore files from Full anchor uploads and/or pre-image; re-verify |
| Post-verify FAIL | Treat as failed cutover → Full-anchor rollback |
| Approval fingerprint drift | Abort pre-PONR |

---

## 13. Resume model

| Situation | Resume policy |
|-----------|---------------|
| Crash before CP-A | Restart CPR job prep; production untouched |
| Crash after maint ON, before PONR | Resume from pre-PONR checks; safe |
| Crash during DELETE | Dirty → finish safe delete **or** Full-anchor rollback (OD-FAIL-DELETE) |
| Crash during IMPORT | **Default: no statement-offset resume.** Re-clear target slice + re-import **or** Full-anchor rollback |
| Crash after DB verify PASS, during uploads | Resume uploads apply only |
| Crash during post-verify | Resume verification (idempotent reads) |
| Crash during rollback | Resume Full rollback from highest Full-DR-compatible checkpoint |
| Stale worker, heartbeat dead, post-PONR | **No auto-unlock**; operator procedure only |

---

## 14. Idempotency model

1. Each CPR job has unique `job_id` and `idempotency_key` bound to package fingerprint + country + anchor id.  
2. Successful finalize marks job terminal — re-run requires **new job**.  
3. Re-entry to `IMPORT` allowed only from explicit dirty/retry states with contract unchanged.  
4. Approval records are append-only; cannot “reuse” another job’s authorization.  
5. Special handlers (sequences) must be written so repeated apply in a retry does not lower counters (C1.1).

---

## 15. Locking model

| Lock | Scope | Purpose |
|------|-------|---------|
| **CPR production lock** | `{workRoot}/country_production/.country_production_restore.lock` | Exclusive CPR mutation |
| **Global restore lock** | Full DR `.restore.lock` / framework locks | Mutual exclusion: CPR must not run concurrent Full restore (OD-LOCK-CROSS) |
| **Backup runner lock** | Backup subsystem | Block Full/Country export during CPR |
| **Country shadow lock** | C6 lock | CPR must not run concurrent C6 on same host work root if shared (OD-LOCK-SHADOW) |

Lock payload (no secrets): `job_id`, `country_id`, `package_id`, `pid`, `heartbeat_at`, `phase`.  
Stale: heartbeat TTL (OD-LOCK-TTL); post-PONR stale → manual only.

---

## 16. Concurrency model

| Rule | Value |
|------|-------|
| CPR jobs concurrent | **One** CPR production job globally (or one per deployment) |
| CPR + Full restore | **Forbidden** concurrently |
| CPR + Country export | **Forbidden** while CPR lock/maint held |
| CPR + C6 shadow | Prefer serialize (OD-LOCK-SHADOW) |
| Multi-country | **One country per job**; no parallel countries |

---

## 17. State machine

Terminal states are bold.

```
cpr_pending
  → cpr_gates_validating
  → cpr_awaiting_approvals
  → cpr_contract_frozen
  → cpr_maintenance_on
  → cpr_pre_ponr
  → cpr_deleting                    ← PONR entered
  → cpr_importing
  → cpr_uploads_applying
  → cpr_post_verifying
  → cpr_succeeded                   ← terminal success (maint still on until release)
  → cpr_maintenance_released        ← terminal operational close
  → cpr_failed_pre_ponr             ← terminal fail safe
  → cpr_failed_post_ponr
  → cpr_rolling_back
  → cpr_rollback_completed          ← terminal
  → cpr_cancelled_pre_ponr          ← terminal
```

Illegal transitions rejected (`illegal_cpr_status_transition`).  
Mirror discipline of Full DR `RESTORE_FW_TRANSITION_MATRIX.md` with a **Country-specific** matrix in a future design artifact (P1).

---

## 18. Execution checkpoints

Durable under `{workRoot}/country_production/{job_id}/checkpoints/` (names architectural):

| ID | Name | When |
|----|------|------|
| **CP0** | `gates_passed` | C4–C8 + enablement + schema/boundary versions |
| **CP1** | `anchor_pinned` | Full pre-restore backup verified + retention pin |
| **CP2** | `approvals_complete` | Dual-control satisfied |
| **CP3** | `contract_frozen` | Fingerprints frozen |
| **CP4** | `maintenance_verified` | Maint ON + write blocked proof |
| **CP5** | `pre_ponr_witnesses` | Survivor/Global/target inventory captured |
| **CP-A** | `last_reversible` | Immediate pre-PONR |
| **CP6** | `delete_complete` | Target-slice delete finished |
| **CP7** | `import_complete` | Batches 1→6 imported |
| **CP8** | `special_handlers_complete` | Sequences/composites done |
| **CP9** | `uploads_complete` | Country uploads applied |
| **CP10** | `post_verify_pass` | Verification suite PASS |
| **CP11** | `success_finalized` | Reports sealed |
| **CP12** | `maint_released` | Writers restored |

---

## 19. Verification checkpoints

Must PASS (or owner-waived per OD) before success:

1. Target row counts vs package inventory (membership-scoped).  
2. Survivor baseline count/hash unchanged vs CP5.  
3. Global / never-export baseline unchanged (incl. `journal_entries`).  
4. NULL ownership leakage = 0 on scoped tables.  
5. Composite units A–H (admins, GL, FIFO, documents, commercial, catalog, expenses, sequences).  
6. Accounting: voucher balance; no JE mutation.  
7. Stock/FIFO ownership + no cross-country references.  
8. Sequence namespaces: no foreign scope touch; counters not lowered.  
9. Uploads allowlist + path safety.  
10. Schema revision / expectations drift check (strict on production).  
11. Batch order integrity.  
12. Production identity still matches contract.

---

## 20. Audit checkpoints

Append-only `audit.jsonl` (redacted) at minimum:

- Job create / cancel  
- Each approval  
- Contract freeze  
- Maint on/off  
- Lock acquire/release  
- Each CP* write  
- PONR mark  
- Delete/import/uploads phase start/end  
- Verify PASS/FAIL codes  
- Rollback start/end  
- Emergency stop  
- Enablement flag reads (deny when false)

Admin `audit_log()` mirror for significant events (no secrets, no absolute private paths).

---

## 21. Monitoring

| Signal | Source |
|--------|--------|
| Job status | CPR job JSON |
| Heartbeat age | Lock / worker heartbeat |
| Phase duration | Checkpoint timestamps |
| Maint flag | `.maintenance.json` / framework |
| Disk free | Preflight |
| Anchor pin present | Retention subsystem |

---

## 22. Metrics

| Metric | Use |
|--------|-----|
| `cpr_jobs_total{result}` | Success/fail/rollback counts |
| `cpr_phase_duration_seconds{phase}` | SLO / capacity |
| `cpr_rows_deleted` / `cpr_rows_inserted` | Impact |
| `cpr_survivor_witness_drift` | Safety (should be 0) |
| `cpr_global_witness_drift` | Safety (should be 0) |
| `cpr_time_in_maintenance_seconds` | Business impact |
| `cpr_emergency_stops_total` | Incident rate |

---

## 23. Logging

| Channel | Rules |
|---------|-------|
| CLI stdout | Progress + stable codes; no credentials |
| `audit.jsonl` | Structured events |
| PHP `error_log` | Faults with `[orange][cpr]` prefix; no PII dumps |
| Reports | JSON under job dir; redacted paths |

---

## 24. Alerting

Alert (page/notify) when:

- Post-PONR failure  
- Rollback failure  
- Heartbeat stale post-PONR  
- Survivor/Global witness drift  
- Anchor pin missing at CP1  
- Emergency stop triggered  
- Maint ON longer than OD-MAINT-MAX

---

## 25. Operational runbook

(Architecture-level checklist; detailed CLI names deferred to implementation phase.)

1. Freeze change window; notify stakeholders.  
2. Confirm Country enablement still false until certification — then OD-ENABLE.  
3. Host preflight (ZipArchive, disk, PHP, DB).  
4. Ensure C3–C8 chain green for package/country.  
5. Create CPR job; attach package + country.  
6. Capture certified production inventory.  
7. Take Full pre-restore backup; verify; pin.  
8. Complete dual-control approvals.  
9. Freeze contract; turn maintenance ON; prove block.  
10. Execute CPR worker through post-verify.  
11. Accept or rollback.  
12. Release maintenance; publish incident notes if any.

---

## 26. Operator responsibilities

| Role | Responsibility |
|------|----------------|
| **Operator** | Runs gates, creates job, monitors, executes CLI under approval |
| **Approver** | Technical dual-control approval |
| **Owner / delegate** | Business approval / waiver; enablement; acceptance |
| **Super Admin** | Re-auth, emergency stop, maint release |
| **On-call** | Post-PONR incidents, rollback |

---

## 27. Permissions

| Capability | Who |
|------------|-----|
| View CPR status | Restore viewers with country scope (OD-PERM) |
| Create CPR job | Restore operators |
| Approve CPR | Distinct approver role |
| Authorize PONR | Super Admin (+ dual control) |
| Emergency stop | Super Admin |
| Release maintenance | Super Admin |
| Enable production flag | **Owner only** (out-of-band + code change / config) — never self-serve in UI |

Country-scoped admins **must not** authorize CPR for another country.

---

## 28. Emergency stop

1. Operator/Super Admin sets `cpr_emergency_stop=true` on job.  
2. Worker cooperatively halts at next safe checkpoint boundary.  
3. If pre-PONR: abort to `cpr_cancelled_pre_ponr`.  
4. If post-PONR: enter failed/rollback decision path; **maint stays ON**.  
5. Audit + alert mandatory.

---

## 29. Timeout policy

| Phase | Guidance (OD-TIMEOUT may refine) |
|-------|----------------------------------|
| Approvals waiting | Soft timeout → cancel pre-PONR |
| Pre-PONR idle with maint ON | Hard alert; auto-cancel only if OD allows |
| Delete/import | Heartbeat ≤ 30s; no HTTP timeout dependency (CLI) |
| Post-verify | Bounded; fail closed on hang |
| Maint max | OD-MAINT-MAX |

---

## 30. Long-running job policy

- CLI worker only; IIS/Plesk PHP request **forbidden** for mutation.  
- Heartbeat file updated ≤ 30s.  
- Progress counters: tables completed, statements, bytes.  
- Operator can attach to logs; no interactive prompts mid-PONR.

---

## 31. Partial failure policy

| Partial state | Policy |
|---------------|--------|
| Some tables deleted, none imported | Dirty → complete delete **or** Full rollback (OD-FAIL-DELETE) |
| Import half-finished | **No** blind resume → re-slice clear + re-import **or** Full rollback |
| DB OK, uploads partial | Finish/revert uploads; DB rollback if verify fails |
| Verify soft warnings | OD-VERIFY-WARN: fail closed by default |

---

## 32. Recovery after crash

Follow §13 Resume model.  
Worker must on start:

1. Load job + checkpoints.  
2. Validate lock ownership / stale rules.  
3. Refuse progress if enablement false.  
4. Branch by last CP* and PONR flag.

---

## 33. Recovery after power loss

Identical to crash recovery, plus:

- Assume disk may have torn last checkpoint write → require rename-atomic checkpoint files.  
- If production DB reachable and dirty mid-import → treat as post-PONR dirty.  
- Prefer Full-anchor rollback when uncertainty remains (fail closed).

---

## 34. Package requirements

Package must be a finalized Country Recovery Package with:

| Requirement | Gate |
|-------------|------|
| Finalized directory name | Retention finalize rules |
| `manifest.json` + fingerprint | C4 |
| `country.sql.gz` / `sql/*.sql` | C3/C4 |
| `files/uploads_country.zip` (or empty-by-inventory) | C3/C4/N3-07 |
| `table_inventory.json`, `dependency_graph.json`, checksums | C3/C4 |
| Boundary / dependency / schema versions match live | C4/C5/C7 |
| C4 overall **PASS** | Mandatory |
| C5 Country DRV **pass**, score **≥ 85** | Mandatory |
| No never-export / Global tables in SQL | C4/C5 |
| `uploads_file_count` coherent | C3/C7/C8 |

---

## 35. Interaction with C3–C8

C3–C8 are **immutable upstream products**. CPR **consumes** their reports; it does **not** redefine them.

| Phase | Role for CPR | CPR may |
|-------|--------------|---------|
| **C3 Export** | Produces package | Read package only |
| **C4 Verify** | Package integrity PASS | Require report PASS |
| **C5 Country DRV** | Recoverability PASS ≥ 85 | Require report pass |
| **C6 Shadow Restore** | Proves target-slice apply on shadow | Require status ready; **no** reuse of shadow DB as production |
| **C7 Shadow Verify** | READY ≥ 90; survivor+global PASS | Require READY + integrity fields |
| **C8 Dry Run** | SAFE impact simulation | Require SAFE (or OD-C8 WARNING waiver); **not** cutover auth |

### Fingerprint continuity

CPR contract must include hashes of:

- Package fingerprint  
- C4 report  
- C5 report  
- C6 restore report  
- C7 verification report  
- C8 dry-run report  

Any drift → abort pre-PONR.

---

## 36. Production prerequisites

| Prerequisite | Notes |
|--------------|-------|
| Host preflight PASS | ZipArchive, disk, PHP, DB connectivity |
| Schema revision **121** (or OD-SCHEMA bump with re-cert) | Matches package + expectations |
| Boundary policy C1.1 + matrix aligned | Live registry/matrix |
| Full DR maintenance enforcement available | Reuse platform maint |
| Staging credentials ≠ production writes for shadow | Already CRP rule |
| Production DB backup capacity | For Full anchor |
| Retention pin support | OD-PIN |
| Dual-control decision recorded | OD-DUAL |
| Country enablement decision recorded | OD-ENABLE |
| Country Production certification PASS | Separate program |
| No concurrent Full restore / export | Locks |
| Operator training / runbook acknowledged | Human |

---

## 37. Explicit PASS list before Production Restore can start

**All** of the following must be true (no silent defaults):

### A. Platform / policy

1. `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED` (or successor) **true** only after OD-ENABLE + certification — until then **hard false**.  
2. OD-DUAL recorded (implement or explicit waive).  
3. OD-PIN retention pin mechanism available.  
4. Country Production certification record **PASS** (future artifact).  
5. No Full restore job active; global restore lock free.  
6. Host deployment preflight **PASS**.

### B. Package / CRP chain

7. Package finalized.  
8. **C4** `overall = PASS`.  
9. **C5** `overall_result = pass` and `recovery_score ≥ 85`.  
10. Package fingerprint unchanged since C4/C5.  
11. **C6** status `ready` / shadow restore success; `production_touched = false`.  
12. **C7** `overall_result = READY`, `readiness_score ≥ 90`.  
13. **C7** `survivor_country_integrity = PASS`.  
14. **C7** `global_state_integrity = PASS`.  
15. **C7** accounting / stock_fifo / composite pillars **PASS** (no unproven live pillars).  
16. **C8** `overall_result = SAFE` (or OD-C8-approved WARNING with written waiver).  
17. **C8** `survivor_country_impact = 0` and `global_impact = 0` and JE/full-only impact `0`.  
18. **C8** `simulation_only = true`, `execution_performed = false`.  
19. Boundary / dependency / schema versions match across manifest, C7, C8, live SoT.

### C. Job / approvals / anchor

20. CPR job created for exact `package_id` + `country_id`.  
21. Certified production inventory present (`certified_read_only=true`).  
22. Full pre-restore backup **verified** + DRV acceptable + **retention pinned**.  
23. Dual-control approvals complete (or waiver).  
24. Execution contract frozen; fingerprints match live re-read.  
25. Maintenance **ON** and write-block **proven**.  
26. CPR production lock held by this job.  
27. Pre-PONR witnesses captured (CP5) with no drift vs expectations.  
28. Super Admin re-auth + phrase success for PONR.  
29. Emergency stop clear.  
30. Operator runbook step sign-off recorded (OD-RUNBOOK).

**Only then** may CP-A → delete phase begin.

---

## Relationship to Full Disaster Recovery

| Concern | Full DR | Country CPR |
|---------|---------|-------------|
| Blast radius | Entire DB + uploads root | One country slice + scoped uploads |
| DB technique | Wipe + import export | Target-slice delete + import |
| Uploads | Two-phase root rename | Scoped apply + pre-image |
| Rollback | Full anchor | Full anchor (primary) |
| Shadow | Full shadow chain | **C6–C8 Country shadow chain** |
| Enablement | Full certification + dual-control | **Separate** Country certification + OD-ENABLE |
| Concurrent with the other | Forbidden | Forbidden |

---

## Risks (architectural)

| ID | Risk | Severity | Mitigation direction |
|----|------|----------|----------------------|
| R1 | Ownership resolver precedence bugs on Mixed tables | High | Strict matrix-resolver-first design in engine (Final CRP Audit FA-01) |
| R2 | Partial import leaves inconsistent country slice | High | No mid-stream resume; Full-anchor rollback |
| R3 | Uploads path bleed across countries | High | Allowlist + path fencing + verify |
| R4 | Full anchor missing when needed | Critical | Pin + preflight refuse |
| R5 | Maint scope too narrow → writers continue | High | OD-MAINT-SCOPE prefer platform-wide |
| R6 | Dual-control waived casually | High | Archive-required waiver text |
| R7 | Operator confuses C8 SAFE with authorization | Medium | Explicit contract gate + UI copy (future) |
| R8 | Concurrent export/restore | High | Cross locks |
| R9 | Schema drift vs package | High | Strict production schema expectations |
| R10 | Long maint window business impact | Medium | OD-MAINT-MAX + rehearsal drills |

---

## Unresolved architectural questions

*Do not invent answers — owner/architect workshop required.*

1. Exact **uploads apply** primitive (per-file replace vs subtree swap) — OD-UPLOADS.  
2. Whether CPR may **reuse** Full DR rollback CLI vs dedicated CPR rollback wrapper — OD-ROLLBACK-CLI.  
3. Whether WARNING C8 can ever proceed — OD-C8.  
4. Delete-failure exact recovery branch — OD-FAIL-DELETE.  
5. Import-failure retry vs immediate Full rollback — OD-FAIL-IMPORT.  
6. Maint scope platform-wide vs country-soft — OD-MAINT-SCOPE.  
7. Cross-lock with Country Shadow jobs — OD-LOCK-SHADOW.  
8. Phrase string and re-auth factor — OD-PHRASE.  
9. Permission model for country-scoped operators — OD-PERM.  
10. Certification evidence pack contents — OD-CERT.  
11. Whether production inventory must be live SELECT under maint or certified snapshot only — OD-INV.  
12. Maximum allowed job duration / business RTO — OD-RTO.

---

## Owner decisions required (catalog)

**Legend:** each item must be `APPROVED` | `WAIVED` | `DEFERRED` with owner name + date before implementation of that concern.

| ID | Decision | Notes |
|----|----------|-------|
| **OD-ENABLE** | When/how Country Production Restore enablement flag may become true | Default: false until Country certification PASS |
| **OD-DUAL** | Implement dual-control for CPR **or** explicit waiver text | Separate from Full DR OD-1 |
| **OD-PHRASE** | Authorization phrase(s) and re-auth factors | |
| **OD-BREAK** | Break-glass single-control procedure | Post-incident mandatory |
| **OD-MAINT-SCOPE** | Platform-wide vs narrower maintenance | Recommend platform-wide |
| **OD-MAINT-MAX** | Max maintenance duration / escalation | |
| **OD-PIN** | Retention pin semantics for Full anchor | |
| **OD-C8** | Allow C8 WARNING with waiver? or SAFE only | Recommend SAFE only |
| **OD-UPLOADS** | Uploads apply strategy details | |
| **OD-FAIL-DELETE** | Recovery when delete phase fails | |
| **OD-FAIL-IMPORT** | Retry re-slice vs immediate Full rollback | |
| **OD-ROLLBACK-CLI** | Reuse Full rollback worker vs CPR-specific | |
| **OD-LOCK-CROSS** | Interaction with Full DR locks | Recommend exclusive |
| **OD-LOCK-SHADOW** | Interaction with C6 shadow lock | |
| **OD-LOCK-TTL** | Heartbeat / stale TTLs | |
| **OD-PERM** | Role matrix for view/create/approve | |
| **OD-INV** | Production inventory capture method | |
| **OD-VERIFY-WARN** | Any post-verify warnings allowable? | Recommend no |
| **OD-SCHEMA** | Process when schema_revision leaves 121 | Re-cert required |
| **OD-CERT** | Country Production certification checklist ownership | |
| **OD-RTO** | Business RTO/RPO for CPR window | |
| **OD-RUNBOOK** | Required human sign-offs | |
| **OD-TIMEOUT** | Numeric timeouts per phase | |
| **OD-FA-RESOLVER** | Confirm matrix-resolver-first fix mandate for engine | From CRP Final Audit FA-01 |
| **OD-FA-STOCK** | Confirm strict FIFO/stock verification mandate | From CRP Final Audit FA-02 |
| **OD-FA-SCHEMA** | Confirm no fixture soft-skip on production schema checks | From CRP Final Audit FA-03 |

---

## Implementation roadmap (architecture sequence — not a build commit)

| Phase | Name | Output | Depends on |
|-------|------|--------|------------|
| **P0** | Architecture (this doc) | Architecture + OD catalog | C3–C8 complete |
| **P0b** | Owner decision workshop | Frozen OD-* answers in archive | P0 |
| **P1** | Detailed design | State transition matrix, checkpoint schemas, lock file formats, report schemas | P0b |
| **P2** | Certification design | Country Production certification program + evidence pack | P1 |
| **P3** | Engine scaffolding (future) | Job framework + gates only (no PONR) | P1–P2 |
| **P4** | Pre-PONR path | Anchor, approvals, maint, witnesses | P3 |
| **P5** | Production apply | Delete/import/uploads under flags | P4 + OD-ENABLE false until drills |
| **P6** | Verify + rollback integration | Post-verify + Full-anchor rollback | P5 |
| **P7** | Clone drills / real-clone proof | Evidence | P6 |
| **P8** | Country Production certification | Cert PASS/FAIL | P7 |
| **P9** | Enablement | Flag true under owner order | P8 + all OD-* |

**Stop rule:** No phase P3+ code work until P0b owner decisions required for that phase are frozen.

---

## Appendix A — Design inputs index

- `docs/backup/COUNTRY_RESTORE_BOUNDARY_POLICY.md` (C1.1 frozen)  
- `docs/backup/COUNTRY_BOUNDARY_VALIDATION.md`  
- `docs/backup/COUNTRY_DEPENDENCY_GRAPH.md`  
- `docs/backup/COUNTRY_CRP_EXPORT_ENGINE_C3.md` … `COUNTRY_CRP_DRY_RUN_C8.md`  
- `docs/backup/COUNTRY_RESTORE_ARCHITECTURE.md` (C0 historical)  
- `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (P0b register)  
- `docs/backup/COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` (UX clarification; **no new ODs**)  
- `docs/backup/GLOBAL_RESTORE_OPERATIONAL_POLICY.md` (Global Maintenance ops for any production Restore; **no new ODs**)  
- `docs/backup/RESTORE_EXECUTION_DESIGN.md`  
- `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`  
- `docs/backup/PRODUCTION_IMPORT_SAFETY.md`  
- `docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md`  
- `docs/backup/ENTERPRISE_FINAL_AUDIT.md` / `_ROUND2.md` / `_ROUND3.md`  
- CRP Final Enterprise Audit (conversation tip `3cb78be2`)  
- `docs/archive/ORANGE_OWNER_MULTICOUNTRY_VISION.txt`

---

## Appendix B — Explicit non-implementation confirmation

This P0 deliverable:

- Does **not** modify C3–C8 code or docs beyond adding **this new** architecture file.  
- Does **not** enable Country Production Restore.  
- Does **not** add CLI, HTTP, UI, SQL, or PHP engines.  
- Does **not** authorize production mutation.

**Next authorized step:** Owner decision workshop (P0b) against the OD-* catalog.
