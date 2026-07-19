# Country Recovery Package Export Engine (Phase C3)

**Status:** EXPORT ONLY — no Country Restore / import / rollback / shadow / certification  
**Date:** 2026-07-19  
**Boundary SoT:** `COUNTRY_RESTORE_BOUNDARY_POLICY.md` (C1.1)  
**Classification SoT:** `COUNTRY_BOUNDARY_VALIDATION.md` (D1)  
**Dependency SoT:** `COUNTRY_DEPENDENCY_GRAPH.md` (C2)  
**Runtime matrix:** `config/country_restore_boundary_matrix.json`

## Package layout

```
{countryCode}/{timestamp}/
  country.sql.gz              # required (concatenated SQL)
  files/uploads_country.zip   # required (country uploads only)
  manifest.json               # required
  table_inventory.json        # required
  dependency_graph.json       # required
  checksums.sha256
  health.json
  id_snapshot.json
  sql/*.sql                   # chunked SQL (staging-compatible)
```

## Manifest fields (C3)

- `country_id`, `schema_revision`, `package_version` (2.0)
- `boundary_policy_version` (C1.1), `dependency_graph_version` (C2)
- `restore_batches`, `package_fingerprint`
- `export_time`, `drv_version`, `verify_version`

## Never exported

- All Global tables  
- `journal_entries` (D6)  
- `orange_country_screen_copy_log` (D5)

## CLI

```bash
php scripts/backup/export_country.php --country-id=N
php scripts/backup/self_test_country_crp_c3_export.php
```

## Explicit non-goals

No Country production restore. Boundary remains frozen for design; Country Restore is not certified or enabled.
