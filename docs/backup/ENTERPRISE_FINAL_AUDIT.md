# Orange Disaster Recovery Platform — Enterprise Final Audit

| Field | Value |
|-------|--------|
| Audit type | Independent Principal Software Architect / Enterprise Security Auditor |
| Scope | Full Disaster Recovery platform (Backup → Certification) |
| Mode | **AUDIT ONLY** — no feature work, no refactors, no cleanup outside this document |
| Audited tip | `c3dd091a` (includes P0-1…P0-4 remediations through real MySQL clone validation) |
| Prior audit tip (superseded) | `980dc6ad` / drill evidence `59f3f447` |
| Evidence basis | Source review of `includes/backup/**`, `scripts/backup/**`, `admin/api/restore/**`, `api/**` mutation guards, `config.php` admin guards, `docs/backup/**`, certification + clone JSON reports |
| Date (UTC) | 2026-07-18 |

**Mandate:** Find everything that could prevent production certification. Findings below are evidence-backed only. Generic advice omitted.

---

## Executive verdict

The Phase **3B.x / 3B.4A–3B.4L** stack is a serious, defense-in-depth Full Restore architecture: CLI-only destructive workers, identity fences, checkpoints, rollback/finalize engines, maintenance policy + route enforcement, legacy Phase-2 CLI tombstones, explicit cutover authorization, and an isolated real-MySQL clone path.

**Production readiness: READY WITH CONDITIONS** (not READY).

Critical unwired-maintenance and runnable Phase-2 cutover CLI risks from the prior audit are **code-remediated (P0-1…P0-4)**. Remaining blockers are primarily **certification honesty / process**, **dual-control policy**, **host capability**, **incomplete live cutover proof beyond synthetic clone SQL**, and **state/lock hardening**.

Country production restore remains correctly **blocked** in production engines.

---

## Remediation status since prior Enterprise Audit (P0)

| Prior finding | P0 | Code status at `c3dd091a` | Still blocks CERTIFIED? |
|---------------|----|---------------------------|-------------------------|
| F-SEC-01 / F-PROD-01 maintenance unwired | P0-1 | **REMEDIATED in code** — `restore_maintenance_enforcement.php` wired via `config.php` admin guards + storefront mutation APIs + intake/backup/cron | Residual: cert JSON/docs still claim unwired; HTTP under active maint not proven in certification drill |
| F-ARCH-01 / F-CLI-02 / F-PROD-02 legacy CLIs | P0-2 | **REMEDIATED** — tombstones + `restore_production_cli_policy.php` allowlist (4 workers) | Residual: Phase-2 **libraries** still loadable if a new caller is introduced |
| F-SM-03 cutover authorization | P0-3 | **REMEDIATED** — `production_cutover_authorization.json` + import gate/consume | Note: `production_cutover_allowed` readiness flag remains false by design |
| F-TEST-02 Mock-only DB confidence | P0-4 | **REMEDIATED (partial honesty)** — real clone on port 3307, `mock_pdo_used=false` | Residual: DR drill still Mock PDO; clone does not exercise uploads rename / full lifecycle / RTO |

---

## 1. Architecture

### F-ARCH-01 — Dual restore stacks (framework vs Phase-2 libraries) — residual
- **Severity:** Medium (was High; CLI entrypoints remediated)
- **Impact:** Phase-2 orchestrator/merge libraries remain in-tree. Approved mutation CLIs are fenced, but any future script requiring those libraries could re-open a parallel cutover path.
- **Evidence:** Tombstones document retained libraries (`scripts/backup/restore_full_database_cutover.php` comments reference `orange_restore_orchestrator_database_cutover`). Modules still present: `restore_orchestrator.php`, `restore_e2e_orchestrator.php`, `restore_merge_*.php`, `restore_job.php` alongside `restore_job_framework.php` + `restore_production_*.php`. Admin HTTP has no references to Phase-2 cutover executors (grep clean under `admin/api/restore`).
- **Affected files:** `includes/backup/restore/restore_orchestrator.php`, `restore_e2e_orchestrator.php`, `restore_merge_*.php`, `restore_production_cli_policy.php`, tombstone CLIs under `scripts/backup/`
- **Recommended fix:** Keep tombstones permanent; treat Phase-2 libraries as test-only; optionally add a static allowlist scan that fails CI if any non-test PHP calls `orange_restore_orchestrator_database_cutover` / `orange_restore_e2e_start_full`.
- **Production risk:** Medium — accidental re-wiring by a future change, not current HTTP/CLI operator path.

### F-ARCH-02 — Framework transition helper has no from→to matrix
- **Severity:** High
- **Impact:** `orange_restore_fw_transition()` may set any allowlisted status without validating prior status. Safety depends entirely on each engine’s entry gates; a buggy caller can invent impossible histories.
- **Evidence:** `orange_restore_fw_transition()` in `restore_job_framework.php` only checks `orange_restore_fw_allowed_statuses()`. Contrast: legacy `restore_job.php` defines approval transition maps.
- **Affected files:** `includes/backup/restore/restore_job_framework.php`, `includes/backup/restore/restore_job.php`
- **Recommended fix:** Explicit transition matrix for framework statuses (or assert previous status inside each engine before transition) + illegal-jump tests.
- **Production risk:** Corrupt job state after partial failures; harder forensic reconstruction; possible gate skip if a future caller misuses the helper.

### F-ARCH-03 — Split “active job” definitions (fw vs execution orchestrator)
- **Severity:** Medium
- **Impact:** `orange_restore_fw_active_statuses()` covers only early statuses (`queued`…`dry_running`). `orange_restore_exec_active_statuses()` ends at `uploads_cutover_ready` and **omits** rollback/finalize statuses. Late-phase concurrency relies primarily on the execution lock file.
- **Evidence:** `restore_job_framework.php` `orange_restore_fw_active_statuses()`; `restore_execution_orchestrator.php` `orange_restore_exec_active_statuses()` (lines ~27–64).
- **Affected files:** `includes/backup/restore/restore_job_framework.php`, `includes/backup/restore/restore_execution_orchestrator.php`
- **Recommended fix:** Extend exec active statuses through rollback/finalize; optionally block second job create while exec lock held.
- **Production risk:** Second job metadata during cutover/rollback; operator confusion; cancel-execution coverage gaps in late phases.

### F-ARCH-04 — HTTP certification endpoint loads full drill engine graph
- **Severity:** Medium
- **Impact:** Read-only API pulls `restore_dr_drill.php`, which requires nearly the entire restore graph into an admin HTTP request.
- **Evidence:** `admin/api/restore/certification.php` requires `restore_dr_drill.php`.
- **Affected files:** `admin/api/restore/certification.php`, `includes/backup/restore/restore_dr_drill.php`
- **Recommended fix:** Move certification report reader to a tiny module with no engine requires.
- **Production risk:** Larger attack/dependency surface; OPcache/memory pressure on Restore Center load.

### F-ARCH-05 — Large “god” modules
- **Severity:** Low
- **Impact:** Maintainability and review risk; not a direct production defect.
- **Evidence:** Large modules under `includes/backup/restore/` and `restore_admin.php`; ~45+ restore includes.
- **Affected files:** `includes/backup/restore_admin.php`, `restore_dr_drill.php`, `restore_production_import.php`, others
- **Recommended fix:** Defer split until after production blockers; no cosmetic refactor now.
- **Production risk:** Low (review/regression cost).

### F-ARCH-06 — Stale “future integration points” API after middleware wiring
- **Severity:** Low
- **Impact:** `orange_restore_maint_fw_future_integration_points()` still advertises wiring as future work even though P0-1 wired enforcement — confuses auditors/operators.
- **Evidence:** `restore_maintenance_framework.php` still exposes `future_integration_points` in status payloads; enforcement lives in `restore_maintenance_enforcement.php` + `config.php` / `api/*`.
- **Affected files:** `includes/backup/restore/restore_maintenance_framework.php`
- **Recommended fix:** Rename/document as “integration inventory (wired)” or mark each point wired/unwired accurately.
- **Production risk:** Process confusion only.

---

## 2. State machine

### F-SM-01 — No enforced transition matrix (framework)
- **Severity:** High
- **Impact:** Same as F-ARCH-02. Impossible transitions are not rejected at the framework layer.
- **Evidence:** `orange_restore_fw_transition()` status allowlist only.
- **Affected files:** `includes/backup/restore/restore_job_framework.php`
- **Recommended fix:** Matrix + tests for illegal jumps (e.g. `queued` → `restore_completed`).
- **Production risk:** State corruption if any caller skips gates.

### F-SM-02 — Terminal / dead-state handling is engine-local
- **Severity:** Medium
- **Impact:** Terminal states are handled per engine (finalize idempotent paths exist). Failed mid-states rely on operator CLI re-run / documented resume—not a single recovery orchestrator.
- **Evidence:** Finalize entry allows resume and completed idempotency (`restore_production_finalize.php`). Import resume modes documented in `restore_production_import.php`. Uploads mid-rename reconcile in `restore_production_uploads_cutover.php`. Rollback checkpoints C9–C12 with idempotent completed paths (`restore_production_rollback.php`).
- **Affected files:** `restore_production_finalize.php`, `restore_production_import.php`, `restore_production_uploads_cutover.php`, `restore_production_rollback.php`, `ORANGE_DR_OPERATOR_RUNBOOK.md`
- **Recommended fix:** Publish a single “failed-state → allowed CLI action” matrix in the operator runbook.
- **Production risk:** Operator error under pressure if runbook not followed.

### F-SM-03 — `production_cutover_allowed` inverted gate / naming drift
- **Severity:** Low (was High; authorization layer added)
- **Impact:** Readiness flag `production_cutover_allowed` remains false by design; real gate is now `production_cutover_authorization.json`. Design §12 / checklist language still mixes “enablement” with the old flag name.
- **Evidence:** Import validates cutover authorization (`restore_production_import.php` + `restore_production_cutover_authorization.php`). Design checklist still has unchecked “`production_cutover_allowed` defaults false; enablement is explicit…”.
- **Affected files:** `restore_production_import.php`, `restore_production_cutover_authorization.php`, `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`
- **Recommended fix:** Update design checklist wording to authorization artifact; leave readiness flag documented as non-authorization.
- **Production risk:** Low if operators follow 3B.4K APIs; confusion risk remains.

**NO FINDINGS** for missing terminal statuses themselves (completed/failed/cancelled/rollback_completed exist and engines cover them).

---

## 3. Locks

### F-LOCK-01 — Stale execution lock auto-clear without heartbeat
- **Severity:** Medium
- **Impact:** Stale orchestrator locks (age > 21600s / dead PID heuristics) are cleared on acquire. Correct for crash recovery; dangerous if a long-running import exceeds stale threshold while still alive and PID check fails on the host.
- **Evidence:** `ORANGE_RESTORE_EXEC_LOCK_STALE_SECONDS = 21600`; `orange_restore_exec_lock_is_stale()` / `orange_restore_exec_acquire_lock()` in `restore_execution_orchestrator.php`. Similar patterns: maint lock, shadow files lock.
- **Affected files:** `includes/backup/restore/restore_execution_orchestrator.php`, `restore_maintenance_framework.php`, `restore_shadow_files.php`
- **Recommended fix:** Heartbeat refresh of exec lock during long import/wipe; never clear stale if heartbeat fresh.
- **Production risk:** Second orchestration acquire during a long wipe on hosts with weak PID liveness checks.

### F-LOCK-02 — Maintenance stale never auto-releases (correct)
- **Severity:** Info (positive)
- **Impact:** Framework correctly forbids auto-release of stale maintenance (`auto_release_forbidden`).
- **Evidence:** `restore_maintenance_framework.php` + `self_test_maintenance_framework.php`.
- **Affected files:** `includes/backup/restore/restore_maintenance_framework.php`
- **Recommended fix:** None for lock policy.
- **Production risk:** N/A for lock math.

### F-LOCK-03 — Finalize release order crash window handled
- **Severity:** Info (positive)
- **Impact:** Finalize paths document/release maintenance and execution lock with idempotent completed states.
- **Evidence:** `restore_production_finalize.php` + finalize self-tests.
- **Affected files:** `includes/backup/restore/restore_production_finalize.php`
- **Recommended fix:** None.
- **Production risk:** Low residual crash window (standard for file-based locks).

### F-LOCK-04 — Rollback/finalize omitted from exec active statuses
- **Severity:** Medium
- **Impact:** See F-ARCH-03. Active-set incompleteness weakens “one active restore” semantics during rollback/finalize.
- **Evidence:** `orange_restore_exec_active_statuses()` ends at uploads cutover ready.
- **Affected files:** `restore_execution_orchestrator.php`
- **Recommended fix:** Include rollback_* and finalize_* running statuses in active set + cancel rules.
- **Production risk:** Concurrent job metadata / unclear cancel semantics late in window.

---

## 4. Checkpoint system

### F-CP-01 — Production import / uploads / rollback checkpoints present (good)
- **Severity:** Info (positive)
- **Impact:** Resume paths exist for mid-cutover crashes.
- **Evidence:** Import C0–C8 (drill asserts); rollback C9–C12 with highest-checkpoint resume (`restore_production_rollback.php`); uploads cutover reconcile helpers.
- **Affected files:** `restore_production_import.php`, `restore_production_uploads_cutover.php`, `restore_production_rollback.php`
- **Recommended fix:** None for presence.
- **Production risk:** Low if operators use approved resume CLIs only.

### F-CP-02 — Checkpoint ordering depends on engine gates (no global enforcer)
- **Severity:** Medium
- **Impact:** Crash consistency is per-engine. There is no cross-engine checkpoint ledger that rejects out-of-order CLI invocation beyond each worker’s entry validation.
- **Evidence:** Separate checkpoint writers per engine; CLI workers accept `--job=` and re-validate entry.
- **Affected files:** production engines + `scripts/backup/restore_*.php` workers
- **Recommended fix:** Keep entry gates strict; add runbook table of legal resume commands per highest checkpoint.
- **Production risk:** Wrong CLI order under stress if gates regress.

**NO FINDINGS** for entirely missing checkpoint IDs on the certified Full path (success C0–C8 / rollback C9–C12 are defined and drilled).

---

## 5. Rollback

### F-RB-01 — Double rollback / completed idempotency (good)
- **Severity:** Info (positive)
- **Impact:** Re-entry after completed rollback is idempotent; “already running” guarded.
- **Evidence:** `rollback_already_running`, `idempotent => true` paths, C12 short-circuit in `restore_production_rollback.php`.
- **Affected files:** `includes/backup/restore/restore_production_rollback.php`
- **Recommended fix:** None.
- **Production risk:** Low for double-exec of completed rollback.

### F-RB-02 — Partial rollback can leave maintenance held (by design)
- **Severity:** Medium
- **Impact:** On failure mid-rollback, maintenance may remain active until finalize/operator action — correct for safety, dangerous if operator believes site is open.
- **Evidence:** Drill asserts maintenance held until rollback finalize; runbook stop conditions.
- **Affected files:** `restore_production_rollback.php`, `ORANGE_DR_OPERATOR_RUNBOOK.md`
- **Recommended fix:** Failed-state matrix must explicitly say “maint stays ON until finalize/rollback_completed”.
- **Production risk:** Extended outage if operator misses maint state; data safer than silent release.

### F-RB-03 — Rollback DB path still Mock-proven in DR drill; clone path does not exercise rollback
- **Severity:** High
- **Impact:** Certification rollback drill uses fixture Mock PDO adapters. P0-4 real clone validates wipe/import/smoke on isolated DBs but **does not** run production rollback from pinned anchor on real MySQL.
- **Evidence:** `OrangeRestoreDrDrillMockPdo` in `restore_dr_drill.php`; `real_clone_validation_report.json` stages = DRV/shadow/target/smoke only (no rollback section).
- **Affected files:** `restore_dr_drill.php`, `restore_real_clone_validation.php`, `docs/backup/real_clone_validation_report.json`
- **Recommended fix:** Extend real-clone validation (or clone-host drill) to include rollback-from-anchor on real MySQL + real uploads tree before CERTIFIED.
- **Production risk:** First live rollback under-proven on real server semantics.

---

## 6. CLI

### F-CLI-01 — Approved 3B.4 mutation workers are CLI-only with argv fences (good)
- **Severity:** Info (positive)
- **Evidence:** `restore_import_production.php`, `restore_uploads_cutover.php`, `restore_rollback.php`, `restore_finalize.php`, drill/clone CLIs check `PHP_SAPI` and reject arbitrary `--path=` / `--db=` / credential argv patterns.
- **Affected files:** `scripts/backup/restore_import_production.php` (and siblings), `run_restore_dr_drill.php`, `run_restore_real_clone_validation.php`
- **Recommended fix:** None.
- **Production risk:** Low for these entrypoints.

### F-CLI-02 — Legacy Phase-2 production CLIs fail-closed (remediated)
- **Severity:** Info (positive / historical High closed)
- **Impact:** Former password-on-argv cutover CLIs no longer execute.
- **Evidence:** Tombstone files exit immediately with `legacy_restore_entrypoint_disabled`; catalog in `restore_production_cli_policy.php`; `self_test_legacy_restore_fencing.php`.
- **Affected files:** eight tombstone scripts + policy module
- **Recommended fix:** Keep permanent; no re-enable flags.
- **Production risk:** Low via these filenames.

### F-CLI-03 — HTTP does not run import/cutover/rollback/finalize/drill (good)
- **Severity:** Info (positive)
- **Evidence:** `admin/api/restore/job/*` expose request/status with `http_never_*` flags; certification GET is read-only.
- **Affected files:** `admin/api/restore/job/*.php`, `certification.php`
- **Recommended fix:** None.
- **Production risk:** Low for HTTP execution of wipe/rename.

**NO FINDINGS** for shell injection in approved 3B.4 mutation CLIs (import uses PDO stream runner; no `passthru` of operator paths observed in those workers).

---

## 7. Security

### F-SEC-01 — Maintenance enforcement wiring (remediated; residual proof gap)
- **Severity:** Medium residual (was Critical)
- **Impact:** Framework maintenance now has route-level enforcement for admin pages/APIs and key storefront mutation APIs. Remaining risk is coverage gaps + lack of certification-drill proof under live HTTP.
- **Evidence (wired):** `config.php` calls `orange_restore_maint_enforcement_http_guard` in admin guards; storefront examples: `api/orders/create-order.php`, `cancel-by-customer.php`, `amend-order-items.php`, payment/auth mutation APIs; `includes/order_intake_queue.php`; `scripts/process_order_intake_queue.php`; `backup_runner.php`; module `restore_maintenance_enforcement.php`; self-test `self_test_maintenance_enforcement.php`.
- **Evidence (gap):** `docs/backup/restore_dr_certification_report.json` still lists open blocker `maintenance_middleware_not_wired` (stale). `ORANGE_DR_PRODUCTION_CERTIFICATION.md` still claims middleware pending. Not every `api/*.php` file is guarded (reads omitted by design); pages are mostly read/render — mutations go through APIs.
- **Affected files:** `restore_maintenance_enforcement.php`, `config.php`, `api/**`, certification docs/JSON
- **Recommended fix:** Refresh certification report/docs; add an integration smoke that activates maint and asserts HTTP 503/blocked on create-order + one admin write; inventory any remaining write APIs without guard.
- **Production risk:** Residual writes on unguarded endpoints during window; process underestimates readiness if stale cert JSON is trusted.

### F-SEC-02 — CSRF + recent-auth + nonce controls on sensitive POSTs (good)
- **Severity:** Info (positive)
- **Evidence:** Restore admin CSRF helper; cutover authorization challenge/finalize require CSRF + nonce + password; final approval nonce consume/expiry/session checks.
- **Affected files:** `admin/api/restore/_bootstrap.php`, `finalize-cutover-authorization.php`, `restore_final_approval.php`, `restore_production_cutover_authorization.php`
- **Recommended fix:** None.
- **Production risk:** Low for those gates.

### F-SEC-03 — Country production blocked in production engines (good)
- **Severity:** Info (positive)
- **Evidence:** Production engines reject `country_recovery`; certification forces `country_restore_certified=false`.
- **Affected files:** `restore_production_*.php`, drill/cert JSON
- **Recommended fix:** Keep blocked until dedicated Country series.
- **Production risk:** Low (Country).

### F-SEC-04 — ZIP / path safety present; DRV hard-depends on ZipArchive
- **Severity:** High (environment-dependent)
- **Impact:** On PHP without `ZipArchive`, DRV uploads stage fails. Production packaging and DRV integrity require the extension. Local Laragon previously had `extension=zip` disabled until clone validation enabled it.
- **Evidence:** `recovery_validation.php` returns error if ZipArchive unavailable; shadow files has pure-PHP fallback for stored zips; DRV does not.
- **Affected files:** `includes/backup/recovery_validation.php`, `restore_shadow_files.php`, host `php.ini`
- **Recommended fix:** Require ZipArchive on production PHP as a hard preflight; verify on target host before any window.
- **Production risk:** False DRV failures or incomplete uploads integrity on some hosts.

### F-SEC-05 — Secrets handling generally sound after P0-2
- **Severity:** Low
- **Impact:** Modern admin public records redact secrets; legacy argv passwords removed via tombstones.
- **Evidence:** Final approval public helpers; tombstones; approved workers `--job=` only.
- **Affected files:** `restore_final_approval.php`, tombstone CLIs
- **Recommended fix:** None beyond keeping tombstones.
- **Production risk:** Low.

### F-SEC-06 — Two-person / dual control not implemented (explicitly deferred)
- **Severity:** High (policy-blocking)
- **Impact:** Code itself marks dual control as **required before production execution** but **not implemented**.
- **Evidence:** `restore_final_approval.php` challenge payload: `two_person_approval.implemented=false`, `required_before_production_execution=true`, `deferred=true`. Design §12 unchecked for two-person control. Cutover authorization is still single operator + password re-auth + nonce.
- **Affected files:** `includes/backup/restore/restore_final_approval.php`, `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`
- **Recommended fix:** Owner decision recorded in archive: (A) implement second approver, or (B) explicitly waive dual control and flip `required_before_production_execution` to false with audited rationale.
- **Production risk:** Single compromised admin session can complete final approval + cutover authorization after re-auth.

### F-SEC-07 — Explicit production cutover authorization (remediated)
- **Severity:** Info (positive / historical High closed)
- **Evidence:** `restore_production_cutover_authorization.php`; APIs challenge/finalize/status; import validate + CLI consume before wipe.
- **Affected files:** cutover authorization module + `restore_production_import.php` + admin APIs
- **Recommended fix:** None for presence.
- **Production risk:** Low relative to pre-P0-3; still single-person (F-SEC-06).

---

## 8. Data safety

### F-DATA-01 — Production identity + credential separation (good)
- **Severity:** Info (positive)
- **Evidence:** Production identity asserts; merge/staging user separation patterns; import asserts session DB before wipe.
- **Affected files:** `restore_production_target.php`, `restore_production_import.php`
- **Recommended fix:** None.
- **Production risk:** Low if `.env.php` correctly configured.

### F-DATA-02 — Wrong `.env.php` / DB_NAME still wipes “configured production”
- **Severity:** High
- **Impact:** Identity checks ensure PDO session DB equals configured `DB_NAME`. They cannot detect wrong logical environment (clone host pointed at live name, mislabeled env).
- **Evidence:** Assert compares `SELECT DATABASE()` to configured production DB name only.
- **Affected files:** `restore_production_target.php`, server `.env.php` (out of repo)
- **Recommended fix:** Pre-flight: confirm host + DB_NAME + merge user; optional `ORANGE_ENVIRONMENT=production|clone` marker enforced before wipe.
- **Production risk:** Wipe of unintended database that matches configured name.

### F-DATA-03 — SQL import safety filters present (good)
- **Severity:** Info (positive)
- **Evidence:** Target import validates statements; rejects dangerous patterns in `restore_sql_safety.php`; wipe is table-scoped inside asserted schema.
- **Affected files:** `restore_sql_runner.php`, `restore_sql_safety.php`
- **Recommended fix:** None.
- **Production risk:** Residual novel SQL edge cases—not observed as open defect.

### F-DATA-04 — Uploads root derived from project root for CLI jobs (good)
- **Severity:** Info (positive)
- **Evidence:** Uploads directory helpers; 3B.4 CLIs resolve project root from script location, not argv.
- **Affected files:** `restore_paths.php`, approved workers
- **Recommended fix:** None.
- **Production risk:** Low if deploy root correct.

### F-DATA-05 — Real clone markers reject production identity (good)
- **Severity:** Info (positive)
- **Evidence:** `.orange_restore_real_clone` markers; target/shadow DB names forbidden from equaling production; isolation asserts before destructive stages; report `production_isolation_proof`.
- **Affected files:** `restore_real_clone_validation.php`, `docs/backup/real_clone_validation_report.json`
- **Recommended fix:** None.
- **Production risk:** None for clone path when markers enforced.

---

## 9. Production safety

### F-PROD-01 — Maintenance traffic fence (code remediated; cert stale)
- Same residual as F-SEC-01.

### F-PROD-02 — Phase-2 production cutover CLIs (remediated)
- Same as F-CLI-02 / residual F-ARCH-01.

### F-PROD-03 — DR drill does not touch real production (good)
- **Severity:** Info (positive)
- **Evidence:** Certification JSON `confirmation.real_production_*=false`; fixture DB names ≠ `orange_db`; clone report asserts production not touched.
- **Affected files:** `restore_dr_certification_report.json`, `real_clone_validation_report.json`
- **Recommended fix:** None.
- **Production risk:** None for drills/clones themselves.

### F-PROD-04 — HTTP maintenance activation is real framework state
- **Severity:** Low (was Medium; middleware now exists)
- **Impact:** Activating maintenance via admin is real ACTIVE state; enforcement should block writes. UI still must not be trusted without verifying blocked responses.
- **Evidence:** `activate-maintenance.php` + enforcement module.
- **Affected files:** admin activate-maintenance API, enforcement module
- **Recommended fix:** Operator checklist: after activate, prove one blocked storefront write before wipe CLI.
- **Production risk:** Low if checklist followed.

### F-PROD-05 — Real clone does not prove full production cutover surface
- **Severity:** High
- **Impact:** P0-4 proves isolated real MySQL wipe/import/DRV/shadow/smoke only. It does **not** prove: uploads two-phase rename, production finalize, maintenance release, retention pin under live retention job, RTO on production-sized dumps, or end-to-end job framework statuses on a clone host.
- **Evidence:** `real_clone_validation_report.json` stages; module notes; DR drill still separate Mock path.
- **Affected files:** `restore_real_clone_validation.php`, certification docs
- **Recommended fix:** Clone-host Full drill (success + rollback) with real MySQL + real uploads FS before CERTIFIED; keep synthetic clone as continuous regression.
- **Production risk:** First live Full window still has unproven FS cutover/RTO dimensions.

---

## 10. Documentation

### F-DOC-01 — Design checklist §12 out of sync with code
- **Severity:** Medium
- **Impact:** Operators may misread readiness: unchecked items include both true gaps and items already implemented (middleware wiring, clone SQL proof, authorization).
- **Evidence:** `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md` §12 still unchecked for maintenance middleware, clone proofs, two-person, etc., while body documents 3B.4H/I/K/L DONE.
- **Affected files:** `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`
- **Recommended fix:** Reconcile checkboxes: check only what is **proven**; leave true gaps unchecked.
- **Production risk:** Premature or delayed go-live decisions.

### F-DOC-02 — Certification markdown still describes pre-P0 world
- **Severity:** High
- **Impact:** `ORANGE_DR_PRODUCTION_CERTIFICATION.md` still states middleware pending and Mock-only DB steps as the expected CONDITIONAL reason — contradicts P0-1 and P0-4 code evidence.
- **Evidence:** “Expected honest outcome” section still references 3B.4H pending and mock PDO adapters.
- **Affected files:** `docs/backup/ORANGE_DR_PRODUCTION_CERTIFICATION.md`
- **Recommended fix:** Rewrite expected outcome for post-P0 stack; list **current** residual blockers only.
- **Production risk:** Auditors/operators trust stale narrative over code.

### F-DOC-03 — Operator runbook exists (good) but incomplete failure matrix
- **Severity:** Medium
- **Impact:** Runbook covers command order and stop conditions; lacks exhaustive failed-state → CLI matrix (F-SM-02) and explicit “Phase-2 CLIs are tombstones”.
- **Evidence:** `ORANGE_DR_OPERATOR_RUNBOOK.md`.
- **Affected files:** `docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md`
- **Recommended fix:** Add failure/resume matrix + tombstone warning.
- **Production risk:** Operator error under pressure.

### F-DOC-04 — Certification JSON open_blockers stale vs tip `c3dd091a`
- **Severity:** High
- **Impact:** Machine-readable certification still lists `maintenance_middleware_not_wired` (high) and `db_steps_use_fixture_adapters` (medium) without acknowledging P0 remediations; `tested_commit` is `59f3f447`.
- **Evidence:** `docs/backup/restore_dr_certification_report.json` `open_blockers` + `tested_commit`.
- **Affected files:** `docs/backup/restore_dr_certification_report.json`
- **Recommended fix:** Re-run drill/certification generation on current tip; refresh blockers to residual-only set (dual control, ZipArchive host, full clone cutover/rollback, transition matrix, etc.).
- **Production risk:** False sense that Critical middleware gap still exists **or** false sense that Mock-only is the only gap.

---

## 11. Tests

### F-TEST-01 — Strong engine suite coverage; master runner incomplete vs P0
- **Severity:** High
- **Impact:** `run_restore_certification_tests.php` still runs 13 suites and **omits** P0 self-tests that now gate production safety claims.
- **Evidence:** Master suite list lacks `self_test_maintenance_enforcement.php`, `self_test_legacy_restore_fencing.php`, `self_test_production_cutover_authorization.php`, `self_test_restore_real_clone_validation.php`.
- **Affected files:** `scripts/backup/run_restore_certification_tests.php`
- **Recommended fix:** Add the four P0 suites (clone suite may be environment-gated with explicit skip reason if mysqld clone unavailable).
- **Production risk:** CI/cert master can be green while P0 regressions go unnoticed.

### F-TEST-02 — Mock PDO remains in DR drill (honesty — partially mitigated)
- **Severity:** Medium (was High; mitigated by P0-4 parallel path)
- **Impact:** DR drill success/rollback still use Mock PDO for DB wipe/import/rollback. Real clone path covers synthetic SQL restore only.
- **Evidence:** `OrangeRestoreDrDrillMockPdo`; certification confirmation `real_production_restore_run=false`; clone report `mock_pdo_used=false` for its own pipeline.
- **Affected files:** `restore_dr_drill.php`, `restore_real_clone_validation.php`
- **Recommended fix:** Keep Mock drill for speed; require real-clone (+ future full clone cutover) for CERTIFIED.
- **Production risk:** Over-trusting drill green alone.

### F-TEST-03 — ZipArchive-less PHP breaks DRV honesty
- **Severity:** Medium
- **Impact:** Same as F-SEC-04. Clone CLI now hard-requires ZipArchive; DR drill historically logged Uploads ZIP FAIL while other stages continued.
- **Evidence:** `recovery_validation.php`; clone CLIs exit 2 without ZipArchive.
- **Affected files:** DRV + clone CLIs
- **Recommended fix:** Host preflight; do not CERTIFY on ZipArchive-less PHP.
- **Production risk:** Misleading package validation.

### F-TEST-04 — Production import self-tests still use fixtures/mocks for engine unit speed
- **Severity:** Low
- **Impact:** Unit self-tests are not live MySQL proofs (by design). Honesty depends on clone suite being in the gate (F-TEST-01).
- **Evidence:** `self_test_production_import.php` patterns historically use overrides/fixtures.
- **Affected files:** production engine self-tests
- **Recommended fix:** Keep unit mocks; bind CERTIFIED to real clone + refreshed cert JSON.
- **Production risk:** Low if F-TEST-01 fixed.

**NO FINDINGS** for total absence of self-tests (coverage is broad across shadow/import/uploads/rollback/finalize/admin).

---

## 12. Code quality

### F-CQ-01 — Duplicated job models and lock helpers
- **Severity:** Medium
- **Impact:** Cognitive load; risk of fixing a bug in one stack only.
- **Evidence:** `restore_job.php` vs `restore_job_framework.php`; multiple lock implementations (fw, exec, maint, shadow files).
- **Affected files:** listed modules
- **Recommended fix:** Freeze Phase-2 stack; document “do not extend”; eventual removal after owner approval.
- **Production risk:** Indirect (wrong stack edits).

### F-CQ-02 — Dead / future-only integration list
- **Severity:** Low
- **Impact:** Same as F-ARCH-06.
- **Evidence:** `orange_restore_maint_fw_future_integration_points()`.
- **Affected files:** `restore_maintenance_framework.php`
- **Recommended fix:** Update labels post-wiring.
- **Production risk:** Confusion only.

### F-CQ-03 — Large functions / modules
- **Severity:** Low
- **Impact:** Review difficulty.
- **Evidence:** Large production engines and drill module.
- **Affected files:** restore production/drill modules
- **Recommended fix:** Defer.
- **Production risk:** Low.

**NO FINDINGS** for unused constants that break runtime (duplicates exist but are not proven dead without deeper unused-symbol analysis).

---

## 13. Performance

### F-PERF-01 — SQL statement buffering / large dump memory risk
- **Severity:** Medium
- **Impact:** Streaming import exists, but very large statements or pathological dumps can still stress PHP memory/time limits during live import.
- **Evidence:** Prior audit notes on statement buffer guards; import runner is PDO-based stream oriented in `restore_sql_runner.php`.
- **Affected files:** `restore_sql_runner.php`, PHP host limits
- **Recommended fix:** Enforce host PHP memory/time preflight; statement size guard if not already strict; clone drill with production-sized dump sample.
- **Production risk:** Import abort mid-wipe window (maint remains ON — safe but outage extends).

### F-PERF-02 — Certification HTTP pulls heavy drill graph
- **Severity:** Low
- **Impact:** Same as F-ARCH-04 (latency/memory on Restore Center).
- **Evidence:** `certification.php` → `restore_dr_drill.php` require chain.
- **Affected files:** those files
- **Recommended fix:** Thin reader module.
- **Production risk:** Low.

### F-PERF-03 — Blocking long wipe/import on single CLI process
- **Severity:** Info (accepted architecture)
- **Impact:** Production mutation is intentionally CLI/blocking. Stale lock interaction is the real risk (F-LOCK-01).
- **Evidence:** Approved workers are synchronous CLI.
- **Affected files:** approved mutation CLIs
- **Recommended fix:** Heartbeat (F-LOCK-01); operator monitoring.
- **Production risk:** Operational, not a design defect.

**NO FINDINGS** for unbounded backup retention pin growth as an observed defect (pin store exists and prune respects pins — see Recovery).

---

## 14. Recovery

### F-REC-01 — Retention pins exist and are respected by retention prune (good)
- **Severity:** Info (positive)
- **Evidence:** `orange_backup_retention_is_pinned()` skips pinned packages in `backup_retention.php`; drill asserts pin preserved.
- **Affected files:** `includes/backup/backup_retention.php`, certification drill
- **Recommended fix:** Keep; prove on clone host under real retention cron once.
- **Production risk:** Low if pin API used for pre-restore anchor.

### F-REC-02 — Operator recovery path incomplete in docs
- **Severity:** Medium
- **Impact:** Engines support resume/idempotency, but operator-facing matrix is incomplete (F-DOC-03 / F-SM-02).
- **Evidence:** Runbook vs engine-local failed states.
- **Affected files:** `ORANGE_DR_OPERATOR_RUNBOOK.md`
- **Recommended fix:** Document every failed status → allowed next CLI / stop condition.
- **Production risk:** Wrong recovery action under pressure.

### F-REC-03 — Can operator recover from every documented failure?
- **Severity:** Medium
- **Impact:** For injected drill failures: yes (17/17 fail-closed in cert JSON). For live partial uploads rename / long import crash: recoverable **if** runbook+checkpoints followed; not fully proven on real FS/MySQL together (F-PROD-05 / F-RB-03).
- **Evidence:** Certification `failure_injection_summary.ok=true`; clone report lacks rollback/uploads cutover.
- **Affected files:** cert JSON, clone report, production engines
- **Recommended fix:** One clone-host crash matrix with real MySQL + real uploads.
- **Production risk:** First live partial failure may surprise operators.

---

## 15. Certification

### Current machine recommendation (as committed)
- `production_execution_recommendation`: **CONDITIONAL**
- `full_restore_certified`: **false**
- `country_restore_certified`: **false**
- `tested_commit`: **`59f3f447`** (stale vs audited tip `c3dd091a`)

### F-CERT-01 — CONDITIONAL still correct; open_blockers list is stale
- **Severity:** High
- **Impact:** CONDITIONAL remains the honest top-level recommendation, but listed blockers no longer match tip `c3dd091a` (middleware wired; real clone exists). Hidden/current blockers are understated or mislabeled.
- **Evidence:** `restore_dr_certification_report.json` open_blockers vs P0 code/docs; clone report PASS at tip.
- **Affected files:** certification JSON + markdown
- **Recommended fix:** Regenerate certification artifacts on current tip; replace open_blockers with residual set below.
- **Production risk:** Governance failure — approving/denying for the wrong reasons.

### F-CERT-02 — Hidden / residual blockers to CERTIFIED (current tip)

| Blocker | Severity | Notes |
|---------|----------|-------|
| Dual-control undecided while code requires it | High | F-SEC-06 |
| Certification artifacts not refreshed on tip | High | F-DOC-02/04, F-CERT-01 |
| Master cert test runner omits P0 suites | High | F-TEST-01 |
| No real-MySQL rollback + uploads cutover proof | High | F-RB-03, F-PROD-05 |
| ZipArchive required on production PHP | High | F-SEC-04 |
| Wrong-environment DB_NAME risk | High | F-DATA-02 |
| Framework transition matrix missing | High | F-SM-01 / F-ARCH-02 |
| Exec lock heartbeat / late active statuses | Medium | F-LOCK-01/04 |
| Design checklist / runbook drift | Medium | F-DOC-01/03 |
| Country production restore | High (keep blocked) | Not a Full-restore fix — remains out of scope |

### F-CERT-03 — Prior Critical blockers closed in code
- **Severity:** Info (positive)
- **Evidence:** P0-1 maintenance enforcement; P0-2 legacy CLI tombstones; P0-3 cutover authorization; P0-4 real clone validation report PASS (`server_version` 8.4.3, `mock_pdo_used=false`).
- **Affected files:** remediation modules + `docs/backup/real_clone_validation_report.json`
- **Recommended fix:** Reflect in regenerated certification.
- **Production risk:** N/A (closed).

---

## Scores

| Dimension | Score /100 | Rationale (brief) |
|-----------|------------|-------------------|
| 1. Overall architecture | **74** | Strong 3B layering + fences; residual dual libraries; no FW transition matrix; heavy modules |
| 2. Security | **78** | CSRF/nonce/reauth/authz/maint wiring solid; dual-control gap; ZipArchive/env identity gaps |
| 3. Reliability | **73** | Checkpoints/rollback idempotency strong; lock heartbeat + late active set + real rollback/FS proof gaps |
| 4. Maintainability | **68** | Dual stacks, god modules, stale docs/cert artifacts, future_integration naming drift |
| 5. Production readiness | **READY WITH CONDITIONS** | See conditions below — not READY, not NOT READY |

---

## Conditions blocking READY

1. **Owner dual-control decision** (implement second approver **or** explicit archive waiver + code flag alignment) — F-SEC-06.
2. **Regenerate certification** on tip `c3dd091a` (or newer) with residual-only `open_blockers` — F-CERT-01/F-DOC-04.
3. **Update certification markdown** expected-outcome narrative post-P0 — F-DOC-02.
4. **Add P0 suites to certification master runner** (clone suite environment-aware) — F-TEST-01.
5. **Prove real-MySQL + real-FS uploads cutover and rollback** on an isolated clone host (beyond synthetic SQL clone) — F-PROD-05/F-RB-03.
6. **Production PHP ZipArchive preflight green** — F-SEC-04.
7. **Live-window environment identity preflight** (host/DB_NAME/merge user / optional env marker) — F-DATA-02.
8. **Framework transition matrix** (or documented owner acceptance that engine gates alone are sufficient) — F-SM-01.
9. **Exec lock heartbeat + include rollback/finalize in active statuses** — F-LOCK-01/04.
10. **Reconcile design §12 checklist + runbook failure matrix** — F-DOC-01/03.
11. **Country production restore remains disabled** (condition to keep, not remove).

---

## MUST fix before production (live Full Restore)

| Priority | Item | Finding IDs |
|----------|------|-------------|
| P0 | Owner decision on two-person approval (implement or waive in archive + align flags) | F-SEC-06 |
| P0 | Refresh certification JSON + `ORANGE_DR_PRODUCTION_CERTIFICATION.md` on current tip | F-CERT-01, F-DOC-02, F-DOC-04 |
| P0 | Include P0 self-tests in `run_restore_certification_tests.php` | F-TEST-01 |
| P0 | Clone-host proof: uploads cutover rename + rollback on real MySQL/FS (not Mock) | F-PROD-05, F-RB-03, F-TEST-02 |
| P0 | Confirm ZipArchive enabled on production PHP | F-SEC-04 |
| P0 | Environment identity preflight for live window | F-DATA-02 |
| P1 | Framework status transition matrix + tests | F-ARCH-02, F-SM-01 |
| P1 | Exec lock heartbeat; late-phase active statuses | F-LOCK-01, F-LOCK-04, F-ARCH-03 |
| P1 | Operator failed-state / resume matrix in runbook | F-SM-02, F-DOC-03, F-REC-02 |
| P1 | Reconcile design checklist §12 | F-DOC-01 |
| P1 | Maintenance HTTP integration smoke under ACTIVE maint | F-SEC-01 residual |
| P2 | Thin certification JSON reader for HTTP | F-ARCH-04, F-PERF-02 |
| P2 | Phase-2 library call-site CI fence | F-ARCH-01 residual |
| P2 | Statement/memory guard / sized-dump clone sample | F-PERF-01 |
| Keep | Country production restore disabled | F-SEC-03 |

**Country production restore:** remain **NOT CERTIFIED / blocked**.

---

## Category index (explicit)

| # | Category | Outcome |
|---|----------|---------|
| 1 | Architecture | Findings F-ARCH-01…06 |
| 2 | State machine | Findings F-SM-01…03; terminals OK |
| 3 | Locks | Findings F-LOCK-01…04 |
| 4 | Checkpoints | Findings F-CP-01…02 |
| 5 | Rollback | Findings F-RB-01…03 |
| 6 | CLI | Findings F-CLI-01…03; **NO FINDINGS** shell injection on approved workers |
| 7 | Security | Findings F-SEC-01…07 |
| 8 | Data safety | Findings F-DATA-01…05 |
| 9 | Production safety | Findings F-PROD-01…05 |
| 10 | Documentation | Findings F-DOC-01…04 |
| 11 | Tests | Findings F-TEST-01…04 |
| 12 | Code quality | Findings F-CQ-01…03 |
| 13 | Performance | Findings F-PERF-01…03 |
| 14 | Recovery | Findings F-REC-01…03 |
| 15 | Certification | Findings F-CERT-01…03 |

---

## Appendix A — Approved production mutation CLIs (allowlist)

1. `scripts/backup/restore_import_production.php`
2. `scripts/backup/restore_uploads_cutover.php`
3. `scripts/backup/restore_rollback.php`
4. `scripts/backup/restore_finalize.php`

Policy: `includes/backup/restore/restore_production_cli_policy.php`.

## Appendix B — Evidence artifacts reviewed

- `docs/backup/restore_dr_certification_report.json`
- `docs/backup/real_clone_validation_report.json`
- `docs/backup/ORANGE_DR_PRODUCTION_CERTIFICATION.md`
- `docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md`
- `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`
- `docs/backup/PRODUCTION_IMPORT_SAFETY.md`
- `docs/backup/RESTORE_PHASE2_CLI_ENTRYPOINTS.md`
- P0 modules: maintenance enforcement, CLI policy/tombstones, cutover authorization, real clone validation

---

*End of Enterprise Final Audit — tip `c3dd091a` — 2026-07-18 UTC.*
