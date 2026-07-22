# Country Production Restore — P8 Artifact Index (Certification Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P8-01** — P8 Control Plane & Artifact Index |
| **Artifact-ID** | `CPR-P8-WP01-ARTIFACT_INDEX` |
| **Status** | COMPLETE (P8 control plane) |
| **Version** | 1.0 |
| **Date** | 2026-07-22 |
| **Authorization** | Owner **P8 Execution Authorization** (after P7 Clone-Drill Evidence Baseline freeze + Enterprise Audit PASSED + phase closure) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **P2 design baseline** | Git tag `P2-Design-Baseline` → commit `4cadc687` |
| **P3 engine baseline** | Git tag `P3-Engine-Baseline` → commit `7a7f8c99` |
| **P4 Pre-PONR baseline** | Git tag `P4-PrePONR-Baseline` → commit `6bc09bcb` |
| **P5 PONR Execution baseline** | Git tag `P5-PONR-Execution-Baseline` → commit `b4c7a739` |
| **P6 Post-Execution baseline** | Git tag `P6-VerifyRollback-Baseline` → commit `9aa0fbbc` |
| **P7 Clone-Drill Evidence baseline** | Git tag `P7-CloneDrill-Evidence-Baseline` → commit `6ea00101` |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (**do not modify**) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P8 Control Plane: inventory, naming, coding scope, hard rules, WP→baseline map for Owner Certification (Cert PASS/FAIL) |
| **Control registry** | `includes/backup/country_production/cpr_p8_control_plane.php` |

---

## 1. Purpose

Establish how all **P8 Country Production certification** artifacts (design notes + code delivered in later P8 WPs) are named, stored, versioned, and bound to:

- OWNER_APPROVED Register (especially **OD-CERT**, **OD-ENABLE**, **OD-SCHEMA**)  
- P0 Architecture (unchanged)  
- P1–P7 frozen baselines (`P1-Design-Baseline` … `P7-CloneDrill-Evidence-Baseline`)  
- P2 certification design contracts (checklist, evidence pack schemas, Owner decision package)  
- P7 sealed Clone-Drill Evidence Baseline (EV-01…EV-14 pack)  

Per Architecture roadmap:

| Phase | Name | Output |
|-------|------|--------|
| **P8** | Country Production certification | **Cert PASS/FAIL (Owner)** |

This Work Package:

- Opens P8 under Owner authorization.  
- Discovers and records the official P8 Work Package inventory from Architecture roadmap P8 + P2 deferred Owner-cert execution contracts (P2-05 / EV-14 / P1-13 certification lifecycle / OD-CERT).  
- Does **not** assemble Owner Submission packages, record Owner PASS/FAIL, or freeze the P8 certification baseline in WP-P8-01 itself.  
- Does **not** flip enablement (P9 / OD-ENABLE).  
- Does **not** allow Engineering to grant Cert PASS.  
- Does **not** redesign Architecture or reopen Owner Decisions.  
- Preserves all contracts frozen in P0–P7 (Recovery, Resume, Checkpoint, Lock, Gate, Authority, Witness, DELETE, IMPORT, Special Handlers, Uploads, Verify, Finalize, Rollback, Maintenance Release, Clone Drill, Drill Execution, Evidence Pack).

---

## 2. Hard rules (binding for all P8 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **Baselines:** Consume P0–P7 frozen baselines; do not silently revise them.  
3. **No redesign:** Do not change Architecture documents to fit P8 convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative P8 behavior must cite OD frozen wording and/or Architecture section and/or P1–P7 / P2 Artifact-ID.  
6. **Insufficient policy:** If frozen policy is insufficient → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/hashes/gates only; never modify CRP engines or semantics.  
8. **Enablement:** Remains **hard false** during P8 (`Architecture` roadmap: cert before OD-ENABLE). P8 does **not** flip enablement (P9).  
9. **OD-CERT split:** Engineering produces / submits evidence and may **recommend**; Owner alone grants Cert **PASS/FAIL**. Engineering shall **never** grant final certification approval.  
10. **Cert PASS ≠ enablement:** Even after Owner Cert PASS, the ops flag remains false until the full OD-ENABLE path (P9).  
11. **Consume P7 evidence:** Owner Submission / decision consume sealed P7 evidence pack — do not re-run drills as a second inventable stack.  
12. **Consume P3–P7 engines:** Orchestrate existing sealed artifacts / engines under flags — do not fork a second CPR stack.  
13. **No invented WPs:** P8 Work Packages are only those discovered from Architecture roadmap P8 + P2 deferred Owner-cert execution contracts (+ control plane + phase integration freeze pattern).  
14. **P9 boundary:** Enablement true remains **P9**.  
15. **Preserve P0–P7 contracts:** Recovery, Resume, Checkpoint, Lock, Gate, Authority, Witness, DELETE, IMPORT, Special Handlers, Uploads, Verify, Finalize, Rollback, Maintenance Release, Clone Drill, Drill Execution, and Evidence Pack contracts remain binding.  

---

## 3. Coding / execution scope (WP-P8-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P8 **control-plane artifacts** for WP-P8-01 | **Yes** — this WP |
| P8 control registry PHP (`cpr_p8_control_plane.php`) + self-test | **Yes** — inventory / hard-rule registry only |
| WP-P8-02 Owner Submission assembly (after Owner approval of WP-P8-01) | **Yes** — delivered in WP-P8-02 |
| WP-P8-03 Owner Cert PASS/FAIL decision recording | **COMPLETE** in WP-P8-03 (`cpr_owner_cert_decision_live.php`) |
| WP-P8-04 integration baseline freeze | **No** until prior WP approved |
| Enterprise Audit / Git Tag / P9 | **No** until Owner explicitly authorizes |
| **Owner Submission assembly** | **COMPLETE** in WP-P8-02 (`cpr_owner_submission_live.php`) |
| Owner Cert PASS granted by engineering | **No** — forbidden (OD-CERT) |
| Enablement flag flip | **No** — P9 / OD-ENABLE |
| Architecture or Owner Decision edits | **No** |
| C3–C8 engine edits | **No** |
| P0–P7 frozen baseline / engine edits | **No** (except confirmed defects) |

**WP-P8-01 coding:** Control-plane registry only.  
**WP-P8-02 coding:** Owner Submission assembly & seal only — **no** Owner Cert PASS/FAIL writer, **no** P9 enablement.  
**WP-P8-03 coding:** Owner Cert PASS/FAIL decision & sealed `cpr_certification_result` only — **no** P8 freeze, **no** P9 enablement; PASS does not enable; FAIL does not auto-rollback.

---

## 4. P8 Certification charter (roadmap binding)

### 4.1 Discovery sources (official — not invented)

| Source | Binding content |
|--------|-----------------|
| Architecture roadmap P8 | Country Production certification = **Cert PASS/FAIL (Owner)** |
| Architecture roadmap P9 | Enablement deferred |
| P2 Artifact Index | Live Owner Cert PASS/FAIL ceremony deferred to **P8** |
| P2-05 Owner Decision Package | Submission package + decision package schemas (design SoT) |
| P2-01 / P2-02 / P2-04 | EV-14 Owner decision package; CG-H* Owner-only; sealed evidence pack contracts |
| P1-13 | `cpr_certification_result` schema; cert lifecycle states; engineering cannot grant PASS |
| OD-CERT | Owner final PASS/FAIL; engineering evidence only |
| OD-ENABLE | Flag remains false through cert until explicit Owner enablement (P9) |
| OD-SCHEMA | Schema revision change invalidates prior cert; no auto re-enable |
| P7 baseline | Sealed Clone-Drill Evidence Pack is input to Owner review |
| Prior phase pattern | Control plane WP-*-01 + final Integration Baseline freeze WP |

### 4.2 In scope for P8 (across WP-P8-02+)

| Capability | Consumes | Must not |
|------------|----------|----------|
| Owner Submission package assembly | P2-05; sealed P7 EV pack; P1-13 lifecycle | Grant Cert PASS; flip enablement |
| Owner Cert PASS/FAIL recording | OD-CERT; `cpr_certification_result`; CG-H* | Engineering `decided_by`; auto-enable |
| Certification baseline freeze | All P8 stages | Start P9; reopen OD/Architecture |

### 4.3 Explicitly out of scope for all of P8

| Item | Deferred to |
|------|-------------|
| Enablement flag true / OD-ENABLE order execution | **P9** |
| Architecture / OD amendments | Forbidden |
| C3–C8 engine changes | Forbidden |
| Re-implement P3–P7 engines / re-invent DS-* / EV-* catalogs | Forbidden — consume only |
| Engineering-granted Cert PASS | Forbidden forever (OD-CERT) |

---

## 5. Storage layout

### 5.1 Design / control documents

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md` | This control plane (WP-P8-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_02_*.md` … | Later WP design notes (names in §7) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_04_INTEGRATION_BASELINE.md` | Phase freeze (WP-P8-04) |

### 5.2 Code (later WPs; not WP-P8-01 engines)

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_p8_control_plane.php` | WP-P8-01 registry |
| `includes/backup/country_production/cpr_owner_submission_live.php` | WP-P8-02 Owner Submission assembly & seal |
| `includes/backup/country_production/cpr_owner_cert_decision_live.php` | WP-P8-03 Owner Cert PASS/FAIL + `cpr_certification_result` |
| `includes/backup/country_production/cpr_*` (P3–P7) | Consumed substrate — do not fork |
| Later P8 live modules | Named when WP-P8-04 is authorized |

### 5.3 Runtime (later WPs)

| Path | Role |
|------|------|
| `{job}/owner_submission/` | Sealed Owner Submission package (WP-P8-02) |
| `{job}/certification/` | Sealed `cpr_certification_result` + decision records (WP-P8-03) |
| `{job}/integration_live/` | Sealed P8 integration freeze report (WP-P8-04) |

---

## 6. Naming

| Kind | Pattern |
|------|---------|
| Design docs | `COUNTRY_PRODUCTION_RESTORE_P8_##_TITLE.md` |
| Artifact-ID | `CPR-P8-WP##-SHORT_NAME` |
| PHP | `orange_cpr_*` / `cpr_*` under `includes/backup/country_production/` |
| Scaffold version | `P8-##-…` via `ORANGE_CPR_SCAFFOLD_VERSION` |

Prefer `orange_cpr_*` prefixes consistent with P3–P7 helpers; never reuse Full DR job ids as CPR job ids.

---

## 7. Work Package inventory (P8) — discovered from approved roadmap

| WP | Title | Primary artifact | Status |
|----|-------|------------------|--------|
| **WP-P8-01** | P8 Control Plane & Artifact Index | `COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md` | **COMPLETE** |
| **WP-P8-02** | Owner Submission Package Assembly (P2-05 / sealed P7 evidence) | `COUNTRY_PRODUCTION_RESTORE_P8_02_OWNER_SUBMISSION.md` | **COMPLETE** |
| **WP-P8-03** | Owner Certification Decision (PASS/FAIL) & `cpr_certification_result` | `COUNTRY_PRODUCTION_RESTORE_P8_03_OWNER_CERT_DECISION.md` | **COMPLETE** |
| **WP-P8-04** | P8 Integration Review & Certification Baseline Freeze | `COUNTRY_PRODUCTION_RESTORE_P8_04_INTEGRATION_BASELINE.md` | **NOT STARTED** |

**Execution rule (Owner):** One WP at a time → Verify AC → Commit → Push → **STOP** → wait for approval before next WP.

**Drift control:** Later WPs must not introduce primary filenames absent from this table without updating **this index** in the same Owner-authorized WP (still without modifying Architecture/Register).

**Discovery note:** Architecture roadmap P8 states only *Country Production certification → Cert PASS/FAIL (Owner)*. Official decomposition maps that single output onto: (1) control plane, (2) Owner Submission package assembly from sealed P7 evidence per P2-05, (3) Owner-only PASS/FAIL recording into `cpr_certification_result` per OD-CERT / P1-13 (including Owner CG-H* acceptance), (4) phase integration freeze pattern used for P2-07 / P3-09 / P4-09 / P5-06 / P6-06 / P7-05. Enablement remains P9. **No additional WPs invented.**

---

## 8. Execution order & dependencies

Architecture roadmap (normative ordering intent):

```text
[P7 Clone-Drill Evidence Baseline]
  → Owner Submission package from sealed Evidence Pack (enablement FALSE)
  → Owner Cert PASS/FAIL + sealed cpr_certification_result
  → P8 certification baseline freeze
  ✗ STOP before enablement (P9)
```

| WP | Depends on | Unlocks |
|----|------------|---------|
| WP-P8-01 | Owner P8 authorization; `P7-CloneDrill-Evidence-Baseline` | All P8 WPs |
| WP-P8-02 | WP-P8-01; sealed P7 evidence pack; P2-05 | WP-P8-03 |
| WP-P8-03 | WP-P8-02; OD-CERT; P1-13 | WP-P8-04 |
| WP-P8-04 | WP-P8-02…WP-P8-03 COMPLETE | P8 baseline freeze; wait for Owner before P9 |

---

## 9. WP → baseline contract map

| WP | Primary OD / principles | Primary P1/P2 | Primary P7 | Architecture |
|----|-------------------------|---------------|------------|--------------|
| WP-P8-01 | OD-CERT, OD-ENABLE, Integrity | P2 Index · P1-13 | P7 freeze | Roadmap P8 |
| WP-P8-02 | OD-CERT | P2-05 · P2-04 · EV-14 | Sealed EV pack | Roadmap P8 submit |
| WP-P8-03 | OD-CERT, OD-SCHEMA | P1-13 · P2-02 CG-H* · P2-05 | Evidence refs | Roadmap P8 decision |
| WP-P8-04 | All cited | All P8 + baselines | Freeze | Freeze |

Foundational principles (always in force):

| Principle | Binds |
|-----------|--------|
| Integrity over privilege | No Super Admin / Engineering waiver into Cert PASS |
| Recovery scope isolation | Survivor/global safety retained |
| Operational governance | Permissions / runbook / flags — never weakens Integrity/Isolation/Global Restore Policy |
| OD-CERT authority split | Engineering evidence ≠ Owner Cert PASS |
| Cert PASS ≠ enablement | OD-ENABLE / P9 |

---

## 10. Consistency commitments

| Baseline | Commitment |
|----------|------------|
| OWNER_APPROVED Register | No reopen; implement only frozen wording |
| P0 Architecture | Technical baseline; register wins conflicts; **do not modify** file |
| P1 Design Baseline | Cert/enablement hooks remain SoT |
| P2 Design Baseline | Owner decision package + checklist + evidence schemas remain SoT for P8 execution |
| P3–P6 Engine / Live Baselines | State/checkpoint/lock/apply/verify/rollback substrate |
| P7 Clone-Drill Evidence Baseline | Sealed evidence pack consumed for Owner review |

---

## 11. Citation rules

1. Prefer **OD-ID + §15 Frozen** when stating policy.  
2. Prefer **P1/P2/P7 Artifact-ID** when stating contracts.  
3. Prefer **Architecture section** as implementation narrative; register wins.  
4. Do not cite draft chat as authority.

---

## 12. Change control

| Change type | Allowed in P8? |
|-------------|----------------|
| New/updated `COUNTRY_PRODUCTION_RESTORE_P8_*.md` under authorized WP | Yes |
| Code under authorized WP-P8-02+ | Yes (not cert engines in WP-P8-01) |
| Edit frozen P0–P7 / Architecture / Register | **No** |
| Enablement true | **No** — P9 |
| Engineering grants Cert PASS | **No** |
| C3–C8 edits | **No** |
| Start WP-P8-02 before Owner approval of WP-P8-01 | **No** |
| Invent WPs beyond §7 inventory | **No** |

---

## 13. Acceptance criteria (WP-P8-01)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | P8 control plane document exists with Artifact-ID | **PASS** |
| AC2 | Hard rules bind SoT, P0–P7 baselines, no OD reopen, no architecture redesign, enablement hard false, OD-CERT split | **PASS** §2 |
| AC3 | P8 roadmap objective recorded: Country Production certification → Cert PASS/FAIL (Owner) | **PASS** §1, §4 |
| AC4 | Certification charter in/out of scope defined from Architecture + P2 (no invented scope) | **PASS** §4 |
| AC5 | Naming, storage, citation, change control defined | **PASS** §5–§6, §11–§12 |
| AC6 | Official P8 WP inventory WP-P8-01…04 listed; discovered from roadmap only | **PASS** §7 |
| AC7 | Every WP maps to OWNER_APPROVED / Architecture / prior baselines | **PASS** §9 |
| AC8 | Dependencies and execution order defined | **PASS** §8 |
| AC9 | Artifact names defined for every WP | **PASS** §7 |
| AC10 | WP-P8-01 contains no Owner Submission sealer / Cert PASS writer / P9 enablement | **PASS** §3 |
| AC11 | Control registry + self-tests green; PHP lint clean | **PASS** |
| AC12 | Architecture and Owner Decisions not modified by this WP | **PASS** |
| AC13 | P0–P7 contracts preserved (no P7 engine redesign) | **PASS** |

---

## 14. Stop rule

**WP-P8-01 COMPLETE** (P8 certification control plane).  
**WP-P8-02 COMPLETE** (Owner Submission Package Assembly).  
**WP-P8-03 COMPLETE** (Owner Certification Decision PASS/FAIL).  
Commit → Push → **STOP.**  

Do **not** begin **WP-P8-04**.  
Do **not** begin **P9**.  
Do **not** flip enablement.  

Wait for Owner review and approval of WP-P8-03.

---

## 15. Acceptance criteria (WP-P8-02)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Owner Submission assembly engine implemented | **PASS** — `cpr_owner_submission_live.php` |
| AC2 | Assembles only from sealed P7 evidence / drills / freeze | **PASS** |
| AC3 | Consumes P2-05 metadata; no evidence mutation | **PASS** |
| AC4 | Integrates state / checkpoint / recovery / audit / contract / job / country | **PASS** |
| AC5 | Rejects missing/stale/corrupt/modified/replayed; fail-closed | **PASS** |
| AC6 | Deterministic section order; sealed package + sealed manifest | **PASS** |
| AC7 | Certification fingerprints + audit + recovery metadata | **PASS** |
| AC8 | No privilege bypass; no cross-country | **PASS** |
| AC9 | Enablement FALSE; Architecture/OD unchanged; no Owner PASS/FAIL | **PASS** |
| AC10 | Self-tests + lint + full CPR suite green | **PASS** |

---

## 16. Acceptance criteria (WP-P8-03)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Owner Certification decision engine implemented | **PASS** — `cpr_owner_cert_decision_live.php` |
| AC2 | Executes only against sealed Owner Submission | **PASS** |
| AC3 | Result strictly PASS or FAIL; ceremony required; mutually exclusive | **PASS** |
| AC4 | Integrates state / checkpoint / recovery / audit / contract / job / country | **PASS** |
| AC5 | Rejects missing/corrupt/replay/duplicate/contract/country; fail-closed | **PASS** |
| AC6 | Sealed decision + sealed manifest + sealed `cpr_certification_result` | **PASS** |
| AC7 | Certification fingerprints + audit + recovery metadata | **PASS** |
| AC8 | No privilege bypass; no cross-country; engineering cannot decide | **PASS** |
| AC9 | Enablement FALSE; PASS does not enable; FAIL does not auto-rollback | **PASS** |
| AC10 | Architecture / OWNER_APPROVED unchanged; no production SQL/uploads | **PASS** |
| AC11 | Self-tests + lint + full CPR suite green | **PASS** |

---

*End of P8 Artifact Index (updated WP-P8-03).*
