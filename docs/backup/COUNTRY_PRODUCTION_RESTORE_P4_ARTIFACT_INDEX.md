# Country Production Restore — P4 Artifact Index (Pre-PONR Path Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P4-01** — P4 Control Plane & Artifact Index |
| **Artifact-ID** | `CPR-P4-WP01-ARTIFACT_INDEX` |
| **Status** | COMPLETE (P4 control plane) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner **P4 Execution Authorization** (after P3 Engine Baseline freeze + Enterprise Audit PASSED) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **P2 design baseline** | Git tag `P2-Design-Baseline` → commit `4cadc687` |
| **P3 engine baseline** | Git tag `P3-Engine-Baseline` → commit `7a7f8c99` |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (**do not modify**) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P4 Control Plane: inventory, naming, coding scope, hard rules, WP→baseline map for the Pre-PONR path |

---

## 1. Purpose

Establish how all **P4 Pre-PONR Path** artifacts (design notes + code delivered in later P4 WPs) are named, stored, versioned, and bound to:

- OWNER_APPROVED Register  
- P0 Architecture (unchanged)  
- P1 Design Baseline (`P1-Design-Baseline`)  
- P2 Design Baseline (`P2-Design-Baseline`)  
- P3 Engine Baseline (`P3-Engine-Baseline`)  

Per Architecture roadmap:

| Phase | Name | Output |
|-------|------|--------|
| **P4** | Pre-PONR path | **Anchor, approvals, maint, witnesses** |

This Work Package:

- Opens P4 under Owner authorization.  
- Defines the Pre-PONR path charter and Work Package inventory.  
- Does **not** implement PHP/SQL/CLI/HTTP/UI production engines in WP-P4-01 itself.  
- Does **not** implement PONR, DELETE, IMPORT, uploads apply, rollback workers, enablement flip, or C3–C8 changes.  
- Does **not** redesign Architecture or reopen Owner Decisions.

---

## 2. Hard rules (binding for all P4 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **Baselines:** Consume P0 Architecture, P1 contracts, P2 certification design, and P3 engine baseline as frozen; do not silently revise them.  
3. **No redesign:** Do not change Architecture documents to fit P4 convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative P4 behavior must cite OD frozen wording and/or Architecture section and/or P1/P2/P3 Artifact-ID.  
6. **Insufficient policy:** If frozen policy is insufficient → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/hashes/gates only; never modify CRP engines or semantics.  
8. **Enablement:** Remains **hard false**. P4 must keep G01 / OD-ENABLE fail-closed; P4 does **not** flip enablement (P9).  
9. **No PONR execution in P4:** P4 may reach **CP-A** (last fully reversible idle point) and complete the pre-PONR ceremony. P4 must **not** perform production DELETE / IMPORT / uploads apply / post-PONR pause-resume-rollback workers (P5+).  
10. **No HTTP-triggered production mutation:** Long-running non-HTTP workers remain the mutation path (Architecture §4.1); P4 does not create HTTP mutate endpoints for production apply.  
11. **Consume P3 engines:** Extend / orchestrate P3 job, state, checkpoint, lock, gate, authority, and mutation-skeleton frameworks — do not fork a second CPR stack.  
12. **Certification:** P4 does not grant Owner Cert PASS; P2 contracts remain SoT for the cert program (P7–P8).  

---

## 3. Coding / execution scope (WP-P4-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P4 **control-plane artifacts** for WP-P4-01 | **Yes** — this WP |
| WP-P4-02+ implementation (including PHP under those WPs) | **No** until Owner approves each next WP |
| **PONR / DELETE / IMPORT / uploads apply / rollback workers** | **No** — P5+ |
| Enablement flag flip | **No** — P9 / OD-ENABLE |
| Architecture or Owner Decision edits | **No** |
| C3–C8 engine edits | **No** |
| P1 / P2 / P3 frozen baseline edits | **No** |

**WP-P4-01 coding:** **None.** This WP is documentation control plane only.

**Later P4 WPs (when Owner-approved):** May introduce PHP/CLI under §5.2 that implement the **live Pre-PONR path** (anchor, approvals, maint, witnesses, CP-A), still with **no production DELETE/IMPORT**.

---

## 4. P4 Pre-PONR path charter (roadmap binding)

### 4.1 In scope for P4 (across WP-P4-02+)

Architecture §6 stages covered by P4 (through CP-A, exclusive of PONR DELETE):

| Capability | Consumes | Must not |
|------------|----------|----------|
| Approvals / OD-DUAL live binding | P1-06, P3-07, OD-DUAL, OD-PERM | Country Admin execute; waive gates |
| Execution contract freeze → `pre_ponr` live | P1-02, P3-02/07 | Illegal package/country swap after freeze |
| GLOBAL Maintenance ON + write-block proof (live) | P1-07, OD-MAINT, OD-MAINT-SCOPE, CP4 | Country-only maint; proceed without proof |
| NEW session Full Backup → verify → pin (live OD-PIN) | P1-04, OD-PIN, CP1 | Reuse existing backup as CPR anchor |
| CPR lock acquire/hold for pre-PONR ceremony | P1-05, P3-05, OD-LOCK-* | Post-PONR auto-unlock; steal locks |
| Pre-PONR gate suite live sealed evaluation | P1-08, P3-06 | Bypass / force-PASS / C8 WARNING waiver |
| Authority / Runbook / Phrase live ceremony | P1-06, P3-07, OD-PHRASE, OD-RUNBOOK | Treat OTA as authorization to DELETE/IMPORT |
| Pre-PONR witnesses (CP5) live capture | P1-04, OD-INV, Architecture §18 | Replace certified inventory with live SoT |
| CP-A last reversible checkpoint | P1-04, Architecture §18 | Enter production DELETE (PONR) |

### 4.2 Explicitly out of scope for all of P4

| Item | Deferred to |
|------|-------------|
| Production target-slice DELETE (PONR) | P5 |
| Target-slice IMPORT / special handlers | P5 |
| Scoped uploads apply | P5 |
| Post-apply verify workers | P6 |
| Resume / Rollback execution workers | P6 (design P1-09; drills P7) |
| Clone drills / evidence pack assembly runs | P7 |
| Owner Cert PASS ceremony | P8 |
| Enablement true | P9 |
| C3–C8 modifications | Forbidden |

### 4.3 “Pre-PONR path (no PONR)” acceptance meaning

A P4 Pre-PONR path build is successful when:

1. Live ceremony can reach **maint + NEW pinned session Full Backup + lock + sealed gates PASS + authority/runbook/phrase + witnesses + CP-A**.  
2. **No** code path performs production DELETE/IMPORT/uploads apply or sets enablement true.  
3. P3 engines remain the integration substrate; P4 binds live evidence into their contracts.  
4. All behaviors cite Register / Architecture / P1–P3 Artifact-IDs — no silent policy.  

---

## 5. Storage layout

### 5.1 Design / control documents

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_ARTIFACT_INDEX.md` | **This file** — P4 Control Plane (WP-P4-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P4_*.md` | Subsequent P4 design/implementation notes (one primary file per WP) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_*.md` | Frozen P1 — **do not modify** |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P2_*.md` | Frozen P2 — **do not modify** |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_*.md` | Frozen P3 — **do not modify** |
| Architecture / Register / Workshop / Dependencies / Ops clarifications | Frozen — **do not modify** |

### 5.2 Intended code roots (for WP-P4-02+ only — not created in WP-P4-01)

| Path (design intent) | Role |
|----------------------|------|
| `includes/backup/country_production/` | Extend P3 CPR libraries for live Pre-PONR path |
| `scripts/backup/country_production/` | Non-HTTP workers / self-tests for Pre-PONR ceremony |
| `admin/api/country_production/` | Control-plane APIs (status/orchestrate start) — **no production mutate / no DELETE/IMPORT** |
| `{workRoot}/country_production/` | Runtime job dirs, locks, checkpoints, auth, gates (Architecture) |

**Forbidden in P4:** Editing C3–C8 engines; inventing a Full DR wipe engine; placing CPR DELETE/IMPORT under HTTP request handlers.

---

## 6. Naming convention

### 6.1 Work Package IDs

Format: `WP-P4-NN` where `NN` is zero-padded order (`01` … `09`).

### 6.2 Artifact file names

```text
COUNTRY_PRODUCTION_RESTORE_P4_<WPNN>_<SHORT_NAME>.md
```

### 6.3 In-document artifact IDs

```text
Artifact-ID: CPR-P4-WPNN-<SHORT>
```

### 6.4 PHP / module naming (later WPs)

Prefer `orange_cpr_*` / `country_production_*` prefixes consistent with P3 helpers; never reuse Full DR job ids as CPR job ids.

---

## 7. Work Package inventory (P4)

| WP | Title | Primary artifact | Status |
|----|-------|------------------|--------|
| **WP-P4-01** | P4 Control Plane & Artifact Index | `COUNTRY_PRODUCTION_RESTORE_P4_ARTIFACT_INDEX.md` | **COMPLETE** |
| **WP-P4-02** | Approvals & execution-contract `pre_ponr` live path | `COUNTRY_PRODUCTION_RESTORE_P4_02_APPROVALS_CONTRACT_LIVE.md` | **COMPLETE** |
| **WP-P4-03** | GLOBAL Maintenance live path (CP4) | `COUNTRY_PRODUCTION_RESTORE_P4_03_MAINTENANCE_LIVE.md` | **COMPLETE** |
| **WP-P4-04** | Session Full Backup & OD-PIN live path (CP1) | `COUNTRY_PRODUCTION_RESTORE_P4_04_OD_PIN_LIVE.md` | PENDING |
| **WP-P4-05** | CPR Lock live pre-PONR path | `COUNTRY_PRODUCTION_RESTORE_P4_05_LOCK_LIVE.md` | PENDING |
| **WP-P4-06** | Pre-PONR Gate suite live evaluation | `COUNTRY_PRODUCTION_RESTORE_P4_06_GATE_LIVE.md` | PENDING |
| **WP-P4-07** | Authority / Runbook / Phrase live ceremony | `COUNTRY_PRODUCTION_RESTORE_P4_07_AUTHORITY_RUNBOOK_LIVE.md` | PENDING |
| **WP-P4-08** | Pre-PONR Witnesses (CP5) & CP-A last reversible | `COUNTRY_PRODUCTION_RESTORE_P4_08_WITNESSES_CPA.md` | PENDING |
| **WP-P4-09** | P4 Integration Review & Pre-PONR Path Baseline Freeze | `COUNTRY_PRODUCTION_RESTORE_P4_09_INTEGRATION_BASELINE.md` | PENDING |

**Execution rule (Owner):** One WP at a time → Verify AC → Commit → Push → **STOP** → wait for approval before next WP.

**Drift control:** Later WPs must not introduce primary filenames absent from this table without updating **this index** in the same Owner-authorized WP (still without modifying Architecture/Register).

---

## 8. Execution order & dependencies

Architecture §6 Pre-PONR sequence (normative ordering intent):

```text
Approvals / contract freeze (pre_ponr)
  → GLOBAL Maint ON + write-block proof (CP4)
  → NEW session Full Backup → verify → pin (CP1)
  → CPR lock acquire
  → Gate suite live sealed PASS
  → Runbook + Phrase + re-auth (OTA)
  → Pre-PONR witnesses (CP5)
  → CP-A (last reversible)
  ✗ STOP — no DELETE (PONR) in P4
```

| WP | Depends on | Unlocks |
|----|------------|---------|
| WP-P4-01 | Owner P4 authorization; P3-Engine-Baseline | All P4 WPs |
| WP-P4-02 | WP-P4-01 | WP-P4-03… |
| WP-P4-03 | WP-P4-02 | WP-P4-04 |
| WP-P4-04 | WP-P4-03 (OD-PIN: Maint before pin) | WP-P4-05, WP-P4-06 |
| WP-P4-05 | WP-P4-04 | WP-P4-06, WP-P4-07 |
| WP-P4-06 | WP-P4-04, WP-P4-05 | WP-P4-07 |
| WP-P4-07 | WP-P4-06 (sealed PASS gate) | WP-P4-08 |
| WP-P4-08 | WP-P4-07 | WP-P4-09 |
| WP-P4-09 | WP-P4-02…WP-P4-08 COMPLETE | P4 baseline freeze; wait for Owner before P5 |

---

## 9. WP → baseline contract map

| WP | Primary OD / principles | Primary P1 | Primary P2 | Primary P3 | Architecture |
|----|-------------------------|------------|------------|------------|--------------|
| WP-P4-01 | OD-ENABLE, OD-CERT, OD-PERM, Integrity | P1-01…14 freeze | P2 freeze | P3-01…09 freeze | Roadmap P4; §4 safety |
| WP-P4-02 | OD-DUAL, OD-PERM, OD-C8, OD-INV | P1-02 · P1-06 | — | P3-02 · P3-07 | §6–§8, §14 |
| WP-P4-03 | OD-MAINT, OD-MAINT-SCOPE, OD-MAINT-MAX | P1-07 · P1-04 (CP4) | — | P3-03 · P3-04 | §9; Global Restore Policy |
| WP-P4-04 | OD-PIN | P1-04 (CP1 DAG) | — | P3-04 | §6 OD-PIN stage; §18 |
| WP-P4-05 | OD-LOCK-CROSS/SHADOW/TTL; Isolation | P1-05 | — | P3-05 | §15–§16 |
| WP-P4-06 | OD-C8, OD-ENABLE, OD-FA-*, OD-INV, OD-PIN | P1-08 | P2-02 CG-M04 | P3-06 | §35–§37 |
| WP-P4-07 | OD-DUAL, OD-PHRASE, OD-BREAK, OD-PERM, OD-RUNBOOK | P1-06 | — | P3-07 | §7–§8, §25–§27 |
| WP-P4-08 | OD-INV; Integrity; Isolation | P1-04 (CP5, CP-A) | — | P3-04 · P3-08 stubs | §6, §18 |
| WP-P4-09 | All cited | All P4 + baselines | Freeze | Freeze | Freeze |

Foundational principles (always in force):

| Principle | Binds |
|-----------|--------|
| Integrity over privilege | Gates; no Super Admin safety bypass |
| Recovery scope isolation | Locks, survivor safety, OD-INV |
| Operational governance | Permissions, runbook, cert hooks — never weakens Integrity/Isolation/Global Restore Policy |

---

## 10. Consistency commitments

| Baseline | Commitment |
|----------|------------|
| OWNER_APPROVED Register | No reopen; implement only frozen wording |
| P0 Architecture | Technical baseline; register wins conflicts; **do not modify** file |
| P1 Design Baseline | Job/state/lock/gate/authority/checkpoint/maint contracts are SoT |
| P2 Design Baseline | Cert/enablement/schema-recert contracts constrain G01/G04/cert reads; P4 does not run Owner Cert ceremony |
| P3 Engine Baseline | Job/state/checkpoint/lock/gate/authority/mutation-skeleton are the integration substrate |

---

## 11. Citation rules

1. Prefer **OD-ID + §15 Frozen** when stating policy.  
2. Prefer **P1/P2/P3 Artifact-ID** when stating contracts.  
3. Prefer **Architecture section** as implementation narrative; register wins.  
4. Do not cite draft chat as authority.

---

## 12. Change control

| Change type | Allowed in P4? |
|-------------|----------------|
| New/updated `COUNTRY_PRODUCTION_RESTORE_P4_*.md` under authorized WP | Yes |
| Code under §5.2 paths in authorized WP-P4-02+ | Yes (not in WP-P4-01) |
| Edit frozen P0/P1/P2/P3/Architecture/Register | **No** |
| PONR DELETE / IMPORT / uploads apply / enablement true | **No** |
| C3–C8 edits | **No** |
| Start WP-P4-02 before Owner approval of WP-P4-01 | **No** |

---

## 13. Acceptance criteria (WP-P4-01)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | P4 control plane document exists with Artifact-ID | **PASS** |
| AC2 | Hard rules bind SoT, baselines (incl. P3), no OD reopen, no architecture redesign, enablement hard false, no PONR DELETE | **PASS** §2 |
| AC3 | P4 roadmap objective recorded: Anchor, approvals, maint, witnesses (through CP-A) | **PASS** §1, §4 |
| AC4 | Pre-PONR path charter in/out of scope defined | **PASS** §4 |
| AC5 | Naming, storage, citation, change control defined | **PASS** §5–§6, §11–§12 |
| AC6 | P4 WP inventory WP-P4-01…09 listed; WP-P4-01…03 COMPLETE when authorized | **PASS** §7 |
| AC7 | Every WP maps to OWNER_APPROVED / P0 / P1 / P2 / P3 / Architecture | **PASS** §9 |
| AC8 | Dependencies and execution order defined | **PASS** §8 |
| AC9 | Artifact names defined for every WP | **PASS** §7 |
| AC10 | WP-P4-01 contains no PHP/SQL/CLI/HTTP/UI production implementation | **PASS** §3 |
| AC11 | Architecture and Owner Decisions not modified by this WP | **PASS** |

---

## 14. Stop rule

**WP-P4-01 COMPLETE** (control plane).  
**WP-P4-02 COMPLETE** (live approvals / pre-PONR contract) — see `COUNTRY_PRODUCTION_RESTORE_P4_02_APPROVALS_CONTRACT_LIVE.md`.  
**WP-P4-03 COMPLETE** (GLOBAL Maintenance live / CP4) — see `COUNTRY_PRODUCTION_RESTORE_P4_03_MAINTENANCE_LIVE.md`.  
Commit → Push → **STOP.**  
Do **not** begin WP-P4-04 until Owner explicitly reviews and approves the next Work Package.

---

*End of WP-P4-01 — P4 Control Plane & Artifact Index (updated inventory status through WP-P4-03).*
