# Country Production Restore — P2 Evidence Pack Assembly Schemas

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P2-04** — Evidence Pack Assembly Schemas |
| **Artifact-ID** | `CPR-P2-WP04-EVIDENCE_PACK_SCHEMAS` |
| **Status** | COMPLETE (certification design only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P2-03; authorized WP-P2-04 |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P2_ARTIFACT_INDEX.md` (WP-P2-01) |
| **Depends on** | WP-P2-01 EV catalog · WP-P2-02 checklist · WP-P2-03 drills · P1-02 · P1-13 |
| **Coding** | **No** — design schemas only; no PHP/SQL/CLI/HTTP/UI |
| **Enablement** | Remains **FALSE** (OD-ENABLE) |

---

## 1. Purpose

Define complete **Evidence Pack assembly contracts**: pack structure, packaging order, manifest schemas, integrity/hashing/sealing rules, unique artifact identity, immutability after seal, pre-Owner-review validation, and mandatory traceability to:

- `cpr_execution_contract` (P1-02)  
- P1 design contracts  
- Drill scenarios (WP-P2-03)  
- Certification checklist (WP-P2-02)  
- OWNER_APPROVED Register  

This WP does **not** define Owner PASS/FAIL ceremony detail (WP-P2-05), execute drills, flip enablement, modify Architecture/ODs/P1, or write mutation code.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Every EV-01…EV-14 class must appear as a uniquely identifiable artifact entry | WP-P2-01 §8 · OD-CERT |
| H2 | Pack is **immutable** after `sealed=true` | Integrity · OD-CERT |
| H3 | Post-seal mutation → reject pack; require new `package_cycle_id` | Integrity |
| H4 | Fail-closed validation before Owner review; no SKIP/waiver default | WP-P2-02 VR-* · Integrity |
| H5 | Engineering assembles and seals evidence; Engineering **cannot** set Cert PASS | OD-CERT · P1-13 |
| H6 | Enablement flag attestation inside pack must be `false` | OD-ENABLE · EV-13 |
| H7 | Hash algorithm fixed for a pack revision; recompute must match | P1-02 fingerprint model |
| H8 | Traceability links required to execution contract, P1 Artifact-IDs, DS-*, CG-*, OD-* | WP-P2-01 citation rules |
| H9 | Secrets/passwords **forbidden** in pack payloads | Architecture §15 pattern · P1-05 |
| H10 | No architecture redesign; no OD reopen; no mutation code | P2 Execution Authorization |

---

## 3. Identity model

### 3.1 Unique identifiers

| ID | Format | Scope | Uniqueness rule |
|----|--------|-------|-----------------|
| `package_cycle_id` | UUID | One certification evidence cycle | One pack root per cycle |
| `evidence_pack_id` | UUID | Physical/logical pack instance | Equals or 1:1 with cycle for sealed pack |
| `artifact_id` | UUID | Single evidence file/blob | Globally unique within cycle; never reused |
| `manifest_id` | UUID | Manifest document | One active manifest per pack revision |
| `content_hash` | `sha256:<hex>` | Bytes of artifact | Canonical hex lowercase |
| `manifest_hash` | `sha256:<hex>` | Canonical JSON of sealed manifest (excl. self-hash field placement per §7) | |
| `pack_seal_hash` | `sha256:<hex>` | Hash over sealed manifest + ordered artifact content hashes | Pack integrity root |
| `certification_id` | UUID | Links to `cpr_certification_result` | P1-13 |
| `trace_link_id` | UUID | One edge in traceability graph | Optional but recommended |

**Artifact logical key (also unique):** `{package_cycle_id}:{evidence_class}:{artifact_seq}`  
where `evidence_class` ∈ `EV-01`…`EV-14` and `artifact_seq` is zero-padded integer starting `001`.

### 3.2 Forbidden reuse

| Forbidden | Reason |
|-----------|--------|
| Reuse `artifact_id` with different bytes | Breaks integrity |
| Reuse sealed `package_cycle_id` after content change | Breaks immutability |
| Point two EV classes at same `artifact_id` without explicit multi-class declaration | Ambiguous coverage — default **forbidden**; use distinct artifacts or declared `covers_classes[]` with single primary |

---

## 4. Evidence Pack structure

### 4.1 Logical layout

```text
cpr_evidence_pack/
  manifest.json                 # cpr_evidence_pack_manifest/1
  seal.json                     # cpr_evidence_pack_seal/1  (written at seal)
  traceability.json             # cpr_evidence_traceability/1
  validation_report.json        # cpr_evidence_validation_report/1 (pre-submit)
  artifacts/
    EV-01/
      001_<short_name>.json|md|zip|bin
    EV-02/
      ...
    EV-14/
      ...
  drills/                       # optional mirror index of DS-* report refs
    index.json                  # maps scenario_id → artifact_id(s)
  checklist/
    evaluation.json             # CG-* results snapshot (Engineering layer only)
```

Design path root (non-normative hosting):  
`{workRoot}/country_production/certification/{package_cycle_id}/`

### 4.2 Pack states

| State | Meaning | Mutable? |
|-------|---------|:--------:|
| `assembling` | Engineering adding artifacts | Yes |
| `validating` | Pre-seal / pre-submit validators running | Yes (reports only) |
| `sealed` | Seal written; content frozen | **No** |
| `submitted_for_owner` | Linked to `cert_submitted_for_owner` | No (pack); Owner decision is separate record |
| `superseded` | Replaced by new cycle (e.g. OD-SCHEMA) | No |

Transitions: `assembling` → `validating` → `sealed` → `submitted_for_owner`.  
Any content change after `sealed` → **forbidden**; start new cycle.

### 4.3 Required top-level members

| Member | Required | Schema |
|--------|:--------:|--------|
| `manifest.json` | Y | §6.1 |
| `seal.json` | Y after seal | §6.2 |
| `traceability.json` | Y before seal | §6.3 |
| `validation_report.json` | Y before Owner submit | §6.4 |
| `artifacts/EV-01` … `EV-14` | Y each class ≥1 artifact | §5 |
| `drills/index.json` | Y | §6.5 |
| `checklist/evaluation.json` | Y | §6.6 |

---

## 5. Per-class artifact entry schema

### 5.1 Common artifact descriptor (embedded in manifest)

Schema: `cpr_evidence_artifact_descriptor/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_evidence_artifact_descriptor/1"` |
| `artifact_id` | Y | UUID |
| `evidence_class` | Y | `EV-01` … `EV-14` |
| `artifact_seq` | Y | Integer ≥ 1 |
| `logical_key` | Y | `{package_cycle_id}:{evidence_class}:{seq}` |
| `relative_path` | Y | Under `artifacts/` |
| `media_type` | Y | e.g. `application/json`, `application/zip` |
| `byte_length` | Y | Exact |
| `content_hash` | Y | `sha256:<hex>` of raw bytes |
| `produced_by` | Y | `engineering` (never `owner` for EV-01…EV-13) |
| `producer_actor_id` | Y | |
| `produced_at` | Y | ISO-8601 |
| `drill_context` | Y* | Required for EV-04…EV-11 drill-derived; ∈ {`clone`,`shadow_lab`,`non_production_fixture`} |
| `enablement_flag_observed` | Y | Must be `false` |
| `od_refs` | Y | Array of OD-IDs |
| `p1_artifact_refs` | Y | Array of P1 Artifact-IDs |
| `scenario_refs` | Y* | Array of `DS-*` (required if class is drill-backed) |
| `checklist_refs` | Y | Array of `CG-*` this artifact supports |
| `execution_contract_refs` | N | Array of `job_id` + `contract_revision` when applicable |
| `c8_safe_evidence` | Y* | Required on EV-03 primary; boolean true only if SAFE |
| `immutable_after_pack_seal` | Y | Const `true` |
| `secrets_present` | Y | Must be `false` |

### 5.2 Minimum artifacts per class

| Class | Min count | Notes |
|-------|:---------:|-------|
| EV-01…EV-14 | 1 each | EV-10 must include rollback-minimum scenario set (WP-P2-03 §6) |
| EV-14 | 1 | Owner decision package shell with `result=PENDING` until Owner decides (WP-P2-05) |

Multiple artifacts per class allowed (e.g. EV-10 many DS reports); all listed in manifest.

---

## 6. Manifest and companion schemas

### 6.1 Pack manifest — `cpr_evidence_pack_manifest/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_evidence_pack_manifest/1"` |
| `manifest_id` | Y | UUID |
| `evidence_pack_id` | Y | UUID |
| `package_cycle_id` | Y | UUID |
| `certification_id` | Y | Links P1-13 result (may be PENDING) |
| `schema_revision_bound` | Y | Production schema revision |
| `pack_state` | Y | `assembling` \| `validating` \| `sealed` \| `submitted_for_owner` \| `superseded` |
| `created_at` | Y | |
| `created_by_engineering_id` | Y | |
| `baseline_git` | Y | Object: `p0_tag`, `p0_commit`, `p1_tag`, `p1_commit` |
| `register_sot_ref` | Y | Path + content hash of Owner Decisions file at freeze |
| `architecture_ref` | Y | Path + content hash (read-only citation) |
| `p2_control_plane_ref` | Y | WP-P2-01 Artifact-ID + doc hash |
| `checklist_ref` | Y | `CPR-P2-WP02-CERT_CHECKLIST` + doc hash |
| `drill_catalog_ref` | Y | `CPR-P2-WP03-DRILL_SCENARIOS` + doc hash |
| `execution_contract_binding` | Y | See §8.1 |
| `artifacts` | Y | Array of §5.1 descriptors; must cover EV-01…EV-14 |
| `packaging_order` | Y | Ordered list of `artifact_id` exactly matching §7.1 |
| `content_hash_algorithm` | Y | Const `"sha256"` |
| `canonical_json` | Y | Const `"rfc8785_or_sorted_keys_utf8_no_ws_variance"` — implementation must pick one and document; design requires **deterministic** canonicalization |
| `sealed` | Y | Boolean; false until seal |
| `seal_ref` | N | `manifest_id` of seal file when sealed |
| `enablement_flag_bound` | Y | Must be `false` |
| `owner_pass_granted_by_engineering` | Y | Const `false` |
| `waiver_present` | Y | Must be `false` unless Owner escalation record id set |
| `owner_escalation_id` | N | Only if waiver_present true (default path: absent) |

**Reject manifest if** any EV-01…EV-14 missing, `enablement_flag_bound!=false`, or `sealed=true` while artifacts still mutable on disk.

### 6.2 Seal record — `cpr_evidence_pack_seal/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_evidence_pack_seal/1"` |
| `seal_id` | Y | UUID |
| `evidence_pack_id` | Y | |
| `package_cycle_id` | Y | |
| `sealed_at` | Y | |
| `sealed_by_engineering_id` | Y | |
| `manifest_id` | Y | |
| `manifest_hash` | Y | Hash of canonical sealed manifest |
| `ordered_artifact_hashes` | Y | Array of `{artifact_id, content_hash}` in packaging order |
| `pack_seal_hash` | Y | `sha256` over concatenation defined in §7.3 |
| `immutable` | Y | Const `true` |
| `post_seal_mutation_allowed` | Y | Const `false` |
| `auto_enable_forbidden` | Y | Const `true` |
| `audit_record_id` | Y | |

### 6.3 Traceability graph — `cpr_evidence_traceability/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_evidence_traceability/1"` |
| `package_cycle_id` | Y | |
| `edges` | Y | Array of §6.3.1 |

#### 6.3.1 Trace edge

| Field | Required | Notes |
|-------|:--------:|-------|
| `trace_link_id` | Y | UUID |
| `from_type` | Y | `artifact` \| `scenario` \| `checklist_item` \| `p1_contract` \| `od` \| `execution_contract` \| `cert_result` |
| `from_id` | Y | |
| `to_type` | Y | Same enum |
| `to_id` | Y | |
| `relation` | Y | `supports` \| `proves` \| `implements` \| `bound_to` \| `evaluates` \| `cites` |

**Minimum edges (normative):**

1. Every `artifact` → ≥1 `od` (`cites`)  
2. Every `artifact` → ≥1 `p1_contract` OR documented P2 Artifact-ID (`cites`)  
3. Every drill-backed `artifact` → ≥1 `scenario` (`proves`)  
4. Every `artifact` → ≥1 `checklist_item` (`supports`)  
5. Pack → `execution_contract` binding (`bound_to`) when drill jobs exist  
6. Pack → `cert_result` (`bound_to`) via `certification_id`  

### 6.4 Validation report — `cpr_evidence_validation_report/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_evidence_validation_report/1"` |
| `package_cycle_id` | Y | |
| `validated_at` | Y | |
| `validator_id` | Y | Engineering tool/process id |
| `rules_evaluated` | Y | Array of `{rule_id, result, fail_code?}` |
| `all_rules_pass` | Y | Boolean |
| `ready_for_owner_review` | Y | true only if all_rules_pass and sealed |
| `enablement_flag_observed` | Y | Must be `false` |
| `engineering_cannot_grant_pass` | Y | Const `true` |

### 6.5 Drills index — `cpr_evidence_drills_index/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_evidence_drills_index/1"` |
| `package_cycle_id` | Y | |
| `scenarios` | Y | Array `{scenario_id, result, artifact_ids[]}` covering all **40** DS-* from WP-P2-03 |
| `ev10_minimum_set_satisfied` | Y | Boolean per WP-P2-03 §6 |

### 6.6 Checklist evaluation snapshot — `cpr_evidence_checklist_eval/1`

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_evidence_checklist_eval/1"` |
| `package_cycle_id` | Y | |
| `items` | Y | Array `{gate_id, layer, result, evidence_artifact_ids[]}` for all CG-S*/CG-M* |
| `evidence_ready` | Y | Boolean (WP-P2-02 §6.1) |
| `cg_h_and_f_owner_only` | Y | Const `true` — Engineering must leave CG-H*/CG-F* as `PENDING` |

---

## 7. Packaging order, hashing, sealing

### 7.1 Packaging order (normative)

Artifacts **must** be packaged and hashed in this order:

1. EV-01 (all seq ascending)  
2. EV-02 …  
3. EV-03  
4. EV-04  
5. EV-05  
6. EV-06  
7. EV-07  
8. EV-08  
9. EV-09  
10. EV-10  
11. EV-11  
12. EV-12  
13. EV-13  
14. EV-14  

Within a class: ascending `artifact_seq`.  
Companion files written after artifacts, before seal:

15. `traceability.json`  
16. `drills/index.json`  
17. `checklist/evaluation.json`  
18. `validation_report.json` (final pre-seal run may update, then re-run hash step)  
19. `manifest.json` (final)  
20. `seal.json`  

### 7.2 Hashing requirements

| Rule | Value |
|------|-------|
| Algorithm | **SHA-256** only for pack revision `/1` |
| Encoding | Hex lowercase; prefix `sha256:` in fields |
| Artifact hash input | Raw file bytes as stored |
| Manifest hash input | Canonical JSON bytes of manifest with `sealed=true` and without depending on `seal_ref` circularity: set `sealed=true`, omit `seal_ref` for hash input, then attach `seal_ref` after seal file exists **or** freeze manifest hash inside seal only (preferred: **seal carries `manifest_hash`; manifest lists `seal_ref` after seal without changing artifact hashes**) |
| Forbidden | MD5/SHA-1; truncated hashes; hashing after secret injection |

**Preferred seal procedure:**

1. Finalize all artifacts + traceability + drills index + checklist eval.  
2. Run validators (§9) → write `validation_report.json` with `all_rules_pass=true`.  
3. Write manifest with `sealed=false`, compute provisional checks.  
4. Set manifest `sealed=true`, compute `manifest_hash` over canonical manifest **excluding** `seal_ref` field.  
5. Compute `pack_seal_hash` (§7.3).  
6. Write `seal.json`.  
7. Set manifest `seal_ref` = `seal_id` (this byte change is the **only** allowed post-hash manifest field if using two-phase; alternatively store seal_ref only in certification result — **design choice frozen here:** seal_ref may be patched **once** immediately after seal; `manifest_hash` inside seal is over pre-`seal_ref` bytes; validators verify that invariant).  

### 7.3 `pack_seal_hash` construction

Canonical string (UTF-8), then SHA-256:

```text
v1|{package_cycle_id}|{manifest_hash}|{artifact_id_1}:{content_hash_1}|...|{artifact_id_n}:{content_hash_n}
```

where artifact pairs follow §7.1 order exactly.

### 7.4 Immutability after sealing

| Action after seal | Status |
|-------------------|--------|
| Add/remove/modify artifact bytes | **Forbidden** |
| Change packaging order | **Forbidden** |
| Re-hash to “fix” drift | **Forbidden** — new cycle required |
| Engineering set `result=PASS` on cert | **Forbidden** |
| Flip enablement | **Forbidden** |
| Owner writes PASS/FAIL on `cpr_certification_result` | **Allowed** (separate record; does not mutate sealed pack bytes) |
| OD-SCHEMA invalidation | Marks cert invalidated; pack becomes `superseded` reference; new cycle |

---

## 8. Traceability bindings (mandatory)

### 8.1 Execution contract binding

Object `execution_contract_binding` in manifest:

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_evidence_exec_contract_binding/1"` |
| `bound_jobs` | Y | Array of `{job_id, contract_revision, contract_fingerprint_digest, package_fingerprint, country_id?}` |
| `fingerprint_fields_cited` | Y | Must include at least: `package_fingerprint`, `c4_report_hash`…`c8_report_hash`, `inventory_snapshot_hash` when jobs present |
| `drift_detected` | Y | Must be `false` at seal |
| `p1_ref` | Y | `CPR-P1-WP02-EXECUTION_CONTRACT` (or exact Artifact-ID from P1-02 header) |

If certification drills used multiple jobs, all listed. Empty `bound_jobs` only allowed for pure design-era dry packs **before** engines exist — still must seal EV-* design proofs; once P7 drills run, empty is **FAIL**.

### 8.2 P1 contracts

Manifest and each artifact must cite applicable P1 Artifact-IDs (examples):

| Evidence class | Typical P1 refs |
|----------------|-----------------|
| EV-03 | P1-08, P1-13 |
| EV-04 | P1-08 |
| EV-05 | P1-06 |
| EV-06 | P1-07 |
| EV-07 | P1-05 |
| EV-08 | P1-10 |
| EV-09 | P1-11 |
| EV-10 | P1-09, P1-03 |
| EV-11 | P1-12 |
| EV-12 / EV-13 / EV-14 | P1-13 |
| EV-02 | P1-08 (inventory gates) |
| Binding | P1-02 |

### 8.3 Drill scenarios

`drills/index.json` must list all DS-N01…DS-P05 with `result` and `artifact_ids`.  
EV-10 artifacts must satisfy WP-P2-03 §6 minimum set.

### 8.4 Certification checklist

`checklist/evaluation.json` must include every CG-S* and CG-M* with `result` and supporting `evidence_artifact_ids`.  
CG-H* / CG-F* remain `PENDING` for Owner (WP-P2-05).

### 8.5 OWNER_APPROVED Register

| Requirement | Rule |
|-------------|------|
| `register_sot_ref.content_hash` | Hash of Register file bytes at pack freeze |
| Every artifact `od_refs` | Non-empty; must be OWNER_APPROVED IDs |
| Trace edges | artifact → od for each listed OD |

---

## 9. Validation rules before Certification review

Evaluate **all** rules; fail-closed. Aggregate `ready_for_owner_review` only if every rule PASS and pack sealed.

| Rule-ID | Predicate | Fail code |
|---------|-----------|-----------|
| PV-01 | Manifest schema valid; `schema_version` correct | `pack_manifest_invalid` |
| PV-02 | All EV-01…EV-14 present with ≥1 artifact | `pack_ev_class_missing` |
| PV-03 | Every artifact `content_hash` recomputes equal | `pack_artifact_hash_mismatch` |
| PV-04 | Packaging order matches §7.1 exactly | `pack_order_invalid` |
| PV-05 | `pack_seal_hash` verifies | `pack_seal_hash_mismatch` |
| PV-06 | `sealed=true` and seal record present | `pack_not_sealed` |
| PV-07 | No post-seal byte changes vs seal inventory | `pack_post_seal_mutation` |
| PV-08 | `enablement_flag_bound=false` and all artifact observations false | `pack_enablement_not_false` |
| PV-09 | `waiver_present=false` (default) | `pack_waiver_present` |
| PV-10 | Traceability minimum edges (§6.3) complete | `pack_traceability_incomplete` |
| PV-11 | Execution contract binding drift false | `pack_contract_drift` |
| PV-12 | Drills index covers all 40 scenarios | `pack_drills_incomplete` |
| PV-13 | `ev10_minimum_set_satisfied=true` | `pack_ev10_minimum_fail` |
| PV-14 | Checklist CG-S*/CG-M* all PASS → `evidence_ready=true` | `pack_evidence_not_ready` |
| PV-15 | CG-H*/CG-F* not marked PASS by Engineering | `pack_eng_owner_gate_usurp` |
| PV-16 | EV-03 asserts C8 SAFE only | `pack_c8_not_safe` |
| PV-17 | `secrets_present=false` all artifacts | `pack_secrets_present` |
| PV-18 | `schema_revision_bound` consistent across manifest, cert result, EV-12 | `pack_schema_inconsistent` |
| PV-19 | Register + Architecture + P2 doc hashes present | `pack_baseline_refs_missing` |
| PV-20 | `owner_pass_granted_by_engineering=false`; cert result not PASS by eng | `pack_eng_cert_pass` |
| PV-21 | Unique `artifact_id` / `logical_key` | `pack_duplicate_identity` |
| PV-22 | Media types / paths exist and `byte_length` match | `pack_artifact_missing` |

**Owner review entry condition:** `validation_report.ready_for_owner_review === true` **and** lifecycle `cert_submitted_for_owner`.

---

## 10. Binding to `cpr_certification_result` (P1-13)

| Field on cert result | Pack source |
|----------------------|-------------|
| `package_cycle_id` | Manifest |
| `schema_revision_bound` | Manifest |
| `c8_safe_evidence_ref` | Primary EV-03 `artifact_id` |
| `evidence_pack_refs` | All `artifact_id` values (+ pack id) |
| `result` | `PENDING` at submit; Owner sets PASS/FAIL later |
| `sealed` | May become true when Owner decides — **distinct** from pack seal |
| `engineering_submitter_id` | Manifest `created_by_engineering_id` |

Pack seal ≠ Owner Cert seal. Pack immutability is independent of Owner decision record.

---

## 11. Integrity rules (summary)

1. Unique IDs for pack, artifacts, manifest, seal.  
2. SHA-256 content hashes for every artifact.  
3. Deterministic packaging order.  
4. Seal hash roots the pack.  
5. Post-seal immutability absolute for artifact bytes.  
6. Traceability graph complete before seal.  
7. Fail-closed PV-01…PV-22 before Owner review.  
8. Enablement false attested.  
9. No Engineering Cert PASS.  
10. EV-10 rollback minimum mandatory.

---

## 12. Out of scope

| Item | Deferred |
|------|----------|
| Owner PASS/FAIL decision package UX/process | WP-P2-05 |
| Schema re-cert cycle packaging deltas | WP-P2-06 |
| P2 integration freeze | WP-P2-07 |
| Live assembly PHP/CLI | Later coding auth |
| Enablement | P9 |

---

## 13. Acceptance criteria (WP-P2-04)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Evidence Pack assembly contracts document exists with Artifact-ID | **PASS** |
| AC2 | Complete pack structure defined | **PASS** §4 |
| AC3 | Packaging order defined | **PASS** §7.1 |
| AC4 | Manifest schemas defined | **PASS** §6.1–§6.6 |
| AC5 | Evidence integrity rules defined | **PASS** §11, §5, H2–H4 |
| AC6 | Hashing and sealing requirements defined | **PASS** §7 |
| AC7 | Traceability to execution contract, P1, drills, checklist, Register | **PASS** §8, §6.3 |
| AC8 | Every evidence artifact uniquely identifiable | **PASS** §3 |
| AC9 | Packs immutable after sealing | **PASS** §4.2, §7.4 |
| AC10 | Validation rules before Certification review defined | **PASS** §9 PV-01…PV-22 |
| AC11 | Enablement FALSE; no code; Architecture/ODs unmodified | **PASS** H6, H10 |

---

## 14. Stop rule

**WP-P2-04 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P2-05 until Owner review and approval.

---

*End of WP-P2-04 — Evidence Pack Assembly Schemas.*
