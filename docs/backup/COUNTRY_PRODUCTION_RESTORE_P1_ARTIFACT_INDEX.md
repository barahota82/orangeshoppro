# Country Production Restore — P1 Artifact Index (Design Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-01** — P1 Design Control Plane |
| **Status** | COMPLETE (design control only) |
| **Date** | 2026-07-21 |
| **P1 plan** | Approved by Owner (P1 Execution Authorization) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (synchronized) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **Workshop / Dependencies** | `COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` · `COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P1 Design Control Plane: inventory, naming, citation rules, change control, WP→OD/architecture map |

---

## 1. Purpose

This document establishes how all P1 design artifacts are:

- Named  
- Stored  
- Versioned  
- Bound to the OWNER_APPROVED Register (SoT)  
- Mapped to Work Packages and architecture sections  

It does **not** redefine policy. It does **not** modify architecture. It does **not** implement mutation engines.

---

## 2. Hard rules (binding for all P1 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **Baseline:** Synchronized P0 Architecture is the technical implementation baseline; where it conflicts with the register, **the register wins** (architecture Document control).  
3. **No redesign:** Do not change architecture documents to fit design convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative field in a P1 contract must cite an OD frozen wording and/or an architecture section.  
6. **Insufficient policy:** If frozen policy is insufficient to specify a contract → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/artifacts only; never modify CRP engines or semantics.  
8. **Enablement:** Design contracts keep production enablement **hard false** until OD-ENABLE path; P1 does not flip enablement.  

---

## 3. Coding / execution scope (WP-P1-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P1 **design artifacts** (schemas, matrices, contracts as docs) for authorized WPs | **Yes** — P1 Implementation Plan APPROVED; WP-P1-01 executing |
| WP-P1-02+ design work | **No** until Owner approves each next WP (Owner: complete WP-P1-01, then STOP) |
| Production mutation **PHP/SQL/CLI/HTTP/UI engine code** (P3+ roadmap) | **No** — remains out of scope until a **separate Owner coding authorization** after P1 design baseline (see WP-P1-14) |
| Architecture or Owner Decision edits | **No** |

**Plan-approval note:** The approved P1 Implementation Plan acceptance line “coding out of scope until plan approval” is satisfied for **design execution**. Mutation-engine coding remains explicitly out of P1 per the frozen architecture roadmap (P3+) and requires separate Owner authorization.

---

## 4. Storage layout

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_ARTIFACT_INDEX.md` | **This file** — Design Control Plane (WP-P1-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_*.md` | Subsequent P1 design artifacts (one primary file per WP unless a WP explicitly needs a small related set) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` | Frozen baseline — **do not modify** in P1 WPs |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` | SoT — **do not modify** in P1 WPs |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_WORKSHOP.md` | Frozen workshop — **do not modify** |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_DECISION_DEPENDENCIES.md` | Frozen map — **do not modify** |
| `docs/backup/GLOBAL_RESTORE_OPERATIONAL_POLICY.md` | Clarification — **do not modify** unless Owner expands scope |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` | Clarification — **do not modify** unless Owner expands scope |

P1 does **not** place design contracts under `admin/`, `api/`, `includes/`, or `scripts/` (those are for later coding phases).

---

## 5. Naming convention

### 5.1 Work Package IDs

Format: `WP-P1-NN` where `NN` is zero-padded order (`01` … `14`).

### 5.2 Artifact file names

Format:

```text
COUNTRY_PRODUCTION_RESTORE_P1_<WPNN>_<SHORT_NAME>.md
```

Rules:

- `WPNN` = two digits matching the Work Package (`01` … `14`).  
- `SHORT_NAME` = `UPPER_SNAKE_CASE`, concise, English.  
- One **primary** deliverable file per WP.  
- Optional secondary files only if the WP acceptance criteria require a separable schema pack; they must still use the same `P1_<WPNN>_` prefix and be listed in §7 before merge.  
- UTF-8 without BOM.  

### 5.3 In-document artifact IDs

Each primary file declares:

```text
Artifact-ID: CPR-P1-WPNN-<SHORT_NAME>
```

Example: `CPR-P1-WP02-EXECUTION_CONTRACT`

### 5.4 Versioning

| Field | Rule |
|-------|------|
| Document `Version` | Start at `1.0` for first merge of that WP |
| Revision | Increment minor (`1.1`) for clarifications that do not change normative meaning; major (`2.0`) only if correcting an error against SoT (with escalation note) |
| Git | One WP per commit/PR when possible; message cites `WP-P1-NN` |
| Baseline pin | Design packs cite tag `P0-P0b-Final` |

---

## 6. Register citation required (normative)

Every P1 contract document **must** include a citation table covering each normative rule it encodes:

| Column | Required content |
|--------|------------------|
| **Rule / field** | What the contract asserts |
| **OD / Principle** | Exact OD ID(s) or foundational principle name |
| **Register anchor** | Prefer **§15 Frozen policy wording**; else Final owner answer |
| **Architecture §** | Section number(s) in synchronized P0 Architecture |
| **Notes** | “Equivalent paraphrase” only if wording cannot be verbatim; meaning must not diverge |

**Forbidden:** Normative rules with no OD/principle citation.  
**Forbidden:** Citing superseded P0 “open catalog” text as authority.  
**Forbidden:** Citing Full DR dual-control (Audit R3 OD-1) as CPR authority (OD-DUAL is SoT).

---

## 7. Planned P1 artifact inventory

Status legend: `PLANNED` = not yet created · `COMPLETE` = WP delivered · `N/A` = control-plane only

| WP | Artifact-ID | Planned primary file | Status |
|----|-------------|----------------------|--------|
| WP-P1-01 | CPR-P1-WP01-ARTIFACT_INDEX | `COUNTRY_PRODUCTION_RESTORE_P1_ARTIFACT_INDEX.md` | **COMPLETE** |
| WP-P1-02 | CPR-P1-WP02-EXECUTION_CONTRACT | `COUNTRY_PRODUCTION_RESTORE_P1_02_EXECUTION_CONTRACT.md` | **COMPLETE** |
| WP-P1-03 | CPR-P1-WP03-STATE_TRANSITION_MATRIX | `COUNTRY_PRODUCTION_RESTORE_P1_03_STATE_TRANSITION_MATRIX.md` | **COMPLETE** |
| WP-P1-04 | CPR-P1-WP04-CHECKPOINT_SCHEMAS | `COUNTRY_PRODUCTION_RESTORE_P1_04_CHECKPOINT_SCHEMAS.md` | **COMPLETE** |
| WP-P1-05 | CPR-P1-WP05-LOCK_FORMATS | `COUNTRY_PRODUCTION_RESTORE_P1_05_LOCK_FORMATS.md` | **COMPLETE** |
| WP-P1-06 | CPR-P1-WP06-AUTHORITY_RUNBOOK | `COUNTRY_PRODUCTION_RESTORE_P1_06_AUTHORITY_RUNBOOK.md` | **COMPLETE** |
| WP-P1-07 | CPR-P1-WP07-MAINTENANCE_TIMEOUT | `COUNTRY_PRODUCTION_RESTORE_P1_07_MAINTENANCE_TIMEOUT.md` | **COMPLETE** |
| WP-P1-08 | CPR-P1-WP08-PRE_PONR_GATES | `COUNTRY_PRODUCTION_RESTORE_P1_08_PRE_PONR_GATES.md` | **COMPLETE** |
| WP-P1-09 | CPR-P1-WP09-FAIL_RESUME_ROLLBACK | `COUNTRY_PRODUCTION_RESTORE_P1_09_FAIL_RESUME_ROLLBACK.md` | PLANNED |
| WP-P1-10 | CPR-P1-WP10-UPLOADS_CONTRACT | `COUNTRY_PRODUCTION_RESTORE_P1_10_UPLOADS_CONTRACT.md` | PLANNED |
| WP-P1-11 | CPR-P1-WP11-VERIFY_REPORTS | `COUNTRY_PRODUCTION_RESTORE_P1_11_VERIFY_REPORTS.md` | PLANNED |
| WP-P1-12 | CPR-P1-WP12-AUDIT_METRICS_ALERTS | `COUNTRY_PRODUCTION_RESTORE_P1_12_AUDIT_METRICS_ALERTS.md` | PLANNED |
| WP-P1-13 | CPR-P1-WP13-ENABLEMENT_CERT_HOOKS | `COUNTRY_PRODUCTION_RESTORE_P1_13_ENABLEMENT_CERT_HOOKS.md` | PLANNED |
| WP-P1-14 | CPR-P1-WP14-INTEGRATION_BASELINE | `COUNTRY_PRODUCTION_RESTORE_P1_14_INTEGRATION_BASELINE.md` | PLANNED |

**Drift control:** Later WPs must not introduce primary filenames absent from this table without updating **this index** in the same WP’s Owner-authorized change (still without modifying architecture/register).

---

## 8. Work Package → OD / Architecture map

| WP | Purpose (plan) | Primary OD / Principles | Architecture §§ (minimum) |
|----|----------------|-------------------------|---------------------------|
| **WP-P1-01** | Design control plane | Governance Principle (citation discipline); all ODs as inventory targets | Document control; roadmap P1 |
| **WP-P1-02** | Job identity, idempotency, execution contract | OD-PIN, OD-INV, OD-C8, OD-ENABLE (flag false) | §6, §7, §14, §34–§37 |
| **WP-P1-03** | State transition matrix | OD-DUAL, OD-FAIL-DELETE, OD-FAIL-IMPORT, OD-ROLLBACK, OD-TIMEOUT, OD-LOCK-TTL | §12, §13, §17, §28 |
| **WP-P1-04** | Checkpoint schemas CP0–CP12 / CP-A | OD-PIN, OD-RUNBOOK, OD-INV, OD-MAINT | §18, §6 (stage order) |
| **WP-P1-05** | Lock formats & exclusion | OD-LOCK-CROSS, OD-LOCK-SHADOW, OD-LOCK-TTL; Isolation Principle | §15, §16 |
| **WP-P1-06** | Authority, permissions, runbook | OD-DUAL, OD-PERM, OD-PHRASE, OD-BREAK, OD-RUNBOOK | §7, §8, §25–§27 |
| **WP-P1-07** | GLOBAL maint & duration/timeout | OD-MAINT, OD-MAINT-SCOPE, OD-MAINT-MAX, OD-RTO, OD-TIMEOUT; Maintenance State | §9, §29; Global Restore Operational Policy |
| **WP-P1-08** | Pre-PONR machine-checkable gates | OD-C8, OD-INV, OD-PIN, OD-FA-RESOLVER, OD-FA-STOCK, OD-FA-SCHEMA, OD-SCHEMA, OD-LOCK-* | §35–§37, §19 (preconditions) |
| **WP-P1-09** | Fail / Resume / Rollback | OD-FAIL-DELETE, OD-FAIL-IMPORT, OD-ROLLBACK, OD-PIN | §11–§13, §31–§33 |
| **WP-P1-10** | Scoped uploads contract | OD-UPLOADS; Isolation Principle | §10.2 B, §31 |
| **WP-P1-11** | Post-apply verify & reports | OD-VERIFY-WARN, OD-FA-*; Integrity Principle | §19, §20 (reports), §31 |
| **WP-P1-12** | Audit / metrics / alerts | OD-RUNBOOK, OD-LOCK-TTL, OD-BREAK, OD-PHRASE | §20–§24 |
| **WP-P1-13** | Enablement & cert hooks | OD-ENABLE, OD-CERT, OD-SCHEMA, OD-PERM | §3, §37 A, Relationship to Full DR (enablement row) |
| **WP-P1-14** | Integration review & design freeze | All OD-* + three principles (cross-check) | Full pipeline §6 + all P1 artifacts |

Foundational principles (always in force for every WP):

| Principle | Binds |
|-----------|--------|
| Integrity over privilege | Gates, no Super Admin safety bypass |
| Recovery scope isolation | Uploads, locks, survivor safety |
| Operational governance | Permissions, runbook, cert, schema re-auth — never weakens Integrity/Isolation/Global Restore Policy |

---

## 9. Change control (P1 design phase)

| Change type | Allowed? | Process |
|-------------|----------|---------|
| Add/complete a PLANNED artifact for an Owner-authorized WP | Yes | Implement that WP only; update §7 status to COMPLETE |
| Rename a PLANNED file before first creation | Yes | Update §7 in same commit as WP-P1-01 index update when Owner authorizes that WP |
| Modify Architecture / Owner Decisions / Workshop / Dependencies | **No** | Escalate to Owner |
| Add normative behavior not cited to frozen OD | **No** | STOP · Document · Escalate |
| Start WP-P1-02 before Owner approval of WP-P1-01 | **No** | Wait |
| Mutation engine code under “P1” | **No** | Separate coding authorization; roadmap P3+ |

---

## 10. WP-P1-01 acceptance criteria verification

| Criterion (approved plan) | Result |
|---------------------------|--------|
| Index lists all planned P1 outputs | **PASS** — §7 lists WP-P1-01 … WP-P1-14 primary artifacts |
| Every WP maps to frozen OD/architecture sections | **PASS** — §8 map |
| States coding scope relative to plan approval | **PASS** — §3: design execution authorized; mutation-engine coding still requires separate Owner authorization / remains P3+ |

Additional WP-P1-01 completeness checks:

| Check | Result |
|-------|--------|
| Naming convention defined | **PASS** — §5 |
| Storage layout defined | **PASS** — §4 |
| Register citation rule defined | **PASS** — §6 |
| Change-control / no OD reopen | **PASS** — §2, §9 |
| Baseline tag cited | **PASS** — `P0-P0b-Final` |

---

## 11. Assumptions

1. Owner-approved P1 Implementation Plan Work Package definitions (WP-P1-01 … WP-P1-14) remain the scope boundaries for artifact content.  
2. One primary markdown artifact per WP is sufficient unless a later WP’s acceptance criteria explicitly require a secondary schema file (then listed in §7 before merge).  
3. JSON Schema may be embedded inside the WP markdown files or as fenced schema blocks; separate `.json` files are optional and must use the same `COUNTRY_PRODUCTION_RESTORE_P1_<WPNN>_` prefix if introduced.  
4. `scripts/orange_db.sql` is not required for WP-P1-01 (no schema migration work).  

---

## 12. Risks discovered

| Risk | Severity | Mitigation |
|------|----------|------------|
| Later WPs invent undocumented filenames | Medium | §7 drift control — update index in same authorized WP |
| Design authors cite obsolete P0 “unresolved catalog” | High | §6 forbids; superseded sections are not authority |
| Premature PHP under P1 | High | §3 coding scope; Owner gate per WP; roadmap P3+ |

No architectural insufficiency discovered during WP-P1-01. No escalation required.

---

## 13. Document control

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-21 | WP-P1-01 complete — Design Control Plane / Artifact Index |
| 1.1 | 2026-07-21 | WP-P1-02 marked COMPLETE in inventory (§7) |
| 1.2 | 2026-07-21 | WP-P1-03 marked COMPLETE in inventory (§7) |
| 1.3 | 2026-07-21 | WP-P1-04 marked COMPLETE in inventory (§7) |
| 1.4 | 2026-07-21 | WP-P1-05 marked COMPLETE in inventory (§7) |
| 1.5 | 2026-07-21 | WP-P1-06 marked COMPLETE in inventory (§7) |
| 1.6 | 2026-07-21 | WP-P1-07 marked COMPLETE in inventory (§7) |
| 1.7 | 2026-07-21 | WP-P1-08 marked COMPLETE in inventory (§7) |

**Artifact-ID:** `CPR-P1-WP01-ARTIFACT_INDEX`

---

*End of WP-P1-01. STOP — do not begin WP-P1-02 until Owner review and approval.*
