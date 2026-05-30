<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();

$prCountryId = orange_admin_context_country_id($pdo);
$prDefaultCurrency = orange_admin_context_currency_code($pdo);
$prProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $prCountryId);
$prSuppliersCountrySql = orange_sql_country_and_fragment($pdo, 'suppliers', 'suppliers', $prCountryId);

/* ── Products (with item_code / barcode when available) ────────────── */
$pr2ProdCols = 'p.id, p.name, p.cost, p.has_colors, p.has_sizes';
if (orange_table_has_column($pdo, 'products', 'item_code')) {
    $pr2ProdCols .= ', p.item_code';
}
if (orange_table_has_column($pdo, 'products', 'barcode')) {
    $pr2ProdCols .= ', p.barcode';
}
$products = $pdo->query(
    "SELECT $pr2ProdCols FROM products p WHERE p.is_active = 1" . $prProductsCountrySql . ' ORDER BY p.name ASC'
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
$pr2SupplierPickRows = [];
if (orange_table_exists($pdo, 'suppliers')) {
    $suppliers = $pdo->query(
        'SELECT id, name, phone FROM suppliers WHERE 1=1' . $prSuppliersCountrySql . ' ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as $s) {
        $sid = (int) $s['id'];
        // س13: لا يجوز أن يكسر مورد واحد (بإعداد accounts_payable مفقود/غير ورقة) كل الشاشة.
        $aid = 0;
        try {
            $aid = orange_supplier_payable_account_id($pdo, $sid);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] purchase_returns supplier payable resolve #' . $sid . ': ' . $e->getMessage());
            }
            $aid = 0;
        }
        if ($aid > 0) {
            $st = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
            $st->execute([$aid]);
            $arow = $st->fetch(PDO::FETCH_ASSOC);
            $supplierPayableMap[$sid] = [
                'id' => $arow ? (int) $arow['id'] : $aid,
                'code' => $arow ? (string) ($arow['code'] ?? '') : '',
                'name' => $arow ? (string) ($arow['name'] ?? '') : ('#' . $aid),
            ];
        } else {
            $supplierPayableMap[$sid] = ['id' => 0, 'code' => '', 'name' => ''];
        }
    }
}

$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}

$pr2ApParentId = orange_gl_supplier_parent_account_id($pdo);
$pr2ApDescendantSet = [];
if ($pr2ApParentId !== null && $pr2ApParentId > 0 && orange_table_has_column($pdo, 'accounts', 'parent_id')) {
    $pr2ApDescIds = [$pr2ApParentId];
    for ($depth = 0; $depth < 10; ++$depth) {
        $ph = implode(',', array_fill(0, count($pr2ApDescIds), '?'));
        $chSt = $pdo->prepare("SELECT id FROM accounts WHERE parent_id IN ($ph) AND id NOT IN ($ph)");
        $chSt->execute(array_merge($pr2ApDescIds, $pr2ApDescIds));
        $newIds = $chSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($newIds === []) {
            break;
        }
        foreach ($newIds as $nid) {
            $pr2ApDescIds[] = (int) $nid;
        }
    }
    $pr2ApDescendantSet = array_flip($pr2ApDescIds);
}

foreach ($suppliers as $s) {
    $sid = (int) ($s['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $map = $supplierPayableMap[$sid] ?? ['id' => 0, 'code' => '', 'name' => ''];
    $mapAccountId = (int) ($map['id'] ?? 0);
    if ($pr2ApDescendantSet !== [] && ($mapAccountId <= 0 || !isset($pr2ApDescendantSet[$mapAccountId]))) {
        continue;
    }
    $supplierName = trim((string) ($s['name'] ?? ''));
    $supplierPhone = trim((string) ($s['phone'] ?? ''));
    $accountCode = trim((string) ($map['code'] ?? ''));
    $accountName = trim((string) ($map['name'] ?? ''));
    $balance = (float) ($supBal[$sid] ?? 0.0);
    $pr2SupplierPickRows[] = [
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
// س13: التقاط استثناءات «ليس ورقة ترحيل» لئلا تكسر الشاشة قبل HTML — يعالجها $pr2Ready.
$inventoryAccId = null;
$cashAccId = null;
try {
    $inventoryAccId = orange_gl_account_id_optional($pdo, 'inventory');
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] purchase_returns inventory account: ' . $e->getMessage());
    }
}
try {
    $cashAccId = orange_gl_account_id_optional($pdo, 'cash');
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] purchase_returns cash account: ' . $e->getMessage());
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

$pr2Ready = ($inventoryAccId !== null && $inventoryAccId > 0 && $cashAccId !== null && $cashAccId > 0);
$pr2NavReady = orange_table_exists($pdo, 'purchase_returns');
$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
$otherVouchersUrl = storefront_public_path('/admin/index.php?page=other_vouchers');
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
.jv-search-modal__form { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
.jv-search-modal__row--fields {
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    align-items: flex-end;
    gap: 10px;
    width: 100%;
    overflow-x: auto;
}
.jv-search-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.jv-search-field label { font-size: 0.78rem; font-weight: 600; white-space: nowrap; }
.jv-search-field input { width: 100%; box-sizing: border-box; }
.jv-search-field--id { flex: 0 0 7rem; }
.jv-search-field--date { flex: 0 0 11rem; }
.jv-search-field--ref { flex: 1 1 0; min-width: 7rem; }
.jv-search-field--full { width: 100%; }
.jv-search-modal__actions { margin: 0 0 16px; }
.jv-search-table-wrap { max-height: min(40vh, 22rem); overflow: auto; border: 1px solid #e4e4e7; border-radius: 8px; }
.jv-search-results-table { margin: 0; font-size: 0.9rem; }
.jv-search-results-table tbody tr { cursor: pointer; }
.jv-search-results-table tbody tr:hover { background: #f4f4f5; }
.form-grid.pr2-header-row2 {
    grid-template-columns: minmax(6.5rem, 0.65fr) auto minmax(0, 1.5fr) minmax(5.5rem, 0.65fr);
}
.form-grid.pr2-header-row2 input[type="text"] {
    height: var(--input-min-h);
    min-height: var(--input-min-h);
    box-sizing: border-box;
}
.pr2-header-row2__action {
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    min-width: 0;
}
.pr2-header-row2__action-label {
    display: block;
    margin-bottom: 5px;
    font-size: 13px;
    visibility: hidden;
    line-height: 1.2;
}
.pr2-header-row2__action .btn-secondary {
    white-space: nowrap;
    min-height: var(--input-min-h);
    box-sizing: border-box;
    padding-inline: 14px;
}
.form-grid.pr2-header-row2 select {
    height: var(--input-min-h);
    min-height: var(--input-min-h);
    box-sizing: border-box;
    padding-block: 0;
    padding-inline: 0.65rem 2rem;
    line-height: var(--input-min-h);
}
</style>

<div class="page-title page-title--stacked">
    <div>
        <h1>مردود مشتريات</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;">سياق الدولة — المبالغ بعملة <strong><?php echo htmlspecialchars($prDefaultCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>. يُولَّد القيد المحاسبي تلقائياً ويُعرض في <a href="<?php echo htmlspecialchars($otherVouchersUrl, ENT_QUOTES, 'UTF-8'); ?>">سندات أخرى</a>.</p>
    </div>
</div>

<?php if (!$pr2Ready): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">اربط حساب <strong>المخزون</strong> و<strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
</div>
<?php endif; ?>

<div class="card jv-print-area">
    <h3 class="card-title">مردود مشتريات <span id="pr2_browse_label" class="muted" style="font-size:0.85rem;font-weight:500;"></span></h3>

    <!-- ١ — المورد -->
    <div class="form-grid" style="margin-bottom:12px;">
        <div style="grid-column:1/-1;">
            <label for="pr2_supplier_code">المورد</label>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:10px 14px;">
                <input type="text" id="pr2_supplier_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
                <input type="text" id="pr2_supplier_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
            </div>
            <input type="hidden" id="pr2_supplier_id" value="0">
        </div>
    </div>

    <!-- ٢ — فاتورة الشراء المرجعية، استرجاع، ملاحظات، نوع المردود -->
    <div class="form-grid pr2-header-row2" style="margin-bottom:16px;">
        <div>
            <label for="pr2_purchase_ref">فاتورة الشراء المرجعية</label>
            <input type="text" id="pr2_purchase_ref" placeholder="PUR- أو رقم" dir="ltr" lang="en" autocomplete="off"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
        </div>
        <div class="pr2-header-row2__action">
            <span class="pr2-header-row2__action-label" aria-hidden="true">.</span>
            <button type="button" class="btn-secondary" id="pr2_btn_retrieve"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>استرجاع</button>
        </div>
        <div>
            <label for="pr2_notes">ملاحظات</label>
            <input type="text" id="pr2_notes" placeholder="رقم إذن الإرجاع، شروط، …"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="pr2_type">نوع المردود</label>
            <select id="pr2_type"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
                <option value="cash">نقدي</option>
                <option value="credit">آجل</option>
            </select>
        </div>
    </div>

    <!-- ٣ — أسطر الأصناف -->
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
                <tbody id="pr2_lines_body"></tbody>
            </table>
        </div>
    </div>

    <!-- ٣ — خصم الفاتورة + المجاميع -->
    <div style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 24px;">
        <div style="flex:0 0 auto;">
            <label for="pr2_invoice_discount" style="font-size:0.82rem;font-weight:600;">خصم الفاتورة</label>
            <input type="text" id="pr2_invoice_discount" placeholder="0 أو 5%" dir="ltr" lang="en" style="width:8rem;" autocomplete="off"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
        </div>
        <div style="flex:1 1 auto;text-align:left;direction:ltr;font-size:0.95rem;line-height:1.8;">
            <span style="color:#64748b;">إجمالي البنود:</span> <strong id="pr2_subtotal" class="admin-money-display" dir="ltr" lang="en"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">صافي المردود:</span> <strong id="pr2_net_total" class="admin-money-display" dir="ltr" lang="en" style="color:#dc2626;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <!-- ٤ — أزرار -->
    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:16px;">
        <span></span>
        <div class="jv-toolbar-primary-group">
            <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين مردودات المشتريات">
                <button type="button" class="btn-secondary jv-nav-btn" id="pr2_nav_first" title="أول مردود" aria-label="أول مردود">&lt;&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="pr2_nav_prev" title="المردود السابق" aria-label="المردود السابق">&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="pr2_nav_next" title="المردود التالي" aria-label="المردود التالي">&gt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="pr2_nav_last" title="آخر مردود" aria-label="آخر مردود">&gt;&gt;</button>
                <button type="button" class="btn-secondary jv-nav-search" id="pr2_btn_search" title="بحث عن مردود">بحث</button>
            </div>
            <button type="button" class="btn-secondary" id="pr2_btn_print" title="طباعة المردود المعروض" disabled>طباعة</button>
            <button type="button" class="btn-secondary" id="pr2_btn_new" title="مردود جديد">مردود جديد</button>
            <button type="button" id="pr2_btn_save"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>حفظ</button>
        </div>
    </div>
</div>

<!-- Supplier Picker Modal -->
<div class="gl-pick-modal" id="pr2_supplier_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="pr2_supplier_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="pr2_supplier_pick_title">
        <h3 id="pr2_supplier_pick_title" class="gl-pick-modal__title">اختيار المورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="pr2_supplier_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بكود الحساب أو اسم المورد…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="pr2_supplier_pick_list"></ul>
        <button type="button" class="btn-secondary" id="pr2_supplier_pick_close">إغلاق</button>
    </div>
</div>


<!-- Search Modal -->
<div id="pr2_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="pr2_search_modal_title">
    <div class="jv-search-modal__backdrop" id="pr2_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="pr2_search_modal_title" class="jv-search-modal__title">بحث في مردودات المشتريات</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="pr2_search_id_from">رقم المردود — من</label>
                        <input type="number" id="pr2_search_id_from" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="pr2_search_id_to">رقم المردود — إلى</label>
                        <input type="number" id="pr2_search_id_to" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="pr2_search_date_from">التاريخ — من</label>
                        <input type="text" id="pr2_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="pr2_search_date_to">التاريخ — إلى</label>
                        <input type="text" id="pr2_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="pr2_search_ref">المرجع PR- (يحتوي النص)</label>
                        <input type="text" id="pr2_search_ref" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="pr2_search_purchase_ref">فاتورة الشراء PUR- (يحتوي النص)</label>
                        <input type="text" id="pr2_search_purchase_ref" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="pr2_search_notes">ملاحظات (يحتوي النص)</label>
                        <input type="text" id="pr2_search_notes" class="admin-inp" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="pr2_search_btn">تنفيذ البحث</button>
            </div>
            <div class="jv-search-modal__results">
                <div class="table-wrap jv-search-table-wrap">
                    <table class="admin-table jv-search-results-table">
                        <thead>
                            <tr>
                                <th>رقم</th>
                                <th>تاريخ</th>
                                <th>مرجع</th>
                                <th>مورد</th>
                                <th>ف. شراء</th>
                                <th>صافي</th>
                            </tr>
                        </thead>
                        <tbody id="pr2_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var PR2_PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PR2_VARIANTS = <?php echo json_encode($variantsByProduct, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PR2_SUPPLIER_PICK_ROWS = <?php echo json_encode($pr2SupplierPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PR2_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PR2_PREFILL_SUPPLIER = <?php echo (int) $prefillSupplierId; ?>;
    var PR2_READY = <?php echo $pr2Ready ? 'true' : 'false'; ?>;
    var PR2_NAV_READY = <?php echo $pr2NavReady ? 'true' : 'false'; ?>;

    var browseReturnId = 0;
    var pr2ViewMode = false;
    var currentSupplierId = 0;

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function fmt3(n) {
        var f = window.orangeFmtMoney || (window.OrangeMoney && window.OrangeMoney.formatAmount);
        if (f) {
            return f(n);
        }
        var d = (window.ORANGE_ADMIN_MONEY && window.ORANGE_ADMIN_MONEY.decimals) || 3;
        return (parseFloat(n) || 0).toFixed(d);
    }
    function fmtZero() {
        if (window.orangeMoneyZero) {
            return window.orangeMoneyZero();
        }
        return fmt3(0);
    }

    /* ── Product lookup by code/barcode ─────────────────────────────── */
    function findProductByCode(code) {
        code = String(code || '').trim();
        if (!code) return null;
        var lower = code.toLowerCase();
        for (var i = 0; i < PR2_PRODUCTS.length; i++) {
            var p = PR2_PRODUCTS[i];
            var ic = String(p.item_code || '').trim().toLowerCase();
            var bc = String(p.barcode || '').trim().toLowerCase();
            if ((ic && ic === lower) || (bc && bc === lower)) return p;
        }
        return null;
    }

    function parseDiscount(raw, lineAmount) {
        raw = String(raw || '').trim();
        if (!raw || raw === '0') return 0;
        if (raw.endsWith('%')) {
            var pct = parseFloat(raw.slice(0, -1)) || 0;
            return Math.round(lineAmount * pct / 100 * 10000) / 10000;
        }
        return parseFloat(raw) || 0;
    }

    function pr2ClampQtyInput(inp) {
        if (!inp) return;
        var maxQ = parseInt(inp.getAttribute('data-max-qty') || '0', 10) || 0;
        if (maxQ <= 0) return;
        var q = parseInt(inp.value, 10) || 0;
        if (q > maxQ) {
            inp.value = String(maxQ);
        } else if (q < 1 && inp.value !== '') {
            inp.value = '1';
        }
    }

    /* ── Supplier picker ────────────────────────────────────────────── */
    function supplierById(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        for (var i = 0; i < PR2_SUPPLIER_PICK_ROWS.length; i++) {
            if ((parseInt(String(PR2_SUPPLIER_PICK_ROWS[i].id || '0'), 10) || 0) === id) return PR2_SUPPLIER_PICK_ROWS[i];
        }
        return null;
    }

    function selectSupplier(id) {
        var row = supplierById(id);
        var codeEl = document.getElementById('pr2_supplier_code');
        var nameEl = document.getElementById('pr2_supplier_name');
        var idEl = document.getElementById('pr2_supplier_id');
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
    }

    function pickerOpen() {
        var modal = document.getElementById('pr2_supplier_pick_modal');
        var qEl = document.getElementById('pr2_supplier_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        pickerRender('');
        qEl.focus();
    }
    function pickerClose() {
        var modal = document.getElementById('pr2_supplier_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function pickerRender(q) {
        var listEl = document.getElementById('pr2_supplier_pick_list');
        if (!listEl) return;
        var query = String(q || '').trim().toLowerCase();
        var rows = PR2_SUPPLIER_PICK_ROWS.filter(function (r) {
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
            li.textContent = (r.account_code ? r.account_code + ' — ' : '') + r.name + (r.phone ? ' (' + r.phone + ')' : '') + ' [رصيد ' + orangeFmtMoney(r.balance) + ']';
            li.addEventListener('dblclick', function () { selectSupplier(r.id); pickerClose(); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { selectSupplier(r.id); pickerClose(); } });
            listEl.appendChild(li);
        });
    }

    /* ── Dynamic rows ───────────────────────────────────────────────── */
    function variantOptionsHtml(pid) {
        var list = (PR2_VARIANTS && PR2_VARIANTS[String(pid)]) ? PR2_VARIANTS[String(pid)] : [];
        if (!list.length) return '<option value="0">— لا متغيرات —</option>';
        return list.map(function (v) {
            return '<option value="' + v.id + '">' + esc(v.label) + '</option>';
        }).join('');
    }

    function productOptionsHtml() {
        var blank = '<option value="">' + esc('— اختر صنفاً —') + '</option>';
        return blank + PR2_PRODUCTS.map(function (p) {
            return '<option value="' + p.id + '" data-cost="' + (parseFloat(p.cost) || 0) + '">' + esc(p.name) + '</option>';
        }).join('');
    }

    function addLine() {
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'pr2-line';
        tr.innerHTML =
            '<td class="pur-col-idx"></td>' +
            '<td><input type="text" class="pr2-barcode admin-inp" placeholder="كود/باركود" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><select class="pr2-product" style="min-width:10rem;">' + productOptionsHtml() + '</select></td>' +
            '<td><select class="pr2-variant"></select></td>' +
            '<td><input type="number" class="pr2-qty admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
            '<td><input type="number" class="pr2-cost admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>' +
            '<td><input type="text" class="pr2-discount admin-inp" placeholder="0" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><input type="text" class="pr2-line-total admin-inp-money" value="' + fmtZero() + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
            '<td><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
        tb.appendChild(tr);
        renumberRows();
        var sel = tr.querySelector('.pr2-product');
        if (sel) updateVariantCell(sel);
        recalcAll();
    }

    function removeLine(btn) {
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        if (tb.querySelectorAll('tr').length <= 1) {
            var tr = btn.closest('tr');
            tr.querySelector('.pr2-barcode').value = '';
            tr.querySelector('.pr2-product').value = '';
            tr.querySelector('.pr2-qty').value = '1';
            tr.querySelector('.pr2-cost').value = fmtZero();
            tr.querySelector('.pr2-discount').value = '';
            tr.querySelector('.pr2-qty').removeAttribute('data-max-qty');
            tr.querySelector('.pr2-qty').removeAttribute('title');
            updateVariantCell(tr.querySelector('.pr2-product'));
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
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        }
    }

    function rowIsBlank(tr) {
        var pid = parseInt(tr.querySelector('.pr2-product').value, 10) || 0;
        var q = parseInt(tr.querySelector('.pr2-qty').value, 10) || 0;
        var code = (tr.querySelector('.pr2-barcode').value || '').trim();
        return pid <= 0 && q <= 0 && !code;
    }

    function trimExtraTrailing() {
        var tb = document.getElementById('pr2_lines_body');
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
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        if (rows.length === 0) { addLine(); return; }
        var last = rows[rows.length - 1];
        if (!rowIsBlank(last)) addLine();
    }

    function updateVariantCell(sel) {
        var row = sel.closest('tr');
        var pid = parseInt(sel.value, 10) || 0;
        var vsel = row.querySelector('.pr2-variant');
        if (vsel) vsel.innerHTML = variantOptionsHtml(pid);
    }

    function onProductChanged(sel) {
        var row = sel.closest('tr');
        var pid = parseInt(sel.value, 10) || 0;
        var opt = sel.options[sel.selectedIndex];
        var cost = opt ? parseFloat(opt.getAttribute('data-cost') || '0') : 0;
        var costInp = row.querySelector('.pr2-cost');
        if (costInp && pid > 0) costInp.value = fmt3(cost);
        updateVariantCell(sel);
        var barcodeInp = row.querySelector('.pr2-barcode');
        if (barcodeInp && pid > 0) {
            var prod = null;
            for (var i = 0; i < PR2_PRODUCTS.length; i++) {
                if (PR2_PRODUCTS[i].id === pid || String(PR2_PRODUCTS[i].id) === String(pid)) { prod = PR2_PRODUCTS[i]; break; }
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
        var sel = row.querySelector('.pr2-product');
        if (sel) {
            sel.value = String(prod.id);
            onProductChanged(sel);
        }
        syncTrailing();
    }

    /* ── Recalculate ────────────────────────────────────────────────── */
    function recalcAll() {
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr.pr2-line');
        var subtotal = 0;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var q = parseInt(r.querySelector('.pr2-qty').value, 10) || 0;
            var c = parseFloat(r.querySelector('.pr2-cost').value) || 0;
            var lineGross = q * c;
            var discRaw = (r.querySelector('.pr2-discount') && r.querySelector('.pr2-discount').value || '').trim();
            var discAmt = parseDiscount(discRaw, lineGross);
            var lineNet = Math.max(0, lineGross - discAmt);
            var ltEl = r.querySelector('.pr2-line-total');
            if (ltEl) ltEl.value = fmt3(lineNet);
            subtotal += lineNet;
        }
        var invDiscRaw = (document.getElementById('pr2_invoice_discount').value || '').trim();
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);
        var netTotal = Math.max(0, subtotal - invDiscAmt);
        var stEl = document.getElementById('pr2_subtotal');
        var ntEl = document.getElementById('pr2_net_total');
        if (stEl) stEl.textContent = fmt3(subtotal);
        if (ntEl) ntEl.textContent = fmt3(netTotal);
    }


    function parsePurchaseRef(raw) {
        raw = String(raw || '').trim();
        if (!raw) return 0;
        var m = /^PUR-(\d+)$/i.exec(raw);
        if (m) return parseInt(m[1], 10) || 0;
        if (/^\d+$/.test(raw)) return parseInt(raw, 10) || 0;
        return 0;
    }

    function pr2SyncToolbar() {
        var pb = document.getElementById('pr2_btn_print');
        if (pb) {
            pb.disabled = browseReturnId <= 0;
            pb.title = browseReturnId > 0 ? 'طباعة المردود المعروض' : 'افتح مردوداً محفوظاً للطباعة';
        }
        var sb = document.getElementById('pr2_btn_save');
        if (sb) {
            sb.disabled = !PR2_READY || pr2ViewMode;
            sb.title = pr2ViewMode ? 'وضع العرض — استخدم «مردود جديد» للإدخال' : 'حفظ مردود جديد';
        }
        var lbl = document.getElementById('pr2_browse_label');
        if (lbl) {
            lbl.textContent = browseReturnId > 0 ? ('— عرض PR-' + browseReturnId) : '';
        }
    }

    function pr2SetViewMode(on) {
        pr2ViewMode = !!on;
        var card = document.querySelector('.jv-print-area');
        if (card) {
            card.querySelectorAll('input, select, button.admin-doc-line-remove').forEach(function (el) {
                if (el.id === 'pr2_btn_new' || el.id === 'pr2_btn_print' || el.closest('.jv-voucher-nav-btns') || el.id === 'pr2_btn_search' || el.id === 'pr2_btn_retrieve') {
                    return;
                }
                if (el.id === 'pr2_btn_save') {
                    return;
                }
                el.disabled = pr2ViewMode || (!PR2_READY && el.id !== 'pr2_supplier_code');
            });
        }
        pr2SyncToolbar();
    }

    function pr2FillLineRow(tr, item) {
        var bc = tr.querySelector('.pr2-barcode');
        var sel = tr.querySelector('.pr2-product');
        if (sel) {
            sel.value = String(item.product_id || '');
            updateVariantCell(sel);
        }
        var vsel = tr.querySelector('.pr2-variant');
        if (vsel && item.variant_id) {
            vsel.value = String(item.variant_id);
        }
        if (bc) {
            var prod = null;
            for (var i = 0; i < PR2_PRODUCTS.length; i++) {
                if (PR2_PRODUCTS[i].id === item.product_id) { prod = PR2_PRODUCTS[i]; break; }
            }
            if (prod) bc.value = prod.item_code || prod.barcode || '';
        }
        var qEl = tr.querySelector('.pr2-qty');
        if (qEl) {
            qEl.value = String(item.qty || 1);
            if (item.qty_available != null && parseInt(String(item.qty_available), 10) > 0) {
                qEl.setAttribute('data-max-qty', String(item.qty_available));
                qEl.setAttribute('title', 'الحد الأقصى: ' + String(item.qty_available));
            } else if (item.qty_max != null && parseInt(String(item.qty_max), 10) > 0) {
                qEl.setAttribute('data-max-qty', String(item.qty_max));
                qEl.setAttribute('title', 'الحد الأقصى: ' + String(item.qty_max));
            } else {
                qEl.removeAttribute('data-max-qty');
                qEl.removeAttribute('title');
            }
        }
        var cEl = tr.querySelector('.pr2-cost');
        if (cEl) cEl.value = fmt3(item.cost || 0);
        var dEl = tr.querySelector('.pr2-discount');
        if (dEl) dEl.value = item.discount_raw || '';
    }

    function pr2ApplyPurchaseRetrievePayload(res) {
        if (!res || !res.success || !res.purchase) {
            alert((res && res.message) || 'تعذر استرجاع بنود فاتورة الشراء');
            return;
        }
        var p = res.purchase;
        var purEl = document.getElementById('pr2_purchase_ref');
        if (purEl) purEl.value = p.reference || ('PUR-' + (p.id || ''));
        selectSupplier(parseInt(String(p.supplier_id || '0'), 10) || 0);
        var typeEl = document.getElementById('pr2_type');
        if (typeEl) typeEl.value = p.type || 'cash';
        var invDiscEl = document.getElementById('pr2_invoice_discount');
        if (invDiscEl) invDiscEl.value = p.invoice_discount_raw || '';
        var tb = document.getElementById('pr2_lines_body');
        if (tb) {
            tb.innerHTML = '';
            var items = res.items || [];
            if (!items.length) {
                alert('لا توجد كميات متبقية للإرجاع من هذه الفاتورة');
                addLine();
            } else {
                items.forEach(function (item) {
                    addLine();
                    var rows = tb.querySelectorAll('tr.pr2-line');
                    pr2FillLineRow(rows[rows.length - 1], item);
                });
            }
            syncTrailing();
        }
        recalcAll();
    }

    function pr2RetrieveFromPurchase() {
        if (!PR2_READY || pr2ViewMode) return;
        var purchaseId = parsePurchaseRef(document.getElementById('pr2_purchase_ref').value || '');
        if (purchaseId <= 0) {
            alert('أدخل رقم فاتورة شراء صالحاً (PUR- أو رقم) في خانة فاتورة الشراء المرجعية.');
            return;
        }
        var btn = document.getElementById('pr2_btn_retrieve');
        if (btn) btn.disabled = true;
        fetch('/admin/api/purchase_returns/retrieve_from_purchase.php?purchase_id=' + encodeURIComponent(String(purchaseId)), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (typeof orangeAdminOfferSuggestOnFailure === 'function' && !res.success && orangeAdminOfferSuggestOnFailure(res, 'تعذر الاسترجاع')) {
                return;
            }
            pr2ApplyPurchaseRetrievePayload(res);
        }).catch(function (e) {
            alert(e.message || String(e));
        }).finally(function () {
            if (btn && !pr2ViewMode && PR2_READY) btn.disabled = false;
        });
    }

    function pr2ApplyReturnPayload(res) {
        if (!res || !res.success || !res.purchase_return) {
            alert((res && res.message) || 'تعذر تحميل المردود');
            return;
        }
        var p = res.purchase_return;
        browseReturnId = parseInt(String(p.id || '0'), 10) || 0;
        selectSupplier(parseInt(String(p.supplier_id || '0'), 10) || 0);
        var typeEl = document.getElementById('pr2_type');
        if (typeEl) typeEl.value = p.type || 'cash';
        var notesEl = document.getElementById('pr2_notes');
        if (notesEl) notesEl.value = p.notes || '';
        var purEl = document.getElementById('pr2_purchase_ref');
        if (purEl) {
            var pid = parseInt(String(p.purchase_id || '0'), 10) || 0;
            purEl.value = pid > 0 ? ('PUR-' + pid) : '';
        }
        var invDiscEl = document.getElementById('pr2_invoice_discount');
        if (invDiscEl) invDiscEl.value = p.invoice_discount_raw || '';
        var tb = document.getElementById('pr2_lines_body');
        if (tb) {
            tb.innerHTML = '';
            var items = res.items || [];
            if (!items.length) {
                addLine();
            } else {
                items.forEach(function (item) {
                    addLine();
                    var rows = tb.querySelectorAll('tr.pr2-line');
                    pr2FillLineRow(rows[rows.length - 1], item);
                });
            }
            syncTrailing();
        }
        recalcAll();
        pr2SetViewMode(true);
    }

    function pr2LoadReturn(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        if (id <= 0) return;
        fetch('/admin/api/purchase_returns/get.php?purchase_return_id=' + encodeURIComponent(String(id)), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            pr2ApplyReturnPayload(res);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function pr2Nav(where) {
        if (!PR2_NAV_READY) return;
        postJSON('/admin/api/purchase_returns/browse.php', {
            action: 'nav',
            where: where,
            current_id: browseReturnId || 0
        }).then(function (r) {
            if (!r.success || !r.id) {
                alert(r.message || 'لا يوجد مردود');
                return;
            }
            pr2LoadReturn(r.id);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function pr2SearchOpen() {
        var m = document.getElementById('pr2_search_modal');
        if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
    }
    function pr2SearchClose() {
        var m = document.getElementById('pr2_search_modal');
        if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
    }
    function pr2SearchRun() {
        var idFrom = parseInt(document.getElementById('pr2_search_id_from').value, 10) || 0;
        var idTo = parseInt(document.getElementById('pr2_search_id_to').value, 10) || 0;
        var dateFrom = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('pr2_search_date_from')) || '' : '';
        var dateTo = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('pr2_search_date_to')) || '' : '';
        var ref = (document.getElementById('pr2_search_ref').value || '').trim();
        var purchaseRef = (document.getElementById('pr2_search_purchase_ref').value || '').trim();
        var notes = (document.getElementById('pr2_search_notes').value || '').trim();
        var tbody = document.getElementById('pr2_search_results');
        tbody.innerHTML = '<tr><td colspan="6">جاري البحث…</td></tr>';
        var payload = { action: 'search' };
        if (idFrom > 0) payload.id_from = idFrom;
        if (idTo > 0) payload.id_to = idTo;
        if (dateFrom) payload.date_from = dateFrom;
        if (dateTo) payload.date_to = dateTo;
        if (ref) payload.reference = ref;
        if (purchaseRef) payload.purchase_ref = purchaseRef;
        if (notes) payload.notes = notes;
        postJSON('/admin/api/purchase_returns/browse.php', payload).then(function (r) {
            tbody.innerHTML = '';
            if (!r.success || !r.results || !r.results.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="muted">لا نتائج</td></tr>';
                return;
            }
            r.results.forEach(function (v) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + esc(String(v.id)) + '</td>'
                    + '<td>' + esc(v.created_at_dmy || '') + '</td>'
                    + '<td dir="ltr">' + esc(v.reference || '') + '</td>'
                    + '<td>' + esc(v.supplier_name || '') + '</td>'
                    + '<td dir="ltr">' + esc(v.purchase_reference || '') + '</td>'
                    + '<td dir="ltr">' + fmt3(v.total || 0) + '</td>';
                tr.addEventListener('dblclick', function () { pr2LoadReturn(v.id); pr2SearchClose(); });
                tbody.appendChild(tr);
            });
        }).catch(function (e) {
            tbody.innerHTML = '<tr><td colspan="6">' + esc(e.message || String(e)) + '</td></tr>';
        });
    }

    function pr2ResetNew() {
        browseReturnId = 0;
        pr2SetViewMode(false);
        location.reload();
    }

    /* ── Save ───────────────────────────────────────────────────────── */
    function save() {
        if (!PR2_READY || pr2ViewMode) return;
        var supplierId = parseInt(document.getElementById('pr2_supplier_id').value, 10) || 0;
        var retType = document.getElementById('pr2_type').value;
        var notes = (document.getElementById('pr2_notes').value || '').trim();
        var purchaseId = parsePurchaseRef(document.getElementById('pr2_purchase_ref').value || '');

        if (retType === 'credit' && supplierId <= 0) {
            alert('مردود آجل يتطلّب مورداً.');
            return;
        }

        var tb = document.getElementById('pr2_lines_body');
        if (!tb) { alert('لا توجد أصناف'); return; }
        var rows = tb.querySelectorAll('tr.pr2-line');
        var items = [];
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var pid = parseInt(r.querySelector('.pr2-product').value, 10) || 0;
            var vid = parseInt(r.querySelector('.pr2-variant').value, 10) || 0;
            var q = parseInt(r.querySelector('.pr2-qty').value, 10) || 0;
            var c = parseFloat(r.querySelector('.pr2-cost').value) || 0;
            var discRaw = (r.querySelector('.pr2-discount') && r.querySelector('.pr2-discount').value || '').trim();
            var maxQ = parseInt(r.querySelector('.pr2-qty').getAttribute('data-max-qty') || '0', 10) || 0;
            if (maxQ > 0 && q > maxQ) {
                alert('الكمية في السطر ' + (i + 1) + ' تتجاوز المتاح (' + maxQ + ').');
                return;
            }
            if (!pid || q < 1) continue;
            var lineGross = q * c;
            var discAmt = parseDiscount(discRaw, lineGross);
            items.push({ product_id: pid, variant_id: vid, qty: q, cost: c, discount_raw: discRaw, discount_amount: discAmt });
        }
        if (!items.length) {
            alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة');
            return;
        }

        var invDiscRaw = (document.getElementById('pr2_invoice_discount').value || '').trim();
        var subtotal = parseFloat(document.getElementById('pr2_subtotal').textContent) || 0;
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);

        var payload = {
            supplier_id: supplierId,
            type: retType,
            notes: notes,
            purchase_id: purchaseId > 0 ? purchaseId : 0,
            items: items,
            invoice_discount_raw: invDiscRaw,
            invoice_discount_amount: invDiscAmt
        };

        postJSON('/admin/api/purchase_returns/create.php', payload).then(function (res) {
            if (res.success) {
                if (typeof orangeAdminOfferOpenGlVoucherAfterSave === 'function') {
                    orangeAdminOfferOpenGlVoucherAfterSave(res, function () {
                        location.reload();
                    });
                } else {
                    alert(res.message || 'تم حفظ مردود المشتريات');
                    location.reload();
                }
                return;
            }
            if (typeof orangeAdminOfferSuggestOnFailure === 'function' && orangeAdminOfferSuggestOnFailure(res, 'فشل')) return;
            alert(res.message || 'فشل');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    /* ── Init & bindings ────────────────────────────────────────────── */
    function init() {
        var codeEl = document.getElementById('pr2_supplier_code');
        if (codeEl) {
            codeEl.addEventListener('dblclick', function (e) { e.preventDefault(); pickerOpen(); });
            codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickerOpen(); } });
        }
        document.getElementById('pr2_supplier_pick_backdrop').addEventListener('click', pickerClose);
        document.getElementById('pr2_supplier_pick_close').addEventListener('click', pickerClose);
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') pickerClose(); });

        var pickQ = document.getElementById('pr2_supplier_pick_q');
        var pickTimer = null;
        if (pickQ) {
            pickQ.addEventListener('input', function () {
                if (pickTimer) clearTimeout(pickTimer);
                pickTimer = setTimeout(function () { pickerRender(pickQ.value || ''); }, 180);
            });
        }

        document.getElementById('pr2_btn_save').addEventListener('click', save);
        document.getElementById('pr2_btn_new').addEventListener('click', pr2ResetNew);
        document.getElementById('pr2_btn_retrieve').addEventListener('click', pr2RetrieveFromPurchase);
        document.getElementById('pr2_invoice_discount').addEventListener('input', function () { recalcAll(); });
        document.getElementById('pr2_btn_print').addEventListener('click', function () {
            if (browseReturnId <= 0) {
                alert('افتح مردوداً محفوظاً للطباعة.');
                return;
            }
            window.print();
        });

        document.getElementById('pr2_nav_first').addEventListener('click', function () { pr2Nav('first'); });
        document.getElementById('pr2_nav_prev').addEventListener('click', function () { pr2Nav('prev'); });
        document.getElementById('pr2_nav_next').addEventListener('click', function () { pr2Nav('next'); });
        document.getElementById('pr2_nav_last').addEventListener('click', function () { pr2Nav('last'); });
        document.getElementById('pr2_btn_search').addEventListener('click', pr2SearchOpen);
        document.getElementById('pr2_search_btn').addEventListener('click', pr2SearchRun);
        document.getElementById('pr2_search_modal_backdrop').addEventListener('click', pr2SearchClose);
        document.addEventListener('mousedown', function (ev) {
            var m = document.getElementById('pr2_search_modal');
            if (!m || m.style.display !== 'flex') return;
            var panel = m.querySelector('.jv-search-modal__panel');
            if (panel && (panel === ev.target || panel.contains(ev.target))) return;
            if (ev.target === m || ev.target.classList.contains('jv-search-modal__backdrop')) pr2SearchClose();
        });



        var tb = document.getElementById('pr2_lines_body');
        if (tb) {
            tb.addEventListener('change', function (e) {
                if (e.target && e.target.classList.contains('pr2-product')) {
                    onProductChanged(e.target);
                }
                if (e.target && e.target.classList.contains('pr2-qty')) {
                    pr2ClampQtyInput(e.target);
                }
                syncTrailing();
                recalcAll();
            });
            tb.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('pr2-barcode')) return;
                if (e.target && e.target.classList.contains('pr2-qty')) {
                    pr2ClampQtyInput(e.target);
                }
                syncTrailing();
                recalcAll();
            });
            tb.addEventListener('keydown', function (e) {
                if (e.target && e.target.classList.contains('pr2-barcode') && e.key === 'Enter') {
                    e.preventDefault();
                    onBarcodeBlurOrEnter(e.target);
                    return;
                }
                if (e.key !== 'Tab' || e.shiftKey) return;
                var ta = e.target;
                if (!ta || !ta.classList.contains('pr2-cost')) return;
                var tr = ta.closest('tr');
                if (!tr || tr.parentElement !== tb) return;
                var rows = tb.querySelectorAll('tr');
                if (tr !== rows[rows.length - 1]) return;
                e.preventDefault();
                syncTrailing();
                var rows2 = tb.querySelectorAll('tr');
                var next = rows2[rows2.length - 1];
                var bc = next && next.querySelector('.pr2-barcode');
                if (bc) bc.focus();
            });
            tb.addEventListener('focusout', function (e) {
                if (e.target && e.target.classList.contains('pr2-barcode')) {
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

        if (PR2_PREFILL_SUPPLIER > 0) {
            selectSupplier(PR2_PREFILL_SUPPLIER);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
