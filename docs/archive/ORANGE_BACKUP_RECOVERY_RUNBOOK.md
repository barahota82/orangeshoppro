# Orange Backup & Recovery Runbook

**Document type:** Engineering archive — operational runbook  
**Scope:** PR-BAK-01, PR-BAK-02 — backup and pre-migration snapshot foundation  
**Implementation:** `scripts/backup/orange_backup.ps1` (Windows / Plesk / MariaDB)

---

## Backup policy

Orange production data consists of:

1. **MariaDB/MySQL database** — orders, catalog, stock, GL, accounts, configuration rows, schema metadata.
2. **`uploads/` directory** — product images, payment proofs, attachments, and other user-uploaded files under the web root.

Both must be backed up together. A database-only backup without `uploads/` is **incomplete** for disaster recovery and pre-deploy rollback planning.

Approved backup tool path: **`scripts/backup/orange_backup.ps1`**.  
Default backup storage: **`D:\orange_backups`** (or another dedicated folder passed as `-BackupRoot`). Backups must **not** live inside the Git repository.

---

## Daily backup requirement

A **daily automated backup** is required for any production or production-like environment.

- Schedule via **Windows Task Scheduler** (see `scripts/backup/README.md`).
- Each run must produce a timestamped snapshot under `{BackupRoot}/snapshots/` with:
  - compressed database dump (`{db_name}.sql.gz`),
  - `uploads.zip`,
  - `manifest.json`,
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
3. Record the snapshot folder name and optional `git_commit` from the manifest.
4. Only then deploy code (`git pull`).

This snapshot is the **mandatory pre-migration rollback point** (PR-BAK-02). Forward-only PHP migrations have no automated down-migration; recovery depends on restoring this snapshot.

If migration fails or the site enters degraded schema state, restore from this snapshot before retrying.

---

## Uploads backup requirement

The `uploads/` tree is part of production state. Payment proofs, CRM attachments, and catalog media are **not** fully represented in the SQL dump alone.

Every backup run must include `uploads.zip` in the snapshot. Missing or empty uploads archives invalidate the backup for go-live and disaster-recovery purposes.

---

## Restore procedure (manual)

**The backup script does not restore automatically.** Restore is a **manual, operator-driven** procedure:

1. **Stop web traffic** to the site (maintenance mode / stop app pool) before overwriting live data.
2. **Database:** decompress `{db_name}.sql.gz`, then import into MariaDB/MySQL using the appropriate client (`mysql` CLI or Plesk database tools). Target the database name recorded in `manifest.json` (default from `config.php`: `orange_db`).
3. **Uploads:** extract `uploads.zip` into the project `uploads/` directory, preserving relative paths. Merge or replace only after confirming the snapshot is the intended point-in-time.
4. **Verify:** run gated `health.php` checks, admin login, sample order/stock read, and spot-check uploaded files referenced by recent orders.
5. **Document:** note snapshot timestamp, manifest `git_commit`, and reason for restore.

Restore must be **tested on a non-production clone** before relying on it for production go-live. An untested backup policy does not satisfy production readiness.

---

## Database engine policy

**MariaDB/MySQL remains the approved database engine** for Orange Shop Pro.

- Backups use **`mysqldump`** against the existing MariaDB/MySQL instance.
- **Do not migrate Orange to Microsoft SQL Server** as part of backup/recovery work.
- Recovery always returns to MariaDB/MySQL on the same engine family used at backup time.

---

## Related references

- Operator usage: `scripts/backup/README.md`
- Deploy / schema policy: `IBRAHIM_ORANGE_MASTER.txt` §2–§4, `docs/archive/ORANGE_STOREFRONT_PERFORMANCE_ROLLOUT.txt`
- Engineering decisions: Production Readiness Review PR-BAK-01, PR-BAK-02; checkpoint `docs/archive/ORANGE_ENGINEERING_CHECKPOINT_01.md`

---

## Production readiness status

| Item | Status |
|------|--------|
| Automated backup script in repository | Implemented (`scripts/backup/orange_backup.ps1`) |
| Pre-migration snapshot procedure documented | This runbook + README |
| Restore automation | **Not implemented** — manual only |
| Restore tested on production | **Required before go-live** — operator responsibility |
