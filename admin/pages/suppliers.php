<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$rows = [];
$totalBalance = 0.0;
if (orange_table_exists($pdo, 'suppliers')) {
    $sql = 'SELECT s.*, (SELECT COUNT(*) FROM purchases p WHERE p.supplier_id = s.id) AS purchase_cnt FROM suppliers s ORDER BY s.name ASC, s.id ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $totalBalance += orange_party_balance_supplier($pdo, (int) $r['id']);
    }
}
$count = count($rows);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>الموردين</h1>
        <p class="page-subtitle">
            إدارة موردي المشتريات: <strong>كود المورد</strong> (فريد اختياري)، الذمم الدائنة، وعدد مستندات الشراء.
            مستقبلاً: ربط <strong>مردود المشتريات</strong> بجدول <code dir="ltr">purchase_returns</code> (المورد + مستند الشراء الأصلي).
            المشتريات من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchases'), ENT_QUOTES, 'UTF-8'); ?>">المشتريات</a>؛ السداد والكشوف من
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_supplier_payment'), ENT_QUOTES, 'UTF-8'); ?>">سند صرف / مورد</a>
            أو <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_ledger'), ENT_QUOTES, 'UTF-8'); ?>">ذمم العملاء والموردين</a>.
        </p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchases'), ENT_QUOTES, 'UTF-8'); ?>">مستند شراء</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_supplier_payment'), ENT_QUOTES, 'UTF-8'); ?>">سند صرف / مورد</a>
    </div>
</div>

<div class="party-registry-stats">
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">عدد الموردين</span>
        <span class="party-registry-stat__val"><?php echo (int) $count; ?></span>
    </div>
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">مجموع ذمم الموردين</span>
        <span class="party-registry-stat__val" dir="ltr"><?php echo number_format($totalBalance, 3); ?></span>
        <span class="party-registry-stat__unit">KD</span>
    </div>
</div>

<div class="card">
    <h3>مورد جديد أو تعديل</h3>
    <p class="card-hint" style="margin-top:0;">الاسم إلزامي. <strong>كود المورد</strong> اختياري وفريد. الهاتف اختياري؛ إن وُجد يجب ألا يتكرر مع مورد آخر.</p>
    <input type="hidden" id="sup_id" value="0">
    <div class="form-grid">
        <div>
            <label for="sup_code">كود المورد (اختياري)</label>
            <input type="text" id="sup_code" maxlength="32" autocomplete="off" dir="ltr" lang="en" placeholder="مثال: V-2001">
        </div>
        <div>
            <label for="sup_name">اسم المورد</label>
            <input type="text" id="sup_name" autocomplete="off" placeholder="اسم المورد أو الشركة">
        </div>
        <div>
            <label for="sup_phone">الهاتف (اختياري)</label>
            <input type="text" id="sup_phone" autocomplete="off" dir="ltr" lang="en" placeholder="مثال: 5xxxxxxxx">
        </div>
        <div style="grid-column:1/-1;">
            <label for="sup_notes">ملاحظات</label>
            <input type="text" id="sup_notes" autocomplete="off" placeholder="اختياري">
        </div>
    </div>
    <div class="actions" style="margin-top:12px;">
        <button type="button" onclick="supSave()">حفظ</button>
        <button type="button" class="btn-secondary" onclick="supResetForm()">تفريغ النموذج</button>
    </div>
</div>

<div class="card">
    <h3>سجل الموردين</h3>
    <div class="party-registry-toolbar">
        <div class="party-registry-search-wrap">
            <label for="sup_filter" class="party-registry-search-label">بحث</label>
            <input type="search" id="sup_filter" class="party-registry-search" placeholder="اسم، هاتف، ملاحظات…" autocomplete="off" lang="ar" dir="rtl" oninput="supFilterRows()">
        </div>
    </div>
    <?php if ($rows === []): ?>
        <p class="card-hint">لا يوجد موردون بعد — أضف مورداً من النموذج أعلاه ليظهر في قوائم المشتريات.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table party-registry-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>الاسم</th>
                        <th>الهاتف</th>
                        <th>ذمة المورد</th>
                        <th>مشتريات</th>
                        <th class="party-registry-col-actions">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="sup_tbody">
                    <?php foreach ($rows as $s): ?>
                        <?php
                        $sid = (int) $s['id'];
                        $bal = orange_party_balance_supplier($pdo, $sid);
                        $phone = (string) ($s['phone'] ?? '');
                        $codeDisp = isset($s['code']) && (string) $s['code'] !== '' ? (string) $s['code'] : '—';
                        $hayRaw = trim((string) ($s['code'] ?? '') . ' ' . ($s['name'] ?? '') . ' ' . $phone . ' ' . ($s['notes'] ?? ''));
                        $hay = function_exists('mb_strtolower') ? mb_strtolower($hayRaw, 'UTF-8') : strtolower($hayRaw);
                        ?>
                        <tr data-sup-search="<?php echo htmlspecialchars($hay, ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo $sid; ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($codeDisp, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo $phone !== '' ? htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <td dir="ltr"><?php echo number_format($bal, 3); ?></td>
                            <td><?php echo (int) ($s['purchase_cnt'] ?? 0); ?></td>
                            <td class="party-registry-actions">
                                <button type="button" class="btn-secondary party-registry-btn" onclick='supEdit(<?php echo json_encode([
                                    'id' => $sid,
                                    'code' => (string) ($s['code'] ?? ''),
                                    'name' => (string) ($s['name'] ?? ''),
                                    'phone' => $phone,
                                    'notes' => (string) ($s['notes'] ?? ''),
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>تعديل</button>
                                <a class="btn btn-secondary party-registry-btn" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_supplier_payment&stmt_party_kind=supplier&stmt_party_id=' . (int) $sid . '#partner-account-statement'), ENT_QUOTES, 'UTF-8'); ?>">كشف حساب</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
function supResetForm() {
    document.getElementById('sup_id').value = '0';
    document.getElementById('sup_code').value = '';
    document.getElementById('sup_name').value = '';
    document.getElementById('sup_phone').value = '';
    document.getElementById('sup_notes').value = '';
}
function supEdit(row) {
    document.getElementById('sup_id').value = String(row.id || 0);
    document.getElementById('sup_code').value = row.code || '';
    document.getElementById('sup_name').value = row.name || '';
    document.getElementById('sup_phone').value = row.phone || '';
    document.getElementById('sup_notes').value = row.notes || '';
    document.getElementById('sup_name').closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function supSave() {
    var id = parseInt(document.getElementById('sup_id').value, 10) || 0;
    var name = document.getElementById('sup_name').value.trim();
    var phone = document.getElementById('sup_phone').value.trim();
    var notes = document.getElementById('sup_notes').value.trim();
    if (!name) {
        alert('اسم المورد مطلوب');
        return;
    }
    var payload = {
        name: name,
        phone: phone || null,
        notes: notes || null,
        code: (document.getElementById('sup_code') && document.getElementById('sup_code').value.trim()) || null
    };
    if (id > 0) {
        payload.id = id;
    }
    postJSON('/admin/api/suppliers/save.php', payload)
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
function supFilterRows() {
    var q = (document.getElementById('sup_filter') && document.getElementById('sup_filter').value || '')
        .trim()
        .toLowerCase();
    document.querySelectorAll('#sup_tbody tr[data-sup-search]').forEach(function (tr) {
        var hay = (tr.getAttribute('data-sup-search') || '').toLowerCase();
        tr.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
    });
}
</script>
