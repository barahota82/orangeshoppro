# Orange Backup & Recovery Runbook

**Document type:** Engineering archive — operational runbook  
**Scope:** PR-BAK-01, PR-BAK-02 — backup and pre-migration snapshot foundation  
**Implementation:** `scripts/backup/run_full_backup.php` (primary — Plesk Scheduled Tasks) and `scripts/backup/orange_backup.ps1` (optional — RDP/command line)  
**Phase 1A (current):** Full disaster backup only — database + uploads + manifest + checksums + health report

---

## Plesk scheduled backup (primary)

| Task | Setting | Value |
|------|---------|-------|
| **Full Disaster Backup** | Task type | **Run a PHP script** |
| | Script path | `scripts/backup/run_full_backup.php` |
| | Schedule | Daily at **03:00 UTC** (off-peak) |
| | Notification | **Errors only** |
| **Automatic Country Packages (1B.3)** | Task type | **Run a PHP script** |
| | Script path | `scripts/backup/export_all_recoverable_countries.php` |
| | Schedule | Daily **after** full backup (e.g. **03:30 UTC**) |
| | Notification | **Errors only** |

The country batch task discovers eligible countries on **every run** — no Scheduled Task edit when countries are added or activated.

Pre-flight (read-only):

```powershell
php D:\orange\scripts\backup\backup_environment_check.php
```

The PHP entry point handles locking, backend selection, logging under `{BackupRoot}/logs/`, and delegates to PowerShell only when explicitly executable; otherwise it uses **PHP + mysqldump via `proc_open`**.

**Required server-only `.env.php` keys (Plesk example):**

```php
'ORANGE_BACKUP_ROOT' => 'C:\\inetpub\\vhosts\\clickstorekw.com\\private\\orange_backups',
'ORANGE_MYSQLDUMP_PATH' => 'C:\\Program Files (x86)\\Plesk\\MySQL\\bin\\mysqldump.exe',
// optional — retention window in days (default 30 when key is missing):
// 'ORANGE_BACKUP_RETENTION_DAYS' => 30,
```

Create the BackupRoot folder outside `httpdocs`, grant write permission to the scheduled-task PHP user, and verify `proc_open` is enabled. If `proc_open` is disabled, the scheduled task **must fail** — there is no PDO fallback in Phase 1A.

---

## Backup policy

Orange production data consists of:

1. **MariaDB/MySQL database** — orders, catalog, stock, GL, accounts, configuration rows, schema metadata.
2. **`uploads/` directory** — product images, payment proofs, attachments, and other user-uploaded files under the web root.

Both must be backed up together. A database-only backup without `uploads/` is **incomplete** for disaster recovery and pre-deploy rollback planning.

Approved backup entry points:

- **Primary:** `scripts/backup/run_full_backup.php` (Plesk Scheduled Tasks)
- **Optional:** `scripts/backup/orange_backup.ps1` (operators with RDP/command access)

Backup storage: **`ORANGE_BACKUP_ROOT`** in server-only `.env.php` (**mandatory on Plesk**). Also set **`ORANGE_MYSQLDUMP_PATH`** to the host `mysqldump.exe` path. Backups must **not** live inside the Git repository or public web root.

---

## Daily backup requirement

A **daily automated backup** is required for any production or production-like environment.

- Schedule via **Plesk Scheduled Tasks** → Run a PHP script → `scripts/backup/run_full_backup.php` (see above).
- Alternative: **Windows Task Scheduler** with `orange_backup.ps1` when RDP/command access is available (`scripts/backup/README.md`).
- Each run must produce a timestamped snapshot under `{BackupRoot}/snapshots/` with:
  - compressed database dump (`{db_name}.sql.gz`),
  - `uploads.zip`,
  - `manifest.json`,
  - `checksums.sha256`,
  - `health.json`,
  - log entry under `{BackupRoot}/logs/`.
- Failed runs must be investigated before the next deploy or migration. The script exits non-zero on failure and does not delete existing snapshots when a new backup fails.

Retention (**30-day policy — Phase 1B.3**, shared helper `includes/backup/backup_retention.php`):

| Key (`.env.php`) | Default |
|------------------|---------|
| `ORANGE_BACKUP_RETENTION_DAYS` | **30** |

Applies independently to:

- `{BackupRoot}/snapshots/{timestamp}/` (full disaster backup — after finalize + verify)
- `{BackupRoot}/country_packages/{country_code}/{timestamp}/` (CRP — after batch exports + verify)

Rules: delete only finalized packages **older than** the retention window; never delete temp/work dirs; never delete the newest **verified healthy** package (global for full, per country for CRP); preserve the last verified healthy package when no newer healthy replacement exists; block paths outside BackupRoot/symlinks; log kept/deleted with reason.

Retention cleanup runs **only after** successful finalize + verify and **only inside** `ORANGE_BACKUP_ROOT`.

---

## Pre-deploy / pre-migration snapshot requirement

**Before every production `git pull`** (or any change that may trigger runtime schema migrations in `includes/catalog_schema.php`):

1. Run `orange_backup.ps1` manually and confirm exit code `0`.
2. Verify the new snapshot folder and `manifest.json` (see README verification steps).
3. Run `verify_full_backup.php` on the snapshot path.
4. Record the snapshot folder name and optional `git_commit` from the manifest.
5. Only then deploy code (`git pull`).

This snapshot is the **mandatory pre-migration rollback point** (PR-BAK-02). Forward-only PHP migrations have no automated down-migration; recovery depends on restoring this snapshot.

If migration fails or the site enters degraded schema state, restore from this snapshot before retrying.

---

## Uploads backup requirement

The `uploads/` tree is part of production state. Payment proofs, CRM attachments, and catalog media are **not** fully represented in the SQL dump alone.

Every backup run must include `uploads.zip` in the snapshot. Missing or empty uploads archives invalidate the backup for go-live and disaster-recovery purposes.

---

## Full disaster package (Phase 1A)

```text
{BackupRoot}/snapshots/yyyy-MM-dd_HHmmss/
  {db_name}.sql.gz
  uploads.zip
  manifest.json
  checksums.sha256
  health.json
```

### Manifest (`manifest.json`)

`package_type=full_disaster`. Includes schema revision, git commit, database/host identifiers (no credentials), dump and uploads filenames, SHA-256 checksums, sizes, table/row counts, `backup_status`, and references to `health.json` and `checksums.sha256`.

### Health report (`health.json`)

Reports validation steps and `package_status` (`healthy`, `warning`, `failed`). Package finalization **fails closed** when required files are missing or checksum verification fails.

### Checksum verification

```powershell
php D:\orange\scripts\backup\verify_full_backup.php --package=D:\orange_backups\snapshots\yyyy-MM-dd_HHmmss
```

Read-only — does not modify the package.

### BackupRoot safety

`ORANGE_BACKUP_ROOT` must be outside the Orange project root and `uploads/`, must not use path traversal or public web-root segments (`httpdocs`, `public_html`, `wwwroot`), and must be writable. Validated by `includes/backup/backup_paths.php` and `resolve_backup_root.php`.

### Off-host copy (recommended)

Copy snapshots to storage separate from the production host (second disk, NAS, cloud). Same-server-only backups do not protect against full host loss.

---

## Restore procedure (manual — not automated)

**The backup script does not restore automatically.** Until Phase 2 Restore is implemented per **`ORANGE_RESTORE_OWNER_POLICY.txt`**, restore remains a **manual, operator-driven** procedure on non-production or emergency basis only:

1. **Stop web traffic** to the site (maintenance mode / stop app pool) before overwriting live data.
2. **Database:** decompress `{db_name}.sql.gz`, then import into MariaDB/MySQL using the appropriate client (`mysql` CLI or Plesk database tools). Target the database name recorded in `manifest.json` (default from `config.php`: `orange_db`).
3. **Uploads:** extract `uploads.zip` into the project `uploads/` directory, preserving relative paths. Merge or replace only after confirming the snapshot is the intended point-in-time.
4. **Verify:** run gated `health.php` checks, admin login, sample order/stock read, and spot-check uploaded files referenced by recent orders.
5. **Document:** note snapshot timestamp, manifest `git_commit`, and reason for restore.

Restore must be **tested on a non-production clone** before relying on it for production go-live. An untested backup policy does not satisfy production readiness.

**Phase 1A does not implement:** staging restore, production merge, restore APIs, or admin backup UI.

**Permanent owner policy:** All future Restore work is governed by **`docs/archive/ORANGE_RESTORE_OWNER_POLICY.txt`** (owner decision 2026-07-13). No Restore implementation may begin until that policy is acknowledged.

---

## Orange Restore Policy (Permanent Owner Decision — 2026-07-13)

**Status:** Archived owner policy — **not implemented in code**.  
**Full text:** `docs/archive/ORANGE_RESTORE_OWNER_POLICY.txt`

| # | Rule | Summary |
|---|------|---------|
| 1 | No automatic restore | No Scheduled Task, Cron, background worker, or unattended restore |
| 2 | No public/automatic exposure | No public endpoint, webhook, URL trigger, auto command, or normal admin permission |
| 3 | Super Admin + `backup_restore` | Dedicated permission; only Super Admin may execute restore |
| 4 | Re-authentication | Current session insufficient; operator must authenticate again before restore |
| 5 | High-friction confirmation | Type exactly `RESTORE` or `RESTORE KUWAIT` — no one-click restore |
| 6 | Mandatory workflow | Validate → fresh full backup → **staging** → DRV → owner review → approval → production merge. **Direct production restore forbidden** |
| 7 | Separate operations | Country Restore and Full Restore: separate permissions, workflows, audit trails |
| 8 | Permanent audit | Operator, timestamp, package, checksum, version, schema revision, scope, staging target, merge approval, duration, result — **never editable** |
| 9 | No silent production deletion | Every destructive action explicit and logged |
| 10 | Implementation gate | No Restore code until this policy is archived and acknowledged (**satisfied by archive file above**) |

**Phase 2 Restore implementation** must conform to every rule above before any merge to production paths.

---

## Database engine policy

**MariaDB/MySQL remains the approved database engine** for Orange Shop Pro.

- Backups use **`mysqldump`** against the existing MariaDB/MySQL instance.
- **Do not migrate Orange to Microsoft SQL Server** as part of backup/recovery work.
- Recovery always returns to MariaDB/MySQL on the same engine family used at backup time.

---

## Phase 1B.1 — Country Backup Inventory (implemented)

| Item | Status |
|------|--------|
| Table registry (`config/backup_table_registry.json`) | **Implemented** |
| Registry validation CLI | **Implemented** — `scripts/backup/validate_registry.php` |

---

## Phase 1B.2 — Country Recovery Package export (implemented)

| Item | Status |
|------|--------|
| CRP export CLI (`export_country.php`) | **Implemented** |
| CRP verify CLI (`verify_country_package.php`) | **Implemented** |
| Country-scoped SQL + uploads collector | **Implemented** |
| Staging restore / production merge | **Not implemented — Phase 2** (gated by `ORANGE_RESTORE_OWNER_POLICY.txt`) |
| Admin backup module | **Not implemented — Phase 3** |

**Never import a CRP directly into production.** Packages are for offline review and future staging restore only.

---

## Phase 1B.3 — Automatic Country Package batch (implemented)

| Item | Status |
|------|--------|
| Batch CLI (`export_all_recoverable_countries.php`) | **Implemented** |
| Dynamic country discovery | **Implemented** — active OR inactive with historical data |
| Per-country retention | **Implemented** — configurable via `.env.php` |
| Staging restore / production merge | **Not implemented — Phase 2** (gated by `ORANGE_RESTORE_OWNER_POLICY.txt`) |
| Admin backup module | **Not implemented — Phase 3** |

**Discovery rule (every run, no hardcoded country IDs):**

1. Read all rows from `countries`.
2. **Export** when `is_active=1`, **or** when inactive but any country-scoped header table has rows for that `country_id`: `customers`, `suppliers`, `purchases`, `purchase_returns`, `orders`, `sales_returns`, `journal_vouchers`, `stock_movements`, `inventory_cost_layers`, or FIFO consumptions linked via `inventory_cost_consumptions`.
3. **Skip** inactive countries with no rows in those tables (empty/template).

**Failure policy:** one country failure does not finalize a partial package; batch continues; exit code non-zero; log lists succeeded and failed countries.

**Retention:** shared 30-day policy via `ORANGE_BACKUP_RETENTION_DAYS` (default 30); per `{BackupRoot}/country_packages/{country_code}/`; newest **verified healthy** package never deleted.

```powershell
php D:\orange\scripts\backup\export_all_recoverable_countries.php
php D:\orange\scripts\backup\self_test_country_batch_export.php
php D:\orange\scripts\backup\self_test_backup_retention.php
```

---

## Phase 1C — Disaster Recovery Validation (implemented)

| Item | Status |
|------|--------|
| DRV CLI (`validate_backup_recoverability.php`) | **Implemented** |
| Full + CRP package support | **Implemented** |
| `recovery_validation.json` report | **Implemented** (sibling file beside package) |
| Restore / merge / admin UI | **Not implemented — Phase 2+** (gated by `ORANGE_RESTORE_OWNER_POLICY.txt`) |

```powershell
php D:\orange\scripts\backup\validate_backup_recoverability.php --package=D:\orange_backups\snapshots\yyyy-MM-dd_HHmmss
php D:\orange\scripts\backup\validate_backup_recoverability.php --package=D:\orange_backups\country_packages\kw\yyyy-MM-dd_HHmmss
php D:\orange\scripts\backup\self_test_recovery_validation.php
```

**Recovery score:** 100 perfect · 90–99 informational · 70–89 recoverable with warnings · below 70 fail.

**Pass/Fail:** exit code `0` when score ≥ 70; exit code `1` when score < 70. Validation is **read-only** — no database, uploads, package, or filesystem changes inside the validated snapshot.

---

## Phase 2A — Restore foundation (implemented — schema-free)

| Item | Status |
|------|--------|
| Restore job store (filesystem JSON) | **Implemented** |
| Restore global lock | **Implemented** |
| Filesystem audit (`audit.jsonl` per job) | **Implemented** — no DB table yet |
| Approval + re-auth contracts | **Implemented** (no execution) |
| Permissions `backup_restore_full` / `backup_restore_country` | **Implemented** (Super Admin + dedicated permission) |
| Staging restore (full → staging) | **Implemented — Phase 2B.1 CLI only** |
| Staging restore (country → staging) | **Implemented — Phase 2B.2 CLI only** |
| Production merge | **Not implemented — Phase 2D** |
| Country restore | **Staging only — Phase 2B.2; merge Phase 2D.2** |
| Admin restore UI | **Not implemented — Phase 3** |
| DB table `restore_audit_log` | **Deferred** — first phase with actual restore ops |

**CLI-first (permanent):** All restore business logic must live in CLI + `includes/backup/restore/*`. Future Admin UI wraps CLI only — no duplicate restore logic in admin PHP.

**Rollback (permanent):** Stage 3 fresh full backup recorded **inside the current restore job** is the **only** automatic production rollback anchor. Older backups never become rollback anchors automatically.

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt`

```powershell
php D:\orange\scripts\backup\self_test_restore_foundation.php
php D:\orange\scripts\backup\self_test_restore_full_staging.php
```

---

## Phase 2B.1 — Full Disaster Restore → STAGING (CLI only)

**Scope:** Restore a verified full disaster package into an **isolated staging database** and staging uploads under the restore work directory. **No production writes. No merge. No country restore. No Admin UI.**

**Prerequisites (`.env.php` on server):**

| Key | Required | Notes |
|-----|----------|-------|
| `ORANGE_BACKUP_ROOT` | Yes | Package must live under this root |
| `ORANGE_RESTORE_STAGING_DB` | Yes | Staging MySQL database name; **must not** equal production `DB_NAME` |
| `ORANGE_RESTORE_STAGING_DB_USER` | Yes | Dedicated staging MySQL user; **must not** equal production `DB_USER` |
| `ORANGE_RESTORE_STAGING_DB_PASS` | Yes | Staging MySQL password |
| `ORANGE_RESTORE_WORK_DIR` | No | Default `{ORANGE_BACKUP_ROOT}/restore_work` |

Create the staging database and staging-only MySQL user before first restore. Example grants (apply manually on the server):

```sql
CREATE DATABASE orange_restore_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'orange_restore_staging'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON orange_restore_staging.* TO 'orange_restore_staging'@'localhost';
FLUSH PRIVILEGES;
```

The staging user must have **zero** privileges on the production database.

**Supported package backends (Phase 2B.1):** `manifest.export_backend=php_pdo` only. Packages from mysqldump/PowerShell (`php_mysqldump`, `powershell`, …) or SQL containing `DELIMITER` / `USE` / routine blocks are **rejected before staging mutation**.

**Workflow:**

1. Operator selects package path under `ORANGE_BACKUP_ROOT`.
2. CLI runs **package verify** + **Phase 1C DRV** — abort on failure.
3. CLI verifies **php_pdo** compatibility and scans SQL for forbidden patterns — abort before staging mutation if unsupported.
4. CLI creates a **fresh full disaster backup** (Stage 3, mandatory) and records it on the job as the **rollback anchor** — abort if backup or verify fails. **No bypass flag.**
5. CLI confirms staging target (dedicated credentials, `SELECT DATABASE()`, privilege fence) and records it in audit.
6. CLI wipes **staging DB only**, streams `orange_db.sql.gz` import (validated statements + session fence), extracts uploads to `{restore_work}/{job_id}/staging_uploads`.
7. CLI runs **staging post-validation** and writes `staging_restore_manifest.json` + `restore_report.json`.
8. Job status becomes `awaiting_owner_approval` — merge is **not** part of 2B.1.

**Command:**

```powershell
php D:\orange\scripts\backup\restore_full_to_staging.php --package=D:\orange_backups\snapshots\yyyy-MM-dd_HHmmss
```

**Failure policy:** Production DB and production uploads remain untouched (staging-only credentials + SQL validator rejects database-switch statements + `SELECT DATABASE()` checks). On failure after staging mutation, job is marked `failed` with `staging_dirty=true` and exact `stage_failed`. Rollback anchor from Stage 3 is preserved when recorded. No automatic retry. Stale restore locks (6h TTL or dead PID) may be cleared safely; active locks are never removed.

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_full_staging.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2B.1 section)

---

## Phase 2B.2 — Country Recovery Restore → STAGING (CLI only)

**Scope:** Restore a verified Country Recovery Package (CRP) into an **isolated staging database** and staging uploads under the restore work directory. **No production writes. No merge. No Admin UI.**

**Package path:** `{ORANGE_BACKUP_ROOT}/country_packages/{country_code}/{timestamp}`

**Prerequisites:** Same staging keys as Phase 2B.1 (`ORANGE_RESTORE_STAGING_DB`, `ORANGE_RESTORE_STAGING_DB_USER`, `ORANGE_RESTORE_STAGING_DB_PASS`).

**Workflow:**

1. Operator selects CRP path under `country_packages/{country_code}/{timestamp}`.
2. CLI runs **CRP package verify** + **Phase 1C DRV** — abort unless `overall_result=pass`.
3. CLI validates live **registry version** matches package and **dependency_graph.json** matches registry edges.
4. CLI creates a **fresh full disaster backup** (Stage 3, mandatory) and records rollback anchor on the job — **no bypass flag.**
5. CLI confirms staging target (dedicated credentials, privilege fence).
6. CLI clears **only CRP tables** (registry `delete_order`) in staging, imports `sql/*.sql` chunks in **restore_order**, extracts `files/uploads_country.zip` to `{restore_work}/{job_id}/staging_uploads`.
7. CLI runs **country staging post-validation** (ID preservation + row counts vs `id_snapshot.json` / `table_inventory.json`).
8. Job status becomes `awaiting_owner_approval` — **production merge is not part of 2B.2.**

**Command:**

```powershell
php D:\orange\scripts\backup\restore_country_to_staging.php --package=D:\orange_backups\country_packages\kw\yyyy-MM-dd_HHmmss
```

**Failure policy:** Production unchanged. `staging_dirty=true` after staging mutation. Rollback anchor preserved when Stage 3 succeeded. No automatic retry.

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_country_staging.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2B.2 section)

---

## Phase 2C — Owner Approval + Staging Validation Gate (CLI only)

**Scope:** After a successful 2B.1 or 2B.2 staging restore, a Super Admin with the correct restore permission may **approve**, **reject**, or **cancel** the job. Approval moves the job to `approved_for_merge` only — **no production writes, no merge execution, no Admin UI.**

**Prerequisites:** Job status must be `awaiting_owner_approval` with:
- `restore_report.json` showing `overall_result=pass` and `production_touched=false`
- Staging post-validation passed (full or country path)
- `staging_restore_manifest.json` present
- Stage 3 rollback anchor on job (`fresh_backup_path` + `fresh_backup_checksum`)
- Owner approval window open (default 7 days from `owner_approval_window_started_at`)

**Approval gates (all required):**
1. Super Admin session operator (`--admin-id`)
2. Dedicated permission: `backup_restore_full` (full disaster) or `backup_restore_country` (country recovery)
3. Password re-authentication (`--password`)
4. Exact confirmation phrase: `RESTORE` (full) or `RESTORE {COUNTRY_CODE}` (country)
5. Live source package checksum matches job record
6. Staging validation gate pass (report + manifest checksums bound into approval token)
7. Rollback anchor present
8. Job not expired, failed, cancelled, or already approved

**Commands (P0-2 — Phase-2 approve CLI permanently disabled):**

```powershell
# Read-only status (JSON report — no secrets)
php D:\orange\scripts\backup\restore_job_status.php --job=yyyy-MM-dd_HHmmss_xxxxxxxx

# LEGACY: restore_approve_merge.php is a DISABLED tombstone (legacy_restore_entrypoint_disabled).
# Do not run Phase-2 approve/cutover CLIs. Use Restore Center final approval + approved 3B.4 workers:
#   restore_import_production.php / restore_uploads_cutover.php / restore_rollback.php / restore_finalize.php
#   (--job=JOB_ID only; argv credentials prohibited)
```

**On success:** Job status becomes `approved_for_merge`. One-time approval token is issued and consumed in the same operation (hash stored on job + sidecar metadata). Filesystem audit records re-auth, phrase check, token lifecycle, and state transition.

**Failure policy:** Any gate failure aborts with no state change (except audit of failed re-auth/phrase/permission). Rejection/cancellation sets status `cancelled` with reason fields. No automatic retry. Active approval tokens invalidated on job mutation, reject, or cancel.

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_approval.php
php D:\orange\scripts\backup\self_test_restore_merge_foundation.php
php D:\orange\scripts\backup\self_test_restore_database_cutover.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2C section)

---

## Phase 2D.1 — Merge Foundation (IMPLEMENTED — precheck + maintenance + production identity)

**Status:** Implemented (2026-07-13). Engine `2D.1-foundation`. Schema revision **121** unchanged.

**Scope:** Merge foundation only — **no production DB writes, no SQL import, no uploads changes,
no merge cutover.** Callable from orchestrator; no dedicated merge CLI in this phase.

**Modules:** `restore_merge_precheck.php`, `restore_merge_maintenance.php`,
`restore_production_target.php`; extensions to `restore_orchestrator.php`, `restore_job.php`,
`restore_lock.php`, `restore_audit.php`, `restore_paths.php`.

**Precheck gates (fail closed, read-only):** `approved_for_merge`; approval window valid; token
consumed; binding checksums unchanged; live package + rollback anchor + staging manifest checksums;
staging validation pass; production ≠ staging DB names; merge credentials present and fenced;
production identity (read-only PDO); staging identity; global restore lock held by current job;
maintenance not already active. Success → job state `merge_precheck_passed` only.

**Maintenance service:** `{restore_work}/.maintenance.json` — enable / disable / status / verify.
Orchestrator wrappers record audit events. No merge, no DB writes, no uploads changes.

**Audit (append-only):** `merge_precheck_started`, `merge_precheck_passed`, `merge_precheck_failed`,
`maintenance_enabled`, `maintenance_disabled`.

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_merge_foundation.php
php D:\orange\scripts\backup\self_test_restore_database_cutover.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2D.1 foundation section)

---

## Phase 2D.2 — Production Database Cutover (IMPLEMENTED — DB only)

**Status:** Implemented (2026-07-13). Engine `2D.2-db-cutover`. Schema revision **121** unchanged.

**Scope:** Production **database** cutover only from job state `merge_precheck_passed`.
**No uploads cutover, no rollback execution, no post-validation.**

**Prerequisites (operator):**
1. Job in `merge_precheck_passed` (Phase 2D.1 foundation precheck completed)
2. Global restore lock acquired by the cutover CLI process for this job
3. Maintenance mode enabled and owned by this job (`orange_restore_orchestrator_merge_maintenance_enable`)

**CLI (P0-2 — permanently disabled tombstone):**

```text
LEGACY_RESTORE_ENTRYPOINT: DISABLED
REASON: legacy_restore_entrypoint_disabled
USE: approved_3b_restore_workflow
Approved worker: php scripts/backup/restore_import_production.php --job=JOB_ID
(argv credentials prohibited; no --password= / --db-password=)
```

**Historical pipeline (library only; not a runnable CLI path):** cutover gate revalidation → merge-time re-auth → staging export to `merge_db_export.sql.gz` (verified) → `merge_started` → production schema wipe (merge credentials) → stream import → `database_cutover_complete`

**Failure policy:**
- Before production wipe: production unchanged; job stays `merge_precheck_passed`
- During/after wipe: job → `failed_merge`; maintenance remains active; rollback anchor preserved; no automatic rollback/retry; uploads untouched

**Audit (append-only):** `staging_export_started`, `staging_export_completed`, `database_cutover_started`, `database_cutover_complete`, `database_cutover_failed`

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_database_cutover.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2D.2 section)

---

## Phase 2D.3 — Production Uploads Cutover (IMPLEMENTED — uploads only)

**Status:** Implemented (2026-07-13). Engine `2D.3-uploads-cutover`. Schema revision **121** unchanged.

**Scope:** Production **uploads** cutover only from job state `database_cutover_complete`.
**No production DB writes, no rollback execution, no post-validation.**

**Prerequisites (operator):**
1. Job in `database_cutover_complete` (Phase 2D.2 database cutover completed)
2. Global restore lock acquired by the cutover CLI process for this job
3. Maintenance mode enabled and owned by this job
4. `uploads_next/` prepared at project root, verified (`uploads_next_manifest.json` in job dir, `verified=true`, tree checksum matches staging uploads)

**CLI (P0-2 — permanently disabled tombstone):**

```text
LEGACY_RESTORE_ENTRYPOINT: DISABLED
REASON: legacy_restore_entrypoint_disabled
USE: approved_3b_restore_workflow
Approved worker: php scripts/backup/restore_uploads_cutover.php --job=JOB_ID
(argv credentials prohibited)
```

**Security (3B.4 workers):** job-scoped CLI identity + maintenance active + execution contract + scoped worker token — never argv passwords.

**Pipeline:** pre-cutover gate revalidation → same-volume check → pre-merge uploads snapshot (checksum manifest) → verify snapshot → atomic directory renames (`uploads` → `uploads_pre_merge_{job}` ; `uploads_next` → `uploads`) → `uploads_cutover_complete`

**Failure policy:**
- Before any rename: production uploads unchanged; job stays `database_cutover_complete`
- After first rename succeeds but second fails: job → `failed_merge`; maintenance remains active; pre-merge snapshot preserved; no automatic rollback/retry; production DB untouched

**Audit (append-only):** `uploads_snapshot_started`, `uploads_snapshot_completed`, `uploads_cutover_started`, `uploads_cutover_complete`, `uploads_cutover_failed`

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_uploads_cutover.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2D.3 section)

---

## Phase 2D.4 — Production Post-Validation + Manual Rollback (IMPLEMENTED)

**Status:** Implemented (2026-07-13). Engine `2D.4-post-validation-rollback`. Schema revision **121** unchanged.
**Post-validation and explicit manual rollback only** — no Country Production Merge, no end-to-end wrapper,
no Admin UI, no restore APIs, no automatic/scheduled restore.

### Post-validation CLI (P0-2 — DISABLED tombstone)

```text
LEGACY_RESTORE_ENTRYPOINT: DISABLED — restore_full_post_validate.php
REASON: legacy_restore_entrypoint_disabled
USE: approved_3b_restore_workflow
```

### Post-validation finalize CLI (P0-2 — DISABLED tombstone)

```text
LEGACY_RESTORE_ENTRYPOINT: DISABLED — restore_full_post_validate_finalize.php
REASON: legacy_restore_entrypoint_disabled
Approved worker: php scripts/backup/restore_finalize.php --job=JOB_ID
```

**Entry:** `post_validation_passed`, `maintenance_disable_pending`, `maintenance_disabled`, or `completed` (artifact reconciliation only).

**Finalize checkpoints:** `post_validation_passed` → `maintenance_disable_pending` → disable maintenance → `maintenance_disabled` → `completed`.

**Entry:** `uploads_cutover_complete` only. Requires global restore lock + maintenance active and owned by job.

**State path:** `uploads_cutover_complete` → `production_merged` → `post_validation_passed` → `maintenance_disable_pending` → `maintenance_disabled` → `completed`.
Hard failure → `failed_post_merge` (maintenance stays active). No direct jump to `completed`.

**Reports:** `production_post_validation.json`, `final_restore_report.json` under `{restore_work}/{job_id}/`.

### Manual rollback CLI (P0-2 — DISABLED tombstone)

```text
LEGACY_RESTORE_ENTRYPOINT: DISABLED — restore_full_rollback.php
REASON: legacy_restore_entrypoint_disabled
Approved worker: php scripts/backup/restore_rollback.php --job=JOB_ID
(argv credentials prohibited)
```

**Policy (3B.4):** job-scoped rollback worker only; maintenance + contract + rollback anchor gates. **Only** this job's
Stage-3 fresh full disaster backup (`fresh_backup_path` + `fresh_backup_checksum` on job). No older backup selection.

**Eligible states:** `failed_merge`, `failed_post_merge`, `database_cutover_complete`, uploads partial states,
`uploads_cutover_complete`, `production_merged`, `rollback_in_progress` (resume). **Not** `completed` or `rolled_back`.

**Uploads rollback order:** (A) `uploads_pre_merge_{job_id}` rename reversal, or (B) anchor uploads zip verified
against `pre_merge_uploads_snapshot` when pre-merge rename unavailable.

**Checkpoints:** `rollback_precheck_passed` → `rollback_database_*` → `rollback_uploads_*` → `rollback_validation_passed` → `rolled_back`.
On failure → `rollback_failed`; maintenance remains active.

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_post_validation_rollback.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2D.4 section)

---

## Phase 2E — Full Restore End-to-End Orchestrator (IMPLEMENTED)

**Status:** CLI-first guided orchestrator coordinating approved phases with manual stop points. **No** Country
Production Merge, Admin UI, restore APIs, automatic/scheduled restore, or schema changes.

**P0-2 (2026-07-18):** Phase-2 E2E start/resume CLIs are **permanently disabled tombstones**
(`legacy_restore_entrypoint_disabled`). Do **not** run `restore_run_full.php` / `restore_resume_full.php`.
Production mutations use **only** the approved 3B.4 workers (`--job=` only; no argv credentials).

**Read-only status (still allowed):**

```powershell
php D:\orange\scripts\backup\restore_status_full.php --job=JOB_ID
```

**Approved production mutation workers:**
- `scripts/backup/restore_import_production.php --job=JOB_ID`
- `scripts/backup/restore_uploads_cutover.php --job=JOB_ID`
- `scripts/backup/restore_rollback.php --job=JOB_ID`
- `scripts/backup/restore_finalize.php --job=JOB_ID`

**Manual gates:** Restore Center final approval + 3B.4 execution contract / maintenance framework.
Phase-2 merge approve / cutover / rollback CLIs are DISABLED tombstones (not runnable).

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_e2e.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2E section)

---

## Phase 2D.1 — Remaining Full Production Merge (Country merge — NOT IMPLEMENTED)

**Status:** Database cutover (Phase 2D.2), uploads cutover (Phase 2D.3), post-validation + manual rollback
(Phase 2D.4), and Full Restore end-to-end orchestrator (Phase 2E) are **implemented**. **Country Production Merge remains not implemented.**

**Scope:** Remaining full-disaster merge steps after `uploads_cutover_complete`.

**Selected strategies (approved):**
- **Database:** Validated staging export → controlled production replace (export staging DB to job artifact, then wipe + stream-import into production with 2B.1 SQL safety)
- **Uploads:** Staged full-tree directory swap (`uploads_next` → rename → `uploads`) after pre-merge snapshot

**Permanent owner decisions (2026-07-13):**

### Production Merge Credentials

Production Merge must **never** use `DB_USER` / `DB_PASS`. Required server-only keys:

| Key | Required | Notes |
|-----|----------|-------|
| `ORANGE_RESTORE_MERGE_DB_USER` | Yes | Must differ from `DB_USER` and `ORANGE_RESTORE_STAGING_DB_USER` |
| `ORANGE_RESTORE_MERGE_DB_PASS` | Yes | Dedicated merge password |

- Production schema (`DB_NAME`) only; minimum merge privileges; never used by the application
- Documented in `.env.example.php`, architecture archive, and this runbook
- **Fail closed:** missing/empty/duplicate user → merge aborts before any production write

Example grants (operator applies on server — adjust to minimum required):

```sql
CREATE USER 'orange_restore_merge'@'localhost' IDENTIFIED BY 'strong_merge_password';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, LOCK TABLES
  ON orange_db.* TO 'orange_restore_merge'@'localhost';
FLUSH PRIVILEGES;
```

### Uploads cutover volume safety

Before production uploads cutover, merge **must** verify `uploads/` and `uploads_next/` are on the **same filesystem / volume** (atomic directory rename).

- **Not same volume → abort merge fail-closed** before any production uploads change
- **No fallback:** recursive copy, merge, move, copy-then-delete, or any non-atomic method

**Ordered pipeline (summary):** precheck → lock → maintenance → export staging → uploads snapshot → build `uploads_next` → **same-volume check** → DB cutover → uploads swap → post-validation → completed

**Rollback:** This job's Stage-3 fresh full disaster backup only (+ pre-merge uploads snapshot).

Architecture detail: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2D.1 section)

---

## Deferred — Phase 2D+ and Phase 3

The following are **not part of Phase 1A / 1B** and must not be assumed available:

| Item | Target phase |
|------|----------------|
| Production merge foundation (precheck + maintenance + identity) | **Phase 2D.1 foundation implemented** |
| Production database cutover (DB only) | **Phase 2D.2 implemented** |
| Production uploads cutover (uploads only) | **Phase 2D.3 implemented** |
| Production merge post-validation + manual rollback | **Phase 2D.4 implemented** |
| Full Restore end-to-end orchestrator (2E) | **Phase 2E implemented** |
| Country Production Merge | Phase 2D+ — **not implemented** |
| Full production merge credentials (`ORANGE_RESTORE_MERGE_DB_*`) | **Owner policy §11 — required (foundation + cutover)** |
| Uploads same-volume gate | **Owner policy §12 — required when 2D.1 implemented** |
| Owner approval gate (`approved_for_merge`) | **Phase 2C implemented** |
| Admin backup/restore UI wrapper | **Phase 3A Backup Center implemented** (restore UI deferred) |
| Dedicated permissions `backup_restore_full` / `backup_restore_country` | **Phase 2A implemented** |
| Restore audit DB table | First mutating restore phase (not 2A) |

---

## Phase 3A — Admin Backup Center

**Scope:** Secure admin wrapper around the **already-approved Backup Engine** (Phase 1A–1C). No restore/rollback UI, no production restore buttons, no package delete/download in this phase.

### Page

- `admin/index.php?page=backup_center` → `admin/pages/backup_center.php` (**single screen** — no separate Full/Country admin pages)
- **Menu:** الإعدادات → الأسواق (مشرف عام) → **إدارة النسخ الاحتياطي** (immediately below **المستخدمون والصلاحيات**); mirrored in `admin/partials/header.php` (`$navSettings`) and `includes/admin_nav_tree.php`
- **Nav safety:** `orange_admin_nav_visible()` / `orange_admin_caps_for_page()` for `backup_center` use lightweight matrix checks (`orange_admin_may_backup_*`) — **never** `require_once includes/backup/backup_admin.php` during header/nav rendering. Engine loads only from `admin/pages/backup_center.php` and `admin/api/backup/_bootstrap.php`.

**Layout (one page, shared sections not duplicated):**

| Section | Scope |
|---------|--------|
| نظرة عامة | Shared |
| Tab **النسخ الاحتياطي الشامل** | Latest Full status, Run Full Backup, snapshots, verify, DRV, manifest/health/recovery report view |
| Tab **نسخ الدول** | Dynamic country discovery, Run All Recoverable Countries, latest package per country, verify, DRV, manifest/health/dependency graph/inventory view |
| حالة المهام المجدولة | Shared (read-only) |
| التخزين والاحتفاظ | Shared |
| السجلات | Shared (read-only) |

UI may hide tabs/actions by permission; every API enforces `backup_view` / `backup_run` / `backup_verify` independently.

**Timestamp display (presentation only — Owner 2026-07-23; config source Owner 2026-07-23; 12h AM/PM Owner 2026-07-23):** Backup Center shows all operator-facing datetimes in the **IANA timezone stored on the Country Context country** (`countries.timezone` via `orange_admin_context_timezone()`), **12-hour AM/PM** via a single JS path (`fmtTimestampDisplay` / `fmtPackageWhenDisplay`). Clock display uses `manifest.generated_at` (`gmdate('c')` UTC) — not raw `package_id`. Accordion previously showed unconverted `package_id` as the visible time (root cause of “historical UTC”). Missing/invalid `countries.timezone` → raw + warning (no silent UTC fallback). APIs / restore/CPR unchanged.

**Package ID time standard (Owner 2026-07-23 — architecture decision):** Every **newly created** Backup Package ID uses **one canonical time source: UTC** only. Applies to Full Backup (PHP `orange_backup_snapshot_name()` via `gmdate`, PowerShell `Get-SnapshotFolderName` via `[DateTime]::UtcNow`) and Country Backup (`country_export.php` folder timestamp via `gmdate`). Package IDs must **not** depend on PHP default timezone, Windows OS timezone, Admin Country Context, `countries.timezone`, or `date_default_timezone_set()`. Format remains `Y-m-d_His` (opaque identity — never treated as display time). `manifest.generated_at` remains UTC (`gmdate('c')`). **Do not** rename or migrate historical package directories; legacy local-named IDs stay permanently valid. Discovery / restore / DRV accept both legacy and new IDs. No CPR behavior change.

**Country Context scope (Owner 2026-07-23):** **Full Backup** remains **global** (shared `snapshots/`, Run Full, Full history/verify/DRV). **Country Backup views** (list, counters, history, details, manifest/health/verify/DRV for `country_recovery`) follow the **selected Admin Country Context** — no cross-country package leakage. **Run All Recoverable Countries** and shared BackupRoot remain **global**. Restore picker listing is unchanged (still multi-country).

**Owner SECURITY POLICY — hide Backup Root filesystem path in Backup Center UI (Owner 2026-07-24 — presentation only):** Backup Center must **never** display the physical Backup Root path (Windows/Linux filesystem locations), nor a **Copy Path** control. Path disclosure has no operational value for normal Backup Center use and is treated as unnecessary infrastructure information. Storage & Retention panel shows size KPIs + retention duration only (Arabic labels; units KB/MB/GB/TB unchanged; no `ORANGE_BACKUP_RETENTION_DAYS` key in UI). Status labels such as writable/healthy remain allowed; absolute paths are forbidden. **تنفيذ:** `admin/pages/backup_center.php` UI only — no API / path-resolution / backup-engine changes.

### Restore Center / UI

**Enterprise UX / IA redesign (Owner 2026-07-23 — UI only):** Restore Center (`admin/pages/restore_center.php`) was redesigned to match Backup Center philosophy (`rc-v2` mirrors `bc-v2`: workflow-first phases, package accordion progressive disclosure, readiness headline, execution stage strip, Country Context TZ + 12h AM/PM via `generated_at`). **No** restore engine, Admin API (`admin/api/restore/`), CPR, CSRF, permission, or payload changes.

**Final Enterprise UX polish (Owner 2026-07-23 — UI only):** Execution stage strip stays permanent; stage detail panels collapsed by default and expand on chip click or when stage is active (never all expanded). Validation (Certificate / C7 / C8) collapsible. Package summary shows Time / Package / Status / Eligibility / Primary Action only. Skeleton loading + proper Restore Jobs empty state. **No** API / CPR / workflow / security changes.

**Owner Approved — final UI refinements (Owner 2026-07-23 — UI only):** Accordion open/close is **chevron only**. Package summary row = decision only (time / status / eligibility / **إنشاء مهمة استرداد** when eligible) — **no** Package Information on summary. Expanded body = sole **معلومات الحزمة** entry + **أدوات تشغيلية** (Manifest/Health/DRV/Verify/Dry). Execution idle labels use Waiting / No activity. Package ID de-emphasized vs operator time. Package header sticky; only details body scrolls. One package expanded at a time unchanged. Restore Center considered Owner Approved after these refinements.

**Owner POLICY — Cancel boundary before production mutation (Owner 2026-07-24):** Operator may cancel the Restore Job at **any safe pre-production stage** (dry validation, plan, final approval, approved waiting execution, pre-restore backup, shadow DB/files/smoke, cutover readiness, …). Boundary is **not** `waiting_confirmation`; boundary is the **first production-mutating stage** (`maintenance_requested` and after: maintenance / production import / uploads cutover / rollback / finalize / terminal). Framework is sole source of truth: `orange_restore_fw_non_cancellable_statuses()` + `orange_restore_fw_cancellable_statuses()`; `job.cancellable` on public row; UI must not keep a parallel status list. After cancel → history + wizard Step 1. **تنفيذ:** `includes/backup/restore/restore_job_framework.php` + existing cancel API/UI wiring in `admin/pages/restore_center.php` — no execution-order / approval / maintenance / cutover / rollback / worker orchestration rewrites.

**Owner UX — Cancel button placement on wizard action row (Owner 2026-07-24 — presentation only):** Cancelable Restore Wizard steps expose secondary **إلغاء المهمة** inside the current workflow card, same action row as the primary CTA: Cancel LEFT · Primary RIGHT (`direction:ltr` on `.rc-guide-actions`). Outlined/neutral only; never stronger than primary orange. Visibility follows framework `job.cancellable` only. Click uses existing `rc-fw-cancel` → browser confirm → `admin/api/restore/job/cancel.php`. After success: clear journey selection (`selectedPackage` / `currentJourneyJob`) and reload so wizard returns to Step 1 «اختيار حزمة الاسترداد»; cancelled job appears in history. **تنفيذ:** `admin/pages/restore_center.php` UI wiring — cancellation policy owned by framework (see Owner POLICY cancel boundary above).

**Owner CRITICAL CORRECTION — wizard start state / terminal jobs (Owner 2026-07-24 — presentation + journey selection only):** A terminal/historical restore job MUST NEVER become the current wizard journey. Start state: (1) if a resumable/non-terminal job exists → open wizard at its valid current step; (2) else → Step 1 «اختيار حزمة الاسترداد» with eligible package list and «إنشاء مهمة استرداد»; (3) terminal jobs (`orange_restore_fw_transition_terminal_statuses()` — cancelled/completed/execution_completed/restore_completed/rollback_completed/failed/…) appear only in **سجل الاسترداد**; viewing history details/diagnostics MUST NOT replace the active journey. **تنفيذ:** `pickActiveJob` / `current_journey_job` / `is_terminal`+`is_resumable` on `orange_restore_fw_public_row` + `orange_restore_fw_find_resumable_job` + history section in `admin/pages/restore_center.php` / `admin/api/restore/list.php` — no Engine/Workers/Orchestrator/gates/transition matrix changes.

**Owner CRITICAL UI — Restore modals match Backup Center viewport pattern (Owner 2026-07-24 — presentation only):** Every Restore Center large modal (`rc_view_modal`, `rc_detail_modal`, `rc_orch_diag_modal`) uses Backup Center drawer sizing behavior as a centered dialog: `max-height: min(88vh, calc(100dvh - 32px), calc(100vh - 32px))`, flex column, fixed header, scrollable `.rc-modal-body` only, fixed footer with visible **إغلاق**, page scroll locked while open (`body.rc-modal-open`), backdrop click closes (same as Backup drawer; Escape not used in Backup reference). **تنفيذ:** `admin/pages/restore_center.php` UI only — no Engine/API/workflow changes.

**Owner CRITICAL UX — expandable panels sticky header (Owner 2026-07-24 — CSS only):** Restore Center expandable panels must match Backup Center: open panel capped (`max-height: min(420px,58vh); overflow:auto`), `summary` (collapse control) `position:sticky; top:0` so it remains reachable while the panel scrolls. Applies to all `rc-acc-item` groups (pkg/job/stage/val), `.rc-legacy-ref`, and Backup `bc-acc-item` / `bc-collapsible`. **تنفيذ:** `admin/pages/restore_center.php` + `admin/pages/backup_center.php` styles only — no workflow/API/backend changes.

**Owner CRITICAL UX — true step-by-step restore wizard (Owner 2026-07-24 — presentation only):** Restore Center is a **wizard journey**, not a dashboard. Operator sees: journey rail (✔ / ▶ / 🔒 / blocked reason) + one hero «مطلوب الآن» card with **exactly one** primary CTA. Package step = select one package card; create job appears only as the hero CTA after selection. Dashboard sections (readiness/validation/jobs/execution/monitoring) are **not** primary navigation (`rc-dash-hide`). Shadow Files kept between verify and smoke (existing gates). **تنفيذ:** `admin/pages/restore_center.php` UI only — **no** Engine / APIs / Framework / Workers / Orchestrator / gates / transitions changes.

**Owner UX — Restore Step 1 package view modes + visibility (Owner 2026-07-24 — presentation + list scope):** Step 1 package card reuses Backup Center browsing terminology: default **آخر العمليات** / **عرض السجل الكامل** / **العودة لآخر العمليات** (same card; no modal/page). Country packages follow Admin Country Context only; Full packages remain global. Latest-5 is applied **after** country/package scope + newest-first (never before filter). **Visibility ≠ eligibility:** show eligible and non-eligible packages; non-eligible stay visible with Arabic status badge, selection/create disabled, package details still accessible. Mode is client UI state only; cancel / country-context reload resets to latest-5. Eligibility calculations unchanged. **تنفيذ:** `admin/pages/restore_center.php` + `admin/api/restore/list.php` country-context scoping (same as Backup Center `list_country_packages(..., null, $countryContextCode)`).

**Owner Architecture — Restore Center self-contained orchestration (Owner 2026-07-23 / corrected 2026-07-24; production hardening 2026-07-24; stage-safe v4 2026-07-24):** Restore Center is the **only** operational interface for restore. Operators must **not** use SSH/terminal/manual CLI. Existing approved workers remain the executors; Restore Center **schedules** them via `admin/api/restore/job/run-worker.php` + `includes/backup/restore/restore_center_orchestrator.php` (allowlisted `scripts/backup/*` with `--job=` only). **Detached spawn (mandatory):** HTTP must **not** wait for the worker; full stdin/stdout/stderr redirect (Windows `<NUL` + `>>log 2>&1` via launch cmd; Linux `</dev/null` + `>>log 2>&1` + `nohup`); operator may close the browser safely; progress by polling job status. Synchronous HTTP-supervised wait is **rejected**. **Server-side stage validation (mandatory):** inside the same mutex critical section as the execution claim, the orchestrator rejects schedule unless the current job status explicitly allows that worker (`restore_center_invalid_stage`); UI visibility / client state / allowlist alone are insufficient. **Atomic duplicate protection (orchestration layer):** one job + one stage = one running worker via per-stage mutex (`flock LOCK_EX|LOCK_NB`) + run claim (`orchestrator_{worker}.run.json`); claim lifecycle reconciles with job status (not PID-only) and must not remain indefinitely after stage completion. **No false success:** audit `restore_center_worker_scheduled` and API `scheduled:true` only after spawn + PID liveness verification; otherwise `restore_center_worker_schedule_failed` / `scheduled:false`. **Diagnostics:** `admin/api/restore/job/orchestrator-diagnostics.php` + Restore Center «تشخيص التنسيق» (safe operational reasons; redacted log tails; no secrets/absolute paths). Production Cutover Authorization via existing APIs. **No** restore engine / gate / transition / worker script rewrites.

### Permissions (server-side enforced on every API)

| Key | Purpose |
|-----|---------|
| `backup_view` | View Backup Center, list packages/logs/storage |
| `backup_run` | Run Full Disaster Backup + Country Batch export |
| `backup_verify` | Verify package + Recoverability Validation (DRV) |

**Rules:** Super Admin **or** explicit matrix permission (`can_view` for view, `can_edit` for run/verify). Restore permissions (`backup_restore_*`) are **not** granted here.

### Admin APIs (POST mutating actions require CSRF token)

| Endpoint | Method | Delegates to |
|----------|--------|--------------|
| `admin/api/backup/list.php` | GET | `orange_backup_admin_collect_overview()`, package discovery |
| `admin/api/backup/status.php` | GET | Lock status, allowlisted file view, log tail |
| `admin/api/backup/run-full.php` | POST | Fixed CLI `scripts/backup/run_full_backup.php` → `orange_backup_run_full()` (subprocess; **not** in-process under IIS FastCGI) |
| `admin/api/backup/run-countries.php` | POST | Fixed CLI `scripts/backup/export_all_recoverable_countries.php` |
| `admin/api/backup/verify.php` | POST | `orange_backup_verify_full_package()` / `orange_country_export_verify_package()` |
| `admin/api/backup/recovery-check.php` | POST | `orange_recovery_validate_package()` |

Package paths are **never** accepted from the client — only server-discovered allowlisted `package_id` + optional `country_code`.

**View allowlist (Phase 3A admin file view):** `manifest.json`, `health.json`, `recovery_validation.json`, `dependency_graph.json`, `table_inventory.json` only — not SQL dumps, ZIPs, checksum files, or `.env.php`.

**Redaction (Phase 3A):** JSON keys matching secret fragments are stripped; log tails and CLI excerpts pass through `orange_backup_admin_redact_text()` before API/audit output. Log tail accepts only filenames discovered under `BackupRoot/logs/`.

### BackupRoot health — read vs write (Admin UI, Phase 3A hardening)

On Windows/Plesk, **Scheduled Task PHP** (CLI under Task Scheduler) and **Website PHP** (IIS application pool / site identity) may run as **different Windows accounts**. It is common for the scheduled Full/Country backup tasks to succeed while the Admin Backup Center reports the same `ORANGE_BACKUP_ROOT` as **readable but not writable** to Website PHP.

| Access | Required Windows ACL (typical) | Used by |
|--------|----------------------------------|---------|
| **Read** | Read & execute / List folder contents on `{BackupRoot}` and subfolders | Admin Backup Center **view**: list packages, view manifest/health/logs, Verify (read-only), lock status |
| **Modify / Write** | Modify (or Write) on `{BackupRoot}` | Manual Admin actions: Run Full Backup, Run All Countries, DRV/recovery-check (writes `recovery_validation.json` into package dirs), engine locks/logs during manual runs |

**Admin behavior (no ACL changes from UI):**

- `list.php` / read-only `status.php` actions use **`orange_backup_admin_context_for_view()`** — readable BackupRoot is enough; `backup_root_health` reports `writable` / `manual_actions_available` without HTTP 500.
- Mutating APIs (`run-full.php`, `run-countries.php`, `recovery-check.php`) use **`orange_backup_admin_manual_actions_block_message()`** and strict **`orange_backup_resolve_root()`** — fail closed before engine/CLI when Website PHP cannot write.
- `verify.php` remains **read-only** (view context); it does not require write access.
- Backup Center UI shows **حالة مسار النسخ الاحتياطي** (exists / readable / writable / manual available) and an Arabic banner when readable+non-writable; Run buttons and DRV are disabled; no generic alert for this expected condition.

**Manual Full Backup execution model (Admin API — Phase 3A):**

- **Manual Run Full Backup** from Admin **never** calls `orange_backup_run_full()` inside the IIS FastCGI HTTP request. The approved boundary is: `admin/api/backup/run-full.php` → **`orange_backup_admin_run_full_for_api()`** → subprocess **`scripts/backup/run_full_backup.php`** → **`orange_backup_run_full()`** (same engine entry as Plesk Scheduled Tasks).
- **Country Batch** uses the same subprocess pattern: fixed CLI **`scripts/backup/export_all_recoverable_countries.php`** (no request-derived script/path; `proc_open` argv array only).
- **`php_pdo` export remains CLI-only** — `orange_backup_pdo_export_database()` enforces `PHP_SAPI === 'cli'` and is **not** weakened. Admin must launch an approved **CLI PHP binary** (not `php-cgi`/FastCGI) for subprocess backup.
- Subprocess **stdout/stderr** are captured, redacted/sanitized for API/audit; runner progress lines must **not** be returned raw to the browser. API returns **one JSON object** only (`success`, `message`, `exit_code`; `error` on failure).
- Writable **BackupRoot**, **`backup_run`**, **CSRF**, and **POST-only** are enforced before subprocess launch.

**Operator action:** ACL fixes on `{BackupRoot}` remain a **server/Plesk operation** (grant Website PHP identity Modify on the private backup folder). Orange does not expose ACL editing in Admin.

### Scheduled Tasks section

Read-only display of expected Plesk schedules (Full daily 03:00 UTC, Country batch after Full, retention days). Orange does **not** edit Plesk Scheduled Tasks.

### Explicitly deferred (Phase 3A)

- Restore UI / production restore buttons / rollback UI
- Country Production Merge from admin
- HTTP Restore APIs / automatic restore
- Package delete / download
- DB-backed immutable restore audit table
- Real controlled Plesk staging/production drill

### Self-test

```powershell
php D:\orange\scripts\backup\self_test_backup_admin.php
php D:\orange\scripts\backup\self_test_backup_admin_nav.php
```

---

## QA commands (read-only / self-test)

```powershell
php D:\orange\scripts\backup\self_test_restore_foundation.php
php D:\orange\scripts\backup\self_test_backup_admin.php
php D:\orange\scripts\backup\self_test_backup.php
php D:\orange\scripts\backup\self_test_backup_retention.php
php D:\orange\scripts\backup\self_test_country_export.php
php D:\orange\scripts\backup\self_test_country_batch_export.php
php D:\orange\scripts\backup\self_test_recovery_validation.php
php D:\orange\scripts\backup\self_test_restore_full_staging.php
php D:\orange\scripts\backup\self_test_restore_country_staging.php
php D:\orange\scripts\backup\self_test_restore_approval.php
php D:\orange\scripts\backup\self_test_restore_merge_foundation.php
php D:\orange\scripts\backup\self_test_restore_database_cutover.php
php D:\orange\scripts\backup\self_test_restore_uploads_cutover.php
php D:\orange\scripts\backup\backup_environment_check.php
php D:\orange\scripts\backup\validate_backup_recoverability.php --package=D:\orange_backups\snapshots\yyyy-MM-dd_HHmmss
php D:\orange\scripts\backup\validate_registry.php --offline
php D:\orange\scripts\backup\export_country.php --country-id=1
php D:\orange\scripts\backup\verify_country_package.php --package=D:\orange_backups\country_packages\kw\yyyy-MM-dd_HHmmss
php D:\orange\scripts\backup\verify_full_backup.php --package=D:\orange_backups\snapshots\yyyy-MM-dd_HHmmss
```

---

## Schema revision 122 sync (2026-07-25) — Country Batch gate

**Incident:** Country Batch failed with  
`Registry validation failed: schema_revision mismatch (expected 122, got 121)`  
after code revision advanced to **122** (`countries.timezone`) while backup inventory JSON stayed at **121**.

**تنفيذ (repo sync):**
- `config/backup_table_registry.json` → `schema_revision` **122**
- `config/country_restore_boundary_matrix.json` → `schema_revision` **122**
- `config/country_restore_schema_expectations.json` → `schema_revision` **122**
- `ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION` → **122** (`includes/backup/recovery_validation.php`)
- Boundary matrix validator follows `ORANGE_CATALOG_SCHEMA_PHP_REVISION` (`includes/backup/country_boundary_matrix_lib.php`)

**Deploy:** `git pull` on server (do not hand-edit only one JSON on the server). Then re-run Country Batch once and confirm a new `country_packages/kw/` package and log `Countries succeeded=1`.

**Note:** Existing CRP packages stamped **121** become schema-incompatible for restore/validation under expected **122** (same class of lockstep as prior revision bumps). Keep Jul-22 packages as historical until a successful 122 export exists.

---

## Schema revision 123 Country Backup compatibility repair (2026-07-26)

**Context:** Phase 3 Step 2 raised live schema to **123** (`orange_company_documents.country_id`,
`orange_admin_audit_log.country_id` + `is_global`). Registry/matrix/recovery expected revision were
updated in commit `b6d5c75f`, but Country Backup still failed operationally.

**RUNTIME_ERROR_EVIDENCE_NOT_AVAILABLE** in the agent workspace (no `export_all_countries_*.log`).
**Code-proven failure path** after Step 2:

1. Country Batch exports a package stamped schema **123** with
   `orange_company_documents.special_handler = null` and `extraction_rule.type = country_id`.
2. Post-export Verify (`orange_country_export_verify_package` inside
   `includes/backup/country_batch_export.php`) still required special handler
   `polymorphic_company_documents` → `special_handler_missing` → batch marks country **failed**.
3. Separately, `config/country_restore_schema_expectations.json` remained at **122**, so Shadow
   N3-02 schema drift failed with `schema_revision_mismatch` (matrix/code 123 vs expectations 122).

**تنفيذ (compatibility repair — no schema 124, no new migration):**
- Regenerate Registry from source: `includes/backup/backup_table_registry_definitions.php` →
  `php scripts/backup/build_table_registry.php` → `config/backup_table_registry.json` (**123**).
- Audit Country extraction: `country_id = :country_id AND is_global = 0`
  (excludes explicit global; NULL country is never treated as global).
- Documents: `country_owned` + `country_id` extraction; Verify accepts direct country_id
  (no polymorphic special handler required).
- `config/country_restore_schema_expectations.json` → **123** + required columns/indexes for
  `orange_company_documents` / `orange_admin_audit_log`.
- Fallback expected-schema literals in Verify/DRV/Shadow/Export → **123**.
- Self-tests: C3 plan/nodes **81**; C4 Verify; Final Hardening N3-02.

**Owner deploy (after pull):** re-run Country Batch once from Backup Center; confirm
`Countries succeeded=1`, new package `schema_revision=123`, healthy, Verify/DRV, Restore eligibility.
Old **122** packages remain visible and **Non-Eligible** (`schema_incompatible`) — Exact Schema Policy; no dual compatibility.

**Frozen:** Backup/Restore UX, time policy, schedules, retention, wizard, production mutation.

---

## Schema revision 124 — Channel display-name country uniqueness (2026-07-27)

**Context:** Pre-Phase-4 narrow rule: `channels.name` («اسم الواجهة») unique per `country_id`
(active + inactive). Same name allowed across countries. App validation in
`admin/api/channels/save.php` + DB `uq_channels_country_name`.

**تنفيذ (repo sync — schema compatibility only):**
- `ORANGE_CATALOG_SCHEMA_PHP_REVISION` → **124**
- Migration `orange_catalog_migrate_channels_country_name_unique_v124`
- Registry regenerated via `scripts/backup/build_table_registry.php`
- Matrix / schema expectations / `ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION` → **124**
- Fallback expected-schema literals → **124**

**Owner deploy (after pull):** first request applies migration; re-run Country Batch once;
new packages stamp **124**. Old **123** packages remain visible and **Non-Eligible**
(`schema_incompatible`) — Exact Schema Policy; no dual compatibility.

**Frozen:** Backup/Restore UX, time policy, schedules, retention, wizard, production mutation.
Phase 4 not started.

---

## Related references

- Operator usage: `scripts/backup/README.md`
- **Restore owner policy (permanent):** `docs/archive/ORANGE_RESTORE_OWNER_POLICY.txt`
- **Restore architecture (approved):** `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt`
- Deploy / schema policy: `IBRAHIM_ORANGE_MASTER.txt` §2–§4, `docs/archive/ORANGE_STOREFRONT_PERFORMANCE_ROLLOUT.txt`
- Engineering decisions: Production Readiness Review PR-BAK-01, PR-BAK-02; checkpoint `docs/archive/ORANGE_ENGINEERING_CHECKPOINT_01.md`

---

## Production readiness status

| Item | Status |
|------|--------|
| Automated full backup script in repository | Implemented (`run_full_backup.php` + `orange_backup.ps1`) |
| Plesk PHP scheduled-task entry point | Implemented (`run_full_backup.php`) |
| Environment diagnostics CLI | Implemented (`backup_environment_check.php`) |
| Manifest + checksums + health report | Implemented (Phase 1A) |
| Package verifier CLI | Implemented (`verify_full_backup.php`) |
| Pre-migration snapshot procedure documented | This runbook + README |
| Country Recovery Package (CRP) export | **Implemented — Phase 1B.2** |
| Table registry (inventory) | **Implemented — Phase 1B.1** |
| Restore foundation (Phase 2A) | **Implemented — schema-free, no execution** |
| Full restore → staging (Phase 2B.1) | **Implemented — CLI only, no merge** |
| Country restore / production merge | **Not implemented — Phase 2B.2 / 2D** |
| Restore automation | **Forbidden by owner policy** — CLI + Super Admin + permissions only |
| Admin restore UI | **Not implemented — Phase 3 wrapper only** |
| Restore owner policy archived | **Yes** — `docs/archive/ORANGE_RESTORE_OWNER_POLICY.txt` |
| Restore architecture archived | **Yes** — `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` |
| Restore tested on production | **Required before go-live** — operator responsibility |

---

## Backup Provenance Metadata Registry — Stage 1 Backend (Owner 2026-08-04)

**Scope:** Backend sidecar only. **No Backup Center visual changes** in Stage 1.

**Layout under `{BackupRoot}`:**

```
.orange_meta/provenance/v1/executions/<execution_id>.json
.orange_meta/provenance/v1/packages/full/<package_id>.json
.orange_meta/provenance/v1/packages/country/<CC>/<package_id>.json
```

**Contract:** `metadata_schema_version = 1` is sidecar-only (not Application Schema 125).

**Authoritative for:** manual vs scheduled; Admin vs System; initiating Country context; parent Batch ↔ child Country packages; execution timestamps/status; per-Country outcomes.

**Not authoritative for:** Health, Verify, DRV, Recoverable, Restore eligibility, Schema compatibility, Cutover, package contents.

**Implementation:** `includes/backup/backup_provenance.php`; wired from `admin/api/backup/run-full.php`, `run-countries.php`, `orange_backup_run_full`, `orange_crp_batch_export_all`. List/UI JSON for Backup Center is intentionally unchanged in Stage 1.

**Focused test:** `scripts/backup/self_test_backup_provenance_stage1.php`

**Note:** Rejected Commit `7897eac5` remains fully reverted; Stage 1 is a fresh Backend-only implementation.
