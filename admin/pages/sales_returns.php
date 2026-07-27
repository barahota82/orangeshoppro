<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/sales_doc_product_pick.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/catalog_multicountry_runtime.php';
require_once __DIR__ . '/../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../includes/admin_voucher_print_tuning.php';

$pdo = orange_admin_page_pdo();
orange_catalog_backfill_products_country_id($pdo);
orange_catalog_backfill_channels_country_id($pdo);
$sr2Caps = orange_admin_caps_for_page($admin, $pdo, 'sales_returns');

$srCountryId = orange_admin_context_country_id($pdo);
$srDefaultCurrency = orange_admin_context_currency_code($pdo);
$sr2DefaultDocDmy = date('d/m/Y');
$sr2DefaultEntryDisp = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
try {
    if ($srCountryId > 0) {
        $sr2TodayYmd = orange_admin_time_document_date_today_for_country_id($pdo, $srCountryId);
        $sr2DefaultDocDmy = substr($sr2TodayYmd, 8, 2) . '/' . substr($sr2TodayYmd, 5, 2) . '/' . substr($sr2TodayYmd, 0, 4);
        $sr2DefaultEntryDisp = orange_admin_time_format_instant_for_country_id(
            $pdo,
            orange_admin_time_utc_now_iso(),
            $srCountryId,
            'ar',
            'datetime'
        );
    }
} catch (OrangeAdminTimeConfigException $e) {
    // keep defaults
}
$srCurrencyDecimals = orange_currency_decimals_for_code($srDefaultCurrency);
$srCurrencyUnit = orange_currency_display_unit($srDefaultCurrency);
$sr2CustomersCountrySql = orange_sql_country_and_fragment($pdo, 'customers', 'customers', $srCountryId);
$sr2PickRows = orange_sales_doc_product_pick_rows($pdo, $srCountryId);

$sr2ChannelsCountrySql = orange_channels_has_country_column($pdo)
    ? orange_sql_country_and_fragment($pdo, 'channels', 'channels', $srCountryId)
    : '';
$sr2HasChannelWa = orange_table_has_column($pdo, 'channels', 'whatsapp_number');
$sr2PhoneDial = orange_admin_context_phone_dial($pdo);
$sr2ChannelWaMap = [];
if ($sr2HasChannelWa) {
    $sr2ChannelRows = $pdo->query(
        'SELECT id, whatsapp_number FROM channels WHERE is_active = 1' . $sr2ChannelsCountrySql . ' ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sr2ChannelRows as $sr2Ch) {
        $sr2ChannelWaMap[(int) $sr2Ch['id']] = orange_phone_strip_dial((string) ($sr2Ch['whatsapp_number'] ?? ''), $sr2PhoneDial);
    }
}
$sr2CompanyPhone = orange_sales_doc_print_company($pdo, $srCountryId)['phones'];

$sr2SalesLineKinds = [];
foreach (orange_invoice_ancillary_sales_line_kind_catalog() as $kindKey => $kindMeta) {
    $sr2SalesLineKinds[] = ['key' => $kindKey, 'label_ar' => (string) ($kindMeta['label_ar'] ?? $kindKey)];
}

$sr2CustomerPickRows = [];
if (orange_table_exists($pdo, 'customers')) {
    $codeCol = orange_table_has_column($pdo, 'customers', 'code') ? 'code' : 'id';
    $sr2CustPickCols = 'id, name_ar, phone, ' . $codeCol . ' AS customer_code';
    if (orange_table_has_column($pdo, 'customers', 'area')) {
        $sr2CustPickCols .= ', area';
    }
    if (orange_table_has_column($pdo, 'customers', 'address')) {
        $sr2CustPickCols .= ', address';
    }
    $customers = $pdo->query(
        'SELECT ' . $sr2CustPickCols . ' FROM customers WHERE 1=1'
        . $sr2CustomersCountrySql . ' ORDER BY name_ar ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $custBal = [];
    foreach ($customers as $c) {
        $custBal[(int) $c['id']] = orange_party_balance_customer($pdo, (int) $c['id']);
    }
    foreach ($customers as $c) {
        $cid = (int) ($c['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $customerCode = trim((string) ($c['customer_code'] ?? ''));
        if ($customerCode === '') {
            $customerCode = (string) $cid;
        }
        $sr2CustomerPickRows[] = [
            'id' => $cid,
            'code' => $customerCode,
            'name' => trim((string) ($c['name_ar'] ?? '')),
            'phone' => trim((string) ($c['phone'] ?? '')),
            'area' => trim((string) ($c['area'] ?? '')),
            'address' => trim((string) ($c['address'] ?? '')),
            'balance' => round((float) ($custBal[$cid] ?? 0.0), $srCurrencyDecimals),
        ];
    }
}

$prefillCustomerId = 0;
$prefillCustomerRaw = (int) ($_GET['customer_id'] ?? 0);
if ($prefillCustomerRaw > 0) {
    foreach ($sr2CustomerPickRows as $row) {
        if ((int) ($row['id'] ?? 0) === $prefillCustomerRaw) {
            $prefillCustomerId = $prefillCustomerRaw;
            break;
        }
    }
}
$prefillOrderId = (int) ($_GET['order_id'] ?? 0);

/** الشاشة نشطة دائماً (مثل مردود المشتريات) — غياب الأصناف تنبيه فقط */
$sr2Ready = true;
$sr2WarnNoProducts = $sr2PickRows === [];
$sr2DiagCountryCode = orange_admin_context_country_code($pdo);
$sr2DiagActiveProductsAll = orange_table_exists($pdo, 'products')
    ? (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn()
    : 0;
$sr2NavReady = orange_table_exists($pdo, 'sales_returns');
$sr2DocSerialPreview = $sr2NavReady
    ? orange_country_document_next_ref_preview($pdo, 'sales_returns', 'SR', $srCountryId)
    : '';
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
.jv-search-modal__backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.45); }
.jv-search-modal__panel {
    position: relative; z-index: 1; width: 100%; max-width: min(96vw, 58rem);
    max-height: calc(100vh - 32px); overflow: auto; background: #fff;
    border: 1px solid #e4e4e7; border-radius: 10px; box-shadow: 0 20px 50px rgba(0,0,0,.18);
}
.jv-search-modal__head { display: flex; align-items: center; justify-content: center; padding: 14px 16px; border-bottom: 1px solid #e4e4e7; }
.jv-search-modal__title { margin: 0; font-size: 1.05rem; text-align: center; }
.jv-search-modal__body { padding: 14px 16px 18px; }
.jv-search-modal__form { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
.jv-search-modal__row--fields {
    display: flex; flex-direction: row; flex-wrap: nowrap; align-items: flex-end;
    gap: 10px; width: 100%; overflow-x: auto;
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
/* صف 2 — فاتورة المبيعات المرجعية · استرجاع · تاريخ المردود · قناة التحصيل · تاريخ الإدخال · ملاحظات
   (نفس ترتيب مردود المشتريات؛ فرق زر الاسترجاع مأخوذ من عرض ملاحظات) */
.form-grid.sr2-header-row2 {
    grid-template-columns: minmax(7rem, 0.85fr) auto minmax(5.5rem, 0.65fr) minmax(5.5rem, 0.65fr) minmax(5.5rem, 0.65fr) minmax(0, 1.4fr);
}
.form-grid.sr2-customer-row {
    grid-template-columns: minmax(7rem, 0.75fr) minmax(0, 1fr) minmax(0, 2fr) minmax(5rem, 0.55fr);
}
@media print {
    /* مردود المبيعات بلا نص قانوني — نقلّل الفراغ أسفل التوقيع/الختم أكثر من فواتير المبيعات. */
    .jv-print-area { padding-bottom: 3mm !important; }

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
    <h1>مردود مبيعات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div id="sr2_view_mode_banner" class="card" style="display:none;border:1px solid #93c5fd;background:#eff6ff;margin-bottom:12px;" role="status">
    <p class="card-hint" style="margin:0;line-height:1.55;">
        <strong>وضع العرض:</strong> تعرض مردوداً محفوظاً (للقراءة والطباعة). لتسجيل مردود جديد اضغط
        <strong>«مردود جديد»</strong> في شريط الأدوات.
    </p>
</div>

<div class="card jv-print-area">
    <?php
    orange_sales_doc_print_banner([
        'prefix' => 'sr2',
        'doc_title' => 'مردود مبيعات',
        'doc_title_en' => 'Sales Return',
        'country_id' => $srCountryId,
        'currency_code' => $srDefaultCurrency,
        'phone_by_channel' => true,
        'serial_label' => 'رقم المردود / Return No.',
        'doc_date_label' => 'تاريخ المردود / Return Date',
        'show_doc_date' => true,
        'show_print_date' => false,
        'show_qr' => true,
        'show_party' => true,
        'party_title' => 'رقم الفاتورة المرتجع / Returned Invoice No.',
        'party_title_value_id' => 'ref_invoice',
        'party_rows' => [
            ['الاسم / Name', 'party_name', ''],
            ['الهاتف / Phone', 'party_phone', 'ltr'],
            ['المنطقة / Area', 'party_area', ''],
            ['العنوان / Address', 'party_address', ''],
        ],
        'show_notes' => true,
        'totals_rows' => [
            ['إجمالي المردود / Total', 'gross'],
            ['قيمة الخصم / Discount', 'disc'],
            ['صافي المردود / Net', 'net'],
        ],
    ]);
    ?>
    <h3 class="card-title">مردود مبيعات <span id="sr2_browse_label" class="muted" style="font-size:0.85rem;font-weight:500;"></span></h3>
    <?php orange_edit_lock_ui_toolbar(['prefix' => 'sr2', 'doc_kind' => 'sales_return', 'country_id' => $srCountryId, 'show_status_badge' => false]); ?>

    <div class="form-grid sr2-customer-row orange-doc-header-row jv-print-hide" style="margin-bottom:12px;">
        <div>
            <label for="sr2_doc_serial">مسلسل المردود</label>
            <input type="text" id="sr2_doc_serial" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en" style="background:#f4f4f5;cursor:default;"
                value="<?php echo htmlspecialchars($sr2DocSerialPreview, ENT_QUOTES, 'UTF-8'); ?>"
                title="يُخصَّص تلقائياً عند الحفظ">
        </div>
        <div>
            <label for="sr2_customer_code">كود العميل</label>
            <input type="text" id="sr2_customer_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار — Enter أيضاً" style="cursor:pointer;">
        </div>
        <div>
            <label for="sr2_customer_name" id="sr2_customer_name_label">اسم العميل</label>
            <input type="text" id="sr2_customer_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
        </div>
        <div>
            <label for="sr2_customer_balance">رصيد الذمم</label>
            <input type="text" id="sr2_customer_balance" class="admin-inp-readonly admin-money-display" readonly disabled tabindex="-1" dir="ltr" lang="en" placeholder="—">
        </div>
        <input type="hidden" id="sr2_customer_id" value="0">
    </div>

    <!-- ٢ — فاتورة المبيعات المرجعية، استرجاع، تاريخ المردود، قناة التحصيل، تاريخ الإدخال، ملاحظات -->
    <div class="form-grid sr2-header-row2 orange-doc-header-row jv-print-hide" style="margin-bottom:16px;">
        <div>
            <label for="sr2_order_ref">فاتورة المبيعات المرجعية</label>
            <input type="text" id="sr2_order_ref" placeholder="INV-C- أو رقم" dir="ltr" lang="en" autocomplete="off">
            <input type="hidden" id="sr2_order_id" value="0">
        </div>
        <div class="orange-doc-header-row__action">
            <span class="orange-doc-header-row__action-label" aria-hidden="true">.</span>
            <button type="button" class="btn-secondary" id="sr2_btn_retrieve">استرجاع</button>
        </div>
        <div>
            <label for="sr2_document_date">تاريخ المردود</label>
            <input type="text" id="sr2_document_date" class="orange-inp-dmy" dir="ltr" lang="en" title="تاريخ المردود = تاريخ ترحيل القيد المحاسبي" value="<?php echo htmlspecialchars($sr2DefaultDocDmy, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
            <label for="sr2_channel">قناة التحصيل</label>
            <select id="sr2_channel">
                <option value="cash">نقدي</option>
                <option value="online">أونلاين</option>
                <option value="credit">آجل</option>
            </select>
        </div>
        <div>
            <label for="sr2_entry_date">تاريخ الإدخال</label>
            <input type="text" id="sr2_entry_date" class="admin-inp-readonly" readonly tabindex="-1" dir="ltr" lang="en" style="background:#f4f4f5;cursor:default;"
                value="<?php echo htmlspecialchars($sr2DefaultEntryDisp, ENT_QUOTES, 'UTF-8'); ?>"
                title="وقت تسجيل إدخال المستند في النظام — يُثبت عند الحفظ ولا يُقبل من المتصفح">
        </div>
        <div>
            <label for="sr2_notes">ملاحظات</label>
            <input type="text" id="sr2_notes" placeholder="رقم إذن الإرجاع، …">
        </div>
    </div>

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
                        <th style="width:6rem;">سعر الوحدة</th>
                        <th style="width:6rem;">خصم</th>
                        <th style="width:7rem;">إجمالي الصنف</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر" style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody id="sr2_lines_body"></tbody>
                <tfoot id="sr2_sd_intable_totals" class="sd-intable-totals"></tfoot>
            </table>
        </div>
    </div>


    <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:16px 0 10px;">بنود إضافية</h4>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table sr2-extra-lines-table">
                <thead>
                    <tr>
                        <th class="pur-col-idx" style="width:2.5rem;">#</th>
                        <th style="min-width:12rem;">الحساب / البند</th>
                        <th style="width:7rem;">المبلغ</th>
                        <th style="min-width:8rem;">تسمية طباعة</th>
                        <th class="admin-doc-col-actions jv-print-hide" aria-label="حذف" style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody id="sr2_extra_lines_body"></tbody>
            </table>
        </div>
    </div>
    <div class="actions jv-print-hide" style="margin-top:10px;">
        <button type="button" class="btn-secondary" id="sr2_btn_add_extra">إضافة بند</button>
    </div>

    <div id="sr2_loyalty_clawback_note" style="display:none;margin-top:10px;padding:8px 12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:0.9rem;color:#9a3412;line-height:1.7;"></div>

    <div class="jv-print-hide" style="margin-top:14px;text-align:left;direction:ltr;font-size:0.95rem;line-height:1.8;">
        <span style="color:#64748b;">إجمالي الأصناف:</span> <strong id="sr2_subtotal" class="admin-money-display" dir="ltr" lang="en"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
        <span style="color:#64748b;">خصم الأصناف:</span> <strong id="sr2_discount_total" class="admin-money-display" dir="ltr" lang="en" style="color:#b91c1c;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
        <span style="color:#64748b;">صافي الأصناف:</span> <strong id="sr2_net_total" class="admin-money-display" dir="ltr" lang="en" style="color:#059669;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
        <span id="sr2_screen_extra"></span>
        <span style="color:#0f172a;font-weight:700;border-top:2px solid #ea580c;display:inline-block;padding-top:4px;margin-top:2px;"><span id="sr2_grand_label">إجمالي المردود:</span> <strong id="sr2_grand_total" class="admin-money-display" dir="ltr" lang="en" style="color:#ea580c;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong>
        <span class="muted" style="font-size:0.85rem;"> <?php echo htmlspecialchars($srCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></span></span>
    </div>
    <div id="sr2_line_disc_error" class="jv-print-hide" style="display:none;color:#dc2626;font-size:0.85rem;margin-top:6px;font-weight:600;"></div>

    <?php orange_sales_doc_print_footer(['country_id' => $srCountryId, 'show_note' => false]); ?>

    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:16px;">
        <span></span>
        <div class="jv-toolbar-primary-group">
            <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين مردودات المبيعات">
                <button type="button" class="btn-secondary jv-nav-btn" id="sr2_nav_first" title="أول مردود">&lt;&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="sr2_nav_prev" title="المردود السابق">&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="sr2_nav_next" title="المردود التالي">&gt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="sr2_nav_last" title="آخر مردود">&gt;&gt;</button>
                <button type="button" class="btn-secondary jv-nav-search" id="sr2_btn_search" title="بحث عن مردود">بحث</button>
            </div>
            <button type="button" class="btn-secondary" id="sr2_btn_print" title="طباعة المردود المعروض"<?php echo orange_admin_invoice_print_tuning_mode() ? '' : ' disabled'; ?>>طباعة</button>
            <button type="button" class="btn-secondary" id="sr2_btn_new" title="مردود جديد" onclick="if (confirm('بدء مردود جديد؟ سيتم مسح أي بيانات غير محفوظة على الشاشة.')) { location.reload(); } return false;">مردود جديد</button>
            <button type="button" id="sr2_btn_save" data-orange-perm="edit" data-orange-page="sales_returns">حفظ</button>
        </div>
    </div>
</div>

<div class="gl-pick-modal" id="sr2_extra_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="sr2_extra_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="sr2_extra_pick_title">
        <h3 id="sr2_extra_pick_title" class="gl-pick-modal__title">اختيار حساب — بند إضافي</h3>
        <div class="sr2-extra-source-tabs" role="tablist">
            <button type="button" class="btn-secondary is-active" id="sr2_extra_tab_presets" data-source="presets">القائمة المحفوظة</button>
            <button type="button" class="btn-secondary" id="sr2_extra_tab_coa" data-source="coa">الدليل المحاسبي</button>
        </div>
        <div id="sr2_extra_line_kind_wrap" hidden style="margin:10px 0;">
            <label for="sr2_extra_line_kind">نوع البند</label>
            <select id="sr2_extra_line_kind" class="admin-inp">
                <?php foreach ($sr2SalesLineKinds as $lk): ?>
                <option value="<?php echo htmlspecialchars((string) $lk['key'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $lk['label_ar'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="search" id="sr2_extra_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="sr2_extra_pick_list"></ul>
        <div class="actions" style="margin-top:10px;flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn-secondary" id="sr2_extra_add_to_presets" hidden>أضف إلى القائمة</button>
            <button type="button" class="btn-secondary" id="sr2_extra_pick_close">إغلاق</button>
        </div>
    </div>
</div>

<div id="sr2_product_pick_modal" class="mo-pick-modal" hidden>
    <div class="mo-pick-modal__backdrop" id="sr2_product_pick_backdrop"></div>
    <div class="mo-pick-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sr2_product_pick_title">
        <h4 id="sr2_product_pick_title" class="mo-pick-modal__title">اختيار صنف</h4>
        <input type="search" id="sr2_product_pick_filter" class="admin-inp mo-pick-modal__search" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" lang="ar" dir="rtl">
        <div class="mo-pick-modal__scroller table-wrap">
            <table class="admin-table mo-pick-table">
                <thead>
                    <tr>
                        <th>الكود</th>
                        <th>الباركود</th>
                        <th>الاسم</th>
                        <th>اللون</th>
                        <th>المقاس</th>
                        <th class="mo-pick-num-h">إجمالي</th>
                        <th class="mo-pick-num-h">محجوز</th>
                        <th class="mo-pick-num-h">صافي</th>
                        <th class="mo-pick-num-h">سعر</th>
                    </tr>
                </thead>
                <tbody id="sr2_product_pick_body"></tbody>
            </table>
        </div>
        <p class="card-hint mo-pick-modal__hint">انقر نقراً مزدوجاً على السطر للاختيار.</p>
    </div>
</div>

<div class="gl-pick-modal" id="sr2_customer_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="sr2_customer_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="sr2_customer_pick_title">
        <h3 id="sr2_customer_pick_title" class="gl-pick-modal__title">اختيار العميل</h3>
        <input type="search" id="sr2_customer_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="sr2_customer_pick_list"></ul>
        <button type="button" class="btn-secondary" id="sr2_customer_pick_close">إغلاق</button>
    </div>
</div>

<div id="sr2_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="sr2_search_modal_title">
    <div class="jv-search-modal__backdrop" id="sr2_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="sr2_search_modal_title" class="jv-search-modal__title">بحث في مردودات المبيعات</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="sr2_search_id_from">رقم المردود — من</label>
                        <input type="number" id="sr2_search_id_from" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="sr2_search_id_to">رقم المردود — إلى</label>
                        <input type="number" id="sr2_search_id_to" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="sr2_search_date_from">التاريخ — من</label>
                        <input type="text" id="sr2_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="sr2_search_date_to">التاريخ — إلى</label>
                        <input type="text" id="sr2_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="sr2_search_ref">المرجع SR-</label>
                        <input type="text" id="sr2_search_ref" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="sr2_search_order_ref">طلب / فاتورة INV-C-</label>
                        <input type="text" id="sr2_search_order_ref" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="sr2_search_customer">العميل (يحتوي النص)</label>
                        <input type="text" id="sr2_search_customer" class="admin-inp" autocomplete="off" dir="auto">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="sr2_search_notes">ملاحظات</label>
                        <input type="text" id="sr2_search_notes" class="admin-inp" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="sr2_search_btn">تنفيذ البحث</button>
            </div>
            <div class="jv-search-modal__results">
                <div class="table-wrap jv-search-table-wrap">
                    <table class="admin-table jv-search-results-table">
                        <thead>
                            <tr>
                                <th>رقم</th>
                                <th>تاريخ</th>
                                <th>مرجع</th>
                                <th>عميل</th>
                                <th>طلب</th>
                                <th>صافي</th>
                            </tr>
                        </thead>
                        <tbody id="sr2_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_purchase_doc_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/admin/assets/vendor/qrcode.min.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/admin/assets/admin_sales_doc_ui.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var SR2_PICK_ROWS = <?php echo json_encode($sr2PickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SR2_CUSTOMER_PICK_ROWS = <?php echo json_encode($sr2CustomerPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SR2_PREFILL_CUSTOMER = <?php echo (int) $prefillCustomerId; ?>;
    var SR2_PREFILL_ORDER = <?php echo (int) $prefillOrderId; ?>;
    var SR2_READY = <?php echo $sr2Ready ? 'true' : 'false'; ?>;
    var SR2_PRINT_TUNING = <?php echo orange_admin_invoice_print_tuning_mode() ? 'true' : 'false'; ?>;
    var SR2_NAV_READY = <?php echo $sr2NavReady ? 'true' : 'false'; ?>;
    var SR2_COUNTRY_ID = <?php echo (int) $srCountryId; ?>;
    var SR2_COMPANY_PHONE = <?php echo json_encode($sr2CompanyPhone, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SR2_CHANNEL_WA = <?php echo json_encode((object) $sr2ChannelWaMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var sr2MarketChannelId = 0;
    var SR2_CAPS = <?php echo json_encode($sr2Caps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var SR2_DOC_SERIAL_PREVIEW = <?php echo json_encode($sr2DocSerialPreview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var sr2EditLockCtl = null;
    var browseReturnId = 0;
    var sr2ViewMode = false;
    var currentCustomerId = 0;
    var sr2ProductPick = null;
    var SR2_MONEY_DECIMALS = (window.ORANGE_ADMIN_MONEY && typeof window.ORANGE_ADMIN_MONEY.decimals === 'number')
        ? Math.max(0, parseInt(String(window.ORANGE_ADMIN_MONEY.decimals), 10) || 3)
        : 3;
    var SR2_MONEY_EPSILON = Math.pow(10, -SR2_MONEY_DECIMALS);

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function fmt3(n) {
        var f = window.orangeFmtMoney || (window.OrangeMoney && window.OrangeMoney.formatAmount);
        if (f) return f(n);
        return (parseFloat(n) || 0).toFixed(SR2_MONEY_DECIMALS);
    }
    function fmtZero() {
        if (window.orangeMoneyZero) return window.orangeMoneyZero();
        return fmt3(0);
    }

    function customerById(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        for (var i = 0; i < SR2_CUSTOMER_PICK_ROWS.length; i++) {
            if ((parseInt(String(SR2_CUSTOMER_PICK_ROWS[i].id || '0'), 10) || 0) === id) return SR2_CUSTOMER_PICK_ROWS[i];
        }
        return null;
    }

    function selectCustomer(id) {
        var row = customerById(id);
        var codeEl = document.getElementById('sr2_customer_code');
        var nameEl = document.getElementById('sr2_customer_name');
        var balEl = document.getElementById('sr2_customer_balance');
        var idEl = document.getElementById('sr2_customer_id');
        if (!row) {
            currentCustomerId = 0;
            if (codeEl) codeEl.value = '';
            if (nameEl) nameEl.value = '';
            if (balEl) balEl.value = '';
            if (idEl) idEl.value = '0';
        } else {
            currentCustomerId = parseInt(String(row.id), 10) || 0;
            if (codeEl) codeEl.value = row.code || '';
            if (nameEl) nameEl.value = row.name || '';
            if (balEl) balEl.value = fmt3(row.balance || 0);
            if (idEl) idEl.value = String(currentCustomerId);
        }
        sr2OnChannelChange();
    }

    function customerPickerOpen() {
        if (sr2ViewMode) {
            alert('وضع العرض — اضغط «مردود جديد» لتسجيل مردود جديد.');
            return;
        }
        var modal = document.getElementById('sr2_customer_pick_modal');
        var qEl = document.getElementById('sr2_customer_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        customerPickerRender('');
        qEl.focus();
    }
    function customerPickerClose() {
        var modal = document.getElementById('sr2_customer_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function customerPickerRender(q) {
        var listEl = document.getElementById('sr2_customer_pick_list');
        if (!listEl) return;
        var query = String(q || '').trim().toLowerCase();
        var rows = SR2_CUSTOMER_PICK_ROWS.filter(function (r) {
            if (!query) return true;
            var hay = (r.code + ' ' + r.name + ' ' + r.phone).toLowerCase();
            return hay.indexOf(query) !== -1;
        });
        listEl.innerHTML = '';
        if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
        rows.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.textContent = (r.code ? r.code + ' — ' : '') + r.name + (r.phone ? ' (' + r.phone + ')' : '') + ' [رصيد ' + fmt3(r.balance) + ']';
            li.addEventListener('dblclick', function () { selectCustomer(r.id); customerPickerClose(); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { selectCustomer(r.id); customerPickerClose(); } });
            listEl.appendChild(li);
        });
    }

    function sr2OnChannelChange() {
        var ch = document.getElementById('sr2_channel');
        var lab = document.getElementById('sr2_customer_name_label');
        if (!ch || !lab) return;
        lab.textContent = ch.value === 'credit' ? 'اسم العميل (مطلوب للآجل)' : 'اسم العميل';
    }

    function lineRowHtml() {
        return '<td class="pur-col-idx"></td>' +
            '<td><input type="text" class="sr2-code admin-inp" placeholder="كود أو باركود" dir="ltr" lang="en" autocomplete="off" style="width:100%;">' +
            '<input type="hidden" class="sr2-product-id" value="">' +
            '<input type="hidden" class="sr2-variant-id" value="0">' +
            '<input type="hidden" class="sr2-cost" value=""></td>' +
            '<td><input type="text" class="sr2-name admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>' +
            '<td><input type="text" class="sr2-var-label admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>' +
            '<td><input type="number" class="sr2-qty admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>' +
            '<td><input type="number" class="sr2-price admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>' +
            '<td><input type="text" class="sr2-line-disc admin-inp admin-inp-discount" value="" placeholder="' + fmtZero() + '" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>' +
            '<td><input type="text" class="sr2-line-total admin-inp-money" value="' + fmtZero() + '" readonly data-money-allow-zero tabindex="0" dir="ltr" lang="en"></td>' +
            '<td><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
    }

    function addLine() {
        var tb = document.getElementById('sr2_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'sr2-line';
        tr.innerHTML = lineRowHtml();
        tb.appendChild(tr);
        renumberRows();
        recalcAll();
    }

    function clearLineRow(tr) {
        if (sr2ProductPick) sr2ProductPick.clearLine(tr);
        var qEl = tr.querySelector('.sr2-qty');
        if (qEl) {
            qEl.value = '1';
            qEl.removeAttribute('data-max-qty');
            qEl.removeAttribute('title');
        }
        var discEl = tr.querySelector('.sr2-line-disc');
        if (discEl) discEl.value = '';
        var costEl = tr.querySelector('.sr2-cost');
        if (costEl) costEl.value = '';
    }

    function removeLine(btn) {
        var tb = document.getElementById('sr2_lines_body');
        if (!tb) return;
        if (tb.querySelectorAll('tr').length <= 1) {
            clearLineRow(btn.closest('tr'));
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
        var tb = document.getElementById('sr2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        }
    }

    function rowIsBlank(tr) {
        var pid = parseInt(tr.querySelector('.sr2-product-id').value, 10) || 0;
        if (pid > 0) return false;
        return (tr.querySelector('.sr2-code').value || '').trim() === '';
    }

    function rowIsComplete(tr) {
        var pid = parseInt(tr.querySelector('.sr2-product-id').value, 10) || 0;
        if (pid <= 0) return false;
        var q = parseInt(tr.querySelector('.sr2-qty').value, 10) || 0;
        if (q < 1) return false;
        var priceEl = tr.querySelector('.sr2-price');
        return !!(priceEl && String(priceEl.value || '').trim() !== '');
    }

    function sr2ClampQtyInput(inp) {
        if (!inp) return;
        var maxQ = parseInt(inp.getAttribute('data-max-qty') || '0', 10) || 0;
        if (maxQ <= 0) return;
        var q = parseInt(inp.value, 10) || 0;
        if (q > maxQ) inp.value = String(maxQ);
        else if (q < 1 && inp.value !== '') inp.value = '1';
    }

    function trimExtraTrailing() {
        var tb = document.getElementById('sr2_lines_body');
        if (!tb) return;
        for (;;) {
            var rows = tb.querySelectorAll('tr');
            if (rows.length < 2) return;
            var a = rows[rows.length - 2];
            var b = rows[rows.length - 1];
            if (rowIsBlank(a) && rowIsBlank(b)) {
                a.remove();
                renumberRows();
            } else return;
        }
    }

    function syncTrailing() {
        trimExtraTrailing();
        var tb = document.getElementById('sr2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        if (rows.length === 0) { addLine(); return; }
        if (rowIsComplete(rows[rows.length - 1])) addLine();
    }

    function sr2FillLineRow(tr, item) {
        var pickRow = sr2ProductPick ? sr2ProductPick.findPickRowByIds(item.product_id, item.variant_id || 0) : null;
        if (pickRow && sr2ProductPick) {
            sr2ProductPick.applyPick(tr, pickRow);
            var priceEl = tr.querySelector('.sr2-price');
            if (priceEl) priceEl.value = fmt3(item.price != null ? item.price : pickRow.price);
            var costEl = tr.querySelector('.sr2-cost');
            if (costEl) costEl.value = String(item.cost != null ? item.cost : (pickRow.cost || 0));
        } else if (sr2ProductPick) {
            sr2ProductPick.clearLine(tr);
            var pidEl = tr.querySelector('.sr2-product-id');
            var vidEl = tr.querySelector('.sr2-variant-id');
            if (pidEl) pidEl.value = String(item.product_id || '');
            if (vidEl) vidEl.value = String(item.variant_id || '0');
            var priceEl2 = tr.querySelector('.sr2-price');
            if (priceEl2) priceEl2.value = fmt3(item.price || 0);
        }
        var qEl = tr.querySelector('.sr2-qty');
        if (qEl) {
            qEl.value = String(item.qty || 1);
            var avail = item.qty_available != null ? parseInt(String(item.qty_available), 10) : 0;
            if (avail > 0) {
                qEl.setAttribute('data-max-qty', String(avail));
                qEl.setAttribute('title', 'الحد الأقصى: ' + String(avail));
            } else {
                qEl.removeAttribute('data-max-qty');
                qEl.removeAttribute('title');
            }
        }
        var dEl = tr.querySelector('.sr2-line-disc');
        if (dEl) {
            var ld = parseFloat(item.line_discount || 0) || 0;
            dEl.value = ld > 0 ? fmt3(ld) : '';
        }
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

    function recalcAll() {
        var tb = document.getElementById('sr2_lines_body');
        if (!tb) return;
        var netTotal = 0;
        var grossSubtotal = 0;
        var firstDiscError = '';
        var rows = tb.querySelectorAll('tr.sr2-line');
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var q = parseInt(r.querySelector('.sr2-qty').value, 10) || 0;
            var price = parseFloat(r.querySelector('.sr2-price').value) || 0;
            var lineGross = q * price;
            var discEl = r.querySelector('.sr2-line-disc');
            var disc = parseDiscount((discEl && discEl.value || '').trim(), lineGross);
            // المردود: الخصم يمكن أن يساوي إجمالي الصنف (الصافي صفر) لكن لا يتجاوزه.
            var invalid = (disc > 0) && (disc > lineGross + SR2_MONEY_EPSILON);
            if (discEl) discEl.style.border = invalid ? '1px solid #dc2626' : '';
            if (invalid && !firstDiscError) firstDiscError = 'خصم الصنف في السطر ' + (i + 1) + ' أكبر من إجمالي الصنف — يمكن أن يساويه فقط.';
            if (disc > lineGross) disc = lineGross;
            var lineNet = Math.max(0, lineGross - disc);
            var ltEl = r.querySelector('.sr2-line-total');
            if (ltEl) ltEl.value = fmt3(lineNet);
            grossSubtotal += lineGross;
            netTotal += lineNet;
        }
        var errEl = document.getElementById('sr2_line_disc_error');
        if (errEl) { errEl.innerHTML = firstDiscError; errEl.style.display = firstDiscError ? '' : 'none'; }
        var totalDiscount = Math.max(0, grossSubtotal - netTotal);
        var stEl = document.getElementById('sr2_subtotal');
        var dtEl = document.getElementById('sr2_discount_total');
        var ntEl = document.getElementById('sr2_net_total');
        if (stEl) stEl.textContent = fmt3(grossSubtotal);
        if (dtEl) dtEl.textContent = fmt3(totalDiscount);
        if (ntEl) ntEl.textContent = fmt3(netTotal);
        sr2RenderTotals();
    }

    function sr2RenderTotals() {
        if (!(window.orangeSalesDocUi && window.orangeSalesDocUi.renderDocTotals)) return;
        window.orangeSalesDocUi.renderDocTotals({
            prefix: 'sr2', context: 'sales',
            subtotalId: 'sr2_subtotal', discountId: 'sr2_discount_total', netId: 'sr2_net_total',
            collectExtra: (typeof sr2CollectExtraLines === 'function') ? sr2CollectExtraLines : function () { return []; },
            unit: <?php echo json_encode($srCurrencyUnit, JSON_UNESCAPED_UNICODE); ?>,
            screenExtraId: 'sr2_screen_extra', grandOutId: 'sr2_grand_total', grandLabelId: 'sr2_grand_label',
            intableId: 'sr2_sd_intable_totals', intableColspan: 7,
            foldTotalId: 'sr2_sd_print_gross', foldDiscountId: 'sr2_sd_print_disc', foldNetId: 'sr2_sd_print_net',
            labels: {
                items: { ar: 'إجمالي الأصناف', en: 'Items Total' },
                items_disc: { ar: 'خصم الأصناف', en: 'Items Discount' },
                net_items: { ar: 'صافي الأصناف', en: 'Net Items' },
                vat: { ar: 'ضريبة القيمة المضافة', en: 'VAT' }
            },
            finalLabel: { ar: 'إجمالي المردود', en: 'Total Refund' }
        });
    }

    function parseOrderRef(raw) {
        raw = String(raw || '').trim();
        if (!raw) return 0;
        var m = /^INV-C-(\d+)$/i.exec(raw);
        if (m) return parseInt(m[1], 10) || 0;
        m = /^INV-O-(\d+)$/i.exec(raw);
        if (m) return parseInt(m[1], 10) || 0;
        if (/^\d+$/.test(raw)) return parseInt(raw, 10) || 0;
        return 0;
    }

    function sr2ResolvedOrderId() {
        var hid = parseInt(document.getElementById('sr2_order_id').value, 10) || 0;
        if (hid > 0) return hid;
        return parseOrderRef(document.getElementById('sr2_order_ref').value || '');
    }

    function sr2ClearOrderLink() {
        var hid = document.getElementById('sr2_order_id');
        if (hid) hid.value = '0';
        sr2MarketChannelId = 0;
    }

    function sr2SetDocSerial(value) {
        var el = document.getElementById('sr2_doc_serial');
        if (el) el.value = String(value || '');
    }

    function sr2SyncToolbar() {
        var pb = document.getElementById('sr2_btn_print');
        if (pb) {
            if (SR2_PRINT_TUNING) {
                pb.disabled = false;
                pb.title = 'طباعة (وضع ضبط التنسيق — مؤقت)';
            } else {
                pb.disabled = browseReturnId <= 0;
                pb.title = browseReturnId > 0 ? 'طباعة المردود المعروض' : 'افتح مردوداً محفوظاً للطباعة';
            }
        }
        var sb = document.getElementById('sr2_btn_save');
        if (sb) {
            sb.disabled = sr2ViewMode || !SR2_CAPS.can_edit;
            sb.title = sr2ViewMode ? 'وضع العرض — استخدم «مردود جديد»' : 'حفظ مردود جديد';
        }
        var lbl = document.getElementById('sr2_browse_label');
        if (lbl) {
            lbl.textContent = browseReturnId > 0
                ? ('— عرض ' + (document.getElementById('sr2_doc_serial') && document.getElementById('sr2_doc_serial').value || ('SR-' + browseReturnId)))
                : '';
        }
        if (browseReturnId <= 0) sr2SetDocSerial(SR2_DOC_SERIAL_PREVIEW || '');
        if (sr2EditLockCtl) sr2EditLockCtl.refresh();
    }

    function sr2SyncViewModeBanner() {
        var ban = document.getElementById('sr2_view_mode_banner');
        if (ban) ban.style.display = sr2ViewMode && browseReturnId > 0 ? '' : 'none';
    }

    function sr2SetViewMode(on) {
        sr2ViewMode = !!on;
        sr2SyncViewModeBanner();
        var card = document.querySelector('.jv-print-area');
        if (card) {
            card.querySelectorAll('input, select, button.admin-doc-line-remove').forEach(function (el) {
                if (el.id === 'sr2_btn_new' || el.id === 'sr2_btn_print' || el.closest('.jv-voucher-nav-btns')
                    || el.id === 'sr2_btn_search' || el.id === 'sr2_btn_retrieve' || el.id === 'sr2_btn_save'
                    || el.id === 'sr2_customer_code') {
                    return;
                }
                el.disabled = sr2ViewMode;
            });
        }
        sr2SyncToolbar();
    }

    function sr2ApplyOrderRetrievePayload(res) {
        if (!res || !res.success || !res.order) {
            alert((res && res.message) || 'تعذر استرجاع بنود الطلب');
            return;
        }
        var o = res.order;
        sr2SetLoyaltyClawbackNote(null);
        var oid = parseInt(String(o.id || '0'), 10) || 0;
        var hid = document.getElementById('sr2_order_id');
        if (hid) hid.value = oid > 0 ? String(oid) : '0';
        var refEl = document.getElementById('sr2_order_ref');
        if (refEl) refEl.value = o.reference || (oid > 0 ? ('INV-C-' + oid) : '');
        if (parseInt(String(o.customer_id || '0'), 10) > 0) {
            selectCustomer(parseInt(String(o.customer_id), 10));
        }
        var chEl = document.getElementById('sr2_channel');
        if (chEl) chEl.value = o.channel || 'cash';
        sr2MarketChannelId = parseInt(String(o.channel_id || '0'), 10) || 0;
        sr2OnChannelChange();
        var tb = document.getElementById('sr2_lines_body');
        if (tb) {
            tb.innerHTML = '';
            var items = res.items || [];
            if (!items.length) {
                alert('لا توجد كميات متبقية للإرجاع من هذه الفاتورة');
                addLine();
            } else {
                items.forEach(function (item) {
                    addLine();
                    var rows = tb.querySelectorAll('tr.sr2-line');
                    sr2FillLineRow(rows[rows.length - 1], item);
                });
            }
            syncTrailing();
        }
        recalcAll();
    }

    function sr2RetrieveFromOrder() {
        if (sr2ViewMode) return;
        var refRaw = (document.getElementById('sr2_order_ref').value || '').trim();
        if (!refRaw) {
            alert('أدخل رقم فاتورة مبيعات صالحاً (INV-C- أو رقم) في خانة فاتورة المبيعات المرجعية.');
            return;
        }
        var btn = document.getElementById('sr2_btn_retrieve');
        if (btn) btn.disabled = true;
        fetch('/admin/api/sales_returns/retrieve_from_order.php?reference=' + encodeURIComponent(refRaw), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (typeof orangeAdminOfferSuggestOnFailure === 'function' && !res.success && orangeAdminOfferSuggestOnFailure(res, 'تعذر الاسترجاع')) {
                return;
            }
            sr2ApplyOrderRetrievePayload(res);
        }).catch(function (e) { alert(e.message || String(e)); }).finally(function () {
            if (btn && !sr2ViewMode) btn.disabled = false;
        });
    }

    function sr2FormatEnteredDisplay(raw) {
        var s = String(raw || '').trim();
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
        var d = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (d) return d[3] + '/' + d[2] + '/' + d[1];
        return s;
    }

    function sr2SyncPrintExtras() {
        var setTxt = function (id, val) {
            var el = document.getElementById(id);
            if (!el) return;
            var v = String(val || '').trim();
            el.textContent = v !== '' ? v : '—';
        };
        var docDateEl = document.getElementById('sr2_document_date');
        setTxt('sr2_sd_print_docdate', docDateEl ? String(docDateEl.value || '').trim() : '');
        var refEl = document.getElementById('sr2_order_ref');
        setTxt('sr2_sd_print_ref_invoice', refEl ? refEl.value : '');
        var nameEl = document.getElementById('sr2_customer_name');
        setTxt('sr2_sd_print_party_name', nameEl ? nameEl.value : '');
        var cust = customerById(currentCustomerId);
        setTxt('sr2_sd_print_party_phone', cust ? cust.phone : '');
        setTxt('sr2_sd_print_party_area', cust ? cust.area : '');
        setTxt('sr2_sd_print_party_address', cust ? cust.address : '');

        var notesEl = document.getElementById('sr2_notes');
        var notesBox = document.getElementById('sr2_sd_print_notes');
        if (notesBox) notesBox.textContent = notesEl ? String(notesEl.value || '').trim() : '';

        sr2RenderTotals();

        orangeSalesDocSetPhoneCells('sr2_sd_print_phone', SR2_COMPANY_PHONE, SR2_CHANNEL_WA, sr2MarketChannelId);
    }

    function sr2ApplyReturnPayload(res) {
        if (!res || !res.success || !res.sales_return) {
            alert((res && res.message) || 'تعذر تحميل المردود');
            return;
        }
        var p = res.sales_return;
        browseReturnId = parseInt(String(p.id || '0'), 10) || 0;
        if (window.orangeSalesDocUi && window.orangeSalesDocUi.setDocQr) window.orangeSalesDocUi.setDocQr('sr2', 'sales_return', browseReturnId);
        sr2MarketChannelId = parseInt(String(p.channel_id || '0'), 10) || 0;
        sr2SetDocSerial(p.return_number || ('SR-' + browseReturnId));
        selectCustomer(parseInt(String(p.customer_id || '0'), 10) || 0);
        var chEl = document.getElementById('sr2_channel');
        if (chEl) {
            var ch = String(p.type || p.channel || 'cash');
            if (ch !== 'cash' && ch !== 'online' && ch !== 'credit') ch = 'cash';
            chEl.value = ch;
        }
        sr2OnChannelChange();
        var notesEl = document.getElementById('sr2_notes');
        if (notesEl) notesEl.value = p.notes || '';
        var docDateEl = document.getElementById('sr2_document_date');
        if (docDateEl) docDateEl.value = (p.document_date ? orangeIsoDateToDmy(String(p.document_date).substr(0, 10)) : '');
        var entryDateEl = document.getElementById('sr2_entry_date');
        if (entryDateEl) entryDateEl.value = p.created_at_display || (p.created_at ? sr2FormatEnteredDisplay(p.created_at) : '');
        var oid = parseInt(String(p.order_id || '0'), 10) || 0;
        var hid = document.getElementById('sr2_order_id');
        if (hid) hid.value = oid > 0 ? String(oid) : '0';
        var refEl = document.getElementById('sr2_order_ref');
        if (refEl) {
            refEl.value = (p.order_reference && String(p.order_reference).trim() !== '')
                ? String(p.order_reference)
                : (oid > 0 ? ('INV-C-' + oid) : '');
        }
        var tb = document.getElementById('sr2_lines_body');
        if (tb) {
            tb.innerHTML = '';
            var items = res.items || [];
            if (!items.length) addLine();
            else {
                items.forEach(function (item) {
                    addLine();
                    var rows = tb.querySelectorAll('tr.sr2-line');
                    sr2FillLineRow(rows[rows.length - 1], item);
                });
            }
            syncTrailing();
        }
        sr2LoadExtraLines(res.extra_lines || []);
        sr2SetLoyaltyClawbackNote(res.loyalty_clawback || null);
        recalcAll();
        sr2SetViewMode(true);
        if (sr2EditLockCtl) sr2EditLockCtl.refresh();
    }

    function sr2LoadReturn(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        if (id <= 0) return;
        fetch('/admin/api/sales_returns/get.php?sales_return_id=' + encodeURIComponent(String(id)), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            sr2ApplyReturnPayload(res);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function sr2Nav(where) {
        if (!SR2_NAV_READY) return;
        postJSON('/admin/api/sales_returns/browse.php', {
            action: 'nav',
            where: where,
            current_id: browseReturnId || 0
        }).then(function (r) {
            if (!r.success || !r.id) {
                alert(r.message || 'لا يوجد مردود');
                return;
            }
            sr2LoadReturn(r.id);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function sr2SearchOpen() {
        var m = document.getElementById('sr2_search_modal');
        if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
    }
    function sr2SearchClose() {
        var m = document.getElementById('sr2_search_modal');
        if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
    }
    function sr2SearchRun() {
        var idFrom = parseInt(document.getElementById('sr2_search_id_from').value, 10) || 0;
        var idTo = parseInt(document.getElementById('sr2_search_id_to').value, 10) || 0;
        var dateFrom = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('sr2_search_date_from')) || '' : '';
        var dateTo = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('sr2_search_date_to')) || '' : '';
        var ref = (document.getElementById('sr2_search_ref').value || '').trim();
        var orderRef = (document.getElementById('sr2_search_order_ref').value || '').trim();
        var customerQ = (document.getElementById('sr2_search_customer').value || '').trim();
        var notes = (document.getElementById('sr2_search_notes').value || '').trim();
        var tbody = document.getElementById('sr2_search_results');
        tbody.innerHTML = '<tr><td colspan="6">جاري البحث…</td></tr>';
        var payload = { action: 'search' };
        if (idFrom > 0) payload.id_from = idFrom;
        if (idTo > 0) payload.id_to = idTo;
        if (dateFrom) payload.date_from = dateFrom;
        if (dateTo) payload.date_to = dateTo;
        if (ref) payload.reference = ref;
        if (orderRef) payload.order_ref = orderRef;
        if (customerQ) payload.customer = customerQ;
        if (notes) payload.notes = notes;
        postJSON('/admin/api/sales_returns/browse.php', payload).then(function (r) {
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
                    + '<td>' + esc(v.customer_name || '') + '</td>'
                    + '<td dir="ltr">' + esc(v.order_reference || '') + '</td>'
                    + '<td dir="ltr">' + fmt3(v.total || 0) + '</td>';
                tr.addEventListener('dblclick', function () { sr2LoadReturn(v.id); sr2SearchClose(); });
                tbody.appendChild(tr);
            });
        }).catch(function (e) {
            tbody.innerHTML = '<tr><td colspan="6">' + esc(e.message || String(e)) + '</td></tr>';
        });
    }

    function sr2ResetNew() {
        browseReturnId = 0;
        location.reload();
    }

    var SR2_SALES_LINE_KINDS = <?php echo json_encode($sr2SalesLineKinds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var sr2ExtraPickSource = 'presets';
    var sr2ExtraPickSelected = null;

    function sr2ExtraLineKindLabel(key) {
        key = String(key || '');
        for (var i = 0; i < SR2_SALES_LINE_KINDS.length; i++) {
            if (SR2_SALES_LINE_KINDS[i].key === key) return SR2_SALES_LINE_KINDS[i].label_ar || key;
        }
        return key;
    }

    function sr2ExtraAccountLabel(row) {
        var code = String(row.account_code || '').trim();
        var name = String(row.account_name || row.label_ar || '').trim();
        if (code && name) return code + ' — ' + name;
        return name || code || ('#' + (row.account_id || ''));
    }

    function sr2ExtraLineRowHtml() {
        return '<td class="pur-col-idx"></td>'
            + '<td><span class="sr2-extra-account-label"></span>'
            + '<input type="hidden" class="sr2-extra-account-id" value="">'
            + '<input type="hidden" class="sr2-extra-line-kind" value="">'
            + '<input type="hidden" class="sr2-extra-preset-id" value="0"></td>'
            + '<td><input type="number" class="sr2-extra-amount admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>'
            + '<td><input type="text" class="sr2-extra-label admin-inp" placeholder="اختياري" dir="auto"></td>'
            + '<td class="jv-print-hide"><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
    }

    function sr2RenumberExtraRows() {
        var tb = document.getElementById('sr2_extra_lines_body');
        if (!tb) return;
        tb.querySelectorAll('tr.sr2-extra-line').forEach(function (tr, i) {
            var c = tr.querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        });
    }

    function sr2FillExtraLineRow(tr, row) {
        if (!tr || !row) return;
        tr.querySelector('.sr2-extra-account-id').value = String(parseInt(String(row.account_id || '0'), 10) || 0);
        tr.querySelector('.sr2-extra-line-kind').value = String(row.line_kind || '');
        tr.querySelector('.sr2-extra-preset-id').value = String(parseInt(String(row.preset_id || '0'), 10) || 0);
        var lbl = tr.querySelector('.sr2-extra-account-label');
        if (lbl) lbl.textContent = sr2ExtraAccountLabel(row);
        var amt = tr.querySelector('.sr2-extra-amount');
        if (amt) amt.value = fmt3(row.amount || 0);
        var la = tr.querySelector('.sr2-extra-label');
        if (la) la.value = row.label_ar || '';
    }

    function sr2AddExtraLine(row) {
        var tb = document.getElementById('sr2_extra_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'sr2-extra-line';
        tr.innerHTML = sr2ExtraLineRowHtml();
        tb.appendChild(tr);
        if (row) sr2FillExtraLineRow(tr, row);
        var rm = tr.querySelector('.admin-doc-line-remove');
        if (rm) rm.addEventListener('click', function () { tr.remove(); sr2RenumberExtraRows(); sr2RenderTotals(); });
        var am = tr.querySelector('.sr2-extra-amount');
        if (am) am.addEventListener('input', function () { sr2RenderTotals(); });
        var la = tr.querySelector('.sr2-extra-label');
        if (la) la.addEventListener('input', function () { sr2RenderTotals(); });
        sr2RenumberExtraRows();
        sr2RenderTotals();
    }

    function sr2ClearExtraLines() {
        var tb = document.getElementById('sr2_extra_lines_body');
        if (tb) tb.innerHTML = '';
    }

    function sr2LoadExtraLines(lines) {
        sr2ClearExtraLines();
        (lines || []).forEach(function (row) { sr2AddExtraLine(row); });
    }

    function sr2SetLoyaltyClawbackNote(cb) {
        var el = document.getElementById('sr2_loyalty_clawback_note');
        if (!el) return;
        var tp = cb ? (parseInt(String(cb.total_points || '0'), 10) || 0) : 0;
        if (tp > 0) {
            el.textContent = 'ملاحظة ولاء: تم استرداد ' + tp + ' نقطة كانت مكتسبة من الطلب المرتبط، بسبب هذا المردود.';
            el.style.display = '';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    function sr2CollectExtraLines() {
        var out = [];
        var tb = document.getElementById('sr2_extra_lines_body');
        if (!tb) return out;
        tb.querySelectorAll('tr.sr2-extra-line').forEach(function (tr) {
            var accountId = parseInt(tr.querySelector('.sr2-extra-account-id').value, 10) || 0;
            var lineKind = (tr.querySelector('.sr2-extra-line-kind').value || '').trim();
            var amount = parseFloat(tr.querySelector('.sr2-extra-amount').value) || 0;
            if (accountId <= 0 || !lineKind || amount <= 0) return;
            out.push({
                account_id: accountId,
                line_kind: lineKind,
                amount: amount,
                label_ar: (tr.querySelector('.sr2-extra-label').value || '').trim(),
                show_on_print: 1,
                preset_id: parseInt(tr.querySelector('.sr2-extra-preset-id').value, 10) || 0
            });
        });
        return out;
    }

    function sr2ExtraPickSetSource(source) {
        sr2ExtraPickSource = source === 'coa' ? 'coa' : 'presets';
        sr2ExtraPickSelected = null;
        document.getElementById('sr2_extra_tab_presets').classList.toggle('is-active', sr2ExtraPickSource === 'presets');
        document.getElementById('sr2_extra_tab_coa').classList.toggle('is-active', sr2ExtraPickSource === 'coa');
        document.getElementById('sr2_extra_line_kind_wrap').hidden = sr2ExtraPickSource !== 'coa';
        document.getElementById('sr2_extra_add_to_presets').hidden = sr2ExtraPickSource !== 'coa';
        sr2ExtraPickRender(document.getElementById('sr2_extra_pick_q').value || '');
    }

    function sr2ExtraPickOpen() {
        if (sr2ViewMode) { alert('وضع العرض — استخدم «مردود جديد»'); return; }
        sr2ExtraPickSetSource('presets');
        var modal = document.getElementById('sr2_extra_pick_modal');
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        document.getElementById('sr2_extra_pick_q').value = '';
        sr2ExtraPickRender('');
        document.getElementById('sr2_extra_pick_q').focus();
    }

    function sr2ExtraPickClose() {
        var modal = document.getElementById('sr2_extra_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
        sr2ExtraPickSelected = null;
    }

    function sr2ExtraPickConfirm(row) {
        if (!row) return;
        if (sr2ExtraPickSource === 'coa') {
            var lineKind = (document.getElementById('sr2_extra_line_kind').value || '').trim();
            if (!lineKind) { alert('اختر نوع البند.'); return; }
            sr2AddExtraLine({
                account_id: row.account_id || row.id,
                account_code: row.account_code || row.code || '',
                account_name: row.account_name || row.name || '',
                line_kind: lineKind, amount: 0, show_on_print: false,
                label_ar: row.name || row.account_name || '', preset_id: 0
            });
        } else {
            sr2AddExtraLine({
                account_id: row.account_id, account_code: row.account_code, account_name: row.account_name,
                line_kind: row.line_kind, amount: 0, show_on_print: !!row.default_show_on_print,
                label_ar: row.label_ar || row.account_name || '', preset_id: row.id || 0
            });
        }
        sr2ExtraPickClose();
    }

    function sr2ExtraPickRender(q) {
        var listEl = document.getElementById('sr2_extra_pick_list');
        if (!listEl) return;
        listEl.innerHTML = '<li class="gl-pick-empty">جاري التحميل…</li>';
        q = String(q || '').trim();
        if (sr2ExtraPickSource === 'presets') {
            var url = '/admin/api/invoice-ancillary/presets-list.php?invoice_context=sales' + (q ? ('&q=' + encodeURIComponent(q)) : '');
            fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    listEl.innerHTML = '';
                    var rows = (res && res.presets) ? res.presets : [];
                    if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
                    rows.forEach(function (row) {
                        var li = document.createElement('li');
                        li.className = 'gl-pick-item'; li.setAttribute('role', 'button'); li.tabIndex = 0;
                        li.textContent = sr2ExtraAccountLabel(row) + ' — ' + sr2ExtraLineKindLabel(row.line_kind);
                        li.addEventListener('dblclick', function () { sr2ExtraPickConfirm(row); });
                        li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') sr2ExtraPickConfirm(row); });
                        listEl.appendChild(li);
                    });
                })
                .catch(function (e) { listEl.innerHTML = '<li class="gl-pick-empty">' + esc(e.message || String(e)) + '</li>'; });
            return;
        }
        fetch('/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || ''), { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                listEl.innerHTML = '';
                var rows = (res && res.accounts) ? res.accounts : [];
                if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
                rows.forEach(function (row) {
                    var li = document.createElement('li');
                    li.className = 'gl-pick-item'; li.setAttribute('role', 'button'); li.tabIndex = 0;
                    var mapped = { account_id: row.id, account_code: row.code, account_name: row.name };
                    li.textContent = sr2ExtraAccountLabel(mapped);
                    li.addEventListener('click', function () {
                        sr2ExtraPickSelected = mapped;
                        listEl.querySelectorAll('.gl-pick-item').forEach(function (n) { n.classList.remove('is-selected'); });
                        li.classList.add('is-selected');
                    });
                    li.addEventListener('dblclick', function () { sr2ExtraPickConfirm(mapped); });
                    li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') sr2ExtraPickConfirm(mapped); });
                    listEl.appendChild(li);
                });
            })
            .catch(function (e) { listEl.innerHTML = '<li class="gl-pick-empty">' + esc(e.message || String(e)) + '</li>'; });
    }

    function sr2ExtraAddToPresets() {
        if (sr2ExtraPickSource !== 'coa' || !sr2ExtraPickSelected) { alert('اختر حساباً من الدليل أولاً.'); return; }
        var lineKind = (document.getElementById('sr2_extra_line_kind').value || '').trim();
        if (!lineKind) { alert('اختر نوع البند.'); return; }
        postJSON('/admin/api/invoice-ancillary/preset-save.php', {
            account_id: sr2ExtraPickSelected.account_id, line_kind: lineKind,
            invoice_context: 'sales', label_ar: sr2ExtraPickSelected.account_name || '', default_show_on_print: false
        }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'تعذر الحفظ'); return; }
            alert(res.message || 'تمت الإضافة');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function save() {
        if (!SR2_CAPS.can_edit) {
            alert('لا تملك صلاحية تعديل مردود المبيعات');
            return;
        }
        if (sr2ViewMode) return;
        var channel = document.getElementById('sr2_channel').value;
        var customerId = parseInt(document.getElementById('sr2_customer_id').value, 10) || 0;
        var orderId = sr2ResolvedOrderId();
        if (orderId <= 0) {
            alert('أدخل فاتورة مبيعات مرجعية صالحة أو استخدم «استرجاع» لتحميل البنود.');
            return;
        }
        var notes = (document.getElementById('sr2_notes').value || '').trim();
        if (channel === 'credit' && customerId <= 0) {
            alert('مردود الآجل يتطلّب عميلاً.');
            return;
        }
        var tb = document.getElementById('sr2_lines_body');
        if (!tb) { alert('لا توجد أصناف'); return; }
        var rows = tb.querySelectorAll('tr.sr2-line');
        var items = [];
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var pid = parseInt(r.querySelector('.sr2-product-id').value, 10) || 0;
            var vid = parseInt(r.querySelector('.sr2-variant-id').value, 10) || 0;
            var q = parseInt(r.querySelector('.sr2-qty').value, 10) || 0;
            var price = parseFloat(r.querySelector('.sr2-price').value) || 0;
            var maxQ = parseInt(r.querySelector('.sr2-qty').getAttribute('data-max-qty') || '0', 10) || 0;
            if (maxQ > 0 && q > maxQ) {
                alert('الكمية في السطر ' + (i + 1) + ' تتجاوز المتاح (' + maxQ + ').');
                return;
            }
            if (!pid || q < 1) continue;
            var lineGross = q * price;
            var disc = parseDiscount((r.querySelector('.sr2-line-disc').value || '').trim(), lineGross);
            if (disc > lineGross + SR2_MONEY_EPSILON) {
                alert('خصم الصنف في السطر ' + (i + 1) + ' أكبر من إجمالي الصنف. صحّح الخصم قبل الحفظ.');
                return;
            }
            var row = { product_id: pid, variant_id: vid, qty: q, price: price, line_discount: disc };
            var costRaw = (r.querySelector('.sr2-cost') && r.querySelector('.sr2-cost').value) || '';
            var costNum = parseFloat(costRaw);
            if (!isNaN(costNum) && costNum > (SR2_MONEY_EPSILON / 2)) row.cost = costNum;
            items.push(row);
        }
        if (!items.length) {
            alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة');
            return;
        }
        var payload = {
            customer_id: customerId,
            channel: channel,
            notes: notes,
            document_date: (document.getElementById('sr2_document_date') ? (orangeGetDmyValueAsIso(document.getElementById('sr2_document_date')) || '') : ''),
            items: items,
            extra_lines: sr2CollectExtraLines()
        };
        if (orderId > 0) payload.order_id = orderId;
        postJSON('/admin/api/sales_returns/create.php', payload).then(function (res) {
            if (res.success) {
                var sr2SavedId = (res && res.sales_return_id) ? (parseInt(String(res.sales_return_id), 10) || 0) : (browseReturnId || 0);
                var sr2AfterSave = function () {
                    if (sr2SavedId > 0) { sr2LoadReturn(sr2SavedId); } else { location.reload(); }
                };
                if (typeof orangeAdminOfferOpenGlVoucherAfterSave === 'function') {
                    orangeAdminOfferOpenGlVoucherAfterSave(res, sr2AfterSave);
                } else {
                    alert(res.message || 'تم حفظ مردود المبيعات');
                    sr2AfterSave();
                }
                return;
            }
            if (typeof orangeAdminOfferSuggestOnFailure === 'function' && orangeAdminOfferSuggestOnFailure(res, 'فشل')) return;
            alert(res.message || 'فشل');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function init() {
        if (window.OrangePurchaseDocProductPick) {
            sr2ProductPick = window.OrangePurchaseDocProductPick.create({
                pickRows: SR2_PICK_ROWS,
                codeClass: 'sr2-code',
                fmtMoney: fmt3,
                showStock: true,
                isViewMode: function () { return sr2ViewMode; },
                modalIds: {
                    root: 'sr2_product_pick_modal',
                    backdrop: 'sr2_product_pick_backdrop',
                    filter: 'sr2_product_pick_filter',
                    body: 'sr2_product_pick_body'
                },
                selectors: {
                    code: '.sr2-code',
                    productId: '.sr2-product-id',
                    variantId: '.sr2-variant-id',
                    name: '.sr2-name',
                    varLabel: '.sr2-var-label',
                    cost: '.sr2-price'
                },
                onApplyPick: function (tr, row) {
                    var priceEl = tr.querySelector('.sr2-price');
                    if (priceEl) priceEl.value = fmt3(row.price || 0);
                    var costEl = tr.querySelector('.sr2-cost');
                    if (costEl) costEl.value = String(row.cost || 0);
                },
                onAfterResolve: function () { recalcAll(); }
            });
            sr2ProductPick.bindModal();
        }

        var codeEl = document.getElementById('sr2_customer_code');
        if (codeEl) {
            codeEl.addEventListener('dblclick', function (e) { e.preventDefault(); customerPickerOpen(); });
            codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); customerPickerOpen(); } });
        }
        document.getElementById('sr2_customer_pick_backdrop').addEventListener('click', customerPickerClose);
        document.getElementById('sr2_customer_pick_close').addEventListener('click', customerPickerClose);
        var custPickQ = document.getElementById('sr2_customer_pick_q');
        var custTimer = null;
        if (custPickQ) {
            custPickQ.addEventListener('input', function () {
                if (custTimer) clearTimeout(custTimer);
                custTimer = setTimeout(function () { customerPickerRender(custPickQ.value || ''); }, 180);
            });
        }
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') customerPickerClose(); });

        document.getElementById('sr2_channel').addEventListener('change', sr2OnChannelChange);
        document.getElementById('sr2_btn_save').addEventListener('click', save);
        // زر «مردود جديد» مربوط عبر onclick مباشر حتى يعمل حتى لو فشل ربط addEventListener.
        document.getElementById('sr2_btn_retrieve').addEventListener('click', sr2RetrieveFromOrder);

        var sr2AddExtraBtn = document.getElementById('sr2_btn_add_extra');
        if (sr2AddExtraBtn) sr2AddExtraBtn.addEventListener('click', sr2ExtraPickOpen);
        var sr2ExtraBackdrop = document.getElementById('sr2_extra_pick_backdrop');
        if (sr2ExtraBackdrop) sr2ExtraBackdrop.addEventListener('click', sr2ExtraPickClose);
        var sr2ExtraCloseBtn = document.getElementById('sr2_extra_pick_close');
        if (sr2ExtraCloseBtn) sr2ExtraCloseBtn.addEventListener('click', sr2ExtraPickClose);
        var sr2ExtraTabPresets = document.getElementById('sr2_extra_tab_presets');
        if (sr2ExtraTabPresets) sr2ExtraTabPresets.addEventListener('click', function () { sr2ExtraPickSetSource('presets'); });
        var sr2ExtraTabCoa = document.getElementById('sr2_extra_tab_coa');
        if (sr2ExtraTabCoa) sr2ExtraTabCoa.addEventListener('click', function () { sr2ExtraPickSetSource('coa'); });
        var sr2ExtraQ = document.getElementById('sr2_extra_pick_q');
        if (sr2ExtraQ) sr2ExtraQ.addEventListener('input', function () { sr2ExtraPickRender(this.value || ''); });
        var sr2ExtraAddPreset = document.getElementById('sr2_extra_add_to_presets');
        if (sr2ExtraAddPreset) sr2ExtraAddPreset.addEventListener('click', sr2ExtraAddToPresets);
        var orderRefEl = document.getElementById('sr2_order_ref');
        if (orderRefEl) {
            orderRefEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    sr2RetrieveFromOrder();
                }
            });
            orderRefEl.addEventListener('input', function () {
                sr2ClearOrderLink();
            });
        }
        if (window.orangeSalesDocUi) {
            window.orangeSalesDocUi.bindPrintButton('sr2_btn_print', {
                prefix: 'sr2',
                serialElId: 'sr2_doc_serial',
                docLabel: 'مردود مبيعات',
                docKind: 'sales_return',
                printNowDisplay: <?php echo json_encode($sr2DefaultEntryDisp, JSON_UNESCAPED_UNICODE); ?>,
                docId: function () { return browseReturnId; },
                beforePrint: function () {
                    if (!SR2_PRINT_TUNING && browseReturnId <= 0) { alert('افتح مردوداً محفوظاً للطباعة.'); return false; }
                    sr2SyncPrintExtras();
                    return true;
                }
            });
        } else {
            document.getElementById('sr2_btn_print').addEventListener('click', function () {
                if (!SR2_PRINT_TUNING && browseReturnId <= 0) { alert('افتح مردوداً محفوظاً للطباعة.'); return; }
                sr2SyncPrintExtras();
                window.print();
            });
        }
        document.getElementById('sr2_nav_first').addEventListener('click', function () { sr2Nav('first'); });
        document.getElementById('sr2_nav_prev').addEventListener('click', function () { sr2Nav('prev'); });
        document.getElementById('sr2_nav_next').addEventListener('click', function () { sr2Nav('next'); });
        document.getElementById('sr2_nav_last').addEventListener('click', function () { sr2Nav('last'); });
        document.getElementById('sr2_btn_search').addEventListener('click', sr2SearchOpen);
        document.getElementById('sr2_search_btn').addEventListener('click', sr2SearchRun);
        document.getElementById('sr2_search_modal_backdrop').addEventListener('click', sr2SearchClose);

        var tb = document.getElementById('sr2_lines_body');
        if (tb) {
            if (sr2ProductPick) sr2ProductPick.bindLinesBody(tb);
            tb.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('sr2-code')) return;
                if (e.target && e.target.classList.contains('sr2-qty')) sr2ClampQtyInput(e.target);
                recalcAll();
            });
            tb.addEventListener('focusout', function (e) {
                if (e.target && e.target.classList.contains('sr2-code') && sr2ProductPick) {
                    sr2ProductPick.resolveCodeForRow(e.target.closest('tr'));
                    recalcAll();
                }
            });
            tb.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('admin-doc-line-remove')) removeLine(e.target);
            });
            addLine();
        }

        if (SR2_PREFILL_CUSTOMER > 0) selectCustomer(SR2_PREFILL_CUSTOMER);
        if (SR2_PREFILL_ORDER > 0) {
            var refEl = document.getElementById('sr2_order_ref');
            if (refEl) refEl.value = 'INV-C-' + SR2_PREFILL_ORDER;
        }
        sr2OnChannelChange();

        if (window.OrangeEditLock) {
            sr2EditLockCtl = OrangeEditLock.bind({
                prefix: 'sr2',
                docKind: 'sales_return',
                page: 'sales_returns',
                canLock: !!SR2_CAPS.can_lock,
                canUnlock: !!SR2_CAPS.can_unlock,
                countryId: SR2_COUNTRY_ID,
                getEntityId: function () { return browseReturnId; }
            });
        }
        sr2SyncToolbar();
        sr2SetViewMode(false);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
