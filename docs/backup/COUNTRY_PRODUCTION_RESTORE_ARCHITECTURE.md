# Country Production Restore Architecture (Phase P0)

| Field | Value |
|-------|--------|
| **Status** | **ARCHITECTURE ONLY** — no implementation, no PHP, no SQL, no CLI/HTTP/UI |
| **Phase** | P0 — Country Production Restore Architecture (**synchronized to P0b OWNER_APPROVED register — 2026-07-20**) |
| **Date** | 2026-07-20 |
| **Nature** | **New project.** Not an extension or patch of Country Recovery Platform (C3–C8). |
| **C3–C8 posture** | **Engineering Complete** — **must not be modified** by this program. Consumed only as **immutable gates / inputs**. |
| **Enablement** | Country Production Restore remains **disabled** until **OD-ENABLE** preconditions: Certification PASS, explicit Owner enablement order, implementation completed, and Final Enterprise approval (see Owner Decision Register). |
| **Schema / boundary SoT** | `scripts/orange_db.sql` (local truth) · C1.1 `COUNTRY_RESTORE_BOUNDARY_POLICY.md` · matrix `COUNTRY_BOUNDARY_VALIDATION.md` · registry `config/backup_table_registry.json` (rev **121**) |
| **Shadow model (input)** | `seeded_multicountry_target_slice` (C6–C8 engines **1.3**) |
| **Full DR design inputs** | `RESTORE_EXECUTION_DESIGN.md`, `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`, `PRODUCTION_IMPORT_SAFETY.md`, `ORANGE_DR_OPERATOR_RUNBOOK.md`, Enterprise Final Audits R1–R3 |
| **CRP design inputs** | `COUNTRY_CRP_*` C3–C8 docs, Final CRP Enterprise Audit (tip `3cb78be2`) |
| **Business policy** | `ORANGE_OWNER_MULTICOUNTRY_VISION.txt` (§13 full country separation) |
| **Owner Decision SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — **all named OD-* are OWNER_APPROVED** (P0b workshop complete) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` (no new ODs; defer to register) |

**Hard non-goals of P0:** no Restore Engine code, no Import, no Rollback code, no Cutover code, no Maintenance wiring changes, no CLI, no HTTP, no UI, no enablement flag flip, no certification artifact claiming production readiness.

---

## Document control

This document defines the **target architecture** for Country Production Restore (CPR).

**Policy precedence (binding):**

1. **`COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md`** (OWNER_APPROVED register + foundational principles) — **Single Source of Truth** for CPR policy.  
2. Ops clarifications that explicitly defer to the register (`GLOBAL_RESTORE_OPERATIONAL_POLICY.md`, `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md`).  
3. **This P0 Architecture document** — technical design narrative; where this file conflicts with the register, **the register wins**.  

Full DR remains the platform for whole-database disaster recovery. Frozen C1.1 owner decisions **D1–D6** remain binding. This P0 document does **not** reopen them or any OWNER_APPROVED OD-*.

**Architecture synchronization (2026-07-20):** P0 narrative aligned to the frozen P0b register (Conflict Matrix APPROVED). No new Owner Decisions.

---

## 1. Goals

1. Recover **one target country’s operational slice** (DB rows + country-scoped uploads) into **live production**, after proving safety via C3–C8.
2. Preserve **all survivor countries**, **Global / Full-only** state, and platform meta with **fail-closed** verification.
3. Reuse Full DR **controls** (GLOBAL maintenance, locks, audit, session Full backup rollback anchor, long-running non-HTTP mutation workers) and CPR **approval/execution authority per OD-DUAL** — without inventing a second Full wipe/import engine.
4. Make every production mutation **authorized, observable, resumable by policy, and auditable**.
5. Keep CPR **disabled by default** until OD-ENABLE preconditions (certification PASS + explicit Owner enablement + implementation + Final Enterprise approval).
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

### 3.2 Owner Decision workshop status (P0b — complete)

**All named OD-* decisions in the Owner Decision Register are OWNER_APPROVED** (Final Governance freeze 2026-07-20). There are **no** unresolved, PROPOSED, WAIVED, or DEFERRED CPR Owner Decisions remaining in that register.

**Do not guess and do not re-open frozen ODs.** P1 detailed design must follow the register verbatim.  
**P1 must not start** until the Owner **explicitly authorizes** P1 (OD completeness alone is not authorization to begin P1).

See **§ Owner decisions catalog (SUPERSEDED)** at the end of this document for the historical ID index only.

---

## 4. Production safety model

### 4.1 Safety axioms

1. **Fail closed** — any unproven survivor / Global / ownership / accounting / FIFO / schema gate → abort before PONR.
2. **Session identity** — every mutating PDO session must assert `DATABASE() = production_db` **and** job-bound production name; never shadow DSN for production steps.
3. **Shadow ≠ production** — C6–C8 never write production; CPR never writes shadow as a substitute for production apply.
4. **Target-slice only** — production DELETE/INSERT predicates use C1.1 ownership resolvers; never full-table wipe of mutate tables.
5. **Witnesses** — pre-PONR and post-apply survivor + Global baselines/hashes must match outside target.
6. **Long-running non-HTTP workers for mutation** (CLI or equivalent); Super Admin **dashboard is the normal control plane** (status, approvals, execute orchestration, Resume/Rollback, maint release) — UI must not perform production mutation inside an IIS/Plesk HTTP request (OD-DUAL / Super Admin Operational Model).
7. **Enablement gate** — hard constant / config `country_production_restore_enabled` remains false until OD-ENABLE preconditions are met.
8. **Maintenance** — **GLOBAL** Maintenance Mode mandatory before CPR mutation; writers blocked until Super Admin success finalize or rollback finalize + maint release (OD-MAINT, OD-MAINT-SCOPE, OD-PERM, OD-RUNBOOK).

### 4.2 Threats explicitly in scope

| Threat | Control |
|--------|---------|
| Survivor-country mutation | Ownership resolvers + pre/post survivor witnesses + abort |
| Global contamination | Never-export list + Global baseline witnesses |
| Wrong DB | Name + `SELECT DATABASE()` + credential role separation |
| Replay / double apply | Job state machine + idempotency tokens + lock |
| Partial apply | Checkpoints + dirty marking + re-apply or Full-anchor rollback |
| HTTP abuse | No mutating endpoints |
| Operator error | OD-DUAL authority model + re-auth + phrase `RESTORE` + package/job allowlists + OD-RUNBOOK |
| Retention deleting rollback anchor | Pin Full pre-restore package (OD-PIN) |

---

## 5. Restore philosophy

**Country Production Restore is a surgical replace of one country’s slice on a live multi-country database.**

| Principle | Meaning |
|-----------|---------|
| **Replace, don’t merge** | Delete target ownership slice → import package rows (preserve PKs per boundary policy) |
| **Shadow proves; production applies** | C6–C8 prove isolability; CPR applies an authorized plan to production |
| **Full DR is the safety chassis** | GLOBAL maintenance, locks, audit, session Full backup rollback anchor |
| **Country CPR is not Full cutover** | No whole-DB wipe; **never** full `uploads` tree rename (OD-UPLOADS scoped only) |
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
  → C8 Country Dry Run SAFE only (OD-C8 — no WARNING waiver)
  → CPR Job create / request (metadata only; OD-DUAL Workflow A or B)
  → Production inventory certification (read-only; OD-INV)
  → OD-DUAL authority satisfied (WF-A protections or WF-B Super Admin approval)
  → Execution contract freeze (fingerprints)
  → GLOBAL Maintenance ON + verify writers blocked (OD-MAINT / OD-MAINT-SCOPE)
  → Automatically create NEW session Full Backup + verify + retention pin (OD-PIN)
  → Acquire CPR production lock
  → OD-RUNBOOK Super Admin pre-PONR checklist (audited)
  → Super Admin re-auth + phrase RESTORE (OD-PHRASE)
  → Pre-PONR witnesses (survivor + Global + target inventory)
  → CHECKPOINT CP-A (last fully reversible idle point)
  → PONR: target-slice DELETE (production)
  → Target-slice IMPORT (production, batches 1→6)
  → Special handlers (sequences, composites, …)
  → Country uploads apply (scoped paths + pre-image only; OD-UPLOADS)
  → Post-apply verification suite (fail-closed; OD-VERIFY-WARN)
  → Success finalize OR Super Admin Resume/Rollback from session Full anchor
  → GLOBAL Maintenance OFF (Super Admin only; after Runbook complete — OD-PERM / OD-RUNBOOK)
  → Audit close
```

**Never** import the raw package into production without C6–C8 chain and contract freeze.  
**Never** use Full DR “wipe entire production schema” for CPR.

---

## 7. Approval / authority pipeline (OD-DUAL)

| Step | Actor | Artifact | Blocks mutation? |
|------|-------|----------|------------------|
| A1 Package eligibility | System / Country Admin or Super Admin | C4+C5 reports | Yes |
| A2 Shadow chain | System / preparer | C6 ready + C7 READY + C8 **SAFE** | Yes |
| A3 Job intent | **Workflow A:** Super Admin creates end-to-end · **Workflow B:** Country Admin requests → `pending_super_admin_approval` | CPR job record | Yes |
| A4 Super Admin approval (WF-B only) | Super Admin | Approval record + fingerprint | Yes (WF-B) |
| A5 Technical protections (mandatory both workflows) | System + Super Admin | Full Rollback Anchor (OD-PIN), gates PASS, GLOBAL Maint ON, OD-RUNBOOK checklist, one-time authorization | Yes |
| A6 Cutover authorization | Super Admin password re-auth + phrase **`RESTORE`** (OD-PHRASE) | `cpr_cutover_authorized` | Yes |
| A7 Post-success / post-rollback maint release | **Super Admin only** (OD-PERM); only after OD-RUNBOOK successfully completed | Maintenance release authorization | Releases writers |

Bindings: `package_id`, `country_id`/`country_code`, schema/boundary/dependency versions, C7/C8 report hashes, **session** Full anchor package id, production DB identity hash, job id.

**There is no distinct “Approver” role and no waiver-of-authority path** for CPR (OD-DUAL / OD-PERM / Integrity Principle).

---

## 8. Approval / execution authority (OD-DUAL)

### 8.1 Intent

CPR uses **one global Super Admin** and **Country Admins** — **not** a dual-Super-Admin model and **not** a waiver-based bypass of OD-DUAL.

| Workflow | Who prepares | Who approves / executes |
|----------|--------------|-------------------------|
| **A** | Super Admin creates and manages the Production Restore request from the beginning | Super Admin executes; **no second human approver required** |
| **B** | Country Admin prepares package and completes C3–C8; requests Production Restore | Job enters **Pending Super Admin Approval**; **only Super Admin** may approve and execute |

**Mandatory in both workflows:** Full Rollback Anchor (OD-PIN), all mandatory gates PASS, GLOBAL Maintenance Mode, confirmation phrase `RESTORE`, password re-authentication, complete audit log, one-time authorization. Country Admins **never** execute Production Restore.

### 8.2 Architectural requirements

| Requirement | Rule |
|-------------|------|
| Authority model | OD-DUAL Workflow A or B + OD-PERM capability matrix |
| Re-auth | Password re-authentication immediately before execution / PONR |
| Phrase | Exact phrase **`RESTORE`** (OD-PHRASE) — Super Admin must type it |
| Audit | Actor identities, timestamps, fingerprints in immutable audit stream |
| Break-glass | OD-BREAK: Super Admin emergency path; **cannot** bypass Full Rollback Anchor, mandatory gates, logging, or authentication |
| No waiver execution | Integrity / Isolation / Governance Principles — no privilege override of safety gates |

Do **not** silently inherit Full DR two-person dual-control (Audit R3 OD-1) into CPR. CPR authority is defined solely by OWNER_APPROVED **OD-DUAL** / **OD-PERM**.

---

## 9. Maintenance model

| Topic | Architecture |
|-------|----------------|
| **When ON** | **Before** session Full Backup (OD-PIN) and **before** any production DELETE/IMPORT/uploads apply; mandatory (OD-MAINT) |
| **When stays ON** | Until success finalize **or** rollback finalize, then **Super Admin** releases maintenance (OD-PERM); never while job incomplete (Maintenance State) |
| **What blocks** | All storefronts (every country), all Country Admin dashboards, payments apply, stock/GL posting, cron mutators, backup create / country export — see `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` |
| **What may continue** | Health; Super Admin **Restore Management** interface only |
| **Scope** | **GLOBAL (platform-wide) only** (OD-MAINT-SCOPE). **Country-only maintenance is not approved** under the current architecture |
| **Proof** | Before PONR: prove at least one blocked write path |
| **Runbook gate** | Global Maintenance shall **never** be released until OD-RUNBOOK is successfully completed |

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

**B. Files — Country-scoped uploads apply (OD-UPLOADS)**

1. Materialize allowed files from `uploads_country.zip` into a staging tree under work root.  
2. Apply only allowlisted relative paths for the target country’s **approved recovery scope** (same allowlist rules as C3/C4).  
3. Before modifying any production file: create a **scoped pre-image** of every file that may be modified.  
4. Write/replace only within the target country’s approved upload scope (e.g. staging under work root then atomic per-file or per-subtree replace).  
5. **NEVER** replace the entire `uploads` tree; **NEVER** delete or modify survivor-country uploads; **NEVER** modify files outside the approved recovery scope.  
6. If upload integrity cannot be guaranteed: fail immediately; remain in GLOBAL Maintenance; Super Admin may only Resume (when safely supported) or Rollback — no best-effort / partial acceptance.

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
| Delete phase fail | Mark dirty; **do not** auto-rollback; keep GLOBAL Maint ON; pause for Super Admin **Resume** (only if stage safely supports) or **Rollback** to session Full Backup (OD-FAIL-DELETE / OD-ROLLBACK) |
| Import phase fail | Mark dirty; **do not** auto-rollback; keep GLOBAL Maint ON; pause for Super Admin **Resume** (safe stage continuation only) or **Rollback** (OD-FAIL-IMPORT / OD-ROLLBACK) |
| Uploads fail after DB OK | Keep maint ON; fail-closed per OD-UPLOADS; Super Admin Resume/Rollback only |
| Post-verify FAIL | Session FAILED; GLOBAL Maint stays ON; Super Admin Resume or Rollback only (OD-VERIFY-WARN) — no integrity waiver |
| Approval fingerprint drift | Abort pre-PONR |

---

## 13. Resume model

**Binding clarification (OD-FAIL-* / OD-ROLLBACK):** Super Admin dashboard **Resume** means a **safe stage continuation** authorized by the Super Admin when the stage supports it. It does **not** mean blind SQL statement-offset resume into a half-applied country slice. **Rollback** is the dedicated Super Admin dashboard action to the session Full Backup (OD-PIN); never automatic; never Country Admin.

| Situation | Resume policy |
|-----------|---------------|
| Crash before CP-A | Restart CPR job prep; production untouched |
| Crash after maint ON, before PONR | Resume from pre-PONR checks; safe |
| Crash during DELETE | Dirty → Super Admin Resume (finish safe delete if supported) **or** Rollback (OD-FAIL-DELETE) |
| Crash during IMPORT | **No statement-offset resume.** Super Admin Resume may authorize re-clear target slice + re-import from contract **or** Rollback to session Full Backup |
| Crash after DB verify PASS, during uploads | Resume uploads apply only if integrity can be guaranteed; else fail per OD-UPLOADS |
| Crash during post-verify | Resume verification (idempotent reads) |
| Crash during rollback | Resume Full rollback from highest Full-DR-compatible checkpoint |
| Stale worker, heartbeat dead, **pre-PONR** | Super Admin may manually clear stale lock; every manual unlock fully audited (OD-LOCK-TTL) |
| Stale worker, heartbeat dead, **post-PONR** | **No automatic lock release** under any circumstance (OD-LOCK-TTL); Super Admin procedure only |

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
Heartbeat monitoring required (OD-LOCK-TTL). Engineering default interval ≤ 30s unless later Owner-specified.  
**Pre-PONR stale:** Super Admin may manually clear only if appropriate; every manual unlock fully audited.  
**Post-PONR:** automatic lock release is **permanently forbidden** — no timeout, worker failure, crash, or other circumstance may auto-release (OD-LOCK-TTL). System Integrity > automatic recovery.

---

## 16. Concurrency model

| Rule | Value |
|------|-------|
| CPR jobs concurrent | **One** CPR production job globally (or one per deployment) |
| CPR + Full restore | **Forbidden** concurrently |
| CPR + Country export | **Forbidden** while CPR lock/maint held |
| CPR + C6 shadow | **Forbidden concurrently** — mutually exclusive / serialized; refuse second if one active (OD-LOCK-SHADOW) |
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
| **CP2** | `approvals_complete` | OD-DUAL Workflow A protections or Workflow B Super Admin approval satisfied |
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

Must **PASS** before success. **No integrity waiver** (Integrity Principle / OD-VERIFY-WARN). On failure: session FAILED; GLOBAL Maint stays ON; Super Admin Resume or Rollback only:

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

(Architecture-level checklist; detailed worker names deferred to implementation phase. Human sign-offs: **OD-RUNBOOK**.)

1. Freeze change window; notify stakeholders.  
2. Confirm enablement flag still false until OD-ENABLE preconditions met.  
3. Host preflight (ZipArchive, disk, PHP, DB).  
4. Ensure C3–C8 chain green for package/country (**C8 = SAFE**).  
5. Create / request CPR job; attach package + country (OD-DUAL WF-A or WF-B).  
6. Capture certified immutable production inventory (OD-INV).  
7. Satisfy OD-DUAL authority (WF-A protections or WF-B Super Admin approval).  
8. Freeze contract; turn **GLOBAL** Maintenance ON; prove writers blocked.  
9. Automatically create **NEW** session Full Backup; verify; pin (OD-PIN) — never reuse an existing backup.  
10. Super Admin completes OD-RUNBOOK pre-PONR checklist (Package ID, Target Country, C8 SAFE, Certified Inventory, Session Full Backup ID, Global Maint active) — fully audited.  
11. Super Admin password re-auth + type phrase **`RESTORE`**.  
12. Execute CPR worker through post-verify.  
13. On failure pause: Super Admin **Resume** (if safe) or dashboard **Rollback** (OD-ROLLBACK) — never automatic.  
14. Super Admin releases GLOBAL Maintenance only after Runbook successfully completed; publish incident notes if any.

---

## 26. Operator responsibilities

| Role | Responsibility |
|------|----------------|
| **Country Admin** | View own-country CPR status; prepare C3–C8; request Production Restore (OD-PERM). Never approve/execute/resume/rollback/release maint/enable-disable |
| **Super Admin** | Approve (WF-B), execute, Resume, Rollback, emergency stop, release GLOBAL Maintenance, enable/disable CPR flag after Owner enablement order (OD-PERM / OD-DUAL) |
| **Owner** | Final certification PASS/FAIL (OD-CERT); explicit enablement order (OD-ENABLE); schema re-authorization after OD-SCHEMA invalidation |
| **On-call / engineering** | Technical evidence and incident support; never final cert approval (OD-CERT) |

---

## 27. Permissions (OD-PERM)

| Capability | Who |
|------------|-----|
| View CPR status (own country) | Country Admin (country-scoped) |
| Prepare C3–C8 / request Production Restore | Country Admin (own country only) |
| Approve Production Restore | **Super Admin alone** |
| Execute Production Restore | **Super Admin alone** |
| Resume Production Restore | **Super Admin alone** |
| Rollback Production Restore | **Super Admin alone** (dashboard action; fail-pause only) |
| Release Global Maintenance | **Super Admin alone** |
| Enable or Disable Country Production Restore | **Super Admin alone** (operational action) — only after OD-ENABLE preconditions including **explicit Owner enablement order** and Certification PASS (OD-CERT / OD-ENABLE) |
| Final certification PASS/FAIL | **Owner** (OD-CERT) — engineering never grants final approval |

Country Admins **must not** act for another country and **must never** approve, execute, resume, rollback, release maintenance, or enable/disable Production Restore.

---

## 28. Emergency stop

1. Super Admin sets `cpr_emergency_stop=true` on job (dashboard / control plane).  
2. Worker cooperatively halts at next safe checkpoint boundary.  
3. If pre-PONR: abort to `cpr_cancelled_pre_ponr`.  
4. If post-PONR: enter failed/rollback decision path; **GLOBAL maint stays ON**.  
5. Audit + alert mandatory.

---

## 29. Timeout policy

| Phase | Guidance (OWNER_APPROVED OD-TIMEOUT / OD-MAINT-MAX / OD-RTO) |
|-------|----------------------------------|
| Approvals waiting | Soft timeout → cancel pre-PONR |
| Pre-PONR idle with maint ON | Hard alert; progress-aware escalation (timeout ≠ automatic failure) |
| Delete/import | Heartbeat monitoring (engineering default ≤ 30s); no HTTP timeout dependency for mutation workers |
| Post-verify | Bounded; fail closed on hang |
| Maint duration | Automatic Estimated Duration per job — monitoring only; no hardcoded RTO abort |

---

## 30. Long-running job policy

- Mutation via long-running **non-HTTP** worker (CLI or equivalent); IIS/Plesk PHP request **forbidden** for mutation.  
- Super Admin dashboard is the normal control plane (orchestrates workers; does not mutate inside the web request).  
- Heartbeat file updated on engineering default ≤ 30s (OD-LOCK-TTL safety rules bind; interval is implementation default unless later Owner-specified).  
- Progress counters: tables completed, statements, bytes.  
- No interactive prompts mid-PONR.

---

## 31. Partial failure policy

| Partial state | Policy |
|---------------|--------|
| Some tables deleted, none imported | Dirty → complete delete **or** Full rollback (OD-FAIL-DELETE) |
| Import half-finished | **No** statement-offset resume → Super Admin Resume (re-slice clear + re-import if safe) **or** Rollback |
| DB OK, uploads partial | OD-UPLOADS fail-closed; Super Admin Resume/Rollback only |
| Verify soft warnings | OD-VERIFY-WARN: fail closed — no waiver |

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
| **C8 Dry Run** | SAFE impact simulation | Require **SAFE only** (OD-C8); **not** cutover auth; no WARNING waiver |

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
| Schema revision matches certified package expectations | Any production schema revision change invalidates prior CPR certification (OD-SCHEMA): package rebuild + new Certification + new C8 SAFE; no auto re-enable — Owner PASS + Enable again |
| Boundary policy C1.1 + matrix aligned | Live registry/matrix |
| Full DR / platform GLOBAL maintenance enforcement available | OD-MAINT-SCOPE GLOBAL |
| Staging credentials ≠ production writes for shadow | Already CRP rule |
| Production DB backup capacity | For session Full anchor (OD-PIN) |
| Retention pin support | OD-PIN |
| OD-DUAL authority model implemented (WF-A/B) | OWNER_APPROVED |
| Country enablement remains false until OD-ENABLE path | OWNER_APPROVED |
| Country Production certification PASS (Owner) | OD-CERT |
| No concurrent Full restore / C6 / export | OD-LOCK-CROSS / OD-LOCK-SHADOW |
| OD-RUNBOOK acknowledged / checklist ready | OWNER_APPROVED |

---

## 37. Explicit PASS list before Production Restore can start

**All** of the following must be true (no silent defaults):

### A. Platform / policy

1. `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED` (or successor) remains **hard false** until OD-ENABLE preconditions: Certification PASS (Owner), explicit Owner enablement order, implementation completed, Final Enterprise approval — then Super Admin may perform operational enable (OD-PERM).  
2. OD-DUAL Workflow A protections **or** Workflow B Super Admin approval path implemented (no waiver model).  
3. OD-PIN session Full Backup create/verify/pin mechanism available.  
4. Country Production certification record **PASS** by Owner (OD-CERT).  
5. No Full restore job active; no C6 concurrent; global restore lock free (OD-LOCK-CROSS / OD-LOCK-SHADOW).  
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
16. **C8** `overall_result = SAFE` only (OD-C8 — **no WARNING waiver**).  
17. **C8** `survivor_country_impact = 0` and `global_impact = 0` and JE/full-only impact `0`.  
18. **C8** `simulation_only = true`, `execution_performed = false`.  
19. Boundary / dependency / schema versions match across manifest, C7, C8, live SoT.

### C. Job / approvals / anchor

20. CPR job created for exact `package_id` + `country_id`.  
21. Certified immutable production inventory present (`certified_read_only=true`) (OD-INV).  
22. **GLOBAL** Maintenance **ON** and write-block **proven** (OD-MAINT / OD-MAINT-SCOPE).  
23. **NEW** session Full Backup created under Maintenance, **verified**, and **retention pinned** (OD-PIN) — existing backups never reused.  
24. OD-DUAL authority satisfied (WF-A protections complete, or WF-B Super Admin approval recorded).  
25. Execution contract frozen; fingerprints match live re-read.  
26. CPR production lock held by this job.  
27. OD-RUNBOOK Super Admin pre-PONR checklist completed and audited.  
28. Pre-PONR witnesses captured (CP5) with no drift vs expectations.  
29. Super Admin password re-auth + phrase **`RESTORE`** success (OD-PHRASE).  
30. Emergency stop clear.

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
| Enablement | Full certification + Full DR authority model | **Separate** Country certification (OD-CERT) + OD-ENABLE |
| Concurrent with the other | Forbidden | Forbidden (OD-LOCK-CROSS) |

---

## Risks (architectural)

| ID | Risk | Severity | Mitigation direction |
|----|------|----------|----------------------|
| R1 | Ownership resolver precedence bugs on Mixed tables | High | Strict matrix-resolver-first design in engine (OD-FA-RESOLVER / FA-01) |
| R2 | Partial import leaves inconsistent country slice | High | No statement-offset resume; Super Admin Resume (safe re-slice) or Rollback to session Full Backup (OD-FAIL-IMPORT / OD-ROLLBACK) |
| R3 | Uploads path bleed across countries | High | OD-UPLOADS scoped allowlist + pre-image + fail-closed |
| R4 | Full anchor missing when needed | Critical | OD-PIN: new session Full Backup under Maint; refuse without pin |
| R5 | GLOBAL Maintenance not enforced → writers continue | High | OD-MAINT-SCOPE GLOBAL + Global Restore Operational Policy |
| R6 | Country Admin or privilege path used to execute CPR | High | OD-DUAL / OD-PERM — Super Admin alone execute; no waiver |
| R7 | Operator confuses C8 SAFE with authorization | Medium | Explicit contract gate + UI copy; C8 SAFE ≠ cutover auth |
| R8 | Concurrent Full DR / C6 / export with CPR | High | OD-LOCK-CROSS / OD-LOCK-SHADOW / OD-LOCK-TTL |
| R9 | Schema drift vs package | High | OD-FA-SCHEMA + OD-SCHEMA re-authorization cycle |
| R10 | Long maint window business impact | Medium | Automatic Estimated Duration monitoring (OD-MAINT-MAX / OD-RTO); progress-aware OD-TIMEOUT |

---

## Unresolved architectural questions — SUPERSEDED

**Status: SUPERSEDED (2026-07-20 Architecture Synchronization).**

The former “open questions” list is **obsolete**. Every OD-* referenced there is **OWNER_APPROVED** in `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md`.

Do **not** treat any item below as open work. For binding answers, read the register (SoT). Remaining P1 work is **detailed design under frozen policy**, not Owner Decision workshop.

| Former open topic | Frozen answer location |
|-------------------|------------------------|
| Uploads apply strategy | OD-UPLOADS |
| Rollback control surface | OD-ROLLBACK (historical catalog ID OD-ROLLBACK-CLI) |
| C8 WARNING cutover | **Rejected** — OD-C8 SAFE only |
| Delete / import failure | OD-FAIL-DELETE / OD-FAIL-IMPORT |
| Maint scope | OD-MAINT-SCOPE = GLOBAL |
| CPR vs C6 / Full DR locks | OD-LOCK-SHADOW / OD-LOCK-CROSS / OD-LOCK-TTL |
| Phrase / re-auth | OD-PHRASE = `RESTORE` + password re-auth |
| Permissions | OD-PERM |
| Certification ownership | OD-CERT |
| Inventory method | OD-INV |
| Duration / RTO | OD-MAINT-MAX / OD-RTO / OD-TIMEOUT |

---

## Owner decisions catalog — SUPERSEDED

**Status: SUPERSEDED (2026-07-20 Architecture Synchronization).**

The pre-workshop catalog (APPROVED / WAIVED / DEFERRED legend) is **obsolete**.  
**All named OD-* are OWNER_APPROVED.** Historical catalog ID **OD-ROLLBACK-CLI** is frozen as **OD-ROLLBACK**.

**Do not re-decide. Do not mark WAIVED/DEFERRED here.**  
Authoritative frozen wording: `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md`.  
Workshop completion: `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md`.  
Dependency map: `COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md`.

| ID | Status |
|----|--------|
| OD-ENABLE · OD-DUAL · OD-PHRASE · OD-BREAK | OWNER_APPROVED |
| OD-MAINT · OD-MAINT-SCOPE · OD-MAINT-MAX · OD-RTO · OD-TIMEOUT | OWNER_APPROVED |
| OD-PIN · OD-ROLLBACK · OD-FAIL-DELETE · OD-FAIL-IMPORT · OD-UPLOADS | OWNER_APPROVED |
| OD-C8 · OD-VERIFY-WARN · OD-INV · OD-FA-RESOLVER · OD-FA-STOCK · OD-FA-SCHEMA | OWNER_APPROVED |
| OD-LOCK-CROSS · OD-LOCK-SHADOW · OD-LOCK-TTL | OWNER_APPROVED |
| OD-PERM · OD-RUNBOOK · OD-CERT · OD-SCHEMA | OWNER_APPROVED |

---

## Implementation roadmap (architecture sequence — not a build commit)

| Phase | Name | Output | Depends on |
|-------|------|--------|------------|
| **P0** | Architecture (this doc) | Architecture narrative (policy SoT = register) | C3–C8 complete |
| **P0b** | Owner decision workshop | Frozen OD-* in register — **COMPLETE** | P0 |
| **P0-sync** | Architecture synchronization | This document aligned to register | P0b + Conflict Matrix APPROVED |
| **P1** | Detailed design | State transition matrix, checkpoint schemas, lock file formats, report schemas | P0b + **explicit Owner authorization to start P1** |
| **P2** | Certification design | Country Production certification program + evidence pack | P1 |
| **P3** | Engine scaffolding (future) | Job framework + gates only (no PONR) | P1–P2 |
| **P4** | Pre-PONR path | Anchor, approvals, maint, witnesses | P3 |
| **P5** | Production apply | Delete/import/uploads under flags | P4 + OD-ENABLE false until drills |
| **P6** | Verify + rollback integration | Post-verify + session Full-anchor rollback | P5 |
| **P7** | Clone drills / real-clone proof | Evidence | P6 |
| **P8** | Country Production certification | Cert PASS/FAIL (Owner) | P7 |
| **P9** | Enablement | Flag true under OD-ENABLE path | P8 |

**Stop rule:** P0b Owner Decisions are frozen. **Do not start P1** until the Owner explicitly authorizes P1. No production mutation implementation until enablement path allows.

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

This P0 deliverable (plus P0b register and this synchronization):

- Does **not** modify C3–C8 code or engines.  
- Does **not** enable Country Production Restore.  
- Does **not** add CLI, HTTP, UI, SQL, or PHP engines.  
- Does **not** authorize production mutation.  
- Does **not** amend OWNER_APPROVED register text.

**P0b Owner Decision workshop:** complete (all OD-* OWNER_APPROVED).  
**Next authorized step:** P1 detailed design — only when the Owner **explicitly authorizes** P1.
