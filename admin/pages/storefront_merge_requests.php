<?php

declare(strict_types=1);

$pdo = db();
orange_catalog_ensure_schema($pdo);
$rows = [];
$hasTable = orange_table_exists($pdo, 'storefront_phone_merge_requests');
if ($hasTable) {
    $q = $pdo->query(
        "SELECT r.*, a.email AS account_email
         FROM storefront_phone_merge_requests r
         INNER JOIN storefront_accounts a ON a.id = r.storefront_account_id
         WHERE r.consumed_at IS NULL AND r.expires_at > NOW()
         ORDER BY r.created_at DESC
         LIMIT 100"
    );
    $rows = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
}
?>
<div class="page-title">
    <h1>دمج هاتف التسجيل (س15)</h1>
</div>
<p class="page-subtitle" style="margin-top:0;">
    عندما يحاول عميل التسجيل ببريد جديد لكن هاتفه مرتبط بحساب <strong>مفعّل</strong> مسبقاً، يُنشأ طلب دمج.
    بعد استلام رسالة واتساب من <strong>نفس رقم العميل</strong> وتطابق النص مع الطلب، اضغط «تأكيد واتساب»؛
    ثم يكمل العميل من صفحة التسجيل بزر «تطبيق تحديث بيانات الملف».
    البريد المؤكَّد للدخول <strong>لا يتغيّر</strong> — يُحدَّث الاسم والمنطقة والعنوان فقط على الحساب القائم.
</p>

<?php if (!$hasTable): ?>
    <div class="alert-error">جدول <code>storefront_phone_merge_requests</code> غير جاهز — حدّث المخطط بزيارة الواجهة أو الأدمن.</div>
<?php elseif ($rows === []): ?>
    <div class="card"><p style="margin:0;">لا توجد طلبات دمج نشطة حالياً.</p></div>
<?php else: ?>
    <div class="table-wrap card">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الهاتف (مطبّع)</th>
                    <th>بريد الحساب الحالي</th>
                    <th>البريد المقترح</th>
                    <th>القناة</th>
                    <th>أنشئ في</th>
                    <th>واتساب</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $rid = (int) ($r['id'] ?? 0);
                    $waOk = !empty($r['wa_confirmed_at']);
                    ?>
                    <tr>
                        <td><?php echo $rid; ?></td>
                        <td dir="ltr"><?php echo htmlspecialchars((string) ($r['phone_normalized'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td dir="ltr"><?php echo htmlspecialchars((string) ($r['account_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td dir="ltr"><?php echo htmlspecialchars((string) ($r['proposed_email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($r['proposed_channel_slug'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo $waOk ? '<span style="color:#15803d;">مؤكَّد</span>' : '—'; ?></td>
                        <td>
                            <?php if (!$waOk): ?>
                                <button type="button" class="btn btn-secondary" onclick="orangeConfirmMergeWa(<?php echo $rid; ?>)">تأكيد واتساب</button>
                            <?php else: ?>
                                <span class="card-hint">بانتظار العميل</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <script>
    async function orangeConfirmMergeWa(id) {
        if (!id || !confirm('تأكيد أن رسالة واتساب وصلت من نفس رقم الهاتف وتطابق الطلب؟')) return;
        var res = await postJSON('/admin/api/storefront/merge_request_confirm_wa.php', { id: id });
        alert(res.message || (res.success ? 'تم' : 'فشل'));
        if (res.success) location.reload();
    }
    </script>
<?php endif; ?>
