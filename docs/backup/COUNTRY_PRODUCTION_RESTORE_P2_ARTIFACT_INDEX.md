# Country Production Restore — P2 Artifact Index (Certification Design Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P2-01** — P2 Certification Design Control Plane |
| **Artifact-ID** | `CPR-P2-WP01-ARTIFACT_INDEX` |
| **Status** | COMPLETE (certification design control only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner **P2 Execution Authorization** (after P1 complete + Enterprise Audit PASSED) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` (WP-P1-14) |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (synchronized; **do not modify** in P2) |
| **P1 cert/enablement hooks** | `COUNTRY_PRODUCTION_RESTORE_P1_13_ENABLEMENT_CERT_HOOKS.md` |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P2 Design Control Plane: inventory, naming, citation rules, certification program scope, evidence pack catalog, WP→OD/P1 map |

---

## 1. Purpose

Establish how all **P2 certification design** artifacts are named, stored, versioned, and bound to the OWNER_APPROVED Register and the frozen P1 design baseline — and record the **Country Production certification program scope** plus the **evidence pack catalog** required for Owner PASS/FAIL (OD-CERT).

This Work Package:

- Implements the Architecture roadmap **P2** objective: *Country Production certification program + evidence pack* (design artifacts only).  
- Extends P1-13 **hooks** into a concrete certification program structure.  
- Does **not** redesign architecture, reopen Owner Decisions, flip enablement, run drills, or implement mutation engines.

---

## 2. Hard rules (binding for all P2 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **P1 baseline:** All P2 contracts must consume P1 schemas/hooks as frozen at `P1-Design-Baseline`; do not silently revise P1.  
3. **No redesign:** Do not change Architecture to fit certification convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative field in a P2 contract must cite an OD frozen wording and/or Architecture section and/or P1 Artifact-ID.  
6. **Insufficient policy:** If frozen policy is insufficient → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/gates only; never modify CRP engines or semantics.  
8. **Enablement:** Remains **hard false** until OD-ENABLE path (P9). P2 does not flip enablement.  
9. **OD-CERT split:** Owner alone grants Cert PASS/FAIL; Engineering produces evidence only.  
10. **OD-SCHEMA:** Any Production Schema Revision change invalidates prior certification; no auto re-enable.  

---

## 3. Coding / execution scope (WP-P2-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P2 **certification design** artifacts for the current authorized WP | **Yes** — P2 Execution Authorization; WP-P2-01 executing |
| WP-P2-02+ design work | **No** until Owner approves each next WP (complete WP → STOP) |
| Production mutation **PHP/SQL/CLI/HTTP/UI engine code** (P3+) | **No** — separate Owner coding authorization required |
| Running live certification drills / Owner PASS ceremony (P7–P8) | **No** — P2 designs the program; execution is later roadmap phases |
| Architecture or Owner Decision edits | **No** |
| Enablement flag flip | **No** |

---

## 4. Storage layout

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_ARTIFACT_INDEX.md` | **This file** — Certification Design Control Plane (WP-P2-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_*.md` | Subsequent P2 design artifacts (one primary file per WP) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_*.md` | Frozen P1 design pack — **do not modify** in P2 WPs |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` | Frozen baseline — **do not modify** in P2 WPs |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` | SoT — **do not modify** in P2 WPs |
| Workshop / Dependencies / Ops clarifications | Frozen — **do not modify** unless Owner expands scope |

P2 does **not** place design contracts under `admin/`, `api/`, `includes/`, or `scripts/` (those are for later coding phases).

---

## 5. Naming convention

### 5.1 Work Package IDs

Format: `WP-P2-NN` where `NN` is zero-padded order (`01` … `07`).

### 5.2 Artifact file names

Format:

```text
COUNTRY_PRODUCTION_RESTORE_P2_<WPNN>_<SHORT_NAME>.md
```

Rules:

- `WPNN` = two digits matching the Work Package.  
- `SHORT_NAME` = `UPPER_SNAKE_CASE`, concise, English.  
- One **primary** deliverable file per WP.  
- UTF-8 without BOM.  

### 5.3 In-document artifact IDs

Each primary file declares:

```text
Artifact-ID: CPR-P2-WPNN-<SHORT>
```

---

## 6. P2 objective (frozen roadmap)

From Architecture roadmap:

| Phase | Name | Output |
|-------|------|--------|
| **P2** | Certification design | Country Production certification program + evidence pack |

**Depends on:** P1 (complete; Enterprise Audit PASSED).  
**Feeds:** P3+ engine work and later P7–P8 drill/cert execution; P9 enablement only after Owner Cert PASS + OD-ENABLE path.

P2 produces **design contracts** for what must be proven and how evidence is packed — not live PASS records and not production enablement.

---

## 7. Certification program scope (OD-CERT)

### 7.1 Program name

**Country Production Restore Certification Program** (CPR Certification).

### 7.2 Program goal

Provide a complete, Owner-reviewable **evidence pack** proving that Country Production Restore is ready for Owner Cert PASS/FAIL decision **before** any OD-ENABLE enablement order — including proof of drills that cover **rollback** (OD-CERT consequences).

### 7.3 In scope (P2 design)

| Item | Notes |
|------|-------|
| Certification objectives & non-goals | This WP §7–§8 |
| Evidence pack catalog | This WP §8 |
| Checklist items (machine + human) | WP-P2-02 COMPLETE — `CPR-P2-WP02-CERT_CHECKLIST` |
| Drill scenario catalog | WP-P2-03 COMPLETE — `CPR-P2-WP03-DRILL_SCENARIOS` |
| Evidence assembly / pack schemas | WP-P2-04 COMPLETE — `CPR-P2-WP04-EVIDENCE_PACK_SCHEMAS` |
| Owner submission & PASS/FAIL package | Later WP-P2-05 |
| Schema-revision re-cert cycle binding | Later WP-P2-06 (OD-SCHEMA) |
| P2 integration freeze | Later WP-P2-07 |

### 7.4 Out of scope (P2)

| Item | Deferred to |
|------|-------------|
| Mutation-engine coding | P3+ (separate coding auth) |
| Live clone drills / real-clone proof execution | P7 |
| Live Owner Cert PASS/FAIL ceremony | P8 |
| Enablement flag true | P9 / OD-ENABLE |
| C3–C8 engine changes | Forbidden |
| Architecture / OD amendments | Forbidden |

### 7.5 Authority split (binding)

| Role | May | Must not |
|------|-----|----------|
| **Engineering** | Produce technical evidence, verification reports, certification artifacts; assemble evidence pack; submit for Owner review | Grant Cert PASS/FAIL; flip enablement; auto-enable |
| **Owner** | Final Cert PASS/FAIL; explicit enablement order (later); re-authorize after OD-SCHEMA | — |
| **Super Admin** | Operational Enable/Disable **after** Owner path (later) | Grant Cert PASS; Enable without OD-ENABLE preconditions |
| **Country Admin** | — | Cert PASS; Enable/Disable |

**Binding:** P1-13 §5; OD-CERT §15 Frozen; OD-PERM; OD-ENABLE.

### 7.6 Lifecycle states (consume P1-13; do not redefine)

P2 consumes without change:

`cert_absent` → `cert_evidence_in_progress` → `cert_submitted_for_owner` → `cert_pass` | `cert_fail` | `cert_invalidated`

Result record schema: `cpr_certification_result` (`cpr_certification_result/1`) per P1-13 §5.2.

**Reject** any PASS where `decided_by != owner`.

---

## 8. Evidence pack catalog (design inventory)

Evidence pack id field (P1-13): `package_cycle_id` + `evidence_pack_refs[]`.

Each catalog entry is a **required evidence class** for a complete CPR certification cycle bound to one Production Schema Revision. Detailed checklists/schemas are later WPs; this catalog is normative for P2 scope.

| Evidence-ID | Class | Producer | Proves | Primary P1 / OD anchors |
|-------------|-------|----------|--------|-------------------------|
| **EV-01** | Policy & baseline freeze proof | Engineering | Register + Architecture + P1 baseline tags unchanged for cycle | SoT; `P0-P0b-Final`; `P1-Design-Baseline` |
| **EV-02** | Boundary / inventory certification | Engineering | OD-INV certified read-only production inventory present for target cycle | OD-INV; Architecture § inventory |
| **EV-03** | C8 SAFE package proof | Engineering | C8 SAFE exists for package cycle (`c8_safe_evidence_ref`) | OD-C8; P1-13 |
| **EV-04** | Pre-PONR gate suite results | Engineering | G01–… pre-PONR gates evaluate as designed (enablement still false in design era) | WP-P1-08; OD-ENABLE |
| **EV-05** | Dual-control / authority path evidence | Engineering | WF-A protections or WF-B path demonstrated in drill design/results | OD-DUAL; WP-P1-06 |
| **EV-06** | Maintenance / duration / timeout posture | Engineering | GLOBAL maint + OD-MAINT-* / OD-RTO / OD-TIMEOUT honored in drill design | OD-MAINT*; WP-P1-07 |
| **EV-07** | Lock / cross-feature exclusion proof | Engineering | CROSS/SHADOW/TTL lock contracts exercised | OD-LOCK-*; WP-P1-05 |
| **EV-08** | Apply path proof (scoped DB + uploads) | Engineering | Delete/import/scoped uploads apply under flags in **non-production drill** context when engines exist | OD-UPLOADS; WP-P1-10; P5–P7 |
| **EV-09** | Post-apply verify pack | Engineering | Post-apply verify & report schemas satisfied | WP-P1-11 |
| **EV-10** | Fail / Resume / Rollback drill proof | Engineering | Fail-pause; SA Resume/Rollback (never automatic); **rollback proof required** for Cert | OD-FAIL-*; OD-ROLLBACK; OD-CERT; WP-P1-09 |
| **EV-11** | Audit / metrics / alert capture | Engineering | Audit trail & alert hooks captured for drill runs | WP-P1-12 |
| **EV-12** | Schema revision binding statement | Engineering | Cert bound to exact `schema_revision_bound`; OD-SCHEMA invalidation understood | OD-SCHEMA; P1-13 §8 |
| **EV-13** | Enablement still disabled attestation | Engineering | Flag false throughout cert cycle; no auto-enable | OD-ENABLE; P1-13 |
| **EV-14** | Owner decision package | Engineering → Owner | Assembled pack + `cpr_certification_result` PENDING → Owner PASS/FAIL | OD-CERT; P1-13 §5 |

**Minimum for Owner review submission:** EV-01…EV-14 all present as refs in `evidence_pack_refs` (or explicit N/A with Owner-accepted waiver only if frozen policy allows — default: **no waiver** without Owner escalation).

**Rollback:** OD-CERT requires certification prove drills **including rollback** — EV-10 is **mandatory**; absence is Cert FAIL grounds.

---

## 9. Work Package inventory (P2)

| WP | Title | Primary artifact | Status |
|----|-------|------------------|--------|
| **WP-P2-01** | P2 Certification Design Control Plane | `COUNTRY_PRODUCTION_RESTORE_P2_ARTIFACT_INDEX.md` | **COMPLETE** |
| **WP-P2-02** | Certification checklist (machine + Owner human review) | `COUNTRY_PRODUCTION_RESTORE_P2_02_CERT_CHECKLIST.md` | **COMPLETE** |
| **WP-P2-03** | Drill scenario catalog (incl. rollback) | `COUNTRY_PRODUCTION_RESTORE_P2_03_DRILL_SCENARIOS.md` | **COMPLETE** |
| **WP-P2-04** | Evidence pack assembly schemas | `COUNTRY_PRODUCTION_RESTORE_P2_04_EVIDENCE_PACK_SCHEMAS.md` | **COMPLETE** |
| **WP-P2-05** | Owner submission & PASS/FAIL decision package | `COUNTRY_PRODUCTION_RESTORE_P2_05_OWNER_DECISION_PACKAGE.md` | PENDING |
| **WP-P2-06** | Schema-revision re-cert cycle (OD-SCHEMA) | `COUNTRY_PRODUCTION_RESTORE_P2_06_SCHEMA_RECERT_CYCLE.md` | PENDING |
| **WP-P2-07** | P2 integration review & certification design freeze | `COUNTRY_PRODUCTION_RESTORE_P2_07_INTEGRATION_BASELINE.md` | PENDING |

**Execution rule (Owner):** One WP at a time → Verify AC → Commit → Push → **STOP** → wait for approval before next WP.

---

## 10. WP → OD / P1 / Architecture map (control plane)

| WP | Primary ODs | Primary P1 artifacts | Architecture |
|----|-------------|----------------------|--------------|
| WP-P2-01 | OD-CERT, OD-ENABLE, OD-SCHEMA | P1-13, P1-14, Artifact Index | Roadmap P2; §26–§27; enablement |
| WP-P2-02 | OD-CERT, OD-ENABLE, OD-PERM | P1-08, P1-11, P1-13 | Pre-enable lists |
| WP-P2-03 | OD-CERT, OD-ROLLBACK, OD-FAIL-*, OD-DUAL, OD-PIN | P1-09, P1-03, P1-04, P1-05 | Stages / rollback |
| WP-P2-04 | OD-CERT, OD-SCHEMA | P1-13 `evidence_pack_refs` | Cert program |
| WP-P2-05 | OD-CERT, OD-ENABLE | P1-13 result schema | Owner PASS |
| WP-P2-06 | OD-SCHEMA, OD-ENABLE, OD-CERT | P1-13 §8 | Schema invalidation |
| WP-P2-07 | All cited in P2 | All P2 + P1 baseline | Freeze |

---

## 11. Citation rules

1. Prefer **OD-ID + §15 Frozen** wording when stating policy.  
2. Prefer **P1 Artifact-ID** when stating contracts already frozen in P1.  
3. Prefer **Architecture section** only as implementation baseline; register wins conflicts.  
4. Do not cite draft chat as authority.

---

## 12. Change control

| Change type | Allowed in P2? |
|-------------|----------------|
| New/updated `COUNTRY_PRODUCTION_RESTORE_P2_*.md` under authorized WP | Yes |
| Edit frozen P1 / Architecture / Register / Workshop | **No** |
| “Fix” P1 by rewriting P1 files | **No** — escalate if defect found |
| Enablement / mutation code | **No** |

---

## 13. Acceptance criteria (WP-P2-01)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | P2 control plane document exists under `docs/backup/` with Artifact-ID | **PASS** |
| AC2 | Hard rules bind SoT, no OD reopen, no architecture redesign, enablement hard false | **PASS** (§2) |
| AC3 | Naming, storage, citation, change control defined | **PASS** (§4–§5, §11–§12) |
| AC4 | Certification program scope + authority split bound to OD-CERT / P1-13 | **PASS** (§7) |
| AC5 | Evidence pack catalog EV-01…EV-14 defined; rollback (EV-10) mandatory | **PASS** (§8) |
| AC6 | P2 WP inventory WP-P2-01…07 listed; only WP-P2-01 COMPLETE | **PASS** (§9) |
| AC7 | Coding / live drills / enablement explicitly out of scope | **PASS** (§3, §7.4) |
| AC8 | Architecture and Owner Decisions not modified by this WP | **PASS** |

---

## 14. Stop rule

**WP-P2-01 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** start WP-P2-02 until Owner explicitly approves the next Work Package.

---

*End of WP-P2-01 — P2 Certification Design Control Plane.*
