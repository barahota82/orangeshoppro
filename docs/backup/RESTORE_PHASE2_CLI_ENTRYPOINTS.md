# Phase 2 CLI restore entry points (bridge discovery)

**Phase:** 3B.3B2 — Restore Bridge Layer (documentation only for discovery)  
**Purpose:** Map Admin Restore Framework contracts to existing Phase 2 CLI / orchestration modules.  
**Non-goals for 3B.3B2:** invoking CLI, SQL import, shadow DB, file restore, maintenance enable, rollback.

---

## Dual-track model

| Track | Job root | Typical start |
|-------|----------|---------------|
| Phase 2 CLI / merge | `{workRoot}/{job_id}/` | `scripts/backup/restore_run_full.php` |
| Phase 3B Admin Framework | `{workRoot}/framework/{job_id}/` | Restore Center → dry-run → plan → final approval → **execution contract** |

The bridge converts an **approved** 3B framework job into a **versioned execution contract** that *describes* which Phase 2 entry would be used later. It does not start that path.

---

## Primary CLI entry points

| Script | Role | Orchestration target |
|--------|------|----------------------|
| `scripts/backup/restore_run_full.php` | Start Full pre-approval E2E (stops at awaiting owner approval) | `orange_restore_e2e_start_full()` |
| `scripts/backup/restore_resume_full.php` | Resume Full E2E after stop points | `orange_restore_e2e_resume_full()` |
| `scripts/backup/restore_status_full.php` | Status report | `orange_restore_orchestrator_e2e_status()` / e2e status |
| `scripts/backup/restore_full_to_staging.php` | Full → staging DB/files only | `orange_restore_full_staging_run()` |
| `scripts/backup/restore_country_to_staging.php` | Country → staging only | `orange_restore_country_staging_run()` |
| `scripts/backup/restore_approve_merge.php` | Approve/reject/cancel for merge | `orange_restore_orchestrator_approve_for_merge` / reject / cancel |
| `scripts/backup/restore_full_database_cutover.php` | DB cutover (mutating) | `orange_restore_orchestrator_database_cutover()` |
| `scripts/backup/restore_full_uploads_cutover.php` | Uploads cutover (mutating) | `orange_restore_orchestrator_uploads_cutover()` |
| `scripts/backup/restore_full_post_validate.php` | Post-cutover validation | `orange_restore_orchestrator_post_validation()` |
| `scripts/backup/restore_full_post_validate_finalize.php` | Finalize post-validation | `orange_restore_orchestrator_post_validation_finalize()` |
| `scripts/backup/restore_full_rollback.php` | Rollback path | `orange_restore_orchestrator_rollback()` |
| `scripts/backup/restore_job_status.php` | Generic job status | restore job helpers |
| `scripts/backup/restore_prepare_backup.php` | 3B framework: pre-restore Full anchor (CLI) | `orange_restore_pre_backup_run_cli()` |
| `scripts/backup/restore_shadow_db.php` | 3B framework: shadow DB import only (CLI; no cutover) | `orange_restore_shadow_run_cli()` |
| `scripts/backup/restore_shadow_verify.php` | 3B framework: shadow readiness verification (CLI; read-only vs prod) | `orange_restore_shadow_verify_run_cli()` |
| `scripts/backup/restore_shadow_files.php` | 3B framework: shadow file extract only (CLI; no rename/cutover) | `orange_restore_shadow_files_run_cli()` |
| `scripts/backup/restore_shadow_smoke.php` | 3B framework: shadow e2e smoke + cutover readiness decision (CLI; no cutover) | `orange_restore_shadow_smoke_run_cli()` |

---

## Core orchestration modules (`includes/backup/restore/`)

| Module | Responsibility |
|--------|----------------|
| `restore_e2e_orchestrator.php` | Full E2E start/resume/status; credential gates |
| `restore_orchestrator.php` | Merge approval, cutover wrappers, maintenance merge helpers |
| `restore_full_staging.php` | Full staging import (non-production) |
| `restore_country_staging.php` | Country staging import |
| `restore_fresh_backup_gate.php` | Mandatory fresh backup / rollback anchor |
| `restore_sql_runner.php` / `restore_sql_safety.php` | SQL apply + safety scans |
| `restore_merge_db_cutover.php` / `restore_merge_uploads_cutover.php` | Production cutover |
| `restore_merge_rollback.php` | Rollback |
| `restore_approval.php` / `restore_reauth.php` | Phase 2 approval / re-auth |

---

## Recommended Full execution profile (future wiring)

1. Fresh backup gate  
2. `restore_full_to_staging` / staging verify  
3. Approval window (Phase 2 merge approval)  
4. Maintenance (Phase 2 merge maintenance — separate from 3B.3B1 framework maint metadata)  
5. DB cutover → uploads cutover → post-validate → finalize  

**Country production** remains disabled at the 3B approval layer (`country_production_restore_not_enabled`).

**Production cutover/rollback contract (Phase 3B.4, design only):** see `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`.

---

## Bridge contract file

Per framework job:

`{workRoot}/framework/{job_id}/restore_execution_contract.json`

Prepared only after `approved_waiting_execution`. Always `execution_started=false` in 3B.3B2.  
Public helpers: `orange_restore_prepare_execution_contract`, `orange_restore_validate_execution_contract`, `orange_restore_load_execution_contract`.
