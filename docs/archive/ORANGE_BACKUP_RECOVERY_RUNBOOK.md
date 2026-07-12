# Orange Backup & Recovery Runbook

**Document type:** Engineering archive — operational runbook  
**Scope:** PR-BAK-01, PR-BAK-02 — backup and pre-migration snapshot foundation  
**Implementation:** `scripts/backup/run_full_backup.php` (primary — Plesk Scheduled Tasks) and `scripts/backup/orange_backup.ps1` (optional — RDP/command line)  
**Current implemented phases:** Phase 1A full disaster backup — database + uploads + manifest + checksums + health report — plus Phase 1B.1 table registry inventory only. Country Recovery Package export is still deferred.

---

## Plesk scheduled backup (primary)

| Setting | Value |
|---------|-------|
| Task type | **Run a PHP script** |
| Script path | `scripts/backup/run_full_backup.php` |
| First test | **Run Now** (manual) after `git pull` |
| Schedule | Daily at off-peak **UTC** time |
| Notification | **Errors only** |

Pre-flight (read-only):

```powershell
php D:\orange\scripts\backup\backup_environment_check.php
```

The PHP entry point handles locking, backend selection, logging under `{BackupRoot}/logs/`, and delegates to PowerShell only when explicitly executable; otherwise it uses **PHP + mysqldump via `proc_open`**, with the guarded PDO SQL fallback only when mysqldump is unavailable and PDO preflight permits it.

**Required server-only `.env.php` keys (Plesk example):**

```php
'ORANGE_BACKUP_ROOT' => 'C:\\inetpub\\vhosts\\clickstorekw.com\\private\\orange_backups',
'ORANGE_MYSQLDUMP_PATH' => 'C:\\Program Files (x86)\\Plesk\\MySQL\\bin\\mysqldump.exe',
```

Create the BackupRoot folder outside `httpdocs`, grant write permission to the scheduled-task PHP user, and verify `proc_open` is enabled for the preferred mysqldump path. If PowerShell/mysqldump cannot run and the PDO fallback preflight is not safe, the scheduled task **must fail closed**.

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

Retention (configurable on the script):

- **Daily:** keep snapshots from the last N calendar days (`-RetentionDaily`, default 7).
- **Weekly:** keep the newest snapshot per ISO week for W weeks (`-RetentionWeekly`, default 4).
- **Monthly:** keep the newest snapshot per calendar month for M months (`-RetentionMonthly`, default 6).

Retention cleanup runs **only after a successful backup** and **only inside `-BackupRoot`**.

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

**The backup script does not restore automatically.** Restore is a **manual, operator-driven** procedure:

1. **Stop web traffic** to the site (maintenance mode / stop app pool) before overwriting live data.
2. **Database:** decompress `{db_name}.sql.gz`, then import into MariaDB/MySQL using the appropriate client (`mysql` CLI or Plesk database tools). Target the database name recorded in `manifest.json` (default from `config.php`: `orange_db`).
3. **Uploads:** extract `uploads.zip` into the project `uploads/` directory, preserving relative paths. Merge or replace only after confirming the snapshot is the intended point-in-time.
4. **Verify:** run gated `health.php` checks, admin login, sample order/stock read, and spot-check uploaded files referenced by recent orders.
5. **Document:** note snapshot timestamp, manifest `git_commit`, and reason for restore.

Restore must be **tested on a non-production clone** before relying on it for production go-live. An untested backup policy does not satisfy production readiness.

**Phase 1A does not implement:** staging restore, production merge, restore APIs, or admin backup UI.

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
| Table registry (`config/backup_table_registry.json`) | **Implemented** — generated by `scripts/backup/build_table_registry.php` only |
| Registry validation CLI | **Implemented** — `scripts/backup/validate_registry.php` |
| Country Recovery Package (CRP) export | **Not implemented — Phase 1B.2+** |
| Country-scoped uploads export | **Not implemented — Phase 1B.2+** |

---

## Deferred — Phase 1B.2+ and later

The following are **not part of Phase 1A / 1B.1** and must not be assumed available:

| Item | Target phase |
|------|----------------|
| Country Recovery Package (CRP) SQL export | Phase 1B.2+ |
| Country-scoped uploads collector | Phase 1B.2+ |
| Staging restore / production merge | Phase 2 |
| Admin backup module / job tables | Phase 3 |

---

## QA commands (read-only / self-test)

```powershell
php D:\orange\scripts\backup\self_test_backup.php
php D:\orange\scripts\backup\backup_environment_check.php
php D:\orange\scripts\backup\validate_registry.php --offline
php D:\orange\scripts\backup\verify_full_backup.php --package=D:\orange_backups\snapshots\yyyy-MM-dd_HHmmss
```

---

## Related references

- Operator usage: `scripts/backup/README.md`
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
| Country Recovery Package (CRP) | **Not implemented — Phase 1B.2+** |
| Table registry (inventory) | **Implemented — Phase 1B.1** |
| Staging restore / merge | **Not implemented — Phase 2** |
| Restore automation (full DB) | **Not implemented — manual only** |
| Admin backup module | **Not implemented — Phase 3** |
| Restore tested on production | **Required before go-live** — operator responsibility |
