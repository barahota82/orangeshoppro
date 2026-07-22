# WP-P8-02 — Owner Submission Package Assembly (P2-05 / Sealed P7 Evidence)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P8-02** — Owner Submission Package Assembly |
| **Artifact-ID** | `CPR-P8-WP02-OWNER_SUBMISSION` |
| **Phase** | P8 — Country Production certification |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P8-01; sealed P7 Evidence Pack + Drill Reports + Integration Freeze; P2-05 |
| **Unlocks** | WP-P8-03 Owner Certification Decision (Owner approval required) |
| **Scaffold** | `P8-02-owner-submission` |
| **Enablement** | **FALSE** (hard) |
| **Architecture / OWNER_APPROVED** | **Not modified** |
| **Design SoT** | `COUNTRY_PRODUCTION_RESTORE_P2_05_OWNER_DECISION_PACKAGE.md` |

---

## 1. Objective

Implement the **Owner Submission Package assembly engine only**. Assemble and seal the P2-05 submission package exclusively from approved sealed P7 artifacts. Do **not** generate certification decisions. Do **not** evaluate or grant Owner Cert PASS/FAIL.

---

## 2. Hard rules

- Sealed sources only: Evidence Pack · Drill Reports · P7 Integration Freeze  
- Reject missing / stale / corrupt / modified / replayed evidence  
- Deterministic P2-05 §5.1 section order  
- No privilege bypass; no cross-country assembly; fail-closed  
- Enablement FALSE; no production SQL/uploads  
- Engineering recommendation may be emitted with `is_certification_decision=false` only  
- Owner decision blank remains `PENDING` / `owner_decision_present=false`  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_owner_submission_live.php` | `orange_cpr_owner_submission_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_OWNER_SUBMISSION_DIRNAME`; scaffold |
| `includes/backup/country_production/cpr_p8_control_plane.php` | `owner_submission_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_owner_submission_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md` | WP-P8-02 COMPLETE; stop → WP-P8-03 |

### Outputs under `{job}/owner_submission/`

- Sealed `package` + sealed `manifest` latest pointers  
- Sealed section files under `sections/` (P2-05 §5.1 order)  
- Submission + certification fingerprints · audit · recovery metadata  

### Consumed sealed inputs

- `{job}/evidence_pack/` pack + manifest + seal  
- `{job}/drill_execution/` aggregate + per-scenario reports  
- `{job}/integration_live/cpr_p7_integration_latest.json`  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Owner Submission assembly engine implemented | **PASS** |
| AC2 | Assembles only from sealed P7 evidence / drills / freeze | **PASS** |
| AC3 | Consumes required P2-05 certification metadata; no evidence mutation | **PASS** |
| AC4 | Integrates state / checkpoint / recovery / audit / contract / job / country | **PASS** |
| AC5 | Rejects missing/stale/corrupt/modified/replayed; fail-closed | **PASS** |
| AC6 | Deterministic section order; sealed package + sealed manifest | **PASS** |
| AC7 | Certification fingerprints + audit + recovery metadata | **PASS** |
| AC8 | No privilege bypass; no cross-country | **PASS** |
| AC9 | Enablement FALSE; no production SQL/uploads; Architecture/OD unchanged | **PASS** |
| AC10 | No Owner PASS/FAIL decision written | **PASS** |
| AC11 | Self-tests + PHP lint + full CPR suite green | **PASS** |

---

## 5. Stop rule

**WP-P8-02 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P8-03** until Owner explicitly reviews and approves the next Work Package.

---

*End of CPR-P8-WP02-OWNER_SUBMISSION.*
