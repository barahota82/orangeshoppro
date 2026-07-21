# WP-P4-08 — Live Witnesses (CP5) & CP-A Pre-PONR Freeze

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P4-08** — Pre-PONR Witnesses (CP5) & CP-A last reversible |
| **Artifact-ID** | `CPR-P4-WP08-WITNESSES_CPA` |
| **Status** | COMPLETE |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Depends on** | WP-P4-07 · WP-P1-04 · OD-INV · P3-04 Checkpoint Engine |
| **Maps to** | Architecture §6, §18 · P1-04 CP5/CP-A · P3-04 |

---

## 1. Purpose

Implement the **live** post-authority path:

1. Capture and **seal** mandatory pre-PONR witnesses (survivor / global / target inventory)  
2. Persist **CP5** via Checkpoint Engine  
3. Persist **CP-A** as the **last fully reversible idle point** before PONR  

**Explicitly out of scope:**

- PONR DELETE / IMPORT / uploads apply  
- WP-P4-09 integration baseline freeze  
- Enablement TRUE  
- Architecture / OWNER_APPROVED / C3–C8 edits  

---

## 2. Implementation root

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_witnesses_live.php` | Live witnesses + CP-A orchestration |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_WITNESSES_LIVE_DIRNAME`; scaffold `P4-08-witnesses-live` |
| `scripts/backup/country_production/self_test_cpr_witnesses_live.php` | Self-tests |

**Consumed:** Checkpoint Engine · Authority live · Gates live · Lock live · OD-PIN · Maint · Contract · State.

---

## 3. Live APIs

| API | Role |
|-----|------|
| `orange_cpr_witnesses_live_capture` | Validate + seal witness bundle |
| `orange_cpr_witnesses_live_commit_cp5` | Write CP5 (create or identical idempotent) |
| `orange_cpr_witnesses_live_commit_cpa` | Write CP-A + seal CP-A live record |
| `orange_cpr_witnesses_live_ceremony` | capture → CP5 → CP-A |

### Bindings enforced

Job identity · execution contract · state `cpr_pre_ponr` · checkpoints · lock ownership · GLOBAL Maint · OD-PIN · gates_live PASS · authority/runbook/RESTORE · inventory snapshot · schema revision · C4–C8 hashes (when supplied).

### Enforce

- All mandatory witnesses present, sealed, fingerprint-bound  
- Survivor / Global fail-closed  
- Missing / stale / corrupt / mismatched / replayed → block CP-A  
- CP-A only after complete pre-PONR path valid  
- CP-A = last reversible idle point; **no** PONR execution  
- No DELETE / IMPORT / production mutation  
- No automatic continuation beyond CP-A  

---

## 4. Artifacts

| File | Content |
|------|---------|
| `witnesses_live/cpr_witness_bundle_*.json` + `cpr_witness_bundle_latest.json` | Sealed witness bundle |
| `checkpoints/CP5_pre_ponr_witnesses.json` | Sealed CP5 |
| `checkpoints/CPA_last_reversible.json` | Sealed CP-A |
| `witnesses_live/cpr_cpa_live_*.json` + `cpr_cpa_live_latest.json` | Sealed CP-A live wrapper |

**Audit:** `cpr.witnesses_live_capture` · `cpr.witnesses_live_commit_cp5` · `cpr.witnesses_live_commit_cpa`

---

## 5. Deterministic codes (selected)

| Code | Meaning |
|------|---------|
| `ok` | Step PASS |
| `witnesslive_witness_missing` | Mandatory witness missing |
| `witnesslive_witness_stale` / `_corrupt` | Stale/corrupt evidence |
| `witnesslive_fingerprint_mismatch` | Fingerprint drift |
| `witnesslive_survivor_failure` / `_global_failure` | Survivor/Global fail-closed |
| `witnesslive_gate_required` / `_authority_required` | Missing prior live ceremonies |
| `witnesslive_session_pin_missing` | OD-PIN / contract pin missing |
| `witnesslive_state_invalid` | Wrong job state |
| `witnesslive_duplicate_forbidden` / `_replay_forbidden` | Duplicate CP-A / replay |
| `witnesslive_ponr_forbidden` | PONR path refused |
| `witnesslive_auto_continue_forbidden` | Auto-continue beyond CP-A refused |

---

## 6. Acceptance Criteria

| # | Criterion | Met |
|---|-----------|-----|
| AC1 | Live witness collection and validation | YES |
| AC2 | CP5 witness persistence via Checkpoint Engine | YES |
| AC3 | CP-A final pre-PONR reversible checkpoint | YES |
| AC4 | Bound to job/contract/state/checkpoints/lock/maint/OD-PIN/gates/authority/inventory/schema/C4–C8 | YES |
| AC5 | Mandatory sealed fingerprint-bound witnesses; survivor/global fail-closed | YES |
| AC6 | Bad witness data blocks CP-A | YES |
| AC7 | CP-A only after complete pre-PONR path; no PONR; no auto-continue | YES |
| AC8 | Sealed bundle + CP5 + CP-A + audit + deterministic codes | YES |
| AC9 | Enablement FALSE; no DELETE/IMPORT/PONR/production mutation | YES |
| AC10 | Architecture / OD / C3–C8 / prior WPs unmodified (scaffold test bumps only) | YES |
| AC11 | Self-tests cover required cases; PHP lint clean | YES |

---

## 7. Stop rule

**WP-P4-08 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P4-09 until Owner explicitly reviews and approves.
