<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
$pdo = db();
orange_catalog_ensure_schema($pdo);
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/countries.php';

$rows = [];
$hasTable = orange_table_exists($pdo, 'storefront_phone_merge_requests');
$ctxCountryId = orange_admin_context_country_id($pdo);
$hasCountryCol = $hasTable && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'country_id');
$ctxCountryLabel = '';
if ($ctxCountryId > 0) {
    $ctxRow = orange_country_row_by_id($pdo, $ctxCountryId, false);
    $ctxCountryLabel = trim((string) ($ctxRow['name_ar'] ?? ''));
    if ($ctxCountryLabel === '' && $ctxRow !== null) {
        $ctxCountryLabel = trim((string) ($ctxRow['name_en'] ?? ''));
    }
}

if ($hasTable && $ctxCountryId > 0) {
    $rScope = orange_sql_filter_storefront_row_belongs_to_country(
        $pdo,
        'r',
        'proposed_channel_slug',
        $ctxCountryId
    );
    $aScope = orange_sql_filter_storefront_row_belongs_to_country(
        $pdo,
        'a',
        'registered_channel_slug',
        $ctxCountryId
    );
    if ($rScope !== null && $aScope !== null) {
        $q = $pdo->prepare(
            "SELECT r.*, a.email AS account_email
             FROM storefront_phone_merge_requests r
             INNER JOIN storefront_accounts a ON a.id = r.storefront_account_id
             WHERE r.consumed_at IS NULL AND r.expires_at > NOW()"
            . $rScope['where']
            . $aScope['where']
            . " ORDER BY r.created_at DESC
             LIMIT 100"
        );
        $q->execute(array_merge($rScope['params'], $aScope['params']));
    } elseif ($hasCountryCol) {
        $q = $pdo->prepare(
            "SELECT r.*, a.email AS account_email
             FROM storefront_phone_merge_requests r
             INNER JOIN storefront_accounts a ON a.id = r.storefront_account_id
             WHERE r.country_id = ? AND r.consumed_at IS NULL AND r.expires_at > NOW()
             ORDER BY r.created_at DESC
             LIMIT 100"
        );
        $q->execute([$ctxCountryId]);
    } else {
        $q = null;
    }
    if ($q) {
        $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
?>
<div class="admin-fy-shell" dir="rtl">
    <div class="page-title">
        <h1>دمج هاتف التسجيل</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

<?php if (!$hasTable): ?>
    <div class="alert-error">جدول <code>storefront_phone_merge_requests</code> غير جاهز — حدّث المخطط بزيارة الواجهة أو الأدمن.</div>
<?php elseif ($rows === []): ?>
    <div class="card admin-fy-card"><p class="muted" style="margin:0;">لا توجد طلبات دمج نشطة حالياً.</p></div>
<?php else: ?>
    <div class="card admin-fy-card">
        <div class="table-wrap admin-fy-table-wrap">
        <table class="admin-fy-table">
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

</div>
