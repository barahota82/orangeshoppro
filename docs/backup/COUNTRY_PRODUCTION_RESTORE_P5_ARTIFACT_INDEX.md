# Country Production Restore — P5 Artifact Index (Production Apply Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P5-01** — P5 Control Plane & Artifact Index |
| **Artifact-ID** | `CPR-P5-WP01-ARTIFACT_INDEX` |
| **Status** | COMPLETE (P5 control plane) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner **P5 Execution Authorization** (after P4 Pre-PONR Live Baseline freeze + Enterprise Audit PASSED + phase closure) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **P2 design baseline** | Git tag `P2-Design-Baseline` → commit `4cadc687` |
| **P3 engine baseline** | Git tag `P3-Engine-Baseline` → commit `7a7f8c99` |
| **P4 Pre-PONR baseline** | Git tag `P4-PrePONR-Baseline` → commit `6bc09bcb` |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (**do not modify**) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P5 Control Plane: inventory, naming, coding scope, hard rules, WP→baseline map for Production Apply |
| **Control registry** | `includes/backup/country_production/cpr_p5_control_plane.php` |

---

## 1. Purpose

Establish how all **P5 Production Apply** artifacts (design notes + code delivered in later P5 WPs) are named, stored, versioned, and bound to:

- OWNER_APPROVED Register  
- P0 Architecture (unchanged)  
- P1 Design Baseline (`P1-Design-Baseline`)  
- P2 Design Baseline (`P2-Design-Baseline`)  
- P3 Engine Baseline (`P3-Engine-Baseline`)  
- P4 Pre-PONR Live Baseline (`P4-PrePONR-Baseline`)  

Per Architecture roadmap:

| Phase | Name | Output |
|-------|------|--------|
| **P5** | Production apply | **Delete/import/uploads under flags** |

This Work Package:

- Opens P5 under Owner authorization.  
- Discovers and records the official P5 Work Package inventory from Architecture §6 / §18 and P4 deferred Production Apply scope.  
- Does **not** implement production DELETE / IMPORT / special handlers / uploads apply in WP-P5-01 itself.  
- Does **not** flip enablement (P9 / OD-ENABLE).  
- Does **not** implement post-apply verify / rollback workers (P6).  
- Does **not** redesign Architecture or reopen Owner Decisions.

---

## 2. Hard rules (binding for all P5 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **Baselines:** Consume P0–P4 frozen baselines; do not silently revise them.  
3. **No redesign:** Do not change Architecture documents to fit P5 convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative P5 behavior must cite OD frozen wording and/or Architecture section and/or P1–P4 Artifact-ID.  
6. **Insufficient policy:** If frozen policy is insufficient → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/hashes/gates only; never modify CRP engines or semantics.  
8. **Enablement:** Remains **hard false** during P5 (`Architecture` roadmap: *P5 + OD-ENABLE false until drills*). P5 does **not** flip enablement (P9).  
9. **PONR boundary:** P5 may implement production apply engines **after** CP-A from the frozen P4 path. First successful target-slice DELETE or first production uploads path replacement is PONR (Architecture §10.3).  
10. **No HTTP-triggered production mutation:** Long-running non-HTTP workers remain the mutation path (Architecture §4.1); P5 does not create HTTP mutate endpoints for production apply.  
11. **Consume P3/P4 engines:** Extend / orchestrate P3 mutation skeleton + P4 Pre-PONR live path — do not fork a second CPR stack.  
12. **No invented WPs:** P5 Work Packages are only those discovered from Architecture §6 / §18 Production Apply stages (+ control plane + phase integration freeze pattern used in P2–P4).  
13. **P6 boundary:** Post-apply verification suite, success finalize, and session Full-anchor rollback workers remain **P6**.  
14. **Certification / enablement:** P5 does not grant Owner Cert PASS; does not set enablement true.  

---

## 3. Coding / execution scope (WP-P5-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P5 **control-plane artifacts** for WP-P5-01 | **Yes** — this WP |
| P5 control registry PHP (`cpr_p5_control_plane.php`) + self-test | **Yes** — inventory / hard-rule registry only |
| WP-P5-02+ production apply engines | **No** until Owner approves each next WP |
| **Production DELETE / IMPORT / special handlers / uploads apply** | **No** in WP-P5-01 |
| Post-apply verify / rollback workers | **No** — P6 |
| Enablement flag flip | **No** — P9 / OD-ENABLE |
| Architecture or Owner Decision edits | **No** |
| C3–C8 engine edits | **No** |
| P0–P4 frozen baseline edits | **No** |

**WP-P5-01 coding:** Control-plane registry only — **no** production mutation engines.

---

## 4. P5 Production Apply charter (roadmap binding)

### 4.1 Discovery sources (official — not invented)

| Source | Binding content |
|--------|-----------------|
| Architecture roadmap P5 | Production apply = Delete / import / uploads under flags |
| Architecture §6 (after CP-A) | PONR DELETE → IMPORT batches 1→6 → special handlers → scoped uploads apply |
| Architecture §10.2 / §10.3 | Target-slice DELETE/IMPORT; OD-UPLOADS; PONR definition |
| Architecture §18 | CP6 / CP7 / CP8 / CP9 |
| P4 Artifact Index §4.2 | Deferred to P5: DELETE (PONR); IMPORT / special handlers; scoped uploads |
| P3 mutation catalog | Stage ids: `ponr_target_slice_delete`, `target_slice_import`, `special_handlers`, `country_uploads_apply` |
| Prior phase pattern | Control plane WP-*-01 + final Integration Baseline freeze WP |

### 4.2 In scope for P5 (across WP-P5-02+)

| Capability | Consumes | Checkpoint / state | Must not |
|------------|----------|--------------------|----------|
| PONR target-slice DELETE | P4 CP-A path; OD-FAIL-DELETE; C1.1 resolvers | CP6 · `cpr_deleting` | Full-schema wipe; survivor delete |
| Target-slice IMPORT batches 1→6 | Package SQL; dependency batches; OD-FAIL-IMPORT | CP7 · `cpr_importing` | Raw package import without contract; statement-offset invent |
| Special handlers | Sequences / composites (Architecture §6 / §18) | CP8 | Bypass handlers; mutate outside scope |
| Country uploads apply | OD-UPLOADS; Isolation | CP9 · `cpr_uploads_applying` | Full `uploads/` tree replace; survivor file mutation |
| Flags / fail-closed under enablement FALSE | OD-ENABLE; G01; mutation skeleton | — | Flip enablement true; HTTP mutate |

### 4.3 Explicitly out of scope for all of P5

| Item | Deferred to |
|------|-------------|
| Post-apply verification suite | P6 |
| Success finalize / maint release workers | P6 (ops closeout) |
| Resume / Rollback execution workers | P6 (design P1-09; drills P7) |
| Clone drills / evidence pack assembly runs | P7 |
| Owner Cert PASS ceremony | P8 |
| Enablement true | P9 |
| C3–C8 modifications | Forbidden |
| Architecture / OWNER_APPROVED edits | Forbidden |

### 4.4 “Production apply under flags” acceptance meaning

A P5 Production Apply build is successful when:

1. After a valid P4 Pre-PONR path through CP-A, production apply engines can execute DELETE → IMPORT → special handlers → scoped uploads under fail-closed flags.  
2. Ops enablement remains **FALSE** until Owner OD-ENABLE path (P9); engines refuse unsafe enablement / bypass.  
3. No HTTP production mutate endpoints.  
4. Post-apply verify / rollback remain P6.  
5. All behaviors cite Register / Architecture / P1–P4 Artifact-IDs — no silent policy.  

---

## 5. Storage layout

### 5.1 Design / control documents

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md` | **This file** — P5 Control Plane (WP-P5-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_*.md` | Subsequent P5 design/implementation notes (one primary file per WP) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_*.md` … `P4_*.md` | Frozen — **do not modify** |
| Architecture / Register / Ops clarifications | Frozen — **do not modify** |

### 5.2 Intended code roots (for WP-P5-02+ only — registry exception in WP-P5-01)

| Path (design intent) | Role |
|----------------------|------|
| `includes/backup/country_production/` | Extend P3/P4 CPR libraries for Production Apply |
| `scripts/backup/country_production/` | Non-HTTP workers / self-tests for apply engines |
| `admin/api/country_production/` | Control-plane APIs only — **no production mutate / no DELETE/IMPORT HTTP** |
| `{workRoot}/country_production/` | Runtime job dirs, locks, checkpoints, audit (Architecture) |

**WP-P5-01 exception:** `cpr_p5_control_plane.php` + self-test only (inventory / hard rules; no mutation).

---

## 6. Naming convention

### 6.1 Work Package IDs

Format: `WP-P5-NN` where `NN` is zero-padded order (`01` … `06`).

### 6.2 Artifact file names

```text
COUNTRY_PRODUCTION_RESTORE_P5_<WPNN>_<SHORT_NAME>.md
```

### 6.3 In-document artifact IDs

```text
Artifact-ID: CPR-P5-WPNN-<SHORT>
```

### 6.4 PHP / module naming (later WPs)

Prefer `orange_cpr_*` / `country_production_*` prefixes consistent with P3/P4 helpers; never reuse Full DR job ids as CPR job ids.

---

## 7. Work Package inventory (P5) — discovered from approved roadmap

| WP | Title | Primary artifact | Status |
|----|-------|------------------|--------|
| **WP-P5-01** | P5 Control Plane & Artifact Index | `COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md` | **COMPLETE** |
| **WP-P5-02** | PONR Target-Slice DELETE Engine | `COUNTRY_PRODUCTION_RESTORE_P5_02_TARGET_SLICE_DELETE.md` | **COMPLETE** |
| **WP-P5-03** | Target-Slice IMPORT Engine (batches 1→6) | `COUNTRY_PRODUCTION_RESTORE_P5_03_TARGET_SLICE_IMPORT.md` | **COMPLETE** |
| **WP-P5-04** | Special Handlers Engine | `COUNTRY_PRODUCTION_RESTORE_P5_04_SPECIAL_HANDLERS.md` | **COMPLETE** |
| **WP-P5-05** | Country Uploads Apply (OD-UPLOADS) | `COUNTRY_PRODUCTION_RESTORE_P5_05_UPLOADS_APPLY.md` | **COMPLETE** |
| **WP-P5-06** | P5 Integration Review & Production Apply Baseline Freeze | `COUNTRY_PRODUCTION_RESTORE_P5_06_INTEGRATION_BASELINE.md` | PENDING |

**Execution rule (Owner):** One WP at a time → Verify AC → Commit → Push → **STOP** → wait for approval before next WP.

**Drift control:** Later WPs must not introduce primary filenames absent from this table without updating **this index** in the same Owner-authorized WP (still without modifying Architecture/Register).

**Discovery note:** WP-P5-02…05 map 1:1 to Architecture §6 / §18 Production Apply stages (CP6–CP9). WP-P5-06 is the phase integration freeze pattern used for P2-07 / P3-09 / P4-09. No additional WPs invented.

---

## 8. Execution order & dependencies

Architecture §6 Production Apply sequence (normative ordering intent):

```text
[P4 complete through CP-A]
  → PONR: target-slice DELETE (CP6)
  → Target-slice IMPORT batches 1→6 (CP7)
  → Special handlers (CP8)
  → Country uploads apply scoped (CP9)
  ✗ STOP before post-apply verify (P6)
```

| WP | Depends on | Unlocks |
|----|------------|---------|
| WP-P5-01 | Owner P5 authorization; `P4-PrePONR-Baseline` | All P5 WPs |
| WP-P5-02 | WP-P5-01; P4 CP-A path available | WP-P5-03 |
| WP-P5-03 | WP-P5-02 (DELETE complete / dirty rules per OD-FAIL-*) | WP-P5-04 |
| WP-P5-04 | WP-P5-03 | WP-P5-05 |
| WP-P5-05 | WP-P5-04 | WP-P5-06 |
| WP-P5-06 | WP-P5-02…WP-P5-05 COMPLETE | P5 baseline freeze; wait for Owner before P6 |

---

## 9. WP → baseline contract map

| WP | Primary OD / principles | Primary P1 | Primary P3/P4 | Architecture |
|----|-------------------------|------------|---------------|--------------|
| WP-P5-01 | OD-ENABLE, OD-PERM, Integrity, Isolation | P1 freeze | P3/P4 freeze | Roadmap P5; §4 safety |
| WP-P5-02 | OD-FAIL-DELETE; Isolation; Integrity | P1-03 · P1-04 (CP6) · P1-09 | P3-08 stubs · P4 CP-A | §6, §10.2 A, §10.3, §18 CP6 |
| WP-P5-03 | OD-FAIL-IMPORT; Isolation | P1-03 · P1-04 (CP7) · P1-09 | P3-08 stubs | §6, §10.2 A, §18 CP7 |
| WP-P5-04 | Integrity; Isolation | P1-04 (CP8) | P3-08 stubs | §6, §18 CP8 |
| WP-P5-05 | OD-UPLOADS; Isolation | P1-10 · P1-04 (CP9) | P3-08 stubs | §6, §10.2 B, §18 CP9 |
| WP-P5-06 | All cited | All P5 + baselines | Freeze | Freeze |

Foundational principles (always in force):

| Principle | Binds |
|-----------|--------|
| Integrity over privilege | No Super Admin safety bypass of apply gates |
| Recovery scope isolation | Target-slice only; survivor/global safety; OD-UPLOADS scope |
| Operational governance | Permissions / runbook / flags — never weakens Integrity/Isolation/Global Restore Policy |

---

## 10. Consistency commitments

| Baseline | Commitment |
|----------|------------|
| OWNER_APPROVED Register | No reopen; implement only frozen wording |
| P0 Architecture | Technical baseline; register wins conflicts; **do not modify** file |
| P1 Design Baseline | Contracts for state/checkpoint/fail/uploads remain SoT |
| P2 Design Baseline | Cert/enablement constraints; P5 does not run Owner Cert |
| P3 Engine Baseline | Mutation skeleton is the apply substrate |
| P4 Pre-PONR Baseline | Live path through CP-A is the required precondition substrate |

---

## 11. Citation rules

1. Prefer **OD-ID + §15 Frozen** when stating policy.  
2. Prefer **P1/P2/P3/P4 Artifact-ID** when stating contracts.  
3. Prefer **Architecture section** as implementation narrative; register wins.  
4. Do not cite draft chat as authority.

---

## 12. Change control

| Change type | Allowed in P5? |
|-------------|----------------|
| New/updated `COUNTRY_PRODUCTION_RESTORE_P5_*.md` under authorized WP | Yes |
| Code under §5.2 paths in authorized WP-P5-02+ | Yes (not mutation engines in WP-P5-01) |
| Edit frozen P0–P4 / Architecture / Register | **No** |
| Enablement true | **No** — P9 |
| Post-apply verify / rollback workers | **No** — P6 |
| C3–C8 edits | **No** |
| Start WP-P5-02 before Owner approval of WP-P5-01 | **No** |
| Invent WPs beyond §7 inventory | **No** |

---

## 13. Acceptance criteria (WP-P5-01)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | P5 control plane document exists with Artifact-ID | **PASS** |
| AC2 | Hard rules bind SoT, P0–P4 baselines, no OD reopen, no architecture redesign, enablement hard false | **PASS** §2 |
| AC3 | P5 roadmap objective recorded: Delete/import/uploads under flags | **PASS** §1, §4 |
| AC4 | Production Apply charter in/out of scope defined from Architecture (no invented scope) | **PASS** §4 |
| AC5 | Naming, storage, citation, change control defined | **PASS** §5–§6, §11–§12 |
| AC6 | Official P5 WP inventory WP-P5-01…06 listed; discovered from roadmap only | **PASS** §7 |
| AC7 | Every WP maps to OWNER_APPROVED / Architecture / prior baselines | **PASS** §9 |
| AC8 | Dependencies and execution order defined | **PASS** §8 |
| AC9 | Artifact names defined for every WP | **PASS** §7 |
| AC10 | WP-P5-01 contains no production DELETE/IMPORT/uploads/special-handler engine | **PASS** §3 |
| AC11 | Control registry + self-tests green; PHP lint clean | **PASS** |
| AC12 | Architecture and Owner Decisions not modified by this WP | **PASS** |

---

## 14. Stop rule

**WP-P5-01 COMPLETE** (control plane).  
**WP-P5-02 COMPLETE** (PONR Target-Slice DELETE) — see `COUNTRY_PRODUCTION_RESTORE_P5_02_TARGET_SLICE_DELETE.md`.  
**WP-P5-03 COMPLETE** (Target-Slice IMPORT Batches 1→6) — see `COUNTRY_PRODUCTION_RESTORE_P5_03_TARGET_SLICE_IMPORT.md`.  
**WP-P5-04 COMPLETE** (Special Handlers) — see `COUNTRY_PRODUCTION_RESTORE_P5_04_SPECIAL_HANDLERS.md`.  
**WP-P5-05 COMPLETE** (Country Uploads Apply / OD-UPLOADS) — see `COUNTRY_PRODUCTION_RESTORE_P5_05_UPLOADS_APPLY.md`.  
Commit → Push → **STOP.**  
Do **not** begin **WP-P5-06** until Owner explicitly reviews and approves the next Work Package.

---

*End of WP-P5-01 — P5 Control Plane & Artifact Index (updated inventory through WP-P5-05).*
