# Country Production Restore — P1 Execution Contract & Job Identity

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-02** — Job Identity, Idempotency & Execution Contract Schema |
| **Artifact-ID** | `CPR-P1-WP02-EXECUTION_CONTRACT` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P1_ARTIFACT_INDEX.md` (WP-P1-01) |
| **Coding** | **No** mutation engine PHP/SQL/HTTP/UI in this WP |

---

## 1. Purpose

Define immutable CPR **job identity**, **idempotency**, and the frozen **`cpr_execution_contract`** fingerprint set so later phases cannot silently swap packages, countries, anchors, or gate reports after authorization.

This WP does **not** implement workers, UI, or database tables. It specifies contracts only.

---

## 2. Hard constraints

1. Register SoT wins; no OD reopen; no architecture edit.  
2. Production enablement remains **hard false** in design until OD-ENABLE path (flag field documented; flip is out of scope).  
3. **No HTTP-triggered production mutation** — contract is consumed by long-running non-HTTP workers; Admin/Super Admin UI is control plane only (Architecture §4.1 / WP-P1-01 §3).  
4. C3–C8 are immutable inputs; contract stores **hashes** of their reports, not redefined semantics.  
5. Session Full Backup id in the contract is the **OD-PIN** anchor for **this job only** — existing backups must never be reused as that anchor.

---

## 3. Job identity

### 3.1 Required identity fields

| Field | Type | Rule |
|-------|------|------|
| `job_id` | string (UUID v4 recommended) | Globally unique per CPR production job; never reused |
| `idempotency_key` | string | Unique; **bound to** `package_fingerprint` + `country_id` + `session_full_backup_id` (Architecture §14.1) |
| `package_id` | string | Exact Country Recovery Package id for this job |
| `country_id` | integer | Target country; exact equality only (C1.1 D2) |
| `country_code` | string | Canonical code bound with `country_id` at job create; mismatch later → abort |
| `created_at` | string (ISO-8601) | Job creation timestamp |
| `workflow` | enum | `A` \| `B` (OD-DUAL) — recorded at create/request |

### 3.2 Identity rules

1. A new production attempt after successful finalize **requires a new `job_id`** (Architecture §14.2).  
2. `idempotency_key` must change if package fingerprint, country, or session Full Backup id changes.  
3. Another job’s approvals / one-time authorization **must not** be reused (Architecture §14.4).  
4. Country Admin may create/request only for **own** `country_id` (OD-PERM) — enforcement design belongs to WP-P1-06; identity schema carries `country_id` for binding.

---

## 4. Execution contract object (`cpr_execution_contract`)

The contract is the **frozen fingerprint set** bound to `job_id`. After `contract_frozen = true`, any material drift vs live re-read **aborts pre-PONR** (Architecture §35 Fingerprint continuity; §37).

### 4.1 Required fingerprint / binding fields

| Field | Required | Meaning |
|-------|:--------:|---------|
| `job_id` | Y | Same as job identity |
| `package_id` | Y | Bound package |
| `package_fingerprint` | Y | Package content fingerprint (C4 / manifest) |
| `country_id` | Y | Target country |
| `country_code` | Y | Bound code |
| `schema_revision_expected` | Y | Schema revision expected by package / cert expectations (OD-SCHEMA / OD-FA-SCHEMA) |
| `boundary_policy_version` | Y | C1.1 / boundary matrix version string used at freeze |
| `dependency_graph_version` | Y | Dependency graph version used at freeze |
| `registry_revision` | Y | `backup_table_registry` (or successor) revision at freeze |
| `c4_report_hash` | Y | Hash of C4 verify report |
| `c5_report_hash` | Y | Hash of C5 Country DRV report |
| `c6_report_hash` | Y | Hash of C6 shadow restore report |
| `c7_report_hash` | Y | Hash of C7 shadow verify report |
| `c8_report_hash` | Y | Hash of C8 dry-run report |
| `c8_overall_result` | Y | Must equal `SAFE` at freeze and at pre-PONR re-check (OD-C8) |
| `inventory_snapshot_id` | Y | Certified immutable production inventory id (OD-INV) |
| `inventory_snapshot_hash` | Y | Hash of certified inventory snapshot |
| `production_db_identity_hash` | Y | Hash binding production DB name/identity for this deployment |
| `session_full_backup_id` | Y* | OD-PIN session Full Backup package/id (*required before PONR; see §5 lifecycle) |
| `session_full_backup_fingerprint` | Y* | Verified backup fingerprint (*with pin) |
| `session_full_backup_pinned` | Y* | Boolean true required before PONR |
| `contract_frozen` | Y | Boolean |
| `frozen_at` | Y if frozen | ISO-8601 |
| `frozen_by_admin_id` | Y if frozen | Actor who froze (Super Admin path) |
| `one_time_authorization_id` | Y before PONR | Single-use authorization record id (OD-DUAL / OD-PHRASE binding) |
| `enablement_flag_observed` | Y | Observed value of production enablement flag at freeze / pre-PONR re-read |

### 4.2 Lifecycle relative to OD-PIN (stage order)

Architecture §6 order (binding):

1. Contract freeze may capture **package + C4–C8 + inventory + DB identity** fingerprints **before** Maintenance.  
2. **GLOBAL Maintenance ON** (OD-MAINT / OD-MAINT-SCOPE).  
3. **NEW** session Full Backup create → verify → pin (OD-PIN).  
4. Contract must be **amended once** under Maint to attach `session_full_backup_*` fields, then re-sealed / re-hashed as `contract_revision` (see §4.3).  
5. PONR forbidden unless `session_full_backup_pinned === true` and all required fingerprints present.

**Normative:** Existing backups must **never** be written into `session_full_backup_id` as the CPR rollback anchor (OD-PIN).

### 4.3 Contract revision

| Field | Rule |
|-------|------|
| `contract_revision` | Integer starting at `1` at first freeze |
| Allowed increment | **Only** to attach OD-PIN session backup fields after Maint ON, or to record authority approval fingerprints that were pending at first freeze — **never** to change `package_fingerprint` or `country_id` |
| Illegal change | Any change to package/country/C4–C8 hashes after freeze → **reject**; require new job |

### 4.4 Freeze and re-read rules

1. **Freeze:** Persist contract; set `contract_frozen = true`.  
2. **Pre-PONR re-read:** Recompute/re-load package fingerprint, C4–C8 report hashes, inventory hash, production DB identity, enablement flag, C8 overall result.  
3. **Drift:** Any mismatch vs frozen contract → **abort pre-PONR** (`cpr_contract_fingerprint_drift` or equivalent); no mutation.  
4. **Approval fingerprint drift:** Abort pre-PONR (Architecture §12).  
5. Live DB reads may **only verify** the OD-INV certified snapshot; they must **never replace** `inventory_snapshot_id` / hash (OD-INV).

### 4.5 One-time authorization binding

| Rule | Source |
|------|--------|
| Phrase acceptance and password re-auth produce a **one-time** `one_time_authorization_id` bound to `job_id` + current `contract_revision` + fingerprint digest of the frozen contract | OD-DUAL, OD-PHRASE |
| Authorization must not be reusable on another job or after contract package/country change | Architecture §14.4 |
| Phrase is exactly `RESTORE` (design constant; challenge UX in WP-P1-06) | OD-PHRASE |
| Authorization does **not** bypass gates, anchor, maintenance, or audit | OD-PHRASE frozen wording |

### 4.6 Enablement observation

| Rule | Source |
|------|--------|
| `enablement_flag_observed` must be recorded at freeze and re-checked pre-PONR | OD-ENABLE |
| Until OD-ENABLE preconditions are met, observed flag must be **false**; design treats true-without-preconditions as illegal | OD-ENABLE |
| This WP does not implement flag flip | P1 scope |

---

## 5. JSON Schema — `cpr_execution_contract`

Validators implementing this schema **must reject** documents missing any required fingerprint field for the phase being validated.

**Phase validation profiles:**

| Profile | When | Extra required |
|---------|------|----------------|
| `freeze_initial` | First contract freeze | All fields except `session_full_backup_*` may be null **only** if `contract_phase = "pre_pin"` |
| `pre_ponr` | Immediately before PONR | **All** fields required; `c8_overall_result` = `"SAFE"`; `session_full_backup_pinned` = true; `enablement_flag_observed` = true only if OD-ENABLE path satisfied (else false and PONR still blocked by gate suite — WP-P1-08) |

For **pre_ponr** acceptance of this WP’s schema tests: missing any of  
`package_fingerprint`, `c4_report_hash`…`c8_report_hash`, `inventory_snapshot_hash`, `production_db_identity_hash`, `session_full_backup_id`, `session_full_backup_fingerprint`  
→ **REJECT**.

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://orange.local/schemas/cpr_execution_contract.json",
  "title": "cpr_execution_contract",
  "type": "object",
  "additionalProperties": false,
  "required": [
    "job_id",
    "idempotency_key",
    "package_id",
    "package_fingerprint",
    "country_id",
    "country_code",
    "workflow",
    "schema_revision_expected",
    "boundary_policy_version",
    "dependency_graph_version",
    "registry_revision",
    "c4_report_hash",
    "c5_report_hash",
    "c6_report_hash",
    "c7_report_hash",
    "c8_report_hash",
    "c8_overall_result",
    "inventory_snapshot_id",
    "inventory_snapshot_hash",
    "production_db_identity_hash",
    "contract_frozen",
    "contract_revision",
    "contract_phase",
    "enablement_flag_observed"
  ],
  "properties": {
    "job_id": { "type": "string", "minLength": 1 },
    "idempotency_key": { "type": "string", "minLength": 1 },
    "package_id": { "type": "string", "minLength": 1 },
    "package_fingerprint": { "type": "string", "minLength": 16 },
    "country_id": { "type": "integer", "minimum": 1 },
    "country_code": { "type": "string", "minLength": 2 },
    "workflow": { "type": "string", "enum": ["A", "B"] },
    "schema_revision_expected": { "type": ["integer", "string"] },
    "boundary_policy_version": { "type": "string", "minLength": 1 },
    "dependency_graph_version": { "type": "string", "minLength": 1 },
    "registry_revision": { "type": ["integer", "string"] },
    "c4_report_hash": { "type": "string", "minLength": 16 },
    "c5_report_hash": { "type": "string", "minLength": 16 },
    "c6_report_hash": { "type": "string", "minLength": 16 },
    "c7_report_hash": { "type": "string", "minLength": 16 },
    "c8_report_hash": { "type": "string", "minLength": 16 },
    "c8_overall_result": { "type": "string", "const": "SAFE" },
    "inventory_snapshot_id": { "type": "string", "minLength": 1 },
    "inventory_snapshot_hash": { "type": "string", "minLength": 16 },
    "production_db_identity_hash": { "type": "string", "minLength": 16 },
    "session_full_backup_id": { "type": ["string", "null"] },
    "session_full_backup_fingerprint": { "type": ["string", "null"] },
    "session_full_backup_pinned": { "type": ["boolean", "null"] },
    "contract_frozen": { "type": "boolean" },
    "contract_revision": { "type": "integer", "minimum": 1 },
    "contract_phase": { "type": "string", "enum": ["pre_pin", "pinned", "pre_ponr"] },
    "frozen_at": { "type": ["string", "null"], "format": "date-time" },
    "frozen_by_admin_id": { "type": ["integer", "null"], "minimum": 1 },
    "one_time_authorization_id": { "type": ["string", "null"], "minLength": 1 },
    "enablement_flag_observed": { "type": "boolean" },
    "http_mutation_forbidden": { "type": "boolean", "const": true }
  },
  "allOf": [
    {
      "if": {
        "properties": { "contract_phase": { "const": "pre_ponr" } },
        "required": ["contract_phase"]
      },
      "then": {
        "required": [
          "session_full_backup_id",
          "session_full_backup_fingerprint",
          "session_full_backup_pinned",
          "one_time_authorization_id",
          "frozen_at",
          "frozen_by_admin_id"
        ],
        "properties": {
          "session_full_backup_id": { "type": "string", "minLength": 1 },
          "session_full_backup_fingerprint": { "type": "string", "minLength": 16 },
          "session_full_backup_pinned": { "type": "boolean", "const": true },
          "one_time_authorization_id": { "type": "string", "minLength": 1 },
          "contract_frozen": { "const": true },
          "http_mutation_forbidden": { "const": true }
        }
      }
    },
    {
      "if": {
        "properties": { "contract_phase": { "const": "pinned" } },
        "required": ["contract_phase"]
      },
      "then": {
        "required": [
          "session_full_backup_id",
          "session_full_backup_fingerprint",
          "session_full_backup_pinned"
        ],
        "properties": {
          "session_full_backup_pinned": { "type": "boolean", "const": true }
        }
      }
    }
  ]
}
```

### 5.1 Example — invalid (missing fingerprints) → REJECT

```json
{
  "job_id": "11111111-1111-4111-8111-111111111111",
  "idempotency_key": "incomplete",
  "package_id": "pkg-1",
  "package_fingerprint": "aaaaaaaaaaaaaaaa",
  "country_id": 1,
  "country_code": "KW",
  "workflow": "A",
  "schema_revision_expected": 121,
  "boundary_policy_version": "C1.1",
  "dependency_graph_version": "1",
  "registry_revision": 121,
  "c4_report_hash": "bbbbbbbbbbbbbbbb",
  "c5_report_hash": "cccccccccccccccc",
  "c6_report_hash": "dddddddddddddddd",
  "c7_report_hash": "eeeeeeeeeeeeeeee",
  "c8_report_hash": "ffffffffffffffff",
  "c8_overall_result": "SAFE",
  "inventory_snapshot_id": "inv-1",
  "inventory_snapshot_hash": "1111111111111111",
  "production_db_identity_hash": "2222222222222222",
  "contract_frozen": true,
  "contract_revision": 1,
  "contract_phase": "pre_ponr",
  "enablement_flag_observed": false,
  "http_mutation_forbidden": true
}
```

**Reject reason:** `pre_ponr` requires `session_full_backup_id`, `session_full_backup_fingerprint`, `session_full_backup_pinned`, `one_time_authorization_id`, `frozen_at`, `frozen_by_admin_id`.

### 5.2 Example — invalid C8 → REJECT

Any document with `"c8_overall_result": "WARNING"` (or other than `SAFE`) fails schema (`const: "SAFE"`) — OD-C8.

### 5.3 Non-HTTP mutation assumption (normative)

| Field / rule | Value |
|--------------|--------|
| `http_mutation_forbidden` | Always `true` in contract documents |
| IIS/Plesk PHP request | Must not perform production DELETE/IMPORT/uploads apply |
| Control plane | Super Admin dashboard may orchestrate workers only |

---

## 6. Idempotency & retry (contract-level)

| Rule | Architecture / OD |
|------|-------------------|
| Unique `job_id` + `idempotency_key` bound to package fingerprint + country + session backup id | §14.1 |
| Successful finalize → terminal; new attempt → new job | §14.2 |
| Re-entry to IMPORT only from explicit dirty/retry states with **contract unchanged** | §14.3 |
| No statement-offset resume into half-applied slice (detail in WP-P1-09) | §13; OD-FAIL-IMPORT |
| Sequence handlers must not lower counters on retry | §14.5; C1.1 |

---

## 7. Register citation table

| Rule / field | OD / Principle | Register anchor | Architecture § | Notes |
|--------------|----------------|-----------------|----------------|-------|
| Session Full Backup new under Maint; never reuse | OD-PIN | §15 Frozen: new Full Backup after Maintenance; never reuse | §6, §11 | Lifecycle §4.2 |
| Certified inventory snapshot mandatory; live verify only | OD-INV | §15 Frozen | §6, §37.21 | `inventory_snapshot_*` |
| C8 SAFE only; no WARNING | OD-C8 | §15 Frozen | §6, §35, §37.16 | `c8_overall_result` const |
| Enablement disabled by default | OD-ENABLE | §15 Frozen | §37 A1 | `enablement_flag_observed` |
| One-time auth; phrase does not bypass gates | OD-DUAL / OD-PHRASE | §15 Frozen | §7–§8, §14.4 | `one_time_authorization_id` |
| Schema revision expectations | OD-SCHEMA / OD-FA-SCHEMA | §15 Frozen | §36, §37 | `schema_revision_expected` |
| Fingerprint continuity; drift abort | Integrity Principle | Integrity Principle | §35 | Re-read rules |
| No HTTP mutation | Architecture hard rule | (baseline) | §2, §4.1, §30 | `http_mutation_forbidden` |
| Country-scoped job binding | OD-PERM / Isolation | OD-PERM §15; Isolation Principle | §7, §27 | `country_id` |
| Idempotency key binding | (baseline) | — | §14 | Identity §3 |

---

## 8. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Contract schema rejects missing required fingerprints | **PASS** — §5 `required` + `pre_ponr` `allOf`; §5.1 example |
| Documents one-time authorization binding | **PASS** — §4.5 |
| No HTTP mutation assumptions | **PASS** — §2.3, §5.3, `http_mutation_forbidden: true` |

Additional checks:

| Check | Result |
|-------|--------|
| OD-PIN session backup lifecycle after Maint | **PASS** — §4.2 |
| C8 WARNING rejected by schema | **PASS** — §5.2 |
| Drift → abort pre-PONR | **PASS** — §4.4 |
| Artifact name matches WP-P1-01 index | **PASS** |

---

## 9. Assumptions

1. Hash algorithm (e.g. SHA-256 hex) will be fixed in coding phase; this contract requires opaque hex/string length ≥ 16.  
2. Exact storage path for the contract file on disk is `{workRoot}/country_production/{job_id}/cpr_execution_contract.json` (architectural convention; workers not implemented here).  
3. Gate evaluation suite (full §37 ordering) is WP-P1-08; this WP only defines the contract object those gates bind to.  
4. Authority/runbook challenge UI fields are WP-P1-06; this WP only binds `one_time_authorization_id`.  

---

## 10. Risks

| Risk | Severity | Mitigation in this WP |
|------|----------|------------------------|
| Incomplete fingerprint set → silent package swap | High | Required hashes + drift abort (§4.1, §4.4, §5) |
| Reusing old Full Backup as pin | High | OD-PIN lifecycle forbids (§4.2) |
| Freezing C8 WARNING into contract | High | `const: "SAFE"` |
| Premature PONR before pin fields | High | `pre_ponr` schema profile |

No architectural insufficiency discovered. No escalation.

---

## 11. Out of scope (explicit)

- State transition matrix (WP-P1-03)  
- Checkpoint file schemas (WP-P1-04)  
- Lock formats (WP-P1-05)  
- PHP/CLI implementation  

---

*End of WP-P1-02. STOP — do not begin WP-P1-03 until Owner review and approval.*
