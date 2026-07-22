# WP-P6-04 — Session Full-Anchor Rollback Integration (OD-ROLLBACK)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P6-04** — Session Full-Anchor Rollback Integration (OD-ROLLBACK) |
| **Artifact-ID** | `CPR-P6-WP04-ROLLBACK_INTEGRATION` |
| **Phase** | P6 — Verify + Rollback Integration |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P6-01; pause/fail states; OD-PIN (P4); P5 apply manifests |
| **Unlocks** | WP-P6-05 Maintenance Release (rollback closeout path) |
| **Scaffold** | `P6-04-rollback` |
| **Enablement** | **FALSE** (hard) — no production SQL; no production upload mutation |
| **Architecture / OWNER_APPROVED** | **Not modified** |

---

## 1. Objective

Implement the **Rollback engine only**, executing exclusively under frozen **OD-ROLLBACK**: Super Admin dashboard action, available only when a CPR session is paused because of failure, targeting the **session Full Backup (OD-PIN)** recovery boundary. Restore must be complete (never partial/undefined). GLOBAL maintenance remains ON (CP12 deferred to WP-P6-05).

---

## 2. Hard rules

- OD-ROLLBACK only: SA + re-auth + phrase `RESTORE`; never automatic; never Country Admin  
- Approved pause/failure states only (`cpr_paused_*_failed` / `cpr_failed_post_ponr` / retry from `cpr_paused_rollback_failed`)  
- Resume from sealed recovery checkpoints only (last good CP; not CP10–CP12 success path)  
- Recovery boundary = contract/OD-PIN `session_full_backup_id` + fingerprint  
- No replay of completed rollback; no privilege bypass; no cross-country; no out-of-scope  
- No maintenance release / CP12  
- Fail-closed on missing recovery metadata, corrupt checkpoint, contract/boundary mismatch  
- Bind DELETE / IMPORT / Special / Uploads sealed manifests into recovery evidence  
- Enablement FALSE; no production SQL; no production upload mutation  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_rollback_live.php` | `orange_cpr_rollback_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_ROLLBACK_DIRNAME`; scaffold `P6-04-rollback` |
| `includes/backup/country_production/cpr_p6_control_plane.php` | `rollback_integration_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_rollback_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md` | WP-P6-04 COMPLETE |

### Flow (P1-09 §6.5)

1. Preconditions: pause eligibility; OD-PIN pinned; recovery metadata; sealed recovery CP; apply manifests; lock/gate/authority; maint ON; phrase + reauth  
2. Seal `cpr_rollback_authorization` (`automatic=false`)  
3. T50–T53 / T56 / T61 → `cpr_rolling_back`  
4. Full-anchor restore evidence (enablement-FALSE sealed ledger; `restore_complete=true`; `partial_rollback=false`)  
5. Seal rollback report + rollback manifest + recovery evidence  
6. T54 → `cpr_rollback_completed`; maint remains ON  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Rollback engine implemented | **PASS** |
| AC2 | Executes only under OD-ROLLBACK conditions / approved pause states | **PASS** |
| AC3 | Restores to last valid sealed OD-PIN recovery boundary; never partial/undefined | **PASS** |
| AC4 | Integrates State / Checkpoint / Lock / recovery metadata / DELETE·IMPORT·Special·Upload manifests / audit / Execution Contract / job identity | **PASS** |
| AC5 | No replay; no privilege bypass; no cross-country; no out-of-scope; no maint release; fail-closed | **PASS** |
| AC6 | Produces sealed Rollback report + manifest + recovery evidence + audit + T50–T54 transitions | **PASS** |
| AC7 | Enablement FALSE; no production SQL/uploads mutation; GLOBAL maint ON; Architecture/OD unchanged | **PASS** |
| AC8 | Self-tests cover valid/invalid/missing meta/corrupt CP/replay/contract/cross-country/boundary/audit/manifest/maint/no-mutation | **PASS** |
| AC9 | PHP lint + complete CPR self-test suite green | **PASS** |

---

## 5. Stop rule

**WP-P6-04 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P6-05** until Owner review and approval.

---

*End of CPR-P6-WP04-ROLLBACK_INTEGRATION.*
