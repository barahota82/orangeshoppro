<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/date_format.php';

require_admin();

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

$companyName = '';
if (orange_table_exists($pdo, 'company_settings')) {
    $cs = $pdo->query('SELECT company_name_ar FROM company_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($cs)) {
        $companyName = trim((string) ($cs['company_name_ar'] ?? ''));
    }
}

$balance = orange_party_balance_supplier($pdo, $supplierId);
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
body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; margin: 24px; color: #0f172a; }
h1 { font-size: 1.4rem; margin: 0 0 6px; }
h2 { font-size: 1rem; margin: 16px 0 6px; }
.muted { color: #64748b; font-size: 0.85rem; }
.card { border: 1px solid #cbd5e1; border-radius: 10px; padding: 16px; margin-bottom: 12px; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 14px; }
.row .k { color: #475569; font-size: 0.85rem; }
.row .v { font-weight: 600; font-size: 0.95rem; }
.row.full { grid-column: 1 / -1; }
.ltr { direction: ltr; }
.actions { margin-top: 14px; }
@media print {
    body { margin: 12mm; }
    .actions { display: none; }
}
</style>
</head>
<body>
<header style="margin-bottom: 12px;">
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
        <div class="v ltr"><?php echo htmlspecialchars((string) ($row['currency_code'] ?? 'KWD'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">المعاملة الضريبية</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['tax_profile'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">طريقة السداد</div>
        <div class="v"><?php echo htmlspecialchars((string) ($row['payment_mode'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="k">أيام السداد</div>
        <div class="v ltr"><?php echo isset($row['payment_terms_days']) && $row['payment_terms_days'] !== null ? (int) $row['payment_terms_days'] : '—'; ?></div>
        <div class="k">حد الائتمان</div>
        <div class="v ltr"><?php echo isset($row['credit_limit']) && $row['credit_limit'] !== null && (float) $row['credit_limit'] > 0 ? number_format((float) $row['credit_limit'], 3) . ' KD' : '—'; ?></div>
        <div class="k">الرصيد المستحق للمورد</div>
        <div class="v ltr"><?php echo number_format((float) $balance, 3) . ' KD'; ?></div>
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
    <pre style="white-space:pre-wrap;font-family:inherit;margin:0;font-size:0.9rem;"><?php echo htmlspecialchars((string) $row['notes'], ENT_QUOTES, 'UTF-8'); ?></pre>
</div>
<?php endif; ?>

<div class="actions">
    <button type="button" onclick="window.print()">طباعة</button>
    <button type="button" onclick="window.close()">إغلاق</button>
</div>
</body>
</html>
