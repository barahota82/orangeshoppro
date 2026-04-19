<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$rows = [];
$totalBalance = 0.0;
if (orange_table_exists($pdo, 'customers')) {
    $hasOrdersLink = orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'customer_id');
    $sql = 'SELECT c.*';
    if ($hasOrdersLink) {
        $sql .= ', (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_cnt';
    } else {
        $sql .= ', 0 AS order_cnt';
    }
    $sql .= ' FROM customers c ORDER BY c.id DESC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $totalBalance += orange_party_balance_customer($pdo, (int) $r['id']);
    }
}
$count = count($rows);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>العملاء</h1>
        <p class="page-subtitle">
            سجل موحّد لكل العملاء: <strong>كود العميل</strong> (فريد اختياري)، البيانات، حد الائتمان، رصيد الذمة، وعدد الطلبات.
            مستقبلاً: ربط <strong>مردود المبيعات</strong> بجدول <code dir="ltr">sales_returns</code> (العميل + الطلب الأصلي).
            الذمم والقبض من <a href="/admin/index.php?page=partner_ledger">ذمم العملاء والموردين</a>.
        </p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary" href="/admin/index.php?page=partner_ledger">الذمم وسندات القبض</a>
        <a class="btn btn-secondary" href="/admin/index.php?page=manual_order">فاتورة شركة</a>
    </div>
</div>

<div class="party-registry-stats">
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">عدد العملاء</span>
        <span class="party-registry-stat__val"><?php echo (int) $count; ?></span>
    </div>
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">مجموع أرصدة الذمم (مدين)</span>
        <span class="party-registry-stat__val" dir="ltr"><?php echo number_format($totalBalance, 3); ?></span>
        <span class="party-registry-stat__unit">KD</span>
    </div>
</div>

<div class="card">
    <h3>عميل جديد أو تعديل</h3>
    <p class="card-hint" style="margin-top:0;">الهاتف معرّف فريد للربط مع الطلبات. <strong>كود العميل</strong> اختياري ويُستخدم في التقارير والربط مع مردود المبيعات. اضغط «تعديل» من الجدول لتحميل عميل قائم.</p>
    <input type="hidden" id="cust_id" value="0">
    <div class="form-grid">
        <div>
            <label for="cust_code">كود العميل (اختياري)</label>
            <input type="text" id="cust_code" maxlength="32" autocomplete="off" dir="ltr" lang="en" placeholder="مثال: C-1001">
        </div>
        <div>
            <label for="cust_name">الاسم</label>
            <input type="text" id="cust_name" autocomplete="off" placeholder="اسم العميل">
        </div>
        <div>
            <label for="cust_phone">الهاتف</label>
            <input type="text" id="cust_phone" autocomplete="off" dir="ltr" lang="en" placeholder="مثال: 5xxxxxxxx">
        </div>
        <div>
            <label for="cust_limit">حد ائتمان (اختياري)</label>
            <input type="number" id="cust_limit" class="admin-inp-money" step="any" min="0" value="" placeholder="فارغ = بلا حد" inputmode="decimal" lang="en" dir="ltr">
        </div>
        <div style="grid-column:1/-1;">
            <label for="cust_notes">ملاحظات</label>
            <input type="text" id="cust_notes" autocomplete="off" placeholder="اختياري">
        </div>
    </div>
    <div class="actions" style="margin-top:12px;">
        <button type="button" onclick="custSave()">حفظ</button>
        <button type="button" class="btn-secondary" onclick="custResetForm()">تفريغ النموذج</button>
    </div>
</div>

<div class="card">
    <h3>سجل العملاء</h3>
    <div class="party-registry-toolbar">
        <div class="party-registry-search-wrap">
            <label for="cust_filter" class="party-registry-search-label">بحث</label>
            <input type="search" id="cust_filter" class="party-registry-search" placeholder="اسم، هاتف، ملاحظات…" autocomplete="off" lang="ar" dir="rtl" oninput="custFilterRows()">
        </div>
    </div>
    <?php if ($rows === []): ?>
        <p class="card-hint">لا يوجد عملاء بعد — أضف أول عميل من النموذج أعلاه.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table party-registry-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>الاسم</th>
                        <th>الهاتف</th>
                        <th>حد الائتمان</th>
                        <th>رصيد الذمة</th>
                        <th>طلبات</th>
                        <th class="party-registry-col-actions">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="cust_tbody">
                    <?php foreach ($rows as $c): ?>
                        <?php
                        $cid = (int) $c['id'];
                        $bal = orange_party_balance_customer($pdo, $cid);
                        $lim = isset($c['credit_limit']) && $c['credit_limit'] !== null && (float) $c['credit_limit'] > 0
                            ? number_format((float) $c['credit_limit'], 3) : '—';
                        $hayRaw = trim((string) ($c['name_ar'] ?? '') . ' ' . ($c['phone'] ?? '') . ' ' . ($c['notes'] ?? ''));
                        $hay = function_exists('mb_strtolower') ? mb_strtolower($hayRaw, 'UTF-8') : strtolower($hayRaw);
                        ?>
                        <tr data-cust-search="<?php echo htmlspecialchars($hay, ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo $cid; ?></td>
                            <td><?php echo htmlspecialchars((string) ($c['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars((string) ($c['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($lim, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo number_format($bal, 3); ?></td>
                            <td><?php echo (int) ($c['order_cnt'] ?? 0); ?></td>
                            <td class="party-registry-actions">
                                <button type="button" class="btn-secondary party-registry-btn" onclick='custEdit(<?php echo json_encode([
                                    'id' => $cid,
                                    'code' => (string) ($c['code'] ?? ''),
                                    'name_ar' => (string) ($c['name_ar'] ?? ''),
                                    'phone' => (string) ($c['phone'] ?? ''),
                                    'credit_limit' => $c['credit_limit'] ?? null,
                                    'notes' => (string) ($c['notes'] ?? ''),
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>تعديل</button>
                                <a class="btn btn-secondary party-registry-btn" href="/admin/index.php?page=partner_ledger&amp;stmt_party_kind=customer&amp;stmt_party_id=<?php echo $cid; ?>#partner-account-statement">كشف حساب</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function custResetForm() {
    document.getElementById('cust_id').value = '0';
    document.getElementById('cust_code').value = '';
    document.getElementById('cust_name').value = '';
    document.getElementById('cust_phone').value = '';
    document.getElementById('cust_limit').value = '';
    document.getElementById('cust_notes').value = '';
}
function custEdit(row) {
    document.getElementById('cust_id').value = String(row.id || 0);
    document.getElementById('cust_code').value = row.code || '';
    document.getElementById('cust_name').value = row.name_ar || '';
    document.getElementById('cust_phone').value = row.phone || '';
    var lim = row.credit_limit;
    document.getElementById('cust_limit').value =
        lim != null && lim !== '' && Number(lim) > 0 ? String(lim) : '';
    document.getElementById('cust_notes').value = row.notes || '';
    document.querySelector('.card input#cust_name').closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function custSave() {
    var id = parseInt(document.getElementById('cust_id').value, 10) || 0;
    var name = document.getElementById('cust_name').value.trim();
    var phone = document.getElementById('cust_phone').value.trim();
    var limRaw = document.getElementById('cust_limit').value.trim();
    var notes = document.getElementById('cust_notes').value.trim();
    if (!phone) {
        alert('الهاتف مطلوب');
        return;
    }
    var payload = {
        name_ar: name || 'عميل',
        phone: phone,
        notes: notes || null,
        code: (document.getElementById('cust_code') && document.getElementById('cust_code').value.trim()) || null
    };
    if (id > 0) {
        payload.id = id;
    }
    if (limRaw === '') {
        payload.credit_limit = null;
    } else {
        var lim = parseFloat(limRaw);
        if (isNaN(lim) || lim < 0) {
            alert('حد ائتمان غير صالح');
            return;
        }
        payload.credit_limit = lim <= 0 ? null : lim;
    }
    postJSON('/admin/api/customers/save.php', payload)
        .then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) {
                location.reload();
            }
        })
        .catch(function (e) {
            alert(e.message || String(e));
        });
}
function custFilterRows() {
    var q = (document.getElementById('cust_filter') && document.getElementById('cust_filter').value || '')
        .trim()
        .toLowerCase();
    document.querySelectorAll('#cust_tbody tr[data-cust-search]').forEach(function (tr) {
        var hay = (tr.getAttribute('data-cust-search') || '').toLowerCase();
        tr.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
    });
}
</script>
