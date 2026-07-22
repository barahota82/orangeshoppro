# WP-P9-02 — Enablement Preconditions & Owner Enablement Order

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P9-02** — Enablement Preconditions & Owner Enablement Order |
| **Artifact-ID** | `CPR-P9-WP02-ENABLEMENT_PRECONDITIONS` |
| **Phase** | P9 — Enablement |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P9-01; sealed P8 `cpr_certification_result` PASS; OD-ENABLE; P1-13 §6 |
| **Unlocks** | WP-P9-03 Super Admin Enable/Disable (Owner approval required) |
| **Scaffold** | `P9-02-enablement-preconditions` |
| **Enablement** | **FALSE** (hard — E5 does not flip the ops flag) |
| **Architecture / OWNER_APPROVED** | **Not modified** |
| **Design SoT** | `COUNTRY_PRODUCTION_RESTORE_P1_13_ENABLEMENT_CERT_HOOKS.md` §6 |

---

## 1. Objective

Implement the **Enablement Preconditions engine only**, including complete **Owner Enablement Order** validation. Seal `cpr_enablement_preconditions` and `cpr_owner_enablement_order` when all four OD-ENABLE preconditions are verified against sealed Owner Certification PASS. Reach `E5_preconditions_satisfied` with the ops flag still **FALSE**.

Do **not** execute Super Admin Enable. Do **not** write the enablement flag true.

---

## 2. Hard rules

- Consume sealed Owner Certification Result (`result=PASS`, `decided_by=owner`) only  
- Validate Owner enablement order per P1-13 §6.4 (`issued_by=owner`, directive `ENABLE_COUNTRY_PRODUCTION_RESTORE`, sealed)  
- Require `implementation_completed` + `final_enterprise_approval` (+ id)  
- Schema revision must match contract / cert / order  
- Job identity, execution contract, country, package fingerprint bound  
- State engine + checkpoint engine observed; audit + recovery metadata written  
- Fail closed on missing / corrupt / mismatched / partial prerequisites  
- No replay / duplicate seal; no privilege bypass; no cross-country  
- No automatic enablement; E5 ≠ enable  
- Enablement remains FALSE; no production SQL/uploads  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_enablement_preconditions_live.php` | `orange_cpr_enablement_preconditions_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_ENABLEMENT_DIRNAME`; scaffold |
| `includes/backup/country_production/cpr_p9_control_plane.php` | `enablement_preconditions_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_enablement_preconditions_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md` | WP-P9-02 COMPLETE; stop → WP-P9-03 |

### Outputs under `{job}/enablement/`

- Sealed `preconditions` (`cpr_enablement_preconditions/1`)  
- Sealed `order` (`cpr_owner_enablement_order/1`)  
- Sealed `manifest` + sealed `report`  
- Audit events · recovery metadata · fingerprints  

### Consumed sealed inputs

- `{job}/certification/cpr_owner_cert_result_latest.json` (PASS)  
- Job + frozen execution contract  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Enablement Preconditions engine implemented | **PASS** |
| AC2 | Owner Enablement Order validated per P1-13 §6.4 | **PASS** |
| AC3 | All four OD-ENABLE prerequisites verified (cert PASS, Owner order, impl complete, Final Enterprise approval) | **PASS** |
| AC4 | Integrates certification result / state / checkpoint / recovery / audit / contract / job / schema / permissions | **PASS** |
| AC5 | Rejects missing/corrupt/cert mismatch/schema mismatch/permission mismatch/replay; fail-closed | **PASS** |
| AC6 | Sealed preconditions report + sealed enablement manifest + sealed Owner order | **PASS** |
| AC7 | Audit events + recovery metadata integrity | **PASS** |
| AC8 | No privilege bypass; no cross-country; no partial enablement; no automatic enablement | **PASS** |
| AC9 | Enablement remains FALSE after E5 seal; no production SQL/uploads | **PASS** |
| AC10 | Architecture / OWNER_APPROVED unchanged; no WP-P9-03 Enable action | **PASS** |
| AC11 | Self-tests + PHP lint + full CPR suite green | **PASS** |

---

## 5. Stop rule

**WP-P9-02 COMPLETE** (delivered; enablement flag remained FALSE).  

**Live status (supersedes the original inter-WP freeze text):** WP-P9-03 and WP-P9-04 are **COMPLETE**. Live stop guidance is `COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md` §13 and Project Status — await Owner approval of the FINAL Enterprise Audit before Git Tag / P9 Phase Sign-Off / project closure.  
Do **not** flip enablement outside the WP-P9-03 sealed ops-state path.

---

*End of CPR-P9-WP02-ENABLEMENT_PRECONDITIONS.*
