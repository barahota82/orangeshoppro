# Orange Disaster Recovery Platform — Enterprise Final Audit (Round 2)

| Field | Value |
|-------|--------|
| Audit type | Independent Principal Software Architect / Enterprise Security Auditor |
| Round | **2 — evidence-based re-certification** |
| Methodology | **Identical** to Audit #1 (`docs/backup/ENTERPRISE_FINAL_AUDIT.md` at commit `980dc6ad`): same severity ladder (Critical/High/Medium/Low/Info), same scoring dimensions, same READY / READY WITH CONDITIONS / NOT READY thresholds, same production-certification bar |
| Audit #1 baseline tip | `980dc6ad` (original Enterprise Final Audit commit) |
| Audit #2 audited tip | `559e68c9` (current `main` at Round 2 writing) |
| Remediation window reviewed | `980dc6ad` → `559e68c9` (P0-1…P0-4 + Audit #1 doc refresh) |
| Mode | **AUDIT ONLY** — no implementation, no refactor, no cleanup outside documentation |
| Date (UTC) | 2026-07-18 |

**Mandate:** Re-audit the current repository from scratch. Do **not** assume prior remediations are correct. Do **not** relax requirements. Do **not** downgrade findings merely because they were already known. Determine whether remediations after `980dc6ad` actually closed Audit #1 findings.

**Original Audit #1 file is preserved** at `docs/backup/ENTERPRISE_FINAL_AUDIT.md` (later rewritten at `559e68c9` with post-P0 narrative). Round 2 dispositions below are measured against the **original finding set and severities as committed in `980dc6ad`**, re-verified against **current code**.

---

## Executive verdict (Round 2)

Remediation work after `980dc6ad` **closed the Critical traffic-fence defect in code** and **closed the High runnable Phase-2 cutover CLI / password-argv path**. Explicit cutover authorization and a real isolated MySQL clone path were added.

The platform is **no longer NOT READY** solely due to unwired maintenance + live Phase-2 cutover CLIs.

It is still **not READY** for unrestricted live Full Restore.

**Production readiness (Round 2): READY WITH CONDITIONS**

Country production restore remains **blocked / not certified**.

---

## Comparison summary — Audit #1 → Audit #2

### Severity inventory (defect findings; Info/positive excluded from counts)

| Severity | Audit #1 (`980dc6ad`) | Audit #2 (open residual + new) | Delta |
|----------|----------------------:|-------------------------------:|------:|
| Critical | **1** (F-SEC-01 / F-PROD-01) | **0** | −1 |
| High | **12** (approx. defect Highs: F-ARCH-01/02, F-SM-01/03, F-CLI-02, F-SEC-04, F-DATA-02, F-PROD-02, F-TEST-02, F-RB-04, F-REC-02, F-PERF-02 clustered) | **10** open High-class residuals/new (see below) | net improvement on original Critical/CLI/authz; new Highs from cert honesty / incomplete clone surface |
| Medium | **~12** | **~11** | slight net change |
| Low | **~6** | **~7** | +naming/docs drift |

*Note:* Audit #1 did not publish a machine count table; Round 2 recounts defect severities from the `980dc6ad` text. Exact High clustering treats F-PROD-01 as the Critical alias of F-SEC-01 (not double-counted as High).

### Score changes (recalculated from zero in Round 2)

| Dimension | Audit #1 | Audit #2 | Delta |
|-----------|---------:|---------:|------:|
| Architecture | **62** | **74** | **+12** |
| Security | **58** | **77** | **+19** |
| Reliability | **68** | **73** | **+5** |
| Maintainability | **55** | **67** | **+12** |
| Production readiness | **NOT READY** | **READY WITH CONDITIONS** | improved |

### Certification changes

| Field | Audit #1 assessment | Audit #2 assessment |
|-------|---------------------|---------------------|
| Machine JSON recommendation | CONDITIONAL (escalated by auditor to NOT READY for live) | CONDITIONAL still present in stale JSON; auditor readiness = **READY WITH CONDITIONS** |
| `full_restore_certified` | false | false (unchanged; correct) |
| `country_restore_certified` | false | false (unchanged; correct) |
| Critical open blockers (auditor) | Yes — unwired maint | **No Critical** open in code |
| Honest cert artifacts | Partially honest | **Stale** vs tip (new High process finding) |

### Remediation disposition totals (Audit #1 defect findings)

| Disposition | Count |
|-------------|------:|
| REMEDIATED | 3 |
| PARTIALLY REMEDIATED | 8 |
| NOT REMEDIATED | 18 |
| REGRESSED | 0 |

*(Positive Info findings from Audit #1 are listed as STILL HOLDS / N/A below; not counted in the four disposition totals.)*

---

## Finding-by-finding disposition (Audit #1 → current code)

Legend: **REMEDIATED** | **PARTIALLY REMEDIATED** | **NOT REMEDIATED** | **REGRESSED** | **STILL HOLDS** (positive Info)

---

### Architecture

#### F-ARCH-01 — Dual restore stacks / runnable Phase-2 cutover CLIs
- **Audit #1 severity:** High
- **Disposition:** **PARTIALLY REMEDIATED**
- **Evidence:** All eight legacy production entrypoints are fail-closed tombstones (`legacy_restore_entrypoint_disabled`) exiting before loading orchestrators — e.g. `scripts/backup/restore_full_database_cutover.php`. Allowlist of four approved workers in `includes/backup/restore/restore_production_cli_policy.php`. Self-test `scripts/backup/self_test_legacy_restore_fencing.php`. Phase-2 **libraries** (`restore_orchestrator.php`, `restore_e2e_orchestrator.php`, `restore_merge_*.php`) remain loadable.
- **Affected files:** tombstone CLIs; `restore_production_cli_policy.php`; Phase-2 library modules
- **Remaining production risk:** Medium — future caller could re-invoke libraries; current CLI/HTTP operator path is fenced.

#### F-ARCH-02 — Framework transition helper has no from→to matrix
- **Audit #1 severity:** High
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** `orange_restore_fw_transition()` still only checks `orange_restore_fw_allowed_statuses()`; no prior-status matrix (`restore_job_framework.php` ~961–990).
- **Affected files:** `includes/backup/restore/restore_job_framework.php`
- **Remaining production risk:** High — impossible histories if a buggy caller misuses the helper.

#### F-ARCH-03 — Split active-job definitions; exec active omits rollback/finalize
- **Audit #1 severity:** Medium
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** `orange_restore_fw_active_statuses()` still early-only; `orange_restore_exec_active_statuses()` still ends at `uploads_cutover_ready` (`restore_execution_orchestrator.php`).
- **Affected files:** `restore_job_framework.php`, `restore_execution_orchestrator.php`
- **Remaining production risk:** Medium — late-phase concurrency/cancel clarity.

#### F-ARCH-04 — HTTP certification loads full drill graph
- **Audit #1 severity:** Medium
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** `admin/api/restore/certification.php` still `require_once` … `restore_dr_drill.php`.
- **Affected files:** `certification.php`, `restore_dr_drill.php`
- **Remaining production risk:** Medium — dependency/memory surface on Restore Center.

#### F-ARCH-05 — Large god modules
- **Audit #1 severity:** Low
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** Large modules remain; remediations added more files rather than splitting gods.
- **Affected files:** `restore_admin.php`, `restore_dr_drill.php`, production engines, new P0 modules
- **Remaining production risk:** Low.

---

### State machine

#### F-SM-01 — No enforced transition matrix
- **Audit #1 severity:** High
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** Same as F-ARCH-02.
- **Affected files:** `restore_job_framework.php`
- **Remaining production risk:** High.

#### F-SM-02 — Terminal / dead-state handling engine-local
- **Audit #1 severity:** Medium
- **Disposition:** **NOT REMEDIATED** (design debt; engines still local)
- **Evidence:** Resume/idempotency remain per-engine; no unified failed-state orchestrator; runbook still lacks full matrix.
- **Affected files:** production engines; `ORANGE_DR_OPERATOR_RUNBOOK.md`
- **Remaining production risk:** Medium — operator error under pressure.

#### F-SM-03 — `production_cutover_allowed` inverted gate / missing authorization
- **Audit #1 severity:** High
- **Disposition:** **PARTIALLY REMEDIATED**
- **Evidence:** Explicit `production_cutover_authorization.json` + challenge/finalize APIs + import entry gate + CLI consume (`restore_production_cutover_authorization.php`, `restore_production_import.php` validate/consume). Import still requires readiness flag `production_cutover_allowed` empty/false (readiness ≠ authorization). Design checklist language still mixed.
- **Affected files:** cutover authorization module; import engine; design doc
- **Remaining production risk:** Low for missing authz layer; Medium for naming/process confusion; dual-control still open (F-SEC-06).

---

### Locks

#### F-LOCK-01 — Stale execution lock auto-clear without heartbeat
- **Audit #1 severity:** Medium
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** `ORANGE_RESTORE_EXEC_LOCK_STALE_SECONDS = 21600`; stale clear on acquire; no heartbeat refresh found.
- **Affected files:** `restore_execution_orchestrator.php`
- **Remaining production risk:** Medium — second acquire during long wipe if PID heuristics fail.

#### F-LOCK-02 — Maintenance stale never auto-releases but traffic not blocked
- **Audit #1 severity:** Info / Critical impact via F-SEC-01
- **Disposition:** **PARTIALLY REMEDIATED**
- **Evidence:** Auto-release still forbidden (correct). Traffic fence now wired via `restore_maintenance_enforcement.php` + `config.php` + storefront mutation APIs (closes the “traffic not blocked” half).
- **Affected files:** `restore_maintenance_framework.php`, `restore_maintenance_enforcement.php`, `config.php`, `api/**`
- **Remaining production risk:** See F-SEC-01 residual (proof/coverage).

#### F-LOCK-03 — Finalize release order crash window handled
- **Disposition:** **STILL HOLDS** (positive)
- **Evidence:** Finalize resume/idempotency paths remain.
- **Remaining production risk:** Low.

---

### Checkpoints

#### F-CP-01 / F-CP-02 / F-CP-03 — Import / uploads / rollback checkpoints
- **Disposition:** **STILL HOLDS** (positive)
- **Evidence:** Checkpoint constants and resume paths still present in import/uploads/rollback engines; drill asserts C0–C12.
- **Remaining production risk:** Low if approved CLIs used.

---

### Rollback

#### F-RB-01 — Rollback sources constrained
- **Disposition:** **STILL HOLDS**
- **Remaining production risk:** Low.

#### F-RB-02 — Double-run / idempotent paths
- **Disposition:** **STILL HOLDS**
- **Evidence:** `rollback_already_running` / idempotent completed paths in `restore_production_rollback.php`.
- **Remaining production risk:** Low.

#### F-RB-03 — Rollback does not release maintenance (by design)
- **Disposition:** **STILL HOLDS** (correct)
- **Remaining production risk:** Extended outage if operator misses state — acceptable vs silent release.

#### F-RB-04 — Inconsistency if DB rollback succeeds and files rollback fails
- **Audit #1 severity:** High
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** No new automated reconciler for mixed DB/files partial rollback; still operator CLI resume + maint held.
- **Affected files:** `restore_production_rollback.php`, runbook
- **Remaining production risk:** High under live partial failure without runbook discipline.

---

### CLI

#### F-CLI-01 — 3B.4 destructive CLIs CLI-only + argv fences
- **Disposition:** **STILL HOLDS**
- **Evidence:** Approved workers + drill/clone CLIs reject SAPI/arbitrary path argv.
- **Remaining production risk:** Low.

#### F-CLI-02 — Legacy CLIs accept `--password=` / `--package=`
- **Audit #1 severity:** High
- **Disposition:** **REMEDIATED**
- **Evidence:** Tombstones reject immediately; no credential parsing; static scan in fencing self-test.
- **Affected files:** eight tombstone scripts; `restore_production_cli_policy.php`
- **Remaining production risk:** Low via these entrypoints.

#### F-CLI-03 — HTTP does not run destructive engines
- **Disposition:** **STILL HOLDS**
- **Evidence:** `http_never_*` flags remain on request/status APIs.
- **Remaining production risk:** Low.

---

### Security / Maintenance / Approval

#### F-SEC-01 — Maintenance decide() not wired (Critical)
- **Audit #1 severity:** Critical
- **Disposition:** **PARTIALLY REMEDIATED**
- **Evidence (closed in code):** `includes/backup/restore/restore_maintenance_enforcement.php`; `config.php` admin `orange_restore_maint_enforcement_http_guard`; storefront mutation APIs (`create-order`, cancel, amend, payments, auth OTP/merge, etc.); intake queue + cron + backup runner guards; `self_test_maintenance_enforcement.php`.
- **Evidence (not closed):** Certification JSON still lists `maintenance_middleware_not_wired`; certification markdown still says 3B.4H pending; no certification-drill HTTP proof under ACTIVE maint; `future_integration_points()` still reads as “future”.
- **Affected files:** enforcement module; `config.php`; `api/**`; cert JSON/docs
- **Remaining production risk:** Medium — residual unguarded write surface unlikely for primary order path, but **uncertified** under live HTTP; process risk if stale cert trusted.

#### F-SEC-02 — CSRF + recent-auth + nonce (good)
- **Disposition:** **STILL HOLDS**
- **Remaining production risk:** Low.

#### F-SEC-03 — Country production blocked
- **Disposition:** **STILL HOLDS**
- **Remaining production risk:** Low (Country).

#### F-SEC-04 — DRV ZipArchive hard dependency
- **Audit #1 severity:** High (environment-dependent)
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** `recovery_validation.php` still fails Uploads ZIP without ZipArchive; clone CLIs now hard-require ZipArchive (good for honesty, does not fix host gap).
- **Affected files:** `recovery_validation.php`, clone CLIs, host `php.ini`
- **Remaining production risk:** High on ZipArchive-less production PHP.

#### F-SEC-05 — Secrets / password argv residual
- **Audit #1 severity:** Low
- **Disposition:** **PARTIALLY REMEDIATED**
- **Evidence:** Password-argv legacy CLIs tombstoned (closes residual). Modern redaction still present.
- **Remaining production risk:** Low.

#### F-SEC-06 — Two-person / time-gated approval not implemented
- **Audit #1 severity:** Medium (policy-dependent)
- **Disposition:** **NOT REMEDIATED** — Round 2 **keeps severity High for certification** because code still marks dual control as required before production execution.
- **Evidence:** `restore_final_approval.php`: `two_person_approval.implemented=false`, `required_before_production_execution=true`, `deferred=true`. No second-approver implementation. Design §12 still unchecked.
- **Affected files:** `restore_final_approval.php`, design doc
- **Remaining production risk:** High — single compromised admin session can approve + authorize cutover after re-auth while product claims dual control is required.

---

### Data safety

#### F-DATA-01 / F-DATA-03 / F-DATA-04 — identity / SQL safety / uploads root
- **Disposition:** **STILL HOLDS**
- **Remaining production risk:** Low (given correct `.env.php`).

#### F-DATA-02 — Wrong `.env.php` / DB_NAME wipes configured production
- **Audit #1 severity:** High
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** Identity still compares session DB to configured `DB_NAME` only; no `ORANGE_ENVIRONMENT` marker gate found.
- **Affected files:** `restore_production_target.php`, server `.env.php`
- **Remaining production risk:** High — wrong logical environment.

---

### Production safety

#### F-PROD-01 — Maintenance without traffic fence (Critical)
- **Disposition:** **PARTIALLY REMEDIATED** (alias of F-SEC-01)
- **Remaining production risk:** Medium residual (proof/docs).

#### F-PROD-02 — Parallel Phase-2 cutover CLIs runnable
- **Disposition:** **REMEDIATED** (CLI entrypoints)
- **Remaining production risk:** Low via CLI; Medium residual library re-wire (F-ARCH-01).

#### F-PROD-03 — Drill does not touch real production
- **Disposition:** **STILL HOLDS**
- **Remaining production risk:** None for drill itself.

#### F-PROD-04 — HTTP maintenance activation is real framework state
- **Audit #1 severity:** Medium
- **Disposition:** **PARTIALLY REMEDIATED**
- **Evidence:** Activation still real ACTIVE; enforcement now makes “illusion while traffic continues” largely false for guarded paths. Operator must still verify blocked write before wipe.
- **Remaining production risk:** Low–Medium without checklist proof.

---

### Documentation

#### F-DOC-01 — Design checklist §12 unchecked vs code
- **Audit #1 severity:** Medium
- **Disposition:** **NOT REMEDIATED**
- **Evidence:** §12 checkboxes still largely unchecked while body documents 3B.4H/I/K/L DONE.
- **Affected files:** `PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`
- **Remaining production risk:** Medium — process confusion.

#### F-DOC-02 — Phase numbering drift
- **Audit #1 severity:** Low
- **Disposition:** **STILL HOLDS** / residual Low
- **Remaining production risk:** Low.

#### F-DOC-03 — Operator runbook exists
- **Audit #1:** Info positive with recommended matrix gap
- **Disposition:** **PARTIALLY REMEDIATED** / gap **NOT REMEDIATED**
- **Evidence:** Runbook exists; failed-state → CLI matrix still incomplete; tombstone warning not fully operationalized.
- **Affected files:** `ORANGE_DR_OPERATOR_RUNBOOK.md`
- **Remaining production risk:** Medium.

---

### Tests / Certification honesty

#### F-TEST-01 — Strong suite coverage; master runner green
- **Audit #1:** Info positive
- **Disposition:** **PARTIALLY REMEDIATED / weakened** (not a code REGRESSION of engines; gate completeness failed to absorb P0)
- **Evidence:** `run_restore_certification_tests.php` still lists 13 suites; **omits** `self_test_maintenance_enforcement.php`, `self_test_legacy_restore_fencing.php`, `self_test_production_cutover_authorization.php`, `self_test_restore_real_clone_validation.php`.
- **Affected files:** `run_restore_certification_tests.php`
- **Remaining production risk:** High for false-green certification master — tracked also as **R2-TEST-01**.

#### F-TEST-02 — Mocks hide live MySQL reality
- **Audit #1 severity:** High
- **Disposition:** **PARTIALLY REMEDIATED**
- **Evidence:** Real clone path exists (`restore_real_clone_validation.php`), report PASS, `mock_pdo_used=false`, port 3307, isolated DBs. DR drill still uses `OrangeRestoreDrDrillMockPdo`. Clone does **not** exercise uploads cutover rename or rollback-from-anchor.
- **Affected files:** `restore_real_clone_validation.php`, `restore_dr_drill.php`, `real_clone_validation_report.json`
- **Remaining production risk:** High for first live FS cutover/rollback; Medium for synthetic SQL-only clone confidence.

#### F-TEST-03 — ZipArchive-less DRV false negatives
- **Disposition:** **NOT REMEDIATED**
- **Remaining production risk:** Medium–High (host-dependent).

#### F-TEST-04 — Historical self-test false negative (fixed)
- **Disposition:** **STILL HOLDS** (historical fix intact)
- **Remaining production risk:** None.

---

### Code quality / Performance / Recovery

#### F-CQ-01 — Duplicated job models / locks
- **Disposition:** **NOT REMEDIATED**
- **Remaining production risk:** Indirect Medium.

#### F-CQ-02 — Dead / future-only integration list
- **Disposition:** **NOT REMEDIATED** (now **more misleading** after wiring — see R2-ARCH-01)
- **Remaining production risk:** Low process confusion.

#### F-PERF-01 — Unbounded statement buffer risk
- **Disposition:** **NOT REMEDIATED**
- **Remaining production risk:** Medium on large dumps.

#### F-PERF-02 — Long wipe/import without exec-lock heartbeat
- **Disposition:** **NOT REMEDIATED** (alias F-LOCK-01)
- **Remaining production risk:** Medium.

#### F-PERF-03 — Admin Restore Center load
- **Disposition:** **NOT REMEDIATED**
- **Remaining production risk:** Low.

#### F-REC-01 — Documented failure paths recoverable via CLI
- **Disposition:** **STILL HOLDS** (with gaps)
- **Remaining production risk:** Medium without matrix.

#### F-REC-02 — No automated recovery for mixed DB/files after partial rollback
- **Audit #1 severity:** High
- **Disposition:** **NOT REMEDIATED**
- **Remaining production risk:** High (with F-RB-04).

#### F-REC-03 — Stale maintenance requires human release
- **Disposition:** **STILL HOLDS** (by design, correct)
- **Remaining production risk:** Operational outage if forgotten — acceptable.

---

## New findings (Round 2) — introduced or newly material after remediation

Do **not** hide defects introduced or exposed by remediation work.

### R2-CERT-01 — Certification JSON open_blockers stale vs remediated tip
- **Severity:** High
- **Impact:** Machine-readable certification still claims Critical-class middleware unwired and Mock-only DB, with `tested_commit=59f3f447`, while tip includes P0-1…P0-4. Operators may approve/deny for wrong reasons.
- **Evidence:** `docs/backup/restore_dr_certification_report.json` `open_blockers` codes `maintenance_middleware_not_wired`, `db_steps_use_fixture_adapters`.
- **Affected files:** `restore_dr_certification_report.json`
- **Recommended fix:** Regenerate certification on current tip; residual-only blockers.
- **Production risk:** Governance / false confidence or false panic.

### R2-DOC-01 — Certification markdown still describes pre-P0 world
- **Severity:** High
- **Impact:** `ORANGE_DR_PRODUCTION_CERTIFICATION.md` still instructs completing 3B.4H and expects Mock-adapter CONDITIONAL narrative.
- **Evidence:** “Expected honest outcome” + operator action #2 still cite pending middleware.
- **Affected files:** `docs/backup/ORANGE_DR_PRODUCTION_CERTIFICATION.md`
- **Recommended fix:** Rewrite expected outcome for post-P0 residual blockers only.
- **Production risk:** High process misdirection.

### R2-TEST-01 — Certification master runner omits all P0 self-tests
- **Severity:** High
- **Impact:** Merge/cert gate can pass while P0 remediations regress unnoticed.
- **Evidence:** `run_restore_certification_tests.php` suite list (13) excludes four P0 suites present under `scripts/backup/`.
- **Affected files:** `run_restore_certification_tests.php`
- **Recommended fix:** Add P0 suites; environment-gate real-clone if mysqld unavailable with explicit non-silent skip.
- **Production risk:** High false-green.

### R2-PROD-01 — Real clone validation does not prove uploads cutover or rollback
- **Severity:** High
- **Impact:** P0-4 closes Mock-only wipe/import honesty only for synthetic SQL path. Live Full Restore also depends on uploads rename + rollback-from-anchor — still Mock-only in DR drill and absent from clone report.
- **Evidence:** `real_clone_validation_report.json` stages = DRV/shadow/target/smoke; no rollback/uploads-cutover keys; drill still Mock PDO.
- **Affected files:** `restore_real_clone_validation.php`, `restore_dr_drill.php`
- **Recommended fix:** Clone-host Full success+rollback with real MySQL + real uploads FS.
- **Production risk:** High for first live FS/rollback window.

### R2-SEC-01 — Product self-declares dual control required but deferred
- **Severity:** High
- **Impact:** Stronger than Audit #1’s Medium policy note: runtime challenge payload asserts `required_before_production_execution=true` while `implemented=false`. Shipping Full restore under that flag is a certification contradiction.
- **Evidence:** `restore_final_approval.php` two_person_approval block.
- **Affected files:** `restore_final_approval.php`
- **Recommended fix:** Implement dual control **or** owner archive waiver + flip required flag to false.
- **Production risk:** High — single-operator production cutover while product claims dual control is mandatory.

### R2-ARCH-01 — `future_integration_points` still advertised after wiring
- **Severity:** Low
- **Impact:** Misleading status payload after P0-1.
- **Evidence:** `orange_restore_maint_fw_future_integration_points()` still exposed from maintenance framework status.
- **Affected files:** `restore_maintenance_framework.php`
- **Recommended fix:** Relabel as wired inventory.
- **Production risk:** Process confusion only.

### R2-CQ-01 — Remediation increased dual-path surface without CI call-site fence
- **Severity:** Medium
- **Impact:** Tombstones close CLIs, but no repository gate prevents new PHP from calling Phase-2 cutover functions.
- **Evidence:** Libraries retained; no CI allowlist scan found in certification master.
- **Affected files:** Phase-2 libraries; CI/scripts
- **Recommended fix:** Static forbid-list for production cutover symbols outside tests.
- **Production risk:** Medium future regression.

**REGRESSED findings vs Audit #1 code defects:** **none observed**. Remediations did not re-enable password-argv CLIs or remove enforcement wiring. Documentation/cert artifacts became **more incorrect relative to code** (process regression → R2-CERT-01 / R2-DOC-01), which Round 2 treats as **new High findings**, not REGRESSED of F-SEC-01 code.

---

## Re-audit scope check (abbreviated evidence)

| Area | Round 2 result |
|------|----------------|
| Architecture | Dual CLI fenced; libraries remain; no transition matrix |
| Security | Maint wired; CSRF/nonce/authz present; dual-control contradiction; ZipArchive host gap |
| State machine | Engine gates only; FW transition unconstrained |
| Locks | No heartbeat; late active statuses incomplete; maint non-auto-release correct |
| Checkpoints | Present C0–C12 |
| Rollback | Idempotent; partial mixed DB/files still operator-dependent; not real-MySQL proven |
| CLI | Approved 4 workers; 8 tombstones; HTTP non-executing |
| Maintenance | Enforcement module wired; cert/docs stale |
| Approval | Final approval + cutover authorization; dual control deferred-but-required |
| Version lock | Still evaluates compatibility; blocks incompatible plans |
| Execution contract | Still validated on maint/import/rollback paths |
| Shadow | Engines present; drill/fixture isolation intact |
| Production import | CLI + identity + authz consume; Country blocked |
| Uploads cutover | Engine present; not real-clone proven |
| Rollback engine | Engine present; Mock in drill |
| Finalization | Present; lock/maint release paths intact |
| DR drill | Still isolated fixtures + Mock PDO |
| Certification | CONDITIONAL JSON stale; not CERTIFIED |
| Documentation | Design/runbook/cert markdown drift |
| Tests | Engines covered; P0 suites outside master runner |

---

## Scores (recalculated from zero)

| Dimension | Score /100 | Rationale |
|-----------|-----------:|-----------|
| 1. Architecture | **74** | CLI dual-stack closed; libraries + missing transition matrix + heavy modules remain |
| 2. Security | **77** | Critical unwired-maint closed in code; authz added; dual-control contradiction + ZipArchive/env identity remain |
| 3. Reliability | **73** | Checkpoints/finalize mature; heartbeat missing; real FS/rollback proof incomplete; partial-rollback gap |
| 4. Maintainability | **67** | Dual libraries remain; docs/cert drift after remediations; god modules larger |
| 5. Production readiness | **READY WITH CONDITIONS** | Critical code blockers closed; High residual blockers remain |

---

## Production readiness

### READY WITH CONDITIONS

Not **READY**. Not **NOT READY** (Critical unwired-maintenance + live Phase-2 cutover CLIs are closed in code).

### Remaining blockers (every item blocking READY)

1. **Owner dual-control decision** — implement second approver **or** explicit archive waiver + align `required_before_production_execution` (F-SEC-06 / R2-SEC-01).
2. **Regenerate certification JSON** on current tip with residual-only `open_blockers` (R2-CERT-01).
3. **Rewrite** `ORANGE_DR_PRODUCTION_CERTIFICATION.md` expected outcome (R2-DOC-01).
4. **Include P0 suites** in `run_restore_certification_tests.php` (R2-TEST-01).
5. **Clone-host proof** of uploads cutover rename + rollback on real MySQL/FS (R2-PROD-01 / F-TEST-02 residual).
6. **Production PHP ZipArchive** preflight green (F-SEC-04).
7. **Environment identity preflight** beyond DB_NAME match (F-DATA-02).
8. **Framework transition matrix** (or explicit owner acceptance of engine-only gates) (F-ARCH-02 / F-SM-01).
9. **Exec lock heartbeat** + rollback/finalize in active statuses (F-LOCK-01 / F-ARCH-03).
10. **Partial rollback operator matrix** in runbook (F-RB-04 / F-REC-02 / F-DOC-03).
11. **Reconcile design §12** checklist (F-DOC-01).
12. **Maintenance HTTP integration smoke** under ACTIVE maint (F-SEC-01 residual).
13. **Keep Country production restore disabled**.

### MUST fix before production (live Full Restore) — Round 2

| Priority | Item | IDs |
|----------|------|-----|
| P0 | Dual-control implement or waive + flag align | F-SEC-06, R2-SEC-01 |
| P0 | Refresh certification JSON + certification markdown | R2-CERT-01, R2-DOC-01 |
| P0 | Add P0 suites to cert master runner | R2-TEST-01 |
| P0 | Real MySQL/FS uploads cutover + rollback proof | R2-PROD-01, F-TEST-02, F-RB-04 |
| P0 | ZipArchive on production PHP | F-SEC-04 |
| P0 | Env identity preflight | F-DATA-02 |
| P1 | FW transition matrix | F-ARCH-02, F-SM-01 |
| P1 | Exec lock heartbeat + late active statuses | F-LOCK-01, F-ARCH-03 |
| P1 | Runbook failed/partial-rollback matrix | F-RB-04, F-REC-02, F-DOC-03 |
| P1 | Design §12 reconcile | F-DOC-01 |
| P1 | Maint HTTP smoke under ACTIVE | F-SEC-01 residual |
| P2 | Thin cert reader; Phase-2 call-site CI fence; SQL buffer guard | F-ARCH-04, R2-CQ-01, F-PERF-01 |

---

## Category index (Round 2)

| Category | Outcome |
|----------|---------|
| Architecture | F-ARCH-01 PARTIAL; 02–05 NOT; + R2-ARCH-01, R2-CQ-01 |
| State machine | F-SM-01 NOT; F-SM-02 NOT; F-SM-03 PARTIAL |
| Locks | F-LOCK-01 NOT; F-LOCK-02 PARTIAL; F-LOCK-03 STILL HOLDS |
| Checkpoints | STILL HOLDS |
| Rollback | F-RB-01…03 STILL HOLDS; F-RB-04 NOT |
| CLI | F-CLI-02 REMEDIATED; 01/03 STILL HOLDS |
| Security / Maintenance / Approval | F-SEC-01 PARTIAL; F-SEC-06 NOT (+R2-SEC-01); F-SEC-04 NOT; authz present |
| Data safety | F-DATA-02 NOT; others STILL HOLDS |
| Production safety | F-PROD-01 PARTIAL; F-PROD-02 REMEDIATED; + R2-PROD-01 |
| Documentation | F-DOC-01 NOT; + R2-DOC-01 |
| Tests / Certification | F-TEST-02 PARTIAL; + R2-TEST-01, R2-CERT-01 |
| Code quality / Perf / Recovery | Mostly NOT REMEDIATED where previously defective |

---

## Auditor attestation (Round 2)

- Methodology and thresholds matched Audit #1 (`980dc6ad`).
- No requirement was relaxed to improve scores.
- Remediations after `980dc6ad` were re-verified in current source; dispositions are evidence-based.
- Original Audit #1 file path `docs/backup/ENTERPRISE_FINAL_AUDIT.md` was **not** overwritten by this Round 2 document.
- Round 2 output path: `docs/backup/ENTERPRISE_FINAL_AUDIT_ROUND2.md`.

*End of Enterprise Final Audit Round 2 — tip `559e68c9` — 2026-07-18 UTC.*
