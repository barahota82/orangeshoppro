<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$suppliers = orange_table_exists($pdo, 'suppliers')
    ? $pdo->query('SELECT id, name, phone FROM suppliers ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$customers = orange_table_exists($pdo, 'customers')
    ? $pdo->query('SELECT id, name_ar, phone FROM customers ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC)
    : [];

$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}
$custBal = [];
foreach ($customers as $c) {
    $custBal[(int) $c['id']] = orange_party_balance_customer($pdo, (int) $c['id']);
}

$recent = [];
if (orange_party_subledger_ready($pdo)) {
    $recent = $pdo->query(
        'SELECT ps.*, jv.voucher_date, jv.reference, jv.entry_type
         FROM party_subledger ps
         INNER JOIN journal_vouchers jv ON jv.id = ps.voucher_id
         ORDER BY ps.id DESC
         LIMIT 40'
    )->fetchAll(PDO::FETCH_ASSOC);
}

$stmtUrl = storefront_public_path('/admin/index.php?page=partner_account_statement');
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>ذمم العملاء والموردين</h1>
        <p class="page-subtitle">
            تُسجَّل الذمم تلقائياً عند <strong>تسليم طلب آجل</strong> (إن وُجد هاتف للعميل) وعند <strong>شراء آجل</strong> مع اختيار مورد.
            سندات القبض والصرف في شاشتين منفصلتين:
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_customer_receipt'), ENT_QUOTES, 'UTF-8'); ?>"><strong>سداد فواتير مبيعات آجلة</strong></a>
            —
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_supplier_payment'), ENT_QUOTES, 'UTF-8'); ?>"><strong>سداد فواتير مشتريات آجلة</strong></a>.
            <a href="<?php echo htmlspecialchars($stmtUrl, ENT_QUOTES, 'UTF-8'); ?>"><strong>كشف حساب طرف</strong></a>
            —
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير الذمم المالية ومطابقة الدليل</a>
        </p>
    </div>
</div>

<div class="card">
    <h3 class="card-title">أرصدة العملاء (ذمم مدينة)</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>الرصيد (عليه لنا)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $c): ?>
                    <tr>
                        <td><?php echo (int) $c['id']; ?></td>
                        <td><?php echo htmlspecialchars((string) $c['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $c['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($custBal[(int) $c['id']] ?? 0, 3); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">أرصدة الموردين (ذمم دائنة)</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>الذمة (لنا له)</th></tr>
            </thead>
            <tbody>
                <?php foreach ($suppliers as $s): ?>
                    <tr>
                        <td><?php echo (int) $s['id']; ?></td>
                        <td><?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($s['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($supBal[(int) $s['id']] ?? 0, 3); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="card-hint">إدارة الموردين من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=suppliers'), ENT_QUOTES, 'UTF-8'); ?>">شاشة الموردين</a> أو عند إنشاء <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchases'), ENT_QUOTES, 'UTF-8'); ?>">مستند شراء</a>.</p>
</div>

<div class="card">
    <h3 class="card-title">آخر حركات الذمم</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>النوع</th>
                    <th>طرف</th>
                    <th>مدين</th>
                    <th>دائن</th>
                    <th>مرجع سند</th>
                    <th>ملاحظة</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(orange_format_date_dmY((string) ($r['voucher_date'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td title="<?php echo htmlspecialchars((string) $r['entry_type'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(orange_gl_entry_type_label_ar((string) ($r['entry_type'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($r['party_kind'] . ' #' . $r['party_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((float) $r['debit'], 3); ?></td>
                        <td><?php echo number_format((float) $r['credit'], 3); ?></td>
                        <td><?php echo htmlspecialchars((string) ($r['reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($r['memo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
