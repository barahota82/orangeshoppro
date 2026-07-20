# Country Production Restore — Owner Workshop Pack (Phase P0b)

| Field | Value |
|-------|--------|
| **Purpose** | Answer OD-* decisions. Do not implement. |
| **Full register** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Dependencies** | `COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` |
| **Architecture** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` |
| **Super Admin UX clarification** | `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` (not a new OD) |
| **Date** | 2026-07-20 |

**Already frozen (do not re-answer):** C1.1 D1–D6 · Multicountry §13 · Country restore stays disabled until certification + explicit enablement.

**Owner-approved (2026-07-20) — do not re-answer:**  
- Group 1: OD-ENABLE · OD-DUAL · OD-PHRASE · OD-BREAK  
- Group 2: OD-MAINT · OD-MAINT-SCOPE · OD-MAINT-MAX · OD-RTO · OD-TIMEOUT  
- Group 3: OD-PIN · OD-ROLLBACK · OD-FAIL-DELETE · OD-FAIL-IMPORT (+ Maintenance State on failure pause)  

**How to answer remaining items:** write one of `A` / `B` / `C` / `D` (or custom text) on `OWNER ANSWER:`.  
Recommended answers are **advice only** for open items.

---

## Group 1 — Enablement & control

### 1. OD-ENABLE — OWNER_APPROVED (2026-07-20)
- **Frozen:** Disabled by default. Enable only after Certification PASS + Explicit Owner enablement + Production Restore implementation completed + Final Enterprise approval.  
- **OWNER ANSWER:** OWNER_APPROVED (as above)

### 2. OD-DUAL — OWNER_APPROVED (2026-07-20)
- **Frozen:** One global Super Admin + Country Admins. **Workflow A:** Super Admin end-to-end → no second approver; still requires Full Rollback Anchor, gates PASS, Maintenance, phrase `RESTORE`, password re-auth, full audit, one-time auth. **Workflow B:** Country Admin prepares C3–C8 only → Pending Super Admin Approval → only Super Admin approves/executes. Country Admin cannot execute. Replaces prior dual-Super-Admin recommendation.  
- **OWNER ANSWER:** OWNER_APPROVED (Workflow A / B)

### 3. OD-PHRASE — OWNER_APPROVED (2026-07-20)
- **Frozen:** Confirmation phrase **`RESTORE`** (mandatory; Super Admin must type it). Required in Workflow A and Workflow B. Password re-authentication mandatory.  
- **OWNER ANSWER:** OWNER_APPROVED (`RESTORE`)

### 4. OD-BREAK — OWNER_APPROVED (2026-07-20)
- **Frozen:** Break Glass = Super Admin only. Emergency reason + full audit + notification required. Does **not** bypass Full Rollback Anchor, mandatory safety gates, logging, or authentication.  
- **OWNER ANSWER:** OWNER_APPROVED

### 5. OD-MAINT — OWNER_APPROVED (2026-07-20)
- **Frozen:** Country Production Restore always requires Maintenance Mode before execution. Maintenance is mandatory.  
- **OWNER ANSWER:** OWNER_APPROVED

### 6. OD-MAINT-SCOPE — OWNER_APPROVED (2026-07-20)
- **Frozen:** **GLOBAL MAINTENANCE.** Country-only Maintenance is NOT approved under the current architecture (shared production DB; Global/Mixed tables; Full pre-restore backup as primary post-PONR rollback; platform-wide maintenance framework and rollback). Future reconsideration only after a proven country-isolated production restore model.  
- **OWNER ANSWER:** OWNER_APPROVED (GLOBAL)

### 7. OD-PIN — OWNER_APPROVED (2026-07-20)
- **Frozen:** Every CPR must auto-create a **NEW** Full Backup (never reuse existing). Workflow: Maintenance Mode → create fresh Full Backup → verify → pin → continue only after success.  
- **OWNER ANSWER:** OWNER_APPROVED

---

## Group 2 — Gates & integrity (answer before P1)

### 8. OD-C8
Allow CPR entry only when C8 = SAFE?
- **Recommended:** A — SAFE only  
- **Alternatives:** B WARNING + per-job waiver · C FAIL + waiver  
- **Consequences:** A cleanest entry. C unsafe.  
- **OWNER ANSWER:** _______________

### 9. OD-VERIFY-WARN
After apply, fail closed (rollback) on accounting / ownership / FIFO / schema / survivor / Global issues — no soft accept?
- **Recommended:** A — Fail closed on those categories  
- **Alternatives:** B owner waive warnings · C best-effort accept  
- **Consequences:** A may rollback more; protects integrity.  
- **OWNER ANSWER:** _______________

### 10. OD-INV
Require certified read-only production inventory snapshot before CPR?
- **Recommended:** A — Certified snapshot mandatory  
- **Alternatives:** B live SELECT only under maint · C uncertified OK  
- **Consequences:** A immutable evidence. C unsafe.  
- **OWNER ANSWER:** _______________

### 11. OD-FA-RESOLVER
Engine must use matrix `ownership_resolver` first (never country_id-column override)?
- **Recommended:** A — Matrix-resolver-first  
- **Alternatives:** B keep column short-circuit · C per-table exceptions  
- **Consequences:** A closes FA-01. B keeps footgun.  
- **OWNER ANSWER:** _______________

### 12. OD-FA-STOCK
Strict stock/FIFO/cross-country verification post-apply, fail closed?
- **Recommended:** A — Strict  
- **Alternatives:** B soft warnings · C skip FIFO  
- **Consequences:** A protects §13 stock separation.  
- **OWNER ANSWER:** _______________

### 13. OD-FA-SCHEMA
On production/cert clones: strict schema expectations, no fixture soft-skip?
- **Recommended:** A — Strict on prod/cert  
- **Alternatives:** B soft-skip everywhere · C columns only  
- **Consequences:** A closes FA-03.  
- **OWNER ANSWER:** _______________

---

## Group 3 — Failure & rollback

### 14. OD-FAIL-DELETE — OWNER_APPROVED (2026-07-20)
- **Frozen:** No automatic rollback after delete-phase failure. Keep Maintenance ON; preserve state; show failure reason, completed phase, execution status; pause for Super Admin: Resume (when supported) or Rollback to session Full Backup.  
- **OWNER ANSWER:** OWNER_APPROVED

### 15. OD-FAIL-IMPORT — OWNER_APPROVED (2026-07-20)
- **Frozen:** No automatic rollback on import failure. Keep Maintenance ON; preserve state; show progress %, completed batches, failure reason, stage; pause for Super Admin: Resume (only if stage safely supports) or Rollback to session Full Backup.  
- **OWNER ANSWER:** OWNER_APPROVED

### 16. OD-ROLLBACK — OWNER_APPROVED (2026-07-20; was OD-ROLLBACK-CLI)
- **Frozen:** Super Admin dashboard provides a **dedicated Rollback action** for failed CPR sessions. Visible only to Super Admin. Available **only when** the session is paused because of failure. Same controls as Production Restore: re-auth, confirmation phrase, permission validation, complete audit logging, complete execution logging. Country Admins never. **Never automatic** — always an explicit Super Admin decision.  
- **OWNER ANSWER:** OWNER_APPROVED

### 16b. Maintenance State on failure pause — OWNER_APPROVED (2026-07-20)
- **Frozen:** While paused for failure, Maintenance Mode remains active. Normal operation returns only after Super Admin successfully completes Resume **or** Rollback. Users must never regain access while restore is incomplete.  
- **OWNER ANSWER:** OWNER_APPROVED

### 17. OD-UPLOADS *(still open)*
Scoped allowlisted uploads apply with pre-image; never full `uploads/` root rename?
- **Recommended:** A — Scoped apply + pre-image  
- **Alternatives:** B full-tree two-phase rename · C in-place no pre-image  
- **Consequences:** B risks other countries’ files.  
- **OWNER ANSWER:** _______________

---

## Group 4 — Locks (answer before P1)

### 18. OD-LOCK-CROSS
CPR and Full DR mutually exclusive?
- **Recommended:** A — Exclusive  
- **Alternatives:** B parallel · C CPR yields to Full only  
- **Consequences:** B dangerous.  
- **OWNER ANSWER:** _______________

### 19. OD-LOCK-SHADOW
Serialize CPR with C6 Country Shadow on same host/work root?
- **Recommended:** A — Serialize  
- **Alternatives:** B parallel if isolated · C always allow C6  
- **Consequences:** A simplest ops safety.  
- **OWNER ANSWER:** _______________

---

## Group 5 — Governance details (may defer past P1 start)

### 20. OD-PHRASE — OWNER_APPROVED (moved from Group 1; do not re-answer)
- See Group 1 item 3. Phrase **`RESTORE`** + password re-auth. Required in Workflow A and B.

### 21. OD-BREAK — OWNER_APPROVED (moved from Group 1; do not re-answer)
- See Group 1 item 4. Super Admin only; does not bypass Full Rollback Anchor, mandatory gates, logging, or authentication.

### 22. OD-PERM *(still open — must align with OD-DUAL)*
Country Admin may prepare C3–C8 / request restore; Super Admin only approves/executes/releases maint; viewers country-scoped?
- **Recommended:** A — Align to OWNER_APPROVED OD-DUAL  
- **Alternatives:** B broader roles · C custom matrix  
- **OWNER ANSWER:** _______________

### 23. OD-RUNBOOK *(still open — must align with OD-DUAL)*
Sign-offs: Workflow A Super Admin end-to-end protections; Workflow B Country Admin prep then Super Admin approval/execute; Super Admin maint release?
- **Recommended:** A — Align to OWNER_APPROVED OD-DUAL  
- **Alternatives:** B operator only · C custom  
- **OWNER ANSWER:** _______________

### 24. OD-CERT
Owner owns certification checklist; engineering fills evidence; PASS required before enablement?
- **Recommended:** A  
- **Alternatives:** B engineering self-cert · C skip formal cert  
- **OWNER ANSWER:** _______________

---

## Group 6 — Timing / duration (Group 2 frozen; remaining open)

### 25. OD-MAINT-MAX — OWNER_APPROVED (2026-07-20)
- **Frozen:** No fixed maximum maintenance duration. Automatic Expected Duration estimate per job (package/SQL/upload size, rows, batches, historical stats, infrastructure performance). No manual duration configuration.  
- **OWNER ANSWER:** OWNER_APPROVED

### 26. OD-TIMEOUT — OWNER_APPROVED (2026-07-20)
- **Frozen:** Timeout ≠ automatic failure. Workflow: Estimated Duration → Warning → Critical → Recovery Investigation → Resume (when supported). Never fail solely because elapsed time exceeded the estimate. Continue if measurable progress (heartbeat, batches, imported rows, etc.). Recovery only when lack of progress + timeout escalation.  
- **OWNER ANSWER:** OWNER_APPROVED

### 27. OD-RTO — OWNER_APPROVED (2026-07-20)
- **Frozen:** No hardcoded RTO. Automatic Estimated Duration per job from actual workload — for **operational monitoring only**.  
- **OWNER ANSWER:** OWNER_APPROVED

### 28. OD-LOCK-TTL *(still open)*
Heartbeat ≤ 30s; stale detect pre-PONR with manual clear if PID dead; **no auto-unlock post-PONR**?
- **Recommended:** A  
- **Alternatives:** B custom TTLs · C auto-unlock anytime  
- **OWNER ANSWER:** _______________

### 29. OD-SCHEMA *(still open)*
If schema_revision leaves 121: mandatory re-cert + package rebuild before CPR?
- **Recommended:** A  
- **Alternatives:** B mixed revisions with warnings · C ignore revision  
- **OWNER ANSWER:** _______________

---

## Sign-off

| Field | Value |
|-------|-------|
| Owner name | _______________ |
| Date | _______________ |
| Group 1 freeze | OWNER_APPROVED 2026-07-20 (OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK) |
| Group 2 freeze | OWNER_APPROVED 2026-07-20 (OD-MAINT, OD-MAINT-SCOPE, OD-MAINT-MAX, OD-RTO, OD-TIMEOUT) |
| Group 3 freeze | OWNER_APPROVED 2026-07-20 (OD-PIN, OD-ROLLBACK, OD-FAIL-DELETE, OD-FAIL-IMPORT + Maintenance State) |
| Workshop complete? | YES / NO (remaining open items) |
| Notes | _______________ |

**Groups 1–3 are frozen.** Continue workshop for open ODs only.  
**Do not begin P1 until remaining P1-blocking ODs are frozen.** **Do not implement.**
