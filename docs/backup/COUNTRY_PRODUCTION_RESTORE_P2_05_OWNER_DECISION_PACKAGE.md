# Country Production Restore — P2 Owner Submission & PASS/FAIL Decision Package

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P2-05** — Owner Submission & PASS/FAIL Decision Package |
| **Artifact-ID** | `CPR-P2-WP05-OWNER_DECISION_PACKAGE` |
| **Status** | COMPLETE (certification design only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P2-04; authorized WP-P2-05 |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-CERT · OD-ENABLE · OD-PERM) |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P2_ARTIFACT_INDEX.md` (WP-P2-01) |
| **Depends on** | WP-P2-02 · WP-P2-03 · WP-P2-04 · P1-13 |
| **Coding** | **No** — design contracts only; no PHP/SQL/CLI/HTTP/UI |
| **Enablement** | Remains **FALSE** (OD-ENABLE) |

---

## 1. Purpose

Define the complete **Owner Submission package** and the **PASS/FAIL decision package** presented to the Owner for Country Production Restore Certification (OD-CERT).

This WP:

- Separates **Engineering findings / recommendations** from the **Owner decision**.  
- Allows Engineering to **recommend** PASS or FAIL with evidence citations.  
- Forbids Engineering from **granting** Certification PASS.  
- Binds submission to sealed Evidence Pack (WP-P2-04) and checklist/drills (WP-P2-02/03).  
- Does **not** flip enablement, modify Architecture/ODs/P1, or write mutation code.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Owner is sole PASS/FAIL authority for Certification | OD-CERT §15 Frozen |
| H2 | Engineering produces evidence and may recommend; **never** grants final Cert PASS | OD-CERT · P1-13 §5.3 |
| H3 | Reject any `cpr_certification_result` with `result=PASS` and `decided_by != owner` | P1-13 §5.2 |
| H4 | Submission requires sealed Evidence Pack + `ready_for_owner_review=true` | WP-P2-04 §9 |
| H5 | Every Engineering recommendation must cite supporting `artifact_id` / EV-* / DS-* / CG-* | WP-P2-01 · Integrity |
| H6 | Cert PASS ≠ enablement; flag remains **FALSE** until OD-ENABLE path | OD-ENABLE · WP-P2-02 §3.5 |
| H7 | CG-H* and CG-F01 are Owner-only; Engineering leaves them `PENDING` until Owner acts | WP-P2-02 |
| H8 | No architecture redesign; no OD reopen; no mutation code | P2 Execution Authorization |

---

## 3. Authority split (binding)

| Concern | Engineering | Owner |
|---------|:-----------:|:-----:|
| Assemble / seal Evidence Pack | Yes | No |
| Mark CG-S* / CG-M* | Yes | May review |
| Write findings & recommendation | Yes | — |
| Mark CG-H* accepted | **No** | **Yes** |
| Set `cpr_certification_result.result` PASS/FAIL | **No** | **Yes** |
| Flip enablement flag | **No** | Order only (later OD-ENABLE; not this package) |
| Super Admin operational Enable | N/A here | After full OD-ENABLE path (P9) |

**Const fields on all submission/decision records:**

- `owner_pass_mandatory = true`  
- `engineering_cannot_grant_pass = true`  
- `enablement_flag = false`  
- `cert_pass_does_not_enable = true`  

---

## 4. Lifecycle binding

| Step | Actor | Lifecycle / pack state |
|------|-------|------------------------|
| 1. Seal Evidence Pack | Engineering | Pack `sealed`; cert `PENDING` |
| 2. Build Owner Submission package | Engineering | `cert_evidence_in_progress` → prepare submit |
| 3. Submit to Owner | Engineering | `cert_submitted_for_owner` |
| 4. Owner reviews CG-H* | Owner | Still submitted |
| 5. Owner records PASS or FAIL | Owner | `cert_pass` or `cert_fail` |
| 6. Enablement | — | Remains false (separate path) |

---

## 5. Owner Submission package structure

### 5.1 Logical layout

```text
cpr_owner_submission/
  submission_manifest.json          # cpr_owner_submission_manifest/1
  executive_summary.json            # §6.1
  certification_summary.json        # §6.2
  evidence_summary.json             # §6.3
  checklist_summary.json            # §6.4
  drill_results_summary.json        # §6.5
  verification_summary.json         # §6.6
  rollback_evidence_summary.json    # §6.7
  outstanding_issues.json           # §6.8
  engineering_recommendation.json   # §7 (PASS or FAIL recommendation)
  owner_decision_blank.json         # §8 template (PENDING)
  links/
    evidence_pack_ref.json          # package_cycle_id, pack_seal_hash, paths
    cpr_certification_result.json   # PENDING shell (P1-13)
```

Design path:  
`{workRoot}/country_production/certification/{package_cycle_id}/owner_submission/`

### 5.2 Submission manifest — `cpr_owner_submission_manifest/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_submission_manifest/1"` |
| `submission_id` | Y | UUID |
| `package_cycle_id` | Y | |
| `evidence_pack_id` | Y | |
| `pack_seal_hash` | Y | Must match sealed pack |
| `certification_id` | Y | |
| `schema_revision_bound` | Y | |
| `submitted_at` | Y | |
| `submitted_by_engineering_id` | Y | |
| `lifecycle_state` | Y | Must be `cert_submitted_for_owner` at submit |
| `ready_for_owner_review` | Y | Must be `true` (from pack validation) |
| `evidence_ready` | Y | Must be `true` (CG-S*+CG-M*) |
| `enablement_flag` | Y | Must be `false` |
| `engineering_recommendation` | Y | `RECOMMEND_PASS` \| `RECOMMEND_FAIL` \| `RECOMMEND_WITHHOLD` |
| `owner_decision_present` | Y | `false` at submit |
| `section_refs` | Y | Paths/ids of §6–§8 documents |
| `owner_pass_mandatory` | Y | Const `true` |
| `engineering_cannot_grant_pass` | Y | Const `true` |
| `cert_pass_does_not_enable` | Y | Const `true` |

**Reject submit if** pack not sealed, `ready_for_owner_review=false`, `evidence_ready=false`, or enablement not false.

---

## 6. Decision package sections (presented to Owner)

Each section is a schema below. All `supporting_evidence_refs` are arrays of `{artifact_id, evidence_class, scenario_id?, checklist_gate_id?, note?}`.

### 6.1 Executive summary — `cpr_owner_exec_summary/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_exec_summary/1"` |
| `headline` | Y | One paragraph: what is being certified |
| `schema_revision_bound` | Y | |
| `package_cycle_id` | Y | |
| `drill_context` | Y | Clone / non-production attestation |
| `evidence_ready` | Y | Boolean |
| `engineering_recommendation` | Y | Same enum as manifest |
| `enablement_remains_false` | Y | Const `true` |
| `key_risks` | Y | Array of short strings + evidence refs |
| `key_proofs` | Y | Array of short strings + evidence refs |
| `author` | Y | `engineering` |
| `not_a_cert_pass` | Y | Const `true` |

### 6.2 Certification summary — `cpr_owner_cert_summary/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_cert_summary/1"` |
| `certification_id` | Y | |
| `od_cert_frozen_citation` | Y | OD-CERT §15 Frozen wording reference |
| `lifecycle_state` | Y | `cert_submitted_for_owner` |
| `result_current` | Y | Must be `PENDING` at submit |
| `baseline_tags` | Y | `P0-P0b-Final`, `P1-Design-Baseline` |
| `c8_safe` | Y | Boolean + EV-03 ref |
| `schema_binding_ok` | Y | Boolean + EV-12 ref |
| `owner_actions_required` | Y | List: review CG-H01…H06; decide CG-F01 |
| `supporting_evidence_refs` | Y | |

### 6.3 Evidence summary — `cpr_owner_evidence_summary/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_evidence_summary/1"` |
| `pack_seal_hash` | Y | |
| `ev_classes` | Y | Array of `{evidence_class, artifact_count, content_hashes[], status}` for EV-01…EV-14 |
| `all_ev_present` | Y | Must be `true` |
| `integrity_validation` | Y | PV-* roll-up: `all_rules_pass` |
| `supporting_evidence_refs` | Y | |

### 6.4 Checklist summary — `cpr_owner_checklist_summary/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_checklist_summary/1"` |
| `l0_l1` | Y | Counts: pass/fail/pending for CG-S* + CG-M* |
| `evidence_ready` | Y | Must be `true` to recommend PASS |
| `l2_owner_human` | Y | CG-H01…H06 all `PENDING` for Owner |
| `l3_final` | Y | CG-F01 `PENDING` |
| `failed_gates` | Y | Array (empty if Evidence Ready) + evidence refs |
| `supporting_evidence_refs` | Y | |

### 6.5 Drill results summary — `cpr_owner_drill_summary/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_drill_summary/1"` |
| `total_scenarios` | Y | 40 |
| `passed` | Y | Count |
| `failed` | Y | Count |
| `by_class` | Y | Object keyed by class (normal, fail-pause, resume, rollback, …) |
| `scenario_rows` | Y | Compact `{scenario_id, result, artifact_ids[]}` |
| `suite_complete` | Y | Boolean |
| `supporting_evidence_refs` | Y | |

### 6.6 Verification summary — `cpr_owner_verify_summary/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_verify_summary/1"` |
| `post_apply_verify` | Y | Status + EV-09 refs |
| `c8_safe_only` | Y | Status + EV-03 refs |
| `pre_ponr_gates` | Y | Status + EV-04 refs |
| `success_with_warnings_forbidden` | Y | Const `true`; proof refs (DS-V02) |
| `pillar_failures_drilled` | Y | DS-V01 coverage note + refs |
| `supporting_evidence_refs` | Y | |

### 6.7 Rollback evidence summary — `cpr_owner_rollback_summary/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_rollback_summary/1"` |
| `ev10_present` | Y | Must be `true` for RECOMMEND_PASS |
| `minimum_set_satisfied` | Y | WP-P2-03 §6 boolean |
| `fail_pause_proofs` | Y | Scenario ids + artifact refs |
| `resume_proofs` | Y | Incl. DENY (DS-R05) |
| `rollback_proofs` | Y | DS-B* refs |
| `no_auto_rollback_proofs` | Y | DS-M03, DS-P03 |
| `ca_denied_proofs` | Y | DS-P02 |
| `supporting_evidence_refs` | Y | Mandatory non-empty for PASS recommend |

### 6.8 Outstanding issues — `cpr_owner_outstanding_issues/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_outstanding_issues/1"` |
| `issues` | Y | Array of §6.8.1 (may be empty) |
| `blocks_recommend_pass` | Y | Boolean — true if any severity `blocker` open |
| `supporting_evidence_refs` | Y | |

#### 6.8.1 Issue row

| Field | Required | Notes |
|-------|:--------:|-------|
| `issue_id` | Y | UUID or stable id |
| `severity` | Y | `blocker` \| `major` \| `minor` \| `info` |
| `title` | Y | |
| `description` | Y | |
| `supporting_evidence_refs` | Y | Must be non-empty |
| `engineering_disposition` | Y | `open` \| `accepted_risk_for_owner` \| `mitigated` |
| `blocks_cert_pass_recommendation` | Y | Boolean |

**Rule:** Any open `blocker` ⇒ Engineering must not use `RECOMMEND_PASS` (use `RECOMMEND_FAIL` or `RECOMMEND_WITHHOLD`).

---

## 7. Engineering recommendation (not a decision)

### 7.1 Schema — `cpr_engineering_cert_recommendation/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_engineering_cert_recommendation/1"` |
| `recommendation_id` | Y | UUID |
| `package_cycle_id` | Y | |
| `author_role` | Y | Const `engineering` |
| `author_actor_id` | Y | |
| `created_at` | Y | |
| `recommendation` | Y | `RECOMMEND_PASS` \| `RECOMMEND_FAIL` \| `RECOMMEND_WITHHOLD` |
| `rationale` | Y | Human text |
| `supporting_evidence_refs` | Y | **Non-empty** |
| `maps_to_exec_summary` | Y | true |
| `maps_to_rollback_summary` | Y | Required true if RECOMMEND_PASS |
| `is_certification_decision` | Y | Const **`false`** |
| `cannot_grant_pass` | Y | Const **`true`** |
| `enablement_flag` | Y | Const `false` |
| `cert_pass_would_still_leave_enablement_false` | Y | Const `true` |

### 7.2 PASS recommendation rules (`RECOMMEND_PASS`)

Engineering may emit `RECOMMEND_PASS` **only if all** hold:

| # | Condition |
|---|-----------|
| 1 | Sealed pack + PV all pass + `ready_for_owner_review` |
| 2 | `evidence_ready` (all CG-S* + CG-M* PASS) |
| 3 | All 40 drills PASS; EV-10 minimum set satisfied |
| 4 | EV-03 C8 SAFE; EV-13 enablement false |
| 5 | No open `blocker` outstanding issues |
| 6 | `supporting_evidence_refs` cite EV-10 rollback proofs and EV-01…EV-14 coverage |
| 7 | Recommendation record sets `is_certification_decision=false` |

Even then: **Owner may FAIL**. Recommendation never writes `result=PASS`.

### 7.3 FAIL recommendation rules (`RECOMMEND_FAIL`)

Use when Evidence Ready false, drills incomplete, EV-10 missing, C8 not SAFE, enablement not false, integrity fail, or blocker issues — each claim cited with evidence.

### 7.4 WITHHOLD recommendation (`RECOMMEND_WITHHOLD`)

Use when evidence incomplete for a confident PASS/FAIL recommend but submission still needed for Owner visibility (e.g. governance review of open majors). Must not claim Evidence Ready true if false.

---

## 8. Owner decision package

### 8.1 Owner decision record — `cpr_owner_cert_decision/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_owner_cert_decision/1"` |
| `decision_id` | Y | UUID |
| `certification_id` | Y | |
| `package_cycle_id` | Y | |
| `decided_by` | Y | Const **`owner`** for PASS/FAIL |
| `decided_by_actor_id` | Y | Owner actor |
| `decided_at` | Y | |
| `result` | Y | `PASS` \| `FAIL` |
| `cg_h_reviews` | Y | Array `{gate_id, accepted: boolean, notes?, evidence_refs?}` for CG-H01…H06 |
| `cg_f01` | Y | Must align with `result` |
| `engineering_recommendation_id` | N | Reference only |
| `engineering_recommendation_followed` | N | Boolean informational |
| `rationale` | Y | Owner rationale |
| `supporting_evidence_refs` | Y | Owner may cite pack artifacts |
| `is_certification_decision` | Y | Const **`true`** |
| `enablement_flag_after_decision` | Y | Must remain **`false`** |
| `enablement_order_issued` | Y | Must be `false` in this package (separate OD-ENABLE artifact later) |
| `sealed` | Y | `true` after Owner seals decision |

### 8.2 Binding to `cpr_certification_result` (P1-13)

On Owner decision:

| Cert result field | Value |
|-------------------|-------|
| `result` | Owner `PASS` or `FAIL` |
| `decided_by` | `owner` |
| `decided_by_actor_id` | Owner id |
| `decided_at` | Decision time |
| `engineering_submitter_id` | Unchanged from submit |
| `owner_pass_mandatory` | `true` |
| `engineering_cannot_grant_pass` | `true` |
| `sealed` | `true` |
| `evidence_pack_refs` | Unchanged (sealed pack) |

**Reject** if Engineering attempts to write this record with `decided_by=engineering` or `result=PASS` without Owner.

### 8.3 Owner PASS criteria (decision-time)

Owner **may** grant PASS only when:

1. Submission valid (§5.2).  
2. Owner accepts all CG-H01…H06 (`accepted=true`).  
3. Owner sets CG-F01 / `result=PASS` with `decided_by=owner`.  
4. No Owner-identified blocker that Owner chooses to treat as FAIL.  
5. Enablement remains false in decision record.  

Owner **may FAIL** even if Engineering recommended PASS.

### 8.4 Owner FAIL criteria (decision-time)

Owner sets `result=FAIL` when any CG-H* not accepted, evidence inadequate (esp. rollback), governance concern, or explicit FAIL choice. FAIL does not enable CPR.

### 8.5 Blank template at submit

`owner_decision_blank.json` ships with:

- `result = PENDING`  
- `decided_by` omitted or null  
- `cg_h_reviews[*].accepted` unset  
- `is_certification_decision` placeholder note: Owner completes §8.1  

---

## 9. PASS / FAIL recommendation presentation (Owner view)

### 9.1 If Engineering recommends PASS

Owner-facing block must include:

1. Executive summary  
2. Certification summary (PENDING; Owner action required)  
3. Evidence / checklist / drills / verification / rollback summaries  
4. Outstanding issues (none blocking, or listed)  
5. Explicit banner: **“Engineering recommendation only — not Certification PASS”**  
6. Explicit banner: **“Certification PASS does not enable production CPR”**  
7. Owner decision form (CG-H* + CG-F01)

### 9.2 If Engineering recommends FAIL

Same sections, plus failed gates/drills/issues prominently listed with evidence refs; Owner may still FAIL (confirm) or rarely investigate further — Owner never forced to PASS.

### 9.3 Forbidden UI/copy (design)

| Forbidden | Reason |
|-----------|--------|
| Button “Certify PASS” available to Engineering | OD-CERT |
| Auto-set `result=PASS` on submit | OD-CERT |
| “Enabled” after Cert PASS | OD-ENABLE |
| Hide rollback summary when recommending PASS | OD-CERT / EV-10 |

---

## 10. Validation rules for submission package

| Rule-ID | Predicate | Fail code |
|---------|-----------|-----------|
| OS-01 | Manifest valid; pack_seal_hash matches | `submit_pack_mismatch` |
| OS-02 | All §6 sections present | `submit_section_missing` |
| OS-03 | Recommendation present with non-empty evidence refs | `submit_recommend_no_evidence` |
| OS-04 | RECOMMEND_PASS ⇒ §7.2 conditions | `submit_pass_recommend_invalid` |
| OS-05 | enablement false everywhere | `submit_enablement_not_false` |
| OS-06 | Cert result still PENDING; decided_by not engineering PASS | `submit_premature_pass` |
| OS-07 | CG-H*/CG-F01 not marked PASS by Engineering | `submit_owner_gates_usurped` |
| OS-08 | Rollback summary present; EV-10 refs if RECOMMEND_PASS | `submit_rollback_summary_fail` |
| OS-09 | Outstanding blockers consistent with recommendation | `submit_blocker_inconsistent` |
| OS-10 | `is_certification_decision=false` on engineering recommendation | `submit_recommend_claims_decision` |

---

## 11. Traceability

| Submission element | Traces to |
|--------------------|-----------|
| Evidence summary | WP-P2-04 pack / EV-* |
| Checklist summary | WP-P2-02 CG-* |
| Drill summary | WP-P2-03 DS-* |
| Rollback summary | EV-10 · P1-09 · OD-ROLLBACK · OD-CERT |
| Cert summary | P1-13 · OD-CERT |
| Exec contract links | Via pack binding P1-02 |
| OD citations | Register OWNER_APPROVED |

---

## 12. Out of scope

| Item | Deferred |
|------|----------|
| Schema re-cert cycle after invalidation | WP-P2-06 |
| P2 integration freeze | WP-P2-07 |
| Owner enablement order schema (beyond pointer) | P1-13 §6.4 / P9 |
| Live UI implementation | Coding auth later |
| Enablement flag true | P9 |

---

## 13. Acceptance criteria (WP-P2-05)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Owner Submission package structure defined | **PASS** §5 |
| AC2 | Executive summary defined | **PASS** §6.1 |
| AC3 | Certification summary defined | **PASS** §6.2 |
| AC4 | Evidence summary defined | **PASS** §6.3 |
| AC5 | Checklist summary defined | **PASS** §6.4 |
| AC6 | Drill results summary defined | **PASS** §6.5 |
| AC7 | Verification summary defined | **PASS** §6.6 |
| AC8 | Rollback evidence summary defined | **PASS** §6.7 |
| AC9 | Outstanding issues section defined | **PASS** §6.8 |
| AC10 | PASS recommendation defined (Engineering) | **PASS** §7.2 |
| AC11 | FAIL recommendation defined (Engineering) | **PASS** §7.3 |
| AC12 | Engineering findings vs Owner decision clearly separated | **PASS** §3, §7, §8 |
| AC13 | Engineering may recommend; never grant Cert PASS | **PASS** H1–H3, §7 |
| AC14 | Owner sole PASS/FAIL authority; decision schema defined | **PASS** §8 |
| AC15 | Every recommendation requires supporting evidence refs | **PASS** H5, §7.1, OS-03 |
| AC16 | Enablement FALSE; no code; Architecture/ODs unmodified | **PASS** H6, H8 |

---

## 14. Stop rule

**WP-P2-05 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P2-06 until Owner review and approval.

---

*End of WP-P2-05 — Owner Submission & PASS/FAIL Decision Package.*
