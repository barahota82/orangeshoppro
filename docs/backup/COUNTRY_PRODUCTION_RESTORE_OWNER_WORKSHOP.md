# Country Production Restore — Owner Workshop Pack (Phase P0b)

| Field | Value |
|-------|--------|
| **Purpose** | Answer OD-* decisions. Do not implement. |
| **Full register** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Dependencies** | `COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` |
| **Architecture** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` |
| **Date** | 2026-07-20 |

**Already frozen (do not re-answer):** C1.1 D1–D6 · Multicountry §13 · Country restore stays disabled until certification + explicit enablement.

**Owner-approved (2026-07-20) — do not re-answer:** OD-ENABLE · OD-DUAL · OD-PHRASE · OD-BREAK.

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

### 5. OD-MAINT *(still open)*
Must maintenance be ON + write-block proven before any production DELETE/IMPORT/uploads?
- **Recommended:** A — Yes, mandatory  
- **Alternatives:** B optional if low traffic · C never  
- **Consequences:** A prevents live writer races. B/C integrity risk.  
- **OWNER ANSWER:** _______________

### 6. OD-MAINT-SCOPE *(still open)*
Platform-wide maintenance, or country-only if isolation proven?
- **Recommended:** A — Platform-wide now (B only after written isolation proof)  
- **Alternatives:** B country-scoped if proven · C no writer blocking  
- **Consequences:** A more downtime, stronger proof. B less downtime if proven (not proven today).  
- **OWNER ANSWER:** _______________

### 7. OD-PIN *(still open)*
Refuse PONR unless Full pre-restore backup is verified and retention-pinned?
- **Recommended:** A — Yes, mandatory pin  
- **Alternatives:** B best-effort · C no pin  
- **Consequences:** A enables Full rollback. B/C Critical if failure after PONR.  
- **OWNER ANSWER:** _______________

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

## Group 3 — Failure & rollback (answer before P1)

### 14. OD-FAIL-DELETE
If DELETE fails after PONR: finish safe deletes if possible; else Full-anchor rollback; never import while dirty-unknown?
- **Recommended:** A — That policy  
- **Alternatives:** B immediate Full rollback always · C continue to import  
- **Consequences:** C unsafe.  
- **OWNER ANSWER:** _______________

### 15. OD-FAIL-IMPORT
If IMPORT fails: no mid-stream resume; re-clear target slice + re-import **or** Full-anchor rollback?
- **Recommended:** A — Re-clear/re-import or Full rollback  
- **Alternatives:** B always Full rollback · C mid-stream SQL resume  
- **Consequences:** C rejected for integrity.  
- **OWNER ANSWER:** _______________

### 16. OD-ROLLBACK-CLI
CPR wrapper that invokes Full DR rollback against pinned Full anchor (not country-inverse as primary)?
- **Recommended:** A — CPR wrapper → Full rollback primitives  
- **Alternatives:** B operators run Full CLI directly · C country-inverse primary  
- **Consequences:** C conflicts with P0 philosophy.  
- **OWNER ANSWER:** _______________

### 17. OD-UPLOADS
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

## Group 6 — Timing numbers (may defer)

### 25. OD-MAINT-MAX
Alert 60m / page 120m maintenance; no auto-cancel post-PONR?
- **Recommended:** A  
- **Alternatives:** B custom alert/page · C no max  
- **OWNER ANSWER:** _______________

### 26. OD-TIMEOUT
Approvals soft-cancel 24h; pre-PONR idle alert 30m; heartbeat ≤ 30s; CLI-only mutation?
- **Recommended:** A  
- **Alternatives:** B custom · C no timeouts  
- **OWNER ANSWER:** _______________

### 27. OD-RTO
Planning default: RTO ≤ 2h; survivor/Global RPO = 0 (unchanged); target replaced from package?
- **Recommended:** A  
- **Alternatives:** B custom RTO/RPO · C no formal RTO  
- **OWNER ANSWER:** _______________

### 28. OD-LOCK-TTL
Heartbeat ≤ 30s; stale detect pre-PONR with manual clear if PID dead; **no auto-unlock post-PONR**?
- **Recommended:** A  
- **Alternatives:** B custom TTLs · C auto-unlock anytime  
- **OWNER ANSWER:** _______________

### 29. OD-SCHEMA
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
| Workshop complete? | YES / NO (remaining open items) |
| Notes | _______________ |

**Group 1 is frozen.** Continue workshop for open ODs only.  
**Do not begin P1 until remaining P1-blocking ODs are frozen.** **Do not implement.**
