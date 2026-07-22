# Country Production Restore — P6 Artifact Index (Verify + Rollback Control Plane)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P6-01** — P6 Control Plane & Artifact Index |
| **Artifact-ID** | `CPR-P6-WP01-ARTIFACT_INDEX` |
| **Status** | COMPLETE (P6 control plane) |
| **Version** | 1.0 |
| **Date** | 2026-07-22 |
| **Authorization** | Owner **P6 Execution Authorization** (after P5 PONR Execution Baseline freeze + Enterprise Audit PASSED + phase closure) |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **P2 design baseline** | Git tag `P2-Design-Baseline` → commit `4cadc687` |
| **P3 engine baseline** | Git tag `P3-Engine-Baseline` → commit `7a7f8c99` |
| **P4 Pre-PONR baseline** | Git tag `P4-PrePONR-Baseline` → commit `6bc09bcb` |
| **P5 PONR Execution baseline** | Git tag `P5-PONR-Execution-Baseline` → commit `b4c7a739` |
| **Policy SoT** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` (**do not modify**) |
| **Ops clarifications** | `GLOBAL_RESTORE_OPERATIONAL_POLICY.md` · `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` |
| **C3–C8** | Immutable inputs — must not be modified |
| **This document role** | P6 Control Plane: inventory, naming, coding scope, hard rules, WP→baseline map for Verify + Rollback |
| **Control registry** | `includes/backup/country_production/cpr_p6_control_plane.php` |

---

## 1. Purpose

Establish how all **P6 Verify + Rollback Integration** artifacts (design notes + code delivered in later P6 WPs) are named, stored, versioned, and bound to:

- OWNER_APPROVED Register  
- P0 Architecture (unchanged)  
- P1–P5 frozen baselines (`P1-Design-Baseline` … `P5-PONR-Execution-Baseline`)  

Per Architecture roadmap:

| Phase | Name | Output |
|-------|------|--------|
| **P6** | Verify + rollback integration | **Post-verify + session Full-anchor rollback** |

This Work Package:

- Opens P6 under Owner authorization.  
- Discovers and records the official P6 Work Package inventory from Architecture §18 / §19 and roadmap P6 (+ OD-VERIFY-WARN / OD-ROLLBACK).  
- Does **not** implement post-verify, success finalize, rollback workers, or maint release in WP-P6-01 itself.  
- Does **not** flip enablement (P9 / OD-ENABLE).  
- Does **not** run clone drills (P7) or Owner Cert (P8).  
- Does **not** redesign Architecture or reopen Owner Decisions.  
- Preserves all contracts frozen in P0–P5 (Recovery, Resume, Checkpoint, Lock, Gate, Authority, Witness, DELETE, IMPORT, Special Handlers, Uploads).

---

## 2. Hard rules (binding for all P6 Work Packages)

1. **SoT:** `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` — frozen OWNER_APPROVED text wins every conflict.  
2. **Baselines:** Consume P0–P5 frozen baselines; do not silently revise them.  
3. **No redesign:** Do not change Architecture documents to fit P6 convenience.  
4. **No OD reopen:** Do not amend, reinterpret, or reopen OWNER_APPROVED decisions.  
5. **No silent interpretation:** Every normative P6 behavior must cite OD frozen wording and/or Architecture section and/or P1–P5 Artifact-ID.  
6. **Insufficient policy:** If frozen policy is insufficient → **STOP · Document · Escalate**. Do not invent policy.  
7. **C3–C8:** Consume reports/hashes/gates only; never modify CRP engines or semantics.  
8. **Enablement:** Remains **hard false** during P6 (`Architecture` roadmap: drills/cert before OD-ENABLE). P6 does **not** flip enablement (P9).  
9. **P5 boundary:** P6 begins only after CP9 from the frozen P5 PONR Execution Baseline. Do not re-implement DELETE/IMPORT/Special/Uploads.  
10. **No integrity waiver:** OD-VERIFY-WARN — fail-closed; no “success with warnings.”  
11. **No auto-rollback:** OD-ROLLBACK — Super Admin explicit action only; never automatic.  
12. **Consume P3–P5 engines:** Orchestrate existing state/checkpoint/lock/authority/P5 apply artifacts — do not fork a second CPR stack.  
13. **No invented WPs:** P6 Work Packages are only those discovered from Architecture roadmap P6 + §18 CP10–CP12 + §19 verify suite (+ control plane + phase integration freeze pattern).  
14. **P7+ boundary:** Clone drills / evidence packs remain **P7**; Owner Cert **P8**; enablement true **P9**.  
15. **Preserve P0–P5 contracts:** Recovery, Resume, Checkpoint, Lock, Gate, Authority, Witness, DELETE, IMPORT, Special Handlers, Upload contracts remain binding.  

---

## 3. Coding / execution scope (WP-P6-01 statement)

| Activity | Authorized now? |
|----------|-----------------|
| P6 **control-plane artifacts** for WP-P6-01 | **Yes** — this WP |
| P6 control registry PHP (`cpr_p6_control_plane.php`) + self-test | **Yes** — inventory / hard-rule registry only |
| WP-P6-02+ verify / rollback / finalize / maint-release engines | **No** until Owner approves each next WP |
| **Post-verify suite / CP10** | **No** in WP-P6-01 |
| **Success finalize / CP11** | **No** in WP-P6-01 |
| **Session Full-anchor rollback worker** | **No** in WP-P6-01 |
| **Maintenance release / CP12** | **No** in WP-P6-01 |
| Enablement flag flip | **No** — P9 / OD-ENABLE |
| Architecture or Owner Decision edits | **No** |
| C3–C8 engine edits | **No** |
| P0–P5 frozen baseline / engine edits | **No** (except confirmed defects) |

**WP-P6-01 coding:** Control-plane registry only — **no** post-verify / rollback / closeout engines.

---

## 4. P6 Verify + Rollback charter (roadmap binding)

### 4.1 Discovery sources (official — not invented)

| Source | Binding content |
|--------|-----------------|
| Architecture roadmap P6 | Verify + rollback integration = Post-verify + session Full-anchor rollback |
| Architecture §18 | CP10 `post_verify_pass` · CP11 `success_finalized` · CP12 `maint_released` |
| Architecture §19 | Post-apply verification suite (12 checks); fail → FAILED; Maint ON; Resume or Rollback only |
| Architecture §11–§13 / states | `cpr_post_verifying` · `cpr_succeeded` · `cpr_maintenance_released` · `cpr_rolling_back` · `cpr_rollback_completed` · `cpr_failed_post_ponr` |
| OD-VERIFY-WARN | Fail-closed post-apply integrity; no waiver / success-with-warnings |
| OD-ROLLBACK | Super Admin dashboard Rollback only when paused on failure; targets OD-PIN session Full Backup; never automatic |
| OD-FAIL-* / OD-PIN | Pause/Resume/Rollback authority already frozen; P6 integrates execution workers |
| P1-09 · P1-11 · P1-04 | Fail/Resume/Rollback design; verify reports; checkpoint schemas for CP10–CP12 |
| P5 Artifact Index §4.3 | Deferred to P6: post-apply verify; success finalize / maint release; Resume/Rollback workers |
| Prior phase pattern | Control plane WP-*-01 + final Integration Baseline freeze WP |

### 4.2 In scope for P6 (across WP-P6-02+)

| Capability | Consumes | Checkpoint / state | Must not |
|------------|----------|--------------------|----------|
| Post-apply verification suite | P5 CP9 path; Arch §19; OD-VERIFY-WARN; CP5 witnesses | CP10 · `cpr_post_verifying` | Integrity waiver; success with warnings |
| Success finalize / sealed reports | Verify PASS; Arch §18 CP11 | CP11 · `cpr_succeeded` | Finalize on failed verify |
| Session Full-anchor rollback | OD-ROLLBACK; OD-PIN session Full Backup; P1-09 | `cpr_rolling_back` → `cpr_rollback_completed` | Auto-rollback; Country Admin rollback |
| Maintenance release / closeout | OD-RUNBOOK / OD-MAINT; success or rollback completed | CP12 · `cpr_maintenance_released` | Release while dirty/incomplete; Country Admin release |
| Flags / fail-closed under enablement FALSE | OD-ENABLE; G01 | — | Flip enablement true |

### 4.3 Explicitly out of scope for all of P6

| Item | Deferred to |
|------|-------------|
| Clone drills / real-clone proof | P7 |
| Owner Cert PASS ceremony | P8 |
| Enablement true | P9 |
| Re-implement P5 DELETE/IMPORT/Special/Uploads | Forbidden (consume P5) |
| C3–C8 modifications | Forbidden |
| Architecture / OWNER_APPROVED edits | Forbidden |

### 4.4 “Verify + rollback under flags” acceptance meaning

A P6 build is successful when:

1. After a valid P5 path through CP9, post-verify can execute the Architecture §19 suite fail-closed under flags.  
2. Success path can seal reports (CP11) and release maint only via authorized closeout (CP12).  
3. Failure path pauses for Super Admin Resume (when safe) or OD-ROLLBACK to session Full Backup — never automatic.  
4. Ops enablement remains **FALSE** until Owner OD-ENABLE path (P9).  
5. All behaviors cite Register / Architecture / P1–P5 Artifact-IDs — no silent policy.  

---

## 5. Storage layout

### 5.1 Design / control documents

| Path | Role |
|------|------|
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md` | This control plane (WP-P6-01) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_02_*.md` … | Later WP design notes (names in §7) |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_06_INTEGRATION_BASELINE.md` | Phase freeze (WP-P6-06) |

### 5.2 Code (later WPs; not WP-P6-01 engines)

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_p6_control_plane.php` | WP-P6-01 registry |
| `includes/backup/country_production/cpr_*_live.php` (P3–P5) | Consumed substrate — do not fork |
| Future `cpr_post_verify_live.php` / rollback / closeout modules | WP-P6-02+ only |

### 5.3 Runtime (later WPs)

| Path | Role |
|------|------|
| `{job}/checkpoints/CP10_*.json` … `CP12_*.json` | Architecture §18 |
| `{job}/post_verify/` · rollback evidence | Sealed verify/rollback reports (later WPs) |

---

## 6. Naming

| Kind | Pattern |
|------|---------|
| Design docs | `COUNTRY_PRODUCTION_RESTORE_P6_##_TITLE.md` |
| Artifact-ID | `CPR-P6-WP##-SHORT_NAME` |
| PHP | `orange_cpr_*` / `cpr_*` under `includes/backup/country_production/` |
| Scaffold version | `P6-##-…` via `ORANGE_CPR_SCAFFOLD_VERSION` |

Prefer `orange_cpr_*` prefixes consistent with P3–P5 helpers; never reuse Full DR job ids as CPR job ids.

---

## 7. Work Package inventory (P6) — discovered from approved roadmap

| WP | Title | Primary artifact | Status |
|----|-------|------------------|--------|
| **WP-P6-01** | P6 Control Plane & Artifact Index | `COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md` | **COMPLETE** |
| **WP-P6-02** | Post-Verify Engine (CP10 / Arch §19) | `COUNTRY_PRODUCTION_RESTORE_P6_02_POST_VERIFY.md` | **COMPLETE** |
| **WP-P6-03** | Success Finalize (CP11) | `COUNTRY_PRODUCTION_RESTORE_P6_03_SUCCESS_FINALIZE.md` | **COMPLETE** |
| **WP-P6-04** | Session Full-Anchor Rollback Integration (OD-ROLLBACK) | `COUNTRY_PRODUCTION_RESTORE_P6_04_ROLLBACK_INTEGRATION.md` | **COMPLETE** |
| **WP-P6-05** | Maintenance Release / Closeout (CP12) | `COUNTRY_PRODUCTION_RESTORE_P6_05_MAINT_RELEASE.md` | **COMPLETE** |
| **WP-P6-06** | P6 Integration Review & Verify/Rollback Baseline Freeze | `COUNTRY_PRODUCTION_RESTORE_P6_06_INTEGRATION_BASELINE.md` | PENDING |

**Execution rule (Owner):** One WP at a time → Verify AC → Commit → Push → **STOP** → wait for approval before next WP.

**Drift control:** Later WPs must not introduce primary filenames absent from this table without updating **this index** in the same Owner-authorized WP (still without modifying Architecture/Register).

**Discovery note:** WP-P6-02…05 map to Architecture roadmap P6 + §18 CP10–CP12 + §19 / OD-VERIFY-WARN / OD-ROLLBACK. WP-P6-06 is the phase integration freeze pattern used for P2-07 / P3-09 / P4-09 / P5-06. No additional WPs invented.

---

## 8. Execution order & dependencies

Architecture §6 / §18 post-apply sequence (normative ordering intent):

```text
[P5 complete through CP9]
  → Post-verify suite (CP10)
  → Success finalize / sealed reports (CP11)
      OR failure → pause → Super Admin Resume (safe) / OD-ROLLBACK → rollback completed
  → Maintenance release (CP12) on authorized closeout
  ✗ STOP before clone drills (P7)
```

| WP | Depends on | Unlocks |
|----|------------|---------|
| WP-P6-01 | Owner P6 authorization; `P5-PONR-Execution-Baseline` | All P6 WPs |
| WP-P6-02 | WP-P6-01; P5 CP9 path available | WP-P6-03 · WP-P6-04 |
| WP-P6-03 | WP-P6-02 PASS | WP-P6-05 (success path) |
| WP-P6-04 | WP-P6-01; pause/fail states; OD-PIN | WP-P6-05 (rollback closeout) |
| WP-P6-05 | WP-P6-03 **or** rollback completed | WP-P6-06 |
| WP-P6-06 | WP-P6-02…WP-P6-05 COMPLETE | P6 baseline freeze; wait for Owner before P7 |

---

## 9. WP → baseline contract map

| WP | Primary OD / principles | Primary P1 | Primary P3–P5 | Architecture |
|----|-------------------------|------------|---------------|--------------|
| WP-P6-01 | OD-ENABLE, OD-PERM, Integrity | P1 freeze | P5 freeze | Roadmap P6; §4 safety |
| WP-P6-02 | OD-VERIFY-WARN; Integrity; OD-FA-* | P1-11 · P1-04 (CP10) | P5 CP9 | §18 CP10, §19 |
| WP-P6-03 | Integrity; OD-PERM | P1-04 (CP11) | — | §18 CP11 |
| WP-P6-04 | OD-ROLLBACK; OD-PIN; OD-FAIL-* | P1-09 · P1-04 | P4 OD-PIN | Roadmap P6; §11–§13 |
| WP-P6-05 | OD-MAINT; OD-RUNBOOK; OD-PERM | P1-04 (CP12) · P1-07 | P4 maint live | §18 CP12 |
| WP-P6-06 | All cited | All P6 + baselines | Freeze | Freeze |

Foundational principles (always in force):

| Principle | Binds |
|-----------|--------|
| Integrity over privilege | No Super Admin waiver of verify failures |
| Recovery scope isolation | Survivor/global safety retained from CP5 |
| Operational governance | Permissions / runbook / flags — never weakens Integrity/Isolation/Global Restore Policy |

---

## 10. Consistency commitments

| Baseline | Commitment |
|----------|------------|
| OWNER_APPROVED Register | No reopen; implement only frozen wording |
| P0 Architecture | Technical baseline; register wins conflicts; **do not modify** file |
| P1 Design Baseline | State/checkpoint/fail/verify/rollback contracts remain SoT |
| P2 Design Baseline | Cert/enablement constraints; P6 does not run Owner Cert |
| P3 Engine Baseline | State/checkpoint/lock substrate |
| P4 Pre-PONR Baseline | OD-PIN / maint / authority path consumed |
| P5 PONR Execution Baseline | CP9 apply path consumed; not reimplemented |

---

## 11. Citation rules

1. Prefer **OD-ID + §15 Frozen** when stating policy.  
2. Prefer **P1/P5 Artifact-ID** when stating contracts.  
3. Prefer **Architecture section** as implementation narrative; register wins.  
4. Do not cite draft chat as authority.

---

## 12. Change control

| Change type | Allowed in P6? |
|-------------|----------------|
| New/updated `COUNTRY_PRODUCTION_RESTORE_P6_*.md` under authorized WP | Yes |
| Code under authorized WP-P6-02+ | Yes (not verify/rollback engines in WP-P6-01) |
| Edit frozen P0–P5 / Architecture / Register | **No** |
| Enablement true | **No** — P9 |
| Clone drills / Owner Cert | **No** — P7 / P8 |
| C3–C8 edits | **No** |
| Start WP-P6-02 before Owner approval of WP-P6-01 | **No** |
| Invent WPs beyond §7 inventory | **No** |

---

## 13. Acceptance criteria (WP-P6-01)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | P6 control plane document exists with Artifact-ID | **PASS** |
| AC2 | Hard rules bind SoT, P0–P5 baselines, no OD reopen, no architecture redesign, enablement hard false | **PASS** §2 |
| AC3 | P6 roadmap objective recorded: Post-verify + session Full-anchor rollback | **PASS** §1, §4 |
| AC4 | Verify/Rollback charter in/out of scope defined from Architecture (no invented scope) | **PASS** §4 |
| AC5 | Naming, storage, citation, change control defined | **PASS** §5–§6, §11–§12 |
| AC6 | Official P6 WP inventory WP-P6-01…06 listed; discovered from roadmap only | **PASS** §7 |
| AC7 | Every WP maps to OWNER_APPROVED / Architecture / prior baselines | **PASS** §9 |
| AC8 | Dependencies and execution order defined | **PASS** §8 |
| AC9 | Artifact names defined for every WP | **PASS** §7 |
| AC10 | WP-P6-01 contains no post-verify / rollback / finalize / maint-release engine | **PASS** §3 |
| AC11 | Control registry + self-tests green; PHP lint clean | **PASS** |
| AC12 | Architecture and Owner Decisions not modified by this WP | **PASS** |
| AC13 | P0–P5 contracts preserved (no P5 engine redesign) | **PASS** |

---

## 14. Stop rule

**WP-P6-01 COMPLETE** (control plane).  
**WP-P6-02 COMPLETE** (Post-Verify / CP10).  
**WP-P6-03 COMPLETE** (Success Finalize / CP11).  
**WP-P6-04 COMPLETE** (Session Full-Anchor Rollback / OD-ROLLBACK).  
**WP-P6-05 COMPLETE** (Maintenance Release / CP12).  
Commit → Push → **STOP.**  
Do **not** begin **WP-P6-06** until Owner explicitly reviews and approves the next Work Package.

---

*End of P6 Artifact Index (updated through WP-P6-05).*
