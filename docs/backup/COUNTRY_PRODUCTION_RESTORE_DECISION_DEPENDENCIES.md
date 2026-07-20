# Country Production Restore — Decision Dependencies

**Status:** P0b dependency map  
**Date:** 2026-07-20  
**Purpose:** Show what each Owner Decision unlocks or blocks — so the workshop order is not arbitrary.  
**Companion:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` · `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md`  
**Frozen OWNER_APPROVED (2026-07-20):** Integrity Principle · Isolation Principle · Group 1 · Group 2 Maint · Group 3 (incl. OD-UPLOADS) · Gates & Integrity · Group 4 (OD-LOCK-CROSS · OD-LOCK-SHADOW)  
**Not yet frozen (deferrable only):** OD-PERM · OD-RUNBOOK · OD-CERT · OD-SCHEMA · OD-LOCK-TTL  
**P1-blocking OD minimum set:** complete (no remaining hard P1 blockers in this register)  
**Do not start P1** until the Owner explicitly authorizes P1. **Do not implement.**

---

## 1. Legend

| Symbol | Meaning |
|--------|---------|
| → | Decision A must be settled before implementing B safely |
| ↔ | Mutual dependency (settle together or accept temporary assumption) |
| soft | Recommended before P1; not a hard blocker if architecture default is kept |
| hard | Must be Owner-approved before that phase/workstream starts |

---

## 2. Phase unlock map

```
P0 Architecture          [DONE — docs only]
        │
        ▼
P0b Owner Decisions      [Integrity + Isolation + Groups 1–4 + Gates frozen]
        │                 Remaining: deferrable detail only
        ▼
P1 Contracts             [Owner must explicitly authorize — then start]
        │
        ├──► P2 Controllers / state machine / locks
        ├──► P3 Apply engine / uploads / rollback
        ├──► P4 Admin UX / dual-control / phrase / break-glass
        └──► P5 Certification / runbook / enablement
```

**Rule:** Frozen ODs (Integrity, Isolation, Groups 1–4, Gates & Integrity) are fixed for P1+. Remaining PROPOSED items are soft/deferrable and may be settled during P1–P5 without blocking P1 authorization.

---

## 3. Dependency graph (by decision)

### Foundational principles (OWNER_APPROVED)

| Principle | Status | Unlocks | Blocks if unresolved |
|-----------|--------|---------|----------------------|
| **Integrity Principle** | **OWNER_APPROVED** | Fail-closed gates; no Super Admin safety bypass; OD-C8 / VERIFY-WARN / INV / FA-* | — |
| **Isolation Principle** | **OWNER_APPROVED** | OD-UPLOADS · OD-LOCK-* · survivor-safe apply; fail if isolation unproven | — |

### Group 1 — Enablement & authority (OWNER_APPROVED)

| Decision | Status | Unlocks | Blocks if unresolved |
|----------|--------|---------|----------------------|
| **OD-ENABLE** | **OWNER_APPROVED** | Certification gate → explicit enable → first Production Restore | — (frozen) |
| **OD-DUAL** | **OWNER_APPROVED** | Dual-control UX (WF-A/B; not dual Super Admin) | — (frozen) |
| **OD-PHRASE** | **OWNER_APPROVED** | Exact phrase `RESTORE` (case-sensitive) | — (frozen) |
| **OD-BREAK** | **OWNER_APPROVED** | Break-glass path + audit fields | — (frozen) |

### Group 2 — Maintenance & timing (OWNER_APPROVED)

| Decision | Status | Unlocks | Blocks if unresolved |
|----------|--------|---------|----------------------|
| **OD-MAINT** | **OWNER_APPROVED** | Maintenance UX + storefront/admin messaging | — (frozen) |
| **OD-MAINT-SCOPE** | **OWNER_APPROVED** = **GLOBAL** | Global Restore Operational Policy; all storefronts + Country Admins | — (frozen) |
| **OD-MAINT-MAX** | **OWNER_APPROVED** | Auto-calculated estimate UX (no Owner hard cap) | — (frozen) |
| **OD-RTO** | **OWNER_APPROVED** | Soft planning target (no hard SLA abort) | — (frozen) |
| **OD-TIMEOUT** | **OWNER_APPROVED** | Progress-aware stuck detection | — (frozen) |

### Group 3 — Permissions, rollback & uploads

| Decision | Status | Unlocks | Blocks if unresolved |
|----------|--------|---------|----------------------|
| **OD-PERM** | PROPOSED A | Soft — capability names | Soft for P4 |
| **OD-CERT** | PROPOSED A | Soft — certification checklist | Soft for P5 |
| **OD-RUNBOOK** | PROPOSED B | Soft — runbook depth | Soft for P5 |
| **OD-PIN** | **OWNER_APPROVED** | New Full Backup pin; surgical success path | — (frozen) |
| **OD-ROLLBACK** | **OWNER_APPROVED** | Super Admin dashboard Rollback (fail-pause only; never automatic) | — (frozen) |
| **OD-FAIL-DELETE** | **OWNER_APPROVED** | Delete-failure pause (no auto-RB) | — (frozen) |
| **OD-FAIL-IMPORT** | **OWNER_APPROVED** | Import-failure pause (no auto-RB) | — (frozen) |
| **OD-UPLOADS** | **OWNER_APPROVED** | Scoped uploads + pre-image; never full-tree; fail → Maint + Resume/Rollback | — (frozen) |

### Gates & Integrity (OWNER_APPROVED)

| Decision | Status | Unlocks | Blocks if unresolved |
|----------|--------|---------|----------------------|
| **OD-C8** | **OWNER_APPROVED** | Preflight SAFE-only gate | — (frozen) |
| **OD-VERIFY-WARN** | **OWNER_APPROVED** | Post-apply fail-closed | — (frozen) |
| **OD-SCHEMA** | PROPOSED A | Soft — schema tolerance wording | Soft for P1 |
| **OD-INV** | **OWNER_APPROVED** | Inventory binding to certified snapshot | — (frozen) |
| **OD-FA-RESOLVER** | **OWNER_APPROVED** | Matrix-driven apply resolver | — (frozen) |
| **OD-FA-STOCK** | **OWNER_APPROVED** | Strict stock restore | — (frozen) |
| **OD-FA-SCHEMA** | **OWNER_APPROVED** | Strict schema / no silent reshape | — (frozen) |

### Group 4 — Cross-feature locks (OWNER_APPROVED for CROSS + SHADOW)

| Decision | Status | Unlocks | Blocks if unresolved |
|----------|--------|---------|----------------------|
| **OD-LOCK-CROSS** | **OWNER_APPROVED** | Mutual exclusion CPR ↔ Full DR; no bypass | — (frozen) |
| **OD-LOCK-SHADOW** | **OWNER_APPROVED** | Mutual exclusion CPR ↔ C6; serialized; no shared concurrent resources | — (frozen) |
| **OD-LOCK-TTL** | PROPOSED A | Soft — heartbeat / stale TTL numbers | Soft for P2 |

---

## 4. Critical chains (read these if workshop time is short)

### Chain A — Isolation & exclusivity (OWNER_APPROVED)

```
Isolation Principle (OWNER_APPROVED)
        │
        ├──► OD-UPLOADS (OWNER_APPROVED)     scoped + pre-image; never full-tree; fail-closed
        ├──► OD-LOCK-CROSS (OWNER_APPROVED)  CPR ⊥ Full DR
        └──► OD-LOCK-SHADOW (OWNER_APPROVED) CPR ⊥ C6
```

**Meaning:** Production Restore never endangers survivors; never runs concurrent with Full DR or Country Shadow; uploads never replace the whole tree.

### Chain B — “Can we turn it on?” (OWNER_APPROVED)

```
OD-ENABLE (OWNER_APPROVED) ──► certification gate + explicit enable
        │
        └──► OD-CERT (soft) ──► P5 packaging only
```

### Chain C — “Who may start restore?” (OWNER_APPROVED)

```
OD-DUAL (OWNER_APPROVED) ──► dual-control UX (WF-A/B)
OD-PHRASE (OWNER_APPROVED) ──► typed RESTORE
OD-BREAK (OWNER_APPROVED) ──► break-glass path
OD-PERM (soft) ──► capability naming in P4
```

### Chain D — “What happens when it breaks?” (OWNER_APPROVED)

```
OD-PIN (OWNER_APPROVED) ──► Full Backup pin before mutate
        │
        ├──► OD-ROLLBACK (OWNER_APPROVED) ──► dashboard Rollback; fail-pause only
        ├──► OD-FAIL-DELETE / OD-FAIL-IMPORT (OWNER_APPROVED) ──► pause; no auto-RB
        └──► OD-UPLOADS (OWNER_APPROVED) ──► fail → Global Maint + Resume/Rollback only
```

### Chain E — “Maintenance & clocks” (OWNER_APPROVED)

```
OD-MAINT-SCOPE = GLOBAL (OWNER_APPROVED)
        │
        ├──► Global Restore Operational Policy
        ├──► OD-MAINT / OD-MAINT-MAX / OD-RTO / OD-TIMEOUT (OWNER_APPROVED)
        └──► Super Admin Operational Model (Restore Management)
```

### Chain F — “Integrity & gates” (OWNER_APPROVED)

```
Integrity Principle (OWNER_APPROVED)
        │
        ├──► OD-C8 (SAFE only)
        ├──► OD-VERIFY-WARN (fail-closed)
        ├──► OD-INV (certified snapshot)
        └──► OD-FA-* (matrix / stock / schema)
```

### Chain G — Remaining soft items (do not block P1 authorization)

```
OD-PERM · OD-RUNBOOK · OD-CERT · OD-SCHEMA · OD-LOCK-TTL
```

Settle during P1–P5 or a short deferrable workshop. **Do not start P1 until Owner authorizes.**

---

## 5. What each major workstream needs

| Workstream | Hard prerequisites (all frozen) | Soft / remaining |
|------------|--------------------------------|------------------|
| **P1 Contracts** | Integrity · Isolation · Groups 1–4 hard ODs · Gates & Integrity | OD-SCHEMA, OD-LOCK-TTL numbers, OD-PERM/CERT/RUNBOOK |
| **P2 Controllers / locks** | OD-LOCK-CROSS · OD-LOCK-SHADOW · OD-TIMEOUT · OD-MAINT* | OD-LOCK-TTL |
| **P3 Apply / uploads / RB** | OD-PIN · OD-ROLLBACK · OD-FAIL-* · OD-UPLOADS · OD-FA-* · Isolation | — |
| **P4 Admin UX** | OD-DUAL · OD-PHRASE · OD-BREAK · OD-MAINT · Super Admin Model | OD-PERM |
| **P5 Certification** | OD-ENABLE · OD-C8 · OD-VERIFY-WARN · OD-INV | OD-CERT · OD-RUNBOOK |

---

## 6. Assumptions log (temporary defaults while Owner decides)

| ID | Temporary assumption | Expires when | Risk if wrong |
|----|---------------------|--------------|---------------|
| ASM-01 | ~~Dual-control default A~~ | **CLOSED** — OD-DUAL OWNER_APPROVED (WF-A/B; not dual Super Admin) | — |
| ASM-02 | ~~Typed phrase = `RESTORE`~~ | **CLOSED** — OD-PHRASE OWNER_APPROVED | — |
| ASM-03 | ~~Maintenance = platform-wide~~ | **CLOSED** — OD-MAINT-SCOPE = GLOBAL OWNER_APPROVED | — |
| ASM-04 | ~~Rollback = Owner-triggered~~ | **CLOSED** — OD-ROLLBACK OWNER_APPROVED (dashboard; fail-pause; never automatic) | — |
| ASM-05 | ~~C8 SAFE-only~~ | **CLOSED** — OD-C8 OWNER_APPROVED | — |
| ASM-06 | ~~Post-apply WARN fails closed~~ | **CLOSED** — OD-VERIFY-WARN OWNER_APPROVED | — |
| ASM-07 | ~~Uploads = scoped + pre-image~~ | **CLOSED** — OD-UPLOADS OWNER_APPROVED | — |
| ASM-08 | ~~CPR ⊥ Full DR~~ | **CLOSED** — OD-LOCK-CROSS OWNER_APPROVED | — |
| ASM-09 | ~~CPR ⊥ C6~~ | **CLOSED** — OD-LOCK-SHADOW OWNER_APPROVED | — |
| ASM-10 | Inventory binds to certified snapshot | **CLOSED** — OD-INV OWNER_APPROVED | — |
| ASM-11 | FA matrix / stock / schema strict | **CLOSED** — OD-FA-* OWNER_APPROVED | — |
| ASM-12 | Integrity > privilege; no gate bypass | **CLOSED** — Integrity Principle OWNER_APPROVED | — |
| ASM-13 | Never modify outside approved recovery scope | **CLOSED** — Isolation Principle OWNER_APPROVED | — |

**Rule:** Closed assumptions must match OWNER_APPROVED text. No open hard assumptions remain for P1-blocking ODs.

---

## 7. Conflict / inconsistency checks (run after each workshop group)

After freezing any group, verify:

1. **OD-MAINT-SCOPE vs OD-ENABLE** — GLOBAL freeze matches Global Restore Operational Policy (all storefronts + Country Admins).
2. **OD-DUAL vs OD-BREAK** — dual-control is WF-A/B (not two Super Admins); break-glass remains exceptional.
3. **OD-ROLLBACK vs OD-PIN** — pin = new Full Backup; Rollback = Super Admin dashboard only after fail-pause; never automatic.
4. **OD-FAIL-* vs OD-ROLLBACK** — delete/import failure → pause + Maintain; Rollback only via dashboard.
5. **OD-C8 vs OD-VERIFY-WARN** — both fail-closed; no Super Admin override (Integrity Principle).
6. **OD-INV vs OD-ENABLE** — inventory/certification bind before enablement.
7. **OD-FA-* vs Isolation** — apply stays inside approved country scope; survivors untouched.
8. **OD-UPLOADS vs Isolation** — scoped uploads + pre-image; never full-tree; fail → Resume/Rollback only.
9. **OD-LOCK-CROSS vs OD-LOCK-SHADOW** — CPR exclusive vs Full DR and vs C6; no parallel; no bypass.
10. **OD-TIMEOUT vs OD-RTO** — progress-aware stuck detection vs soft planning target (no hard SLA abort).
11. **No decision silently re-enables Production Restore** before certification + OD-ENABLE path.

---

## 8. Suggested freeze order (workshop)

| Freeze | Contents | Status |
|--------|----------|--------|
| 0 | Integrity Principle | **OWNER_APPROVED** 2026-07-20 |
| 1 | Group 1 | **OWNER_APPROVED** 2026-07-20 |
| 2 | Group 2 Maint | **OWNER_APPROVED** 2026-07-20 |
| 3 | Group 3 (PIN/RB/FAIL + UPLOADS) | **OWNER_APPROVED** 2026-07-20 |
| 3b | Gates & Integrity | **OWNER_APPROVED** 2026-07-20 |
| 4 | Group 4 (LOCK-CROSS + LOCK-SHADOW) + Isolation Principle | **OWNER_APPROVED** 2026-07-20 |
| — | Deferrable: OD-PERM · OD-RUNBOOK · OD-CERT · OD-SCHEMA · OD-LOCK-TTL | Open (soft) |
| — | **P1** | **Blocked until Owner explicitly authorizes** |

---

## 9. Still open for P1 (summary)

| Class | Items | P1 impact |
|-------|-------|-----------|
| **Hard P1 blockers** | *(none — minimum OD set complete)* | — |
| Soft / deferrable | OD-PERM · OD-RUNBOOK · OD-CERT · OD-SCHEMA · OD-LOCK-TTL | May proceed after Owner authorizes P1; settle in P1–P5 |
| Already frozen | Integrity · Isolation · Group 1 · Group 2 Maint · Group 3 · Gates & Integrity · Group 4 CROSS/SHADOW | Do not re-open without Owner revision |

**P1 still requires an explicit Owner authorization to start** — OD completeness is necessary but not sufficient.

---

## 10. Document control

| Version | Date | Notes |
|---------|------|-------|
| 0.1 | 2026-07-20 | Initial P0b dependency map |
| 0.2 | 2026-07-20 | Group 1 frozen OWNER_APPROVED |
| 0.3 | 2026-07-20 | Group 2 Maint frozen; OD-MAINT-SCOPE = GLOBAL |
| 0.4 | 2026-07-20 | Group 3 PIN/RB/FAIL frozen |
| 0.5 | 2026-07-20 | OD-ROLLBACK refined (dashboard; fail-pause; never automatic) |
| 0.6 | 2026-07-20 | Gates & Integrity + Integrity Principle frozen |
| 0.7 | 2026-07-20 | Group 3 OD-UPLOADS + Group 4 LOCK-CROSS/SHADOW + Isolation Principle frozen OWNER_APPROVED; P1-blocking OD minimum set complete; remaining soft only |

---

*End of Decision Dependencies — P0b. Documentation only. No P1. No implementation.*
