# Country Production Restore — Decision Dependency Map (Phase P0b)

| Field | Value |
|-------|--------|
| **Status** | Architecture / policy only |
| **Phase** | P0b |
| **Date** | 2026-07-20 |
| **Companion** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md`, `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` |
| **Super Admin UX clarification** | `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` (**no new ODs**) |
| **Global Restore ops clarification** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` (**no new ODs**) |
| **Parent** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (P0 tip `b28abb81`) |
| **Last owner freeze** | 2026-07-20 — Gates & Integrity (workshop Group 2): OD-C8, OD-VERIFY-WARN, OD-INV, OD-FA-RESOLVER, OD-FA-STOCK, OD-FA-SCHEMA + Integrity Principle → **OWNER_APPROVED** |

**No implementation.** Remaining open ODs answered later. **Do not start P1** until remaining P1-blocking ODs are frozen.

**UX clarification (2026-07-20):** Super Admin dashboard is the complete normal operational interface for CPR. See `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md`.

**Global Restore ops clarification (2026-07-20):** Any production Restore Maintenance → entire platform Global Maintenance. See `GLOBAL_RESTORE_OPERATIONAL_POLICY.md`.

### Architectural reference note (OWNER_APPROVED)

**Integrity Principle:** System/Data Integrity > user privileges; no Super Admin bypass of production safety gates; system-enforced; missing proof → do not execute.

**Group 1:** OD-DUAL Workflow A/B; phrase **`RESTORE`**; Break Glass Super Admin only; enablement gated (OD-ENABLE).

**Group 2 — Maintenance & timing:** Mandatory **GLOBAL** Maintenance; auto Estimated Duration; progress-aware timeout.

**Group 3:** OD-PIN new Full Backup; OD-ROLLBACK dashboard action (fail-pause only); OD-FAIL-* no auto-rollback; Maintenance State stays ON until Resume or Rollback.

**Gates & Integrity (workshop Group 2):** Proof-driven — OD-C8 SAFE only (no waiver/bypass); OD-VERIFY-WARN fail-closed → session FAILED + Global Maint; OD-INV certified immutable inventory; OD-FA-RESOLVER matrix only; OD-FA-STOCK / OD-FA-SCHEMA mandatory fail-closed. Never rely on user judgement, manual override, privilege, or best-effort.

---

## 1. Required ordering (recommended)

```
OD-ENABLE [OWNER_APPROVED]
  → Integrity Principle [OWNER_APPROVED]
  → OD-DUAL + OD-PHRASE + OD-BREAK [OWNER_APPROVED]
  → OD-PERM (align to OD-DUAL) + remaining open governance
  → OD-MAINT + OD-MAINT-SCOPE [OWNER_APPROVED — GLOBAL]
  → OD-MAINT-MAX + OD-RTO + OD-TIMEOUT [OWNER_APPROVED]
  → OD-PIN [OWNER_APPROVED]
  → OD-C8 + OD-INV + OD-FA-* [OWNER_APPROVED — proofs before / during]
  → OD-VERIFY-WARN [OWNER_APPROVED — post-apply fail-closed]
  → OD-ROLLBACK + OD-FAIL-DELETE + OD-FAIL-IMPORT [OWNER_APPROVED]
  → OD-UPLOADS + OD-LOCK-CROSS + OD-LOCK-SHADOW   [still open — block P1]
  → OD-LOCK-TTL + OD-RUNBOOK + OD-PERM + OD-CERT + OD-SCHEMA [deferrable]
  → (implementation / drills)
  → certification PASS
  → OD-ENABLE final flip
```

---

## 2. Blocks P1 detailed design

### Already frozen (P1 must design to these — do not reopen)

| Decision | Frozen shape |
|----------|--------------|
| **Integrity Principle** | Integrity > privilege; no gate bypass (incl. Super Admin); system-enforced; missing proof → no execute |
| **OD-ENABLE** … **OD-TIMEOUT** | As previously frozen (Groups 1–2 Maint) |
| **OD-PIN** / **OD-ROLLBACK** / **OD-FAIL-*** / **Maintenance State** | As previously frozen (Group 3) |
| **OD-C8** | SAFE only; no WARNING/FAIL waiver; no Super Admin bypass |
| **OD-VERIFY-WARN** | Integrity fail → session FAILED; Global Maint ON; Resume or Rollback only |
| **OD-INV** | Certified immutable inventory snapshot mandatory; live reads verify only |
| **OD-FA-RESOLVER** | Certified Ownership Resolver Matrix only; fail if unproven |
| **OD-FA-STOCK** | Mandatory warehouse/stock/FIFO/cross-country; fail session on failure |
| **OD-FA-SCHEMA** | Mandatory revision/tables/columns/indexes/constraints; no fixture soft-skip on prod/cert |

### Still open — minimum freeze before P1

| Decision | Why P1 needs it |
|----------|-----------------|
| **OD-UPLOADS** | Uploads cutover design (scoped) |
| **OD-LOCK-CROSS** | Lock topology vs Full DR |
| **OD-LOCK-SHADOW** | Lock topology vs C6 |

**P1 may proceed with provisional notes** if OD-PERM, OD-RUNBOOK, OD-CERT, OD-SCHEMA, OD-LOCK-TTL are deferred — but those must freeze before implementation of their surfaces, and **must not contradict** OWNER_APPROVED decisions or the Integrity Principle.

---

## 3. Deferrable until implementation (with care)

| Decision | Deferral condition |
|----------|--------------------|
| **OD-PERM** | Align to OD-DUAL + OD-ROLLBACK; no gate bypass |
| **OD-RUNBOOK** | Checklist detail; must include gates + pause / Resume / Rollback |
| **OD-LOCK-TTL** | Tune after soak; must not contradict OD-TIMEOUT |
| **OD-CERT** | Checklist expansion during P2 |
| **OD-SCHEMA** | When revision leaves certified baseline; must not contradict OD-FA-SCHEMA |

**Not deferrable (already frozen):** Integrity Principle; Groups 1–3; Gates & Integrity ODs above.

**Still architecture-shaping (open):** OD-UPLOADS, OD-LOCK-CROSS, OD-LOCK-SHADOW.

---

## 4. Blocks certification

| Decision | Role in certification |
|----------|----------------------|
| **OD-CERT** | Defines evidence pack |
| **OD-C8** | Cert proves SAFE-only entry (no waiver path) |
| **OD-INV** | Cert proves certified immutable inventory binding |
| **OD-VERIFY-WARN** / **OD-FA-*** | Cert proves fail-closed integrity suites |
| **Integrity Principle** | Cert proves no Super Admin bypass of gates |
| Prior Groups 1–3 | As previously listed |
| **OD-UPLOADS** / **OD-LOCK-*** / **OD-SCHEMA** | Remaining open surfaces |

---

## 5. Blocks enablement

**All** of the following (OWNER_APPROVED OD-ENABLE):

1. Country Production certification **PASS** (per OD-CERT).  
2. Explicit Owner enablement order.  
3. Country Production Restore **implementation completed**.  
4. Final Enterprise approval.  
5. All OWNER_APPROVED Groups 1–3 + Gates & Integrity + Integrity Principle implemented as frozen.  
6. OD-PIN / failure-pause / gate drills proven.  
7. No open REJECTED-but-unimplemented mandatory safety OD.  
8. C1.1 D1–D6 still intact.  
9. Production restore drills on clone (roadmap P7) evidence attached.

Enablement is **last**. Flag stays **false by default**.

---

## 6. Conflicts and resolutions

| Pair | Potential conflict | Resolution |
|------|-------------------|------------|
| Super Admin “bypass C8 WARNING” | Privilege vs Integrity Principle | **Forbidden** — OD-C8 + Integrity Principle |
| OD-VERIFY-WARN soft success vs Global Restore ops | Users reopen mid-FAILED | **Forbidden** — session FAILED; Maint ON; Resume/Rollback only |
| OD-FA-RESOLVER country_id shortcut | FA-01 / C1.1 | **Forbidden** — matrix only |
| OD-FA-SCHEMA fixture soft-skip on prod | FA-03 | **Forbidden** on Production and Certification |
| OD-C8 SAFE ≠ cutover auth | Operator confusion | Still true: SAFE is entry proof only; security controls (OD-PHRASE etc.) still apply |
| Prior Group 1–3 conflicts | See prior resolutions | Unchanged |
| OD-UPLOADS full-tree rename | Survivor files | Still open; prefer scoped |

**No unresolved contradiction** among OWNER_APPROVED decisions including Gates & Integrity.

---

## 7. Frozen policies (must not be reopened by OD answers)

| Frozen | Effect on OD register |
|--------|----------------------|
| C1.1 D1–D6 / Multicountry §13 | Boundary SoT |
| Integrity Principle | No OD may allow privilege to bypass missing proofs |
| Groups 1–3 + Gates & Integrity | OWNER_APPROVED — do not reopen without a new owner decision |

If an owner answer would violate these, mark **REJECTED** and re-ask.

---

## 8. Validation checklist (P0b)

| Check | Result |
|-------|--------|
| Every P0 catalog OD-* in register | **YES** (26) + **OD-MAINT** (27) |
| Gates & Integrity OWNER_APPROVED after owner workshop | **YES** |
| Integrity Principle recorded | **YES** |
| Remaining ODs still PROPOSED | **YES** (OD-UPLOADS, locks, PERM, RUNBOOK, CERT, SCHEMA, LOCK-TTL, …) |
| No implementation presented as policy | **YES** |
| C1.1 not reopened | **YES** |

---

## 9. Summary counts

| Gate | Decisions |
|------|-----------|
| OWNER_APPROVED (Group 1) | 4 |
| OWNER_APPROVED (Group 2 Maint) | 5 |
| OWNER_APPROVED (Group 3) | 4 IDs + Maintenance State |
| OWNER_APPROVED (Gates & Integrity) | 6 + Integrity Principle |
| OWNER_APPROVED named ODs total | **19** (+ Maintenance State + Integrity Principle) |
| Still block P1 (open minimum set) | **3** — OD-UPLOADS, OD-LOCK-CROSS, OD-LOCK-SHADOW |
| Block enablement | OD-ENABLE four gates + all frozen ODs proven |

---

*End of dependency map — P0b. Groups 1–3 + Gates & Integrity frozen. No P1. No implementation.*
