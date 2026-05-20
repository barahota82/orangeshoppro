<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
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
$hasCustomerStatusCol = orange_table_has_column($pdo, 'customers', 'status');
$hasCustomerBlockReasonCol = orange_table_has_column($pdo, 'customers', 'block_reason');
$hasCustomerAttachmentsCol = orange_table_has_column($pdo, 'customers', 'attachments_json');
$hasCustomerCivilIdCol = orange_table_has_column($pdo, 'customers', 'civil_id');

$adminDeliveryAreas = orange_delivery_areas_admin_list($pdo);
$adminDaIndex = [];
foreach ($adminDeliveryAreas as $da) {
    $daId = (int) ($da['id'] ?? 0);
    if ($daId > 0) {
        $adminDaIndex[$daId] = $da;
    }
}

// س15 + توحيد مع شاشة الموردين: قائمة خيارات المنطقة بنفس بناء suppliers.php (value = نص الاسم؛ label يجمع الاسم العربي والإنجليزي ووسم المعطّلة).
$customerAreaOptions = [];
$seenCustomerAreas = [];
foreach ($adminDeliveryAreas as $daRow) {
    if (!is_array($daRow)) {
        continue;
    }
    $nameAr = trim((string) ($daRow['name_ar'] ?? ''));
    $nameEn = trim((string) ($daRow['name_en'] ?? ''));
    $areaValue = $nameAr !== '' ? $nameAr : $nameEn;
    if ($areaValue === '') {
        continue;
    }
    $areaKey = function_exists('mb_strtolower') ? mb_strtolower($areaValue, 'UTF-8') : strtolower($areaValue);
    if (isset($seenCustomerAreas[$areaKey])) {
        continue;
    }
    $seenCustomerAreas[$areaKey] = true;
    $display = $areaValue;
    if ($nameAr !== '' && $nameEn !== '' && strcasecmp($nameAr, $nameEn) !== 0) {
        $display = $nameAr . ' — ' . $nameEn;
    }
    if ((int) ($daRow['is_active'] ?? 0) !== 1) {
        $display .= ' (غير منطقة توصيل حالياً)';
    }
    $customerAreaOptions[] = [
        'value' => $areaValue,
        'label' => $display,
        'da_id' => (int) ($daRow['id'] ?? 0),
    ];
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

    // إحصاءات الطلبات لكل عميل + تفصيل حسب نوع الفاتورة (نقدي/آجل/أونلاين) — س15-2.
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
                            'cash' => 0,
                            'credit' => 0,
                            'online' => 0,
                        ];
                    }
                }
            }
            // تفصيل حسب payment_terms (cash/credit) + source للأونلاين — استعلامان منفصلان لتجنّب تعقيد GROUP BY.
            $hasPaymentTerms = orange_table_has_column($pdo, 'orders', 'payment_terms');
            $hasSource = orange_table_has_column($pdo, 'orders', 'source');
            $hasChannelId = orange_table_has_column($pdo, 'orders', 'channel_id');
            // 1) تفصيل النقدي/الآجل (payment_terms)
            if ($hasPaymentTerms) {
                $dSt1 = $pdo->query(
                    'SELECT customer_id, payment_terms, COUNT(*) AS cnt
                     FROM orders WHERE customer_id IS NOT NULL AND customer_id > 0
                     GROUP BY customer_id, payment_terms'
                );
                if ($dSt1) {
                    while ($dRow = $dSt1->fetch(PDO::FETCH_ASSOC)) {
                        $cid = (int) ($dRow['customer_id'] ?? 0);
                        if ($cid <= 0 || !isset($orderStatsByCustomerId[$cid])) {
                            continue;
                        }
                        $pt = strtolower(trim((string) ($dRow['payment_terms'] ?? 'cash')));
                        $cnt = (int) ($dRow['cnt'] ?? 0);
                        if ($pt === 'credit') {
                            $orderStatsByCustomerId[$cid]['credit'] += $cnt;
                        } else {
                            $orderStatsByCustomerId[$cid]['cash'] += $cnt;
                        }
                    }
                }
            } else {
                foreach ($orderStatsByCustomerId as $cid => &$st) {
                    $st['cash'] = (int) ($st['count'] ?? 0);
                }
                unset($st);
            }
            // 2) تفصيل الأونلاين (source/storefront أو channel_id غير NULL) — يُعدّل التفصيل أعلاه (نضمن أن online لا يُحتسب مرتين).
            if ($hasSource || $hasChannelId) {
                $cond = $hasSource ? "source IN ('online','storefront')" : 'channel_id IS NOT NULL';
                $dSt2 = $pdo->query(
                    "SELECT customer_id, COUNT(*) AS cnt
                     FROM orders WHERE customer_id IS NOT NULL AND customer_id > 0 AND $cond
                     GROUP BY customer_id"
                );
                if ($dSt2) {
                    while ($dRow = $dSt2->fetch(PDO::FETCH_ASSOC)) {
                        $cid = (int) ($dRow['customer_id'] ?? 0);
                        if ($cid <= 0 || !isset($orderStatsByCustomerId[$cid])) {
                            continue;
                        }
                        $cnt = (int) ($dRow['cnt'] ?? 0);
                        $orderStatsByCustomerId[$cid]['online'] = $cnt;
                        // اقتطاع من النقدي/الآجل (الأونلاين له toggled كقناة + قد يحمل payment_terms="cash"؛ نعتبر الأونلاين أولوية للتفصيل البصري).
                        if ($cnt > 0) {
                            $remaining = max(0, (int) $orderStatsByCustomerId[$cid]['cash'] - $cnt);
                            $consumed = (int) $orderStatsByCustomerId[$cid]['cash'] - $remaining;
                            $orderStatsByCustomerId[$cid]['cash'] = $remaining;
                            if ($consumed < $cnt) {
                                $stillFromCredit = $cnt - $consumed;
                                $orderStatsByCustomerId[$cid]['credit'] = max(0, (int) $orderStatsByCustomerId[$cid]['credit'] - $stillFromCredit);
                            }
                        }
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
            (string) ($r['civil_id'] ?? '') . ' ' .
            (string) ($r['area'] ?? '') . ' ' .
            $daName . ' ' .
            (string) ($r['notes'] ?? '')
        );
        $searchHay = function_exists('mb_strtolower') ? mb_strtolower($searchHayRaw, 'UTF-8') : strtolower($searchHayRaw);

        $statusRaw = strtolower(trim((string) ($r['status'] ?? 'active')));
        if (!in_array($statusRaw, ['active', 'inactive', 'blocked'], true)) {
            $statusRaw = 'active';
        }

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
            'civil_id' => (string) ($r['civil_id'] ?? ''),
            'notes' => (string) ($r['notes'] ?? ''),
            'status' => $statusRaw,
            'block_reason' => (string) ($r['block_reason'] ?? ''),
            'attachments_json' => (string) ($r['attachments_json'] ?? ''),
            'current_balance' => round((float) $bal, 3),
            'orders_count' => (int) ($stats['count'] ?? 0),
            'orders_last_at' => (string) ($stats['last_at'] ?? ''),
            'orders_cash' => (int) ($stats['cash'] ?? 0),
            'orders_credit' => (int) ($stats['credit'] ?? 0),
            'orders_online' => (int) ($stats['online'] ?? 0),
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
/*
 * ترتيب الحقول كما طلب المالك (RTL → القارئ يبدأ من اليمين):
 * r1: كود العميل (تلقائي) — full width مع شريط التنقل
 * r2: كود الدولة | الهاتف | حد الائتمان | رصيد الذمة | حالة العميل  (5 أعمدة)
 * r3: اسم العميل | الرقم المدني | البريد الإلكتروني | المرفقات (عداد + زر داخل label واحد)  (5 grid: 1+1+1+2)
 *     — نفس نمط suppliers.php سطر «المعاملة الضريبية»: خلية المرفقات بـ sub-grid 1fr 1fr.
 * r4: المنطقة | العنوان  (1 + 4 spans)
 * r4b: سبب الحظر (full — يظهر فقط عند حالة محظور)
 * r5: ملاحظات (full)
 * r6: تفصيل الطلبات (full)
 * r7: لافتة حساب الواجهة (full)
 */
#cus_form_grid.customers-form-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr);
    row-gap: 12px;
    column-gap: 12px;
    grid-template-areas:
        "cus_r1_code cus_r1_code cus_r1_code cus_r1_code cus_r1_code"
        "cus_r2_country cus_r2_phone cus_r2_credit cus_r2_balance cus_r2_status"
        "cus_r3_all cus_r3_all cus_r3_all cus_r3_all cus_r3_all"
        "cus_r4_area cus_r4_address cus_r4_address cus_r4_address cus_r4_address"
        "cus_r4b_block_reason cus_r4b_block_reason cus_r4b_block_reason cus_r4b_block_reason cus_r4b_block_reason"
        "cus_r5_notes cus_r5_notes cus_r5_notes cus_r5_notes cus_r5_notes"
        "cus_r6_orders cus_r6_orders cus_r6_orders cus_r6_orders cus_r6_orders"
        "cus_r7_sf cus_r7_sf cus_r7_sf cus_r7_sf cus_r7_sf";
}
#cus_form_grid.customers-form-grid.customers-form-grid--block-hidden {
    grid-template-areas:
        "cus_r1_code cus_r1_code cus_r1_code cus_r1_code cus_r1_code"
        "cus_r2_country cus_r2_phone cus_r2_credit cus_r2_balance cus_r2_status"
        "cus_r3_all cus_r3_all cus_r3_all cus_r3_all cus_r3_all"
        "cus_r4_area cus_r4_address cus_r4_address cus_r4_address cus_r4_address"
        "cus_r5_notes cus_r5_notes cus_r5_notes cus_r5_notes cus_r5_notes"
        "cus_r6_orders cus_r6_orders cus_r6_orders cus_r6_orders cus_r6_orders"
        "cus_r7_sf cus_r7_sf cus_r7_sf cus_r7_sf cus_r7_sf";
}
/* r3 صف مستقل بـ 4 أعمدة × 25% (نسخة طبق الأصل من سطر «المعاملة الضريبية» في الموردين). */
#cus_form_grid .cus-grid-r3-all {
    grid-area: cus_r3_all;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr);
    column-gap: 12px;
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
#cus_form_grid .cus-grid-r2-country { grid-area: cus_r2_country; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
#cus_form_grid .cus-grid-r2-phone   { grid-area: cus_r2_phone;   display: flex; flex-direction: column; gap: 6px; min-width: 0; }
#cus_form_grid .cus-grid-r2-credit  { grid-area: cus_r2_credit;  display: flex; flex-direction: column; gap: 6px; min-width: 0; }
#cus_form_grid .cus-grid-r2-balance { grid-area: cus_r2_balance; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
#cus_form_grid .cus-grid-r2-status  { grid-area: cus_r2_status;  display: flex; flex-direction: column; gap: 6px; min-width: 0; }
/* خلايا داخل r3-all — كل واحدة flex column (label + input/select) بلا grid-area لأنها داخل الـ sub-grid. */
#cus_form_grid .cus-grid-r3-cell        { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
#cus_form_grid .cus-grid-r3-attachments { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
/* sub-grid (عداد + زر) داخل خلية المرفقات — نفس نمط suppliers.php */
#cus_form_grid .cus-attachments-inline {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px;
    width: 100%;
}
#cus_form_grid #cus_attachments_count {
    max-width: none;
    width: 100%;
    text-align: center;
}
#cus_form_grid #cus_attachments_manage_btn {
    width: 100%;
    height: 42px;
}
#cus_form_grid .cus-grid-r4-area    { grid-area: cus_r4_area;    display: flex; flex-direction: column; gap: 6px; min-width: 0; }
#cus_form_grid .cus-grid-r4-address { grid-area: cus_r4_address; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
#cus_form_grid .cus-grid-r4b-block-reason {
    grid-area: cus_r4b_block_reason;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r6-orders {
    grid-area: cus_r6_orders;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-orders-breakdown {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr);
    gap: 8px;
}
#cus_form_grid .cus-orders-breakdown__item {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 10px;
    border: 1px solid #e4e4e7;
    border-radius: 8px;
    background: #f8fafc;
    font-size: 0.85rem;
}
#cus_form_grid .cus-orders-breakdown__label {
    color: #64748b;
}
#cus_form_grid .cus-orders-breakdown__val {
    color: #0f172a;
    font-weight: 700;
    font-size: 1.05rem;
    text-align: center;
}
.cus-attachments-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}
.cus-attachments-toolbar > div {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.cus-attachments-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 320px;
    overflow-y: auto;
}
.cus-attachment-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border: 1px solid #e4e4e7;
    border-radius: 8px;
}
.cus-attachment-actions {
    display: flex;
    gap: 6px;
}
#cus_form_grid .cus-area-current-hint {
    margin: 4px 0 0;
    font-size: 0.85rem;
    color: #b45309;
}
#cus_form_grid .cus-grid-r5-notes {
    grid-area: cus_r5_notes;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#cus_form_grid .cus-grid-r7-sf {
    grid-area: cus_r7_sf;
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
            "cus_r2_country cus_r2_phone"
            "cus_r2_credit cus_r2_balance"
            "cus_r2_status cus_r2_status"
            "cus_r3_all cus_r3_all"
            "cus_r4_area cus_r4_address"
            "cus_r4b_block_reason cus_r4b_block_reason"
            "cus_r5_notes cus_r5_notes"
            "cus_r6_orders cus_r6_orders"
            "cus_r7_sf cus_r7_sf";
    }
    #cus_form_grid.customers-form-grid.customers-form-grid--block-hidden {
        grid-template-areas:
            "cus_r1_code cus_r1_code"
            "cus_r2_country cus_r2_phone"
            "cus_r2_credit cus_r2_balance"
            "cus_r2_status cus_r2_status"
            "cus_r3_all cus_r3_all"
            "cus_r4_area cus_r4_address"
            "cus_r5_notes cus_r5_notes"
            "cus_r6_orders cus_r6_orders"
            "cus_r7_sf cus_r7_sf";
    }
    #cus_form_grid .cus-grid-r3-all {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
    #cus_form_grid .cus-orders-breakdown {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
}
@media (max-width: 768px) {
    #cus_form_grid.customers-form-grid {
        grid-template-columns: 1fr;
        grid-template-areas:
            "cus_r1_code"
            "cus_r2_country"
            "cus_r2_phone"
            "cus_r2_credit"
            "cus_r2_balance"
            "cus_r2_status"
            "cus_r3_all"
            "cus_r4_area"
            "cus_r4_address"
            "cus_r4b_block_reason"
            "cus_r5_notes"
            "cus_r6_orders"
            "cus_r7_sf";
    }
    #cus_form_grid.customers-form-grid.customers-form-grid--block-hidden {
        grid-template-areas:
            "cus_r1_code"
            "cus_r2_country"
            "cus_r2_phone"
            "cus_r2_credit"
            "cus_r2_balance"
            "cus_r2_status"
            "cus_r3_all"
            "cus_r4_area"
            "cus_r4_address"
            "cus_r5_notes"
            "cus_r6_orders"
            "cus_r7_sf";
    }
    #cus_form_grid .cus-grid-r3-all {
        grid-template-columns: 1fr;
    }
    #cus_form_grid .cus-orders-breakdown {
        grid-template-columns: 1fr;
    }
    .cus-attachments-toolbar {
        grid-template-columns: 1fr;
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
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_code_edit_btn" title="تعديل الكود يدوياً">تعديل الكود</button>
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_nav_first" title="أول عميل" aria-label="أول عميل">&lt;&lt;</button>
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_nav_prev" title="العميل السابق" aria-label="العميل السابق">&lt;</button>
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_nav_next" title="العميل التالي" aria-label="العميل التالي">&gt;</button>
                    <button type="button" class="btn-secondary cus-code-nav-btn" id="cus_nav_last" title="آخر عميل" aria-label="آخر عميل">&gt;&gt;</button>
                    <button type="button" class="btn-secondary cus-code-nav-search" id="cus_open_search_btn" title="بحث عميل">بحث</button>
                </div>
            </div>
        </div>

        <!-- الصف 2: كود الدولة | الهاتف | حد الائتمان | رصيد الذمة | حالة العميل (RTL: العين تبدأ يميناً) -->
        <div class="cus-grid-r2-country">
            <label for="cus_phone_country">كود الدولة</label>
            <input type="search" id="cus_phone_country" list="cus_phone_country_list" autocomplete="off" dir="ltr" lang="en" placeholder="اكتب اسم الدولة أو +965 أو 965">
            <datalist id="cus_phone_country_list"></datalist>
        </div>
        <div class="cus-grid-r2-phone">
            <label for="cus_phone">الهاتف <span style="color:#b45309;">*</span></label>
            <input type="text" id="cus_phone" class="js-orange-phone-input" maxlength="24" autocomplete="off" dir="ltr" lang="en" placeholder="اكتب الرقم المحلي فقط بدون كود الدولة">
        </div>
        <?php if ($hasCustomerLimitCol): ?>
        <div class="cus-grid-r2-credit">
            <label for="cus_credit_limit">حد الائتمان</label>
            <input type="number" id="cus_credit_limit" class="admin-inp-money" step="any" min="0" value="" placeholder="فارغ = بلا حد" inputmode="decimal" lang="en" dir="ltr">
        </div>
        <?php endif; ?>
        <div class="cus-grid-r2-balance">
            <label for="cus_current_balance">رصيد الذمة (مدين)</label>
            <input type="text" id="cus_current_balance" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" value="0.000" readonly>
        </div>
        <?php if ($hasCustomerStatusCol): ?>
        <div class="cus-grid-r2-status">
            <label for="cus_status">حالة العميل</label>
            <select id="cus_status">
                <option value="active" selected>نشط</option>
                <option value="inactive">غير نشط</option>
                <option value="blocked">محظور مؤقتاً</option>
            </select>
        </div>
        <?php endif; ?>

        <!-- الصف 3: 4 خلايا متساوية (25% × 4) مطابقة لنمط الموردين -->
        <div class="cus-grid-r3-all">
            <div class="cus-grid-r3-cell">
                <label for="cus_name">اسم العميل</label>
                <input type="text" id="cus_name" autocomplete="off" placeholder="اسم العميل" maxlength="255">
            </div>
            <?php if ($hasCustomerCivilIdCol): ?>
            <div class="cus-grid-r3-cell">
                <label for="cus_civil_id">الرقم المدني</label>
                <input type="text" id="cus_civil_id" maxlength="20" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري — فريد إذا أُدخل">
            </div>
            <?php endif; ?>
            <?php if ($hasCustomerEmailCol): ?>
            <div class="cus-grid-r3-cell">
                <label for="cus_email">البريد الإلكتروني</label>
                <input type="email" id="cus_email" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري">
            </div>
            <?php endif; ?>
            <?php if ($hasCustomerAttachmentsCol): ?>
            <div class="cus-grid-r3-attachments" id="cus_attachments_wrap">
                <label for="cus_attachments_count">عدد المرفقات</label>
                <div class="cus-attachments-inline">
                    <input type="text" id="cus_attachments_count" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" value="0" readonly>
                    <button type="button" class="btn-secondary" id="cus_attachments_manage_btn">إدارة المرفقات</button>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- الصف 4: المنطقة | العنوان -->
        <div class="cus-grid-r4-area">
            <label for="cus_city_area">المنطقة</label>
            <select id="cus_city_area" autocomplete="address-level1">
                <option value="">اختياري</option>
                <?php foreach ($customerAreaOptions as $areaOpt): ?>
                    <option value="<?php echo htmlspecialchars((string) $areaOpt['value'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars((string) $areaOpt['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p id="cus_area_current_hint" class="cus-area-current-hint" hidden></p>
        </div>
        <?php if ($hasCustomerAddressCol): ?>
        <div class="cus-grid-r4-address">
            <label for="cus_address">العنوان</label>
            <input type="text" id="cus_address" autocomplete="off" placeholder="عنوان التوصيل" maxlength="2000">
        </div>
        <?php endif; ?>

        <?php if ($hasCustomerBlockReasonCol): ?>
        <div id="cus_block_reason_wrap" class="cus-grid-r4b-block-reason" style="display:none;">
            <label for="cus_block_reason">سبب الحظر (عند الحظر)</label>
            <input type="text" id="cus_block_reason" maxlength="255" autocomplete="off" placeholder="اختياري إذا العميل غير محظور">
        </div>
        <?php endif; ?>

        <?php if ($hasCustomerNotesCol): ?>
        <div class="cus-grid-r5-notes">
            <label for="cus_notes">ملاحظات (تتراكم تلقائياً من الطلبات)</label>
            <textarea id="cus_notes" rows="3" autocomplete="off" placeholder="ملاحظات يدوية + أسطر تلقائية من كل طلب ويب"></textarea>
        </div>
        <?php endif; ?>

        <div class="cus-grid-r6-orders">
            <label>تفصيل الطلبات حسب نوع الفاتورة</label>
            <div class="cus-orders-breakdown">
                <div class="cus-orders-breakdown__item">
                    <span class="cus-orders-breakdown__label">إجمالي</span>
                    <span class="cus-orders-breakdown__val" id="cus_orders_count" dir="ltr">0</span>
                </div>
                <div class="cus-orders-breakdown__item">
                    <span class="cus-orders-breakdown__label">نقدي (أدمن)</span>
                    <span class="cus-orders-breakdown__val" id="cus_orders_cash" dir="ltr">0</span>
                </div>
                <div class="cus-orders-breakdown__item">
                    <span class="cus-orders-breakdown__label">آجل (أدمن)</span>
                    <span class="cus-orders-breakdown__val" id="cus_orders_credit" dir="ltr">0</span>
                </div>
                <div class="cus-orders-breakdown__item">
                    <span class="cus-orders-breakdown__label">أونلاين</span>
                    <span class="cus-orders-breakdown__val" id="cus_orders_online" dir="ltr">0</span>
                </div>
            </div>
        </div>

        <div class="cus-grid-r7-sf">
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
        <button type="button" class="btn-secondary" id="cus_open_sales_return_btn">مردود مبيعات</button>
        <button type="button" class="btn-secondary" id="cus_open_receipt_btn">قبض من العميل</button>
        <button type="button" class="btn-secondary" id="cus_open_orders_btn">طلباته</button>
        <button type="button" class="btn-secondary" id="cus_open_statement_btn">كشف حساب</button>
        <button type="button" class="btn-secondary" id="cus_print_btn">طباعة بطاقة</button>
        <button type="button" class="btn-danger" id="cus_delete_btn">حذف</button>
    </div>
</div>

<?php if ($hasCustomerAttachmentsCol): ?>
<div class="gl-pick-modal" id="cus_attachments_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="cus_attachments_backdrop"></div>
    <div class="gl-pick-modal__dialog cus-attachments-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="cus_attachments_title">
        <h3 id="cus_attachments_title" class="gl-pick-modal__title">مرفقات العميل</h3>
        <p class="gl-pick-modal__hint muted" id="cus_attachments_hint" style="margin:0 0 10px;font-size:0.9rem;">
            PDF وصور فقط — حد أقصى 5 مرفقات لكل عميل (حتى 20MB للملف قبل الضغط).
        </p>
        <div class="cus-attachments-toolbar">
            <div>
                <label for="cus_attachment_file">اختر ملف</label>
                <input type="file" id="cus_attachment_file" accept=".pdf,image/*">
            </div>
            <div>
                <label for="cus_attachment_name">اسم الملف</label>
                <input type="text" id="cus_attachment_name" maxlength="191" autocomplete="off" placeholder="اختياري (يؤخذ من اسم الملف)">
            </div>
            <div class="actions" style="margin:0;">
                <button type="button" class="btn-secondary" id="cus_attachment_upload_btn">رفع مرفق</button>
            </div>
        </div>
        <div class="cus-attachments-list" id="cus_attachments_list"></div>
        <div class="actions" style="margin-top:12px;">
            <button type="button" class="btn-secondary" id="cus_attachments_close">إغلاق</button>
        </div>
    </div>
</div>
<?php endif; ?>

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

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/country-codes.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/admin-phone-country.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/input-constraints.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
window.ORANGE_ADMIN_PHONE_INTL_LABEL = <?php echo json_encode(t('phone_country_full_international'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_NEXT_AUTO_CODE = <?php echo json_encode($nextCustomerCodePreview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_SEARCH_ROWS = <?php echo json_encode($customerSearchRowsPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_PARTNER_STATEMENT_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=partner_account_statement'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_MANUAL_ORDER_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=manual_order'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_RECEIPT_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=partner_customer_receipt'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_SALES_RETURN_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=sales_returns'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_ORDERS_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=orders'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var CUS_PRINT_URL = <?php echo json_encode(storefront_public_path('/admin/api/customers/print-card.php'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
    return document.getElementById('cus_city_area');
}
/**
 * يطابق نمط الموردين: المنطقة select مع قيم نصية (الاسم).
 * يحاول مطابقة الاسم في القائمة عند التحديث؛ لو لم يجد، يبقى فارغاً (Default «اختياري»).
 */
function cusAreaSetValue(value) {
    var el = cusAreaEl();
    if (!el) return;
    var name = String(value == null ? '' : value).trim();
    if (name === '') { el.value = ''; return; }
    for (var i = 0; i < el.options.length; i++) {
        if (String(el.options[i].value || '') === name) {
            el.value = name;
            return;
        }
    }
    // لو الاسم خارج القائمة (نظرياً مستحيل ما دامت من نفس delivery_areas): نُبقي «اختياري».
    el.value = '';
}
function cusSetCurrentBalance(value) {
    var el = document.getElementById('cus_current_balance');
    if (!el) return;
    var n = parseFloat(String(value || 0));
    if (isNaN(n)) n = 0;
    el.value = n.toFixed(3);
}
function cusSetOrdersBreakdown(total, cash, credit, online) {
    var ids = {
        'cus_orders_count': total,
        'cus_orders_cash': cash,
        'cus_orders_credit': credit,
        'cus_orders_online': online
    };
    for (var key in ids) {
        if (!Object.prototype.hasOwnProperty.call(ids, key)) continue;
        var el = document.getElementById(key);
        if (!el) continue;
        var n = parseInt(String(ids[key] || 0), 10);
        if (isNaN(n)) n = 0;
        if (el.tagName === 'INPUT') {
            el.value = String(n);
        } else {
            el.textContent = String(n);
        }
    }
}
function cusToggleBlockReason() {
    var statusEl = document.getElementById('cus_status');
    var reasonEl = document.getElementById('cus_block_reason');
    var wrapEl = document.getElementById('cus_block_reason_wrap');
    var gridEl = document.getElementById('cus_form_grid');
    if (!statusEl) return;
    var isBlocked = String(statusEl.value || 'active') === 'blocked';
    if (gridEl && gridEl.classList) {
        gridEl.classList.toggle('customers-form-grid--block-hidden', !isBlocked);
    }
    if (wrapEl) {
        wrapEl.style.setProperty('display', isBlocked ? 'flex' : 'none', 'important');
    }
    if (reasonEl) {
        reasonEl.required = isBlocked;
        reasonEl.placeholder = isBlocked ? 'سبب الحظر مطلوب' : 'اختياري إذا العميل غير محظور';
        if (!isBlocked) reasonEl.value = '';
    }
}
function cusUpdateAreaCurrentHint(row) {
    var hint = document.getElementById('cus_area_current_hint');
    if (!hint) return;
    var areaEl = cusAreaEl();
    if (!areaEl) { hint.hidden = true; return; }
    var saved = String((row && (row.area || row.delivery_area_name)) || '').trim();
    var matched = String(areaEl.value || '').trim();
    if (saved === '' || matched !== '') {
        hint.hidden = true;
        return;
    }
    hint.hidden = false;
    hint.textContent = 'المنطقة المسجّلة سابقاً: ' + saved + ' — غير موجودة في القائمة الحالية';
}
function cusUpdateAttachmentsCount(jsonStr) {
    var el = document.getElementById('cus_attachments_count');
    if (!el) return;
    var arr = cusAttachmentsParse(jsonStr);
    el.value = String(arr.length);
}
function cusAttachmentsParse(jsonStr) {
    var raw = String(jsonStr || '').trim();
    if (raw === '') return [];
    try {
        var parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) return parsed;
    } catch (e) { /* ignore */ }
    return [];
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

function cusSplitPhoneForForm(stored, preferredDial, preferredNational) {
    if (window.orangeAdminPhoneCountry) {
        return window.orangeAdminPhoneCountry.splitPhoneForForm(
            stored,
            preferredDial || '',
            preferredNational || '',
            true
        );
    }
    return { country: '965', phone: String(stored || '').trim() };
}

function cusResetForm() {
    document.getElementById('cus_id').value = '0';
    document.getElementById('cus_storefront_account_id').value = '0';
    var codeEl = document.getElementById('cus_code');
    if (codeEl) {
        codeEl.value = String(CUS_NEXT_AUTO_CODE || '1');
        codeEl.readOnly = true;
        codeEl.classList.add('admin-sort-field--muted');
    }
    document.getElementById('cus_name').value = '';
    document.getElementById('cus_phone').value = '';
    var cc = cusPhoneCountryEl();
    if (cc && window.orangeAdminPhoneCountry) {
        window.orangeAdminPhoneCountry.setInputByDial(cc, window.orangeAdminPhoneCountry.defaultCountryDial(), true);
    }
    var statusEl = document.getElementById('cus_status');
    if (statusEl) statusEl.value = 'active';
    var brEl = document.getElementById('cus_block_reason');
    if (brEl) brEl.value = '';
    var emEl = document.getElementById('cus_email');
    if (emEl) emEl.value = '';
    var civilEl = document.getElementById('cus_civil_id');
    if (civilEl) civilEl.value = '';
    var addrEl = document.getElementById('cus_address');
    if (addrEl) addrEl.value = '';
    cusAreaSetValue('');
    var hint = document.getElementById('cus_area_current_hint');
    if (hint) hint.hidden = true;
    var limEl = document.getElementById('cus_credit_limit');
    if (limEl) limEl.value = '';
    var notesEl = document.getElementById('cus_notes');
    if (notesEl) notesEl.value = '';
    cusSetCurrentBalance(0);
    cusSetOrdersBreakdown(0, 0, 0, 0);
    cusSfBannerSet(null);
    cusAttachmentSetRows([]);
    cusAttachmentModalClose();
    cusToggleBlockReason();
    cusNavRefreshButtons();
}
function cusEdit(row) {
    document.getElementById('cus_id').value = String(row.id || 0);
    document.getElementById('cus_storefront_account_id').value = String(row.storefront_account_id || 0);
    var codeEl = document.getElementById('cus_code');
    if (codeEl) {
        codeEl.value = String(row.code || '');
        codeEl.readOnly = true;
        codeEl.classList.add('admin-sort-field--muted');
    }
    document.getElementById('cus_name').value = String(row.name_ar || '');
    var split = cusSplitPhoneForForm(row.phone || '', row.phone_country_dial || '', row.phone_national || '');
    var ccEl = cusPhoneCountryEl();
    if (ccEl && window.orangeAdminPhoneCountry) {
        var ccSet = split.country && split.country !== '' ? split.country : window.orangeAdminPhoneCountry.defaultCountryDial();
        window.orangeAdminPhoneCountry.setInputByDial(ccEl, ccSet, true);
    }
    document.getElementById('cus_phone').value = split.phone || '';
    var statusEl = document.getElementById('cus_status');
    if (statusEl) {
        var st = String(row.status || 'active').toLowerCase();
        if (st !== 'active' && st !== 'inactive' && st !== 'blocked') st = 'active';
        statusEl.value = st;
    }
    var brEl = document.getElementById('cus_block_reason');
    if (brEl) brEl.value = String(row.block_reason || '');
    var emEl = document.getElementById('cus_email');
    if (emEl) emEl.value = String(row.email || '');
    var civilEl = document.getElementById('cus_civil_id');
    if (civilEl) civilEl.value = String(row.civil_id || '');
    var addrEl = document.getElementById('cus_address');
    if (addrEl) addrEl.value = String(row.address || '');
    var areaNameForForm = String(row.delivery_area_name || row.area || '').trim();
    cusAreaSetValue(areaNameForForm);
    cusUpdateAreaCurrentHint(row);
    var limEl = document.getElementById('cus_credit_limit');
    if (limEl) {
        var lim = row.credit_limit;
        limEl.value = lim != null && lim !== '' && Number(lim) > 0 ? String(lim) : '';
    }
    var notesEl = document.getElementById('cus_notes');
    if (notesEl) notesEl.value = String(row.notes || '');
    cusSetCurrentBalance(row.current_balance != null ? row.current_balance : 0);
    cusSetOrdersBreakdown(
        row.orders_count != null ? row.orders_count : 0,
        row.orders_cash != null ? row.orders_cash : 0,
        row.orders_credit != null ? row.orders_credit : 0,
        row.orders_online != null ? row.orders_online : 0
    );
    cusSfBannerSet(row);
    cusAttachmentLoadFromJson(row.attachments_json || '');
    cusToggleBlockReason();
    var cardEl = document.getElementById('cus_form_card');
    if (cardEl) cardEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    cusNavRefreshButtons();
}

function cusSave() {
    var id = parseInt(document.getElementById('cus_id').value, 10) || 0;
    var name = document.getElementById('cus_name').value.trim();
    var phone = document.getElementById('cus_phone').value.trim();
    var ccEl = cusPhoneCountryEl();
    var phoneCountry = window.orangeAdminPhoneCountry && ccEl
        ? window.orangeAdminPhoneCountry.forApi(ccEl, true)
        : null;
    var intlSel = phoneCountry === '__intl__';
    var ccForNorm = intlSel ? null : phoneCountry;
    var emEl = document.getElementById('cus_email');
    var email = emEl ? emEl.value.trim() : '';
    var addrEl = document.getElementById('cus_address');
    var address = addrEl ? addrEl.value.trim() : '';
    var civilEl = document.getElementById('cus_civil_id');
    var civilId = civilEl ? civilEl.value.trim() : '';
    // نمط الموردين: نُرسل اسم المنطقة نصاً؛ السيرفر يبحث عن delivery_area_id المطابق.
    var areaElForSave = cusAreaEl();
    var area = areaElForSave ? String(areaElForSave.value || '').trim() : '';
    var limRaw = (document.getElementById('cus_credit_limit') || { value: '' }).value.trim();
    var notesEl = document.getElementById('cus_notes');
    var notes = notesEl ? notesEl.value.trim() : '';
    if (!phone) { alert('الهاتف مطلوب'); return; }
    if (!intlSel && !ccForNorm) {
        alert('اختيار كود الدولة إلزامي. اكتب الرقم الوطني فقط في خانة الهاتف، أو اختر «دولي» للرقم الكامل.');
        return;
    }
    if (!intlSel) {
        if (/^\s*(\+|00)/.test(phone)) {
            alert('اكتب الهاتف كرقم محلي فقط بدون + أو 00؛ كود الدولة يُؤخذ من القائمة.');
            return;
        }
        var phoneDigits = phone.replace(/\D+/g, '');
        if (phoneDigits !== '' && phoneDigits.indexOf(ccForNorm) === 0 && phoneDigits.length > ccForNorm.length + 3) {
            alert('لا تكرر كود الدولة داخل خانة الهاتف؛ اكتب الرقم المحلي فقط.');
            return;
        }
        if (window.orangeNormalizeCustomerPhone) {
            var ok = window.orangeNormalizeCustomerPhone(phone, ccForNorm, false);
            if (!ok) {
                alert('رقم الهاتف غير صالح. اكتب الرقم المحلي فقط بعد اختيار كود الدولة.');
                return;
            }
        }
    } else if (window.orangeNormalizeCustomerPhone) {
        var okIntl = window.orangeNormalizeCustomerPhone(phone, null, true);
        if (!okIntl) {
            alert('رقم الهاتف غير صالح. استخدم + أو 00 مع كود الدولة، أو اختر دولة وأدخل الرقم الوطني.');
            return;
        }
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('بريد إلكتروني غير صالح');
        return;
    }
    var statusEl = document.getElementById('cus_status');
    var statusVal = statusEl ? String(statusEl.value || 'active').toLowerCase() : 'active';
    if (!['active', 'inactive', 'blocked'].includes(statusVal)) statusVal = 'active';
    var brEl = document.getElementById('cus_block_reason');
    var brVal = brEl ? String(brEl.value || '').trim() : '';
    if (statusVal === 'blocked' && brVal === '') {
        alert('سبب الحظر مطلوب عند اختيار محظور مؤقتاً');
        return;
    }
    var codeEl2 = document.getElementById('cus_code');
    var codeVal = codeEl2 && !codeEl2.readOnly ? String(codeEl2.value || '').trim() : '';
    var payload = {
        name_ar: name || 'عميل',
        phone: phone,
        phone_country: phoneCountry != null && phoneCountry !== '' ? phoneCountry : null,
        area: area,
        address: address,
        email: email || null,
        civil_id: civilId !== '' ? civilId : null,
        notes: notes || null,
        status: statusVal,
        block_reason: statusVal === 'blocked' ? brVal : null
    };
    if (codeVal !== '') payload.code = codeVal;
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
function cusOpenCurrentSalesReturn() {
    var row = cusCurrentRow();
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        alert('اختر العميل أولاً');
        return;
    }
    window.location.href = CUS_SALES_RETURN_URL + '&customer_id=' + encodeURIComponent(String(row.id));
}
function cusOpenCurrentOrders() {
    var row = cusCurrentRow();
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        alert('اختر العميل أولاً');
        return;
    }
    window.location.href = CUS_ORDERS_URL + '&customer_id=' + encodeURIComponent(String(row.id));
}
function cusPrintCurrentCard() {
    var row = cusCurrentRow();
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        alert('اختر العميل أولاً');
        return;
    }
    window.open(CUS_PRINT_URL + '?customer_id=' + encodeURIComponent(String(row.id)), '_blank');
}
function cusDeleteCurrent() {
    var row = cusCurrentRow();
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        alert('اختر العميل أولاً');
        return;
    }
    if (!window.confirm('حذف العميل «' + (row.name_ar || ('#' + row.id)) + '» نهائياً؟\nشرط: العميل بلا رصيد ذمة + بلا طلبات + بلا حساب واجهة.')) {
        return;
    }
    postJSON('/admin/api/customers/delete.php', { id: row.id })
        .then(function (r) {
            alert(r.message || (r.success ? 'تم الحذف' : 'فشل الحذف'));
            if (r.success) location.reload();
        })
        .catch(function (e) { alert(e.message || String(e)); });
}
function cusToggleCodeEdit() {
    var codeEl = document.getElementById('cus_code');
    if (!codeEl) return;
    var willEdit = codeEl.readOnly;
    if (willEdit) {
        if (!window.confirm('تحذير: تعديل كود عميل قائم قد يكسر تقارير قديمة.\nهل أنت متأكد؟')) {
            return;
        }
        codeEl.readOnly = false;
        codeEl.classList.remove('admin-sort-field--muted');
        codeEl.focus();
        codeEl.select();
    } else {
        codeEl.readOnly = true;
        codeEl.classList.add('admin-sort-field--muted');
    }
}

// المرفقات (نسخ نمط الموردين).
function cusAttachmentInputs() {
    return {
        file: document.getElementById('cus_attachment_file'),
        name: document.getElementById('cus_attachment_name')
    };
}
function cusAttachmentModalOpen() {
    var idEl = document.getElementById('cus_id');
    var cid = parseInt(String(idEl && idEl.value || '0'), 10) || 0;
    if (cid <= 0) {
        alert('احفظ العميل أولاً قبل إدارة المرفقات');
        return;
    }
    var modal = document.getElementById('cus_attachments_modal');
    if (!modal) return;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gl-pick-open');
}
function cusAttachmentModalClose() {
    var modal = document.getElementById('cus_attachments_modal');
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('gl-pick-open');
}
function cusAttachmentSetRows(arr) {
    var list = document.getElementById('cus_attachments_list');
    if (!list) return;
    list.innerHTML = '';
    var rows = Array.isArray(arr) ? arr : [];
    if (rows.length === 0) {
        list.innerHTML = '<p class="muted" style="margin:0;">لا توجد مرفقات.</p>';
        return;
    }
    rows.forEach(function (att) {
        var row = document.createElement('div');
        row.className = 'cus-attachment-row';
        var label = document.createElement('div');
        var displayName = String(att.name || att.file || '').trim();
        var ts = String(att.created_at || '').trim();
        label.innerHTML = '<strong>' + cusEsc(displayName) + '</strong>' + (ts ? '<br><span class="muted" style="font-size:0.8rem;">' + cusEsc(ts) + '</span>' : '');
        var actions = document.createElement('div');
        actions.className = 'cus-attachment-actions';
        if (att.url) {
            var openBtn = document.createElement('a');
            openBtn.className = 'btn-secondary';
            openBtn.href = att.url;
            openBtn.target = '_blank';
            openBtn.textContent = 'فتح';
            actions.appendChild(openBtn);
        }
        var delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'btn-danger';
        delBtn.textContent = 'حذف';
        delBtn.addEventListener('click', function () {
            cusAttachmentDelete(att);
        });
        actions.appendChild(delBtn);
        row.appendChild(label);
        row.appendChild(actions);
        list.appendChild(row);
    });
    cusUpdateAttachmentsCount(JSON.stringify(rows));
}
function cusAttachmentLoadFromJson(jsonStr) {
    cusAttachmentSetRows(cusAttachmentsParse(jsonStr));
}
function cusAttachmentUpload() {
    var idEl = document.getElementById('cus_id');
    var cid = parseInt(String(idEl && idEl.value || '0'), 10) || 0;
    if (cid <= 0) {
        alert('احفظ العميل أولاً');
        return;
    }
    var inp = cusAttachmentInputs();
    if (!inp.file || !inp.file.files || !inp.file.files[0]) {
        alert('اختر ملفاً أولاً');
        return;
    }
    var fd = new FormData();
    fd.append('customer_id', String(cid));
    fd.append('file', inp.file.files[0]);
    if (inp.name && inp.name.value.trim() !== '') fd.append('name', inp.name.value.trim());
    fetch('/admin/api/customers/attachment-upload.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
    })
        .then(function (r) { return r.json(); })
        .then(function (r) {
            if (!r.success) { alert(r.message || 'فشل رفع المرفق'); return; }
            if (inp.file) inp.file.value = '';
            if (inp.name) inp.name.value = '';
            cusAttachmentSetRows(r.attachments || []);
        })
        .catch(function (e) { alert(e.message || String(e)); });
}
function cusAttachmentDelete(att) {
    var idEl = document.getElementById('cus_id');
    var cid = parseInt(String(idEl && idEl.value || '0'), 10) || 0;
    if (cid <= 0) return;
    if (!window.confirm('حذف المرفق «' + (att.name || att.file) + '»؟')) return;
    postJSON('/admin/api/customers/attachment-delete.php', {
        customer_id: cid,
        file: att.file || ''
    })
        .then(function (r) {
            if (!r.success) { alert(r.message || 'فشل حذف المرفق'); return; }
            cusAttachmentSetRows(r.attachments || []);
        })
        .catch(function (e) { alert(e.message || String(e)); });
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
    var salesReturnBtn = document.getElementById('cus_open_sales_return_btn');
    if (salesReturnBtn) {
        salesReturnBtn.addEventListener('click', function (e) { e.preventDefault(); cusOpenCurrentSalesReturn(); });
    }
    var receiptBtn = document.getElementById('cus_open_receipt_btn');
    if (receiptBtn) {
        receiptBtn.addEventListener('click', function (e) { e.preventDefault(); cusOpenCurrentReceipt(); });
    }
    var ordersBtn = document.getElementById('cus_open_orders_btn');
    if (ordersBtn) {
        ordersBtn.addEventListener('click', function (e) { e.preventDefault(); cusOpenCurrentOrders(); });
    }
    var printBtn = document.getElementById('cus_print_btn');
    if (printBtn) {
        printBtn.addEventListener('click', function (e) { e.preventDefault(); cusPrintCurrentCard(); });
    }
    var delBtn = document.getElementById('cus_delete_btn');
    if (delBtn) {
        delBtn.addEventListener('click', function (e) { e.preventDefault(); cusDeleteCurrent(); });
    }
    var codeEditBtn = document.getElementById('cus_code_edit_btn');
    if (codeEditBtn) {
        codeEditBtn.addEventListener('click', function (e) { e.preventDefault(); cusToggleCodeEdit(); });
    }
    var statusEl = document.getElementById('cus_status');
    if (statusEl) {
        statusEl.addEventListener('change', cusToggleBlockReason);
    }
    var attManageBtn = document.getElementById('cus_attachments_manage_btn');
    if (attManageBtn) {
        attManageBtn.addEventListener('click', function (e) { e.preventDefault(); cusAttachmentModalOpen(); });
    }
    var attBackdrop = document.getElementById('cus_attachments_backdrop');
    if (attBackdrop) {
        attBackdrop.addEventListener('click', cusAttachmentModalClose);
    }
    var attClose = document.getElementById('cus_attachments_close');
    if (attClose) {
        attClose.addEventListener('click', cusAttachmentModalClose);
    }
    var attUploadBtn = document.getElementById('cus_attachment_upload_btn');
    if (attUploadBtn) {
        attUploadBtn.addEventListener('click', function (e) { e.preventDefault(); cusAttachmentUpload(); });
    }
    var attFile = document.getElementById('cus_attachment_file');
    var attName = document.getElementById('cus_attachment_name');
    if (attFile) {
        attFile.addEventListener('change', function () {
            var f = attFile.files && attFile.files[0] ? attFile.files[0] : null;
            if (!f || !attName) return;
            var current = String(attName.value || '').trim();
            if (current !== '') return;
            var raw = String(f.name || '');
            var dot = raw.lastIndexOf('.');
            attName.value = dot > 0 ? raw.slice(0, dot) : raw;
        });
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
        if (searchModal && !searchModal.hidden) { cusSearchModalClose(); return; }
        var attModal = document.getElementById('cus_attachments_modal');
        if (attModal && !attModal.hidden) { cusAttachmentModalClose(); }
    });

    // دبل كليك على خانة الكود يفتح modal البحث (للسرعة) — فقط إذا الحقل readonly.
    var codeEl = document.getElementById('cus_code');
    if (codeEl) {
        codeEl.addEventListener('dblclick', function (e) {
            if (!codeEl.readOnly) return; // إذا في وضع التعديل، لا نفتح البحث.
            e.preventDefault();
            cusSearchModalOpen();
        });
    }

    var countryEl = cusPhoneCountryEl();
    var countryListEl = document.getElementById('cus_phone_country_list');
    if (countryEl && countryListEl && window.orangeAdminPhoneCountry) {
        window.orangeAdminPhoneCountry.bindInput(countryEl, countryListEl, true);
        window.orangeAdminPhoneCountry.populateDatalist(countryEl, countryListEl, '', true);
        if (String(countryEl.value || '').trim() === '') {
            window.orangeAdminPhoneCountry.setInputByDial(
                countryEl,
                window.orangeAdminPhoneCountry.defaultCountryDial(),
                true
            );
        }
    }

    cusToggleBlockReason();
    cusNavRefreshButtons();
})();
</script>
