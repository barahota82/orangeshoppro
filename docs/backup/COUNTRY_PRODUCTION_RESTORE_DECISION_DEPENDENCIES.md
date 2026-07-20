# Country Production Restore — Decision Dependency Map (Phase P0b)

| Field | Value |
|-------|--------|
| **Status** | Architecture / policy only |
| **Phase** | P0b |
| **Date** | 2026-07-20 |
| **Companion** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md`, `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` |
| **Super Admin UX clarification** | `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` (**no new ODs**; does not amend OWNER_APPROVED text) |
| **Parent** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (P0 tip `b28abb81`) |
| **Last owner freeze** | 2026-07-20 — Group 3: OD-PIN, OD-ROLLBACK, OD-FAIL-DELETE, OD-FAIL-IMPORT (+ Maintenance State) → **OWNER_APPROVED** |

**No implementation.** Remaining open ODs answered later. **Do not start P1** until remaining P1-blocking ODs are frozen.

**UX clarification (2026-07-20):** Super Admin dashboard is the complete normal operational interface for CPR (no SSH/CLI for normal ops); Production Restore action auto-runs Maint → new Full Backup → verify → pin → begin (presents OD-PIN); live/paused management screen; Resume / Rollback / View Logs per already OWNER_APPROVED controls. See `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md`.

### Architectural reference note (OWNER_APPROVED Groups 1–3)

**Group 1:** OD-DUAL Workflow A/B (not dual Super Admin); phrase **`RESTORE`**; Break Glass Super Admin only; enablement gated (OD-ENABLE).

**Group 2:** Mandatory **GLOBAL** Maintenance; no fixed max / no hardcoded RTO; automatic Estimated Duration; progress-aware timeout (never fail on elapsed-vs-estimate alone).

**Group 3 (aligned with P0 Full-anchor philosophy + Final Enterprise Audit fail-closed ops + Groups 1–2):**
- **OD-PIN** — After Maintenance ON: **automatically create a NEW Full Backup** for this session → verify → pin. **Never reuse** existing backups. CPR must not continue without that session anchor.
- **OD-ROLLBACK** (P0 catalog: OD-ROLLBACK-CLI) — Dedicated **Super Admin dashboard Rollback action**; visible only to Super Admin; available **only when** CPR is paused on failure; same security controls as Production Restore (re-auth, phrase, permissions, complete audit + execution logging); never Country Admin; **never automatic**; always explicit Super Admin decision; rolls back to OD-PIN session Full Backup.
- **OD-FAIL-DELETE** / **OD-FAIL-IMPORT** — **No automatic rollback.** Pause under Maintenance; surface status; Super Admin chooses Resume (when safe) or Rollback.
- **Maintenance State on failure pause** — Maintenance stays ON until Super Admin successfully completes Resume or Rollback; users must never regain access while restore is incomplete.

P0 notes that previously implied automatic rollback branches or reusing an existing pinned Full package are **superseded** by Group 3 owner wording where they conflict.

---

## 1. Required ordering (recommended)

```
OD-ENABLE [OWNER_APPROVED]
  → OD-DUAL + OD-PHRASE + OD-BREAK [OWNER_APPROVED]
  → OD-PERM (align to OD-DUAL) + remaining open governance
  → OD-MAINT + OD-MAINT-SCOPE [OWNER_APPROVED — GLOBAL mandatory]
  → OD-MAINT-MAX + OD-RTO + OD-TIMEOUT [OWNER_APPROVED]
  → OD-PIN [OWNER_APPROVED — Maint → NEW Full Backup → verify → pin]
  → OD-ROLLBACK + OD-FAIL-DELETE + OD-FAIL-IMPORT [OWNER_APPROVED — pause; Super Admin decide]
  → OD-C8 + OD-VERIFY-WARN + OD-INV + OD-FA-*   [gate strictness — still open]
  → OD-UPLOADS + OD-LOCK-* + OD-RUNBOOK
  → OD-SCHEMA + OD-CERT
  → (implementation / drills)
  → certification PASS
  → OD-ENABLE final flip
```

---

## 2. Blocks P1 detailed design

### Already frozen (P1 must design to these — do not reopen)

| Decision | Frozen shape |
|----------|--------------|
| **OD-ENABLE** | Disabled until Certification PASS + Explicit Owner enablement + implementation completed + Final Enterprise approval |
| **OD-DUAL** | Super Admin / Country Admin Workflow A/B |
| **OD-PHRASE** | Phrase **`RESTORE`** + password re-auth |
| **OD-BREAK** | Super Admin Break Glass; no bypass of anchor/gates/auth/logging |
| **OD-MAINT** | Maintenance mandatory before execution |
| **OD-MAINT-SCOPE** | GLOBAL MAINTENANCE only |
| **OD-MAINT-MAX** | No fixed max; automatic Expected Duration |
| **OD-RTO** | No hardcoded RTO; Estimated Duration for monitoring only |
| **OD-TIMEOUT** | Progress-aware escalation; never fail on elapsed-vs-estimate alone |
| **OD-PIN** | Maint → auto **new** Full Backup → verify → pin; never reuse existing |
| **OD-ROLLBACK** | Dedicated Super Admin dashboard Rollback action; fail-pause only; never automatic; same controls as Production Restore; never Country Admin |
| **OD-FAIL-DELETE** | No auto-rollback; pause; Super Admin Resume/Rollback |
| **OD-FAIL-IMPORT** | No auto-rollback; pause; Super Admin Resume/Rollback |
| **Maintenance State (pause)** | Maint ON until successful Resume or Rollback |

### Still open — minimum freeze before P1

| Decision | Why P1 needs it |
|----------|-----------------|
| **OD-C8** | Entry gate from C8 |
| **OD-VERIFY-WARN** | Post-verify state machine |
| **OD-INV** | Inventory artifact design |
| **OD-UPLOADS** | Uploads cutover design (scoped) |
| **OD-LOCK-CROSS** | Lock topology vs Full DR |
| **OD-LOCK-SHADOW** | Lock topology vs C6 |
| **OD-FA-RESOLVER** | Membership engine contract |
| **OD-FA-STOCK** | Verify suite contract |
| **OD-FA-SCHEMA** | Schema gate contract |

**P1 may proceed with provisional notes** if OD-PERM, OD-RUNBOOK, OD-CERT, OD-SCHEMA, OD-LOCK-TTL are deferred — but those must freeze before implementation of their surfaces, and **must not contradict** OWNER_APPROVED Groups 1–3.

---

## 3. Deferrable until implementation (with care)

| Decision | Deferral condition |
|----------|--------------------|
| **OD-PERM** | Role naming may refine **only if** aligned to OD-DUAL + OD-ROLLBACK (Country Admin never rolls back / never executes) |
| **OD-RUNBOOK** | Checklist detail during P2/P7; must include Group 3 pause / Resume / Rollback UI steps |
| **OD-LOCK-TTL** | Tune after heartbeat soak tests (must not contradict OD-TIMEOUT) |
| **OD-CERT** | Checklist expansion during P2 |
| **OD-SCHEMA** | Only when revision actually changes |

**Not deferrable (already frozen):** Groups 1–3 IDs listed in §2.

**Still architecture-shaping (open):** OD-C8, OD-UPLOADS, OD-FA-*, OD-LOCK-CROSS/SHADOW, OD-VERIFY-WARN, OD-INV.

---

## 4. Blocks certification

| Decision | Role in certification |
|----------|----------------------|
| **OD-CERT** | Defines evidence pack |
| **OD-ENABLE** | Cert before enablement |
| **OD-DUAL** / **OD-PHRASE** / **OD-BREAK** | Authority / phrase / break-glass drills |
| **OD-MAINT** / **OD-MAINT-SCOPE** | GLOBAL Maintenance + write-block proof |
| **OD-PIN** | Cert proves **new** session Full Backup create → verify → pin (no reuse) |
| **OD-ROLLBACK** | Cert proves dedicated Super Admin dashboard Rollback action (fail-pause only) with full security controls; never automatic |
| **OD-FAIL-DELETE** / **OD-FAIL-IMPORT** | Cert proves pause (no auto-rollback) + Super Admin Resume/Rollback paths |
| **Maintenance State** | Cert proves users stay blocked until Resume or Rollback succeeds |
| **OD-C8** / **OD-VERIFY-WARN** / **OD-FA-*** | Gate strictness |
| **OD-UPLOADS** / **OD-LOCK-*** / **OD-SCHEMA** | Remaining safety surfaces |

---

## 5. Blocks enablement

**All** of the following (OWNER_APPROVED OD-ENABLE):

1. Country Production certification **PASS** (per OD-CERT).  
2. Explicit Owner enablement order.  
3. Country Production Restore **implementation completed**.  
4. Final Enterprise approval.  
5. Groups 1–3 OWNER_APPROVED decisions remain frozen and implemented as written.  
6. OD-PIN session Full Backup path proven in drills.  
7. Failure-pause + Super Admin Resume/Rollback drills proven.  
8. No open REJECTED-but-unimplemented mandatory safety OD.  
9. C1.1 D1–D6 still intact.  
10. Production restore drills on clone (roadmap P7) evidence attached.

Enablement is **last**. Flag stays **false by default**.

---

## 6. Conflicts and resolutions

| Pair | Potential conflict | Resolution |
|------|-------------------|------------|
| OD-PIN reuse existing Full backup vs “pin any verified Full” | Stale / wrong-session anchor | **Resolved:** always create **new** Full Backup for the session; never reuse |
| OD-PIN after Maint vs older P0 diagrams with backup before Maint | Sequence mismatch | **Resolved OWNER_APPROVED:** Maint → new Full Backup → verify → pin → continue |
| OD-FAIL-* auto-rollback vs Super Admin decision | Silent Full restore | **Resolved:** no auto-rollback; pause for Super Admin |
| OD-FAIL-IMPORT “re-clear/re-import” as default vs pause | Historical recommendation | **Superseded** by pause + Resume/Rollback choice |
| OD-ROLLBACK-CLI CLI-only vs admin UI | Operator path | **Resolved as OD-ROLLBACK:** dedicated Super Admin dashboard Rollback action; available only on failure pause; never automatic; security parity with Production Restore |
| Country Admin rollback | Violates OD-DUAL | **Forbidden** by OD-ROLLBACK |
| Failure pause vs releasing Maintenance | Users regain access mid-dirty | **Forbidden** — Maintenance State stays ON until Resume or Rollback succeeds |
| OD-TIMEOUT progress continue vs delete/import failure pause | Different signals | Timeout progress ≠ failure pause; both keep GLOBAL Maint as required |
| OD-MAINT-SCOPE country-only | Integrity | **Resolved Group 2:** GLOBAL only |
| OD-UPLOADS full-tree rename | Survivor files | Reject full-tree; keep scoped (still open OD) |

**No unresolved contradiction** among OWNER_APPROVED Groups 1–3.

---

## 7. Frozen policies (must not be reopened by OD answers)

| Frozen | Effect on OD register |
|--------|----------------------|
| C1.1 D1–D6 | No OD may redefine boundary SoT |
| Multicountry §13 | No cross-country stock/GL blend |
| OD-ENABLE | Cannot enable without cert + owner + impl + enterprise |
| Groups 1–2 | As previously frozen |
| OD-PIN / OD-ROLLBACK / OD-FAIL-* / Maintenance State | OWNER_APPROVED — do not reopen without a new owner decision |

If an owner answer would violate these, mark **REJECTED** and re-ask.

---

## 8. Validation checklist (P0b)

| Check | Result |
|-------|--------|
| Every P0 catalog OD-* in register | **YES** (26) + **OD-MAINT** (27); OD-ROLLBACK freezes OD-ROLLBACK-CLI |
| Every OD has one owner question | **YES** |
| Groups 1–3 OWNER_APPROVED only after owner workshop | **YES** |
| Remaining ODs still PROPOSED | **YES** |
| No implementation presented as policy | **YES** |
| C1.1 not reopened | **YES** |

---

## 9. Summary counts

| Gate | Decisions |
|------|-----------|
| OWNER_APPROVED (Group 1) | 4 |
| OWNER_APPROVED (Group 2) | 5 |
| OWNER_APPROVED (Group 3) | 4 IDs + Maintenance State cross-cut |
| OWNER_APPROVED total (named ODs) | **13** (+ Maintenance State) |
| Still block P1 (open minimum set) | 9 (see §2 “Still open”) |
| Block enablement | OD-ENABLE four gates + Groups 1–3 proven |

---

*End of dependency map — P0b. Groups 1–3 frozen. No P1. No implementation.*
