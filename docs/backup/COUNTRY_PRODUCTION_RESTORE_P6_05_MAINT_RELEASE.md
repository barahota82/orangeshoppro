# WP-P6-05 — Maintenance Release / Closeout (CP12)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P6-05** — Maintenance Release / Closeout (CP12) |
| **Artifact-ID** | `CPR-P6-WP05-MAINT_RELEASE` |
| **Phase** | P6 — Verify + Rollback Integration |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P6-03 (success path) **or** WP-P6-04 (rollback path) |
| **Unlocks** | WP-P6-06 Integration Baseline freeze |
| **Scaffold** | `P6-05-maint-release` |
| **Enablement** | **FALSE** (hard) — no production SQL; no production upload mutation |
| **Architecture / OWNER_APPROVED** | **Not modified** |

---

## 1. Objective

Implement the **GLOBAL Maintenance Release engine only**. Release GLOBAL maintenance **exactly once** after an approved terminal path (CP11 success **or** approved rollback completion), produce **CP12** (`maint_released`), seal release report/manifest, restore writers under Runbook gate (OD-RUNBOOK / OD-MAINT).

---

## 2. Hard rules

- Terminal paths only: `cpr_succeeded` + CP11 + sealed success report, **or** `cpr_rollback_completed` + sealed rollback report  
- No early release; no replay; no privilege bypass; no partial release; no automatic release  
- Runbook completed + `runbook_evidence_ref` + `write_block_cleared_proof` required  
- Authorized post-PONR lock closeout before release  
- Fail-closed on state / contract / checkpoint / authority / lock inconsistency  
- Enablement FALSE; no production SQL; no production upload mutation  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_maint_release_live.php` | `orange_cpr_maint_release_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_MAINT_RELEASE_DIRNAME`; scaffold |
| `includes/backup/country_production/cpr_p6_control_plane.php` | `maint_release_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_maint_release_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md` | WP-P6-05 COMPLETE |

### Flow

1. Preconditions: terminal path + Runbook + lock/authority/contract + maint ON + no CP12  
2. Authorized lock closeout  
3. T14 (success) or T57 (rollback) → `cpr_maintenance_released`  
4. GLOBAL maint state OFF  
5. Seal release report + release manifest  
6. **CP12** (`runbook_completed`, `writers_restored`, `prior_terminal`)  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | GLOBAL Maint Release engine implemented | **PASS** |
| AC2 | Runs only after CP11 success or approved rollback completion | **PASS** |
| AC3 | Produces CP12 | **PASS** |
| AC4 | Releases GLOBAL maintenance exactly once | **PASS** |
| AC5 | Integrates State / Checkpoint / Lock / Authority / recovery / audit / contract / job identity | **PASS** |
| AC6 | No early release; no replay; no privilege bypass; no partial release; fail-closed | **PASS** |
| AC7 | Sealed release report + manifest + audit + recovery + CP12 | **PASS** |
| AC8 | Enablement FALSE; no production SQL/uploads mutation; Architecture/OD unchanged | **PASS** |
| AC9 | Self-tests + lint + full CPR suite green | **PASS** |

---

## 5. Stop rule

**WP-P6-05 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P6-06** until Owner review and approval.

---

*End of CPR-P6-WP05-MAINT_RELEASE.*
