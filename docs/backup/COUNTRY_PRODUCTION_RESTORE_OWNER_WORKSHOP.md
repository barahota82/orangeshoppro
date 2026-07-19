# Country Production Restore — Owner Workshop Pack (Phase P0b)

| Field | Value |
|-------|--------|
| **Purpose** | Answer OD-* decisions. Do not implement. |
| **Full register** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Dependencies** | `COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` |
| **Architecture** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` |
| **Date** | 2026-07-20 |

**Already frozen (do not re-answer):** C1.1 D1–D6 · Multicountry §13 · Country restore stays disabled until certification + explicit enablement.

**How to answer:** For each item, write one of `A` / `B` / `C` / `D` (or custom text) on `OWNER ANSWER:`.  
Recommended answers are **advice only**.

---

## Group 1 — Enablement & control (answer before P1)

### 1. OD-ENABLE
Must enablement stay **false** until Country Production certification PASS **and** an explicit owner enablement order?
- **Recommended:** A — Yes  
- **Alternatives:** B allow after drills without cert · C emergency enable without cert  
- **Consequences:** A = safest go-live. B/C weaken proof.  
- **OWNER ANSWER:** _______________

### 2. OD-DUAL
Require two distinct people (creator ≠ PONR authorizer), or written waiver?
- **Recommended:** A — Implement dual control  
- **Alternatives:** B written waiver · C dual control only for first N jobs  
- **Consequences:** A stronger governance. B faster, higher insider risk.  
- **OWNER ANSWER:** _______________

### 3. OD-MAINT
Must maintenance be ON + write-block proven before any production DELETE/IMPORT/uploads?
- **Recommended:** A — Yes, mandatory  
- **Alternatives:** B optional if low traffic · C never  
- **Consequences:** A prevents live writer races. B/C integrity risk.  
- **OWNER ANSWER:** _______________

### 4. OD-MAINT-SCOPE
Platform-wide maintenance, or country-only if isolation proven?
- **Recommended:** A — Platform-wide now (B only after written isolation proof)  
- **Alternatives:** B country-scoped if proven · C no writer blocking  
- **Consequences:** A more downtime, stronger proof. B less downtime if proven (not proven today).  
- **OWNER ANSWER:** _______________

### 5. OD-PIN
Refuse PONR unless Full pre-restore backup is verified and retention-pinned?
- **Recommended:** A — Yes, mandatory pin  
- **Alternatives:** B best-effort · C no pin  
- **Consequences:** A enables Full rollback. B/C Critical if failure after PONR.  
- **OWNER ANSWER:** _______________

---

## Group 2 — Gates & integrity (answer before P1)

### 6. OD-C8
Allow CPR entry only when C8 = SAFE?
- **Recommended:** A — SAFE only  
- **Alternatives:** B WARNING + per-job waiver · C FAIL + waiver  
- **Consequences:** A cleanest entry. C unsafe.  
- **OWNER ANSWER:** _______________

### 7. OD-VERIFY-WARN
After apply, fail closed (rollback) on accounting / ownership / FIFO / schema / survivor / Global issues — no soft accept?
- **Recommended:** A — Fail closed on those categories  
- **Alternatives:** B owner waive warnings · C best-effort accept  
- **Consequences:** A may rollback more; protects integrity.  
- **OWNER ANSWER:** _______________

### 8. OD-INV
Require certified read-only production inventory snapshot before CPR?
- **Recommended:** A — Certified snapshot mandatory  
- **Alternatives:** B live SELECT only under maint · C uncertified OK  
- **Consequences:** A immutable evidence. C unsafe.  
- **OWNER ANSWER:** _______________

### 9. OD-FA-RESOLVER
Engine must use matrix `ownership_resolver` first (never country_id-column override)?
- **Recommended:** A — Matrix-resolver-first  
- **Alternatives:** B keep column short-circuit · C per-table exceptions  
- **Consequences:** A closes FA-01. B keeps footgun.  
- **OWNER ANSWER:** _______________

### 10. OD-FA-STOCK
Strict stock/FIFO/cross-country verification post-apply, fail closed?
- **Recommended:** A — Strict  
- **Alternatives:** B soft warnings · C skip FIFO  
- **Consequences:** A protects §13 stock separation.  
- **OWNER ANSWER:** _______________

### 11. OD-FA-SCHEMA
On production/cert clones: strict schema expectations, no fixture soft-skip?
- **Recommended:** A — Strict on prod/cert  
- **Alternatives:** B soft-skip everywhere · C columns only  
- **Consequences:** A closes FA-03.  
- **OWNER ANSWER:** _______________

---

## Group 3 — Failure & rollback (answer before P1)

### 12. OD-FAIL-DELETE
If DELETE fails after PONR: finish safe deletes if possible; else Full-anchor rollback; never import while dirty-unknown?
- **Recommended:** A — That policy  
- **Alternatives:** B immediate Full rollback always · C continue to import  
- **Consequences:** C unsafe.  
- **OWNER ANSWER:** _______________

### 13. OD-FAIL-IMPORT
If IMPORT fails: no mid-stream resume; re-clear target slice + re-import **or** Full-anchor rollback?
- **Recommended:** A — Re-clear/re-import or Full rollback  
- **Alternatives:** B always Full rollback · C mid-stream SQL resume  
- **Consequences:** C rejected for integrity.  
- **OWNER ANSWER:** _______________

### 14. OD-ROLLBACK-CLI
CPR wrapper that invokes Full DR rollback against pinned Full anchor (not country-inverse as primary)?
- **Recommended:** A — CPR wrapper → Full rollback primitives  
- **Alternatives:** B operators run Full CLI directly · C country-inverse primary  
- **Consequences:** C conflicts with P0 philosophy.  
- **OWNER ANSWER:** _______________

### 15. OD-UPLOADS
Scoped allowlisted uploads apply with pre-image; never full `uploads/` root rename?
- **Recommended:** A — Scoped apply + pre-image  
- **Alternatives:** B full-tree two-phase rename · C in-place no pre-image  
- **Consequences:** B risks other countries’ files.  
- **OWNER ANSWER:** _______________

---

## Group 4 — Locks (answer before P1)

### 16. OD-LOCK-CROSS
CPR and Full DR mutually exclusive?
- **Recommended:** A — Exclusive  
- **Alternatives:** B parallel · C CPR yields to Full only  
- **Consequences:** B dangerous.  
- **OWNER ANSWER:** _______________

### 17. OD-LOCK-SHADOW
Serialize CPR with C6 Country Shadow on same host/work root?
- **Recommended:** A — Serialize  
- **Alternatives:** B parallel if isolated · C always allow C6  
- **Consequences:** A simplest ops safety.  
- **OWNER ANSWER:** _______________

---

## Group 5 — Governance details (may defer past P1 start)

### 18. OD-PHRASE
PONR re-auth: Super Admin password + phrase `COUNTRY_RESTORE` (distinct from Full DR)?
- **Recommended:** A  
- **Alternatives:** B same as Full `RESTORE` · C password only · D custom phrase `________`  
- **OWNER ANSWER:** _______________

### 19. OD-BREAK
If dual control: allow break-glass with mandatory incident report ≤ 72h?
- **Recommended:** A (if dual control) · else N/A  
- **Alternatives:** B never · C silent whenever second person missing  
- **OWNER ANSWER:** _______________

### 20. OD-PERM
View: scoped viewers · Create: operators · Approve: distinct role · PONR/maint release: Super Admin only?
- **Recommended:** A  
- **Alternatives:** B any Super Admin alone · C custom matrix  
- **OWNER ANSWER:** _______________

### 21. OD-RUNBOOK
Mandatory sign-offs: operator + approver + owner/delegate pre-PONR; Super Admin for maint release?
- **Recommended:** A  
- **Alternatives:** B operator only · C custom  
- **OWNER ANSWER:** _______________

### 22. OD-CERT
Owner owns certification checklist; engineering fills evidence; PASS required before enablement?
- **Recommended:** A  
- **Alternatives:** B engineering self-cert · C skip formal cert  
- **OWNER ANSWER:** _______________

---

## Group 6 — Timing numbers (may defer)

### 23. OD-MAINT-MAX
Alert 60m / page 120m maintenance; no auto-cancel post-PONR?
- **Recommended:** A  
- **Alternatives:** B custom alert/page · C no max  
- **OWNER ANSWER:** _______________

### 24. OD-TIMEOUT
Approvals soft-cancel 24h; pre-PONR idle alert 30m; heartbeat ≤ 30s; CLI-only mutation?
- **Recommended:** A  
- **Alternatives:** B custom · C no timeouts  
- **OWNER ANSWER:** _______________

### 25. OD-RTO
Planning default: RTO ≤ 2h; survivor/Global RPO = 0 (unchanged); target replaced from package?
- **Recommended:** A  
- **Alternatives:** B custom RTO/RPO · C no formal RTO  
- **OWNER ANSWER:** _______________

### 26. OD-LOCK-TTL
Heartbeat ≤ 30s; stale detect pre-PONR with manual clear if PID dead; **no auto-unlock post-PONR**?
- **Recommended:** A  
- **Alternatives:** B custom TTLs · C auto-unlock anytime  
- **OWNER ANSWER:** _______________

### 27. OD-SCHEMA
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
| Workshop complete? | YES / NO |
| Notes | _______________ |

**Next phase after answers:** freeze approved wording into the register (`OWNER_APPROVED`) → then P1 detailed design may begin for decisions that are frozen.  
**Do not begin P1 in this phase.** **Do not implement.**
