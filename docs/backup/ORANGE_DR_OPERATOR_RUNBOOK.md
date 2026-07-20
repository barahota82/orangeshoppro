# Orange — Full Restore Operator Runbook (DR / Live)

Concise checklist for operators. No passwords or absolute private paths.

**Related (ops clarification, no new Owner Decisions):** `docs/backup/GLOBAL_RESTORE_OPERATIONAL_POLICY.md` — when any production Restore enters Maintenance, the entire platform is in Global Maintenance (storefronts / Country Admins / writers suspended; Super Admin Restore Management only until Maintenance release).

## Pre-flight checklist

- [ ] Owner approved the maintenance window
- [ ] `php scripts/backup/run_restore_deployment_preflight.php` → **PASS** on this host
- [ ] Latest commit matches certified tip (or re-run certification after changes)
- [ ] Full package eligible (healthy + DRV pass; ZipArchive required)
- [ ] Mandatory pre-restore Full backup / rollback anchor plan understood
- [ ] Retention pin policy understood (do not unpin during restore)
- [ ] Country restore remains disabled (**owner decision**)
- [ ] Dual-control / two-person policy decided by owner (implement or explicit waiver)
- [ ] External integrations freeze agreed (payments / email / SMS / webhooks)
- [ ] Communication channel for stop / escalate is open

## Exact command order (CLI workers)

Use job id only. Do not pass package paths, DB names, or upload roots.

**Approved production mutation CLIs only:**

1. Discover / verify / DRV (admin Restore Center + existing verify/DRV tools)
2. Create restore job (admin)
3. Dry run
4. Prepare execution plan
5. Final approval challenge + approval
6. Create execution contract
7. Pre-restore Full backup + verify rollback anchor + retention pin
8. Shadow DB restore → verify → shadow files → shadow smoke
9. Cutover-readiness decision
10. Production cutover authorization challenge + finalize (admin; no wipe yet)
11. Maintenance activation (framework) — then prove one blocked storefront write
12. `php scripts/backup/restore_import_production.php --job=JOB_ID`
13. `php scripts/backup/restore_uploads_cutover.php --job=JOB_ID`
14. Success finalize: `php scripts/backup/restore_finalize.php --job=JOB_ID`  
    **or** rollback path below

### Legacy CLIs

Phase-2 cutover scripts are **permanent tombstones** (`legacy_restore_entrypoint_disabled`). Do not use them.

### Rollback decision path

If production import or uploads cutover is unsafe after PONR:

1. Request rollback (admin metadata)
2. `php scripts/backup/restore_rollback.php --job=JOB_ID`
3. Confirm `rollback_ready` + C9–C12
4. `php scripts/backup/restore_finalize.php --job=JOB_ID` → `rollback_completed`

Maintenance stays **ON** until finalize releases it.

## Failed-state → allowed next action

| Status / symptom | Allowed next CLI / action | Stop if |
|------------------|---------------------------|---------|
| `pre_restore_backup_failed` | Re-run prepare backup path / fix disk | Anchor missing |
| `shadow_*_failed` | Re-run corresponding shadow CLI | Identity mismatch |
| `production_import_failed` before wipe complete | Resume import CLI per engine gates | Wrong DB identity |
| Mid-import after wipe | Resume import **or** escalate; do not open site | Lock/heartbeat issues |
| `uploads_cutover_failed` mid-rename | Resume uploads cutover (reconcile) | Unreconciled trees |
| `uploads_cutover_ready` unsafe | Rollback CLI | — |
| DB rollback OK, files rollback fail | Re-run rollback CLI (files stage); keep maint ON | Improvised manual renames |
| `rollback_failed` | Resume rollback from highest checkpoint | Anchor deleted |
| Finalize crash while `*_FINALIZING` | Re-run finalize CLI | — |
| Stale execution lock, PID dead | Documented stale clear via orchestrator acquire | Heartbeat fresh + PID alive |

## Framework status transitions

Illegal status jumps are rejected (`illegal_framework_status_transition`). See `RESTORE_FW_TRANSITION_MATRIX.md`.

## Expected outputs

- Import CLI: import report + checkpoints C0–C6 (and related)
- Uploads cutover CLI: C7–C8, `uploads_cutover_ready`
- Finalize CLI: `RESTORE_COMPLETED` or `ROLLBACK_COMPLETED`, maintenance released, execution lock released
- Drill CLI: `DR_DRILL_RESULT: PASS|FAIL`
- Real clone CLI: `REAL_CLONE_VALIDATION_RESULT: PASS|FAIL` (includes uploads cutover/rollback)
- Preflight CLI: `DEPLOYMENT_PREFLIGHT: PASS|FAIL`

## Certification commands (non-production)

```text
php scripts/backup/run_restore_deployment_preflight.php
php scripts/backup/run_restore_dr_drill.php --mode=all
php scripts/backup/run_restore_real_clone_validation.php
php scripts/backup/run_restore_certification_tests.php
```

Master runner must execute **all** listed suites (including P0 + closure). Partial runs must not be treated as certification.

## Stop conditions

Stop immediately and escalate if:

- Wrong DB identity / production path rejection fires
- Deployment preflight FAIL
- Rollback anchor missing or verify fails
- Retention pin missing
- Maintenance activation fails while orchestration already holds locks
- Partial uploads rename cannot be reconciled
- Any unexpected production write outside the documented job
- Execution lock cleared while heartbeat is fresh / PID alive

## Rollback decision points

| Point | Action |
|-------|--------|
| Before maintenance | Cancel job; no production wipe/rename |
| After maint, before C3 wipe | Abort safely; release via documented failed-path procedures |
| After C3 / during import | Resume per documented re-wipe/re-import rules **or** escalate |
| After uploads cutover ready | Prefer rollback engine + finalize rollback |
| After finalize success | Do **not** re-run cutover; open a new job if needed |

## Emergency escalation

1. Keep maintenance active until a controlled finalize/release decision
2. Preserve forensic artifacts (reports, checkpoints, audit)
3. Do not delete rollback anchors or remove retention pins
4. Contact owner + on-call operator; do not improvise DB restores from shadow

## Artifacts to preserve

- Job reports: import, uploads cutover, rollback, finalize
- Checkpoints C0–C12 as applicable
- Pre-restore backup record + pinned Full package
- Framework audit / execution contract / approval / cutover authorization records
- Certification JSON + real clone report if this was a drill/clone

## Actions prohibited during maintenance

- Normal storefront/admin writes (enforcement must block mutations)
- Manual SQL against production outside the CLI engines
- Deleting `uploads_pre_merge_*`, anchors, or pins
- Running Country production restore
- Breaking execution locks without documented stale-lock procedure
- Using Phase-2 tombstone CLIs

## Retention pin policy

Pinned Full packages for rollback anchors must not be pruned by normal retention while a restore job is active or until owner-approved unpin after finalize.
