# Country Production Restore — Decision Dependency Map (Phase P0b)

| Field | Value |
|-------|--------|
| **Status** | Architecture / policy only |
| **Phase** | P0b |
| **Date** | 2026-07-20 |
| **Companion** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md`, `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` |
| **Parent** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (P0 tip `b28abb81`) |
| **Last owner freeze** | 2026-07-20 — Group 2: OD-MAINT, OD-MAINT-SCOPE, OD-MAINT-MAX, OD-RTO, OD-TIMEOUT → **OWNER_APPROVED** (Group 1 remains frozen) |

**No implementation.** Remaining open ODs answered later. **Do not start P1** until remaining P1-blocking ODs are frozen.

### Architectural reference note (OWNER_APPROVED Groups 1–2)

**Group 1:** P0 architecture §8 dual-Super-Admin recommendation is **superseded** by OWNER_APPROVED **OD-DUAL** (Workflow A/B). Phrase **`RESTORE`** (OD-PHRASE). Break Glass Super Admin only; no bypass of anchor/gates/auth/logging (OD-BREAK). Enablement false until Certification PASS + Explicit Owner enablement + implementation completed + Final Enterprise approval (OD-ENABLE).

**Group 2 (aligned with P0 §9, Final Enterprise Audit safety chassis, Production Restore philosophy):**
- **OD-MAINT** — Maintenance Mode mandatory before execution.
- **OD-MAINT-SCOPE** — **GLOBAL MAINTENANCE** only. Country-only NOT approved under current shared-DB / Global-Mixed / Full-anchor / platform maint+rollback architecture. Future reconsideration only after a proven country-isolated model.
- **OD-MAINT-MAX** — No fixed maximum; automatic Expected Duration per job (workload signals).
- **OD-RTO** — No hardcoded RTO; Estimated Duration for operational monitoring only.
- **OD-TIMEOUT** — Timeout ≠ failure; progress-aware escalation (estimate → warning → critical → investigate → resume when supported).

---

## 1. Required ordering (recommended)

```
OD-ENABLE [OWNER_APPROVED — disabled until cert + owner + impl + enterprise]
  → OD-DUAL + OD-PHRASE + OD-BREAK [OWNER_APPROVED — governance skeleton frozen]
  → OD-PERM (align to OD-DUAL) + remaining open governance
  → OD-MAINT + OD-MAINT-SCOPE [OWNER_APPROVED — GLOBAL mandatory maint]
  → OD-MAINT-MAX + OD-RTO + OD-TIMEOUT [OWNER_APPROVED — auto estimate / progress-aware]
  → OD-PIN                                   [safety chassis — still open]
  → OD-C8 + OD-VERIFY-WARN + OD-INV + OD-FA-*   [gate strictness]
  → OD-FAIL-DELETE + OD-FAIL-IMPORT + OD-ROLLBACK-CLI + OD-UPLOADS
  → OD-LOCK-* + OD-RUNBOOK
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
| **OD-DUAL** | One global Super Admin + Country Admins. Workflow A / Workflow B (Country Admin cannot execute) |
| **OD-PHRASE** | Phrase **`RESTORE`** + password re-auth; Workflow A and B |
| **OD-BREAK** | Super Admin only; does not bypass Full Rollback Anchor, mandatory gates, logging, authentication |
| **OD-MAINT** | Maintenance Mode mandatory before CPR execution |
| **OD-MAINT-SCOPE** | **GLOBAL MAINTENANCE**; Country-only not approved under current architecture |
| **OD-MAINT-MAX** | No fixed max duration; automatic Expected Duration estimate per job |
| **OD-RTO** | No hardcoded RTO; Estimated Duration for monitoring only |
| **OD-TIMEOUT** | Progress-aware: estimate → warning → critical → investigate → resume; never fail on elapsed-vs-estimate alone |

### Still open — minimum freeze before P1

| Decision | Why P1 needs it |
|----------|-----------------|
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

**P1 may proceed with provisional notes** if OD-PERM, OD-RUNBOOK, OD-CERT, OD-SCHEMA, OD-LOCK-TTL are deferred — but those must freeze before implementation of their surfaces, and **must not contradict** OWNER_APPROVED Groups 1–2.

---

## 3. Deferrable until implementation (with care)

| Decision | Deferral condition |
|----------|--------------------|
| **OD-PERM** | Role naming may refine in implementation **only if** aligned to OWNER_APPROVED OD-DUAL Workflow A/B |
| **OD-RUNBOOK** | Checklist detail during P2/P7; must reflect Workflow A/B + GLOBAL maint |
| **OD-LOCK-TTL** | Tune after heartbeat soak tests (must not contradict OD-TIMEOUT progress rules) |
| **OD-CERT** | Checklist expansion during P2 |
| **OD-SCHEMA** | Only when revision actually changes |

**Not deferrable (already frozen):** OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK, OD-MAINT, OD-MAINT-SCOPE, OD-MAINT-MAX, OD-RTO, OD-TIMEOUT.

**Still architecture-shaping (open):** OD-PIN, OD-C8, OD-FAIL-*, OD-UPLOADS, OD-FA-*.

---

## 4. Blocks certification

| Decision | Role in certification |
|----------|----------------------|
| **OD-CERT** | Defines evidence pack |
| **OD-ENABLE** | Cert must exist before enablement; enablement also requires implementation completed + Final Enterprise approval |
| **OD-DUAL** | Cert proves Workflow A and/or Workflow B paths (not dual Super Admin) |
| **OD-PHRASE** | Cert proves typed `RESTORE` + password re-auth |
| **OD-BREAK** | Cert proves Break Glass does not bypass anchor/gates/auth/logging |
| **OD-MAINT** / **OD-MAINT-SCOPE** | Cert proves GLOBAL Maintenance ON + write-block before PONR |
| **OD-MAINT-MAX** / **OD-RTO** | Cert proves automatic Estimated Duration is produced (monitoring) |
| **OD-TIMEOUT** | Cert proves timeout alone does not fail a progressing job |
| **OD-PIN** | Cert proves Full Rollback Anchor pin works |
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
5. **OD-DUAL** / **OD-PHRASE** / **OD-BREAK** / **OD-MAINT** / **OD-MAINT-SCOPE** / **OD-MAINT-MAX** / **OD-RTO** / **OD-TIMEOUT** remain OWNER_APPROVED and implemented as frozen.  
6. **OD-PIN** proven in drills.  
7. No open REJECTED-but-unimplemented mandatory safety OD.  
8. C1.1 D1–D6 still intact.  
9. Production restore drills on clone (roadmap P7) evidence attached.

Enablement is **last**. Nothing else flips the flag. Flag stays **false by default**.

---

## 6. Conflicts and resolutions

| Pair | Potential conflict | Resolution |
|------|-------------------|------------|
| OD-MAINT-SCOPE country-only vs integrity | Shared Global/Mixed + Full-anchor rollback | **Resolved OWNER_APPROVED:** GLOBAL only; Country-only not approved under current architecture |
| OD-MAINT-MAX fixed cap vs large packages | Arbitrary wall-clock fail | **Resolved:** no fixed max; auto Estimated Duration |
| OD-RTO hardcoded ≤2h vs real workload | Misleading fail deadline | **Resolved:** no hardcoded RTO; estimate for monitoring only |
| OD-TIMEOUT elapsed > estimate vs progressing job | False failure | **Resolved:** never fail on elapsed-vs-estimate alone; require lack of progress + escalation |
| OD-C8 = WARNING allowed vs OD-VERIFY-WARN = no warnings | Soft entry + hard exit inconsistency | If C8 WARNING waived, still keep post-verify fail-closed |
| OD-DUAL Workflow A vs “need second approver” | Old dual-Super-Admin recommendation | **Superseded** — Workflow A allows Super Admin end-to-end; protections remain mandatory |
| OD-DUAL Workflow B vs Country Admin execute | Country Admin must not execute | Pending Super Admin Approval; only Super Admin executes |
| OD-BREAK vs safety chassis | Emergency skip of anchor/gates | **Forbidden** — Break Glass does not bypass Full Rollback Anchor, mandatory gates, logging, or authentication |
| OD-FAIL-IMPORT vs duration monitoring | Long Full rollback vs estimate | Failure policy separate; OD-RTO/OD-TIMEOUT do not force fail on duration alone |
| OD-UPLOADS full-tree rename vs survivor file safety | Contaminates other countries | Reject full-tree; keep scoped |
| OD-ENABLE early vs OD-CERT / impl / enterprise | Enable without proof | Forbidden by OWNER_APPROVED OD-ENABLE |
| OD-LOCK-CROSS exclusive vs emergency Full+Country | Operational deadlock | Serialize; never parallel |
| OD-PERM / OD-RUNBOOK vs OD-DUAL | Role matrix contradicts Workflow A/B | Align OD-PERM/OD-RUNBOOK to frozen OD-DUAL |

**No unresolved contradiction** among OWNER_APPROVED Group 1 and Group 2 decisions.

---

## 7. Frozen policies (must not be reopened by OD answers)

| Frozen | Effect on OD register |
|--------|----------------------|
| C1.1 D1–D6 | No OD may redefine NULL-as-target, JE ignore, screen-copy ignore, sequences special, admins composite, matrix SoT |
| Multicountry §13 | No OD may allow cross-country stock/GL blend |
| Country disabled until cert+enable+impl+enterprise | OD-ENABLE OWNER_APPROVED — cannot mean “enable now without those gates” |
| OD-DUAL / OD-PHRASE / OD-BREAK | OWNER_APPROVED — do not reopen without a new owner decision |
| OD-MAINT / OD-MAINT-SCOPE | OWNER_APPROVED — mandatory GLOBAL maintenance; Country-only not approved under current architecture |
| OD-MAINT-MAX / OD-RTO / OD-TIMEOUT | OWNER_APPROVED — auto estimate + progress-aware timeout; no hardcoded RTO/max fail |

If an owner answer would violate these, mark **REJECTED** and re-ask.

---

## 8. Validation checklist (P0b)

| Check | Result |
|-------|--------|
| Every P0 catalog OD-* in register | **YES** (26) + **OD-MAINT** (27) |
| Every OD has one owner question | **YES** |
| Recommendations internally consistent | **YES** (see §6; dual-Super-Admin withdrawn; Country-only maint rejected) |
| C1.1 not reopened | **YES** |
| Group 1 OWNER_APPROVED only after owner workshop | **YES** — OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK |
| Group 2 OWNER_APPROVED only after owner workshop | **YES** — OD-MAINT, OD-MAINT-SCOPE, OD-MAINT-MAX, OD-RTO, OD-TIMEOUT |
| Remaining ODs still PROPOSED | **YES** |
| No implementation presented as policy | **YES** |

---

## 9. Summary counts

| Gate | Decisions |
|------|-----------|
| OWNER_APPROVED (Group 1) | 4 — OD-ENABLE, OD-DUAL, OD-PHRASE, OD-BREAK |
| OWNER_APPROVED (Group 2) | 5 — OD-MAINT, OD-MAINT-SCOPE, OD-MAINT-MAX, OD-RTO, OD-TIMEOUT |
| OWNER_APPROVED total | **9** |
| Still block P1 (open minimum set) | 13 (see §2 “Still open”) |
| Deferrable to implementation (ops detail) | See §3 |
| Block certification | See §4 (most safety ODs) |
| Block enablement | OD-ENABLE four gates + pin proof + frozen Groups 1–2 |

---

*End of dependency map — P0b. Groups 1–2 frozen. No P1. No implementation.*
