# Country Production Restore — P1 Scoped Uploads Apply Contract (Design Only)

| Field | Value |
|-------|--------|
| **Work Package** | **WP-P1-10** — Scoped Uploads Apply Contract (Design Only) |
| **Artifact-ID** | `CPR-P1-WP10-UPLOADS_CONTRACT` |
| **Status** | COMPLETE (design contract only) |
| **Version** | 1.0 |
| **Date** | 2026-07-21 |
| **Baseline** | Git tag `P0-P0b-Final` → commit `e6c19ef1` |
| **Policy SoT** | `COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md` (**OD-UPLOADS** §15 Frozen; Isolation Principle) |
| **Implementation baseline** | `COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md` §10.2 B, §10.3, §11 |
| **Depends on** | WP-P1-02 · WP-P1-08 · WP-P1-09 |
| **Coding** | **No** — design only; no PHP/CLI/UI implementation in this WP |

---

## 1. Purpose

Specify the **strictly scoped** production uploads apply algorithm for `uploads_country.zip`: allowlisted paths, mandatory scoped pre-image, staging layout, pre-image manifest, failure → GLOBAL Maint + Super Admin Resume/Rollback only, and interaction with OD-PIN Full Backup rollback — without borrowing Full DR full-tree rename patterns.

This WP does **not** modify Architecture, Owner Decisions, C3–C8 engines, or prior P1 artifacts.

---

## 2. Hard constraints (OD-UPLOADS + Isolation)

| ID | Rule | Authority |
|----|------|-----------|
| H1 | Uploads restored **only** with a strictly scoped strategy | OD-UPLOADS |
| H2 | **NEVER** replace the entire uploads tree / full-tree rename path | OD-UPLOADS (rejects OD-UPLOADS-FULLTREE) |
| H3 | **NEVER** delete or modify survivor-country uploads | OD-UPLOADS · Isolation |
| H4 | **NEVER** modify files outside the approved recovery scope | OD-UPLOADS · Isolation |
| H5 | Before modifying any production file: create a **scoped pre-image** of every file that may be modified | OD-UPLOADS |
| H6 | Only the **target country’s** approved upload scope may be restored | OD-UPLOADS |
| H7 | If upload integrity cannot be guaranteed: **immediate fail**; GLOBAL Maint ON; Super Admin **Resume** (when safe) or **Rollback** only | OD-UPLOADS · WP-P1-09 |
| H8 | **No** best-effort upload recovery; **no** partial acceptance | OD-UPLOADS |
| H9 | Apply only under GLOBAL Maintenance + CPR lock (WP-P1-05/07) | OD-MAINT · Isolation |
| H10 | First successful production uploads path replacement is a **PONR** event (with first DELETE) | Architecture §10.3 |

---

## 3. Inputs & binding

| Input | Rule |
|-------|------|
| Package archive | `files/uploads_country.zip` from Country Recovery Package (C3) |
| Target | Contract `country_id` / `country_code` (WP-P1-02) — exact equality |
| Allowlist | **Same allowlist rules as C3/C4** for the package (Architecture §10.2 B.2) — consumed as immutable evidence; CPR must not invent a looser list |
| C4 evidence | C4 must have passed uploads allowlist checks (`uploads_allowlist_violation` absent) — WP-P1-08 G08 |
| Staging root | Under CPR job work root only (never production tree as staging) |
| Production uploads root | Live platform uploads root (deployment SoT path) |

**Isolation Principle:** never modify outside approved recovery scope; never endanger survivor/unrelated resources; fail if safe isolation unproven.

---

## 4. Approved recovery scope & allowlisted paths

### 4.1 Scope definition

| Term | Definition |
|------|------------|
| **Approved recovery scope** | The set of relative upload paths for the **target country only**, as certified by the package manifest + C3 export allowlist + C4 verify, bound into the CPR execution contract / job allowlist snapshot |
| **Allowlisted relative path** | A path relative to the production uploads root that is a member of the approved recovery scope |
| **Survivor path** | Any path under production uploads that belongs to another country or is outside the approved recovery scope |

### 4.2 Allowlist rules (normative consumption)

1. Build `cpr_uploads_allowlist` at job freeze / pre-apply from package + C3/C4 artifacts.  
2. Every zip member to be applied **must** map to an allowlisted relative path.  
3. Path normalization mandatory before membership test: reject `..`, absolute paths, drive letters, null bytes, alternate data streams, symlink escape, case-confusable tricks that resolve outside scope.  
4. Empty allowlist with non-empty zip → **FAIL** (integrity cannot be guaranteed).  
5. Zip member not on allowlist → **FAIL** (`uploads_allowlist_violation`) — do not skip.  
6. Allowlist entry with no zip member may be a delete-within-scope operation **only if** package/C3 semantics explicitly authorize scoped delete; otherwise leave production file untouched. Unauthorized deletes → **FAIL**.  
7. **Forbidden patterns (always):**  
   - Replacing or renaming the entire production `uploads/` directory  
   - Applying a zip rooted at uploads tree root for all countries  
   - Wildcards that expand beyond target country scope  
   - Paths for `country_id ≠ target`

### 4.3 Allowlist snapshot schema (`cpr_uploads_allowlist`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_uploads_allowlist/1"` |
| `job_id` | Y | |
| `country_id` | Y | Target only |
| `package_id` | Y | |
| `source` | Y | `c3_c4_package` |
| `c4_report_hash` | Y | Must match contract |
| `paths` | Y | Array of relative path strings (POSIX-style `/`) |
| `path_count` | Y | |
| `allowlist_sha256` | Y | Hash of canonical path list |
| `full_tree_forbidden` | Y | Const `true` |
| `survivor_modify_forbidden` | Y | Const `true` |

---

## 5. Staging layout

```text
{workRoot}/country_production/{job_id}/
  uploads_apply/
    allowlist.json                 # cpr_uploads_allowlist
    staging/                       # materialize zip members (allowlisted only)
      {relative/path/...}
    pre_image/                     # scoped pre-images of production files to modify
      {relative/path/...}
    pre_image_manifest.json        # §7
    apply_plan.json                # ordered operations (§6)
    apply_progress.json            # runtime counters
    apply_result.json              # success/fail sealed result
  checkpoints/
    CP9_uploads_complete.json      # WP-P1-04 — only after success
```

| Rule | Value |
|------|-------|
| Staging ≠ production | Staging tree must not be the live uploads root |
| Materialize filter | Extract **only** allowlisted members; reject zip slip |
| Cleanup | Staging retained for audit until job terminal closeout policy; never used as production |

---

## 6. Apply algorithm (normative sequence)

Execute only in WP-P1-03 state `cpr_uploads_applying`, under GLOBAL Maint + CPR lock, after DB stages required by architecture order.

```
UPLOADS_APPLY(job):
  1. PRECONDITIONS
     - GLOBAL maint ON + write-block proven
     - CPR lock held by job; ponr may already be true from DELETE
     - Contract frozen; allowlist snapshot loaded
     - uploads_country.zip present; hash matches package fingerprint expectations
  2. MATERIALIZE
     - Extract allowlisted members → uploads_apply/staging/
     - Any allowlist/zip slip/hash failure → FAIL_CLOSED (§8)
  3. PLAN
     - Build apply_plan: list of {relative_path, op: replace|create|scoped_delete?}
     - Every op path ∈ allowlist; no path outside scope
  4. PRE-IMAGE (mandatory before any production mutation)
     - For every production file that may be modified or deleted by the plan:
         copy byte-exact to uploads_apply/pre_image/{relative_path}
         record in pre_image_manifest (§7)
     - For create-new paths where production file does not exist:
         record pre_image absence (exists=false) — still mandatory manifest entry
     - If any pre-image capture fails → FAIL_CLOSED (do not modify production)
  5. APPLY (scoped only)
     - For each plan op: atomic replace/create/delete **only** at production path
       corresponding to allowlisted relative path
     - First successful production path replacement → PONR if not already (Architecture §10.3)
     - After each op: update apply_progress; heartbeat lock (WP-P1-05)
  6. VERIFY APPLY INTEGRITY
     - Re-read applied paths; compare to staging hashes where required
     - Confirm no production path outside allowlist was written (spot + journal check)
     - If integrity cannot be guaranteed → FAIL_CLOSED (§8)
  7. SUCCESS
     - Write apply_result success; commit CP9; continue post-verify
```

### 6.1 Explicitly forbidden operations (implementer blacklist)

| Operation | Status |
|-----------|--------|
| `rename(production_uploads_root, …)` full-tree swap | **Forbidden** |
| Extract zip to production uploads root then swap | **Forbidden** |
| Delete entire `uploads/` | **Forbidden** |
| Modify path where `country` ≠ target / outside allowlist | **Forbidden** |
| Skip pre-image “for speed” | **Forbidden** |
| Continue after partial apply with warnings | **Forbidden** |
| Best-effort skip failed files | **Forbidden** |

### 6.2 Apply plan schema (`cpr_uploads_apply_plan`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_uploads_apply_plan/1"` |
| `job_id` | Y | |
| `allowlist_sha256` | Y | |
| `operations` | Y | Ordered array |
| `operations[].relative_path` | Y | Allowlisted |
| `operations[].op` | Y | `replace` \| `create` \| `scoped_delete` |
| `operations[].staging_sha256` | Y* | Required for replace/create |
| `full_tree_mode` | Y | **Must be `false`** |

---

## 7. Pre-image manifest schema

### 7.1 Rules

1. Manifest must be complete **before** first production uploads mutation for the plan.  
2. Every plan path that may modify production **must** have a manifest entry.  
3. Pre-image bytes stored under `uploads_apply/pre_image/` with mirrored relative paths.  
4. Manifest is append-only during apply; sealed at success or fail.  
5. Pre-image is **secondary assist** for forensics / limited resume — **not** primary rollback (Architecture §11).

### 7.2 Schema (`cpr_uploads_pre_image_manifest`)

| Field | Required | Notes |
|-------|:--------:|-------|
| `schema_version` | Y | `"cpr_uploads_pre_image_manifest/1"` |
| `job_id` | Y | |
| `country_id` | Y | |
| `package_id` | Y | |
| `created_at` | Y | Before first production mutate |
| `allowlist_sha256` | Y | |
| `entries` | Y | Array |
| `entries[].relative_path` | Y | |
| `entries[].existed_before` | Y | Boolean |
| `entries[].pre_image_sha256` | Y* | Required if existed_before |
| `entries[].pre_image_size` | Y* | Required if existed_before |
| `entries[].pre_image_relpath` | Y* | Under `pre_image/` |
| `entries[].production_mtime_before` | N | Forensic |
| `sealed` | Y | Boolean |
| `integrity_guaranteed` | Y | Set false on any capture/apply doubt |

Path: `uploads_apply/pre_image_manifest.json` (atomic rename write).

---

## 8. Failure path (OD-UPLOADS exact)

### 8.1 Trigger

Any of: allowlist violation; zip slip; pre-image failure; apply I/O error; hash mismatch; detected out-of-scope write attempt; inability to guarantee integrity.

### 8.2 Mandatory response

| Step | Action |
|------|--------|
| 1 | **Immediately fail** uploads stage — no best-effort continuation |
| 2 | Set `integrity_guaranteed = false` |
| 3 | Keep **GLOBAL Maintenance ON** |
| 4 | Keep CPR lock (post-PONR rules WP-P1-05) |
| 5 | Write `cpr_failure_event` with `failure_class = uploads` (WP-P1-09 §4.4) |
| 6 | Transition to `cpr_paused_uploads_failed` (WP-P1-03 T32) |
| 7 | Super Admin may only **Resume** (when integrity can be guaranteed — WP-P1-09 §5.3) or **Rollback** to session Full Backup (OD-PIN) |
| 8 | **No** partial acceptance; **no** success-with-warnings |

### 8.3 Apply result fail schema extras

| Field | Value |
|-------|-------|
| `success` | `false` |
| `partial_acceptance` | `false` (const) |
| `best_effort` | `false` (const) |
| `full_tree_attempted` | `false` (must remain false; if true → critical defect) |
| `survivor_paths_modified` | Must be `false`; if detected true → critical incident + fail |

---

## 9. Interaction with Full Backup Rollback (OD-PIN / OD-ROLLBACK)

| Topic | Rule |
|-------|------|
| **Primary rollback** | Pinned session Full Backup restores DB **and** uploads to pre-CPR point (Architecture §11) |
| **Pre-image role** | Secondary assist only — may help forensic restore of individual scoped files; **not sufficient alone** for DB partial failure |
| **Resume after uploads pause** | Only if scoped apply integrity can again be guaranteed; may rebuild staging + re-pre-image as needed; still no full-tree |
| **After Full Rollback** | Production uploads return to pre-session Full Backup state; job staging/pre-image retained for audit |
| **Missing Full pin** | Critical incident (Architecture §11); pre-image does not substitute |

Rollback authorization remains WP-P1-09 / OD-ROLLBACK (Super Admin, paused-on-failure, phrase `RESTORE`, etc.).

---

## 10. Success criteria & CP9 binding

Uploads stage may commit WP-P1-04 **CP9** only when:

1. All plan operations completed within allowlist.  
2. Pre-image manifest sealed with complete entries.  
3. Apply integrity verification PASS.  
4. `survivor_paths_modified = false`.  
5. `full_tree_mode = false` throughout.  
6. `apply_result.success = true`.  

Otherwise CP9 **must not** be written; pause path §8 applies.

---

## 11. Register / Architecture citation map

| Contract element | OD / Principle | Frozen wording locus | Architecture |
|------------------|----------------|----------------------|--------------|
| Strict scope; no full-tree; no survivor modify; pre-image; fail→Maint+SA | OD-UPLOADS | §15 Frozen | §10.2 B |
| Isolation / zero uncontrolled file replacement | Isolation Principle | Register | §10.2 B.5 |
| Staging → allowlist → pre-image → apply | (baseline) | — | §10.2 B.1–4 |
| PONR includes first uploads replace | (baseline) | — | §10.3 |
| Full Backup primary; pre-image secondary | OD-PIN · OD-ROLLBACK | §15 Frozen | §11 |
| Pause Resume/Rollback | OD-UPLOADS · WP-P1-09 | — | §12–§13 |

---

## 12. Acceptance criteria verification

| Criterion (approved P1 plan) | Result |
|------------------------------|--------|
| Full-tree rename path absent | **PASS** — H2; §6.1; `full_tree_mode` must be false |
| Survivor modification forbidden | **PASS** — H3–H4; §4; §8.3 |
| Integrity-fail path matches OD-UPLOADS | **PASS** — H7–H8; §8 |

Additional (Owner execution order for this WP):

| Check | Result |
|-------|--------|
| Scoped apply contract defined | **PASS** — §6 |
| Allowlisted paths defined | **PASS** — §4 |
| Mandatory scoped pre-image + manifest schema | **PASS** — §7 |
| Staging layout defined | **PASS** — §5 |
| Full Backup rollback interaction | **PASS** — §9 |
| Design only / no code | **PASS** |
| OD-UPLOADS + Isolation preserved | **PASS** — §2, §11 |
| Architecture / Register / prior WPs unmodified | **PASS** |

---

## 13. Assumptions

1. Exact C3/C4 path pattern catalogs remain in immutable CRP docs; this WP consumes their allowlist rules.  
2. Production uploads root absolute path is deployment configuration — not redefined here.  
3. WP-P1-11 may add post-apply file witnesses; this WP owns apply-time integrity.  

---

## 14. Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Full-tree pattern borrowed from Full DR | Critical | H2; §6.1 blacklist; acceptance criterion |
| Zip slip / path escape | Critical | §4.2 normalization |
| Skipping pre-image | Critical | H5; algorithm step 4 hard gate |
| Treating pre-image as primary rollback | High | §9 |

No architectural insufficiency. No confirmed defect in prior WPs requiring edits.

---

## 15. Out of scope

- PHP/CLI uploads apply implementation  
- Changing C3/C4 allowlist engines  
- WP-P1-11 post-apply verify report schemas  

---

*End of WP-P1-10. STOP — do not begin WP-P1-11 until Owner review and approval.*
