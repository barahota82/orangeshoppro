# Orange — Disaster Recovery Production Certification (Phase 3B.4G+)

## Purpose

This document records the **non-production** Disaster Recovery (DR) certification posture for Orange **Full Restore**. It does **not** authorize Country production restore.

Machine-readable companion: `docs/backup/restore_dr_certification_report.json`.

Real MySQL/FS clone companion: `docs/backup/real_clone_validation_report.json`.

## Hard rules

- The drill **must not** wipe the real production database.
- The drill **must not** rename real production uploads.
- The drill **must not** enable real production maintenance against live traffic without operator window.
- The drill **must not** mutate real `.env.php` or invoke live payment/email/SMS/webhook integrations.
- Country production restore remains **uncertified** (`country_restore_certified = false`) — **owner decision** to enable later.
- Dual-control / two-person approval remains an **owner policy decision** (see closure report) — not auto-implemented.

## How to run (full gate)

```text
php scripts/backup/run_restore_deployment_preflight.php
php scripts/backup/run_restore_dr_drill.php --mode=all
php scripts/backup/run_restore_real_clone_validation.php
php scripts/backup/run_restore_certification_tests.php
```

Allowed drill modes: `success` | `rollback` | `all` (default `all`). Optional: `--verbose`.

No arbitrary paths, DB names, or package paths are accepted on these CLIs.

## Isolation guarantees

| Fence | Requirement |
|-------|-------------|
| Drill marker | `.orange_restore_dr_drill_fixture` |
| Real clone marker | `.orange_restore_real_clone` |
| Fixture / clone DBs | never `orange_db` |
| Uploads / backup roots | outside production web tree for drills/clones |
| Destructive adapters | fail closed without markers |
| Legacy Phase-2 cutover CLIs | permanent tombstones |

## Post-P0 engineering posture (honest)

The following engineering blockers from Enterprise Audit Round 2 are addressed in code/docs by the Production Certification Closure Sprint:

- Maintenance enforcement wired (admin + storefront mutation APIs) + HTTP smoke suite
- Legacy Phase-2 production CLIs tombstoned + call-site fence test
- Explicit production cutover authorization record
- Real MySQL clone validation including uploads cutover + DB/FS rollback proof
- Framework transition matrix enforced
- Execution lock heartbeat + late-phase active statuses
- Deployment preflight (ZipArchive, extensions, DB/env identity, roots/permissions)
- Certification master runner includes all P0 + closure suites

## Remaining CONDITIONAL reasons (owner / process)

1. **Owner decision:** dual-control / two-person approval (`required_before_production_execution` remains true until owner waives or implements).
2. **Owner decision:** Country production restore stays disabled.
3. Operator must run deployment preflight + certification master on the **target host** before any live window.
4. Live production cutover itself is never claimed by drill/clone reports (`real_production_restore_run=false`).

## Certification thresholds

Full Restore may be marked `CERTIFIED` only when:

1. Success + rollback drills pass with isolation proof.
2. Real clone validation PASS (DB + uploads cutover + rollback).
3. Certification master runner PASS (all suites, including P0/closure).
4. Deployment preflight PASS on target host.
5. Owner dual-control decision recorded (implement or explicit waiver).
6. Country remains disabled unless separately certified.

Otherwise recommendation remains `CONDITIONAL`.

## Evidence artifacts

- `docs/backup/ORANGE_DR_PRODUCTION_CERTIFICATION.md` (this file)
- `docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md`
- `docs/backup/restore_dr_certification_report.json`
- `docs/backup/real_clone_validation_report.json`
- `docs/backup/RESTORE_FW_TRANSITION_MATRIX.md`
- `docs/backup/PRODUCTION_CERTIFICATION_CLOSURE_REPORT.md`
- `scripts/backup/run_restore_certification_tests.php`

## UI

Restore Center shows read-only certification status. HTTP never executes the drill or clone validation.
