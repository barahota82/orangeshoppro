# Country Production Restore — P3 Artifact Index (Engine Scaffolding Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P3-01** — P3 Engine Scaffolding Control Plane |
| **Artifact-ID** | `CPR-P3-WP01-ARTIFACT_INDEX` |
| **Status** | COMPLETE (scaffolding control plane) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner **P3 Execution Authorization** (after P2 Design Baseline freeze) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **P2 design baseline** | Git tag `P2-Design-Baseline` → tag SHA `6a376cfa` → commit `4cadc687` |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (**do not modify**) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P3 Control Plane: inventory, naming, coding scope, hard rules, WP→contract map for engine scaffolding |

---

## 1. Purpose

Establish how all **P3 Engine Scaffolding** artifacts (design notes + code delivered in later P3 WPs) are named, stored, versioned, and bound to:

- OWNER_APPROVED Register  
- P0 Architecture (unchanged)  
- P1 Design Baseline (`P1-Design-Baseline`)  
- P2 Design Baseline (`P2-Design-Baseline`)  

Per Architecture roadmap:

| Phase | Name | Output |
|-------|------|--------|
| **P3** | Engine scaffolding | **Job framework + gates only (no PONR)** |

This Work Package:

- Opens P3 under Owner authorization.  
- Defines the scaffolding charter and Work Package inventory.  
- Does **not** implement PHP/SQL/CLI/HTTP/UI in WP-P3-01 itself.  
- Does **not** implement PONR, delete/import/uploads apply, rollback workers, enablement flip, or C3–C8 changes.  
- Does **not** redesign Architecture or reopen Owner Decisions.

---

## 2. Hard rules (binding for all P3 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **Baselines:** Consume P0 Architecture, P1 contracts, and P2 certification design as frozen; do not silently revise them.  
3. **No redesign:** Do not change Architecture documents to fit scaffolding convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative scaffold behavior must cite OD frozen wording and/or Architecture section and/or P1/P2 Artifact-ID.  
6. **Insufficient policy:** If frozen policy is insufficient → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/hashes/gates only; never modify CRP engines or semantics.  
8. **Enablement:** Remains **hard false**. P3 must enforce G01 fail-closed while flag false; P3 does **not** flip enablement.  
9. **No PONR in P3:** Scaffolding must **not** enter CP-A / delete / import / uploads apply / post-PONR pause/resume/rollback execution. Those are P4+.  
10. **No HTTP-triggered production mutation:** Long-running non-HTTP workers remain the mutation path (Architecture §4.1); P3 does not create HTTP mutate endpoints.  
11. **New project path:** CPR scaffolding is **not** a patch of Full DR restore framework or C3–C8; reuse patterns only where Architecture requires (locks, maint, audit shapes) without merging tracks.  
12. **Certification:** P3 does not grant Owner Cert PASS; P2 contracts remain SoT for cert program.  

---

## 3. Coding / execution scope (WP-P3-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P3 **control-plane artifacts** for WP-P3-01 | **Yes** — this WP |
| WP-P3-02+ scaffolding (including PHP under those WPs) | **No** until Owner approves each next WP |
| **PONR / production apply / rollback workers** | **No** — P4+ |
| Enablement flag flip | **No** — P9 / OD-ENABLE |
| Architecture or Owner Decision edits | **No** |
| C3–C8 engine edits | **No** |

**WP-P3-01 coding:** **None.** This WP is documentation control plane only.

**Later P3 WPs (when Owner-approved):** May introduce scaffolding PHP/CLI under §5 paths that implement **job framework + gate evaluation only**, still with **no PONR**.

---

## 4. P3 scaffolding charter (roadmap binding)

### 4.1 In scope for P3 (across WP-P3-02+)

| Capability | Consumes | Must not |
|------------|----------|----------|
| Job identity / create / read / list / cancel (pre-PONR) | P1-02, P1-03, P1-06 | Execute PONR; mutate production data |
| Idempotency & execution contract freeze scaffolding | P1-02 | Attach session pin as production mutation enabler beyond scaffold storage |
| Pre-PONR state machine transitions only | P1-03 (subset) | T09 CP-A→delete; post-PONR states as live mutation |
| Pre-PONR checkpoint writers (CP0–CP5, runbook evidence as applicable) | P1-04 | Authorize PONR; write success CPs for apply phases |
| CPR lock acquire/release scaffolding (pre-PONR rules) | P1-05 | Post-PONR auto-unlock invention; steal locks |
| Gate evaluator suite G01–G30 (fail-closed) | P1-08 | Skip gates; treat enablement-false as PASS; waive C8 WARNING |
| Permission / phrase / re-auth challenge scaffolding | P1-06, OD-PHRASE | Treat challenge as PONR authorize for apply |
| Audit / metrics / alert emit hooks | P1-12 | Secret-bearing payloads |
| Enablement flag **read** as hard false | P1-13, OD-ENABLE | Write true |

### 4.2 Explicitly out of scope for all of P3

| Item | Deferred to |
|------|-------------|
| Maint ON proof + NEW Full Backup pin path as live pre-PONR production ceremony | P4 |
| CP-A / PONR / delete / import / special handlers | P4–P5 |
| Scoped uploads apply | P5 |
| Post-apply verify workers | P6 |
| Resume / Rollback execution workers | P6 (design P1-09; drills P7) |
| Clone drills / evidence pack assembly runs | P7 |
| Owner Cert PASS ceremony | P8 |
| Enablement true | P9 |
| C3–C8 modifications | Forbidden |

### 4.3 “Gates only (no PONR)” acceptance meaning

A P3 scaffolding build is successful when:

1. A CPR job can be created/viewed/cancelled under OD-PERM/OD-DUAL rules **without** production row mutation.  
2. Gate suite can evaluate and **fail-closed** (including G01 while enablement false).  
3. Contract fingerprints can be stored/frozen per P1-02 without proceeding to PONR.  
4. No code path reaches delete/import/uploads apply or sets enablement true.  
5. All behaviors cite P1/P2/Register — no silent policy.

---

## 5. Storage layout

### 5.1 Design / control documents

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` | **This file** — P3 Control Plane (WP-P3-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_*.md` | Subsequent P3 design/scaffold notes (one primary file per WP) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_*.md` | Frozen P1 — **do not modify** |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_*.md` | Frozen P2 — **do not modify** |
| Architecture / Register / Workshop / Dependencies / Ops clarifications | Frozen — **do not modify** |

### 5.2 Intended code roots (for WP-P3-02+ only — not created in WP-P3-01)

| Path (design intent) | Role |
|----------------------|------|
| `includes/backup/country_production/` | CPR scaffolding libraries (job, gates, locks, contract) |
| `admin/api/country_production/` | Control-plane APIs (create/view/cancel/gate-eval status) — **no mutate** |
| `scripts/backup/country_production/` | Non-HTTP workers / self-tests for scaffolding |
| `{workRoot}/country_production/` | Runtime job dirs, locks, checkpoints (Architecture) |

**Forbidden in P3:** Editing C3–C8 engines under country shadow/CRP paths; inventing a third Full DR wipe engine; placing CPR mutate logic under HTTP request handlers.

---

## 6. Naming convention

### 6.1 Work Package IDs

Format: `WP-P3-NN` where `NN` is zero-padded order (`01` … `09`).

### 6.2 Artifact file names

```text
COUNTRY_PRODUCTION_RESTORE_P3_<WPNN>_<SHORT_NAME>.md
```

### 6.3 In-document artifact IDs

```text
Artifact-ID: CPR-P3-WPNN-<SHORT>
```

### 6.4 PHP / module naming (later WPs)

Prefer `orange_cpr_*` / `country_production_*` prefixes consistent with existing Orange helpers; never reuse Full DR job ids as CPR job ids.

---

## 7. Work Package inventory (P3)

| WP | Title | Primary artifact | Status |
|----|-------|------------------|--------|
| **WP-P3-01** | P3 Engine Scaffolding Control Plane | `COUNTRY_PRODUCTION_RESTORE_P3_ARTIFACT_INDEX.md` | **COMPLETE** |
| **WP-P3-02** | Job framework scaffolding (identity, persist, list/cancel) | `COUNTRY_PRODUCTION_RESTORE_P3_02_JOB_FRAMEWORK.md` | **COMPLETE** |
| **WP-P3-03** | State engine & transition enforcement | `COUNTRY_PRODUCTION_RESTORE_P3_03_STATE_SCAFFOLD.md` | **COMPLETE** |
| **WP-P3-04** | Checkpoint engine & persistence | `COUNTRY_PRODUCTION_RESTORE_P3_04_CHECKPOINT_SCAFFOLD.md` | **COMPLETE** |
| **WP-P3-05** | Lock engine & concurrency enforcement | `COUNTRY_PRODUCTION_RESTORE_P3_05_LOCK_SCAFFOLD.md` | **COMPLETE** |
| **WP-P3-06** | Pre-PONR gate evaluation engine | `COUNTRY_PRODUCTION_RESTORE_P3_06_GATE_EVALUATOR.md` | **COMPLETE** |
| **WP-P3-07** | Pre-PONR Authorization & Contract Freeze Engine | `COUNTRY_PRODUCTION_RESTORE_P3_07_AUTHORITY_SCAFFOLD.md` | **COMPLETE** |
| **WP-P3-08** | Audit / metrics / alert emit scaffolding | `COUNTRY_PRODUCTION_RESTORE_P3_08_AUDIT_SCAFFOLD.md` | PENDING |
| **WP-P3-09** | P3 integration review & scaffolding baseline freeze | `COUNTRY_PRODUCTION_RESTORE_P3_09_INTEGRATION_BASELINE.md` | PENDING |

**Execution rule (Owner):** One WP at a time → Verify AC → Commit → Push → **STOP** → wait for approval before next WP.

---

## 8. WP → baseline contract map

| WP | Primary OD / principles | Primary P1 | Primary P2 | Architecture |
|----|-------------------------|------------|------------|--------------|
| WP-P3-01 | OD-ENABLE, OD-CERT, OD-PERM, Integrity | P1-01…14 freeze | P2-01…07 freeze | Roadmap P3; §4 safety |
| WP-P3-02 | OD-DUAL, OD-PERM | P1-02 | — | §14 identity |
| WP-P3-03 | OD-FAIL-*; OD-ROLLBACK; OD-PERM | P1-03 · P1-09 | — | §12 states (enforced scaffold) |
| WP-P3-04 | OD-PIN; OD-RUNBOOK evidence bind | P1-04 | — | §18 checkpoints (scaffold) |
| WP-P3-05 | OD-LOCK-CROSS/SHADOW/TTL | P1-05 | — | §15–§16 (scaffold) |
| WP-P3-06 | OD-C8, OD-ENABLE, OD-FA-*, OD-INV, … | P1-08 | P2-02 CG-M04 | §37 gates (scaffold) |
| WP-P3-07 | OD-DUAL, OD-PHRASE, OD-BREAK, OD-PERM, OD-RUNBOOK | P1-06 | — | §26–§27 |
| WP-P3-08 | Audit expectations | P1-12 | P2-06 audit types (register at code) | §20–§24 |
| WP-P3-09 | All cited | All P3 + baselines | Freeze | Freeze |

---

## 9. Consistency commitments

| Baseline | Commitment |
|----------|------------|
| OWNER_APPROVED Register | No reopen; implement only frozen wording |
| P0 Architecture | Technical baseline; register wins conflicts; **do not modify** file |
| P1 Design Baseline | Job/state/lock/gate/authority/audit contracts are SoT for scaffolding |
| P2 Design Baseline | Cert/enablement/schema-recert contracts constrain G01/G04/cert reads; P3 does not run cert ceremony |

---

## 10. Citation rules

1. Prefer **OD-ID + §15 Frozen** when stating policy.  
2. Prefer **P1/P2 Artifact-ID** when stating contracts.  
3. Prefer **Architecture section** as implementation narrative; register wins.  
4. Do not cite draft chat as authority.

---

## 11. Change control

| Change type | Allowed in P3? |
|-------------|----------------|
| New/updated `COUNTRY_PRODUCTION_RESTORE_P3_*.md` under authorized WP | Yes |
| Scaffolding code under §5.2 paths in authorized WP | Yes (not in WP-P3-01) |
| Edit frozen P0/P1/P2/Architecture/Register | **No** |
| PONR / apply / enablement true | **No** |
| C3–C8 edits | **No** |

---

## 12. Acceptance criteria (WP-P3-01)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | P3 control plane document exists with Artifact-ID | **PASS** |
| AC2 | Hard rules bind SoT, baselines, no OD reopen, no architecture redesign, enablement hard false, no PONR | **PASS** §2 |
| AC3 | P3 roadmap objective recorded: job framework + gates only (no PONR) | **PASS** §1, §4 |
| AC4 | Scaffolding charter in/out of scope defined | **PASS** §4 |
| AC5 | Naming, storage (docs + intended code roots), citation, change control defined | **PASS** §5–§6, §10–§11 |
| AC6 | P3 WP inventory WP-P3-01…09 listed; only WP-P3-01 COMPLETE | **PASS** §7 |
| AC7 | WP-P3-01 contains no PHP/SQL/CLI/HTTP/UI implementation | **PASS** §3 |
| AC8 | Consistency with Register, P0, P1 baseline, P2 baseline explicitly committed | **PASS** §9 |
| AC9 | Architecture and Owner Decisions not modified by this WP | **PASS** |

---

## 13. Stop rule

**WP-P3-01…WP-P3-07 COMPLETE** (through pre-PONR authorization & contract freeze).  
Commit → Push → **STOP.**  
Do **not** begin WP-P3-08 until Owner explicitly approves the next Work Package.

---

*End of WP-P3-01 — P3 Engine Scaffolding Control Plane (inventory updated as later WPs complete).*
