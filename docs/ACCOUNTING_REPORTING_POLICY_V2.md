# Accounting Reporting Policy (v2)

This document distinguishes **implemented behavior in Orange**, **rules that reporting code must enforce**, and **future modules**.

---

## 1. Implemented account mapping (`accounts`)

| Area | Behavior |
|------|-----------|
| **Parent inheritance** | On create/update — `account_type`, `report_section`, `normal_balance`, `cashflow_section`, `report_line_id` default from parent (and tree-context derivation where empty), merged with sanitized user input (`includes/account_tree.php`, `admin/api/accounts/save-node.php`). |
| **Report lines** | `report_line` free text is replaced by **`report_line_id`** → `report_line_master.code`/`label_*` (`includes/report_line_master.php`, DDL in `includes/catalog_schema.php`). User may only pick from the seeded master list in the Chart of Accounts UI. |
| **Posting eligibility** | `is_group`: groups are non-posting; postings only on posting leaves (`orange_accounts_account_is_posting_leaf`, journal API). |

---

## 2. Required report generation behavior (ongoing conformance)

Reporting **must**:

- Prefer **aggregation by mapping** (`report_section`, `account_type`, `report_line_id` / master labels), not account names — **names remain for disclosure lines only**.
- Fall back to **tree/P&amp;L role** helpers (`orange_accounts_account_pl_role` / `bs_role`) **only where** `report_line_id` / mapping columns were never set (legacy databases).
- Enforce **`debit == credit`** at journal voucher level independently of titles (existing voucher logic).
- **Empty COA (no posting leaves yet):** Income statement, trading account, and monthly P&amp;L pages must still render the chrome (title, period form) and a visible in-app notice — not a blank main area — while the ledger has no posting-leaf rows to classify.

Screens updated toward mapping-first grouping include **Trading** and **Income Statement** (section membership via **`orange_accounts_pnl_bucket_for_report`** plus `orange_accounts_map_row_from_leaf_account_row` before `report_section` gates). **Year-over-year P&amp;L comparison** (`admin/pages/report_pl_compare_years.php`) uses **`orange_accounts_fy_pl_summary_from_vouchers`**. The same shared helpers underpin **Financial report** (P&amp;L / balance summary and **embedded trial balance**), **standalone trial balance** (`admin/pages/report_trial_balance.php`), and **monthly P&amp;L** (`admin/pages/report_pl_monthly.php`) in `includes/accounting_report_mapping.php`. Broader reports should follow the same pattern when touching them.

---

## 3. Future / out of scope here

| Module | Scope |
|--------|--------|
| **Analytical dimensions** | branch / channel / sales_rep / campaign / department — not part of COA FK work. |
| **Reconciliation** | Inventory ↔ count, AR ↔ aging, Bank ↔ statement — operational processes adjacent to ledger integrity. |

---

## 4. Maintainer notes

- Master rows are **`INSERT … ON DUPLICATE KEY UPDATE`** via `orange_report_line_master_seed_defaults()`; extend codes there and re-run migrations/seed safely.
- `ORANGE_CATALOG_SCHEMA_PHP_REVISION` is bumped when DDL in `orange_catalog_ensure_schema()` changes (see revision constant in `includes/catalog_schema.php`).
