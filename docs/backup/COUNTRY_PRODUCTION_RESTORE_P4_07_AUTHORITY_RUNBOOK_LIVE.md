# WP-P4-07 — Live Authority, Runbook & RESTORE Ceremony

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P4-07** — Authority / Runbook / Phrase live ceremony |
| **Artifact-ID** | `CPR-P4-WP07-AUTHORITY_RUNBOOK_LIVE` |
| **Status** | COMPLETE |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Depends on** | WP-P4-06 · WP-P3-07 · WP-P1-06 · OD-DUAL · OD-PHRASE · OD-PERM · OD-RUNBOOK |
| **Maps to** | Architecture §7–§8, §25–§27 · P1-06 · P3-07 |

---

## 1. Purpose

Implement the **live** Super Admin ceremony after sealed live gate PASS:

1. Machine-verified **Runbook** completion (OD-RUNBOOK)  
2. Exact **RESTORE** phrase (OD-PHRASE)  
3. Mandatory **password re-authentication** evidence  
4. One-time sealed **PONR authorization** via P3 Authority Engine — **without** DELETE/IMPORT/PONR mutation  

**Canonical post-P4-06 ceremony entry:** `orange_cpr_authority_live_ceremony` / `complete_runbook` + `authorize`.  
P4-02 `approvals_live` remains the WF dual-approval live path; it must not be treated as a bypass of P4-06 gates.

**Explicitly out of scope:**

- Pre-PONR witnesses / CP5 live capture (WP-P4-08)  
- DELETE / IMPORT / PONR mutation  
- Enablement TRUE  
- C3–C8 / Architecture / OWNER_APPROVED edits  

---

## 2. Implementation root

| Path | Role |
|------|------|
| `includes/backup/country_production/cpr_authority_live.php` | Live runbook + RESTORE orchestration |
| `includes/backup/country_production/cpr_paths.php` | `ORANGE_CPR_AUTH_LIVE_DIRNAME`; scaffold `P4-07-authority-live` |
| `scripts/backup/country_production/self_test_cpr_authority_live.php` | Self-tests |

**Consumed (not forked):** `orange_cpr_ponr_authorize` · `orange_cpr_auth_validate_runbook` · `orange_cpr_auth_validate_gate_report` · lock live revalidate · OD-PIN · maint · gates_live · checkpoints · contract.

---

## 3. Live APIs

| API | Role |
|-----|------|
| `orange_cpr_authority_live_complete_runbook` | Machine-verify/create `runbook_pre_ponr`; seal runbook live record |
| `orange_cpr_authority_live_authorize` | RESTORE + re-auth → P3 OTA; seal authority live record |
| `orange_cpr_authority_live_ceremony` | Runbook then authorize |

### Preconditions

- Ops enablement **FALSE**  
- State `cpr_pre_ponr`  
- Super Admin only (Country Admin never)  
- GLOBAL Maint proven + OD-PIN pin + lock ownership  
- Sealed **gates_live PASS** linked to P3 `pre_ponr_full` hash  
- Frozen execution contract + session Full Backup pin  

### Enforce

- Phrase exactly `RESTORE`  
- `password_reauth_ok` mandatory (password never stored)  
- `all_minimum_items_confirmed` for runbook  
- One-time only; no replay / bypass / privilege-escalation knobs  
- Fail-closed  

---

## 4. Artifacts

| File | Content |
|------|---------|
| `auth_live/cpr_runbook_live_*.json` + `cpr_runbook_live_latest.json` | Sealed runbook validation |
| `auth_live/cpr_authority_live_*.json` + `cpr_authority_live_latest.json` | Sealed live ceremony wrapper |
| `auth/cpr_ponr_authorization_*.json` | P3 one-time authorization (consumed by engine) |
| `checkpoints/runbook_pre_ponr.json` | Machine-verified runbook checkpoint |

**Audit:** `cpr.runbook_live_complete` · `cpr.authority_live_authorize` (+ P3 `cpr.ponr_authorization`)

---

## 5. Deterministic codes (selected)

| Code | Meaning |
|------|---------|
| `ok` | Ceremony step PASS |
| `authlive_actor_not_super_admin` | Invalid actor / Country Admin |
| `authlive_privilege_escalation` | Escalation / PONR-execute knobs |
| `authlive_phrase_invalid` | Missing or wrong phrase |
| `authlive_reauth_missing` | Password re-auth missing |
| `authlive_runbook_incomplete` | Incomplete / unsealed runbook |
| `authlive_gate_required` | Live/P3 gate PASS missing |
| `authlive_sealed_artifact_missing` | Required sealed artifact linkage broken |
| `authlive_authorization_corrupt` | Corrupt / unsealed authorization |
| `authlive_replay_forbidden` / `_duplicate_forbidden` | Replay / duplicate |
| `authlive_bypass_forbidden` | Bypass / skip knobs |
| `authlive_ponr_forbidden` | PONR crossed / mutation path |

---

## 6. Acceptance Criteria

| # | Criterion | Met |
|---|-----------|-----|
| AC1 | Live authority uses P3 Authority Engine | YES |
| AC2 | Complete live Runbook validation | YES |
| AC3 | Live RESTORE confirmation ceremony | YES |
| AC4 | Mandatory password re-authentication | YES |
| AC5 | Integrated Authority / Gate / Lock / State / Checkpoint / Session pin / Contract | YES |
| AC6 | Super Admin only; Country Admin never authorizes | YES |
| AC7 | Exact RESTORE; complete runbook; sealed artifacts; one-time; no replay; no privilege escalation; fail-closed | YES |
| AC8 | Sealed authorization + sealed runbook validation + audit events | YES |
| AC9 | Enablement FALSE; no DELETE/IMPORT/PONR/production mutation | YES |
| AC10 | Architecture / OD / C3–C8 / prior WPs unmodified (scaffold test bumps only) | YES |
| AC11 | Self-tests cover required cases; PHP lint clean | YES |

---

## 7. Stop rule

**WP-P4-07 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P4-08 until Owner explicitly reviews and approves.
