# Country Production Restore — P2 Schema Revision Re-Certification Cycle

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P2-06** — Schema Revision Re-Certification Cycle (OD-SCHEMA) |
| **Artifact-ID** | `CPR-P2-WP06-SCHEMA_RECERT_CYCLE` |
| **Status** | COMPLETE (certification design only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P2-05; authorized WP-P2-06 |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-SCHEMA · OD-CERT · OD-ENABLE · OD-C8 · OD-FA-SCHEMA) |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P2_ARTIFACT_INDEX.md` (WP-P2-01) |
| **Depends on** | P1-13 §8 · WP-P2-02 · WP-P2-04 · WP-P2-05 · P1-08 · P1-12 |
| **Coding** | **No** — design contracts only; no PHP/SQL/CLI/HTTP/UI |
| **Enablement** | Remains **FALSE** unless separately re-authorized after full cycle (never automatic) |

---

## 1. Purpose

Define the complete **Schema Revision Re-Certification lifecycle**: detection, immediate invalidation, mandatory rebuild/re-cert/re-enable steps, state transitions, validation contracts, audit/alert events, and traceability — bound to OD-SCHEMA frozen wording and P1-13 §8.

This WP does **not** auto-recertify, auto-enable, mutate sealed historical Evidence Packs, modify Architecture/ODs/P1, or write mutation code.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | **Any** Production Schema Revision change **immediately invalidates** prior CPR certification | OD-SCHEMA §15 Frozen |
| H2 | **Auto re-certification is forbidden** | OD-SCHEMA · OD-CERT |
| H3 | **Auto re-enable is forbidden** | OD-SCHEMA · OD-ENABLE · P1-13 H7 |
| H4 | Prior Evidence Packs remain **historical and immutable** (`superseded`); never rewritten | WP-P2-04 §7.4 |
| H5 | Before CPR may be used again: package rebuild + new Certification + new C8 SAFE + Owner review + Owner PASS + Owner Enable again + SA Enable from new E5 | OD-SCHEMA §15 |
| H6 | Rebuild / new cert / new C8 SAFE **do not** auto-enable | OD-SCHEMA |
| H7 | Owner alone grants new Cert PASS; Engineering never grants PASS | OD-CERT |
| H8 | Flag forced **false** on invalidation if it was true | OD-SCHEMA · P1-13 §8.2 |
| H9 | Pre-PONR schema gates FAIL until new cycle completes | P1-08 · OD-FA-SCHEMA |
| H10 | No architecture redesign; no OD reopen; no mutation code in this WP | P2 Execution Authorization |

---

## 3. Terms

| Term | Meaning |
|------|---------|
| `schema_revision` | Live production catalog/schema revision identifier consumed by CPR gates |
| `schema_revision_bound` | Revision frozen on `cpr_certification_result` / Evidence Pack / enablement preconditions |
| `prior cycle` | Certification + Evidence Pack bound to previous revision |
| `new cycle` | Entirely new `package_cycle_id` + `certification_id` for current revision |
| Historical pack | Sealed prior pack; `pack_state=superseded`; bytes immutable |

---

## 4. Schema revision detection

### 4.1 Detection sources (read-only)

| Source | Role |
|--------|------|
| Live SoT schema revision | Authoritative current value |
| Active `cpr_certification_result.schema_revision_bound` | Bound value for current/prior cycle |
| Active enablement preconditions `schema_revision_bound` | Must match live when enabling |
| Sealed Evidence Pack manifest `schema_revision_bound` | Historical binding |

### 4.2 Detection event — `cpr_schema_revision_detection/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_schema_revision_detection/1"` |
| `detection_id` | Y | UUID |
| `detected_at` | Y | ISO-8601 |
| `schema_revision_previous` | Y | Last known bound / observed |
| `schema_revision_current` | Y | Live SoT |
| `mismatch` | Y | `true` if previous ≠ current |
| `prior_certification_id` | Y* | Required if a cert cycle existed |
| `prior_package_cycle_id` | Y* | Required if pack existed |
| `detector` | Y | `system` \| `gate_eval` \| `admin_tool` |
| `auto_recert_attempted` | Y | Must be `false` |
| `auto_reenable_attempted` | Y | Must be `false` |

### 4.3 Trigger rule

```
IF live.schema_revision != bound.schema_revision_for_active_cert_or_enablement_cycle
THEN fire invalidation (§5) immediately
```

**Any change** counts (including leaving historical examples such as “121”). No grace, warning-only, or mixed-revision waiver (OD-FA-SCHEMA / OD-SCHEMA).

---

## 5. Immediate invalidation rules

### 5.1 Effects (normative, atomic design intent)

On mismatch detection:

| # | Effect |
|---|--------|
| 1 | Prior `cpr_certification_result.result` → `INVALIDATED` (or lifecycle `cert_invalidated`) |
| 2 | Set `invalidation_ref` → new `cpr_schema_invalidation_event` |
| 3 | Enablement state → `E8_schema_invalidated` |
| 4 | Enablement flag forced **`false`** if it was `true` |
| 5 | `auto_reenable = false` (const on event) |
| 6 | Emit audit + alert (§10) |
| 7 | WP-P1-08 schema-related gates FAIL until new cycle completes |
| 8 | Prior Evidence Pack `pack_state` → `superseded` (**bytes unchanged**) |
| 9 | Prior Owner enablement orders bound to old revision → **not valid** for new revision |
| 10 | **No** automatic start of package rebuild, drills, or Owner PASS |

### 5.2 Invalidation event — extends P1-13 `cpr_schema_invalidation_event`

Consume P1-13 §8.4 and require these additional design fields for P2:

| Field | Required | Notes |
|-------|:--------:|-------|
| *(all P1-13 §8.4 fields)* | Y | Including `auto_reenable=false`, `flag_forced_false=true` |
| `auto_recertification` | Y | Const **`false`** |
| `prior_evidence_pack_id` | Y | |
| `prior_pack_seal_hash` | Y | |
| `prior_pack_immutable` | Y | Const `true` |
| `prior_pack_state` | Y | Must become `superseded` |
| `new_package_cycle_id` | N | Null until Engineering opens new cycle |
| `detection_id` | Y | Links §4.2 |
| `alert_event_id` | Y | |
| `trace_graph_ref` | Y | Traceability snapshot id |

### 5.3 Checklist object on invalidation event (initially all `done=false`)

| Step key | Meaning | `done` initial |
|----------|---------|:--------------:|
| `package_rebuild` | New CRP package for new schema | false |
| `new_c8_safe` | New C8 SAFE verification | false |
| `new_evidence_pack` | New sealed Evidence Pack (WP-P2-04) | false |
| `new_certification_review` | Owner submission / review (WP-P2-05) | false |
| `new_owner_pass` | Owner Cert PASS for new revision | false |
| `new_owner_enablement_order` | New Owner enablement order bound to new cert/revision | false |
| `new_sa_enable` | Super Admin Enable from new E5 only | false |

---

## 6. Re-certification lifecycle states

### 6.1 Recert state machine (`cpr_schema_recert_state`)

| State | Meaning | Flag |
|-------|---------|------|
| `R0_bound_valid` | Cert bound matches live revision | Per enablement machine |
| `R1_mismatch_detected` | Detection recorded | Force path to invalidate |
| `R2_invalidated` | Prior cert INVALIDATED; packs superseded; E8 | `false` |
| `R3_rebuild_in_progress` | Engineering rebuilding package / C3–C8 | `false` |
| `R4_new_c8_safe_pending` | Awaiting new C8 SAFE | `false` |
| `R5_new_evidence_assembling` | New Evidence Pack assembling | `false` |
| `R6_new_evidence_sealed` | New pack sealed; ready for Owner submit | `false` |
| `R7_owner_review` | `cert_submitted_for_owner` for new cycle | `false` |
| `R8_owner_pass` | New Owner Cert PASS | `false` (still) |
| `R9_awaiting_enable_order` | Need new Owner enablement order | `false` |
| `R10_awaiting_enterprise_and_impl` | Other OD-ENABLE preconditions | `false` |
| `R11_e5_ready` | New `E5_preconditions_satisfied` | `false` |
| `R12_enabled` | SA Enable completed for **new** revision | `true` only here |
| `R13_failed_owner_fail` | Owner FAIL on new cycle — remain disabled | `false` |

### 6.2 Allowed transitions

```
R0_bound_valid
  → R1_mismatch_detected          # live ≠ bound
  → R2_invalidated                # immediate effects applied

R2_invalidated
  → R3_rebuild_in_progress        # Engineering starts NEW cycle (manual/authorized process — not auto)

R3_rebuild_in_progress
  → R4_new_c8_safe_pending        # package rebuild recorded

R4_new_c8_safe_pending
  → R5_new_evidence_assembling    # C8 SAFE obtained (OD-C8)

R5_new_evidence_assembling
  → R6_new_evidence_sealed        # WP-P2-04 seal

R6_new_evidence_sealed
  → R7_owner_review               # WP-P2-05 submit

R7_owner_review
  → R8_owner_pass                 # Owner PASS only
  → R13_failed_owner_fail         # Owner FAIL

R8_owner_pass
  → R9_awaiting_enable_order      # new Owner enablement order required

R9_awaiting_enable_order
  → R10_awaiting_enterprise_and_impl

R10_awaiting_enterprise_and_impl
  → R11_e5_ready                  # all four OD-ENABLE preconditions true; flag still false

R11_e5_ready
  → R12_enabled                   # Super Admin Enable ONLY; automatic=false

R13_failed_owner_fail
  → R3_rebuild_in_progress        # optional restart after remediation (new cycle ids)
```

### 6.3 Forbidden transitions

| Transition / behavior | Status |
|-----------------------|--------|
| R1/R2 → R8 without Owner | **Forbidden** (auto re-cert) |
| R3…R8 → R12 automatic | **Forbidden** (auto re-enable) |
| R2 → R12 | **Forbidden** |
| Reuse prior `package_cycle_id` / sealed pack for new revision | **Forbidden** |
| Mutate superseded pack bytes | **Forbidden** |
| Treat prior Owner PASS as valid for new revision | **Forbidden** |
| Treat prior enablement order as valid for new revision | **Forbidden** |
| Engineering sets new `result=PASS` | **Forbidden** |
| Country Admin Enable / Cert PASS | **Forbidden** |
| Soft-skip schema gates (fixture) in Production/Certification | **Forbidden** |

### 6.4 Mapping to P1-13 enablement states

| Recert state | Enablement state (P1-13) |
|--------------|--------------------------|
| R2…R7 | `E8_schema_invalidated` or restart toward `E2_awaiting_cert_pass` |
| R8 | `E3_awaiting_owner_enable_order` (after leaving E8 restart) |
| R9–R10 | `E3` / `E4` as applicable |
| R11 | `E5_preconditions_satisfied` |
| R12 | `E6_enabled` |
| Always on invalidate | Flag `false` |

Exact E8 → E2 restart: after invalidation force-disable, new cycle begins in awaiting-cert-pass posture (P1-13 §6.3).

---

## 7. Mandatory requirements for the new cycle

### 7.1 Package rebuild

| Requirement | Rule |
|-------------|------|
| New package | New `package_id` / fingerprint for **current** schema revision |
| Boundary/matrix | Aligned to live SoT; OD-INV inventory re-certified as needed |
| Prior package | Historical only; not reused as current cert package |
| Evidence | Recorded under new cycle; checklist step `package_rebuild.done=true` |

### 7.2 New C8 SAFE verification

| Requirement | Rule |
|-------------|------|
| Re-run C8 | Mandatory |
| Result | `SAFE` only (OD-C8); WARNING = FAIL for cert |
| `c8_safe_evidence_ref` | New artifact id on new cert result |
| Auto enable after SAFE | **Forbidden** |

### 7.3 New Evidence Pack

| Requirement | Rule |
|-------------|------|
| New `package_cycle_id` | Mandatory |
| EV-01…EV-14 | All required (WP-P2-01/04) |
| Seal | WP-P2-04 immutability |
| Prior pack | `superseded`; still readable; not editable |
| Trace | Must link `prior_package_cycle_id` + `invalidation_event_id` |

### 7.4 New Certification Review (Owner submission)

| Requirement | Rule |
|-------------|------|
| WP-P2-05 package | New submission for new cycle |
| Engineering recommend | Allowed; not a decision |
| Prior Owner PASS | Irrelevant to new cycle validity |

### 7.5 New Owner PASS

| Requirement | Rule |
|-------------|------|
| New `certification_id` | Mandatory |
| `schema_revision_bound` | Equals **current** live revision |
| `decided_by` | `owner` only |
| Engineering PASS | Reject |

### 7.6 New Enablement authorization

| Requirement | Rule |
|-------------|------|
| New Owner enablement order | Bound to **new** `certification_id` + **new** `schema_revision_bound` |
| Implementation completed | Still required (OD-ENABLE) |
| Final Enterprise approval | Still required |
| SA Enable | Only from new E5; `automatic=false` |
| Old enablement order | Invalid for new revision |

---

## 8. Validation contracts

### 8.1 On detection / invalidation

| Rule-ID | Predicate | Fail code |
|---------|-----------|-----------|
| SI-01 | Mismatch ⇒ invalidation event emitted | `schema_invalidate_missing_event` |
| SI-02 | Prior cert result INVALIDATED | `schema_prior_cert_not_invalidated` |
| SI-03 | Flag false after event | `schema_flag_not_forced_false` |
| SI-04 | `auto_reenable=false` and `auto_recertification=false` | `schema_auto_path_set` |
| SI-05 | Prior pack state superseded; seal hash unchanged | `schema_prior_pack_mutated` |
| SI-06 | Audit + alert ids present | `schema_audit_alert_missing` |

### 8.2 Before claiming new cycle usable for enablement

| Rule-ID | Predicate | Fail code |
|---------|-----------|-----------|
| SI-10 | `package_rebuild.done` | `recert_package_missing` |
| SI-11 | `new_c8_safe.done` and C8 SAFE | `recert_c8_not_safe` |
| SI-12 | New Evidence Pack sealed; EV-01…14; PV pass | `recert_evidence_pack_invalid` |
| SI-13 | `new_certification_review.done` (submitted + Owner reviewed path) | `recert_owner_review_missing` |
| SI-14 | New Owner PASS; `schema_revision_bound` == live | `recert_owner_pass_missing` |
| SI-15 | New Owner enablement order for new cert/revision | `recert_enable_order_missing` |
| SI-16 | Impl complete + enterprise approval | `recert_enable_preconditions_incomplete` |
| SI-17 | SA Enable only from E5; not automatic | `recert_auto_enable` |
| SI-18 | No use of prior cert id / prior pack as current | `recert_stale_binding` |
| SI-19 | Pre-PONR schema gates may PASS only after SI-10…SI-14 as applicable to cert; enablement still separate | `recert_gate_premature` |

### 8.3 Continuous guards

| Rule-ID | Predicate |
|---------|-----------|
| SI-20 | Live revision change anytime while R8–R12 ⇒ re-enter R1/R2 (invalidate again) |
| SI-21 | Historical packs never accept new artifacts |
| SI-22 | Enablement flag read while R2–R11 ⇒ false / deny mutation |

---

## 9. Traceability

### 9.1 Required links

| From | To | Relation |
|------|-----|----------|
| `detection_id` | live vs bound revisions | `detects` |
| `invalidation_event_id` | prior `certification_id` | `invalidates` |
| `invalidation_event_id` | prior `package_cycle_id` / `pack_seal_hash` | `supersedes_pack` |
| New `package_cycle_id` | `invalidation_event_id` | `successor_of` |
| New `certification_id` | new pack + new schema revision | `bound_to` |
| New enablement order | new `certification_id` | `authorizes_enable_for` |
| Checklist steps | audit events | `evidenced_by` |

### 9.2 Traceability record — `cpr_schema_recert_trace/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_schema_recert_trace/1"` |
| `trace_id` | Y | |
| `invalidation_event_id` | Y | |
| `prior_certification_id` | Y | |
| `prior_package_cycle_id` | Y | |
| `prior_pack_seal_hash` | Y | |
| `new_certification_id` | N | Until created |
| `new_package_cycle_id` | N | Until created |
| `new_c8_safe_evidence_ref` | N | |
| `new_owner_enablement_order_id` | N | |
| `edges` | Y | Array of from/to/relation |
| `od_refs` | Y | Must include OD-SCHEMA, OD-CERT, OD-ENABLE, OD-C8 |

---

## 10. Audit and alert events

### 10.1 Audit event types (schema invalidation family)

Emit using P1-12 `cpr_audit_event/1` envelope. Design catalog additions for OD-SCHEMA (do not require editing P1-12 file in this WP; coding phase must register these types):

| `event_type` | When | Actor | Required details (min) |
|--------------|------|-------|------------------------|
| `cpr.schema_revision_detected` | Mismatch observed | system | `detection_id`, previous, current |
| `cpr.schema_invalidation` | Invalidation applied | system | `event_id`, prior cert id, `flag_forced_false=true`, `auto_reenable=false`, `auto_recertification=false` |
| `cpr.cert_invalidated` | Cert record flipped | system | `certification_id`, `result=INVALIDATED` |
| `cpr.evidence_pack_superseded` | Prior pack marked superseded | system | `package_cycle_id`, `pack_seal_hash`, `immutable=true` |
| `cpr.schema_recert_cycle_opened` | New cycle ids allocated | Engineering | `new_package_cycle_id`, `schema_revision_current` |
| `cpr.schema_package_rebuilt` | Rebuild done | Engineering | `package_id`, fingerprint |
| `cpr.schema_c8_safe_recorded` | New C8 SAFE | Engineering | `c8_safe_evidence_ref`, SAFE only |
| `cpr.schema_evidence_pack_sealed` | New pack sealed | Engineering | `new_package_cycle_id`, `pack_seal_hash` |
| `cpr.schema_owner_cert_pass` | Owner PASS new cycle | Owner | `certification_id`, `decided_by=owner` |
| `cpr.schema_owner_cert_fail` | Owner FAIL new cycle | Owner | `certification_id` |
| `cpr.schema_owner_enable_order` | New enable order | Owner | `order_id`, new cert id, new revision |
| `cpr.enable` | SA Enable after new E5 | Super Admin | Must cite **new** cert/order; `automatic=false` |
| `cpr.disable` | Force disable on invalidation | system / SA | `flag_value=false`, `reason=schema_invalidation` |

### 10.2 Alert events

| Alert condition | Severity | When |
|-----------------|----------|------|
| `cpr.alert.schema_invalidation` | critical | On `cpr.schema_invalidation` |
| `cpr.alert.schema_auto_reenable_blocked` | critical | If any component attempts auto-enable (must deny + alert) |
| `cpr.alert.schema_auto_recert_blocked` | critical | If any component attempts auto-recert (must deny + alert) |
| `cpr.alert.schema_stale_cert_used` | critical | Gate/job cites invalidated cert |

Alerts use P1-12 `cpr_alert_event/1` shape; no secrets.

### 10.3 Metrics (design)

| Metric key | Meaning |
|------------|---------|
| `cpr_schema_revision_live` | Current live revision label/info |
| `cpr_schema_revision_cert_bound` | Bound revision on active cert (or none) |
| `cpr_schema_invalidation_total` | Counter |
| `cpr_schema_recert_state` | Label of §6.1 state |
| `cpr_enablement_flag` | Must read false through R2–R11 |

---

## 11. Relationship to Evidence Packs and Owner packages

| Artifact | On invalidation | On new cycle |
|----------|-----------------|--------------|
| Prior Evidence Pack | `superseded`; immutable | Remains archive |
| Prior Owner submission | Historical | New WP-P2-05 submission |
| Prior Owner PASS | Void for operations | New PASS required |
| Prior enablement order | Void for new revision | New order required |
| New Evidence Pack | — | Full EV-01…EV-14; may cite prior cycle as historical lineage only |

---

## 12. Enablement posture (this WP)

| Phase | Flag |
|-------|------|
| Design / P2 | **FALSE** |
| After invalidation | **FALSE** (forced) |
| After rebuild / C8 / new pack / Owner PASS | **FALSE** until SA Enable |
| Auto path | **Never** |

P2 design itself does not perform live invalidation; contracts ensure future coding cannot auto-recert or auto-enable.

---

## 13. Out of scope

| Item | Deferred |
|------|----------|
| P2 integration freeze | WP-P2-07 |
| PHP detectors / flag writers | Coding auth / P9 |
| Live schema migration execution | Platform process outside this WP |
| Architecture / OD edits | Forbidden |

---

## 14. Acceptance criteria (WP-P2-06)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Complete schema re-cert lifecycle defined | **PASS** §6 |
| AC2 | Invalidation rules for any Production Schema Revision defined | **PASS** §4–§5 |
| AC3 | Schema revision detection defined | **PASS** §4 |
| AC4 | Certification invalidation defined | **PASS** §5 |
| AC5 | Package rebuild requirements defined | **PASS** §7.1 |
| AC6 | New C8 SAFE verification required | **PASS** §7.2 |
| AC7 | New Evidence Pack required | **PASS** §7.3 |
| AC8 | New Certification Review required | **PASS** §7.4 |
| AC9 | New Owner PASS required | **PASS** §7.5 |
| AC10 | New Enablement authorization required | **PASS** §7.6 |
| AC11 | Prior cert immediately INVALID; auto re-cert forbidden; auto re-enable forbidden | **PASS** H1–H3, §6.3 |
| AC12 | Previous Evidence Packs historical and immutable | **PASS** H4, §5.1#8, §11 |
| AC13 | All state transitions defined | **PASS** §6.2–§6.3 |
| AC14 | All validation contracts defined | **PASS** §8 |
| AC15 | Audit/alert events for schema invalidation defined | **PASS** §10 |
| AC16 | Complete traceability defined | **PASS** §9 |
| AC17 | Enablement FALSE by default/path; no code; Architecture/ODs unmodified | **PASS** §12, H10 |

---

## 15. Stop rule

**WP-P2-06 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P2-07 until Owner review and approval.

---

*End of WP-P2-06 — Schema Revision Re-Certification Cycle.*
