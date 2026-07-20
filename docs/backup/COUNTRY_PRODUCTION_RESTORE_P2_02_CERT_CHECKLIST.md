# Country Production Restore — P2 Certification Checklist

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P2-02** — Certification Checklist (Machine + Owner Human Review) |
| **Artifact-ID** | `CPR-P2-WP02-CERT_CHECKLIST` |
| **Status** | COMPLETE (certification design only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P2-01; authorized WP-P2-02 |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P2_ARTIFACT_INDEX.md` (WP-P2-01) |
| **P1 hooks** | `CPR-P1-WP13-ENABLEMENT_CERT_HOOKS` · `CPR-P1-WP08-PRE_PONR_GATES` · `CPR-P1-WP11-*` · `CPR-P1-WP09-*` |
| **Coding** | **No** — design checklist only; no PHP/SQL/CLI/HTTP/UI |
| **Enablement** | Remains **FALSE** (OD-ENABLE) |

---

## 1. Purpose

Define the **complete Country Production Restore Certification Checklist**: mandatory certification gates, evidence validation rules, and PASS/FAIL evaluation rules for Owner Cert decision (OD-CERT).

This WP:

- Consumes EV-01…EV-14 from WP-P2-01 §8 without redefining the catalog.  
- Consumes `cpr_certification_result` and lifecycle states from P1-13 without change.  
- Distinguishes **Engineering evidence evaluation** from **Owner final PASS/FAIL**.  
- Does **not** modify Architecture, Owner Decisions, P1 artifacts, or flip enablement.  
- Does **not** define drill scenarios (WP-P2-03) or pack assembly schemas (WP-P2-04) beyond checklist references.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Owner alone grants Cert PASS/FAIL; Engineering never grants final PASS | OD-CERT §15 Frozen |
| H2 | Engineering produces evidence, reports, artifacts only | OD-CERT · P1-13 §5.3 |
| H3 | Enablement flag remains **FALSE** throughout certification design and until OD-ENABLE path | OD-ENABLE |
| H4 | Fail-closed: missing proof / unknown / error → item **FAIL** (not skip) | Integrity Principle · P1-08 |
| H5 | **No** waiver, Continue Anyway, or Engineering self-PASS | OD-C8 · OD-CERT · Integrity |
| H6 | C8 must be **SAFE** only — WARNING is Cert FAIL | OD-C8 |
| H7 | Rollback drill proof (EV-10) is **mandatory** | OD-CERT · WP-P2-01 §8 |
| H8 | Every checklist item maps to ≥1 OWNER_APPROVED OD | WP-P2-01 hard rules |
| H9 | Every EV-01…EV-14 must be referenced by ≥1 checklist item | WP-P2-01 §8 |
| H10 | Reject `cpr_certification_result` with `result=PASS` and `decided_by != owner` | P1-13 §5.2 |
| H11 | Schema revision mismatch / prior cert invalidated → Cert FAIL / restart cycle | OD-SCHEMA |
| H12 | No architecture redesign; no OD reopen; no mutation code in this WP | P2 Execution Authorization |

---

## 3. Evaluation model

### 3.1 Item result enum

| Result | Meaning | Who may set |
|--------|---------|-------------|
| `PASS` | Predicate proven true from durable evidence | Engineering evaluator (evidence items); **Owner only** for final Cert |
| `FAIL` | Predicate false, proof missing, drift, or error | Engineering evaluator or Owner |
| `PENDING` | Not yet evaluated | Default before evaluation |
| `SKIP` | **Forbidden** for all mandatory items | — |

### 3.2 Layers

| Layer | ID prefix | Purpose |
|-------|-----------|---------|
| **L0 — Submission gates** | CG-S* | Must PASS before Engineering may submit pack to Owner |
| **L1 — Machine evidence gates** | CG-M* | Fail-closed technical checklist over EV-* |
| **L2 — Owner human review** | CG-H* | Owner-only judgment items; Engineering cannot mark PASS |
| **L3 — Final Cert decision** | CG-F* | Owner PASS/FAIL on `cpr_certification_result` |

### 3.3 Aggregate rules (Engineering evidence readiness)

1. Evaluate all L0 then L1 items.  
2. On first FAIL, record it; **continue** remaining items for diagnostics; aggregate remains FAIL.  
3. Aggregate **Evidence Ready** = every mandatory L0+L1 item is `PASS`.  
4. Evidence Ready **does not** equal Cert PASS.  
5. Absent evidence ref → FAIL (`cert_evidence_missing`).  
6. Exception/timeout reading evidence → FAIL (`cert_eval_error`).  
7. Any SKIP / waiver knob → reject evaluation configuration.

### 3.4 Aggregate rules (Owner Cert)

1. Owner may decide only when lifecycle is `cert_submitted_for_owner` and L0+L1 Evidence Ready = true (unless Owner explicitly FAILs earlier for governance reasons).  
2. Owner reviews all L2 items (human).  
3. **Cert PASS** requires: Evidence Ready; all L2 Owner items accepted; CG-F01 PASS with `decided_by=owner`; `result=PASS`; schema/package bound; enablement still false (CG-M13).  
4. **Cert FAIL** if Owner sets `result=FAIL`, or any mandatory L0/L1 remains FAIL at submission, or H10 reject conditions, or EV-10 absent.  
5. Engineering cannot transition to `cert_pass`.

### 3.5 Relationship to enablement

| Fact | Rule |
|------|------|
| Cert PASS | Necessary but **not sufficient** for enablement |
| Enablement | Still requires Owner enablement order + implementation completed + Final Enterprise approval + Super Admin Enable from E5 | OD-ENABLE · P1-13 §6 |
| This checklist | **Never** sets flag true |

---

## 4. Evidence validation rules (global)

Apply to every EV-* referenced by checklist items:

| Rule-ID | Rule |
|---------|------|
| VR-01 | Evidence artifact id present in `evidence_pack_refs` for the `package_cycle_id` |
| VR-02 | Artifact hash/fingerprint recorded and re-hash matches at evaluation (fail-closed on mismatch) |
| VR-03 | Producer role = Engineering (or system drill runner under Engineering custody) — never Owner-as-evidence-forger |
| VR-04 | Bound `schema_revision_bound` matches live production schema revision under certification |
| VR-05 | Bound `package_cycle_id` / package id consistent across EV-03, EV-04, EV-08, EV-09, EV-12 |
| VR-06 | C8 evidence (EV-03) must show `overall_result = SAFE` only — WARNING/unsafe → FAIL |
| VR-07 | Drill evidence timestamps within the certification cycle window; no reuse of prior invalidated cycle artifacts without new package |
| VR-08 | Rollback evidence (EV-10) must demonstrate SA Resume/Rollback path and **never automatic** rollback/resume |
| VR-09 | Enablement attestation (EV-13) must show flag `false` for entire cycle |
| VR-10 | No production mutation claimed as “cert complete” while flag false and engines unauthorized — drill context must be labeled non-production / clone / simulation per later WP-P2-03 |
| VR-11 | Missing, corrupt, or unsigned sealed evidence → FAIL |
| VR-12 | N/A / waiver without Owner escalation record → FAIL (default: no waiver) |

---

## 5. Mandatory certification gates — complete checklist

Legend: **Type** = `M` machine / evidence · `H` Owner human · `S` submission · `F` final.  
**Eval** = who records item result for that layer (Owner alone for H/F PASS).

### 5.1 L0 — Submission gates (CG-S)

#### CG-S01 — Package cycle identity present

| Field | Value |
|-------|-------|
| Predicate | `package_cycle_id` and `certification_id` assigned; `cpr_certification_result` exists with `result=PENDING` |
| Evidence | EV-14 (draft), EV-01 |
| OD | OD-CERT |
| P1 | P1-13 §5.2 |
| Fail codes | `cert_cycle_identity_missing` |
| Type | S |

#### CG-S02 — All EV-01…EV-14 refs present

| Field | Value |
|-------|-------|
| Predicate | `evidence_pack_refs` contains durable refs for **every** EV-01 through EV-14 |
| Evidence | EV-01…EV-14 |
| OD | OD-CERT |
| P1 | P1-13 §5.2; WP-P2-01 §8 |
| Fail codes | `cert_evidence_catalog_incomplete` |
| Type | S |

#### CG-S03 — Schema revision bound

| Field | Value |
|-------|-------|
| Predicate | `schema_revision_bound` set and equals live production schema revision; no `cert_invalidated` for this cycle |
| Evidence | EV-12 |
| OD | OD-SCHEMA · OD-FA-SCHEMA |
| P1 | P1-13 §8; P1-08 G-FA-SCHEMA / G19 |
| Fail codes | `cert_schema_mismatch` · `cert_invalidated` |
| Type | S |

#### CG-S04 — Enablement still false (submission)

| Field | Value |
|-------|-------|
| Predicate | `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED` (or successor) is `false`; no enable action in cycle |
| Evidence | EV-13 |
| OD | OD-ENABLE |
| P1 | P1-13 §3; P1-08 G01 design note |
| Fail codes | `cert_enablement_not_false` |
| Type | S |

#### CG-S05 — Engineering submitter recorded; cannot set PASS

| Field | Value |
|-------|-------|
| Predicate | `engineering_submitter_id` set; `result` remains `PENDING` until Owner; `engineering_cannot_grant_pass=true` |
| Evidence | EV-14 |
| OD | OD-CERT · OD-PERM |
| P1 | P1-13 §5.2–§5.3 |
| Fail codes | `cert_eng_pass_attempt` · `cert_submitter_missing` |
| Type | S |

---

### 5.2 L1 — Machine / evidence gates (CG-M)

#### CG-M01 — Policy & baseline freeze (EV-01)

| Field | Value |
|-------|-------|
| Predicate | Register SoT, Architecture baseline tag `P0-P0b-Final`, P1 tag `P1-Design-Baseline` attested unchanged for cycle; no silent OD reopen |
| Evidence | EV-01 |
| OD | OD-CERT (governance) · Integrity Principle |
| Validation | VR-01, VR-02, VR-07 |
| Fail codes | `cert_baseline_drift` |
| Type | M |

#### CG-M02 — Certified inventory (EV-02)

| Field | Value |
|-------|-------|
| Predicate | OD-INV certified immutable production inventory present; `certified_read_only=true`; live DB may verify only — never replace snapshot |
| Evidence | EV-02 |
| OD | OD-INV |
| P1 | P1-08 G21 / inventory gates |
| Validation | VR-01, VR-02, VR-04 |
| Fail codes | `cert_inventory_missing` · `cert_inventory_not_readonly` |
| Type | M |

#### CG-M03 — C8 SAFE only (EV-03)

| Field | Value |
|-------|-------|
| Predicate | C8 `overall_result = SAFE`; survivor/global/JE impacts `0` as Architecture §37.16–18; `simulation_only=true`; `execution_performed=false`; **no WARNING waiver** |
| Evidence | EV-03 (`c8_safe_evidence_ref`) |
| OD | OD-C8 |
| P1 | P1-08 G16–G18; P1-13 `c8_safe_evidence_ref` |
| Validation | VR-01, VR-02, VR-06 |
| Fail codes | `cert_c8_not_safe` · `cert_c8_warning` |
| Type | M |

#### CG-M04 — Pre-PONR gate suite design proof (EV-04)

| Field | Value |
|-------|-------|
| Predicate | Evidence shows pre-PONR suite G01–G30 contracts are implemented/exercised in drill design results per P1-08; fail-closed; no SKIP; **note:** while enablement false, G01 FAIL for live PONR is expected — certification records that posture plus other gates’ drill results |
| Evidence | EV-04 |
| OD | OD-ENABLE · OD-C8 · OD-PIN · OD-LOCK-* · OD-INV · OD-DUAL · OD-PHRASE · OD-RUNBOOK · OD-FA-* |
| P1 | P1-08 entire suite |
| Validation | VR-01, VR-02, VR-05 |
| Fail codes | `cert_pre_ponr_suite_incomplete` |
| Type | M |

#### CG-M05 — Dual-control / authority path (EV-05)

| Field | Value |
|-------|-------|
| Predicate | WF-A protections **or** WF-B Super Admin approval path demonstrated; no dual-Super-Admin; no waiver model; Country Admin cannot execute/enable |
| Evidence | EV-05 |
| OD | OD-DUAL · OD-PERM · OD-BREAK |
| P1 | P1-06; P1-08 G02/G24 |
| Validation | VR-01, VR-02 |
| Fail codes | `cert_dual_path_unproven` |
| Type | M |

#### CG-M06 — Maintenance / duration / timeout (EV-06)

| Field | Value |
|-------|-------|
| Predicate | GLOBAL maint mandatory before PONR; OD-MAINT-SCOPE GLOBAL; max duration / RTO / phase timeouts honored in drill evidence |
| Evidence | EV-06 |
| OD | OD-MAINT · OD-MAINT-SCOPE · OD-MAINT-MAX · OD-RTO · OD-TIMEOUT |
| P1 | P1-07; P1-08 G22 |
| Validation | VR-01, VR-02 |
| Fail codes | `cert_maint_posture_fail` |
| Type | M |

#### CG-M07 — Locks / cross-feature exclusion (EV-07)

| Field | Value |
|-------|-------|
| Predicate | CROSS vs Full DR, SHADOW vs C6, TTL/heartbeat contracts exercised; no concurrent Full DR/C6 during CPR drill critical sections |
| Evidence | EV-07 |
| OD | OD-LOCK-CROSS · OD-LOCK-SHADOW · OD-LOCK-TTL |
| P1 | P1-05; P1-08 G05 |
| Validation | VR-01, VR-02 |
| Fail codes | `cert_lock_exclusion_fail` |
| Type | M |

#### CG-M08 — Apply path scoped DB + uploads (EV-08)

| Field | Value |
|-------|-------|
| Predicate | Drill evidence of target-slice delete/import and scoped uploads apply under flags in **non-production/clone** context; production_touched false where required by C6/C8 rules |
| Evidence | EV-08 |
| OD | OD-UPLOADS · (boundary C1.1 D1–D6 frozen inputs) |
| P1 | P1-10 |
| Validation | VR-01, VR-02, VR-05, VR-10 |
| Fail codes | `cert_apply_path_unproven` |
| Type | M |

#### CG-M09 — Post-apply verify pack (EV-09)

| Field | Value |
|-------|-------|
| Predicate | Post-apply verify & report schemas satisfied; OD-VERIFY-WARN handling per frozen policy (no silent ignore of required fails) |
| Evidence | EV-09 |
| OD | OD-VERIFY-WARN · OD-FA-STOCK · OD-FA-SCHEMA · OD-FA-RESOLVER |
| P1 | P1-11 |
| Validation | VR-01, VR-02, VR-05 |
| Fail codes | `cert_post_verify_fail` |
| Type | M |

#### CG-M10 — Fail / Resume / Rollback proof (EV-10) — MANDATORY

| Field | Value |
|-------|-------|
| Predicate | Evidence of fail-pause; Super Admin Resume **or** Rollback only; **never automatic**; rollback path proven for Cert (OD-CERT consequences) |
| Evidence | EV-10 |
| OD | OD-FAIL-DELETE · OD-FAIL-IMPORT · OD-ROLLBACK · OD-CERT · OD-PIN |
| P1 | P1-09 |
| Validation | VR-01, VR-02, VR-08 |
| Fail codes | `cert_rollback_unproven` · `cert_auto_resume_or_rollback` |
| Type | M |

#### CG-M11 — Audit / metrics / alerts (EV-11)

| Field | Value |
|-------|-------|
| Predicate | Audit trail and alert hooks captured for drill runs (enable/disable/cert/schema events as designed) |
| Evidence | EV-11 |
| OD | OD-PERM · OD-RUNBOOK · OD-SCHEMA (audit expectations) |
| P1 | P1-12 |
| Validation | VR-01, VR-02 |
| Fail codes | `cert_audit_capture_fail` |
| Type | M |

#### CG-M12 — Schema binding & invalidation awareness (EV-12)

| Field | Value |
|-------|-------|
| Predicate | Cert bound to exact revision; evidence states OD-SCHEMA invalidation → force cert_invalidated, flag false, no auto re-enable, full re-cert + new C8 SAFE required |
| Evidence | EV-12 |
| OD | OD-SCHEMA · OD-FA-SCHEMA |
| P1 | P1-13 §8 |
| Validation | VR-01, VR-04, VR-05 |
| Fail codes | `cert_schema_binding_fail` |
| Type | M |

#### CG-M13 — Enablement disabled attestation (EV-13)

| Field | Value |
|-------|-------|
| Predicate | Flag false for entire certification cycle; `auto_enable_forbidden=true`; no SA Enable without E5 (E5 not claimed during P2 design) |
| Evidence | EV-13 |
| OD | OD-ENABLE · OD-PERM |
| P1 | P1-13 §3, §6–§7 |
| Validation | VR-01, VR-09 |
| Fail codes | `cert_enablement_attestation_fail` |
| Type | M |

#### CG-M14 — Phrase / re-auth design proof

| Field | Value |
|-------|-------|
| Predicate | Evidence that execution path requires Super Admin password re-auth + phrase **`RESTORE`** (OD-PHRASE); no alternate phrase |
| Evidence | EV-05 (authority), EV-04 (G29) |
| OD | OD-PHRASE |
| P1 | P1-06; P1-08 G29 |
| Validation | VR-01, VR-02 |
| Fail codes | `cert_phrase_path_unproven` |
| Type | M |

#### CG-M15 — PIN order design proof

| Field | Value |
|-------|-------|
| Predicate | Evidence that session Full Backup order is Maint ON → **NEW** Full Backup → verify → pin; existing backups never reused |
| Evidence | EV-04, EV-06, EV-10 |
| OD | OD-PIN |
| P1 | P1-04; P1-08 G03/G23 |
| Validation | VR-01, VR-02 |
| Fail codes | `cert_pin_order_unproven` |
| Type | M |

#### CG-M16 — Runbook pre-PONR checklist design proof

| Field | Value |
|-------|-------|
| Predicate | OD-RUNBOOK Super Admin pre-PONR human sign-off checklist exists in design/drill evidence and is audited |
| Evidence | EV-04, EV-11, EV-14 |
| OD | OD-RUNBOOK |
| P1 | P1-06; P1-08 G27 |
| Validation | VR-01, VR-02 |
| Fail codes | `cert_runbook_unproven` |
| Type | M |

#### CG-M17 — CRP chain C4–C8 package integrity (subset of EV-03/EV-04)

| Field | Value |
|-------|-------|
| Predicate | Package finalized; C4 PASS; C5 pass + score≥85; C6 ready/production_touched=false; C7 READY + pillars; C8 SAFE; fingerprints stable — as proven in certification evidence (Architecture §37.7–19) |
| Evidence | EV-03, EV-04 |
| OD | OD-C8 · OD-FA-SCHEMA · OD-FA-STOCK · OD-FA-RESOLVER |
| P1 | P1-08 G07–G19 |
| Validation | VR-01, VR-02, VR-05, VR-06 |
| Fail codes | `cert_crp_chain_fail` |
| Type | M |

---

### 5.3 L2 — Owner human review (CG-H)

Engineering may attach notes; **only Owner** may mark these PASS/accepted.

#### CG-H01 — Evidence pack completeness review

| Field | Value |
|-------|-------|
| Predicate | Owner confirms EV-01…EV-14 pack is complete and intelligible for decision |
| Evidence | EV-14 + all EV-* |
| OD | OD-CERT |
| Fail codes | `owner_pack_incomplete` |
| Type | H |

#### CG-H02 — Rollback adequacy review

| Field | Value |
|-------|-------|
| Predicate | Owner accepts that rollback drill proof (EV-10) is adequate for production-risk posture |
| Evidence | EV-10 |
| OD | OD-CERT · OD-ROLLBACK |
| Fail codes | `owner_rollback_inadequate` |
| Type | H |

#### CG-H03 — Authority & dual-control adequacy

| Field | Value |
|-------|-------|
| Predicate | Owner accepts WF-A/B evidence and that Engineering/Country Admin cannot grant Cert PASS or Enable |
| Evidence | EV-05, EV-13 |
| OD | OD-DUAL · OD-PERM · OD-CERT · OD-ENABLE |
| Fail codes | `owner_authority_inadequate` |
| Type | H |

#### CG-H04 — Schema / invalidation posture accepted

| Field | Value |
|-------|-------|
| Predicate | Owner accepts OD-SCHEMA cycle binding and that schema change voids prior PASS with no auto re-enable |
| Evidence | EV-12 |
| OD | OD-SCHEMA · OD-ENABLE |
| Fail codes | `owner_schema_posture_rejected` |
| Type | H |

#### CG-H05 — Enablement remains gated

| Field | Value |
|-------|-------|
| Predicate | Owner acknowledges Cert PASS ≠ enablement; flag stays false until full OD-ENABLE path |
| Evidence | EV-13, EV-14 |
| OD | OD-ENABLE · OD-CERT |
| Fail codes | `owner_enablement_conflation` |
| Type | H |

#### CG-H06 — C8 SAFE / no-waiver posture accepted

| Field | Value |
|-------|-------|
| Predicate | Owner accepts SAFE-only entry; no WARNING waiver for certification |
| Evidence | EV-03 |
| OD | OD-C8 |
| Fail codes | `owner_c8_waiver_rejected` |
| Type | H |

---

### 5.4 L3 — Final Cert decision (CG-F)

#### CG-F01 — Owner Cert PASS/FAIL

| Field | Value |
|-------|-------|
| Predicate | Owner sets `cpr_certification_result.result` to `PASS` or `FAIL`; `decided_by=owner`; `decided_by_actor_id` + `decided_at` set; `owner_pass_mandatory=true`; `sealed=true` after decision |
| Evidence | EV-14 |
| OD | OD-CERT |
| P1 | P1-13 §5.2 |
| Fail codes | `cert_owner_decision_invalid` · `cert_eng_granted_pass` |
| Type | F |

**Reject rules (automatic FAIL of decision record):**

| Condition | Action |
|-----------|--------|
| `result=PASS` and `decided_by != owner` | Reject |
| `result=PASS` and any mandatory L0/L1 item not PASS | Reject |
| `result=PASS` and EV-10 missing/FAIL | Reject |
| `result=PASS` and EV-13 shows flag true | Reject |
| `result=PASS` and C8 not SAFE | Reject |
| Engineering sets `result=PASS` | Reject |

---

## 6. PASS / FAIL evaluation rules (summary)

### 6.1 Engineering — Evidence Ready

| Outcome | Condition |
|---------|-----------|
| **Evidence Ready = true** | All CG-S* and CG-M* = PASS |
| **Evidence Ready = false** | Any CG-S* or CG-M* = FAIL/PENDING/missing |
| May submit to Owner | Only if Evidence Ready = true → lifecycle `cert_submitted_for_owner` |
| May grant Cert PASS | **Never** |

### 6.2 Owner — Certification

| Outcome | Condition |
|---------|-----------|
| **Cert PASS** | Evidence Ready; all CG-H* accepted by Owner; CG-F01 PASS with Owner decision; H10/reject rules clear; schema bound; enablement false |
| **Cert FAIL** | Owner `result=FAIL`; **or** reject rules; **or** Owner declines any CG-H*; **or** mandatory evidence FAIL |
| **Cert INVALIDATED** | OD-SCHEMA (or explicit revoke) — not a success path; restart per P1-13 §8 / WP-P2-06 |

### 6.3 What Cert PASS does **not** authorize

| Not authorized by Cert PASS alone |
|-----------------------------------|
| Enablement flag true |
| Production PONR / delete / import |
| Super Admin Enable without Owner enablement order + other OD-ENABLE preconditions |
| Auto re-enable after schema change |
| C3–C8 engine modification |

---

## 7. Checklist → OD map (completeness)

| OD | Checklist items covering it |
|----|----------------------------|
| OD-ENABLE | CG-S04, CG-M13, CG-H05, CG-F01 (indirect), §3.5 |
| OD-DUAL | CG-M05, CG-H03 |
| OD-PHRASE | CG-M14 |
| OD-BREAK | CG-M05 |
| OD-PERM | CG-S05, CG-M05, CG-M13, CG-H03 |
| OD-CERT | CG-S01–S05, CG-M01, CG-M10, CG-H*, CG-F01, §6 |
| OD-MAINT | CG-M06 |
| OD-MAINT-SCOPE | CG-M06 |
| OD-MAINT-MAX | CG-M06 |
| OD-RTO | CG-M06 |
| OD-TIMEOUT | CG-M06 |
| OD-RUNBOOK | CG-M16 |
| OD-PIN | CG-M15, CG-M10 |
| OD-ROLLBACK | CG-M10, CG-H02 |
| OD-FAIL-DELETE | CG-M10 |
| OD-FAIL-IMPORT | CG-M10 |
| OD-C8 | CG-M03, CG-M17, CG-H06 |
| OD-VERIFY-WARN | CG-M09 |
| OD-SCHEMA | CG-S03, CG-M12, CG-H04 |
| OD-INV | CG-M02 |
| OD-UPLOADS | CG-M08 |
| OD-LOCK-CROSS | CG-M07 |
| OD-LOCK-SHADOW | CG-M07 |
| OD-LOCK-TTL | CG-M07 |
| OD-FA-RESOLVER | CG-M09, CG-M17 |
| OD-FA-STOCK | CG-M09, CG-M17 |
| OD-FA-SCHEMA | CG-S03, CG-M12, CG-M17 |

**Coverage statement:** Every OWNER_APPROVED OD listed in the Register catalog above is cited by ≥1 checklist item. Frozen C1.1 D1–D6 boundary inputs are consumed via CG-M08 / inventory gates (not reopened).

---

## 8. Checklist → Evidence map (EV-01…EV-14)

| Evidence-ID | Referenced by |
|-------------|---------------|
| EV-01 | CG-S01, CG-S02, CG-M01, CG-H01 |
| EV-02 | CG-S02, CG-M02, CG-H01 |
| EV-03 | CG-S02, CG-M03, CG-M17, CG-H01, CG-H06 |
| EV-04 | CG-S02, CG-M04, CG-M14, CG-M15, CG-M16, CG-M17, CG-H01 |
| EV-05 | CG-S02, CG-M05, CG-M14, CG-H01, CG-H03 |
| EV-06 | CG-S02, CG-M06, CG-M15, CG-H01 |
| EV-07 | CG-S02, CG-M07, CG-H01 |
| EV-08 | CG-S02, CG-M08, CG-H01 |
| EV-09 | CG-S02, CG-M09, CG-H01 |
| EV-10 | CG-S02, CG-M10, CG-M15, CG-H01, CG-H02, CG-F01 reject |
| EV-11 | CG-S02, CG-M11, CG-M16, CG-H01 |
| EV-12 | CG-S02, CG-S03, CG-M12, CG-H01, CG-H04 |
| EV-13 | CG-S02, CG-S04, CG-M13, CG-H01, CG-H03, CG-H05, CG-F01 reject |
| EV-14 | CG-S01, CG-S02, CG-S05, CG-M16, CG-H01, CG-H05, CG-F01 |

**Coverage statement:** Every EV-01…EV-14 is referenced. EV-10 is mandatory for Cert PASS.

---

## 9. Mandatory gate inventory (IDs only)

| Layer | Gate IDs |
|-------|----------|
| L0 Submission | CG-S01, CG-S02, CG-S03, CG-S04, CG-S05 |
| L1 Machine | CG-M01 … CG-M17 |
| L2 Owner human | CG-H01 … CG-H06 |
| L3 Final | CG-F01 |
| **Total mandatory** | **29** gates/items |

All are mandatory. SKIP forbidden.

---

## 10. Authority split (binding restatement)

| Action | Engineering | Owner | Super Admin | Country Admin |
|--------|:-----------:|:-----:|:-----------:|:-------------:|
| Produce EV-* evidence | Yes | No | Assist ops only | No |
| Mark CG-M* / CG-S* | Yes (evidence eval) | May review | No Cert authority | No |
| Mark CG-H* accepted | No | **Yes** | No | No |
| Set `result=PASS/FAIL` | **No** | **Yes** | No | No |
| Flip enablement | No | Order only (later) | Operational after E5 (later) | **No** |

---

## 11. Out of scope (this WP)

| Item | Deferred |
|------|----------|
| Drill scenario catalog | WP-P2-03 |
| Evidence pack assembly JSON schemas | WP-P2-04 |
| Owner decision package UX/process detail | WP-P2-05 |
| Schema re-cert cycle procedure narrative | WP-P2-06 |
| P2 integration freeze | WP-P2-07 |
| Live drills / Cert ceremony | P7–P8 |
| Enablement | P9 |
| Mutation engine code | P3+ |

---

## 12. Acceptance criteria (WP-P2-02)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Complete certification checklist document exists with Artifact-ID | **PASS** |
| AC2 | All mandatory certification gates defined (L0–L3; §9 inventory) | **PASS** — 29 items |
| AC3 | Evidence validation rules defined (VR-01…VR-12) | **PASS** §4 |
| AC4 | PASS/FAIL evaluation rules defined for Evidence Ready and Owner Cert | **PASS** §3, §6 |
| AC5 | Every checklist item maps to ≥1 OWNER_APPROVED OD | **PASS** §5 + §7 |
| AC6 | Every EV-01…EV-14 referenced | **PASS** §8 |
| AC7 | Authority split preserved (Eng evidence only; Owner PASS only) | **PASS** §10, H1–H2 |
| AC8 | Enablement remains FALSE; no enablement flip in checklist | **PASS** H3, CG-S04, CG-M13, §3.5 |
| AC9 | No implementation code; Architecture and Owner Decisions unmodified | **PASS** |
| AC10 | Rollback (EV-10 / CG-M10) mandatory for Cert PASS | **PASS** H7, §6.2 |

---

## 13. Stop rule

**WP-P2-02 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P2-03 until Owner review and approval.

---

*End of WP-P2-02 — Certification Checklist.*
