# WP-P9-03 — Super Admin Enable/Disable & Schema Invalidation Force-Disable Hooks

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P9-03** — Super Admin Enable/Disable & Schema Invalidation Force-Disable Hooks |
| **Artifact-ID** | `CPR-P9-WP03-ENABLEMENT_ACTIONS` |
| **Phase** | P9 — Enablement |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P9-02 sealed E5; OD-ENABLE; OD-PERM; OD-SCHEMA; P1-13 §7–§8 |
| **Unlocks** | WP-P9-04 Integration Baseline Freeze (Owner approval required) |
| **Scaffold** | `P9-03-enablement-actions` |
| **Flag write** | **ONLY this WP** may change operational enablement (sealed ops state) |
| **Architecture / OWNER_APPROVED** | **Not modified** |
| **Design SoT** | `COUNTRY_PRODUCTION_RESTORE_P1_13_ENABLEMENT_CERT_HOOKS.md` §7–§8 |

---

## 1. Objective

Implement the **Super Admin Enable/Disable engine** and **Schema Invalidation Force-Disable hooks**. This is the **only** Work Package authorized to change the operational enablement flag. Enable is permitted only after sealed E5 prerequisites. Schema invalidation immediately force-disables and moves to E8 with **no auto re-enable**.

No production SQL. No production upload mutation.

---

## 2. Hard rules

- Super Admin only (OD-PERM)  
- Enable only from sealed E5 + Owner enablement order + Cert PASS (OD-ENABLE)  
- Disable from E6 → E7  
- Schema force-disable → E8; flag forced false; checklist incomplete; no auto re-enable (OD-SCHEMA)  
- `automatic` must be false; no replay while E6; no Enable from E8  
- Job / contract / country / fingerprint / schema bound  
- State + checkpoint observed; audit + recovery metadata  
- Fail closed on missing prerequisites / permission / owner order / inconsistency  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_enablement_action_live.php` | `orange_cpr_enablement_action_live_run()` |
| `includes/backup/country_production/cpr_enablement.php` | Sealed ops-state read/write substrate (write used only by P9-03) |
| `includes/backup/country_production/cpr_p9_control_plane.php` | `enablement_action_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_enablement_action_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md` | WP-P9-03 COMPLETE; stop → WP-P9-04 |

### Outputs

- `{job}/enablement/cpr_enablement_action_{enable\|disable\|force_disable}_latest.json`  
- Sealed manifest + report (+ invalidation event for schema force-disable)  
- `{cprRoot}/enablement_ops/cpr_enablement_ops_state_latest.json` — operational flag  
- Audit (`cpr.enable` / `cpr.disable` / `cpr.schema_force_disable`) · recovery metadata  

### State transitions

```text
E5 + SA Enable     → E6_enabled (flag true)
E6 + SA Disable    → E7_disabled_operational (flag false)
E5/E6 + schema FD  → E8_schema_invalidated (flag false; no auto re-enable)
```

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Super Admin Enable/Disable engine implemented | **PASS** |
| AC2 | Schema Invalidation Force-Disable hooks implemented | **PASS** |
| AC3 | Only this WP changes the operational enablement flag | **PASS** |
| AC4 | Enable only after sealed E5 prerequisites | **PASS** |
| AC5 | Integrates E5 / cert / state / checkpoint / recovery / audit / contract / job / schema | **PASS** |
| AC6 | Super Admin only; Owner order required for Enable; fail-closed | **PASS** |
| AC7 | No automatic enablement / no automatic re-enable | **PASS** |
| AC8 | Immediate force-disable on schema invalidation → E8 | **PASS** |
| AC9 | No replay; no privilege bypass; no cross-country | **PASS** |
| AC10 | Sealed enable/disable decisions + manifest + audit + recovery | **PASS** |
| AC11 | No production SQL/uploads; Architecture/OD unchanged | **PASS** |
| AC12 | Self-tests + lint + full CPR suite green | **PASS** |

---

## 5. Stop rule

**WP-P9-03 COMPLETE** (sole sealed writer of the operational enablement flag).  

**Live status (supersedes the original inter-WP freeze text):** WP-P9-04 is **COMPLETE**. Live stop guidance is `COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md` §13 and Project Status — await Owner approval of the FINAL Enterprise Audit before Git Tag / P9 Phase Sign-Off / project closure.

---

*End of CPR-P9-WP03-ENABLEMENT_ACTIONS.*
