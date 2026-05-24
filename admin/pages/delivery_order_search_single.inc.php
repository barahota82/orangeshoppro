<?php

declare(strict_types=1);

/** @var array<string, mixed> $o */
/** @var int $oid */
/** @var array<string, mixed> $ordersMoney context from parent — use orange_format_money_for_context */

$st = (string) ($o['status'] ?? '');
$agentName = trim((string) ($o['agent_name_ar'] ?? ''));
?>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>رقم الطلب</th>
                <th>العميل</th>
                <th>الهاتف</th>
                <th>المنطقة</th>
                <th>المندوب</th>
                <th>الحالة</th>
                <th>الإجمالي</th>
                <th>إجراءات</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo htmlspecialchars((string) ($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td dir="ltr"><?php echo htmlspecialchars((string) ($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string) ($o['area'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo $agentName !== '' ? htmlspecialchars($agentName, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                <td><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo orange_format_money_for_context($ordersMoney, (float) ($o['total'] ?? 0)); ?></td>
                <td class="actions">
                    <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice_edit&order_id=' . $oid), ENT_QUOTES, 'UTF-8'); ?>">تعديل جزئي</a>
                    <button type="button" class="btn-danger" onclick="dosCancelOrder(<?php echo $oid; ?>)">إلغاء (مرتجع كامل)</button>
                    <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice&order_id=' . $oid), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">طباعة</a>
                </td>
            </tr>
        </tbody>
    </table>
</div>
<p class="card-hint" style="margin-top:10px;">مرتجع جزئي → <strong>تعديل جزئي</strong> (إعادة حساب العروض + «حفظ + تم التسليم»).</p>
