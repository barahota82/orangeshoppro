# Country Production Restore — Decision Dependency Map (Phase P0b)

| Field | Value |
|-------|--------|
| **Status** | Architecture / policy only |
| **Phase** | P0b |
| **Date** | 2026-07-20 |
| **Companion** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md`, `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` |
| **Parent** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (P0 tip `b28abb81`) |
| **Last owner freeze** | 2026-07-20 — Group 1: OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK → **OWNER_APPROVED** |

**No implementation.** Remaining open ODs answered later. **Do not start P1** until remaining P1-blocking ODs are frozen.

### Architectural reference note (OWNER_APPROVED Group 1)

P0 architecture §8 previously recommended “two distinct Super Admin identities” / dual-control. That recommendation is **superseded** by OWNER_APPROVED **OD-DUAL** (Super Admin / Country Admin Workflow A and B). Do not design or implement the old dual-Super-Admin model. Confirmation phrase is **`RESTORE`** (OD-PHRASE). Break Glass is Super Admin only and does not bypass anchor/gates/auth/logging (OD-BREAK). Enablement stays false until Certification PASS + Explicit Owner enablement + implementation completed + Final Enterprise approval (OD-ENABLE).

---

## 1. Required ordering (recommended)

```
OD-ENABLE [OWNER_APPROVED — disabled until cert + owner + impl + enterprise]
  → OD-DUAL + OD-PHRASE + OD-BREAK [OWNER_APPROVED — governance skeleton frozen]
  → OD-PERM (align to OD-DUAL) + remaining open governance
  → OD-MAINT + OD-MAINT-SCOPE + OD-PIN           [safety chassis — still open]
  → OD-C8 + OD-VERIFY-WARN + OD-INV + OD-FA-*   [gate strictness]
  → OD-FAIL-DELETE + OD-FAIL-IMPORT + OD-ROLLBACK-CLI + OD-UPLOADS
  → OD-LOCK-* + OD-TIMEOUT + OD-MAINT-MAX + OD-RTO + OD-RUNBOOK
  → OD-SCHEMA + OD-CERT                         [cert program]
  → (implementation / drills)
  → certification PASS
  → OD-ENABLE final flip                        [enablement — last]
```

---

## 2. Blocks P1 detailed design

These should be answered (or explicitly DEFERRED with written deferral) before P1 starts.

### Already frozen (P1 must design to these — do not reopen)

| Decision | Frozen shape |
|----------|--------------|
| **OD-ENABLE** | Disabled by default; enable only after Certification PASS + Explicit Owner enablement + Production Restore implementation completed + Final Enterprise approval |
| **OD-DUAL** | One global Super Admin + Country Admins. **Workflow A:** Super Admin end-to-end, no second approver; mandatory protections (Full Rollback Anchor, gates PASS, Maintenance, phrase `RESTORE`, password re-auth, full audit, one-time auth). **Workflow B:** Country Admin prepares C3–C8 only → Pending Super Admin Approval → only Super Admin approves/executes. Country Admin cannot execute. |
| **OD-PHRASE** | Phrase **`RESTORE`** mandatory; Super Admin types it; Workflow A and B; with password re-auth |
| **OD-BREAK** | Super Admin only; emergency reason + audit + notification; does **not** bypass Full Rollback Anchor, mandatory safety gates, logging, authentication |

### Still open — minimum freeze before P1

| Decision | Why P1 needs it |
|----------|-----------------|
| **OD-MAINT** | Whether maint is mandatory in pipeline |
| **OD-MAINT-SCOPE** | Maint model + proof requirements |
| **OD-PIN** | Anchor + retention design |
| **OD-C8** | Entry gate from C8 |
| **OD-VERIFY-WARN** | Post-verify state machine |
| **OD-INV** | Inventory artifact design |
| **OD-FAIL-DELETE** | Failure/resume branches |
| **OD-FAIL-IMPORT** | Failure/resume branches |
| **OD-ROLLBACK-CLI** | Rollback interface design |
| **OD-UPLOADS** | Uploads cutover design (scoped) |
| **OD-LOCK-CROSS** | Lock topology vs Full DR |
| **OD-LOCK-SHADOW** | Lock topology vs C6 |
| **OD-FA-RESOLVER** | Membership engine contract |
| **OD-FA-STOCK** | Verify suite contract |
| **OD-FA-SCHEMA** | Schema gate contract |

**P1 may proceed with provisional notes** if OD-PERM, OD-TIMEOUT, OD-MAINT-MAX, OD-RTO, OD-RUNBOOK, OD-CERT, OD-SCHEMA are deferred — but those must freeze before implementation of their surfaces, and **must not contradict** OWNER_APPROVED OD-DUAL / OD-PHRASE / OD-BREAK / OD-ENABLE.

---

## 3. Deferrable until implementation (with care)

| Decision | Deferral condition |
|----------|--------------------|
| **OD-PERM** | Role naming may refine in implementation **only if** aligned to OWNER_APPROVED OD-DUAL Workflow A/B |
| **OD-TIMEOUT** | Numeric defaults can be tuned in drills |
| **OD-MAINT-MAX** | Numeric threshold after rehearsal |
| **OD-RTO** | Business number after ops workshop |
| **OD-RUNBOOK** | Checklist detail during P2/P7; must reflect Workflow A/B |
| **OD-LOCK-TTL** | Tune after heartbeat soak tests |
| **OD-CERT** | Checklist expansion during P2 |
| **OD-SCHEMA** | Only when revision actually changes |

**Not deferrable (already frozen or architecture-shaping):** OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK (frozen); OD-MAINT-SCOPE, OD-PIN, OD-C8, OD-FAIL-*, OD-UPLOADS, OD-FA-* (still open, shape P1).

---

## 4. Blocks certification

| Decision | Role in certification |
|----------|----------------------|
| **OD-CERT** | Defines evidence pack |
| **OD-ENABLE** | Cert must exist before enablement; enablement also requires implementation completed + Final Enterprise approval |
| **OD-DUAL** | Cert proves Workflow A and/or Workflow B paths (not dual Super Admin) |
| **OD-PHRASE** | Cert proves typed `RESTORE` + password re-auth |
| **OD-BREAK** | Cert proves Break Glass does not bypass anchor/gates/auth/logging |
| **OD-PIN** | Cert proves Full Rollback Anchor pin works |
| **OD-MAINT** / **OD-MAINT-SCOPE** | Cert proves writer block |
| **OD-C8** / **OD-VERIFY-WARN** / **OD-FA-*** | Cert gate strictness |
| **OD-FAIL-*** / **OD-ROLLBACK-CLI** | Cert proves failure drills |
| **OD-UPLOADS** | Cert proves scoped uploads |
| **OD-LOCK-*** | Cert proves exclusion |
| **OD-SCHEMA** | Cert binds schema revision |

---

## 5. Blocks enablement

**All** of the following (OWNER_APPROVED OD-ENABLE):

1. Country Production certification **PASS** (per OD-CERT).  
2. Explicit Owner enablement order.  
3. Country Production Restore **implementation completed**.  
4. Final Enterprise approval.  
5. **OD-DUAL** / **OD-PHRASE** / **OD-BREAK** remain OWNER_APPROVED and implemented as frozen.  
6. **OD-PIN** proven in drills.  
7. No open REJECTED-but-unimplemented mandatory safety OD.  
8. C1.1 D1–D6 still intact.  
9. Production restore drills on clone (roadmap P7) evidence attached.

Enablement is **last**. Nothing else flips the flag. Flag stays **false by default**.

---

## 6. Conflicts and resolutions

| Pair | Potential conflict | Resolution |
|------|-------------------|------------|
| OD-MAINT-SCOPE = country-only vs OD-FA / integrity | Writers on other countries may still touch shared Global | Prefer platform-wide unless OD-MAINT-SCOPE proves isolation |
| OD-C8 = WARNING allowed vs OD-VERIFY-WARN = no warnings | Soft entry + hard exit inconsistency | If C8 WARNING waived, still keep post-verify fail-closed |
| OD-DUAL Workflow A vs “need second approver” | Old dual-Super-Admin recommendation | **Superseded** — Workflow A allows Super Admin end-to-end; protections listed in OD-DUAL remain mandatory |
| OD-DUAL Workflow B vs Country Admin execute | Country Admin must not execute | Request stays Pending Super Admin Approval; only Super Admin executes |
| OD-BREAK vs safety chassis | Emergency skip of anchor/gates | **Forbidden** — Break Glass does not bypass Full Rollback Anchor, mandatory gates, logging, or authentication |
| OD-FAIL-IMPORT = always Full rollback vs OD-RTO aggressive | Long rollback vs fast retry | Owner picks; document RTO impact |
| OD-UPLOADS full-tree rename vs survivor file safety | Contaminates other countries | Reject full-tree; keep scoped |
| OD-ENABLE early vs OD-CERT / impl / enterprise | Enable without proof | Forbidden by OWNER_APPROVED OD-ENABLE |
| OD-LOCK-CROSS exclusive vs emergency Full+Country | Operational deadlock | Serialize; never parallel |
| OD-PERM / OD-RUNBOOK vs OD-DUAL | Role matrix contradicts Workflow A/B | Align OD-PERM/OD-RUNBOOK to frozen OD-DUAL |

**No unresolved contradiction** among OWNER_APPROVED Group 1 decisions.

---

## 7. Frozen policies (must not be reopened by OD answers)

| Frozen | Effect on OD register |
|--------|----------------------|
| C1.1 D1–D6 | No OD may redefine NULL-as-target, JE ignore, screen-copy ignore, sequences special, admins composite, matrix SoT |
| Multicountry §13 | No OD may allow cross-country stock/GL blend |
| Country disabled until cert+enable+impl+enterprise | OD-ENABLE OWNER_APPROVED — cannot mean “enable now without those gates” |
| OD-DUAL / OD-PHRASE / OD-BREAK | OWNER_APPROVED — do not reopen without a new owner decision |

If an owner answer would violate these, mark **REJECTED** and re-ask.

---

## 8. Validation checklist (P0b)

| Check | Result |
|-------|--------|
| Every P0 catalog OD-* in register | **YES** (26) + **OD-MAINT** (27) |
| Every OD has one owner question | **YES** |
| Recommendations internally consistent | **YES** (see §6; dual-Super-Admin withdrawn) |
| C1.1 not reopened | **YES** |
| Group 1 OWNER_APPROVED only after owner workshop | **YES** — OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK |
| Remaining ODs still PROPOSED | **YES** |
| No implementation presented as policy | **YES** |

---

## 9. Summary counts

| Gate | Decisions |
|------|-----------|
| OWNER_APPROVED (Group 1) | 4 — OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK |
| Still block P1 (open minimum set) | 15 (see §2 “Still open”) |
| Deferrable to implementation (numeric/ops detail) | See §3 (OD-PHRASE/OD-BREAK no longer deferrable) |
| Block certification | See §4 (most safety ODs) |
| Block enablement | OD-ENABLE four gates + pin/maint proof + frozen governance |

---

*End of dependency map — P0b. Group 1 frozen. No P1. No implementation.*
