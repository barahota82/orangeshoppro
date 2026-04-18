<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$years = orange_fiscal_years_list($pdo);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>السنوات المالية</h1>
        <p class="page-subtitle">
            حدّد فترة كل سنة (من — إلى). <strong>إغلاق السنة</strong> يمنع أي قيد جديد أو تعديل أو حذف على تواريخ هذه الفترة.
            عند الإغلاق يمكن تشغيل <strong>قيود الإقفال المحاسبي</strong> (إيرادات/مصروفات → ملخص الدخل → أرباح محتجزة) بعد ضبط الحسابين في «حسابات القيود التلقائية».
            لبدء سنة جديدة أضف سنة بتواريخ غير متداخلة، ثم سجّل <a href="/admin/index.php?page=opening_balances">أرصدة أول المدة</a> إن لزم.
        </p>
    </div>
</div>

<div class="card">
    <h3 class="card-title">إضافة سنة مالية</h3>
    <div class="form-grid">
        <div>
            <label for="fy_label">الاسم (يظهر في التقارير)</label>
            <input type="text" id="fy_label" placeholder="مثال: سنة 2027">
        </div>
        <div>
            <label for="fy_start">من تاريخ</label>
            <input type="date" id="fy_start">
        </div>
        <div>
            <label for="fy_end">إلى تاريخ</label>
            <input type="date" id="fy_end">
        </div>
    </div>
    <div class="actions" style="margin-top:14px;">
        <button type="button" onclick="fyCreate()">حفظ السنة</button>
    </div>
</div>

<div class="card">
    <h3 class="card-title">السنوات المعرفة</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الاسم</th>
                    <th>من</th>
                    <th>إلى</th>
                    <th>الحالة</th>
                    <th>إغلاق في</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($years as $y): ?>
                    <?php
                    $closed = (int)($y['is_closed'] ?? 0) === 1;
                    $id = (int)$y['id'];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($y['label_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($y['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($y['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $closed ? '<span class="badge cancelled">مغلقة</span>' : '<span class="badge approved">مفتوحة</span>'; ?></td>
                        <td><?php echo $y['closed_at'] ? htmlspecialchars((string)$y['closed_at'], ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        <td>
                            <?php if (!$closed): ?>
                                <button type="button" class="btn-secondary" onclick="fyClose(<?php echo $id; ?>)">إغلاق السنة</button>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($years === []): ?>
        <p class="page-subtitle">لا توجد سنوات بعد — يُنشئ النظام سنة للسنة الحالية تلقائياً عند أول طلب بعد الترقية.</p>
    <?php endif; ?>
</div>

<script>
function fyCreate() {
    var label = document.getElementById('fy_label').value.trim();
    var start = document.getElementById('fy_start').value;
    var end = document.getElementById('fy_end').value;
    if (!label || !start || !end) {
        alert('أكمل الاسم وتاريخ البداية والنهاية');
        return;
    }
    postJSON('/admin/api/fiscal_years/save.php', { action: 'create', label_ar: label, start_date: start, end_date: end })
        .then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) location.reload();
        })
        .catch(function (e) { alert(e.message || String(e)); });
}
function fyClose(id) {
    if (!confirm('إغلاق السنة؟ لن يُسمح بقيود جديدة على تواريخ هذه الفترة.')) return;
    var accountingClose = confirm('تشغيل قيود الإقفال المحاسبي (إيرادات/مصروفات → ملخص الدخل → الأرباح المحتجزة)؟\n\nموافق = نعم (يتطلب ربط income_summary و retained_earnings في «حسابات القيود التلقائية»)\nإلغاء = إغلاق إداري فقط بدون قيود إقفال');
    postJSON('/admin/api/fiscal_years/save.php', { action: 'close', id: id, accounting_close: accountingClose })
        .then(function (r) {
            alert(r.message || (r.success ? 'تم' : 'فشل'));
            if (r.success) location.reload();
        })
        .catch(function (e) { alert(e.message || String(e)); });
}
</script>
