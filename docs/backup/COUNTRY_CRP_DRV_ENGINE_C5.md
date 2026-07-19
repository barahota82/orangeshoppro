# Country Recovery Package — Disaster Recovery Validation (Phase C5)

**Status:** Country DRV ONLY  
**Does not implement:** Country Restore, Import, Rollback, Shadow Restore, Production Enablement, or Certification.

**Confirmation:** `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED = false`. Country Restore remains disabled and uncertified.

## Verify vs DRV

| Layer | Responsibility |
|-------|----------------|
| **C4 Verify** | Package integrity: manifest, fingerprint, checksums, forbidden SQL, inventory, batches, composites markers |
| **C5 Country DRV** | Operational recoverability under country isolation: boundary, collisions, accounting/stock graphs, uploads/sequences recoverability, rollback-anchor readiness |

DRV **consumes** `country_verify_report.json`. It does **not** re-implement Verify. Entry rejects missing Verify, Verify FAIL, fingerprint drift after Verify, and version/country/schema inconsistencies.

## Pipeline

1. Resolve allowlisted package ID under `BackupRoot/country_packages/{cc}/{packageId}/`
2. Require finalized package id (`YYYY-MM-DD_HHMMSS`)
3. Load C4 Verify report → reject if missing/FAIL
4. Confirm fingerprint + boundary/dependency/schema/country identity
5. Run DRV checks 1–10
6. Score → write `{packageId}.country_recovery_validation.json` beside package
7. `execution_performed` always `false`

## Report schema

Sibling file: `{packageId}.country_recovery_validation.json`

Required fields: `validated_at`, `package_type=country`, `country_id`, `schema_revision`, `package_version`, `boundary_policy_version`, `dependency_graph_version`, `verify_engine_version`, `validation_engine_version`, `verify_result`, ten `*_valid` booleans, `overall_result` (`pass|warning|fail`), `recovery_score` (0–100), `checks[]`, `warnings[]`, `errors[]`, `blocking_reason_codes[]`, `execution_performed=false`.

No absolute paths, credentials, raw SQL, or personal data.

## Country-specific scoring

**Do not use Full DRV’s exit threshold of 70 as a Country pass gate.**

| Band | overall_result | Score | Meaning |
|------|----------------|-------|---------|
| Blocker present | `fail` | 0–69 | Isolation/recoverability not proven |
| No blockers, warnings only | `warning` | 70–84 | Recoverable with caveats; warnings never mask blockers |
| All flags valid, no warnings | `pass` | 85–100 | Country-isolated recoverability validated |

**Pass threshold:** `recovery_score >= 85` and `overall_result=pass`.

Rationale: Country DRV must prove **non-interference with other countries**. A weaker Full-style “warning≥70 exits 0” would understate cross-country risk. Country CLI exits non-zero on warning/fail.

Hard FAIL (blockers) include: cross-country leakage, Global/Full-only mutation requirement, unresolved PK/unique/admin/sequence/product collisions, `accounting_boundary_not_proven`, incomplete stock/FIFO, missing composite member, environment incompatibility.

## Collision policy

- Compare package `id_snapshot` / declared IDs against optional survivor index (fixture or future read-only probe).
- Detect PK, unique, sequence, shared-account, admin, product/variant collisions.
- **Never** resolve collisions silently — emit stable blocker codes.

## Accounting boundary policy (D6)

- `journal_entries` must remain absent.
- Vouchers/lines/slots must restore without shared `journal_entries`.
- Accounts must belong to target country graph.
- If unproven → `accounting_boundary_not_proven`.

## Stock / FIFO policy

- Warehouses and stock rows must be target-country owned.
- Layers + consumptions must be complete; over-consumption is a blocker.
- Legacy mirror differences → warning only (`legacy_mirror_difference`).

## Rollback-anchor requirement

Country DRV states (assessment only; **no backup created**):

1. Mandatory **Full** rollback anchor will be required before production Country restore
2. Country package alone is **not** the rollback source
3. Package is compatible with Country tear-down + Full-anchor rollback strategy

## CLI

```bash
php scripts/backup/validate_country_recovery.php --package=YYYY-MM-DD_HHMMSS
php scripts/backup/validate_country_recovery.php --package=YYYY-MM-DD_HHMMSS --country=kw
```

## API / UI

Read-only: Country package listing surfaces Verify + Country DRV report viewers and score fields. No Restore / Import / Shadow / Execute / Approval / Rollback buttons added by C5.

## Tests

```bash
php scripts/backup/self_test_country_crp_c5_drv.php
```
