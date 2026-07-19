# Country Production Restore — Decision Dependency Map (Phase P0b)

| Field | Value |
|-------|--------|
| **Status** | Architecture / policy only |
| **Phase** | P0b |
| **Date** | 2026-07-20 |
| **Companion** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md`, `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` |
| **Parent** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (P0 tip `b28abb81`) |

**No implementation.** Owner answers later.

---

## 1. Required ordering (recommended)

```
OD-ENABLE (direction: remain false until cert)
  → OD-DUAL + OD-PHRASE + OD-BREAK + OD-PERM     [governance skeleton]
  → OD-MAINT + OD-MAINT-SCOPE + OD-PIN           [safety chassis]
  → OD-C8 + OD-VERIFY-WARN + OD-INV + OD-FA-*   [gate strictness]
  → OD-FAIL-DELETE + OD-FAIL-IMPORT + OD-ROLLBACK-CLI + OD-UPLOADS
  → OD-LOCK-* + OD-TIMEOUT + OD-MAINT-MAX + OD-RTO + OD-RUNBOOK
  → OD-SCHEMA + OD-CERT                         [cert program]
  → (implementation / drills)
  → certification PASS
  → OD-ENABLE final flip                        [enablement]
```

---

## 2. Blocks P1 detailed design

These should be answered (or explicitly DEFERRED with written deferral) before P1 starts. **Minimum freeze for P1:**

| Decision | Why P1 needs it |
|----------|-----------------|
| **OD-ENABLE** | P1 must design disabled-by-default + cert gate |
| **OD-DUAL** | Approval state machine shape |
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

**P1 may proceed with provisional notes** if OD-PHRASE, OD-BREAK, OD-PERM, OD-TIMEOUT, OD-MAINT-MAX, OD-RTO, OD-RUNBOOK, OD-CERT, OD-SCHEMA are deferred — but those must freeze before implementation of their surfaces.

---

## 3. Deferrable until implementation (with care)

| Decision | Deferral condition |
|----------|--------------------|
| **OD-PHRASE** | Exact string can wait; dual-control existence cannot |
| **OD-BREAK** | Procedure text can wait until ops design; existence of break-glass policy should be yes/no early |
| **OD-PERM** | Role names can refine in implementation if dual-control roles exist |
| **OD-TIMEOUT** | Numeric defaults can be tuned in drills |
| **OD-MAINT-MAX** | Numeric threshold after rehearsal |
| **OD-RTO** | Business number after ops workshop |
| **OD-RUNBOOK** | Checklist detail during P2/P7 |
| **OD-LOCK-TTL** | Tune after heartbeat soak tests |
| **OD-CERT** | Checklist expansion during P2 |
| **OD-SCHEMA** | Only when revision actually changes |

**Not deferrable if they change architecture shape:** OD-DUAL, OD-MAINT-SCOPE, OD-PIN, OD-C8, OD-FAIL-*, OD-UPLOADS, OD-FA-*.

---

## 4. Blocks certification

| Decision | Role in certification |
|----------|----------------------|
| **OD-CERT** | Defines evidence pack |
| **OD-ENABLE** | Cert must exist before enablement; cert program assumes enablement still false |
| **OD-DUAL** | Cert proves dual-control or records waiver |
| **OD-PIN** | Cert proves anchor pin works |
| **OD-MAINT** / **OD-MAINT-SCOPE** | Cert proves writer block |
| **OD-C8** / **OD-VERIFY-WARN** / **OD-FA-*** | Cert gate strictness |
| **OD-FAIL-*** / **OD-ROLLBACK-CLI** | Cert proves failure drills |
| **OD-UPLOADS** | Cert proves scoped uploads |
| **OD-LOCK-*** | Cert proves exclusion |
| **OD-SCHEMA** | Cert binds schema revision |

---

## 5. Blocks enablement

**All** of the following:

1. Country Production certification **PASS** (per OD-CERT).  
2. **OD-ENABLE** explicit owner order to flip flag.  
3. **OD-DUAL** not left as silent “later”.  
4. **OD-PIN** proven in drills.  
5. No open REJECTED-but-unimplemented mandatory safety OD.  
6. C1.1 D1–D6 still intact.  
7. Production restore drills on clone (roadmap P7) evidence attached.

Enablement is **last**. Nothing else flips the flag.

---

## 6. Conflicts and resolutions

| Pair | Potential conflict | Resolution |
|------|-------------------|------------|
| OD-MAINT-SCOPE = country-only vs OD-FA / integrity | Writers on other countries may still touch shared Global | Prefer platform-wide unless OD-MAINT-SCOPE proves isolation |
| OD-C8 = WARNING allowed vs OD-VERIFY-WARN = no warnings | Soft entry + hard exit inconsistency | If C8 WARNING waived, still keep post-verify fail-closed |
| OD-DUAL = WAIVE vs OD-BREAK | Single-control becomes normal | Waiver must be explicit; break-glass still audited |
| OD-FAIL-IMPORT = always Full rollback vs OD-RTO aggressive | Long rollback vs fast retry | Owner picks; document RTO impact |
| OD-UPLOADS full-tree rename vs survivor file safety | Contaminates other countries | Reject full-tree; keep scoped |
| OD-ENABLE early vs OD-CERT | Enable without proof | Forbidden by frozen OD-2 / P0 |
| OD-LOCK-CROSS exclusive vs emergency Full+Country | Operational deadlock | Serialize; never parallel |

**No unresolved contradiction** in recommendations if owner follows Recommended column in the register.

---

## 7. Frozen policies (must not be reopened by OD answers)

| Frozen | Effect on OD register |
|--------|----------------------|
| C1.1 D1–D6 | No OD may redefine NULL-as-target, JE ignore, screen-copy ignore, sequences special, admins composite, matrix SoT |
| Multicountry §13 | No OD may allow cross-country stock/GL blend |
| Country disabled until cert+enable | OD-ENABLE cannot mean “enable now without cert” |

If an owner answer would violate these, mark **REJECTED** and re-ask.

---

## 8. Validation checklist (P0b)

| Check | Result |
|-------|--------|
| Every P0 catalog OD-* in register | **YES** (26) + **OD-MAINT** (27) |
| Every OD has one owner question | **YES** |
| Recommendations internally consistent | **YES** (see §6) |
| C1.1 not reopened | **YES** |
| No silent assumptions as OWNER_APPROVED | **YES** — all **PROPOSED** |
| No implementation presented as policy | **YES** |

---

## 9. Summary counts

| Gate | Decisions |
|------|-----------|
| Block P1 (minimum set) | 17 (see §2) |
| Deferrable to implementation (numeric/ops detail) | 10 (see §3) |
| Block certification | See §4 (most safety ODs) |
| Block enablement | OD-ENABLE + cert PASS + dual/pin/maint proof |

---

*End of dependency map — P0b. No P1. No implementation.*
