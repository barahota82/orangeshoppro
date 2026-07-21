# WP-P5-02 — PONR Target-Slice DELETE Engine

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P5-02** — PONR Target-Slice DELETE Engine |
| **Artifact-ID** | `CPR-P5-WP02-TARGET_SLICE_DELETE` |
| **Status** | COMPLETE |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Depends on** | WP-P5-01 · P4-PrePONR-Baseline (CP-A) · P3 State/Checkpoint/Lock/Gate/Authority |
| **Maps to** | Architecture §6, §10.2 A, §10.3, §18 CP6 · OD-FAIL-DELETE · OD-ENABLE |
| **Scaffold** | `P5-02-delete-live` |

---

## 1. Purpose

Implement the **live PONR Target-Slice DELETE** engine:

1. Enter PONR only from approved pre-PONR state (`cpr_pre_ponr` + CP-A + sealed gates/authority/runbook + lock).  
2. DELETE only the approved target slice bound to the execution contract.  
3. Deterministic `delete_order` · sealed mutation manifest · sealed DELETE report · CP6.  
4. Fail-closed; full audit; recovery metadata (OD-FAIL-DELETE).  

**Explicitly out of scope:**

- IMPORT engine (WP-P5-03)  
- Special handlers (WP-P5-04)  
- Country uploads (WP-P5-05)  
- Enablement TRUE / production SQL while ops enablement FALSE  
- Architecture / OWNER_APPROVED edits  

---

## 2. Implementation root

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_delete_live.php` | Live PONR DELETE engine |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_DELETE_LIVE_DIRNAME`; scaffold `P5-02-delete-live` |
| `scripts/backup/country_production/self_test_cpr_delete_live.php` | Self-tests |
| This document | Design + AC |

**Consumed (not redesigned):** State Engine (T09) · Checkpoint Engine (CP6) · Lock live · Gates live · Authority live · Witnesses/CP-A · Contract · Job identity.

---

## 3. Live API

| API | Role |
|-----|------|
| `orange_cpr_delete_live_run($env, $jobId, $request)` | Full DELETE ceremony through CP6 |
| `orange_cpr_delete_live_validate_slice` | Contract-bound slice validation |
| `orange_cpr_delete_live_load_latest($cprRoot, $jobId, 'report'\|'manifest')` | Load sealed artifacts |

### Request (minimum)

- `actor_admin_id` + `actor_is_super_admin=true`  
- `lease_token` + `worker_id` (lock ownership)  
- `target_slice`: `{ country_id, country_code, tables: [{ table, membership_key: country_id, row_ids: [] }] }`  

### Enforcement

| Rule | Behavior |
|------|----------|
| PONR entry | T09 only from `cpr_pre_ponr` with phrase/reauth/runbook/C8 SAFE |
| Scope | Allowlisted membership tables only; country must match contract |
| Expansion | Extra countries / non-allowlisted tables → fail-closed |
| Order | Canonical `c1.1-delete_order/1` |
| Idempotent | Sealed complete report + CP6 → success without re-apply |
| Replay | Partial/mismatched artifacts → refuse |
| Enablement | Ops flag FALSE; **no production SQL** (`production_sql_executed=false`) |
| IMPORT/uploads | Hard-disabled knobs |
| Post-complete state | Remains `cpr_deleting` (no auto T10) |

### Enablement / production SQL note

Per Architecture roadmap (*P5 + OD-ENABLE false until drills*) and OD-ENABLE: ops enablement stays **FALSE**. This WP executes DELETE against the **job-bound sealed target-slice ledger** under `delete_live/` and records `production_sql_executed=false`. Production DB SQL DELETE remains gated by future OD-ENABLE path; engine control plane, PONR state, CP6, seals, and audit are live.

---

## 4. Artifacts

| File | Content |
|------|---------|
| `delete_live/target_slice_ledger.json` | Before/deleted/remaining ledger |
| `delete_live/cpr_delete_manifest_*.json` + `…_manifest_latest.json` | Sealed mutation manifest |
| `delete_live/cpr_delete_report_*.json` + `…_report_latest.json` | Sealed DELETE execution report + recovery metadata |
| `checkpoints/CP6_delete_complete.json` | Sealed CP6 |

**Audit:** `cpr.delete_live_ponr_enter` · `cpr.delete_live_apply` · `cpr.delete_live_complete`

**Recovery metadata:** OD-FAIL-DELETE pause semantics; session Full Backup id; `import_not_started`; maint remains ON; no auto-rollback.

---

## 5. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Live PONR Target-Slice DELETE engine implemented | **PASS** |
| AC2 | Operates only on contract-approved target slice | **PASS** |
| AC3 | Integrates State / Checkpoint / Lock / Gate / Authority / CP-A / Contract / Job | **PASS** |
| AC4 | PONR entry only from approved pre-PONR state | **PASS** |
| AC5 | Deterministic delete order; no scope expansion | **PASS** |
| AC6 | Idempotent complete path; replay refused | **PASS** |
| AC7 | Fail-closed on validation / lock / gate / authority failures | **PASS** |
| AC8 | No privilege bypass | **PASS** |
| AC9 | Sealed DELETE report + mutation manifest + audit + recovery metadata | **PASS** |
| AC10 | IMPORT and uploads remain disabled | **PASS** |
| AC11 | Enablement FALSE; no production SQL; Architecture/OD unchanged | **PASS** |
| AC12 | Self-tests cover required cases; PHP lint; full CPR suite green | **PASS** |
| AC13 | P5 Artifact Index WP-P5-02 COMPLETE | **PASS** |

---

## 6. Stop rule

**WP-P5-02 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P5-03** until Owner review and approval.

---

*End of WP-P5-02.*
