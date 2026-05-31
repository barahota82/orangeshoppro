# Orange — Full system review export (Admin + Storefront + Policies + GAPs)
# Usage: powershell -NoProfile -File scripts/orange_full_system_review.ps1

$root = 'd:\orange'
$date = Get-Date -Format 'yyyy-MM-dd'
$exportDir = Join-Path $root 'docs/exports'
New-Item -ItemType Directory -Force -Path $exportDir | Out-Null

function Export-ReviewCsv {
    param([string]$Name, [object[]]$Rows)
    $path = Join-Path $exportDir "${Name}_${date}.csv"
    $Rows | Export-Csv -LiteralPath $path -NoTypeInformation -Encoding UTF8
    return $path
}

$adminScreens = @(
    @{ Page = 'dashboard'; Area = 'Main'; Scope = 'country'; PolicyRef = 'v52'; Status = 'OK'; Notes = '' },
    @{ Page = 'stock'; Area = 'Warehouse'; Scope = 'country'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'opening_stock_balances'; Area = 'Warehouse'; Scope = 'country'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'item_card'; Area = 'Warehouse'; Scope = 'country'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'suppliers'; Area = 'Purchasing'; Scope = 'country'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'purchases'; Area = 'Purchasing'; Scope = 'country'; PolicyRef = 'GAP-04'; Status = 'OK'; Notes = 'GL country_id on posting' },
    @{ Page = 'purchase_returns'; Area = 'Purchasing'; Scope = 'country'; PolicyRef = 'GAP-04'; Status = 'OK'; Notes = '' },
    @{ Page = 'departments'; Area = 'Catalog'; Scope = 'global+per-country active'; PolicyRef = 'v52-S9'; Status = 'OK'; Notes = 'department_countries' },
    @{ Page = 'unified_catalog_branches'; Area = 'Catalog'; Scope = 'global'; PolicyRef = '5tax'; Status = 'OK'; Notes = 'Unified taxonomy ERD' },
    @{ Page = 'product_types'; Area = 'Catalog'; Scope = 'global'; PolicyRef = '5tax'; Status = 'OK'; Notes = '' },
    @{ Page = 'catalog_attributes'; Area = 'Catalog'; Scope = 'global'; PolicyRef = '5tax'; Status = 'OK'; Notes = '' },
    @{ Page = 'color_dictionary'; Area = 'Catalog'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'pattern_dictionary'; Area = 'Catalog'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'size_scheme_templates'; Area = 'Catalog'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'sizing_dictionary'; Area = 'Catalog'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'size_families'; Area = 'Catalog'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'advisory_sizing_guides'; Area = 'Catalog'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'products'; Area = 'Products'; Scope = 'country'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'customers'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'S15/zero'; Status = 'OK'; Notes = 'Phone country select' },
    @{ Page = 'orders'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'S16-17'; Status = 'OK'; Notes = '' },
    @{ Page = 'company_sales_invoice'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'GAP-SALE-DOC-01'; Status = 'OK'; Notes = 'INV-C doc v2 sv2' },
    @{ Page = 'online_sales_invoice'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'GAP-SALE-DOC-01'; Status = 'OK'; Notes = 'INV-O doc v2 ov2' },
    @{ Page = 'reserved_orders'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'S23/S28'; Status = 'OK'; Notes = 'Stock reservations' },
    @{ Page = 'order_intake_queue'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'S1'; Status = 'OK'; Notes = '' },
    @{ Page = 'invoice'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'v52-S3'; Status = 'OK'; Notes = 'company_settings per country' },
    @{ Page = 'manual_order'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'GAP-SALE-DOC-01'; Status = 'OK'; Notes = 'Legacy redirect to company_sales_invoice' },
    @{ Page = 'sales_invoices'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'GAP-SALE-DOC-01'; Status = 'OK'; Notes = 'Legacy browse redirect to company_sales_invoice' },
    @{ Page = 'online_invoices'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'GAP-SALE-DOC-01'; Status = 'OK'; Notes = 'Legacy redirect to online_sales_invoice' },
    @{ Page = 'sales_returns'; Area = 'Sales'; Scope = 'country'; PolicyRef = 'GAP-SALE-DOC-01'; Status = 'OK'; Notes = 'sr2 doc v2 parity pr2' },
    @{ Page = 'offers'; Area = 'Promotions'; Scope = 'country'; PolicyRef = 'S4'; Status = 'OK'; Notes = '' },
    @{ Page = 'cart_promotions'; Area = 'Promotions'; Scope = 'country'; PolicyRef = 'S4/S7'; Status = 'OK'; Notes = '' },
    @{ Page = 'cart_gift_promotions'; Area = 'Promotions'; Scope = 'country'; PolicyRef = 'S4'; Status = 'OK'; Notes = '' },
    @{ Page = 'cart_bogo_promotions'; Area = 'Promotions'; Scope = 'country'; PolicyRef = 'S4'; Status = 'OK'; Notes = '' },
    @{ Page = 'cart_combo_promotions'; Area = 'Promotions'; Scope = 'country'; PolicyRef = 'S4/S22'; Status = 'OK'; Notes = '' },
    @{ Page = 'reports'; Area = 'SalesReports'; Scope = 'country'; PolicyRef = 'S8'; Status = 'OK'; Notes = 'Sales by delivery area' },
    @{ Page = 'channel_analytics'; Area = 'SalesReports'; Scope = 'country'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'chart_of_accounts'; Area = 'GL'; Scope = 'country'; PolicyRef = 'GAP-09'; Status = 'OK'; Notes = '' },
    @{ Page = 'gl_account_settings'; Area = 'GL'; Scope = 'country'; PolicyRef = 'GAP-01'; Status = 'OK'; Notes = '' },
    @{ Page = 'journal_types'; Area = 'GL'; Scope = 'country'; PolicyRef = 'v52-S1'; Status = 'OK'; Notes = '' },
    @{ Page = 'fiscal_years'; Area = 'GL'; Scope = 'country'; PolicyRef = 'v52-S2'; Status = 'OK'; Notes = '' },
    @{ Page = 'edit_lock'; Area = 'GL'; Scope = 'country'; PolicyRef = 'GL-MOVEMENTS'; Status = 'OK'; Notes = 'Edit lock hub (replaces legacy gl_posting)' },
    @{ Page = 'opening_balances'; Area = 'GL'; Scope = 'country'; PolicyRef = 'GAP-06'; Status = 'OK'; Notes = '' },
    @{ Page = 'journal_entries'; Area = 'Vouchers'; Scope = 'country'; PolicyRef = 'GAP-09'; Status = 'OK'; Notes = 'journal_voucher_screen.php' },
    @{ Page = 'receipt_voucher'; Area = 'Vouchers'; Scope = 'country'; PolicyRef = 'GAP-09'; Status = 'OK'; Notes = '' },
    @{ Page = 'payment_voucher'; Area = 'Vouchers'; Scope = 'country'; PolicyRef = 'GAP-09'; Status = 'OK'; Notes = '' },
    @{ Page = 'other_vouchers'; Area = 'Vouchers'; Scope = 'country'; PolicyRef = 'GAP-09'; Status = 'OK'; Notes = '' },
    @{ Page = 'partner_customer_receipt'; Area = 'Vouchers'; Scope = 'country'; PolicyRef = 'GAP-09'; Status = 'OK'; Notes = 'partner_party_voucher_ui' },
    @{ Page = 'partner_supplier_payment'; Area = 'Vouchers'; Scope = 'country'; PolicyRef = 'GAP-09'; Status = 'OK'; Notes = '' },
    @{ Page = 'journal_voucher_reports'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'GAP-09'; Status = 'OK'; Notes = '' },
    @{ Page = 'partner_account_statement'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'GAP-09-j2'; Status = 'OK'; Notes = '' },
    @{ Page = 'financial_report'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'ACCOUNTING-v2'; Status = 'OK'; Notes = '' },
    @{ Page = 'report_gl_account_monthly'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'GAP-09-j2'; Status = 'OK'; Notes = '' },
    @{ Page = 'partner_reports'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'report_income_statement'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'ACCOUNTING-v2'; Status = 'OK'; Notes = '' },
    @{ Page = 'report_trading_account'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'ACCOUNTING-v2'; Status = 'OK'; Notes = 'includes detail partial' },
    @{ Page = 'report_pl_monthly'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'GAP-09-j2'; Status = 'OK'; Notes = '' },
    @{ Page = 'report_pl_compare_years'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'report_trial_balance'; Area = 'GLReports'; Scope = 'country'; PolicyRef = 'ACCOUNTING-v2'; Status = 'OK'; Notes = '' },
    @{ Page = 'company_settings'; Area = 'Settings'; Scope = 'country'; PolicyRef = 'v52-S3'; Status = 'OK'; Notes = '' },
    @{ Page = 'company_documents'; Area = 'Settings'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'logs'; Area = 'Settings'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'countries'; Area = 'Settings'; Scope = 'global'; PolicyRef = 'MULTICOUNTRY'; Status = 'OK'; Notes = 'country_provision' },
    @{ Page = 'admin_users'; Area = 'Settings'; Scope = 'global'; PolicyRef = 'admin-screens'; Status = 'OK'; Notes = '' },
    @{ Page = 'channels'; Area = 'Market'; Scope = 'country'; PolicyRef = 'S31/v55'; Status = 'OK'; Notes = 'is_country_default' },
    @{ Page = 'delivery_areas'; Area = 'Market'; Scope = 'country'; PolicyRef = 'S8/S20'; Status = 'OK'; Notes = '' },
    @{ Page = 'storefront_hero'; Area = 'Market'; Scope = 'country'; PolicyRef = 'GAP-03/S3'; Status = 'OK'; Notes = '' },
    @{ Page = 'storefront_merge_requests'; Area = 'Market'; Scope = 'country'; PolicyRef = 'GAP-07/S15'; Status = 'OK'; Notes = '' }
)

$routerContent = Get-Content -LiteralPath (Join-Path $root 'admin/index.php') -Raw

$adminRows = foreach ($s in $adminScreens) {
    $path = Join-Path $root "admin/pages/$($s.Page).php"
    $exists = Test-Path -LiteralPath $path
    $inRouter = $routerContent -match "'$($s.Page)'"
    [pscustomobject]@{
        Section      = 'AdminScreen'
        Page         = $s.Page
        Area         = $s.Area
        CountryScope = $s.Scope
        PolicyRef    = $s.PolicyRef
        Status       = if (-not $exists) { 'MISSING_FILE' } else { $s.Status }
        InRouter     = if ($inRouter) { 'yes' } else { 'no' }
        FilePath     = "admin/pages/$($s.Page).php"
        Notes        = $s.Notes
    }
}

$storefrontPages = @(
    @{ Page = 'home.php'; Role = 'Home+channel'; PolicyRef = 'S3/S25-26/S31'; Status = 'OK' },
    @{ Page = 'product.php'; Role = 'Product'; PolicyRef = 'S6'; Status = 'OK' },
    @{ Page = 'cart.php'; Role = 'Cart+checkout'; PolicyRef = 'S1-4/S7/S18-22/S30'; Status = 'OK' },
    @{ Page = 'register.php'; Role = 'Register'; PolicyRef = 'S8-11/S15/zero'; Status = 'OK' },
    @{ Page = 'verify-email.php'; Role = 'Email verify'; PolicyRef = 'S9'; Status = 'OK' },
    @{ Page = 'track.php'; Role = 'Track order'; PolicyRef = 'S12-14/S27'; Status = 'OK' },
    @{ Page = 'region-unavailable.php'; Role = 'Geo block'; PolicyRef = 'S31'; Status = 'OK' }
)

$storefrontApi = @(
    @{ Api = 'api/orders/create-order.php'; Role = 'Create order'; PolicyRef = 'S1/S16-18/S23'; Status = 'OK' },
    @{ Api = 'api/orders/get-order.php'; Role = 'Track POST'; PolicyRef = 'S12/S27'; Status = 'OK' },
    @{ Api = 'api/orders/cancel-by-customer.php'; Role = 'Cancel'; PolicyRef = 'S14'; Status = 'OK' },
    @{ Api = 'api/orders/amend-order-items.php'; Role = 'Amend items'; PolicyRef = 'S22'; Status = 'OK' },
    @{ Api = 'api/orders/list-storefront-orders.php'; Role = 'Account orders'; PolicyRef = 'S13'; Status = 'OK' },
    @{ Api = 'api/orders/list-guest-storefront-orders.php'; Role = 'Guest orders'; PolicyRef = 'S13'; Status = 'OK' },
    @{ Api = 'api/orders/intake-status.php'; Role = 'Intake status'; PolicyRef = 'S1'; Status = 'OK' },
    @{ Api = 'api/orders/email-track-order-summary.php'; Role = 'Track email'; PolicyRef = 'S13'; Status = 'OK' },
    @{ Api = 'api/auth/request-email-verify.php'; Role = 'Email verify API'; PolicyRef = 'S9-11/S15'; Status = 'OK' },
    @{ Api = 'api/auth/apply-phone-merge.php'; Role = 'Phone merge'; PolicyRef = 'S15/GAP-07'; Status = 'OK' },
    @{ Api = 'api/cart/checkout-preview.php'; Role = 'Checkout preview'; PolicyRef = 'S4/S7'; Status = 'OK' },
    @{ Api = 'api/cart/stock-limits.php'; Role = 'Stock limits'; PolicyRef = 'S5/S23'; Status = 'OK' },
    @{ Api = 'api/products/get-products.php'; Role = 'Product list'; PolicyRef = 'S6/v52-S9'; Status = 'OK' },
    @{ Api = 'api/products/get-product.php'; Role = 'Product one'; PolicyRef = 'S6'; Status = 'OK' },
    @{ Api = 'api/products/get-categories.php'; Role = 'Categories'; PolicyRef = '5tax'; Status = 'OK' },
    @{ Api = 'api/products/get-attribute-facets.php'; Role = 'Facets'; PolicyRef = 'v52-S9'; Status = 'OK' },
    @{ Api = 'api/products/variant-labels.php'; Role = 'Variant labels'; PolicyRef = 'admin-screens'; Status = 'OK' }
)

$sfRows = @()
foreach ($p in $storefrontPages) {
    $exists = Test-Path -LiteralPath (Join-Path $root "pages/$($p.Page)")
    $sfRows += [pscustomobject]@{
        Section = 'StorefrontPage'; Item = $p.Page; Role = $p.Role
        PolicyRef = $p.PolicyRef; Status = if ($exists) { $p.Status } else { 'MISSING' }; Notes = ''
    }
}
foreach ($a in $storefrontApi) {
    $exists = Test-Path -LiteralPath (Join-Path $root $a.Api)
    $sfRows += [pscustomobject]@{
        Section = 'StorefrontAPI'; Item = $a.Api; Role = $a.Role
        PolicyRef = $a.PolicyRef; Status = if ($exists) { $a.Status } else { 'MISSING' }; Notes = ''
    }
}
$sfRows += [pscustomobject]@{
    Section = 'StorefrontEntry'; Item = 'index.php'; Role = 'Geo entry'
    PolicyRef = 'S31'; Status = 'OK'; Notes = 'storefront_entry.php'
}
$sfRows += [pscustomobject]@{
    Section = 'StorefrontEntry'; Item = 'includes/storefront_geo.php'; Role = 'CF-IPCountry'
    PolicyRef = 'S31'; Status = 'OK'; Notes = ''
}

$policies = @(
    @{ Id = 'zero'; Topic = 'Phone country code'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S1'; Topic = 'Cart+WhatsApp sale path'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S2'; Topic = 'Guest vs registered'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S3'; Topic = 'Price + hero/header copy'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S4'; Topic = 'Promos BOGO/combo/gift'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S5'; Topic = 'Out of stock UX'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S6'; Topic = 'Channels vs stock'; Status = 'PARTIAL'; HasImplement = 'yes'; Notes = 'Stock per-country (vision 13) not all paths' },
    @{ Id = 'S7'; Topic = 'Register teaser in cart'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S8'; Topic = 'Register fields + areas'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S9'; Topic = 'Email verification'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S10'; Topic = 'Channel on register'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S11'; Topic = 'Email per country'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S12'; Topic = 'Who can track'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S13'; Topic = 'Track detail level'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S14'; Topic = 'Customer cancel'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S15'; Topic = 'Phone merge'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S16'; Topic = 'Order created when'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S17'; Topic = 'Instant order number'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S18'; Topic = 'Order phone'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S19'; Topic = 'Guest email'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S20'; Topic = 'Address/area'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S21'; Topic = 'WhatsApp confirmation'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S22'; Topic = 'Amend order'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S23'; Topic = 'Stock deduct timing'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S24'; Topic = 'Insufficient after order'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S25'; Topic = 'Default channel'; Status = 'OK'; HasImplement = 'yes'; Notes = 'PERF: do not break' },
    @{ Id = 'S26'; Topic = 'Channel switch'; Status = 'OK'; HasImplement = 'yes'; Notes = 'PERF: cookie path' },
    @{ Id = 'S27'; Topic = 'Track share security'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S28'; Topic = 'Reservation retention'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S29'; Topic = 'No auto GL on online order'; Status = 'OK'; HasImplement = 'yes'; Notes = 'Fulfillment posts later' },
    @{ Id = 'S30'; Topic = 'Partial cart submit'; Status = 'OK'; HasImplement = 'yes'; Notes = '' },
    @{ Id = 'S31'; Topic = 'Geo root /'; Status = 'OK'; HasImplement = 'yes'; Notes = '' }
)

$policyRows = foreach ($p in $policies) {
    [pscustomobject]@{
        Section = 'StorefrontPolicy'; PolicyId = $p.Id; Topic = $p.Topic
        Status = $p.Status; ArchiveImpl = $p.HasImplement; Notes = $p.Notes
    }
}

$gaps = @(
    @{ Id = 'GAP-01'; Severity = 'HIGH'; Status = 'CLOSED'; Topic = 'gl_journal_type_rules per country' },
    @{ Id = 'GAP-02'; Severity = 'HIGH'; Status = 'CLOSED'; Topic = 'provision journal_type remap' },
    @{ Id = 'GAP-03'; Severity = 'MEDIUM'; Status = 'CLOSED'; Topic = 'storefront_hero scoped' },
    @{ Id = 'GAP-04'; Severity = 'MEDIUM'; Status = 'CLOSED'; Topic = 'purchase GL country_id' },
    @{ Id = 'GAP-05'; Severity = 'MEDIUM'; Status = 'CLOSED'; Topic = 'fiscal voucher fallback country' },
    @{ Id = 'GAP-06'; Severity = 'MEDIUM'; Status = 'CLOSED'; Topic = 'opening_balances country' },
    @{ Id = 'GAP-07'; Severity = 'LOW'; Status = 'CLOSED'; Topic = 'phone merge per country' },
    @{ Id = 'GAP-08'; Severity = 'LOW'; Status = 'CLOSED'; Topic = 'legacy copy_lines migrate' },
    @{ Id = 'GAP-09'; Severity = 'AUDIT'; Status = 'CLOSED'; Topic = 'GL country bind j4 182 hits 0 HIGH 0 LOW' },
    @{ Id = 'GAP-SALE-DOC-01'; Severity = 'MEDIUM'; Status = 'CLOSED'; Topic = 'Sales invoice doc v2 INV-C/INV-O/sr2 phases 0-4' }
)

$gapRows = foreach ($g in $gaps) {
    [pscustomobject]@{
        Section = 'GAP'; GapId = $g.Id; Severity = $g.Severity
        Status = $g.Status; Topic = $g.Topic; OpenGaps = 0
    }
}

$apiFiles = Get-ChildItem -Path (Join-Path $root 'admin/api') -Recurse -Filter '*.php' -File |
    Where-Object { $_.Name -ne 'translate_names_lib.php' }
$apiRows = foreach ($f in ($apiFiles | Sort-Object FullName)) {
    $rel = $f.FullName.Substring($root.Length + 1).Replace('\', '/')
    $text = Get-Content -LiteralPath $f.FullName -Raw -ErrorAction SilentlyContinue
    $scoped = ($text -match 'country_id|orange_admin_settings_effective_country_id|orange_gl_voucher_country_bind|orange_accounts_sql_country_filter')
    [pscustomobject]@{
        Section = 'AdminAPI'; ApiPath = $rel
        CountryScopedHint = if ($scoped) { 'likely' } else { 'check/PK/global' }; Notes = ''
    }
}

$adminOk = @($adminRows | Where-Object Status -eq 'OK').Count
$adminPartial = @($adminRows | Where-Object Status -eq 'PARTIAL').Count
$sfOk = @($sfRows | Where-Object Status -eq 'OK').Count
$polOk = @($policyRows | Where-Object Status -eq 'OK').Count
$polPartial = @($policyRows | Where-Object Status -eq 'PARTIAL').Count
$gitCommit = git -C $root rev-parse --short HEAD 2>$null

$summaryRows = @(
    [pscustomobject]@{ Metric = 'Scan_date'; Value = $date },
    [pscustomobject]@{ Metric = 'Git_commit'; Value = $gitCommit },
    [pscustomobject]@{ Metric = 'Admin_screens_total'; Value = $adminRows.Count },
    [pscustomobject]@{ Metric = 'Admin_screens_OK'; Value = $adminOk },
    [pscustomobject]@{ Metric = 'Admin_screens_PARTIAL'; Value = $adminPartial },
    [pscustomobject]@{ Metric = 'Storefront_routes_total'; Value = $sfRows.Count },
    [pscustomobject]@{ Metric = 'Storefront_OK'; Value = $sfOk },
    [pscustomobject]@{ Metric = 'Policy_items'; Value = $policyRows.Count },
    [pscustomobject]@{ Metric = 'Policy_OK'; Value = $polOk },
    [pscustomobject]@{ Metric = 'Policy_PARTIAL'; Value = $polPartial },
    [pscustomobject]@{ Metric = 'GAP_open'; Value = 0 },
    [pscustomobject]@{ Metric = 'GAP_closed'; Value = $gaps.Count },
    [pscustomobject]@{ Metric = 'Admin_API_files'; Value = $apiRows.Count },
    [pscustomobject]@{ Metric = 'GAP09_GL_scan'; Value = '182 hits / 0 HIGH / 0 LOW' }
)

$masterRows = @()
foreach ($r in $adminRows) {
    $masterRows += [pscustomobject]@{
        Section = $r.Section; Key = $r.Page; Area = $r.Area; Scope = $r.CountryScope
        PolicyRef = $r.PolicyRef; Status = $r.Status; Notes = "$($r.Notes) | router:$($r.InRouter)"
    }
}
foreach ($r in $sfRows) {
    $masterRows += [pscustomobject]@{
        Section = $r.Section; Key = $r.Item; Area = $r.Role; Scope = ''
        PolicyRef = $r.PolicyRef; Status = $r.Status; Notes = $r.Notes
    }
}
foreach ($r in $policyRows) {
    $masterRows += [pscustomobject]@{
        Section = $r.Section; Key = $r.PolicyId; Area = $r.Topic; Scope = ''
        PolicyRef = 'STOREFRONT_POLICY'; Status = $r.Status; Notes = $r.Notes
    }
}
foreach ($r in $gapRows) {
    $masterRows += [pscustomobject]@{
        Section = $r.Section; Key = $r.GapId; Area = $r.Topic; Scope = $r.Severity
        PolicyRef = 'ADMIN_GAPS'; Status = $r.Status; Notes = ''
    }
}

$p1 = Export-ReviewCsv -Name 'ORANGE_FULL_REVIEW_summary' -Rows $summaryRows
$p2 = Export-ReviewCsv -Name 'ORANGE_FULL_REVIEW_admin_screens' -Rows $adminRows
$p3 = Export-ReviewCsv -Name 'ORANGE_FULL_REVIEW_storefront' -Rows $sfRows
$p4 = Export-ReviewCsv -Name 'ORANGE_FULL_REVIEW_policy' -Rows $policyRows
$p5 = Export-ReviewCsv -Name 'ORANGE_FULL_REVIEW_gaps' -Rows $gapRows
$p6 = Export-ReviewCsv -Name 'ORANGE_FULL_REVIEW_admin_apis' -Rows $apiRows
$p7 = Export-ReviewCsv -Name 'ORANGE_FULL_REVIEW_master' -Rows $masterRows
Copy-Item -LiteralPath $p7 -Destination (Join-Path $exportDir "ORANGE_FULL_REVIEW_${date}.csv") -Force

Write-Host "Orange full review $date commit $gitCommit"
Write-Host "Admin: $($adminRows.Count) ($adminOk OK, $adminPartial PARTIAL)"
Write-Host "Storefront: $($sfRows.Count) ($sfOk OK)"
Write-Host "Policies: $($policyRows.Count) ($polOk OK, $polPartial PARTIAL)"
Write-Host "Master: $p7"
