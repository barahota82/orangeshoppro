# Country Production Restore — P2 Certification Drill Scenario Catalog

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P2-03** — Drill Scenario Catalog (incl. rollback) |
| **Artifact-ID** | `CPR-P2-WP03-DRILL_SCENARIOS` |
| **Status** | COMPLETE (certification design only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Authorization** | Owner approved WP-P2-02; authorized WP-P2-03 |
| **Architecture baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **P1 design baseline** | Git tag `P1-Design-Baseline` → commit `56580dab` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` |
| **Control plane** | `COUNTRY_PRODUCTION_RESTORE_P2_ARTIFACT_INDEX.md` (WP-P2-01) |
| **Checklist** | `CPR-P2-WP02-CERT_CHECKLIST` |
| **Primary P1 contracts** | P1-03 · P1-05 · P1-06 · P1-07 · P1-08 · P1-09 · P1-10 · P1-11 · P1-13 |
| **Coding** | **No** — scenario design only; no PHP/SQL/CLI/HTTP/UI |
| **Enablement** | Remains **FALSE** (OD-ENABLE) |

---

## 1. Purpose

Define the **complete Certification Drill Scenario Catalog** proving CPR behavior required for Owner Cert PASS/FAIL (OD-CERT), including mandatory **rollback** proof (EV-10 / CG-M10).

This WP:

- Specifies what each drill must demonstrate, expected outcomes, PASS/FAIL criteria, and required evidence.  
- Maps every scenario to OWNER_APPROVED decisions, P1 contracts, and EV-* artifacts.  
- Does **not** execute drills (P7), grant Cert PASS (Owner / P8), flip enablement, modify Architecture/ODs/P1, or write mutation code.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Drills for certification run in **clone / non-production / designated drill** context — not live production enablement | OD-ENABLE · OD-CERT · Architecture P7 |
| H2 | Production enablement flag remains **FALSE** | OD-ENABLE |
| H3 | **No automatic Rollback** under failure, timeout, crash, or pause | OD-ROLLBACK · OD-FAIL-* · P1-09 H1 |
| H4 | **No** statement-offset / SQL byte-offset resume | OD-FAIL-IMPORT · P1-09 H2 |
| H5 | Resume / Rollback = **Super Admin only**; Country Admin **never** | OD-PERM · OD-ROLLBACK · P1-09 |
| H6 | Rollback available **only** when paused because of failure (post-PONR pause classes) | OD-ROLLBACK · P1-09 §6.1 |
| H7 | Rollback targets **session** Full Backup (OD-PIN) only | OD-ROLLBACK · OD-PIN |
| H8 | C8 SAFE only; no WARNING waiver in package chain evidence | OD-C8 |
| H9 | Fail-closed; no success-with-warnings | OD-VERIFY-WARN · Integrity |
| H10 | Break Glass does **not** bypass anchor, gates, logging, or authentication | OD-BREAK |
| H11 | Engineering produces drill evidence only; Owner alone grants Cert PASS | OD-CERT |
| H12 | Every scenario maps to ≥1 OD, ≥1 P1 contract, and required EV refs | WP-P2-01 · WP-P2-02 |

---

## 3. Drill environment & evidence rules

| Rule | Value |
|------|-------|
| Environment label | Every drill report must set `drill_context` ∈ {`clone`,`shadow_lab`,`non_production_fixture`} |
| Production flag | Must remain `false` for entire cert cycle (EV-13) |
| Actor for Resume/Rollback/Break Glass | Super Admin (or simulated SA identity in drill harness) |
| Forbidden | Country Admin Resume/Rollback; auto-rollback; enablement flip; C3–C8 engine edits |
| Evidence binding | Scenario results feed EV-* (especially EV-04…EV-11, EV-10 mandatory set) |
| Checklist binding | Scenario PASS contributes to CG-M* Evidence Ready; does not equal Owner Cert PASS |

### 3.1 Scenario record fields (normative for later pack schemas)

Each executed drill produces a report referencing:

`scenario_id`, `package_cycle_id`, `schema_revision_bound`, `drill_context`, `started_at`, `ended_at`, `actors`, `od_refs[]`, `p1_refs[]`, `evidence_refs[]`, `expected_outcome`, `actual_outcome`, `result` (`PASS`|`FAIL`), `failure_codes[]`, `auto_rollback_executed` (must be `false`), `enablement_flag` (must be `false`).

---

## 4. Scenario catalog (complete)

Legend: **Class** = category required by Owner authorization for WP-P2-03.

---

### 4.1 Normal successful restore

#### DS-N01 — Happy-path Country Production Restore (clone)

| Field | Value |
|-------|-------|
| **Class** | Normal successful restore |
| **Objective** | Prove end-to-end restore succeeds under WF-A or WF-B with all mandatory chassis |
| **Setup** | Valid C3–C8 SAFE package; certified inventory; GLOBAL Maint ON; NEW Full Backup verify+pin; locks clear; phrase `RESTORE`; re-auth |
| **Steps (summary)** | Pre-PONR gates → CP-A → delete → import → scoped uploads → post-verify → success; Maint release only via SA+Runbook |
| **Expected outcome** | `cpr_succeeded` (or success terminal); session pin retained per policy; no auto paths; flag still false |
| **PASS criteria** | All mandatory stages complete; post-verify PASS; `auto_rollback_executed=false`; enablement false; audit complete |
| **FAIL criteria** | Any stage fail; WARNING treated as success; skipped gate; CA execute; flag true; missing pin |
| **Required evidence** | EV-04, EV-05, EV-06, EV-07, EV-08, EV-09, EV-11, EV-13 |
| **OD** | OD-DUAL, OD-PHRASE, OD-PERM, OD-MAINT*, OD-PIN, OD-UPLOADS, OD-VERIFY-WARN, OD-RUNBOOK, OD-C8, OD-INV, OD-ENABLE |
| **P1** | P1-03, P1-04, P1-05, P1-06, P1-07, P1-08, P1-10, P1-11 |
| **Checklist** | CG-M04…M09, CG-M14…M17 |

---

### 4.2 Fail-pause scenarios (every post-PONR pause class)

#### DS-F01 — Delete-phase fail-pause

| Field | Value |
|-------|-------|
| **Class** | Fail-pause |
| **Objective** | Inject/delete-phase failure → `cpr_paused_delete_failed` |
| **Expected outcome** | Pause; Maint ON; `cpr_failure_event` with `failure_class=delete`; `auto_rollback_executed=false`; wait SA Resume or Rollback |
| **PASS criteria** | Lands correct pause; surface reason/phase/status; no auto-rollback; no Maint release; no `cpr_succeeded` |
| **FAIL criteria** | Auto-rollback; statement invent; CA Resume; silent continue; Maint off |
| **Required evidence** | EV-10, EV-06, EV-11 (+ failure event) |
| **OD** | OD-FAIL-DELETE, OD-MAINT, OD-PIN |
| **P1** | P1-09 §3–§4.2, P1-03, P1-07 |
| **Checklist** | CG-M10, CG-M06 |

#### DS-F02 — Import-phase fail-pause

| Field | Value |
|-------|-------|
| **Class** | Fail-pause |
| **Objective** | Import failure → `cpr_paused_import_failed` with progress % / completed batches |
| **Expected outcome** | Pause; Maint ON; no SQL offset resume authority; no auto-rollback |
| **PASS criteria** | Correct pause; `statement_offset_resume_attempted=false`; progress fields present; SA-only next actions |
| **FAIL criteria** | Blind offset resume; auto-rollback; success-with-partial-import |
| **Required evidence** | EV-10, EV-11 |
| **OD** | OD-FAIL-IMPORT, OD-PIN |
| **P1** | P1-09 §4.3, §5.3 |
| **Checklist** | CG-M10 |

#### DS-F03 — Uploads fail-pause

| Field | Value |
|-------|-------|
| **Class** | Fail-pause / Upload integrity |
| **Objective** | Scoped uploads apply failure → `cpr_paused_uploads_failed`; `integrity_guaranteed=false` |
| **Expected outcome** | Pause; Maint ON; no best-effort accept; no auto-rollback |
| **PASS criteria** | Correct pause; pre-image refs preserved; integrity not claimed true |
| **FAIL criteria** | Partial accept; auto-rollback; ignore integrity |
| **Required evidence** | EV-08, EV-10, EV-11 |
| **OD** | OD-UPLOADS, OD-FAIL-* (pause policy), OD-PIN |
| **P1** | P1-09 §4.4, P1-10 |
| **Checklist** | CG-M08, CG-M10 |

#### DS-F04 — Post-verify fail-pause

| Field | Value |
|-------|-------|
| **Class** | Fail-pause / Verification failure |
| **Objective** | Post-apply verify FAIL → `cpr_paused_verify_failed`; `waiver_forbidden=true` |
| **Expected outcome** | Pause; Maint ON; pillar failure identified; no success-with-warnings |
| **PASS criteria** | Correct pause; failed pillar recorded; no waiver path |
| **FAIL criteria** | Mark succeeded despite FAIL; ignore pillar; auto-rollback |
| **Required evidence** | EV-09, EV-10, EV-11 |
| **OD** | OD-VERIFY-WARN, OD-FA-STOCK, OD-FA-SCHEMA, OD-FA-RESOLVER |
| **P1** | P1-09 §4.4, P1-11 |
| **Checklist** | CG-M09, CG-M10 |

#### DS-F05 — Emergency stop post-PONR fail-pause

| Field | Value |
|-------|-------|
| **Class** | Fail-pause |
| **Objective** | Emergency stop during active post-PONR stage → matching `cpr_paused_*` |
| **Expected outcome** | Pause; Maint ON; `failure_class=emergency_stop`; no auto-rollback |
| **PASS criteria** | Clean pause landing; SA Resume/Rollback only; audit |
| **FAIL criteria** | Auto-rollback; Maint release; continue mutation |
| **Required evidence** | EV-10, EV-11 |
| **OD** | OD-ROLLBACK (no auto), OD-MAINT, OD-PERM |
| **P1** | P1-09 §3; Architecture §28 |
| **Checklist** | CG-M10 |

#### DS-F06 — Rollback worker fail-pause

| Field | Value |
|-------|-------|
| **Class** | Fail-pause |
| **Objective** | Rollback worker fails → `cpr_paused_rollback_failed` |
| **Expected outcome** | Stay paused; Maint ON; Retry Rollback (not import Resume); no auto path |
| **PASS criteria** | Correct pause; Retry Rollback path only; `auto_rollback_executed=false` |
| **FAIL criteria** | Treat as import Resume; auto second rollback; Maint off |
| **Required evidence** | EV-10, EV-11 |
| **OD** | OD-ROLLBACK, OD-PIN |
| **P1** | P1-09 §3, §5.3, §6.5 |
| **Checklist** | CG-M10 |

---

### 4.3 Resume scenarios

#### DS-R01 — Resume after delete fail (safe finish delete)

| Field | Value |
|-------|-------|
| **Class** | Resume |
| **Objective** | SA Resume with `finish_safe_delete` when stage safely supports |
| **Expected outcome** | `cpr_resume_authorization`; return `cpr_deleting`; continue safely |
| **PASS criteria** | SA only; safe proof present; `forbids_statement_offset=true`; fingerprint match; Maint ON |
| **FAIL criteria** | CA Resume; empty proof; invent undelete; auto |
| **Required evidence** | EV-10, EV-05, EV-11 |
| **OD** | OD-FAIL-DELETE, OD-PERM, OD-PHRASE (if re-auth required by impl parity) |
| **P1** | P1-09 §5.3 T40 |
| **Checklist** | CG-M10, CG-M05 |

#### DS-R02 — Resume after import fail (re-clear + re-import)

| Field | Value |
|-------|-------|
| **Class** | Resume |
| **Objective** | SA authorizes `re_clear_target_slice_and_reimport` |
| **Expected outcome** | Safe re-clear + re-import from contract; **no** SQL byte offset |
| **PASS criteria** | Mode allowed; offset forbidden; fingerprint match; audit |
| **FAIL criteria** | Statement-offset mode accepted; CA actor |
| **Required evidence** | EV-10, EV-11 |
| **OD** | OD-FAIL-IMPORT, OD-PERM |
| **P1** | P1-09 §5.3 T41, H2 |
| **Checklist** | CG-M10 |

#### DS-R03 — Resume after uploads fail (integrity can be guaranteed)

| Field | Value |
|-------|-------|
| **Class** | Resume |
| **Objective** | SA Resume to `cpr_uploads_applying` only if integrity can be guaranteed |
| **Expected outcome** | Scoped re-apply under integrity guarantee |
| **PASS criteria** | Integrity proof; no best-effort; SA only |
| **FAIL criteria** | Resume without integrity guarantee |
| **Required evidence** | EV-08, EV-10 |
| **OD** | OD-UPLOADS, OD-PERM |
| **P1** | P1-09 §5.3 T42; P1-10 |
| **Checklist** | CG-M08, CG-M10 |

#### DS-R04 — Resume after verify fail (idempotent re-verify)

| Field | Value |
|-------|-------|
| **Class** | Resume |
| **Objective** | SA Resume to `cpr_post_verifying` when retry supported |
| **Expected outcome** | Re-verify; still fail-closed on pillar FAIL |
| **PASS criteria** | Idempotent retry; no waiver |
| **FAIL criteria** | Waiver; ignore failed pillar |
| **Required evidence** | EV-09, EV-10 |
| **OD** | OD-VERIFY-WARN, OD-PERM |
| **P1** | P1-09 §5.3 T43; P1-11 |
| **Checklist** | CG-M09, CG-M10 |

#### DS-R05 — Resume DENIED (unsafe / offset / missing proof)

| Field | Value |
|-------|-------|
| **Class** | Resume |
| **Objective** | Attempt Resume with `resume_mode_if_eligible=none`, empty proof, or statement-offset → DENY |
| **Expected outcome** | Remain paused; only Rollback (or incident) allowed |
| **PASS criteria** | Deny recorded; no state advance; no offset used |
| **FAIL criteria** | Resume accepted despite deny conditions |
| **Required evidence** | EV-10, EV-11 |
| **OD** | OD-FAIL-IMPORT, OD-FAIL-DELETE, OD-PERM |
| **P1** | P1-09 §5.3, §5.5–§5.6 |
| **Checklist** | CG-M10 |

---

### 4.4 Rollback scenarios

#### DS-B01 — Rollback from delete pause

| Field | Value |
|-------|-------|
| **Class** | Rollback |
| **Objective** | SA Rollback from `cpr_paused_delete_failed` to session Full Backup |
| **Expected outcome** | `cpr_rollback_authorization` (`automatic=false`); `cpr_rolling_back` → completed; Maint ON until SA release |
| **PASS criteria** | SA; phrase `RESTORE`; re-auth; session pin only; audit+exec logs; CA denied |
| **FAIL criteria** | Auto; wrong backup; CA; skip phrase/gates; unlock Maint auto |
| **Required evidence** | EV-10, EV-06, EV-11, EV-15-class via EV-10 pin proof |
| **OD** | OD-ROLLBACK, OD-PIN, OD-PHRASE, OD-PERM |
| **P1** | P1-09 §6 |
| **Checklist** | CG-M10, CG-M15, CG-H02 |

#### DS-B02 — Rollback from import pause

| Field | Value |
|-------|-------|
| **Class** | Rollback |
| **Objective** | Same chassis from `cpr_paused_import_failed` |
| **Expected outcome** | Full session-anchor restore; no inverse-import-as-sole-rollback |
| **PASS criteria** | As DS-B01 |
| **FAIL criteria** | As DS-B01 |
| **Required evidence** | EV-10, EV-11 |
| **OD** | OD-ROLLBACK, OD-PIN, OD-FAIL-IMPORT |
| **P1** | P1-09 §6 |
| **Checklist** | CG-M10 |

#### DS-B03 — Rollback from uploads pause

| Field | Value |
|-------|-------|
| **Class** | Rollback |
| **Objective** | Rollback from uploads pause; pre-image assist only — DB via Full anchor |
| **Expected outcome** | Session Full Backup restore; scoped upload assist not sole DB recovery |
| **PASS criteria** | Pin target correct; security parity |
| **FAIL criteria** | Uploads-only “rollback” claimed as full recovery when DB dirty |
| **Required evidence** | EV-08, EV-10 |
| **OD** | OD-ROLLBACK, OD-UPLOADS, OD-PIN |
| **P1** | P1-09 §6.2; P1-10 |
| **Checklist** | CG-M08, CG-M10 |

#### DS-B04 — Rollback from verify pause

| Field | Value |
|-------|-------|
| **Class** | Rollback |
| **Objective** | Rollback after verify FAIL pause |
| **Expected outcome** | Session anchor restore; then SA Maint release path |
| **PASS criteria** | As DS-B01; verify failure not waived into success |
| **FAIL criteria** | Force succeed verify instead of Rollback |
| **Required evidence** | EV-09, EV-10 |
| **OD** | OD-ROLLBACK, OD-VERIFY-WARN, OD-PIN |
| **P1** | P1-09 §6; P1-11 |
| **Checklist** | CG-M09, CG-M10 |

#### DS-B05 — Retry Rollback after rollback worker fail

| Field | Value |
|-------|-------|
| **Class** | Rollback |
| **Objective** | From `cpr_paused_rollback_failed`, SA Retry Rollback (T56) |
| **Expected outcome** | Re-enter `cpr_rolling_back`; still no auto |
| **PASS criteria** | Retry authorized; automatic=false |
| **FAIL criteria** | Auto retry loop without SA; import Resume used |
| **Required evidence** | EV-10, EV-11 |
| **OD** | OD-ROLLBACK, OD-PIN |
| **P1** | P1-09 §5.3, §6.5 |
| **Checklist** | CG-M10 |

#### DS-B06 — Missing session pin critical incident

| Field | Value |
|-------|-------|
| **Class** | Rollback |
| **Objective** | Post-PONR failure with missing/unpinned session Full anchor |
| **Expected outcome** | Critical incident; Maint ON; **no** invented auto-rollback; no arbitrary older backup |
| **PASS criteria** | Incident recorded; site remains Maint; no silent recovery invention |
| **FAIL criteria** | Auto-pick unrelated backup; auto-rollback |
| **Required evidence** | EV-10, EV-06, EV-11 |
| **OD** | OD-ROLLBACK, OD-PIN |
| **P1** | P1-09 §6.6 |
| **Checklist** | CG-M10, CG-M15 |

---

### 4.5 Lock conflict scenarios

#### DS-L01 — Full DR lock blocks CPR

| Field | Value |
|-------|-------|
| **Class** | Lock conflict |
| **Objective** | Active Full DR lock → CPR acquire/start FAIL |
| **Expected outcome** | Refuse CPR; no PONR |
| **PASS criteria** | Exclusion enforced; audit/reason `gate_full_dr_active` or lock refuse |
| **FAIL criteria** | CPR proceeds concurrent with Full DR |
| **Required evidence** | EV-07, EV-04 |
| **OD** | OD-LOCK-CROSS |
| **P1** | P1-05; P1-08 G05 |
| **Checklist** | CG-M07 |

#### DS-L02 — C6 shadow lock blocks CPR (and inverse)

| Field | Value |
|-------|-------|
| **Class** | Lock conflict |
| **Objective** | Active C6 ↔ CPR mutual exclusion |
| **Expected outcome** | Refuse conflicting acquire |
| **PASS criteria** | Both directions proven |
| **FAIL criteria** | Concurrent C6 + CPR mutation |
| **Required evidence** | EV-07 |
| **OD** | OD-LOCK-SHADOW |
| **P1** | P1-05; P1-08 G05 |
| **Checklist** | CG-M07 |

#### DS-L03 — Backup Runner lock conflict

| Field | Value |
|-------|-------|
| **Class** | Lock conflict |
| **Objective** | Backup runner lock held when exclusion requires idle → CPR refuse (and CPR blocks export while held) |
| **Expected outcome** | Refuse per P1-05 matrix |
| **PASS criteria** | Exclusion honored |
| **FAIL criteria** | Concurrent export + CPR mutation |
| **Required evidence** | EV-07 |
| **OD** | OD-LOCK-CROSS (family) · Architecture §15–§16 |
| **P1** | P1-05 |
| **Checklist** | CG-M07 |

#### DS-L04 — Post-PONR no automatic lock release

| Field | Value |
|-------|-------|
| **Class** | Lock conflict / TTL |
| **Objective** | After PONR, simulate worker crash/timeout — lock must **not** auto-release |
| **Expected outcome** | Lock remains; heartbeat/procedure only; SA path audited |
| **PASS criteria** | `auto` unlock false; post-PONR H5 of P1-05 held |
| **FAIL criteria** | TTL auto-unlock post-PONR |
| **Required evidence** | EV-07, EV-11 |
| **OD** | OD-LOCK-TTL |
| **P1** | P1-05 H5; P1-09 §7 |
| **Checklist** | CG-M07 |

---

### 4.6 Maintenance interruption scenarios

#### DS-M01 — PONR refused without GLOBAL Maint ON

| Field | Value |
|-------|-------|
| **Class** | Maintenance interruption |
| **Objective** | Attempt PONR with Maint OFF → FAIL |
| **Expected outcome** | Pre-PONR FAIL; no mutation |
| **PASS criteria** | Gate/maint check FAIL; production untouched |
| **FAIL criteria** | PONR without Maint |
| **Required evidence** | EV-06, EV-04 |
| **OD** | OD-MAINT, OD-MAINT-SCOPE |
| **P1** | P1-07; P1-08 G22 |
| **Checklist** | CG-M06 |

#### DS-M02 — Maint remains ON during fail-pause

| Field | Value |
|-------|-------|
| **Class** | Maintenance interruption |
| **Objective** | After DS-F01/F02/F03/F04, users must not regain normal access |
| **Expected outcome** | `maint_global_on=true` throughout pause |
| **PASS criteria** | No auto Maint release on failure |
| **FAIL criteria** | Maint off while dirty/paused |
| **Required evidence** | EV-06, EV-10 |
| **OD** | OD-FAIL-*, OD-MAINT, Maintenance State |
| **P1** | P1-09 H8; P1-07 |
| **Checklist** | CG-M06, CG-M10 |

#### DS-M03 — Timeout / duration does not auto-rollback

| Field | Value |
|-------|-------|
| **Class** | Maintenance interruption |
| **Objective** | Progress-aware timeout / estimated duration exceeded → must **not** trigger Rollback |
| **Expected outcome** | Pause/alert/incident per OD-TIMEOUT; SA decision only |
| **PASS criteria** | `auto_rollback_executed=false` |
| **FAIL criteria** | Timeout → auto Rollback |
| **Required evidence** | EV-06, EV-10, EV-11 |
| **OD** | OD-TIMEOUT, OD-MAINT-MAX, OD-RTO, OD-ROLLBACK |
| **P1** | P1-07; P1-09 §7 |
| **Checklist** | CG-M06, CG-M10 |

#### DS-M04 — Maint release only after SA + Runbook (success or rollback complete)

| Field | Value |
|-------|-------|
| **Class** | Maintenance interruption |
| **Objective** | After success or rollback completed, Maint stays ON until SA release + Runbook |
| **Expected outcome** | No auto release |
| **PASS criteria** | Explicit SA release audited |
| **FAIL criteria** | Auto release on terminal state |
| **Required evidence** | EV-06, EV-11, EV-05 |
| **OD** | OD-MAINT, OD-RUNBOOK, OD-PERM |
| **P1** | P1-06, P1-07, P1-09 §7 |
| **Checklist** | CG-M06, CG-M16 |

---

### 4.7 Schema mismatch scenarios

#### DS-S01 — Schema mismatch blocks pre-PONR / cert binding

| Field | Value |
|-------|-------|
| **Class** | Schema mismatch |
| **Objective** | Live schema ≠ package/cert bound revision → FAIL closed |
| **Expected outcome** | Gate FAIL (`cert_schema_mismatch` / G-FA-SCHEMA); no PONR |
| **PASS criteria** | Fail-closed; no fixture soft-skip in Production/Certification |
| **FAIL criteria** | Soft-skip; proceed anyway |
| **Required evidence** | EV-12, EV-04, EV-03 |
| **OD** | OD-SCHEMA, OD-FA-SCHEMA |
| **P1** | P1-08; P1-13 §8 |
| **Checklist** | CG-S03, CG-M12, CG-M17 |

#### DS-S02 — Schema revision change invalidates prior cert (no auto-enable)

| Field | Value |
|-------|-------|
| **Class** | Schema mismatch |
| **Objective** | Simulate schema revision change after a prior PASS record |
| **Expected outcome** | `cert_invalidated`; flag forced false; no auto re-enable; requires rebuild+new cert+new C8 SAFE+Owner PASS+Enable again |
| **PASS criteria** | `auto_reenable=false`; checklist steps initially false |
| **FAIL criteria** | Auto re-enable; keep old PASS valid |
| **Required evidence** | EV-12, EV-13, EV-03 |
| **OD** | OD-SCHEMA, OD-ENABLE, OD-CERT, OD-C8 |
| **P1** | P1-13 §8 |
| **Checklist** | CG-M12, CG-M13, CG-H04 |

---

### 4.8 Inventory mismatch scenarios

#### DS-I01 — Missing / non-certified inventory blocks restore

| Field | Value |
|-------|-------|
| **Class** | Inventory mismatch |
| **Objective** | Absent or `certified_read_only!=true` inventory → FAIL |
| **Expected outcome** | Pre-PONR FAIL; no mutation |
| **PASS criteria** | Gate FAIL; clear code |
| **FAIL criteria** | Proceed without certified inventory |
| **Required evidence** | EV-02, EV-04 |
| **OD** | OD-INV |
| **P1** | P1-08 G21 |
| **Checklist** | CG-M02 |

#### DS-I02 — Live DB must not replace OD-INV snapshot

| Field | Value |
|-------|-------|
| **Class** | Inventory mismatch |
| **Objective** | Attempt to rebuild/replace certified inventory from live SELECT during gate eval → forbidden |
| **Expected outcome** | Reject; verify-only against snapshot |
| **PASS criteria** | Snapshot unchanged; live used only to verify |
| **FAIL criteria** | Snapshot rewritten from live as SoT |
| **Required evidence** | EV-02, EV-04 |
| **OD** | OD-INV |
| **P1** | P1-08 H5 |
| **Checklist** | CG-M02 |

---

### 4.9 Upload integrity failure scenarios

#### DS-U01 — Upload integrity failure (primary)

| Field | Value |
|-------|-------|
| **Class** | Upload integrity failure |
| **Objective** | Same as DS-F03 with emphasis on integrity flag and scoped pre-image |
| **Expected outcome** | Pause uploads; integrity not guaranteed |
| **PASS criteria** | See DS-F03 |
| **FAIL criteria** | See DS-F03 |
| **Required evidence** | EV-08, EV-10 |
| **OD** | OD-UPLOADS |
| **P1** | P1-10; P1-09 §4.4 |
| **Checklist** | CG-M08 |

#### DS-U02 — Best-effort / partial upload accept forbidden

| Field | Value |
|-------|-------|
| **Class** | Upload integrity failure |
| **Objective** | Attempt to continue with partial scoped apply → DENY |
| **Expected outcome** | Remain failed/paused; no success |
| **PASS criteria** | Reject best-effort |
| **FAIL criteria** | Partial accept marked success |
| **Required evidence** | EV-08, EV-10 |
| **OD** | OD-UPLOADS |
| **P1** | P1-09 §5.3 uploads row; P1-10 |
| **Checklist** | CG-M08 |

---

### 4.10 Verification failure scenarios

#### DS-V01 — Pillar verification FAIL (accounting / ownership / fifo / stock / schema / survivor / global)

| Field | Value |
|-------|-------|
| **Class** | Verification failure |
| **Objective** | Force each major pillar FAIL class at least once across drill set (may be parameterized runs under one ID family) |
| **Expected outcome** | `cpr_paused_verify_failed`; pillar id recorded |
| **PASS criteria** | Fail-closed; pillar named; no waiver |
| **FAIL criteria** | Soft pass; ignore pillar |
| **Required evidence** | EV-09, EV-10 |
| **OD** | OD-VERIFY-WARN, OD-FA-* |
| **P1** | P1-11; P1-09 |
| **Checklist** | CG-M09 |

#### DS-V02 — Success-with-warnings forbidden

| Field | Value |
|-------|-------|
| **Class** | Verification failure |
| **Objective** | Attempt to complete job as success while verify warnings/FAIL present |
| **Expected outcome** | Reject; remain paused or failed |
| **PASS criteria** | No `cpr_succeeded` with warnings |
| **FAIL criteria** | Success despite FAIL/WARN |
| **Required evidence** | EV-09, EV-11 |
| **OD** | OD-VERIFY-WARN · Integrity |
| **P1** | P1-09 H9 |
| **Checklist** | CG-M09 |

---

### 4.11 Emergency Break Glass scenarios

#### DS-G01 — Break Glass Super Admin path (allowed chassis)

| Field | Value |
|-------|-------|
| **Class** | Emergency Break Glass |
| **Objective** | SA Break Glass with mandatory emergency reason, full audit, notification |
| **Expected outcome** | Path available to SA only; all non-bypass items still enforced |
| **PASS criteria** | Reason+audit+notify present; anchor/gates/auth/logging **not** skipped |
| **FAIL criteria** | Missing reason/audit/notify; silent path |
| **Required evidence** | EV-05, EV-11, EV-10 (anchor still required) |
| **OD** | OD-BREAK, OD-PERM, OD-PIN, OD-PHRASE |
| **P1** | P1-06 |
| **Checklist** | CG-M05, CG-H03 |

#### DS-G02 — Break Glass cannot bypass safety chassis

| Field | Value |
|-------|-------|
| **Class** | Emergency Break Glass |
| **Objective** | Attempt Break Glass while skipping Full Rollback Anchor / mandatory gates / logging / authentication → DENY |
| **Expected outcome** | Reject; no mutation |
| **PASS criteria** | Each bypass attempt denied and audited |
| **FAIL criteria** | Any bypass succeeds |
| **Required evidence** | EV-05, EV-04, EV-10, EV-11 |
| **OD** | OD-BREAK §15 Frozen |
| **P1** | P1-06; P1-08 |
| **Checklist** | CG-M05, CG-M10 |

#### DS-G03 — Country Admin Break Glass DENIED

| Field | Value |
|-------|-------|
| **Class** | Emergency Break Glass |
| **Objective** | Country Admin attempts Break Glass / execute / Resume / Rollback |
| **Expected outcome** | Denied (`cpr_perm_denied_*`) |
| **PASS criteria** | No capability; audit deny |
| **FAIL criteria** | CA succeeds any of the above |
| **Required evidence** | EV-05, EV-11 |
| **OD** | OD-BREAK, OD-PERM, OD-DUAL, OD-ROLLBACK |
| **P1** | P1-06; P1-09 H10 |
| **Checklist** | CG-M05 |

---

### 4.12 Additional mandatory certification scenarios

#### DS-P01 — Pre-PONR gate/authority failure (not Rollback)

| Field | Value |
|-------|-------|
| **Class** | Pre-PONR / authority |
| **Objective** | Fail dual-control / phrase / gate before PONR |
| **Expected outcome** | Terminal pre-PONR fail/cancel; **Rollback UI not exposed** |
| **PASS criteria** | No post-PONR rollback action; production untouched |
| **FAIL criteria** | Expose OD-ROLLBACK pre-PONR; mutate anyway |
| **Required evidence** | EV-04, EV-05 |
| **OD** | OD-DUAL, OD-PHRASE, OD-ENABLE (flag false → G01 FAIL expected for live prod path) |
| **P1** | P1-08; P1-09 §3 last row; §5.4 |
| **Checklist** | CG-M04, CG-M05, CG-M14 |

#### DS-P02 — Country Admin Resume/Rollback DENIED (post-PONR pause)

| Field | Value |
|-------|-------|
| **Class** | Authority |
| **Objective** | From a valid pause, CA attempts Resume and Rollback |
| **Expected outcome** | Both denied |
| **PASS criteria** | Deny codes; state unchanged |
| **FAIL criteria** | CA succeeds |
| **Required evidence** | EV-05, EV-10, EV-11 |
| **OD** | OD-PERM, OD-ROLLBACK |
| **P1** | P1-09 H10 |
| **Checklist** | CG-M05, CG-M10 |

#### DS-P03 — Crash/timeout must not auto-rollback

| Field | Value |
|-------|-------|
| **Class** | Fail-pause / integrity |
| **Objective** | Kill worker mid post-PONR stage |
| **Expected outcome** | Pause/incident; Maint ON; lock not auto-released post-PONR; no auto-rollback |
| **PASS criteria** | `auto_rollback_executed=false`; SA must act |
| **FAIL criteria** | Auto-rollback or auto-unlock |
| **Required evidence** | EV-07, EV-10, EV-06 |
| **OD** | OD-ROLLBACK, OD-LOCK-TTL, OD-FAIL-* |
| **P1** | P1-09 H1; P1-05 H5 |
| **Checklist** | CG-M07, CG-M10 |

#### DS-P04 — PIN order: Maint → NEW Full Backup → verify → pin

| Field | Value |
|-------|-------|
| **Class** | Anchor / normal path prerequisite |
| **Objective** | Prove existing backup reuse forbidden; order mandatory |
| **Expected outcome** | Only NEW under Maint accepted as session pin |
| **PASS criteria** | Reuse of pre-existing backup rejected |
| **FAIL criteria** | Reuse accepted as session anchor |
| **Required evidence** | EV-06, EV-04, EV-10 |
| **OD** | OD-PIN |
| **P1** | P1-04; P1-08 G03/G23 |
| **Checklist** | CG-M15 |

#### DS-P05 — Enablement remains FALSE across all drills

| Field | Value |
|-------|-------|
| **Class** | Enablement posture |
| **Objective** | Attest flag false before/during/after entire drill suite |
| **Expected outcome** | EV-13 PASS; no Enable action |
| **PASS criteria** | Flag false throughout |
| **FAIL criteria** | Flag true at any point |
| **Required evidence** | EV-13 |
| **OD** | OD-ENABLE, OD-CERT |
| **P1** | P1-13 |
| **Checklist** | CG-S04, CG-M13, CG-H05 |

---

## 5. Catalog inventory (mandatory set)

| ID | Title | Class |
|----|-------|-------|
| DS-N01 | Happy-path restore | Normal successful restore |
| DS-F01 | Delete fail-pause | Fail-pause |
| DS-F02 | Import fail-pause | Fail-pause |
| DS-F03 | Uploads fail-pause | Fail-pause / Upload integrity |
| DS-F04 | Verify fail-pause | Fail-pause / Verification |
| DS-F05 | Emergency stop pause | Fail-pause |
| DS-F06 | Rollback worker fail-pause | Fail-pause |
| DS-R01 | Resume delete | Resume |
| DS-R02 | Resume import re-clear | Resume |
| DS-R03 | Resume uploads | Resume |
| DS-R04 | Resume verify | Resume |
| DS-R05 | Resume DENIED | Resume |
| DS-B01 | Rollback delete pause | Rollback |
| DS-B02 | Rollback import pause | Rollback |
| DS-B03 | Rollback uploads pause | Rollback |
| DS-B04 | Rollback verify pause | Rollback |
| DS-B05 | Retry Rollback | Rollback |
| DS-B06 | Missing pin incident | Rollback |
| DS-L01 | Full DR lock conflict | Lock conflict |
| DS-L02 | C6 lock conflict | Lock conflict |
| DS-L03 | Backup runner conflict | Lock conflict |
| DS-L04 | Post-PONR no auto-unlock | Lock conflict |
| DS-M01 | Maint required | Maintenance |
| DS-M02 | Maint ON during pause | Maintenance |
| DS-M03 | Timeout ≠ auto-rollback | Maintenance |
| DS-M04 | Maint release discipline | Maintenance |
| DS-S01 | Schema mismatch gate | Schema mismatch |
| DS-S02 | Schema invalidates cert | Schema mismatch |
| DS-I01 | Inventory missing | Inventory mismatch |
| DS-I02 | Live replace inventory forbidden | Inventory mismatch |
| DS-U01 | Upload integrity failure | Upload integrity |
| DS-U02 | Best-effort uploads forbidden | Upload integrity |
| DS-V01 | Pillar verify FAIL | Verification failure |
| DS-V02 | Success-with-warnings forbidden | Verification failure |
| DS-G01 | Break Glass SA allowed chassis | Break Glass |
| DS-G02 | Break Glass no bypass | Break Glass |
| DS-G03 | CA Break Glass DENIED | Break Glass |
| DS-P01 | Pre-PONR fail (no Rollback UI) | Authority / pre-PONR |
| DS-P02 | CA Resume/Rollback DENIED | Authority |
| DS-P03 | Crash ≠ auto-rollback | Integrity |
| DS-P04 | PIN order | Anchor |
| DS-P05 | Enablement FALSE attestation | Enablement |

**Total mandatory scenarios: 40**

All classes required by Owner WP-P2-03 authorization are covered. DS-V01 may be executed as parameterized pillar runs; each pillar FAIL must appear in evidence.

---

## 6. Minimum scenario set for EV-10 (rollback proof)

For CG-M10 / Owner CG-H02, evidence pack **must** include PASS results for at least:

| Required | Scenarios |
|----------|-----------|
| ≥1 fail-pause | DS-F01 or DS-F02 (recommend both) |
| ≥1 Resume | DS-R01 or DS-R02 |
| ≥1 Resume DENY | DS-R05 |
| ≥1 Rollback success | DS-B01 or DS-B02 |
| ≥1 Rollback from verify or uploads | DS-B03 or DS-B04 |
| No auto-rollback | DS-M03 and DS-P03 |
| CA denied | DS-P02 |

---

## 7. Coverage matrices

### 7.1 Required Owner classes → scenarios

| Required class | Scenario IDs |
|----------------|--------------|
| Normal successful restore | DS-N01 |
| Every fail-pause | DS-F01…F06 |
| Resume | DS-R01…R05 |
| Rollback | DS-B01…B06 |
| Lock conflict | DS-L01…L04 |
| Maintenance interruption | DS-M01…M04 |
| Schema mismatch | DS-S01…S02 |
| Inventory mismatch | DS-I01…I02 |
| Upload integrity failure | DS-F03, DS-U01…U02 |
| Verification failure | DS-F04, DS-V01…V02 |
| Emergency Break Glass | DS-G01…G03 |

### 7.2 Evidence artifacts touched by drills

| EV | Primary scenarios |
|----|-------------------|
| EV-02 | DS-I01, DS-I02 |
| EV-03 | DS-N01, DS-S01, DS-S02 |
| EV-04 | DS-N01, DS-L*, DS-M01, DS-S01, DS-P01, DS-P04 |
| EV-05 | DS-N01, DS-R01, DS-G*, DS-P02 |
| EV-06 | DS-N01, DS-F*, DS-M*, DS-B06, DS-P03, DS-P04 |
| EV-07 | DS-L*, DS-P03 |
| EV-08 | DS-N01, DS-F03, DS-R03, DS-B03, DS-U* |
| EV-09 | DS-N01, DS-F04, DS-R04, DS-B04, DS-V* |
| EV-10 | DS-F*, DS-R*, DS-B*, DS-M02–M03, DS-P02–P03, DS-U*, DS-V01 |
| EV-11 | Most scenarios (audit) |
| EV-12 | DS-S01, DS-S02 |
| EV-13 | DS-P05 (and all via enablement=false field) |

EV-01 / EV-14 are pack-level (WP-P2-01/05), not individual drill IDs.

---

## 8. PASS / FAIL roll-up for certification drills

| Outcome | Rule |
|---------|------|
| **Scenario PASS** | Meets that scenario’s PASS criteria; required evidence attached; enablement false; no auto-rollback |
| **Scenario FAIL** | Any FAIL criterion; missing evidence; forbidden behavior observed |
| **Suite Evidence Ready (drills)** | All **40** mandatory scenarios PASS (DS-V01 pillars covered) |
| **Cert PASS** | Still **Owner only** after WP-P2-02 checklist — drills alone never grant Cert PASS |

---

## 9. Out of scope

| Item | Deferred |
|------|----------|
| Evidence pack assembly schemas | WP-P2-04 |
| Owner decision package | WP-P2-05 |
| Schema re-cert procedure narrative | WP-P2-06 |
| Live clone execution | P7 |
| Owner Cert ceremony | P8 |
| Enablement | P9 |
| Mutation engine code | P3+ |

---

## 10. Acceptance criteria (WP-P2-03)

| # | Criterion | Result |
|---|-----------|--------|
| AC1 | Complete drill scenario catalog document exists with Artifact-ID | **PASS** |
| AC2 | Normal successful restore defined | **PASS** DS-N01 |
| AC3 | Every fail-pause class covered | **PASS** DS-F01…F06 |
| AC4 | Resume scenarios defined (incl. DENY) | **PASS** DS-R01…R05 |
| AC5 | Rollback scenarios defined (incl. retry + missing pin) | **PASS** DS-B01…B06 |
| AC6 | Lock conflict scenarios defined | **PASS** DS-L01…L04 |
| AC7 | Maintenance interruption scenarios defined | **PASS** DS-M01…M04 |
| AC8 | Schema mismatch scenarios defined | **PASS** DS-S01…S02 |
| AC9 | Inventory mismatch scenarios defined | **PASS** DS-I01…I02 |
| AC10 | Upload integrity failure scenarios defined | **PASS** DS-U01…U02 (+ DS-F03) |
| AC11 | Verification failure scenarios defined | **PASS** DS-V01…V02 (+ DS-F04) |
| AC12 | Emergency Break Glass scenarios defined | **PASS** DS-G01…G03 |
| AC13 | Every scenario maps to OD + P1 + required evidence | **PASS** §4 tables + §7 |
| AC14 | Expected outcome + PASS + FAIL + required evidence per scenario | **PASS** §4 |
| AC15 | Enablement FALSE; no code; Architecture/ODs unmodified | **PASS** H2, H11–H12 |
| AC16 | EV-10 minimum rollback proof set defined | **PASS** §6 |

---

## 11. Stop rule

**WP-P2-03 COMPLETE.**  
Commit → Push → **STOP.**  
Do **not** begin WP-P2-04 until Owner review and approval.

---

*End of WP-P2-03 — Certification Drill Scenario Catalog.*
