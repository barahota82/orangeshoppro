# Orange Production Cutover & Rollback Design (Phase 3B.4)

**Status:** DESIGN ONLY — implementation contract for remaining restore work.  
**Date:** 2026-07-17  
**Scope:** Full Disaster Restore production cutover + rollback on Windows / Plesk / MariaDB / PHP PDO.  
**Non-goals of this phase:** no production cutover code, no maintenance activation, no rollback execution, no CLI/worker execution, no execute/cutover/resume endpoints.

**Grounding references (mandatory):**

- `docs/backup/RESTORE_EXECUTION_DESIGN.md`
- `docs/backup/RESTORE_PHASE2_CLI_ENTRYPOINTS.md`
- Phase 3B modules: framework, bridge, final approval, pre-restore backup, shadow DB, shadow verify, shadow files, shadow smoke, maintenance framework, version lock
- Phase 2 cutover primitives: `restore_merge_db_cutover.php`, `restore_merge_uploads_cutover.php`, `restore_merge_rollback.php`, `restore_merge_maintenance.php`, `restore_e2e_orchestrator.php`
- Import crash-safety contract: `docs/backup/PRODUCTION_IMPORT_SAFETY.md` (Phase 3B.4A)

**Prerequisite gate (already built):** job must reach a cutover-readiness decision with prior shadow reports successful. Even then, **`production_cutover_allowed` remains false until a future implementation phase explicitly flips it under owner authorization.** Country production restore remains **disabled**.

---

## 0. Executive recommendations (contract)

| Decision | Choice |
|----------|--------|
| **DB cutover** | **Controlled import-over-production** of a **verified staging/shadow export artifact** (wipe production → import gzip). Reject DB rename, reject `.env` connection switch, reject live RENAME DATABASE promotion. |
| **Files cutover** | **Two-phase directory rename** (`uploads` → `uploads_pre_merge`, then `uploads_next` → `uploads`). Reject in-place overwrite and blind copy/swap without snapshot. |
| **Rollback** | Restore from **pinned Full pre-restore backup anchor** (DB via staging-then-import or approved emergency path; files via inverse rename +/or anchor `uploads.zip`). Maintenance stays on until operator confirms. |
| **Point of no return** | Instant `orange_restore_production_wipe()` begins on the production schema **or** first successful `uploads` → `uploads_pre_merge` rename — whichever occurs first. |
| **Mode** | Full Disaster only. Country production cutover is out of scope until 3B.3C table-boundary proof. |

---

## 1. Complete Production Cutover timeline (second-by-second)

Times are relative to `T0` = operator starts authorized production cutover CLI after all shadow gates pass. Durations are planning estimates for a mid-size Orange DB; actuals vary with dump size and disk.

| T+sec | Actor | Action | Artifacts / asserts | Reversible? |
|------:|-------|--------|---------------------|-------------|
| 0 | Operator | Confirm cutover readiness READY (or owner-approved MANUAL_REVIEW exception) | `cutover_readiness.json`, fingerprints match | Yes |
| 1–5 | CLI | Acquire Phase 2 `.restore.lock` + assert framework contract still valid | lock file, contract revalidation | Yes |
| 5–15 | CLI | Operator re-auth (password + phrase `RESTORE`) | audit event | Yes |
| 15–30 | CLI | **Enter maintenance** (see §2) | `{workRoot}/.maintenance.json` | Yes (disable) |
| 30–45 | CLI | Kill/block writers; assert no backup runner; assert pin still present | maint verify | Yes |
| 45–90 | CLI | Final fingerprint recheck (package, plan, approval, dry-run, contract, anchor, shadow reports) | reject on drift | Yes |
| 90–120 | CLI | Prepare `uploads_next` from verified shadow workspace **or** re-verify existing Phase 2 staging uploads_next | tree checksum | Yes |
| 120–300 | CLI | Export verified shadow/staging DB → `merge_db_export.sql.gz` | export checksum/manifest | Yes |
| 300–330 | CLI | Pre-cutover disk/volume/atomic-rename checks | same volume assert | Yes |
| 330 | CLI | **CHECKPOINT A — last fully reversible production-idle point** | all artifacts on disk; prod untouched | Yes |
| 331–… | CLI | **DB cutover starts:** `production_wipe` + import export gzip into production | live schema destroyed then rebuilt | **NO** |
| … | CLI | Mark `database_cutover_complete`; short DB read probes | status | Rollback only |
| … | CLI | Pre-merge uploads snapshot inventory; final uploads revalidation | snapshot dir | Rollback only |
| … | CLI | **Files rename 1:** `uploads` → `uploads_pre_merge` | pending status if crash | Rollback only |
| … | CLI | **Files rename 2:** `uploads_next` → `uploads` | live uploads replaced | Rollback only |
| … | CLI | Post-cutover validation (production adapter) | report | Rollback only |
| … | CLI | Production smoke (read-only under maintenance) | smoke report | Rollback on fail |
| … | CLI | Finalize reports; retention pin confirm; heartbeat stop | final report | — |
| … | Operator | Explicitly disable maintenance after acceptance | maint off | — |

**Hard rule:** HTTP never performs any row in this table. Only CLI workers under Super Admin re-auth.

---

## 2. Exact maintenance entry timing

### 2.1 When maintenance MUST turn on

**Earliest allowed:** after final cutover authorization for the production window (future approve-cutover / production-execute gate), **before**:

1. any production session kill intended for cutover,
2. staging export that is immediately followed by production wipe (operational preference: enable before wipe),
3. **any** production DB wipe/import,
4. **any** production uploads rename.

**Contract for Orange Full path:**

```
cutover_authorized
→ maintenance_entering (write .maintenance.json)
→ maintenance_verified
→ final fingerprint recheck
→ [optional] uploads_next materialization
→ staging/shadow export
→ CHECKPOINT A
→ production_wipe / import   ← production mutation begins
```

### 2.2 When maintenance MUST stay on

From maintenance enable until **all** of:

- post-cutover validation pass **or** rollback verification pass,
- operator acknowledges final status,
- explicit maintenance disable by Super Admin (phrase + re-auth + audit).

### 2.3 What maintenance blocks

| Surface | Behavior |
|---------|----------|
| Storefront writes / checkout / order create | 503 `maintenance_mode` |
| Admin mutating APIs | blocked except Restore Center emergency controls for owning Super Admin |
| Payments apply / stock / GL posting | blocked (callbacks may ack/retry; never apply) |
| Cron / queue consumers | must check maint flag and no-op mutations |
| Backup create / country export | blocked while restore lock or maint held |
| Safe GETs | optional limited catalog/health reads |

### 2.4 What must already exist before maint

- Valid final approval record
- Valid execution contract
- Pinned pre-restore Full rollback anchor (`ready_for_rollback` + retention pin)
- Shadow DB READY + Shadow Files PASS + Smoke READY (or owner-documented MANUAL_REVIEW waiver)
- `execution_started` still false until the production worker formally sets the production-execution flag in a future phase

---

## 3. Database cutover strategy

### 3.1 Options compared

| Option | Mechanism | Pros | Cons | Verdict |
|--------|-----------|------|------|---------|
| **A. RENAME DATABASE** | `RENAME TABLE`/`RENAME DATABASE` swap shadow ↔ production | Fast; near-atomic schema swap if supported | MariaDB rename semantics fragile across privileges; Plesk users often lack global rename; connection pools still point at name; Windows file handles; hard to audit statement-level progress | **Rejected** |
| **B. Connection switch** | Change `DB_NAME` in `.env.php` to shadow | No data copy | Violates Orange policy (never overwrite `.env.php` via restore); breaks ops/Plesk mental model; dual live DBs; credential drift | **Rejected** |
| **C. Shadow promotion** | Make shadow the new production by renaming files/datadir or alias | Conceptually clean | Requires host-level DB admin beyond app; not portable on shared Plesk; not implemented | **Rejected** |
| **D. Import-over-production (controlled)** | Export verified shadow/staging → wipe production → import artifact with merge credentials | Matches existing Phase 2 code (`restore_merge_db_cutover.php`); verify-before-expose; SQL safety fences; CLI-only | Wipe is irreversible without anchor; import duration; DDL auto-commit | **Recommended** |

### 3.2 Recommended DB cutover (exactly one)

**Controlled import-over-production of a verified shadow/staging export.**

Sequence (must reuse Phase 2 primitives, bridged from 3B):

1. Shadow/staging DB already imported and verified (3B.3B4–3B.3B5).
2. `orange_restore_merge_staging_export_run()` → `merge_db_export.sql.gz` + checksum/manifest.
3. Safety scans forbid cross-schema / production-targeting anomalies in the export.
4. Under maintenance + re-auth + lock: `orange_restore_production_wipe($mergePdo, $productionDb)`.
5. `orange_restore_sql_runner_import_gzip_to_target(...)` into **production** only.
6. Mark `database_cutover_complete`; run read-only probes.

**Never:** import the original package dump directly into production without the shadow verify + export gate.  
**Never:** change `.env.php` DB name as the cutover mechanism.

---

## 4. Files cutover strategy

### 4.1 Options compared

| Option | Mechanism | Pros | Cons | Verdict |
|--------|-----------|------|------|---------|
| **A. Rename (two-phase)** | `uploads`→`uploads_pre_merge`; `uploads_next`→`uploads` | Near-atomic on same volume; existing code; snapshot retained | Windows locks/AV; mid-rename crash needs reconcile | **Recommended** |
| **B. Swap** | Junction/symlink flip | Fast | Symlinks/reparse **blocked** by Orange uploads FS policy | **Rejected** |
| **C. Overwrite** | Copy/extract into live `uploads` | Simple mentally | Partial trees, locked files, non-atomic, zip-slip risk on live | **Rejected** |

### 4.2 Recommended files cutover (exactly one)

**Two-phase directory rename with pre-merge snapshot and verified `uploads_next`.**

Reuse `restore_merge_uploads_cutover.php`:

1. Materialize/verify `uploads_next` from shadow workspace (or Phase 2 staging path) with tree checksum = shadow/files report.
2. Assert same-volume atomic rename feasibility.
3. Create job-scoped pre-merge snapshot metadata/inventory.
4. Rename 1: live `uploads` → `uploads_pre_merge` (status `uploads_first_rename_pending` until reconciled).
5. Rename 2: `uploads_next` → `uploads`.
6. Verify live tree checksum matches expected; keep `uploads_pre_merge` until finalize/rollback window closes.

**Application PHP/code and `.env.php` are never restored or renamed.**

---

## 5. Atomicity guarantees

| Layer | Guarantee | Non-guarantee |
|-------|-----------|---------------|
| Shadow import | Isolated; production untouched | — |
| Staging export | File artifact checksummed before prod touch | — |
| DB wipe+import | **Not** one SQL transaction (DDL auto-commit). Atomicity = “complete import or rollback-from-anchor” | No crash-safe mid-import resume into partial production |
| Uploads rename | Directory rename is atomic **per rename** on same NTFS volume | Two-step window between rename1 and rename2 |
| Overall cutover | “All required stages succeed under maintenance, else rollback path” | No distributed 2PC across DB+files |
| Config | Immutable during restore | — |

**Engineering rules:**

- Same-volume assert before uploads renames (`orange_restore_uploads_fs_assert_atomic_rename_volume`).
- No symlink/junction cutover.
- Heartbeat + status machine prevent double cutover.
- After Point of No Return, forward cancel is forbidden; only rollback/resume-rollback.

---

## 6. Point Of No Return

### 6.1 Definition

**Point Of No Return (PONR)** = the first moment production data plane is mutated such that returning to the pre-cutover live state requires the rollback engine (not “discard staging”).

### 6.2 Exact identification

| Checkpoint | Name | Production mutated? | Recovery |
|------------|------|---------------------|----------|
| After maint on, before wipe | **CHECKPOINT A — last reversible** | No | Disable maint; discard staging/export; job → cancelled/failed |
| `orange_restore_production_wipe()` first destructive statement | **PONR-DB** | Yes | Rollback from pinned Full anchor |
| First successful `uploads` → `uploads_pre_merge` | **PONR-FILES** | Yes (paths) | Inverse rename / anchor uploads |
| **Contract PONR** | **min(PONR-DB, PONR-FILES)** | Yes | Rollback engine mandatory |

### 6.3 Last reversible checkpoint (contract)

**Last reversible checkpoint = CHECKPOINT A**  
Conditions all true:

- maintenance may already be on,
- shadow/staging verified,
- merge export artifact written & verified,
- `uploads_next` verified,
- **production DB not wiped/imported,**
- **production `uploads` directory not renamed.**

Any failure at or before CHECKPOINT A → fail closed **without** rollback engine (optional maint disable by operator).

---

## 7. Rollback design

### 7.1 Sources (priority order)

1. **Pinned Full pre-restore backup anchor** (DB dump + uploads.zip) — primary, job-scoped, retention-pinned  
2. **`uploads_pre_merge`** directory (inverse of files cutover) when still present  
3. Merge export / shadow DB (forensics; not sufficient alone for prod rollback)  
4. Job audit + checkpoints  

### 7.2 Triggers

| Trigger | Automatic? |
|---------|------------|
| DB cutover import failure after wipe started | Yes → rollback_preparing |
| Uploads second rename failure after first rename | Yes |
| Post-cutover validation fail | Yes |
| Production smoke fail after cutover | Yes |
| Operator emergency | Manual: phrase `ROLLBACK` + password re-auth + job id confirm |

### 7.3 Database rollback

1. Keep maintenance on.  
2. Resolve job-only anchor (`orange_restore_merge_rollback_resolve_anchor` pattern).  
3. Re-verify anchor package + DRV score ≥ 70.  
4. Prefer: import anchor into staging/shadow → export → wipe+import production (**same controlled path as cutover**), **or** approved emergency direct import of anchor dump into production under the same wipe+import controls.  
5. Verify production schema/row probes.  

### 7.4 Files rollback

1. If `uploads_next` still exists and `uploads` missing and `uploads_pre_merge` exists → rename `uploads_pre_merge` → `uploads` (reconcile).  
2. If live `uploads` is the new tree and `uploads_pre_merge` exists → rename live aside, restore `uploads_pre_merge` → `uploads`.  
3. If renames impossible → extract anchor `uploads.zip` into a fresh `uploads_next`, verify, then rename cutover back.  
4. Never leave production without a readable uploads root.

### 7.5 Maintenance during rollback

- Remains **enabled** for entire rollback.  
- Disable only after rollback verify + operator confirm.

### 7.6 Sessions

- Invalidate/block storefront write sessions via maintenance middleware.  
- Do not attempt surgical session table surgery as primary rollback.  
- After success, normal session issuance resumes when maint off.

### 7.7 Cache

- Treat APCu/opcache/file caches as stale after DB/files cutover or rollback.  
- Implementation phase must flush or version-bust storefront caches; design requires explicit `cache_invalidate` step in finalize and rollback finalize.  
- Do not rely on cache as source of truth.

### 7.8 Queues

- Queue consumers remain paused under maintenance.  
- Do not drain/apply payment or stock jobs against a mid-rollback DB.  
- After finalize: optional purge of maintenance-era poisoned jobs (implementation detail; fail closed = leave paused until operator).

---

## 8. Crash recovery (every interruption point)

| Stop during | Detect | Resume policy |
|-------------|--------|---------------|
| Pre-auth / lock acquire | no lock | restart cutover CLI |
| Maintenance entering | maint missing/partial | retry enable; still pre-PONR |
| Fingerprint recheck fail | status failed | fix cause; re-run from readiness |
| uploads_next materialization | incomplete next | wipe next; rebuild from shadow |
| Staging/shadow export | incomplete gzip | delete partial; re-export |
| **CHECKPOINT A** idle crash | prod untouched | resume forward from export/next verify |
| Production wipe started / mid-import | PONR-DB | **rollback** (do not resume partial import) |
| DB cutover complete, pre-files | DB new / files old | continue files cutover **or** rollback both to anchor (prefer continue if uploads_next verified) |
| uploads_first_rename_pending | FS reconcile helpers | reconcile to first-complete or rollback |
| Mid second rename | inconsistent dirs | rollback/reconcile runbook; no blind forward |
| Post-validate / smoke | reports | rerun idempotent checks; fail → rollback |
| Rollback mid-DB | rollback status | continue rollback only |
| Rollback mid-files | rollback status | continue rollback only |
| Rollback failed | `rollback_failed` | emergency runbook; maint stays on; no auto-unlock |
| Stale worker pre-PONR | heartbeat+PID dead | mark failed; release locks carefully |
| Stale worker post-PONR | heartbeat+PID dead | **no auto-unlock**; operator rollback/resume-rollback |

---

## 9. Operator runbook (Full production cutover)

### 9.1 Preconditions checklist

- [ ] Clone/drill succeeded on non-prod with same schema revision  
- [ ] Restore Center job at `cutover_readiness_ready` (or documented MANUAL_REVIEW waiver)  
- [ ] Pre-restore Full anchor pinned; checksum known  
- [ ] Disk free ≥ 2× (dump + uploads + export working set)  
- [ ] Staging/shadow DB credentials healthy; merge credentials healthy; ≠ production user where required  
- [ ] No other restore/backup locks  
- [ ] Super Admin available for re-auth; second person if policy requires  
- [ ] Communications: storefront maintenance message ready  

### 9.2 Execution steps (future CLI — not implemented in 3B.4)

1. Re-read `cutover_readiness.json` + shadow reports.  
2. Run production cutover CLI with `--job=` only (no path args).  
3. Complete password + `RESTORE` phrase challenges.  
4. Watch heartbeat / phase until `completed` or rollback states.  
5. Run post-cutover smoke summary.  
6. Only then disable maintenance.  
7. Spot-check: admin login, product page, one order read, uploads asset, health.php.  

### 9.3 Forbidden operator actions

- Editing `.env.php` to “point” at shadow  
- Manual phpMyAdmin import into production during automated window  
- Deleting `uploads_pre_merge` before finalize window  
- Running retention prune  
- Starting a second restore job  

---

## 10. Emergency runbook

### 10.1 Symptoms → actions

| Symptom | Immediate action |
|---------|------------------|
| Site down mid-cutover | Confirm maint file present; do **not** disable maint |
| CLI dead post-PONR | Do not unlock; start rollback CLI with `ROLLBACK` phrase |
| Rollback fails | Keep maint; preserve all packages; escalate; restore from anchor manually via approved emergency path |
| Wrong package suspected post-cutover | Rollback to pre-restore anchor (not “re-run wrong package”) |
| Disk full mid-import | Rollback path; free disk from non-anchor artifacts only |
| Suspected ransomware/host compromise | Stop; offline backups; out of band — beyond app rollback |

### 10.2 Emergency unlock (last resort)

- Super Admin + typed unlock/rollback phrase + audit  
- Allowed only when status is clearly pre-PONR **or** after rollback_failed with forensic copy secured  
- Never auto-clear locks during `database_restoring` / cutover / rollback_running  

### 10.3 Evidence to preserve

- `{framework_job}/` and Phase 2 `{workRoot}/{job}/` trees  
- Anchor package + pin record  
- `merge_db_export.sql.gz`  
- `uploads_pre_merge`, `uploads_next` if present  
- audit.jsonl / framework audit.jsonl  

---

## 11. Disaster Recovery Drill

### 11.1 Goal

Prove RTO/RPO targets on a **production clone** before any live cutover authorization.

### 11.2 Drill script (minimum)

1. Snapshot clone DB + uploads.  
2. Create Full package; run 3B path through shadow smoke → readiness READY.  
3. Execute production cutover against **clone only**.  
4. Induce at least one controlled failure **after PONR** and complete rollback.  
5. Induce one pre-PONR failure and confirm no rollback engine needed.  
6. Record timings: shadow import, export, wipe+import, uploads renames, rollback.  
7. Owner sign-off checklist (§12).  

### 11.3 Drill cadence

- Before first live Full cutover: mandatory  
- After schema revision bumps that affect restore: re-drill  
- Quarterly thereafter (recommended)

---

## 12. Production certification checklist

Live cutover is **forbidden** until all boxes are checked:

- [ ] This design document accepted by owner  
- [ ] Phase 3B.3B1–3B.3B7 artifacts present and green on clone  
- [ ] Implementation phases 3B.4C–3B.4G (below) complete with self-tests  
- [x] Phase 3B.4A import safety study (`PRODUCTION_IMPORT_SAFETY.md`)  
- [x] Phase 3B.4B production maintenance activation framework
- [x] Phase 3B.4C production database import engine (DB only; files not switched)
- [x] Phase 3B.4D production uploads cutover (rename only; no finalize/rollback/maint release)
- [ ] Maintenance middleware proven on storefront + admin write APIs  
- [ ] Retention pin cannot be pruned by normal retention  
- [ ] DB cutover path proven: wipe+import from verified export  
- [ ] Uploads two-phase rename proven with reconcile helpers  
- [ ] Rollback from pinned anchor proven after intentional PONR failure  
- [ ] Crash matrix exercises documented with pass evidence  
- [ ] Country production still explicitly disabled  
- [ ] `production_cutover_allowed` defaults false; enablement is explicit, audited, time-bounded  
- [ ] Two-person / time-gated approval control decided and implemented as required  
- [ ] Operator + emergency runbooks printed/linked in Restore Center (read-only)  
- [ ] Success metrics baseline captured on drill (§13)

---

## 13. Success metrics

| Metric | Definition | Drill target (initial) |
|--------|------------|------------------------|
| **RPO** | Max data loss vs pre-restore anchor moment | ≈ 0 relative to anchor (anchor taken immediately pre-window) |
| **RTO-cutover** | Maint on → production smoke pass | Track; optimize after first drill |
| **RTO-rollback** | Rollback trigger → rolled_back verified | Must be < cutover window tolerance set by owner |
| **Shadow readiness score** | From 3B.3B5/3B.3B7 | READY; WARNING only with waiver |
| **Cutover success rate (drills)** | Successful finalize / attempts | 100% on certification drill set |
| **Rollback success rate (drills)** | Successful rolled_back / induced PONR failures | 100% before live |
| **False start rate** | Aborts before PONR | Acceptable; must be fail-closed |
| **Lock incidents** | Stale lock needing emergency unlock | 0 unexplained |

---

## 14. Failure matrix

| Failure | Before PONR | After PONR | Auto action | Operator |
|---------|-------------|------------|-------------|----------|
| Approval/contract drift | Abort | N/A (should block entry) | fail closed | Re-dry / re-approve |
| Anchor missing/unpin | Abort | Emergency only | fail closed | Restore pin from backup ops |
| Staging export fail | Abort | N/A | fail | Retry export |
| Production wipe/import fail | — | Yes | rollback_preparing | Monitor rollback |
| uploads rename1 fail | If DB not cut over: abort files only / or rollback DB if already cut | Yes | rollback | Reconcile FS |
| uploads rename2 fail | — | Yes | rollback | Keep maint |
| Post-validate fail | — | Yes | rollback | — |
| Smoke fail | — | Yes | rollback | — |
| Rollback fail | — | Yes | `rollback_failed` | Emergency runbook |
| Second job started | Reject | Reject | lock | — |
| Maint bypass attempt | Block writes | Block writes | 503 | Investigate |

---

## 15. Implementation phases (smallest safe slices)

> 3B.4 (this document) is design-only. **3B.4A** (import safety study) is also documentation-only. Remaining slices below are future work.

### 3B.4A — Production Import Safety Layer (study / documentation only) — DONE

- **Deliverable:** `docs/backup/PRODUCTION_IMPORT_SAFETY.md`  
- Crash-safe import contract: checkpoints, resume rules, partial detection, DDL/FK/transaction boundaries, failure matrix, chunk/timeout/memory strategy, operator recovery, shadow-promotion rejection, risk comparison  
- **Must not:** production import/wipe/DB modification; no production code  

### 3B.4B — Production Maintenance Activation Framework — DONE

- **Code:** `includes/backup/restore/restore_production_maintenance.php` (+ extended `restore_maintenance_framework.php`)  
- **APIs:** `request-maintenance.php`, `activate-maintenance.php`, `maintenance-state.php`  
- **States:** `approved_waiting_execution` (gate) → `maintenance_requested` → `maintenance_validating` → `maintenance_active` (stop; no restore)  
- Central middleware decision helper `orange_restore_production_maintenance_decide` (policy only; routes not fully wired)  
- Heartbeat + stale detection; **never auto-release**  
- CLI scoped bypass only; no administrator bypass  
- **Must not:** production import/wipe, file restore, cutover, rollback, auto worker invoke  

### 3B.4C — Production Database Import Engine — DONE

- **Code:** `includes/backup/restore/restore_production_import.php`  
- **CLI:** `scripts/backup/restore_import_production.php --job=` (only); HTTP never imports  
- **APIs:** `request-production-import.php` (metadata), `production-import.php` (status GET)  
- **States:** `maintenance_active` → `production_import_pending` → `production_import_running` → `production_import_verifying` → `production_import_ready` / `production_import_failed`  
- **Checkpoints:** C0 validated … C6 import committed (owner contract); resume only at documented safe points  
- Reuses approved SQL runner + documented production wipe; streaming 64 KiB chunks  
- **Must not:** file cutover, uploads rename, rollback execution, maintenance release, completion/finalization  
- **Tests:** `self_test_production_import.php` (isolated fixtures / mock PDO only)  

### 3B.4D — Production Uploads Cutover — DONE

- **Code:** `includes/backup/restore/restore_production_uploads_cutover.php`  
- **CLI:** `scripts/backup/restore_uploads_cutover.php --job=` (only); HTTP never renames  
- **APIs:** `request-uploads-cutover.php` (metadata), `uploads-cutover.php` (status GET)  
- **States:** `production_import_ready` → `uploads_cutover_pending` → `uploads_cutover_running` → `uploads_cutover_verifying` → `uploads_cutover_ready` / `uploads_cutover_failed`  
- **Checkpoints:** C7 uploads rename completed | C8 uploads verification completed  
- Bridge shadow workspace → `uploads_next` → two-phase rename (`uploads`→`uploads_pre_merge`, `uploads_next`→`uploads`) via approved atomic rename helper  
- **Must not:** database import, rollback, maintenance release, finalize/complete restore  
- **Tests:** `self_test_production_uploads_cutover.php` (temp directories only)  

### 3B.4E — Production Rollback Engine — DONE

- **Code:** `includes/backup/restore/restore_production_rollback.php`  
- **CLI:** `scripts/backup/restore_rollback.php --job=` (only); HTTP never executes  
- **APIs:** `request-rollback.php` (metadata), `rollback.php` (status GET)  
- **States:** `uploads_cutover_ready` → `rollback_pending` → `rollback_database_running` → `rollback_database_verifying` → `rollback_files_running` → `rollback_files_verifying` → `rollback_ready` / `rollback_failed`  
- **Checkpoints:** C9 database rollback complete | C10 database verify complete | C11 uploads rollback complete | C12 rollback verify complete  
- **Sources:** Full rollback anchor dump only (never shadow DB); `uploads_pre_merge_{job}` → `uploads` only (never shadow workspace)  
- **Must not:** release maintenance, mark restore completed, finalize execution, delete rollback anchors, remove retention pins  
- **Tests:** `self_test_production_rollback.php` (isolated fixtures / mock PDO only)  

### 3B.4F — Restore Finalization & Maintenance Release — DONE

- **Code:** `includes/backup/restore/restore_production_finalize.php`  
- **CLI:** `scripts/backup/restore_finalize.php --job=` (only); HTTP never finalizes  
- **APIs:** `request-finalize.php` (metadata), `finalize.php` (status GET)  
- **Success states:** `uploads_cutover_ready` → `restore_finalizing` → `restore_completed`  
- **Rollback states:** `rollback_ready` → `rollback_finalizing` → `rollback_completed`  
- **Actions:** write `restore_final_report.json`; release framework + merge maintenance; release execution lock; preserve rollback anchor, retention pin, reports, checkpoints  
- **Must not:** DB import, uploads rename, rollback execution, shadow execution, delete anchors, remove pins, re-run verification  
- **Tests:** `self_test_production_finalize.php`  

### 3B.4G — Production cutover authorization gate (metadata + UI warnings only)

- Explicit `production_cutover_authorized` record separate from shadow readiness  
- Still no application PHP/.env cutover  
- Tests: cannot authorize without readiness; Country rejected  

### 3B.4H — Route-level maintenance middleware wiring (storefront/admin/cron)

- Wire real request guards using `orange_restore_production_maintenance_decide`  
- Tests: writes 503; Restore Center emergency paths permissioned  

### 3B.4I — Production smoke (post-finalize optional) + cache invalidate

- Post-completion smoke / cache bust (after maintenance released)  
- Tests: smoke fail does not re-enter cutover without new job  

### 3B.4J — Disaster recovery drill automation + certification pack

- Scripted drill; metrics capture; checklist enforcement flag  
- **Prod gate:** owner certification (§12)  

### 3B.3C — Country production (separate series)

- Only after Full certification + table-boundary matrix  
- Not part of Full cutover MVP  

---

## 16. State machine additions (3B.4E rollback path)

After uploads cutover ready:

```
uploads_cutover_ready
→ rollback_pending
→ rollback_database_running → rollback_database_verifying
→ rollback_files_running → rollback_files_verifying
→ rollback_ready
```

Failure: `rollback_failed` (maintenance remains active; anchors/pins retained).

Cancel allowed only **before PONR**. After PONR: rollback only.

---

## 17. Security & policy constraints (non-negotiable)

- CLI-only for all mutating cutover/rollback steps  
- HTTP: request/status only; never execute cutover  
- Super Admin + restore permission + password re-auth + typed phrase  
- No client-supplied absolute paths or DB names  
- No `.env.php` rewrite  
- No application source restore  
- `production_cutover_allowed` false until 3B.4B+ authorization record says otherwise **and** certification checklist complete  
- Production DB import must follow `docs/backup/PRODUCTION_IMPORT_SAFETY.md` (no mid-stream resume by default; re-wipe+re-import or rollback) 
- Country production disabled  

---

## 18. Mapping to existing code (reuse, do not rewrite)

| Concern | Existing module |
|---------|-----------------|
| Staging/shadow import | `restore_shadow_db.php`, `restore_full_staging.php`, `restore_sql_runner.php` |
| Shadow verify/smoke | `restore_shadow_verify.php`, `restore_shadow_smoke.php` |
| Pre-restore anchor | `restore_pre_restore_backup.php`, retention pins |
| DB cutover | `restore_merge_db_cutover.php` + `restore_merge_staging_export.php` + `restore_production_target.php` |
| Files cutover | `restore_merge_uploads_cutover.php` + `restore_uploads_fs.php` |
| Rollback | `restore_merge_rollback.php` |
| Maintenance file | `restore_merge_maintenance.php` (+ future middleware) |
| Phase 2 E2E | `restore_e2e_orchestrator.php`, CLI scripts in `RESTORE_PHASE2_CLI_ENTRYPOINTS.md` |
| 3B bridge contract | `restore_execution_bridge.php` |

**Design mandate:** wire 3B → these primitives; do not invent a third cutover engine.

---

## 19. Confirmation (this phase)

- No production cutover implemented  
- No production database/file switch implemented  
- No maintenance activation code added in this phase  
- No rollback execution added  
- No execute/cutover/resume endpoints added  
- Deliverable is this document only (plus optional cross-links in restore design docs)

---

*End of Phase 3B.4 — Production Cutover & Rollback Design.*
