# Country Recovery Package — Verify Engine (Phase C4)

**Status:** VERIFY ONLY  
**Does not implement:** Country Restore, Import, Rollback, Shadow, or Certification.

## Sources of truth

1. Country Boundary Matrix — `config/country_restore_boundary_matrix.json` (policy C1.1)
2. Country Dependency Graph — package `dependency_graph.json` / matrix restore batches (C2)
3. Country Package Manifest — package `manifest.json` (C3 contract)

## Entry points

| Path | Role |
|------|------|
| `includes/backup/country_crp_verify.php` | Verify engine (`orange_crp_verify_run`) |
| `scripts/backup/verify_country_package.php` | CLI |
| `scripts/backup/self_test_country_crp_c4_verify.php` | Dedicated fixture suite |
| `orange_country_export_verify_package()` | Compatibility wrapper → C4 engine |

## Output

Writes `country_verify_report.json` into the package directory (unless `--no-write-report`).

### Report schema (stable keys)

```json
{
  "report_type": "country_recovery_verify",
  "verify_engine_version": "1.0",
  "generated_at": "ISO-8601",
  "package_path": "...",
  "overall": "PASS | WARNING | FAIL",
  "ok": true,
  "codes": ["stable_failure_code", "..."],
  "warnings": ["stable_warning_code", "..."],
  "checks": [
    { "id": "check_id", "status": "PASS|WARNING|FAIL", "code": null, "detail": "..." }
  ],
  "boundary_policy_version": "C1.1",
  "dependency_graph_version": "C2",
  "country_id": 1,
  "schema_revision": 121,
  "package_fingerprint": "...",
  "project_root": "..."
}
```

- `overall=PASS` — all checks passed  
- `overall=WARNING` — no FAIL; one or more WARNING codes  
- `overall=FAIL` — one or more FAIL codes; `ok=false`

## Validations

- Manifest presence / JSON / required fields / `package_type=country_recovery`
- Boundary policy version (`C1.1`)
- Dependency graph version (`C2`)
- Schema revision (matrix / catalog)
- Country ID (+ health / inventory consistency)
- Package fingerprint (recomputed)
- Checksums
- Inventory (markers, forbidden/unknown tables)
- Uploads allowlist (`uploads_country.zip`)
- Dependency batches vs matrix + graph
- Composite units (admins/permissions, expenses/accounts, voucher slots)
- Special handlers on dependency nodes
- Sequence namespace (`_c{country_id}`)
- Forbidden tables / forbidden SQL absent
- NULL leakage / cross-country leakage absent

## Stable failure codes (non-exhaustive)

| Code | Meaning |
|------|---------|
| `manifest_missing` | No manifest.json |
| `manifest_invalid_json` | Manifest not JSON |
| `manifest_package_type_invalid` | Not `country_recovery` |
| `manifest_field_missing_*` | Required field absent |
| `boundary_policy_version_mismatch` | Not C1.1 |
| `dependency_graph_version_mismatch` | Not C2 |
| `schema_revision_mismatch` | Schema revision mismatch |
| `country_id_invalid` | Non-positive country_id |
| `country_id_mismatch_health` | health.json country mismatch |
| `country_id_mismatch_inventory` | inventory country mismatch |
| `package_fingerprint_missing` | Fingerprint empty |
| `package_fingerprint_mismatch` | Fingerprint recomputation failed |
| `checksums_missing` / `checksum_mismatch` / `checksums_invalid` | Checksum failures |
| `inventory_missing` / `inventory_invalid` | Inventory issues |
| `inventory_forbidden_table_present` | Never-export table listed |
| `inventory_unknown_table` | Table outside mutate set |
| `inventory_other_country_markers` | Markers present |
| `dependency_graph_missing` / `dependency_graph_invalid` | Graph file issues |
| `dependency_batch_missing` / `dependency_batch_mismatch` | Batch contract broken |
| `dependency_order_violation` | Node batch vs batches disagree |
| `composite_admins_permissions_mismatch` | Permissions without admins |
| `composite_expenses_accounts_mismatch` | Expenses without accounts |
| `composite_voucher_slots_mismatch` | Slots without vouchers |
| `special_handler_missing` | Required special handler absent/wrong |
| `sequence_namespace_violation` | Sequences without `_c{N}` |
| `forbidden_table_in_sql` | Forbidden INSERT in SQL |
| `forbidden_sql_present` | Other forbidden SQL patterns |
| `null_leakage_in_sql` | NULL country_id patterns |
| `cross_country_leakage_in_sql` | SQL header country ≠ manifest |
| `uploads_archive_missing` / `uploads_allowlist_violation` / `uploads_zip_unreadable` | Uploads issues |
| `sql_chunks_missing` / `country_sql_gz_missing` / `country_sql_gz_unreadable` | SQL artifact issues |

## Tests

```text
php scripts/backup/self_test_country_crp_c4_verify.php
```

Covers: leakage, wrong country, missing dependency, broken manifest, wrong fingerprint, forbidden table, NULL rows, composite mismatch, sequence violation, uploads mismatch, batch mismatch, node order violation, inventory forbidden table, PASS baseline + report write.

## CLI

```text
php scripts/backup/verify_country_package.php --package=PATH
php scripts/backup/verify_country_package.php --package=PATH --no-write-report
```

Exit `0` for PASS or WARNING; exit `1` for FAIL.
