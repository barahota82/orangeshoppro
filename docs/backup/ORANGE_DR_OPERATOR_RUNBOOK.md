# Orange — Full Restore Operator Runbook (DR / Live)

Concise checklist for operators. No passwords or absolute private paths.

## Pre-flight checklist

- [ ] Owner approved the maintenance window
- [ ] Latest commit matches the certified drill commit (or re-drill after changes)
- [ ] Full package eligible (healthy + DRV pass)
- [ ] Mandatory pre-restore Full backup / rollback anchor plan understood
- [ ] Retention pin policy understood (do not unpin during restore)
- [ ] Country restore remains disabled
- [ ] External integrations freeze agreed (payments / email / SMS / webhooks)
- [ ] Communication channel for stop / escalate is open

## Exact command order (CLI workers)

Use job id only. Do not pass package paths, DB names, or upload roots.

1. Discover / verify / DRV (admin Restore Center + existing verify/DRV tools)
2. Create restore job (admin)
3. Dry run
4. Prepare execution plan
5. Final approval challenge + approval
6. Create execution contract
7. Pre-restore Full backup + verify rollback anchor + retention pin
8. Shadow DB restore → verify → shadow files → shadow smoke
9. Cutover-readiness decision
10. Maintenance activation (framework)
11. `php scripts/backup/restore_import_production.php --job=JOB_ID`
12. `php scripts/backup/restore_uploads_cutover.php --job=JOB_ID`
13. Success finalize: `php scripts/backup/restore_finalize.php --job=JOB_ID`  
    **or** rollback path below

### Rollback decision path

If production import or uploads cutover is unsafe after PONR:

1. Request rollback (admin metadata)
2. `php scripts/backup/restore_rollback.php --job=JOB_ID`
3. Confirm `rollback_ready` + C9–C12
4. `php scripts/backup/restore_finalize.php --job=JOB_ID` → `rollback_completed`

### DR certification (non-production only)

```text
php scripts/backup/run_restore_dr_drill.php --mode=all
php scripts/backup/run_restore_certification_tests.php
```

## Expected outputs

- Import CLI: import report + checkpoints C0–C6 (and related)
- Uploads cutover CLI: C7–C8, `uploads_cutover_ready`
- Finalize CLI: `RESTORE_COMPLETED` or `ROLLBACK_COMPLETED`, maintenance released, execution lock released
- Drill CLI: `DR_DRILL_RESULT: PASS|FAIL`, writes `docs/backup/restore_dr_certification_report.json`

## Stop conditions

Stop immediately and escalate if:

- Wrong DB identity / production path rejection fires
- Rollback anchor missing or verify fails
- Retention pin missing
- Maintenance activation fails while orchestration already holds locks
- Partial uploads rename cannot be reconciled
- Any unexpected production write outside the documented job

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
- Framework audit / execution contract / approval records
- Certification JSON if this was a drill

## Actions prohibited during maintenance

- Normal storefront/admin writes (once middleware is wired)
- Manual SQL against production outside the CLI engines
- Deleting `uploads_pre_merge_*`, anchors, or pins
- Running Country production restore
- Breaking execution locks without documented stale-lock procedure

## Post-restore monitoring

- Health endpoint / admin login / sample catalog read
- Order intake / payment idle state as agreed for the window
- Confirm maintenance released only after successful finalize
- Confirm retention pin still present until owner retention policy allows unpin

## Retention-pin handling policy

- Pin is created for the mandatory pre-restore Full anchor
- Pin survives success finalize and rollback finalize
- Normal retention must not prune a pinned package
- Unpin only after owner sign-off and a later successful retention review — never during an active restore/rollback job
