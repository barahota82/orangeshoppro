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
| **Enablement** | Remains **disabled** until certification + explicit OD-ENABLE |

### Frozen inputs (not reopened)

| ID | Policy |
|----|--------|
| C1.1 **D1–D6** | Boundary matrix; NULL≠target; sequences special; admins composite; screen-copy Global; `journal_entries` Full-only |
| Multicountry **§13** | Full country separation (stock, GL, parties, sequences) |
| Full DR **OD-2** | Country production restore disabled until Country certification |
| CRP Final Audit | C8 SAFE ≠ cutover auth; FA-01…FA-03 residuals inform OD-FA-* |

### Register rules

- Every status below is **PROPOSED** until the owner fills **Final owner answer**.  
- **Recommended** is facilitator advice, **not** an assumed approval.  
- Do not guess. Do not implement from recommendations alone.

### OD count

**27** decisions (26 from P0 catalog + **OD-MAINT** called out in P0 §4 but missing from the catalog table).

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
| **6. Recommended** | **A** |
| **7. Reason** | Fail closed; production restore disabled by default; aligns Full DR OD-2 and P0. |
| **8. Security** | Prevents accidental/unauthorized production mutation path. |
| **9. Data integrity** | Ensures certified gates exist before live apply. |
| **10. Operational** | Clear go-live ceremony. |
| **11. Rollback** | N/A pre-enable; post-enable still requires Full anchor pins. |
| **12. Required before** | P1: yes (design disabled-default). Implementation: yes. Certification: defines post-cert gate. **Enablement: blocks.** |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank until approved)_ — Draft: *Country Production Restore enablement remains false until Country Production certification PASS and an explicit owner enablement order are both recorded.* |

---

### OD-DUAL — Dual control for PONR

| Field | Content |
|-------|---------|
| **1. ID** | OD-DUAL |
| **2. Title** | Dual control for irreversible Country Production actions |
| **3. Exact question** | Must Country Production Restore require two distinct identities (job creator ≠ PONR authorizer), or do you explicitly waive dual control in writing? |
| **4. Options** | **A)** Implement dual control (mandatory). **B)** Explicit written waiver (single Super Admin may authorize). **C)** Dual control only for first N production uses. |
| **5. Consequences** | A: stronger governance. B: faster ops, higher insider risk; must not silently inherit Full DR waiver. C: complex state. |
| **6. Recommended** | **A** |
| **7. Reason** | Dual control for irreversible production actions; separate from Full DR OD-1. |
| **8. Security** | Reduces single-person malicious/mistaken cutover. |
| **9. Data integrity** | Indirect — better process control. |
| **10. Operational** | Needs second person available in window. |
| **11. Rollback** | Unaffected. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-PHRASE — Authorization phrase and re-auth

| Field | Content |
|-------|---------|
| **1. ID** | OD-PHRASE |
| **2. Title** | PONR re-authentication phrase |
| **3. Exact question** | What re-auth factors and exact confirmation phrase are required immediately before Country Production PONR? |
| **4. Options** | **A)** Super Admin password re-auth + phrase `COUNTRY_RESTORE` (distinct from Full DR). **B)** Same phrase as Full DR (`RESTORE`). **C)** Password only, no phrase. **D)** Owner-specified phrase: `________`. |
| **5. Consequences** | A: clear mental separation Full vs Country. B: risk of operator confusion. C: weaker. D: custom. |
| **6. Recommended** | **A** |
| **7. Reason** | One-time authorization clarity; reduce cross-product mistakes. |
| **8. Security** | Extra intentionality gate. |
| **9–11** | Integrity/ops/rollback: process only. |
| **12. Required before** | P1: deferrable detail. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-BREAK — Break-glass single control

| Field | Content |
|-------|---------|
| **1. ID** | OD-BREAK |
| **2. Title** | Emergency single-control path |
| **3. Exact question** | If dual control is implemented, is a break-glass single-control path allowed, and what post-incident review is mandatory? |
| **4. Options** | **A)** Allowed only with distinct break-glass flag + mandatory incident report within 72h. **B)** Never allow single-control. **C)** Allowed silently whenever second person unavailable. |
| **5. Consequences** | A: controlled emergency. B: may block true emergencies. C: defeats dual control. |
| **6. Recommended** | **A** if OD-DUAL=A; **B** if OD-DUAL=B (waiver already covers). |
| **7. Reason** | Explicit rollback/incident triggers; no silent single-control. |
| **8. Security** | Audited exception. |
| **9–11** | Integrity/ops: emergency continuity; rollback still Full anchor. |
| **12. Required before** | P1: soft. Implementation: yes if dual control. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-PERM — Permissions model

| Field | Content |
|-------|---------|
| **1. ID** | OD-PERM |
| **2. Title** | Who may view / create / approve CPR jobs |
| **3. Exact question** | Which roles may (1) view CPR status, (2) create CPR jobs, (3) approve CPR, (4) authorize PONR / release maintenance — and may country-scoped admins act only for their country? |
| **4. Options** | **A)** View: restore viewers (country-scoped). Create: restore operators. Approve: distinct approver role. PONR/maint release: Super Admin only. Country scope enforced. **B)** Any Super Admin may do all steps alone. **C)** Owner-defined matrix: `________`. |
| **5. Consequences** | A: least privilege + dual control. B: conflicts with OD-DUAL=A. C: custom. |
| **6. Recommended** | **A** |
| **7. Reason** | Fail closed; country isolation of authority. |
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
| **5. Consequences** | A: aligns Full DR safety chassis. B/C: race with live writers → integrity risk. |
| **6. Recommended** | **A** |
| **7. Reason** | Fail closed against concurrent storefront/admin/GL/stock writes. |
| **8. Security** | Reduces concurrent mutation attacks/errors. |
| **9. Data integrity** | Critical — prevents mid-restore writes. |
| **10. Operational** | Downtime window required. |
| **11. Rollback** | Maint stays on through rollback. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-MAINT-SCOPE — Maintenance scope

| Field | Content |
|-------|---------|
| **1. ID** | OD-MAINT-SCOPE |
| **2. Title** | Platform-wide vs country-scoped maintenance |
| **3. Exact question** | During CPR, should maintenance block **all** mutating traffic platform-wide, or only traffic for the target country if isolation can be proven? |
| **4. Options** | **A)** Platform-wide maintenance (recommended default). **B)** Country-scoped maintenance only if a written technical proof shows Global/survivor writers cannot contaminate the job (else fall back to A). **C)** No writer blocking beyond DB locks. |
| **5. Consequences** | A: simplest proof, broader downtime. B: less downtime if proven — **not assumed proven today**. C: unsafe. |
| **6. Recommended** | **A** now; allow **B** only after explicit isolation proof accepted by owner. |
| **7. Reason** | Prefer country-scoped **when technically proven safe**; otherwise global maintenance. Isolation is **not** proven in P0. |
| **8. Security** | A strongest. |
| **9. Data integrity** | A strongest against Global/shared path races. |
| **10. Operational** | A: full downtime; B: narrower if proven. |
| **11. Rollback** | Maint scope should cover rollback window equally. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-MAINT-MAX — Max maintenance duration

| Field | Content |
|-------|---------|
| **1. ID** | OD-MAINT-MAX |
| **2. Title** | Maximum maintenance window / escalation |
| **3. Exact question** | What maximum maintenance duration triggers escalation/alert, and what happens if exceeded? |
| **4. Options** | **A)** Alert at 60 minutes; page at 120; no auto-cancel post-PONR. **B)** Owner numbers: alert `___` / page `___`. **C)** No max. |
| **5. Consequences** | A: sensible default. B: business-specific. C: risk of forgotten maint. |
| **6. Recommended** | **A** until OD-RTO refines. |
| **7. Reason** | Operational visibility without auto-unlock post-PONR. |
| **8–11** | Ops-focused; integrity unchanged if maint stays on. |
| **12. Required before** | P1: deferrable. Implementation: yes. Certification: soft. Enablement: soft. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-RTO — Business RTO/RPO for CPR

| Field | Content |
|-------|---------|
| **1. ID** | OD-RTO |
| **2. Title** | Business RTO / RPO for Country Production Restore |
| **3. Exact question** | What maximum downtime (RTO) and data-loss tolerance (RPO) apply to a planned Country Production Restore window? |
| **4. Options** | **A)** Target RTO ≤ 2 hours; RPO = 0 for non-target countries (survivors/Global unchanged); target country replaced from package. **B)** Owner figures: RTO `___` / RPO notes `___`. **C)** No formal RTO. |
| **5. Consequences** | Drives OD-FAIL-IMPORT choice (retry vs immediate Full rollback duration). |
| **6. Recommended** | **A** as planning default; confirm **B** if business differs. |
| **7. Reason** | Makes failure-policy tradeoffs explicit. |
| **8–11** | Ops/rollback duration planning. |
| **12. Required before** | P1: soft. Implementation: soft. Certification: soft. Enablement: soft. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-TIMEOUT — Phase timeouts

| Field | Content |
|-------|---------|
| **1. ID** | OD-TIMEOUT |
| **2. Title** | Numeric timeouts per CPR phase |
| **3. Exact question** | What timeouts apply to approvals waiting, pre-PONR idle with maint ON, and worker heartbeat interval? |
| **4. Options** | **A)** Approvals soft-cancel 24h; pre-PONR idle alert 30m; heartbeat ≤ 30s; no HTTP mutation timeouts (CLI). **B)** Owner numbers. **C)** No timeouts. |
| **5. Consequences** | A: replay prevention for abandoned jobs; safe heartbeats. |
| **6. Recommended** | **A** |
| **7. Reason** | Long-running CLI policy; abandon stale pre-PONR jobs. |
| **8–11** | Ops; post-PONR never auto-cancel. |
| **12. Required before** | P1: deferrable. Implementation: yes. Certification: soft. Enablement: soft. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-RUNBOOK — Human sign-offs

| Field | Content |
|-------|---------|
| **1. ID** | OD-RUNBOOK |
| **2. Title** | Required human sign-offs in the CPR runbook |
| **3. Exact question** | Which human sign-offs are mandatory before PONR and before maintenance release? |
| **4. Options** | **A)** Pre-PONR: operator checklist + approver + owner/delegate. Post-success: Super Admin maint release. **B)** Operator only. **C)** Owner-defined list. |
| **5. Consequences** | A: matches dual control + acceptance. B: weak. |
| **6. Recommended** | **A** |
| **7. Reason** | Explicit operational accountability. |
| **8–11** | Process. |
| **12. Required before** | P1: soft. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

## Group C — Backup and rollback

### OD-PIN — Retention pin for Full rollback anchor

| Field | Content |
|-------|---------|
| **1. ID** | OD-PIN |
| **2. Title** | Pin Full pre-restore backup against retention deletion |
| **3. Exact question** | Must a verified Full pre-restore backup be retention-pinned for the CPR job duration (and until rollback window closes), refusing PONR if pin fails? |
| **4. Options** | **A)** Yes — pin mandatory; refuse PONR without pin. **B)** Best-effort pin; allow PONR if pin fails. **C)** No pin. |
| **5. Consequences** | A: rollback possible. B/C: Critical risk if failure after PONR. |
| **6. Recommended** | **A** |
| **7. Reason** | Pinned Full rollback anchor; fail closed. |
| **8. Security** | Protects recovery path. |
| **9. Data integrity** | Enables platform restore to pre-CPR point. |
| **10. Operational** | Disk retention cost. |
| **11. Rollback** | **Primary rollback depends on this.** |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-ROLLBACK-CLI — Rollback worker shape

| Field | Content |
|-------|---------|
| **1. ID** | OD-ROLLBACK-CLI |
| **2. Title** | Full DR rollback reuse vs CPR-specific wrapper |
| **3. Exact question** | After CPR PONR failure, should rollback invoke the existing Full DR rollback worker against the pinned Full anchor, or a CPR-specific wrapper that only orchestrates the same Full rollback? |
| **4. Options** | **A)** CPR wrapper CLI that calls Full rollback primitives (recommended). **B)** Operators run Full DR rollback CLI directly with documented job linkage. **C)** Country-only inverse delete/import as primary rollback. |
| **5. Consequences** | A: clear CPR UX + reuse proven Full path. B: workable but error-prone. C: **rejected by P0 philosophy** (not primary). |
| **6. Recommended** | **A** |
| **7. Reason** | Explicit rollback triggers; Full anchor primary; avoid false “country undo”. |
| **8–10** | Security/integrity/ops: reuse Full DR proof. |
| **11. Rollback** | Defines operator path. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-FAIL-DELETE — Delete-phase failure policy

| Field | Content |
|-------|---------|
| **1. ID** | OD-FAIL-DELETE |
| **2. Title** | Recovery when target-slice DELETE fails mid-way |
| **3. Exact question** | If production target-slice DELETE fails after PONR has started, what is the mandatory recovery? |
| **4. Options** | **A)** Mark dirty → attempt complete remaining safe deletes → if not clean, **Full-anchor rollback**; never start import while dirty-unknown. **B)** Immediate Full-anchor rollback without finishing deletes. **C)** Continue to import anyway. |
| **5. Consequences** | A: balanced. B: always Full restore (longer). C: **unsafe**. |
| **6. Recommended** | **A** |
| **7. Reason** | Fail closed; explicit rollback trigger when uncertain. |
| **8–9** | Integrity: prevent import onto unknown partial delete. |
| **10. Operational** | May extend window. |
| **11. Rollback** | Full anchor when not clean. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

---

### OD-FAIL-IMPORT — Import-phase failure policy

| Field | Content |
|-------|---------|
| **1. ID** | OD-FAIL-IMPORT |
| **2. Title** | Recovery when target-slice IMPORT fails |
| **3. Exact question** | If production IMPORT fails mid-batch, choose the default recovery (no SQL byte-offset resume)? |
| **4. Options** | **A)** Mark dirty → **re-clear target slice** → re-import from frozen contract **or** Full-anchor rollback (operator chooses with runbook). **B)** Always immediate Full-anchor rollback. **C)** Resume mid-stream SQL. |
| **5. Consequences** | A: faster RTO possible. B: simplest integrity. C: **rejected** (DDL/partial unsafe). |
| **6. Recommended** | **A** with runbook preference; escalate to **B** if re-clear unsafe. |
| **7. Reason** | No mid-stream resume; explicit triggers; aligns PRODUCTION_IMPORT_SAFETY philosophy adapted to slice. |
| **8–9** | Integrity: no silent partial country. |
| **10. Operational** | Tradeoff vs OD-RTO. |
| **11. Rollback** | Full anchor always available. |
| **12. Required before** | P1: yes. Implementation: yes. Certification: yes. Enablement: yes. |
| **13. Status** | PROPOSED |
| **14. Final owner answer** | _(blank)_ |
| **15. Frozen policy wording** | _(blank)_ |

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
| OD-ENABLE | A | A | Y | N | Y | **Y** |
| OD-DUAL | A | A | Y | N | Y | Y |
| OD-PHRASE | A | A | soft | Y | Y | Y |
| OD-BREAK | A | A | soft | partial | Y | Y |
| OD-PERM | A | A | soft | partial | Y | Y |
| OD-CERT | A | A | soft | Y | **Y** | **Y** |
| OD-MAINT | B | A | Y | N | Y | Y |
| OD-MAINT-SCOPE | B | A | Y | N | Y | Y |
| OD-MAINT-MAX | B | A | soft | Y | soft | soft |
| OD-RTO | B | A | soft | Y | soft | soft |
| OD-TIMEOUT | B | A | soft | Y | soft | soft |
| OD-RUNBOOK | B | A | soft | Y | Y | Y |
| OD-PIN | C | A | Y | N | Y | Y |
| OD-ROLLBACK-CLI | C | A | Y | N | Y | Y |
| OD-FAIL-DELETE | C | A | Y | N | Y | Y |
| OD-FAIL-IMPORT | C | A | Y | N | Y | Y |
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

*End of Owner Decision Register — P0b. All statuses PROPOSED. Owner answers via workshop document in a later phase. No P1. No implementation.*
