<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$adminCountryId = orange_admin_context_country_id($pdo);
$purchasesProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $adminCountryId);
$purchasesSuppliersCountrySql = orange_sql_country_and_fragment($pdo, 'suppliers', 'suppliers', $adminCountryId);

/* ── Products (with item_code / barcode when available) ────────────── */
$pv2ProdCols = 'p.id, p.name, p.cost, p.has_colors, p.has_sizes';
if (orange_table_has_column($pdo, 'products', 'item_code')) {
    $pv2ProdCols .= ', p.item_code';
}
if (orange_table_has_column($pdo, 'products', 'barcode')) {
    $pv2ProdCols .= ', p.barcode';
}
$products = $pdo->query(
    "SELECT $pv2ProdCols FROM products p WHERE p.is_active = 1" . $purchasesProductsCountrySql . ' ORDER BY p.name ASC'
)->fetchAll(PDO::FETCH_ASSOC);

/* ── Variants by product ───────────────────────────────────────────── */
$variantsByProduct = [];
$vRows = $pdo->query(
    'SELECT id, product_id, color, size FROM product_variants ORDER BY product_id ASC, id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($vRows as $vr) {
    $pid = (int) $vr['product_id'];
    if (!isset($variantsByProduct[$pid])) {
        $variantsByProduct[$pid] = [];
    }
    $c = trim((string) ($vr['color'] ?? ''));
    $s = trim((string) ($vr['size'] ?? ''));
    $label = ($c !== '' || $s !== '')
        ? trim($c . ($c !== '' && $s !== '' ? ' / ' : '') . $s)
        : ('#' . (int) $vr['id']);
    $variantsByProduct[$pid][] = [
        'id' => (int) $vr['id'],
        'label' => $label,
    ];
}

/* ── Suppliers (same filter as partner_supplier_payment) ───────────── */
$suppliers = [];
$supplierPayableMap = [];
$pv2SupplierPickRows = [];
if (orange_table_exists($pdo, 'suppliers')) {
    $suppliers = $pdo->query(
        'SELECT id, name, phone FROM suppliers WHERE 1=1' . $purchasesSuppliersCountrySql . ' ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as $s) {
        $sid = (int) $s['id'];
        $aid = orange_supplier_payable_account_id($pdo, $sid);
        $st = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$aid]);
        $arow = $st->fetch(PDO::FETCH_ASSOC);
        $supplierPayableMap[$sid] = [
            'id' => $arow ? (int) $arow['id'] : $aid,
            'code' => $arow ? (string) ($arow['code'] ?? '') : '',
            'name' => $arow ? (string) ($arow['name'] ?? '') : ('#' . $aid),
        ];
    }
}

$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}

$pv2ApParentId = orange_gl_supplier_parent_account_id($pdo);
$pv2ApDescendantSet = [];
if ($pv2ApParentId !== null && $pv2ApParentId > 0 && orange_table_has_column($pdo, 'accounts', 'parent_id')) {
    $pv2ApDescIds = [$pv2ApParentId];
    for ($depth = 0; $depth < 10; ++$depth) {
        $ph = implode(',', array_fill(0, count($pv2ApDescIds), '?'));
        $chSt = $pdo->prepare("SELECT id FROM accounts WHERE parent_id IN ($ph) AND id NOT IN ($ph)");
        $chSt->execute(array_merge($pv2ApDescIds, $pv2ApDescIds));
        $newIds = $chSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($newIds === []) {
            break;
        }
        foreach ($newIds as $nid) {
            $pv2ApDescIds[] = (int) $nid;
        }
    }
    $pv2ApDescendantSet = array_flip($pv2ApDescIds);
}

foreach ($suppliers as $s) {
    $sid = (int) ($s['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $map = $supplierPayableMap[$sid] ?? ['id' => 0, 'code' => '', 'name' => ''];
    $mapAccountId = (int) ($map['id'] ?? 0);
    if ($pv2ApDescendantSet !== [] && ($mapAccountId <= 0 || !isset($pv2ApDescendantSet[$mapAccountId]))) {
        continue;
    }
    $supplierName = trim((string) ($s['name'] ?? ''));
    $supplierPhone = trim((string) ($s['phone'] ?? ''));
    $accountCode = trim((string) ($map['code'] ?? ''));
    $accountName = trim((string) ($map['name'] ?? ''));
    $balance = (float) ($supBal[$sid] ?? 0.0);
    $pv2SupplierPickRows[] = [
        'id' => $sid,
        'name' => $supplierName,
        'phone' => $supplierPhone,
        'balance' => round($balance, 3),
        'account_id' => $mapAccountId,
        'account_code' => $accountCode,
        'account_name' => $accountName,
    ];
}

/* ── GL accounts ───────────────────────────────────────────────────── */
$inventoryAccId = orange_gl_account_id_optional($pdo, 'inventory');
$cashAccId = orange_gl_account_id_optional($pdo, 'cash');
$purchaseDiscountAccId = orange_gl_account_id_optional($pdo, 'purchase_discount');

$pv2GlAccounts = [];
$pv2AcctFilter = orange_accounts_sql_country_filter($pdo, '');
$pv2AcctSql = 'SELECT id, code, name FROM accounts WHERE 1=1';
$pv2AcctParams = [];
if ($pv2AcctFilter !== null) {
    $pv2AcctSql .= $pv2AcctFilter['sql'];
    $pv2AcctParams = $pv2AcctFilter['params'];
}
$pv2AcctSql .= ' ORDER BY code ASC';
if ($pv2AcctParams !== []) {
    $glAccStmt = $pdo->prepare($pv2AcctSql);
    $glAccStmt->execute($pv2AcctParams);
} else {
    $glAccStmt = $pdo->query($pv2AcctSql);
}
if ($glAccStmt) {
    while ($r = $glAccStmt->fetch(PDO::FETCH_ASSOC)) {
        $pv2GlAccounts[(int) $r['id']] = [
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
        ];
    }
}

$prefillSupplierId = 0;
$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);
$prefillStmtKind = trim((string) ($_GET['stmt_party_kind'] ?? ''));
$prefillSupplierDirect = (int) ($_GET['supplier_id'] ?? 0);
if ($prefillStmtKind === 'supplier' && $prefillStmtId > 0) {
    $prefillSupplierId = $prefillStmtId;
} elseif ($prefillSupplierDirect > 0) {
    $prefillSupplierId = $prefillSupplierDirect;
}

$pv2TodayDmy = orange_format_date_dmY(date('Y-m-d'));
$pv2NowDmy = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$nextVoucherNo = 1;
if (orange_journal_vouchers_ready($pdo)) {
    $nextVoucherNo = (int) $pdo->query('SELECT COALESCE(MAX(id),0) + 1 FROM journal_vouchers')->fetchColumn();
}

$pv2Ready = ($inventoryAccId !== null && $inventoryAccId > 0 && $cashAccId !== null && $cashAccId > 0);
$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
?>
<style>
.jv-search-modal {
    position: fixed;
    inset: 0;
    z-index: 10060;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
    direction: rtl;
}
.jv-search-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
}
.jv-search-modal__panel {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: min(96vw, 58rem);
    max-height: calc(100vh - 32px);
    overflow: auto;
    background: #fff;
    border: 1px solid #e4e4e7;
    border-radius: 10px;
    box-shadow: 0 20px 50px rgba(0,0,0,.18);
}
.jv-search-modal__head {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 14px 16px;
    border-bottom: 1px solid #e4e4e7;
}
.jv-search-modal__title { margin: 0; font-size: 1.05rem; text-align: center; }
.jv-search-modal__body { padding: 14px 16px 18px; }
.jv-search-modal__form {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 12px;
}
.jv-search-modal__row--fields {
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    align-items: flex-end;
    gap: 10px;
    width: 100%;
    overflow-x: auto;
    box-sizing: border-box;
    padding-bottom: 2px;
}
.jv-search-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.jv-search-field label {
    font-size: 0.78rem;
    font-weight: 600;
    white-space: nowrap;
}
.jv-search-field input { width: 100%; box-sizing: border-box; }
.jv-search-field--id { flex: 0 0 7rem; }
.jv-search-field--date { flex: 0 0 11rem; }
.jv-search-field--ref { flex: 1 1 0; min-width: 7rem; }
.jv-search-field--full { width: 100%; }
.jv-search-modal__row--desc { width: 100%; }
.jv-search-modal__actions { margin: 0 0 16px; }
.jv-search-table-wrap { max-height: min(40vh, 22rem); overflow: auto; border: 1px solid #e4e4e7; border-radius: 8px; }
.jv-search-results-table { margin: 0; font-size: 0.9rem; }
.jv-search-results-table tbody tr { cursor: pointer; }
.jv-search-results-table tbody tr:hover { background: #f4f4f5; }
</style>

<div class="page-title page-title--stacked jv-print-hide">
    <div><h1>فاتورة شراء</h1></div>
</div>

<?php if (!$pv2Ready): ?>
<div class="card jv-print-hide" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">اربط حساب <strong>المخزون</strong> و<strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
</div>
<?php endif; ?>

<div class="card jv-print-area">
    <h3 class="card-title">فاتورة شراء</h3>

    <!-- ١ — المورد -->
    <div class="form-grid" style="margin-bottom:16px;">
        <div style="grid-column:1/-1;">
            <label for="pv2_supplier_code">المورد</label>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:10px 14px;">
                <input type="text" id="pv2_supplier_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
                <input type="text" id="pv2_supplier_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
            </div>
            <input type="hidden" id="pv2_supplier_id" value="0">
        </div>
        <div>
            <label for="pv2_type">نوع الشراء</label>
            <select id="pv2_type"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
                <option value="cash">نقدي</option>
                <option value="credit">آجل</option>
            </select>
        </div>
        <div style="grid-column:1/-1;">
            <label for="pv2_notes">ملاحظات</label>
            <input type="text" id="pv2_notes" placeholder="رقم فاتورة المورد، شروط، …"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
    </div>

    <!-- ٢ — أسطر الأصناف -->
    <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:0 0 10px;">أسطر الأصناف</h4>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table pur-lines-table">
                <thead>
                    <tr>
                        <th class="pur-col-idx" style="width:2.5rem;">#</th>
                        <th style="min-width:8rem;">كود-باركود</th>
                        <th style="min-width:10rem;">الصنف</th>
                        <th style="min-width:8rem;">المتغير (لون/مقاس)</th>
                        <th style="width:5rem;">الكمية</th>
                        <th style="width:6rem;">تكلفة الوحدة</th>
                        <th style="width:6rem;">خصم</th>
                        <th style="width:7rem;">إجمالي السطر</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر" style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody id="pv2_lines_body"></tbody>
            </table>
        </div>
    </div>

    <!-- ٣ — خصم الفاتورة + المجاميع -->
    <div style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 24px;">
        <div style="flex:0 0 auto;">
            <label for="pv2_invoice_discount" style="font-size:0.82rem;font-weight:600;">خصم الفاتورة</label>
            <input type="text" id="pv2_invoice_discount" placeholder="0 أو 5%" dir="ltr" lang="en" style="width:8rem;" autocomplete="off"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
        <div style="flex:1 1 auto;text-align:left;direction:ltr;font-size:0.95rem;line-height:1.8;">
            <span style="color:#64748b;">إجمالي البنود:</span> <strong id="pv2_subtotal" dir="ltr" lang="en">0.000</strong><br>
            <span style="color:#64748b;">صافي الفاتورة:</span> <strong id="pv2_net_total" dir="ltr" lang="en" style="color:#059669;">0.000</strong>
        </div>
    </div>

    <!-- ٤ — القيد المحاسبي -->
    <div style="margin-top:20px;padding-top:14px;border-top:2px solid #e2e8f0;">
        <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:0 0 10px;">القيد المحاسبي</h4>

        <div class="jv-voucher-header-line jv-voucher-header-line--nav" style="margin-bottom:12px;">
            <div>
                <label for="pv2_number_preview">رقم القيد</label>
                <input type="text" id="pv2_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;" value="<?php echo (int) $nextVoucherNo; ?>">
            </div>
            <div>
                <label for="pv2_date">تاريخ السند</label>
                <input type="text" id="pv2_date" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($pv2TodayDmy, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
            </div>
            <div>
                <label for="pv2_ref">المرجع</label>
                <input type="text" id="pv2_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" value="—" title="يُخصَّص تلقائياً عند الحفظ">
            </div>
            <div>
                <label for="pv2_document_entered">تاريخ المستند</label>
                <input type="text" id="pv2_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" value="<?php echo htmlspecialchars($pv2NowDmy, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="pv2_tot_debit">مجموع المدين</label>
                <input type="text" id="pv2_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000" dir="ltr" lang="en">
            </div>
            <div>
                <label for="pv2_tot_credit">مجموع الدائن</label>
                <input type="text" id="pv2_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000" dir="ltr" lang="en">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين السندات">
                    <button type="button" class="btn-secondary jv-nav-btn" id="pv2_nav_first" title="أول سند" aria-label="أول سند">&lt;&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="pv2_nav_prev" title="السند السابق" aria-label="السند السابق">&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="pv2_nav_next" title="السند التالي" aria-label="السند التالي">&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="pv2_nav_last" title="آخر سند" aria-label="آخر سند">&gt;&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="pv2_btn_search" title="بحث عن سند">بحث</button>
                </div>
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label for="pv2_desc">البيان</label>
            <input type="text" id="pv2_desc" placeholder="بيان القيد المحاسبي" value=""<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>

        <div class="admin-doc-frame">
            <div class="table-wrap">
                <table class="admin-table admin-doc-lines-table jv-lines-table">
                    <colgroup>
                        <col class="jv-col-code">
                        <col class="jv-col-name">
                        <col class="jv-col-amt">
                        <col class="jv-col-amt">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>كود الحساب</th>
                            <th>اسم الحساب</th>
                            <th>مدين</th>
                            <th>دائن</th>
                        </tr>
                    </thead>
                    <tbody id="pv2_jv_body"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ٥ — أزرار -->
    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:16px;">
        <span></span>
        <div class="jv-toolbar-primary-group">
            <button type="button" id="pv2_btn_new" title="إدخال سند جديد">سند جديد</button>
            <button type="button" class="btn-secondary" id="pv2_btn_delete" title="حذف السند المعروض" disabled>حذف</button>
            <button type="button" class="btn-secondary" id="pv2_btn_print" title="طباعة السند">طباعة</button>
            <button type="button" id="pv2_btn_save"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>حفظ</button>
        </div>
    </div>
</div>

<!-- Supplier Picker Modal -->
<div class="gl-pick-modal jv-print-hide" id="pv2_supplier_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="pv2_supplier_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="pv2_supplier_pick_title">
        <h3 id="pv2_supplier_pick_title" class="gl-pick-modal__title">اختيار المورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="pv2_supplier_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بكود الحساب أو اسم المورد…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="pv2_supplier_pick_list"></ul>
        <button type="button" class="btn-secondary" id="pv2_supplier_pick_close">إغلاق</button>
    </div>
</div>

<!-- Search Modal -->
<div id="pv2_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="pv2_search_modal_title">
    <div class="jv-search-modal__backdrop" id="pv2_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="pv2_search_modal_title" class="jv-search-modal__title">بحث في فواتير الشراء</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="pv2_search_id_from">رقم القيد — من</label>
                        <input type="number" id="pv2_search_id_from" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="pv2_search_id_to">رقم القيد — إلى</label>
                        <input type="number" id="pv2_search_id_to" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="pv2_search_date_from">تاريخ السند — من</label>
                        <input type="text" id="pv2_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="pv2_search_date_to">تاريخ السند — إلى</label>
                        <input type="text" id="pv2_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="pv2_search_ref">المرجع (يحتوي النص)</label>
                        <input type="text" id="pv2_search_ref" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
                <div class="jv-search-modal__row jv-search-modal__row--desc">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="pv2_search_desc">بيان القيد العام (يحتوي النص)</label>
                        <input type="text" id="pv2_search_desc" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="pv2_search_btn">تنفيذ البحث</button>
            </div>
            <div class="jv-search-modal__results">
                <div class="table-wrap jv-search-table-wrap">
                    <table class="admin-table jv-search-results-table">
                        <thead>
                            <tr>
                                <th>رقم</th>
                                <th>تاريخ السند</th>
                                <th>المرجع</th>
                                <th>البيان</th>
                                <th>مبلغ القيد</th>
                            </tr>
                        </thead>
                        <tbody id="pv2_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var PV2_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_VARIANTS = <?php echo json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_SUPPLIER_PICK_ROWS = <?php echo json_encode($pv2SupplierPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_PREFILL_SUPPLIER = <?php echo (int) $prefillSupplierId; ?>;
    var PV2_READY = <?php echo $pv2Ready ? 'true' : 'false'; ?>;
    var PV2_GL_ACCOUNTS = <?php echo json_encode($pv2GlAccounts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_INV_ACC = <?php echo (int) ($inventoryAccId ?? 0); ?>;
    var PV2_CASH_ACC = <?php echo (int) ($cashAccId ?? 0); ?>;
    var PV2_DISC_ACC = <?php echo (int) ($purchaseDiscountAccId ?? 0); ?>;

    var currentSupplierId = 0;
    var browseId = null;

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function accInfo(id) { var a = PV2_GL_ACCOUNTS[String(id)]; return a || { code: '', name: '#' + id }; }
    function fmt3(n) { return (parseFloat(n) || 0).toFixed(3); }

    /* ── Product lookup by code/barcode ─────────────────────────────── */
    function findProductByCode(code) {
        code = String(code || '').trim();
        if (!code) return null;
        var lower = code.toLowerCase();
        for (var i = 0; i < PV2_PRODUCTS.length; i++) {
            var p = PV2_PRODUCTS[i];
            var ic = String(p.item_code || '').trim().toLowerCase();
            var bc = String(p.barcode || '').trim().toLowerCase();
            if ((ic && ic === lower) || (bc && bc === lower)) return p;
        }
        return null;
    }

    /* ── Discount parsing ───────────────────────────────────────────── */
    function parseDiscount(raw, lineAmount) {
        raw = String(raw || '').trim();
        if (!raw || raw === '0') return 0;
        if (raw.endsWith('%')) {
            var pct = parseFloat(raw.slice(0, -1)) || 0;
            return Math.round(lineAmount * pct / 100 * 10000) / 10000;
        }
        return parseFloat(raw) || 0;
    }

    /* ── Supplier picker ────────────────────────────────────────────── */
    function supplierById(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        for (var i = 0; i < PV2_SUPPLIER_PICK_ROWS.length; i++) {
            if ((parseInt(String(PV2_SUPPLIER_PICK_ROWS[i].id || '0'), 10) || 0) === id) return PV2_SUPPLIER_PICK_ROWS[i];
        }
        return null;
    }

    function selectSupplier(id) {
        var row = supplierById(id);
        var codeEl = document.getElementById('pv2_supplier_code');
        var nameEl = document.getElementById('pv2_supplier_name');
        var idEl = document.getElementById('pv2_supplier_id');
        if (!row) {
            currentSupplierId = 0;
            if (codeEl) codeEl.value = '';
            if (nameEl) nameEl.value = '';
            if (idEl) idEl.value = '0';
        } else {
            currentSupplierId = parseInt(String(row.id), 10) || 0;
            if (codeEl) codeEl.value = row.account_code || '';
            if (nameEl) nameEl.value = row.name || '';
            if (idEl) idEl.value = String(currentSupplierId);
        }
        rebuildJournal();
    }

    function pickerOpen() {
        var modal = document.getElementById('pv2_supplier_pick_modal');
        var qEl = document.getElementById('pv2_supplier_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        pickerRender('');
        qEl.focus();
    }
    function pickerClose() {
        var modal = document.getElementById('pv2_supplier_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function pickerRender(q) {
        var listEl = document.getElementById('pv2_supplier_pick_list');
        if (!listEl) return;
        var query = String(q || '').trim().toLowerCase();
        var rows = PV2_SUPPLIER_PICK_ROWS.filter(function (r) {
            if (!query) return true;
            var hay = (r.account_code + ' ' + r.account_name + ' ' + r.name + ' ' + r.phone).toLowerCase();
            return hay.indexOf(query) !== -1;
        });
        listEl.innerHTML = '';
        if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
        rows.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.textContent = (r.account_code ? r.account_code + ' — ' : '') + r.name + (r.phone ? ' (' + r.phone + ')' : '') + ' [رصيد ' + r.balance.toFixed(3) + ']';
            li.addEventListener('dblclick', function () { selectSupplier(r.id); pickerClose(); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { selectSupplier(r.id); pickerClose(); } });
            listEl.appendChild(li);
        });
    }

    /* ── Dynamic rows ───────────────────────────────────────────────── */
    function variantOptionsHtml(pid) {
        var list = (PV2_VARIANTS && PV2_VARIANTS[String(pid)]) ? PV2_VARIANTS[String(pid)] : [];
        if (!list.length) return '<option value="0">— لا متغيرات —</option>';
        return list.map(function (v) {
            return '<option value="' + v.id + '">' + esc(v.label) + '</option>';
        }).join('');
    }

    function productOptionsHtml() {
        var blank = '<option value="">' + esc('— اختر صنفاً —') + '</option>';
        return blank + PV2_PRODUCTS.map(function (p) {
            return '<option value="' + p.id + '" data-cost="' + (parseFloat(p.cost) || 0) + '">' + esc(p.name) + '</option>';
        }).join('');
    }

    function addLine() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'pv2-line';
        tr.innerHTML =
            '<td class="pur-col-idx"></td>' +
            '<td><input type="text" class="pv2-barcode admin-inp" placeholder="كود/باركود" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><select class="pv2-product" style="min-width:10rem;">' + productOptionsHtml() + '</select></td>' +
            '<td><select class="pv2-variant"></select></td>' +
            '<td><input type="number" class="pv2-qty admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
            '<td><input type="number" class="pv2-cost admin-inp-money" min="0" step="any" value="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
            '<td><input type="text" class="pv2-discount admin-inp" placeholder="0" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><input type="text" class="pv2-line-total admin-inp-money" value="0.000" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
            '<td><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
        tb.appendChild(tr);
        renumberRows();
        var sel = tr.querySelector('.pv2-product');
        if (sel) updateVariantCell(sel);
        recalcAll();
    }

    function removeLine(btn) {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        if (tb.querySelectorAll('tr').length <= 1) {
            var tr = btn.closest('tr');
            tr.querySelector('.pv2-barcode').value = '';
            tr.querySelector('.pv2-product').value = '';
            tr.querySelector('.pv2-qty').value = '1';
            tr.querySelector('.pv2-cost').value = '0.000';
            tr.querySelector('.pv2-discount').value = '';
            updateVariantCell(tr.querySelector('.pv2-product'));
            syncTrailing();
            recalcAll();
            return;
        }
        btn.closest('tr').remove();
        renumberRows();
        syncTrailing();
        recalcAll();
    }

    function renumberRows() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        }
    }

    function rowIsBlank(tr) {
        var pid = parseInt(tr.querySelector('.pv2-product').value, 10) || 0;
        var q = parseInt(tr.querySelector('.pv2-qty').value, 10) || 0;
        var code = (tr.querySelector('.pv2-barcode').value || '').trim();
        return pid <= 0 && q <= 0 && !code;
    }

    function trimExtraTrailing() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        for (;;) {
            var rows = tb.querySelectorAll('tr');
            if (rows.length < 2) return;
            var a = rows[rows.length - 2];
            var b = rows[rows.length - 1];
            if (rowIsBlank(a) && rowIsBlank(b)) {
                a.remove();
                renumberRows();
            } else {
                return;
            }
        }
    }

    function syncTrailing() {
        trimExtraTrailing();
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        if (rows.length === 0) { addLine(); return; }
        var last = rows[rows.length - 1];
        if (!rowIsBlank(last)) addLine();
    }

    function updateVariantCell(sel) {
        var row = sel.closest('tr');
        var pid = parseInt(sel.value, 10) || 0;
        var vsel = row.querySelector('.pv2-variant');
        if (vsel) vsel.innerHTML = variantOptionsHtml(pid);
    }

    function onProductChanged(sel) {
        var row = sel.closest('tr');
        var pid = parseInt(sel.value, 10) || 0;
        var opt = sel.options[sel.selectedIndex];
        var cost = opt ? parseFloat(opt.getAttribute('data-cost') || '0') : 0;
        var costInp = row.querySelector('.pv2-cost');
        if (costInp && pid > 0) costInp.value = fmt3(cost);
        updateVariantCell(sel);
        var barcodeInp = row.querySelector('.pv2-barcode');
        if (barcodeInp && pid > 0) {
            var prod = null;
            for (var i = 0; i < PV2_PRODUCTS.length; i++) {
                if (PV2_PRODUCTS[i].id === pid || String(PV2_PRODUCTS[i].id) === String(pid)) { prod = PV2_PRODUCTS[i]; break; }
            }
            if (prod) {
                barcodeInp.value = prod.item_code || prod.barcode || '';
            }
        }
        recalcAll();
    }

    function onBarcodeBlurOrEnter(inp) {
        var code = (inp.value || '').trim();
        if (!code) return;
        var prod = findProductByCode(code);
        if (!prod) return;
        var row = inp.closest('tr');
        var sel = row.querySelector('.pv2-product');
        if (sel) {
            sel.value = String(prod.id);
            onProductChanged(sel);
        }
        syncTrailing();
    }

    /* ── Recalculate ────────────────────────────────────────────────── */
    function recalcAll() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr.pv2-line');
        var subtotal = 0;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var q = parseInt(r.querySelector('.pv2-qty').value, 10) || 0;
            var c = parseFloat(r.querySelector('.pv2-cost').value) || 0;
            var lineGross = q * c;
            var discRaw = (r.querySelector('.pv2-discount').value || '').trim();
            var discAmt = parseDiscount(discRaw, lineGross);
            var lineNet = Math.max(0, lineGross - discAmt);
            var ltEl = r.querySelector('.pv2-line-total');
            if (ltEl) ltEl.value = fmt3(lineNet);
            subtotal += lineNet;
        }
        var invDiscRaw = (document.getElementById('pv2_invoice_discount').value || '').trim();
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);
        var netTotal = Math.max(0, subtotal - invDiscAmt);
        var stEl = document.getElementById('pv2_subtotal');
        var ntEl = document.getElementById('pv2_net_total');
        if (stEl) stEl.textContent = fmt3(subtotal);
        if (ntEl) ntEl.textContent = fmt3(netTotal);
        rebuildJournal();
    }

    /* ── Journal entry (auto-built, readonly) ───────────────────────── */
    function rebuildJournal() {
        var tb = document.getElementById('pv2_jv_body');
        if (!tb || !PV2_READY) return;
        tb.innerHTML = '';

        var subtotal = parseFloat(document.getElementById('pv2_subtotal').textContent) || 0;
        var netTotal = parseFloat(document.getElementById('pv2_net_total').textContent) || 0;
        var invDiscAmt = subtotal - netTotal;
        var purType = document.getElementById('pv2_type').value;

        if (netTotal <= 0) {
            var dEl = document.getElementById('pv2_tot_debit');
            var cEl = document.getElementById('pv2_tot_credit');
            if (dEl) dEl.value = '0.000';
            if (cEl) cEl.value = '0.000';
            return;
        }

        var invAcc = accInfo(PV2_INV_ACC);
        var cashAcc = accInfo(PV2_CASH_ACC);
        var discAcc = PV2_DISC_ACC > 0 ? accInfo(PV2_DISC_ACC) : null;

        var lines = [];

        if (purType === 'credit') {
            var map = PV2_SUPPLIER_PAYABLE[String(currentSupplierId)] || { id: 0, code: '', name: '' };
            var apAcc = map.id > 0 ? accInfo(map.id) : { code: '?', name: 'ذمة مورد' };
            lines.push({ code: invAcc.code, name: invAcc.name, debit: subtotal, credit: 0 });
            if (invDiscAmt > 0.0005 && discAcc) {
                lines.push({ code: discAcc.code, name: discAcc.name, debit: 0, credit: invDiscAmt });
            }
            lines.push({ code: apAcc.code, name: apAcc.name, debit: 0, credit: netTotal - (invDiscAmt > 0.0005 && discAcc ? 0 : 0) });
            if (invDiscAmt > 0.0005 && discAcc) {
                lines[lines.length - 1].credit = netTotal;
            } else if (invDiscAmt > 0.0005 && !discAcc) {
                lines[0].debit = netTotal;
            }
        } else if (currentSupplierId > 0) {
            var map2 = PV2_SUPPLIER_PAYABLE[String(currentSupplierId)] || { id: 0, code: '', name: '' };
            var apAcc2 = map2.id > 0 ? accInfo(map2.id) : { code: '?', name: 'ذمة مورد' };
            lines.push({ code: invAcc.code, name: invAcc.name + ' — شراء نقدي', debit: subtotal, credit: 0 });
            if (invDiscAmt > 0.0005 && discAcc) {
                lines.push({ code: discAcc.code, name: discAcc.name, debit: 0, credit: invDiscAmt });
            }
            lines.push({ code: apAcc2.code, name: apAcc2.name + ' — تسجيل فاتورة', debit: 0, credit: invDiscAmt > 0.0005 && discAcc ? netTotal : subtotal });
            lines.push({ code: apAcc2.code, name: apAcc2.name + ' — سداد', debit: invDiscAmt > 0.0005 && discAcc ? netTotal : subtotal, credit: 0 });
            lines.push({ code: cashAcc.code, name: cashAcc.name, debit: 0, credit: invDiscAmt > 0.0005 && discAcc ? netTotal : subtotal });
            if (!discAcc && invDiscAmt > 0.0005) {
                lines[0].debit = netTotal;
                lines[2].credit = netTotal;
                lines[3].debit = netTotal;
                lines[4].credit = netTotal;
            }
        } else {
            lines.push({ code: invAcc.code, name: invAcc.name, debit: subtotal, credit: 0 });
            if (invDiscAmt > 0.0005 && discAcc) {
                lines.push({ code: discAcc.code, name: discAcc.name, debit: 0, credit: invDiscAmt });
            }
            lines.push({ code: cashAcc.code, name: cashAcc.name, debit: 0, credit: invDiscAmt > 0.0005 && discAcc ? netTotal : subtotal });
            if (!discAcc && invDiscAmt > 0.0005) {
                lines[0].debit = netTotal;
                lines[lines.length - 1].credit = netTotal;
            }
        }

        var totD = 0, totC = 0;
        lines.forEach(function (l) {
            totD += l.debit;
            totC += l.credit;
            var tr = document.createElement('tr');
            tr.className = 'jv-line-main';
            tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(l.code) + '" readonly tabindex="-1"></td>' +
                '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(l.name) + '" readonly tabindex="-1"></td>' +
                '<td><input type="text" class="admin-inp-money" value="' + (l.debit > 0 ? fmt3(l.debit) : '0.000') + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
                '<td><input type="text" class="admin-inp-money" value="' + (l.credit > 0 ? fmt3(l.credit) : '0.000') + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>';
            tb.appendChild(tr);
        });

        var dEl2 = document.getElementById('pv2_tot_debit');
        var cEl2 = document.getElementById('pv2_tot_credit');
        if (dEl2) dEl2.value = fmt3(totD);
        if (cEl2) cEl2.value = fmt3(totC);
    }

    /* ── Save ───────────────────────────────────────────────────────── */
    function save() {
        if (!PV2_READY) return;
        var supplierId = parseInt(document.getElementById('pv2_supplier_id').value, 10) || 0;
        var purType = document.getElementById('pv2_type').value;
        var notes = (document.getElementById('pv2_notes').value || '').trim();

        if (purType === 'credit' && supplierId <= 0) {
            alert('شراء آجل يتطلّب مورداً.');
            return;
        }

        var tb = document.getElementById('pv2_lines_body');
        if (!tb) { alert('لا توجد أصناف'); return; }
        var rows = tb.querySelectorAll('tr.pv2-line');
        var items = [];
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var pid = parseInt(r.querySelector('.pv2-product').value, 10) || 0;
            var vid = parseInt(r.querySelector('.pv2-variant').value, 10) || 0;
            var q = parseInt(r.querySelector('.pv2-qty').value, 10) || 0;
            var c = parseFloat(r.querySelector('.pv2-cost').value) || 0;
            var discRaw = (r.querySelector('.pv2-discount').value || '').trim();
            if (!pid || q < 1) continue;
            var lineGross = q * c;
            var discAmt = parseDiscount(discRaw, lineGross);
            items.push({ product_id: pid, variant_id: vid, qty: q, cost: c, discount_raw: discRaw, discount_amount: discAmt });
        }
        if (!items.length) {
            alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة');
            return;
        }

        var invDiscRaw = (document.getElementById('pv2_invoice_discount').value || '').trim();
        var subtotal = parseFloat(document.getElementById('pv2_subtotal').textContent) || 0;
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);

        var payload = {
            supplier_id: supplierId,
            type: purType,
            notes: notes,
            items: items,
            invoice_discount_raw: invDiscRaw,
            invoice_discount_amount: invDiscAmt
        };

        postJSON('/admin/api/purchases/create.php', payload).then(function (res) {
            if (res.success) {
                alert(res.message || 'تم حفظ فاتورة الشراء');
                location.reload();
                return;
            }
            if (typeof orangeAdminOfferSuggestOnFailure === 'function' && orangeAdminOfferSuggestOnFailure(res, 'فشل')) return;
            alert(res.message || 'فشل');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    /* ── Navigation ─────────────────────────────────────────────────── */
    function nav(where) {
        var payload = {
            action: 'nav_manual',
            entry_type: 'purchase',
            where: where,
            current_id: browseId || 0
        };
        postJSON('/admin/api/journal/manage.php', payload).then(function (r) {
            if (!r.success || !r.id) {
                alert(r.message || 'لا توجد سندات من هذا النوع بعد');
                return;
            }
            loadVoucher(r.id);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function loadVoucher(id) {
        postJSON('/admin/api/journal/manage.php', { action: 'get', id: id, entry_type: 'purchase' }).then(function (r) {
            if (!r.success || !r.voucher) {
                alert(r.message || 'تعذر تحميل السند');
                return;
            }
            browseId = r.voucher.id;
            displayVoucher(r);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function displayVoucher(r) {
        var v = r.voucher;
        document.getElementById('pv2_number_preview').value = String(v.voucher_serial || v.id || '');
        document.getElementById('pv2_ref').value = v.reference || '';
        document.getElementById('pv2_date').value = v.voucher_date_dmy || v.voucher_date || '';
        document.getElementById('pv2_desc').value = v.description || '';
        var total = 0;
        (r.lines || []).forEach(function (l) { total += parseFloat(String(l.debit || '0')) || 0; });
        document.getElementById('pv2_tot_debit').value = fmt3(total);
        document.getElementById('pv2_tot_credit').value = fmt3(total);
        document.getElementById('pv2_btn_delete').disabled = false;

        if (r.party_supplier_id) {
            selectSupplier(parseInt(String(r.party_supplier_id), 10) || 0);
        }

        var tb = document.getElementById('pv2_jv_body');
        if (tb && r.lines) {
            tb.innerHTML = '';
            r.lines.forEach(function (l) {
                var tr = document.createElement('tr');
                tr.className = 'jv-line-main';
                var accId = parseInt(String(l.account_id || '0'), 10) || 0;
                var ai = (r.accounts_by_id && r.accounts_by_id[String(accId)]) ? r.accounts_by_id[String(accId)] : { code: '', name: '' };
                var d = parseFloat(String(l.debit || '0')) || 0;
                var c = parseFloat(String(l.credit || '0')) || 0;
                tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(ai.code || '') + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc((ai.name || '') + (l.memo ? ' — ' + l.memo : '')) + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="admin-inp-money" value="' + (d > 0 ? fmt3(d) : '0.000') + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
                    '<td><input type="text" class="admin-inp-money" value="' + (c > 0 ? fmt3(c) : '0.000') + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>';
                tb.appendChild(tr);
            });
        }
    }

    function deleteVoucher() {
        if (!browseId) {
            alert('لا يوجد سند محفوظ للحذف');
            return;
        }
        if (!confirm('تأكيد حذف هذا السند؟ لا يمكن التراجع.')) return;
        postJSON('/admin/api/journal/manage.php', { action: 'delete', id: browseId }).then(function (r) {
            if (r.success) {
                alert(r.message || 'تم الحذف');
                location.reload();
                return;
            }
            alert(r.message || 'فشل الحذف');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    /* ── Search modal ───────────────────────────────────────────────── */
    function searchOpen() {
        var m = document.getElementById('pv2_search_modal');
        if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
    }
    function searchClose() {
        var m = document.getElementById('pv2_search_modal');
        if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
    }
    function searchRun() {
        var idFrom = parseInt(document.getElementById('pv2_search_id_from').value) || 0;
        var idTo = parseInt(document.getElementById('pv2_search_id_to').value) || 0;
        var dateFrom = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('pv2_search_date_from')) || '' : '';
        var dateTo = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('pv2_search_date_to')) || '' : '';
        var ref = (document.getElementById('pv2_search_ref').value || '').trim();
        var desc = (document.getElementById('pv2_search_desc').value || '').trim();
        var tbody = document.getElementById('pv2_search_results');
        tbody.innerHTML = '<tr><td colspan="5">جاري البحث…</td></tr>';
        var payload = {
            action: 'search',
            entry_type: 'purchase'
        };
        if (idFrom > 0) payload.id_from = idFrom;
        if (idTo > 0) payload.id_to = idTo;
        if (dateFrom) payload.date_from = dateFrom;
        if (dateTo) payload.date_to = dateTo;
        if (ref) payload.reference = ref;
        if (desc) payload.description = desc;
        postJSON('/admin/api/journal/manage.php', payload).then(function (r) {
            tbody.innerHTML = '';
            if (!r.success || !r.results || !r.results.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="muted">لا نتائج</td></tr>';
                return;
            }
            r.results.forEach(function (v) {
                var tr = document.createElement('tr');
                tr.style.cursor = 'pointer';
                tr.innerHTML = '<td>' + esc(String(v.voucher_serial || v.id)) + '</td><td>' + esc(v.voucher_date_dmy || v.voucher_date || '') + '</td><td>' + esc(v.reference || '') + '</td><td>' + esc(v.description || '') + '</td><td dir="ltr">' + fmt3(v.total || 0) + '</td>';
                tr.addEventListener('dblclick', function () { loadVoucher(v.id); searchClose(); });
                tbody.appendChild(tr);
            });
        }).catch(function (e) {
            tbody.innerHTML = '<tr><td colspan="5">' + esc(e.message || String(e)) + '</td></tr>';
        });
    }

    /* ── Init & bindings ────────────────────────────────────────────── */
    function init() {
        var codeEl = document.getElementById('pv2_supplier_code');
        if (codeEl) {
            codeEl.addEventListener('dblclick', function (e) { e.preventDefault(); pickerOpen(); });
            codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickerOpen(); } });
        }
        document.getElementById('pv2_supplier_pick_backdrop').addEventListener('click', pickerClose);
        document.getElementById('pv2_supplier_pick_close').addEventListener('click', pickerClose);
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') pickerClose(); });

        var pickQ = document.getElementById('pv2_supplier_pick_q');
        var pickTimer = null;
        if (pickQ) {
            pickQ.addEventListener('input', function () {
                if (pickTimer) clearTimeout(pickTimer);
                pickTimer = setTimeout(function () { pickerRender(pickQ.value || ''); }, 180);
            });
        }

        document.getElementById('pv2_type').addEventListener('change', function () { rebuildJournal(); });
        document.getElementById('pv2_invoice_discount').addEventListener('input', function () { recalcAll(); });

        document.getElementById('pv2_btn_save').addEventListener('click', save);
        document.getElementById('pv2_btn_new').addEventListener('click', function () { location.reload(); });
        document.getElementById('pv2_btn_print').addEventListener('click', function () { window.print(); });
        document.getElementById('pv2_btn_delete').addEventListener('click', deleteVoucher);

        document.getElementById('pv2_nav_first').addEventListener('click', function () { nav('first'); });
        document.getElementById('pv2_nav_prev').addEventListener('click', function () { nav('prev'); });
        document.getElementById('pv2_nav_next').addEventListener('click', function () { nav('next'); });
        document.getElementById('pv2_nav_last').addEventListener('click', function () { nav('last'); });
        document.getElementById('pv2_btn_search').addEventListener('click', searchOpen);

        document.getElementById('pv2_search_btn').addEventListener('click', searchRun);
        document.getElementById('pv2_search_modal_backdrop').addEventListener('click', searchClose);

        document.addEventListener('mousedown', function (ev) {
            var m = document.getElementById('pv2_search_modal');
            if (!m || m.style.display !== 'flex') return;
            var panel = m.querySelector('.jv-search-modal__panel');
            if (panel && (panel === ev.target || panel.contains(ev.target))) return;
            if (ev.target.closest && ev.target.closest('#pv2_btn_search')) return;
            searchClose();
        }, true);

        var tb = document.getElementById('pv2_lines_body');
        if (tb) {
            tb.addEventListener('change', function (e) {
                if (e.target && e.target.classList.contains('pv2-product')) {
                    onProductChanged(e.target);
                }
                syncTrailing();
                recalcAll();
            });
            tb.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('pv2-barcode')) return;
                syncTrailing();
                recalcAll();
            });
            tb.addEventListener('keydown', function (e) {
                if (e.target && e.target.classList.contains('pv2-barcode') && e.key === 'Enter') {
                    e.preventDefault();
                    onBarcodeBlurOrEnter(e.target);
                    return;
                }
                if (e.key !== 'Tab' || e.shiftKey) return;
                var ta = e.target;
                if (!ta || !ta.classList.contains('pv2-discount')) return;
                var tr = ta.closest('tr');
                if (!tr || tr.parentElement !== tb) return;
                var rows = tb.querySelectorAll('tr');
                if (tr !== rows[rows.length - 1]) return;
                e.preventDefault();
                syncTrailing();
                var rows2 = tb.querySelectorAll('tr');
                var next = rows2[rows2.length - 1];
                var bc = next && next.querySelector('.pv2-barcode');
                if (bc) bc.focus();
            });
            tb.addEventListener('focusout', function (e) {
                if (e.target && e.target.classList.contains('pv2-barcode')) {
                    onBarcodeBlurOrEnter(e.target);
                }
            });
            tb.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('admin-doc-line-remove')) {
                    removeLine(e.target);
                }
            });

            addLine();
            syncTrailing();
        }

        if (PV2_PREFILL_SUPPLIER > 0) {
            selectSupplier(PV2_PREFILL_SUPPLIER);
        } else {
            rebuildJournal();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
