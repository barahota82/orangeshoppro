# WP-P5-04 — Special Handlers Engine

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P5-04** — Special Handlers Engine |
| **Artifact-ID** | `CPR-P5-WP04-SPECIAL_HANDLERS` |
| **Phase** | P5 — Production Apply |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P5-03 COMPLETE (CP7 / IMPORT) |
| **Unlocks** | WP-P5-05 Country Uploads Apply |
| **Scaffold** | `P5-04-special-handlers` |
| **Enablement** | **FALSE** (hard) — no production SQL |
| **Architecture / OWNER_APPROVED** | **Not modified** |

---

## 1. Objective

Implement the Special Handler **framework** as a dedicated layer after Target-Slice IMPORT. Keep the IMPORT engine generic and unchanged. All exceptional restore behavior (sequences, composites, resolvers) executes exclusively through this layer per the approved C1.1 special-handler list.

---

## 2. Frozen handler catalog

Executable order (`c1.1-special_handlers/1`):

1. `admins_permissions_composite`
2. `expenses_via_accounts`
3. `polymorphic_company_documents`
4. `gl_voucher_slots_country`
5. `seq_country_namespace` (last; never lower counters)

Excluded (must refuse): `full_only_journal_entries`, `ignore_screen_copy_log`.

Source: `COUNTRY_RESTORE_BOUNDARY_POLICY.md` §4 · `COUNTRY_DEPENDENCY_GRAPH.md` §3.

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_special_handlers_catalog.php` | Frozen handler IDs / order / deps |
| `includes/backup/country_production/cpr_special_handlers_live.php` | Live framework `orange_cpr_special_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_SPECIAL_LIVE_DIRNAME`; scaffold bump |
| `includes/backup/country_production/cpr_p5_control_plane.php` | `special_handlers_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_special_handlers_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md` | WP-P5-04 COMPLETE |

**IMPORT engine:** not modified (remains generic batch 1→6 importer).

### Integration

- Requires DELETE (CP6) + IMPORT summary (CP7)
- State remains `cpr_importing` (CP8 allowed); failure → pause via T31
- Resume from last sealed handler boundary
- Final sealed report + mutation manifest + **CP8** (`handlers`, `counters_not_lowered_ack=true`)
- Uploads remain disabled (no T11)

### Enablement FALSE

Mutations apply to `special_handlers/special_handlers_ledger.json` with `production_sql_executed=false`.

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Special Handler framework implemented | **PASS** |
| AC2 | IMPORT engine unchanged / remains generic | **PASS** |
| AC3 | Exceptional behavior only via Special Handler layer | **PASS** |
| AC4 | Handlers only from approved catalog; excluded refused | **PASS** |
| AC5 | Deterministic order + dependency ordering | **PASS** |
| AC6 | Integrates DELETE/IMPORT/State/Checkpoint/Lock/Gate/Authority/Contract/Job | **PASS** |
| AC7 | Sealed report + mutation manifest + audit + recovery metadata + CP8 | **PASS** |
| AC8 | Fail-closed; no privilege bypass; no replay; no out-of-slice/cross-country | **PASS** |
| AC9 | Resume from sealed handler checkpoint | **PASS** |
| AC10 | Enablement FALSE; no production SQL; uploads disabled | **PASS** |
| AC11 | Architecture / OWNER_APPROVED unchanged | **PASS** |
| AC12 | Self-tests + lint + full CPR suite green | **PASS** |
| AC13 | P5 Artifact Index WP-P5-04 COMPLETE | **PASS** |

---

## 5. Stop rule

**WP-P5-04 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P5-05** until Owner review and approval.

---

*End of WP-P5-04.*
