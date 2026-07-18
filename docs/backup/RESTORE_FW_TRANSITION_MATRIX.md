# Restore Framework Status Transition Matrix

| Field | Value |
|-------|--------|
| Version | `3B.4M-fw-transition-v1` |
| Code authority | `includes/backup/restore/restore_fw_transition_matrix.php` |
| Enforced by | `orange_restore_fw_transition()` in `restore_job_framework.php` |

## Rules

1. Same-status transitions are always allowed (message/progress refresh).
2. Empty/new job may only enter `queued`.
3. Happy-path and resume edges are encoded as chains + extra edges in code.
4. Non-terminal statuses may enter global escapes: `cancelled`, `failed`, `execution_cancelled`, `execution_failed`, `cutover_readiness_blocked`.
5. Terminal statuses (`restore_completed`, `rollback_completed`, `execution_completed`, `completed`, `cancelled`, `failed`, `execution_*`) may not leave except same-status.

## Happy path (Full success)

`queued` → `preparing` → `waiting_confirmation` → `dry_running` → `dry_completed` → `execution_precheck` → `execution_plan_ready` → `awaiting_final_approval` → `approved_waiting_execution` → pre-restore backup lane → shadow DB/verify/files/smoke → `cutover_readiness_ready` → maintenance lane → production import lane → uploads cutover lane → `restore_finalizing` → `restore_completed` → (`execution_completed` → `completed`)

## Rollback branch

From `uploads_cutover_ready` (or `production_import_ready`) → `rollback_pending` → DB/files rollback statuses → `rollback_ready` → `rollback_finalizing` → `rollback_completed`

## Operator note

Illegal jumps throw `illegal_framework_status_transition:from=>to`. Do not bypass via direct `job.json` edits.

## Tests

`scripts/backup/self_test_restore_fw_transition_matrix.php`
