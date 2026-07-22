# WP-P7-04 — Evidence Pack Assembly & Seal (P2-04 / EV-01…EV-14)

| Field | Value |
|-------|-------|
| **Work Package** | **WP-P7-04** — Evidence Pack Assembly & Seal |
| **Artifact-ID** | `CPR-P7-WP04-EVIDENCE_PACK` |
| **Phase** | P7 — Clone drills / real-clone proof |
| **Status** | **COMPLETE** |
| **Depends on** | WP-P7-03 (sealed drill suite); P2-04 schemas; P2 Artifact Index §8 |
| **Unlocks** | WP-P7-05 Integration Baseline Freeze (Owner approval required) |
| **Scaffold** | `P7-04-evidence-pack` |
| **Enablement** | **FALSE** (hard) |
| **Architecture / OWNER_APPROVED** | **Not modified** |
| **Evidence SoT** | `COUNTRY_PRODUCTION_RESTORE_P2_04_EVIDENCE_PACK_SCHEMAS.md` · P2 Index §8 |

---

## 1. Objective

Implement the **Evidence Pack assembly engine only**. Assemble and seal **EV-01…EV-14** exactly as defined in frozen P2-04 contracts, using **only sealed drill harness + scenario execution artifacts**. Do **not** invent, merge, reorder, or omit evidence items. Do **not** grant Owner Cert PASS (P8).

---

## 2. Hard rules

- Sealed source artifacts only; reject stale / modified / missing / corrupt / replayed evidence  
- Deterministic packaging order EV-01→EV-14 (P2-04 §7.1)  
- Clone environment only; no cross-country evidence  
- No privilege bypass; fail-closed on any validation failure  
- Enablement FALSE; no production SQL/uploads; no Architecture/OD changes  
- Integrate harness, drill execution, state, checkpoint, recovery, audit, contract, job, country, schema  

---

## 3. Implementation

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_evidence_catalog.php` | Frozen EV-01…EV-14 + order / EV-10 minimum |
| `includes/backup/country_production/cpr_evidence_pack_live.php` | `orange_cpr_evidence_pack_live_run()` |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_EVIDENCE_PACK_DIRNAME`; scaffold |
| `includes/backup/country_production/cpr_p7_control_plane.php` | `evidence_pack_engine_implemented=true` |
| `scripts/backup/country_production/self_test_cpr_evidence_pack_live.php` | Self-tests |
| `docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md` | WP-P7-04 COMPLETE; stop → WP-P7-05 |

### Outputs under `{job}/evidence_pack/`

- `artifacts/EV-01` … `EV-14`  
- `manifest.json` · `seal.json` · `traceability.json` · `drills/index.json` · `checklist/evaluation.json` · `validation_report.json`  
- Sealed latest pointers: pack / manifest / seal  
- Evidence fingerprints · audit · recovery metadata  

---

## 4. Acceptance Criteria

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Evidence Pack assembly engine implemented | **PASS** |
| AC2 | Assembles only EV-01…EV-14; no invent/merge/reorder/omit | **PASS** |
| AC3 | Assembles only from sealed drill artifacts | **PASS** |
| AC4 | Integrates harness / execution / state / checkpoint / recovery / audit / contract / job / country / schema | **PASS** |
| AC5 | Rejects stale/modified/missing/corrupt/replayed evidence; fail-closed | **PASS** |
| AC6 | Deterministic packaging order; sealed pack + sealed manifest | **PASS** |
| AC7 | Evidence fingerprints + audit + recovery metadata | **PASS** |
| AC8 | No privilege bypass; no cross-country; clone only | **PASS** |
| AC9 | Enablement FALSE; no production SQL/uploads; Architecture/OD unchanged; no Owner Cert PASS | **PASS** |
| AC10 | Self-tests + PHP lint + full CPR suite green | **PASS** |

---

## 5. Stop rule

**WP-P7-04 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin **WP-P7-05** until Owner explicitly reviews and approves the next Work Package.

---

*End of CPR-P7-WP04-EVIDENCE_PACK.*
