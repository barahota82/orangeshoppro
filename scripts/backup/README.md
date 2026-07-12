# Orange Backup Script — Phase 1A (Full Disaster Backup)

Phase 1A scope: **full database + full uploads backup only**.  
**Not in this phase:** Country Recovery Package (CRP), table registry, staging restore, production merge, admin backup module, restore APIs.

**Primary entry point (Plesk):** `scripts/backup/run_full_backup.php`  
**Optional lower-level entry point (RDP / command line):** `scripts/backup/orange_backup.ps1`

---

## Plesk Scheduled Tasks (recommended)

Use Plesk **Scheduled Tasks** when RDP or interactive PowerShell is not available.

| Setting | Value |
|---------|-------|
| **Task type** | Run a PHP script |
| **Script path** | `scripts/backup/run_full_backup.php` |
| **First manual test** | Click **Run Now** after deploy |
| **Recommended schedule** | Daily at an off-peak UTC time (e.g. `03:00 UTC`) |
| **Notification** | Errors only |

The PHP entry point:

- resolves the project root automatically from the script location,
- validates `ORANGE_BACKUP_ROOT` via `backup_paths.php`,
- acquires a lock file to prevent concurrent runs,
- selects the safest backend (PowerShell when explicitly executable, otherwise PHP mysqldump via `proc_open`),
- writes logs under `{BackupRoot}/logs/run_full_backup_*.log`,
- never prints database credentials,
- exits `0` on success, non-zero on failure (`2` when another backup is already running).

**Before scheduling:** run the read-only environment check:

```powershell
php D:\orange\scripts\backup\backup_environment_check.php
```

Exit code must be `0` (`can_run_full_backup=yes`).

---

## Full Disaster Backup (`orange_backup.ps1`)

Creates a **timestamped, compressed backup** of:

- the MariaDB/MySQL database (via `mysqldump`), and
- the project `uploads/` directory.

Each successful run writes:

```text
{BackupRoot}/snapshots/yyyy-MM-dd_HHmmss/
  {db_name}.sql.gz
  uploads.zip
  manifest.json
  checksums.sha256
  health.json
{BackupRoot}/logs/orange_backup_yyyyMMdd_HHmmss.log
```

**This script does NOT restore automatically.** Restore is manual — see `docs/archive/ORANGE_BACKUP_RECOVERY_RUNBOOK.md`.

---

## Safe storage and Plesk configuration (`.env.php`)

**On Plesk production, set these keys in server-only `.env.php` (never commit):**

```php
'ORANGE_BACKUP_ROOT' => 'C:\\inetpub\\vhosts\\clickstorekw.com\\private\\orange_backups',
'ORANGE_MYSQLDUMP_PATH' => 'C:\\Program Files (x86)\\Plesk\\MySQL\\bin\\mysqldump.exe',
// optional — only if you want PowerShell delegation from PHP:
// 'ORANGE_BACKUP_POWERSHELL_PATH' => 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
```

| Key | Required on Plesk | Purpose |
|-----|-------------------|---------|
| `ORANGE_BACKUP_ROOT` | **Yes** | Writable folder **outside** the site / `httpdocs` / Git repo |
| `ORANGE_MYSQLDUMP_PATH` | **Strongly recommended** | Exact host path to `mysqldump.exe` when auto-discovery fails |
| `ORANGE_BACKUP_POWERSHELL_PATH` | No | Optional explicit PowerShell path for delegation |

**Owner setup before first backup:**

1. Create `private\orange_backups` (or equivalent) **outside** `orangeshoppro.com` / `httpdocs`.
2. Grant the **Plesk scheduled-task PHP identity** write permission on that folder.
3. Locate `mysqldump.exe` on the host (Plesk / MariaDB bin path) and set `ORANGE_MYSQLDUMP_PATH`.
4. Confirm **`proc_open` is enabled** for the PHP CLI profile used by scheduled tasks. If disabled, the task **must fail** — do not expect success.
5. Run `backup_environment_check.php` until `can_run_full_backup=yes`.

Rules enforced by `includes/backup/backup_paths.php`:

| Rule | Behavior |
|------|----------|
| Outside web root | Rejects paths inside the Orange project root or `uploads/` |
| No public paths | Rejects `httpdocs`, `public_html`, `wwwroot` segments |
| No traversal | Rejects `..` in configured paths |
| No empty override | Rejects explicitly empty `ORANGE_BACKUP_ROOT` |
| Writable | Directory must exist (or be creatable) and be writable |
| Explicit tool paths | `ORANGE_MYSQLDUMP_PATH` preferred; optional `ORANGE_BACKUP_POWERSHELL_PATH` |
| `proc_open` required | PHP mysqldump backend fails closed when `proc_open` is disabled |
| No hardcoded production paths in Git | Example paths live in `.env.php` on the server only |

PowerShell `-BackupRoot` still works; when PHP CLI is available, `resolve_backup_root.php` validates the resolved path.

---

## Package structure

| File | Purpose |
|------|---------|
| `{db_name}.sql.gz` | Compressed full database dump |
| `uploads.zip` | Full `uploads/` archive |
| `manifest.json` | Package metadata (no secrets) |
| `checksums.sha256` | SHA-256 for dump, uploads, manifest, health |
| `health.json` | Validation report with `package_status` |

Atomic finalize: dump and uploads are created in a temporary workspace (`._work_*`); `finalize_full_backup.php` writes manifest/health/checksums and verifies them. The workspace is renamed to the final snapshot folder **only on success**. Failed runs remove the temporary workspace.

---

## Manifest fields (`manifest.json`)

| Field | Purpose |
|-------|---------|
| `package_type` | Always `full_disaster` |
| `package_version` | Package format version |
| `generated_at` | ISO-8601 timestamp (UTC) |
| `schema_revision` | Live `orange_schema_meta.version` |
| `git_commit` | Short Git hash when available |
| `source_database` | Database name (no password) |
| `database_host` | Host identifier (no credentials) |
| `project_identifier` | Environment/project label (no secrets) |
| `dump_file` / `uploads_file` | Payload filenames |
| `dump_sha256` / `uploads_sha256` | Payload checksums |
| `dump_size_bytes` / `uploads_size_bytes` | Payload sizes |
| `table_count` / `approx_total_rows` | INFORMATION_SCHEMA summary |
| `backup_status` | `success`, `warning`, or `failed` |
| `health_report_file` | `health.json` |
| `checksums_file` | `checksums.sha256` |

---

## Health report (`health.json`)

Minimum fields:

- `generated_at`, `schema_revision`
- `metadata_collection_status`
- `database_dump_created`, `uploads_archive_created`
- `dump_non_zero_size`, `uploads_archive_readable`
- `dump_checksum_verified`, `uploads_checksum_verified`
- `backup_root_safety_passed`
- `package_file_inventory`, `warnings`, `failure_reasons`
- `package_status`: `healthy`, `warning`, or `failed`

The backup is **not finalized** when required files are missing or checksum validation fails (`package_status=failed`).

---

## Verification CLI (read-only)

```powershell
php D:\orange\scripts\backup\verify_full_backup.php --package=D:\orange_backups\snapshots\2026-07-11_030000
```

Verifies: safe package path, manifest structure, `package_type=full_disaster`, required files, checksums, readable `health.json`, non-failed status, schema revision present, no secret fields in manifest.

---

## Self-test / QA (read-only, no real backup)

```powershell
php D:\orange\scripts\backup\self_test_backup.php
php D:\orange\scripts\backup\backup_environment_check.php
php D:\orange\scripts\backup\resolve_backup_root.php --project-root=D:\orange
php D:\orange\scripts\backup\backup_metadata.php --project-root=D:\orange
```

---

## Manual full backup (PHP entry point)

```powershell
php D:\orange\scripts\backup\run_full_backup.php
```

## Manual full backup (PowerShell — optional)

From PowerShell (run as a user that can read the project, `.env.php`, and `uploads/`):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File D:\orange\scripts\backup\orange_backup.ps1 `
  -ProjectRoot D:\orange `
  -BackupRoot D:\orange_backups
```

---

## Prerequisites

- Windows Server with **Plesk** (or equivalent) hosting Orange.
- **MariaDB/MySQL** client tools (`mysqldump.exe`) installed and reachable.
- **PHP CLI** for scheduled tasks and package finalization.
- `.env.php` present in the project root (same folder as `config.php`).
- Write access to a dedicated backup folder outside the web root.

---

## Manual full backup (PowerShell details)

| Parameter | Default | Purpose |
|-----------|---------|---------|
| `-ProjectRoot` | Repository root (parent of `scripts/`) | Orange install path |
| `-BackupRoot` | `ORANGE_BACKUP_ROOT` or `{drive}:\orange_backups` | All backups and logs stay under this folder |
| `-MysqldumpPath` | Auto-detect | Full path to `mysqldump.exe` if not on PATH |
| `-RetentionDaily` | `7` | Keep snapshots from the last N calendar days |
| `-RetentionWeekly` | `4` | Also keep the newest snapshot per ISO week |
| `-RetentionMonthly` | `6` | Also keep the newest snapshot per calendar month |

On success the script exits `0`. On failure it exits non-zero, leaves prior snapshots untouched, and removes any incomplete temporary workspace.

---

## Schedule daily backup (Plesk — recommended)

See **Plesk Scheduled Tasks** section above.

## Schedule daily backup (Windows Task Scheduler — optional)

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
2. Confirm the newest folder under `{BackupRoot}\snapshots\` contains all five package files.
3. Run `verify_full_backup.php` on the snapshot.
4. Only then run `git pull` (or allow the first HTTP request that triggers `catalog_schema.php` migrations).

---

## Retention

Retention runs **only after a fully successful backup**. The script never deletes files outside `-BackupRoot`. Expired snapshots under `{BackupRoot}/snapshots/` may be removed according to daily / weekly / monthly rules.

---

## Off-host copy (recommended)

Copy each successful snapshot (or the entire `{BackupRoot}/snapshots/` tree) to off-host storage (second disk, NAS, cloud object storage). Backups on the same server as production reduce recovery value when the host fails.

---

## Restore

**Not implemented in Phase 1A.** Manual full restore steps (disaster only) are documented in:

`docs/archive/ORANGE_BACKUP_RECOVERY_RUNBOOK.md`

Country Recovery Package export/restore is **Phase 1B** (export not yet implemented).

---

## Country Backup Inventory — Phase 1B.1 (registry only)

Authoritative table classification for future Country Recovery Package export. **No export, restore, or SQL generation in this phase.**

| Artifact | Purpose |
|----------|---------|
| `includes/backup/backup_table_registry_definitions.php` | **Single source of truth** — ownership, orders, extraction rules |
| `scripts/backup/build_table_registry.php` | Regenerates `config/backup_table_registry.json` (never hand-edit) |
| `scripts/backup/validate_registry.php` | Fails on missing/unknown tables, incomplete metadata, schema revision mismatch |
| `config/backup_table_registry.json` | Generated inventory (117 tables @ schema revision 121) |

**Ownership types:** `global`, `country_owned`, `dependent`, `excluded_ephemeral`

**Per-table metadata:** `ownership_type`, `export_order`, `delete_order`, `restore_order`, `extraction_rule`, `parent_dependency`, `uploads_linked`, `integrity_critical`

```powershell
php D:\orange\scripts\backup\build_table_registry.php --verify-dump
php D:\orange\scripts\backup\validate_registry.php --offline
php D:\orange\scripts\backup\validate_registry.php
```

After schema changes: update definitions, bump `ORANGE_CATALOG_SCHEMA_PHP_REVISION`, rebuild registry, commit both PHP and generated JSON.
