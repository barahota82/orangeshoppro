<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/storefront_phone_country_select.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$customerSchemaBootstrapError = '';
if (function_exists('orange_catalog_ensure_schema_core')) {
    try {
        orange_catalog_ensure_schema_core($pdo);
    } catch (Throwable $e) {
        $customerSchemaBootstrapError = trim((string) $e->getMessage());
    }
}

$hasCustomerCodeCol = orange_table_has_column($pdo, 'customers', 'code');
$hasCustomerAreaCol = orange_table_has_column($pdo, 'customers', 'area');
$hasCustomerAddressCol = orange_table_has_column($pdo, 'customers', 'address');
$hasCustomerEmailCol = orange_table_has_column($pdo, 'customers', 'email');
$hasCustomerNotesCol = orange_table_has_column($pdo, 'customers', 'notes');
$hasCustomerLimitCol = orange_table_has_column($pdo, 'customers', 'credit_limit');
$hasCustomerPhoneCountryDialCol = orange_table_has_column($pdo, 'customers', 'phone_country_dial');
$hasCustomerPhoneNationalCol = orange_table_has_column($pdo, 'customers', 'phone_national');
$hasCustomerDeliveryAreaCol = orange_table_has_column($pdo, 'customers', 'delivery_area_id');

$adminDeliveryAreas = orange_delivery_areas_admin_list($pdo);
$adminDaIndex = [];
foreach ($adminDeliveryAreas as $da) {
    $daId = (int) ($da['id'] ?? 0);
    if ($daId > 0) {
        $adminDaIndex[$daId] = $da;
    }
}

/**
 * س15: معاينة كود العميل التالي (للعرض فقط؛ التثبيت في API).
 */
$nextCustomerCodePreview = '1';
if (orange_table_exists($pdo, 'customers') && $hasCustomerCodeCol) {
    $codeRows = $pdo->query(
        'SELECT code FROM customers WHERE code IS NOT NULL AND TRIM(code) <> \'\' ORDER BY id DESC LIMIT 5000'
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $maxCodeNum = 0;
    foreach ($codeRows as $rawCode) {
        $code = trim((string) $rawCode);
        if ($code === '') {
            continue;
        }
        if (preg_match_all('/\d+/', $code, $m) && isset($m[0]) && is_array($m[0])) {
            foreach ($m[0] as $chunk) {
                $num = (int) $chunk;
                if ($num > $maxCodeNum) {
                    $maxCodeNum = $num;
                }
            }
        }
    }
    $nextCustomerCodePreview = (string) max(1, $maxCodeNum + 1);
}

// تحميل العملاء + المؤشرات الخفيفة لـ payload شاشة احترافية (نمط الموردين).
$customerRows = [];
$customerSearchRowsPayload = [];
$totalCustomersBalance = 0.0;
if (orange_table_exists($pdo, 'customers')) {
    $customerRows = $pdo->query('SELECT c.* FROM customers c ORDER BY c.id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // معرّفات حسابات الواجهة (storefront_accounts) لكل عميل (لو مربوطة).
    $sfAccountByCustomerId = [];
    if (orange_table_exists($pdo, 'storefront_accounts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'customer_id')
    ) {
        $sfSt = $pdo->query(
            'SELECT id, customer_id, email, email_verified_at, registered_channel_slug
             FROM storefront_accounts WHERE customer_id IS NOT NULL'
        );
        if ($sfSt) {
            while ($sfRow = $sfSt->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) ($sfRow['customer_id'] ?? 0);
                if ($cid > 0 && !isset($sfAccountByCustomerId[$cid])) {
                    $sfAccountByCustomerId[$cid] = [
                        'id' => (int) ($sfRow['id'] ?? 0),
                        'email' => (string) ($sfRow['email'] ?? ''),
                        'verified' => !empty($sfRow['email_verified_at']),
                        'channel' => (string) ($sfRow['registered_channel_slug'] ?? ''),
                    ];
                }
            }
        }
    }

    // إحصاءات الطلبات لكل عميل.
    $orderStatsByCustomerId = [];
    if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'customer_id')) {
        try {
            $oSt = $pdo->query(
                'SELECT customer_id, COUNT(*) AS cnt, MAX(created_at) AS last_at
                 FROM orders WHERE customer_id IS NOT NULL AND customer_id > 0
                 GROUP BY customer_id'
            );
            if ($oSt) {
                while ($oRow = $oSt->fetch(PDO::FETCH_ASSOC)) {
                    $cid = (int) ($oRow['customer_id'] ?? 0);
                    if ($cid > 0) {
                        $orderStatsByCustomerId[$cid] = [
                            'count' => (int) ($oRow['cnt'] ?? 0),
                            'last_at' => (string) ($oRow['last_at'] ?? ''),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] customers payload order stats: ' . $e->getMessage());
            }
        }
    }

    foreach ($customerRows as $r) {
        $cid = (int) ($r['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $bal = orange_party_balance_customer($pdo, $cid);
        $totalCustomersBalance += $bal;

        $daId = $hasCustomerDeliveryAreaCol && isset($r['delivery_area_id']) ? (int) $r['delivery_area_id'] : 0;
        $daName = '';
        $daActive = true;
        if ($daId > 0 && isset($adminDaIndex[$daId])) {
            $aRow = $adminDaIndex[$daId];
            $daName = trim((string) ($aRow['name_ar'] ?? ''));
            if ($daName === '') {
                $daName = trim((string) ($aRow['name_en'] ?? ''));
            }
            $daActive = (int) ($aRow['is_active'] ?? 0) === 1;
        }

        $stats = $orderStatsByCustomerId[$cid] ?? ['count' => 0, 'last_at' => ''];
        $sfAcc = $sfAccountByCustomerId[$cid] ?? null;

        $searchHayRaw = trim(
            (string) ($r['code'] ?? '') . ' ' .
            (string) ($r['name_ar'] ?? '') . ' ' .
            (string) ($r['phone'] ?? '') . ' ' .
            (string) ($r['email'] ?? '') . ' ' .
            (string) ($r['area'] ?? '') . ' ' .
            $daName . ' ' .
            (string) ($r['notes'] ?? '')
        );
        $searchHay = function_exists('mb_strtolower') ? mb_strtolower($searchHayRaw, 'UTF-8') : strtolower($searchHayRaw);

        $customerSearchRowsPayload[] = [
            'id' => $cid,
            'code' => (string) ($r['code'] ?? ''),
            'name_ar' => (string) ($r['name_ar'] ?? ''),
            'phone' => (string) ($r['phone'] ?? ''),
            'phone_country_dial' => (string) ($r['phone_country_dial'] ?? ''),
            'phone_national' => (string) ($r['phone_national'] ?? ''),
            'email' => (string) ($r['email'] ?? ''),
            'area' => (string) ($r['area'] ?? ''),
            'delivery_area_id' => $daId > 0 ? $daId : null,
            'delivery_area_name' => $daName,
            'delivery_area_active' => $daActive,
            'address' => (string) ($r['address'] ?? ''),
            'credit_limit' => isset($r['credit_limit']) && $r['credit_limit'] !== null && (float) $r['credit_limit'] > 0
                ? (float) $r['credit_limit'] : null,
            'notes' => (string) ($r['notes'] ?? ''),
            'current_balance' => round((float) $bal, 3),
            'orders_count' => (int) ($stats['count'] ?? 0),
            'orders_last_at' => (string) ($stats['last_at'] ?? ''),
            'storefront_account_id' => $sfAcc ? (int) $sfAcc['id'] : null,
            'storefront_account_email' => $sfAcc ? (string) $sfAcc['email'] : '',
            'storefront_account_verified' => $sfAcc ? (bool) $sfAcc['verified'] : false,
            'storefront_account_channel' => $sfAcc ? (string) $sfAcc['channel'] : '',
            'created_at' => (string) ($r['created_at'] ?? ''),
            'search_text' => $searchHay,
        ];
    }
}
$count = count($customerRows);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>العملاء</h1>
    </div>
</div>

<?php if ($customerSchemaBootstrapError !== ''): ?>
<div class="card" style="border:1px solid #fbbf24; background:#fffbeb; color:#92400e;">
    <h3 style="margin-top:0;">تنبيه مخطط العملاء</h3>
    <p class="card-hint" style="margin:6px 0 0; color:#92400e;">
        تم تشغيل ترقية مخطط العملاء تلقائياً عند فتح الصفحة. إذا استمرت خانات ناقصة، فغالباً ترحيل القاعدة لم يكتمل (صلاحيات ALTER أو كاش PHP).
    </p>
    <p class="card-hint" style="margin:6px 0 0; color:#7c2d12;">
        رسالة النظام: <code dir="ltr"><?php echo htmlspecialchars($customerSchemaBootstrapError, ENT_QUOTES, 'UTF-8'); ?></code>
    </p>
</div>
<?php endif; ?>

<div class="party-registry-stats">
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">عدد العملاء</span>
        <span class="party-registry-stat__val"><?php echo (int) $count; ?></span>
    </div>
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">مجموع أرصدة الذمم (مدين)</span>
        <span class="party-registry-stat__val" dir="ltr"><?php echo number_format($totalCustomersBalance, 3); ?></span>
        <span class="party-registry-stat__unit">KD</span>
    </div>
</div>

<style>
#cus_form_grid.customers-form-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr);
    row-gap: 12px;
    column-gap: 12px;
    grid-template-areas:
        "cus_r1_code cus_r1_code cus_r1_code cus_r1_code"
        "cus_r2_name cus_r2_name cus_r2_balance cus_r2_orders"
        "cus_r3_country cus_r3_phone cus_r3_email cus_r3_credit"
        "cus_r4_address cus_r4_address cus_r4_address cus_r4_area"
        "cus_r5_notes cus_r5_notes cus_r5_notes cus_r5_notes"
        "cus_r6_sf cus_r6_sf cus_r6_sf cus_r6_sf";
}
#cus_form_grid.customers-form-grid input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
#cus_form_grid.customers-form-grid select {
    height: 42px;
    min-height: 42px;
    box-sizing: border-box;
}
#cus_form_grid.customers-form-grid textarea {
    min-height: 84px;
    box-sizing: border-box;
}
#cus_form_grid .cus-grid-r1-code {
    grid-area: cus_r1_code;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
#cus_form_grid .cus-code-nav-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: end;
    direction: rtl;
}
#cus_form_grid .cus-code-nav-main {
    min-width: 0;
}
#cus_form_grid .cus-code-nav-main input {
    width: 100%;
}
#cus_form_grid .cus-code-nav-btns {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    direction: ltr;
    margin-inline-start: auto;
}
#cus_form_grid .cus-code-nav-btn {
    min-width: 38px;
    height: 42px;
    padding: 0 10px;
}
#cus_form_grid .cus-code-nav-search {
    height: 42px;
    padding: 0 12px;
    white-space: nowrap;
}
#cus_form_grid .cus-code-nav-btn[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}
#cus_form_grid .cus-grid-r2-name {
    grid-area: cus_r2_name;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r2-balance {
    grid-area: cus_r2_balance;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r2-orders {
    grid-area: cus_r2_orders;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r3-country {
    grid-area: cus_r3_country;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r3-phone {
    grid-area: cus_r3_phone;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r3-email {
    grid-area: cus_r3_email;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r3-credit {
    grid-area: cus_r3_credit;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r4-address {
    grid-area: cus_r4_address;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r4-area {
    grid-area: cus_r4_area;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r5-notes {
    grid-area: cus_r5_notes;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r6-sf {
    grid-area: cus_r6_sf;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-sf-banner {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    padding: 10px 12px;
    border: 1px solid #e4e4e7;
    border-radius: 8px;
    background: #f8fafc;
    color: #334155;
    font-size: 0.9rem;
    direction: rtl;
}
#cus_form_grid .cus-sf-banner.is-empty {
    color: #94a3b8;
    font-style: italic;
}
#cus_form_grid .cus-sf-banner .cus-sf-banner__item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
#cus_form_grid .cus-sf-banner .cus-sf-banner__label {
    color: #64748b;
}
#cus_form_grid .cus-sf-banner .cus-sf-banner__value {
    color: #0f172a;
    font-weight: 600;
}
#cus_form_grid .cus-sf-banner .cus-sf-banner__value--ltr {
    direction: ltr;
}
#cus_form_grid .cus-sf-banner .cus-sf-banner__verified-yes {
    color: #047857;
}
#cus_form_grid .cus-sf-banner .cus-sf-banner__verified-no {
    color: #b45309;
}

@media (max-width: 1200px) {
    #cus_form_grid.customers-form-grid {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        grid-template-areas:
            "cus_r1_code cus_r1_code"
            "cus_r2_name cus_r2_name"
            "cus_r2_balance cus_r2_orders"
            "cus_r3_country cus_r3_phone"
            "cus_r3_email cus_r3_credit"
            "cus_r4_address cus_r4_area"
            "cus_r5_notes cus_r5_notes"
            "cus_r6_sf cus_r6_sf";
    }
}
@media (max-width: 768px) {
    #cus_form_grid.customers-form-grid {
        grid-template-columns: 1fr;
        grid-template-areas:
            "cus_r1_code"
            "cus_r2_name"
            "cus_r2_balance"
            "cus_r2_orders"
            "cus_r3_country"
            "cus_r3_phone"
            "cus_r3_email"
            "cus_r3_credit"
            "cus_r4_area"
            "cus_r4_address"
            "cus_r5_notes"
            "cus_r6_sf";
    }
}
</style>

<div class="card" id="cus_form_card">
    <input type="hidden" id="cus_id" value="0">
    <input type="hidden" id="cus_storefront_account_id" value="0">
    <div class="form-grid customers-form-grid" id="cus_form_grid">
        <div class="cus-grid-r1-code">
            <div class="cus-code-nav-row">
                <div class="cus-code-nav-main">
                    <label for="cus_code">كود العميل (تلقائي)</label>
                    <input type="text" id="cus_code" class="admin-sort-field admin-sort-field--muted" maxlength="32" autocomplete="off" dir="ltr" lang="en" value="<?php echo htmlspecialchars($nextCustomerCodePreview, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>
                <div class="cus-code-nav-btns" role="group" aria-label="تنقل بين العملاء">
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_nav_first" title="أول عميل" aria-label="أول عميل">&lt;&lt;</button>
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_nav_prev" title="العميل السابق" aria-label="العميل السابق">&lt;</button>
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_nav_next" title="العميل التالي" aria-label="العميل التالي">&gt;</button>
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_nav_last" title="آخر عميل" aria-label="آخر عميل">&gt;&gt;</button>
                    <button type="button" class="btn-secondary cus-code-nav-search" id="cus_open_search_btn" title="بحث عميل">بحث</button>
                </div>
            </div>
        </div>

        <div class="cus-grid-r2-name">
            <label for="cus_name">اسم العميل</label>
            <input type="text" id="cus_name" autocomplete="off" placeholder="اسم العميل" maxlength="255">
        </div>
        <div class="cus-grid-r2-balance">
            <label for="cus_current_balance">رصيد الذمة (مدين)</label>
            <input type="text" id="cus_current_balance" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" value="0.000" readonly>
        </div>
        <div class="cus-grid-r2-orders">
            <label for="cus_orders_count">عدد الطلبات</label>
            <input type="text" id="cus_orders_count" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" value="0" readonly>
        </div>

        <div class="cus-grid-r3-country">
            <label for="cus_phone_country">كود الدولة</label>
            <?php orange_storefront_render_phone_country_select('cus_phone_country'); ?>
        </div>
        <div class="cus-grid-r3-phone">
            <label for="cus_phone">الهاتف <span style="color:#b45309;">*</span></label>
            <input type="text" id="cus_phone" class="js-orange-phone-input" maxlength="24" autocomplete="off" dir="ltr" lang="en" placeholder="+965… أو 00… أو رقم وطني مع اختيار الدولة">
        </div>
        <?php if ($hasCustomerEmailCol): ?>
        <div class="cus-grid-r3-email">
            <label for="cus_email">البريد الإلكتروني</label>
            <input type="email" id="cus_email" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasCustomerLimitCol): ?>
        <div class="cus-grid-r3-credit">
            <label for="cus_credit_limit">حد الائتمان</label>
            <input type="number" id="cus_credit_limit" class="admin-inp-money" step="any" min="0" value="" placeholder="فارغ = بلا حد" inputmode="decimal" lang="en" dir="ltr">
        </div>
        <?php endif; ?>

        <?php if ($hasCustomerAddressCol): ?>
        <div class="cus-grid-r4-address">
            <label for="cus_address">العنوان</label>
            <input type="text" id="cus_address" autocomplete="off" placeholder="عنوان التوصيل" maxlength="2000">
        </div>
        <?php endif; ?>
        <div class="cus-grid-r4-area">
            <label for="cus_area">المنطقة</label>
            <?php if ($hasCustomerDeliveryAreaCol && $adminDeliveryAreas !== []): ?>
                <select id="cus_area" autocomplete="off">
                    <option value="">— اختر منطقة —</option>
                    <?php foreach ($adminDeliveryAreas as $da):
                        $daId = (int) ($da['id'] ?? 0);
                        if ($daId <= 0) {
                            continue;
                        }
                        $daName = trim((string) ($da['name_ar'] ?? ''));
                        if ($daName === '') {
                            $daName = trim((string) ($da['name_en'] ?? ''));
                        }
                        $daActive = (int) ($da['is_active'] ?? 0) === 1;
                        $label = $daName !== '' ? $daName : ('#' . $daId);
                        if (!$daActive) {
                            $label .= ' (معطّلة)';
                        }
                        ?>
                        <option value="<?php echo $daId; ?>" data-name="<?php echo htmlspecialchars($daName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="text" id="cus_area" maxlength="255" autocomplete="off" placeholder="المنطقة">
            <?php endif; ?>
        </div>

        <?php if ($hasCustomerNotesCol): ?>
        <div class="cus-grid-r5-notes">
            <label for="cus_notes">ملاحظات (تتراكم تلقائياً من الطلبات)</label>
            <textarea id="cus_notes" rows="3" autocomplete="off" placeholder="ملاحظات يدوية + أسطر تلقائية من كل طلب ويب"></textarea>
        </div>
        <?php endif; ?>

        <div class="cus-grid-r6-sf">
            <label>حساب الواجهة (مزامنة تلقائية)</label>
            <div id="cus_sf_banner" class="cus-sf-banner is-empty">
                <span>لا حساب واجهة مربوط حالياً.</span>
            </div>
        </div>
    </div>
    <div class="actions admin-actions--start" style="margin-top:12px;">
        <button type="button" onclick="cusSave()">حفظ</button>
        <button type="button" class="btn-secondary" onclick="cusResetForm()">عميل جديد</button>
        <button type="button" class="btn-secondary" id="cus_open_sales_btn">فاتورة مبيعات</button>
        <button type="button" class="btn-secondary" id="cus_open_receipt_btn">قبض من العميل</button>
        <button type="button" class="btn-secondary" id="cus_open_statement_btn">كشف حساب</button>
    </div>
</div>

<div class="gl-pick-modal" id="cus_search_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="cus_search_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="cus_search_title">
        <h3 id="cus_search_title" class="gl-pick-modal__title">بحث عميل</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">دبل كليك لاختيار العميل وتعبئة النموذج</p>
        <input type="search" id="cus_search_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم أو الهاتف أو البريد…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="cus_search_list"></ul>
        <button type="button" class="btn-secondary" id="cus_search_close">إغلاق</button>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/input-constraints.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
var CUS_NEXT_AUTO_CODE = <?php echo json_encode($nextCustomerCodePreview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_SEARCH_ROWS = <?php echo json_encode($customerSearchRowsPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_PARTNER_STATEMENT_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=partner_account_statement'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_MANUAL_ORDER_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=manual_order'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_RECEIPT_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=partner_customer_receipt'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_NAV_ROWS = CUS_SEARCH_ROWS
    .slice()
    .filter(function (row) {
        return (parseInt(String(row && row.id || '0'), 10) || 0) > 0;
    })
    .sort(function (a, b) {
        var aId = parseInt(String(a && a.id || '0'), 10) || 0;
        var bId = parseInt(String(b && b.id || '0'), 10) || 0;
        return aId - bId;
    });
var cusSearchTimer = null;

function cusPhoneCountryEl() {
    return document.getElementById('cus_phone_country');
}
function cusAreaEl() {
    return document.getElementById('cus_area');
}
function cusAreaIsSelect() {
    var el = cusAreaEl();
    return !!(el && el.tagName === 'SELECT');
}
function cusSetCurrentBalance(value) {
    var el = document.getElementById('cus_current_balance');
    if (!el) return;
    var n = parseFloat(String(value || 0));
    if (isNaN(n)) n = 0;
    el.value = n.toFixed(3);
}
function cusSetOrdersCount(value) {
    var el = document.getElementById('cus_orders_count');
    if (!el) return;
    var n = parseInt(String(value || 0), 10);
    if (isNaN(n)) n = 0;
    el.value = String(n);
}

function cusNavRows() {
    return CUS_NAV_ROWS;
}
function cusNavCurrentIndex(rows) {
    var idEl = document.getElementById('cus_id');
    var cid = parseInt(String(idEl && idEl.value || '0'), 10) || 0;
    if (cid <= 0) return -1;
    for (var i = 0; i < rows.length; i++) {
        var rid = parseInt(String(rows[i] && rows[i].id || '0'), 10) || 0;
        if (rid === cid) return i;
    }
    return -1;
}
function cusNavRefreshButtons() {
    var firstBtn = document.getElementById('cus_nav_first');
    var prevBtn = document.getElementById('cus_nav_prev');
    var nextBtn = document.getElementById('cus_nav_next');
    var lastBtn = document.getElementById('cus_nav_last');
    if (!firstBtn || !prevBtn || !nextBtn || !lastBtn) return;
    firstBtn.disabled = false;
    prevBtn.disabled = false;
    nextBtn.disabled = false;
    lastBtn.disabled = false;
}
function cusNavLoadIndex(index) {
    var rows = cusNavRows();
    if (!rows.length) return;
    var i = parseInt(String(index), 10);
    if (isNaN(i)) return;
    if (i < 0) i = 0;
    if (i > rows.length - 1) i = rows.length - 1;
    var row = rows[i] || null;
    if (!row) return;
    cusEdit(row);
}
function cusNavFirst() {
    if (!cusNavRows().length) { alert('لا يوجد عملاء بعد'); return; }
    cusNavLoadIndex(0);
}
function cusNavLast() {
    var rows = cusNavRows();
    if (!rows.length) { alert('لا يوجد عملاء بعد'); return; }
    cusNavLoadIndex(rows.length - 1);
}
function cusNavPrev() {
    var rows = cusNavRows();
    if (!rows.length) { alert('لا يوجد عملاء بعد'); return; }
    var idx = cusNavCurrentIndex(rows);
    if (idx < 0) { cusNavLoadIndex(rows.length - 1); return; }
    cusNavLoadIndex(idx - 1);
}
function cusNavNext() {
    var rows = cusNavRows();
    if (!rows.length) { alert('لا يوجد عملاء بعد'); return; }
    var idx = cusNavCurrentIndex(rows);
    if (idx < 0) { cusNavLoadIndex(0); return; }
    cusNavLoadIndex(idx + 1);
}

function cusSearchFindById(id) {
    var wanted = parseInt(String(id || '0'), 10) || 0;
    if (wanted <= 0) return null;
    for (var i = 0; i < CUS_SEARCH_ROWS.length; i++) {
        var row = CUS_SEARCH_ROWS[i];
        if ((parseInt(String(row.id || '0'), 10) || 0) === wanted) return row;
    }
    return null;
}
function cusCurrentRow() {
    var idEl = document.getElementById('cus_id');
    var cid = parseInt(String(idEl && idEl.value || '0'), 10) || 0;
    if (cid <= 0) return null;
    return cusSearchFindById(cid);
}
function cusSearchModalClose() {
    var modal = document.getElementById('cus_search_modal');
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('gl-pick-open');
}
function cusSearchSelect(row) {
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) return;
    cusEdit(row);
    cusSearchModalClose();
}
function cusSearchRender(q) {
    var listEl = document.getElementById('cus_search_list');
    if (!listEl) return;
    var query = String(q || '').trim().toLowerCase();
    var rows = CUS_SEARCH_ROWS.filter(function (row) {
        if (!query) return true;
        var hay = String(row.search_text || '').toLowerCase();
        if (hay === '') {
            hay = (
                String(row.code || '') + ' ' +
                String(row.name_ar || '') + ' ' +
                String(row.phone || '') + ' ' +
                String(row.email || '') + ' ' +
                String(row.area || '') + ' ' +
                String(row.delivery_area_name || '')
            ).toLowerCase();
        }
        return hay.indexOf(query) !== -1;
    });
    listEl.innerHTML = '';
    if (rows.length === 0) {
        listEl.innerHTML = '<li class="gl-pick-empty">لا توجد نتائج</li>';
        return;
    }
    rows.forEach(function (row) {
        var li = document.createElement('li');
        li.className = 'gl-pick-item cus-search-item';
        li.setAttribute('role', 'button');
        li.tabIndex = 0;
        var nameEl = document.createElement('span');
        nameEl.className = 'cus-search-item__name';
        var code = String(row.code || '').trim();
        var name = String(row.name_ar || '').trim();
        nameEl.textContent = (code !== '' ? code + ' — ' : '') + (name !== '' ? name : ('#' + String(row.id || '0')));
        var metaEl = document.createElement('span');
        metaEl.className = 'cus-search-item__meta';
        var phone = String(row.phone || '').trim();
        var bal = (typeof row.current_balance === 'number') ? row.current_balance : 0;
        metaEl.textContent = (phone !== '' ? phone : 'بدون هاتف') + ' • رصيد: ' + bal.toFixed(3) + ' KD';
        li.appendChild(nameEl);
        li.appendChild(metaEl);
        li.addEventListener('dblclick', function () { cusSearchSelect(row); });
        li.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); cusSearchSelect(row); }
        });
        listEl.appendChild(li);
    });
}
function cusSearchModalOpen() {
    var modal = document.getElementById('cus_search_modal');
    var qEl = document.getElementById('cus_search_q');
    var listEl = document.getElementById('cus_search_list');
    if (!modal || !qEl || !listEl) return;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gl-pick-open');
    qEl.value = '';
    listEl.innerHTML = '';
    cusSearchRender('');
    qEl.focus();
}

function cusSfBannerSet(row) {
    var el = document.getElementById('cus_sf_banner');
    if (!el) return;
    if (!row || !row.storefront_account_id) {
        el.classList.add('is-empty');
        el.innerHTML = '<span>لا حساب واجهة مربوط حالياً.</span>';
        return;
    }
    el.classList.remove('is-empty');
    var email = String(row.storefront_account_email || '').trim();
    var verified = !!row.storefront_account_verified;
    var channel = String(row.storefront_account_channel || '').trim();
    var lastAt = String(row.orders_last_at || '').trim();
    var parts = [];
    if (email !== '') {
        parts.push('<span class="cus-sf-banner__item"><span class="cus-sf-banner__label">البريد:</span><span class="cus-sf-banner__value cus-sf-banner__value--ltr">' + cusEsc(email) + '</span></span>');
    }
    parts.push('<span class="cus-sf-banner__item"><span class="cus-sf-banner__label">حالة البريد:</span><span class="cus-sf-banner__value ' + (verified ? 'cus-sf-banner__verified-yes' : 'cus-sf-banner__verified-no') + '">' + (verified ? 'مفعّل' : 'بانتظار التفعيل') + '</span></span>');
    if (channel !== '') {
        parts.push('<span class="cus-sf-banner__item"><span class="cus-sf-banner__label">القناة:</span><span class="cus-sf-banner__value cus-sf-banner__value--ltr">' + cusEsc(channel) + '</span></span>');
    }
    if (lastAt !== '') {
        parts.push('<span class="cus-sf-banner__item"><span class="cus-sf-banner__label">آخر طلب:</span><span class="cus-sf-banner__value cus-sf-banner__value--ltr">' + cusEsc(lastAt) + '</span></span>');
    }
    el.innerHTML = parts.join('');
}
function cusEsc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function cusSplitPhoneForForm(stored) {
    var raw = String(stored || '').trim();
    if (!raw) return { country: '__intl__', phone: '' };
    var normFn = window.orangeNormalizeCustomerPhone;
    var norm = normFn ? normFn(raw, null) : null;
    if (!norm) return { country: '__intl__', phone: raw };
    var digits = norm.replace(/\D/g, '');
    var prefs = ['965', '92', '91', '63'];
    for (var i = 0; i < prefs.length; i++) {
        var cc = prefs[i];
        if (digits.indexOf(cc) !== 0) continue;
        var nat = digits.slice(cc.length);
        if (nat.length < 8) continue;
        if (normFn && normFn(nat, cc) === norm) return { country: cc, phone: nat };
    }
    return { country: '__intl__', phone: norm.charAt(0) === '+' ? norm.slice(1) : norm };
}

function cusResetForm() {
    document.getElementById('cus_id').value = '0';
    document.getElementById('cus_storefront_account_id').value = '0';
    document.getElementById('cus_code').value = String(CUS_NEXT_AUTO_CODE || '1');
    document.getElementById('cus_name').value = '';
    document.getElementById('cus_phone').value = '';
    var cc = cusPhoneCountryEl();
    if (cc) cc.value = '__intl__';
    var emEl = document.getElementById('cus_email');
    if (emEl) emEl.value = '';
    var addrEl = document.getElementById('cus_address');
    if (addrEl) addrEl.value = '';
    var areaEl = cusAreaEl();
    if (areaEl) areaEl.value = '';
    var limEl = document.getElementById('cus_credit_limit');
    if (limEl) limEl.value = '';
    var notesEl = document.getElementById('cus_notes');
    if (notesEl) notesEl.value = '';
    cusSetCurrentBalance(0);
    cusSetOrdersCount(0);
    cusSfBannerSet(null);
    cusNavRefreshButtons();
}
function cusEdit(row) {
    document.getElementById('cus_id').value = String(row.id || 0);
    document.getElementById('cus_storefront_account_id').value = String(row.storefront_account_id || 0);
    document.getElementById('cus_code').value = String(row.code || '');
    document.getElementById('cus_name').value = String(row.name_ar || '');
    var split = cusSplitPhoneForForm(row.phone || '');
    var ccEl = cusPhoneCountryEl();
    if (ccEl) ccEl.value = split.country && split.country !== '' ? split.country : '__intl__';
    document.getElementById('cus_phone').value = split.phone || '';
    var emEl = document.getElementById('cus_email');
    if (emEl) emEl.value = String(row.email || '');
    var addrEl = document.getElementById('cus_address');
    if (addrEl) addrEl.value = String(row.address || '');
    var areaEl = cusAreaEl();
    if (areaEl) {
        if (cusAreaIsSelect()) {
            var daId = row.delivery_area_id != null && row.delivery_area_id !== '' ? String(row.delivery_area_id) : '';
            var matchOpt = daId !== '' ? areaEl.querySelector('option[value="' + daId + '"]') : null;
            areaEl.value = matchOpt ? daId : '';
        } else {
            areaEl.value = String(row.area || '');
        }
    }
    var limEl = document.getElementById('cus_credit_limit');
    if (limEl) {
        var lim = row.credit_limit;
        limEl.value = lim != null && lim !== '' && Number(lim) > 0 ? String(lim) : '';
    }
    var notesEl = document.getElementById('cus_notes');
    if (notesEl) notesEl.value = String(row.notes || '');
    cusSetCurrentBalance(row.current_balance != null ? row.current_balance : 0);
    cusSetOrdersCount(row.orders_count != null ? row.orders_count : 0);
    cusSfBannerSet(row);
    var cardEl = document.getElementById('cus_form_card');
    if (cardEl) cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    cusNavRefreshButtons();
}

function cusSave() {
    var id = parseInt(document.getElementById('cus_id').value, 10) || 0;
    var name = document.getElementById('cus_name').value.trim();
    var phone = document.getElementById('cus_phone').value.trim();
    var ccEl = cusPhoneCountryEl();
    var phoneCountry = ccEl ? String(ccEl.value || '').trim() : '';
    var intlSel = ccEl && ccEl.tagName === 'SELECT' && phoneCountry === '__intl__';
    var ccForNorm = intlSel ? null : phoneCountry && phoneCountry !== '__intl__' ? phoneCountry : null;
    var emEl = document.getElementById('cus_email');
    var email = emEl ? emEl.value.trim() : '';
    var addrEl = document.getElementById('cus_address');
    var address = addrEl ? addrEl.value.trim() : '';
    var areaElForSave = cusAreaEl();
    var areaIsSelect = cusAreaIsSelect();
    var area = '';
    var deliveryAreaId = null;
    if (areaElForSave) {
        if (areaIsSelect) {
            var daIdVal = parseInt(areaElForSave.value, 10) || 0;
            if (daIdVal > 0) {
                deliveryAreaId = daIdVal;
                var selOpt = areaElForSave.options[areaElForSave.selectedIndex];
                if (selOpt) {
                    var dn = selOpt.getAttribute('data-name') || selOpt.textContent || '';
                    area = String(dn).trim();
                }
            }
        } else {
            area = String(areaElForSave.value || '').trim();
        }
    }
    var limRaw = (document.getElementById('cus_credit_limit') || { value: '' }).value.trim();
    var notesEl = document.getElementById('cus_notes');
    var notes = notesEl ? notesEl.value.trim() : '';
    if (!phone) { alert('الهاتف مطلوب'); return; }
    if (window.orangeNormalizeCustomerPhone) {
        var ok = window.orangeNormalizeCustomerPhone(phone, ccForNorm, intlSel);
        if (!ok) {
            alert('رقم الهاتف غير صالح. استخدم + أو 00 مع كود الدولة، أو اختر الدولة وأدخل الرقم الوطني (8–14 رقماً مع الكود).');
            return;
        }
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('بريد إلكتروني غير صالح');
        return;
    }
    var payload = {
        name_ar: name || 'عميل',
        phone: phone,
        phone_country: phoneCountry !== '' ? phoneCountry : null,
        area: area,
        delivery_area_id: deliveryAreaId,
        address: address,
        email: email || null,
        notes: notes || null
    };
    if (id > 0) payload.id = id;
    if (limRaw === '') {
        payload.credit_limit = null;
    } else {
        var lim = parseFloat(limRaw);
        if (isNaN(lim) || lim < 0) { alert('حد ائتمان غير صالح'); return; }
        payload.credit_limit = lim <= 0 ? null : lim;
    }
    postJSON('/admin/api/customers/save.php', payload)
        .then(function (r) {
            var msg = r.message || (r.success ? 'تم' : 'فشل');
            if (r && r.success && r.code) msg += '\nكود العميل: ' + r.code;
            alert(msg);
            if (r.success) location.reload();
        })
        .catch(function (e) { alert(e.message || String(e)); });
}

function cusOpenCurrentStatement() {
    var row = cusCurrentRow();
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        alert('اختر العميل أولاً');
        return;
    }
    window.location.href = CUS_PARTNER_STATEMENT_URL + '&mode=customer&customer=' + encodeURIComponent(String(row.id));
}
function cusOpenCurrentSalesInvoice() {
    var row = cusCurrentRow();
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        alert('اختر العميل أولاً');
        return;
    }
    window.location.href = CUS_MANUAL_ORDER_URL + '&customer_id=' + encodeURIComponent(String(row.id));
}
function cusOpenCurrentReceipt() {
    var row = cusCurrentRow();
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        alert('اختر العميل أولاً');
        return;
    }
    window.location.href = CUS_RECEIPT_URL + '&stmt_party_kind=customer&stmt_party_id=' + encodeURIComponent(String(row.id));
}

(function initCustomersPage() {
    var searchOpenBtn = document.getElementById('cus_open_search_btn');
    if (searchOpenBtn) {
        searchOpenBtn.addEventListener('click', function (e) { e.preventDefault(); cusSearchModalOpen(); });
    }
    var statementBtn = document.getElementById('cus_open_statement_btn');
    if (statementBtn) {
        statementBtn.addEventListener('click', function (e) { e.preventDefault(); cusOpenCurrentStatement(); });
    }
    var salesBtn = document.getElementById('cus_open_sales_btn');
    if (salesBtn) {
        salesBtn.addEventListener('click', function (e) { e.preventDefault(); cusOpenCurrentSalesInvoice(); });
    }
    var receiptBtn = document.getElementById('cus_open_receipt_btn');
    if (receiptBtn) {
        receiptBtn.addEventListener('click', function (e) { e.preventDefault(); cusOpenCurrentReceipt(); });
    }
    var navFirstBtn = document.getElementById('cus_nav_first');
    if (navFirstBtn) navFirstBtn.addEventListener('click', function (e) { e.preventDefault(); cusNavFirst(); });
    var navPrevBtn = document.getElementById('cus_nav_prev');
    if (navPrevBtn) navPrevBtn.addEventListener('click', function (e) { e.preventDefault(); cusNavPrev(); });
    var navNextBtn = document.getElementById('cus_nav_next');
    if (navNextBtn) navNextBtn.addEventListener('click', function (e) { e.preventDefault(); cusNavNext(); });
    var navLastBtn = document.getElementById('cus_nav_last');
    if (navLastBtn) navLastBtn.addEventListener('click', function (e) { e.preventDefault(); cusNavLast(); });

    var searchBackdrop = document.getElementById('cus_search_backdrop');
    if (searchBackdrop) searchBackdrop.addEventListener('click', cusSearchModalClose);
    var searchClose = document.getElementById('cus_search_close');
    if (searchClose) searchClose.addEventListener('click', cusSearchModalClose);
    var searchQ = document.getElementById('cus_search_q');
    if (searchQ && !searchQ.getAttribute('data-cus-search-bound')) {
        searchQ.setAttribute('data-cus-search-bound', '1');
        searchQ.addEventListener('input', function () {
            if (cusSearchTimer) clearTimeout(cusSearchTimer);
            cusSearchTimer = setTimeout(function () { cusSearchRender(searchQ.value || ''); }, 180);
        });
    }
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        var searchModal = document.getElementById('cus_search_modal');
        if (searchModal && !searchModal.hidden) { cusSearchModalClose(); }
    });

    // دبل كليك على خانة الكود يفتح modal البحث (للسرعة).
    var codeEl = document.getElementById('cus_code');
    if (codeEl) {
        codeEl.addEventListener('dblclick', function (e) {
            e.preventDefault();
            cusSearchModalOpen();
        });
    }

    cusNavRefreshButtons();
})();
</script>
