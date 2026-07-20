# Country Production Restore — Owner Workshop Pack (Phase P0b)

| Field | Value |
|-------|--------|
| **Purpose** | Answer OD-* decisions. Do not implement. |
| **Full register** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Dependencies** | `COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` |
| **Architecture** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` |
| **Super Admin UX clarification** | `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` (not a new OD) |
| **Global Restore ops clarification** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` (not a new OD) |
| **Date** | 2026-07-20 |

**Already frozen (do not re-answer):** C1.1 D1–D6 · Multicountry §13 · Country restore stays disabled until certification + explicit enablement.

**Owner-approved (2026-07-20) — do not re-answer:**  
- Foundational Integrity Principle (integrity > privilege; no Super Admin gate bypass; system-enforced)  
- Isolation Principle (recovery scope; survivor safety; fail if isolation unproven)  
- Operational Governance Principle (governance never weakens Integrity/Isolation/Global Restore Policy; enforces integrity; no contradiction of prior OWNER_APPROVED)  
- Group 1: OD-ENABLE · OD-DUAL · OD-PHRASE · OD-BREAK  
- Group 2 (Maintenance & timing): OD-MAINT · OD-MAINT-SCOPE · OD-MAINT-MAX · OD-RTO · OD-TIMEOUT  
- Group 3: OD-PIN · OD-ROLLBACK · OD-FAIL-DELETE · OD-FAIL-IMPORT · OD-UPLOADS (+ Maintenance State on failure pause)  
- Gates & Integrity (workshop Group 2): OD-C8 · OD-VERIFY-WARN · OD-INV · OD-FA-RESOLVER · OD-FA-STOCK · OD-FA-SCHEMA  
- Group 4: OD-LOCK-CROSS · OD-LOCK-SHADOW · OD-LOCK-TTL  
- Final Governance: OD-PERM · OD-RUNBOOK · OD-CERT · OD-SCHEMA  

**Workshop status:** All named OD-* items are frozen OWNER_APPROVED. No open OWNER ANSWER fields remain for OD-* in this pack.

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

## Group 2 — Gates & integrity

### 8. OD-C8 — OWNER_APPROVED (2026-07-20)
- **Frozen:** Production Restore only when C8 Overall Result = **SAFE**. No WARNING/FAIL override, waiver, Continue Anyway, or Super Admin bypass. If not SAFE: do not start; correct package; re-run C8.  
- **OWNER ANSWER:** OWNER_APPROVED

### 9. OD-VERIFY-WARN — OWNER_APPROVED (2026-07-20)
- **Frozen:** Post-apply integrity fail-closed. Failure in accounting / ownership / FIFO / stock / schema / survivor / Global → session **FAILED**; Global Maint stays ON; storefronts and Country Admins unavailable; Super Admin may only Resume (if safe) or Rollback. No success-with-warnings / ignore / accept / override.  
- **OWNER ANSWER:** OWNER_APPROVED

### 10. OD-INV — OWNER_APPROVED (2026-07-20)
- **Frozen:** Certified Immutable Production Inventory Snapshot mandatory before every Production Restore (read-only, certified, immutable, cryptographically bound to session, retained for audit/forensics). Live reads may only verify the snapshot — never replace it.  
- **OWNER ANSWER:** OWNER_APPROVED

### 11. OD-FA-RESOLVER — OWNER_APPROVED (2026-07-20)
- **Frozen:** Always use certified Ownership Resolver Matrix. Never guess ownership. Never country_id shortcuts. If unproven → fail before execution. No best-effort ownership.  
- **OWNER ANSWER:** OWNER_APPROVED

### 12. OD-FA-STOCK — OWNER_APPROVED (2026-07-20)
- **Frozen:** Mandatory warehouse ownership, stock ownership, FIFO integrity, cross-country stock references. Any failure immediately fails the session. No soft warning / ignore / best-effort.  
- **OWNER ANSWER:** OWNER_APPROVED

### 13. OD-FA-SCHEMA — OWNER_APPROVED (2026-07-20)
- **Frozen:** Mandatory schema revision + tables/columns/indexes/constraints. Any mismatch fails the session. No fixture soft-skip in Production or Certification. Schema integrity over execution.  
- **OWNER ANSWER:** OWNER_APPROVED

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

### 17. OD-UPLOADS — OWNER_APPROVED (2026-07-20)
- **Frozen:** Strictly scoped country uploads only; scoped pre-image before modify; never full-tree replace / never touch survivor uploads / never outside approved scope. If integrity not guaranteed → fail immediately; Global Maint ON; Super Admin Resume or Rollback only. No best-effort / partial acceptance.  
- **OWNER ANSWER:** OWNER_APPROVED

---

## Group 4 — Locks

### 18. OD-LOCK-CROSS — OWNER_APPROVED (2026-07-20)
- **Frozen:** CPR and Full DR mutually exclusive. Active one blocks the other until finished. No override / parallel / Super Admin bypass.  
- **OWNER ANSWER:** OWNER_APPROVED

### 19. OD-LOCK-SHADOW — OWNER_APPROVED (2026-07-20)
- **Frozen:** CPR and C6 mutually exclusive and serialized on the same production deployment. No concurrent shared resources/work roots/DBs/verification contexts. Second refused if one running. No override / parallel.  
- **OWNER ANSWER:** OWNER_APPROVED

---

## Group 5 — Governance details (Final Governance — OWNER_APPROVED)

### 20. OD-PHRASE — OWNER_APPROVED (moved from Group 1; do not re-answer)
- See Group 1 item 3. Phrase **`RESTORE`** + password re-auth. Required in Workflow A and B.

### 21. OD-BREAK — OWNER_APPROVED (moved from Group 1; do not re-answer)
- See Group 1 item 4. Super Admin only; does not bypass Full Rollback Anchor, mandatory gates, logging, or authentication.

### 22. OD-PERM — OWNER_APPROVED (2026-07-20)
- **Frozen:** Country Admin may view own-country CPR status, prepare C3–C8, and request restore. Country Admin shall never approve/execute/resume/rollback Production Restore, release Global Maintenance, or enable/disable Production Restore. Super Admin alone may approve, execute, resume, Rollback, release Global Maintenance, and enable/disable CPR. No authority model may contradict OD-DUAL.  
- **OWNER ANSWER:** OWNER_APPROVED

### 23. OD-RUNBOOK — OWNER_APPROVED (2026-07-20)
- **Frozen:** Mandatory operational Runbook every Production Restore. Before PONR, Super Admin completes audited checklist including at minimum: Package ID; Target Country; C8 = SAFE; Certified Inventory Snapshot; Session Full Backup ID; Global Maintenance active. Global Maintenance never released until Runbook successfully completed.  
- **OWNER ANSWER:** OWNER_APPROVED

### 24. OD-CERT — OWNER_APPROVED (2026-07-20)
- **Frozen:** Owner is final PASS/FAIL authority. Engineering produces evidence/reports/artifacts only — never final certification approval. CPR remains disabled until Certification PASS + explicit Owner approval + explicit Production Enablement.  
- **OWNER ANSWER:** OWNER_APPROVED

---

## Group 6 — Timing / duration / locks / schema (OWNER_APPROVED)

### 25. OD-MAINT-MAX — OWNER_APPROVED (2026-07-20)
- **Frozen:** No fixed maximum maintenance duration. Automatic Expected Duration estimate per job (package/SQL/upload size, rows, batches, historical stats, infrastructure performance). No manual duration configuration.  
- **OWNER ANSWER:** OWNER_APPROVED

### 26. OD-TIMEOUT — OWNER_APPROVED (2026-07-20)
- **Frozen:** Timeout ≠ automatic failure. Workflow: Estimated Duration → Warning → Critical → Recovery Investigation → Resume (when supported). Never fail solely because elapsed time exceeded the estimate. Continue if measurable progress (heartbeat, batches, imported rows, etc.). Recovery only when lack of progress + timeout escalation.  
- **OWNER ANSWER:** OWNER_APPROVED

### 27. OD-RTO — OWNER_APPROVED (2026-07-20)
- **Frozen:** No hardcoded RTO. Automatic Estimated Duration per job from actual workload — for **operational monitoring only**.  
- **OWNER ANSWER:** OWNER_APPROVED

### 28. OD-LOCK-TTL — OWNER_APPROVED (2026-07-20)
- **Frozen:** Heartbeat monitoring for restore locks. Pre-PONR: Super Admin–only manual clear of stale locks; every manual unlock fully audited. Post-PONR: automatic lock release permanently forbidden — no timeout, worker failure, crash, or other circumstance may auto-release. System Integrity > automatic recovery.  
- **OWNER ANSWER:** OWNER_APPROVED

### 29. OD-SCHEMA — OWNER_APPROVED (2026-07-20)
- **Frozen:** Any Production Schema Revision change invalidates prior CPR certification. Before CPR may be used again: package rebuild + new Certification + new C8 SAFE. Those steps do **not** auto re-enable. CPR stays disabled until Owner reviews new cert, grants PASS, and explicitly Enables again. Every major schema evolution = full new production authorization cycle.  
- **OWNER ANSWER:** OWNER_APPROVED

---

## Sign-off

| Field | Value |
|-------|-------|
| Owner name | _______________ |
| Date | _______________ |
| Group 1 freeze | OWNER_APPROVED 2026-07-20 (OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK) |
| Group 2 (Maint) freeze | OWNER_APPROVED 2026-07-20 (OD-MAINT, OD-MAINT-SCOPE, OD-MAINT-MAX, OD-RTO, OD-TIMEOUT) |
| Group 3 freeze | OWNER_APPROVED 2026-07-20 (OD-PIN, OD-ROLLBACK, OD-FAIL-DELETE, OD-FAIL-IMPORT, OD-UPLOADS + Maintenance State) |
| Gates & Integrity freeze | OWNER_APPROVED 2026-07-20 (OD-C8, OD-VERIFY-WARN, OD-INV, OD-FA-* + Integrity Principle) |
| Group 4 freeze | OWNER_APPROVED 2026-07-20 (OD-LOCK-CROSS, OD-LOCK-SHADOW + Isolation Principle) |
| Final Governance freeze | OWNER_APPROVED 2026-07-20 (OD-PERM, OD-RUNBOOK, OD-CERT, OD-LOCK-TTL, OD-SCHEMA + Governance Principle) |
| Workshop complete? | **YES** (all named OD-* frozen OWNER_APPROVED) |
| Notes | P1 still requires explicit Owner authorization to start |

**P0b Owner Decision workshop is complete.** All named OD-* decisions and foundational principles are frozen OWNER_APPROVED.  
**Do not begin P1 until the Owner explicitly authorizes P1.** **Do not implement.**
