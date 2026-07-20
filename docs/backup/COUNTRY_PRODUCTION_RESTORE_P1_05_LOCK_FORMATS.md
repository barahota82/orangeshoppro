# Country Production Restore — P1 Lock File Formats & Cross-Feature Exclusion

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-05** — Lock File Formats & Cross-Feature Exclusion |
| **Artifact-ID** | `CPR-P1-WP05-LOCK_FORMATS` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (OD-LOCK-CROSS · OD-LOCK-SHADOW · OD-LOCK-TTL; Isolation Principle) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §15, §16 (also §13 stale rows) |
| **Depends on** | WP-P1-03 (states / no post-PONR auto-unlock transitions) |
| **Coding** | **No** mutation engine / lock-writer PHP in this WP |

---

## 1. Purpose

Specify exclusive **lock file formats**, **payloads**, **cross-feature exclusion** (CPR ↔ Full DR ↔ C6 ↔ Backup Runner), and **stale-handling** algorithms so implementation cannot invent auto-unlock after PONR or Super Admin bypass of mutual exclusion.

This WP does **not** modify Architecture, Owner Decisions, or prior P1 artifacts. It does **not** change existing Full DR / C6 / Backup Runner PHP.

---

## 2. Hard constraints

| ID | Rule | Authority |
|----|------|-----------|
| H1 | CPR and Full Disaster Recovery are **mutually exclusive**; active one **blocks** the other until finished | OD-LOCK-CROSS |
| H2 | CPR and Country Shadow (C6) are **mutually exclusive / serialized**; second **refused** if one active | OD-LOCK-SHADOW |
| H3 | Restore execution locks use **heartbeat monitoring** | OD-LOCK-TTL |
| H4 | **Pre-PONR:** stale CPR lock may be cleared **only** by Super Admin; every manual unlock **fully audited** | OD-LOCK-TTL |
| H5 | **Post-PONR:** automatic lock release is **permanently forbidden** — no timeout, worker failure, crash, or other circumstance may auto-release | OD-LOCK-TTL |
| H6 | **No** Super Admin **bypass** of exclusion (cannot force CPR while Full DR or C6 active) | OD-LOCK-CROSS · OD-LOCK-SHADOW |
| H7 | One CPR production job globally (per deployment); one country per job | Architecture §16 |
| H8 | Secrets **forbidden** in lock payloads | Architecture §15 |

---

## 3. Engineering defaults vs OWNER_APPROVED policy

| Topic | Classification | Binding value |
|-------|----------------|---------------|
| CPR ↔ Full DR mutual exclusion; no override/bypass | **OWNER_APPROVED** | OD-LOCK-CROSS frozen wording |
| CPR ↔ C6 mutual exclusion / serialize; refuse second | **OWNER_APPROVED** | OD-LOCK-SHADOW frozen wording |
| Heartbeat **monitoring** (not wall-clock auto-fail alone) | **OWNER_APPROVED** | OD-LOCK-TTL; pairs with OD-TIMEOUT progress-aware rule |
| Pre-PONR manual clear: Super Admin **only** + full audit | **OWNER_APPROVED** | OD-LOCK-TTL |
| Post-PONR: **no** automatic lock release under **any** circumstance | **OWNER_APPROVED** | OD-LOCK-TTL |
| Isolation / zero concurrent recovery programs | **OWNER_APPROVED** | Isolation Principle |
| Heartbeat **interval** ≤ 30 seconds | **Engineering default** | Architecture §15 — *unless later Owner-specified*; **not** an Owner numeric freeze |
| Stale **observation** window for alerting (e.g. 3× heartbeat interval = 90s) | **Engineering default** | Monitoring/alerts only — **never** triggers auto-release post-PONR |
| Peer-lock path constants matching current Full DR / C6 / Backup filenames | **Engineering continuity** | Observe existing paths (Architecture §15); do not invent parallel exclusion channels |

**Rule:** Coding phases may tune engineering defaults without reopening ODs. They must **never** weaken OWNER_APPROVED rows above.

---

## 4. Lock inventory & paths

Paths are relative to the deployment restore/backup roots already used by the platform (Architecture §15).

| Lock kind | Canonical path (design) | Feature | Exclusion role |
|-----------|-------------------------|---------|----------------|
| **CPR production lock** | `{workRoot}/country_production/.country_production_restore.lock` | Country Production Restore | Exclusive CPR mutation; peer observers refuse Full DR / C6 / conflicting backups |
| **Full DR global restore lock** | `{workRoot}/.restore.lock` | Full Disaster Recovery | OD-LOCK-CROSS peer — presence of active Full DR blocks CPR acquire |
| **C6 Country Shadow lock** | `{workRoot}/country_shadow/.country_shadow_restore.lock` *(shadow work root; filename `.country_shadow_restore.lock`)* | C6 Shadow Restore | OD-LOCK-SHADOW peer — active C6 blocks CPR; active CPR blocks C6 |
| **Backup Runner lock** | `{backupRoot}/locks/orange_full_backup.lock` | Backup Runner (Full/Country export) | Architecture §15–§16 — block Full/Country export while CPR lock/maint held; CPR must refuse start if runner lock held when exclusion requires idle backup |

**Notes:**

1. Full DR may also use framework/orchestrator locks (e.g. `.restore_framework.lock`, `.restore_execution_orchestrator.lock`). For OD-LOCK-CROSS, CPR treats **any** active Full DR mutation lock family under `{workRoot}` as **Full DR active** (refuse CPR). Primary SoT path for cross-check remains `.restore.lock` plus documented framework locks.  
2. C6 path uses the Country Shadow work root; exact subdirectory helper remains coding-phase (`orange_country_shadow_work_root`). Filename is normative for exclusion.  
3. This WP **defines contracts**; it does not rewrite existing peer lock writers.

---

## 5. Common payload envelope (normative fields)

Architecture §15 requires lock payload (no secrets): `job_id`, `country_id`, `package_id`, `pid`, `heartbeat_at`, `phase`.

### 5.1 Fields required on **CPR** lock

| Field | Type | Required | Meaning |
|-------|------|----------|---------|
| `schema_version` | string | Y | `"cpr_lock/1"` |
| `lock_kind` | string | Y | `"country_production_restore"` |
| `job_id` | string | Y | CPR `job_id` (WP-P1-02) |
| `country_id` | integer | Y | Target country |
| `package_id` | string | Y | Bound Country Recovery Package id |
| `phase` | string | Y | Current CPR phase / WP-P1-03 state name (see §5.3) |
| `ponr_crossed` | boolean | Y | `false` pre-PONR; **`true` from first successful PONR entry onward** (DELETE or uploads replace) |
| `heartbeat_at` | string (ISO-8601 UTC) | Y | Last successful heartbeat |
| `acquired_at` | string (ISO-8601 UTC) | Y | Lock acquire time |
| `pid` | integer | Y | Holding worker OS pid |
| `ownership` | object | Y | Ownership metadata (§5.2) |
| `hostname` | string | Y | Host identity |
| `idempotency_key` | string | N | Echo of job key when known |
| `maint_global_required` | boolean | Y | Always `true` once CPR intends mutation under OD-MAINT (set no later than `cpr_maintenance_on`) |

### 5.2 Ownership metadata object (`ownership`)

| Field | Type | Required | Meaning |
|-------|------|----------|---------|
| `owner_class` | enum | Y | `system_worker` \| `super_admin_procedure` |
| `worker_id` | string | Y | Stable worker instance id (UUID); not a secret |
| `acquired_by_admin_id` | integer\|null | Y | Super Admin id if acquire/clear was admin-driven; else `null` for system worker |
| `acquired_by_username` | string\|null | N | Display username for audit convenience (no password) |
| `lease_token` | string | Y | Opaque non-secret token binding this acquire; required on heartbeat rewrite |
| `deployment_id` | string | N | Deployment / site identity when multi-host |

### 5.3 `phase` vocabulary (CPR)

`phase` **must** equal the WP-P1-03 `state` name of the holding job (e.g. `cpr_maintenance_on`, `cpr_deleting`, `cpr_paused_import`, `cpr_rolling_back`).  
Forbidden: inventing phases that skip pause/Rollback rules.

### 5.4 Peer lock design payloads (Full DR / C6 / Backup Runner)

For **CPR exclusion**, an active peer lock is detected by file presence + valid held semantics (§8).  
For **forward-compatible design** (coding authorization later), peer locks **should** carry the envelope below so cross-feature tooling shares one reader. Existing runtime files may be leaner today; CPR still refuses on **held** peer locks without requiring every legacy field.

#### 5.4.1 Full DR — design payload (`lock_kind = full_disaster_recovery`)

| Field | Required (design) | Notes |
|-------|-------------------|-------|
| `schema_version` | Y | `"full_dr_lock/1"` |
| `lock_kind` | Y | `"full_disaster_recovery"` |
| `job_id` | Y | Full DR job id |
| `country_id` | N\* | `null` or omit for platform-wide Full DR (\*not a country job) |
| `package_id` | N | Full package / backup id when applicable |
| `phase` | Y | Full DR phase string |
| `heartbeat_at` | Y | Design; legacy may use `started_at` only — CPR treats missing heartbeat as held if file exists and peer subsystem marks active |
| `pid` | Y | |
| `ownership` | Y | Same shape as §5.2 (`owner_class`, `worker_id`, …) |
| `hostname` | Y | |

#### 5.4.2 C6 Shadow — design payload (`lock_kind = country_shadow_restore`)

| Field | Required (design) | Notes |
|-------|-------------------|-------|
| `schema_version` | Y | `"c6_shadow_lock/1"` |
| `lock_kind` | Y | `"country_shadow_restore"` |
| `job_id` | Y | C6 `run_id` mapped as job id for exclusion readers |
| `country_id` | Y | Shadow target country |
| `package_id` | Y | Package under shadow proof |
| `phase` | Y | C6 phase / status |
| `heartbeat_at` | Y | Design; refresh while shadow run active |
| `pid` | Y | |
| `ownership` | Y | §5.2 |
| `shadow_db` | Y | Shadow DB name (non-secret) |
| `production_touched` | Y | Must be `false` for valid C6 (observation aid) |

#### 5.4.3 Backup Runner — design payload (`lock_kind = backup_runner`)

| Field | Required (design) | Notes |
|-------|-------------------|-------|
| `schema_version` | Y | `"backup_runner_lock/1"` |
| `lock_kind` | Y | `"backup_runner"` |
| `job_id` | Y | Backup job / run id (generate if legacy lacked one) |
| `country_id` | N | Set for country export; `null` for Full backup |
| `package_id` | N | Output package id when known |
| `phase` | Y | e.g. `exporting`, `verifying`, `finalizing` |
| `heartbeat_at` | Y | Design; legacy may use `started_at` |
| `pid` | Y | |
| `ownership` | Y | §5.2 |
| `backup_mode` | Y | `full` \| `country` \| `other` |

**Legacy observation (non-normative for peer writers):** Today Full DR `.restore.lock` may contain `{pid, hostname, job_id, started_at}`; Backup Runner `{pid, started_at, hostname, sapi}`; C6 flock payload `{run_id, shadow_db, pid, acquired_at, model}`. CPR exclusion **must** recognize these shapes as **held** when present and not released. Enrichment to design payloads is a **later coding** task under separate authorization — **not** a silent change in this WP.

---

## 6. Atomic write / rename rules (locks)

Same durability intent as WP-P1-04 checkpoints (Architecture torn-write caution):

1. Validate payload against the lock schema for `lock_kind`.  
2. Write to `{lockPath}.tmp.{uuid}` (or `.hb.tmp` for heartbeat-only refresh).  
3. Durable close.  
4. Atomic replace/rename onto the canonical lock path.  
5. Acquire uses create-exclusive (`xb` / O_EXCL) when creating a new lock so two workers cannot both succeed.  
6. Heartbeat refresh **must not** drop `ponr_crossed`, `job_id`, `lease_token`, or ownership identity.

Torn write: tmp without final → treat as **not held** only if canonical path absent; never invent release.

---

## 7. Cross-feature exclusion matrix (OD-LOCK-CROSS / OD-LOCK-SHADOW)

| If active… | CPR acquire | Full DR acquire | C6 acquire | Backup Runner (Full/Country export) |
|------------|-------------|-----------------|------------|-------------------------------------|
| **CPR lock held** | Same `job_id` may heartbeat; other CPR job **refuse** | **Refuse** (OD-LOCK-CROSS) | **Refuse** (OD-LOCK-SHADOW) | **Refuse** while CPR lock/maint held (§16) |
| **Full DR lock held** | **Refuse** (OD-LOCK-CROSS) | Peer Full DR rules | **Refuse** (serialize recovery) | Per Full DR/backup subsystem rules |
| **C6 lock held** | **Refuse** (OD-LOCK-SHADOW) | **Refuse** | Same run may continue; other C6 **refuse** | Prefer refuse country export colliding with shadow work root (engineering) |
| **Backup Runner held** | **Refuse** CPR start / pin-export windows that require idle runner | Per Full DR | Prefer refuse | Peer backup rules |

### 7.1 Acquire algorithm (CPR) — normative

```
CPR_ACQUIRE(job):
  1. If enablement/path gates fail → refuse (WP-P1-08; out of detail here)
  2. If Full DR active (any Full DR lock family held) → FAIL code cpr_blocked_full_dr_active
       // OD-LOCK-CROSS — no Super Admin bypass
  3. If C6 lock held → FAIL code cpr_blocked_c6_active
       // OD-LOCK-SHADOW — no override
  4. If Backup Runner lock held when policy requires idle exporter → FAIL cpr_blocked_backup_runner_active
  5. If CPR lock held by other job_id → FAIL country_production_lock_held
       // no steal while heartbeat fresh (Architecture §13/§15)
  6. If CPR lock held by same job_id → refresh heartbeat; OK
  7. Else create CPR lock (ponr_crossed=false) via atomic exclusive create
```

### 7.2 Peer acquire expectations (design)

- Full DR start **must** refuse if CPR lock held (OD-LOCK-CROSS symmetric).  
- C6 start **must** refuse if CPR lock held (OD-LOCK-SHADOW symmetric).  
- **No** “force” flag, **no** Super Admin privilege bit, **no** break-glass may skip H1/H2 (Break Glass cannot bypass Isolation — OD-BREAK list alignment deferred to WP-P1-06; exclusion remains non-bypassable here).

### 7.3 Forbidden behaviors

| Behavior | Status |
|----------|--------|
| Run CPR and Full DR simultaneously | **Forbidden** |
| Run CPR and C6 simultaneously | **Forbidden** |
| Super Admin override to allow parallel | **Forbidden** |
| Steal CPR lock while `heartbeat_at` fresh | **Forbidden** |
| Auto-delete CPR lock because TTL expired **after** `ponr_crossed=true` | **Forbidden** |
| Treat heartbeat stale as automatic success/unlock post-PONR | **Forbidden** |

---

## 8. OD-LOCK-TTL — heartbeat & stale-handling algorithm

### 8.1 Heartbeat monitoring (OWNER_APPROVED)

- Worker **must** refresh `heartbeat_at` on the CPR lock while holding it.  
- **Engineering default:** refresh interval ≤ **30 seconds**.  
- Heartbeat age is for **monitoring / stale classification / Super Admin procedure** — not for silent unlock post-PONR.  
- OD-TIMEOUT: elapsed wall-clock alone must not fail the job; progress includes heartbeat (Owner Decision OD-TIMEOUT).

### 8.2 Stale classification (engineering observation)

```
HEARTBEAT_INTERVAL_DEFAULT = 30s          # engineering default
STALE_OBSERVE_AFTER = 3 * HEARTBEAT_INTERVAL_DEFAULT   # e.g. 90s — engineering default

is_heartbeat_stale = (now - heartbeat_at) > STALE_OBSERVE_AFTER
  OR pid clearly dead (best-effort OS check)
```

`is_heartbeat_stale` is a **signal**. It does **not** authorize automatic release when `ponr_crossed === true`.

### 8.3 Pre-PONR stale clear (OWNER_APPROVED)

Preconditions:

1. CPR lock exists.  
2. `ponr_crossed === false`.  
3. Job is still in a **pre-PONR** WP-P1-03 state class (N).  
4. Actor is **Super Admin** (not Country Admin).  
5. Operator confirms stale (heartbeat stale and/or pid dead).  
6. Manual clear writes an **audit record** (§9) **before or atomically with** lock file removal.  
7. Only then may delete/rename-away the CPR lock.

**Forbidden pre-PONR:** automatic unlink by worker watchdog; Country Admin clear; clear without audit.

### 8.4 Post-PONR stale / crash (OWNER_APPROVED)

When `ponr_crossed === true` **or** WP-P1-03 state class is post-PONR (X):

| Action | Allowed? |
|--------|----------|
| Continue heartbeat if worker resumes same `job_id` + `lease_token` | Yes (Resume path — WP-P1-09) |
| Automatic unlock on timeout | **No** |
| Automatic unlock on worker crash | **No** |
| Automatic unlock on stale heartbeat | **No** |
| Automatic unlock on deploy restart | **No** |
| Super Admin “stale clear” that only deletes the lock without Resume/Rollback/incident procedure | **No** as a silent unlock — post-PONR recovery is Super Admin **procedure** (Resume / Rollback / documented incident) with Maint rules; lock release only as part of that authorized closeout, never as TTL auto-recovery |
| Super Admin bypass to start a second CPR/Full DR/C6 while lock held | **No** |

**Invariant:** System Integrity > automatic recovery (OD-LOCK-TTL).

### 8.5 Normal release (non-stale)

CPR lock may be released when:

1. Job reaches an authorized terminal closeout that architecture allows unlock **and**  
2. Release is performed by the holding worker or Super Admin closeout procedure **and**  
3. If post-PONR success/rollback path: release occurs only with the authorized maintenance-release / job finalization sequence (WP-P1-03 / WP-P1-07) — **never** because a timer fired.

Pre-PONR cancel/fail terminals may release CPR lock after Super Admin/system closeout **without** Rollback (no production slice mutation).

---

## 9. Manual clear audit record (pre-PONR)

Every Super Admin pre-PONR manual unlock **must** append a durable audit artifact:

**Path (design):**  
`{workRoot}/country_production/{job_id}/audit/lock_manual_clear_{iso8601}_{uuid}.json`  
(and/or platform `audit_log` equivalent in coding phase — same fields)

| Field | Required | Meaning |
|-------|----------|---------|
| `event_type` | Y | `"cpr_lock_manual_clear"` |
| `job_id` | Y | |
| `country_id` | Y | |
| `package_id` | Y | |
| `cleared_at` | Y | ISO-8601 UTC |
| `cleared_by_admin_id` | Y | Super Admin id |
| `cleared_by_username` | N | |
| `reason` | Y | Free text; min length 8 |
| `ponr_crossed_observed` | Y | Must be `false` or clear **aborts** |
| `phase_observed` | Y | Phase at clear |
| `prior_lock_sha256` | Y | Hash of lock file bytes before clear |
| `prior_heartbeat_at` | Y | |
| `prior_pid` | Y | |
| `stale_evidence` | Y | Why stale (heartbeat age, pid dead, etc.) |
| `lease_token_observed` | Y | From prior lock |

Missing audit → **clear forbidden**.

---

## 10. CPR lock JSON Schema (critical excerpt)

```json
{
  "$id": "cpr_lock_v1",
  "type": "object",
  "required": [
    "schema_version", "lock_kind", "job_id", "country_id", "package_id",
    "phase", "ponr_crossed", "heartbeat_at", "acquired_at", "pid",
    "ownership", "hostname", "maint_global_required"
  ],
  "properties": {
    "schema_version": { "const": "cpr_lock/1" },
    "lock_kind": { "const": "country_production_restore" },
    "job_id": { "type": "string", "minLength": 1 },
    "country_id": { "type": "integer", "minimum": 1 },
    "package_id": { "type": "string", "minLength": 1 },
    "phase": { "type": "string", "minLength": 1 },
    "ponr_crossed": { "type": "boolean" },
    "heartbeat_at": { "type": "string", "minLength": 1 },
    "acquired_at": { "type": "string", "minLength": 1 },
    "pid": { "type": "integer", "minimum": 1 },
    "hostname": { "type": "string", "minLength": 1 },
    "maint_global_required": { "type": "boolean" },
    "ownership": {
      "type": "object",
      "required": ["owner_class", "worker_id", "acquired_by_admin_id", "lease_token"],
      "properties": {
        "owner_class": { "enum": ["system_worker", "super_admin_procedure"] },
        "worker_id": { "type": "string", "minLength": 1 },
        "acquired_by_admin_id": { "type": ["integer", "null"] },
        "acquired_by_username": { "type": ["string", "null"] },
        "lease_token": { "type": "string", "minLength": 1 },
        "deployment_id": { "type": "string" }
      }
    }
  },
  "additionalProperties": false
}
```

---

## 11. Binding to WP-P1-03

| WP-P1-03 rule | Lock implication |
|---------------|------------------|
| R2 — no post-PONR automatic unlock transition | §8.4; `ponr_crossed=true` forbids TTL auto-delete |
| Pre-PONR terminals | Lock release allowed after audited closeout |
| Post-PONR pause / Rollback | Lock **remains held**; heartbeat continues or Super Admin procedure owns recovery |
| `cpr_maintenance_released` | Lock release only with authorized closeout — never timer |

On first transition into post-PONR (`cpr_deleting` after successful PONR entry, or uploads replace path): worker **must** rewrite lock with `ponr_crossed=true` before further mutation.

---

## 12. Register / Architecture citation map

| Contract element | OD / Principle | Frozen wording locus | Architecture |
|------------------|----------------|----------------------|--------------|
| CPR ↔ Full DR exclusive | OD-LOCK-CROSS | §15 Frozen | §15, §16 |
| CPR ↔ C6 exclusive | OD-LOCK-SHADOW | §15 Frozen | §15, §16 |
| Heartbeat; pre-PONR SA clear+audit; no post-PONR auto-release | OD-LOCK-TTL | §15 Frozen | §13, §15 |
| Isolation / no concurrent recovery | Isolation Principle | Register | §16 |
| Payload field list | (baseline) | — | §15 |
| Heartbeat ≤30s | Engineering default | — | §15 |
| No steal while heartbeat fresh | (baseline) | — | §13 / risks |

---

## 13. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Spec forbids post-PONR auto-release under **all** circumstances | **PASS** — H5; §8.4 |
| Concurrent CPR+Full DR and CPR+C6 are refuse/block | **PASS** — H1/H2; §7 |
| No Super Admin bypass of exclusion | **PASS** — H6; §7.2–§7.3 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| Lock formats for CPR / Full DR / C6 / Backup Runner | **PASS** — §4, §5 |
| Payloads include job_id, country_id, package_id, heartbeat, phase, ownership | **PASS** — §5 (peer design + CPR normative) |
| OD-LOCK-CROSS / SHADOW / TTL design contracts | **PASS** — §7, §8 |
| Heartbeat monitoring only (no TTL auto-unlock as recovery) | **PASS** — §3, §8 |
| Pre-PONR manual clear Super Admin only + auditable | **PASS** — §8.3, §9 |
| Engineering defaults vs OWNER_APPROVED distinguished | **PASS** — §3 |
| Architecture / Register / prior WPs unmodified | **PASS** — this file + index status only |

---

## 14. Assumptions

1. Peer lock **writers** in current PHP may lag design payloads; CPR exclusion uses **held detection** first.  
2. Full DR “lock family” includes `.restore.lock` and active framework/orchestrator locks under the same work root.  
3. Alerting thresholds (WP-P1-12) consume `is_heartbeat_stale` without unlocking.  
4. Authority UX for who may clear (Super Admin only) is detailed further in WP-P1-06; this WP already binds OD-LOCK-TTL actor rule.  

---

## 15. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Accidental post-PONR auto-unlock | Critical | H5; `ponr_crossed`; forbid watchdog unlink |
| Concurrent CPR + Full DR | Critical | Symmetric refuse §7 |
| Concurrent CPR + C6 | Critical | OD-LOCK-SHADOW refuse |
| Treating ≤30s as Owner policy | Medium | §3 labeling |
| Stealing lock on stale pre-PONR without audit | High | §8.3, §9 |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 16. Out of scope

- PHP lock acquire/release implementation  
- WP-P1-06 Break Glass / permission UI tables  
- WP-P1-12 alert channel wiring  
- Changing existing Full DR / C6 / Backup Runner code  

---

*End of WP-P1-05. STOP — do not begin WP-P1-06 until Owner review and approval.*
