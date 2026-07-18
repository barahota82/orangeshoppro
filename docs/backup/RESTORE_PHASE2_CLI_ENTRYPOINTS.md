# Phase 2 CLI restore entry points (bridge discovery)

**Phase:** 3B.3B2 — Restore Bridge Layer (documentation)  
**P0-2 update (2026-07-18):** Phase-2 **production** cutover / merge / E2E CLIs are **permanently disabled tombstones** (`legacy_restore_entrypoint_disabled`). Production mutations use the **approved 3B.4 worker allowlist only**.

**Purpose:** Historical map of Phase 2 CLI / orchestration modules vs Admin Restore Framework.  
**Non-goals:** invoking disabled CLIs; argv credentials; bypass of 3B.4 contract/maintenance.

---

## Dual-track model (historical → current)

| Track | Job root | Status |
|-------|----------|--------|
| Phase 2 CLI / merge | `{workRoot}/{job_id}/` | Production mutator CLIs **DISABLED** (tombstones) |
| Phase 3B Admin Framework | `{workRoot}/framework/{job_id}/` | **Supported** — Restore Center → dry-run → plan → final approval → execution contract → **3B.4 workers** |

---

## Approved production mutation workers (only)

| Script | Role |
|--------|------|
| `scripts/backup/restore_import_production.php` | Production DB wipe/import (`--job=` only) |
| `scripts/backup/restore_uploads_cutover.php` | Production uploads cutover (`--job=` only) |
| `scripts/backup/restore_rollback.php` | Production rollback (`--job=` only) |
| `scripts/backup/restore_finalize.php` | Finalize / maintenance release path (`--job=` only) |

**Policy:** CLI + approved job ID + framework state + maintenance active + execution contract + rollback anchor as applicable + scoped worker identity/token. **No** `--password=` / `--db-password=` / arbitrary paths / DB names on argv.

Catalog: `includes/backup/restore/restore_production_cli_policy.php`

---

## Phase-2 production CLIs — DISABLED tombstones

| Script | Former role | Disposition |
|--------|-------------|-------------|
| `restore_full_database_cutover.php` | DB cutover | Tombstone → use `restore_import_production.php` |
| `restore_full_uploads_cutover.php` | Uploads cutover | Tombstone → use `restore_uploads_cutover.php` |
| `restore_full_rollback.php` | Rollback | Tombstone → use `restore_rollback.php` |
| `restore_run_full.php` | E2E start | Tombstone |
| `restore_resume_full.php` | E2E resume | Tombstone |
| `restore_approve_merge.php` | Merge approve/reject/cancel | Tombstone → Restore Center approval |
| `restore_full_post_validate.php` | Post-cutover validation | Tombstone |
| `restore_full_post_validate_finalize.php` | Finalize | Tombstone → use `restore_finalize.php` |

Each prints:

```text
LEGACY_RESTORE_ENTRYPOINT: DISABLED
REASON: legacy_restore_entrypoint_disabled
USE: approved_3b_restore_workflow
```

and exits non-zero **before** loading orchestrators or parsing credentials.  
**No** compatibility flag, env var, or token may re-enable them.

---

## Still allowed (non-production-mutation / staging / status)

| Script | Role |
|--------|------|
| `restore_status_full.php` | Status report |
| `restore_job_status.php` | Generic job status |
| `restore_full_to_staging.php` | Full → staging only |
| `restore_country_to_staging.php` | Country → staging only |
| `restore_prepare_backup.php` | 3B pre-restore Full anchor |
| `restore_shadow_db.php` / `verify` / `files` / `smoke` | 3B shadow path (no production cutover) |

---

## Underlying libraries retained

Phase-2 **library** modules under `includes/backup/restore/` (`restore_orchestrator.php`, `restore_e2e_orchestrator.php`, `restore_merge_*`, etc.) remain for isolated self-tests and any internal reuse by the 3B engines. **Executable production entry points** for those libraries are the tombstones above (disabled) or the approved 3B.4 workers.

---

## Bridge contract file

Per framework job:

`{workRoot}/framework/{job_id}/restore_execution_contract.json`

Public helpers: `orange_restore_prepare_execution_contract`, `orange_restore_validate_execution_contract`, `orange_restore_load_execution_contract`.

Bridge `cli_request.primary_cli` for Full points at `restore_import_production.php` (metadata only; bridge never invokes CLI).

**Production cutover/rollback design:** `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`.
