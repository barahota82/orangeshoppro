# Orange Backup Script

`orange_backup.ps1` creates a **timestamped, compressed backup** of:

- the MariaDB/MySQL database (via `mysqldump`), and
- the project `uploads/` directory.

Each successful run writes:

- `{BackupRoot}/snapshots/yyyy-MM-dd_HHmmss/manifest.json`
- `{BackupRoot}/snapshots/yyyy-MM-dd_HHmmss/{db_name}.sql.gz`
- `{BackupRoot}/snapshots/yyyy-MM-dd_HHmmss/uploads.zip`
- `{BackupRoot}/logs/orange_backup_yyyyMMdd_HHmmss.log`

**This script does NOT restore automatically.** Restore is manual — see `docs/archive/ORANGE_BACKUP_RECOVERY_RUNBOOK.md`.

---

## Prerequisites

- Windows Server with **Plesk** (or equivalent) hosting Orange.
- **MariaDB/MySQL** client tools (`mysqldump.exe`) installed and reachable.
- **PHP CLI** recommended (reads `config.php` + `.env.php` accurately). Text parsing fallback exists if PHP is unavailable.
- `.env.php` present in the project root (same folder as `config.php`).
- Write access to a dedicated backup folder (default: `D:\orange_backups`).

---

## Manual backup

From PowerShell (run as a user that can read the project, `.env.php`, and `uploads/`):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\orange\scripts\backup\orange_backup.ps1 `
  -ProjectRoot D:\orange `
  -BackupRoot D:\orange_backups
```

Optional parameters:

| Parameter | Default | Purpose |
|-----------|---------|---------|
| `-ProjectRoot` | Repository root (parent of `scripts/`) | Orange install path |
| `-BackupRoot` | `{drive}:\orange_backups` | All backups and logs stay under this folder |
| `-MysqldumpPath` | Auto-detect | Full path to `mysqldump.exe` if not on PATH |
| `-RetentionDaily` | `7` | Keep snapshots from the last N calendar days |
| `-RetentionWeekly` | `4` | Also keep the newest snapshot per ISO week |
| `-RetentionMonthly` | `6` | Also keep the newest snapshot per calendar month |

Example with explicit `mysqldump`:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\orange\scripts\backup\orange_backup.ps1 `
  -ProjectRoot D:\orange `
  -BackupRoot D:\orange_backups `
  -MysqldumpPath "C:\Program Files\MariaDB 10.11\bin\mysqldump.exe"
```

On success the script exits `0`. On failure it exits non-zero, leaves prior snapshots untouched, and removes any incomplete temporary workspace.

---

## Schedule daily backup (Windows Task Scheduler)

1. Open **Task Scheduler** → **Create Task**.
2. **General:** name e.g. `Orange Daily Backup`; run whether user is logged on or not; use an account with read access to the site and backup folder.
3. **Triggers:** Daily at a low-traffic time (e.g. 03:00).
4. **Actions:** Start a program  
   - **Program:** `powershell.exe`  
   - **Arguments:**

     ```text
     -NoProfile -ExecutionPolicy Bypass -File "D:\orange\scripts\backup\orange_backup.ps1" -ProjectRoot "D:\orange" -BackupRoot "D:\orange_backups"
     ```

5. **Settings:** stop task if it runs longer than expected; do not start a new instance if already running.
6. After the first scheduled run, verify files under `D:\orange_backups\snapshots\` and the log under `D:\orange_backups\logs\`.

---

## Backup before `git pull` / migration

**Always take a snapshot immediately before** deploying code that may run schema migrations (`git pull` on production).

1. Run the manual backup command above and wait for exit code `0`.
2. Confirm the newest folder under `{BackupRoot}\snapshots\` contains:
   - `manifest.json`
   - `{db_name}.sql.gz`
   - `uploads.zip`
3. Only then run `git pull` (or allow the first HTTP request that triggers `catalog_schema.php` migrations).

Treat this pre-deploy snapshot as mandatory — see `docs/archive/ORANGE_BACKUP_RECOVERY_RUNBOOK.md`.

---

## Verify backup files exist

After each run:

1. Check exit code (`echo $LASTEXITCODE` in the same PowerShell session should be `0`).
2. Open the latest log in `{BackupRoot}\logs\`.
3. Open the newest `{BackupRoot}\snapshots\yyyy-MM-dd_HHmmss\manifest.json` and confirm:
   - `timestamp`
   - `project_root`
   - `db_name`
   - `dump_file`
   - `uploads_archive`
   - `git_commit` (when Git is available)
4. Confirm `dump_file` and `uploads_archive` exist in that snapshot folder and are non-trivial in size.

---

## Retention

Retention runs **only after a fully successful backup**. The script never deletes files outside `-BackupRoot`. Expired snapshots under `{BackupRoot}\snapshots\` may be removed according to daily / weekly / monthly rules.

---

## Restore

**Not supported by this script.** Manual restore steps are documented in:

`docs/archive/ORANGE_BACKUP_RECOVERY_RUNBOOK.md`
