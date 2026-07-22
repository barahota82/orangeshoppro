# Country Production Restore — P9 Artifact Index (Enablement Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P9-01** — P9 Control Plane & Artifact Index |
| **Artifact-ID** | `CPR-P9-WP01-ARTIFACT_INDEX` |
| **Status** | COMPLETE (P9 control plane) |
| **Version** | 1.0 |
| **Date** | 2026-07-22 |
| **Authorization** | Owner **P9 Execution Authorization** (after P8 Owner Certification Baseline freeze + Enterprise Audit PASSED + phase closure; status READY FOR P9) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **P2 design baseline** | Git tag `P2-Design-Baseline` → commit `4cadc687` |
| **P3 engine baseline** | Git tag `P3-Engine-Baseline` → commit `7a7f8c99` |
| **P4 Pre-PONR baseline** | Git tag `P4-PrePONR-Baseline` → commit `6bc09bcb` |
| **P5 PONR Execution baseline** | Git tag `P5-PONR-Execution-Baseline` → commit `b4c7a739` |
| **P6 Post-Execution baseline** | Git tag `P6-VerifyRollback-Baseline` → commit `9aa0fbbc` |
| **P7 Clone-Drill Evidence baseline** | Git tag `P7-CloneDrill-Evidence-Baseline` → commit `6ea00101` |
| **P8 Owner Certification baseline** | Git tag `P8-OwnerCert-Baseline` → commit `2f1778f9` |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (**do not modify**) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P9 Control Plane: inventory, naming, coding scope, hard rules, WP→baseline map for Enablement (OD-ENABLE path) |
| **Control registry** | `includes/backup/country_production/cpr_p9_control_plane.php` |

---

## 1. Purpose

Establish how all **P9 Enablement** artifacts (design notes + code delivered in later P9 WPs) are named, stored, versioned, and bound to:

- OWNER_APPROVED Register (especially **OD-ENABLE**, **OD-CERT**, **OD-PERM**, **OD-SCHEMA**)  
- P0 Architecture (unchanged)  
- P1–P8 frozen baselines (`P1-Design-Baseline` … `P8-OwnerCert-Baseline`)  
- P1-13 enablement / certification interface contracts  
- P2-06 schema re-certification cycle design (consume; do not redesign)  
- P8 sealed Owner Certification Baseline (`cpr_certification_result` PASS path as input)  

Per Architecture roadmap:

| Phase | Name | Output |
|-------|------|--------|
| **P9** | Enablement | **Flag true under OD-ENABLE path** |

This Work Package:

- Opens P9 under Owner authorization.  
- Discovers and records the official P9 Work Package inventory from Architecture roadmap P9 + OD-ENABLE / OD-PERM / OD-SCHEMA + P1-13 enablement contracts (+ control plane + phase integration freeze pattern).  
- Does **not** issue Owner enablement orders, record Final Enterprise approval, flip the ops flag, or freeze the P9 enablement baseline in WP-P9-01 itself.  
- Does **not** allow Engineering or Country Admin to enable CPR.  
- Does **not** redesign Architecture or reopen Owner Decisions.  
- Preserves all contracts frozen in P0–P8 (Recovery, Resume, Checkpoint, Lock, Gate, Authority, Witness, DELETE, IMPORT, Special Handlers, Uploads, Verify, Finalize, Rollback, Maintenance Release, Clone Drill, Drill Execution, Evidence Pack, Owner Certification).

**P9 is the final CPR implementation phase.** After WP-P9-* complete, the project must still pass Enterprise Audit → Git Tag → Phase Sign-Off → Project Status / Release History updates before CPR is considered officially complete. WP-P9-01 does **not** start that closure sequence.

---

## 2. Hard rules (binding for all P9 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **Baselines:** Consume P0–P8 frozen baselines; do not silently revise them.  
3. **No redesign:** Do not change Architecture documents to fit P9 convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative P9 behavior must cite OD frozen wording and/or Architecture section and/or P1–P8 / P1-13 Artifact-ID.  
6. **Insufficient policy:** If frozen policy is insufficient → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/hashes/gates only; never modify CRP engines or semantics.  
8. **OD-ENABLE four preconditions:** Flag may become true **only after all** of: (1) Certification PASS; (2) Explicit Owner enablement order; (3) Implementation completed; (4) Final Enterprise approval. Until then the flag stays **false**.  
9. **Cert PASS ≠ enablement:** P8 Owner Cert PASS alone never flips the ops flag.  
10. **No auto-enable:** Reaching `E5_preconditions_satisfied` does **not** set the flag true; Super Admin Enable action is mandatory (P1-13 §6–§7).  
11. **OD-PERM:** Only Super Admin may operationally Enable/Disable; Country Admin never; Engineering never.  
12. **OD-SCHEMA:** Schema revision change invalidates prior cert; forces flag false; **auto re-enable permanently forbidden**.  
13. **Consume P8 certification:** Enablement path consumes sealed P8 `cpr_certification_result` — do not re-invent Owner Cert.  
14. **Consume P3–P8 engines:** Orchestrate existing sealed artifacts / engines — do not fork a second CPR stack.  
15. **No invented WPs:** P9 Work Packages are only those discovered from Architecture roadmap P9 + P1-13 / OD-ENABLE / OD-PERM / OD-SCHEMA (+ control plane + phase integration freeze pattern).  
16. **Preserve P0–P8 contracts:** Recovery, Resume, Checkpoint, Lock, Gate, Authority, Witness, DELETE, IMPORT, Special Handlers, Uploads, Verify, Finalize, Rollback, Maintenance Release, Clone Drill, Drill Execution, Evidence Pack, and Owner Certification contracts remain binding.  
17. **Project closure boundary:** Completing P9 WPs does **not** by itself close the CPR project — Enterprise Audit / Tag / Sign-Off remain Owner-gated after P9 implementation.

---

## 3. Coding / execution scope (WP-P9-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P9 **control-plane artifacts** for WP-P9-01 | **Yes** — this WP |
| P9 control registry PHP (`cpr_p9_control_plane.php`) + self-test | **Yes** — inventory / hard-rule registry only |
| WP-P9-02 Enablement Preconditions & Owner Enablement Order | **COMPLETE** — `cpr_enablement_preconditions_live.php` |
| WP-P9-03 Super Admin Enable/Disable + schema force-disable hooks | **COMPLETE** — `cpr_enablement_action_live.php` |
| WP-P9-04 integration baseline freeze | **COMPLETE** — `cpr_p9_integration.php` |
| Enterprise Audit / Git Tag / Phase Sign-Off / project closure | **No** until Owner explicitly authorizes after P9 implementation |
| Owner enablement order sealing | **COMPLETE** in WP-P9-02 |
| Ops enablement flag write / flip true | **COMPLETE** in WP-P9-03 only (sealed ops state) |
| Architecture or Owner Decision edits | **No** |
| C3–C8 engine edits | **No** |
| P0–P8 frozen baseline / engine edits | **No** (except confirmed defects; scaffold version bump only) |

**WP-P9-01 coding:** Control-plane registry only. Ops flag remains **FALSE**.  
**Later WPs:** Follow §7 inventory; one WP at a time.

---

## 4. P9 Enablement charter (roadmap binding)

### 4.1 Discovery sources (official — not invented)

| Source | Binding content |
|--------|-----------------|
| Architecture roadmap P9 | Enablement = **Flag true under OD-ENABLE path** |
| Architecture §3 / §37.1 / enablement gate | Hard false until OD-ENABLE preconditions |
| OD-ENABLE | Four preconditions; disabled by default; explicit Owner enablement order |
| OD-CERT | Cert PASS required as precondition; Engineering never grants PASS |
| OD-PERM | Super Admin Enable/Disable operational; Country Admin never |
| OD-SCHEMA | Invalidation force-disable; no auto re-enable; full re-auth cycle |
| P1-13 | Enablement state machine E0–E8; `cpr_enablement_preconditions`; `cpr_owner_enablement_order`; `cpr_enablement_action`; `cpr_schema_invalidation_event` |
| P2-06 | Schema re-certification cycle design (consume) |
| P8 baseline | Sealed Owner Certification result is input to enablement preconditions |
| Prior phase pattern | Control plane WP-*-01 + final Integration Baseline freeze WP |

### 4.2 In scope for P9 (across WP-P9-02+)

| Capability | Consumes | Must not |
|------------|----------|----------|
| Enablement preconditions + Owner enablement order | OD-ENABLE; P1-13 §6; sealed P8 cert PASS | Auto-enable; Engineering enable |
| Super Admin Enable/Disable | OD-PERM; P1-13 §7; E5 only for Enable | Country Admin / Engineering enable; auto-enable |
| Schema invalidation force-disable hooks | OD-SCHEMA; P1-13 §8; P2-06 | Auto re-enable; rewrite historical packs |
| Enablement baseline freeze | All P9 stages | Start Enterprise Audit / Tag without Owner; reopen OD/Architecture |

### 4.3 Explicitly out of scope for all of P9 (and forever where noted)

| Item | Deferred / forbidden |
|------|----------------------|
| Architecture / OD amendments | Forbidden |
| C3–C8 engine changes | Forbidden |
| Re-implement P3–P8 engines / re-invent cert / evidence stacks | Forbidden — consume only |
| Engineering-granted Cert PASS | Forbidden forever (OD-CERT) |
| Auto-enable / auto re-enable | Forbidden forever (OD-ENABLE / OD-SCHEMA) |
| Country Admin Enable/Disable | Forbidden forever (OD-PERM) |
| Enterprise Audit / Git Tag / Phase Sign-Off | After P9 implementation — Owner-gated closure sequence (not WP engines) |

---

## 5. Storage layout

### 5.1 Design / control documents

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md` | This control plane (WP-P9-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_02_*.md` … | Later WP design notes (names in §7) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_04_INTEGRATION_BASELINE.md` | Phase freeze (WP-P9-04) |

### 5.2 Code (later WPs; not WP-P9-01 engines)

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_p9_control_plane.php` | WP-P9-01 registry |
| `includes/backup/country_production/cpr_enablement_preconditions_live.php` | WP-P9-02 Enablement Preconditions & Owner Order |
| `includes/backup/country_production/cpr_enablement_action_live.php` | WP-P9-03 Super Admin Enable/Disable + schema force-disable |
| `includes/backup/country_production/cpr_p9_integration.php` | WP-P9-04 Integration Baseline Freeze |
| `includes/backup/country_production/cpr_enablement.php` | Existing read/assert substrate — writers remain P9-03 |
| `includes/backup/country_production/cpr_*` (P3–P8) | Consumed substrate — do not fork |

### 5.3 Runtime (later WPs)

| Path | Role |
|------|------|
| `{job}/enablement/` | Sealed preconditions, Owner order, enable/disable actions (WP-P9-02+) |
| `{job}/integration_live/` | Sealed P9 integration freeze report (WP-P9-04) |

---

## 6. Naming

| Kind | Pattern |
|------|---------|
| Design docs | `COUNTRY_PRODUCTION_RESTORE_P9_##_TITLE.md` |
| Artifact-ID | `CPR-P9-WP##-SHORT_NAME` |
| PHP | `orange_cpr_*` / `cpr_*` under `includes/backup/country_production/` |
| Scaffold version | `P9-##-…` via `ORANGE_CPR_SCAFFOLD_VERSION` |

Prefer `orange_cpr_*` prefixes consistent with P3–P8 helpers; never reuse Full DR job ids as CPR job ids.

---

## 7. Work Package inventory (P9) — discovered from approved roadmap

| WP | Title | Primary artifact | Status |
|----|-------|------------------|--------|
| **WP-P9-01** | P9 Control Plane & Artifact Index | `COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md` | **COMPLETE** |
| **WP-P9-02** | Enablement Preconditions & Owner Enablement Order (P1-13 §6 / OD-ENABLE → E5; flag still false) | `COUNTRY_PRODUCTION_RESTORE_P9_02_ENABLEMENT_PRECONDITIONS.md` | **COMPLETE** |
| **WP-P9-03** | Super Admin Enable/Disable + Schema Invalidation Force-Disable Hooks (P1-13 §7–§8 / OD-PERM / OD-SCHEMA) | `COUNTRY_PRODUCTION_RESTORE_P9_03_ENABLEMENT_ACTIONS.md` | **COMPLETE** |
| **WP-P9-04** | P9 Integration Review & Enablement Baseline Freeze | `COUNTRY_PRODUCTION_RESTORE_P9_04_INTEGRATION_BASELINE.md` | **COMPLETE** |

**Execution rule (Owner):** One WP at a time → Verify AC → Commit → Push → **STOP** → wait for approval before next WP.

**Drift control:** Later WPs must not introduce primary filenames absent from this table without updating **this index** in the same Owner-authorized WP (still without modifying Architecture/Register).

**Discovery note:** Architecture roadmap P9 states only *Enablement → Flag true under OD-ENABLE path*. Official decomposition maps that single output onto: (1) control plane, (2) enablement preconditions record + explicit Owner enablement order reaching `E5_preconditions_satisfied` with flag still false per P1-13 §6 / OD-ENABLE four preconditions (consuming sealed P8 Cert PASS), (3) Super Admin operational Enable/Disable per P1-13 §7 / OD-PERM plus schema-invalidation force-disable hooks per P1-13 §8 / OD-SCHEMA / P2-06 (no auto-enable / no auto re-enable), (4) phase integration freeze pattern used for P2-07 / P3-09 / P4-09 / P5-06 / P6-06 / P7-05 / P8-04. **No additional WPs invented.**

---

## 8. Execution order & dependencies

Architecture roadmap (normative ordering intent):

```text
[P8 Owner Certification Baseline — sealed Cert PASS]
  → Enablement preconditions + Owner enablement order (flag FALSE → E5)
  → Super Admin Enable (E5 → E6; flag TRUE) / Disable / schema force-disable
  → P9 enablement baseline freeze
  ✗ STOP before Enterprise Audit / Git Tag / Phase Sign-Off (Owner-gated closure)
```

| WP | Depends on | Unlocks |
|----|------------|---------|
| WP-P9-01 | Owner P9 authorization; `P8-OwnerCert-Baseline` | All P9 WPs |
| WP-P9-02 | WP-P9-01; sealed P8 Cert PASS; OD-ENABLE; P1-13 §6 | WP-P9-03 |
| WP-P9-03 | WP-P9-02; OD-PERM; P1-13 §7–§8; OD-SCHEMA | WP-P9-04 |
| WP-P9-04 | WP-P9-02…WP-P9-03 COMPLETE | P9 baseline freeze; wait for Owner before Enterprise Audit / Tag / Sign-Off |

---

## 9. WP → baseline contract map

| WP | OWNER_APPROVED | Architecture / P1–P8 | Notes |
|----|----------------|----------------------|-------|
| WP-P9-01 | OD-ENABLE, OD-CERT, OD-PERM, OD-SCHEMA | Roadmap P9; P1-13; P8 freeze | Inventory only |
| WP-P9-02 | OD-ENABLE, OD-CERT | P1-13 §6; P8 `cpr_certification_result` | Flag remains false at E5 |
| WP-P9-03 | OD-ENABLE, OD-PERM, OD-SCHEMA | P1-13 §7–§8; P2-06 | Only path that may set flag true |
| WP-P9-04 | All above | Phase freeze pattern | No new business rules |

### Normative invariants

| Invariant | Authority |
|-----------|-----------|
| Disabled by default | OD-ENABLE |
| Four preconditions before Enable | OD-ENABLE |
| Cert PASS ≠ enablement | OD-ENABLE / P8 |
| No auto-enable / no auto re-enable | OD-ENABLE / OD-SCHEMA |
| Super Admin only for ops Enable/Disable | OD-PERM |
| Country Admin never Enable/Disable | OD-PERM |
| Engineering never Enable / never Cert PASS | OD-PERM / OD-CERT |
| Schema change → force false + full cycle | OD-SCHEMA |

---

## 10. Citation rules

Every later P9 design/code change must cite at least one of:

- OD-ENABLE / OD-CERT / OD-PERM / OD-SCHEMA frozen wording  
- Architecture roadmap P9 or enablement sections  
- `CPR-P1-WP13-ENABLEMENT_CERT_HOOKS` (P1-13)  
- `CPR-P2-WP06-SCHEMA_RECERT_CYCLE` (P2-06) where schema cycle applies  
- P8 Artifact-ID / sealed certification artifacts  

---

## 11. Change control

| Change | Requires |
|--------|----------|
| New P9 WP / primary artifact name | Update this index in same Owner-authorized WP |
| Architecture or OD text | **Forbidden** |
| Enablement flag default true in repo | **Forbidden** |
| Skip Owner approval between WPs | **Forbidden** |

---

## 12. Acceptance criteria (WP-P9-01)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | P9 control plane document exists with Artifact-ID | **PASS** |
| AC2 | Hard rules bind SoT, P0–P8 baselines, no OD reopen, no architecture redesign, OD-ENABLE four preconditions, no auto-enable | **PASS** §2 |
| AC3 | P9 roadmap objective recorded: Enablement → Flag true under OD-ENABLE path | **PASS** §1, §4 |
| AC4 | Enablement charter in/out of scope defined from Architecture + P1-13 + ODs (no invented scope) | **PASS** §4 |
| AC5 | Naming, storage, citation, change control defined | **PASS** §5–§6, §10–§11 |
| AC6 | Official P9 WP inventory WP-P9-01…04 listed; discovered from roadmap only | **PASS** §7 |
| AC7 | Every WP maps to OWNER_APPROVED / Architecture / prior baselines | **PASS** §9 |
| AC8 | Dependencies and execution order defined | **PASS** §8 |
| AC9 | Artifact names defined for every WP | **PASS** §7 |
| AC10 | WP-P9-01 contains no Owner enablement order sealer / SA Enable writer / flag flip | **PASS** §3 |
| AC11 | Control registry + self-tests green; PHP lint clean | **PASS** |
| AC12 | Architecture and Owner Decisions not modified by this WP | **PASS** |
| AC13 | P0–P8 contracts preserved (no P8 engine redesign) | **PASS** |
| AC14 | Ops enablement remains FALSE after WP-P9-01 | **PASS** |

---

## 13. Stop rule

**WP-P9-01 COMPLETE** (P9 enablement control plane).  
**WP-P9-02 COMPLETE** (Enablement Preconditions & Owner Enablement Order).  
**WP-P9-03 COMPLETE** (Super Admin Enable/Disable & Schema Force-Disable).  
**WP-P9-04 COMPLETE** (P9 Integration Review & Enablement Baseline Freeze).  

**FINAL Enterprise Audit:** documented in `COUNTRY_PRODUCTION_RESTORE_FINAL_ENTERPRISE_AUDIT.md` (**PASSED**; documentation consistency restored; Owner approval of the audit verdict still pending).  

Historical WP-P9-04 freeze text retained for evidence: Do **not** start the Enterprise Audit.  
Do **not** create the Git Tag.  
Do **not** produce the P9 Phase Sign-Off.  
Do **not** declare the project complete.  

Wait for Owner approval of the FINAL audit verdict before Tag / Sign-Off / closure.

---

## 14. Acceptance criteria (WP-P9-02)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Enablement Preconditions engine implemented | **PASS** — `cpr_enablement_preconditions_live.php` |
| AC2 | Owner Enablement Order validated per P1-13 §6.4 | **PASS** |
| AC3 | All four OD-ENABLE prerequisites verified | **PASS** |
| AC4 | Integrates cert / state / checkpoint / recovery / audit / contract / job / schema / permissions | **PASS** |
| AC5 | Rejects missing/corrupt/cert/schema/permission/replay; fail-closed | **PASS** |
| AC6 | Sealed preconditions + manifest + Owner order | **PASS** |
| AC7 | Audit + recovery metadata integrity | **PASS** |
| AC8 | No privilege bypass; no cross-country; no partial/auto enablement | **PASS** |
| AC9 | Enablement remains FALSE after E5; no production SQL/uploads | **PASS** |
| AC10 | Architecture / OWNER_APPROVED unchanged; no SA Enable | **PASS** |
| AC11 | Self-tests + lint + full CPR suite green | **PASS** |

---

## 15. Acceptance criteria (WP-P9-03)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Super Admin Enable/Disable engine implemented | **PASS** — `cpr_enablement_action_live.php` |
| AC2 | Schema Invalidation Force-Disable hooks implemented | **PASS** |
| AC3 | Only this WP changes the operational enablement flag | **PASS** |
| AC4 | Enable only after sealed E5 prerequisites | **PASS** |
| AC5 | Integrates E5 / cert / state / checkpoint / recovery / audit / contract / job / schema | **PASS** |
| AC6 | Super Admin only; Owner order required for Enable; fail-closed | **PASS** |
| AC7 | No automatic enablement / no automatic re-enable | **PASS** |
| AC8 | Immediate force-disable on schema invalidation → E8 | **PASS** |
| AC9 | No replay; no privilege bypass; no cross-country | **PASS** |
| AC10 | Sealed enable/disable decisions + manifest + audit + recovery | **PASS** |
| AC11 | No production SQL/uploads; Architecture/OD unchanged | **PASS** |
| AC12 | Self-tests + lint + full CPR suite green | **PASS** |

---

## 16. Acceptance criteria (WP-P9-04)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | All P9 live modules integrated into one verified enablement chain | **PASS** — `cpr_p9_integration.php` |
| AC2 | Complete execution order verified (Cert → E5 → Enable → ops → Disable/Schema FD → freeze) | **PASS** |
| AC3 | Prerequisite / enable-disable / schema / contract / job / permission / fingerprint verified | **PASS** |
| AC4 | Audit + recovery integrity; no orphans; no replay; no privilege bypass | **PASS** |
| AC5 | P9 Integration Baseline document + Freeze report + inventory + verification report | **PASS** |
| AC6 | Updated P9 Artifact Index + phase completion status | **PASS** |
| AC7 | No new business logic; Architecture / OWNER_APPROVED unchanged; no production SQL/uploads | **PASS** |
| AC8 | No Enterprise Audit; no Git Tag; no Sign-Off; project not declared complete | **PASS** |
| AC9 | Self-tests + PHP lint + full CPR suite green | **PASS** |

---

*End of P9 Artifact Index (updated WP-P9-04).*
