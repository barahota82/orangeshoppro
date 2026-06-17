<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_once __DIR__ . '/../../../includes/company_settings.php';

require_admin_page();

$pdo = db();
orange_catalog_ensure_schema($pdo);

$customerId = (int) ($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(400);
    exit('Customer not specified');
}
if (!orange_table_exists($pdo, 'customers')) {
    http_response_code(500);
    exit('Customers table missing');
}

$row = null;
$st = $pdo->prepare('SELECT c.* FROM customers c WHERE c.id = ? LIMIT 1');
$st->execute([$customerId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Customer not found');
}
try {
    orange_admin_assert_entity_country($pdo, 'customers', $customerId);
} catch (RuntimeException $e) {
    http_response_code(403);
    exit($e->getMessage());
}

$companyName = orange_company_settings_name_ar($pdo);

$balance = orange_party_balance_customer($pdo, $customerId);
$custMoney = orange_admin_currency_context($pdo);
$daName = '';
if (orange_table_has_column($pdo, 'customers', 'delivery_area_id') && (int) ($row['delivery_area_id'] ?? 0) > 0) {
    $daSt = $pdo->prepare('SELECT name_ar, name_en FROM delivery_areas WHERE id = ? LIMIT 1');
    $daSt->execute([(int) $row['delivery_area_id']]);
    $daRow = $daSt->fetch(PDO::FETCH_ASSOC);
    if ($daRow) {
        $daName = trim((string) ($daRow['name_ar'] ?? '')) ?: trim((string) ($daRow['name_en'] ?? ''));
    }
}
if ($daName === '') {
    $daName = trim((string) ($row['area'] ?? ''));
}

$ordersCount = 0;
$ordersLastAt = '';
if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'customer_id')) {
    $ordersCountrySql = orange_sql_country_and_fragment($pdo, 'orders', '', orange_admin_context_country_id($pdo));
    $oSt = $pdo->prepare('SELECT COUNT(*) AS cnt, MAX(created_at) AS last_at FROM orders WHERE customer_id = ?' . $ordersCountrySql);
    $oSt->execute([$customerId]);
    $oRow = $oSt->fetch(PDO::FETCH_ASSOC);
    if ($oRow) {
        $ordersCount = (int) ($oRow['cnt'] ?? 0);
        $ordersLastAt = (string) ($oRow['last_at'] ?? '');
    }
}

$sfAcc = null;
if (orange_table_exists($pdo, 'storefront_accounts') && orange_table_has_column($pdo, 'storefront_accounts', 'customer_id')) {
    $sfSt = $pdo->prepare('SELECT id, email, email_verified_at, registered_channel_slug FROM storefront_accounts WHERE customer_id = ? LIMIT 1');
    $sfSt->execute([$customerId]);
    $sfRow = $sfSt->fetch(PDO::FETCH_ASSOC);
    if ($sfRow) {
        $sfAcc = $sfRow;
    }
}

$statusLabel = '—';
$statusRaw = strtolower(trim((string) ($row['status'] ?? 'active')));
if ($statusRaw === 'inactive') {
    $statusLabel = 'غير نشط';
} elseif ($statusRaw === 'blocked') {
    $statusLabel = 'محظور مؤقتاً';
} else {
    $statusLabel = 'نشط';
}

$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$todayDmY = orange_format_date_dmY(date('Y-m-d'));

?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>بطاقة عميل #<?php echo (int) $customerId; ?></title>
<style>
* { box-sizing: border-box; }
body {
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
    margin: 0;
    padding: 20px;
    color: #0f172a;
    background: #f4f7fb;
}
.print-wrap {
    max-width: 980px;
    margin: 0 auto;
}
.print-head {
    margin-bottom: 12px;
    padding: 14px 16px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    background: #ffffff;
}
h1 { font-size: 1.35rem; margin: 0 0 4px; }
h2 { font-size: 1rem; margin: 0 0 10px; }
.muted { color: #64748b; font-size: 0.86rem; }
.card {
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 12px;
    background: #ffffff;
    page-break-inside: avoid;
    break-inside: avoid-page;
}
.row {
    display: grid;
    grid-template-columns: minmax(160px, 0.42fr) minmax(0, 1fr);
    gap: 8px 12px;
    align-items: start;
}
.row .k {
    color: #334155;
    font-size: 0.86rem;
    font-weight: 600;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 10px;
    min-height: 40px;
    display: flex;
    align-items: center;
}
.row .v {
    font-weight: 600;
    font-size: 0.95rem;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 10px;
    min-height: 40px;
    word-break: break-word;
}
.ltr {
    direction: ltr;
    unicode-bidi: plaintext;
}
.actions {
    margin-top: 16px;
    display: flex;
    gap: 8px;
    justify-content: flex-start;
}
.actions button {
    border: 1px solid #334155;
    background: #334155;
    color: #ffffff;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
}
.actions button:last-child {
    background: #ffffff;
    color: #334155;
}
pre {
    white-space: pre-wrap;
    font-family: inherit;
    margin: 0;
    font-size: 0.92rem;
    line-height: 1.5;
}
@media (max-width: 760px) {
    body { padding: 12px; }
    .row { grid-template-columns: 1fr; }
    .row .k, .row .v { min-height: auto; }
    .actions button { flex: 1 1 auto; }
}
@media print {
    body {
        margin: 0;
        padding: 0;
        background: #ffffff;
    }
    .print-wrap {
        max-width: none;
        margin: 0;
    }
    .print-head,
    .card {
        border-color: #9ca3af;
        box-shadow: none;
    }
    .actions { display: none; }
}
</style>
</head>
<body>
<div class="print-wrap">
<header class="print-head">
    <?php if ($companyName !== ''): ?>
    <p class="muted"><?php echo htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <h1>بطاقة عميل #<?php echo (int) $customerId; ?></h1>
    <p class="muted">تاريخ الطباعة: <span class="ltr"><?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?></span></p>
</header>

<div class="card">
    <h2>بيانات أساسية</h2>
    <div class="row">
        <div class="k">كود العميل</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['code'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">الاسم</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['name_ar'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">الهاتف</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">البريد</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">الرقم المدني</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['civil_id'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">الحالة</div>
        <div class="v"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if ($statusRaw === 'blocked' && trim((string) ($row['block_reason'] ?? '')) !== ''): ?>
        <div class="k">سبب الحظر</div>
        <div class="v"><?php echo htmlspecialchars((string) $row['block_reason'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="k">المنطقة</div>
        <div class="v"><?php echo htmlspecialchars($daName !== '' ? $daName : '—', ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">العنوان</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['address'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">حد الائتمان</div>
        <div class="v ltr"><?php echo isset($row['credit_limit']) && $row['credit_limit'] !== null && (float) $row['credit_limit'] > 0 ? orange_format_money_for_context($custMoney, (float) $row['credit_limit']) : '—'; ?></div>
        <div class="k">رصيد الذمة (مدين)</div>
        <div class="v ltr"><?php echo orange_format_money_for_context($custMoney, (float) $balance); ?></div>
    </div>
</div>

<div class="card">
    <h2>الإحصاءات</h2>
    <div class="row">
        <div class="k">عدد الطلبات</div>
        <div class="v ltr"><?php echo (int) $ordersCount; ?></div>
        <div class="k">آخر طلب</div>
        <div class="v ltr"><?php echo $ordersLastAt !== '' ? htmlspecialchars(orange_format_datetime_dmY_hi((string) $ordersLastAt), ENT_QUOTES, 'UTF-8') : '—'; ?></div>
        <?php if ($sfAcc): ?>
        <div class="k">حساب الواجهة</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($sfAcc['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">حالة البريد</div>
        <div class="v"><?php echo !empty($sfAcc['email_verified_at']) ? 'مفعّل' : 'بانتظار التفعيل'; ?></div>
        <div class="k">القناة</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($sfAcc['registered_channel_slug'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </div>
</div>

<?php if (trim((string) ($row['notes'] ?? '')) !== ''): ?>
<div class="card">
    <h2>ملاحظات</h2>
    <pre><?php echo htmlspecialchars((string) $row['notes'], ENT_QUOTES, 'UTF-8'); ?></pre>
</div>
<?php endif; ?>

<div class="actions">
    <button type="button" onclick="window.print()">طباعة</button>
    <button type="button" onclick="window.close()">إغلاق</button>
</div>
</div>
</body>
</html>
