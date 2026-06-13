<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/sales_doc_product_pick.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';
require_once __DIR__ . '/../../includes/invoice_ancillary_lines.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/sales_doc_channel.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/document_sequences.php';
require_once __DIR__ . '/../../includes/catalog_multicountry_runtime.php';
require_once __DIR__ . '/../../includes/admin_voucher_print_tuning.php';

$pdo = orange_admin_page_pdo();
orange_catalog_backfill_products_country_id($pdo);
orange_catalog_backfill_channels_country_id($pdo);
$sv2Caps = orange_admin_caps_for_page($admin, $pdo, 'company_sales_invoice');

$adminCountryId = orange_admin_context_country_id($pdo);
$adminDefaultPhoneDial = orange_admin_context_phone_dial($pdo);
$adminDefaultCurrency = orange_admin_context_currency_code($pdo);
$adminCurrencyUnit = orange_currency_display_unit($adminDefaultCurrency);
$sv2CustomersCountrySql = orange_sql_country_and_fragment($pdo, 'customers', 'customers', $adminCountryId);
$sv2ChannelsCountrySql = orange_channels_has_country_column($pdo)
    ? orange_sql_country_and_fragment($pdo, 'channels', 'channels', $adminCountryId)
    : '';

$sv2PickRows = orange_sales_doc_product_pick_rows($pdo, $adminCountryId);

$sv2HasChannelWa = orange_table_has_column($pdo, 'channels', 'whatsapp_number');
$channels = $pdo->query(
    'SELECT id, name' . ($sv2HasChannelWa ? ', whatsapp_number' : '') . ' FROM channels WHERE is_active = 1' . $sv2ChannelsCountrySql . ' ORDER BY id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$sv2DefaultChannelId = orange_sales_company_direct_channel_id();

$sv2PhoneDial = orange_admin_context_phone_dial($pdo);
$sv2ChannelWaMap = [];
foreach ($channels as $sv2Ch) {
    $sv2ChannelWaMap[(int) $sv2Ch['id']] = orange_phone_strip_dial((string) ($sv2Ch['whatsapp_number'] ?? ''), $sv2PhoneDial);
}
$sv2CompanyPhone = orange_sales_doc_print_company($pdo, $adminCountryId)['phones'];

$sv2CustomerPickRows = [];
if (orange_table_exists($pdo, 'customers')) {
    $codeCol = orange_table_has_column($pdo, 'customers', 'code') ? 'code' : 'id';
    $custPickCols = 'id, name_ar, phone, ' . $codeCol . ' AS customer_code';
    if (orange_table_has_column($pdo, 'customers', 'area')) {
        $custPickCols .= ', area';
    }
    if (orange_table_has_column($pdo, 'customers', 'address')) {
        $custPickCols .= ', address';
    }
    $customers = $pdo->query(
        'SELECT ' . $custPickCols . ' FROM customers WHERE 1=1'
        . $sv2CustomersCountrySql . ' ORDER BY name_ar ASC'
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
        $sv2CustomerPickRows[] = [
            'id' => $cid,
            'code' => $customerCode,
            'name' => trim((string) ($c['name_ar'] ?? '')),
            'phone' => trim((string) ($c['phone'] ?? '')),
            'area' => trim((string) ($c['area'] ?? '')),
            'address' => trim((string) ($c['address'] ?? '')),
            'balance' => round((float) ($custBal[$cid] ?? 0.0), 3),
        ];
    }
}

$prefillOrderId = (int) ($_GET['order_id'] ?? 0);
/** الشاشة نشطة دائماً (مثل المشتريات) — غياب المنتجات/القنوات تنبيه فقط عند البحث عن صنف */
$sv2Ready = true;
$sv2WarnNoProducts = $sv2PickRows === [];
$sv2WarnNoChannels = $channels === [];
$sv2DiagCountryCode = orange_admin_context_country_code($pdo);
$sv2DiagActiveProductsAll = orange_table_exists($pdo, 'products')
    ? (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn()
    : 0;
$sv2DiagActiveChannelsAll = orange_table_exists($pdo, 'channels')
    ? (int) $pdo->query('SELECT COUNT(*) FROM channels WHERE is_active = 1')->fetchColumn()
    : 0;
$sv2NavReady = orange_table_exists($pdo, 'orders');
$sv2DocSerialPreview = $sv2NavReady
    ? orange_sales_invoice_ref_preview($pdo, 'company', $adminCountryId)
    : '';
$sv2SalesLineKinds = [];
foreach (orange_invoice_ancillary_sales_line_kind_catalog() as $kindKey => $kindMeta) {
    $sv2SalesLineKinds[] = [
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
.jv-search-field input, .jv-search-field select { width: 100%; box-sizing: border-box; }
.jv-search-field--id { flex: 0 0 7rem; }
.jv-search-field--date { flex: 0 0 11rem; }
.jv-search-field--ref { flex: 1 1 0; min-width: 7rem; }
.jv-search-field--full { width: 100%; }
.jv-search-modal__actions { margin: 0 0 16px; }
.jv-search-table-wrap { max-height: min(40vh, 22rem); overflow: auto; border: 1px solid #e4e4e7; border-radius: 8px; }
.jv-search-results-table { margin: 0; font-size: 0.9rem; }
.jv-search-results-table tbody tr { cursor: pointer; }
.jv-search-results-table tbody tr:hover { background: #f4f4f5; }
.form-grid.sv2-header-row1 {
    grid-template-columns: minmax(7rem, 0.75fr) minmax(6.5rem, 0.65fr) minmax(0, 1.4fr) minmax(6rem, 0.65fr);
}
.form-grid.sv2-header-row2 {
    grid-template-columns: minmax(8rem, 0.85fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.4fr);
}
/* صف 2 — تاريخ الفاتورة · قناة العملاء · نوع البيع · تاريخ الإدخال · مدفوع الآن */
.form-grid.sv2-header-rowdoc {
    grid-template-columns: minmax(7rem, 0.7fr) minmax(0, 1.4fr) minmax(6rem, 0.65fr) minmax(7rem, 0.7fr) minmax(6rem, 0.65fr);
}
/* صف 4 — ملاحظات بعرض كامل */
.form-grid.sv2-header-row3 {
    grid-template-columns: 1fr;
}
.sv2-extra-source-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
}
.sv2-extra-source-tabs button.is-active {
    font-weight: 700;
    border-color: #2563eb;
}
.gl-pick-item.is-selected {
    background: #eff6ff;
    outline: 1px solid #2563eb;
}
@media print {
    .sv2-extra-skip-print { display: none !important; }
    .jv-print-area .sv2-extra-lines-table thead th.admin-doc-col-actions,
    .jv-print-area .sv2-extra-lines-table tr.sv2-extra-line td:last-child {
        display: none !important;
    }
    /* مسافة أسفل التوقيع/الختم — النص القانوني يشغل هامش @page (16mm) أسفله. */
    .jv-print-area { padding-bottom: 10mm !important; }

    /* ===== طباعة جدول البنود: إخفاء عمود الحذف + عرض القيم كنص كامل بلا قصّ ===== */
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
        /* فواصل الشبكة: حدود رأسية وأفقية لكل خلية */
        border: 1px solid #cbd5e1 !important;
    }
    /* صف العناوين بلون العلامة المميز (برتقالي + نص أبيض) */
    .jv-print-area .pur-lines-table thead th {
        background: #ea580c !important;
        color: #ffffff !important;
        font-weight: 700 !important;
        text-align: center !important;
        border-color: #ffffff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    /* الحقول تظهر كنص عادي: بلا حدود/خلفية/عرض ثابت، فتُعرض القيمة كاملة */
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
    /* نِسَب الأعمدة (بعد إخفاء عمود الحذف): #، كود، اسم، لون/مقاس، كمية، سعر، خصم، إجمالي */
    .jv-print-area .pur-lines-table thead th:nth-child(1) { width: 4% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(2) { width: 12% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(3) { width: 28% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(4) { width: 16% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(5) { width: 8% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(6) { width: 11% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(7) { width: 9% !important; }
    .jv-print-area .pur-lines-table thead th:nth-child(8) { width: 12% !important; }
    /* محاذاة: الكود يسار، الاسم/اللون يمين، الأرقام وسط */
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
    <h1>فاتورة مبيعات</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div id="sv2_view_mode_banner" class="card" style="display:none;border:1px solid #93c5fd;background:#eff6ff;margin-bottom:12px;" role="status">
    <p class="card-hint" style="margin:0;line-height:1.55;">
        <strong>وضع العرض:</strong> الفاتورة المعروضة للقراءة. للتعديل اضغط <strong>«فك القفل»</strong> إن وُجد، أو
        <strong>«فاتورة جديدة»</strong> لفاتورة جديدة.
    </p>
</div>

<div class="card jv-print-area">
    <?php
    orange_sales_doc_print_banner([
        'prefix' => 'sv2',
        'doc_title' => 'فاتورة مبيعات',
        'doc_title_en' => 'Sales Invoice',
        'country_id' => $adminCountryId,
        'currency_code' => $adminDefaultCurrency,
        'phone_by_channel' => true,
        'serial_label' => 'رقم الفاتورة / Invoice No.',
        'show_party' => true,
        'party_title' => 'فاتورة إلى / Bill To',
        'show_doc_date' => true,
        'show_print_date' => false,
        'show_qr' => true,
        'show_notes' => true,
        'totals_rows' => [
            ['الإجمالي / Total', 'total'],
        ],
    ]);
    ?>
    <h3 class="card-title">فاتورة مبيعات <span id="sv2_browse_label" class="muted" style="font-size:0.85rem;font-weight:500;"></span></h3>
    <?php orange_edit_lock_ui_toolbar(['prefix' => 'sv2', 'doc_kind' => 'company_sales_invoice', 'country_id' => $adminCountryId, 'show_status_badge' => false]); ?>

    <div class="form-grid sv2-header-row1 orange-doc-header-row jv-print-hide" style="margin-bottom:12px;">
        <div>
            <label for="sv2_doc_serial">مسلسل الفاتورة</label>
            <input type="text" id="sv2_doc_serial" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en" style="background:#f4f4f5;cursor:default;"
                value="<?php echo htmlspecialchars($sv2DocSerialPreview, ENT_QUOTES, 'UTF-8'); ?>"
                title="يُخصَّص تلقائياً من النظام عند الحفظ">
        </div>
        <div>
            <label for="sv2_customer_code">كود العميل</label>
            <input type="text" id="sv2_customer_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار — Enter أيضاً" style="cursor:pointer;">
        </div>
        <div>
            <label for="sv2_customer_name">اسم العميل</label>
            <input type="text" id="sv2_customer_name" required placeholder="اسم العميل">
        </div>
        <div>
            <label for="sv2_customer_balance">رصيد الذمم</label>
            <input type="text" id="sv2_customer_balance" class="admin-inp-readonly admin-money-display" readonly disabled tabindex="-1" dir="ltr" lang="en" placeholder="—">
        </div>
        <input type="hidden" id="sv2_customer_id" value="0">
    </div>

    <!-- ٢ — تاريخ الفاتورة، قناة العملاء، نوع البيع، تاريخ الإدخال، مدفوع الآن -->
    <div class="form-grid sv2-header-rowdoc orange-doc-header-row jv-print-hide" style="margin-bottom:12px;">
        <div>
            <label for="sv2_document_date">تاريخ الفاتورة</label>
            <input type="date" id="sv2_document_date" dir="ltr" lang="en" title="تاريخ الفاتورة = تاريخ ترحيل القيد المحاسبي" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
            <label for="sv2_channel">قناة العملاء</label>
            <select id="sv2_channel" required>
                <option value="0"<?php echo $sv2DefaultChannelId === 0 ? ' selected' : ''; ?>><?php echo htmlspecialchars(orange_sales_company_direct_channel_label(), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php foreach ($channels as $ch): ?>
                <option value="<?php echo (int) $ch['id']; ?>"<?php echo (int) $ch['id'] === $sv2DefaultChannelId ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $ch['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="sv2_payment_terms">نوع البيع</label>
            <select id="sv2_payment_terms">
                <option value="cash">نقدي</option>
                <option value="credit">آجل</option>
                <option value="online">أونلاين</option>
            </select>
        </div>
        <div>
            <label for="sv2_entry_date">تاريخ الإدخال</label>
            <input type="text" id="sv2_entry_date" class="admin-inp-readonly" readonly tabindex="-1" dir="ltr" lang="en" style="background:#f4f4f5;cursor:default;"
                value="<?php echo htmlspecialchars(orange_format_datetime_dmY_hi(date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8'); ?>"
                title="وقت تسجيل إدخال المستند في النظام — يُثبت عند الحفظ ولا يُقبل من المتصفح">
        </div>
        <div>
            <label for="sv2_amount_paid">مدفوع الآن</label>
            <input type="number" id="sv2_amount_paid" class="admin-inp-money" step="any" min="0" value="0" dir="ltr" lang="en">
        </div>
    </div>

    <div class="form-grid sv2-header-row2 orange-doc-header-row jv-print-hide" style="margin-bottom:12px;">
        <div>
            <label for="sv2_phone_country">كود الدولة</label>
            <input type="search" id="sv2_phone_country" list="sv2_phone_country_list" autocomplete="off" dir="ltr" lang="en" placeholder="+965" required>
            <datalist id="sv2_phone_country_list"></datalist>
        </div>
        <div>
            <label for="sv2_phone">الهاتف (محلي)</label>
            <input type="text" id="sv2_phone" class="js-orange-phone-input" maxlength="24" autocomplete="off" dir="ltr" lang="en" placeholder="رقم محلي بدون كود الدولة" required>
        </div>
        <div>
            <label for="sv2_area">المنطقة</label>
            <input type="text" id="sv2_area">
        </div>
        <div>
            <label for="sv2_address">العنوان</label>
            <input type="text" id="sv2_address">
        </div>
    </div>

    <!-- ٤ — ملاحظات -->
    <div class="form-grid sv2-header-row3 orange-doc-header-row jv-print-hide" style="margin-bottom:16px;">
        <div>
            <label for="sv2_notes">ملاحظات</label>
            <input type="text" id="sv2_notes" placeholder="ملاحظات…">
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
                        <th style="width:7rem;">إجمالي السطر</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر" style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody id="sv2_lines_body"></tbody>
            </table>
        </div>
    </div>

    <?php orange_sales_doc_print_totals_box('sv2'); ?>

    <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:18px 0 10px;">بنود إضافية</h4>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table sv2-extra-lines-table">
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
                <tbody id="sv2_extra_lines_body"></tbody>
            </table>
        </div>
    </div>
    <div class="actions jv-print-hide" style="margin-top:10px;">
        <button type="button" class="btn-secondary" id="sv2_btn_add_extra">إضافة بند</button>
    </div>

    <div class="jv-print-hide" style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 24px;">
        <div style="flex:1 1 auto;text-align:left;direction:ltr;font-size:0.95rem;line-height:1.8;">
            <span style="color:#64748b;">إجمالي الأصناف:</span> <strong id="sv2_subtotal" class="admin-money-display" dir="ltr" lang="en"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">خصم الأصناف:</span> <strong id="sv2_discount_total" class="admin-money-display" dir="ltr" lang="en" style="color:#b91c1c;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">صافي الأصناف:</span> <strong id="sv2_net_total" class="admin-money-display" dir="ltr" lang="en" style="color:#059669;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span id="sv2_screen_extra"></span>
            <span style="color:#0f172a;font-weight:700;border-top:2px solid #ea580c;display:inline-block;padding-top:4px;margin-top:2px;"><span id="sv2_grand_label">المبلغ المحصّل:</span> <strong id="sv2_grand_total" class="admin-money-display" dir="ltr" lang="en" style="color:#ea580c;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong>
            <span class="muted" style="font-size:0.85rem;"> <?php echo htmlspecialchars($adminCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></span></span>
        </div>
    </div>

    <?php orange_sales_doc_print_footer(['country_id' => $adminCountryId]); ?>
    <?php echo orange_sales_doc_print_legal_pagecss((int) $adminCountryId); ?>

    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:16px;">
        <span></span>
        <div class="jv-toolbar-primary-group">
            <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين فواتير المبيعات">
                <button type="button" class="btn-secondary jv-nav-btn" id="sv2_nav_first" title="أول فاتورة" aria-label="أول فاتورة">&lt;&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="sv2_nav_prev" title="الفاتورة السابقة" aria-label="الفاتورة السابقة">&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="sv2_nav_next" title="الفاتورة التالية" aria-label="الفاتورة التالية">&gt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="sv2_nav_last" title="آخر فاتورة" aria-label="آخر فاتورة">&gt;&gt;</button>
                <button type="button" class="btn-secondary jv-nav-search" id="sv2_btn_search" title="بحث عن فاتورة">بحث</button>
            </div>
            <button type="button" class="btn-secondary" id="sv2_btn_print" title="طباعة الفاتورة المعروضة"<?php echo orange_admin_invoice_print_tuning_mode() ? '' : ' disabled'; ?>>طباعة</button>
            <button type="button" class="btn-secondary" id="sv2_btn_new" title="فاتورة جديدة" data-orange-perm="edit" data-orange-page="company_sales_invoice" onclick="if (confirm('بدء فاتورة جديدة؟ سيتم مسح أي بيانات غير محفوظة على الشاشة.')) { location.href = (typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH.replace(/\/+$/, '') : '') + '/admin/index.php?page=company_sales_invoice'; } return false;">فاتورة جديدة</button>
            <button type="button" id="sv2_btn_save" data-orange-perm="edit" data-orange-page="company_sales_invoice">حفظ</button>
        </div>
    </div>
</div>

<div class="gl-pick-modal" id="sv2_customer_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="sv2_customer_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="sv2_customer_pick_title">
        <h3 id="sv2_customer_pick_title" class="gl-pick-modal__title">اختيار العميل</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="sv2_customer_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم أو الهاتف…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="sv2_customer_pick_list"></ul>
        <button type="button" class="btn-secondary" id="sv2_customer_pick_close">إغلاق</button>
    </div>
</div>

<div class="gl-pick-modal" id="sv2_extra_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="sv2_extra_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="sv2_extra_pick_title">
        <h3 id="sv2_extra_pick_title" class="gl-pick-modal__title">اختيار حساب — بند إضافي</h3>
        <div class="sv2-extra-source-tabs" role="tablist">
            <button type="button" class="btn-secondary is-active" id="sv2_extra_tab_presets" data-source="presets">القائمة المحفوظة</button>
            <button type="button" class="btn-secondary" id="sv2_extra_tab_coa" data-source="coa">الدليل المحاسبي</button>
        </div>
        <div id="sv2_extra_line_kind_wrap" hidden style="margin:10px 0;">
            <label for="sv2_extra_line_kind">نوع البند</label>
            <select id="sv2_extra_line_kind" class="admin-inp">
                <?php foreach ($sv2SalesLineKinds as $lk): ?>
                <option value="<?php echo htmlspecialchars((string) $lk['key'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $lk['label_ar'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="search" id="sv2_extra_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="sv2_extra_pick_list"></ul>
        <div class="actions" style="margin-top:10px;flex-wrap:wrap;gap:8px;">
            <button type="button" class="btn-secondary" id="sv2_extra_add_to_presets" hidden>أضف إلى القائمة</button>
            <button type="button" class="btn-secondary" id="sv2_extra_pick_close">إغلاق</button>
        </div>
    </div>
</div>

<div id="sv2_product_pick_modal" class="mo-pick-modal" hidden>
    <div class="mo-pick-modal__backdrop" id="sv2_product_pick_backdrop"></div>
    <div class="mo-pick-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sv2_product_pick_title">
        <h4 id="sv2_product_pick_title" class="mo-pick-modal__title">اختيار صنف</h4>
        <input type="search" id="sv2_product_pick_filter" class="admin-inp mo-pick-modal__search" placeholder="ابحث بالكود أو الاسم أو اللون أو المقاس…" autocomplete="off" lang="ar" dir="rtl">
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
                <tbody id="sv2_product_pick_body"></tbody>
            </table>
        </div>
        <p class="card-hint mo-pick-modal__hint">انقر نقراً مزدوجاً على السطر للاختيار — أو امسح الباركود في خانة الكود.</p>
    </div>
</div>

<div id="sv2_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="sv2_search_modal_title">
    <div class="jv-search-modal__backdrop" id="sv2_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="sv2_search_modal_title" class="jv-search-modal__title">بحث في فواتير المبيعات (INV-C)</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="sv2_search_id_from">رقم الفاتورة — من</label>
                        <input type="number" id="sv2_search_id_from" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="sv2_search_id_to">رقم الفاتورة — إلى</label>
                        <input type="number" id="sv2_search_id_to" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="sv2_search_date_from">التاريخ — من</label>
                        <input type="text" id="sv2_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="sv2_search_date_to">التاريخ — إلى</label>
                        <input type="text" id="sv2_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="sv2_search_ref">المرجع INV-C (يحتوي النص)</label>
                        <input type="text" id="sv2_search_ref" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="sv2_search_customer">اسم العميل (يحتوي النص)</label>
                        <input type="text" id="sv2_search_customer" class="admin-inp" autocomplete="off" dir="auto">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="sv2_search_phone">الهاتف (يحتوي النص)</label>
                        <input type="text" id="sv2_search_phone" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="sv2_search_channel">القناة</label>
                        <select id="sv2_search_channel" class="admin-inp">
                            <option value="">— الكل —</option>
                            <option value="0"><?php echo htmlspecialchars(orange_sales_company_direct_channel_label(), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php foreach ($channels as $ch): ?>
                            <option value="<?php echo (int) $ch['id']; ?>"><?php echo htmlspecialchars((string) $ch['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="sv2_search_notes">ملاحظات (يحتوي النص)</label>
                        <input type="text" id="sv2_search_notes" class="admin-inp" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="sv2_search_btn">تنفيذ البحث</button>
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
                                <th>قناة</th>
                                <th>صافي</th>
                            </tr>
                        </thead>
                        <tbody id="sv2_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/country-codes.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin-phone-country.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_purchase_doc_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/admin/assets/vendor/qrcode.min.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/admin/assets/admin_sales_doc_ui.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var SV2_PICK_ROWS = <?php echo json_encode($sv2PickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SV2_CUSTOMER_PICK_ROWS = <?php echo json_encode($sv2CustomerPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SV2_PREFILL_ORDER_ID = <?php echo (int) $prefillOrderId; ?>;
    var SV2_READY = <?php echo $sv2Ready ? 'true' : 'false'; ?>;
    var SV2_PRINT_TUNING = <?php echo orange_admin_invoice_print_tuning_mode() ? 'true' : 'false'; ?>;
    var SV2_NAV_READY = <?php echo $sv2NavReady ? 'true' : 'false'; ?>;
    var SV2_COUNTRY_ID = <?php echo (int) $adminCountryId; ?>;
    var SV2_DOC_SERIAL_PREVIEW = <?php echo json_encode($sv2DocSerialPreview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SV2_DEFAULT_CHANNEL_ID = <?php echo (int) $sv2DefaultChannelId; ?>;
    var SV2_COMPANY_PHONE = <?php echo json_encode($sv2CompanyPhone, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SV2_CHANNEL_WA = <?php echo json_encode((object) $sv2ChannelWaMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SV2_CAPS = <?php echo json_encode($sv2Caps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var SV2_SALES_LINE_KINDS = <?php echo json_encode($sv2SalesLineKinds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

    var sv2EditLockCtl = null;
    var currentCustomerId = 0;
    var browseOrderId = 0;
    var sv2ViewMode = false;
    var sv2ProductPick = null;
    var sv2ExtraPickSource = 'presets';
    var sv2ExtraPickSelected = null;

    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function fmt3(n) {
        var f = window.orangeFmtMoney || (window.OrangeMoney && window.OrangeMoney.formatAmount);
        if (f) return f(n);
        var d = (window.ORANGE_ADMIN_MONEY && window.ORANGE_ADMIN_MONEY.decimals) || 3;
        return (parseFloat(n) || 0).toFixed(d);
    }
    function fmtZero() {
        if (window.orangeMoneyZero) return window.orangeMoneyZero();
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

    function customerById(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        for (var i = 0; i < SV2_CUSTOMER_PICK_ROWS.length; i++) {
            if ((parseInt(String(SV2_CUSTOMER_PICK_ROWS[i].id || '0'), 10) || 0) === id) return SV2_CUSTOMER_PICK_ROWS[i];
        }
        return null;
    }

    function applyCustomerDeliveryFields(source, opts) {
        opts = opts || {};
        if (opts.keepDelivery) return;
        var areaEl = document.getElementById('sv2_area');
        var addrEl = document.getElementById('sv2_address');
        if (!source) {
            if (areaEl) areaEl.value = '';
            if (addrEl) addrEl.value = '';
            return;
        }
        if (areaEl && source.area != null) areaEl.value = String(source.area || '');
        if (addrEl && source.address != null) addrEl.value = String(source.address || '');
    }

    function selectCustomer(id, opts) {
        opts = opts || {};
        var row = customerById(id);
        var codeEl = document.getElementById('sv2_customer_code');
        var nameEl = document.getElementById('sv2_customer_name');
        var balEl = document.getElementById('sv2_customer_balance');
        var idEl = document.getElementById('sv2_customer_id');
        if (!row) {
            currentCustomerId = 0;
            if (codeEl) codeEl.value = '';
            if (!opts.keepName && nameEl) nameEl.value = '';
            if (balEl) balEl.value = '';
            if (idEl) idEl.value = '0';
            if (!opts.keepDelivery) applyCustomerDeliveryFields(null, opts);
            return;
        }
        currentCustomerId = parseInt(String(row.id), 10) || 0;
        if (codeEl) codeEl.value = row.code || '';
        if (nameEl && !opts.keepName) nameEl.value = row.name || '';
        if (balEl) balEl.value = fmt3(row.balance || 0);
        if (idEl) idEl.value = String(currentCustomerId);
        applyCustomerDeliveryFields(row, opts);
    }

    function applyCustomerFromApi(cust, invoice) {
        if (cust && cust.id) {
            selectCustomer(cust.id, { keepName: false });
            if (cust.name_ar) {
                var nameEl = document.getElementById('sv2_customer_name');
                if (nameEl) nameEl.value = cust.name_ar;
            }
            if (cust.code) {
                var codeEl = document.getElementById('sv2_customer_code');
                if (codeEl) codeEl.value = cust.code;
            }
            var balEl = document.getElementById('sv2_customer_balance');
            if (balEl && cust.current_balance != null) balEl.value = fmt3(cust.current_balance);
            applyCustomerDeliveryFields(cust);
        } else {
            selectCustomer(0, { keepName: true, keepDelivery: true });
            var codeEl2 = document.getElementById('sv2_customer_code');
            if (codeEl2) codeEl2.value = '';
            var nameEl2 = document.getElementById('sv2_customer_name');
            if (nameEl2 && invoice && invoice.customer_name) nameEl2.value = invoice.customer_name;
            var balEl2 = document.getElementById('sv2_customer_balance');
            if (balEl2) balEl2.value = '';
            var idEl2 = document.getElementById('sv2_customer_id');
            if (idEl2) idEl2.value = String(parseInt(String(invoice && invoice.customer_id || '0'), 10) || 0);
            applyCustomerDeliveryFields(invoice);
        }
    }

    function customerPickerOpen() {
        if (sv2ViewMode) {
            alert('وضع العرض — لفتح التعديل اضغط «فك القفل» أو «فاتورة جديدة».');
            return;
        }
        var modal = document.getElementById('sv2_customer_pick_modal');
        var qEl = document.getElementById('sv2_customer_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        customerPickerRender('');
        qEl.focus();
    }
    function customerPickerClose() {
        var modal = document.getElementById('sv2_customer_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function customerPickerRender(q) {
        var listEl = document.getElementById('sv2_customer_pick_list');
        if (!listEl) return;
        var query = String(q || '').trim().toLowerCase();
        var rows = SV2_CUSTOMER_PICK_ROWS.filter(function (r) {
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
            li.addEventListener('dblclick', function () {
                selectCustomer(r.id);
                var phoneEl = document.getElementById('sv2_phone');
                if (phoneEl && r.phone && !phoneEl.value.trim()) phoneEl.value = r.phone;
                customerPickerClose();
            });
            li.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    selectCustomer(r.id);
                    customerPickerClose();
                }
            });
            listEl.appendChild(li);
        });
    }

    function lineRowHtml() {
        return '<td class="pur-col-idx"></td>'
            + '<td><input type="text" class="sv2-code admin-inp" placeholder="كود أو باركود" dir="ltr" lang="en" autocomplete="off" style="width:100%;" title="امسح الباركود أو دبل كليك للبحث">'
            + '<input type="hidden" class="sv2-product-id" value="">'
            + '<input type="hidden" class="sv2-variant-id" value="0"></td>'
            + '<td><input type="text" class="sv2-name admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>'
            + '<td><input type="text" class="sv2-var-label admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>'
            + '<td><input type="number" class="sv2-qty admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>'
            + '<td><input type="number" class="sv2-price admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>'
            + '<td><input type="text" class="sv2-discount admin-inp admin-inp-discount" value="' + fmtZero() + '" placeholder="0" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>'
            + '<td><input type="text" class="sv2-line-total admin-inp-money" value="' + fmtZero() + '" readonly data-money-allow-zero tabindex="0" dir="ltr" lang="en"></td>'
            + '<td><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
    }

    function addLine() {
        var tb = document.getElementById('sv2_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'sv2-line';
        tr.innerHTML = lineRowHtml();
        tb.appendChild(tr);
        renumberRows();
        recalcAll();
    }

    function clearLineRow(tr) {
        if (sv2ProductPick) sv2ProductPick.clearLine(tr);
        var qEl = tr.querySelector('.sv2-qty');
        if (qEl) qEl.value = '1';
        var dEl = tr.querySelector('.sv2-discount');
        if (dEl) dEl.value = '';
    }

    function removeLine(btn) {
        var tb = document.getElementById('sv2_lines_body');
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
        var tb = document.getElementById('sv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        }
    }

    function rowIsBlank(tr) {
        var pid = parseInt(tr.querySelector('.sv2-product-id').value, 10) || 0;
        if (pid > 0) return false;
        return (tr.querySelector('.sv2-code').value || '').trim() === '';
    }

    function rowIsComplete(tr) {
        var pid = parseInt(tr.querySelector('.sv2-product-id').value, 10) || 0;
        if (pid <= 0) return false;
        var q = parseInt(tr.querySelector('.sv2-qty').value, 10) || 0;
        if (q < 1) return false;
        var priceEl = tr.querySelector('.sv2-price');
        return !!(priceEl && String(priceEl.value || '').trim() !== '');
    }

    function sv2LineNavFields(tr) {
        return [
            tr.querySelector('.sv2-code'),
            tr.querySelector('.sv2-qty'),
            tr.querySelector('.sv2-price'),
            tr.querySelector('.sv2-discount'),
            tr.querySelector('.sv2-line-total')
        ].filter(function (el) { return el && !el.disabled; });
    }

    function sv2FocusLineField(el) {
        if (!el) return;
        el.focus();
        if (typeof el.select === 'function' && el.tagName === 'INPUT' && !el.readOnly) {
            try { el.select(); } catch (err) {}
        }
    }

    function sv2AdvanceFromLineTotal(tr) {
        recalcAll();
        if (!rowIsComplete(tr)) return;
        var tb = document.getElementById('sv2_lines_body');
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
        if (nextTr) sv2FocusLineField(nextTr.querySelector('.sv2-code'));
    }

    function sv2OnLineKeydown(e) {
        if (sv2ViewMode) return;
        if (e.key !== 'Enter' && e.key !== 'Tab') return;
        if (e.key === 'Tab' && e.shiftKey) return;
        var ta = e.target;
        if (!ta) return;
        var tr = ta.closest('tr');
        if (!tr || !tr.classList.contains('sv2-line')) return;
        var isNav = ta.classList.contains('sv2-code') || ta.classList.contains('sv2-qty')
            || ta.classList.contains('sv2-price') || ta.classList.contains('sv2-discount')
            || ta.classList.contains('sv2-line-total');
        if (!isNav) return;
        if (ta.classList.contains('sv2-code') && e.key === 'Enter') {
            e.preventDefault();
            if (sv2ProductPick) sv2ProductPick.resolveCodeForRow(tr);
            recalcAll();
            sv2FocusLineField(tr.querySelector('.sv2-qty'));
            return;
        }
        if (ta.classList.contains('sv2-line-total')) {
            e.preventDefault();
            sv2AdvanceFromLineTotal(tr);
            return;
        }
        if (ta.classList.contains('sv2-discount')) {
            e.preventDefault();
            recalcAll();
            sv2FocusLineField(tr.querySelector('.sv2-line-total'));
            return;
        }
        e.preventDefault();
        var list = sv2LineNavFields(tr);
        var idx = list.indexOf(ta);
        if (idx >= 0 && idx < list.length - 1) sv2FocusLineField(list[idx + 1]);
    }

    function trimExtraTrailing() {
        var tb = document.getElementById('sv2_lines_body');
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
        var tb = document.getElementById('sv2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        if (rows.length === 0) { addLine(); return; }
        if (rowIsComplete(rows[rows.length - 1])) addLine();
    }

    function sv2FillLineRow(tr, item) {
        var pickRow = sv2ProductPick ? sv2ProductPick.findPickRowByIds(item.product_id, item.variant_id || 0) : null;
        if (pickRow && sv2ProductPick) {
            sv2ProductPick.applyPick(tr, pickRow);
        } else if (sv2ProductPick) {
            sv2ProductPick.clearLine(tr);
            var pidEl = tr.querySelector('.sv2-product-id');
            var vidEl = tr.querySelector('.sv2-variant-id');
            if (pidEl) pidEl.value = String(item.product_id || '');
            if (vidEl) vidEl.value = String(item.variant_id || '0');
            var nameEl = tr.querySelector('.sv2-name');
            if (nameEl) nameEl.value = item.product_name || '';
            var varEl = tr.querySelector('.sv2-var-label');
            if (varEl) {
                var c = item.color || '';
                var s = item.size || '';
                varEl.value = (c && s) ? (c + ' / ' + s) : (c || s || '');
            }
        }
        var qEl = tr.querySelector('.sv2-qty');
        if (qEl) qEl.value = String(item.qty || 1);
        var pEl = tr.querySelector('.sv2-price');
        if (pEl) pEl.value = fmt3(item.price || 0);
        var dEl = tr.querySelector('.sv2-discount');
        if (dEl) {
            var ld = parseFloat(item.line_discount || 0) || 0;
            dEl.value = fmt3(ld);
        }
    }

    function recalcAll() {
        var tb = document.getElementById('sv2_lines_body');
        if (!tb) return;
        var grossSubtotal = 0;
        var totalDiscount = 0;
        tb.querySelectorAll('tr.sv2-line').forEach(function (r) {
            var q = parseInt(r.querySelector('.sv2-qty').value, 10) || 0;
            var p = parseFloat(r.querySelector('.sv2-price').value) || 0;
            var lineGross = q * p;
            var discAmt = parseDiscount((r.querySelector('.sv2-discount').value || '').trim(), lineGross);
            if (discAmt > lineGross) discAmt = lineGross;
            var lineNet = Math.max(0, lineGross - discAmt);
            var ltEl = r.querySelector('.sv2-line-total');
            if (ltEl) ltEl.value = fmt3(lineNet);
            grossSubtotal += lineGross;
            totalDiscount += discAmt;
        });
        var netTotal = Math.max(0, grossSubtotal - totalDiscount);
        var stEl = document.getElementById('sv2_subtotal');
        var dtEl = document.getElementById('sv2_discount_total');
        var ntEl = document.getElementById('sv2_net_total');
        if (stEl) stEl.textContent = fmt3(grossSubtotal);
        if (dtEl) dtEl.textContent = fmt3(totalDiscount);
        if (ntEl) ntEl.textContent = fmt3(netTotal);
        sv2RenderTotals();
    }

    function sv2RenderTotals() {
        if (!(window.orangeSalesDocUi && window.orangeSalesDocUi.renderDocTotals)) return;
        var ptEl = document.getElementById('sv2_payment_terms');
        var isCash = !ptEl || String(ptEl.value || 'cash') !== 'credit';
        window.orangeSalesDocUi.renderDocTotals({
            prefix: 'sv2', context: 'sales',
            subtotalId: 'sv2_subtotal', discountId: 'sv2_discount_total', netId: 'sv2_net_total',
            collectExtra: sv2CollectExtraLines,
            unit: <?php echo json_encode($adminCurrencyUnit, JSON_UNESCAPED_UNICODE); ?>,
            screenExtraId: 'sv2_screen_extra', grandOutId: 'sv2_grand_total', grandLabelId: 'sv2_grand_label',
            labels: {
                items: { ar: 'إجمالي الأصناف', en: 'Items Total' },
                items_disc: { ar: 'خصم الأصناف', en: 'Items Discount' },
                net_items: { ar: 'صافي الأصناف', en: 'Net Items' },
                vat: { ar: 'ضريبة القيمة المضافة', en: 'VAT' }
            },
            finalLabel: isCash
                ? { ar: 'المبلغ المحصّل', en: 'Amount Collected' }
                : { ar: 'الإجمالي المستحق', en: 'Amount Due' }
        });
    }

    function sv2ExtraLineKindLabel(key) {
        key = String(key || '');
        for (var i = 0; i < SV2_SALES_LINE_KINDS.length; i++) {
            if (SV2_SALES_LINE_KINDS[i].key === key) return SV2_SALES_LINE_KINDS[i].label_ar || key;
        }
        return key;
    }

    function sv2ExtraAccountLabel(row) {
        var code = String(row.account_code || '').trim();
        var name = String(row.account_name || row.label_ar || '').trim();
        if (code && name) return code + ' — ' + name;
        return name || code || ('#' + (row.account_id || ''));
    }

    function sv2ExtraLineRowHtml() {
        return '<td class="pur-col-idx"></td>'
            + '<td><span class="sv2-extra-account-label"></span>'
            + '<input type="hidden" class="sv2-extra-account-id" value="">'
            + '<input type="hidden" class="sv2-extra-line-kind" value="">'
            + '<input type="hidden" class="sv2-extra-preset-id" value="0"></td>'
            + '<td><input type="number" class="sv2-extra-amount admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>'
            + '<td style="text-align:center;"><input type="checkbox" class="sv2-extra-print" title="يظهر في الفاتورة المطبوعة"></td>'
            + '<td><input type="text" class="sv2-extra-label admin-inp" placeholder="اختياري" dir="auto"></td>'
            + '<td class="jv-print-hide"><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
    }

    function sv2RenumberExtraRows() {
        var tb = document.getElementById('sv2_extra_lines_body');
        if (!tb) return;
        tb.querySelectorAll('tr.sv2-extra-line').forEach(function (tr, i) {
            var c = tr.querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        });
    }

    function sv2SyncExtraPrintClass(tr) {
        if (!tr) return;
        var show = tr.querySelector('.sv2-extra-print');
        tr.classList.toggle('sv2-extra-skip-print', !(show && show.checked));
    }

    function sv2FillExtraLineRow(tr, row) {
        if (!tr || !row) return;
        tr.querySelector('.sv2-extra-account-id').value = String(parseInt(String(row.account_id || '0'), 10) || 0);
        tr.querySelector('.sv2-extra-line-kind').value = String(row.line_kind || '');
        tr.querySelector('.sv2-extra-preset-id').value = String(parseInt(String(row.preset_id || '0'), 10) || 0);
        var lbl = tr.querySelector('.sv2-extra-account-label');
        if (lbl) lbl.textContent = sv2ExtraAccountLabel(row);
        var amt = tr.querySelector('.sv2-extra-amount');
        if (amt) amt.value = fmt3(row.amount || 0);
        var pr = tr.querySelector('.sv2-extra-print');
        if (pr) pr.checked = !!row.show_on_print;
        var la = tr.querySelector('.sv2-extra-label');
        if (la) la.value = row.label_ar || '';
        sv2SyncExtraPrintClass(tr);
    }

    function sv2AddExtraLine(row) {
        var tb = document.getElementById('sv2_extra_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'sv2-extra-line sv2-extra-skip-print';
        tr.innerHTML = sv2ExtraLineRowHtml();
        tb.appendChild(tr);
        if (row) sv2FillExtraLineRow(tr, row);
        sv2RenumberExtraRows();
        if (typeof sv2RenderTotals === 'function') sv2RenderTotals();
    }

    function sv2ClearExtraLines() {
        var tb = document.getElementById('sv2_extra_lines_body');
        if (tb) tb.innerHTML = '';
    }

    function sv2LoadExtraLines(lines) {
        sv2ClearExtraLines();
        (lines || []).forEach(function (row) { sv2AddExtraLine(row); });
    }

    function sv2CollectExtraLines() {
        var out = [];
        var tb = document.getElementById('sv2_extra_lines_body');
        if (!tb) return out;
        tb.querySelectorAll('tr.sv2-extra-line').forEach(function (tr) {
            var accountId = parseInt(tr.querySelector('.sv2-extra-account-id').value, 10) || 0;
            var lineKind = (tr.querySelector('.sv2-extra-line-kind').value || '').trim();
            var amount = parseFloat(tr.querySelector('.sv2-extra-amount').value) || 0;
            if (accountId <= 0 || !lineKind || amount <= 0) return;
            out.push({
                account_id: accountId,
                line_kind: lineKind,
                amount: amount,
                label_ar: (tr.querySelector('.sv2-extra-label').value || '').trim(),
                show_on_print: tr.querySelector('.sv2-extra-print').checked ? 1 : 0,
                preset_id: parseInt(tr.querySelector('.sv2-extra-preset-id').value, 10) || 0
            });
        });
        return out;
    }

    function sv2ExtraPickSetSource(source) {
        sv2ExtraPickSource = source === 'coa' ? 'coa' : 'presets';
        sv2ExtraPickSelected = null;
        document.getElementById('sv2_extra_tab_presets').classList.toggle('is-active', sv2ExtraPickSource === 'presets');
        document.getElementById('sv2_extra_tab_coa').classList.toggle('is-active', sv2ExtraPickSource === 'coa');
        document.getElementById('sv2_extra_line_kind_wrap').hidden = sv2ExtraPickSource !== 'coa';
        document.getElementById('sv2_extra_add_to_presets').hidden = sv2ExtraPickSource !== 'coa';
        sv2ExtraPickRender(document.getElementById('sv2_extra_pick_q').value || '');
    }

    function sv2ExtraPickOpen() {
        if (sv2ViewMode) return;
        sv2ExtraPickSetSource('presets');
        var modal = document.getElementById('sv2_extra_pick_modal');
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        document.getElementById('sv2_extra_pick_q').value = '';
        sv2ExtraPickRender('');
        document.getElementById('sv2_extra_pick_q').focus();
    }

    function sv2ExtraPickClose() {
        var modal = document.getElementById('sv2_extra_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
        sv2ExtraPickSelected = null;
    }

    function sv2ExtraPickConfirm(row) {
        if (!row) return;
        if (sv2ExtraPickSource === 'coa') {
            var lineKind = (document.getElementById('sv2_extra_line_kind').value || '').trim();
            if (!lineKind) { alert('اختر نوع البند.'); return; }
            sv2AddExtraLine({
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
            sv2AddExtraLine({
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
        sv2ExtraPickClose();
    }

    function sv2ExtraPickRender(q) {
        var listEl = document.getElementById('sv2_extra_pick_list');
        if (!listEl) return;
        listEl.innerHTML = '<li class="gl-pick-empty">جاري التحميل…</li>';
        q = String(q || '').trim();
        if (sv2ExtraPickSource === 'presets') {
            var url = '/admin/api/invoice-ancillary/presets-list.php?invoice_context=sales' + (q ? ('&q=' + encodeURIComponent(q)) : '');
            fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    listEl.innerHTML = '';
                    var rows = (res && res.presets) ? res.presets : [];
                    if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
                    rows.forEach(function (row) {
                        var li = document.createElement('li');
                        li.className = 'gl-pick-item';
                        li.setAttribute('role', 'button');
                        li.tabIndex = 0;
                        li.textContent = sv2ExtraAccountLabel(row) + ' — ' + sv2ExtraLineKindLabel(row.line_kind);
                        li.addEventListener('dblclick', function () { sv2ExtraPickConfirm(row); });
                        li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') sv2ExtraPickConfirm(row); });
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
                    li.className = 'gl-pick-item';
                    li.setAttribute('role', 'button');
                    li.tabIndex = 0;
                    var mapped = { account_id: row.id, account_code: row.code, account_name: row.name };
                    li.textContent = sv2ExtraAccountLabel(mapped);
                    li.addEventListener('click', function () {
                        sv2ExtraPickSelected = mapped;
                        listEl.querySelectorAll('.gl-pick-item').forEach(function (n) { n.classList.remove('is-selected'); });
                        li.classList.add('is-selected');
                    });
                    li.addEventListener('dblclick', function () { sv2ExtraPickConfirm(mapped); });
                    li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') sv2ExtraPickConfirm(mapped); });
                    listEl.appendChild(li);
                });
            })
            .catch(function (e) { listEl.innerHTML = '<li class="gl-pick-empty">' + esc(e.message || String(e)) + '</li>'; });
    }

    function sv2ExtraAddToPresets() {
        if (sv2ExtraPickSource !== 'coa' || !sv2ExtraPickSelected) {
            alert('اختر حساباً من الدليل أولاً.');
            return;
        }
        var lineKind = (document.getElementById('sv2_extra_line_kind').value || '').trim();
        if (!lineKind) { alert('اختر نوع البند.'); return; }
        postJSON('/admin/api/invoice-ancillary/preset-save.php', {
            account_id: sv2ExtraPickSelected.account_id,
            line_kind: lineKind,
            invoice_context: 'sales',
            label_ar: sv2ExtraPickSelected.account_name || '',
            default_show_on_print: false
        }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'تعذر الحفظ'); return; }
            alert(res.message || 'تمت الإضافة');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function sv2SetDocSerial(value) {
        var el = document.getElementById('sv2_doc_serial');
        if (el) el.value = String(value || '');
    }

    function sv2SyncToolbar() {
        var pb = document.getElementById('sv2_btn_print');
        if (pb) {
            if (SV2_PRINT_TUNING) {
                pb.disabled = false;
                pb.title = 'طباعة (وضع ضبط التنسيق — مؤقت)';
            } else {
                pb.disabled = browseOrderId <= 0;
                pb.title = browseOrderId > 0 ? 'طباعة الفاتورة المعروضة' : 'احفظ الفاتورة أولاً';
            }
        }
        var sb = document.getElementById('sv2_btn_save');
        if (sb) {
            sb.disabled = sv2ViewMode || !SV2_CAPS.can_edit;
            sb.title = browseOrderId > 0 && !sv2ViewMode ? 'حفظ التعديلات' : (sv2ViewMode ? 'وضع العرض — فك القفل للتعديل' : 'حفظ فاتورة جديدة');
        }
        var lbl = document.getElementById('sv2_browse_label');
        if (lbl) {
            lbl.textContent = browseOrderId > 0
                ? ('— عرض ' + (document.getElementById('sv2_doc_serial') && document.getElementById('sv2_doc_serial').value || ('INV-C-' + browseOrderId)))
                : '';
        }
        if (browseOrderId <= 0) sv2SetDocSerial(SV2_DOC_SERIAL_PREVIEW || '');
        if (sv2EditLockCtl) sv2EditLockCtl.refresh();
    }

    function sv2SyncViewModeBanner() {
        var ban = document.getElementById('sv2_view_mode_banner');
        if (ban) ban.style.display = sv2ViewMode && browseOrderId > 0 ? '' : 'none';
    }

    function sv2SetViewMode(on) {
        sv2ViewMode = !!on;
        sv2SyncViewModeBanner();
        var card = document.querySelector('.jv-print-area');
        if (card) {
            card.querySelectorAll('input, select, button.admin-doc-line-remove').forEach(function (el) {
                if (el.id === 'sv2_btn_new' || el.id === 'sv2_btn_print' || el.closest('.jv-voucher-nav-btns') || el.id === 'sv2_btn_search' || el.id === 'sv2_btn_save' || el.id === 'sv2_customer_code') {
                    return;
                }
                el.disabled = sv2ViewMode;
            });
            var addExtra = document.getElementById('sv2_btn_add_extra');
            if (addExtra) addExtra.disabled = sv2ViewMode;
        }
        sv2SyncToolbar();
    }

    function sv2FormatEnteredDisplay(raw) {
        var s = String(raw || '').trim();
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (m) return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
        var d = s.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (d) return d[3] + '/' + d[2] + '/' + d[1];
        return s;
    }

    function sv2SyncPrintExtras() {
        var setTxt = function (id, val) {
            var el = document.getElementById(id);
            if (!el) return;
            var v = String(val || '').trim();
            el.textContent = v !== '' ? v : '—';
        };
        var docDateEl = document.getElementById('sv2_document_date');
        var docDateVal = docDateEl ? String(docDateEl.value || '').trim() : '';
        var dm = docDateVal.match(/^(\d{4})-(\d{2})-(\d{2})/);
        setTxt('sv2_sd_print_docdate', dm ? (dm[3] + '/' + dm[2] + '/' + dm[1]) : docDateVal);

        var nameEl = document.getElementById('sv2_customer_name');
        var phoneEl = document.getElementById('sv2_phone');
        var areaEl = document.getElementById('sv2_area');
        var addrEl = document.getElementById('sv2_address');
        setTxt('sv2_sd_print_party_name', nameEl ? nameEl.value : '');
        setTxt('sv2_sd_print_party_phone', phoneEl ? phoneEl.value : '');
        setTxt('sv2_sd_print_party_area', areaEl ? areaEl.value : '');
        setTxt('sv2_sd_print_party_address', addrEl ? addrEl.value : '');

        var getTot = function (id) {
            var el = document.getElementById(id);
            return el ? String(el.textContent || '').trim() : '';
        };
        sv2RenderTotals();

        var chSel = document.getElementById('sv2_channel');
        var chId = chSel ? (parseInt(chSel.value, 10) || 0) : 0;
        orangeSalesDocSetPhoneCells('sv2_sd_print_phone', SV2_COMPANY_PHONE, SV2_CHANNEL_WA, chId);

        var notesEl = document.getElementById('sv2_notes');
        var notesBox = document.getElementById('sv2_sd_print_notes');
        if (notesBox) notesBox.textContent = notesEl ? String(notesEl.value || '').trim() : '';
    }

    function sv2ApplyHeaderFromInvoice(inv, cust) {
        sv2SetDocSerial(inv.reference || ('INV-C-' + (inv.id || browseOrderId)));
        applyCustomerFromApi(cust, inv);
        var ccEl = document.getElementById('sv2_phone_country');
        var phoneEl = document.getElementById('sv2_phone');
        if (window.orangeAdminPhoneCountry && ccEl) {
            window.orangeAdminPhoneCountry.setInputByDial(ccEl, inv.phone_country_dial || window.orangeAdminPhoneCountry.defaultCountryDial(), false);
        }
        if (phoneEl) phoneEl.value = inv.phone_national || inv.phone || '';
        var areaEl = document.getElementById('sv2_area');
        if (areaEl) areaEl.value = inv.area || '';
        var addrEl = document.getElementById('sv2_address');
        if (addrEl) addrEl.value = inv.address || '';
        var chEl = document.getElementById('sv2_channel');
        if (chEl) chEl.value = String(inv.channel_id != null && inv.channel_id !== '' ? inv.channel_id : 0);
        var ptEl = document.getElementById('sv2_payment_terms');
        if (ptEl) ptEl.value = inv.payment_terms || 'cash';
        var paidEl = document.getElementById('sv2_amount_paid');
        if (paidEl) paidEl.value = fmt3(inv.amount_paid || 0);
        var notesEl = document.getElementById('sv2_notes');
        if (notesEl) notesEl.value = inv.notes || '';
        var docDateEl = document.getElementById('sv2_document_date');
        if (docDateEl) docDateEl.value = (inv.document_date ? String(inv.document_date).substr(0, 10) : '');
        var entryDateEl = document.getElementById('sv2_entry_date');
        if (entryDateEl && inv.created_at) entryDateEl.value = sv2FormatEnteredDisplay(inv.created_at);
    }

    function sv2ApplyInvoicePayload(res) {
        if (!res || !res.success || !res.invoice) {
            alert((res && res.message) || 'تعذر تحميل الفاتورة');
            return;
        }
        var inv = res.invoice;
        browseOrderId = parseInt(String(inv.id || '0'), 10) || 0;
        if (window.orangeSalesDocUi && window.orangeSalesDocUi.setDocQr) window.orangeSalesDocUi.setDocQr('sv2', 'inv_c', browseOrderId);
        sv2ApplyHeaderFromInvoice(inv, res.customer);
        var tb = document.getElementById('sv2_lines_body');
        if (tb) {
            tb.innerHTML = '';
            var items = res.items || [];
            if (!items.length) {
                addLine();
            } else {
                items.forEach(function (item) {
                    addLine();
                    var rows = tb.querySelectorAll('tr.sv2-line');
                    sv2FillLineRow(rows[rows.length - 1], item);
                });
            }
            syncTrailing();
        }
        sv2LoadExtraLines(res.extra_lines || []);
        recalcAll();
        sv2SetViewMode(true);
        if (sv2EditLockCtl) sv2EditLockCtl.refresh();
        sv2SyncToolbar();
    }

    function sv2LoadInvoice(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        if (id <= 0) return;
        fetch('/admin/api/sales-invoices/get.php?order_id=' + encodeURIComponent(String(id)), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            sv2ApplyInvoicePayload(res);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function sv2Nav(where) {
        if (!SV2_NAV_READY) return;
        postJSON('/admin/api/sales-invoices/browse.php', {
            action: 'nav',
            where: where,
            current_id: browseOrderId || 0
        }).then(function (r) {
            if (!r.success || !r.id) { alert(r.message || 'لا توجد فاتورة'); return; }
            sv2LoadInvoice(r.id);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function sv2SearchOpen() {
        var m = document.getElementById('sv2_search_modal');
        if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
    }
    function sv2SearchClose() {
        var m = document.getElementById('sv2_search_modal');
        if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
    }
    function sv2SearchRun() {
        var idFrom = parseInt(document.getElementById('sv2_search_id_from').value, 10) || 0;
        var idTo = parseInt(document.getElementById('sv2_search_id_to').value, 10) || 0;
        var dateFrom = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('sv2_search_date_from')) || '' : '';
        var dateTo = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('sv2_search_date_to')) || '' : '';
        var ref = (document.getElementById('sv2_search_ref').value || '').trim();
        var customer = (document.getElementById('sv2_search_customer').value || '').trim();
        var phone = (document.getElementById('sv2_search_phone').value || '').trim();
        var channelSel = document.getElementById('sv2_search_channel');
        var channelRaw = channelSel ? channelSel.value : '';
        var channelId = channelRaw === '' ? -1 : (parseInt(channelRaw, 10) || 0);
        var notes = (document.getElementById('sv2_search_notes').value || '').trim();
        var tbody = document.getElementById('sv2_search_results');
        tbody.innerHTML = '<tr><td colspan="6">جاري البحث…</td></tr>';
        var payload = { action: 'search' };
        if (idFrom > 0) payload.id_from = idFrom;
        if (idTo > 0) payload.id_to = idTo;
        if (dateFrom) payload.date_from = dateFrom;
        if (dateTo) payload.date_to = dateTo;
        if (ref) payload.reference = ref;
        if (customer) payload.customer_name = customer;
        if (phone) payload.phone = phone;
        if (channelId >= 0) payload.channel_id = channelId;
        if (notes) payload.notes = notes;
        postJSON('/admin/api/sales-invoices/browse.php', payload).then(function (r) {
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
                    + '<td>' + esc(v.channel_name || '') + '</td>'
                    + '<td dir="ltr">' + fmt3(v.total || 0) + '</td>';
                tr.addEventListener('dblclick', function () { sv2LoadInvoice(v.id); sv2SearchClose(); });
                tbody.appendChild(tr);
            });
        }).catch(function (e) {
            tbody.innerHTML = '<tr><td colspan="6">' + esc(e.message || String(e)) + '</td></tr>';
        });
    }

    function sv2ReloadAfterSave(res) {
        var oid = parseInt(String((res && (res.order_id || (res.invoice && res.invoice.id))) || browseOrderId || '0'), 10) || 0;
        if (oid > 0) sv2LoadInvoice(oid);
    }

    function sv2ResetNew() {
        browseOrderId = 0;
        sv2ClearExtraLines();
        sv2SetViewMode(false);
        location.href = (typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH.replace(/\/+$/, '') : '') + '/admin/index.php?page=company_sales_invoice';
    }

    function save() {
        if (!SV2_CAPS.can_edit) { alert('لا تملك صلاحية تعديل فواتير المبيعات'); return; }
        if (sv2ViewMode) return;

        var name = (document.getElementById('sv2_customer_name').value || '').trim();
        var phone = (document.getElementById('sv2_phone').value || '').trim();
        var ccEl = document.getElementById('sv2_phone_country');
        if (!name) { alert('اسم العميل مطلوب'); return; }
        if (!phone) { alert('رقم الهاتف مطلوب'); return; }
        if (!window.orangeAdminPhoneCountry) { alert('تحميل كود الدولة…'); return; }
        var phoneCountry = window.orangeAdminPhoneCountry.forApi(ccEl, false);
        if (!phoneCountry) { alert('اختيار كود الدولة إلزامي'); return; }
        if (/^\s*(\+|00)/.test(phone)) {
            alert('اكتب الهاتف كرقم محلي فقط بدون + أو 00');
            return;
        }
        var channel = parseInt(document.getElementById('sv2_channel').value, 10);
        if (isNaN(channel) || channel < 0) { alert('اختر قناة العملاء'); return; }

        var tb = document.getElementById('sv2_lines_body');
        if (!tb) { alert('لا توجد أصناف'); return; }
        var items = [];
        tb.querySelectorAll('tr.sv2-line').forEach(function (r) {
            var pid = parseInt(r.querySelector('.sv2-product-id').value, 10) || 0;
            var vid = parseInt(r.querySelector('.sv2-variant-id').value, 10) || 0;
            var q = parseInt(r.querySelector('.sv2-qty').value, 10) || 0;
            var p = parseFloat(r.querySelector('.sv2-price').value) || 0;
            var discRaw = (r.querySelector('.sv2-discount').value || '').trim();
            if (!pid || q < 1) return;
            var discAmt = parseDiscount(discRaw, q * p);
            var o = { product_id: pid, qty: q, line_discount: discAmt };
            if (vid) o.variant_id = vid;
            items.push(o);
        });
        if (!items.length) { alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة'); return; }

        var paid = parseFloat(document.getElementById('sv2_amount_paid').value) || 0;
        if (paid < 0) paid = 0;

        var payload = {
            customer_name: name,
            customer_id: parseInt(document.getElementById('sv2_customer_id').value, 10) || 0,
            phone: phone,
            phone_country: phoneCountry,
            area: (document.getElementById('sv2_area').value || '').trim(),
            address: (document.getElementById('sv2_address').value || '').trim(),
            notes: (document.getElementById('sv2_notes').value || '').trim(),
            channel_id: channel,
            payment_terms: document.getElementById('sv2_payment_terms').value || 'cash',
            amount_paid: paid,
            document_date: (document.getElementById('sv2_document_date') ? (document.getElementById('sv2_document_date').value || '') : ''),
            items: items,
            extra_lines: sv2CollectExtraLines()
        };

        var apiUrl = '/admin/api/orders/create-manual.php';
        if (browseOrderId > 0) {
            apiUrl = '/admin/api/sales-invoices/update.php';
            payload.order_id = browseOrderId;
        }

        postJSON(apiUrl, payload).then(function (res) {
            if (!res || !res.success) {
                if (typeof orangeAdminOfferSuggestOnFailure === 'function' && orangeAdminOfferSuggestOnFailure(res, 'فشل')) return;
                alert((res && res.message) || 'فشل');
                return;
            }
            if (window.orangeSalesDocUi && channel > 0) {
                window.orangeSalesDocUi.rememberChannel(SV2_COUNTRY_ID, channel);
            }
            if (typeof orangeAdminOfferOpenGlVoucherAfterSave === 'function') {
                orangeAdminOfferOpenGlVoucherAfterSave(res, function () { sv2ReloadAfterSave(res); });
            } else {
                alert(res.message || 'تم الحفظ');
                sv2ReloadAfterSave(res);
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function init() {
        var ccEl = document.getElementById('sv2_phone_country');
        var ccList = document.getElementById('sv2_phone_country_list');
        if (ccEl && ccList && window.orangeAdminPhoneCountry) {
            window.orangeAdminPhoneCountry.bindInput(ccEl, ccList, false);
            window.orangeAdminPhoneCountry.populateDatalist(ccEl, ccList, '', false);
            window.orangeAdminPhoneCountry.setInputByDial(ccEl, window.orangeAdminPhoneCountry.defaultCountryDial(), false);
        }

        if (window.OrangePurchaseDocProductPick) {
            sv2ProductPick = window.OrangePurchaseDocProductPick.create({
                pickRows: SV2_PICK_ROWS,
                codeClass: 'sv2-code',
                fmtMoney: fmt3,
                showStock: true,
                isViewMode: function () { return sv2ViewMode; },
                modalIds: {
                    root: 'sv2_product_pick_modal',
                    backdrop: 'sv2_product_pick_backdrop',
                    filter: 'sv2_product_pick_filter',
                    body: 'sv2_product_pick_body'
                },
                selectors: {
                    code: '.sv2-code',
                    productId: '.sv2-product-id',
                    variantId: '.sv2-variant-id',
                    name: '.sv2-name',
                    varLabel: '.sv2-var-label',
                    cost: '.sv2-price'
                },
                onAfterResolve: function () { recalcAll(); }
            });
            sv2ProductPick.bindModal();
        }

        var codeEl = document.getElementById('sv2_customer_code');
        if (codeEl) {
            codeEl.addEventListener('dblclick', function (e) { e.preventDefault(); customerPickerOpen(); });
            codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); customerPickerOpen(); } });
        }
        document.getElementById('sv2_customer_pick_backdrop').addEventListener('click', customerPickerClose);
        document.getElementById('sv2_customer_pick_close').addEventListener('click', customerPickerClose);
        var custPickQ = document.getElementById('sv2_customer_pick_q');
        var custPickTimer = null;
        if (custPickQ) {
            custPickQ.addEventListener('input', function () {
                if (custPickTimer) clearTimeout(custPickTimer);
                custPickTimer = setTimeout(function () { customerPickerRender(custPickQ.value || ''); }, 180);
            });
        }

        document.getElementById('sv2_btn_save').addEventListener('click', save);
        // زر «فاتورة جديدة» مربوط عبر onclick مباشر حتى يعمل حتى لو فشل ربط addEventListener.
        document.getElementById('sv2_btn_add_extra').addEventListener('click', sv2ExtraPickOpen);
        document.getElementById('sv2_extra_pick_backdrop').addEventListener('click', sv2ExtraPickClose);
        document.getElementById('sv2_extra_pick_close').addEventListener('click', sv2ExtraPickClose);
        document.getElementById('sv2_extra_tab_presets').addEventListener('click', function () { sv2ExtraPickSetSource('presets'); });
        document.getElementById('sv2_extra_tab_coa').addEventListener('click', function () { sv2ExtraPickSetSource('coa'); });
        document.getElementById('sv2_extra_add_to_presets').addEventListener('click', sv2ExtraAddToPresets);
        var extraPickQ = document.getElementById('sv2_extra_pick_q');
        var extraPickTimer = null;
        if (extraPickQ) {
            extraPickQ.addEventListener('input', function () {
                if (extraPickTimer) clearTimeout(extraPickTimer);
                extraPickTimer = setTimeout(function () { sv2ExtraPickRender(extraPickQ.value || ''); }, 200);
            });
        }
        var extraTb = document.getElementById('sv2_extra_lines_body');
        if (extraTb) {
            extraTb.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('admin-doc-line-remove')) {
                    e.target.closest('tr').remove();
                    sv2RenumberExtraRows();
                    sv2RenderTotals();
                }
            });
            extraTb.addEventListener('change', function (e) {
                if (e.target && e.target.classList.contains('sv2-extra-print')) {
                    sv2SyncExtraPrintClass(e.target.closest('tr'));
                }
                sv2RenderTotals();
            });
            extraTb.addEventListener('input', function (e) {
                if (e.target && (e.target.classList.contains('sv2-extra-amount') || e.target.classList.contains('sv2-extra-label'))) {
                    sv2RenderTotals();
                }
            });
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                customerPickerClose();
                sv2ExtraPickClose();
            }
        });
        if (window.orangeSalesDocUi) {
            window.orangeSalesDocUi.bindPrintButton('sv2_btn_print', {
                prefix: 'sv2',
                serialElId: 'sv2_doc_serial',
                docLabel: 'فاتورة مبيعات',
                docKind: 'inv_c',
                docId: function () { return browseOrderId; },
                beforePrint: function () {
                    if (!SV2_PRINT_TUNING && browseOrderId <= 0) { alert('افتح فاتورة محفوظة للطباعة.'); return false; }
                    sv2SyncPrintExtras();
                    return true;
                }
            });
        } else {
            document.getElementById('sv2_btn_print').addEventListener('click', function () {
                if (!SV2_PRINT_TUNING && browseOrderId <= 0) { alert('افتح فاتورة محفوظة للطباعة.'); return; }
                sv2SyncPrintExtras();
                window.print();
            });
        }
        document.getElementById('sv2_nav_first').addEventListener('click', function () { sv2Nav('first'); });
        document.getElementById('sv2_nav_prev').addEventListener('click', function () { sv2Nav('prev'); });
        document.getElementById('sv2_nav_next').addEventListener('click', function () { sv2Nav('next'); });
        document.getElementById('sv2_nav_last').addEventListener('click', function () { sv2Nav('last'); });
        document.getElementById('sv2_btn_search').addEventListener('click', sv2SearchOpen);
        document.getElementById('sv2_search_btn').addEventListener('click', sv2SearchRun);
        document.getElementById('sv2_search_modal_backdrop').addEventListener('click', sv2SearchClose);
        document.addEventListener('mousedown', function (ev) {
            var m = document.getElementById('sv2_search_modal');
            if (!m || m.style.display !== 'flex') return;
            var panel = m.querySelector('.jv-search-modal__panel');
            if (panel && (panel === ev.target || panel.contains(ev.target))) return;
            if (ev.target.closest && ev.target.closest('#sv2_btn_search')) return;
            sv2SearchClose();
        }, true);

        var tb = document.getElementById('sv2_lines_body');
        if (tb) {
            if (sv2ProductPick) sv2ProductPick.bindLinesBody(tb);
            tb.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('sv2-code')) return;
                recalcAll();
            });
            tb.addEventListener('keydown', sv2OnLineKeydown);
            tb.addEventListener('focusout', function (e) {
                if (e.target && e.target.classList.contains('sv2-code') && sv2ProductPick) {
                    sv2ProductPick.resolveCodeForRow(e.target.closest('tr'));
                }
            });
            tb.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('admin-doc-line-remove')) removeLine(e.target);
            });
            addLine();
        }

        sv2SyncToolbar();

        var chSel = document.getElementById('sv2_channel');
        if (chSel && browseOrderId <= 0 && SV2_PREFILL_ORDER_ID <= 0 && !chSel.value) {
            chSel.value = '0';
        }

        if (window.OrangeEditLock) {
            sv2EditLockCtl = OrangeEditLock.bind({
                prefix: 'sv2',
                docKind: 'company_sales_invoice',
                page: 'company_sales_invoice',
                canLock: !!SV2_CAPS.can_lock,
                canUnlock: !!SV2_CAPS.can_unlock,
                countryId: SV2_COUNTRY_ID,
                getEntityId: function () { return browseOrderId; },
                onLockedChange: function (locked) {
                    if (browseOrderId > 0) sv2SetViewMode(!!locked);
                }
            });
        }

        sv2SetViewMode(false);
        if (SV2_PREFILL_ORDER_ID > 0) sv2LoadInvoice(SV2_PREFILL_ORDER_ID);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<?php orange_edit_lock_ui_script_once(); ?>
