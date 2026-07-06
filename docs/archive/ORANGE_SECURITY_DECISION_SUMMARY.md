# Orange Security Engineering Decision Summary

**Document type:** Engineering Decision archive (not a bug report, implementation plan, or progress report)  
**Scope:** All reviewed Production Readiness security findings (PR-SEC-01 through PR-SEC-08, including PR-SEC-01B)  
**Source:** Orange Engineering Audit review sessions (engineering review phase; implementation pending where required)  
**Status:** Current approved engineering review decisions as reached in review; no proposed fixes or repository changes recorded here

---

## Purpose

This document preserves the **current approved engineering review classifications and decisions** reached during the Orange Production Readiness security reviews. It exists so future agents and developers can continue work without relying on prior chat transcripts.

---

## Finding Category Index

| Category | Findings |
|----------|----------|
| **Real Bug / Real Security Issue** | PR-SEC-01B (subset), PR-SEC-02, PR-SEC-03, PR-SEC-04, PR-SEC-05, PR-SEC-06, PR-SEC-07 |
| **Defense in Depth** | PR-SEC-01 (aggregate POST surface), PR-SEC-07 (partial), PR-SEC-08 (primary at default config) |
| **Deployment / Infrastructure Issue** | PR-SEC-06 (partial), PR-SEC-07 (HTML fatals), PR-SEC-08 (env misconfiguration risk) |
| **Configuration Issue** | PR-SEC-08 (`ORANGE_API_DEBUG` production misconfiguration) |
| **Payment Integrity Issue** | PR-SEC-05 |

---

## PR-SEC-01 — No CSRF Protection Beyond SameSite Cookies

### Audit finding (original)

No synchronizer CSRF tokens; all state-changing admin/storefront actions rely on session cookies with `SameSite=Lax`.

### Current Engineering Classification

**Defense in Depth** (aggregate finding for session-authenticated POST JSON admin APIs)

### Current Engineering Review Decision

The absence of CSRF tokens on the dominant admin POST JSON mutation path is a **missing second control**, not absence of the **first** control. Orange explicitly sets `SameSite=Lax` on the session cookie. Under Lax, cross-site POST requests do not attach the session cookie; admin mutations require JSON bodies that cross-site HTML forms cannot supply. The review found **no repository evidence of exploitable cross-site POST CSRF** against standard admin JSON write endpoints.

The review **rejects Critical/High severity for the aggregate PR-SEC-01 finding** because elevating token absence alone would overstate POST CSRF risk relative to repository evidence.

Residual **real CSRF-class exposure** exists on specific **GET-accepting mutators and GET logout** endpoints. Those are tracked and decided under **PR-SEC-01B**.

### Business reasoning

CSRF protection prevents a third-party site from causing a logged-in user's browser to perform unwanted authenticated actions. Orange's admin surface covers catalog, orders, stock, accounting, payments, and configuration. A compromised session or CSRF on high-impact mutations could alter pricing, orders, vouchers, and payment settings.

For the **POST JSON bulk of admin APIs**, SameSite=Lax plus JSON-body pattern addresses the primary historical CSRF attack path in the current architecture.

### Architecture reasoning

Orange's **current architecture** is: session authentication + explicit `SameSite=Lax` + JSON-body admin APIs + same-origin JavaScript. CSRF tokens are absent as an architectural layer, not present-but-disabled. SameSite=Lax with HttpOnly and Secure-on-HTTPS matches modern baseline cookie hardening. No archive document states CSRF tokens were an explicit deferred engineering decision; absence is an omission of a second layer, not an absence of all CSRF-related controls.

### Severity

**Not Critical/High for the aggregate finding** (explicit in Phase 5 engineering decision). Original audit posture was Medium; engineering review reclassified the aggregate finding as defense-in-depth rather than demonstrated open POST CSRF.

### Fix timing

**Not assigned in PR-SEC-01 Phase 5.** Actionable authenticated GET CSRF items are decided under PR-SEC-01B.

---

## PR-SEC-01B — Authenticated GET Mutations

### Audit finding (scope)

Four endpoints reviewed as CSRF-class authenticated GET mutations: `admin/api/payments/bank-accounts.php`, `admin/api/doc-token/ensure.php`, `admin/logout.php`, `pages/register.php?logout=1`.

### Current Engineering Classification

**Real Bug / Real CSRF-Class Issue** (confirmed, not theoretical defense-in-depth)

### Current Engineering Review Decision

PR-SEC-01B is a **confirmed real CSRF-class issue**. SameSite=Lax **does** send the session cookie on top-level cross-site GET navigation.

| Endpoint | CSRF-class | Business-impact mutation | Severity | Fix timing |
|----------|------------|--------------------------|----------|------------|
| `bank-accounts.php` (GET toggle) | Yes | Yes — disables payment methods | Medium | Must be resolved before Production Go-Live |
| `doc-token/ensure.php` (GET) | Yes | Yes — creates/returns public document access | Medium | Must be resolved before Production Go-Live |
| `admin/logout.php` (GET) | Yes | No — session termination only | Low | Future |
| `register.php?logout=1` (GET) | Yes | No — storefront account session keys only | Low | Future |

**Overall severity:** Medium (two endpoints mutate business-critical configuration/confidentiality via authenticated GET + SameSite=Lax top-level navigation).

**Overall fix decision:** Must be resolved before Production Go-Live for payment-toggle and document-token GET mutations. Logout-via-GET hardening is real CSRF but lower priority — Future per endpoint.

### Business reasoning

Payment method toggles affect checkout availability for an entire country context. Document-token ensure creates or retrieves public URLs to invoice/sales-return content — unauthorized widening of document confidentiality if an admin is tricked into navigating to a crafted link. Logout endpoints cause session disruption without data mutation.

### Architecture reasoning

These endpoints combine authenticated session cookies (Lax) with GET-triggered state change. This is an **endpoint-design issue within the CSRF domain**, distinct from the POST JSON surface protected by Lax cookie semantics.

### Category

Real Bug / CSRF-Class Issue

---

## PR-SEC-02 — Admin Login Has No Rate Limiting or Lockout

### Audit finding (original)

`admin/login.php` accepts unlimited POST attempts with no throttling, CAPTCHA, or attempt counter. Storefront OTP flows implement attempt limits; admin login does not.

### Current Engineering Classification

**Real Bug / Real Production Security Issue**

### Current Engineering Review Decision

**Real production security issue — High severity — Must be resolved before Production Go-Live.**

Admin login guards the full Orange operational backend (orders, inventory, accounting, payments, configuration, customer PII). Unlimited online password attempts against a public `/admin/login.php` path is a direct authentication control gap. Bcrypt and password-creation policy reduce guess speed but do not replace attempt limiting.

Not Critical — bcrypt is used and compromise requires successful guess. Not Defense in Depth — this is a primary authentication control. Not Low — backend blast radius is too large.

### Business reasoning

A compromised admin account exposes orders, pricing, GL, stock, payments, and customer PII across all operational areas the permission matrix allows.

### Architecture reasoning

Structural gap in `admin/login.php`; not a latent edge case. Storefront OTP demonstrates the project already treats brute-force limits as necessary for customer auth — the same class of risk applies to admin credentials with higher blast radius.

### Category

Real Bug — Authentication Control Gap

### Severity

**High**

### Fix timing

**Must be resolved before Production Go-Live**

---

## PR-SEC-03 — Unauthenticated Diagnostic Endpoint (`env-check.php`)

### Audit finding (original)

`admin/api/departments/env-check.php` returns JSON with `PHP_VERSION` and `PHP_SAPI` with no auth guard. Peer diagnostics (e.g. `deploy-check.php`) correctly call `require_admin_api()`.

### Current Engineering Classification

**Real Bug — Information Disclosure**

### Current Engineering Review Decision

Confirmed **Low**-severity **information disclosure** via `admin/api/departments/env-check.php`. Classify **Fix Immediately** for production hygiene.

Unauthenticated stack fingerprinting with no direct confidentiality, integrity, or availability impact on Orange business data. Real issue, not theoretical, but narrow.

No production business function requires public version disclosure. Peer patterns show the project already treats diagnostics with less exposure.

### Business reasoning

Minor information disclosure aids attackers in fingerprinting the server stack. Not part of storefront checkout, fulfillment, or customer flows.

### Architecture reasoning

Endpoint sits under admin API tree alongside authenticated department management endpoints but lacks the same auth guard. Deploy/encoding diagnostic purpose only.

### Category

Information Disclosure — Unauthenticated Diagnostic Endpoint

### Severity

**Low**

### Fix timing

**Fix Immediately**

---

## PR-SEC-04 — Standalone Admin HTML Endpoints Skip Page-Permission Checks

### Audit finding (original)

`admin/api/customers/print-card.php` and `admin/api/suppliers/print-card.php` call `require_admin_page()` (login only) and country assert, but not `orange_admin_require_page()` for the relevant screen permission.

### Current Engineering Classification

**Real Bug — Authorization Bypass**

### Current Engineering Review Decision

Confirmed **Medium**-severity **authorization bypass** on both print-card endpoints. Classify **Must be resolved before Production Go-Live**.

Real RBAC bypass with sensitive partner PII and balance disclosure, but only for **already-authenticated admin users** and only **within** the admin's country context — not anonymous or cross-country exploitation.

### Business reasoning

Print cards expose customer/supplier code, name, phone, email, civil ID, address, credit limit, AR balance, order counts, storefront account email, notes, and block reason. A logged-in admin without partner permissions could still access cards if they know the URL.

### Architecture reasoning

Standalone HTML endpoints bypass the central `orange_admin_require_page()` gate used by `admin/index.php` routed pages. Production use with multiple scoped admin accounts and a permission matrix is a documented Orange direction.

### Category

Authorization Bypass — Missing Function-Level Access Control (Standalone Admin HTML Endpoints)

### Severity

**Medium**

### Fix timing

**Must be resolved before Production Go-Live**

---

## PR-SEC-05 — Payment Settlement Is Not Atomic

### Audit finding (original)

`orange_payment_gateway_settle()` runs multiple writes with no DB transaction. Idempotency on `txn_uuid` can skip order status update if txn is already `paid` but order is not.

### Current Engineering Classification

**Payment Integrity Issue — Real Bug**

### Current Engineering Review Decision

Confirmed **Critical** payment-integrity defect in `orange_payment_gateway_settle()` — non-atomic writes plus idempotency that skips order updates when txn is already `paid`. **Must be resolved before Production Go-Live** (before online gateway enablement).

For the online gateway payment path when enabled, inconsistent settlement directly breaks the core contract "customer paid → order paid." Idempotent retry logic can **permanently** leave orders unpaid after successful charges. Severity is **latent** while gateway remains disabled, but the defect is structural in the production settlement function.

Not required for bank-transfer-only operation if gateway stays disabled, but **mandatory** before go-live of card/gateway checkout.

### Owner decision (cross-reference)

Aligns with `ORANGE_AUDIT_PROGRESS.md` **ISSUE-12 Pre-Go-Live** decision: Payment Settlement must become atomic before Online Payments are enabled.

### Business reasoning

Gateway settlement is the authoritative step converting verified online payment into business state: audit row in `payment_transactions`, order payment fields, and future GL receipt hook. Until settlement completes atomically, orders can show unpaid while payment is recorded (or vice versa), causing fulfillment delays, disputes, and GL misalignment.

### Architecture reasoning

Settlement function performs ordered writes without a transaction boundary. Idempotency guard on `txn_uuid` can short-circuit order update while txn row already exists as paid.

### Category

Payment Data Integrity — Non-Atomic Gateway Settlement / Idempotency–Order State Divergence

### Severity

**Critical** (for online gateway path when enabled)

### Fix timing

**Must be resolved before Production Go-Live** — specifically before enabling online gateway payments

---

## PR-SEC-06 — Upload Directories Web-Accessible Without Repo-Level Access Rules

### Audit finding (original)

Upload handlers use good validation, but files live under web-root `uploads/`. No `.htaccess` / `web.config` in the repo restricts direct HTTP access to sensitive subfolders (payment proofs, attachments). Product/channel images are intentionally public.

### Current Engineering Classification

**Real Security Issue** (primary) + **Infrastructure / Deployment Issue** (secondary)

### Current Engineering Review Decision

PR-SEC-06 is a **confirmed real security issue** — unauthorized direct HTTP access to sensitive uploads — with an **infrastructure/deployment** dimension because protection is neither implemented in PHP routing nor committed in web-server configuration.

| Field | Decision |
|-------|----------|
| **Severity** | **High** (financial/legal/customer documents; payment proof filenames partially guessable via `orderId`; no access gate on direct GET) |
| **Category** | Authorization / Access Control — Upload Storage & Static File Exposure |
| **Fix Timing** | **Must be resolved before Production Go-Live** |

Not merely Defense in Depth: code states certain paths must not be public while also publishing direct URLs to payment proofs — a broken access-control model. Whether production has unpublished server rules cannot be verified from the repo; the **committed architecture** does not enforce privacy.

### Business reasoning

Payment proofs, company documents, CRM attachments, and stocktake reports are business-critical private data. Unauthorized direct HTTP access exposes financial, legal, and customer information.

### Architecture reasoning

Private business data stored under `/uploads/` inside the site root. Authorized download scripts exist, but IIS static serving can bypass them. Absence of committed deny rules is a deployment surface gap on IIS/Plesk. Partial intentional public behavior for payment proof links in admin UI expands exposure rather than mitigating it.

### Category

Real Security Issue + Deployment / Infrastructure Issue

---

## PR-SEC-07 — No Application-Level Production Error Hardening

### Audit finding (original)

`config.php` has no `ini_set('display_errors', '0')`, no global exception handler, and no shutdown handler. JSON endpoints depend on per-handler try/catch; unwrapped fatals depend on server `php.ini`.

### Current Engineering Classification

**Real Security Issue** (JSON leakage active today) + **Defense in Depth** + **Deployment / Infrastructure Issue** (HTML fatals)

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Severity** | **Medium** |
| **Category** | Information Disclosure — Runtime Error Handling (Application + Inconsistent API Catch Blocks) |
| **Fix Timing** | **Must be resolved before Production Go-Live** |

Repository contains dozens of API handlers that return `$e->getMessage()` to clients on 500 responses without any debug flag — exposing internal error text independent of `php.ini`. Unhandled exceptions on HTML pages remain a conditional real issue when `display_errors=On`. Even with correct `php.ini`, the app lacks global production-safe error enforcement.

### Business reasoning

Uncaught PHP errors could expose stack traces, file paths, or SQL fragments to customers or attackers if server configuration allows display. Affects all surfaces: storefront, admin, payments, health probes.

### Architecture reasoning

Plain PHP with no framework-level error boundary. Every request loads `config.php` which bootstraps session and DB but does not define production-safe PHP error settings globally.

### Category

Real Security Issue + Defense in Depth + Deployment Issue

---

## PR-SEC-08 — `ORANGE_API_DEBUG` Can Leak Exception Messages to Clients

### Audit finding (original)

`api_error()` adds a `debug` field when env `ORANGE_API_DEBUG` is `1` or `true`. Misconfiguration in production would expose raw exception text.

### Current Engineering Classification

**Defense in Depth / Deployment Configuration Issue** (primary at default config); **Real Security Issue** (conditional when misconfigured)

### Current Engineering Review Decision

| Field | Decision |
|-------|----------|
| **Severity** | **Medium** (when `ORANGE_API_DEBUG` enabled); **Low** as latent misconfiguration risk at default |
| **Category** | Information Disclosure — Debug Configuration (`ORANGE_API_DEBUG` env gate on `api_error()` / GL API catch) |
| **Fix Timing** | **Must be resolved before Production Go-Live** |

Valid defense-in-depth / deployment-configuration finding. Becomes a real security issue **only if `ORANGE_API_DEBUG` is enabled in production**. Does **not** explain the broader JSON `getMessage()` leakage (PR-SEC-07). Project policy forbids production use of the debug flag. Control lives in server environment, not in `.env.php` or `.env.example.php`, increasing misconfiguration risk during Plesk pool setup.

### Business reasoning

Internal error details in API responses aid exploitation and reveal implementation details.

### Architecture reasoning

Intentional diagnostic switch gated by environment variable. Separate from the always-on catch-block message leakage tracked under PR-SEC-07.

### Category

Configuration Issue + Defense in Depth (default) / Real Security Issue (when misconfigured)

---

## Document Control

This archive records **current approved engineering review decisions only**. Implementation steps, code changes, and proposed fixes are intentionally excluded per Orange Engineering Audit policy.
