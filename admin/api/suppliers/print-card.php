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

$supplierId = (int) ($_GET['supplier_id'] ?? 0);
if ($supplierId <= 0) {
    http_response_code(400);
    exit('Supplier not specified');
}
if (!orange_table_exists($pdo, 'suppliers')) {
    http_response_code(500);
    exit('Suppliers table missing');
}

$st = $pdo->prepare('SELECT s.* FROM suppliers s WHERE s.id = ? LIMIT 1');
$st->execute([$supplierId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Supplier not found');
}
try {
    orange_admin_assert_entity_country($pdo, 'suppliers', $supplierId);
} catch (RuntimeException $e) {
    http_response_code(403);
    exit($e->getMessage());
}

$companyName = orange_company_settings_name_ar($pdo);

$balance = orange_party_balance_supplier($pdo, $supplierId);
$printCurrencyCode = orange_admin_context_currency_code($pdo);
if (orange_table_has_column($pdo, 'suppliers', 'country_id')) {
    $supCid = (int) ($row['country_id'] ?? 0);
    if ($supCid > 0) {
        $printCurrencyCode = orange_country_functional_currency_code($pdo, $supCid);
    }
}
$printCurrencyUnit = orange_currency_display_unit($printCurrencyCode);
$printCurrencyDecimals = orange_currency_decimals_for_code($printCurrencyCode);
$payableAccountLabel = '—';
$payableId = isset($row['payable_account_id']) ? (int) $row['payable_account_id'] : 0;
if ($payableId > 0 && orange_table_exists($pdo, 'accounts')) {
    $accSt = $pdo->prepare('SELECT code, name FROM accounts WHERE id = ? LIMIT 1');
    $accSt->execute([$payableId]);
    $accRow = $accSt->fetch(PDO::FETCH_ASSOC);
    if ($accRow) {
        $payableAccountLabel = trim((string) ($accRow['code'] ?? '')) !== ''
            ? trim((string) $accRow['code']) . ' — ' . trim((string) ($accRow['name'] ?? ''))
            : trim((string) ($accRow['name'] ?? ''));
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

?><!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>بطاقة مورد #<?php echo (int) $supplierId; ?></title>
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
    <h1>بطاقة مورد #<?php echo (int) $supplierId; ?></h1>
    <p class="muted">تاريخ الطباعة: <span class="ltr"><?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?></span></p>
</header>

<div class="card">
    <h2>بيانات أساسية</h2>
    <div class="row">
        <div class="k">كود المورد</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['code'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">الاسم</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">الهاتف</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['phone'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">البريد</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['email'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">مسؤول التواصل</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['contact_person'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">الحالة</div>
        <div class="v"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php if ($statusRaw === 'blocked' && trim((string) ($row['block_reason'] ?? '')) !== ''): ?>
        <div class="k">سبب الحظر</div>
        <div class="v"><?php echo htmlspecialchars((string) $row['block_reason'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <div class="k">المنطقة</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['city_area'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">العنوان</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['address_line'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">العملة</div>
        <div class="v ltr"><?php echo htmlspecialchars($printCurrencyCode, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">المعاملة الضريبية</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['tax_profile'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">طريقة السداد</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['payment_mode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">أيام السداد</div>
        <div class="v ltr"><?php echo isset($row['payment_terms_days']) && $row['payment_terms_days'] !== null ? (int) $row['payment_terms_days'] : '—'; ?></div>
        <div class="k">حد الائتمان</div>
        <div class="v ltr"><?php echo isset($row['credit_limit']) && $row['credit_limit'] !== null && (float) $row['credit_limit'] > 0 ? number_format((float) $row['credit_limit'], $printCurrencyDecimals) . ' ' . htmlspecialchars($printCurrencyUnit, ENT_QUOTES, 'UTF-8') : '—'; ?></div>
        <div class="k">الرصيد المستحق للمورد</div>
        <div class="v ltr"><?php echo number_format((float) $balance, $printCurrencyDecimals) . ' ' . htmlspecialchars($printCurrencyUnit, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">حساب الذمة</div>
        <div class="v"><?php echo htmlspecialchars($payableAccountLabel, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
</div>

<?php if (trim((string) ($row['bank_name'] ?? '')) !== '' || trim((string) ($row['bank_iban'] ?? '')) !== ''): ?>
<div class="card">
    <h2>الحساب البنكي</h2>
    <div class="row">
        <div class="k">اسم البنك</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['bank_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">IBAN</div>
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['bank_iban'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">صاحب الحساب</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['bank_account_holder'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
</div>
<?php endif; ?>

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
