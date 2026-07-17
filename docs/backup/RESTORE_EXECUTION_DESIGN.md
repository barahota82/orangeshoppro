# Orange Restore Execution Design (Phase 3B.3B-DESIGN)

**Status:** Design review only — no production restore execution is authorized by this document.  
**Date:** 2026-07-17  
**Scope:** Future real Restore Execution engine, grounded in the existing Orange backup/restore codebase on Windows / Plesk / MariaDB / PHP PDO.  
**Related phases:** 3B.1 eligibility UI · 3B.2A job framework · 3B.2B dry-run · 3B.3A execution plan orchestrator (stops at `awaiting_final_approval`).

---

## 0. Dual-track architecture (critical finding)

Orange already has **two restore tracks**. Future work must unify them deliberately — not invent a third engine.

| Track | Location | Operator surface | Production mutation |
|-------|----------|------------------|---------------------|
| **Phase 2 CLI / staging-merge** | `includes/backup/restore/restore_job.php`, `restore_full_staging.php`, `restore_country_staging.php`, `restore_merge_*`, `restore_e2e_orchestrator.php` | CLI scripts under `scripts/backup/` | Full: staging → export → DB cutover → uploads rename cutover (CLI-gated, re-auth). Country: **staging + approval only** in current code paths inspected. |
| **Phase 3B admin framework** | `restore_job_framework.php`, `restore_dry_run.php`, `restore_execution_orchestrator.php`, `admin/api/restore/*`, Restore Center UI | Admin UI | **None.** Stops at metadata plan + `awaiting_final_approval`. |

**Design mandate:** Wire Phase 3B jobs to **reuse** Phase 2 staging/cutover/rollback primitives after approval. Do **not** reimplement SQL import or uploads cutover for admin. Do **not** expose Phase 2 mutating CLIs from the Restore Center until the phases in §15 pass.

---

## 1. Current architecture inventory

### 1.1 Package discovery

| Concern | Source of truth |
|---------|-----------------|
| Backup root | `orange_backup_resolve_root()` — `includes/backup/backup_environment.php` / `backup_paths.php` |
| Full packages | `{backupRoot}/snapshots/{YYYY-MM-DD_HHMMSS}/` via `orange_backup_admin_list_full_packages()`, `orange_backup_admin_resolve_full_package_path()`, `orange_backup_admin_summarize_full_package()` — `backup_admin.php` |
| Country packages | `{backupRoot}/country_packages/{cc}/{id}/` via `orange_backup_admin_list_country_packages()`, `orange_backup_admin_resolve_country_package_path()`, `orange_backup_admin_summarize_country_package()` |
| DRV sibling report | `orange_backup_admin_recovery_report_sibling_path()` — report lives **beside** package as `{packageId}.recovery_validation.json` (not only inside package) |

**Reusable:** all of the above.  
**Gap:** admin framework jobs store `package_id` / type / country only — must re-resolve path at every stage; never accept client absolute paths.

### 1.2 Package validation / verify

| Concern | Function | File |
|---------|----------|------|
| Full verify | `orange_backup_verify_full_package()` | `backup_validate.php` |
| Country verify | `orange_country_export_verify_package()` | `backup_validate.php` |
| Checksums | `orange_backup_verify_checksums()` | `backup_manifest.php` |
| Staging precheck Full | `orange_restore_validation_adapter_package_precheck()` | `restore_validation_adapter.php` |
| Staging precheck Country | `orange_restore_validation_adapter_country_package_precheck()` | same |
| Dry-run checks | `orange_restore_dry_run_execute()` | `restore_dry_run.php` |
| Eligibility (admin) | `orange_restore_admin_package_eligibility()` | `restore_admin.php` |

**Constants:**  
- `ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION = 121`  
- `ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION = '1.0'`  
- Full restore eligibility requires `export_backend === php_pdo` (mysqldump packages rejected for restore path).

**Reusable:** verify + adapters + dry-run.  
**Gap:** no single “execution preflight” that re-runs dry-run + fingerprint + disk + staging DB connectivity under maintenance.

### 1.3 DRV

| Concern | Function | File |
|---------|----------|------|
| Run DRV | `orange_recovery_validate_package()` | `recovery_validation.php` |
| Read report | `orange_backup_admin_read_recovery_validation_report()` | `backup_admin.php` |
| Engine version | `ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION = '1.1'` | |

**Policy already encoded:** Full may proceed with DRV warning if score ≥ 70; Country requires DRV `pass` for eligibility / dry-run. Execution orchestrator reuses the same WARNING policy for plan prep.

### 1.4 Schema / backend compatibility

| Gate | Where |
|------|--------|
| Schema revision 121 | DRV, dry-run, eligibility, exec fingerprint |
| Full backend `php_pdo` only | dry-run, eligibility, exec precheck |
| Country registry `1.0` | dry-run, `orange_restore_country_staging_build_import_plan()` |
| Staging import safety | `restore_sql_safety.php` — statements validated against staging DB name; production DB name forbidden in USE/DDL |

### 1.5 Restore job storage (two roots)

| Track | Path | Lock |
|-------|------|------|
| Phase 2 jobs | `{workRoot}/{jobId}/job.json` + `audit.jsonl` | `{workRoot}/.restore.lock` |
| Phase 3B framework | `{workRoot}/framework/{jobId}/job.json` + `audit.jsonl` + `dry_run_report.json` + `execution_plan.json` | `.restore_framework.lock`, dry-run uses framework lock, `.restore_execution_orchestrator.lock` |
| Work root | `ORANGE_RESTORE_WORK_DIR` or `{backupRoot}/restore_work` | must be outside web root |

**Gap:** no durable link between a 3B `job_id` and a Phase 2 merge `job_id`. Design requires `linked_phase2_job_id` after staging starts.

### 1.6 Locks (inventory)

| Lock | File | Owner |
|------|------|-------|
| Global restore (Phase 2) | `.restore.lock` | merge/staging job |
| Framework (3B) | `framework/.restore_framework.lock` | create / dry-run |
| Execution orchestrator (3B) | `framework/.restore_execution_orchestrator.lock` | plan prep through awaiting approval |
| Maintenance | `.maintenance.json` | merge job (`restore_merge_maintenance.php`) |
| Backup | backup runner lock (separate; must not overlap restore) | backup jobs |

Stale policy (restore locks): ~21600 seconds + dead PID (Windows `tasklist` / POSIX kill).

### 1.7 Audit / logging

| Mechanism | Location |
|-----------|----------|
| Phase 2 audit | `orange_restore_audit_append()` → `audit.jsonl` |
| Phase 3B audit | `orange_restore_fw_audit_append()` |
| CLI stdout | `orange_restore_log()` (CLI only) |
| Admin audit | `audit_log()` for significant admin mutations (pattern elsewhere; restore APIs currently redacted JSON) |

**Gap:** unified execution report downloadable from UI; heartbeat file for long CLI workers.

### 1.8 Execution plans (3B.3A)

File: `{frameworkJobDir}/execution_plan.json`  
Prepared by `orange_restore_exec_prepare_plan()` — metadata only; `execution_started: false`; `requires_final_approval: true`.  
Fingerprint: package id/type/country/schema + manifest/package/DRV checksums; dry-run report SHA stored separately.

### 1.9 Full backup creation

| Function | Role |
|----------|------|
| `orange_backup_run_full()` / `orange_backup_run_via_pdo()` | `backup_runner.php` / `backup_full.php` |
| Format | `database.sql.gz` + `uploads.zip` + `manifest.json` + `health.json` + `checksums.sha256` |
| Backend for restore path | **`php_pdo`** (preferred/required) |
| Fresh pre-restore gate | `orange_restore_fresh_backup_gate()` — run full + verify + DRV score ≥ 70 |

### 1.10 Country package creation

| Function | Role |
|----------|------|
| `orange_country_export_*` / batch | `country_export.php`, `country_batch_export.php` |
| Format | `sql/*.sql` chunks, `files/uploads_country.zip`, registry artifacts, `checksums.sha256` |
| Import plan | `orange_restore_country_staging_build_import_plan()` using table registry restore order + dependency graph |

### 1.11 Retention

| Item | Behavior |
|------|----------|
| Config | `ORANGE_BACKUP_RETENTION_DAYS` (default 30) — `backup_retention.php` |
| Protection today | keeps current run + newest verified healthy; age-based prune |
| **Gap** | no explicit “pinned by restore job / rollback anchor” marker — **must add** before production execution so retention cannot delete the mandatory pre-restore backup |

### 1.12 Reusable vs missing (summary)

**Reusable (do not rewrite):**  
PDO gzip SQL streamer (`orange_restore_sql_runner_import_gzip`), SQL safety, staging DB credentials model, full/country staging runners, fresh backup gate, maintenance file, uploads FS guards (symlink/reparse block), uploads next + rename cutover, approval token/phrase/reauth helpers, DRV/verify/dry-run/eligibility, 3B framework + plan.

**Missing / incomplete for safe admin production restore:**  
1. Bridge 3B job ↔ Phase 2 job  
2. Admin approval endpoint wired to 3B (Phase 2 has CLI approve)  
3. Maintenance middleware for storefront/admin APIs/cron  
4. Retention pin for rollback anchors  
5. Country **production** cutover (staging exists; e2e orchestrator is Full-only)  
6. Proven country table-boundary matrix (GL, shared catalog, FK cross-country)  
7. Durable heartbeat + resume for admin-triggered workers  
8. Unified state machine names (3B vs Phase 2 status vocabulary)

---

## 2. Restore modes

### 2.A Full Disaster Restore

| Asset | Scope |
|-------|--------|
| Database | **Entire** production schema/data replaced via staging import → export artifact → production cutover (not in-place on live DB during import) |
| Uploads | Full `uploads.zip` tree → staging extract → `uploads_next` → rename cutover of production uploads root |
| Application PHP/code | **Never** restored from backup packages (packages are data/uploads only) |
| Config / `.env.php` | **Never** overwritten by restore |
| Schema gate | Package `schema_revision` must equal live expected revision (121) unless owner approves a documented migration exception (out of scope) |

**Must never overwrite:** application source tree, `.env.php`, Plesk/IIS config, unrelated databases on the same MariaDB instance, backup root itself (except writing new packages), other tenants’ DBs.

**Sequence (distinct from Country):**  
precheck → maintenance → mandatory full backup → staging wipe/import full dump → staging verify/DRV → approval (already done in 3B) → merge export → DB cutover → uploads snapshot → uploads cutover → smoke → finalize → disable maintenance.

### 2.B Country Restore

| Asset | Scope |
|-------|--------|
| Database | **Only** country-scoped tables/rows defined by backup table registry + package SQL chunks; delete-then-import for that `country_id` per import plan |
| Uploads | Country uploads archive only (`uploads_country.zip`), applied under country-scoped upload paths — not full tree replace |
| Application files | **Never** |
| Registry | Package `registry_version` must match live registry; dependency graph must validate |
| Shared catalog / global tables | **Not** restored by default; if package contains only country-scoped data, shared products remain |

**Must never overwrite:** other countries’ rows, global config tables without registry allowlist, full uploads tree, GL periods belonging to other countries without explicit mapping, admin users globally.

**Sequence (different):**  
precheck (registry + country code/id bind) → maintenance (possibly lighter) → **mandatory Full** pre-restore backup (see §3) → country staging clone or staging DB with production snapshot baseline → country delete-order + import → country post-validation → **production country apply** (future — not yet a complete e2e path) → smoke → finalize.

**Blocker:** Country production apply is **not** proven safe until §7 matrix is closed. Staging-only is allowed for drills.

---

## 3. Mandatory pre-restore backup

| Topic | Design |
|-------|--------|
| When | After final approval validation and maintenance enter; **before** any staging wipe that is required for the job; always before any production-touching step |
| Engine | Reuse `orange_restore_fresh_backup_gate()` → `orange_backup_run_full()` + `orange_backup_verify_full_package()` + `orange_recovery_validate_package()` |
| Type | **Always Full disaster package**, even for Country restore (rollback must restore whole DB if country apply corrupts shared state) |
| Naming/tagging | Normal snapshot id `YYYY-MM-DD_HHMMSS`; job metadata fields: `pre_restore_backup_package_id`, `pre_restore_backup_checksum`, `pre_restore_backup_drv_score`, `rollback_anchor: true`; optional manifest note `purpose=pre_restore_anchor` / `linked_restore_job_id` |
| Verification | Must `verify.ok === true` |
| DRV | Score ≥ 70 required (same as fresh backup gate); fail closed if below |
| Failure | Abort restore; status `failed` / `execution_failed`; release execution lock per policy; **do not** enter database restore |
| Disk reservation | Precheck free space ≥ max(estimated restore bytes × 2, dry-run estimate, 2× dump+uploads size) before starting backup |
| Retention protection | Pin package in retention (`protected_reason=restore_rollback_anchor`, ttl ≥ approval window + 30 days or until job terminal + N days) |
| Link to job | Stored on both 3B and Phase 2 job JSON; referenced by rollback |
| Rollback use | Primary DB+uploads rollback source for Full; for Country, Full anchor is still the only guaranteed whole-system rollback |

---

## 4. Maintenance and concurrency

### 4.1 Mechanism

Reuse/extend `restore_merge_maintenance.php`:  
- File: `{workRoot}/.maintenance.json`  
- Payload: `job_id`, `reason`, `enabled_at`, `pid`, `hostname`, `mode` (`full_disaster` \| `country_recovery`), `allow_reads`  

**Begin:** after approval_validating succeeds, before pre_restore_backup_running.  
**End:** only after `finalizing` success **or** completed rollback verification **or** explicit emergency unlock by Super Admin with `ROLLBACK`/`UNLOCK` phrase + audit.

### 4.2 User-facing

| Surface | Behavior |
|---------|----------|
| Storefront HTML | Maintenance page / banner: “النظام تحت الصيانة مؤقتاً” — cart checkout disabled |
| Storefront APIs | Writes → `503` + code `maintenance_mode`; safe GETs may continue (products list optional) |
| Admin UI | Read-only except Restore Center emergency controls for owning Super Admin |
| Payments callbacks | Accept and **queue** or return retryable 503 — never apply stock/GL during maintenance (prefer queue with idempotency) |
| Order create | Blocked |
| Cron / scheduled backup | **Blocked** while restore lock or maintenance active (fail with clear log) |
| Backup Center manual run | Blocked if restore global lock or maintenance held |

### 4.3 Lock interaction

| Lock | During execution |
|------|------------------|
| Backup lock | Must be free to start pre-restore backup; hold backup semantics inside backup runner as today |
| Framework lock | Not used for long execution; released after dry-run |
| Execution orchestrator lock | Held from prepare through approval; **upgrade** to Phase 2 global `.restore.lock` when execution starts; release orchestrator lock only when Phase 2 lock acquired (handoff) or on cancel before execution |
| Phase 2 `.restore.lock` | Held for entire mutating window |
| Stale | Same 21600s + PID; emergency unlock requires Super Admin + typed phrase + audit; never auto-clear during `database_restoring` / cutover states without operator |

### 4.4 Writes vs reads

- **Blocked writes:** orders, stock mutations, GL posting, catalog edits, backup create, country export, restore second job  
- **Allowed reads:** health (limited), package list, job status, dry-run reports, execution plan  
- **Workers:** any queue consumer must check maintenance flag at start of each job

---

## 5. Exact execution state machine

### 5.1 Proposed states (future)

Keep 3B pre-execution states, then:

`awaiting_final_approval` → `approval_validating` → `maintenance_entering` → `pre_restore_backup_running` → `pre_restore_backup_verifying` → `restore_preparing` → `database_restoring` → `database_verifying` → `files_restoring` → `files_verifying` → `smoke_testing` → `finalizing` → `completed`

Failure branch: any stage → `failed` (if before irreversible) or → `rollback_preparing` → `rolling_back_database` → `rolling_back_files` → `rollback_verifying` → `rolled_back` | `rollback_failed`

### 5.2 Per-state rules (condensed)

| State | Entry | Allowed writes | Checkpoint | Cancel | Resume | Failure |
|-------|-------|----------------|------------|--------|--------|---------|
| awaiting_final_approval | plan ready | framework metadata | plan.json | yes → execution_cancelled | n/a | n/a |
| approval_validating | POST approve | approval audit, nonce consume | approval_record.json | no | restart approve | → failed |
| maintenance_entering | approval ok | maintenance file | maint checkpoint | no | retry enable | → failed |
| pre_restore_backup_* | maint on | new full package only | anchor fields | no | restart backup if incomplete package discarded | → failed (no DB touch) |
| restore_preparing | anchor ok | staging DB create/wipe prep | staging_ready | no | retry prep | → failed |
| database_restoring | staging ready | **staging DB only** | statements/bytes | no | resume only if importer supports offset (else restart staging wipe) | → failed → rollback_preparing if production already touched; else failed |
| database_verifying | import done | none (reads) | verify report | no | rerun verify | → rollback if prod touched else failed |
| files_restoring | DB verify ok | staging uploads / uploads_next | tree checksum | no | restart extract to clean staging | → failed / rollback |
| files_verifying | extract done | none | checksum match | no | rerun | → rollback if cutover started |
| smoke_testing | files ok | none | smoke report | no | rerun smokes | → rollback_preparing |
| finalizing | smoke pass | disable maint, pin retention, reports | final report | no | reconcile | → failed_post_merge pattern |
| completed | finalize ok | none | — | no | reconcile only | — |
| rollback_* | trigger | restore from anchor / reverse renames | rollback checkpoints | no | continue rollback | → rollback_failed |

### 5.3 Irreversible boundary

**Irreversible (or high-cost) boundary:** first production DB cutover statement that swaps/replaces live data **or** first successful uploads production rename (`uploads` ↔ `uploads_next` / snapshot).  

Before that boundary: cancel/fail without rollback engine (discard staging).  
After that boundary: **must** enter rollback path; cancel button disabled; only rollback/resume rollback.

---

## 6. Database restore design

### 6.1 Current format (ground truth)

- Full dump: **`database.sql.gz`**, produced primarily by **`php_pdo`** exporter  
- Session practices in dumps: charset utf8mb4; FK checks toggled; postamble includes `SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS` (DRV expects this)  
- Import exists: `orange_restore_sql_runner_import_gzip(PDO, gzipPath, stagingDb, productionDb)` — streaming gz, statement split, **staging-only** session assert, statement validation forbidding production DB targeting  

### 6.2 Technical constraints

| Topic | Implication |
|-------|-------------|
| Transactions | DDL in MariaDB often **auto-commits**; wrapping full restore in one transaction is **not** feasible |
| FK | Disable during import; re-enable and verify |
| SQL modes / charset | Session `SET NAMES utf8mb4`; match production collation |
| DEFINER / routines | php_pdo dumps may be table-centric; validate whether views/triggers/events are included — if missing, post-import schema gate must compare expected objects |
| Large files | Stream only; never load whole dump in memory; raise PHP `max_execution_time` for CLI worker; use CLI for import |
| Timeouts | Plesk PHP-FPM unsuitable for long import — **CLI worker mandatory** |
| Partial import | Detect via incomplete statement at EOF, statement counter vs expected, table existence matrix, row-count probes |
| App connections | Maintenance blocks writers; optionally `KILL` non-system sessions on production DB immediately before cutover (careful with Plesk) |

### 6.3 Options

**Option A — In-place restore into production DB**  
Import gzip directly into live `orange_db`.  
- Pros: simpler cutover  
- Cons: irreversible partial import; DDL auto-commit; no clean verify before expose; catastrophic on failure; incompatible with existing safety design  

**Option B — Shadow/staging database + cutover (existing)**  
Import into `ORANGE_RESTORE_STAGING_DB` with dedicated staging credentials ≠ production user; verify; export merge artifact; cut over production under maintenance using merge DB user.  
- Pros: matches implemented Phase 2; verify before expose; aligns with SQL safety asserts  
- Cons: needs staging DB + disk; cutover still sensitive; Plesk user grants required  

### 6.4 Recommendation

**Choose Option B (staging + cutover).**  
It is already implemented for Full (`restore_full_staging.php`, `restore_merge_db_cutover.php`) and matches MariaDB DDL reality and Plesk operational limits (CLI-only cutover). In-place import into production is **rejected** for Orange.

---

## 7. Country restore design

### 7.1 Existing pieces

- Export registry-driven country packages  
- Staging import plan: `orange_restore_country_staging_build_import_plan()` — registry restore_order, dependency graph, delete_order_tables  
- Staging runner stops at `awaiting_owner_approval`  
- E2E orchestrator explicitly **Full-only**

### 7.2 Boundaries that must be proven before production Country restore

| Domain | Risk | Required proof |
|--------|------|----------------|
| Country-scoped tables | Incomplete delete order → orphans/FK breaks | Registry completeness test per schema revision |
| Globally shared tables (`products`, channels, taxonomy) | Accidental overwrite or missing SKUs | Explicit allowlist: Country restore **does not** replace global catalog unless package + registry say so |
| Cross-country FKs | Import fails or attaches wrong parents | Graph validation + staging FK check |
| IDs / AUTO_INCREMENT | Collisions after delete/import | id_snapshot + post-import AI adjust policy |
| Warehouses / stock / FIFO | Layers for other countries damaged | Prove all stock tables filtered by country_id / warehouse ownership |
| Orders | History loss for one country only | delete/import only target country orders |
| Accounting / GL | Shared chart vs country journals | **Blocker:** GL vouchers/lines may not be cleanly country-scoped — needs accounting handoff review |
| Users / permissions | Global admins | Never delete global admins from CRP |
| Uploads | Path bleed into other countries | Path prefix allowlist + zip-slip checks |
| Replace vs merge | Staging uses delete-order then insert (replace semantics for scoped tables) | Production apply must same semantics |

### 7.3 Tables that cannot be safely country-restored without extra mapping

Until proven otherwise, treat as **unsafe / excluded** from Country production restore:

- Shared catalog trees not tagged by country  
- `journal_vouchers` / `journal_lines` / partner subledgers if not strictly country-keyed  
- Any table lacking `country_id` (or proven ownership path) but referenced by country rows  
- Session/cache tables  

**Do not claim Country restore is production-safe until a signed table-boundary matrix (schema revision 121 + registry 1.0) is attached to this design as an addendum and staging drills pass against a copy of production.**

---

## 8. File / uploads restore design

### 8.1 Formats

| Mode | Archive |
|------|---------|
| Full | `uploads.zip` |
| Country | `files/uploads_country.zip` |

### 8.2 Safety (existing patterns to mandate)

- Extract only under staging / `uploads_next` under work root or designated uploads staging — never directly into live tree first  
- Zip-slip: reject `..`, absolute paths, Windows drive prefixes  
- Symlink / reparse / junction: **blocked** (`restore_uploads_fs.php`)  
- Allowlist extensions optional; checksum tree after extract  
- Permissions: inherit IIS/Plesk app pool ACLs after cutover  

### 8.3 Options

**Option A — In-place overwrite** into live uploads: rejected (partial tree, locked files, no atomicity on Windows).  

**Option B — Extract to staging + rename cutover** (existing Full path: `uploads_next`, pre-merge snapshot, two-phase rename): **recommended.**

Windows/Plesk notes: `rename` of busy directories can fail; antivirus may lock files; design must retry with backoff, keep snapshot for rollback, and fail to `rollback_preparing` if second rename fails after first.

---

## 9. Resume and crash recovery

### 9.1 Checkpoint artifacts (per job)

- `job.json` status/phase/progress/heartbeat_at  
- `checkpoints/{stage}.json` with hashes, byte offsets, package fingerprint, anchor ids  
- Phase 2 staging manifest / merge export manifests (already exist)  
- `worker.pid` + heartbeat every 30s  

### 9.2 Crash matrix

| Stop during | Resume? | Action |
|-------------|---------|--------|
| pre-restore backup | Restart backup | Discard incomplete snapshot dir; do not pin |
| DB import (staging) | Prefer **restart** staging wipe+import | Partial gzip resume only if offset checkpoint proven; default restart |
| DB verification | Resume verify | Idempotent |
| Files extraction | Restart clean staging extract | |
| Files cutover (after first rename) | **Rollback path** | Do not “resume forward” blindly |
| Smoke tests | Resume smokes | Idempotent read-only |
| Rollback | Continue rollback | If rollback_failed → emergency runbook |

### 9.3 Idempotency

- Approval nonce one-time  
- Cutover steps gated by status machine (no double cutover)  
- Backup package ids unique by timestamp  
- Audit reconciliation on resume (compare last checkpoint vs filesystem)

### 9.4 Stale worker

If heartbeat stale and PID dead during pre-boundary states → mark failed, release locks carefully.  
If past irreversible boundary → mark `rollback_preparing` / `rollback_failed` needing operator — **no auto unlock**.

---

## 10. Rollback design

### 10.1 Sources

1. Mandatory pre-restore **Full** backup (DB + uploads) — primary  
2. Pre-merge uploads snapshot directory  
3. Staging DB (forensics; not always enough for prod rollback)  
4. Execution checkpoints / merge export artifacts  

### 10.2 Triggers

- Automatic: verification failure after production touch; smoke failure after cutover; cutover second-step failure  
- Manual: Super Admin + phrase `ROLLBACK` + re-auth + job id confirm  

### 10.3 Order

1. Ensure maintenance remains on  
2. Roll back uploads renames using snapshot (inverse of cutover)  
3. Roll back database from pre-restore Full dump into staging then cutover **or** approved emergency import path (same Option B)  
4. Verify  
5. Smoke (read-only)  
6. Leave maintenance on until operator confirms  
7. Preserve failed job artifacts forever (or long retention)

### 10.4 Rollback failure

Status `rollback_failed`; keep maintenance; surface emergency instructions; do not unlock automatically; retain all forensic packages.

---

## 11. Final approval security design (future — not implemented here)

| Control | Design |
|---------|--------|
| Permission | Super Admin + `backup_restore_full` or `backup_restore_country` matching package |
| CSRF | Fresh token on approve POST |
| Re-auth | Password re-verify (`orange_restore_verify_operator_password`) — session alone insufficient (existing policy in `restore_reauth.php`) |
| Phrase | Full: `RESTORE`; Country: `RESTORE {CC}` (existing) |
| Confirm | Exact `package_id` + `job_id` fields must match |
| Operator identity | Bound admin id + username in approval record |
| Expiry | Approval token TTL 3600s (existing); execution must start within window or re-approve |
| Anti-replay | One-time token hash on job; consume on success |
| Audit | Immutable approval event with timestamp, IP if available, binding fingerprint |
| Package freshness | Recompute fingerprint; reject `package_changed_after_dry_run` |
| Two-person | **Recommended for Full production cutover; mandatory before enabling Country production.** Approver ≠ preparer when two Super Admins exist; if only one Super Admin, require delayed second confirmation (time-gated re-auth ≥ 5 minutes) as compensating control |

Wire as future `POST admin/api/restore/job/approve-execution.php` — **out of scope for this phase.**

---

## 12. Smoke tests (read-only)

Post-restore, under maintenance, no business mutations:

1. PDO connect to production DB  
2. `schema_revision` / catalog schema revision constant match  
3. Required tables exist (orders, products, countries, warehouses, journal_vouchers, …)  
4. Country registry load + version  
5. Admin user row readable (login dependency)  
6. Sample product/catalog SELECT  
7. Sample order SELECT  
8. Stock totals query coherence (non-negative aggregates)  
9. FIFO layers count sanity (no cross-negative)  
10. GL: voucher/line counts + basic balance probe without posting  
11. Uploads critical paths readable  
12. Health endpoint / selected admin APIs GET  
13. Queue/cron flag “paused for maintenance” visible  
14. Backup subsystem: list packages still works; retention pin present  

Fail → rollback_preparing.

---

## 13. Observability and operator UI

| Element | Design |
|---------|--------|
| Live progress | phase, % , current stage label (AR) |
| Heartbeat | `heartbeat_at`, worker pid |
| ETA | from dry-run estimates + rolling average |
| Codes | stable machine codes (no secrets) |
| Logs | redacted stream; no passwords, no absolute private paths in API |
| Report | downloadable `execution_report.json` redacted |
| Buttons | Prepare/Cancel plan only until approval phase; during execution disable all except emergency rollback (post-boundary); no Execute until 3B.3B6 |
| Rollback UI | prominent red status, last checkpoint, emergency steps |

---

## 14. Risk register

| Risk | Severity | Probability | Detection | Prevention | Recovery |
|------|----------|-------------|-----------|------------|----------|
| Partial DB import | Critical | Medium | statement/EOF checks, table matrix | Staging-only import, restart on fail | Discard staging; no prod touch |
| Wrong package/country | Critical | Low | fingerprint + id confirms | Approval bindings | Abort pre-boundary |
| Stale package after approval | High | Medium | fingerprint recheck at start | Token TTL + recheck | Re-dry + re-approve |
| Insufficient disk | High | Medium | precheck bytes | Reserve 2× | Abort |
| Maintenance bypass | Critical | Low | middleware tests | Central guard | Emergency maint enable |
| Lock loss | High | Low | lock assert each stage | Fail closed | Stop; operator |
| Process termination | High | Medium | heartbeat | CLI worker + checkpoints | Resume/rollback matrix |
| Corrupted pre-restore backup | Critical | Low | verify+DRV gate | Fail closed | Abort restore |
| File cutover failure | Critical | Medium | rename result codes | Two-phase + snapshot | Rollback uploads |
| Rollback failure | Critical | Low | rollback verify | Keep maint on | Manual runbook |
| Schema incompatibility | High | Medium | revision gates | Dry-run/eligibility | Abort |
| Cross-country corruption | Critical | Medium (if Country rushed) | staging post-validation | Delay Country prod | Full anchor restore |

---

## 15. Recommended phased implementation

### 3B.3B1 — Approval gate + maintenance framework (no restore)

- **Code:** admin approve API for 3B jobs; re-auth; CSRF; nonce; maintenance enable/disable helpers; storefront/API maintenance guard skeleton  
- **Effects:** flags/files only; no SQL restore  
- **Tests:** approval security, fingerprint recheck, maint middleware unit tests  
- **Prod gate:** feature flag off by default  
- **Rollback:** disable flag; delete maint file via emergency unlock  

### 3B.3B2 — Restore Bridge Layer (owner scope; contract only)

- **Code:** `includes/backup/restore/restore_execution_bridge.php`; discovery doc `docs/backup/RESTORE_PHASE2_CLI_ENTRYPOINTS.md`; GET `admin/api/restore/job/execution-contract.php`  
- **Effects:** writes `{job}/restore_execution_contract.json` after final approval (`execution_started=false`); never invokes CLI / SQL / staging / cutover / maintenance  
- **Helpers only:** `orange_restore_prepare_execution_contract`, `orange_restore_validate_execution_contract`, `orange_restore_load_execution_contract`  
- **UI:** View Execution Contract (no Execute / Run / Resume)  
- **Tests:** contract generation + mismatch rejection in `self_test_restore_admin.php`  

### 3B.3B3 — Mandatory Pre-Restore Backup Gate (owner scope; rollback anchor only)

- **Code:** `includes/backup/restore/restore_pre_restore_backup.php`; CLI `scripts/backup/restore_prepare_backup.php --job=`; retention pins in `backup_retention.php`; APIs request + GET status  
- **Reuses:** `orange_backup_run_full`, `orange_backup_verify_full_package`, `orange_recovery_validate_package` (score ≥ 70), `orange_backup_retention_pin_package`  
- **Effects:** Full rollback anchor + pin only; stops at `pre_restore_backup_ready`; `execution_started=false`  
- **Must not:** restore DB/files, shadow cutover, maintenance enable, restore worker, contract execution, unpin API  
- **Tests:** `self_test_pre_restore_backup.php` + expansions in `self_test_restore_admin.php`  

### 3B.3B2b — (superseded naming) see 3B.3B3 above  

### 3B.3B4 — Shadow Database Restore Engine (owner scope; shadow DB only)

- **Code:** `includes/backup/restore/restore_shadow_db.php`; CLI `scripts/backup/restore_shadow_db.php --job=`; POST `request-shadow-restore.php` (metadata only); GET `shadow-restore.php` (status/report)  
- **Reuses:** staging credential fences (`restore_staging_target.php`), `orange_restore_sql_runner_import_gzip()`, approved Full `manifest.dump_file` gzip SQL  
- **Shadow name:** `ORANGE_RESTORE_SHADOW_DB` or fallback `ORANGE_RESTORE_STAGING_DB` — never equals production  
- **Effects:** create/wipe/import into shadow DB only; writes `{job}/shadow_restore.json` + `{job}/shadow_restore_report.json`; stops at `shadow_restore_ready` / `shadow_restore_failed`  
- **Verify:** schema objects (tables/views/routines/triggers/events), row counts, charset/collation; compare vs package + read-only production inventory  
- **Must not:** production writes, cutover, app config switch, file restore, maintenance enable, rollback execution, HTTP-side SQL import  
- **Prerequisite:** `pre_restore_backup_ready` + pinned rollback anchor + valid execution contract; Full only  
- **Tests:** `self_test_shadow_restore.php` + expansions in `self_test_restore_admin.php`  

### 3B.3B5 — Shadow Database Verification & Production Readiness (owner scope)

- **Code:** `includes/backup/restore/restore_shadow_verify.php`; CLI `scripts/backup/restore_shadow_verify.php --job=`; GET `shadow-verification.php` (read-only)  
- **Prerequisite:** `shadow_restore_ready` (+ pinned pre-restore anchor + valid contract); Full only  
- **Effects:** deep read-only inspect of shadow DB; writes `{job}/shadow_verification.json` + `{job}/shadow_verification_report.json`; stops at `shadow_verified` / `shadow_not_ready` (via `shadow_verifying`)  
- **Report overall:** `READY` | `WARNING` | `FAIL` + **readiness_score** (0–100)  
- **Checks:** schema revision, tables, FKs, indexes, triggers/routines/events/views, AUTO_INCREMENT, row counts, CHECKSUM where supported, charset/collation; compare vs package + read-only production schema; detect missing/extra objects, broken FK, orphans  
- **Must not:** production writes, cutover, app config switch, file restore, maintenance enable, rollback execution, HTTP-side verification run  
- **Tests:** `self_test_shadow_verify.php` + expansions in `self_test_restore_admin.php`  

### 3B.3B6 — Shadow File Restore (owner scope; isolated workspace only)

- **Code:** `includes/backup/restore/restore_shadow_files.php`; CLI `scripts/backup/restore_shadow_files.php --job=`; GET `shadow-files.php` (read-only)  
- **Prerequisite:** `shadow_verified` (+ pinned pre-restore anchor + valid contract); Full only  
- **Target:** `{framework_job}/restore_shadow_workspace/` only — never production uploads, never `uploads_next` rename  
- **Reuses:** `orange_restore_uploads_applicator_extract`, `orange_restore_uploads_tree_inventory`, symlink/reparse fences  
- **Effects:** wipe+extract package `uploads.zip` into shadow workspace; writes `{job}/shadow_files.json` + `{job}/shadow_files_report.json`; stops at `shadow_files_ready` / `shadow_files_failed`  
- **Validate:** zip-slip, absolute/drive paths, symlink entries, uploads SHA-256, file set match, tree checksum, readability/permissions report  
- **Must not:** production filesystem writes, directory rename/cutover, app config changes, maintenance enable, HTTP-side extract  
- **Tests:** `self_test_shadow_files.php` + expansions in `self_test_restore_admin.php`  

### 3B.3B7 — Shadow End-to-End Smoke Tests and Cutover Readiness (owner scope; non-destructive)

- **Code:** `includes/backup/restore/restore_shadow_smoke.php`; CLI `scripts/backup/restore_shadow_smoke.php --job=`; POST `request-shadow-smoke.php` (metadata only); GET `shadow-smoke.php` + `cutover-readiness.php`  
- **Prerequisite:** `shadow_files_ready` + prior PASS/READY reports + pinned rollback anchor + valid approval/contract; Full only  
- **Effects:** read-only smoke of Shadow DB + Shadow Files workspace; writes `{job}/shadow_smoke_report.json` + `{job}/cutover_readiness.json`; stops at cutover readiness decision  
- **States:** `shadow_smoke_pending|running|ready|warning|failed` → `cutover_readiness_ready|manual_review|blocked`  
- **Must not:** production DB/file/config writes, cutover, maintenance, rollback execution, HTTP-side smoke run; **`production_cutover_allowed` always false**  
- **Tests:** `self_test_shadow_smoke.php` + expansions in `self_test_restore_admin.php`  

### 3B.4 — Production Cutover & Rollback Design (documentation only)

- **Deliverable:** `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md` — implementation contract for DB/files cutover, PONR, rollback, crash recovery, drills, and remaining phases **3B.4A–3B.4H**  
- **Must not (this phase):** production cutover/rollback code, maintenance activation, execute/cutover/resume endpoints  

### 3B.4A — Production Import Safety Layer (documentation only)

- **Deliverable:** `docs/backup/PRODUCTION_IMPORT_SAFETY.md`  
- **Effects:** study/contract only — checkpoints, resume, partial detection, DDL/FK/transactions, failure matrix, chunk/timeout/memory, operator recovery, shadow-promotion rejection  
- **Must not:** production import, wipe, DB modification, or production import code  

### 3B.3B8 / 3B.4F — Rollback engine (implementation; see 3B.4 design)

- **Code:** automate rollback from pinned Full anchor + uploads snapshot; CLI + later admin  
- **Effects:** may touch prod **only** in controlled rollback drills on non-prod first  
- **Tests:** simulated cutover failure → rolled_back  
- **Prod gate:** drill sign-off  

### 3B.3B9 / 3B.4D–3B.4G — Production execution wiring (implementation; see 3B.4 design)

- **Code:** cutover wiring under maint; admin progress UI; heartbeat worker; import safety checkpoints per 3B.4A  
- **Effects:** real Full restore path  
- **Tests:** end-to-end on clone; then limited prod drill window  
- **Rollback:** 3B.4F  

### 3B.3B10 / 3B.4H — Disaster recovery drill (implementation; see 3B.4 design)

- **Code:** runbooks + checklist automation  
- **Effects:** scheduled drill restore on clone  
- **Tests:** timed RTO/RPO metrics  
- **Prod gate:** owner acceptance  

**Country production** starts only after a dedicated **3B.3C** series following table-boundary proof — not before Full shadow path success.

---

## 16. Final recommendation

| Question | Answer |
|----------|--------|
| Ready to implement real restore in production admin now? | **No.** Foundations exist (staging/cutover/CLI + 3B plan), but admin wiring, maintenance middleware, retention pins, unified job bridge, and Country proof are incomplete. |
| Which mode first? | **Full Disaster Restore** |
| Delay Country? | **Yes** — delay Country **production** restore until table-boundary/GL/shared-catalog proof; Country **staging drills** may continue |
| DB strategy | **Option B: staging/shadow DB + cutover** (already aligned with code) |
| File strategy | **Option B: staging extract + rename cutover** |
| Next smallest safe phase | **`3B.3B1` — approval gate + maintenance framework, no restore execution** |

### Confirmation (this phase)

- No database restore, SQL execution against production, schema replacement, uploads restore, application file replacement, atomic production swap, rollback execution, maintenance enablement in production code, approve/execute/resume endpoints were implemented in this design phase.  
- Deliverable is this document only.

---

## Appendix A — Key file index

- `includes/backup/backup_full.php`, `backup_runner.php`, `backup_validate.php`, `backup_retention.php`, `backup_admin.php`  
- `includes/backup/country_export.php`, `country_batch_export.php`  
- `includes/backup/recovery_validation.php`  
- `includes/backup/restore_admin.php`  
- `includes/backup/restore/restore_job_framework.php`, `restore_dry_run.php`, `restore_execution_orchestrator.php`, `restore_final_approval.php`, `restore_execution_bridge.php`  
- `docs/backup/RESTORE_PHASE2_CLI_ENTRYPOINTS.md`  

- `includes/backup/restore/restore_job.php`, `restore_lock.php`, `restore_merge_maintenance.php`  
- `includes/backup/restore/restore_fresh_backup_gate.php`, `restore_full_staging.php`, `restore_country_staging.php`  
- `includes/backup/restore/restore_sql_runner.php`, `restore_sql_safety.php`  
- `includes/backup/restore/restore_merge_db_cutover.php`, `restore_merge_uploads_cutover.php`, `restore_merge_rollback.php`  
- `includes/backup/restore/restore_approval.php`, `restore_reauth.php`, `restore_e2e_orchestrator.php`  

## Appendix B — Hosting constraints

- Windows + Plesk + MariaDB (TMD)  
- Long work **must** be CLI (not IIS request)  
- Staging DB user must differ from production DB user (enforced in `restore_staging_target.php`)  
- Work/backup roots outside web root  

---

*End of Phase 3B.3B-DESIGN.*
