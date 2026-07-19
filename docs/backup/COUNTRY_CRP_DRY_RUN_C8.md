# Country Recovery Package — Dry Run & Impact Simulation (Phase C8)

**Status:** Country Dry Run ONLY  
**Does not implement:** Country Production Restore, Import, Rollback, Maintenance, Approval, Certification, or Production Enablement.

**Confirmation:** `ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED = false`.  
Simulation sets `production_db_writes = 0`, `shadow_db_writes = 0`, `execution_performed = false`.

## Goal

Answer without mutating any database:

1. What **will** change in the target country?
2. What **must** remain unchanged outside the target country?
3. Is the restore operationally **safe**?

## Sources of truth

- Frozen Country Boundary Policy (C1.1)
- Country Dependency Graph (C2)
- C4 Verify, C5 Country DRV
- C6 Shadow Restore, C7 Shadow Verification (**READY** required)

## Entry conditions

Reject unless all hold:

- C7 `overall_result = READY`
- C7 `readiness_score >= 90`
- `survivor_country_integrity = PASS`
- `global_state_integrity = PASS`
- Package fingerprint unchanged vs C7 / Verify
- Boundary + dependency versions unchanged
- `execution_performed = false`

## Simulation rules

- **No** production writes  
- **No** shadow writes  
- **No** imports / deletes  
- **F-04 / EA-04:** Impact requires a **certified read-only production inventory** (`production_inventory_snapshot.json` with `certified_read_only=true`, or inject / gated live SELECT counts). Missing → `production_inventory_snapshot_missing`.  
- Rows to delete/replace use **production target counts** from that inventory (not C6 shadow counts alone). Package inventory drives inserts.  
- Outside-target impacts are **proven** from inventory + restore plan (`outside_target_impact_proof`). Missing survivor/global inventory keys fail closed (non-zero impact), never silent defaults.
- **N3-04 (engine 1.3):** Proof is explicit from **restore plan + certified inventory** (enumerated survivor/global tables + row totals, plan exclusion flags). `simulation_execution=false` — does **not** simulate production execution. Proof method: `restore_plan_plus_certified_inventory`.  

Predicted impacts that must stay **zero** for SAFE:

- survivor-country rows  
- Global tables  
- `journal_entries`  
- Full-only / never-export tables  

## Report

`{countryShadowWorkDir}/country_dry_run_report.json`

Key fields:

| Field | Meaning |
|-------|---------|
| `overall_result` | `SAFE` \| `WARNING` \| `FAIL` |
| `rows_to_replace` / `rows_to_delete` / `rows_to_insert` | Target-country row impact |
| `uploads_to_replace` / `uploads_to_add` | Upload reference impact (no cutover) |
| `composite_units` | A–H unit status from C7 |
| `special_handlers` | e.g. sequences, admins composite |
| `estimated_duration` | Heuristic duration |
| `survivor_country_impact` / `global_impact` | Must be `0` for SAFE |
| `production_inventory_source` | `certified_snapshot` \| `inject` \| `live_read_only` |
| `production_target_row_total` | Sum of production target-country row counts |
| `blocking_reason_codes` | Stable FAIL codes |
| `simulation_only` | Always `true` |

## Scoring

| Result | Rule |
|--------|------|
| **SAFE** | No blockers; survivor/global/journal/full-only impact = 0; no warnings |
| **WARNING** | No blockers; zero contamination impacts; documented non-blocking warnings only |
| **FAIL** | Any predicted Global / survivor / journal_entries / Full-only mutation, leakage, sequence collision, accounting/FIFO/composite failure, unresolved ownership, or failed entry |

## States

```
(C7 country_shadow_verified)
  → country_dry_run_running
  → country_dry_run_safe | country_dry_run_warning | country_dry_run_failed
```

No production execution state.

## CLI

```bash
php scripts/backup/country_dry_run.php --job=cc_YYYY-MM-DD_HHMMSS
```

CLI only. Job ID only. Non-zero exit unless `SAFE`.

## HTTP / UI

- `GET admin/api/restore/country-dry-run-status.php?job_id=…`
- Restore Center: read-only Country Dry Run (C8) summary panel

No Import / Restore / Execute / Approval / Maintenance / Rollback / Production enablement controls for Country.
