# Country Production Restore — P7 Artifact Index (Clone Drills / Evidence Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P7-01** — P7 Control Plane & Artifact Index |
| **Artifact-ID** | `CPR-P7-WP01-ARTIFACT_INDEX` |
| **Status** | COMPLETE (P7 control plane) |
| **Version** | 1.0 |
| **Date** | 2026-07-22 |
| **Authorization** | Owner **P7 Execution Authorization** (after P6 Post-Execution Baseline freeze + Enterprise Audit PASSED + phase closure) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **P2 design baseline** | Git tag `P2-Design-Baseline` → commit `4cadc687` |
| **P3 engine baseline** | Git tag `P3-Engine-Baseline` → commit `7a7f8c99` |
| **P4 Pre-PONR baseline** | Git tag `P4-PrePONR-Baseline` → commit `6bc09bcb` |
| **P5 PONR Execution baseline** | Git tag `P5-PONR-Execution-Baseline` → commit `b4c7a739` |
| **P6 Post-Execution baseline** | Git tag `P6-VerifyRollback-Baseline` → commit `9aa0fbbc` |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (**do not modify**) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P7 Control Plane: inventory, naming, coding scope, hard rules, WP→baseline map for Clone Drills / Evidence |
| **Control registry** | `includes/backup/country_production/cpr_p7_control_plane.php` |

---

## 1. Purpose

Establish how all **P7 Clone Drills / real-clone proof** artifacts (design notes + code delivered in later P7 WPs) are named, stored, versioned, and bound to:

- OWNER_APPROVED Register  
- P0 Architecture (unchanged)  
- P1–P6 frozen baselines (`P1-Design-Baseline` … `P6-VerifyRollback-Baseline`)  
- P2 certification design contracts (drill scenarios, evidence pack schemas, checklist)  

Per Architecture roadmap:

| Phase | Name | Output |
|-------|------|--------|
| **P7** | Clone drills / real-clone proof | **Evidence** |

This Work Package:

- Opens P7 under Owner authorization.  
- Discovers and records the official P7 Work Package inventory from Architecture roadmap P7 + P2 deferred execution contracts (P2-03 / P2-04 / EV catalog / OD-CERT evidence role).  
- Does **not** implement drill harness, scenario runner, or evidence-pack sealer in WP-P7-01 itself.  
- Does **not** flip enablement (P9 / OD-ENABLE).  
- Does **not** grant Owner Cert PASS/FAIL (P8 / OD-CERT).  
- Does **not** redesign Architecture or reopen Owner Decisions.  
- Preserves all contracts frozen in P0–P6 (Recovery, Resume, Checkpoint, Lock, Gate, Authority, Witness, DELETE, IMPORT, Special Handlers, Uploads, Verify, Finalize, Rollback, Maintenance Release).

---

## 2. Hard rules (binding for all P7 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **Baselines:** Consume P0–P6 frozen baselines; do not silently revise them.  
3. **No redesign:** Do not change Architecture documents to fit P7 convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative P7 behavior must cite OD frozen wording and/or Architecture section and/or P1–P6 / P2 Artifact-ID.  
6. **Insufficient policy:** If frozen policy is insufficient → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/hashes/gates only; never modify CRP engines or semantics.  
8. **Enablement:** Remains **hard false** during P7 (`Architecture` roadmap: drills/cert before OD-ENABLE). P7 does **not** flip enablement (P9).  
9. **Clone / non-production context only:** Drills run with `drill_context` ∈ {`clone`,`shadow_lab`,`non_production_fixture`} — not live production enablement (P2-03 H1; OD-ENABLE; OD-CERT).  
10. **OD-CERT split:** Engineering produces drill evidence only; Owner alone grants Cert PASS/FAIL in **P8**.  
11. **No auto-rollback / no integrity waiver:** OD-ROLLBACK · OD-VERIFY-WARN remain binding during drills.  
12. **Consume P3–P6 engines:** Orchestrate existing live engines under flags — do not fork a second CPR stack.  
13. **No invented WPs:** P7 Work Packages are only those discovered from Architecture roadmap P7 + P2 deferred drill/evidence execution contracts (+ control plane + phase integration freeze pattern).  
14. **P8+ boundary:** Owner Cert PASS/FAIL remains **P8**; enablement true **P9**.  
15. **Preserve P0–P6 contracts:** Recovery, Resume, Checkpoint, Lock, Gate, Authority, Witness, DELETE, IMPORT, Special Handlers, Uploads, Verify, Finalize, Rollback, and Maintenance Release contracts remain binding.  

---

## 3. Coding / execution scope (WP-P7-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P7 **control-plane artifacts** for WP-P7-01 | **Yes** — this WP |
| P7 control registry PHP (`cpr_p7_control_plane.php`) + self-test | **Yes** — inventory / hard-rule registry only |
| WP-P7-02 clone drill harness (after Owner approval of WP-P7-01) | **Yes** — delivered in WP-P7-02 |
| WP-P7-03 drill scenario execution (after Owner approval of WP-P7-02) | **Yes** — delivered in WP-P7-03 |
| WP-P7-04+ evidence sealer | **No** until Owner approves each next WP |
| **Clone drill harness** | **COMPLETE** in WP-P7-02 (`cpr_drill_harness_live.php`) |
| **Drill scenario execution** | **COMPLETE** in WP-P7-03 (`cpr_drill_execution_live.php` + `cpr_drill_catalog.php`) |
| **Evidence pack assembly / seal** | **No** until WP-P7-04 |
| Owner Cert PASS/FAIL | **No** — P8 / OD-CERT |
| Enablement flag flip | **No** — P9 / OD-ENABLE |
| Architecture or Owner Decision edits | **No** |
| C3–C8 engine edits | **No** |
| P0–P6 frozen baseline / engine edits | **No** (except confirmed defects) |

**WP-P7-01 coding:** Control-plane registry only.  
**WP-P7-02 coding:** Clone drill harness & environment binding only.  
**WP-P7-03 coding:** DS-* scenario execution only — **no** evidence pack sealer.

---

## 4. P7 Clone Drills / Evidence charter (roadmap binding)

### 4.1 Discovery sources (official — not invented)

| Source | Binding content |
|--------|-----------------|
| Architecture roadmap P7 | Clone drills / real-clone proof = **Evidence** |
| Architecture roadmap P8 / P9 | Owner Cert PASS/FAIL deferred; enablement deferred |
| P2 Artifact Index §7.4 | Live clone drills / real-clone proof execution deferred to **P7** |
| P2-03 Drill Scenario Catalog | DS-* scenarios, `drill_context`, PASS/FAIL criteria, EV refs (design SoT for execution) |
| P2-04 Evidence Pack Schemas | Pack structure, seal, EV-01…EV-14 assembly contracts |
| P2-01 / P2-02 | Evidence catalog EV-*; checklist CG-M* Evidence Ready (not Owner Cert PASS) |
| OD-CERT | Engineering evidence only; Owner final PASS/FAIL in P8 |
| OD-ENABLE | Flag remains false through drills/cert until explicit Owner enablement (P9) |
| OD-ROLLBACK / OD-FAIL-* / OD-VERIFY-WARN | Drill proofs must honor fail-closed / no auto-rollback / no waiver |
| Prior phase pattern | Control plane WP-*-01 + final Integration Baseline freeze WP |

### 4.2 In scope for P7 (across WP-P7-02+)

| Capability | Consumes | Must not |
|------------|----------|----------|
| Clone / non-production drill harness | P2-03 H1; OD-ENABLE false | Live production enablement; production mutation outside drill context |
| Drill scenario execution | P2-03 DS-*; P3–P6 live engines under flags | Invent new scenarios; Country Admin Resume/Rollback; auto-rollback |
| Evidence pack assembly & seal | P2-04; EV-01…EV-14; P1-13 hooks | Owner Cert PASS; post-seal mutation; secrets in pack |
| Evidence readiness for Owner review | P2-02 CG-M* Evidence Ready | Equate Evidence Ready with Owner Cert PASS (P8) |

### 4.3 Explicitly out of scope for all of P7

| Item | Deferred to |
|------|-------------|
| Owner Cert PASS/FAIL ceremony | **P8** / OD-CERT |
| Owner decision package finalization as Cert PASS | **P8** (P2-05 design) |
| Enablement flag true | **P9** / OD-ENABLE |
| Architecture / OD amendments | Forbidden |
| C3–C8 engine changes | Forbidden |
| Re-implement P3–P6 engines | Forbidden — consume only |

---

## 5. Storage layout

### 5.1 Design / control documents

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md` | This control plane (WP-P7-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_02_*.md` … | Later WP design notes (names in §7) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_05_INTEGRATION_BASELINE.md` | Phase freeze (WP-P7-05) |

### 5.2 Code (later WPs; not WP-P7-01 engines)

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_p7_control_plane.php` | WP-P7-01 registry |
| `includes/backup/country_production/cpr_drill_harness_live.php` | WP-P7-02 clone drill harness & environment binding |
| `includes/backup/country_production/cpr_drill_catalog.php` | WP-P7-03 frozen P2-03 DS-* catalog |
| `includes/backup/country_production/cpr_drill_execution_live.php` | WP-P7-03 DS-* scenario execution |
| `includes/backup/country_production/cpr_*_live.php` (P3–P6) | Consumed substrate — do not fork |
| Future evidence modules | WP-P7-04+ only |

### 5.3 Runtime (later WPs)

| Path | Role |
|------|------|
| `{job}/drill_harness/` | Sealed environment binding + harness reports (WP-P7-02) |
| `{job}/drill_execution/` | Sealed per-scenario + aggregate drill reports (WP-P7-03) |
| evidence pack dirs | Evidence packs (WP-P7-04+) |

---

## 6. Naming

| Kind | Pattern |
|------|---------|
| Design docs | `COUNTRY_PRODUCTION_RESTORE_P7_##_TITLE.md` |
| Artifact-ID | `CPR-P7-WP##-SHORT_NAME` |
| PHP | `orange_cpr_*` / `cpr_*` under `includes/backup/country_production/` |
| Scaffold version | `P7-##-…` via `ORANGE_CPR_SCAFFOLD_VERSION` |

Prefer `orange_cpr_*` prefixes consistent with P3–P6 helpers; never reuse Full DR job ids as CPR job ids.

---

## 7. Work Package inventory (P7) — discovered from approved roadmap

| WP | Title | Primary artifact | Status |
|----|-------|------------------|--------|
| **WP-P7-01** | P7 Control Plane & Artifact Index | `COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md` | **COMPLETE** |
| **WP-P7-02** | Clone Drill Harness & Environment Binding | `COUNTRY_PRODUCTION_RESTORE_P7_02_DRILL_HARNESS.md` | **COMPLETE** |
| **WP-P7-03** | Drill Scenario Execution (P2-03 DS-*) | `COUNTRY_PRODUCTION_RESTORE_P7_03_DRILL_EXECUTION.md` | **COMPLETE** |
| **WP-P7-04** | Evidence Pack Assembly & Seal (P2-04 / EV-01…EV-14) | `COUNTRY_PRODUCTION_RESTORE_P7_04_EVIDENCE_PACK.md` | PENDING |
| **WP-P7-05** | P7 Integration Review & Clone-Drill Evidence Baseline Freeze | `COUNTRY_PRODUCTION_RESTORE_P7_05_INTEGRATION_BASELINE.md` | PENDING |

**Execution rule (Owner):** One WP at a time → Verify AC → Commit → Push → **STOP** → wait for approval before next WP.

**Drift control:** Later WPs must not introduce primary filenames absent from this table without updating **this index** in the same Owner-authorized WP (still without modifying Architecture/Register).

**Discovery note:** Architecture roadmap P7 states only *Clone drills / real-clone proof → Evidence*. Official decomposition maps that single output onto: (1) control plane, (2) clone/non-production harness per P2-03 H1, (3) execution of the frozen P2-03 DS-* catalog against P3–P6 engines, (4) evidence pack seal per P2-04 / EV-01…EV-14, (5) phase integration freeze pattern used for P2-07 / P3-09 / P4-09 / P5-06 / P6-06. Owner Cert PASS/FAIL remains P8. **No additional WPs invented.**

---

## 8. Execution order & dependencies

Architecture roadmap (normative ordering intent):

```text
[P6 complete through CP12 / Post-Execution Baseline]
  → Clone / non-production drill harness (enablement FALSE)
  → Execute drill scenarios (P2-03 DS-*) using P3–P6 engines under flags
  → Assemble + seal Evidence Pack (P2-04 / EV-01…EV-14)
  → P7 evidence baseline freeze
  ✗ STOP before Owner Cert ceremony (P8)
```

| WP | Depends on | Unlocks |
|----|------------|---------|
| WP-P7-01 | Owner P7 authorization; `P6-VerifyRollback-Baseline` | All P7 WPs |
| WP-P7-02 | WP-P7-01 | WP-P7-03 |
| WP-P7-03 | WP-P7-02; P2-03 catalog; P3–P6 engines | WP-P7-04 |
| WP-P7-04 | WP-P7-03; P2-04 schemas | WP-P7-05 |
| WP-P7-05 | WP-P7-02…WP-P7-04 COMPLETE | P7 baseline freeze; wait for Owner before P8 |

---

## 9. WP → baseline contract map

| WP | Primary OD / principles | Primary P1/P2 | Primary P3–P6 | Architecture |
|----|-------------------------|---------------|---------------|--------------|
| WP-P7-01 | OD-CERT, OD-ENABLE, Integrity | P2 Index · P1-13 | P6 freeze | Roadmap P7 |
| WP-P7-02 | OD-ENABLE; OD-CERT | P2-03 H1 | — | Roadmap P7 clone |
| WP-P7-03 | OD-ROLLBACK, OD-FAIL-*, OD-VERIFY-WARN, OD-PERM | P2-03 DS-* · P1-09 | P3–P6 live | Roadmap P7 drills |
| WP-P7-04 | OD-CERT, OD-SCHEMA | P2-04 · EV-01…EV-14 · P1-13 | — | Roadmap P7 Evidence |
| WP-P7-05 | All cited | All P7 + baselines | Freeze | Freeze |

Foundational principles (always in force):

| Principle | Binds |
|-----------|--------|
| Integrity over privilege | No Super Admin waiver of verify failures / drill failures |
| Recovery scope isolation | Survivor/global safety retained |
| Operational governance | Permissions / runbook / flags — never weakens Integrity/Isolation/Global Restore Policy |
| OD-CERT authority split | Engineering evidence ≠ Owner Cert PASS |

---

## 10. Consistency commitments

| Baseline | Commitment |
|----------|------------|
| OWNER_APPROVED Register | No reopen; implement only frozen wording |
| P0 Architecture | Technical baseline; register wins conflicts; **do not modify** file |
| P1 Design Baseline | State/checkpoint/fail/verify/rollback/cert hooks remain SoT |
| P2 Design Baseline | Drill scenarios + evidence pack schemas + checklist remain SoT for P7 execution |
| P3 Engine Baseline | State/checkpoint/lock substrate |
| P4 Pre-PONR Baseline | OD-PIN / maint / authority path consumed |
| P5 PONR Execution Baseline | CP9 apply path consumed |
| P6 Post-Execution Baseline | CP10–CP12 verify/rollback/closeout consumed |

---

## 11. Citation rules

1. Prefer **OD-ID + §15 Frozen** when stating policy.  
2. Prefer **P1/P2/P6 Artifact-ID** when stating contracts.  
3. Prefer **Architecture section** as implementation narrative; register wins.  
4. Do not cite draft chat as authority.

---

## 12. Change control

| Change type | Allowed in P7? |
|-------------|----------------|
| New/updated `COUNTRY_PRODUCTION_RESTORE_P7_*.md` under authorized WP | Yes |
| Code under authorized WP-P7-02+ | Yes (not drill/evidence engines in WP-P7-01) |
| Edit frozen P0–P6 / Architecture / Register | **No** |
| Enablement true | **No** — P9 |
| Owner Cert PASS/FAIL | **No** — P8 |
| C3–C8 edits | **No** |
| Start WP-P7-02 before Owner approval of WP-P7-01 | **No** |
| Start WP-P7-03 before Owner approval of WP-P7-02 | **No** |
| Start WP-P7-04 before Owner approval of WP-P7-03 | **No** |
| Invent WPs beyond §7 inventory | **No** |

---

## 13. Acceptance criteria (WP-P7-01)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | P7 control plane document exists with Artifact-ID | **PASS** |
| AC2 | Hard rules bind SoT, P0–P6 baselines, no OD reopen, no architecture redesign, enablement hard false | **PASS** §2 |
| AC3 | P7 roadmap objective recorded: Clone drills / real-clone proof → Evidence | **PASS** §1, §4 |
| AC4 | Drill/Evidence charter in/out of scope defined from Architecture + P2 (no invented scope) | **PASS** §4 |
| AC5 | Naming, storage, citation, change control defined | **PASS** §5–§6, §11–§12 |
| AC6 | Official P7 WP inventory WP-P7-01…05 listed; discovered from roadmap only | **PASS** §7 |
| AC7 | Every WP maps to OWNER_APPROVED / Architecture / prior baselines | **PASS** §9 |
| AC8 | Dependencies and execution order defined | **PASS** §8 |
| AC9 | Artifact names defined for every WP | **PASS** §7 |
| AC10 | WP-P7-01 contains no drill harness / scenario runner / evidence sealer | **PASS** §3 |
| AC11 | Control registry + self-tests green; PHP lint clean | **PASS** |
| AC12 | Architecture and Owner Decisions not modified by this WP | **PASS** |
| AC13 | P0–P6 contracts preserved (no P6 engine redesign) | **PASS** |

---

## 14. Stop rule

**WP-P7-03 COMPLETE** (DS-* drill scenario execution).  
Commit → Push → **STOP.**  
Do **not** begin **WP-P7-04** until Owner explicitly reviews and approves the next Work Package.

---

## 15. Acceptance criteria (WP-P7-02)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Clone Drill Harness implemented | **PASS** — `cpr_drill_harness_live.php` |
| AC2 | Isolated Drill Environment binding | **PASS** |
| AC3 | Operates exclusively against approved clone environment | **PASS** |
| AC4 | Never interacts with production resources | **PASS** |
| AC5 | Binds job identity, execution contract, country, schema revision, clone env | **PASS** |
| AC6 | Isolation + fail-closed mismatches | **PASS** |
| AC7 | Production endpoint detection; deterministic validation | **PASS** |
| AC8 | No replay; no privilege bypass | **PASS** |
| AC9 | Sealed binding + harness reports | **PASS** |
| AC10 | Audit events + recovery metadata | **PASS** |
| AC11 | Enablement FALSE; no production SQL/uploads; Architecture/OD unchanged | **PASS** |
| AC12 | No DS-* execution; WP-P7-03 not started | **PASS** (historical AC for WP-P7-02 freeze) |
| AC13 | Self-tests + lint + full CPR suite green | **PASS** |

---

## 16. Acceptance criteria (WP-P7-03)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | DS-* catalog execution engine implemented | **PASS** — `cpr_drill_execution_live.php` |
| AC2 | Only frozen P2-03 scenarios; no invent/reorder/merge/skip | **PASS** — `cpr_drill_catalog.php` |
| AC3 | Deterministic catalog execution order | **PASS** |
| AC4 | Clone drill environment only | **PASS** |
| AC5 | Integrates harness / state / checkpoint / recovery / audit / contract / job / country / schema | **PASS** |
| AC6 | No production SQL/uploads/services | **PASS** |
| AC7 | No replay; no privilege bypass; no cross-country; fail-closed | **PASS** |
| AC8 | Sealed per-scenario + aggregate reports | **PASS** |
| AC9 | Audit + recovery metadata + scenario fingerprints | **PASS** |
| AC10 | Enablement FALSE; Architecture/OD unchanged; no evidence pack | **PASS** |
| AC11 | Self-tests + lint + full CPR suite green | **PASS** |

---

*End of P7 Artifact Index (updated WP-P7-03).*
