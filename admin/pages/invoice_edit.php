<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/invoice_edit_helpers.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$order = null;
$paidItems = [];
$comboGroups = [];
$bogoBundleGroups = [];
$standaloneItems = [];
$err = '';
$storedRestores = [];

if ($orderId > 0) {
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$order) {
        $err = 'الطلب غير موجود';
    } elseif (!in_array((string) ($order['status'] ?? ''), orange_invoice_edit_allowed_statuses(), true)) {
        $err = 'الطلب غير مؤهل للتعديل في هذه الحالة';
    } else {
        $storedRestores = orange_invoice_edit_read_stored_restores($order);
        $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $it->execute([$orderId]);
        $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($items as $row) {
            if (!orange_invoice_edit_is_gift_line($pdo, $row, $order)) {
                $paidItems[] = $row;
            }
        }
        $framesLayout = orange_invoice_edit_paid_frames_layout($pdo, $order, $paidItems);
        $comboGroups = $framesLayout['combo_groups'];
        $bogoBundleGroups = $framesLayout['bogo_bundle_groups'];
        $standaloneItems = $framesLayout['standalone'];
    }
}

$money = orange_admin_currency_context($pdo);
$moneyDec = (int) ($money['decimals'] ?? 3);
?>
<style>
.ie-promo-panel { margin-top:16px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;background:#fff; }
.ie-promo-panel--dropped { border-color:#fecaca;background:#fef2f2; }
.ie-promo-row { display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:8px 0;border-bottom:1px solid #f1f5f9; }
.ie-promo-row:last-child { border-bottom:none; }
.ie-badge-ok { color:#15803d;font-weight:600; }
.ie-badge-no { color:#b91c1c;font-weight:600; }
.ie-badge-override { font-size:0.8rem;color:#7c3aed;background:#f5f3ff;padding:2px 8px;border-radius:4px; }
.ie-combo-frame { margin:0 0 16px;padding:12px 14px;border:2px solid #93c5fd;border-radius:10px;background:#f8fafc; }
.ie-combo-frame__title { margin:0 0 8px;font-weight:700;color:#1e40af; }
.ie-combo-frame__hint { margin:0 0 10px;font-size:0.88rem;color:#64748b; }
.ie-combo-frame__footer { display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid #cbd5e1; }
.ie-combo-frame__price { font-weight:700;color:#0f172a; }
.ie-combo-qty-ro { width:64px;background:#f1f5f9;cursor:not-allowed; }
.ie-bogo-frame { margin:0 0 16px;padding:12px 14px;border:2px solid #86efac;border-radius:10px;background:#f0fdf4; }
.ie-bogo-frame__title { margin:0 0 8px;font-weight:700;color:#166534; }
.ie-bogo-frame__hint { margin:0 0 10px;font-size:0.88rem;color:#64748b; }
.ie-bogo-frame__footer { display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:10px;padding-top:10px;border-top:1px solid #bbf7d0; }
.ie-bogo-frame__price { font-weight:700;color:#0f172a; }
</style>

<div class="admin-fy-shell" dir="rtl">
    <div class="page-title">
        <h1>تعديل بنود الطلب (أونلاين)</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <p class="page-subtitle">
        مرتجع جزئي قبل التسليم — معاينة فورية للعروض (§13.11.9.7.6–7).
        <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=delivery_order_search'), ENT_QUOTES, 'UTF-8'); ?>">بحث التسليم</a>
    </p>

<?php if ($orderId <= 0): ?>
<div class="card"><p class="muted">افتح الصفحة مع <code>?order_id=</code> من بحث التسليم.</p></div>
<?php elseif ($err !== ''): ?>
<div class="card"><div class="alert-error"><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div></div>
<?php else: ?>

<div class="card admin-fy-card">
    <p><strong>طلب:</strong> <?php echo htmlspecialchars((string) ($order['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        — <?php echo htmlspecialchars((string) ($order['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>

    <h3 class="card-title">بنود مدفوعة</h3>

    <?php foreach ($comboGroups as $cg): ?>
    <div class="ie-combo-frame" data-combo-group="<?php echo (int) ($cg['group_id'] ?? 1); ?>">
        <p class="ie-combo-frame__title"><?php echo htmlspecialchars((string) ($cg['title'] ?? 'كومبو'), ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="ie-combo-frame__hint">إطار كومبو — الكل أو لا شيء. لا يُعدَّل مكوّن واحد؛ «إرجاع الحزمة كاملة» فقط.</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>الصنف</th>
                        <th>لون/مقاس</th>
                        <th>الكمية</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cg['items'] as $row): ?>
                    <tr data-item-id="<?php echo (int) ($row['id'] ?? 0); ?>" data-combo-member="1">
                        <td><?php echo htmlspecialchars((string) ($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(trim((string) ($row['color'] ?? '') . ' / ' . (string) ($row['size'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <input type="number" class="ie-combo-qty-ro" value="<?php echo (int) ($row['qty'] ?? 0); ?>" readonly tabindex="-1" aria-readonly="true">
                            <input type="hidden" class="ie-qty" value="<?php echo (int) ($row['qty'] ?? 0); ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="ie-combo-frame__footer">
            <span class="ie-combo-frame__price">سعر الحزمة: <?php echo orange_format_money_for_context($money, (float) ($cg['total_price'] ?? 0)); ?>
                <?php if ((int) ($cg['bundle_qty'] ?? 1) > 1): ?>
                    <span class="muted">(<?php echo (int) $cg['bundle_qty']; ?> × <?php echo orange_format_money_for_context($money, (float) ($cg['combo_price'] ?? 0)); ?>)</span>
                <?php endif; ?>
            </span>
            <button type="button" class="btn-secondary ie-combo-return-all" data-item-ids="<?php echo htmlspecialchars(implode(',', array_map('strval', $cg['item_ids'] ?? [])), ENT_QUOTES, 'UTF-8'); ?>">إرجاع الحزمة كاملة</button>
        </div>
    </div>
    <?php endforeach; ?>

    <?php foreach ($bogoBundleGroups as $bg): ?>
    <div class="ie-bogo-frame" data-bogo-group="<?php echo (int) ($bg['group_id'] ?? 2); ?>">
        <p class="ie-bogo-frame__title"><?php echo htmlspecialchars((string) ($bg['title'] ?? 'حزمة شراء BOGO'), ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="ie-bogo-frame__hint">إطار حزمة شراء BOGO — الكل أو لا شيء. بنود بالتجزئة؛ لا يُعدَّل مكوّن واحد.</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>الصنف</th>
                        <th>لون/مقاس</th>
                        <th>الكمية</th>
                        <th>السعر</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bg['items'] as $row): ?>
                    <tr data-item-id="<?php echo (int) ($row['id'] ?? 0); ?>" data-bogo-member="1">
                        <td><?php echo htmlspecialchars((string) ($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(trim((string) ($row['color'] ?? '') . ' / ' . (string) ($row['size'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <input type="number" class="ie-combo-qty-ro" value="<?php echo (int) ($row['qty'] ?? 0); ?>" readonly tabindex="-1" aria-readonly="true">
                            <input type="hidden" class="ie-qty" value="<?php echo (int) ($row['qty'] ?? 0); ?>">
                        </td>
                        <td><?php echo orange_format_money_for_context($money, (float) ($row['price'] ?? 0)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="ie-bogo-frame__footer">
            <span class="ie-bogo-frame__price">مجموع التجزئة: <?php echo orange_format_money_for_context($money, (float) ($bg['total_price'] ?? 0)); ?></span>
            <button type="button" class="btn-secondary ie-bogo-return-all" data-item-ids="<?php echo htmlspecialchars(implode(',', array_map('strval', $bg['item_ids'] ?? [])), ENT_QUOTES, 'UTF-8'); ?>">إرجاع الحزمة كاملة</button>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($standaloneItems !== []): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الصنف</th>
                    <th>لون/مقاس</th>
                    <th>الكمية</th>
                    <th>السعر</th>
                </tr>
            </thead>
            <tbody id="ie_paid_body">
                <?php foreach ($standaloneItems as $row): ?>
                <tr data-item-id="<?php echo (int) ($row['id'] ?? 0); ?>">
                    <td><?php echo htmlspecialchars((string) ($row['product_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars(trim((string) ($row['color'] ?? '') . ' / ' . (string) ($row['size'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><input type="number" min="0" class="ie-qty" value="<?php echo (int) ($row['qty'] ?? 0); ?>" style="width:80px;"></td>
                    <td><?php echo orange_format_money_for_context($money, (float) ($row['price'] ?? 0)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($comboGroups === [] && $bogoBundleGroups === []): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الصنف</th>
                    <th>لون/مقاس</th>
                    <th>الكمية</th>
                    <th>السعر</th>
                </tr>
            </thead>
            <tbody id="ie_paid_body"></tbody>
        </table>
        <p class="muted">لا توجد بنود مدفوعة.</p>
    </div>
    <?php else: ?>
    <tbody id="ie_paid_body" hidden aria-hidden="true"></tbody>
    <?php endif; ?>

    <div class="ie-promo-panel" id="ie_active_panel">
        <strong>ملخص العروض — الفاتورة النشطة</strong>
        <div id="ie_active_list" style="margin-top:8px;"><p class="muted">جاري المعاينة…</p></div>
        <p style="margin:10px 0 0;"><strong>الإجمالي المتوقع:</strong> <span id="ie_total_preview">—</span></p>
    </div>

    <div class="ie-promo-panel ie-promo-panel--dropped" id="ie_dropped_panel" style="display:none;">
        <strong>سقط من الفاتورة</strong>
        <p class="muted" style="margin:4px 0 8px;font-size:0.88rem;">بنود/عروض ستُزال لعدم تحقق الشرط — «ارجع للفاتورة» = استثناء إدارة (§13.11.9.7.7).</p>
        <div id="ie_dropped_list"></div>
    </div>

    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;">
        <button type="button" onclick="invoiceEditSave(false)">حفظ التعديل</button>
        <button type="button" class="btn-success" onclick="invoiceEditSave(true)">حفظ + تم التسليم</button>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=invoice&order_id=' . $orderId), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">معاينة طباعة</a>
    </div>
</div>

<script>
(function () {
    var orderId = <?php echo (int) $orderId; ?>;
    var adminRestores = <?php echo json_encode($storedRestores, JSON_UNESCAPED_UNICODE); ?>;
    window.ieAdminRestores = adminRestores;
    var previewTimer = null;
    var moneyDec = <?php echo (int) $moneyDec; ?>;

    function collectChanges() {
        var changes = [];
        document.querySelectorAll('tr[data-item-id]').forEach(function (tr) {
            var id = parseInt(tr.getAttribute('data-item-id'), 10);
            var inp = tr.querySelector('.ie-qty');
            if (!id || !inp) return;
            changes.push({ item_id: id, qty: parseInt(inp.value, 10) || 0 });
        });
        return changes;
    }

    function fmtMoney(n) {
        return (parseFloat(n) || 0).toFixed(moneyDec);
    }

    function renderPromoRow(row, showRestore) {
        var deliver = row.deliver !== false;
        var html = '<div class="ie-promo-row" data-kind="' + (row.kind || '') + '">';
        html += '<span>' + (row.label || row.kind || '') + '</span>';
        if (row.condition_text) {
            html += '<span class="muted" style="font-size:0.85rem;">(' + row.condition_text + ')</span>';
        }
        html += deliver
            ? '<span class="ie-badge-ok">✅ يُسلَّم</span>'
            : '<span class="ie-badge-no">❌ لا تُسلَّم</span>';
        if (row.admin_override) {
            html += '<span class="ie-badge-override">استثناء إدارة</span>';
        }
        if (showRestore) {
            html += '<button type="button" class="btn-secondary" style="margin-right:auto;" onclick="invoiceEditRestore(\'' + row.kind + '\')">ارجع للفاتورة</button>';
        }
        html += '</div>';
        return html;
    }

    window.invoiceEditRestore = function (kind) {
        if (!kind || adminRestores.indexOf(kind) >= 0) return;
        adminRestores.push(kind);
        window.ieAdminRestores = adminRestores;
        invoiceEditPreviewNow();
    };

    window.invoiceEditPreviewNow = async function () {
        var activeEl = document.getElementById('ie_active_list');
        var droppedEl = document.getElementById('ie_dropped_list');
        var droppedPanel = document.getElementById('ie_dropped_panel');
        var totalEl = document.getElementById('ie_total_preview');
        try {
            var res = await postJSON('/admin/api/orders/preview-invoice-promos.php', {
                order_id: orderId,
                changes: collectChanges(),
                admin_restores: adminRestores
            });
            if (!res.success) {
                activeEl.innerHTML = '<p class="alert-error">' + (res.message || 'فشل المعاينة') + '</p>';
                return;
            }
            var active = res.active || [];
            if (active.length === 0) {
                activeEl.innerHTML = '<p class="muted">— لا عروض نشطة —</p>';
            } else {
                activeEl.innerHTML = active.map(function (r) { return renderPromoRow(r, false); }).join('');
            }
            var dropped = res.dropped || [];
            if (dropped.length === 0) {
                droppedPanel.style.display = 'none';
            } else {
                droppedPanel.style.display = 'block';
                droppedEl.innerHTML = dropped.map(function (r) { return renderPromoRow(r, true); }).join('');
            }
            totalEl.textContent = fmtMoney(res.total);
        } catch (e) {
            activeEl.innerHTML = '<p class="alert-error">' + (e.message || String(e)) + '</p>';
        }
    };

    function schedulePreview() {
        if (previewTimer) clearTimeout(previewTimer);
        previewTimer = setTimeout(invoiceEditPreviewNow, 650);
    }

    document.querySelectorAll('.ie-qty').forEach(function (inp) {
        inp.addEventListener('input', schedulePreview);
        inp.addEventListener('change', schedulePreview);
    });

    document.querySelectorAll('.ie-combo-return-all, .ie-bogo-return-all').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ids = (btn.getAttribute('data-item-ids') || '').split(',').map(function (s) { return parseInt(s, 10); }).filter(function (n) { return n > 0; });
            if (!ids.length) return;
            var isBogo = btn.classList.contains('ie-bogo-return-all');
            var msg = isBogo
                ? 'إرجاع حزمة شراء BOGO كاملة — حذف كل مكوّناتها من الطلب؟'
                : 'إرجاع الحزمة كاملة (كومbo) — حذف كل مكوّناتها من الطلب؟';
            if (!confirm(msg)) return;
            ids.forEach(function (id) {
                var tr = document.querySelector('tr[data-item-id="' + id + '"]');
                if (!tr) return;
                var hid = tr.querySelector('.ie-qty');
                var ro = tr.querySelector('.ie-combo-qty-ro');
                if (hid) hid.value = '0';
                if (ro) ro.value = '0';
            });
            invoiceEditPreviewNow();
        });
    });

    invoiceEditPreviewNow();
})();

async function invoiceEditSave(completeAfter) {
    var changes = [];
    document.querySelectorAll('tr[data-item-id]').forEach(function (tr) {
        var id = parseInt(tr.getAttribute('data-item-id'), 10);
        var inp = tr.querySelector('.ie-qty');
        if (!id || !inp) return;
        changes.push({ item_id: id, qty: parseInt(inp.value, 10) || 0 });
    });
    if (!confirm(completeAfter ? 'حفظ التعديل ثم «تم التسليم» (مخزون فقط)؟' : 'حفظ التعديل؟')) return;
    var restores = window.ieAdminRestores || [];
    var res = await postJSON('/admin/api/orders/amend-invoice-items.php', {
        order_id: <?php echo (int) $orderId; ?>,
        changes: changes,
        admin_restores: restores,
        mark_completed: completeAfter ? 1 : 0
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) {
        if (completeAfter) {
            window.location.href = <?php echo json_encode(storefront_public_path('/admin/index.php?page=online_orders_final_posting'), JSON_UNESCAPED_UNICODE); ?>;
        } else {
            location.reload();
        }
    }
}
</script>
<?php endif; ?>
</div>
