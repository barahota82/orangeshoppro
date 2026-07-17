# Orange Production Import Safety Layer (Phase 3B.4A — Study Only)

**Status:** STUDY / DESIGN ONLY — no production import implementation in this phase.  
**Date:** 2026-07-17  
**Parent contract:** `docs/backup/PRODUCTION_CUTOVER_AND_ROLLBACK_DESIGN.md`  
**Grounding code (read-only study):**  
`includes/backup/restore/restore_sql_runner.php`,  
`restore_sql_safety.php`,  
`restore_production_target.php` (`orange_restore_production_wipe`),  
`restore_merge_db_cutover.php`,  
`restore_merge_staging_export.php`,  
shadow import path in `restore_shadow_db.php`.

**Hard non-goals of this phase:**

- Do **not** import into production  
- Do **not** wipe production  
- Do **not** modify production DB  
- Do **not** add cutover/execute/resume endpoints  
- Documentation only  

**Purpose:** Prove, on paper and against the real importer design, that a future production import can be made **crash-safe enough to operate**—with explicit resume rules, partial-import detection, and operator recovery—**before** any production wipe/import is authorized.

---

## 0. Executive conclusions

| Topic | Contract decision |
|-------|-------------------|
| Current importer | Streams gzip in **64 KiB** reads; splits SQL statements; `PDO::exec` one statement at a time; **no durable byte-offset resume** today |
| Production mid-import crash default | **Do not resume mid-stream.** Re-wipe (or treat as dirty) and **re-import from verified export**, or **rollback from pinned Full anchor** |
| True crash-safety | Achieved by **phase checkpoints + fail-closed detection + rollback anchor**, not by pretending DDL is transactional |
| Shadow promotion | **Not viable** on Orange (Windows/Plesk/MariaDB privilege + `.env` immutability). Import-over-production remains the path—with this safety layer |
| Max safe read chunk | Keep **65536** gzip read size (matches current runner); do not raise without memory soak tests |
| CLI only | Production import must remain CLI; IIS/Plesk request timeouts are unsuitable |

---

## 1. Import checkpoint strategy

### 1.1 Checkpoint philosophy

MariaDB **DDL auto-commits**. A Full dump cannot be one atomic SQL transaction. Therefore checkpoints are **phase boundaries and durable job metadata**, not “uncommitted SQL.”

### 1.2 Required checkpoints (future production importer)

Write under `{workRoot}/framework/{job_id}/checkpoints/` (and mirror key fields on job JSON):

| ID | Name | When written | Contents (no secrets) |
|----|------|--------------|------------------------|
| **C0** | `export_verified` | After staging/shadow export gzip verified | export sha256, table_count, row_count, bytes |
| **C1** | `pre_wipe` | Immediately before production wipe | production_db identity hash, maint on, lock held, anchor pin id |
| **C2** | `wipe_complete` | After wipe succeeds | dropped_table_count, wiped_at |
| **C3** | `import_progress` | Every N statements / M bytes (advisory) | statements_executed, bytes_read, last_heartbeat_at, pid |
| **C4** | `import_stream_complete` | After gzip EOF + no incomplete statement | statements_executed, bytes_read, export sha256 match |
| **C5** | `import_verified` | After post-import verification suite | overall PASS/FAIL, table matrix summary |
| **C6** | `database_cutover_complete` | Only if C5 PASS | status handoff to files cutover |

### 1.3 What C3 is and is not

- **Is:** heartbeat / progress telemetry for operators and stale-worker detection  
- **Is not:** a safe resume cursor into a half-built production schema (unless a future enhancement proves table-level restartability—see §2)

### 1.4 Checkpoint durability rules

- fsync-equivalent: write temp file → rename into place  
- Heartbeat every ≤ 30s while import running  
- Stale worker after PONR: **no auto-unlock** (parent cutover design)

---

## 2. Resume capability

### 2.1 Current capability (as implemented today)

`orange_restore_sql_runner_import_gzip` / `_to_target`:

- Streams from byte 0 of the gzip every run  
- Tracks `statements_executed` / `bytes_read` only in the return array / logs  
- **No** persisted gzip offset, **no** statement index resume file  

### 2.2 Allowed resume policies (contract)

| Situation | Resume policy |
|-----------|---------------|
| Crash **before** C1 (`pre_wipe`) | Restart cutover prep; production untouched |
| Crash **during wipe** (C1→C2) | Treat production as dirty → complete wipe then import, **or** rollback from anchor if wipe cannot finish cleanly |
| Crash **during import** (C2→C4) | **Default: abort forward resume.** Mark dirty → **full re-wipe + re-import** from same verified export **or** rollback from pinned Full anchor |
| Crash after C4, before C5 | Resume **verification only** (idempotent reads) |
| Crash after C5 PASS | Resume next phase (files cutover), not re-import |
| Shadow/staging import crash | Prefer wipe staging/shadow + full re-import (already the safe pattern) |

### 2.3 Why mid-stream resume is rejected by default

1. DDL/`CREATE TABLE`/`DROP`/`INSERT` mixtures leave schemas in undefined intermediate shapes.  
2. Statement split points are not stable “logical units” for business tables (multi-row inserts, routines).  
3. Replaying from an offset risks duplicate key errors or skipped DDL.  
4. Orange already has a **pinned Full rollback anchor** and a **verified export artifact**—cheaper and safer to restart the production import phase than to invent fragile resume.

### 2.4 Optional future enhancement (not authorized now)

Table-ordered import with per-table checkpoints (`table X complete`) could allow resume **after** all prior tables verified—only if export format guarantees deterministic table order and no cross-table statements mid-chunk. Out of scope until proven on clone drills.

---

## 3. Partial import detection

### 3.1 Signals the runner already has

| Signal | Source |
|--------|--------|
| Incomplete trailing statement | Non-empty buffer after EOF that is not comment-only → hard fail |
| Statement execution exception | Caught; returns `failed_statement` (redacted) on production target importer |
| Progress counters | `statements_executed`, `bytes_read` |

### 3.2 Required detection suite after any interrupted or completed import

Compare production (or target) against **export manifest / shadow inventory**:

1. **Table set:** expected base tables present; count ≥ export `table_count` (or exact match policy).  
2. **Empty/dirty wipe:** if status says importing but `SHOW TABLES` empty or far below expected → partial/failed.  
3. **Half-built tables:** expected table missing while later tables exist (ordering heuristic).  
4. **Row-count probes:** sample critical tables vs export row_count totals (tolerance only if documented).  
5. **Schema revision / meta:** `orange_schema_meta` / migrations markers readable and match package expectation.  
6. **FK enablement:** after import, `FOREIGN_KEY_CHECKS=1` and orphan spot-checks (reuse shadow verify orphan helpers).  
7. **Stream integrity:** export gzip sha256 unchanged vs C0.  
8. **Job status vs filesystem:** if heartbeat stale and C4 missing → classify `partial_import_suspected`.

### 3.3 Classification

| Class | Meaning | Next action |
|-------|---------|-------------|
| `clean_pre_wipe` | No prod mutation | Abort/retry prep |
| `wiped_not_imported` | C2 without C4 | Re-import export or rollback |
| `partial_import` | C2..C4 incomplete / verify fail | Re-wipe+re-import or rollback |
| `import_complete_unverified` | C4 without C5 | Run verification |
| `import_verified` | C5 PASS | Proceed |

**Never** expose storefront writes while class ≠ `import_verified` (maintenance remains on).

---

## 4. DDL interruption handling

### 4.1 Reality

- `CREATE`/`DROP`/`ALTER` typically **auto-commit** on MariaDB.  
- Interrupting mid-DDL can leave a table missing, half-created, or locked.  
- PHP process kill does not roll back completed DDL.

### 4.2 Rules

1. Treat any crash between C2 and C4 as **schema dirty**.  
2. Do not attempt to “continue the next statement” after an arbitrary kill.  
3. Recovery = **deterministic rebuild**: wipe target schema objects again → import full verified export from byte 0, **or** rollback from Full anchor via the same controlled path.  
4. During wipe/import, keep `FOREIGN_KEY_CHECKS=0` (see §5); restore to 1 only after structured completion.  
5. Log redacted failed statement (existing `orange_restore_sql_runner_redact_failed_statement`) for forensics—never full row payloads with PII.

---

## 5. Foreign key handling

### 5.1 Current patterns

| Phase | Behavior |
|-------|----------|
| Production wipe | `SET FOREIGN_KEY_CHECKS=0` → `DROP TABLE IF EXISTS` each table → `SET FOREIGN_KEY_CHECKS=1` |
| Shadow wipe | Same pattern (`restore_shadow_db.php`) |
| Dump content | php_pdo exports typically toggle FK checks around load; DRV expects postamble restoration patterns |

### 5.2 Contract for production import

1. **Session start:** `SET NAMES utf8mb4`; assert `SELECT DATABASE()` == production.  
2. **Wipe:** FK checks off for duration of drops.  
3. **Import:** Prefer dump-controlled FK toggles; additionally ensure session can complete with FK off during bulk load if dump lacks toggles—implementation must not leave FK checks off after success.  
4. **After C4:** explicitly `SET FOREIGN_KEY_CHECKS=1` if not already.  
5. **C5 verification:** orphan FK spot-checks (orders/items, journal lines, stock FKs) — fail → dirty → rollback/re-import.  
6. **Never** disable FK checks globally as a permanent server setting.

---

## 6. Transaction boundaries

| Unit | Transactional? | Notes |
|------|----------------|-------|
| Entire Full dump | **No** | DDL auto-commit; size prohibitive |
| Single `PDO::exec` statement | Statement-level only | InnoDB DML may be atomic per statement; DDL commits |
| Wipe loop | **No** multi-table transaction | Each `DROP` commits |
| Import loop | **No** wrapping `beginTransaction()` | Would be misleading and often impossible with DDL |
| Job metadata checkpoint write | File rename atomicity | Separate from SQL |

**Contract:** Do not advertise “transactional production restore.” Advertise **checkpointed rebuild + rollback anchor**.

---

## 7. Crash recovery

### 7.1 Matrix (import-focused)

| Crash point | Production state | Recovery |
|-------------|------------------|----------|
| Before C1 | Untouched | Restart prep |
| During wipe | Partial drops | Finish wipe → import export, or rollback anchor if wipe stuck |
| During import | Partial schema/data | **Re-wipe + full re-import** of verified export, **or** rollback from pinned Full anchor |
| After C4, verify running | Full stream applied, unverified | Resume verification; on fail → rollback |
| Worker stale, PID dead, C3 only | Assume partial | Same as during import |
| Disk full mid-import | Partial | Free space (non-anchor); then re-wipe+re-import or rollback |
| Corrupt gzip mid-stream | Partial | Fail; re-export from shadow if export corrupt; else rollback |

### 7.2 Instrumentation required before production authorization

- Heartbeat file with pid + checkpoint id  
- Durable C0–C6 files  
- Explicit status: `database_import_running` / `database_import_failed_partial` / `database_import_verified`  
- Audit events without credentials or absolute private paths in public APIs  

---

## 8. Verification after each phase

| After | Verification |
|-------|--------------|
| **C0 export** | sha256, gzip openable, SQL safety scan, table_count/row_count present, cross-schema forbid |
| **C1 pre-wipe** | maint on; lock held; production identity; anchor pin present; export sha still matches |
| **C2 wipe** | `SHOW TABLES` empty (or only allowlisted leftovers if any—default: empty); session DB still production |
| **C3 progress** | heartbeat fresh; statements/bytes monotonic; session DB assert each batch |
| **C4 stream complete** | no incomplete statement; bytes_read > 0; statements_executed > 0 |
| **C5 import verified** | table matrix vs export/shadow; charset/collation; schema_revision; orphan FK probes; sample SELECTs on critical tables; FK checks enabled |
| **Hand-off** | Only then allow files cutover |

Reuse read-only helpers from shadow verify/smoke where possible; do not invent a second validation language.

---

## 9. Failure matrix

| Failure | Detect | Auto action | Operator |
|---------|--------|-------------|----------|
| Missing export gzip | C0 | Abort pre-wipe | Re-export from shadow |
| Export checksum drift | C1 recheck | Abort | Re-export |
| Wipe fails mid-drop | exception / partial tables | Stay dirty; retry wipe | If stuck → emergency |
| Import SQL error | exception + redacted statement | Mark partial; **no mid-stream resume** | Re-wipe+re-import or rollback |
| Incomplete statement EOF | runner hard fail | Mark partial | Same |
| Heartbeat stale post-PONR | monitor | No auto-unlock | Inspect; recover per §7 |
| Verify fail after full stream | C5 | Rollback preparing | Confirm rollback |
| FK orphans after enable | C5 | Fail verify | Rollback |
| Wrong session database | assert helpers | Fail closed immediately | Investigate credentials |
| Out of memory / PHP fatal | process death | Treat as partial | Raise limits; retry CLI |
| Timeout (host kill) | process death | Treat as partial | Ensure CLI + set_time_limit(0) |

---

## 10. Maximum safe import chunk

| Parameter | Current / recommended | Rationale |
|-----------|----------------------|-----------|
| Gzip `gzread` size | **65536 bytes** | Existing runner; bounded buffer growth when combined with statement splitter |
| Statement execution | **1 statement / exec** | Failure isolation; session assert between statements |
| Progress log interval | every **500** statements | Operator visibility without log flood |
| Checkpoint flush interval (future C3) | every **500–2000** statements **or** 30s | Balance I/O vs recoverability telemetry |
| Country plain SQL files | whole file buffered today | Acceptable for CRP chunks; Full production path must stay gzip-streamed |

**Do not** load the entire Full dump into memory (`file_get_contents` on multi‑GB gzip decompressed SQL is forbidden for Full production).

**Do not** increase read size above 256 KiB without clone soak tests measuring peak PHP memory under worst-case long statements.

---

## 11. Timeout strategy

| Layer | Strategy |
|-------|----------|
| HTTP / IIS / Plesk PHP-FPM | **Never** run production import here |
| CLI PHP | `set_time_limit(0)`; ignore user abort where applicable |
| MySQL `wait_timeout` / `net_read_timeout` | Ensure merge session timeouts ≥ expected import window; document host knobs for ops |
| Heartbeat stale threshold | Align with restore locks (~hours), but operator alert earlier (e.g. 5–15 min no heartbeat) |
| Soft ETA | From dry-run / prior drill timings; never used as hard kill |

---

## 12. Memory strategy

| Concern | Rule |
|---------|------|
| Gzip stream | Stream only (`gzopen`/`gzread`) |
| Statement buffer | Grow only until next `;` outside quotes/comments; pathological giant statements are a dump-quality issue—fail with clear error rather than unbounded RAM |
| PDO buffering | Prefer unbuffered where applicable for large result probes; import path uses `exec`, not large fetches |
| Logging | Redact statements to ≤240 chars (existing helper) |
| Concurrent imports | Forbidden (global restore lock) |
| Peak memory target | Stay well under host PHP `memory_limit` with margin; drill must record peak |

---

## 13. Operator recovery instructions

### 13.1 Before any wipe (still reversible)

1. Confirm maintenance / locks.  
2. Do **not** manually import via phpMyAdmin.  
3. Re-run prep/export verification.  
4. Abort job if fingerprints drifted.

### 13.2 After wipe or mid-import (dirty production)

1. **Keep maintenance on.**  
2. Do not unlock restore locks casually.  
3. Prefer automated recovery CLI (future): `reimport-from-export` (wipe+import verified export) **or** `rollback-from-anchor`.  
4. If automation unavailable: emergency path = restore pinned Full anchor through the **same** wipe+import controls used for cutover (never ad-hoc partial fixes).  
5. Preserve job directory, export gzip, anchor package, audit logs.  
6. Only disable maintenance after verification PASS and operator sign-off.

### 13.3 After import stream complete but verify failed

1. Do not proceed to uploads cutover.  
2. Rollback from anchor (safer than guessing missing tables).  

### 13.4 Forbidden operator improvisation

- Editing rows to “finish” a partial import  
- Pointing `.env.php` at shadow  
- Dropping random tables without full rebuild  
- Deleting the rollback anchor  

---

## 14. Why shadow promotion is impossible (on Orange)

“Shadow promotion” means making the shadow/staging schema become production by **rename** or **connection switch**, without wipe+import.

| Barrier | Detail |
|---------|--------|
| **`.env.php` immutability** | Orange policy: restore must never rewrite DB name/credentials in `.env.php` |
| **Plesk/shared hosting** | App pool and tools assume a stable production schema name; rename requires privileges often unavailable |
| **MariaDB RENAME DATABASE** | Incomplete/fragile; not a supported portable cutover API for this stack |
| **Connection pools / long-lived PHP** | In-flight workers would still target old name or mix identities |
| **Credential model** | Staging/shadow user must **≠** production user; merge user is a third identity—promotion would collapse safety fences |
| **Auditability** | Wipe+import of a checksummed export yields clearer forensic artifacts than filesystem-level datadir tricks |
| **Existing code** | `restore_merge_db_cutover.php` already implements export → wipe → import; promotion would be a third engine |

**Conclusion:** Shadow remains a **verify-before-expose** environment. Production cutover stays **controlled import-over-production** of the verified export, guarded by this safety layer + rollback anchor.

---

## 15. Risk comparison: Import-over-production vs Shadow Promotion

| Dimension | Import-over-production (recommended) | Shadow promotion (rejected) |
|-----------|--------------------------------------|-----------------------------|
| Verify before expose | Yes (shadow + export checksum) | Partial (shadow verified, but cutover is rename/switch) |
| Crash mid-cutover | Dirty production until rebuild/rollback | Rename half-done can strand names; connection switch can split brains |
| Fits Plesk/Windows | Yes (SQL over network) | Poor (privileges, datadir, services) |
| `.env.php` safe | Yes | Usually requires env change |
| Credential fences | Staging ≠ merge ≠ app user preserved | Blurs identities |
| Aligns with current code | Yes | No |
| RTO | Longer (wipe+import duration) | Potentially faster if it worked |
| RPO | Anchor-defined | Anchor still required if promotion fails |
| Operability | Clear runbooks | Host-specific, brittle |
| Overall for Orange | **Accepted with §1–§13 controls** | **Rejected** |

---

## 16. Implementation prerequisites (future; not this phase)

Before any production wipe/import code is authorized:

1. Durable C0–C6 checkpoint writer  
2. Status values for partial import  
3. Automated “re-wipe + re-import export” recovery path  
4. Wired rollback-from-anchor on import failure  
5. Clone drill proving crash between C2–C4 recovers cleanly  
6. Memory/timeout soak on production-sized dump  
7. Owner certification per cutover design §12  

---

## 17. Confirmation (this phase)

- No production import executed  
- No production wipe executed  
- No production DB modified  
- No production PHP/SQL implementation added  
- Deliverable: this document only (plus optional cross-links in sibling design docs)

---

*End of Phase 3B.4A — Production Import Safety Layer (study).*
