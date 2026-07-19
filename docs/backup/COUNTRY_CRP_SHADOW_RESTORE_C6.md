# Country Recovery Package — Shadow Restore (Phase C6)

**Status:** Country Shadow Restore ONLY  
**Does not implement:** Country Production Restore, Import into production, Rollback, Certification, Maintenance, or Production Enablement.

**Confirmation:** `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED = false`.

## Goal

Restore a verified Country Recovery Package into an **isolated Country Shadow database** only. Never modify production. Never modify another country.

## Sources of truth

- Frozen Country Boundary Policy (C1.1)
- Country Dependency Graph / restore batches 1→6 (C2)
- C4 Verify (`country_verify_report.json` overall PASS)
- C5 Country DRV (`{packageId}.country_recovery_validation.json` overall pass, score ≥ 85)

## Entry conditions

Reject when any of:

- Verify missing / not PASS
- Country DRV missing / not PASS
- Package not finalized
- Fingerprint changed after Verify
- Schema / boundary / dependency / backend incompatible
- Required PHP archive extensions missing

## Shadow strategy

| Item | Value |
|------|--------|
| Target DB | `ORANGE_RESTORE_COUNTRY_SHADOW_DB` (default `orange_country_shadow`) |
| Credentials | Staging restore credentials (never production DSN) |
| Safety | Before every destructive step: session DB ≠ production and session DB = shadow |
| Clear | DELETE mutate tables in reverse batch order (shadow sandbox only) |
| Import | SQL chunks in restore batches **1→6** (matrix + dependency graph) |
| Scope | Country Scoped + Mixed exportable tables only; never Global / Full-only / `journal_entries` |

## States

```
country_shadow_restore_pending
  → running
  → verifying
  → ready
  → failed
```

Stored in `country_shadow_restore.json` under `{workRoot}/country_shadow/{run_id}/`.

## Verification (post-import)

- Row counts vs inventory
- Ownership / `country_id` leakage
- Composite units (admins/permissions, expenses/accounts, GL vouchers/lines)
- Forbidden tables remain unpopulated
- Batch integrity
- Soft FK checks where applicable

## Report

`country_shadow_restore_report.json` in the run directory.

Includes: status, overall_result, country_id, shadow_db, checks, blocking codes, `production_touched=false`, `execution_performed=false`, `country_production_restore_enabled=false`.

## CLI

```bash
php scripts/backup/restore_country_shadow.php --package=YYYY-MM-DD_HHMMSS
php scripts/backup/restore_country_shadow.php --package=YYYY-MM-DD_HHMMSS --country=kw
```

CLI only. Package-ID allowlist. No arbitrary paths.

## HTTP

`GET admin/api/restore/country-shadow-status.php?run_id=cc_YYYY-MM-DD_HHMMSS`

Status only. **No execution.**

## Tests

```bash
php scripts/backup/self_test_country_crp_c6_shadow.php
```

Fixture databases / mocks only. Never production.
