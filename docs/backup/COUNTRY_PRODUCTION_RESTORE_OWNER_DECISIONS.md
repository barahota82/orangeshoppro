# Country Production Restore — Owner Decision Register (Phase P0b)

| Field | Value |
|-------|--------|
| **Status** | Architecture / policy only — **no implementation** |
| **Phase** | P0b — Owner Decision Freeze (register) |
| **Date** | 2026-07-20 |
| **Parent architecture** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (committed `b28abb81`) |
| **Workshop** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` |
| **Dependencies** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` |
| **Super Admin UX clarification** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` (**not** a new OD; does not amend OWNER_APPROVED text) |
| **Global Restore ops clarification** | `docs/backup/GLOBAL_RESTORE_OPERATIONAL_POLICY.md` (**not** a new OD; platform-wide maint UX for any Restore) |
| **C3–C8** | Must not be modified |
| **Enablement** | Remains **disabled** until certification + explicit OD-ENABLE + implementation + final enterprise approval (OWNER_APPROVED) |
| **Last owner freeze** | 2026-07-20 — Final Governance: OD-PERM, OD-RUNBOOK, OD-CERT, OD-LOCK-TTL, OD-SCHEMA + Governance Principle |

### Frozen inputs (not reopened)

| ID | Policy |
|----|--------|
| C1.1 **D1–D6** | Boundary matrix; NULL≠target; sequences special; admins composite; screen-copy Global; `journal_entries` Full-only |
| Multicountry **§13** | Full country separation (stock, GL, parties, sequences) |
| Full DR **OD-2** | Country production restore disabled until Country certification |
| CRP Final Audit | C8 SAFE ≠ cutover auth; FA-01…FA-03 residuals inform OD-FA-* |

### Foundational Owner Principle — Integrity over privilege (OWNER_APPROVED — 2026-07-20)

**OWNER_APPROVED** for the entire Orange platform:

- System Integrity and Data Integrity always have **higher priority** than user privileges.  
- **No user**, including the Super Admin, may bypass any production safety gate.  
- These are **mandatory runtime enforcement rules**, not operational recommendations.  
- The **system itself** must enforce these rules.  
- If a required proof is missing, the requested operation **shall not execute**.  

This principle governs Gates & Integrity and must not be contradicted by later ODs or implementation.

### Foundational Owner Principle — Recovery scope isolation (OWNER_APPROVED — 2026-07-20)

**OWNER_APPROVED** for the entire Orange platform:

- Production Restore shall **never** modify data outside the explicitly approved recovery scope.  
- No operation may endanger survivor countries or unrelated platform resources.  
- If safe isolation cannot be proven, the operation shall **fail**.  

This principle governs Group 3 remaining (OD-UPLOADS) and Group 4 (OD-LOCK-*) and must not be contradicted by later ODs or implementation.

### Foundational Owner Principle — Operational governance (OWNER_APPROVED — 2026-07-20)

**OWNER_APPROVED** for the entire Orange platform:

- Operational governance shall **never** weaken the previously approved Integrity Principle, Isolation Principle, or Global Restore Operational Policy.  
- Governance exists to **enforce** system integrity, not to bypass it.  
- No governance decision may contradict any previously frozen OWNER_APPROVED decision.  

This principle governs OD-PERM, OD-RUNBOOK, OD-CERT, OD-LOCK-TTL, and OD-SCHEMA and must not be contradicted by later ODs or implementation.

### Owner-approved CPR decisions (Group 1 — Enablement & control — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-ENABLE** | OWNER_APPROVED |
| **OD-DUAL** | OWNER_APPROVED (Super Admin / Country Admin workflows — **not** dual Super Admin) |
| **OD-PHRASE** | OWNER_APPROVED (`RESTORE` + password re-auth) |
| **OD-BREAK** | OWNER_APPROVED (Super Admin only; does not bypass anchor/gates/auth/logging) |

### Owner-approved CPR decisions (Group 2 — Maintenance & timing — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-MAINT** | OWNER_APPROVED (Maintenance Mode mandatory before execution) |
| **OD-MAINT-SCOPE** | OWNER_APPROVED (**GLOBAL MAINTENANCE**; Country-only NOT approved under current architecture) |
| **OD-MAINT-MAX** | OWNER_APPROVED (no fixed max; automatic Estimated Duration per job) |
| **OD-RTO** | OWNER_APPROVED (no hardcoded RTO; Estimated Duration for monitoring only) |
| **OD-TIMEOUT** | OWNER_APPROVED (timeout ≠ failure; progress-aware escalation → investigate/resume) |

### Owner-approved CPR decisions (Group 3 — Backup / failure / rollback / uploads — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-PIN** | OWNER_APPROVED (new session Full Backup under Maintenance → verify → pin; never reuse existing) |
| **OD-ROLLBACK** | OWNER_APPROVED (dedicated Super Admin dashboard Rollback action; visible only when session paused on failure; never automatic; never Country Admin) — P0 catalog ID was **OD-ROLLBACK-CLI** |
| **OD-FAIL-DELETE** | OWNER_APPROVED (no auto-rollback; pause for Super Admin Resume/Rollback) |
| **OD-FAIL-IMPORT** | OWNER_APPROVED (no auto-rollback; pause for Super Admin Resume/Rollback) |
| **Maintenance State (on failure pause)** | OWNER_APPROVED (Maintenance stays ON until Super Admin completes Resume or Rollback) |
| **OD-UPLOADS** | OWNER_APPROVED (strictly scoped uploads; pre-image; never full-tree; fail → Global Maint + Resume/Rollback) |

### Owner-approved CPR decisions (Gates & Integrity — workshop Group 2 — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-C8** | OWNER_APPROVED (SAFE only; no WARNING/FAIL waiver; no Super Admin bypass) |
| **OD-VERIFY-WARN** | OWNER_APPROVED (integrity fail-closed → session FAILED; Global Maint stays ON; Resume or Rollback only) |
| **OD-INV** | OWNER_APPROVED (certified immutable inventory snapshot mandatory; live reads verify only) |
| **OD-FA-RESOLVER** | OWNER_APPROVED (certified Ownership Resolver Matrix only; no country_id shortcuts; fail if unproven) |
| **OD-FA-STOCK** | OWNER_APPROVED (mandatory warehouse/stock/FIFO/cross-country checks; no soft warning) |
| **OD-FA-SCHEMA** | OWNER_APPROVED (mandatory revision/tables/columns/indexes/constraints; no fixture soft-skip on prod/cert) |

### Owner-approved CPR decisions (Group 4 — Locks — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-LOCK-CROSS** | OWNER_APPROVED (CPR and Full DR mutually exclusive; no parallel; no override/bypass) |
| **OD-LOCK-SHADOW** | OWNER_APPROVED (CPR and C6 mutually exclusive / serialized; no concurrent shared resources) |
| **OD-LOCK-TTL** | OWNER_APPROVED (heartbeat; Super Admin manual pre-PONR clear only; **no** post-PONR auto-release under any circumstance) |

### Owner-approved CPR decisions (Final Governance — 2026-07-20)

| ID | Status |
|----|--------|
| **OD-PERM** | OWNER_APPROVED (Country Admin view/prepare/request only; Super Admin alone approve/execute/resume/rollback/release maint/enable-disable; aligns OD-DUAL) |
| **OD-RUNBOOK** | OWNER_APPROVED (mandatory pre-PONR Super Admin checklist; fully audited; Global Maint not released until Runbook complete) |
| **OD-CERT** | OWNER_APPROVED (Owner is final PASS/FAIL; engineering evidence only; disabled until PASS + Owner approval + explicit enablement) |
| **OD-SCHEMA** | OWNER_APPROVED (any production schema revision change invalidates cert; rebuild + new cert + new C8 SAFE; no auto re-enable — Owner PASS + Enable again) |

**Note:** P0 architecture §8 previously recommended “two distinct Super Admin identities.” That recommendation is **superseded** by OWNER_APPROVED **OD-DUAL** in this register. Do not implement the old dual-Super-Admin model.

**Note (Group 2 — Maintenance):** P0 architecture §9 prefers platform-wide maintenance — now **OWNER_APPROVED** as GLOBAL MAINTENANCE (OD-MAINT-SCOPE). Country-only maintenance is not approved under the current shared-DB / Full-anchor rollback architecture.

**Note (Group 3):** OD-PIN owner workflow is: Maintenance Mode → **new** Full Backup for this session → verify → pin → continue. Existing backups must never be reused as the CPR rollback anchor. Failure after delete/import does **not** auto-rollback; Super Admin chooses Resume (when safe) or the dedicated dashboard **Rollback** action. P0 catalog **OD-ROLLBACK-CLI** is frozen under owner ID **OD-ROLLBACK**. **OD-UPLOADS** is strictly scoped; full-tree replace is forbidden.

**Note (Gates & Integrity):** Proof-driven production philosophy — Production Restore shall never rely on user judgement, manual override, administrator privilege, or best-effort execution. If integrity cannot be proven, the operation shall not execute. Aligns with `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` and Super Admin Operational Model.

**Note (Group 4 + Isolation Principle):** Production Isolation philosophy — scoped recovery, proven survivor isolation, exclusive execution, zero concurrent recovery operations, zero uncontrolled file replacement. Prefer proven integrity over speed or convenience.

**Note (Final Governance + Governance Principle):** Governance completes the CPR policy layer — integrity before privilege; proof before execution; isolation before convenience; Owner authorization before production enablement. No production restore may rely on assumptions, administrator judgement, privilege overrides, or best-effort execution. Only proven system integrity may authorize execution. All named OD-* in this register are now OWNER_APPROVED.

### Register rules

- Status **OWNER_APPROVED** = frozen owner policy; do not reopen without a new owner decision.  
- All named OD-* decisions in this register are **OWNER_APPROVED** (Final Governance freeze 2026-07-20).  
- Facilitation “Recommended” is historical advice only.  
- Foundational Integrity, Isolation, and Operational Governance Principles are OWNER_APPROVED and bind gates, uploads, locks, permissions, runbook, certification, and schema re-authorization.

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
| **4. Options** | *(Superseded.)* Historical B broader roles and C custom matrix rejected. |
| **5. Consequences** | Permanent permission model aligned to OD-DUAL; Country Admin never mutates production restore control plane. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Fail closed; country isolation; match frozen OD-DUAL; Governance Principle. |
| **8. Security** | Prevents cross-country authorization and Country Admin privilege escalation. |
| **9–11** | Integrity/ops/rollback: Super Admin alone for execute/resume/rollback/maint release/enable-disable. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | The Production Restore permission model is permanently defined as follows. **Country Admin may:** view Country Production Restore status for their own country; prepare C3–C8; request Country Production Restore. **Country Admin shall never:** approve, execute, resume, or rollback Production Restore; release Global Maintenance; enable or disable Production Restore. **Super Admin alone may:** approve, execute, resume Production Restore; execute Rollback; release Global Maintenance; enable or disable Country Production Restore. No additional authority model shall be introduced that contradicts frozen **OD-DUAL**. |
| **15. Frozen policy wording** | *Country Admin may view CPR status for their own country, prepare C3–C8, and request Country Production Restore. Country Admin shall never approve, execute, resume, or rollback Production Restore; never release Global Maintenance; never enable or disable Production Restore. Super Admin alone may approve, execute, and resume Production Restore; execute Rollback; release Global Maintenance; and enable or disable Country Production Restore. No additional authority model may contradict OD-DUAL.* |

---

### OD-CERT — Certification program ownership

| Field | Content |
|-------|---------|
| **1. ID** | OD-CERT |
| **2. Title** | Country Production certification evidence pack |
| **3. Exact question** | Who owns the Country Production certification checklist, and must certification PASS before any enablement? |
| **4. Options** | *(Superseded.)* Historical B engineering self-cert and C skip formal cert rejected. |
| **5. Consequences** | Owner is final PASS/FAIL; engineering never grants final certification approval; CPR stays disabled until PASS + Owner approval + explicit enablement. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Explicit enablement after proof; Governance Principle; aligns OD-ENABLE. |
| **8–11** | Security/integrity/ops/rollback: cert proves drills including rollback; Owner alone closes PASS. |
| **12. Required before** | P1: yes (frozen). Implementation: before cert phase. **Certification: blocks.** **Enablement: blocks.** |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | The Owner is the final **PASS / FAIL** authority for Country Production Restore Certification. Engineering is responsible for producing technical evidence, verification reports, and certification artifacts. Engineering shall **never** grant the final certification approval. Production Restore shall remain disabled until: Certification PASS; explicit Owner approval; explicit Production Enablement. |
| **15. Frozen policy wording** | *The Owner is the final PASS/FAIL authority for Country Production Restore Certification. Engineering produces technical evidence, verification reports, and certification artifacts, and shall never grant final certification approval. Production Restore remains disabled until Certification PASS, explicit Owner approval, and explicit Production Enablement.* |

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
| **4. Options** | *(Superseded.)* Historical B operator-only and C custom list rejected as primary policy. |
| **5. Consequences** | Mandatory audited Super Admin pre-PONR checklist; Global Maint not released until Runbook complete. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED with explicit minimum checklist.)* |
| **7. Reason** | Explicit operational accountability; Governance Principle; aligns OD-DUAL / Super Admin model. |
| **8–11** | Process + audit; maint release gated on Runbook completion. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Every Production Restore shall follow a **mandatory** operational Runbook. Before PONR, the Super Admin shall explicitly complete the operational checklist. The checklist shall include at minimum: Restore Package ID; Target Country; C8 Overall Result = SAFE; Certified Inventory Snapshot; Session Full Backup ID; verification that Global Maintenance is active. The Runbook shall be fully audited. Global Maintenance shall **never** be released until the Runbook has been successfully completed. |
| **15. Frozen policy wording** | *Every Production Restore follows a mandatory operational Runbook. Before PONR, the Super Admin must explicitly complete an audited checklist including at minimum: Restore Package ID; Target Country; C8 Overall Result = SAFE; Certified Inventory Snapshot; Session Full Backup ID; verification that Global Maintenance is active. Global Maintenance shall never be released until the Runbook has been successfully completed.* |

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
| **4. Options** | *(Superseded.)* Historical: A SAFE only · B WARNING + waiver · C FAIL + waiver. |
| **5. Consequences** | SAFE only; no overrides; package must be corrected and C8 re-run. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED with zero-bypass.)* |
| **7. Reason** | Integrity Principle: no Super Admin bypass of missing proof. |
| **8–9** | Integrity: contamination impacts must stay at 0. |
| **10. Operational** | CPR unavailable until C8 SAFE. |
| **11. Rollback** | N/A pre-start. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Production Restore may begin **only** when C8 Overall Result = **SAFE**. **No** WARNING override. **No** FAIL override. **No** waiver. **No** Continue Anyway. **No** Super Admin bypass. If C8 is not SAFE: Production Restore shall **not** start; the package must be corrected; C8 must be executed again; Production Restore becomes available only after C8 returns SAFE. |
| **15. Frozen policy wording** | *Production Restore may begin only when C8 Overall Result equals SAFE. WARNING override, FAIL override, waiver, Continue Anyway, and Super Admin bypass are forbidden. If C8 is not SAFE, Production Restore must not start; the package must be corrected and C8 re-executed; Production Restore becomes available only after C8 returns SAFE.* |

---

### OD-VERIFY-WARN — Post-apply verification warnings

| Field | Content |
|-------|---------|
| **1. ID** | OD-VERIFY-WARN |
| **2. Title** | Post-apply integrity verification (fail-closed) |
| **3. Exact question** | After production apply, may any verification warning be accepted without rollback for accounting, ownership, stock/FIFO, schema, survivor, or Global integrity? |
| **4. Options** | *(Superseded.)* Historical soft-accept / waive options rejected. |
| **5. Consequences** | Integrity failure → session FAILED; Global Maintenance remains; Super Admin Resume or Rollback only. |
| **6. Recommended** | *(Historical A strengthened to OWNER_APPROVED zero-override.)* |
| **7. Reason** | Integrity Principle + Global Restore Operational Policy. |
| **8–9** | Critical integrity. |
| **10. Operational** | Storefronts and Country Admins stay unavailable; Super Admin Restore Management only. |
| **11. Rollback** | Explicit Super Admin Rollback (OD-ROLLBACK); not automatic; not “success with warnings.” |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Post-apply integrity verification is **fail-closed**. Any integrity failure involving Accounting, Ownership, FIFO, Stock, Schema, Survivor integrity, or Global integrity shall **immediately** mark the Production Restore session as **FAILED**. Platform remains in Global Maintenance. Normal operation shall **not** resume. Customer storefronts and Country Admin dashboards remain unavailable. Only Super Admin Restore Management remains available. Super Admin may choose only **Resume** (when stage safely supports continuation) or **Rollback**. Forbidden: Success with warnings; Ignore verification; Accept anyway; override. |
| **15. Frozen policy wording** | *Post-apply integrity verification is fail-closed. Failure in accounting, ownership, FIFO, stock, schema, survivor integrity, or Global integrity immediately marks the session FAILED, keeps Global Maintenance ON, and keeps storefronts and Country Admin dashboards unavailable. Only the Super Admin Restore Management interface remains available, and only Resume (when safely supported) or Rollback may be chosen. Success with warnings, ignore verification, accept anyway, and any override are forbidden.* |

---

### OD-SCHEMA — Schema revision change process

| Field | Content |
|-------|---------|
| **1. ID** | OD-SCHEMA |
| **2. Title** | Process when schema_revision leaves 121 |
| **3. Exact question** | If live schema_revision changes from 121, must Country CPR re-certify (matrix/expectations/package) before any production job? |
| **4. Options** | *(Superseded.)* Historical B mixed revisions with warnings and C ignore revision rejected. |
| **5. Consequences** | Any production schema revision change invalidates prior CPR certification; full new authorization cycle required; no auto re-enable. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Schema drift is a production blocker; aligns OD-FA-SCHEMA / Integrity / OD-CERT / OD-ENABLE. |
| **8–9** | Integrity. |
| **10. Operational** | Re-cert + Owner PASS + explicit Enable again on every major schema evolution. |
| **11. Rollback** | Unaffected. |
| **12. Required before** | P1: yes (frozen). Implementation: when revision changes. Certification: yes on change. Enablement: must match cert revision + new Enable. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Any Production Schema Revision change **invalidates** the previous Country Production Restore certification. Before Production Restore may be used again, the following are mandatory: package rebuild; new Certification; new C8 SAFE verification. Successful completion of those steps shall **NOT** automatically re-enable Production Restore. Production Restore shall remain disabled until: the Owner explicitly reviews the new certification; the Owner grants PASS; the Owner explicitly performs Enable again. Every major schema evolution therefore requires a completely new production authorization cycle. |
| **15. Frozen policy wording** | *Any Production Schema Revision change invalidates the previous Country Production Restore certification. Before CPR may be used again: package rebuild, new Certification, and new C8 SAFE verification are mandatory. Those steps do not automatically re-enable Production Restore. CPR remains disabled until the Owner explicitly reviews the new certification, grants PASS, and explicitly performs Enable again. Every major schema evolution requires a completely new production authorization cycle.* |

---

### OD-INV — Production inventory method

| Field | Content |
|-------|---------|
| **1. ID** | OD-INV |
| **2. Title** | Certified immutable production inventory snapshot |
| **3. Exact question** | Before CPR, must impact/gates use a `certified_read_only=true` inventory snapshot, live read-only SELECTs under maintenance, or either? |
| **4. Options** | *(Superseded.)* Historical: A certified snapshot · B live SELECT only · C uncertified. |
| **5. Consequences** | Certified immutable snapshot mandatory; live reads verify only; bound to session for audit/forensics. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Proof-driven; immutable evidence for approvals and forensics. |
| **8–9** | Integrity of impact proof. |
| **10. Operational** | Snapshot step before Production Restore mutation. |
| **11. Rollback** | Snapshot retained for forensic investigation. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | A **Certified Immutable Production Inventory Snapshot** is **mandatory** before every Production Restore. The snapshot shall be: read-only; certified; immutable; cryptographically bound to the restore session; retained for audit; retained for forensic investigation. Live database reads may **only verify** the certified snapshot; they shall **never replace** it. |
| **15. Frozen policy wording** | *Before every Production Restore, a Certified Immutable Production Inventory Snapshot is mandatory. It must be read-only, certified, immutable, cryptographically bound to the restore session, and retained for audit and forensic investigation. Live database reads may only verify that snapshot and must never replace it.* |

---

## Group E — Uploads

### OD-UPLOADS — Country uploads apply strategy

| Field | Content |
|-------|---------|
| **1. ID** | OD-UPLOADS |
| **2. Title** | How country-scoped uploads are applied on production |
| **3. Exact question** | How should `uploads_country.zip` be applied to production without harming survivor countries' files? |
| **4. Options** | *(Superseded.)* Historical B full-tree and C no-pre-image rejected. |
| **5. Consequences** | Strictly scoped restore + pre-image; fail → Global Maint + Resume/Rollback only. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Isolation Principle; survivor uploads must remain untouched. |
| **8. Security** | Path allowlist / approved recovery scope critical. |
| **9. Data integrity** | Protects survivor files; no uncontrolled replacement. |
| **10. Operational** | Scoped apply under GLOBAL Maint. |
| **11. Rollback** | Full session Backup primary; scoped pre-image assists; Super Admin Resume/Rollback on fail. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Country uploads shall always be restored using a **strictly scoped** strategy. The system shall **NEVER**: replace the entire uploads tree; delete uploads belonging to other countries; modify files outside the approved recovery scope. Before applying uploads, the system shall create a **scoped pre-image** of every file that may be modified. Only the target country's approved upload scope may be restored. Survivor-country uploads shall remain untouched. If upload integrity cannot be guaranteed: Production Restore shall **immediately fail**; platform remains in Global Maintenance; Super Admin only Resume (when safely supported) or Rollback. **No** best-effort upload recovery. **No** partial acceptance. |
| **15. Frozen policy wording** | *Country uploads are restored only with a strictly scoped strategy. Full uploads-tree replace, deletion of other countries' uploads, and modification outside the approved recovery scope are forbidden. Before apply, a scoped pre-image of every file that may be modified is mandatory. Only the target country's approved upload scope may be restored; survivor-country uploads remain untouched. If upload integrity cannot be guaranteed, Production Restore fails immediately, Global Maintenance remains ON, and the Super Admin may choose only Resume (when safely supported) or Rollback. Best-effort upload recovery and partial acceptance are forbidden.* |

*Note: P0 mention `OD-UPLOADS-FULLTREE` (full-tree rename) is **rejected** by this OWNER_APPROVED decision.*

---

### OD-LOCK-CROSS — Exclusion vs Full DR

| Field | Content |
|-------|---------|
| **1. ID** | OD-LOCK-CROSS |
| **2. Title** | CPR vs Full Disaster Restore concurrency |
| **3. Exact question** | Must CPR and Full DR restore be mutually exclusive on the same deployment? |
| **4. Options** | *(Superseded.)* Historical parallel options rejected. |
| **5. Consequences** | Mutually exclusive; active job blocks the other until complete; no override/bypass. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Isolation Principle; one production mutation program at a time. |
| **8–9** | Integrity under concurrent cutovers. |
| **10. Operational** | Serialize windows. |
| **11. Rollback** | Avoid interleaved rollbacks. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Country Production Restore and Full Disaster Recovery shall be **mutually exclusive**. The platform shall never execute both simultaneously. If one is active, the other is **blocked** until the active operation has completely finished. **No** override. **No** parallel execution. **No** Super Admin bypass. |
| **15. Frozen policy wording** | *Country Production Restore and Full Disaster Recovery are mutually exclusive. They must never run simultaneously. If one is active, the other is blocked until the active operation has completely finished. Override, parallel execution, and Super Admin bypass are forbidden.* |

---

### OD-LOCK-SHADOW — Exclusion vs C6 Country Shadow

| Field | Content |
|-------|---------|
| **1. ID** | OD-LOCK-SHADOW |
| **2. Title** | CPR vs Country Shadow (C6) concurrency |
| **3. Exact question** | May C6 Country Shadow Restore run concurrently with a CPR production job on the same host/work root? |
| **4. Options** | *(Superseded.)* Historical parallel options rejected. |
| **5. Consequences** | Serialized / mutually exclusive on same production deployment; shared resources never concurrent. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Isolation Principle; shared work roots/DBs/verification contexts. |
| **8–11** | Ops safety; integrity of gates. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Country Production Restore and Country Shadow (C6) shall be **mutually exclusive** and **serialized**. The platform shall never allow them to execute simultaneously on the same production deployment. Shared runtime resources, working directories, databases, and verification contexts shall never be used concurrently. If one is already running, the second shall be **refused**. **No** override. **No** parallel execution. |
| **15. Frozen policy wording** | *Country Production Restore and Country Shadow (C6) are mutually exclusive and must be serialized. They must never execute simultaneously on the same production deployment. Shared runtime resources, working directories, databases, and verification contexts must never be used concurrently. If one is already running, the second is refused. Override and parallel execution are forbidden.* |

---

### OD-LOCK-TTL — Heartbeat and stale locks

| Field | Content |
|-------|---------|
| **1. ID** | OD-LOCK-TTL |
| **2. Title** | Lock heartbeat TTL and stale handling |
| **3. Exact question** | What heartbeat interval and stale TTL apply, and is post-PONR auto-unlock forbidden? |
| **4. Options** | *(Superseded.)* Historical C auto-unlock anytime rejected. |
| **5. Consequences** | Heartbeat monitoring; Super Admin–only audited pre-PONR manual clear; post-PONR auto-release permanently forbidden under every circumstance. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | System Integrity > automatic recovery; aligns OD-LOCK-CROSS/SHADOW and Isolation Principle. |
| **8–11** | Ops/incident; post-PONR stuck lock requires human Super Admin path, never silent unlock. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Restore execution locks shall use **heartbeat monitoring**. **Pre-PONR:** stale locks may be manually cleared **only** by the Super Admin; every manual unlock shall be fully audited. **Post-PONR:** automatic lock release is **permanently forbidden**. No timeout, worker failure, crash, or other circumstance shall automatically release a post-PONR lock. System Integrity has higher priority than automatic recovery. |
| **15. Frozen policy wording** | *Restore execution locks use heartbeat monitoring. Pre-PONR, stale locks may be manually cleared only by the Super Admin, and every manual unlock must be fully audited. Post-PONR, automatic lock release is permanently forbidden — no timeout, worker failure, crash, or other circumstance may automatically release a post-PONR lock. System Integrity has higher priority than automatic recovery.* |

---

## Group G — Final-audit residuals (engine mandates)

### OD-FA-RESOLVER — Matrix resolver precedence

| Field | Content |
|-------|---------|
| **1. ID** | OD-FA-RESOLVER |
| **2. Title** | Membership resolver precedence (CRP Final Audit FA-01) |
| **3. Exact question** | Must the future CPR production engine honor **matrix `ownership_resolver` first**, and never let “table has `country_id` column” override `parent_fk` / `admin_ownership` / other resolvers? |
| **4. Options** | *(Superseded.)* Historical B/C rejected. |
| **5. Consequences** | Certified Ownership Resolver Matrix only; fail before execution if unproven; no best-effort. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | C1.1 matrix is law; Integrity Principle forbids guessing. |
| **8–9** | Critical country isolation / integrity. |
| **10. Operational** | Engine must refuse unproven membership. |
| **11. Rollback** | Fail before execution when possible. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Production Restore shall **always** use the certified Ownership Resolver Matrix. It shall **never** guess ownership. It shall **never** fall back to country_id shortcuts. If ownership cannot be proven with certainty: Production Restore shall **fail before execution**. The system shall **never** attempt a best-effort ownership decision. |
| **15. Frozen policy wording** | *Production Restore must always use the certified Ownership Resolver Matrix. Guessing ownership and country_id shortcuts are forbidden. If ownership cannot be proven with certainty, Production Restore must fail before execution. Best-effort ownership decisions are forbidden.* |

---

### OD-FA-STOCK — Strict FIFO/stock verification

| Field | Content |
|-------|---------|
| **1. ID** | OD-FA-STOCK |
| **2. Title** | Strict stock/FIFO ownership verification (FA-02) |
| **3. Exact question** | Must CPR post-apply verification enforce warehouse ownership, stock ownership, FIFO graph completeness, and cross-country reference checks with **no** dead/unexecuted predicates? |
| **4. Options** | *(Superseded.)* Historical soft-warning / skip rejected. |
| **5. Consequences** | Mandatory warehouse/stock/FIFO/cross-country checks; any failure fails the session. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Multicountry §13; Integrity Principle. |
| **8–9** | Critical. |
| **10–11** | Session FAILED → Global Maint + Super Admin Resume/Rollback path (OD-VERIFY-WARN). |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Stock and FIFO verification are **mandatory**. Required: warehouse ownership; stock ownership; FIFO integrity; cross-country stock references. Any verification failure shall **immediately fail** the Production Restore session. **No** soft warning. **No** ignore mode. **No** best-effort completion. |
| **15. Frozen policy wording** | *Stock and FIFO verification are mandatory, including warehouse ownership, stock ownership, FIFO integrity, and cross-country stock references. Any failure immediately fails the Production Restore session. Soft warning, ignore mode, and best-effort completion are forbidden.* |

---

### OD-FA-SCHEMA — Strict production schema checks

| Field | Content |
|-------|---------|
| **1. ID** | OD-FA-SCHEMA |
| **2. Title** | No fixture soft-skip on production schema gates (FA-03) |
| **3. Exact question** | On production (and certification clones), must schema expectations enforce revision + required tables/columns/indexes/constraints **without** “zero-index fixture skip” soft-PASS? |
| **4. Options** | *(Superseded.)* Historical soft-skip / columns-only rejected for prod/cert. |
| **5. Consequences** | Mandatory schema revision + tables/columns/indexes/constraints; mismatch fails session; no fixture soft-skip on prod/cert. |
| **6. Recommended** | *(Historical A; now OWNER_APPROVED.)* |
| **7. Reason** | Schema integrity over execution; Integrity Principle. |
| **8–9** | Critical. |
| **10–11** | Ties to future OD-SCHEMA on revision change; fail closed. |
| **12. Required before** | P1: yes (frozen). Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | **OWNER_APPROVED** (2026-07-20) |
| **14. Final owner answer** | Production Schema verification is **mandatory**. Verification shall include: Schema Revision; required tables; required columns; required indexes; required constraints. Any mismatch shall **immediately fail** the Production Restore session. Fixture-style soft skip is **never** permitted in Production or Certification environments. Schema integrity shall always take priority over execution. |
| **15. Frozen policy wording** | *Production schema verification is mandatory and must include schema revision, required tables, columns, indexes, and constraints. Any mismatch immediately fails the Production Restore session. Fixture-style soft skip is never permitted in Production or Certification environments. Schema integrity always takes priority over execution.* |

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
8. **Integrity Principle (OWNER_APPROVED):** System/Data Integrity > user privileges; no Super Admin bypass of production safety gates; system-enforced; missing proof → do not execute.  
9. **Proof-driven Production Restore:** never rely on user judgement, manual override, administrator privilege, or best-effort execution.  
10. **Isolation Principle (OWNER_APPROVED):** never modify outside approved recovery scope; never endanger survivors/unrelated resources; fail if safe isolation unproven.  
11. **Production Isolation:** scoped recovery; exclusive execution; zero concurrent recovery ops; zero uncontrolled file replacement.  
12. **Governance Principle (OWNER_APPROVED):** governance never weakens Integrity, Isolation, or Global Restore Operational Policy; governance enforces integrity and must not contradict prior OWNER_APPROVED decisions.  
13. **Governance layer complete:** OD-PERM · OD-RUNBOOK · OD-CERT · OD-LOCK-TTL · OD-SCHEMA are OWNER_APPROVED; CPR remains disabled until cert PASS + Owner approval + explicit enablement; schema revision change requires a full new authorization cycle.

---

## Register index

| ID | Group | Recommended | Blocks P1? | Deferrable detail? | Blocks cert? | Blocks enable? |
|----|-------|-------------|:----------:|:------------------:|:------------:|:--------------:|
| OD-ENABLE | A | **OWNER_APPROVED** | Y | N | Y | **Y** |
| OD-DUAL | A | **OWNER_APPROVED** (WF-A/B) | Y | N | Y | Y |
| OD-PHRASE | A | **OWNER_APPROVED** (`RESTORE`) | Y | N | Y | Y |
| OD-BREAK | A | **OWNER_APPROVED** | Y | N | Y | Y |
| OD-PERM | A | **OWNER_APPROVED** (OD-DUAL capabilities) | Y | N | Y | Y |
| OD-CERT | A | **OWNER_APPROVED** (Owner PASS/FAIL) | Y | N | **Y** | **Y** |
| OD-MAINT | B | **OWNER_APPROVED** | Y | N | Y | Y |
| OD-MAINT-SCOPE | B | **OWNER_APPROVED** (GLOBAL) | Y | N | Y | Y |
| OD-MAINT-MAX | B | **OWNER_APPROVED** (auto estimate) | Y | N | soft | soft |
| OD-RTO | B | **OWNER_APPROVED** (estimate only) | Y | N | soft | soft |
| OD-TIMEOUT | B | **OWNER_APPROVED** (progress-aware) | Y | N | soft | soft |
| OD-RUNBOOK | B | **OWNER_APPROVED** (mandatory checklist) | Y | N | Y | Y |
| OD-PIN | C | **OWNER_APPROVED** (new Full Backup) | Y | N | Y | Y |
| OD-ROLLBACK | C | **OWNER_APPROVED** (dashboard action; fail-pause only) | Y | N | Y | Y |
| OD-FAIL-DELETE | C | **OWNER_APPROVED** (pause; no auto-RB) | Y | N | Y | Y |
| OD-FAIL-IMPORT | C | **OWNER_APPROVED** (pause; no auto-RB) | Y | N | Y | Y |
| OD-C8 | D | **OWNER_APPROVED** (SAFE only) | Y | N | Y | Y |
| OD-VERIFY-WARN | D | **OWNER_APPROVED** (fail-closed) | Y | N | Y | Y |
| OD-SCHEMA | D | **OWNER_APPROVED** (invalidate + re-auth cycle) | Y | N | Y | Y |
| OD-INV | D | **OWNER_APPROVED** (certified snapshot) | Y | N | Y | Y |
| OD-UPLOADS | E | **OWNER_APPROVED** (scoped + pre-image) | Y | N | Y | Y |
| OD-LOCK-CROSS | F | **OWNER_APPROVED** (exclusive vs Full DR) | Y | N | Y | Y |
| OD-LOCK-SHADOW | F | **OWNER_APPROVED** (exclusive vs C6) | Y | N | Y | Y |
| OD-LOCK-TTL | F | **OWNER_APPROVED** (no post-PONR auto-unlock) | Y | N | Y | Y |
| OD-FA-RESOLVER | G | **OWNER_APPROVED** | Y | N | Y | Y |
| OD-FA-STOCK | G | **OWNER_APPROVED** | Y | N | Y | Y |
| OD-FA-SCHEMA | G | **OWNER_APPROVED** | Y | N | Y | Y |

**Total: 27** (all OWNER_APPROVED)

---

*End of Owner Decision Register — P0b. All named OD-* decisions and foundational principles are frozen OWNER_APPROVED (Final Governance complete). No PROPOSED OD-* remain in this register. No P1. No implementation.*
