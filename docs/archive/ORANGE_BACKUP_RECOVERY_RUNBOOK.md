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

**Commands:**

```powershell
# Read-only status (JSON report — no secrets)
php D:\orange\scripts\backup\restore_job_status.php --job=yyyy-MM-dd_HHmmss_xxxxxxxx

# Approve for future merge (does NOT merge)
php D:\orange\scripts\backup\restore_approve_merge.php --job=JOB_ID --admin-id=1 --password=SECRET --confirm=RESTORE --action=approve

# Country approve example
php D:\orange\scripts\backup\restore_approve_merge.php --job=JOB_ID --admin-id=1 --password=SECRET --confirm="RESTORE KW" --action=approve

# Reject or cancel before merge
php D:\orange\scripts\backup\restore_approve_merge.php --job=JOB_ID --admin-id=1 --password=SECRET --action=reject --reason="Owner declined"
php D:\orange\scripts\backup\restore_approve_merge.php --job=JOB_ID --admin-id=1 --password=SECRET --action=cancel --reason="Operator cancelled"
```

**On success:** Job status becomes `approved_for_merge`. One-time approval token is issued and consumed in the same operation (hash stored on job + sidecar metadata). Filesystem audit records re-auth, phrase check, token lifecycle, and state transition.

**Failure policy:** Any gate failure aborts with no state change (except audit of failed re-auth/phrase/permission). Rejection/cancellation sets status `cancelled` with reason fields. No automatic retry. Active approval tokens invalidated on job mutation, reject, or cancel.

**Self-test:**

```powershell
php D:\orange\scripts\backup\self_test_restore_approval.php
```

Architecture: `docs/archive/ORANGE_RESTORE_ARCHITECTURE.txt` (Phase 2C section)

---

## Phase 2D.1 — Full Production Merge (ARCHITECTURE APPROVED — NOT IMPLEMENTED)

**Status:** Architecture approved in principle (2026-07-13). **No merge CLI, merge engine,
or production writes exist in the repository yet.**

**Scope:** Promote a `full_disaster` job from `approved_for_merge` to production using
CLI-first orchestration only. Separate from Phase 2D.2 (country merge).

**Selected strategies (approved):**
- **Database:** Validated staging export → controlled production replace (export staging DB to job artifact, then wipe + stream-import into production with 2B.1 SQL safety)
- **Uploads:** Staged full-tree directory swap (`uploads_next` → rename → `uploads`) after pre-merge snapshot

**Permanent owner decisions (2026-07-13):**

### Production Merge Credentials

Production Merge must **never** use `DB_USER` / `DB_PASS`. Required server-only keys:

| Key | Required | Notes |
|-----|----------|-------|
| `ORANGE_RESTORE_MERGE_DB_USER` | Yes | Must differ from `DB_USER` and `ORANGE_RESTORE_STAGING_DB_USER` |
| `ORANGE_RESTORE_MERGE_DB_PASS` | Yes | Must differ from `DB_PASS` and `ORANGE_RESTORE_STAGING_DB_PASS` |

- Production schema (`DB_NAME`) only; minimum merge privileges; never used by the application
- Documented in `.env.example.php`, architecture archive, and this runbook
- **Fail closed:** missing/empty/reused user or password → merge aborts before any production write

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
| Production merge | Phase 2D.1 architecture **approved** — **not implemented** |
| Full production merge credentials (`ORANGE_RESTORE_MERGE_DB_*`) | **Owner policy §11 — required when 2D.1 implemented** |
| Uploads same-volume gate | **Owner policy §12 — required when 2D.1 implemented** |
| Owner approval gate (`approved_for_merge`) | **Phase 2C implemented** |
| Admin backup/restore UI wrapper | Phase 3 |
| Dedicated permissions `backup_restore_full` / `backup_restore_country` | **Phase 2A implemented** |
| Restore audit DB table | First mutating restore phase (not 2A) |

---

## QA commands (read-only / self-test)

```powershell
php D:\orange\scripts\backup\self_test_restore_foundation.php
php D:\orange\scripts\backup\self_test_backup.php
php D:\orange\scripts\backup\self_test_backup_retention.php
php D:\orange\scripts\backup\self_test_country_export.php
php D:\orange\scripts\backup\self_test_country_batch_export.php
php D:\orange\scripts\backup\self_test_recovery_validation.php
php D:\orange\scripts\backup\self_test_restore_full_staging.php
php D:\orange\scripts\backup\self_test_restore_country_staging.php
php D:\orange\scripts\backup\self_test_restore_approval.php
php D:\orange\scripts\backup\backup_environment_check.php
php D:\orange\scripts\backup\validate_backup_recoverability.php --package=D:\orange_backups\snapshots\yyyy-MM-dd_HHmmss
php D:\orange\scripts\backup\validate_registry.php --offline
php D:\orange\scripts\backup\export_country.php --country-id=1
php D:\orange\scripts\backup\verify_country_package.php --package=D:\orange_backups\country_packages\kw\yyyy-MM-dd_HHmmss
php D:\orange\scripts\backup\verify_full_backup.php --package=D:\orange_backups\snapshots\yyyy-MM-dd_HHmmss
```

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
