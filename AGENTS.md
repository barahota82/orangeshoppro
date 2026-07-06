# AGENTS.md — Orange Shop Pro

**Audience:** AI coding agents, reviewers, and automation working in this repository.  
**Project:** Production e-commerce (PHP 8.2+, MySQL/MariaDB, HTML, CSS, JavaScript).  
**Canonical workspace:** `D:\orange` (do not mirror or work from another folder unless the owner explicitly asks).

---

## Table of contents

1. [Mandatory reading before any work](#1-mandatory-reading-before-any-work)
2. [Project architecture and folder structure](#2-project-architecture-and-folder-structure)
3. [Coding standards](#3-coding-standards)
4. [Database conventions](#4-database-conventions)
5. [API development standards](#5-api-development-standards)
6. [JSON structure rules](#6-json-structure-rules)
7. [Git workflow](#7-git-workflow)
8. [Security requirements](#8-security-requirements)
9. [Performance guidelines](#9-performance-guidelines)
10. [Testing checklist before every change](#10-testing-checklist-before-every-change)
11. [Modifying existing code without breaking compatibility](#11-modifying-existing-code-without-breaking-compatibility)
12. [Naming conventions](#12-naming-conventions)
13. [Documentation standards](#13-documentation-standards)
14. [Error handling standards](#14-error-handling-standards)
15. [Logging conventions](#15-logging-conventions)
16. [Deployment checklist](#16-deployment-checklist)
17. [Local development (Cursor Cloud / VM)](#17-local-development-cursor-cloud--vm)

---

## 1. Mandatory reading before any work

**Do not edit files, run migrations, or commit until you have read the mandatory archive.**

Single entry file for the full list: **`ORANGE_AGENT_READ_FIRST.txt`** (repository root).

Minimum bundle (always):

| File | Purpose |
|------|---------|
| `docs/archive/ORANGE_STOREFRONT_POLICY_REFERENCE.txt` | Storefront, cart, checkout, registration, tracking, channels — **first priority** when scope touches the shop |
| `IBRAHIM_ORANGE_MASTER.txt` | Unified handoff: DB truth path, deploy, archive precedence |
| `.cursor/rules/orange-session-handoff.mdc` | Session handoff, archive updates on policy decisions, end-of-session notice |
| `docs/archive/ORANGE_STOREFRONT_PERFORMANCE_ROLLOUT.txt` | Performance constraints; do not regress hot paths |
| `.cursor/rules/orange-continuity.mdc` | Full continuity list (items 0–8, 5b, scope-specific 5tax/5c/5m/5sale) |

Read additionally when scope applies:

- **Schema / migrations:** `scripts/orange_db.sql` locally if present (schema truth; if missing, do not assume schema from Git alone)
- **Accounting / GL / reports:** `docs/archive/ORANGE_ACCOUNTING_MAPPING_AND_REPORT_HANDOFF.txt`, `docs/ACCOUNTING_REPORTING_POLICY_V2.md`
- **Unified catalog / taxonomy:** `docs/archive/ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt` (full read before any catalog patch)
- **Multi-country / channels per country:** `docs/archive/ORANGE_OWNER_MULTICOUNTRY_VISION.txt`
- **Operational Q&A:** `docs/archive/ORANGE_AGENT_QA_REFERENCE.txt`

**Policy decisions:** When the owner reaches a final policy decision, update the appropriate archive file **before** considering the task done (see `orange-session-handoff.mdc`).

**End of executable session:** After commits or repo changes, the agent must notify the owner in Arabic per `orange-session-handoff.mdc` (`تم انتهاء الجلسة التنفيذية.` + handoff reminder).

## Repository Source of Truth

This repository already has an established onboarding system.

The mandatory entry point for every AI agent is:

- `ORANGE_AGENT_READ_FIRST.txt`

This file must always be read first before any implementation work.

`AGENTS.md` is an engineering guide only.

It does not replace the project onboarding documents.

When there is any conflict, ambiguity, or overlap, always follow the repository's official documentation in this priority:

1. `ORANGE_AGENT_READ_FIRST.txt`
2. `IBRAHIM_ORANGE_MASTER.txt`
3. `.cursor/rules/*`
4. `docs/archive/*`

Never bypass or replace these documents.

## Agent Operating Principles

Before implementing any solution:

- Read existing code before writing new code.
- Prefer extending existing architecture instead of creating new systems.
- Never duplicate business logic.
- Keep changes as small as possible.
- Preserve backward compatibility.
- Ask for clarification instead of making assumptions whenever repository documentation is unclear.
- Follow repository architecture instead of personal coding preferences.
- Reuse existing helpers, services and APIs whenever possible.

## Existing Architecture First

Always search the repository before creating anything new.

Do not introduce:

- new helper libraries
- new utility layers
- new services
- duplicate APIs
- duplicate database tables
- duplicate business logic

unless the repository clearly lacks an existing implementation.

Always prefer extending existing code over replacing it.

---

## 2. Project architecture and folder structure

### 2.1 Stack overview

| Layer | Technology |
|-------|------------|
| Runtime | PHP 8.2+ (`declare(strict_types=1);` in new/modified files) |
| Database | MySQL / MariaDB, `utf8mb4` / `utf8mb4_unicode_ci` |
| Frontend | Plain HTML, CSS, vanilla JavaScript (no React/Vue build) |
| Package managers | **None** — no Composer, no npm, no webpack |
| Hosting | TMD Hosting, **Windows**, **Plesk** (not Flask) |
| Config | `.env.php` at repo root — **server/local only**, never committed |

### 2.2 Request flow

```
Browser
  ├─ Storefront pages     → pages/*.php + includes/*.php + assets/*
  ├─ Storefront API       → api/**/*.php
  ├─ Admin UI             → admin/index.php?page=… → admin/pages/*.php
  └─ Admin API            → admin/api/**/*.php
         ↓
    config.php (session, env, db(), helpers)
         ↓
    includes/*.php (domain logic, schema, permissions)
         ↓
    MySQL (orange_db)
```

**Schema bootstrap:** Most requests call `orange_catalog_ensure_schema($pdo)` from `includes/catalog_schema.php` (idempotent migrations).

### 2.3 Top-level directories

| Path | Role |
|------|------|
| `/` | `config.php`, `index.php`, `health.php`, entry docs |
| `pages/` | Public storefront pages (`home.php`, `cart.php`, `product.php`, `track.php`, …) |
| `api/` | Public JSON endpoints (orders, auth, products, cart, payments) |
| `admin/` | Back-office shell (`index.php`, `login.php`, `pages/`, `api/`, `assets/`) |
| `includes/` | Shared PHP libraries (~100+ modules: catalog, orders, GL, cart promos, …) |
| `assets/` | Storefront CSS/JS/images/branding |
| `scripts/` | SQL templates, maintenance, hooks, optional CLI (`run_migrations.php`) |
| `scripts/migrations/` | Numbered SQL migrations (runner + `orange_schema_migrations`) |
| `docs/archive/` | Owner policy, handoffs, continuity (authoritative for business rules) |
| `.cursor/rules/` | Cursor agent rules (encoding, stack, session handoff) |

### 2.4 Admin routing

- New admin screens: create `admin/pages/{name}.php` and add `{name}` to `$allowed` in `admin/index.php`.
- Admin APIs live under `admin/api/{resource}/{action}.php` (flat PHP files, not a framework router).

### 2.5 Shared libraries (`includes/`)

Group by domain when searching:

- **Catalog:** `catalog_schema.php`, `catalog_unified_*.php`, `product_channels.php`
- **Storefront:** `storefront_*.php`, `cart_*.php`, `storefront_account.php`
- **Orders:** `order_helpers.php`, `order_intake_queue.php`, `order_stock.php`
- **Accounting:** `gl_*.php`, `journal_*.php`, `fiscal_years.php`
- **Admin:** `admin_permissions.php`, `edit_lock.php`

**Do not** add feature helpers to `config.php` unless the owner explicitly asks. Prefer `includes/{domain}.php`.

### 2.6 Assets and caching

- Static assets served directly; cache-busting via `asset_url()` / `STOREFRONT_ASSET_VERSION` in `.env.php`.
- Storefront hot paths (home, product, cart) are performance-sensitive — see §9.

---

## 3. Coding standards

### 3.1 PHP

- Start files with `<?php` and `declare(strict_types=1);` when touching a file.
- Use PDO via `db()` from `config.php`; prefer prepared statements.
- Business logic belongs in `includes/`, not duplicated in page templates.
- Call `orange_catalog_ensure_schema($pdo)` at the start of write paths and admin APIs.
- Use existing helpers: `json_response()`, `get_json_input()`, `require_admin_api()`, `require_admin_page()`, `t()` for i18n.
- **Do not** add `PDO::MYSQL_ATTR_INIT_COMMAND` to `config.php` unless explicitly approved.
- Charset: `SET NAMES utf8mb4` is set inside `orange_catalog_ensure_schema()` — do not duplicate inconsistently.
- Timezone: `Asia/Kuwait` (set in `config.php`).

### 3.2 File encoding (critical)

- **UTF-8 without BOM** for all PHP, HTML, JS, CSS.
- Never save PHP as UTF-16 LE (breaks execution).
- Before commit: `powershell -NoProfile -File scripts/verify-php-utf8.ps1` (use `-Fix` if needed, then re-verify).
- Install hooks once per clone: `powershell -NoProfile -File scripts/install-hooks.ps1`.

### 3.3 JavaScript

- Vanilla JS in `assets/js/` (storefront) and `admin/assets/` (admin).
- Prefer delegated event handlers for dynamic admin tables (see `admin/pages/departments.php` pattern).
- Pass server data via `data-*-json` attributes or inline `json_encode(..., JSON_UNESCAPED_UNICODE)` — never embed raw JSON in HTML without `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Debounced auto-translation in admin forms: ~600–800 ms after Arabic input; ~550–750 ms after English input (see §3.5).
- Storefront cart/checkout: respect channel cookies, `lang` in payload/URL, and archived channel rules (S25/S26).

### 3.4 HTML

- Storefront and admin UIs are server-rendered PHP templates.
- Arabic admin screens: grid column order follows **visual RTL** — first-mentioned field in a horizontal row appears on the **right** (see `admin/pages/pattern_dictionary.php`, `pd-form-grid`).
- Use semantic structure; avoid breaking existing CSS class contracts without checking dependents.

### 3.5 CSS

- Storefront: `assets/css/`
- Admin: typically co-located in page `<style>` blocks or shared admin assets
- No CSS preprocessor; edit source files directly.
- Do not remove performance-critical minimal rules on hot pages without measuring impact.

### 3.6 Admin translation workflow (mandatory for multilingual fields)

1. **Arabic input (debounced):** silent call chain updates English, then Filipino and Hindi from English (`translate_names_from_ar_en` pattern).
2. **English input (debounced):** regenerate Filipino and Hindi from English only (`forceFromArabic: false`).
3. **Manual button:** same chain, `silent: false`, show errors to user.

APIs:

- Short names: `admin/api/translate/names.php` + `admin/api/lib/translate_names_lib.php`
- Long text: `admin/api/translate/descriptions.php`

**Forbidden:** `admin/api/_shared/translator.php` (deprecated).

---

## 4. Database conventions

### 4.1 Sources of truth (in order)

1. **Production snapshot (local review):** `D:\orange\scripts\orange_db.sql` — mysqldump from live DB; may be gitignored.
2. **Runtime migrations:** `includes/catalog_schema.php` — `orange_catalog_ensure_schema()`; revision constant `ORANGE_CATALOG_SCHEMA_PHP_REVISION`.
3. **Fresh install template:** `scripts/mysql-create-orange-database-full.sql`
4. **Numbered SQL:** `scripts/migrations/NNN_*.sql` via `orange_schema_migrations` table.

When reviewing columns/indexes/FKs, compare code against **`orange_db.sql` first**, not assumptions from older docs.

### 4.2 Schema changes

- **Preferred (owner policy):** implement in `includes/catalog_schema.php`, bump `ORANGE_CATALOG_SCHEMA_PHP_REVISION`, `git push` → server `git pull` → applies on first HTTP request.
- Manual ad-hoc SQL for the server (when needed): write idempotent statements to **`D:\orange_sql_updates.sql`** (outside repo), using `INFORMATION_SCHEMA` checks before `ALTER`/`ADD INDEX`.
- Do **not** add new `.sql` migration files inside the repo for routine work unless the owner explicitly requests it.
- Destructive maintenance scripts under `scripts/maintenance_*` are reference-only; do not wire them into `catalog_schema` without approval.

### 4.3 Table and column conventions

- Table names: `snake_case`, plural where established (`products`, `order_items`, `cart_bogo_promotions`).
- Primary keys: `id` (INT AUTO_INCREMENT).
- Multilingual text columns: suffix `_ar`, `_en`, `_fil`, `_hi` (e.g. `name_ar`, `description_en`).
- Booleans: `TINYINT(1)` with `is_*` prefix (`is_active`, `is_superuser`).
- Timestamps: `created_at`, `updated_at` where used; server timezone Asia/Kuwait.
- FK constraint names: prefixed to avoid collisions (e.g. `orange_fk_*`).
- String lengths: catalog slugs/names often `VARCHAR(191)` for utf8mb4 index compatibility.
- Country scoping: many entities use `country_id`; respect `orange_admin_context_country_id()` and permission locks.
- **Product hard delete:** allowed only with zero business/historical footprint; otherwise deactivate via `admin/api/products/toggle.php` (`is_active = 0`). Guard + delete in `includes/product_delete_policy.php` and `admin/api/products/delete.php` — **`docs/archive/ORANGE_STOCK_ORDER_POLICY.txt` §16**.

### 4.4 Queries

- Always use prepared statements for user input.
- Use `orange_table_exists()` / `orange_table_has_column()` when code must tolerate partial schema during rollout.
- Wrap multi-step writes in transactions where existing modules do (orders, GL posting, stock).

---

## 5. API development standards

### 5.1 Endpoint layout

| Namespace | Path pattern | Auth |
|-----------|--------------|------|
| Storefront | `api/{area}/{action}.php` | Session, tokens, or public read as designed per endpoint |
| Admin | `admin/api/{resource}/{action}.php` | `require_admin_api()` — session `admin_id` |

### 5.2 Standard bootstrap (admin API)

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
// domain includes…
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    // …
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'حدث خطأ غير متوقع');
}
```

Adjust `require_once` depth for file location. Some APIs accept `require_admin_api('GET')` — follow sibling files in the same folder.

### 5.3 Standard bootstrap (storefront API)

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
// domain includes…

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input(); // when POST JSON
    // validate, then json_response([...]);
} catch (Throwable $e) {
    api_error($e, t('generic_error')); // or a specific user message
}
```

### 5.4 HTTP methods and input

- Read admin data: GET with query params (`?id=`, filters).
- Mutations: POST with JSON body via `get_json_input()`.
- Respect archived method restrictions (e.g. `get-order.php` — POST only per storefront policy).
- Validate required fields early; return `422` with stable `code` for client handling.

### 5.5 Responses

- Always use `json_response($payload, $httpCode)` — sets `Content-Type: application/json; charset=utf-8` and exits.
- Never leave JSON endpoints with empty body on error.

### 5.6 Permissions

- After `require_admin_api()`, check capabilities via `includes/admin_permissions.php` helpers before destructive actions.
- Country-scoped admins: use `orange_admin_assert_entity_country()` when loading/editing entities.
- Do not trust client-supplied `country_id` or `storefront_account_id` when session/policy says otherwise.

### 5.7 Side effects

- Stock, GL, and order writes must follow existing modules (`order_stock.php`, `gl_posting`, archived stock/order policy).
- Use `audit_log()` for significant admin mutations when sibling endpoints do.

---

## 6. JSON structure rules

### 6.1 Encoding

- PHP output: `json_encode($data, JSON_UNESCAPED_UNICODE)` via `json_response()`.
- HTML-embedded JSON: `json_encode(..., JSON_UNESCAPED_UNICODE)` + `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- For script-tag safety, add `JSON_HEX_TAG | JSON_HEX_APOS` when embedding in `<script>` (see admin pages pattern).

### 6.2 Response shape (conventions)

**Success:**

```json
{
  "success": true,
  "message": "optional human text",
  "data": { }
}
```

Flat success payloads are also used (e.g. `"success": true, "order_number": "…"`) — match the surrounding endpoint family.

**Failure:**

```json
{
  "success": false,
  "code": "stable_machine_code",
  "message": "User-facing localized or Arabic message"
}
```

- `code` — stable for JS branching (`invalid_phone`, `phone_country_required`, `server_error`, …).
- `message` — safe for UI; no stack traces in production.
- HTTP status: `401` unauthorized, `403` forbidden, `404` not found, `422` validation/business rule, `500` unexpected server error.

### 6.3 Request body

- JSON object at root (not bare array) unless an endpoint already accepts an array by convention.
- Language: include `lang` (`ar`, `en`, `fil`, `hi`) where storefront i18n applies.
- Channel: respect `channel_id` / cookie rules per archive.
- Phone: use structured `phone_country` + national number per storefront policy (section «صفر» in policy reference).

### 6.4 Debug

- `ORANGE_API_DEBUG=1` may add a `debug` field on 500 responses — never enable in production routinely.

---

## 7. Git workflow

### 7.1 Branching and commits

- Default branch: `main`.
- **Commit only when the owner explicitly asks.**
- Write commit messages in complete sentences; focus on **why**, not only what.
- Never commit `.env.php`, credentials, or `scripts/orange_db.sql` dumps.
- Never use `--no-verify` unless the owner explicitly requests bypassing hooks.

### 7.2 Pre-commit

```powershell
powershell -NoProfile -File scripts/verify-php-utf8.ps1
```

If failures: `-Fix`, then verify again without `-Fix`.

### 7.3 CI

- `.github/workflows/php-ci.yml` — `php -l` on all PHP files.
- `.github/workflows/php-encoding.yml` — encoding checks.

Run locally before push:

```bash
find . -name "*.php" -not -path "./vendor/*" -exec php -l {} \; 2>&1 | grep -v "^No syntax errors"
```

### 7.4 Deploy (production)

- **Preferred:** local `git push` → server `git pull` (same bytes, no manual paste into Plesk).
- Do not paste PHP into hosting panels.
- After pull, smoke-test `health.php` with key (see §16).

---

## 8. Security requirements

### 8.1 Secrets and configuration

- `.env.php` exists **only on server / local dev** — never create or commit it in the repo.
- Reference keys via `.env.example.php` documentation only.
- `HEALTH_CHECK_KEY` protects detailed health output; without key, `health.php` returns minimal `OK`.

### 8.2 Authentication and sessions

- Admin: session cookie `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS.
- `require_admin_page()` / `require_admin_api()` gate admin surfaces.
- `admin_login()` calls `session_regenerate_id(true)`.
- Storefront accounts: session-based; do not trust client-sent account IDs (see `create-order.php`).

### 8.3 Input validation

- Validate and normalize phones via `includes/phone_validation.php`.
- Use allowlists for enums (`lang`, status transitions, payment methods).
- Upload paths via `includes/upload_paths.php` — never accept arbitrary filesystem paths from clients.

### 8.4 Output safety

- Escape HTML output: `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`.
- JSON in HTML attributes: encode then escape (see §6.1).

### 8.5 Cryptographic comparisons

- Use `hash_equals()` for secret/key comparison (see `health.php`).

### 8.6 Authorization

- Check admin permissions and country scope before read/write on scoped entities.
- GL and stock operations: follow edit-lock and fiscal-year rules where implemented.

### 8.7 External integrations

- Payment gateways (`includes/payments/`), WhatsApp links, email — use configured credentials from `.env.php` only on server.

---

## 9. Performance guidelines

Read **`docs/archive/ORANGE_STOREFRONT_PERFORMANCE_ROLLOUT.txt`** in full for hot-path rules.

Summary for agents:

- **Do not regress** storefront load times on home, product, cart, checkout.
- **Do not simplify or remove** channel cookie / S25–S26 behavior for perceived performance gains.
- Schema work on storefront requests: migrations run via `orange_catalog_ensure_schema()` — keep new steps idempotent and fast; avoid heavy backfills on every request.
- Prefer APCu/schema gate patterns already in rollout docs over ad-hoc repeated migration work.
- Asset changes: use cache busting; avoid huge uncached JS on hot pages.
- N+1 queries: follow existing batch patterns in catalog/list endpoints.
- Admin-only heavy work belongs in admin APIs or CLI scripts, not storefront page render.

---

## 10. Testing checklist before every change

Use scope-appropriate items — not every row applies to every task.

### 10.1 All PHP changes

- [ ] `php -l` on touched files
- [ ] UTF-8 verify script passes
- [ ] No accidental BOM / UTF-16
- [ ] `declare(strict_types=1)` preserved or added

### 10.2 Schema / DB

- [ ] `ORANGE_CATALOG_SCHEMA_PHP_REVISION` bumped if `catalog_schema.php` changed
- [ ] Migration is idempotent (`INFORMATION_SCHEMA` checks)
- [ ] Compared against local `scripts/orange_db.sql` if available

### 10.3 Admin UI

- [ ] New page added to `$allowed` in `admin/index.php`
- [ ] Arabic grid field order correct (RTL visual order)
- [ ] Translation debounce wired for multilingual fields
- [ ] Permission/country scope enforced

### 10.4 Admin / storefront API

- [ ] Uses `json_response()` / error helpers
- [ ] Stable `code` on validation failures
- [ ] Auth and method restrictions match archive
- [ ] No empty error bodies

### 10.5 Storefront

- [ ] Re-read applicable «تنفيذ» items in `ORANGE_STOREFRONT_POLICY_REFERENCE.txt`
- [ ] Channel + lang behavior intact
- [ ] Cart/checkout/promo order of operations preserved (combo → cart discount → gifts/BOGO per S22)
- [ ] Preview mode (`orange_preview_is_active`) still blocks real writes where required

### 10.6 Accounting / stock (when in scope)

- [ ] GL posting uses `journal_vouchers` / `journal_lines` patterns
- [ ] Stock movements follow `ORANGE_STOCK_ORDER_POLICY.txt`
- [ ] No report logic keyed on account **names** (use codes / `report_line_master`)

### 10.7 Smoke tests (local or post-deploy)

```bash
curl "http://localhost:8080/health.php?key=YOUR_KEY"
# Expect: PHP OK, DB OK, SESSION OK, php_schema_revision=…
```

- [ ] Affected admin page loads without PHP fatal
- [ ] Affected API returns expected JSON shape
- [ ] Storefront page loads without console errors (for JS changes)

---

## 11. Modifying existing code without breaking compatibility

1. **Read callers first** — grep for function/table/column name across `includes/`, `admin/`, `api/`, `pages/`.
2. **Prefer additive changes** — new columns nullable or with defaults; new JSON fields optional; old clients ignore them.
3. **Schema** — use `orange_table_has_column()` guards so mixed deploy/revision states do not fatal.
4. **Do not rename** JSON `code` values or HTTP status semantics without updating all JS consumers.
5. **Promo/cart pipeline** — respect fixed resolution order documented in archive (bundles/combos, subtotal tier discounts, gifts, BOGO).
6. **Feature flags** — follow existing env keys (`STOREFRONT_FORCE_LONG_URLS`, preview modes, unified catalog toggles) instead of hard cutovers.
7. **Deprecated paths** — keep shims until archive says otherwise (e.g. legacy category tables during unified taxonomy rollout).
8. **Minimal diff** — fix the requested scope only; unrelated refactors increase regression risk.
9. **Diagnose before blaming logic** — encoding, allowlist, missing GET params, and DB permissions cause many apparent “bugs”.

---

## 12. Naming conventions

| Element | Convention | Example |
|---------|------------|---------|
| PHP functions (project) | `snake_case` with `orange_` prefix for shared libs | `orange_cart_promotion_resolve()` |
| PHP files | `snake_case.php` | `cart_gift_promotions.php` |
| Admin page slug | `snake_case` matching filename | `cart_bogo_promotions` |
| Admin API folders | plural resource name | `admin/api/products/` |
| DB tables | `snake_case`, plural | `cart_bogo_promotions` |
| DB columns | `snake_case` | `gift_pool_config`, `country_id` |
| Multilingual fields | `{field}_{lang}` | `name_ar`, `title_en` |
| JS (storefront) | `camelCase` functions/vars in existing files | follow local file style |
| CSS classes | kebab-case or established prefix | `pd-form-grid` |
| Migration functions | `orange_catalog_migrate_*_vNNN()` | matches revision blocks |
| Env keys | `SCREAMING_SNAKE_CASE` in `.env.php` | `HEALTH_CHECK_KEY` |
| Audit actions | short verb phrases | `product.update`, `order.cancel` |

---

## 13. Documentation standards

### 13.1 Where to document

| Change type | Document in |
|-------------|-------------|
| Storefront / order / registration policy | `docs/archive/ORANGE_STOREFRONT_POLICY_REFERENCE.txt` |
| Stock / fulfillment | `docs/archive/ORANGE_STOCK_ORDER_POLICY.txt` |
| Accounting / GL | `docs/archive/ORANGE_ACCOUNTING_MAPPING_AND_REPORT_HANDOFF.txt` |
| Catalog / taxonomy | `docs/archive/ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt` |
| Multi-country | `docs/archive/ORANGE_OWNER_MULTICOUNTRY_VISION.txt` |
| Performance / rollout | `docs/archive/ORANGE_STOREFRONT_PERFORMANCE_ROLLOUT.txt` |
| Project status / agreements | `docs/archive/ORANGE_PROJECT_CONTINUITY.txt` |

### 13.2 How to update archives

- **Owner policy:** add/update **only** the relevant item — no full-file rewrites, no ellipses replacing existing lists.
- Include **«تنفيذ»** with file paths when code implements the policy.
- Do not create new root-level markdown docs unless the owner asks (this `AGENTS.md` is the agent-oriented exception).

### 13.3 Code comments

- Comment non-obvious business rules and archive references (`@see docs/archive/...`).
- Avoid narrating obvious code; prefer clarity in naming.

---

## 14. Error handling standards

### 14.1 API errors

| Helper | Use when |
|--------|----------|
| `json_response(['success' => false, 'code' => '…', 'message' => '…'], 4xx)` | Expected validation/business failures |
| `orange_admin_api_catch($e, $generic, 422)` | Admin API catch block — exposes `RuntimeException` message; hides other throwables |
| `api_error($e, $userMessage)` | Storefront/unexpected errors — logs server-side, returns `server_error` |

### 14.2 Rules

- Never expose stack traces, SQL, or file paths to end users in production JSON/HTML.
- Use specific `code` for client branching; use localized `message` via `t()` on storefront when available.
- `audit_log()` must not throw — failures are swallowed after `error_log`.
- Transaction rollbacks: use existing patterns in order/GL modules; do not partial-commit stock/GL pairs.

### 14.3 Page errors

- Admin: show Arabic messages inline or via flash patterns established on sibling pages.
- Storefront: user-friendly messages; log details server-side.

---

## 15. Logging conventions

### 15.1 PHP `error_log`

- Prefix: `[orange]` (or subsystem tag: `[orange audit]`, `[orange audit_log]`).
- Include context: operation, entity, exception message.
- Do not log passwords, tokens, full payment payloads, or PII beyond what existing modules already log.

Example:

```php
error_log('[orange] country-copy accounts: ' . $e->getMessage());
```

### 15.2 Admin audit trail

- `audit_log($action, $message, $entityTable, $entityId)` → `orange_admin_audit_log` table.
- Optional mirror to PHP log when `ORANGE_AUDIT_LOG=true`.

### 15.3 What not to do

- Do not add verbose logging on hot storefront paths without approval.
- Do not rely on logging as the only error signal — still return proper JSON/HTML errors.

---

## 16. Deployment checklist

### 16.1 Pre-push (agent)

- [ ] Mandatory archive read for the task scope
- [ ] UTF-8 verification passed
- [ ] PHP lint clean on touched files
- [ ] No secrets or `.env.php` in diff
- [ ] Owner asked for commit/push (if committing)

### 16.2 Server pull

- [ ] `git pull` on production clone (preferred over FTP upload)
- [ ] No manual PHP paste into Plesk file manager

### 16.3 Post-deploy verification

- [ ] `health.php?key=…` → PHP OK, DB OK, SESSION OK
- [ ] `php_schema_revision` matches expected `ORANGE_CATALOG_SCHEMA_PHP_REVISION`
- [ ] First request may run migrations — retry once if transient 500 (OPcache/schema timing)
- [ ] Spot-check affected admin page + API + storefront path
- [ ] Channel/cart smoke test if storefront logic changed

### 16.4 Rollback mindset

- Schema migrations in `catalog_schema.php` are forward-only — design idempotent steps; avoid destructive drops without owner approval.
- Keep Git revert available; document manual recovery in archive if a policy change requires it.

---

## 17. Local development (Cursor Cloud / VM)

### 17.1 Overview

Orange Shop Pro runs without Composer/npm. Static assets served directly. PHP built-in server or IIS/Plesk locally.

### 17.2 Services

| Service | How to start |
|---------|-------------|
| MariaDB | `sudo mysqld_safe &` (wait ~3s; `sudo chmod 755 /run/mysqld` if socket denied) |
| PHP dev server | `php -S 0.0.0.0:8080 -t /workspace` (or repo root on Windows) |

### 17.3 Dev credentials (local only)

- DB: `orange_dev` / `orange_pass` → database `orange_db`
- Admin: `admin` / `admin123` (seed manually on fresh DB)
- Create `.env.php` from `.env.example.php` — **never commit**

Minimum `.env.php` keys for local dev:

- `DB_USER`, `DB_PASS`
- `STOREFRONT_FORCE_LONG_URLS=true` (built-in PHP server has no URL rewrite)
- `HEALTH_CHECK_KEY=dev_health_check_key_2026`
- `ORANGE_STOREFRONT_GEO_OVERRIDE=kw` (optional)

### 17.4 First-time bootstrap

```bash
# MariaDB
sudo mysqld_safe &
sleep 3
sudo chmod 755 /run/mysqld

sudo mysql -e "CREATE DATABASE IF NOT EXISTS orange_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 CREATE USER IF NOT EXISTS 'orange_dev'@'localhost' IDENTIFIED BY 'orange_pass';
 GRANT ALL PRIVILEGES ON orange_db.* TO 'orange_dev'@'localhost';
 FLUSH PRIVILEGES;"

mysql -u orange_dev -porange_pass orange_db < scripts/mysql-create-orange-database-full.sql

# Seed admin (fresh DB has no admins)
HASH=$(php -r "echo password_hash('admin123', PASSWORD_DEFAULT);")
mysql -u orange_dev -porange_pass orange_db -e "INSERT INTO admins (username, password_hash, display_name, is_active, is_superuser) VALUES ('admin', '$HASH', 'Dev Admin', 1, 1);"
```

Validate:

```bash
curl "http://localhost:8080/health.php?key=dev_health_check_key_2026"
```

Expect: `PHP OK`, `DB OK`, `SESSION OK`.

### 17.5 Local URLs

- Storefront: `http://localhost:8080/pages/home.php`
- Admin: `http://localhost:8080/admin/login.php`
- Health: `http://localhost:8080/health.php?key=dev_health_check_key_2026`

### 17.6 Local gotchas

1. MariaDB socket permissions on `/run/mysqld/` — chmod `755` if PDO cannot connect.
2. `STOREFRONT_FORCE_LONG_URLS=true` when short URLs 404 without IIS rewrite.
3. Full SQL import + runtime `catalog_schema.php` — duplicate-column messages in `run_migrations.php` may be benign; prefer `health.php` smoke test.
4. One known legacy BOM issue may exist in `admin/api/purchases/create.php` — do not introduce new BOM files.
5. Schema auto-migration runs on first HTTP hit after clone — allow one retry on transient failure.

---

## Quick reference links (in-repo)

| Topic | File |
|-------|------|
| Agent read-first gate | `ORANGE_AGENT_READ_FIRST.txt` |
| Master handoff | `IBRAHIM_ORANGE_MASTER.txt` |
| Storefront policy | `docs/archive/ORANGE_STOREFRONT_POLICY_REFERENCE.txt` |
| Performance | `docs/archive/ORANGE_STOREFRONT_PERFORMANCE_ROLLOUT.txt` |
| UTF-8 / SQL / deploy workflow | `.cursor/rules/orange-php-utf8-workflow.mdc` |
| Stack | `.cursor/rules/orange-stack.mdc` |
| Session handoff | `.cursor/rules/orange-session-handoff.mdc` |
| Continuity list | `.cursor/rules/orange-continuity.mdc` |
| Schema entry | `includes/catalog_schema.php` |
| JSON helpers | `config.php` (`json_response`, `api_error`, `audit_log`) |

---

## AI Decision Policy

When multiple valid implementations exist, always follow this priority:

1. Existing repository implementation.
2. Repository documentation.
3. Owner documentation.
4. Cursor rules.
5. Smallest possible code change.

Avoid unnecessary refactoring.

Never redesign existing architecture unless explicitly requested by the owner.

## Documentation Precedence

If any recommendation in `AGENTS.md` conflicts with repository policy, owner documentation, archive documents, or Cursor rules, the official repository documentation always takes precedence.

---

*Last updated: 2026-07-01 — maintain this file when stack or agent workflow conventions change; policy details remain in `docs/archive/`.*
