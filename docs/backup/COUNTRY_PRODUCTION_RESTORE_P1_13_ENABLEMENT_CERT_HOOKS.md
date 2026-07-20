# Country Production Restore — P1 Enablement & Certification Interface Contracts (Hooks Only)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-13** — Enablement & Certification Interface Contracts (Hooks Only) |
| **Artifact-ID** | `CPR-P1-WP13-ENABLEMENT_CERT_HOOKS` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-ENABLE · OD-CERT · OD-SCHEMA · OD-PERM) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §3 enablement, §26–§27, §36–§37.1, roadmap P2/P8/P9 |
| **Depends on** | WP-P1-06 · WP-P1-08 |
| **Coding** | **No** — hooks/interfaces only; does **not** flip enablement, run cert program, or implement P2/P8/P9 |

---

## 1. Purpose

Define **interface contracts** (state machine, record schemas, invalidation checklist, Enable/Disable action contract) for OD-ENABLE / OD-CERT / OD-SCHEMA / OD-PERM so later phases cannot accidentally enable CPR, let Engineering grant Cert PASS, or auto-re-enable after schema change.

This WP does **not** implement the certification program (P2), drills (P8), or production enablement flip (P9). It does **not** modify Architecture, Owner Decisions, or prior P1 artifacts.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Enablement flag remains **FALSE by default** | OD-ENABLE |
| H2 | Flag may become true **only after all**: Certification PASS; explicit Owner enablement order; implementation completed; Final Enterprise approval | OD-ENABLE |
| H3 | Owner is final **PASS/FAIL** for Certification; Engineering **never** grants final Cert PASS | OD-CERT |
| H4 | Super Admin Enable/Disable is **operational only** after Owner authorization / OD-ENABLE preconditions (Enable) | OD-PERM · OD-ENABLE |
| H5 | Any Production Schema Revision change **invalidates** prior CPR certification | OD-SCHEMA |
| H6 | After schema invalidation, mandatory: package rebuild; new Certification; new C8 SAFE; then Owner PASS + explicit Enable again | OD-SCHEMA |
| H7 | **Auto re-enable is permanently forbidden** | OD-SCHEMA · OD-ENABLE |
| H8 | Country Admin must **never** enable/disable CPR | OD-PERM · WP-P1-06 |
| H9 | This WP does not flip the flag or claim production readiness | P1 plan · Architecture hard non-goals |

---

## 3. Flag & configuration interface

| Item | Contract |
|------|----------|
| Flag name (design) | `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED` (or successor `country_production_restore_enabled`) |
| Type | Boolean |
| Default | **`false`** (hard) |
| Storage | Server/local config only — never committed secrets; design forbids shipping `true` in repo defaults |
| Read semantics | WP-P1-08 G01: PONR/`pre_ponr_full` FAIL while false |
| Write semantics | Only via §7 Enable/Disable interface after preconditions |

**P1 design posture:** contracts and evaluators treat the flag as **false** until a future Owner-authorized enablement phase (P9) completes the §6 path.

---

## 4. Roles (hooks)

| Role | May | Must not |
|------|-----|----------|
| **Engineering** | Produce technical evidence, verification reports, certification **artifacts** | Grant Cert PASS/FAIL; flip enablement; auto-enable |
| **Owner** | Final Cert PASS/FAIL; issue explicit enablement order; re-authorize after OD-SCHEMA | Day-to-day substitute for Super Admin execute (OD-PERM executor remains SA) |
| **Super Admin** | Operational Enable/Disable **after** Owner path; Disable anytime as operational stop | Grant Cert PASS; Enable without Owner order + other OD-ENABLE preconditions |
| **Country Admin** | — | Enable/Disable/Cert PASS |

---

## 5. Certification interface (OD-CERT)

### 5.1 Certification lifecycle (interface states)

| State | Meaning |
|-------|---------|
| `cert_absent` | No certification record for current schema cycle |
| `cert_evidence_in_progress` | Engineering assembling evidence pack |
| `cert_submitted_for_owner` | Evidence ready; awaiting Owner decision |
| `cert_pass` | Owner granted PASS for bound schema/package cycle |
| `cert_fail` | Owner granted FAIL |
| `cert_invalidated` | OD-SCHEMA (or explicit revoke) voided prior PASS |

### 5.2 Certification result schema (`cpr_certification_result`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_certification_result/1"` |
| `certification_id` | Y | UUID |
| `schema_revision_bound` | Y | Production schema revision this cert covers |
| `package_cycle_id` | Y | Evidence pack / package generation id |
| `c8_safe_evidence_ref` | Y | Proof C8 SAFE exists for cycle |
| `evidence_pack_refs` | Y | Array of artifact ids (Engineering-produced) |
| `result` | Y | `PASS` \| `FAIL` \| `INVALIDATED` \| `PENDING` |
| `decided_by` | Y* | **Must be `owner`** when result is PASS or FAIL — never `engineering` |
| `decided_by_actor_id` | Y* | Owner actor id when decided |
| `decided_at` | Y* | When PASS/FAIL/INVALIDATED |
| `engineering_submitter_id` | N | Who submitted evidence — **cannot** set `result=PASS` |
| `owner_pass_mandatory` | Y | Const `true` |
| `engineering_cannot_grant_pass` | Y | Const `true` |
| `invalidation_ref` | N | Link to §8 event if invalidated |
| `sealed` | Y | Boolean |

**Reject** any record where `result=PASS` and `decided_by != owner`.

### 5.3 Engineering vs Owner split

| Artifact | Producer | Final authority |
|----------|----------|-----------------|
| Drill reports, verify packs, evidence zip | Engineering | — |
| Certification PASS/FAIL | — | **Owner only** |

---

## 6. Enablement state machine (OD-ENABLE)

### 6.1 States

| State | Flag | Meaning |
|-------|------|---------|
| `E0_disabled_default` | `false` | Initial / permanent default until full path |
| `E1_impl_incomplete` | `false` | Implementation not marked complete |
| `E2_awaiting_cert_pass` | `false` | Need Owner Cert PASS |
| `E3_awaiting_owner_enable_order` | `false` | Cert PASS exists; need Owner enablement order |
| `E4_awaiting_enterprise_approval` | `false` | Need Final Enterprise approval record |
| `E5_preconditions_satisfied` | `false` | All four preconditions true — flag still false until SA Enable |
| `E6_enabled` | `true` | Super Admin operational Enable completed |
| `E7_disabled_operational` | `false` | Super Admin Disable (or schema invalidation force-disable) |
| `E8_schema_invalidated` | `false` | OD-SCHEMA cycle — must restart cert/enable path |

### 6.2 Preconditions record (`cpr_enablement_preconditions`)

All four must be `true` before Super Admin may Enable:

| Field | Required | Rule |
|-------|:--------:|------|
| `schema_version` | Y | `"cpr_enablement_preconditions/1"` |
| `certification_pass` | Y | `cpr_certification_result.result == PASS` for current schema revision |
| `certification_id` | Y | |
| `owner_enablement_order` | Y | Explicit Owner order present |
| `owner_enablement_order_id` | Y | |
| `implementation_completed` | Y | Boolean — CPR implementation marked complete (phase gate) |
| `final_enterprise_approval` | Y | Boolean |
| `final_enterprise_approval_id` | Y | |
| `schema_revision_bound` | Y | Must match live + cert |
| `all_preconditions_met` | Y | AND of the four OWNER_APPROVED requirements |
| `auto_enable_forbidden` | Y | Const `true` |

### 6.3 Transitions

```
E0_disabled_default
  → E1_impl_incomplete          # default until impl complete recorded
  → E2_awaiting_cert_pass       # when impl complete, cert not PASS
  → E3_awaiting_owner_enable_order  # when cert PASS
  → E4_awaiting_enterprise_approval # when owner order present
  → E5_preconditions_satisfied  # all four true; flag still false
  → E6_enabled                  # Super Admin Enable action ONLY from E5
  → E7_disabled_operational     # Super Admin Disable from E6
  → E8_schema_invalidated       # from E6/E5/E7 on schema revision change
  → E2_awaiting_cert_pass       # from E8 after force flag false (restart cycle)
```

**Forbidden transitions:**

| Transition | Status |
|------------|--------|
| Any → `E6_enabled` without `all_preconditions_met` | **Forbidden** |
| `E5` → `E6` automatic (no SA action) | **Forbidden** (no auto-enable) |
| Engineering sets flag true | **Forbidden** |
| Country Admin Enable | **Forbidden** |
| Cert PASS by Engineering | **Forbidden** |
| Schema rebuild/re-cert/C8 SAFE → auto `E6` | **Forbidden** |

### 6.4 Owner enablement order schema (`cpr_owner_enablement_order`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `order_id` | Y | UUID |
| `issued_by` | Y | `owner` |
| `issued_at` | Y | |
| `schema_revision_bound` | Y | |
| `certification_id` | Y | Must be PASS |
| `directive` | Y | `ENABLE_COUNTRY_PRODUCTION_RESTORE` |
| `sealed` | Y | |

---

## 7. Enable / Disable interface contract (OD-PERM)

### 7.1 Super Admin Enable

| Rule | Value |
|------|-------|
| Actor | Super Admin alone |
| Allowed from | `E5_preconditions_satisfied` only |
| Preconditions | §6.2 all true; cert PASS; Owner order; impl complete; enterprise approval; schema revision match |
| Effect | Set flag `true` → `E6_enabled` |
| Audit | `cpr.enable` (WP-P1-12) |
| Country Admin | Denied |

### 7.2 Super Admin Disable

| Rule | Value |
|------|-------|
| Actor | Super Admin alone |
| Allowed from | `E6_enabled` (and may force-disable on incident) |
| Effect | Set flag `false` → `E7_disabled_operational` |
| Re-enable | Requires return to `E5` path — **not** automatic; if cert still valid and Owner order still covers revision, SA Enable from E5 again; if schema invalidated, full §8 cycle |
| Audit | `cpr.disable` |

### 7.3 Enable/Disable request schema (`cpr_enablement_action`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `action_id` | Y | |
| `action` | Y | `enable` \| `disable` |
| `actor_admin_id` | Y | Super Admin |
| `actor_role` | Y | Must be `super_admin` |
| `at` | Y | |
| `preconditions_snapshot_ref` | Y* | Required for `enable` |
| `all_preconditions_met` | Y* | Must be `true` for enable |
| `owner_enablement_order_id` | Y* | Required for enable |
| `certification_id` | Y* | Required for enable; result PASS |
| `flag_before` | Y | |
| `flag_after` | Y | enable→true; disable→false |
| `automatic` | Y | **Must be `false`** |
| `audit_record_id` | Y | |

**Reject enable** if `automatic=true` or any precondition false or actor not Super Admin.

---

## 8. Schema invalidation contract (OD-SCHEMA)

### 8.1 Trigger

Live production `schema_revision` changes from the revision bound to the current `cpr_certification_result` / enablement cycle (including leaving a previously certified revision such as historical “121” examples — any change).

### 8.2 Immediate effects (normative)

1. Prior certification → `cert_invalidated`.  
2. Enablement state → `E8_schema_invalidated`.  
3. Flag forced **`false`** if it was true.  
4. **No** auto re-enable.  
5. Emit audit + alert (WP-P1-12) with contract/schema refs.  
6. WP-P1-08 schema gates FAIL until new cycle completes.

### 8.3 Mandatory re-authorization checklist

Before CPR may be used again:

| Step | Required | Notes |
|------|:--------:|-------|
| Package rebuild | Y | New package for new schema |
| New Certification | Y | New `cpr_certification_result` cycle |
| New C8 SAFE | Y | Re-run; SAFE only (OD-C8) |
| Owner reviews new certification | Y | |
| Owner grants PASS | Y | OD-CERT |
| Owner explicitly Enable again (new enablement order) | Y | OD-ENABLE / OD-SCHEMA |
| Super Admin operational Enable | Y | Only after new `E5` |

Successful completion of rebuild / new cert / new C8 SAFE **does NOT** auto-enable (OD-SCHEMA frozen wording).

### 8.4 Schema invalidation event schema (`cpr_schema_invalidation_event`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `event_id` | Y | |
| `detected_at` | Y | |
| `schema_revision_previous` | Y | |
| `schema_revision_current` | Y | |
| `prior_certification_id` | Y | |
| `prior_certification_invalidated` | Y | `true` |
| `flag_forced_false` | Y | `true` |
| `auto_reenable` | Y | **Must be `false`** |
| `checklist` | Y | Object of §8.3 steps with `done` booleans (initially false) |
| `enablement_state` | Y | `E8_schema_invalidated` |
| `audit_record_id` | Y | |

---

## 9. Binding to WP-P1-08 / WP-P1-06

| Gate / rule | Hook |
|-------------|------|
| G01 enablement | Reads flag + preconditions; FAIL while false |
| G04 certification PASS | Requires `cpr_certification_result` PASS for current revision |
| G-FA-SCHEMA / G19 | Schema mismatch / invalidation → FAIL |
| OD-PERM matrix | Enable/Disable Super Admin only; Cert PASS Owner only |

---

## 10. Register / Architecture citation map

| Contract | OD | Frozen wording locus | Architecture |
|----------|-----|----------------------|--------------|
| Disabled default; four preconditions | OD-ENABLE | §15 Frozen | §37.1, enablement rows |
| Owner Cert PASS/FAIL; Eng never final | OD-CERT | §15 Frozen | §26–§27 |
| SA Enable/Disable operational | OD-PERM | §15 Frozen | §27 |
| Schema invalidates; rebuild+recert+C8; no auto re-enable; Owner PASS+Enable again | OD-SCHEMA | §15 Frozen | §36, §37 |

---

## 11. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Contracts keep flag false by default | **PASS** — H1; §3; E0 |
| Engineering cannot grant Cert PASS | **PASS** — H3; §5.2–§5.3 |
| Schema change cannot auto-enable | **PASS** — H7; §8.2–§8.3 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| Enablement state machine | **PASS** — §6 |
| Certification result schema | **PASS** — §5.2 |
| Schema invalidation contract + checklist | **PASS** — §8 |
| Enable/Disable interface | **PASS** — §7 |
| Owner PASS + Owner Enablement Order mandatory | **PASS** — H2–H3; §6.2 |
| Design only / no code / no flag flip | **PASS** — H9 |
| Architecture / Register / prior WPs unmodified | **PASS** |

---

## 12. Assumptions

1. Full certification drill program content is P2 — this WP only defines result/order interfaces.  
2. Actual flag write in production config is P9 under separate Owner coding/enablement authorization.  
3. “Implementation completed” boolean is a phase gate recorded by Owner/authorized process — not Engineering self-cert of OD-CERT.

---

## 13. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Accidental enablement UI before P9 | Critical | H1/H9; Enable only from E5; default false |
| Engineering self-PASS | Critical | §5.2 reject non-owner PASS |
| Auto re-enable after schema | Critical | H7; `auto_reenable=false` |
| SA Enable without Owner order | Critical | §7.1; preconditions |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 14. Out of scope

- Running certification drills  
- Flipping production enablement flag  
- WP-P1-14 integration freeze  
- PHP config writers  

---

*End of WP-P1-13. STOP — do not begin WP-P1-14 until Owner review and approval.*
