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
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';

$pdo = orange_admin_page_pdo();
$ov2Caps = orange_admin_caps_for_page($admin, $pdo, 'online_sales_invoice');

$adminCountryId = orange_admin_context_country_id($pdo);
$adminDefaultPhoneDial = orange_admin_context_phone_dial($pdo);
$adminDefaultCurrency = orange_admin_context_currency_code($pdo);
$adminCurrencyUnit = orange_currency_display_unit($adminDefaultCurrency);
$ov2ChannelsCountrySql = orange_channels_has_country_column($pdo)
    ? orange_sql_country_and_fragment($pdo, 'channels', 'channels', $adminCountryId)
    : '';

$ov2PickRows = orange_sales_doc_product_pick_rows($pdo, $adminCountryId);

$channels = $pdo->query(
    'SELECT id, name FROM channels WHERE is_active = 1' . $ov2ChannelsCountrySql . ' ORDER BY id ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$prefillOrderId = (int) ($_GET['order_id'] ?? 0);
/** الشاشة نشطة دائماً — فاتورة أونلاين تُفتح من بحث/تنقل؛ القناة والعميل من الطلب */
$ov2Ready = true;
$ov2WarnNoProducts = $ov2PickRows === [];
$ov2NavReady = orange_table_exists($pdo, 'orders');
$finalPostingUrl = storefront_public_path('/admin/index.php?page=online_orders_final_posting');
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
.form-grid.ov2-header-row1 {
    grid-template-columns: minmax(7rem, 0.75fr) minmax(7rem, 0.75fr) minmax(6rem, 0.65fr) minmax(8rem, 0.8fr);
}
.form-grid.ov2-header-row2 {
    grid-template-columns: minmax(6.5rem, 0.65fr) minmax(0, 1.4fr) minmax(6rem, 0.65fr);
}
.form-grid.ov2-header-row3 {
    grid-template-columns: minmax(8rem, 0.85fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.4fr);
}
.form-grid.ov2-header-row4 {
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.4fr);
}
.gl-pick-item.is-selected {
    background: #eff6ff;
    outline: 1px solid #2563eb;
}
</style>

<div class="page-title page-title--stacked">
    <div>
        <h1>فاتورة أونلاين</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;line-height:1.55;">
            مستند بيع أونلاين (<strong dir="ltr">INV-O</strong>) — سياق الدولة: المبالغ بعملة
            <strong><?php echo htmlspecialchars($adminDefaultCurrency, ENT_QUOTES, 'UTF-8'); ?></strong>
            — كود الهاتف الافتراضي
            <strong dir="ltr">+<?php echo htmlspecialchars($adminDefaultPhoneDial, ENT_QUOTES, 'UTF-8'); ?></strong>.
            افتح فاتورة محفوظة من <strong>بحث</strong> أو أزرار التنقل — القناة وبيانات العميل تُحمَّل من الطلب ولا تُختار يدوياً.
            لإنشاء رقم الفاتورة والقيود المحاسبية استخدم
            <a href="<?php echo htmlspecialchars($finalPostingUrl, ENT_QUOTES, 'UTF-8'); ?>">طلبات أونلاين — إنشاء القيود</a>.
        </p>
    </div>
</div>

<?php if ($ov2WarnNoProducts): ?>
<div class="card" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">
        لا توجد منتجات نشطة في سياق الدولة — إضافة/تعديل أصناف في البنود قد يفشل حتى تُضاف منتجات من «المنتجات».
    </p>
</div>
<?php endif; ?>

<div id="ov2_gl_banner" class="card jv-print-hide" style="display:none;border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">
        <strong>تنبيه محاسبي:</strong> هذه الفاتورة مرتبطة بقيود مرحّلة. تعديل البنود أو الترويسة
        <strong>لا يعيد حساب</strong> القيود المحاسبية تلقائياً — راجع المحاسبة أو مردود المبيعات إن لزم.
    </p>
</div>

<div class="card jv-print-area">
    <?php
    orange_sales_doc_print_banner([
        'prefix' => 'ov2',
        'doc_title' => 'فاتورة أونلاين',
        'doc_badge' => 'INV-O',
        'country_id' => $adminCountryId,
        'currency_code' => $adminDefaultCurrency,
    ]);
    ?>
    <h3 class="card-title">فاتورة أونلاين <span id="ov2_browse_label" class="muted" style="font-size:0.85rem;font-weight:500;"></span></h3>
    <?php orange_edit_lock_ui_toolbar(['prefix' => 'ov2', 'doc_kind' => 'online_sales_invoice', 'country_id' => $adminCountryId]); ?>

    <div class="form-grid ov2-header-row1" style="margin-bottom:12px;">
        <div>
            <label for="ov2_doc_serial">مسلسل الفاتورة</label>
            <input type="text" id="ov2_doc_serial" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en"
                value="—" title="INV-O">
        </div>
        <div>
            <label for="ov2_order_number">رقم الطلب</label>
            <input type="text" id="ov2_order_number" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en"
                value="—">
        </div>
        <div>
            <label for="ov2_status">حالة الطلب</label>
            <input type="text" id="ov2_status" class="admin-inp-readonly" readonly disabled tabindex="-1" value="—">
        </div>
        <div>
            <label for="ov2_completed_at">تاريخ الإكمال</label>
            <input type="text" id="ov2_completed_at" class="admin-inp-readonly" readonly disabled tabindex="-1" dir="ltr" lang="en"
                value="—">
        </div>
    </div>

    <div class="form-grid ov2-header-row2" style="margin-bottom:12px;">
        <div>
            <label for="ov2_customer_code">كود العميل</label>
            <input type="text" id="ov2_customer_code" class="admin-inp-readonly" autocomplete="off" dir="ltr" lang="en" readonly disabled tabindex="-1" placeholder="—" title="من الطلب — يُعبَّأ عند فتح الفاتورة">
        </div>
        <div>
            <label for="ov2_customer_name">اسم العميل</label>
            <input type="text" id="ov2_customer_name" required placeholder="اسم العميل">
        </div>
        <div>
            <label for="ov2_customer_balance">رصيد الذمم</label>
            <input type="text" id="ov2_customer_balance" class="admin-inp-readonly admin-money-display" readonly disabled tabindex="-1" dir="ltr" lang="en" placeholder="—">
        </div>
        <input type="hidden" id="ov2_customer_id" value="0">
    </div>

    <div class="form-grid ov2-header-row3" style="margin-bottom:12px;">
        <div>
            <label for="ov2_phone_country">كود الدولة</label>
            <input type="search" id="ov2_phone_country" list="ov2_phone_country_list" autocomplete="off" dir="ltr" lang="en" placeholder="+965" required>
            <datalist id="ov2_phone_country_list"></datalist>
        </div>
        <div>
            <label for="ov2_phone">الهاتف (محلي)</label>
            <input type="text" id="ov2_phone" class="js-orange-phone-input" maxlength="24" autocomplete="off" dir="ltr" lang="en" placeholder="رقم محلي بدون كود الدولة" required>
        </div>
        <div>
            <label for="ov2_area">المنطقة</label>
            <input type="text" id="ov2_area">
        </div>
        <div>
            <label for="ov2_address">العنوان</label>
            <input type="text" id="ov2_address">
        </div>
    </div>

    <div class="form-grid ov2-header-row4 orange-doc-header-row" style="margin-bottom:16px;">
        <div>
            <label for="ov2_channel_name">قناة البيع</label>
            <input type="text" id="ov2_channel_name" class="admin-inp-readonly" readonly disabled tabindex="-1" value="—" title="من الطلب — لا يُغيَّر يدوياً">
            <input type="hidden" id="ov2_channel_id" value="0">
        </div>
        <div>
            <label for="ov2_notes">ملاحظات</label>
            <input type="text" id="ov2_notes" placeholder="ملاحظات…">
        </div>
    </div>

    <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:0 0 10px;">أسطر الأصناف</h4>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table pur-lines-table ov2-lines-table">
                <thead>
                    <tr>
                        <th class="pur-col-idx" style="width:2.5rem;">#</th>
                        <th style="min-width:8rem;">كود / باركود</th>
                        <th style="min-width:10rem;">اسم الصنف</th>
                        <th style="min-width:8rem;">اللون / المقاس</th>
                        <th style="width:5rem;">الكمية</th>
                        <th style="width:6rem;">سعر الوحدة</th>
                        <th style="width:6rem;">خصم</th>
                        <th style="width:7rem;">إجمالي السطر</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر" style="width:3rem;"></th>
                    </tr>
                </thead>
                <tbody id="ov2_lines_body"></tbody>
            </table>
        </div>
    </div>

    <div style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 24px;">
        <div style="flex:1 1 auto;text-align:left;direction:ltr;font-size:0.95rem;line-height:1.8;">
            <span style="color:#64748b;">إجمالي البنود:</span> <strong id="ov2_subtotal" class="admin-money-display" dir="ltr" lang="en"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong><br>
            <span style="color:#64748b;">صافي الفاتورة:</span> <strong id="ov2_net_total" class="admin-money-display" dir="ltr" lang="en" style="color:#059669;"><?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?></strong>
            <span class="muted" style="font-size:0.85rem;"> <?php echo htmlspecialchars($adminCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>

    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:16px;">
        <span></span>
        <div class="jv-toolbar-primary-group">
            <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين فواتير أونلاين">
                <button type="button" class="btn-secondary jv-nav-btn" id="ov2_nav_first" title="أول فاتورة" aria-label="أول فاتورة">&lt;&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="ov2_nav_prev" title="الفاتورة السابقة" aria-label="الفاتورة السابقة">&lt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="ov2_nav_next" title="الفاتورة التالية" aria-label="الفاتورة التالية">&gt;</button>
                <button type="button" class="btn-secondary jv-nav-btn" id="ov2_nav_last" title="آخر فاتورة" aria-label="آخر فاتورة">&gt;&gt;</button>
                <button type="button" class="btn-secondary jv-nav-search" id="ov2_btn_search" title="بحث عن فاتورة">بحث</button>
            </div>
            <button type="button" class="btn-secondary" id="ov2_btn_print" title="طباعة الفاتورة المعروضة" disabled>طباعة</button>
            <button type="button" id="ov2_btn_save" data-orange-perm="edit" data-orange-page="online_sales_invoice" disabled>حفظ</button>
        </div>
    </div>
</div>

<div id="ov2_product_pick_modal" class="mo-pick-modal" hidden>
    <div class="mo-pick-modal__backdrop" id="ov2_product_pick_backdrop"></div>
    <div class="mo-pick-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ov2_product_pick_title">
        <h4 id="ov2_product_pick_title" class="mo-pick-modal__title">اختيار صنف</h4>
        <input type="search" id="ov2_product_pick_filter" class="admin-inp mo-pick-modal__search" placeholder="ابحث بالكود أو الاسم أو اللون أو المقاس…" autocomplete="off" lang="ar" dir="rtl">
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
                <tbody id="ov2_product_pick_body"></tbody>
            </table>
        </div>
        <p class="card-hint mo-pick-modal__hint">انقر نقراً مزدوجاً على السطر للاختيار — أو امسح الباركود في خانة الكود.</p>
    </div>
</div>

<div id="ov2_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="ov2_search_modal_title">
    <div class="jv-search-modal__backdrop" id="ov2_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="ov2_search_modal_title" class="jv-search-modal__title">بحث في فواتير أونلاين (INV-O)</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="ov2_search_id_from">رقم الفاتورة — من</label>
                        <input type="number" id="ov2_search_id_from" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="ov2_search_id_to">رقم الفاتورة — إلى</label>
                        <input type="number" id="ov2_search_id_to" class="admin-inp" min="1" step="1" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="ov2_search_date_from">التاريخ — من</label>
                        <input type="text" id="ov2_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="ov2_search_date_to">التاريخ — إلى</label>
                        <input type="text" id="ov2_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="ov2_search_ref">المرجع INV-O (يحتوي النص)</label>
                        <input type="text" id="ov2_search_ref" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="ov2_search_order_number">رقم الطلب (يحتوي النص)</label>
                        <input type="text" id="ov2_search_order_number" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="ov2_search_customer">اسم العميل (يحتوي النص)</label>
                        <input type="text" id="ov2_search_customer" class="admin-inp" autocomplete="off" dir="auto">
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="ov2_search_phone">الهاتف (يحتوي النص)</label>
                        <input type="text" id="ov2_search_phone" class="admin-inp" autocomplete="off" dir="ltr" lang="en">
                    </div>
                </div>
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="ov2_search_channel">القناة</label>
                        <select id="ov2_search_channel" class="admin-inp">
                            <option value="">— الكل —</option>
                            <?php foreach ($channels as $ch): ?>
                            <option value="<?php echo (int) $ch['id']; ?>"><?php echo htmlspecialchars((string) $ch['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="jv-search-modal__row">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="ov2_search_notes">ملاحظات (يحتوي النص)</label>
                        <input type="text" id="ov2_search_notes" class="admin-inp" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="ov2_search_btn">تنفيذ البحث</button>
            </div>
            <div class="jv-search-modal__results">
                <div class="table-wrap jv-search-table-wrap">
                    <table class="admin-table jv-search-results-table">
                        <thead>
                            <tr>
                                <th>رقم</th>
                                <th>تاريخ</th>
                                <th>مرجع</th>
                                <th>طلب</th>
                                <th>عميل</th>
                                <th>قناة</th>
                                <th>صافي</th>
                            </tr>
                        </thead>
                        <tbody id="ov2_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/country-codes.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin-phone-country.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin_purchase_doc_product_pick.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/admin/assets/admin_sales_doc_ui.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function () {
    var OV2_PICK_ROWS = <?php echo json_encode($ov2PickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var OV2_PREFILL_ORDER_ID = <?php echo (int) $prefillOrderId; ?>;
    var OV2_READY = true;
    var OV2_WARN_NO_PRODUCTS = <?php echo $ov2WarnNoProducts ? 'true' : 'false'; ?>;
    var OV2_NAV_READY = <?php echo $ov2NavReady ? 'true' : 'false'; ?>;
    var OV2_COUNTRY_ID = <?php echo (int) $adminCountryId; ?>;
    var OV2_CAPS = <?php echo json_encode($ov2Caps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;

    var ov2EditLockCtl = null;
    var browseOrderId = 0;
    var ov2ViewMode = false;
    var ov2GlPosted = false;
    var ov2ProductPick = null;

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

    function applyCustomerFromApi(cust, invoice) {
        var codeEl = document.getElementById('ov2_customer_code');
        var nameEl = document.getElementById('ov2_customer_name');
        var balEl = document.getElementById('ov2_customer_balance');
        var idEl = document.getElementById('ov2_customer_id');
        var cid = cust && cust.id ? (parseInt(String(cust.id), 10) || 0) : (parseInt(String(invoice && invoice.customer_id || '0'), 10) || 0);
        if (idEl) idEl.value = String(cid);
        if (codeEl) codeEl.value = (cust && cust.code) ? String(cust.code) : (cid > 0 ? String(cid) : '—');
        if (nameEl) nameEl.value = (cust && cust.name_ar) ? String(cust.name_ar) : ((invoice && invoice.customer_name) ? String(invoice.customer_name) : '');
        if (balEl) {
            if (cust && cust.current_balance != null) balEl.value = fmt3(cust.current_balance);
            else balEl.value = cid > 0 ? '' : '—';
        }
    }

    function lineRowHtml() {
        return '<td class="pur-col-idx"></td>'
            + '<td><input type="text" class="ov2-code admin-inp" placeholder="كود أو باركود" dir="ltr" lang="en" autocomplete="off" style="width:100%;" title="امسح الباركود أو دبل كليك للبحث">'
            + '<input type="hidden" class="ov2-product-id" value="">'
            + '<input type="hidden" class="ov2-variant-id" value="0"></td>'
            + '<td><input type="text" class="ov2-name admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>'
            + '<td><input type="text" class="ov2-var-label admin-inp-readonly" readonly disabled tabindex="-1" placeholder="—"></td>'
            + '<td><input type="number" class="ov2-qty admin-inp-qty" min="1" step="1" value="1" inputmode="numeric" lang="en" dir="ltr"></td>'
            + '<td><input type="number" class="ov2-price admin-inp-money" min="0" step="any" value="' + fmtZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>'
            + '<td><input type="text" class="ov2-discount admin-inp" placeholder="0" dir="ltr" lang="en" autocomplete="off" style="width:100%;"></td>'
            + '<td><input type="text" class="ov2-line-total admin-inp-money" value="' + fmtZero() + '" readonly data-money-allow-zero tabindex="0" dir="ltr" lang="en"></td>'
            + '<td><button type="button" class="btn-secondary admin-doc-line-remove" title="حذف">&times;</button></td>';
    }

    function addLine() {
        var tb = document.getElementById('ov2_lines_body');
        if (!tb) return;
        var tr = document.createElement('tr');
        tr.className = 'ov2-line';
        tr.innerHTML = lineRowHtml();
        tb.appendChild(tr);
        renumberRows();
        recalcAll();
    }

    function clearLineRow(tr) {
        if (ov2ProductPick) ov2ProductPick.clearLine(tr);
        var qEl = tr.querySelector('.ov2-qty');
        if (qEl) qEl.value = '1';
        var dEl = tr.querySelector('.ov2-discount');
        if (dEl) dEl.value = '';
    }

    function removeLine(btn) {
        var tb = document.getElementById('ov2_lines_body');
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
        var tb = document.getElementById('ov2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.pur-col-idx');
            if (c) c.textContent = String(i + 1);
        }
    }

    function rowIsBlank(tr) {
        var pid = parseInt(tr.querySelector('.ov2-product-id').value, 10) || 0;
        if (pid > 0) return false;
        return (tr.querySelector('.ov2-code').value || '').trim() === '';
    }

    function rowIsComplete(tr) {
        var pid = parseInt(tr.querySelector('.ov2-product-id').value, 10) || 0;
        if (pid <= 0) return false;
        var q = parseInt(tr.querySelector('.ov2-qty').value, 10) || 0;
        if (q < 1) return false;
        var priceEl = tr.querySelector('.ov2-price');
        return !!(priceEl && String(priceEl.value || '').trim() !== '');
    }

    function ov2LineNavFields(tr) {
        return [
            tr.querySelector('.ov2-code'),
            tr.querySelector('.ov2-qty'),
            tr.querySelector('.ov2-price'),
            tr.querySelector('.ov2-discount'),
            tr.querySelector('.ov2-line-total')
        ].filter(function (el) { return el && !el.disabled; });
    }

    function ov2FocusLineField(el) {
        if (!el) return;
        el.focus();
        if (typeof el.select === 'function' && el.tagName === 'INPUT' && !el.readOnly) {
            try { el.select(); } catch (err) {}
        }
    }

    function ov2AdvanceFromLineTotal(tr) {
        recalcAll();
        if (!rowIsComplete(tr)) return;
        var tb = document.getElementById('ov2_lines_body');
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
        if (nextTr) ov2FocusLineField(nextTr.querySelector('.ov2-code'));
    }

    function ov2OnLineKeydown(e) {
        if (ov2ViewMode || browseOrderId <= 0) return;
        if (e.key !== 'Enter' && e.key !== 'Tab') return;
        if (e.key === 'Tab' && e.shiftKey) return;
        var ta = e.target;
        if (!ta) return;
        var tr = ta.closest('tr');
        if (!tr || !tr.classList.contains('ov2-line')) return;
        var isNav = ta.classList.contains('ov2-code') || ta.classList.contains('ov2-qty')
            || ta.classList.contains('ov2-price') || ta.classList.contains('ov2-discount')
            || ta.classList.contains('ov2-line-total');
        if (!isNav) return;
        if (ta.classList.contains('ov2-code') && e.key === 'Enter') {
            e.preventDefault();
            if (ov2ProductPick) ov2ProductPick.resolveCodeForRow(tr);
            recalcAll();
            ov2FocusLineField(tr.querySelector('.ov2-qty'));
            return;
        }
        if (ta.classList.contains('ov2-line-total')) {
            e.preventDefault();
            ov2AdvanceFromLineTotal(tr);
            return;
        }
        if (ta.classList.contains('ov2-discount')) {
            e.preventDefault();
            recalcAll();
            ov2FocusLineField(tr.querySelector('.ov2-line-total'));
            return;
        }
        e.preventDefault();
        var list = ov2LineNavFields(tr);
        var idx = list.indexOf(ta);
        if (idx >= 0 && idx < list.length - 1) ov2FocusLineField(list[idx + 1]);
    }

    function trimExtraTrailing() {
        var tb = document.getElementById('ov2_lines_body');
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
        var tb = document.getElementById('ov2_lines_body');
        if (!tb) return;
        var rows = tb.querySelectorAll('tr');
        if (rows.length === 0) { addLine(); return; }
        if (rowIsComplete(rows[rows.length - 1])) addLine();
    }

    function ov2FillLineRow(tr, item) {
        var pickRow = ov2ProductPick ? ov2ProductPick.findPickRowByIds(item.product_id, item.variant_id || 0) : null;
        if (pickRow && ov2ProductPick) {
            ov2ProductPick.applyPick(tr, pickRow);
        } else if (ov2ProductPick) {
            ov2ProductPick.clearLine(tr);
            var pidEl = tr.querySelector('.ov2-product-id');
            var vidEl = tr.querySelector('.ov2-variant-id');
            if (pidEl) pidEl.value = String(item.product_id || '');
            if (vidEl) vidEl.value = String(item.variant_id || '0');
            var nameEl = tr.querySelector('.ov2-name');
            if (nameEl) nameEl.value = item.product_name || '';
            var varEl = tr.querySelector('.ov2-var-label');
            if (varEl) {
                var c = item.color || '';
                var s = item.size || '';
                varEl.value = (c && s) ? (c + ' / ' + s) : (c || s || '');
            }
        }
        var qEl = tr.querySelector('.ov2-qty');
        if (qEl) qEl.value = String(item.qty || 1);
        var pEl = tr.querySelector('.ov2-price');
        if (pEl) pEl.value = fmt3(item.price || 0);
        var dEl = tr.querySelector('.ov2-discount');
        if (dEl) {
            var ld = parseFloat(item.line_discount || 0) || 0;
            dEl.value = ld > 0 ? fmt3(ld) : '';
        }
    }

    function recalcAll() {
        var tb = document.getElementById('ov2_lines_body');
        if (!tb) return;
        var subtotal = 0;
        tb.querySelectorAll('tr.ov2-line').forEach(function (r) {
            var q = parseInt(r.querySelector('.ov2-qty').value, 10) || 0;
            var p = parseFloat(r.querySelector('.ov2-price').value) || 0;
            var lineGross = q * p;
            var discAmt = parseDiscount((r.querySelector('.ov2-discount').value || '').trim(), lineGross);
            var lineNet = Math.max(0, lineGross - discAmt);
            var ltEl = r.querySelector('.ov2-line-total');
            if (ltEl) ltEl.value = fmt3(lineNet);
            subtotal += lineNet;
        });
        var stEl = document.getElementById('ov2_subtotal');
        var ntEl = document.getElementById('ov2_net_total');
        if (stEl) stEl.textContent = fmt3(subtotal);
        if (ntEl) ntEl.textContent = fmt3(subtotal);
    }

    function ov2SetDocSerial(value) {
        var el = document.getElementById('ov2_doc_serial');
        if (el) el.value = String(value || '—');
    }

    function ov2SyncGlBanner() {
        var el = document.getElementById('ov2_gl_banner');
        if (el) el.style.display = (browseOrderId > 0 && ov2GlPosted) ? 'block' : 'none';
    }

    function ov2SyncToolbar() {
        var pb = document.getElementById('ov2_btn_print');
        if (pb) {
            pb.disabled = browseOrderId <= 0;
            pb.title = browseOrderId > 0 ? 'طباعة الفاتورة المعروضة' : 'افتح فاتورة محفوظة للطباعة';
        }
        var sb = document.getElementById('ov2_btn_save');
        if (sb) {
            sb.disabled = browseOrderId <= 0 || ov2ViewMode || !OV2_CAPS.can_edit;
            sb.title = browseOrderId > 0 && !ov2ViewMode ? 'حفظ التعديلات' : (browseOrderId <= 0 ? 'افتح فاتورة أونلاين أولاً' : 'وضع العرض — فك القفل للتعديل');
        }
        var lbl = document.getElementById('ov2_browse_label');
        if (lbl) {
            lbl.textContent = browseOrderId > 0
                ? ('— عرض ' + (document.getElementById('ov2_doc_serial') && document.getElementById('ov2_doc_serial').value || ('INV-O-' + browseOrderId)))
                : '';
        }
        ov2SyncGlBanner();
        if (ov2EditLockCtl) ov2EditLockCtl.refresh();
    }

    function ov2SetViewMode(on) {
        ov2ViewMode = !!on;
        var noDoc = browseOrderId <= 0;
        var card = document.querySelector('.jv-print-area');
        if (card) {
            card.querySelectorAll('input, select, button.admin-doc-line-remove').forEach(function (el) {
                if (el.id === 'ov2_btn_print' || el.closest('.jv-voucher-nav-btns') || el.id === 'ov2_btn_search' || el.id === 'ov2_btn_save') {
                    return;
                }
                if (el.id === 'ov2_doc_serial' || el.id === 'ov2_order_number' || el.id === 'ov2_status' || el.id === 'ov2_completed_at'
                    || el.id === 'ov2_customer_code' || el.id === 'ov2_customer_balance' || el.id === 'ov2_channel_name') {
                    return;
                }
                el.disabled = noDoc || ov2ViewMode;
            });
        }
        ov2SyncToolbar();
    }

    function ov2ApplyHeaderFromInvoice(inv, cust) {
        ov2SetDocSerial(inv.reference || inv.invoice_number || ('INV-O-' + (inv.id || browseOrderId)));
        var ordEl = document.getElementById('ov2_order_number');
        if (ordEl) ordEl.value = inv.order_number || '—';
        var stEl = document.getElementById('ov2_status');
        if (stEl) stEl.value = inv.status_label || inv.status || '—';
        var compEl = document.getElementById('ov2_completed_at');
        if (compEl) compEl.value = inv.completed_at_dmy || inv.completed_at || '—';
        applyCustomerFromApi(cust, inv);
        var ccEl = document.getElementById('ov2_phone_country');
        var phoneEl = document.getElementById('ov2_phone');
        if (window.orangeAdminPhoneCountry && ccEl) {
            window.orangeAdminPhoneCountry.setInputByDial(ccEl, inv.phone_country_dial || window.orangeAdminPhoneCountry.defaultCountryDial(), false);
        }
        if (phoneEl) phoneEl.value = inv.phone_national || inv.phone || '';
        var areaEl = document.getElementById('ov2_area');
        if (areaEl) areaEl.value = inv.area || '';
        var addrEl = document.getElementById('ov2_address');
        if (addrEl) addrEl.value = inv.address || '';
        var chNameEl = document.getElementById('ov2_channel_name');
        var chIdEl = document.getElementById('ov2_channel_id');
        if (chNameEl) chNameEl.value = inv.channel_name || '—';
        if (chIdEl) chIdEl.value = String(parseInt(String(inv.channel_id || '0'), 10) || 0);
        var notesEl = document.getElementById('ov2_notes');
        if (notesEl) notesEl.value = inv.notes || '';
        ov2GlPosted = !!inv.gl_posted;
    }

    function ov2ApplyInvoicePayload(res) {
        if (!res || !res.success || !res.invoice) {
            alert((res && res.message) || 'تعذر تحميل الفاتورة');
            return;
        }
        var inv = res.invoice;
        browseOrderId = parseInt(String(inv.id || '0'), 10) || 0;
        ov2ApplyHeaderFromInvoice(inv, res.customer);
        var tb = document.getElementById('ov2_lines_body');
        if (tb) {
            tb.innerHTML = '';
            var items = res.items || [];
            if (!items.length) {
                addLine();
            } else {
                items.forEach(function (item) {
                    addLine();
                    var rows = tb.querySelectorAll('tr.ov2-line');
                    ov2FillLineRow(rows[rows.length - 1], item);
                });
            }
            syncTrailing();
        }
        recalcAll();
        ov2SetViewMode(true);
        if (ov2EditLockCtl) ov2EditLockCtl.refresh();
        ov2SyncToolbar();
    }

    function ov2LoadInvoice(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        if (id <= 0) return;
        fetch('/admin/api/online-invoices/get.php?order_id=' + encodeURIComponent(String(id)), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            ov2ApplyInvoicePayload(res);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function ov2Nav(where) {
        if (!OV2_NAV_READY) return;
        postJSON('/admin/api/online-invoices/browse.php', {
            action: 'nav',
            where: where,
            current_id: browseOrderId || 0
        }).then(function (r) {
            if (!r.success || !r.id) { alert(r.message || 'لا توجد فاتورة'); return; }
            ov2LoadInvoice(r.id);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function ov2SearchOpen() {
        var m = document.getElementById('ov2_search_modal');
        if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
    }
    function ov2SearchClose() {
        var m = document.getElementById('ov2_search_modal');
        if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
    }
    function ov2SearchRun() {
        var idFrom = parseInt(document.getElementById('ov2_search_id_from').value, 10) || 0;
        var idTo = parseInt(document.getElementById('ov2_search_id_to').value, 10) || 0;
        var dateFrom = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('ov2_search_date_from')) || '' : '';
        var dateTo = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(document.getElementById('ov2_search_date_to')) || '' : '';
        var ref = (document.getElementById('ov2_search_ref').value || '').trim();
        var orderRef = (document.getElementById('ov2_search_order_number').value || '').trim();
        var customer = (document.getElementById('ov2_search_customer').value || '').trim();
        var phone = (document.getElementById('ov2_search_phone').value || '').trim();
        var channelId = parseInt(document.getElementById('ov2_search_channel').value, 10) || 0;
        var notes = (document.getElementById('ov2_search_notes').value || '').trim();
        var tbody = document.getElementById('ov2_search_results');
        tbody.innerHTML = '<tr><td colspan="7">جاري البحث…</td></tr>';
        var payload = { action: 'search' };
        if (idFrom > 0) payload.id_from = idFrom;
        if (idTo > 0) payload.id_to = idTo;
        if (dateFrom) payload.date_from = dateFrom;
        if (dateTo) payload.date_to = dateTo;
        if (ref) payload.reference = ref;
        if (orderRef) payload.order_ref = orderRef;
        if (customer) payload.customer_name = customer;
        if (phone) payload.phone = phone;
        if (channelId > 0) payload.channel_id = channelId;
        if (notes) payload.notes = notes;
        postJSON('/admin/api/online-invoices/browse.php', payload).then(function (r) {
            tbody.innerHTML = '';
            if (!r.success || !r.results || !r.results.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="muted">لا نتائج</td></tr>';
                return;
            }
            r.results.forEach(function (v) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + esc(String(v.id)) + '</td>'
                    + '<td>' + esc(v.created_at_dmy || '') + '</td>'
                    + '<td dir="ltr">' + esc(v.reference || '') + '</td>'
                    + '<td dir="ltr">' + esc(v.order_number || '') + '</td>'
                    + '<td>' + esc(v.customer_name || '') + '</td>'
                    + '<td>' + esc(v.channel_name || '') + '</td>'
                    + '<td dir="ltr">' + fmt3(v.total || 0) + '</td>';
                tr.addEventListener('dblclick', function () { ov2LoadInvoice(v.id); ov2SearchClose(); });
                tbody.appendChild(tr);
            });
        }).catch(function (e) {
            tbody.innerHTML = '<tr><td colspan="7">' + esc(e.message || String(e)) + '</td></tr>';
        });
    }

    function ov2ReloadAfterSave(res) {
        var oid = parseInt(String((res && (res.order_id || (res.invoice && res.invoice.id))) || browseOrderId || '0'), 10) || 0;
        if (oid > 0) ov2LoadInvoice(oid);
    }

    function save() {
        if (!OV2_CAPS.can_edit) { alert('لا تملك صلاحية تعديل فواتير أونلاين'); return; }
        if (ov2ViewMode || browseOrderId <= 0) {
            alert(browseOrderId <= 0 ? 'افتح فاتورة أونلاين من البحث أو التنقل أولاً' : 'وضع العرض — فك القفل للتعديل');
            return;
        }
        if (OV2_WARN_NO_PRODUCTS) {
            alert('لا توجد منتجات نشطة في سياق الدولة — راجع شاشة المنتجات قبل تعديل الأصناف');
            return;
        }

        var name = (document.getElementById('ov2_customer_name').value || '').trim();
        var phone = (document.getElementById('ov2_phone').value || '').trim();
        var ccEl = document.getElementById('ov2_phone_country');
        if (!name) { alert('اسم العميل مطلوب'); return; }
        if (!phone) { alert('رقم الهاتف مطلوب'); return; }
        if (!window.orangeAdminPhoneCountry) { alert('تحميل كود الدولة…'); return; }
        var phoneCountry = window.orangeAdminPhoneCountry.forApi(ccEl, false);
        if (!phoneCountry) { alert('اختيار كود الدولة إلزامي'); return; }
        if (/^\s*(\+|00)/.test(phone)) {
            alert('اكتب الهاتف كرقم محلي فقط بدون + أو 00');
            return;
        }
        var tb = document.getElementById('ov2_lines_body');
        if (!tb) { alert('لا توجد أصناف'); return; }
        var items = [];
        tb.querySelectorAll('tr.ov2-line').forEach(function (r) {
            var pid = parseInt(r.querySelector('.ov2-product-id').value, 10) || 0;
            var vid = parseInt(r.querySelector('.ov2-variant-id').value, 10) || 0;
            var q = parseInt(r.querySelector('.ov2-qty').value, 10) || 0;
            var p = parseFloat(r.querySelector('.ov2-price').value) || 0;
            var discRaw = (r.querySelector('.ov2-discount').value || '').trim();
            if (!pid || q < 1) return;
            var discAmt = parseDiscount(discRaw, q * p);
            var o = { product_id: pid, qty: q, line_discount: discAmt };
            if (vid) o.variant_id = vid;
            items.push(o);
        });
        if (!items.length) { alert('أضف سطرًا واحدًا على الأقل بصنف وكمية صحيحة'); return; }

        var payload = {
            order_id: browseOrderId,
            customer_name: name,
            customer_id: parseInt(document.getElementById('ov2_customer_id').value, 10) || 0,
            phone: phone,
            phone_country: phoneCountry,
            area: (document.getElementById('ov2_area').value || '').trim(),
            address: (document.getElementById('ov2_address').value || '').trim(),
            notes: (document.getElementById('ov2_notes').value || '').trim(),
            items: items
        };

        postJSON('/admin/api/online-invoices/update.php', payload).then(function (res) {
            if (!res || !res.success) {
                if (typeof orangeAdminOfferSuggestOnFailure === 'function' && orangeAdminOfferSuggestOnFailure(res, 'فشل')) return;
                alert((res && res.message) || 'فشل');
                return;
            }
            var msg = res.gl_sync_note || res.message || 'تم الحفظ';
            alert(msg);
            ov2ReloadAfterSave(res);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function init() {
        var ccEl = document.getElementById('ov2_phone_country');
        var ccList = document.getElementById('ov2_phone_country_list');
        if (ccEl && ccList && window.orangeAdminPhoneCountry) {
            window.orangeAdminPhoneCountry.bindInput(ccEl, ccList, false);
            window.orangeAdminPhoneCountry.populateDatalist(ccEl, ccList, '', false);
            window.orangeAdminPhoneCountry.setInputByDial(ccEl, window.orangeAdminPhoneCountry.defaultCountryDial(), false);
        }

        if (window.OrangePurchaseDocProductPick) {
            ov2ProductPick = window.OrangePurchaseDocProductPick.create({
                pickRows: OV2_PICK_ROWS,
                codeClass: 'ov2-code',
                fmtMoney: fmt3,
                showStock: true,
                isViewMode: function () { return ov2ViewMode || browseOrderId <= 0; },
                modalIds: {
                    root: 'ov2_product_pick_modal',
                    backdrop: 'ov2_product_pick_backdrop',
                    filter: 'ov2_product_pick_filter',
                    body: 'ov2_product_pick_body'
                },
                selectors: {
                    code: '.ov2-code',
                    productId: '.ov2-product-id',
                    variantId: '.ov2-variant-id',
                    name: '.ov2-name',
                    varLabel: '.ov2-var-label',
                    cost: '.ov2-price'
                },
                onAfterResolve: function () { recalcAll(); }
            });
            ov2ProductPick.bindModal();
        }

        document.getElementById('ov2_btn_save').addEventListener('click', save);
        if (window.orangeSalesDocUi) {
            window.orangeSalesDocUi.bindPrintButton('ov2_btn_print', {
                prefix: 'ov2',
                serialElId: 'ov2_doc_serial',
                beforePrint: function () {
                    if (browseOrderId <= 0) { alert('افتح فاتورة محفوظة للطباعة.'); return false; }
                    return true;
                }
            });
        } else {
            document.getElementById('ov2_btn_print').addEventListener('click', function () {
                if (browseOrderId <= 0) { alert('افتح فاتورة محفوظة للطباعة.'); return; }
                window.print();
            });
        }
        document.getElementById('ov2_nav_first').addEventListener('click', function () { ov2Nav('first'); });
        document.getElementById('ov2_nav_prev').addEventListener('click', function () { ov2Nav('prev'); });
        document.getElementById('ov2_nav_next').addEventListener('click', function () { ov2Nav('next'); });
        document.getElementById('ov2_nav_last').addEventListener('click', function () { ov2Nav('last'); });
        document.getElementById('ov2_btn_search').addEventListener('click', ov2SearchOpen);
        document.getElementById('ov2_search_btn').addEventListener('click', ov2SearchRun);
        document.getElementById('ov2_search_modal_backdrop').addEventListener('click', ov2SearchClose);
        document.addEventListener('mousedown', function (ev) {
            var m = document.getElementById('ov2_search_modal');
            if (!m || m.style.display !== 'flex') return;
            var panel = m.querySelector('.jv-search-modal__panel');
            if (panel && (panel === ev.target || panel.contains(ev.target))) return;
            if (ev.target.closest && ev.target.closest('#ov2_btn_search')) return;
            ov2SearchClose();
        }, true);

        var tb = document.getElementById('ov2_lines_body');
        if (tb) {
            if (ov2ProductPick) ov2ProductPick.bindLinesBody(tb);
            tb.addEventListener('input', function (e) {
                if (e.target && e.target.classList.contains('ov2-code')) return;
                recalcAll();
            });
            tb.addEventListener('keydown', ov2OnLineKeydown);
            tb.addEventListener('focusout', function (e) {
                if (e.target && e.target.classList.contains('ov2-code') && ov2ProductPick) {
                    ov2ProductPick.resolveCodeForRow(e.target.closest('tr'));
                }
            });
            tb.addEventListener('click', function (e) {
                if (e.target && e.target.classList.contains('admin-doc-line-remove')) removeLine(e.target);
            });
        }

        ov2SetViewMode(false);
        ov2SyncToolbar();

        if (window.OrangeEditLock) {
            ov2EditLockCtl = OrangeEditLock.bind({
                prefix: 'ov2',
                docKind: 'online_sales_invoice',
                page: 'online_sales_invoice',
                canLock: !!OV2_CAPS.can_lock,
                canUnlock: !!OV2_CAPS.can_unlock,
                countryId: OV2_COUNTRY_ID,
                getEntityId: function () { return browseOrderId; },
                onLockedChange: function (locked) {
                    if (browseOrderId > 0) ov2SetViewMode(!!locked);
                }
            });
        }

        if (OV2_PREFILL_ORDER_ID > 0) ov2LoadInvoice(OV2_PREFILL_ORDER_ID);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<?php orange_edit_lock_ui_script_once(); ?>
