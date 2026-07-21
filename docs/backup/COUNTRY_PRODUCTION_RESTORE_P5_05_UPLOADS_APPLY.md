# WP-P5-05 — Country Uploads Apply (OD-UPLOADS)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P5-05** — Country Uploads Apply (OD-UPLOADS) |
| **Artifact-ID** | `CPR-P5-WP05-UPLOADS_APPLY` |
| **Phase** | P5 — Production Apply |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P5-04 COMPLETE (CP8) |
| **Unlocks** | WP-P5-06 Integration Baseline |
| **Scaffold** | `P5-05-uploads-live` |
| **Enablement** | **FALSE** (hard) — no production SQL; no live uploads-tree mutation |
| **Architecture / OWNER_APPROVED** | **Not modified** |

---

## 1. Objective

Implement the Country Uploads Apply layer per OD-UPLOADS and WP-P1-10: strictly scoped allowlist, mandatory pre-image, deterministic apply order, fail-closed integrity, CP9 — only after DELETE + IMPORT + Special Handlers (CP6–CP8).

---

## 2. Hard rules (OD-UPLOADS)

- Never full-tree replace / rename of production `uploads/`
- Never modify survivor-country or out-of-scope paths
- Manifest validation + fingerprint before every apply
- Scoped pre-image of every path that may be modified
- Fail → GLOBAL Maint remains; Super Admin Resume/Rollback only
- No best-effort / partial acceptance / privilege bypass / replay

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_uploads_live.php` | Live engine `orange_cpr_uploads_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_UPLOADS_LIVE_DIRNAME`; scaffold |
| `includes/backup/country_production/cpr_p5_control_plane.php` | `uploads_apply_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_uploads_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md` | WP-P5-05 COMPLETE |

### Enablement FALSE path

Staging / pre-image / apply execute against job-bound `uploads_apply/` (virtual production). Reports `production_sql_executed=false` and `production_uploads_mutated=false`. Live production uploads root is not written.

### Flow

1. Preconditions: CP6–CP8, sealed special report, contract/job/country/schema bind, lock/gate/authority  
2. T11 → `cpr_uploads_applying`  
3. Validate approved upload manifest → allowlist → materialize → plan → pre-image → apply  
4. Sealed mutation manifest + execution report + **CP9**  
5. State remains `cpr_uploads_applying` (post-verify = P6)

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Uploads Apply layer implemented | **PASS** |
| AC2 | Runs only after DELETE/IMPORT/Special/CP8 | **PASS** |
| AC3 | Bound to job/contract/country/schema/approved manifest | **PASS** |
| AC4 | Country isolation; no cross-country; deterministic order | **PASS** |
| AC5 | Manifest + fingerprint validation; fail-closed | **PASS** |
| AC6 | Resume from sealed progress; no replay; no privilege bypass | **PASS** |
| AC7 | Sealed report/manifest + audit + recovery + CP9 | **PASS** |
| AC8 | Enablement FALSE; no production SQL; Architecture/OD unchanged | **PASS** |
| AC9 | Self-tests + lint + full CPR suite green | **PASS** |
| AC10 | P5 Artifact Index WP-P5-05 COMPLETE | **PASS** |

---

## 5. Stop rule

**WP-P5-05 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P5-06** until Owner review and approval.

---

*End of WP-P5-05.*
