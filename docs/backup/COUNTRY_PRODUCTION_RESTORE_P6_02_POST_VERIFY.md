# WP-P6-02 — Post-Verify Engine (CP10 / Architecture §19)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P6-02** — Post-Verify Engine (CP10) |
| **Artifact-ID** | `CPR-P6-WP02-POST_VERIFY` |
| **Phase** | P6 — Verify + Rollback Integration |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P6-01 COMPLETE; P5 CP9 path |
| **Unlocks** | WP-P6-03 Success Finalize · WP-P6-04 Rollback (failure path) |
| **Scaffold** | `P6-02-post-verify` |
| **Enablement** | **FALSE** (hard) — no production SQL; no production upload mutation |
| **Architecture / OWNER_APPROVED** | **Not modified** |

---

## 1. Objective

Implement the fail-closed **Post-Apply Verification** suite (Architecture §19 / OD-VERIFY-WARN / WP-P1-11) after successful DELETE → IMPORT → Special Handlers → Country Uploads (**CP9**), producing sealed verify report/manifest, audit events, recovery metadata, and **CP10** (`post_verify_pass`) only on suite **PASS**.

---

## 2. Hard rules (OD-VERIFY-WARN)

- Fail-closed: no success with warnings; no integrity waiver; no skip/bypass
- Any integrity failure → pause `cpr_paused_verify_failed` (T33); Maint remains ON; Resume or Rollback only
- CP10 only when `verify_suite_result=PASS` and `integrity_waiver=false`
- No replay of completed PASS; no privilege bypass; no cross-country verification
- Bind to job identity, execution contract, country, schema revision
- State remains `cpr_post_verifying` after CP10 (success finalize = WP-P6-03)

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_post_verify_live.php` | Live engine `orange_cpr_post_verify_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_POST_VERIFY_DIRNAME`; scaffold `P6-02-post-verify` |
| `includes/backup/country_production/cpr_p6_control_plane.php` | `post_verify_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_post_verify_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md` | WP-P6-02 COMPLETE |

### Enablement FALSE path

Suite evaluates sealed P5 apply ledger + CP5 witnesses + checkpoint chain (virtual production). Reports `production_sql_executed=false` and `production_uploads_mutated=false`.

### Flow

1. Preconditions: CP6–CP9 + sealed DELETE/IMPORT/Special/Uploads reports & manifests; contract/job/country/schema bind; lock/gate/authority  
2. T12 → `cpr_post_verifying`  
3. Evaluate V01–V12 + FA pillars + recovery/audit integrity (PASS/FAIL only)  
4. PASS → sealed report + verification manifest + **CP10**; state stays `cpr_post_verifying`  
5. FAIL → sealed fail report; T33 pause; no CP10  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Post-Verify engine implemented | **PASS** |
| AC2 | Runs only after CP9 + DELETE/IMPORT/Special/Uploads | **PASS** |
| AC3 | Produces CP10 as first post-execution verify checkpoint | **PASS** |
| AC4 | Verifies completeness, manifests, checkpoint chain, state, recovery, fingerprints, audit | **PASS** |
| AC5 | Bound to job / contract / country / schema revision | **PASS** |
| AC6 | Fail-closed; no replay; no privilege bypass; no cross-country; no skip | **PASS** |
| AC7 | Sealed report + verification manifest + audit + recovery + CP10 | **PASS** |
| AC8 | Enablement FALSE; no production SQL/uploads mutation; Architecture/OD unchanged | **PASS** |
| AC9 | Self-tests + lint + full CPR suite green | **PASS** |
| AC10 | P6 Artifact Index WP-P6-02 COMPLETE | **PASS** |

---

## 5. Stop rule

**WP-P6-02 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P6-03** until Owner review and approval.

---

*End of CPR-P6-WP02-POST_VERIFY.*
