# WP-P8-03 — Owner Certification Decision (PASS / FAIL)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P8-03** — Owner Certification Decision (PASS/FAIL) & `cpr_certification_result` |
| **Artifact-ID** | `CPR-P8-WP03-OWNER_CERT_DECISION` |
| **Phase** | P8 — Country Production certification |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P8-02 sealed Owner Submission; OD-CERT; P1-13; P2-05 §8; P2-02 CG-H*/CG-F01 |
| **Unlocks** | WP-P8-04 Integration / Certification Baseline Freeze (Owner approval required) |
| **Scaffold** | `P8-03-owner-cert-decision` |
| **Enablement** | **FALSE** (hard; PASS does not enable) |
| **Architecture / OWNER_APPROVED** | **Not modified** |
| **Design SoT** | `COUNTRY_PRODUCTION_RESTORE_P1_13_ENABLEMENT_CERT_HOOKS.md` · `COUNTRY_PRODUCTION_RESTORE_P2_05_OWNER_DECISION_PACKAGE.md` §8 |

---

## 1. Objective

Implement the **Owner Certification decision engine only**. Execute exclusively against a sealed Owner Submission Package. Record a strict Owner **PASS** or **FAIL** via the approved Owner certification ceremony (CG-H01…H06 + CG-F01). Produce sealed decision, sealed manifest, certification fingerprints, audit events, recovery metadata, and sealed `cpr_certification_result`.

---

## 2. Hard rules

- Certification only from a valid sealed Owner Submission package  
- Result strictly `PASS` \| `FAIL` — no intermediate certification states  
- PASS and FAIL mutually exclusive  
- Decision recorded exactly once — no replay / no duplicate  
- No automatic approval or automatic rejection  
- Owner ceremony required (`owner_certification_ceremony`, `decided_by=owner`, CG-H*, CG-F01, rationale)  
- Engineering cannot decide (`decided_by != owner` rejected; PASS with non-owner rejected)  
- No privilege bypass; no cross-country; fail-closed  
- Enablement remains FALSE — recording PASS does **not** enable production  
- Recording FAIL does **not** trigger automatic rollback  
- No production SQL / upload mutation  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_owner_cert_decision_live.php` | `orange_cpr_owner_cert_decision_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_CERTIFICATION_DIRNAME`; scaffold |
| `includes/backup/country_production/cpr_p8_control_plane.php` | `owner_cert_decision_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_owner_cert_decision_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md` | WP-P8-03 COMPLETE; stop → WP-P8-04 |

### Outputs under `{job}/certification/`

- Sealed Owner decision (`cpr_owner_cert_decision_latest.json`)  
- Sealed certification manifest  
- Sealed `cpr_certification_result` (`cpr_owner_cert_result_latest.json`)  
- Sealed decision report  
- Certification / decision fingerprints · audit · recovery metadata  

### Consumed sealed inputs

- `{job}/owner_submission/` sealed package + manifest (WP-P8-02)  
- Frozen execution contract · job identity · country binding  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Owner Certification decision engine implemented | **PASS** |
| AC2 | Executes only against sealed Owner Submission | **PASS** |
| AC3 | Result strictly PASS or FAIL; mutually exclusive; ceremony required | **PASS** |
| AC4 | Integrates state / checkpoint / recovery / audit / contract / job / country | **PASS** |
| AC5 | Rejects missing/corrupt/replay/duplicate/contract/country; fail-closed | **PASS** |
| AC6 | Sealed decision + sealed manifest + sealed `cpr_certification_result` | **PASS** |
| AC7 | Certification fingerprints + audit + recovery metadata | **PASS** |
| AC8 | No privilege bypass; no cross-country; engineering cannot decide | **PASS** |
| AC9 | Enablement FALSE; PASS does not enable; FAIL does not auto-rollback | **PASS** |
| AC10 | Architecture / OWNER_APPROVED unchanged; no production SQL/uploads | **PASS** |
| AC11 | Self-tests + PHP lint + full CPR suite green | **PASS** |

---

## 5. Stop rule

**WP-P8-03 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P8-04** until Owner explicitly reviews and approves the next Work Package.

---

*End of CPR-P8-WP03-OWNER_CERT_DECISION.*
