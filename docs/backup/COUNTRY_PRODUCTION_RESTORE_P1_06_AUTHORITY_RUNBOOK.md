# Country Production Restore — P1 Authority, Permissions & Runbook Contracts

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-06** — Authority, Permissions & Runbook Contracts |
| **Artifact-ID** | `CPR-P1-WP06-AUTHORITY_RUNBOOK` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-DUAL · OD-PERM · OD-PHRASE · OD-BREAK · OD-RUNBOOK; also OD-CERT / OD-ENABLE for Owner/Engineering bounds) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §7, §8, §25–§27 |
| **UX clarification (non-SoT)** | `COUNTRY_PRODUCTION_RESTORE_SUPER_ADMIN_OPERATIONAL_MODEL.md` — dashboard presentation only; register wins on conflict |
| **Depends on** | WP-P1-02 · WP-P1-03 · WP-P1-04 (`runbook_pre_ponr` evidence binding) |
| **Coding** | **No** mutation engine / UI PHP in this WP |

---

## 1. Purpose

Encode OWNER_APPROVED **OD-DUAL**, **OD-PERM**, **OD-PHRASE**, **OD-BREAK**, and **OD-RUNBOOK** as checkable authority contracts: Workflow A/B, role capability matrices, phrase + password re-auth challenge records, Break Glass non-bypass list, Runbook minimum fields, Runbook completion gate, and Global Maintenance release gate.

This WP does **not** modify Architecture, Owner Decisions, or prior P1 artifacts. It does **not** invent an Approver role, dual-Super-Admin model, or waiver execution path.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | **One** global Super Admin + Country Admins — **not** dual-Super-Admin | OD-DUAL |
| H2 | **No** waiver-of-authority / waiver execution path | OD-DUAL · OD-PERM · Integrity Principle |
| H3 | **No** distinct “Approver” role | Architecture §7 |
| H4 | Country Admin **never** approve / execute / resume / rollback / release Global Maint / enable-disable CPR | OD-PERM |
| H5 | Super Admin authority **exactly** matches OD-PERM frozen wording | OD-PERM |
| H6 | Confirmation phrase is exactly **`RESTORE`** (case-sensitive literal); Super Admin must **type** it | OD-PHRASE |
| H7 | Password re-authentication mandatory before execution / PONR in **both** WF-A and WF-B | OD-PHRASE · OD-DUAL |
| H8 | Break Glass: Super Admin only; **cannot** bypass Full Rollback Anchor, mandatory safety gates, logging, or authentication | OD-BREAK |
| H9 | Pre-PONR Runbook checklist mandatory + fully audited; Global Maint **never** released until Runbook successfully completed | OD-RUNBOOK |
| H10 | **No privilege escalation** — Country Admin cannot gain Super Admin capabilities; Engineering cannot grant itself Owner/Super Admin authority | OD-PERM · OD-CERT · Governance Principle |
| H11 | Mutation remains non-HTTP workers; dashboard is control plane only | Architecture §4.1 / §8 |

---

## 3. Roles (identity classes)

| Role | Definition (CPR) |
|------|------------------|
| **Country Admin** | Country-scoped admin; may act only for **own** `country_id` |
| **Super Admin** | Single global Super Admin identity class (one platform Super Admin model) |
| **Owner** | Business/owner authority for certification PASS/FAIL and explicit enablement order (OD-CERT / OD-ENABLE) — not a day-to-day CPR executor |
| **Engineering** | Technical implementers / on-call producing evidence and incident support; **never** final cert approval |
| **System** | Automated gate/worker actions under frozen contracts (not a human privilege grant) |

**Forbidden identity inventions:** dual Super Admin pair; named Approver; temporary elevate Country Admin; anonymous execute; shared Super Admin password without individual audit actor id.

---

## 4. OD-DUAL — Workflow A and Workflow B contracts

### 4.1 Workflow A (Super Admin end-to-end)

| Step | Actor | Action | Artifact | Blocks mutation? |
|------|-------|--------|----------|------------------|
| A1 | System / Super Admin | Package eligibility (C4+C5) | Gate reports | Yes |
| A2 | System / Super Admin | Shadow chain C6 ready + C7 READY + C8 **SAFE** | Shadow reports | Yes |
| A3 | **Super Admin** | Creates and manages CPR job from the beginning (`workflow = A`) | Job record | Yes |
| A4 | — | **No second human approver** | N/A | — |
| A5 | System + Super Admin | Technical protections: OD-PIN session Full Backup, gates PASS, GLOBAL Maint ON, OD-RUNBOOK checklist, one-time authorization | CP1 / runbook / auth | Yes |
| A6 | **Super Admin** | Password re-auth + type phrase `RESTORE` | `cpr_auth_challenge` (§7) | Yes |
| A7 | **Super Admin** | Authorize PONR / execute orchestration (workers perform mutation) | Cutover auth + job state | Releases mutation path only after A5–A6 |
| A8 | **Super Admin** | Resume / Rollback if paused (post-PONR) | Pause decisions | Maint stays ON |
| A9 | **Super Admin** | Release GLOBAL Maintenance **only after** Runbook successfully completed | Maint release auth | Releases writers |

**WF-A rule:** Absence of a second approver does **not** waive A5–A6. Technical protections remain mandatory (OD-DUAL frozen wording).

### 4.2 Workflow B (Country Admin request → Super Admin approve/execute)

| Step | Actor | Action | Artifact | Blocks mutation? |
|------|-------|--------|----------|------------------|
| B1 | Country Admin / System | Prepare package; complete C3–C8 for **own country** | Package + reports | Yes |
| B2 | **Country Admin** | Request Production Restore (`workflow = B`) | Job → `pending_super_admin_approval` / `cpr_awaiting_approvals` | Yes |
| B3 | **Super Admin alone** | Approve **or** reject | Approval record + fingerprint (WP-P1-02/03) | Yes |
| B4 | System + Super Admin | Same technical protections as WF-A A5 | Same | Yes |
| B5 | **Super Admin alone** | Password re-auth + phrase `RESTORE` | `cpr_auth_challenge` | Yes |
| B6 | **Super Admin alone** | Execute / PONR authorize | Cutover auth | Yes |
| B7–B8 | **Super Admin alone** | Resume / Rollback / maint release (Runbook-gated) | Same as A8–A9 | Yes |

**WF-B rule:** Country Admin **cannot** execute Production Restore. Only Super Admin may approve and execute (OD-DUAL).

### 4.3 Common mandatory protections (both workflows)

Every CPR job, WF-A or WF-B, **must** satisfy before PONR:

1. Full Rollback Anchor — new session Full Backup verified + pinned (OD-PIN)  
2. All mandatory gates PASS (incl. C8 **SAFE**)  
3. GLOBAL Maintenance Mode ON + write-block proven (OD-MAINT / OD-MAINT-SCOPE)  
4. Confirmation phrase `RESTORE` typed (OD-PHRASE)  
5. Password re-authentication (OD-PHRASE / OD-DUAL)  
6. Complete audit log  
7. One-time authorization (`one_time_authorization_id` — WP-P1-02)  
8. OD-RUNBOOK pre-PONR checklist completed and audited  

### 4.4 Explicitly forbidden authority shapes

| Shape | Status |
|-------|--------|
| Dual Super Admin (creator ≠ executor as required second Super Admin) | **Forbidden** (withdrawn) |
| Waiver execution / “skip dual control” flag | **Forbidden** |
| Country Admin self-approve / self-execute | **Forbidden** |
| Approver role distinct from Super Admin | **Forbidden** |
| Inherit Full DR Audit R3 two-person dual-control as CPR SoT | **Forbidden** (Architecture §8.2) |

---

## 5. OD-PERM — Complete capability matrix

Legend: **Y** = permitted · **N** = forbidden · **C** = own country only · **P** = only after OD-ENABLE preconditions (Certification PASS + explicit Owner enablement order + implementation completed + Final Enterprise approval) · **R** = only after OD-RUNBOOK successfully completed

### 5.1 Matrix

| Capability | Country Admin | Super Admin | Owner | Engineering | System |
|------------|:-------------:|:-----------:|:-----:|:-----------:|:------:|
| View CPR status (own country) | **Y (C)** | Y | Y (ops oversight) | Y (support read) | Y (status write) |
| View CPR status (other country) | **N** | Y | Y | Y (support; no mutate) | Y |
| Prepare C3–C8 | **Y (C)** | Y | N (not operator) | Y (tooling/evidence) | Y |
| Request Production Restore (create WF-B job) | **Y (C)** | Y (creates WF-A instead) | N | N | N |
| Create / manage WF-A job end-to-end | N | **Y** | N | N | N |
| Approve Production Restore (WF-B) | **N** | **Y alone** | N | N | N |
| Reject / cancel pre-PONR (authority path) | N (may withdraw own request only if pre-approval policy allows — **never** post-approve execute) | **Y** | N | N | Soft-timeout cancel per WP-P1-03 |
| Execute Production Restore / authorize PONR | **N** | **Y alone** | N | N | Workers only after SA auth |
| Password re-auth + phrase `RESTORE` challenge | N | **Y** (must) | N | N | Validates only |
| Complete OD-RUNBOOK checklist | N | **Y** (must) | N | N | Validates |
| Resume Production Restore | **N** | **Y alone** | N | N | Continues only after SA |
| Rollback Production Restore | **N** | **Y alone** | N | N | Full rollback worker after SA |
| Emergency stop | N | **Y** | N | N | Cooperative halt |
| Pre-PONR stale lock manual clear | N | **Y** (audited; WP-P1-05) | N | N | N |
| Release Global Maintenance | **N** | **Y alone (R)** | N | N | N |
| Enable Country Production Restore flag | **N** | **Y alone (P)** | Order only (OD-ENABLE) | N | N |
| Disable Country Production Restore flag | **N** | **Y alone** | May order disable | N | N |
| Final certification PASS/FAIL | N | N | **Y alone** (OD-CERT) | **N** | N |
| Explicit enablement order | N | N (executes flag only after order) | **Y** (OD-ENABLE) | N | N |
| Grant final certification approval | N | N | Y | **N** | N |
| Mutate production inside HTTP request | N | N | N | N | N |
| Bypass gates / anchor / audit via privilege | N | N | N | N | N |
| Break Glass emergency path | N | **Y** (OD-BREAK limits) | N | N | N |

### 5.2 Country Admin — permitted (closed list)

1. View CPR status for **own** `country_id` only.  
2. Prepare C3–C8 for own country.  
3. Request Country Production Restore (WF-B) for own country.

### 5.3 Country Admin — forbidden (non-exhaustive hard denies)

| Forbidden action | Code (design) |
|------------------|---------------|
| Approve Production Restore | `cpr_perm_denied_approve` |
| Execute Production Restore / PONR | `cpr_perm_denied_execute` |
| Resume | `cpr_perm_denied_resume` |
| Rollback | `cpr_perm_denied_rollback` |
| Release Global Maintenance | `cpr_perm_denied_maint_release` |
| Enable CPR | `cpr_perm_denied_enable` |
| Disable CPR | `cpr_perm_denied_disable` |
| Act for another country | `cpr_perm_denied_cross_country` |
| Complete Runbook as authority sign-off | `cpr_perm_denied_runbook` |
| Submit phrase/password challenge for PONR | `cpr_perm_denied_phrase` |
| Break Glass | `cpr_perm_denied_break_glass` |
| Clear CPR locks | `cpr_perm_denied_lock_clear` |

### 5.4 Super Admin — permitted (matches OD-PERM §14/§15)

Super Admin **alone** may:

1. Approve Production Restore (WF-B).  
2. Execute Production Restore.  
3. Resume Production Restore.  
4. Execute Rollback.  
5. Release Global Maintenance (**only** after Runbook successfully completed — OD-RUNBOOK).  
6. Enable or disable Country Production Restore (**enable** only after OD-ENABLE preconditions including explicit Owner enablement order).  
7. Create/manage WF-A end-to-end (still with mandatory technical protections).  
8. Emergency stop; Break Glass under OD-BREAK non-bypass list; audited pre-PONR lock clear (WP-P1-05).  
9. Complete OD-RUNBOOK checklist; satisfy OD-PHRASE challenge.

Super Admin **must not**:

- Waive gates, OD-PIN anchor, logging, authentication, or OD-RUNBOOK.  
- Auto-unlock post-PONR (WP-P1-05 / OD-LOCK-TTL).  
- Grant Country Admin any Super Admin capability.  
- Grant final Certification PASS/FAIL (Owner only — OD-CERT).  
- Flip enablement `true` without OD-ENABLE preconditions.

### 5.5 Owner — permitted / forbidden

| Permitted | Forbidden |
|-----------|-----------|
| Final Certification PASS/FAIL (OD-CERT) | Day-to-day approve/execute/resume/rollback CPR as a substitute Super Admin role (Owner is not the OD-PERM executor) |
| Explicit enablement order (OD-ENABLE) | Waiving Integrity/Isolation/gates by decree without new OWNER_APPROVED decision |
| Schema re-authorization after OD-SCHEMA invalidation (order) | Silent privilege escalation of Engineering |

### 5.6 Engineering — permitted / forbidden

| Permitted | Forbidden |
|-----------|-----------|
| Produce technical evidence, verification reports, certification **artifacts** | Final certification approval (OD-CERT) |
| Incident support / tooling under Super Admin direction | Approve / execute / resume / rollback / maint release / enable-disable |
| Read logs for diagnosis | Privilege escalation to Super Admin; waiver flags; skipping gates |

---

## 6. OD-BREAK — Break Glass contract

### 6.1 Availability

- **Actor:** Super Admin **only**.  
- **Purpose:** Emergency Super Admin path with mandatory reason, full audit, and notification.  
- **Not** a waiver of OD-DUAL WF-B approval when a WF-B job is awaiting approval — Break Glass does **not** convert Country Admin request into unapproved execute.  
- **Not** a bypass of safety chassis.

### 6.2 Mandatory requirements (on every Break Glass use)

| Requirement | Rule |
|-------------|------|
| `emergency_reason` | Non-empty; min length 16; audited |
| Full audit log | Immutable event with actor, job_id, timestamps, fingerprints |
| Notification | Out-of-band / platform notification record id required |
| Authentication | Password re-auth still required |
| Phrase | `RESTORE` still required before PONR/execution |

### 6.3 Non-bypass list (hard)

Break Glass **DOES NOT bypass**:

1. Full Rollback Anchor (OD-PIN)  
2. Mandatory safety gates (incl. C8 SAFE, OD-INV, lock exclusion, schema, FA gates as applicable)  
3. Logging / audit  
4. Authentication (password re-auth)  
5. GLOBAL Maintenance requirement (OD-MAINT)  
6. OD-RUNBOOK completion before maint release  
7. OD-LOCK-CROSS / OD-LOCK-SHADOW exclusion  
8. Post-PONR no-auto-unlock (OD-LOCK-TTL)  
9. Integrity / Isolation / Governance Principles  

### 6.4 Break Glass record schema (`cpr_break_glass_event`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `event_id` | Y | UUID |
| `job_id` | Y | |
| `country_id` | Y | |
| `package_id` | Y | |
| `opened_by_admin_id` | Y | Super Admin |
| `opened_at` | Y | ISO-8601 |
| `emergency_reason` | Y | |
| `notification_ref` | Y | |
| `auth_challenge_id` | Y | Links §7 challenge used |
| `non_bypass_ack` | Y | Boolean `true` — operator acknowledged non-bypass list |
| `audit_record_id` | Y | |

---

## 7. OD-PHRASE — Auth challenge contract

### 7.1 Rules

| Rule | Value |
|------|-------|
| Phrase literal | Exactly `RESTORE` (no trim variants that alter meaning; reject `restore`, `Restore`, `COUNTRY_RESTORE`, padded lookalikes after normalization policy: **exact match after forbidding leading/trailing whitespace-only acceptance of wrong tokens** — implementer must require typed value **identical** to `RESTORE`) |
| Who | Super Admin only |
| When | Immediately before Country Production execution / PONR (both WF-A and WF-B) |
| Password re-auth | Mandatory; verify Super Admin password (or platform-equivalent re-auth factor already used for Super Admin session elevation) **in the same challenge ceremony** |
| One-time | Challenge success mints / binds `one_time_authorization_id`; reuse forbidden (WP-P1-02) |
| Does not bypass | Gates, OD-PIN anchor, maintenance, audit, Runbook |

### 7.2 Challenge record schema (`cpr_auth_challenge`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `challenge_id` | Y | UUID |
| `job_id` | Y | |
| `workflow` | Y | `A` \| `B` |
| `admin_id` | Y | Super Admin |
| `password_reauth_ok` | Y | `true` only after successful verify — **never store password** |
| `password_reauth_at` | Y | ISO-8601 |
| `phrase_submitted_hash` | Y | Hash of submitted phrase (e.g. SHA-256 of exact bytes) — optional store; **must not** store raw if policy prefers hash-only |
| `phrase_accepted` | Y | `true` only if exact `RESTORE` |
| `one_time_authorization_id` | Y | Bound on success |
| `contract_fingerprint` | Y | Fingerprint of frozen contract at challenge time |
| `runbook_evidence_ref` | Y | Must reference completed `runbook_pre_ponr` |
| `created_at` | Y | |
| `consumed_at` | N | Set when used for PONR; second use → reject |
| `audit_record_id` | Y | |

**Secrets forbidden:** password plaintext, session tokens, DB credentials.

### 7.3 Validation algorithm (normative)

```
PHRASE_CHALLENGE(job, admin):
  1. Assert admin is Super Admin
  2. Assert job workflow protections satisfied (WF-A technical path or WF-B approved)
  3. Assert runbook_pre_ponr complete (§8)
  4. Assert OD-PIN pinned; GLOBAL maint ON; gates PASS; enablement path OK for execute design
  5. Verify password re-auth success (no password persisted)
  6. Require typed phrase === "RESTORE"
  7. Mint one_time_authorization_id; write cpr_auth_challenge; audit
  8. On any failure → reject; no PONR
```

---

## 8. OD-RUNBOOK — Checklist, completion gate, maint release gate

### 8.1 Minimum required checklist fields (OWNER_APPROVED)

Before PONR, Super Admin must explicitly complete:

| Checklist item | Field (align WP-P1-04 §7.1) | Acceptance |
|----------------|-----------------------------|------------|
| Restore Package ID | `restore_package_id` | Equals contract `package_id` |
| Target Country | `target_country_id` + `target_country_code` | Equals contract |
| C8 Overall Result = SAFE | `c8_overall_result` | Exactly `SAFE` |
| Certified Inventory Snapshot | `certified_inventory_snapshot_id` | Equals contract `inventory_snapshot_id` |
| Session Full Backup ID | `session_full_backup_id` | Equals OD-PIN session backup id |
| Global Maintenance active | `global_maintenance_active` | `true` |

Additional evidence fields (design; required for auditability):

| Field | Rule |
|-------|------|
| `completed_by_admin_id` | Super Admin |
| `completed_at` | ISO-8601 |
| `audit_record_id` | Immutable audit link |
| `job_id` | Bound job |
| `checklist_version` | `"od_runbook/1"` |
| `all_minimum_items_confirmed` | `true` only if every minimum item verified by system against contract/live proofs — **not** unchecked human ticks alone |

**Human confirmation + machine verify:** Super Admin confirms; system **re-reads** contract/gate/maint/pin values and **rejects** Runbook completion on mismatch (Governance / Integrity).

### 8.2 Runbook completion gate

| Gate | Rule |
|------|------|
| PONR | **Forbidden** unless `runbook_pre_ponr` committed (WP-P1-04) and §8.1 satisfied |
| CP-A | **Forbidden** without Runbook evidence (WP-P1-04) |
| Auth challenge | **Forbidden** without `runbook_evidence_ref` |

### 8.3 Global Maintenance release gate

| Gate | Rule |
|------|------|
| Maint release | Super Admin **alone** (OD-PERM) |
| Runbook | Global Maintenance shall **never** be released until the Runbook has been **successfully completed** (OD-RUNBOOK) |
| Job state | Only from authorized terminals per WP-P1-03 (`cpr_succeeded` / `cpr_rollback_completed` / authorized pre-PONR closeout when Maint was ON) |
| Evidence | Release authorization record must cite `runbook_completed=true` and `runbook_evidence_ref` |

**Forbidden:** Release Maint because wall-clock elapsed; release by Country Admin; release with incomplete Runbook; release to “unblock users” while job still paused post-PONR without Super Admin closeout.

### 8.4 Maint release authorization schema (`cpr_maint_release_authorization`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `release_id` | Y | UUID |
| `job_id` | Y | |
| `released_by_admin_id` | Y | Super Admin |
| `released_at` | Y | |
| `runbook_completed` | Y | Must be `true` |
| `runbook_evidence_ref` | Y | |
| `prior_terminal_state` | Y | Allowed terminal/closeout state |
| `write_block_cleared_proof` | Y | Evidence id after release procedure |
| `audit_record_id` | Y | |

---

## 9. Audit requirements (cross-cutting)

Every authority-sensitive action **must** emit an immutable audit event containing at minimum:

| Field | Required |
|-------|:--------:|
| `audit_id` | Y |
| `at` | Y |
| `actor_admin_id` | Y (or `system`) |
| `actor_role` | Y |
| `action` | Y |
| `job_id` | Y when job-scoped |
| `country_id` | Y when country-scoped |
| `package_id` | Y when package-scoped |
| `workflow` | Y when job-scoped (`A`/`B`) |
| `result` | Y (`allowed` / `denied` / `completed`) |
| `denial_code` | Y if denied |
| `fingerprints` | Y when binding contract/approval/challenge |
| `evidence_refs` | Y when Runbook/challenge/Break Glass/maint release |

### 9.1 Actions that always audit

- WF-B request, approve, reject  
- WF-A job create  
- Runbook complete  
- Auth challenge success/failure  
- PONR authorize / execute start  
- Resume, Rollback, emergency stop  
- Break Glass open  
- Pre-PONR lock manual clear  
- Maint release  
- Enable / disable CPR flag  
- Any permission denial on the Country Admin hard-deny list  

### 9.2 Audit integrity rules

- Append-only; no edit of prior events.  
- Denied attempts audited (no silent drop).  
- Secrets never in audit payload.

---

## 10. Binding to prior WPs (read-only)

| Prior WP | Binding |
|----------|---------|
| WP-P1-02 | `workflow`, `one_time_authorization_id`, approval fingerprints |
| WP-P1-03 | Who may trigger Resume/Rollback/PONR/maint release transitions |
| WP-P1-04 | `runbook_pre_ponr.json` fields; CP12 requires `runbook_completed` |
| WP-P1-05 | Super Admin–only pre-PONR lock clear + audit; no exclusion bypass |

No edits to those artifacts in this WP.

---

## 11. Register / Architecture citation map

| Contract element | OD / Principle | Frozen wording locus | Architecture |
|------------------|----------------|----------------------|--------------|
| WF-A / WF-B; no dual-SA; CA never execute | OD-DUAL | §15 Frozen | §7, §8 |
| CA/SA capability matrix | OD-PERM | §15 Frozen | §26, §27 |
| Phrase `RESTORE` + password re-auth | OD-PHRASE | §15 Frozen | §8.2, §25 |
| Break Glass non-bypass | OD-BREAK | §15 Frozen | §8.2 |
| Runbook min fields; maint release gate | OD-RUNBOOK | §15 Frozen | §25, §9 runbook gate |
| Owner cert PASS/FAIL; Eng never final | OD-CERT | §15 Frozen | §26, §27 |
| Enablement order / flag false until path | OD-ENABLE | §15 Frozen | §27 enable row |
| No waiver / integrity over privilege | Integrity · Governance | Principles | §7, §8.2 |

---

## 12. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| No dual-Super-Admin | **PASS** — H1; §4.4 |
| No waiver execution | **PASS** — H2; §4.4; §6.3 |
| Country Admin cannot approve/execute/resume/rollback/release maint/enable | **PASS** — §5.1–§5.3 |
| Phrase exactly `RESTORE` | **PASS** — H6; §7 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| OD-DUAL / OD-PERM / OD-PHRASE / OD-BREAK / OD-RUNBOOK fully encoded | **PASS** — §4–§8 |
| WF-A and WF-B complete contracts | **PASS** — §4 |
| Permitted/forbidden for CA / SA / Owner / Engineering | **PASS** — §5 |
| Password re-auth challenge + audit | **PASS** — §7, §9 |
| Break Glass restrictions | **PASS** — §6 |
| Runbook minimum fields + completion gate + Maint release gate | **PASS** — §8 |
| Super Admin matches OD-PERM frozen register | **PASS** — §5.4 |
| No privilege escalation | **PASS** — H10; §5 |
| Architecture / Register / prior WPs unmodified | **PASS** |

---

## 13. Assumptions

1. Super Admin Operational Model is UX clarification only; this WP is the P1 checkable authority contract.  
2. Detailed Resume/Rollback stage safety is WP-P1-09; this WP only assigns **who** may invoke those actions.  
3. Enablement flag flip mechanics are expanded in WP-P1-13; this WP binds OD-PERM/OD-ENABLE actor rules.  
4. `runbook_pre_ponr` path/fields remain as defined in WP-P1-04; this WP adds authority gates and maint-release authorization schema.

---

## 14. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Inventing Approver / dual-SA | High | §4.4 hard forbid |
| Waiver / Break Glass as gate skip | Critical | §6.3 non-bypass list |
| Country Admin UI accidentally exposes Resume/Rollback | High | §5.3 denial codes; Architecture §26 |
| Runbook checkbox without machine verify | High | §8.1 `all_minimum_items_confirmed` |
| Maint release before Runbook | Critical | §8.3 |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 15. Out of scope

- PHP permission middleware / dashboard UI  
- WP-P1-07 maint duration/timeout signal schemas  
- WP-P1-09 Resume/Rollback stage algorithms  
- WP-P1-13 enablement ceremony details beyond actor rules  

---

*End of WP-P1-06. STOP — do not begin WP-P1-07 until Owner review and approval.*
