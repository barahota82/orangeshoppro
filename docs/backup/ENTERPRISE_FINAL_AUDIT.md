# Orange Disaster Recovery Platform — Enterprise Final Audit

| Field | Value |
|-------|--------|
| Audit type | Independent Principal Software Architect / Enterprise Security Auditor |
| Scope | Full Disaster Recovery platform (Backup → Certification) |
| Mode | **AUDIT ONLY** — no feature work, no refactors, no cleanup |
| Audited tip (approx.) | `da1f9bd9` / drill evidence `59f3f447` |
| Evidence basis | Source review of `includes/backup/restore/*`, `includes/backup/*`, `scripts/backup/restore_*.php`, `admin/api/restore/*`, `docs/backup/*`, certification JSON |
| Date (UTC) | 2026-07-18 |

**Mandate:** Find everything that could prevent production certification. Findings below are evidence-backed only.

---

## Executive verdict

The Phase **3B.x / 3B.4A–3B.4G** stack is a serious, defense-in-depth restore architecture with strong CLI separation for destructive steps, identity fences, checkpoints, rollback/finalize engines, and an honest **CONDITIONAL** certification.

It is **not** ready for unrestricted live Full Restore against production traffic until Critical/High blockers below are closed—chiefly **maintenance middleware not wired into request paths**, **parallel legacy Phase-2 cutover CLIs**, **missing explicit production authorization**, and **certification drills that do not prove live MySQL wipe/import**.

Country production restore remains correctly blocked in production engines.

---

## 1. Architecture

### F-ARCH-01 — Dual restore stacks (framework vs Phase-2 merge/e2e)
- **Severity:** High
- **Impact:** Operators or automation can invoke an older production DB cutover path alongside the 3B.4 production import path, increasing wrong-tool / wrong-job risk.
- **Evidence:** Parallel modules: `restore_job_framework.php` + `restore_production_*.php` vs `restore_job.php` + `restore_merge_*` + `restore_orchestrator.php` + `restore_e2e_orchestrator.php`. Legacy CLIs still present: `scripts/backup/restore_full_database_cutover.php` (calls `orange_restore_orchestrator_database_cutover`), `restore_run_full.php` (`orange_restore_e2e_start_full`), `restore_full_uploads_cutover.php`, `restore_full_rollback.php`. Admin UI does not reference the Phase-2 cutover CLI (good), but scripts remain executable.
- **Affected files:** `scripts/backup/restore_full_database_cutover.php`, `scripts/backup/restore_run_full.php`, `includes/backup/restore/restore_orchestrator.php`, `includes/backup/restore/restore_job.php`, `includes/backup/restore/restore_production_import.php`
- **Recommended fix:** Hard-disable or fail-closed legacy cutover CLIs in production (env fence / removed from deploy allowlist) until deleted after owner approval; document single supported command sequence only.
- **Production risk:** Accidental use of Phase-2 cutover against a live job/DB while 3B.4 path is the certified path.

### F-ARCH-02 — Framework transition helper has no from→to matrix
- **Severity:** High
- **Impact:** Any caller of `orange_restore_fw_transition()` may set any allowlisted status without validating prior status. Safety depends entirely on each engine’s entry gates; a buggy caller can invent impossible histories.
- **Evidence:** `orange_restore_fw_transition()` in `restore_job_framework.php` only checks `orange_restore_fw_allowed_statuses()`. Contrast: legacy `restore_job.php` defines `orange_restore_job_approval_transition_map()` / `orange_restore_job_assert_approval_transition()`.
- **Affected files:** `includes/backup/restore/restore_job_framework.php`, `includes/backup/restore/restore_job.php`
- **Recommended fix:** Add an explicit transition matrix for framework statuses (or assert previous status inside each engine before transition).
- **Production risk:** Corrupt job state after partial failures; harder forensic reconstruction; possible skip of gates if a future caller misuses the helper.

### F-ARCH-03 — Split “active job” definitions (fw vs execution orchestrator)
- **Severity:** Medium
- **Impact:** Concurrency control for late phases relies primarily on the execution lock, not framework active-job detection. `orange_restore_fw_active_statuses()` only covers early statuses (`queued`…`dry_running`). `orange_restore_exec_active_statuses()` ends at `uploads_cutover_ready` and **omits** rollback/finalize statuses.
- **Evidence:** `restore_job_framework.php` `orange_restore_fw_active_statuses()`; `restore_execution_orchestrator.php` `orange_restore_exec_active_statuses()`; drill explicitly notes second job create allowed while lock is the gate (`restore_dr_drill.php` lock validation).
- **Affected files:** `includes/backup/restore/restore_job_framework.php`, `includes/backup/restore/restore_execution_orchestrator.php`
- **Recommended fix:** Extend exec active statuses through rollback/finalize; optionally block `fw_create` while exec lock held or job in late statuses.
- **Production risk:** Second job metadata created during cutover/rollback; operator confusion; cancel-execution coverage gaps in late phases.

### F-ARCH-04 — HTTP certification endpoint loads full drill engine graph
- **Severity:** Medium
- **Impact:** Read-only API pulls `restore_dr_drill.php`, which requires nearly the entire restore graph into an admin HTTP request.
- **Evidence:** `admin/api/restore/certification.php` requires `restore_dr_drill.php`; that file requires import/uploads/rollback/finalize/shadow/maint modules.
- **Affected files:** `admin/api/restore/certification.php`, `includes/backup/restore/restore_dr_drill.php`
- **Recommended fix:** Move `orange_restore_dr_drill_read_certification_report()` to a tiny reader module with no engine requires.
- **Production risk:** Larger attack/dependency surface; OPcache/memory pressure on Restore Center load; accidental future coupling of HTTP to drill helpers.

### F-ARCH-05 — Large “god” modules
- **Severity:** Low
- **Impact:** Maintainability and review risk; not a direct production defect.
- **Evidence:** Approx. sizes: `restore_admin.php` ~1873 lines, `restore_dr_drill.php` ~1619, `restore_production_import.php` ~962; ~45 files under `includes/backup/restore/`.
- **Affected files:** `includes/backup/restore_admin.php`, `includes/backup/restore/restore_dr_drill.php`, others
- **Recommended fix:** Defer split until after production blockers; no cosmetic refactor now.
- **Production risk:** Low (review/regression cost).

---

## 2. State machine

### F-SM-01 — No enforced transition matrix (framework)
- **Severity:** High
- **Impact:** See F-ARCH-02. Impossible transitions are not rejected at the framework layer.
- **Evidence:** `orange_restore_fw_transition()` status allowlist only.
- **Affected files:** `includes/backup/restore/restore_job_framework.php`
- **Recommended fix:** Matrix + tests for illegal jumps (e.g. `queued` → `restore_completed`).
- **Production risk:** State corruption if any caller skips gates.

### F-SM-02 — Terminal / dead-state handling is engine-local
- **Severity:** Medium
- **Impact:** Terminal states (`restore_completed`, `rollback_completed`, `failed`, `cancelled`, …) are handled per engine (finalize idempotent paths exist). Failed mid-states (`production_import_failed`, `uploads_cutover_failed`, `rollback_failed`, `cutover_readiness_blocked`) rely on operator CLI re-run / documented resume—not a single recovery orchestrator.
- **Evidence:** Finalize entry allows `*_FINALIZING` resume and completed idempotency (`restore_production_finalize.php`). Import resume modes C3/C4/C5 documented in `restore_production_import.php`. Uploads mid-rename reconcile in `restore_production_uploads_cutover.php`.
- **Affected files:** `restore_production_finalize.php`, `restore_production_import.php`, `restore_production_uploads_cutover.php`, `restore_production_rollback.php`
- **Recommended fix:** Operator runbook already covers many cases; add a single “failed-state → allowed CLI action” matrix table in docs (no code change required for audit close).
- **Production risk:** Operator error under pressure if runbook not followed.

### F-SM-03 — `production_cutover_allowed` inverted gate
- **Severity:** High
- **Impact:** Production import **requires** cutover readiness READY **and** `production_cutover_allowed` empty/false. There is no separate audited “authorize production cutover” flip-to-true control as described in design checklist.
- **Evidence:** `orange_restore_prod_import_validate_entry()` in `restore_production_import.php` (`empty($cutover['production_cutover_allowed'])`). Design checklist in `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md` §12 still unchecked for explicit enablement / two-person control. Smoke reports keep `production_cutover_allowed` false by design.
- **Affected files:** `includes/backup/restore/restore_production_import.php`, `includes/backup/restore/restore_shadow_smoke.php`, `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`
- **Recommended fix:** Implement explicit time-bounded `production_cutover_authorized` record (owner-approved) **before** import/uploads CLIs may run; do not treat “allowed=false” as authorization.
- **Production risk:** Shadow readiness alone can proceed to wipe/import once maintenance is active—missing a final human authorization layer the design called for.

**NO FINDINGS** for missing terminal statuses themselves (completed/failed/cancelled exist and finalize/rollback cover them).

---

## 3. Locks

### F-LOCK-01 — Stale execution lock auto-clear
- **Severity:** Medium
- **Impact:** Stale orchestrator locks (age > 21600s / dead PID heuristics) are cleared on acquire. Correct for crash recovery; dangerous if a long-running import exceeds stale threshold while still alive and PID check fails on the host.
- **Evidence:** `orange_restore_exec_acquire_lock()` / `orange_restore_exec_lock_is_stale()` in `restore_execution_orchestrator.php` (`ORANGE_RESTORE_EXEC_LOCK_STALE_SECONDS = 21600`). Similar patterns: maint lock, shadow files lock.
- **Affected files:** `includes/backup/restore/restore_execution_orchestrator.php`, `restore_maintenance_framework.php`, `restore_shadow_files.php`
- **Recommended fix:** Heartbeat refresh of exec lock during long import/wipe; never clear stale if heartbeat fresh.
- **Production risk:** Second orchestration acquire during a long wipe on hosts with weak PID liveness checks.

### F-LOCK-02 — Maintenance stale never auto-releases (correct) but traffic not blocked
- **Severity:** Info (behavior correct) / see F-SEC-01 for impact
- **Impact:** Framework correctly forbids auto-release of stale maintenance (`auto_release_forbidden`).
- **Evidence:** `restore_maintenance_framework.php` + self-test `self_test_maintenance_framework.php`.
- **Affected files:** `includes/backup/restore/restore_maintenance_framework.php`
- **Recommended fix:** None for lock policy; wire middleware (F-SEC-01).
- **Production risk:** N/A for lock math; Critical for traffic (middleware).

### F-LOCK-03 — Finalize release order crash window handled
- **Severity:** Info (positive)
- **Impact:** Crash after maintenance release / before completed status is anticipated; finalize allows resume when status is `*_FINALIZING`.
- **Evidence:** Comments + entry gates in `restore_production_finalize.php` (~lines 248+).
- **Affected files:** `includes/backup/restore/restore_production_finalize.php`
- **Recommended fix:** Keep; ensure runbook mentions re-run finalize.
- **Production risk:** Low if operators re-run finalize CLI.

**NO FINDINGS** for classic deadlock cycles among file locks (locks are mostly single-file, short hold).

---

## 4. Checkpoint system

### F-CP-01 — Import C0–C6 resume rules present and coherent
- **Severity:** Info (positive)
- **Impact:** Documented resume: after C3 → `rewipe_reimport`; after C4 → `verify_only`; after C5 → commit-only; after C6 → already committed.
- **Evidence:** `restore_production_import.php` header + `resumeMode` branch (~675–697, 752+).
- **Affected files:** `includes/backup/restore/restore_production_import.php`
- **Recommended fix:** None.
- **Production risk:** Low if followed.

### F-CP-02 — Uploads C7–C8 + mid-rename reconcile present
- **Severity:** Info (positive)
- **Impact:** Partial rename detection path exists (`uploads` missing + `pre_merge` + `next` present).
- **Evidence:** `restore_production_uploads_cutover.php` mid-rename block (~629–640).
- **Affected files:** `includes/backup/restore/restore_production_uploads_cutover.php`
- **Recommended fix:** None.
- **Production risk:** Residual risk if rename crosses volumes (preflight asserts same volume).

### F-CP-03 — Rollback C9–C12 present
- **Severity:** Info (positive)
- **Evidence:** `restore_production_rollback.php` + drill assertions C9–C12.
- **Affected files:** `includes/backup/restore/restore_production_rollback.php`
- **Recommended fix:** None.
- **Production risk:** Low.

**NO FINDINGS** for missing checkpoint IDs C0–C12 in the 3B.4 production path.

---

## 5. Rollback

### F-RB-01 — Rollback sources correctly constrained
- **Severity:** Info (positive)
- **Impact:** Rollback uses Full anchor dump + `uploads_pre_merge_{job}`—not shadow DB/workspace.
- **Evidence:** Engine comments + gates in `restore_production_rollback.php`; design §3B.4E.
- **Affected files:** `includes/backup/restore/restore_production_rollback.php`
- **Recommended fix:** None.
- **Production risk:** Low if anchors/pins intact.

### F-RB-02 — Double-run / idempotent request paths exist
- **Severity:** Info (positive)
- **Impact:** Request path returns idempotent when already running/ready; CLI continues from checkpoints.
- **Evidence:** `rollback_already_running` / `Rollback already ready` branches in `restore_production_rollback.php`.
- **Affected files:** `includes/backup/restore/restore_production_rollback.php`
- **Recommended fix:** None.
- **Production risk:** Low.

### F-RB-03 — Rollback does not release maintenance (correct)
- **Severity:** Info (positive)
- **Impact:** Maintenance held until finalize—matches policy.
- **Evidence:** Rollback must-not list; finalize releases; drill asserts maint held until rollback finalize.
- **Affected files:** `restore_production_rollback.php`, `restore_production_finalize.php`
- **Recommended fix:** None.
- **Production risk:** Low.

### F-RB-04 — Inconsistency if DB rollback succeeds and files rollback fails
- **Severity:** High
- **Impact:** System can sit in `rollback_failed` / files phase with DB restored to anchor but uploads still post-cutover (or partial). Operator must continue CLI; not automatically consistent.
- **Evidence:** Separate statuses `rollback_database_*` vs `rollback_files_*`; failure injection `files_rollback_failure` expects `rollback_failed`.
- **Affected files:** `includes/backup/restore/restore_production_rollback.php`
- **Recommended fix:** Runbook already implies continue rollback; add explicit “DB rolled back / files pending” operator banner in UI status (doc/UI later—not this audit).
- **Production risk:** Storefront serving mixed old DB + new files (or reverse) until rollback completed.

---

## 6. CLI

### F-CLI-01 — 3B.4 destructive CLIs are CLI-only and reject arbitrary paths
- **Severity:** Info (positive)
- **Evidence:** `restore_import_production.php`, `restore_uploads_cutover.php`, `restore_rollback.php`, `restore_finalize.php`, `run_restore_dr_drill.php` check `PHP_SAPI` and reject `--path=` / `--db=` / etc.
- **Affected files:** `scripts/backup/restore_*.php` (3B.4 set)
- **Recommended fix:** None.
- **Production risk:** Low for these entrypoints.

### F-CLI-02 — Legacy CLIs accept `--password=` on argv and `--package=` paths
- **Severity:** High
- **Impact:** Password visible in process lists / shell history; arbitrary package path argument pattern conflicts with 3B.4 safety model.
- **Evidence:** `scripts/backup/restore_full_database_cutover.php`, `restore_run_full.php` parse `--password=` and (for run_full) `--package=`.
- **Affected files:** those scripts + orchestrators they call
- **Recommended fix:** Disable in production; never pass passwords on argv; migrate operators exclusively to 3B.4 `--job=` workers.
- **Production risk:** Credential leakage; accidental Phase-2 production cutover.

### F-CLI-03 — HTTP does not run import/cutover/rollback/finalize/drill
- **Severity:** Info (positive)
- **Evidence:** Corresponding `admin/api/restore/job/*` are request/status; certification GET is read-only; `http_never_*` flags in APIs.
- **Affected files:** `admin/api/restore/job/*.php`, `certification.php`
- **Recommended fix:** None.
- **Production risk:** Low for HTTP execution of wipe/rename.

**NO FINDINGS** for shell injection in 3B.4 CLIs (no `shell_exec`/`passthru` usage found in those restore CLIs; import uses PDO stream runner).

---

## 7. Security

### F-SEC-01 — Maintenance decide() not wired into storefront/admin routes
- **Severity:** Critical
- **Impact:** Activating framework maintenance does **not** stop storefront/admin writes. During production wipe/import, live traffic can write to a disappearing/half-imported DB.
- **Evidence:** `orange_restore_production_maintenance_decide()` documented as “policy only — callers wire routes later” (`restore_maintenance_framework.php`). Grep: **no** callers under `admin/` or storefront `api/` / `pages/`. Integration list is still `orange_restore_maint_fw_future_integration_points()`. Certification open blocker `maintenance_middleware_not_wired`. Self-tests exercise decide() in isolation only.
- **Affected files:** `includes/backup/restore/restore_maintenance_framework.php`, storefront/admin entrypoints (not wired), `docs/backup/restore_dr_certification_report.json`
- **Recommended fix:** Phase 3B.4H — wire decide() into write paths listed in future integration points; prove with integration tests on clone.
- **Production risk:** **Critical data corruption / lost orders during restore window.**

### F-SEC-02 — CSRF + recent-auth + nonce controls on sensitive POSTs (good)
- **Severity:** Info (positive)
- **Evidence:** `_bootstrap.php` CSRF helper; `activate-maintenance.php` requires password + nonce; `restore_final_approval.php` nonce consume/expiry/operator/session checks; replay → `approval_nonce_used`.
- **Affected files:** `admin/api/restore/_bootstrap.php`, `job/activate-maintenance.php`, `job/final-approve.php`, `restore_final_approval.php`
- **Recommended fix:** None.
- **Production risk:** Low for those gates.

### F-SEC-03 — Country production blocked in production engines
- **Severity:** Info (positive)
- **Evidence:** `restore_production_maintenance.php` rejects `country_recovery`; finalize/import gates require `full_disaster`; certification forces `country_restore_certified=false`.
- **Affected files:** production engines + `restore_dr_drill.php`
- **Recommended fix:** Keep blocked until dedicated 3B.3C series.
- **Production risk:** Low (Country).

### F-SEC-04 — ZIP / path safety largely present; DRV ZipArchive hard dependency
- **Severity:** High (environment-dependent)
- **Impact:** On PHP builds without `ZipArchive`, DRV uploads stage fails (`recovery_validation.php` returns error). Shadow files has a pure-PHP fallback for stored zips; DRV does not. Local Laragon drill logs showed `Uploads ZIP... FAIL (entries=0)` while other gates soft-pass in places.
- **Evidence:** `includes/backup/recovery_validation.php` (~529–532); `restore_uploads_fs.php` rejects `..`; `restore_shadow_files.php` ZipArchive + fallback.
- **Affected files:** `includes/backup/recovery_validation.php`, `includes/backup/restore/restore_shadow_files.php`
- **Recommended fix:** Require ZipArchive on production PHP; or align DRV with shadow-files fallback; verify on target host before any window.
- **Production risk:** False DRV failures or skipped uploads integrity on some hosts; packaging risk if fallback incomplete.

### F-SEC-05 — Secrets in reports generally redacted (good) with residual path risk in older surfaces
- **Severity:** Low
- **Impact:** Admin public record helpers strip paths/passwords in modern APIs; legacy CLIs still take password on argv (F-CLI-02).
- **Evidence:** `orange_restore_final_approval_public` unsets secrets; pre-backup redaction self-tests; certification reader avoids absolute private paths.
- **Affected files:** `restore_final_approval.php`, `restore_admin.php`, legacy CLIs
- **Recommended fix:** Retire legacy password argv CLIs.
- **Production risk:** Credential leakage via process list.

### F-SEC-06 — Two-person / time-gated approval not implemented
- **Severity:** Medium (policy-dependent)
- **Impact:** Design §12 still requires two-person / time-gated control “as required”; current model is single operator + password re-auth + nonce.
- **Evidence:** Unchecked box in `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md` §12; no dual-approver code found; runbook has no two-person step.
- **Affected files:** design doc, `restore_final_approval.php`
- **Recommended fix:** Owner decision: waive explicitly in archive **or** implement second approver.
- **Production risk:** Single compromised admin session can approve Full restore after re-auth.

---

## 8. Data safety

### F-DATA-01 — Production identity + merge credential separation (good)
- **Severity:** Info (positive)
- **Evidence:** `orange_restore_production_assert_identity()`, merge user ≠ app user ≠ staging user (`restore_production_target.php` / `restore_staging_target.php`). Import asserts identity twice before wipe.
- **Affected files:** `restore_production_target.php`, `restore_production_import.php`
- **Recommended fix:** None.
- **Production risk:** Low if `.env.php` correctly configured.

### F-DATA-02 — Wrong `.env.php` / DB_NAME still wipes “configured production”
- **Severity:** High
- **Impact:** Identity checks ensure the PDO session DB equals configured `DB_NAME`. They cannot detect that the configured DB is the wrong logical environment (e.g. staging host pointed at live name, or clone mislabeled).
- **Evidence:** Assert compares `SELECT DATABASE()` to `orange_restore_production_db_name($projectRoot)` only.
- **Affected files:** `restore_production_target.php`, server `.env.php` (out of repo)
- **Recommended fix:** Pre-flight checklist: confirm host + DB_NAME + merge user on clone vs prod; optional secondary marker table/env `ORANGE_ENVIRONMENT=production|clone`.
- **Production risk:** Wipe of unintended database that happens to match configured name.

### F-DATA-03 — SQL import safety filters present for target import
- **Severity:** Info (positive)
- **Evidence:** `orange_restore_sql_runner_import_gzip_to_target()` validates statements; rejects `DROP DATABASE` patterns in `restore_sql_safety.php`; wipe uses `DROP TABLE` only inside asserted schema.
- **Affected files:** `restore_sql_runner.php`, `restore_sql_safety.php`, `restore_production_target.php`
- **Recommended fix:** None.
- **Production risk:** Residual risk from novel SQL edge cases—not observed as open defect.

### F-DATA-04 — Uploads root derived from project root (good for CLI job binding)
- **Severity:** Info (positive)
- **Evidence:** `orange_restore_production_uploads_directory($projectRoot)`; 3B.4 CLIs resolve project root from script location, not argv.
- **Affected files:** `restore_paths.php`, 3B.4 CLIs
- **Recommended fix:** None.
- **Production risk:** Low if deploy root correct.

---

## 9. Production safety

### F-PROD-01 — Maintenance activation without traffic fence (Critical)
- Same as F-SEC-01.

### F-PROD-02 — Parallel Phase-2 production cutover CLIs remain runnable
- Same as F-ARCH-01 / F-CLI-02.

### F-PROD-03 — No real production touched by DR drill (good)
- **Severity:** Info (positive)
- **Evidence:** Certification JSON `confirmation.real_production_restore_run=false`; isolation markers; fixture DB names ≠ `orange_db`.
- **Affected files:** `docs/backup/restore_dr_certification_report.json`, `restore_dr_drill.php`
- **Recommended fix:** None.
- **Production risk:** None for the drill itself.

### F-PROD-04 — HTTP maintenance activation is real framework state
- **Severity:** Medium
- **Impact:** `activate-maintenance.php` truly activates framework maintenance (not a dry flag). Without middleware, this creates a “Maintenance Active” UI illusion while traffic continues.
- **Evidence:** API calls `orange_restore_admin_fw_activate_maintenance`; response claims `framework_activation_only` / `production_touched=false` but state becomes ACTIVE.
- **Affected files:** `admin/api/restore/job/activate-maintenance.php`, `restore_production_maintenance.php`
- **Recommended fix:** Wire middleware before any live activation; UI warning until 3B.4H done (already partially warned).
- **Production risk:** False confidence during window.

---

## 10. Documentation

### F-DOC-01 — Design checklist §12 largely unchecked despite 3B.4C–G code
- **Severity:** Medium
- **Impact:** Operators reading `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md` §12 may believe core cutover proofs are incomplete even where engines exist; conversely some unchecked items are still true gaps (middleware, two-person, live clone proof).
- **Evidence:** §12 checkboxes; code phases marked DONE for 3B.4C–G in same file; certification CONDITIONAL.
- **Affected files:** `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`
- **Recommended fix:** Reconcile checklist against code: check only what is proven on a **live clone**; leave middleware/authz unchecked until done.
- **Production risk:** Process confusion; premature or delayed go-live decisions.

### F-DOC-02 — Phase numbering drift historically cleaned for 3B.4G
- **Severity:** Low
- **Impact:** Older drafts labeled 3B.4G as authorization gate / 3B.4J as drill; current docs updated—residual confusion if external notes cite old labels.
- **Evidence:** `RESTORE_EXECUTION_DESIGN.md` / cutover design now state 3B.4G = DR drill.
- **Affected files:** design docs
- **Recommended fix:** Keep owner phase table authoritative.
- **Production risk:** Low.

### F-DOC-03 — Operator runbook exists and is concise (good)
- **Severity:** Info (positive)
- **Evidence:** `ORANGE_DR_OPERATOR_RUNBOOK.md` command order, stop conditions, retention pin policy.
- **Affected files:** `docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md`
- **Recommended fix:** Add failed-state matrix (F-SM-02) and explicit “do not use Phase-2 CLIs”.
- **Production risk:** Low.

---

## 11. Tests

### F-TEST-01 — Strong suite coverage for engines; master runner green
- **Severity:** Info (positive)
- **Evidence:** `run_restore_certification_tests.php` 13/13 suites; large counts in restore_admin / finalize / shadow / import / rollback self-tests.
- **Affected files:** `scripts/backup/self_test_*.php`, `run_restore_certification_tests.php`
- **Recommended fix:** Keep green as merge gate.
- **Production risk:** Low.

### F-TEST-02 — Mocks hide live MySQL / filesystem reality (certification honesty)
- **Severity:** High
- **Impact:** Production import/wipe/rollback DB steps in drills/self-tests use mock PDO / overrides. Pass does not prove mysqldump/import performance, privilege, or crash behavior on real server.
- **Evidence:** Certification blocker `db_steps_use_fixture_adapters`; `OrangeRestoreDrDrillMockPdo`; production import self-test mock PDO.
- **Affected files:** `restore_dr_drill.php`, `self_test_production_import.php`, certification JSON
- **Recommended fix:** Mandatory clone-host drill with real MySQL + real uploads tree before CERTIFIED.
- **Production risk:** First live wipe is under-proven.

### F-TEST-03 — DRV uploads ZIP failures on ZipArchive-less PHP create false negatives / soft paths
- **Severity:** Medium
- **Impact:** Tests/drills may pass overall while DRV uploads stage fails loudly in logs.
- **Evidence:** Drill/rollback console `Uploads ZIP... FAIL (entries=0)`; `recovery_validation.php` ZipArchive requirement.
- **Affected files:** `recovery_validation.php`, drill seed zip writer
- **Recommended fix:** Align DRV with host PHP capabilities; fix fixture zip if central directory incomplete for ZipArchive.
- **Production risk:** Misleading validation on some hosts.

### F-TEST-04 — Earlier self-tests assumed “no rollback endpoint” (fixed)
- **Severity:** Info (historical)
- **Impact:** Would have been false-negative gate; corrected when 3B.4E shipped.
- **Evidence:** Updates in `self_test_pre_restore_backup.php` / `self_test_shadow_restore.php`.
- **Affected files:** those self-tests
- **Recommended fix:** None.
- **Production risk:** None now.

---

## 12. Code quality

### F-CQ-01 — Duplicated job models and lock helpers
- **Severity:** Medium
- **Impact:** Cognitive load; risk of fixing a bug in one stack only.
- **Evidence:** `restore_job.php` vs `restore_job_framework.php`; multiple lock implementations (fw, exec, maint, shadow files, global restore lock).
- **Affected files:** listed modules
- **Recommended fix:** Freeze Phase-2 stack; document “do not extend”; eventual removal after owner approval.
- **Production risk:** Indirect (wrong stack edits).

### F-CQ-02 — Dead / future-only integration list
- **Severity:** Low
- **Impact:** `orange_restore_maint_fw_future_integration_points()` is documentation-as-code until wired.
- **Evidence:** function in `restore_maintenance_framework.php`.
- **Affected files:** that file
- **Recommended fix:** Convert to real wiring (F-SEC-01).
- **Production risk:** None until ignored.

**NO FINDINGS** for unused constants that break runtime (not exhaustively proven unused).

---

## 13. Performance

### F-PERF-01 — Streaming SQL import (good) with unbounded statement buffer risk
- **Severity:** Medium
- **Impact:** Gzip is streamed in 64KiB chunks, but a single giant statement grows `$buffer` in memory until semicolon split.
- **Evidence:** `restore_sql_runner.php` import loops.
- **Affected files:** `includes/backup/restore/restore_sql_runner.php`
- **Recommended fix:** Max statement/buffer guard with fail-closed.
- **Production risk:** PHP memory exhaustion on pathological dumps during live window.

### F-PERF-02 — Long wipe/import without exec-lock heartbeat
- **Severity:** Medium
- **Impact:** See F-LOCK-01; also CLI may run past web timeouts (CLI OK) but operator SSH sessions / host limits still apply.
- **Evidence:** Wipe loops all tables; import streams all statements; stale lock 6h.
- **Affected files:** `restore_production_target.php`, `restore_production_import.php`, exec lock
- **Recommended fix:** Heartbeat + progress logging (partially present every 500 statements).
- **Production risk:** Stuck window / lock races on long DBs.

### F-PERF-03 — Admin Restore Center loads many status endpoints
- **Severity:** Low
- **Impact:** UX latency only; certification endpoint currently heavy (F-ARCH-04).
- **Evidence:** `admin/pages/restore_center.php` multiple GETs.
- **Affected files:** restore center + APIs
- **Recommended fix:** Lightweight cert reader.
- **Production risk:** Low.

---

## 14. Recovery (operator)

### F-REC-01 — Documented failure paths mostly recoverable via CLI re-run
- **Severity:** Info (positive) with gaps
- **Impact:** Import/uploads/rollback/finalize each document resume; runbook lists stop/rollback decision points.
- **Evidence:** Engines + `ORANGE_DR_OPERATOR_RUNBOOK.md`.
- **Affected files:** runbook + engines
- **Recommended fix:** Add matrix: status → exact CLI → expected next status; include Phase-2 CLI prohibition.
- **Production risk:** Medium under stress without matrix.

### F-REC-02 — No automated recovery for mixed DB/files after partial rollback
- **Severity:** High
- **Impact:** See F-RB-04. Operator must complete rollback CLI; system will not auto-heal.
- **Evidence:** Split rollback phases; `rollback_failed` terminal-ish until retry.
- **Affected files:** `restore_production_rollback.php`
- **Recommended fix:** UI/runbook emphasis; optional “continue rollback” only action when `rollback_failed`.
- **Production risk:** Extended inconsistency window.

### F-REC-03 — Stale maintenance requires human release (by design)
- **Severity:** Info (positive)
- **Evidence:** auto_release_forbidden; self-tests.
- **Affected files:** `restore_maintenance_framework.php`
- **Recommended fix:** Emergency procedure in runbook (already “keep maint until controlled finalize”).
- **Production risk:** Prolonged outage if finalize forgotten—acceptable vs silent release.

---

## 15. Certification review (CONDITIONAL)

### Confirmed open blockers (from `restore_dr_certification_report.json`)

| Severity | Code | Confirmed? | Notes |
|----------|------|------------|-------|
| high | `maintenance_middleware_not_wired` | **Yes — Critical in this audit (F-SEC-01)** | Decide() unwired; traffic unsafe during wipe |
| medium | `db_steps_use_fixture_adapters` | **Yes — High for CERTIFIED (F-TEST-02)** | Mock PDO ≠ live MySQL proof |

### Hidden / additional blockers not fully listed in certification JSON

| Severity | Title | Ref |
|----------|-------|-----|
| High | Legacy Phase-2 cutover CLIs still runnable with password-on-argv | F-ARCH-01, F-CLI-02 |
| High | No explicit production cutover authorization (inverted `production_cutover_allowed` gate) | F-SM-03 |
| High | Partial rollback can leave DB/files inconsistent until retry | F-RB-04 |
| High | Wrong-environment wipe if `.env`/DB_NAME mis-pointed | F-DATA-02 |
| High | ZipArchive / DRV uploads integrity host-dependent | F-SEC-04 |
| Medium | Framework transition matrix missing | F-ARCH-02 / F-SM-01 |
| Medium | Exec active statuses omit rollback/finalize | F-ARCH-03 |
| Medium | Two-person approval not implemented (policy) | F-SEC-06 |
| Medium | Design §12 checklist not reconciled | F-DOC-01 |
| Medium | SQL statement buffer unbounded | F-PERF-01 |
| Medium | HTTP certification loads full drill engine | F-ARCH-04 |

### Certification honesty
- `full_restore_certified=false` — **correct**
- `country_restore_certified=false` — **correct**
- `production_execution_recommendation=CONDITIONAL` — **correct; this audit escalates overall readiness to NOT READY for live cutover until Critical/High items close**

---

## Category summary

| Category | Result |
|----------|--------|
| Architecture | Findings: F-ARCH-01…05 |
| State machine | Findings: F-SM-01…03 |
| Locks | Findings: F-LOCK-01…03 (incl. positive Info) |
| Checkpoints | Positive Info only (F-CP-01…03) — **no defect findings** |
| Rollback | Findings: F-RB-01…04 (incl. positives) |
| CLI | Findings: F-CLI-01…03 |
| Security | Findings: F-SEC-01…06 |
| Data safety | Findings: F-DATA-01…04 |
| Production safety | Findings: F-PROD-01…04 |
| Documentation | Findings: F-DOC-01…03 |
| Tests | Findings: F-TEST-01…04 |
| Code quality | Findings: F-CQ-01…02 |
| Performance | Findings: F-PERF-01…03 |
| Recovery | Findings: F-REC-01…03 |
| Certification | Reviewed; blockers confirmed + hidden list |

---

## Final scores

| Dimension | Score /100 | Rationale |
|-----------|------------|-----------|
| 1. Overall architecture | **62** | Solid 3B.4 layering and fences, undermined by dual stacks, missing transition matrix, oversized modules |
| 2. Security | **58** | Strong CSRF/nonce/CLI gates/Country block; Critical unwired maintenance; legacy password argv; authz gap |
| 3. Reliability | **68** | Checkpoints/rollback/finalize mature; mock-heavy certification; partial-rollback inconsistency; ZipArchive DRV gap |
| 4. Maintainability | **55** | Two job systems, many locks, very large admin/drill files |
| 5. Production readiness | **NOT READY** | Critical traffic fence missing + High cutover/authorization/legacy-CLI/live-DB proof gaps |

---

## Conditions blocking READY

1. **Wire and prove** maintenance middleware on storefront + admin write APIs (and listed integration points).
2. **Disable or fail-closed** legacy Phase-2 production cutover / password-argv CLIs on production hosts.
3. **Implement or explicitly waive (in owner archive)** production cutover authorization + two-person control policy.
4. **Run and pass** a real MySQL + real uploads clone drill (no mock PDO for wipe/import/rollback).
5. **Prove** ZipArchive (or equivalent DRV uploads integrity) on the target PHP runtime.
6. **Reconcile** design §12 checklist with proven evidence; keep Country production disabled.
7. **Close** High reliability gaps: partial rollback operator path, exec-lock heartbeat for long imports, env/DB preflight identity beyond name match.

---

## MUST fix before production (live Full Restore)

| Priority | Item | Finding IDs |
|----------|------|-------------|
| P0 | Wire maintenance middleware; integration-test write blocking | F-SEC-01, F-PROD-01 |
| P0 | Remove/disable Phase-2 production cutover CLIs on prod | F-ARCH-01, F-CLI-02, F-PROD-02 |
| P0 | Explicit production cutover authorization before wipe/rename | F-SM-03 |
| P0 | Live clone drill with real DB/files; refresh certification | F-TEST-02 |
| P1 | Target host ZipArchive/DRV uploads integrity | F-SEC-04 |
| P1 | Owner decision on two-person approval | F-SEC-06 |
| P1 | Exec lock heartbeat + include rollback/finalize in active set | F-LOCK-01, F-ARCH-03 |
| P1 | Framework transition matrix | F-ARCH-02, F-SM-01 |
| P1 | Operator failed-state / partial-rollback matrix in runbook | F-RB-04, F-REC-02, F-DOC-03 |
| P2 | Lightweight certification JSON reader for HTTP | F-ARCH-04 |
| P2 | SQL statement buffer guard | F-PERF-01 |
| P2 | Reconcile design checklist §12 | F-DOC-01 |

**Country production restore:** remain **NOT CERTIFIED / blocked**.

---

## Auditor attestation

- No feature implementation, refactor, or optimization was performed in this audit task.
- No real production restore was executed as part of this audit.
- This document is the sole deliverable of the audit commit.

*End of Enterprise Final Audit.*
