<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

require_once __DIR__ . '/../../includes/purchase_doc_product_pick.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';
require_once __DIR__ . '/../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/admin_voucher_print_tuning.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';

$pdo = orange_admin_page_pdo();
$pv2Caps = orange_admin_caps_for_page($admin, $pdo, 'purchases');

$adminCountryId = orange_admin_context_country_id($pdo);
$adminDefaultCurrency = orange_admin_context_currency_code($pdo);
$adminCurrencyUnit = orange_currency_display_unit($adminDefaultCurrency);
$purchasesProductsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $adminCountryId);
$purchasesSuppliersCountrySql = orange_sql_country_and_fragment($pdo, 'suppliers', 'suppliers', $adminCountryId);

$pv2PickRows = orange_purchase_doc_product_pick_rows($pdo, $adminCountryId);

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
        try {
            $aid = orange_supplier_payable_account_id($pdo, $sid);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] purchases supplier #' . $sid . ': ' . $e->getMessage());
            }
            $aid = 0;
        }
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

$prefillSupplierId = 0;
$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);
$prefillStmtKind = trim((string) ($_GET['stmt_party_kind'] ?? ''));
$prefillSupplierDirect = (int) ($_GET['supplier_id'] ?? 0);
if ($prefillStmtKind === 'supplier' && $prefillStmtId > 0) {
    $prefillSupplierId = $prefillStmtId;
} elseif ($prefillSupplierDirect > 0) {
    $prefillSupplierId = $prefillSupplierDirect;
}

$pv2Ready = ($inventoryAccId !== null && $inventoryAccId > 0 && $cashAccId !== null && $cashAccId > 0);
$pv2NavReady = orange_table_exists($pdo, 'purchases');
$pv2DocSerialPreview = $pv2NavReady
    ? orange_country_document_next_ref_preview($pdo, 'purchases', 'PUR', $adminCountryId)
    : '';
$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
$otherVouchersUrl = storefront_public_path('/admin/index.php?page=other_vouchers');
$pv2PurchaseLineKinds = [];
foreach (orange_invoice_ancillary_purchase_line_kind_catalog() as $kindKey => $kindMeta) {
    $pv2PurchaseLineKinds[] = [
        'key' => $kindKey,
        'label_ar' => (string) ($kindMeta['label_ar'] ?? $kindKey),
    ];
}
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
/* صف 2 — رقم فاتورة المورد · تاريخ الفاتورة · نوع الشراء · تاريخ الإدخال · ملاحظات
   (تاريخ الفاتورة = تاريخ الإدخال = نوع الشراء عرضاً؛ الفائض لملاحظات) */
.form-grid.pv2-header-row2 {
    grid-template-columns: minmax(7rem, 0.85fr) minmax(5.5rem, 0.65fr) minmax(5.5rem, 0.65fr) minmax(5.5rem, 0.65fr) minmax(0, 1.9fr);
}
.form-grid.pv2-supplier-row {
    grid-template-columns: minmax(7rem, 0.75fr) minmax(0, 1fr) minmax(0, 2fr);
}
.pv2-extra-source-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
}
.pv2-extra-source-tabs button {
    flex: 1 1 auto;
}
.pv2-extra-source-tabs button.is-active {
    font-weight: 700;
    border-color: #2563eb;
}
.pv2-extra-line-kind-row {
    margin: 10px 0;
}
.gl-pick-item.is-selected {
    background: #eff6ff;
    outline: 1px solid #2563eb;
}
@media print {
    .pv2-extra-skip-print {
        display: none !important;
    }
    .jv-print-area .pv2-extra-lines-table thead th.admin-doc-col-actions,
    .jv-print-area .pv2-extra-lines-table tr.pv2-extra-line td:last-child {
        display: none !important;
    }

    /* ===== شبكة جدول البنود (مطابقة فاتورة مبيعات الشركة) ===== */
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
    <h1>فاتورة شراء</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$pv2Ready): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">اربط حساب <strong>المخزون</strong> و<strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
</div>
<?php endif; ?>

<div class="card jv-print-area">
    <?php
    orange_sales_doc_print_banner([
        'prefix' => 'pv2',
        'doc_title' => 'فاتورة شراء',
        'doc_title_en' => 'Purchase Invoice',
        'country_id' => $adminCountryId,
        'currency_code' => $adminDefaultCurrency,
        'serial_label' => 'مسلسل الشراء / Purchase No.',
        'show_doc_date' => true,
        'show_print_date' => false,
        'show_qr' => false,
        'show_party' => true,
        'party_title' => 'المورد / Supplier',
        'party_rows' => [
            ['اسم المورد / Name', 'party_name', ''],
            ['كود المورد / Code', 'party_code', 'ltr'],
            ['رقم فاتورة المورد / Supplier Inv. No.', 'party_inv', 'ltr'],
            ['نوع الشراء / Type', 'party_type', ''],
        ],
        'show_notes' => true,
        'totals_rows' => [
            ['إجمالي الفاتورة / Total', 'total'],
            ['قيمة الخصم / Discount', 'disc'],
            ['مبلغ الفاتورة / Net', 'net'],
        ],
    ]);
    ?>
    <h3 class="card-title">فاتورة شراء <span id="pv2_browse_label" class="muted" style="font-size:0.85rem;font-weight:500;"></span></h3>
    <?php orange_edit_lock_ui_toolbar(['prefix' => 'pv2', 'doc_kind' => 'purchase', 'country_id' => $adminCountryId, 'show_status_badge' => false]); ?>

    <!-- ١ — مسلسل الفاتورة + المورد -->
    <div class="form-grid pv2-supplier-row orange-doc-header-row jv-print-hide" style="margin-bottom:12px;">
        <div>
            <label for="pv2_doc_serial">مسلسل الفاتورة</label>
            <input type="text" id="pv2_doc_serial" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en" style="background:#f4f4f5;cursor:default;"
                value="<?php echo htmlspecialchars($pv2DocSerialPreview, ENT_QUOTES, 'UTF-8'); ?>"
                title="يُخصَّص تلقائياً من النظام عند الحفظ">
        </div>
        <div>
            <label for="pv2_supplier_code">كود المورد</label>
            <input type="text" id="pv2_supplier_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="pv2_supplier_name">اسم المورد</label>
            <input type="text" id="pv2_supplier_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
        </div>
        <input type="hidden" id="pv2_supplier_id" value="0">
    </div>

    <!-- ٢ — رقم فاتورة المورد، تاريخ الفاتورة، نوع الشراء، تاريخ الإدخال، ملاحظات -->
    <div class="form-grid pv2-header-row2 orange-doc-header-row jv-print-hide" style="margin-bottom:16px;">
        <div>
            <label for="pv2_supplier_invoice">رقم فاتورة المورد</label>
            <input type="text" id="pv2_supplier_invoice" placeholder="رقم فاتورة المورد" dir="ltr" lang="en" autocomplete="off" maxlength="64"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="pv2_document_date">تاريخ الفاتورة</label>
            <input type="date" id="pv2_document_date" dir="ltr" lang="en" title="تاريخ الفاتورة = تاريخ ترحيل القيد المحاسبي" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
        <div>
            <label for="pv2_type">نوع الشراء</label>
            <select id="pv2_type"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
                <option value="cash">نقدي</option>
                <option value="credit">آجل</option>
            </select>
        </div>
        <div>
            <label for="pv2_entry_date">تاريخ الإدخال</label>
            <input type="text" id="pv2_entry_date" class="admin-inp-readonly" readonly tabindex="-1" dir="ltr" lang="en" style="background:#f4f4f5;cursor:default;"
                value="<?php echo htmlspecialchars(orange_format_datetime_dmY_hi(date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8'); ?>"
                title="وقت تسجيل إدخال المستند في النظام — يُثبت عند الحفظ ولا يُقبل من المتصفح">
        </div>
        <div>
            <label for="pv2_notes">ملاحظات</label>
            <input type="text" id="pv2_notes" placeholder="شروط، ملاحظات إضافية، …"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
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
                        <th style="min-width:8rem;">كود<span class="sd-print-hide"> / باركود</span></th>
                        <th style="min-width:10rem;">اسم الصنف</th>
                        <th style="min-width:8rem;">اللون / المقاس</th>
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

    <!-- بنود إضافية (GL مركّب) -->
    <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:18px 0 10px;">بنود إضافية</h4>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table pv2-extra-lines-table">
                <thead>
                    <tr>
                        <th class="pur-col-idx" style="width:2.5rem;">#</th>
                        <th style="min-width:12rem;">الحساب / البند</th>
                        <th style="width:7rem;">المبلغ</th>
                        <th style="width:6rem;">يظهر بالطباعة</th>
                        <th style="min-width:8rem;">تسمية طباعة</th>
                        <th class="admin-doc-col-actions jv-print-hide" aria-label="حذف" style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody id="pv2_extra_lines_body"></tbody>
            </table>
        </div>
    </div>
    <div class="actions jv-print-hide" style="margin-top:10px;">
        <button type="button" class="btn-secondary" id="pv2_btn_add_extra"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>إضافة بند</button>
    </div>

    <!-- ٣ — خصم الفاتورة + المجاميع -->
    <div class="jv-print-hide" style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 24px;">
        <div style="flex:0 0 auto;" class="jv-print-hide">
            <label for="pv2_invoice_discount" style="font-size:0.82rem;font-weight:600;">خصم الفاتورة</label>
            <input type="text" id="pv2_invoice_discount" placeholder="0 أو 5%" dir="ltr" lang="en" style="width:8rem;" autocomplete="off"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>
        </div>
        <div style="flex:1 1 auto;text-align:left;direction:ltr;font-size:0.95rem;line-height:1.8;">
            <span style="color:#64748b;">إجمالي الفاتورة:</span> <strong id="pv2_subtotal" class="admin-money-display" dir="ltr" lang="en"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">قيمة الخصم:</span> <strong id="pv2_discount_total" class="admin-money-display" dir="ltr" lang="en" style="color:#b91c1c;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">مبلغ الفاتورة:</span> <strong id="pv2_net_total" class="admin-money-display" dir="ltr" lang="en" style="color:#059669;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
    </div>

    <!-- ٤ — أزرار -->
    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:16px;">
        <span></span>
        <div class="jv-toolbar-primary-group">
            <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين فواتير الشراء">
                <button type="button" class="btn-secondary jv-nav-btn" id="pv2_nav_first" title="أول فاتورة" aria-label="أول فاتورة">&lt;&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="pv2_nav_prev" title="الفاتورة السابقة" aria-label="الفاتورة السابقة">&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="pv2_nav_next" title="الفاتورة التالية" aria-label="الفاتورة التالية">&gt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="pv2_nav_last" title="آخر فاتورة" aria-label="آخر فاتورة">&gt;&gt;</button>
                <button type="button" class="btn-secondary jv-nav-search" id="pv2_btn_search" title="بحث عن فاتورة">بحث</button>
            </div>
            <button type="button" class="btn-secondary" id="pv2_btn_print" title="طباعة الفاتورة المعروضة"<?php echo orange_admin_invoice_print_tuning_mode() ? '' : ' disabled'; ?>>طباعة</button>
            <button type="button" class="btn-secondary" id="pv2_btn_new" title="فاتورة جديدة" data-orange-perm="edit" data-orange-page="purchases" onclick="if (confirm('بدء فاتورة جديدة؟ سيتم مسح أي بيانات غير محفوظة على الشاشة.')) { location.reload(); } return false;">فاتورة جديدة</button>
            <button type="button" id="pv2_btn_save" data-orange-perm="edit" data-orange-page="purchases"<?php echo !$pv2Ready ? ' disabled' : ''; ?>>حفظ</button>
        </div>
    </div>
</div>

<!-- Ancillary account picker -->
<div class="gl-pick-modal" id="pv2_extra_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="pv2_extra_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="pv2_extra_pick_title">
        <h3 id="pv2_extra_pick_title" class="gl-pick-modal__title">اختيار حساب — بند إضافي</h3>
        <div class="pv2-extra-source-tabs" role="tablist">
            <button type="button" class="btn-secondary is-active" id="pv2_extra_tab_presets" data-source="presets">القائمة المحفوظة</button>
            <button type="button" class="btn-secondary" id="pv2_extra_tab_coa" data-source="coa">الدليل المحاسبي</button>
        </div>
        <div class="pv2-extra-line-kind-row" id="pv2_extra_line_kind_wrap" hidden>
            <label for="pv2_extra_line_kind">نوع البند</label>
            <select id="pv2_extra_line_kind" class="admin-inp">
                <?php foreach ($pv2PurchaseLineKinds as $lk): ?>
                <option value="<?php echo htmlspecialchars((string) $lk['key'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $lk['label_ar'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="search" id="pv2_extra_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="pv2_extra_pick_list"></ul>
        <div class="actions" style="margin-top:10px;flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn-secondary" id="pv2_extra_add_to_presets" hidden>أضف إلى القائمة</button>
            <button type="button" class="btn-secondary" id="pv2_extra_pick_close">إغلاق</button>
        </div>
    </div>
</div>

<!-- Supplier Picker Modal -->
<div class="gl-pick-modal" id="pv2_supplier_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="pv2_supplier_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="pv2_supplier_pick_title">
        <h3 id="pv2_supplier_pick_title" class="gl-pick-modal__title">اختيار المورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="pv2_supplier_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بكود الحساب أو اسم المورد…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="pv2_supplier_pick_list"></ul>
        <button type="button" class="btn-secondary" id="pv2_supplier_pick_close">إغلاق</button>
    </div>
</div>

<!-- Product pick modal -->
<div id="pv2_product_pick_modal" class="mo-pick-modal" hidden>
    <div class="mo-pick-modal__backdrop" id="pv2_product_pick_backdrop"></div>
    <div class="mo-pick-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="pv2_product_pick_title">
        <h4 id="pv2_product_pick_title" class="mo-pick-modal__title">اختيار صنف</h4>
        <input type="search" id="pv2_product_pick_filter" class="admin-inp mo-pick-modal__search" placeholder="ابحث بالكود أو الاسم أو اللون أو المقاس…" autocomplete="off" lang="ar" dir="rtl">
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
                <tbody id="pv2_product_pick_body"></tbody>
            </table>
        </div>
        <p class="card-hint mo-pick-modal__hint">انقر نقراً مزدوجاً على السطر للاختيار — أو امسح الباركود في خانة الكود.</p>
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
                        <label for="pv2_search_id_from">رقم الفاتورة — من</label>
                        <input type="number" id="pv2_search_id_from" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="pv2_search_id_to">رقم الفاتورة — إلى</label>
                        <input type="number" id="pv2_search_id_to" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="pv2_search_date_from">التاريخ — من</label>
                        <input type="text" id="pv2_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="pv2_search_date_to">التاريخ — إلى</label>
                        <input type="text" id="pv2_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="pv2_search_ref">المرجع PUR- (يحتوي النص)</label>
                        <input type="text" id="pv2_search_ref" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="pv2_search_supplier_inv">رقم فاتورة المورد (يحتوي النص)</label>
                        <input type="text" id="pv2_search_supplier_inv" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="pv2_search_notes">ملاحظات (يحتوي النص)</label>
                        <input type="text" id="pv2_search_notes" class="admin-inp" autocomplete="off" dir="auto">
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
                                <th>تاريخ</th>
                                <th>مرجع</th>
                                <th>مورد</th>
                                <th>رقم ف. المورد</th>
                                <th>صافي</th>
                            </tr>
                        </thead>
                        <tbody id="pv2_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_purchase_doc_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var PV2_PICK_ROWS = <?php echo json_encode($pv2PickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_SUPPLIER_PICK_ROWS = <?php echo json_encode($pv2SupplierPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_PREFILL_SUPPLIER = <?php echo (int) $prefillSupplierId; ?>;
    var PV2_READY = <?php echo $pv2Ready ? 'true' : 'false'; ?>;
    var PV2_PRINT_TUNING = <?php echo orange_admin_invoice_print_tuning_mode() ? 'true' : 'false'; ?>;
    var PV2_NAV_READY = <?php echo $pv2NavReady ? 'true' : 'false'; ?>;
    var PV2_COUNTRY_ID = <?php echo (int) $adminCountryId; ?>;
    var PV2_CAPS = <?php echo json_encode($pv2Caps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var pv2EditLockCtl = null;
    var PV2_DOC_SERIAL_PREVIEW = <?php echo json_encode($pv2DocSerialPreview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var PV2_PURCHASE_LINE_KINDS = <?php echo json_encode($pv2PurchaseLineKinds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

    var currentSupplierId = 0;
    var browsePurchaseId = 0;
    var pv2ViewMode = false;
    var pv2ProductPick = null;
    var pv2ExtraPickSource = 'presets';
    var pv2ExtraPickSelected = null;

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
            li.textContent = (r.account_code ? r.account_code + ' — ' : '') + r.name + (r.phone ? ' (' + r.phone + ')' : '') + ' [رصيد ' + orangeFmtMoney(r.balance) + ']';
            li.addEventListener('dblclick', function () { selectSupplier(r.id); pickerClose(); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { selectSupplier(r.id); pickerClose(); } });
            listEl.appendChild(li);
        });
    }

    /* ── Dynamic rows ───────────────────────────────────────────────── */
    function lineRowHtml() {
        return '<td class="pur-col-idx"></td>' +
            '<td><input type="text" class="pv2-code admin-inp" placeholder="كود أو باركود" dir="ltr" lang="en" autocomplete="off" style="width:100%;" title="امسح الباركود أو دبل كليك للبحث">' +
            '<input type="hidden" class="pv2-product-id" value="">' +
            '<input type="hidden" class="pv2-variant-id" value="0"></td>' +
            '<td><input type="text" class="pv2-name admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>' +
            '<td><input type="text" class="pv2-var-label admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>' +
            '<td><input type="number" class="pv2-qty admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
            '<td><input type="number" class="pv2-cost admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>' +
            '<td><input type="text" class="pv2-discount admin-inp admin-inp-discount" value="' + fmtZero() + '" placeholder="0" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><input type="text" class="pv2-line-total admin-inp-money" value="' + fmtZero() + '" readonly data-money-allow-zero tabindex="0" dir="ltr" lang="en"></td>' +
            '<td><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
    }

    function addLine() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'pv2-line';
        tr.innerHTML = lineRowHtml();
        tb.appendChild(tr);
        renumberRows();
        recalcAll();
    }

    function clearLineRow(tr) {
        if (pv2ProductPick) {
            pv2ProductPick.clearLine(tr);
        }
        var qEl = tr.querySelector('.pv2-qty');
        if (qEl) qEl.value = '1';
        var dEl = tr.querySelector('.pv2-discount');
        if (dEl) dEl.value = '';
    }

    function removeLine(btn) {
        var tb = document.getElementById('pv2_lines_body');
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
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        }
    }

    function rowIsBlank(tr) {
        var pid = parseInt(tr.querySelector('.pv2-product-id').value, 10) || 0;
        if (pid > 0) return false;
        var code = (tr.querySelector('.pv2-code').value || '').trim();
        return code === '';
    }

    function rowIsComplete(tr) {
        var pid = parseInt(tr.querySelector('.pv2-product-id').value, 10) || 0;
        if (pid <= 0) return false;
        var q = parseInt(tr.querySelector('.pv2-qty').value, 10) || 0;
        if (q < 1) return false;
        var costEl = tr.querySelector('.pv2-cost');
        return !!(costEl && String(costEl.value || '').trim() !== '');
    }

    function pv2LineNavFields(tr) {
        return [
            tr.querySelector('.pv2-code'),
            tr.querySelector('.pv2-qty'),
            tr.querySelector('.pv2-cost'),
            tr.querySelector('.pv2-discount'),
            tr.querySelector('.pv2-line-total')
        ].filter(function (el) {
            return el && !el.disabled;
        });
    }

    function pv2FocusLineField(el) {
        if (!el) return;
        el.focus();
        if (typeof el.select === 'function' && el.tagName === 'INPUT' && !el.readOnly) {
            try { el.select(); } catch (err) {}
        }
    }

    function pv2FocusNextInRow(tr, current) {
        var list = pv2LineNavFields(tr);
        var idx = list.indexOf(current);
        if (idx < 0 || idx >= list.length - 1) return false;
        pv2FocusLineField(list[idx + 1]);
        return true;
    }

    function pv2AdvanceFromLineTotal(tr) {
        recalcAll();
        if (!rowIsComplete(tr)) return;
        var tb = document.getElementById('pv2_lines_body');
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
        pv2FocusLineField(nextTr.querySelector('.pv2-code'));
    }

    function pv2OnLineKeydown(e) {
        if (pv2ViewMode) return;
        if (e.key !== 'Enter' && e.key !== 'Tab') return;
        if (e.key === 'Tab' && e.shiftKey) return;
        var ta = e.target;
        if (!ta) return;
        var tr = ta.closest('tr');
        if (!tr || !tr.classList.contains('pv2-line')) return;
        var isNav = ta.classList.contains('pv2-code')
            || ta.classList.contains('pv2-qty')
            || ta.classList.contains('pv2-cost')
            || ta.classList.contains('pv2-discount')
            || ta.classList.contains('pv2-line-total');
        if (!isNav) return;

        if (ta.classList.contains('pv2-code') && e.key === 'Enter') {
            e.preventDefault();
            if (pv2ProductPick) {
                pv2ProductPick.resolveCodeForRow(tr);
            }
            recalcAll();
            pv2FocusLineField(tr.querySelector('.pv2-qty'));
            return;
        }
        if (ta.classList.contains('pv2-line-total')) {
            e.preventDefault();
            pv2AdvanceFromLineTotal(tr);
            return;
        }
        if (ta.classList.contains('pv2-discount')) {
            e.preventDefault();
            recalcAll();
            pv2FocusLineField(tr.querySelector('.pv2-line-total'));
            return;
        }
        e.preventDefault();
        pv2FocusNextInRow(tr, ta);
    }

    function resolveCodeForRow(tr) {
        if (!pv2ProductPick) return;
        pv2ProductPick.resolveCodeForRow(tr);
        recalcAll();
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
        if (rowIsComplete(last)) addLine();
    }

    function pv2FillLineRow(tr, item) {
        var pickRow = pv2ProductPick
            ? pv2ProductPick.findPickRowByIds(item.product_id, item.variant_id || 0)
            : null;
        if (pickRow && pv2ProductPick) {
            pv2ProductPick.applyPick(tr, pickRow);
        } else if (pv2ProductPick) {
            pv2ProductPick.clearLine(tr);
            var pidEl = tr.querySelector('.pv2-product-id');
            var vidEl = tr.querySelector('.pv2-variant-id');
            if (pidEl) pidEl.value = String(item.product_id || '');
            if (vidEl) vidEl.value = String(item.variant_id || '0');
        }
        var qEl = tr.querySelector('.pv2-qty');
        if (qEl) qEl.value = String(item.qty || 1);
        var cEl = tr.querySelector('.pv2-cost');
        if (cEl) cEl.value = fmt3(item.cost || 0);
        var dEl = tr.querySelector('.pv2-discount');
        if (dEl) {
            var pvRaw = String(item.discount_raw || '').trim();
            dEl.value = (pvRaw.charAt(pvRaw.length - 1) === '%') ? pvRaw : fmt3(parseFloat(pvRaw) || 0);
        }
    }
    function recalcAll() {
        var tb = document.getElementById('pv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr.pv2-line');
        var grossSubtotal = 0;
        var subtotal = 0;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var q = parseInt(r.querySelector('.pv2-qty').value, 10) || 0;
            var c = parseFloat(r.querySelector('.pv2-cost').value) || 0;
            var lineGross = q * c;
            var discRaw = (r.querySelector('.pv2-discount').value || '').trim();
            var discAmt = parseDiscount(discRaw, lineGross);
            if (discAmt > lineGross) discAmt = lineGross;
            var lineNet = Math.max(0, lineGross - discAmt);
            var ltEl = r.querySelector('.pv2-line-total');
            if (ltEl) ltEl.value = fmt3(lineNet);
            grossSubtotal += lineGross;
            subtotal += lineNet;
        }
        var invDiscRaw = (document.getElementById('pv2_invoice_discount').value || '').trim();
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);
        var netTotal = Math.max(0, subtotal - invDiscAmt);
        var totalDiscount = Math.max(0, grossSubtotal - netTotal);
        var stEl = document.getElementById('pv2_subtotal');
        var dtEl = document.getElementById('pv2_discount_total');
        var ntEl = document.getElementById('pv2_net_total');
        if (stEl) stEl.textContent = fmt3(grossSubtotal);
        if (dtEl) dtEl.textContent = fmt3(totalDiscount);
        if (ntEl) ntEl.textContent = fmt3(netTotal);
    }

    /* ── Extra invoice lines ─────────────────────────────────────── */
    function pv2ExtraLineKindLabel(key) {
        key = String(key || '');
        for (var i = 0; i < PV2_PURCHASE_LINE_KINDS.length; i++) {
            if (PV2_PURCHASE_LINE_KINDS[i].key === key) return PV2_PURCHASE_LINE_KINDS[i].label_ar || key;
        }
        return key;
    }

    function pv2ExtraAccountLabel(row) {
        var code = String(row.account_code || '').trim();
        var name = String(row.account_name || row.label_ar || '').trim();
        if (code && name) return code + ' — ' + name;
        return name || code || ('#' + (row.account_id || ''));
    }

    function pv2ExtraLineRowHtml() {
        return '<td class="pur-col-idx"></td>'
            + '<td><span class="pv2-extra-account-label"></span>'
            + '<input type="hidden" class="pv2-extra-account-id" value="">'
            + '<input type="hidden" class="pv2-extra-line-kind" value="">'
            + '<input type="hidden" class="pv2-extra-preset-id" value="0"></td>'
            + '<td><input type="number" class="pv2-extra-amount admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>'
            + '<td style="text-align:center;"><input type="checkbox" class="pv2-extra-print" title="يظهر في الفاتورة المطبوعة"></td>'
            + '<td><input type="text" class="pv2-extra-label admin-inp" placeholder="اختياري" dir="auto"></td>'
            + '<td class="jv-print-hide"><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
    }

    function pv2RenumberExtraRows() {
        var tb = document.getElementById('pv2_extra_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr.pv2-extra-line');
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        }
    }

    function pv2SyncExtraPrintClass(tr) {
        if (!tr) return;
        var show = tr.querySelector('.pv2-extra-print');
        if (show && show.checked) {
            tr.classList.remove('pv2-extra-skip-print');
        } else {
            tr.classList.add('pv2-extra-skip-print');
        }
    }

    function pv2FillExtraLineRow(tr, row) {
        if (!tr || !row) return;
        var aid = parseInt(String(row.account_id || '0'), 10) || 0;
        tr.querySelector('.pv2-extra-account-id').value = String(aid);
        tr.querySelector('.pv2-extra-line-kind').value = String(row.line_kind || '');
        tr.querySelector('.pv2-extra-preset-id').value = String(parseInt(String(row.preset_id || '0'), 10) || 0);
        var lbl = tr.querySelector('.pv2-extra-account-label');
        if (lbl) lbl.textContent = pv2ExtraAccountLabel(row);
        var amt = tr.querySelector('.pv2-extra-amount');
        if (amt) amt.value = fmt3(row.amount || 0);
        var pr = tr.querySelector('.pv2-extra-print');
        if (pr) pr.checked = !!row.show_on_print;
        var la = tr.querySelector('.pv2-extra-label');
        if (la) la.value = row.label_ar || '';
        pv2SyncExtraPrintClass(tr);
    }

    function pv2AddExtraLine(row) {
        var tb = document.getElementById('pv2_extra_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'pv2-extra-line pv2-extra-skip-print';
        tr.innerHTML = pv2ExtraLineRowHtml();
        tb.appendChild(tr);
        if (row) pv2FillExtraLineRow(tr, row);
        pv2RenumberExtraRows();
    }

    function pv2ClearExtraLines() {
        var tb = document.getElementById('pv2_extra_lines_body');
        if (tb) tb.innerHTML = '';
    }

    function pv2LoadExtraLines(lines) {
        pv2ClearExtraLines();
        (lines || []).forEach(function (row) { pv2AddExtraLine(row); });
    }

    function pv2CollectExtraLines() {
        var out = [];
        var tb = document.getElementById('pv2_extra_lines_body');
        if (!tb) return out;
        tb.querySelectorAll('tr.pv2-extra-line').forEach(function (tr) {
            var accountId = parseInt(tr.querySelector('.pv2-extra-account-id').value, 10) || 0;
            var lineKind = (tr.querySelector('.pv2-extra-line-kind').value || '').trim();
            var amount = parseFloat(tr.querySelector('.pv2-extra-amount').value) || 0;
            if (accountId <= 0 || !lineKind || amount <= 0) return;
            out.push({
                account_id: accountId,
                line_kind: lineKind,
                amount: amount,
                label_ar: (tr.querySelector('.pv2-extra-label').value || '').trim(),
                show_on_print: tr.querySelector('.pv2-extra-print').checked ? 1 : 0,
                preset_id: parseInt(tr.querySelector('.pv2-extra-preset-id').value, 10) || 0
            });
        });
        return out;
    }

    function pv2ExtraPickSetSource(source) {
        pv2ExtraPickSource = source === 'coa' ? 'coa' : 'presets';
        pv2ExtraPickSelected = null;
        var tabP = document.getElementById('pv2_extra_tab_presets');
        var tabC = document.getElementById('pv2_extra_tab_coa');
        if (tabP) tabP.classList.toggle('is-active', pv2ExtraPickSource === 'presets');
        if (tabC) tabC.classList.toggle('is-active', pv2ExtraPickSource === 'coa');
        var kindWrap = document.getElementById('pv2_extra_line_kind_wrap');
        var addPresets = document.getElementById('pv2_extra_add_to_presets');
        if (kindWrap) kindWrap.hidden = pv2ExtraPickSource !== 'coa';
        if (addPresets) addPresets.hidden = pv2ExtraPickSource !== 'coa';
        pv2ExtraPickRender(document.getElementById('pv2_extra_pick_q').value || '');
    }

    function pv2ExtraPickOpen() {
        if (pv2ViewMode) return;
        var modal = document.getElementById('pv2_extra_pick_modal');
        var qEl = document.getElementById('pv2_extra_pick_q');
        if (!modal || !qEl) return;
        pv2ExtraPickSetSource('presets');
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        pv2ExtraPickRender('');
        qEl.focus();
    }

    function pv2ExtraPickClose() {
        var modal = document.getElementById('pv2_extra_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
        pv2ExtraPickSelected = null;
    }

    function pv2ExtraPickConfirm(row) {
        if (!row) return;
        if (pv2ExtraPickSource === 'coa') {
            var kindEl = document.getElementById('pv2_extra_line_kind');
            var lineKind = kindEl ? (kindEl.value || '').trim() : '';
            if (!lineKind) {
                alert('اختر نوع البند.');
                return;
            }
            pv2AddExtraLine({
                account_id: row.account_id || row.id,
                account_code: row.account_code || row.code || '',
                account_name: row.account_name || row.name || '',
                line_kind: lineKind,
                amount: 0,
                show_on_print: false,
                label_ar: row.name || row.account_name || '',
                preset_id: 0
            });
        } else {
            pv2AddExtraLine({
                account_id: row.account_id,
                account_code: row.account_code,
                account_name: row.account_name,
                line_kind: row.line_kind,
                amount: 0,
                show_on_print: !!row.default_show_on_print,
                label_ar: row.label_ar || row.account_name || '',
                preset_id: row.id || 0
            });
        }
        pv2ExtraPickClose();
    }

    function pv2ExtraPickRender(q) {
        var listEl = document.getElementById('pv2_extra_pick_list');
        if (!listEl) return;
        listEl.innerHTML = '<li class="gl-pick-empty">جاري التحميل…</li>';
        q = String(q || '').trim();
        if (pv2ExtraPickSource === 'presets') {
            var url = '/admin/api/invoice-ancillary/presets-list.php?invoice_context=purchase'
                + (q ? ('&q=' + encodeURIComponent(q)) : '');
            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    listEl.innerHTML = '';
                    var rows = (res && res.presets) ? res.presets : [];
                    if (!rows.length) {
                        listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج في القائمة المحفوظة</li>';
                        return;
                    }
                    rows.forEach(function (row) {
                        var li = document.createElement('li');
                        li.className = 'gl-pick-item';
                        li.setAttribute('role', 'button');
                        li.tabIndex = 0;
                        li.textContent = pv2ExtraAccountLabel(row) + ' — ' + pv2ExtraLineKindLabel(row.line_kind);
                        li.addEventListener('dblclick', function () { pv2ExtraPickConfirm(row); });
                        li.addEventListener('keydown', function (ev) {
                            if (ev.key === 'Enter') pv2ExtraPickConfirm(row);
                        });
                        listEl.appendChild(li);
                    });
                })
                .catch(function (e) {
                    listEl.innerHTML = '<li class="gl-pick-empty">' + esc(e.message || String(e)) + '</li>';
                });
            return;
        }
        var coaUrl = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '');
        fetch(coaUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                listEl.innerHTML = '';
                var rows = (res && res.accounts) ? res.accounts : [];
                if (!rows.length) {
                    listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج في الدليل</li>';
                    return;
                }
                    rows.forEach(function (row) {
                    var li = document.createElement('li');
                    li.className = 'gl-pick-item';
                    li.setAttribute('role', 'button');
                    li.tabIndex = 0;
                    var mapped = {
                        account_id: row.id,
                        account_code: row.code,
                        account_name: row.name
                    };
                    li.textContent = pv2ExtraAccountLabel(mapped);
                    li.addEventListener('click', function () {
                        pv2ExtraPickSelected = mapped;
                        listEl.querySelectorAll('.gl-pick-item').forEach(function (n) {
                            n.classList.remove('is-selected');
                        });
                        li.classList.add('is-selected');
                    });
                    li.addEventListener('dblclick', function () { pv2ExtraPickConfirm(mapped); });
                    li.addEventListener('keydown', function (ev) {
                        if (ev.key === 'Enter') pv2ExtraPickConfirm(mapped);
                    });
                    listEl.appendChild(li);
                });
            })
            .catch(function (e) {
                listEl.innerHTML = '<li class="gl-pick-empty">' + esc(e.message || String(e)) + '</li>';
            });
    }

    function pv2ExtraAddToPresets() {
        if (pv2ExtraPickSource !== 'coa' || !pv2ExtraPickSelected) {
            alert('اختر حساباً من الدليل أولاً (نقرة واحدة ثم «أضف إلى القائمة»).');
            return;
        }
        var kindEl = document.getElementById('pv2_extra_line_kind');
        var lineKind = kindEl ? (kindEl.value || '').trim() : '';
        if (!lineKind) {
            alert('اختر نوع البند.');
            return;
        }
        postJSON('/admin/api/invoice-ancillary/preset-save.php', {
            account_id: pv2ExtraPickSelected.account_id,
            line_kind: lineKind,
            invoice_context: 'purchase',
            label_ar: pv2ExtraPickSelected.account_name || '',
            default_show_on_print: false
        }).then(function (res) {
            if (!res || !res.success) {
                alert((res && res.message) || 'تعذر الحفظ');
                return;
            }
            alert(res.message || 'تمت الإضافة للقائمة');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function pv2SetDocSerial(value) {
        var el = document.getElementById('pv2_doc_serial');
        if (el) el.value = String(value || '');
    }

    function pv2FormatEnteredDisplay(raw) {
        var s = String(raw || '').trim();
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
        var d = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (d) return d[3] + '/' + d[2] + '/' + d[1];
        return s;
    }

    function pv2SyncPrintBanner() {
        var setTxt = function (id, val) {
            var el = document.getElementById(id);
            if (!el) return;
            var v = String(val || '').trim();
            el.textContent = v !== '' ? v : '—';
        };
        var serEl = document.getElementById('pv2_doc_serial');
        setTxt('pv2_sd_print_serial', serEl ? serEl.value : '');

        var docDateEl = document.getElementById('pv2_document_date');
        var docDateVal = docDateEl ? String(docDateEl.value || '').trim() : '';
        var dm = docDateVal.match(/^(\d{4})-(\d{2})-(\d{2})/);
        setTxt('pv2_sd_print_docdate', dm ? (dm[3] + '/' + dm[2] + '/' + dm[1]) : docDateVal);

        var supEl = document.getElementById('pv2_supplier_name');
        setTxt('pv2_sd_print_party_name', supEl ? supEl.value : '');
        var codeEl = document.getElementById('pv2_supplier_code');
        setTxt('pv2_sd_print_party_code', codeEl ? codeEl.value : '');
        var invEl = document.getElementById('pv2_supplier_invoice');
        setTxt('pv2_sd_print_party_inv', invEl ? invEl.value : '');
        var typeSel = document.getElementById('pv2_type');
        var typeTxt = (typeSel && typeSel.selectedIndex >= 0) ? typeSel.options[typeSel.selectedIndex].text : '';
        setTxt('pv2_sd_print_party_type', typeTxt);

        var notesEl = document.getElementById('pv2_notes');
        var notesBox = document.getElementById('pv2_sd_print_notes');
        if (notesBox) notesBox.textContent = notesEl ? String(notesEl.value || '').trim() : '';

        var getTot = function (id) {
            var el = document.getElementById(id);
            return el ? String(el.textContent || '').trim() : '';
        };
        setTxt('pv2_sd_print_total', getTot('pv2_subtotal'));
        setTxt('pv2_sd_print_disc', getTot('pv2_discount_total'));
        setTxt('pv2_sd_print_net', getTot('pv2_net_total'));
    }

    function pv2SyncToolbar() {
        var pb = document.getElementById('pv2_btn_print');
        if (pb) {
            if (PV2_PRINT_TUNING) {
                pb.disabled = false;
                pb.title = 'طباعة (وضع ضبط التنسيق — مؤقت)';
            } else {
                pb.disabled = browsePurchaseId <= 0;
                pb.title = browsePurchaseId > 0 ? 'طباعة الفاتورة المعروضة' : 'افتح فاتورة محفوظة للطباعة';
            }
        }
        var sb = document.getElementById('pv2_btn_save');
        if (sb) {
            sb.disabled = !PV2_READY || pv2ViewMode || !PV2_CAPS.can_edit;
            if (browsePurchaseId > 0 && !pv2ViewMode) {
                sb.title = 'حفظ التعديلات';
            } else if (pv2ViewMode) {
                sb.title = 'وضع العرض — فك القفل للتعديل';
            } else {
                sb.title = 'حفظ فاتورة جديدة';
            }
        }
        var lbl = document.getElementById('pv2_browse_label');
        if (lbl) {
            lbl.textContent = browsePurchaseId > 0 ? ('— عرض ' + (document.getElementById('pv2_doc_serial') && document.getElementById('pv2_doc_serial').value || ('PUR-' + browsePurchaseId))) : '';
        }
        if (browsePurchaseId <= 0) {
            pv2SetDocSerial(PV2_DOC_SERIAL_PREVIEW || '');
        }
        if (pv2EditLockCtl) pv2EditLockCtl.refresh();
    }

    function pv2SetViewMode(on) {
        pv2ViewMode = !!on;
        var card = document.querySelector('.jv-print-area');
        if (card) {
            card.querySelectorAll('input, select, button.admin-doc-line-remove').forEach(function (el) {
                if (el.id === 'pv2_btn_new' || el.id === 'pv2_btn_print' || el.closest('.jv-voucher-nav-btns') || el.id === 'pv2_btn_search') {
                    return;
                }
                if (el.id === 'pv2_btn_save') {
                    return;
                }
                el.disabled = pv2ViewMode || (!PV2_READY && el.id !== 'pv2_supplier_code');
            });
            var addExtra = document.getElementById('pv2_btn_add_extra');
            if (addExtra) addExtra.disabled = pv2ViewMode || !PV2_READY;
        }
        pv2SyncToolbar();
    }

    function pv2ApplyPurchasePayload(res) {
        if (!res || !res.success || !res.purchase) {
            alert((res && res.message) || 'تعذر تحميل الفاتورة');
            return;
        }
        var p = res.purchase;
        browsePurchaseId = parseInt(String(p.id || '0'), 10) || 0;
        pv2SetDocSerial(p.reference || ('PUR-' + browsePurchaseId));
        selectSupplier(parseInt(String(p.supplier_id || '0'), 10) || 0);
        var typeEl = document.getElementById('pv2_type');
        if (typeEl) typeEl.value = p.type || 'cash';
        var notesEl = document.getElementById('pv2_notes');
        if (notesEl) notesEl.value = p.notes || '';
        var invEl = document.getElementById('pv2_supplier_invoice');
        if (invEl) invEl.value = p.supplier_invoice_number || '';
        var docDateEl = document.getElementById('pv2_document_date');
        if (docDateEl) docDateEl.value = (p.document_date ? String(p.document_date).substr(0, 10) : '');
        var entryDateEl = document.getElementById('pv2_entry_date');
        if (entryDateEl && p.created_at) entryDateEl.value = pv2FormatEnteredDisplay(p.created_at);
        var invDiscEl = document.getElementById('pv2_invoice_discount');
        if (invDiscEl) invDiscEl.value = p.invoice_discount_raw || '';

        var tb = document.getElementById('pv2_lines_body');
        if (tb) {
            tb.innerHTML = '';
            var items = res.items || [];
            if (!items.length) {
                addLine();
            } else {
                items.forEach(function (item) {
                    addLine();
                    var rows = tb.querySelectorAll('tr.pv2-line');
                    pv2FillLineRow(rows[rows.length - 1], item);
                });
            }
            syncTrailing();
        }
        pv2LoadExtraLines(res.extra_lines || []);
        recalcAll();
        pv2SetViewMode(true);
        if (pv2EditLockCtl) pv2EditLockCtl.refresh();
    }

    function pv2LoadPurchase(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        if (id <= 0) return;
        fetch('/admin/api/purchases/get.php?purchase_id=' + encodeURIComponent(String(id)), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            pv2ApplyPurchasePayload(res);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function pv2Nav(where) {
        if (!PV2_NAV_READY) return;
        postJSON('/admin/api/purchases/browse.php', {
            action: 'nav',
            where: where,
            current_id: browsePurchaseId || 0
        }).then(function (r) {
            if (!r.success || !r.id) {
                alert(r.message || 'لا توجد فاتورة');
                return;
            }
            pv2LoadPurchase(r.id);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function pv2SearchOpen() {
        var m = document.getElementById('pv2_search_modal');
        if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
    }
    function pv2SearchClose() {
        var m = document.getElementById('pv2_search_modal');
        if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
    }
    function pv2SearchRun() {
        var idFrom = parseInt(document.getElementById('pv2_search_id_from').value, 10) || 0;
        var idTo = parseInt(document.getElementById('pv2_search_id_to').value, 10) || 0;
        var dateFrom = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('pv2_search_date_from')) || '' : '';
        var dateTo = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('pv2_search_date_to')) || '' : '';
        var ref = (document.getElementById('pv2_search_ref').value || '').trim();
        var supplierInv = (document.getElementById('pv2_search_supplier_inv').value || '').trim();
        var notes = (document.getElementById('pv2_search_notes').value || '').trim();
        var tbody = document.getElementById('pv2_search_results');
        tbody.innerHTML = '<tr><td colspan="6">جاري البحث…</td></tr>';
        var payload = { action: 'search' };
        if (idFrom > 0) payload.id_from = idFrom;
        if (idTo > 0) payload.id_to = idTo;
        if (dateFrom) payload.date_from = dateFrom;
        if (dateTo) payload.date_to = dateTo;
        if (ref) payload.reference = ref;
        if (supplierInv) payload.supplier_invoice = supplierInv;
        if (notes) payload.notes = notes;
        postJSON('/admin/api/purchases/browse.php', payload).then(function (r) {
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
                    + '<td dir="ltr">' + esc(v.supplier_invoice_number || '') + '</td>'
                    + '<td dir="ltr">' + fmt3(v.total || 0) + '</td>';
                tr.addEventListener('dblclick', function () { pv2LoadPurchase(v.id); pv2SearchClose(); });
                tbody.appendChild(tr);
            });
        }).catch(function (e) {
            tbody.innerHTML = '<tr><td colspan="6">' + esc(e.message || String(e)) + '</td></tr>';
        });
    }

    function pv2ResetNew() {
        browsePurchaseId = 0;
        pv2ClearExtraLines();
        pv2SetViewMode(false);
        location.reload();
    }

    /* ── Save ───────────────────────────────────────────────────────── */
    function save() {
        if (!PV2_CAPS.can_edit) {
            alert('لا تملك صلاحية تعديل المشتريات');
            return;
        }
        if (!PV2_READY || pv2ViewMode) return;
        var supplierId = parseInt(document.getElementById('pv2_supplier_id').value, 10) || 0;
        var purType = document.getElementById('pv2_type').value;
        var notes = (document.getElementById('pv2_notes').value || '').trim();
        var supplierInvoice = (document.getElementById('pv2_supplier_invoice').value || '').trim();

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
            var pid = parseInt(r.querySelector('.pv2-product-id').value, 10) || 0;
            var vid = parseInt(r.querySelector('.pv2-variant-id').value, 10) || 0;
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
        var subtotal = 0;
        for (var si = 0; si < items.length; si++) {
            subtotal += Math.max(0, (items[si].qty * items[si].cost) - items[si].discount_amount);
        }
        var invDiscAmt = parseDiscount(invDiscRaw, subtotal);

        var payload = {
            supplier_id: supplierId,
            type: purType,
            notes: notes,
            supplier_invoice_number: supplierInvoice,
            document_date: (document.getElementById('pv2_document_date') ? (document.getElementById('pv2_document_date').value || '') : ''),
            items: items,
            invoice_discount_raw: invDiscRaw,
            invoice_discount_amount: invDiscAmt,
            extra_lines: pv2CollectExtraLines()
        };

        var apiUrl = '/admin/api/purchases/create.php';
        if (browsePurchaseId > 0) {
            apiUrl = '/admin/api/purchases/update.php';
            payload.id = browsePurchaseId;
        }

        postJSON(apiUrl, payload).then(function (res) {
            if (res.success) {
                var pv2SavedId = (res && res.purchase_id) ? (parseInt(String(res.purchase_id), 10) || 0) : (browsePurchaseId || 0);
                var pv2AfterSave = function () {
                    if (pv2SavedId > 0) { pv2LoadPurchase(pv2SavedId); } else { location.reload(); }
                };
                if (typeof orangeAdminOfferOpenGlVoucherAfterSave === 'function') {
                    orangeAdminOfferOpenGlVoucherAfterSave(res, pv2AfterSave);
                } else {
                    alert(res.message || 'تم حفظ فاتورة الشراء');
                    pv2AfterSave();
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
            pv2ProductPick = window.OrangePurchaseDocProductPick.create({
                pickRows: PV2_PICK_ROWS,
                codeClass: 'pv2-code',
                fmtMoney: fmt3,
                isViewMode: function () { return pv2ViewMode; },
                modalIds: {
                    root: 'pv2_product_pick_modal',
                    backdrop: 'pv2_product_pick_backdrop',
                    filter: 'pv2_product_pick_filter',
                    body: 'pv2_product_pick_body'
                },
                selectors: {
                    code: '.pv2-code',
                    productId: '.pv2-product-id',
                    variantId: '.pv2-variant-id',
                    name: '.pv2-name',
                    varLabel: '.pv2-var-label',
                    cost: '.pv2-cost'
                },
                onAfterResolve: function (tr) {
                    recalcAll();
                }
            });
            pv2ProductPick.bindModal();
        }

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

        document.getElementById('pv2_invoice_discount').addEventListener('input', function () { recalcAll(); });

        document.getElementById('pv2_btn_save').addEventListener('click', save);
        // زر «فاتورة جديدة» مربوط عبر onclick مباشر حتى يعمل حتى لو فشل ربط addEventListener.
        var addExtraBtn = document.getElementById('pv2_btn_add_extra');
        if (addExtraBtn) addExtraBtn.addEventListener('click', pv2ExtraPickOpen);
        document.getElementById('pv2_extra_pick_backdrop').addEventListener('click', pv2ExtraPickClose);
        document.getElementById('pv2_extra_pick_close').addEventListener('click', pv2ExtraPickClose);
        document.getElementById('pv2_extra_tab_presets').addEventListener('click', function () { pv2ExtraPickSetSource('presets'); });
        document.getElementById('pv2_extra_tab_coa').addEventListener('click', function () { pv2ExtraPickSetSource('coa'); });
        document.getElementById('pv2_extra_add_to_presets').addEventListener('click', pv2ExtraAddToPresets);
        var extraPickQ = document.getElementById('pv2_extra_pick_q');
        var extraPickTimer = null;
        if (extraPickQ) {
            extraPickQ.addEventListener('input', function () {
                if (extraPickTimer) clearTimeout(extraPickTimer);
                extraPickTimer = setTimeout(function () { pv2ExtraPickRender(extraPickQ.value || ''); }, 200);
            });
        }
        var extraTb = document.getElementById('pv2_extra_lines_body');
        if (extraTb) {
            extraTb.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('admin-doc-line-remove')) {
                    var tr = e.target.closest('tr');
                    if (tr) tr.remove();
                    pv2RenumberExtraRows();
                }
            });
            extraTb.addEventListener('change', function (e) {
                if (e.target && e.target.classList.contains('pv2-extra-print')) {
                    pv2SyncExtraPrintClass(e.target.closest('tr'));
                }
            });
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                pv2ExtraPickClose();
            }
        });
        document.getElementById('pv2_btn_print').addEventListener('click', function () {
            if (!PV2_PRINT_TUNING && browsePurchaseId <= 0) {
                alert('افتح فاتورة محفوظة للطباعة.');
                return;
            }
            pv2SyncPrintBanner();
            var pv2SerEl = document.getElementById('pv2_doc_serial');
            var pv2Ser = pv2SerEl ? String(pv2SerEl.value || '').trim() : '';
            var pv2Title = pv2Ser !== '' ? ('فاتورة مشتريات رقم ' + pv2Ser) : 'فاتورة مشتريات';
            if (typeof window.orangeAdminOpenPrintDialog === 'function') {
                window.orangeAdminOpenPrintDialog(pv2Title);
            } else {
                window.print();
            }
        });

        document.getElementById('pv2_nav_first').addEventListener('click', function () { pv2Nav('first'); });
        document.getElementById('pv2_nav_prev').addEventListener('click', function () { pv2Nav('prev'); });
        document.getElementById('pv2_nav_next').addEventListener('click', function () { pv2Nav('next'); });
        document.getElementById('pv2_nav_last').addEventListener('click', function () { pv2Nav('last'); });
        document.getElementById('pv2_btn_search').addEventListener('click', pv2SearchOpen);
        document.getElementById('pv2_search_btn').addEventListener('click', pv2SearchRun);
        document.getElementById('pv2_search_modal_backdrop').addEventListener('click', pv2SearchClose);
        document.addEventListener('mousedown', function (ev) {
            var m = document.getElementById('pv2_search_modal');
            if (!m || m.style.display !== 'flex') return;
            var panel = m.querySelector('.jv-search-modal__panel');
            if (panel && (panel === ev.target || panel.contains(ev.target))) return;
            if (ev.target.closest && ev.target.closest('#pv2_btn_search')) return;
            pv2SearchClose();
        }, true);

        pv2SyncToolbar();

        var tb = document.getElementById('pv2_lines_body');
        if (tb) {
            if (pv2ProductPick) {
                pv2ProductPick.bindLinesBody(tb);
            }
            tb.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('pv2-code')) return;
                recalcAll();
            });
            tb.addEventListener('keydown', pv2OnLineKeydown);
            tb.addEventListener('focusout', function (e) {
                if (e.target && e.target.classList.contains('pv2-code')) {
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

        if (PV2_PREFILL_SUPPLIER > 0) {
            selectSupplier(PV2_PREFILL_SUPPLIER);
        }

        if (window.OrangeEditLock) {
            pv2EditLockCtl = OrangeEditLock.bind({
                prefix: 'pv2',
                docKind: 'purchase',
                page: 'purchases',
                canLock: !!PV2_CAPS.can_lock,
                canUnlock: !!PV2_CAPS.can_unlock,
                countryId: PV2_COUNTRY_ID,
                getEntityId: function () { return browsePurchaseId; },
                onLockedChange: function (locked) {
                    if (browsePurchaseId > 0) {
                        pv2SetViewMode(!!locked);
                    }
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
