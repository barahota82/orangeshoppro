# Orange Uploads Access Control Runbook

**Document type:** Engineering archive — operational runbook  
**Scope:** PR-SEC-06 — sensitive uploads static access blocking (IIS / Plesk)  
**Deploy fragment:** `deploy/iis/uploads-sensitive-deny.web.config.fragment.xml`  
**Related implementation:** PR-SEC-06 Batch 2 — `admin/api/payments/proof-download.php`

---

## Purpose

Orange stores uploaded files under the web-root `uploads/` tree. Some subfolders are **public catalog/branding assets**; others hold **private business data** (payment proofs, CRM attachments, company archive, stocktake reports).

PHP upload handlers and authorized download endpoints enforce access in application code, but **IIS can still serve files directly** if a URL is known. This runbook describes which paths are public vs sensitive, how to merge the repo fragment into live `web.config`, and how to verify behavior after deploy.

**The fragment is not active until merged.** Git does not contain live `web.config` (`.gitignore`).

---

## Public upload paths (must remain HTTP-accessible)

These paths are used by the storefront, admin previews, and printed documents. **Do not** add deny rules for them.

| Web path | Usage |
|----------|--------|
| `uploads/products/` | Product images (`storefront_product_image_web_path`, catalog cards, product pages) |
| `uploads/company/` | Company logo on invoices, sales print, company settings preview |

**Verification (after merge):** open a known product image URL and company logo URL in a browser without admin login — expect **200** and image content.

---

## Sensitive upload paths (must be blocked from direct HTTP)

Direct anonymous `GET` to these URLs must return **404** (or equivalent deny) after the IIS fragment is merged.

| Web path | Data | Authorized access in Orange |
|----------|------|-----------------------------|
| `uploads/payment_proofs/` | Bank transfer proofs | **`admin/api/payments/proof-download.php`** (session + country scope). Admin payment review UI uses `proof_url` from `admin/api/payments/review.php` — no longer static `/uploads/payment_proofs/...` URLs. |
| `uploads/company_docs/` | Company document archive | `admin/api/company_documents/download.php` |
| `uploads/customers/{id}/` | Customer CRM attachments | `admin/api/customers/attachment-download.php` |
| `uploads/suppliers/{id}/` | Supplier attachments | `admin/api/suppliers/attachment-download.php` |
| `uploads/stocktake/{id}/` and `uploads/stocktake/_drafts/{token}/` | Inventory reconciliation attachments | `admin/api/inventory-reconciliation/attachment-download.php` |

**Note:** Blocking static access does not remove PHP filesystem read — authorized endpoints continue to work for logged-in admins with permission.

---

## Deploy fragment location

**File:** `deploy/iis/uploads-sensitive-deny.web.config.fragment.xml`

- Marked as a **deploy fragment** in file header comments.
- **Not** automatically applied on `git pull`.
- **Not** a replacement for live `web.config`.
- Requires **IIS URL Rewrite** module (same dependency as `web.config.example` storefront rules).

---

## How to merge into Plesk / IIS web.config

1. **Back up** the current live site `web.config` from Plesk File Manager or FTP (outside Git).

2. Open live `web.config` at the **site physical root** (same folder as `config.php`, `index.php`).

3. Open `deploy/iis/uploads-sensitive-deny.web.config.fragment.xml` from the deployed code tree.

4. Copy the **five** `<rule name="OrangeDenyUploads...">` elements from the fragment into the existing:
   ```xml
   <configuration>
     <system.webServer>
       <rewrite>
         <rules>
           <!-- paste OrangeDenyUploads* rules here, BEFORE OrangeStorefrontDynamic / other catch-all rules -->
         </rules>
       </rewrite>
     </system.webServer>
   </configuration>
   ```
   If the site has **no** `<rewrite>` section yet, merge the rewrite block from `web.config.example` first, then add the deny rules.

5. **Subfolder site** (`PUBLIC_BASE_PATH` in `.env.php`, e.g. `/shop`): edit each rule’s `match url` to include the prefix, e.g. `^shop/uploads/payment_proofs(/.*)?$`.

6. Save live `web.config`. Recycle the site app pool in Plesk if static responses appear cached.

7. Run verification below before considering PR-SEC-06 Batch 1 complete on production.

---

## How to verify sensitive paths are blocked

Use a browser **without** admin session, or `curl -I` from an external client.

Replace `{host}` and optional `{base}` (`PUBLIC_BASE_PATH`, e.g. empty or `/shop`).

| Test URL | Expected |
|----------|----------|
| `{base}/uploads/payment_proofs/` (or a known proof filename if one exists) | **404** Not Found |
| `{base}/uploads/company_docs/` | **404** |
| `{base}/uploads/customers/1/` (any id) | **404** |
| `{base}/uploads/suppliers/1/` | **404** |
| `{base}/uploads/stocktake/1/` | **404** |

**Admin authorized paths (with login):**

| Test | Expected |
|------|----------|
| Payment review → «عرض الإثبات» (`proof-download.php?order_id=…&txn_id=…`) | **200** — image/PDF inline |
| Company document download API | **200** with attachment |
| Customer/supplier/stocktake attachment download APIs | **200** when permitted |

---

## How to verify public images/logos still load

Without admin login:

| Test URL | Expected |
|----------|----------|
| `{base}/uploads/products/{known_product_image}` | **200** |
| `{base}/uploads/company/{known_company_logo}` | **200** |

Also spot-check storefront home/category pages and a printed invoice preview for broken images.

---

## Backup and disaster recovery note

`scripts/backup/orange_backup.ps1` backs up:

- compressed database dump, and  
- `uploads.zip` (file contents only).

**IIS `web.config` rules are not inside `uploads.zip`.** Restoring uploads from backup does **not** restore access control. After any restore or new server build:

1. Deploy current code (`git pull`).  
2. Re-merge `deploy/iis/uploads-sensitive-deny.web.config.fragment.xml` into live `web.config`.  
3. Re-run verification above.

See also `docs/archive/ORANGE_BACKUP_RECOVERY_RUNBOOK.md`.

---

## Revision history

| Item | Detail |
|------|--------|
| PR-SEC-06 Batch 1 | Fragment + this runbook |
| PR-SEC-06 Batch 2 | Payment proof authorized download (`admin/api/payments/proof-download.php`) — required before blocking `uploads/payment_proofs/` static access in production |
