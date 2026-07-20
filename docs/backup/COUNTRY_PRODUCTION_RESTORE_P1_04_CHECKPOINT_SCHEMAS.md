# Country Production Restore — P1 Checkpoint Schemas (CP0–CP12 / CP-A)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-04** — Checkpoint Schemas (CP0–CP12) |
| **Artifact-ID** | `CPR-P1-WP04-CHECKPOINT_SCHEMAS` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §6, §18, §33 |
| **Depends on** | WP-P1-02 (execution contract) · WP-P1-03 (state matrix) |
| **Coding** | **No** mutation engine code in this WP |

---

## 1. Purpose

Define durable **checkpoint** file schemas, directory layout, atomic write/rename rules, binding to WP-P1-03 states, **OD-PIN order enforcement**, OD-RUNBOOK evidence binding, and pre-PONR vs post-PONR checkpoint discipline.

Checkpoint **IDs/names** follow Architecture §18. **Prerequisite order** follows synchronized §6 + OD-PIN / OD-MAINT (Maint before new Full Backup pin) — numeric CP labels alone must not be read as chronological order.

---

## 2. Directory layout

```text
{workRoot}/country_production/{job_id}/
  cpr_execution_contract.json          # WP-P1-02
  status.json                          # current state (WP-P1-03) — coding phase
  checkpoints/
    CP0_gates_passed.json
    CP2_approvals_complete.json
    CP3_contract_frozen.json
    CP4_maintenance_verified.json
    CP1_anchor_pinned.json             # AFTER CP4 only (OD-PIN)
    runbook_pre_ponr.json              # OD-RUNBOOK evidence (see §7)
    CP5_pre_ponr_witnesses.json
    CPA_last_reversible.json           # CP-A
    CP6_delete_complete.json
    CP7_import_complete.json
    CP8_special_handlers_complete.json
    CP9_uploads_complete.json
    CP10_post_verify_pass.json
    CP11_success_finalized.json
    CP12_maint_released.json
  checkpoints/.tmp/                    # staging for atomic rename
```

| Rule | Value |
|------|--------|
| Missing checkpoint file | Means “not yet achieved” (not success) |
| Presence of final JSON | Means checkpoint **committed** (atomic rename completed) |
| Secrets | **Forbidden** in checkpoint payloads (no passwords, tokens, DB passwords) |

---

## 3. Atomic write / rename rules (torn-write recovery)

Architecture §33: assume disk may tear last checkpoint write → **rename-atomic** checkpoint files.

### 3.1 Write algorithm (normative)

1. Validate payload against the checkpoint schema for that ID.  
2. Validate **prerequisite DAG** (§5) — reject if predecessors missing or OD-PIN order violated.  
3. Validate **state binding** (§6) — current job state must be in the allowed set.  
4. Write full payload to:  
   `checkpoints/.tmp/{CPID}_{uuid}.json`  
5. `fflush` / close file (platform durable close).  
6. Atomic rename/replace to final path:  
   `checkpoints/{filename}.json`  
7. Only after successful rename may the worker advance state that depends on this checkpoint.

### 3.2 Torn-write / crash rules

| Situation | Rule |
|-----------|------|
| `.tmp` file exists without final | Ignore tmp; checkpoint **not** achieved; redo from last final checkpoint |
| Final file partially written (non-atomic FS) | **Forbidden design** on Windows target — must use replace that is atomic for the volume; if not possible, write to unique final name then update a `checkpoints/MANIFEST.json` via atomic replace listing committed CP IDs |
| Duplicate write of same CP with different payload | **Reject** unless payload identical (byte-stable or canonical JSON equal) |
| Writing CP1 before CP4 | **Hard reject** (OD-PIN order) |

### 3.3 MANIFEST (optional but recommended)

`checkpoints/MANIFEST.json` lists committed checkpoint ids in write order; updated only via same atomic rename pattern after each CP commit.

---

## 4. Common envelope (all checkpoints)

Every checkpoint JSON **must** include:

| Field | Type | Rule |
|-------|------|------|
| `checkpoint_id` | string | e.g. `CP0`, `CP1`, `CP-A`, … |
| `checkpoint_name` | string | Architecture name |
| `job_id` | string | Matches WP-P1-02 |
| `package_id` | string | Bound package |
| `country_id` | integer | Bound country |
| `contract_revision` | integer | WP-P1-02 revision at write time |
| `written_at` | string (ISO-8601) | Commit time |
| `written_by` | string | `system` \| `super_admin` \| admin id string |
| `schema_version` | string | `1.0` for this WP |
| `payload` | object | Checkpoint-specific (§8) |

---

## 5. Prerequisite DAG (binding write order)

```text
CP0 ──► CP2 ──► CP3 ──► CP4 ──► CP1 ──► runbook_pre_ponr ──► CP5 ──► CP-A
                                                              │
                                                    [PONR boundary]
                                                              │
                                                              ▼
                                                         CP6 ──► CP7 ──► CP8 ──► CP9 ──► CP10 ──► CP11 ──► CP12
```

### 5.1 OD-PIN / Maint order (normative)

| Step | Checkpoint | Must already exist |
|------|------------|-------------------|
| 1 | CP4 `maintenance_verified` | CP3 |
| 2 | CP1 `anchor_pinned` | **CP4** + evidence of **NEW** Full Backup create → verify → pin |
| 3 | Any post-pin pre-PONR CP | CP1 |

**Forbidden:** CP1 without CP4.  
**Forbidden:** CP1 that references an existing/reused backup as session anchor (OD-PIN).  
**Forbidden:** CP-A or PONR without CP1 + `runbook_pre_ponr`.

### 5.2 Prerequisite table

| Checkpoint | Requires all of |
|------------|-----------------|
| CP0 | (none) |
| CP2 | CP0 |
| CP3 | CP0, CP2 |
| CP4 | CP0, CP2, CP3 |
| CP1 | CP0, CP2, CP3, **CP4** |
| `runbook_pre_ponr` | CP0–CP4, **CP1** |
| CP5 | CP0–CP4, CP1, `runbook_pre_ponr` |
| CP-A | CP0–CP5, CP1, `runbook_pre_ponr` |
| CP6 | CP-A (+ PONR entered) |
| CP7 | CP6 |
| CP8 | CP7 |
| CP9 | CP8 |
| CP10 | CP9 |
| CP11 | CP10 |
| CP12 | CP11 **or** (`cpr_rollback_completed` path — see §6.3) |

---

## 6. Checkpoint ↔ state binding (WP-P1-03)

### 6.1 When a checkpoint may be written

| Checkpoint | Allowed job states at write | Advances / confirms |
|------------|----------------------------|---------------------|
| CP0 | `cpr_gates_validating` → success edge | Gates OK before approvals/freeze |
| CP2 | `cpr_awaiting_approvals` (WF-B approve) or WF-A equivalent at freeze prep | Approvals complete |
| CP3 | entering/leaving `cpr_contract_frozen` | Contract frozen (WP-P1-02) |
| CP4 | `cpr_maintenance_on` | Maint ON + write-block proven |
| CP1 | `cpr_anchor_pinning` only **after** CP4 | OD-PIN complete |
| `runbook_pre_ponr` | `cpr_pre_ponr` | OD-RUNBOOK checklist done |
| CP5 | `cpr_pre_ponr` | Witnesses captured |
| CP-A | `cpr_pre_ponr` | **Last fully reversible idle point** |
| CP6 | `cpr_deleting` → complete | Delete finished |
| CP7 | `cpr_importing` → complete | Import finished |
| CP8 | after special handlers (still import phase or dedicated) | Handlers done |
| CP9 | `cpr_uploads_applying` → complete | Uploads done |
| CP10 | `cpr_post_verifying` → PASS | Verify PASS |
| CP11 | `cpr_succeeded` | Success sealed |
| CP12 | transition to `cpr_maintenance_released` | Maint released |

### 6.2 Pre-PONR vs post-PONR discipline

| Class | Checkpoints | Discipline |
|-------|-------------|------------|
| **Pre-PONR** | CP0, CP2, CP3, CP4, CP1, runbook, CP5, CP-A | On abort/cancel: leave files for audit; **no** requirement to write CP6+; Maint release via Super Admin from pre-PONR terminals (WP-P1-03 T25/T26) may write CP12 only if Maint was ON and Runbook/abort closeout satisfied |
| **CP-A** | `last_reversible` | **Last fully reversible idle point.** After CP-A commit, next successful production DELETE or uploads path replace = **PONR**. No CP6+ without CP-A |
| **Post-PONR** | CP6–CP11 | On OD-FAIL pause: **do not** write success CPs; retain last good CP; pause state in status (WP-P1-03). Resume continues from stage; Rollback does **not** invent CP10/CP11 |
| **Rollback path** | No CP6–CP11 success chain | After `cpr_rollback_completed`, CP12 may be written on maint release; optional `rollback_completed.json` evidence (non-CP architecture id) allowed under `checkpoints/` for audit |

### 6.3 CP12 on rollback success path

| Path | CP11 | CP12 |
|------|------|------|
| Success | Required before CP12 | After Super Admin maint release |
| Rollback completed | **Not** written | Allowed after Runbook closeout + Super Admin release (WP-P1-03 T57) |

---

## 7. OD-RUNBOOK checklist evidence (`runbook_pre_ponr.json`)

Not numbered in Architecture §18; required by OD-RUNBOOK before PONR. Stored beside checkpoints.

### 7.1 Required checklist fields (minimum — OD-RUNBOOK §15)

| Field | Rule |
|-------|------|
| `restore_package_id` | Equals contract `package_id` |
| `target_country_id` | Equals contract `country_id` |
| `target_country_code` | Equals contract `country_code` |
| `c8_overall_result` | Must be `SAFE` |
| `certified_inventory_snapshot_id` | Equals contract `inventory_snapshot_id` |
| `session_full_backup_id` | Equals contract / CP1 session backup id |
| `global_maintenance_active` | Must be `true` |
| `completed_by_admin_id` | Super Admin |
| `completed_at` | ISO-8601 |
| `audit_record_id` | Link to immutable audit event |

### 7.2 Binding rules

1. Must not be written before **CP1** and **CP4**.  
2. Must be written before **CP-A** and before transition T09 (`cpr_pre_ponr` → `cpr_deleting`).  
3. Fully audited (OD-RUNBOOK).  
4. GLOBAL Maint must not be released (CP12) until Runbook successfully completed (success or authorized abort/rollback closeout).

---

## 8. Per-checkpoint payload schemas

### CP0 — `gates_passed`

| Field | Required | Notes |
|-------|:--------:|-------|
| `c4_overall` | Y | `PASS` |
| `c5_overall` | Y | `pass` |
| `c5_recovery_score` | Y | ≥ 85 |
| `c6_status` | Y | ready / success |
| `c7_overall` | Y | `READY` |
| `c7_readiness_score` | Y | ≥ 90 |
| `c8_overall_result` | Y | `SAFE` only |
| `enablement_flag_observed` | Y | Usually false until OD-ENABLE |
| `schema_revision_observed` | Y | |
| `boundary_policy_version` | Y | |
| `report_hashes` | Y | Object of c4…c8 hashes matching contract |

### CP2 — `approvals_complete`

| Field | Required | Notes |
|-------|:--------:|-------|
| `workflow` | Y | `A` \| `B` |
| `super_admin_approval_id` | Y if B | WF-B |
| `wfa_protections_ack` | Y if A | Anchor/gates/maint/phrase/re-auth/audit/one-time still enforced later |
| `approval_fingerprint` | Y | |

### CP3 — `contract_frozen`

| Field | Required | Notes |
|-------|:--------:|-------|
| `contract_revision` | Y | |
| `contract_phase` | Y | `pre_pin` expected here |
| `package_fingerprint` | Y | |
| `fingerprint_digest` | Y | Digest of frozen fingerprint set |

### CP4 — `maintenance_verified`

| Field | Required | Notes |
|-------|:--------:|-------|
| `global_maintenance_on` | Y | `true` |
| `write_block_proof` | Y | Evidence id/description of proven blocked write |
| `maint_entered_at` | Y | |

**Must be committed before CP1.**

### CP1 — `anchor_pinned`

| Field | Required | Notes |
|-------|:--------:|-------|
| `session_full_backup_id` | Y | **NEW** backup for this session |
| `session_full_backup_fingerprint` | Y | |
| `verified` | Y | `true` |
| `pinned` | Y | `true` |
| `created_under_maintenance` | Y | `true` |
| `reused_existing_backup` | Y | Must be `false` (OD-PIN) |
| `cp4_reference` | Y | Confirms CP4 present |

### CP5 — `pre_ponr_witnesses`

| Field | Required | Notes |
|-------|:--------:|-------|
| `survivor_baseline_hash` | Y | |
| `global_baseline_hash` | Y | Incl. never-export / JE posture |
| `target_inventory_hash` | Y | |
| `inventory_snapshot_id` | Y | OD-INV bind |
| `captured_at` | Y | |

### CP-A — `last_reversible`

| Field | Required | Notes |
|-------|:--------:|-------|
| `runbook_evidence_ref` | Y | Path/id of `runbook_pre_ponr.json` |
| `one_time_authorization_id` | Y | WP-P1-02 |
| `phrase_challenge_id` | Y | Phrase `RESTORE` challenge record (detail WP-P1-06) |
| `contract_phase` | Y | Must be `pre_ponr` |
| `cp1_session_full_backup_id` | Y | |
| `reversible` | Y | `true` — documents last idle reversible point |
| `ponr_not_entered` | Y | `true` |

### CP6 — `delete_complete`

| Field | Required | Notes |
|-------|:--------:|-------|
| `tables_completed` | Y | Count/list summary |
| `delete_order_version` | Y | |
| `ponr_entered_at` | Y | |

### CP7 — `import_complete`

| Field | Required | Notes |
|-------|:--------:|-------|
| `batches_completed` | Y | 1→6 |
| `rows_imported` | Y | |

### CP8 — `special_handlers_complete`

| Field | Required | Notes |
|-------|:--------:|-------|
| `handlers` | Y | e.g. sequences, composites |
| `counters_not_lowered_ack` | Y | `true` |

### CP9 — `uploads_complete`

| Field | Required | Notes |
|-------|:--------:|-------|
| `scoped_only` | Y | `true` |
| `pre_image_manifest_id` | Y | |
| `files_applied_count` | Y | |

### CP10 — `post_verify_pass`

| Field | Required | Notes |
|-------|:--------:|-------|
| `verify_suite_result` | Y | `PASS` |
| `survivor_hash_match_cp5` | Y | `true` |
| `global_hash_match_cp5` | Y | `true` |
| `integrity_waiver` | Y | Must be `false` |

### CP11 — `success_finalized`

| Field | Required | Notes |
|-------|:--------:|-------|
| `reports_sealed` | Y | `true` |
| `report_ids` | Y | Array |

### CP12 — `maint_released`

| Field | Required | Notes |
|-------|:--------:|-------|
| `released_by_admin_id` | Y | Super Admin |
| `runbook_completed` | Y | `true` |
| `prior_terminal` | Y | `cpr_succeeded` \| `cpr_rollback_completed` \| pre-PONR terminals |
| `writers_restored` | Y | `true` |

---

## 9. JSON Schema — envelope + CP1 / CP4 / CP-A (critical)

Full per-CP JSON Schema packs may be split in coding phase; normative fields are §4 + §8. Critical order enforcement schemas:

### 9.1 CP4 required shape (excerpt)

```json
{
  "$id": "cpr_cp4_maintenance_verified",
  "type": "object",
  "required": ["checkpoint_id", "job_id", "payload"],
  "properties": {
    "checkpoint_id": { "const": "CP4" },
    "checkpoint_name": { "const": "maintenance_verified" },
    "payload": {
      "type": "object",
      "required": ["global_maintenance_on", "write_block_proof", "maint_entered_at"],
      "properties": {
        "global_maintenance_on": { "const": true },
        "write_block_proof": { "type": "string", "minLength": 1 },
        "maint_entered_at": { "type": "string" }
      }
    }
  }
}
```

### 9.2 CP1 required shape (excerpt) — rejects reuse / missing Maint

```json
{
  "$id": "cpr_cp1_anchor_pinned",
  "type": "object",
  "required": ["checkpoint_id", "job_id", "payload"],
  "properties": {
    "checkpoint_id": { "const": "CP1" },
    "checkpoint_name": { "const": "anchor_pinned" },
    "payload": {
      "type": "object",
      "required": [
        "session_full_backup_id",
        "session_full_backup_fingerprint",
        "verified",
        "pinned",
        "created_under_maintenance",
        "reused_existing_backup",
        "cp4_reference"
      ],
      "properties": {
        "verified": { "const": true },
        "pinned": { "const": true },
        "created_under_maintenance": { "const": true },
        "reused_existing_backup": { "const": false },
        "cp4_reference": { "type": "string", "minLength": 1 }
      }
    }
  }
}
```

### 9.3 CP-A required shape (excerpt)

```json
{
  "$id": "cpr_cpa_last_reversible",
  "type": "object",
  "required": ["checkpoint_id", "payload"],
  "properties": {
    "checkpoint_id": { "const": "CP-A" },
    "checkpoint_name": { "const": "last_reversible" },
    "payload": {
      "type": "object",
      "required": [
        "runbook_evidence_ref",
        "one_time_authorization_id",
        "phrase_challenge_id",
        "contract_phase",
        "cp1_session_full_backup_id",
        "reversible",
        "ponr_not_entered"
      ],
      "properties": {
        "contract_phase": { "const": "pre_ponr" },
        "reversible": { "const": true },
        "ponr_not_entered": { "const": true }
      }
    }
  }
}
```

---

## 10. Validation rules (implementer checklist)

1. Reject CP1 if CP4 file absent.  
2. Reject CP1 if `reused_existing_backup !== false`.  
3. Reject CP1 if `created_under_maintenance !== true`.  
4. Reject CP-A if `runbook_pre_ponr.json` absent or checklist incomplete.  
5. Reject CP6+ if CP-A absent.  
6. Reject CP10 if `integrity_waiver === true`.  
7. Reject CP12 if `runbook_completed !== true` or actor not Super Admin.  
8. On crash: ignore `.tmp`; resume from latest final checkpoint + WP-P1-03 state.  

---

## 11. Register citation table

| Rule | OD / Principle | Register anchor | Architecture § |
|------|----------------|-----------------|----------------|
| Maint before new Full Backup pin | OD-PIN; OD-MAINT | OD-PIN §15; OD-MAINT §15 | §6, §18 |
| Never reuse existing backup as anchor | OD-PIN | §15 Frozen | §6 |
| Runbook checklist min fields; audit; maint release gate | OD-RUNBOOK | §15 Frozen | §25 |
| Certified inventory bind | OD-INV | §15 Frozen | §18 CP5 / §37 |
| C8 SAFE in gates/runbook | OD-C8 | §15 Frozen | §18 CP0 |
| GLOBAL maint proof | OD-MAINT-SCOPE | §15 Frozen | §9, CP4 |
| Atomic rename / torn write | (baseline) | — | §33 |
| CP-A last reversible | (baseline) | — | §18 CP-A, §10.3 |
| No verify waiver | OD-VERIFY-WARN; Integrity | §15 / Principle | §19, CP10 |

---

## 12. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Schema enforces OD-PIN order (Maint before new Full Backup pin) | **PASS** — §5.1, §5.2, CP1 requires CP4 + `created_under_maintenance` + `reused_existing_backup=false` |
| CP-A last fully reversible idle point documented | **PASS** — §6.2, §8 CP-A |
| Torn-write recovery rule stated | **PASS** — §3 |

Additional:

| Check | Result |
|-------|--------|
| Directory layout defined | **PASS** — §2 |
| All CP0–CP12 + CP-A schemas/payloads | **PASS** — §8 |
| Bound to WP-P1-03 states | **PASS** — §6 |
| OD-RUNBOOK evidence bound | **PASS** — §7 |
| Pre/post-PONR discipline | **PASS** — §6.2 |

---

## 13. Assumptions

1. Architecture §18 lists CP1 before CP4 numerically; **write order** follows OD-PIN / §6 (CP4 then CP1). This is alignment to OWNER_APPROVED order, not an architecture redesign.  
2. `runbook_pre_ponr.json` is an evidence file required by OD-RUNBOOK; not a renumbering of Architecture CP ids.  
3. Optional `MANIFEST.json` recommended for Windows durability.  
4. Pause/failure evidence files beyond CPs are detailed in WP-P1-09.  

---

## 14. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Writing CP1 before Maint (CP4) | High | Hard reject §5.1 / §10 |
| Treating numeric CP order as chronology | Medium | §1, §5 callout |
| PONR without CP-A / runbook | High | §5.2, §10 |
| Non-atomic checkpoint write | High | §3 |

No architectural insufficiency. No defect found in WP-P1-02/03 requiring edits.

---

## 15. Out of scope

- Lock formats (WP-P1-05)  
- Authority UX for phrase challenge (WP-P1-06)  
- PHP checkpoint writer implementation  

---

*End of WP-P1-04. STOP — do not begin WP-P1-05 until Owner review and approval.*
