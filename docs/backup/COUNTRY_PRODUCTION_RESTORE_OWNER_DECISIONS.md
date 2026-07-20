# Country Production Restore — Owner Decision Register (Phase P0b)

| Field | Value |
|-------|--------|
| **Status** | Architecture / policy only — **no implementation** |
| **Phase** | P0b — Owner Decision Freeze (register) |
| **Date** | 2026-07-20 |
| **Parent architecture** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (committed `b28abb81`) |
| **Workshop** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` |
| **Dependencies** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` |
| **C3–C8** | Must not be modified |
| **Enablement** | Remains **disabled** until certification + explicit OD-ENABLE + implementation + final enterprise approval (OWNER_APPROVED) |
| **Last owner freeze** | 2026-07-20 — Group 3: OD-PIN, OD-ROLLBACK, OD-FAIL-DELETE, OD-FAIL-IMPORT (+ Maintenance State on pause) |

### Frozen inputs (not reopened)

| ID | Policy |
|----|--------|
| C1.1 **D1–D6** | Boundary matrix; NULL≠target; sequences special; admins composite; screen-copy Global; `journal_entries` Full-only |
| Multicountry **§13** | Full country separation (stock, GL, parties, sequences) |
| Full DR **OD-2** | Country production restore disabled until Country certification |
| CRP Final Audit | C8 SAFE ≠ cutover auth; FA-01…FA-03 residuals inform OD-FA-* |

### Owner-approved CPR decisions (Group 1 — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-ENABLE** | OWNER_APPROVED |
| **OD-DUAL** | OWNER_APPROVED (Super Admin / Country Admin workflows — **not** dual Super Admin) |
| **OD-PHRASE** | OWNER_APPROVED (`RESTORE` + password re-auth) |
| **OD-BREAK** | OWNER_APPROVED (Super Admin only; does not bypass anchor/gates/auth/logging) |

### Owner-approved CPR decisions (Group 2 — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-MAINT** | OWNER_APPROVED (Maintenance Mode mandatory before execution) |
| **OD-MAINT-SCOPE** | OWNER_APPROVED (**GLOBAL MAINTENANCE**; Country-only NOT approved under current architecture) |
| **OD-MAINT-MAX** | OWNER_APPROVED (no fixed max; automatic Estimated Duration per job) |
| **OD-RTO** | OWNER_APPROVED (no hardcoded RTO; Estimated Duration for monitoring only) |
| **OD-TIMEOUT** | OWNER_APPROVED (timeout ≠ failure; progress-aware escalation → investigate/resume) |

### Owner-approved CPR decisions (Group 3 — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-PIN** | OWNER_APPROVED (new session Full Backup under Maintenance → verify → pin; never reuse existing) |
| **OD-ROLLBACK** | OWNER_APPROVED (dedicated Super Admin dashboard Rollback action; visible only when session paused on failure; never automatic; never Country Admin) — P0 catalog ID was **OD-ROLLBACK-CLI** |
| **OD-FAIL-DELETE** | OWNER_APPROVED (no auto-rollback; pause for Super Admin Resume/Rollback) |
| **OD-FAIL-IMPORT** | OWNER_APPROVED (no auto-rollback; pause for Super Admin Resume/Rollback) |
| **Maintenance State (on failure pause)** | OWNER_APPROVED (Maintenance stays ON until Super Admin completes Resume or Rollback) |

**Note:** P0 architecture §8 previously recommended “two distinct Super Admin identities.” That recommendation is **superseded** by OWNER_APPROVED **OD-DUAL** in this register. Do not implement the old dual-Super-Admin model.

**Note (Group 2):** P0 architecture §9 prefers platform-wide maintenance — now **OWNER_APPROVED** as GLOBAL MAINTENANCE (OD-MAINT-SCOPE). Country-only maintenance is not approved under the current shared-DB / Full-anchor rollback architecture.

**Note (Group 3):** OD-PIN owner workflow is: Maintenance Mode → **new** Full Backup for this session → verify → pin → continue. Existing backups must never be reused as the CPR rollback anchor. Failure after delete/import does **not** auto-rollback; Super Admin chooses Resume (when safe) or the dedicated dashboard **Rollback** action. P0 catalog **OD-ROLLBACK-CLI** is frozen under owner ID **OD-ROLLBACK** (dedicated Super Admin dashboard action, available only on failure pause, security parity with Production Restore, never automatic).

### Register rules

- Status **OWNER_APPROVED** = frozen owner policy; do not reopen without a new owner decision.  
- Remaining decisions stay **PROPOSED** until the owner answers.  
- Facilitation “Recommended” is historical advice only where status is still PROPOSED.

### OD count

**27** decisions (26 from P0 catalog + **OD-MAINT** called out in P0 §4 but missing from the catalog table). Owner ID **OD-ROLLBACK** freezes the historical catalog item **OD-ROLLBACK-CLI**.

---

## Group A — Enablement and governance

### OD-ENABLE — Production enablement gate

| Field | Content |
|-------|---------|
| **1. ID** | OD-ENABLE |
| **2. Title** | When may Country Production Restore be enabled? |
| **3. Exact question** | May the production enablement flag become true only after (a) Country Production certification PASS **and** (b) an explicit owner enablement order, remaining false until then? |
| **4. Options** | **A)** Yes — false until cert PASS + explicit order. **B)** Allow enablement after drills without formal certification. **C)** Allow temporary enablement for emergencies without cert. |
| **5. Consequences** | A: safest; matches P0/OD-2. B: weakens cert program. C: break-glass without OD-BREAK discipline. |
| **6. Recommended** | **A** (historical facilitation) |
| **7. Reason** | Fail closed; production restore disabled by default; aligns Full DR OD-2 and P0. |
| **8. Security** | Prevents accidental/unauthorized production mutation path. |
| **9. Data integrity** | Ensures certified gates exist before live apply. |
| **10. Operational** | Clear go-live ceremony. |
| **11. Rollback** | N/A pre-enable; post-enable still requires Full anchor pins. |
| **12. Required before** | P1: yes (design disabled-default). Implementation: yes. Certification: defines post-cert gate. **Enablement: blocks.** |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Country Production Restore remains **disabled by default**. Enablement is allowed **only after all** of: (1) Certification PASS; (2) Explicit Owner enablement; (3) Production Restore **implementation completed**; (4) Final Enterprise approval. |
| **15. Frozen policy wording** | *Country Production Restore remains disabled by default. The enablement flag may become true only after Certification PASS, an explicit Owner enablement order, completion of Country Production Restore implementation, and Final Enterprise approval. Until then the flag stays false.* |

---

### OD-DUAL — Approval / execution authority (Super Admin ↔ Country Admin)

| Field | Content |
|-------|---------|
| **1. ID** | OD-DUAL |
| **2. Title** | Who may create vs approve/execute Country Production Restore |
| **3. Exact question** | Must Country Production Restore require two distinct identities (job creator ≠ PONR authorizer), or do you explicitly waive dual control in writing? |
| **4. Options** | *(Superseded by owner policy below.)* Historical: A dual Super Admin · B waiver · C first-N only. |
| **5. Consequences** | Owner model: Super Admin may run end-to-end (Workflow A) with mandatory technical protections; Country Admin may prepare C3–C8 only and cannot execute (Workflow B). |
| **6. Recommended** | *(Superseded)* Former “dual Super Admin” recommendation is **withdrawn**. |
| **7. Reason** | Owner-approved role model matches Orange’s one global Super Admin + Country Admins. |
| **8. Security** | Country Admin cannot execute production restore; Super Admin retains sole execution authority. Workflow A still requires anchor, gates, maint, phrase, re-auth, audit, one-time auth. |
| **9. Data integrity** | Mandatory gates + Full Rollback Anchor remain in both workflows. |
| **10. Operational** | Workflow A: single Super Admin path. Workflow B: pending Super Admin approval queue. |
| **11. Rollback** | Full Rollback Anchor mandatory in both workflows; break-glass cannot bypass anchor (OD-BREAK). |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | **Replace** prior dual-Super-Admin recommendation. Roles: **one global Super Admin**; **one or more Country Admins**. **Workflow A** — If Super Admin creates and manages the Production Restore request from the beginning: no second approver required; Super Admin performs final execution; **still mandatory:** Full Rollback Anchor, all mandatory gates PASS, Maintenance Mode, Confirmation Phrase, password re-authentication, complete Audit Log, one-time authorization. **Workflow B** — If Country Admin creates the Country Recovery Package and requests Production Restore: Country Admin may prepare package and complete C3–C8; Country Admin **cannot** execute Production Restore; request enters **Pending Super Admin Approval**; **only Super Admin** may approve and execute Production Restore. |
| **15. Frozen policy wording** | *CPR authority uses one global Super Admin and Country Admins. Workflow A: Super Admin may create, approve, and execute end-to-end without a second human approver, but Full Rollback Anchor, mandatory gates PASS, Maintenance Mode, Confirmation Phrase `RESTORE`, password re-authentication, complete audit log, and one-time authorization remain mandatory. Workflow B: Country Admin may prepare the package and complete C3–C8 only; the job enters Pending Super Admin Approval; only the Super Admin may approve and execute Production Restore. Country Admins must never execute Production Restore.* |

---

### OD-PHRASE — Authorization phrase and re-auth

| Field | Content |
|-------|---------|
| **1. ID** | OD-PHRASE |
| **2. Title** | PONR re-authentication phrase |
| **3. Exact question** | What re-auth factors and exact confirmation phrase are required immediately before Country Production PONR? |
| **4. Options** | **A)** phrase `COUNTRY_RESTORE`. **B)** phrase `RESTORE` (same token as Full DR). **C)** password only. **D)** custom. |
| **5. Consequences** | Owner chose **B** + mandatory password re-auth in Workflow A and B. |
| **6. Recommended** | *(Historical A withdrawn.)* |
| **7. Reason** | Owner-approved shared phrase `RESTORE` with mandatory typing + password re-auth. |
| **8. Security** | Intentionality gate; does not replace role checks (OD-DUAL). |
| **9–11** | Integrity/ops/rollback: process only. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Confirmation phrase is **`RESTORE`**. Phrase is **mandatory**. Super Admin must **type** it before execution. Required in **both Workflow A and Workflow B**. Password re-authentication remains mandatory (per OD-DUAL protections). |
| **15. Frozen policy wording** | *Before Country Production Restore execution, the Super Admin must re-authenticate with password and type the confirmation phrase `RESTORE`. The phrase is mandatory in Workflow A and Workflow B. One-time authorization applies; phrase acceptance does not bypass gates, anchor, maintenance, or audit.* |

---

### OD-BREAK — Break-glass (Super Admin emergency)

| Field | Content |
|-------|---------|
| **1. ID** | OD-BREAK |
| **2. Title** | Emergency Break Glass path |
| **3. Exact question** | If dual control is implemented, is a break-glass single-control path allowed, and what post-incident review is mandatory? |
| **4. Options** | *(Superseded by owner policy.)* |
| **5. Consequences** | Break Glass is Super Admin only; cannot skip anchor/gates/logging/authentication. |
| **6. Recommended** | *(Historical facilitation superseded.)* |
| **7. Reason** | Owner-approved emergency path with hard non-bypass list. |
| **8. Security** | Audited Super Admin emergency; no silent bypass of safety chassis. |
| **9. Data integrity** | Full Rollback Anchor and mandatory safety gates still required. |
| **10. Operational** | Emergency reason + notification required. |
| **11. Rollback** | Anchor not bypassable. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Emergency Break Glass available **only to the Super Admin**. Requirements: emergency reason mandatory; full audit log; notification. **Does NOT bypass:** Full Rollback Anchor; mandatory safety gates; logging; authentication. |
| **15. Frozen policy wording** | *Emergency Break Glass is available only to the Super Admin. An emergency reason, full audit log, and notification are mandatory. Break Glass does not bypass Full Rollback Anchor, mandatory safety gates, logging, or authentication.* |

---

### OD-PERM — Permissions model

| Field | Content |
|-------|---------|
| **1. ID** | OD-PERM |
| **2. Title** | Who may view / create / approve CPR jobs |
| **3. Exact question** | Which roles may (1) view CPR status, (2) create CPR jobs, (3) approve CPR, (4) authorize PONR / release maintenance — and may country-scoped admins act only for their country? |
| **4. Options** | **A)** Align with OWNER_APPROVED OD-DUAL: Country Admin may prepare C3–C8 / request restore; Super Admin only approves/executes/releases maint; viewers country-scoped. **B)** Broader roles. **C)** Owner-defined matrix. |
| **5. Consequences** | Must not contradict OD-DUAL Workflow A/B. Super Admin may execute alone under Workflow A protections. |
| **6. Recommended** | **A** (align to frozen OD-DUAL) |
| **7. Reason** | Fail closed; country isolation; match owner-approved authority model. |
| **8. Security** | Prevents cross-country authorization. |
| **9–11** | Integrity/ops/rollback: process. |
| **12. Required before** | P1: soft. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-CERT — Certification program ownership

| Field | Content |
|-------|---------|
| **1. ID** | OD-CERT |
| **2. Title** | Country Production certification evidence pack |
| **3. Exact question** | Who owns the Country Production certification checklist, and must certification PASS before any enablement? |
| **4. Options** | **A)** Owner owns checklist; engineering fills evidence; PASS mandatory before OD-ENABLE. **B)** Engineering self-certifies without owner sign-off. **C)** Skip formal certification; rely on drills only. |
| **5. Consequences** | A: matches P0 roadmap P8→P9. B/C: weaken governance. |
| **6. Recommended** | **A** |
| **7. Reason** | Explicit enablement after proof. |
| **8–11** | Security/integrity/ops/rollback: cert proves drills including rollback. |
| **12. Required before** | P1: soft (program design in P2). Implementation: before cert phase. **Certification: blocks.** **Enablement: blocks.** |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

## Group B — Maintenance and timing

### OD-MAINT — Maintenance mandatory before PONR

| Field | Content |
|-------|---------|
| **1. ID** | OD-MAINT |
| **2. Title** | Is maintenance mandatory before Country Production PONR? |
| **3. Exact question** | Must platform maintenance be ON and write-block proven before any production DELETE/IMPORT/uploads apply for CPR? |
| **4. Options** | **A)** Yes — mandatory. **B)** Optional if traffic is low. **C)** Never use maintenance for Country CPR. |
| **5. Consequences** | Owner chose mandatory Maintenance Mode (aligns Full DR safety chassis). B/C would race with live writers. |
| **6. Recommended** | **A** (historical facilitation; now OWNER_APPROVED) |
| **7. Reason** | Fail closed against concurrent storefront/admin/GL/stock writes. |
| **8. Security** | Reduces concurrent mutation attacks/errors. |
| **9. Data integrity** | Critical — prevents mid-restore writes. |
| **10. Operational** | Downtime window required (GLOBAL scope — see OD-MAINT-SCOPE). |
| **11. Rollback** | Maint stays on through rollback. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Country Production Restore **always** requires Maintenance Mode before execution. Maintenance is **mandatory**. |
| **15. Frozen policy wording** | *Country Production Restore always requires Maintenance Mode before execution. Maintenance is mandatory. No production DELETE, IMPORT, or uploads apply may begin until Maintenance Mode is ON and write-block is proven.* |

---

### OD-MAINT-SCOPE — Maintenance scope

| Field | Content |
|-------|---------|
| **1. ID** | OD-MAINT-SCOPE |
| **2. Title** | Platform-wide vs country-scoped maintenance |
| **3. Exact question** | During CPR, should maintenance block **all** mutating traffic platform-wide, or only traffic for the target country if isolation can be proven? |
| **4. Options** | **A)** Platform-wide / GLOBAL maintenance. **B)** Country-scoped only if isolation proven. **C)** No writer blocking beyond DB locks. |
| **5. Consequences** | Owner approved **GLOBAL**. Country-only is **not** approved under current architecture. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED as GLOBAL.)* |
| **7. Reason** | Shared production DB, Global/Mixed tables, Full pre-restore backup as primary post-PONR rollback, platform-wide maintenance framework and rollback strategy. |
| **8. Security** | Strongest — all mutating traffic blocked. |
| **9. Data integrity** | Prevents survivor/Global contamination and Full-anchor rollback conflict with live survivor writes. |
| **10. Operational** | Full-platform downtime for CPR window. |
| **11. Rollback** | GLOBAL maintenance covers Full-anchor rollback window equally. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | **GLOBAL MAINTENANCE.** After reviewing current Orange architecture and runtime: one shared production database; shared Global and Mixed tables; Full pre-restore backup as primary rollback after PONR; platform-wide maintenance framework; platform-wide rollback strategy. Therefore **Country-only Maintenance is NOT approved** under the current architecture. Future reconsideration allowed only after a future architecture change introduces a fully proven country-isolated production restore model. |
| **15. Frozen policy wording** | *During Country Production Restore, Maintenance Mode is GLOBAL (platform-wide). All mutating traffic is blocked. Country-only maintenance is not approved under the current architecture. Reconsideration requires a future proven country-isolated production restore model and a new owner decision.* |

---

### OD-MAINT-MAX — Max maintenance duration

| Field | Content |
|-------|---------|
| **1. ID** | OD-MAINT-MAX |
| **2. Title** | Maximum maintenance window / escalation |
| **3. Exact question** | What maximum maintenance duration triggers escalation/alert, and what happens if exceeded? |
| **4. Options** | *(Superseded.)* Historical: A alert 60m / page 120m · B owner numbers · C no max. |
| **5. Consequences** | No fixed maximum. System auto-estimates Expected Duration per job for monitoring (with OD-RTO / OD-TIMEOUT). |
| **6. Recommended** | *(Historical facilitation superseded.)* |
| **7. Reason** | Workload-driven estimate avoids arbitrary hard caps that conflict with real package size. |
| **8–11** | Ops monitoring; does not auto-cancel post-PONR; pairs with OD-TIMEOUT progress rules. |
| **12. Required before** | P1: yes (frozen shape). Implementation: yes. Certification: soft. Enablement: soft. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | **No fixed maximum** maintenance duration. The system must **automatically estimate** the expected execution duration for every Production Restore job. Estimation should consider, where applicable: package size; SQL size; upload size; number of rows; number of batches; historical execution statistics; current infrastructure performance. **No manual duration configuration** is required. |
| **15. Frozen policy wording** | *No fixed maximum maintenance duration exists for Country Production Restore. Every job must receive an automatic Expected Duration estimate from workload signals (package/SQL/upload size, rows, batches, historical stats, infrastructure performance). Manual duration configuration is not required. Exceeding the estimate does not by itself fail the job (see OD-TIMEOUT).* |

---

### OD-RTO — Business RTO/RPO for CPR

| Field | Content |
|-------|---------|
| **1. ID** | OD-RTO |
| **2. Title** | Business RTO / RPO for Country Production Restore |
| **3. Exact question** | What maximum downtime (RTO) and data-loss tolerance (RPO) apply to a planned Country Production Restore window? |
| **4. Options** | *(Superseded.)* Historical: A RTO ≤ 2h · B owner figures · C no formal RTO. |
| **5. Consequences** | No hardcoded RTO. Estimated Duration is operational monitoring only; does not redefine survivor/Global integrity (RPO = 0 for non-target remains architectural). |
| **6. Recommended** | *(Historical facilitation superseded.)* |
| **7. Reason** | Duration depends on actual workload; hardcoding misleads operators. |
| **8–11** | Ops monitoring only; failure/rollback policy remains separate (OD-FAIL-*). |
| **12. Required before** | P1: yes (frozen shape). Implementation: yes. Certification: soft. Enablement: soft. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | **No fixed Recovery Time Objective** shall be hardcoded. The system shall automatically calculate an **Estimated Duration** for every job based on the actual workload. The calculated estimate is used for **operational monitoring only**. |
| **15. Frozen policy wording** | *No fixed RTO is hardcoded for Country Production Restore. Each job receives an automatic Estimated Duration based on actual workload. That estimate is for operational monitoring only and must not be treated as a hard fail deadline (see OD-TIMEOUT).* |

---

### OD-TIMEOUT — Phase timeouts

| Field | Content |
|-------|---------|
| **1. ID** | OD-TIMEOUT |
| **2. Title** | Timeout / escalation model for CPR execution |
| **3. Exact question** | What timeouts apply to approvals waiting, pre-PONR idle with maint ON, and worker heartbeat interval? |
| **4. Options** | *(Superseded.)* Historical fixed soft-cancel/idle numbers replaced by progress-aware escalation. |
| **5. Consequences** | Timeout does **not** automatically mean failure. Progress (heartbeat, batches, imported rows, etc.) may allow continuation past estimate. |
| **6. Recommended** | *(Historical facilitation superseded.)* |
| **7. Reason** | Long-running CLI restore must not fail solely because wall-clock exceeded an estimate. |
| **8–11** | Ops; post-PONR never auto-cancel solely on elapsed time; pairs with OD-MAINT-MAX / OD-RTO estimates. |
| **12. Required before** | P1: yes (frozen shape). Implementation: yes. Certification: soft. Enablement: soft. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Timeout does **NOT** automatically mean failure. Workflow: Estimated Duration → Warning Threshold → Critical Threshold → Recovery Investigation → Resume (when supported). A job must **never** fail simply because elapsed time exceeded the estimate. Timeout handling must consider runtime progress. If the job continues to make measurable progress (heartbeat, completed batches, imported rows, etc.), execution may continue. Only **lack of progress together with timeout escalation** may trigger recovery procedures. |
| **15. Frozen policy wording** | *CPR timeout handling is progress-aware: Estimated Duration → Warning Threshold → Critical Threshold → Recovery Investigation → Resume (when supported). Exceeding the Estimated Duration alone must never fail the job. Measurable progress (heartbeat, completed batches, imported rows, and equivalent signals) permits continued execution. Recovery procedures may start only when lack of progress coincides with timeout escalation.* |

---

### OD-RUNBOOK — Human sign-offs

| Field | Content |
|-------|---------|
| **1. ID** | OD-RUNBOOK |
| **2. Title** | Required human sign-offs in the CPR runbook |
| **3. Exact question** | Which human sign-offs are mandatory before PONR and before maintenance release? |
| **4. Options** | **A)** Align to OD-DUAL: Workflow A — Super Admin checklist + phrase/`RESTORE`/re-auth before execute; Workflow B — Country Admin prep sign-off then Super Admin approval/execute; post-success Super Admin maint release. **B)** Operator only. **C)** Owner-defined list. |
| **5. Consequences** | Must match OWNER_APPROVED OD-DUAL (not dual Super Admin). B: weak. |
| **6. Recommended** | **A** (align to frozen OD-DUAL) |
| **7. Reason** | Explicit operational accountability under Super Admin / Country Admin model. |
| **8–11** | Process. |
| **12. Required before** | P1: soft. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

## Group C — Backup and rollback

### OD-PIN — Session Full Backup create / verify / pin

| Field | Content |
|-------|---------|
| **1. ID** | OD-PIN |
| **2. Title** | New Full Backup as CPR rollback anchor (create, verify, pin) |
| **3. Exact question** | Must a verified Full pre-restore backup be retention-pinned for the CPR job duration (and until rollback window closes), refusing PONR if pin fails? |
| **4. Options** | *(Superseded by owner policy.)* Historical: A pin mandatory · B best-effort · C no pin. |
| **5. Consequences** | Every CPR session creates a **new** Full Backup under Maintenance; existing backups never reused; PONR forbidden without verified+pinned session anchor. |
| **6. Recommended** | *(Historical A superseded by stronger owner workflow.)* |
| **7. Reason** | Fail closed; rollback path bound to this restore session only. |
| **8. Security** | Protects recovery path; prevents silent reuse of stale anchors. |
| **9. Data integrity** | Enables platform restore to the pre-mutation point for **this** session. |
| **10. Operational** | Disk cost of a fresh Full Backup per CPR; aligns with OD-MAINT GLOBAL. |
| **11. Rollback** | **Primary rollback depends on this session’s pinned Full Backup.** |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Every Country Production Restore **MUST** begin by **automatically creating a NEW Full Backup**. Existing backups shall **never** be reused for this purpose, regardless of age or validity. Workflow: (1) Enter Maintenance Mode; (2) Automatically create a fresh Full Backup; (3) Verify backup integrity; (4) Pin the backup; (5) Continue only after successful completion. Production Restore must never begin without a newly created, verified and pinned Full Backup generated specifically for that restore session. |
| **15. Frozen policy wording** | *Every Country Production Restore session must automatically create a new Full Backup after Maintenance Mode is entered. Existing backups must never be reused as the CPR rollback anchor. The session Full Backup must be integrity-verified and retention-pinned before Production Restore mutation continues. CPR must never begin without that newly created, verified, and pinned Full Backup.* |

---

### OD-ROLLBACK — Rollback authority and controls (P0 catalog: OD-ROLLBACK-CLI)

| Field | Content |
|-------|---------|
| **1. ID** | OD-ROLLBACK (historical P0 catalog ID: **OD-ROLLBACK-CLI**) |
| **2. Title** | Dedicated Super Admin dashboard Rollback action for failed CPR sessions |
| **3. Exact question** | After CPR PONR failure, should rollback invoke the existing Full DR rollback worker against the pinned Full anchor, or a CPR-specific wrapper that only orchestrates the same Full rollback? |
| **4. Options** | *(Superseded.)* Historical CLI-shape options replaced by owner dashboard-action model. |
| **5. Consequences** | Dedicated Rollback action on Super Admin dashboard; Super Admin only; visible/available only when CPR session is paused because of failure; never automatic; same security controls as Production Restore; Country Admins never; target is OD-PIN session Full Backup. |
| **6. Recommended** | *(Historical CLI wrapper recommendation superseded for authority; Full-anchor primary rollback philosophy remains.)* |
| **7. Reason** | Explicit Super Admin decision via dedicated UI action; security parity with Production Restore; aligns OD-DUAL / OD-PHRASE / OD-FAIL-*. |
| **8–10** | Security: re-auth + phrase + permissions + complete audit + complete execution logging. Integrity: Full Backup from this session. Ops: dashboard action only when paused on failure. |
| **11. Rollback** | Defines who/how/when; uses OD-PIN session Full Backup; never automatic. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20; wording refined) |
| **14. Final owner answer** | The Super Admin dashboard shall provide a **dedicated Rollback action** for failed Country Production Restore sessions. This action shall be **visible only to the Super Admin**. The Rollback action becomes available **only when a Production Restore session is paused because of failure**. Executing Rollback shall require the **same security controls** as Production Restore, including: re-authentication; confirmation phrase; permission validation; complete audit logging; complete execution logging. Country Admins must **never** have access to this action. Rollback is **never** executed automatically. Rollback always requires an **explicit Super Admin decision**. |
| **15. Frozen policy wording** | *The Super Admin dashboard provides a dedicated Rollback action for failed Country Production Restore sessions. The action is visible only to the Super Admin and becomes available only when a Production Restore session is paused because of failure. Executing Rollback requires the same security controls as Production Restore: re-authentication, confirmation phrase, permission validation, complete audit logging, and complete execution logging. Country Admins must never have access to this action. Rollback is never executed automatically and always requires an explicit Super Admin decision. Rollback targets the Full Backup created and pinned for the current restore session (OD-PIN).* |

---

### OD-FAIL-DELETE — Delete-phase failure policy

| Field | Content |
|-------|---------|
| **1. ID** | OD-FAIL-DELETE |
| **2. Title** | Recovery when target-slice DELETE fails mid-way |
| **3. Exact question** | If production target-slice DELETE fails after PONR has started, what is the mandatory recovery? |
| **4. Options** | *(Superseded.)* Historical auto-finish-delete / immediate rollback options replaced by pause-for-Super-Admin. |
| **5. Consequences** | No automatic rollback. Maintenance stays ON. Super Admin chooses Resume (when supported) or Rollback to session Full Backup. |
| **6. Recommended** | *(Historical facilitation superseded.)* |
| **7. Reason** | Explicit Super Admin decision; avoids silent Full restore; preserves state for investigation. |
| **8–9** | Integrity: never continue blindly; never auto-rollback without Super Admin. |
| **10. Operational** | Pause under GLOBAL Maintenance until Resume or Rollback completes. |
| **11. Rollback** | Available as explicit Super Admin action (OD-ROLLBACK) to OD-PIN session Full Backup — not automatic. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | If Production Restore fails after the target country’s data has been deleted: the system shall **NOT** automatically execute a rollback. Instead: keep Maintenance Mode enabled; preserve the current restore state; display the failure reason; display the completed phase; display execution status. The workflow pauses and waits for an explicit Super Admin decision. Available actions: Resume (when supported by the restore stage); Rollback to the Full Backup created immediately before this Production Restore. |
| **15. Frozen policy wording** | *On delete-phase failure after target data deletion has begun: do not auto-rollback. Keep Maintenance Mode ON, preserve restore state, and surface failure reason, completed phase, and execution status. Pause for explicit Super Admin choice: Resume (only when the stage safely supports it) or Rollback to the session Full Backup (OD-PIN). Users must not regain normal access while the job remains incomplete.* |

---

### OD-FAIL-IMPORT — Import-phase failure policy

| Field | Content |
|-------|---------|
| **1. ID** | OD-FAIL-IMPORT |
| **2. Title** | Recovery when target-slice IMPORT fails |
| **3. Exact question** | If production IMPORT fails mid-batch, choose the default recovery (no SQL byte-offset resume)? |
| **4. Options** | *(Superseded.)* Historical auto re-clear/re-import vs immediate Full rollback replaced by pause-for-Super-Admin. |
| **5. Consequences** | No automatic rollback. Maintenance stays ON. Display progress %, completed batches, failure reason, stage. Super Admin chooses Resume (if safe) or Rollback. |
| **6. Recommended** | *(Historical facilitation superseded.)* |
| **7. Reason** | Explicit Super Admin decision; progress-aware pause; no silent auto-rollback. |
| **8–9** | Integrity: no silent partial country; no unsafe mid-stream resume unless stage supports it. |
| **10. Operational** | Duration monitored via OD-RTO/OD-TIMEOUT; failure pause separate from timeout policy. |
| **11. Rollback** | Explicit Super Admin action (OD-ROLLBACK) to OD-PIN session Full Backup — not automatic. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | If Production Restore fails during data import: the system shall **NOT** automatically execute a rollback. Instead: keep Maintenance Mode enabled; preserve execution state; display progress percentage; display completed batches; display failure reason; display current execution stage. The workflow pauses and waits for an explicit Super Admin decision. Available actions: Resume (only if the current restore stage safely supports continuation); Rollback to the Full Backup created immediately before this Production Restore. |
| **15. Frozen policy wording** | *On import-phase failure: do not auto-rollback. Keep Maintenance Mode ON, preserve execution state, and surface progress percentage, completed batches, failure reason, and current stage. Pause for explicit Super Admin choice: Resume (only if the stage safely supports continuation) or Rollback to the session Full Backup (OD-PIN). Users must not regain normal access while the job remains incomplete.* |

---

## Group D — Package and verification gates

### OD-C8 — C8 Dry Run entry strictness

| Field | Content |
|-------|---------|
| **1. ID** | OD-C8 |
| **2. Title** | Must C8 be SAFE, or may WARNING proceed? |
| **3. Exact question** | May Country Production Restore start only when C8 `overall_result = SAFE`, or may WARNING proceed with written waiver? |
| **4. Options** | **A)** SAFE only. **B)** WARNING allowed with owner waiver per job. **C)** FAIL allowed with waiver. |
| **5. Consequences** | A: strongest. B: flexibility for non-integrity warnings only — must not waive survivor/Global/accounting. C: unsafe. |
| **6. Recommended** | **A** |
| **7. Reason** | No warning acceptance for material risk; C8 SAFE ≠ auth but must be clean entry. |
| **8–9** | Integrity: keep contamination impacts at 0. |
| **10. Operational** | May block marginal packages. |
| **11. Rollback** | N/A pre-PONR. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-VERIFY-WARN — Post-apply verification warnings

| Field | Content |
|-------|---------|
| **1. ID** | OD-VERIFY-WARN |
| **2. Title** | Post-apply soft warnings |
| **3. Exact question** | After production apply, may any verification warning be accepted without rollback for accounting, ownership, stock/FIFO, schema, survivor, or Global integrity? |
| **4. Options** | **A)** No — those categories fail closed → rollback. Non-integrity cosmetic warnings only if explicitly listed later. **B)** Allow warnings with owner waive. **C)** Best-effort accept. |
| **5. Consequences** | A: matches recommendation principles. B/C: integrity risk. |
| **6. Recommended** | **A** |
| **7. Reason** | No warning acceptance for accounting, ownership, stock/FIFO, schema, survivor, or Global integrity. |
| **8–9** | Critical integrity. |
| **10. Operational** | May force rollback more often. |
| **11. Rollback** | Explicit trigger on those fails. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-SCHEMA — Schema revision change process

| Field | Content |
|-------|---------|
| **1. ID** | OD-SCHEMA |
| **2. Title** | Process when schema_revision leaves 121 |
| **3. Exact question** | If live schema_revision changes from 121, must Country CPR re-certify (matrix/expectations/package) before any production job? |
| **4. Options** | **A)** Yes — mandatory re-cert + package rebuild. **B)** Allow mixed revisions with warnings. **C)** Ignore revision. |
| **5. Consequences** | A: fail closed. B/C: drift risk. |
| **6. Recommended** | **A** |
| **7. Reason** | Schema drift is a production blocker; no silent proceed. |
| **8–9** | Integrity. |
| **10. Operational** | Re-cert cost on upgrades. |
| **11. Rollback** | Unaffected. |
| **12. Required before** | P1: soft. Implementation: when revision changes. Certification: yes on change. Enablement: must match cert revision. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-INV — Production inventory method

| Field | Content |
|-------|---------|
| **1. ID** | OD-INV |
| **2. Title** | Certified production inventory capture |
| **3. Exact question** | Before CPR, must impact/gates use a `certified_read_only=true` inventory snapshot, live read-only SELECTs under maintenance, or either? |
| **4. Options** | **A)** Certified snapshot mandatory (capture under controlled window); live SELECT only to refresh/verify snapshot, not as unaudited source. **B)** Live SELECT only under maint. **C)** Uncertified counts OK. |
| **5. Consequences** | A: immutable evidence. B: acceptable if audited. C: unsafe. |
| **6. Recommended** | **A** |
| **7. Reason** | Immutable approvals/reports; aligns C8 certified inventory. |
| **8–9** | Integrity of impact proof. |
| **10. Operational** | Snapshot step in runbook. |
| **11. Rollback** | Snapshot aids forensics. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

## Group E — Uploads

### OD-UPLOADS — Country uploads apply strategy

| Field | Content |
|-------|---------|
| **1. ID** | OD-UPLOADS |
| **2. Title** | How country-scoped uploads are applied on production |
| **3. Exact question** | How should `uploads_country.zip` be applied to production without harming survivor countries’ files? |
| **4. Options** | **A)** Scoped allowlisted paths only: stage → per-file/subtree replace with pre-image snapshot under work root; **never** full `uploads/` root two-phase rename. **B)** Full uploads root two-phase rename (Full DR style). **C)** In-place overwrite without pre-image. |
| **5. Consequences** | A: country-safe. B: contaminates/moves entire tree — **not recommended**. C: weak rollback assist. |
| **6. Recommended** | **A** |
| **7. Reason** | Country-scoped safety; no silent path bleed; pre-image assists file rollback alongside Full anchor. |
| **8. Security** | Path allowlist critical. |
| **9. Data integrity** | Protects survivor files. |
| **10. Operational** | More steps than Full rename. |
| **11. Rollback** | Full anchor primary; pre-image secondary for files. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

*Note: P0 mention `OD-UPLOADS-FULLTREE` is **not** a separate decision — it is option **B** here and is **not recommended**.*

---

## Group F — Locks and concurrency

### OD-LOCK-CROSS — Exclusion vs Full DR

| Field | Content |
|-------|---------|
| **1. ID** | OD-LOCK-CROSS |
| **2. Title** | CPR vs Full Disaster Restore concurrency |
| **3. Exact question** | Must CPR and Full DR restore be mutually exclusive on the same deployment? |
| **4. Options** | **A)** Yes — exclusive (either may hold global restore exclusion). **B)** Allow parallel. **C)** CPR yields to Full only. |
| **5. Consequences** | A: safe. B: catastrophic interaction risk. C: OK if exclusive in practice. |
| **6. Recommended** | **A** |
| **7. Reason** | One production mutation program at a time. |
| **8–9** | Integrity under concurrent cutovers. |
| **10. Operational** | Serialize windows. |
| **11. Rollback** | Avoid interleaved rollbacks. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-LOCK-SHADOW — Exclusion vs C6 Country Shadow

| Field | Content |
|-------|---------|
| **1. ID** | OD-LOCK-SHADOW |
| **2. Title** | CPR vs Country Shadow (C6) concurrency |
| **3. Exact question** | May C6 Country Shadow Restore run concurrently with a CPR production job on the same host/work root? |
| **4. Options** | **A)** Serialize — forbid concurrent C6 while CPR lock/maint held (and vice versa for production job). **B)** Allow parallel (separate DBs). **C)** Allow C6 always. |
| **5. Consequences** | A: simplest ops safety. B: possible if proven isolated — still risks shared backup root/locks. |
| **6. Recommended** | **A** |
| **7. Reason** | Reduce operational confusion and shared work-root races. |
| **8–11** | Ops safety; integrity of gates. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: soft. Enablement: soft. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-LOCK-TTL — Heartbeat and stale locks

| Field | Content |
|-------|---------|
| **1. ID** | OD-LOCK-TTL |
| **2. Title** | Lock heartbeat TTL and stale handling |
| **3. Exact question** | What heartbeat interval and stale TTL apply, and is post-PONR auto-unlock forbidden? |
| **4. Options** | **A)** Heartbeat ≤ 30s; stale detect ~5–15 minutes pre-PONR with documented manual clear if PID dead; **post-PONR no auto-unlock**. **B)** Owner TTLs. **C)** Auto-unlock anytime. |
| **5. Consequences** | A: matches Full DR post-PONR discipline. C: dangerous. |
| **6. Recommended** | **A** |
| **7. Reason** | Replay/concurrency safety; no silent unlock after irreversible start. |
| **8–11** | Ops/incident. |
| **12. Required before** | P1: soft. Implementation: yes. Certification: soft. Enablement: soft. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

## Group G — Final-audit residuals (engine mandates)

### OD-FA-RESOLVER — Matrix resolver precedence

| Field | Content |
|-------|---------|
| **1. ID** | OD-FA-RESOLVER |
| **2. Title** | Membership resolver precedence (CRP Final Audit FA-01) |
| **3. Exact question** | Must the future CPR production engine honor **matrix `ownership_resolver` first**, and never let “table has `country_id` column” override `parent_fk` / `admin_ownership` / other resolvers? |
| **4. Options** | **A)** Yes — matrix-resolver-first mandatory (fail closed on unresolved). **B)** Keep country_id-column short-circuit. **C)** Per-table exceptions list. |
| **5. Consequences** | A: closes FA-01 for production. B: retains architectural footgun. C: needs explicit table list. |
| **6. Recommended** | **A** |
| **7. Reason** | No silent ownership mistakes; C1.1 matrix is law. |
| **8–9** | Critical country isolation / integrity. |
| **10. Operational** | Engine design constraint. |
| **11. Rollback** | Fewer bad applies. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-FA-STOCK — Strict FIFO/stock verification

| Field | Content |
|-------|---------|
| **1. ID** | OD-FA-STOCK |
| **2. Title** | Strict stock/FIFO ownership verification (FA-02) |
| **3. Exact question** | Must CPR post-apply verification enforce warehouse ownership, stock ownership, FIFO graph completeness, and cross-country reference checks with **no** dead/unexecuted predicates? |
| **4. Options** | **A)** Yes — strict suite; fail closed. **B)** Soft warnings OK. **C)** Skip FIFO checks. |
| **5. Consequences** | A: matches principles. B/C: stock integrity risk. |
| **6. Recommended** | **A** |
| **7. Reason** | No warning acceptance for stock/FIFO; multicountry §13. |
| **8–9** | Critical. |
| **10–11** | May force rollback; correct. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-FA-SCHEMA — Strict production schema checks

| Field | Content |
|-------|---------|
| **1. ID** | OD-FA-SCHEMA |
| **2. Title** | No fixture soft-skip on production schema gates (FA-03) |
| **3. Exact question** | On production (and certification clones), must schema expectations enforce revision + required tables/columns/indexes/constraints **without** “zero-index fixture skip” soft-PASS? |
| **4. Options** | **A)** Yes — strict on production/cert clones. Soft-skip allowed only in non-production unit fixtures. **B)** Soft-skip everywhere. **C)** Columns only, never indexes. |
| **5. Consequences** | A: closes FA-03 for prod. B: weak. C: partial. |
| **6. Recommended** | **A** |
| **7. Reason** | No schema warning/soft-pass on production integrity path. |
| **8–9** | Critical. |
| **10–11** | Re-cert on schema change (OD-SCHEMA). |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

## Cross-cutting constraints (not separate OD votes)

These are **already frozen** and must not be contradicted by any OD answer:

1. CLI-only production mutation.  
2. No silent ID remapping / collision resolution.  
3. Immutable approvals and reports.  
4. One-time authorization; fingerprint re-check before PONR.  
5. Replay prevention via job state machine.  
6. C1.1 D1–D6 and multicountry §13.  
7. C3–C8 not modified by CPR program.

---

## Register index

| ID | Group | Recommended | Blocks P1? | Deferrable detail? | Blocks cert? | Blocks enable? |
|----|-------|-------------|:----------:|:------------------:|:------------:|:--------------:|
| OD-ENABLE | A | **OWNER_APPROVED** | Y | N | Y | **Y** |
| OD-DUAL | A | **OWNER_APPROVED** (WF-A/B) | Y | N | Y | Y |
| OD-PHRASE | A | **OWNER_APPROVED** (`RESTORE`) | Y | N | Y | Y |
| OD-BREAK | A | **OWNER_APPROVED** | Y | N | Y | Y |
| OD-PERM | A | A | soft | partial | Y | Y |
| OD-CERT | A | A | soft | Y | **Y** | **Y** |
| OD-MAINT | B | **OWNER_APPROVED** | Y | N | Y | Y |
| OD-MAINT-SCOPE | B | **OWNER_APPROVED** (GLOBAL) | Y | N | Y | Y |
| OD-MAINT-MAX | B | **OWNER_APPROVED** (auto estimate) | Y | N | soft | soft |
| OD-RTO | B | **OWNER_APPROVED** (estimate only) | Y | N | soft | soft |
| OD-TIMEOUT | B | **OWNER_APPROVED** (progress-aware) | Y | N | soft | soft |
| OD-RUNBOOK | B | A | soft | Y | Y | Y |
| OD-PIN | C | **OWNER_APPROVED** (new Full Backup) | Y | N | Y | Y |
| OD-ROLLBACK | C | **OWNER_APPROVED** (dashboard action; fail-pause only) | Y | N | Y | Y |
| OD-FAIL-DELETE | C | **OWNER_APPROVED** (pause; no auto-RB) | Y | N | Y | Y |
| OD-FAIL-IMPORT | C | **OWNER_APPROVED** (pause; no auto-RB) | Y | N | Y | Y |
| OD-C8 | D | A | Y | N | Y | Y |
| OD-VERIFY-WARN | D | A | Y | N | Y | Y |
| OD-SCHEMA | D | A | soft | Y | Y | Y |
| OD-INV | D | A | Y | N | Y | Y |
| OD-UPLOADS | E | A | Y | N | Y | Y |
| OD-LOCK-CROSS | F | A | Y | N | Y | Y |
| OD-LOCK-SHADOW | F | A | Y | N | soft | soft |
| OD-LOCK-TTL | F | A | soft | Y | soft | soft |
| OD-FA-RESOLVER | G | A | Y | N | Y | Y |
| OD-FA-STOCK | G | A | Y | N | Y | Y |
| OD-FA-SCHEMA | G | A | Y | N | Y | Y |

**Total: 27**

---

*End of Owner Decision Register — P0b. Groups 1–3 frozen OWNER_APPROVED (incl. OD-PIN, OD-ROLLBACK, OD-FAIL-DELETE, OD-FAIL-IMPORT + Maintenance State on pause). Remaining statuses PROPOSED. No P1. No implementation.*
