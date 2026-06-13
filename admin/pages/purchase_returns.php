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

require_once __DIR__ . '/../../includes/purchase_doc_product_pick.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/admin_voucher_print_tuning.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';

$pdo = orange_admin_page_pdo();
$pr2Caps = orange_admin_caps_for_page($admin, $pdo, 'purchase_returns');

$prCountryId = orange_admin_context_country_id($pdo);
$prDefaultCurrency = orange_admin_context_currency_code($pdo);
$prProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $prCountryId);
$prSuppliersCountrySql = orange_sql_country_and_fragment($pdo, 'suppliers', 'suppliers', $prCountryId);

$pr2PickRows = orange_purchase_doc_product_pick_rows($pdo, $prCountryId);

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
$pr2DocSerialPreview = $pr2NavReady
    ? orange_country_document_next_ref_preview($pdo, 'purchase_returns', 'PR', $prCountryId)
    : '';
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
/* صف 2 — فاتورة الشراء المرجعية · استرجاع · تاريخ المردود · نوع المردود · تاريخ الإدخال · ملاحظات
   (التواريخ والنوع بنفس عرض فاتورة الشراء؛ فرق زر الاسترجاع مأخوذ من عرض ملاحظات) */
.form-grid.pr2-header-row2 {
    grid-template-columns: minmax(7rem, 0.85fr) auto minmax(5.5rem, 0.65fr) minmax(5.5rem, 0.65fr) minmax(5.5rem, 0.65fr) minmax(0, 1.4fr);
}
.form-grid.pr2-supplier-row {
    grid-template-columns: minmax(7rem, 0.75fr) minmax(0, 1fr) minmax(0, 2fr);
}
.pur-inv-disc-row { display: none; }
@media print {
    /* ===== شبكة جدول البنود (مطابقة فاتورة مبيعات الشركة) ===== */
    .jv-print-area .pur-lines-table tfoot tr.pur-inv-disc-row.pur-inv-disc-active {
        display: table-row !important;
    }
    .jv-print-area .pur-lines-table tfoot td {
        padding: 4px 5px !important;
        font-size: 8.5pt !important;
        border: 1px solid #cbd5e1 !important;
        vertical-align: middle !important;
        background: #fff7ed !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .jv-print-area .pur-lines-table thead th.admin-doc-col-actions,
    .jv-print-area .pur-lines-table tbody td:last-child {
        display: none !important;
    }
    .sd-print-hide { display: none !important; }
    .jv-print-area .pur-lines-table {
        table-layout: fixed !important;
        width: 100% !important;
        border-collapse: collapse !important;
        border: 1px solid #cbd5e1 !important;
    }
    .jv-print-area .pur-lines-table thead th,
    .jv-print-area .pur-lines-table tbody td {
        padding: 4px 5px !important;
        font-size: 8.5pt !important;
        white-space: normal !important;
        overflow: visible !important;
        word-break: break-word;
        vertical-align: middle !important;
        border: 1px solid #cbd5e1 !important;
    }
    .jv-print-area .pur-lines-table thead th {
        background: #ea580c !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        text-align: center !important;
        border-color: #ffffff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .jv-print-area .pur-lines-table input {
        border: none !important;
        background: transparent !important;
        box-shadow: none !important;
        outline: none !important;
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        padding: 0 !important;
        margin: 0 !important;
        font-size: 8.5pt !important;
        line-height: 1.3 !important;
        color: #0f172a !important;
        -webkit-text-fill-color: #0f172a !important;
        opacity: 1 !important;
        text-overflow: clip !important;
    }
    .jv-print-area .pur-lines-table thead th:nth-child(1) { width: 4% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(2) { width: 12% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(3) { width: 28% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(4) { width: 16% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(5) { width: 8% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(6) { width: 11% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(7) { width: 9% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(8) { width: 12% !important; }
    .jv-print-area .pur-lines-table tbody td:nth-child(2) input { text-align: left !important; }
    .jv-print-area .pur-lines-table tbody td:nth-child(3) input,
    .jv-print-area .pur-lines-table tbody td:nth-child(4) input { text-align: right !important; }
    .jv-print-area .pur-lines-table tbody td:nth-child(5) input,
    .jv-print-area .pur-lines-table tbody td:nth-child(6) input,
    .jv-print-area .pur-lines-table tbody td:nth-child(7) input,
    .jv-print-area .pur-lines-table tbody td:nth-child(8) input {
        text-align: center !important;
    }
}
</style>

<div class="page-title">
    <h1>مردود مشتريات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$pr2Ready): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">اربط حساب <strong>المخزون</strong> و<strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
</div>
<?php endif; ?>

<div class="card jv-print-area">
    <?php
    orange_sales_doc_print_banner([
        'prefix' => 'pr2',
        'doc_title' => 'مردود مشتريات',
        'doc_title_en' => 'Purchase Return',
        'country_id' => $prCountryId,
        'currency_code' => $prDefaultCurrency,
        'serial_label' => 'رقم المردود / Return No.',
        'doc_date_label' => 'تاريخ المردود / Return Date',
        'show_doc_date' => true,
        'show_print_date' => false,
        'show_qr' => false,
        'show_party' => true,
        'party_title' => 'رقم الفاتورة المرتجع / Returned Invoice No.',
        'party_title_value_id' => 'ref_invoice',
        'party_rows' => [
            ['اسم المورد / Name', 'party_name', ''],
            ['كود المورد / Code', 'party_code', 'ltr'],
            ['رقم فاتورة المورد / Supplier Inv. No.', 'party_inv', 'ltr'],
            ['نوع الشراء / Type', 'party_type', ''],
        ],
        'show_notes' => true,
        'totals_rows' => [
            ['إجمالي المردود / Total', 'gross'],
            ['قيمة الخصم / Discount', 'disc'],
            ['صافي المردود / Net', 'net'],
        ],
    ]);
    ?>
    <h3 class="card-title">مردود مشتريات <span id="pr2_browse_label" class="muted" style="font-size:0.85rem;font-weight:500;"></span></h3>
    <?php orange_edit_lock_ui_toolbar(['prefix' => 'pr2', 'doc_kind' => 'purchase_return', 'country_id' => $prCountryId, 'show_status_badge' => false]); ?>

    <!-- ١ — مسلسل الفاتورة + المورد -->
    <div class="form-grid pr2-supplier-row orange-doc-header-row jv-print-hide" style="margin-bottom:12px;">
        <div>
            <label for="pr2_doc_serial">مسلسل الفاتورة</label>
            <input type="text" id="pr2_doc_serial" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en" style="background:#f4f4f5;cursor:default;"
                value="<?php echo htmlspecialchars($pr2DocSerialPreview, ENT_QUOTES, 'UTF-8'); ?>"
                title="يُخصَّص تلقائياً من النظام عند الحفظ">
        </div>
        <div>
            <label for="pr2_supplier_code">كود المورد</label>
            <input type="text" id="pr2_supplier_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="pr2_supplier_name">اسم المورد</label>
            <input type="text" id="pr2_supplier_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
        </div>
        <input type="hidden" id="pr2_supplier_id" value="0">
        <input type="hidden" id="pr2_supplier_invoice_ref" value="">
    </div>

    <!-- ٢ — فاتورة الشراء المرجعية، استرجاع، تاريخ المردود، نوع المردود، تاريخ الإدخال، ملاحظات -->
    <div class="form-grid pr2-header-row2 orange-doc-header-row jv-print-hide" style="margin-bottom:16px;">
        <div>
            <label for="pr2_purchase_ref">فاتورة الشراء المرجعية</label>
            <input type="text" id="pr2_purchase_ref" placeholder="PUR- أو رقم" dir="ltr" lang="en" autocomplete="off"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
        </div>
        <div class="orange-doc-header-row__action">
            <span class="orange-doc-header-row__action-label" aria-hidden="true">.</span>
            <button type="button" class="btn-secondary" id="pr2_btn_retrieve"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>استرجاع</button>
        </div>
        <div>
            <label for="pr2_document_date">تاريخ المردود</label>
            <input type="date" id="pr2_document_date" dir="ltr" lang="en" title="تاريخ المردود = تاريخ ترحيل القيد المحاسبي" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="pr2_type">نوع المردود</label>
            <select id="pr2_type"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
                <option value="cash">نقدي</option>
                <option value="credit">آجل</option>
            </select>
        </div>
        <div>
            <label for="pr2_entry_date">تاريخ الإدخال</label>
            <input type="text" id="pr2_entry_date" class="admin-inp-readonly" readonly tabindex="-1" dir="ltr" lang="en" style="background:#f4f4f5;cursor:default;"
                value="<?php echo htmlspecialchars(orange_format_datetime_dmY_hi(date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8'); ?>"
                title="وقت تسجيل إدخال المستند في النظام — يُثبت عند الحفظ ولا يُقبل من المتصفح">
        </div>
        <div>
            <label for="pr2_notes">ملاحظات</label>
            <input type="text" id="pr2_notes" placeholder="رقم إذن الإرجاع، شروط، …"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
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
                        <th style="min-width:8rem;">كود<span class="sd-print-hide"> / باركود</span></th>
                        <th style="min-width:10rem;">اسم الصنف</th>
                        <th style="min-width:8rem;">اللون / المقاس</th>
                        <th style="width:5rem;">الكمية</th>
                        <th style="width:6rem;">تكلفة الوحدة</th>
                        <th style="width:6rem;">خصم</th>
                        <th style="width:7rem;">إجمالي الصنف</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر" style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody id="pr2_lines_body"></tbody>
                <tfoot>
                    <tr id="pr2_inv_disc_print_row" class="pur-inv-disc-row">
                        <td colspan="7" style="text-align:right;font-weight:700;">خصم الفاتورة</td>
                        <td id="pr2_inv_disc_print_val" style="text-align:center;font-weight:700;">—</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php /* مردود المشتريات بلا بنود إضافية (قرار 2026-06): الإجماليات تظهر في كارت الترويسة (إجمالي/خصم/صافي) — لا صندوق سفلي مكرّر. */ ?>

    <!-- ٣ — خصم الفاتورة + المجاميع -->
    <div class="jv-print-hide" style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 24px;">
        <div style="flex:0 0 auto;" class="jv-print-hide">
            <label for="pr2_invoice_discount" style="font-size:0.82rem;font-weight:600;">خصم الفاتورة</label>
            <input type="text" id="pr2_invoice_discount" placeholder="0 أو 5%" dir="ltr" lang="en" style="width:8rem;" autocomplete="off"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>
        </div>
        <div style="flex:1 1 auto;text-align:left;direction:ltr;font-size:0.95rem;line-height:1.8;">
            <span style="color:#64748b;">إجمالي الأصناف:</span> <strong id="pr2_subtotal" class="admin-money-display" dir="ltr" lang="en"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">خصم الأصناف:</span> <strong id="pr2_discount_total" class="admin-money-display" dir="ltr" lang="en" style="color:#b91c1c;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">صافي الأصناف:</span> <strong id="pr2_net_total" class="admin-money-display" dir="ltr" lang="en" style="color:#059669;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">خصم الفاتورة:</span> <strong id="pr2_inv_disc" class="admin-money-display" dir="ltr" lang="en" style="color:#b91c1c;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#0f172a;font-weight:700;border-top:2px solid #ea580c;display:inline-block;padding-top:4px;margin-top:2px;">مبلغ المردود: <strong id="pr2_grand_total" class="admin-money-display" dir="ltr" lang="en" style="color:#ea580c;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong></span>
        </div>
        <div id="pr2_line_disc_error" style="flex:1 1 100%;color:#dc2626;font-size:0.85rem;font-weight:600;display:none;"></div>
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
            <button type="button" class="btn-secondary" id="pr2_btn_print" title="طباعة المردود المعروض"<?php echo orange_admin_invoice_print_tuning_mode() ? '' : ' disabled'; ?>>طباعة</button>
            <button type="button" class="btn-secondary" id="pr2_btn_new" title="مردود جديد" onclick="if (confirm('بدء مردود جديد؟ سيتم مسح أي بيانات غير محفوظة على الشاشة.')) { location.reload(); } return false;">مردود جديد</button>
            <button type="button" id="pr2_btn_save" data-orange-perm="edit" data-orange-page="purchase_returns"<?php echo !$pr2Ready ? ' disabled' : ''; ?>>حفظ</button>
        </div>
    </div>
</div>

<!-- Supplier Picker Modal -->
<!-- Product pick modal -->
<div id="pr2_product_pick_modal" class="mo-pick-modal" hidden>
    <div class="mo-pick-modal__backdrop" id="pr2_product_pick_backdrop"></div>
    <div class="mo-pick-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pr2_product_pick_title">
        <h4 id="pr2_product_pick_title" class="mo-pick-modal__title">اختيار صنف</h4>
        <input type="search" id="pr2_product_pick_filter" class="admin-inp mo-pick-modal__search" placeholder="ابحث بالكود أو الاسم أو اللون أو المقاس…" autocomplete="off" lang="ar" dir="rtl">
        <div class="mo-pick-modal__scroller table-wrap">
            <table class="admin-table mo-pick-table">
                <thead>
                    <tr>
                        <th>الكود</th>
                        <th>الباركود</th>
                        <th>الاسم</th>
                        <th>اللون</th>
                        <th>المقاس</th>
                        <th class="mo-pick-num-h">التكلفة</th>
                    </tr>
                </thead>
                <tbody id="pr2_product_pick_body"></tbody>
            </table>
        </div>
        <p class="card-hint mo-pick-modal__hint">انقر نقراً مزدوجاً على السطر للاختيار — أو امسح الباركود في خانة الكود.</p>
    </div>
</div>

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

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_purchase_doc_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var PR2_PICK_ROWS = <?php echo json_encode($pr2PickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PR2_SUPPLIER_PICK_ROWS = <?php echo json_encode($pr2SupplierPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PR2_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PR2_PREFILL_SUPPLIER = <?php echo (int) $prefillSupplierId; ?>;
    var PR2_READY = <?php echo $pr2Ready ? 'true' : 'false'; ?>;
    var PR2_PRINT_TUNING = <?php echo orange_admin_invoice_print_tuning_mode() ? 'true' : 'false'; ?>;
    var PR2_NAV_READY = <?php echo $pr2NavReady ? 'true' : 'false'; ?>;
    var PR2_COUNTRY_ID = <?php echo (int) $prCountryId; ?>;
    var PR2_CAPS = <?php echo json_encode($pr2Caps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var pr2EditLockCtl = null;
    var PR2_DOC_SERIAL_PREVIEW = <?php echo json_encode($pr2DocSerialPreview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

    var browseReturnId = 0;
    var pr2ViewMode = false;
    var currentSupplierId = 0;
    var pr2ProductPick = null;

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
    function lineRowHtml() {
        return '<td class="pur-col-idx"></td>' +
            '<td><input type="text" class="pr2-code admin-inp" placeholder="كود أو باركود" dir="ltr" lang="en" autocomplete="off" style="width:100%;" title="امسح الباركود أو دبل كليك للبحث">' +
            '<input type="hidden" class="pr2-product-id" value="">' +
            '<input type="hidden" class="pr2-variant-id" value="0"></td>' +
            '<td><input type="text" class="pr2-name admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>' +
            '<td><input type="text" class="pr2-var-label admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>' +
            '<td><input type="number" class="pr2-qty admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
            '<td><input type="number" class="pr2-cost admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>' +
            '<td><input type="text" class="pr2-discount admin-inp admin-inp-discount" value="" placeholder="' + fmtZero() + '" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><input type="text" class="pr2-line-total admin-inp-money" value="' + fmtZero() + '" readonly data-money-allow-zero tabindex="0" dir="ltr" lang="en"></td>' +
            '<td><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
    }

    function addLine() {
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'pr2-line';
        tr.innerHTML = lineRowHtml();
        tb.appendChild(tr);
        renumberRows();
        recalcAll();
    }

    function clearLineRow(tr) {
        if (pr2ProductPick) {
            pr2ProductPick.clearLine(tr);
        }
        var qEl = tr.querySelector('.pr2-qty');
        if (qEl) {
            qEl.value = '1';
            qEl.removeAttribute('data-max-qty');
            qEl.removeAttribute('title');
        }
        var dEl = tr.querySelector('.pr2-discount');
        if (dEl) dEl.value = '';
    }

    function removeLine(btn) {
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        if (tb.querySelectorAll('tr').length <= 1) {
            var tr = btn.closest('tr');
            clearLineRow(tr);
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
        var pid = parseInt(tr.querySelector('.pr2-product-id').value, 10) || 0;
        if (pid > 0) return false;
        var code = (tr.querySelector('.pr2-code').value || '').trim();
        return code === '';
    }

    function rowIsComplete(tr) {
        var pid = parseInt(tr.querySelector('.pr2-product-id').value, 10) || 0;
        if (pid <= 0) return false;
        var q = parseInt(tr.querySelector('.pr2-qty').value, 10) || 0;
        if (q < 1) return false;
        var costEl = tr.querySelector('.pr2-cost');
        return !!(costEl && String(costEl.value || '').trim() !== '');
    }

    function pr2LineNavFields(tr) {
        return [
            tr.querySelector('.pr2-code'),
            tr.querySelector('.pr2-qty'),
            tr.querySelector('.pr2-cost'),
            tr.querySelector('.pr2-discount'),
            tr.querySelector('.pr2-line-total')
        ].filter(function (el) {
            return el && !el.disabled;
        });
    }

    function pr2FocusLineField(el) {
        if (!el) return;
        el.focus();
        if (typeof el.select === 'function' && el.tagName === 'INPUT' && !el.readOnly) {
            try { el.select(); } catch (err) {}
        }
    }

    function pr2FocusNextInRow(tr, current) {
        var list = pr2LineNavFields(tr);
        var idx = list.indexOf(current);
        if (idx < 0 || idx >= list.length - 1) return false;
        pr2FocusLineField(list[idx + 1]);
        return true;
    }

    function pr2AdvanceFromLineTotal(tr) {
        recalcAll();
        if (!rowIsComplete(tr)) return;
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        var idx = -1;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i] === tr) { idx = i; break; }
        }
        if (idx < 0) return;
        if (idx === rows.length - 1) {
            syncTrailing();
            rows = tb.querySelectorAll('tr');
        }
        var nextTr = rows[idx + 1];
        if (!nextTr) return;
        pr2FocusLineField(nextTr.querySelector('.pr2-code'));
    }

    function pr2OnLineKeydown(e) {
        if (pr2ViewMode) return;
        if (e.key !== 'Enter' && e.key !== 'Tab') return;
        if (e.key === 'Tab' && e.shiftKey) return;
        var ta = e.target;
        if (!ta) return;
        var tr = ta.closest('tr');
        if (!tr || !tr.classList.contains('pr2-line')) return;
        var isNav = ta.classList.contains('pr2-code')
            || ta.classList.contains('pr2-qty')
            || ta.classList.contains('pr2-cost')
            || ta.classList.contains('pr2-discount')
            || ta.classList.contains('pr2-line-total');
        if (!isNav) return;

        if (ta.classList.contains('pr2-code') && e.key === 'Enter') {
            e.preventDefault();
            if (pr2ProductPick) {
                pr2ProductPick.resolveCodeForRow(tr);
            }
            recalcAll();
            pr2FocusLineField(tr.querySelector('.pr2-qty'));
            return;
        }
        if (ta.classList.contains('pr2-line-total')) {
            e.preventDefault();
            pr2AdvanceFromLineTotal(tr);
            return;
        }
        if (ta.classList.contains('pr2-discount')) {
            e.preventDefault();
            recalcAll();
            pr2FocusLineField(tr.querySelector('.pr2-line-total'));
            return;
        }
        e.preventDefault();
        pr2FocusNextInRow(tr, ta);
    }

    function resolveCodeForRow(tr) {
        if (!pr2ProductPick) return;
        pr2ProductPick.resolveCodeForRow(tr);
        recalcAll();
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
        if (rowIsComplete(last)) addLine();
    }

    function pr2FillLineRow(tr, item) {
        var pickRow = pr2ProductPick
            ? pr2ProductPick.findPickRowByIds(item.product_id, item.variant_id || 0)
            : null;
        if (pickRow && pr2ProductPick) {
            pr2ProductPick.applyPick(tr, pickRow);
        } else if (pr2ProductPick) {
            pr2ProductPick.clearLine(tr);
            var pidEl = tr.querySelector('.pr2-product-id');
            var vidEl = tr.querySelector('.pr2-variant-id');
            if (pidEl) pidEl.value = String(item.product_id || '');
            if (vidEl) vidEl.value = String(item.variant_id || '0');
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
        if (dEl) {
            var prRaw = String(item.discount_raw || '').trim();
            if (prRaw.charAt(prRaw.length - 1) === '%') {
                dEl.value = prRaw;
            } else {
                var prAmt = parseFloat(prRaw) || 0;
                dEl.value = prAmt > 0 ? fmt3(prAmt) : '';
            }
        }
    }

    /* ── Recalculate ────────────────────────────────────────────────── */
    function recalcAll() {
        var tb = document.getElementById('pr2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr.pr2-line');
        var grossSubtotal = 0;
        var subtotal = 0;
        var lineDiscTotal = 0;
        var firstDiscError = '';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var q = parseInt(r.querySelector('.pr2-qty').value, 10) || 0;
            var c = parseFloat(r.querySelector('.pr2-cost').value) || 0;
            var lineGross = q * c;
            var discEl = r.querySelector('.pr2-discount');
            var discRaw = (discEl && discEl.value || '').trim();
            var discAmt = parseDiscount(discRaw, lineGross);
            var invalid = (discAmt > 0) && ((lineGross - discAmt) < 0.0005);
            if (discEl) discEl.style.border = invalid ? '1px solid #dc2626' : '';
            if (invalid && !firstDiscError) firstDiscError = 'خصم الصنف في السطر ' + (i + 1) + ' يساوي أو يتجاوز إجمالي الصنف — يجب أن تبقى للصنف قيمة.';
            if (discAmt > lineGross) discAmt = lineGross;
            var lineNet = Math.max(0, lineGross - discAmt);
            var ltEl = r.querySelector('.pr2-line-total');
            if (ltEl) ltEl.value = fmt3(lineNet);
            grossSubtotal += lineGross;
            subtotal += lineNet;
            lineDiscTotal += (lineGross - lineNet);
        }
        var invDiscEl = document.getElementById('pr2_invoice_discount');
        var invDiscRaw = (invDiscEl && invDiscEl.value || '').trim();
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);
        var invInvalid = (invDiscAmt > 0) && ((subtotal - invDiscAmt) < 0.0005);
        if (invDiscEl) invDiscEl.style.border = invInvalid ? '1px solid #dc2626' : '';
        if (invDiscAmt > subtotal) invDiscAmt = subtotal;
        var netTotal = Math.max(0, subtotal - invDiscAmt);
        var totalDiscount = Math.max(0, grossSubtotal - netTotal);
        var setTxt = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = fmt3(v); };
        setTxt('pr2_subtotal', grossSubtotal);
        setTxt('pr2_discount_total', lineDiscTotal);
        setTxt('pr2_net_total', subtotal);
        setTxt('pr2_inv_disc', invDiscAmt);
        setTxt('pr2_grand_total', netTotal);
        var invDiscValEl = document.getElementById('pr2_inv_disc_print_val');
        if (invDiscValEl) invDiscValEl.textContent = fmt3(invDiscAmt);
        var invDiscRow = document.getElementById('pr2_inv_disc_print_row');
        if (invDiscRow) invDiscRow.classList.toggle('pur-inv-disc-active', invDiscAmt > 0.0005);
        var errMsgs = [];
        if (firstDiscError) errMsgs.push(firstDiscError);
        if (invInvalid) errMsgs.push('خصم الفاتورة يجعل مبلغ المردود صفراً — يجب أن يكون للمردود إجمالي.');
        var errEl = document.getElementById('pr2_line_disc_error');
        if (errEl) { errEl.innerHTML = errMsgs.join('<br>'); errEl.style.display = errMsgs.length ? '' : 'none'; }
        pr2FillBannerTotals(grossSubtotal, totalDiscount, netTotal);
    }

    function pr2FillBannerTotals(gross, disc, net) {
        var set = function (id, val) {
            var el = document.getElementById(id);
            if (el) el.textContent = fmt3(val);
        };
        set('pr2_sd_print_gross', gross);
        set('pr2_sd_print_disc', disc);
        set('pr2_sd_print_net', net);
    }


    function parsePurchaseRef(raw) {
        raw = String(raw || '').trim();
        if (!raw) return 0;
        var m = /^PUR-(\d+)$/i.exec(raw);
        if (m) return parseInt(m[1], 10) || 0;
        if (/^\d+$/.test(raw)) return parseInt(raw, 10) || 0;
        return 0;
    }

    function pr2SetDocSerial(value) {
        var el = document.getElementById('pr2_doc_serial');
        if (el) el.value = String(value || '');
    }

    function pr2SyncToolbar() {
        var pb = document.getElementById('pr2_btn_print');
        if (pb) {
            if (PR2_PRINT_TUNING) {
                pb.disabled = false;
                pb.title = 'طباعة (وضع ضبط التنسيق — مؤقت)';
            } else {
                pb.disabled = browseReturnId <= 0;
                pb.title = browseReturnId > 0 ? 'طباعة المردود المعروض' : 'افتح مردوداً محفوظاً للطباعة';
            }
        }
        var sb = document.getElementById('pr2_btn_save');
        if (sb) {
            sb.disabled = !PR2_READY || pr2ViewMode || !PR2_CAPS.can_edit;
            sb.title = pr2ViewMode ? 'وضع العرض — استخدم «مردود جديد» للإدخال' : 'حفظ مردود جديد';
        }
        var lbl = document.getElementById('pr2_browse_label');
        if (lbl) {
            lbl.textContent = browseReturnId > 0 ? ('— عرض ' + (document.getElementById('pr2_doc_serial') && document.getElementById('pr2_doc_serial').value || ('PR-' + browseReturnId))) : '';
        }
        if (browseReturnId <= 0) {
            pr2SetDocSerial(PR2_DOC_SERIAL_PREVIEW || '');
        }
        if (pr2EditLockCtl) pr2EditLockCtl.refresh();
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
        var invRefEl = document.getElementById('pr2_supplier_invoice_ref');
        if (invRefEl) invRefEl.value = p.supplier_invoice_number || '';
        var invDiscEl = document.getElementById('pr2_invoice_discount');
        if (invDiscEl) {
            // عكس خصم الفاتورة المكتسب بالتناسب: نملأ الخانة بنسبة خصم الفاتورة الأصلية (%)
            // حتى يُحسب على صافي الأصناف المردودة (يصحّ مع المردود الجزئي تلقائياً).
            var origSub = parseFloat(String(p.subtotal || '0')) || 0;
            var origAmt = parseFloat(String(p.invoice_discount_amount || '0')) || 0;
            if (origSub > 0 && origAmt > 0.0005) {
                var rate = (origAmt / origSub) * 100;
                invDiscEl.value = (Math.round(rate * 10000) / 10000) + '%';
            } else {
                invDiscEl.value = '';
            }
        }
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

    function pr2FormatEnteredDisplay(raw) {
        var s = String(raw || '').trim();
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
        var d = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (d) return d[3] + '/' + d[2] + '/' + d[1];
        return s;
    }

    function pr2SyncPrintBanner() {
        var setTxt = function (id, val) {
            var el = document.getElementById(id);
            if (!el) return;
            var v = String(val || '').trim();
            el.textContent = v !== '' ? v : '—';
        };
        var serEl = document.getElementById('pr2_doc_serial');
        setTxt('pr2_sd_print_serial', serEl ? serEl.value : '');

        var docDateEl = document.getElementById('pr2_document_date');
        var docDateVal = docDateEl ? String(docDateEl.value || '').trim() : '';
        var dm = docDateVal.match(/^(\d{4})-(\d{2})-(\d{2})/);
        setTxt('pr2_sd_print_docdate', dm ? (dm[3] + '/' + dm[2] + '/' + dm[1]) : docDateVal);

        var refEl = document.getElementById('pr2_purchase_ref');
        setTxt('pr2_sd_print_ref_invoice', refEl ? refEl.value : '');

        var supEl = document.getElementById('pr2_supplier_name');
        setTxt('pr2_sd_print_party_name', supEl ? supEl.value : '');
        var codeEl = document.getElementById('pr2_supplier_code');
        setTxt('pr2_sd_print_party_code', codeEl ? codeEl.value : '');
        var invRefEl = document.getElementById('pr2_supplier_invoice_ref');
        setTxt('pr2_sd_print_party_inv', invRefEl ? invRefEl.value : '');
        var typeSel = document.getElementById('pr2_type');
        var typeTxt = (typeSel && typeSel.selectedIndex >= 0) ? typeSel.options[typeSel.selectedIndex].text : '';
        setTxt('pr2_sd_print_party_type', typeTxt);

        var notesEl = document.getElementById('pr2_notes');
        var notesBox = document.getElementById('pr2_sd_print_notes');
        if (notesBox) notesBox.textContent = notesEl ? String(notesEl.value || '').trim() : '';

        var getTot = function (id) {
            var el = document.getElementById(id);
            return el ? String(el.textContent || '').trim() : '';
        };
        var grossN = parseFloat(getTot('pr2_subtotal').replace(',', '.')) || 0;
        var netN = parseFloat(getTot('pr2_grand_total').replace(',', '.')) || 0;
        setTxt('pr2_sd_print_gross', fmt3(grossN));
        setTxt('pr2_sd_print_disc', fmt3(Math.max(0, grossN - netN)));
        setTxt('pr2_sd_print_net', fmt3(netN));
    }

    function pr2ApplyReturnPayload(res) {
        if (!res || !res.success || !res.purchase_return) {
            alert((res && res.message) || 'تعذر تحميل المردود');
            return;
        }
        var p = res.purchase_return;
        browseReturnId = parseInt(String(p.id || '0'), 10) || 0;
        pr2SetDocSerial(p.return_number || ('PR-' + browseReturnId));
        selectSupplier(parseInt(String(p.supplier_id || '0'), 10) || 0);
        var typeEl = document.getElementById('pr2_type');
        if (typeEl) typeEl.value = p.type || 'cash';
        var notesEl = document.getElementById('pr2_notes');
        if (notesEl) notesEl.value = p.notes || '';
        var docDateEl = document.getElementById('pr2_document_date');
        if (docDateEl) docDateEl.value = (p.document_date ? String(p.document_date).substr(0, 10) : '');
        var entryDateEl = document.getElementById('pr2_entry_date');
        if (entryDateEl && p.created_at) entryDateEl.value = pr2FormatEnteredDisplay(p.created_at);
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
        if (pr2EditLockCtl) pr2EditLockCtl.refresh();
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
        if (!PR2_CAPS.can_edit) {
            alert('لا تملك صلاحية تعديل مردود المشتريات');
            return;
        }
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
            var pid = parseInt(r.querySelector('.pr2-product-id').value, 10) || 0;
            var vid = parseInt(r.querySelector('.pr2-variant-id').value, 10) || 0;
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
            if (discAmt > 0 && (lineGross - discAmt) < 0.0005) {
                alert('خصم الصنف في السطر ' + (i + 1) + ' يساوي أو يتجاوز إجمالي الصنف — يجب أن تبقى للصنف قيمة. صحّح الخصم قبل الحفظ.');
                return;
            }
            items.push({ product_id: pid, variant_id: vid, qty: q, cost: c, discount_raw: discRaw, discount_amount: discAmt });
        }
        if (!items.length) {
            alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة');
            return;
        }

        var invDiscRaw = (document.getElementById('pr2_invoice_discount').value || '').trim();
        var subtotal = 0;
        for (var si = 0; si < items.length; si++) {
            subtotal += Math.max(0, (items[si].qty * items[si].cost) - items[si].discount_amount);
        }
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);
        if (invDiscAmt > 0 && (subtotal - invDiscAmt) < 0.0005) {
            alert('خصم الفاتورة يجعل مبلغ المردود صفراً — يجب أن يكون للمردود إجمالي. صحّح الخصم قبل الحفظ.');
            return;
        }

        var payload = {
            supplier_id: supplierId,
            type: retType,
            notes: notes,
            document_date: (document.getElementById('pr2_document_date') ? (document.getElementById('pr2_document_date').value || '') : ''),
            purchase_id: purchaseId > 0 ? purchaseId : 0,
            items: items,
            invoice_discount_raw: invDiscRaw,
            invoice_discount_amount: invDiscAmt
        };

        postJSON('/admin/api/purchase_returns/create.php', payload).then(function (res) {
            if (res.success) {
                var pr2SavedId = (res && res.purchase_return_id) ? (parseInt(String(res.purchase_return_id), 10) || 0) : (browseReturnId || 0);
                var pr2AfterSave = function () {
                    if (pr2SavedId > 0) { pr2LoadReturn(pr2SavedId); } else { location.reload(); }
                };
                if (typeof orangeAdminOfferOpenGlVoucherAfterSave === 'function') {
                    orangeAdminOfferOpenGlVoucherAfterSave(res, pr2AfterSave);
                } else {
                    alert(res.message || 'تم حفظ مردود المشتريات');
                    pr2AfterSave();
                }
                return;
            }
            if (typeof orangeAdminOfferSuggestOnFailure === 'function' && orangeAdminOfferSuggestOnFailure(res, 'فشل')) return;
            alert(res.message || 'فشل');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    /* ── Init & bindings ────────────────────────────────────────────── */
    function init() {
        if (window.OrangePurchaseDocProductPick) {
            pr2ProductPick = window.OrangePurchaseDocProductPick.create({
                pickRows: PR2_PICK_ROWS,
                codeClass: 'pr2-code',
                fmtMoney: fmt3,
                isViewMode: function () { return pr2ViewMode; },
                modalIds: {
                    root: 'pr2_product_pick_modal',
                    backdrop: 'pr2_product_pick_backdrop',
                    filter: 'pr2_product_pick_filter',
                    body: 'pr2_product_pick_body'
                },
                selectors: {
                    code: '.pr2-code',
                    productId: '.pr2-product-id',
                    variantId: '.pr2-variant-id',
                    name: '.pr2-name',
                    varLabel: '.pr2-var-label',
                    cost: '.pr2-cost'
                },
                onAfterResolve: function (tr) {
                    recalcAll();
                }
            });
            pr2ProductPick.bindModal();
        }

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
        // زر «مردود جديد» مربوط عبر onclick مباشر حتى يعمل حتى لو فشل ربط addEventListener.
        document.getElementById('pr2_btn_retrieve').addEventListener('click', pr2RetrieveFromPurchase);
        document.getElementById('pr2_invoice_discount').addEventListener('input', function () { recalcAll(); });
        document.getElementById('pr2_btn_print').addEventListener('click', function () {
            if (!PR2_PRINT_TUNING && browseReturnId <= 0) {
                alert('افتح مردوداً محفوظاً للطباعة.');
                return;
            }
            pr2SyncPrintBanner();
            var pr2SerEl = document.getElementById('pr2_doc_serial');
            var pr2Ser = pr2SerEl ? String(pr2SerEl.value || '').trim() : '';
            var pr2Title = pr2Ser !== '' ? ('مردود مشتريات رقم ' + pr2Ser) : 'مردود مشتريات';
            if (typeof window.orangeAdminOpenPrintDialog === 'function') {
                window.orangeAdminOpenPrintDialog(pr2Title);
            } else {
                window.print();
            }
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
            if (pr2ProductPick) {
                pr2ProductPick.bindLinesBody(tb);
            }
            tb.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('pr2-code')) return;
                if (e.target && e.target.classList.contains('pr2-qty')) {
                    pr2ClampQtyInput(e.target);
                }
                recalcAll();
            });
            tb.addEventListener('keydown', pr2OnLineKeydown);
            tb.addEventListener('focusout', function (e) {
                if (e.target && e.target.classList.contains('pr2-code')) {
                    resolveCodeForRow(e.target.closest('tr'));
                }
            });
            tb.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('admin-doc-line-remove')) {
                    removeLine(e.target);
                }
            });

            addLine();
        }

        if (PR2_PREFILL_SUPPLIER > 0) {
            selectSupplier(PR2_PREFILL_SUPPLIER);
        }

        if (window.OrangeEditLock) {
            pr2EditLockCtl = OrangeEditLock.bind({
                prefix: 'pr2',
                docKind: 'purchase_return',
                page: 'purchase_returns',
                canLock: !!PR2_CAPS.can_lock,
                canUnlock: !!PR2_CAPS.can_unlock,
                countryId: PR2_COUNTRY_ID,
                getEntityId: function () { return browseReturnId; }
            });
        }

        pr2SyncToolbar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
