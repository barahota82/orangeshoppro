<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/storefront_phone_country_select.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

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
            سجل العملاء: <strong>الهاتف</strong> معرّف فريد للعميل (مثل الكود). الحقول: الاسم، المنطقة، العنوان، البريد، الملاحظات (تُحدَّث تلقائياً من ملاحظات كل طلب ويب)، حد الائتمان، والذمم.
            الذمم والقبض من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_ledger'), ENT_QUOTES, 'UTF-8'); ?>">ذمم العملاء والموردين</a>.
        </p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_ledger'), ENT_QUOTES, 'UTF-8'); ?>">الذمم وسندات القبض</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=manual_order'), ENT_QUOTES, 'UTF-8'); ?>">فاتورة مبيعات</a>
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
    <p class="card-hint" style="margin-top:0;">الهاتف هو المعرّف الأساسي للعميل. <strong>كود العميل</strong> اختياري للتقارير. ملاحظات الطلبات من المتجر تُضاف تلقائياً إلى حقل الملاحظات مع رقم الطلب والوقت.</p>
    <input type="hidden" id="cust_id" value="0">
    <div class="form-grid">
        <div>
            <label for="cust_code">كود العميل (اختياري)</label>
            <input type="text" id="cust_code" maxlength="32" autocomplete="off" dir="ltr" lang="en" placeholder="مثال: C-1001">
        </div>
        <div>
            <label for="cust_name">الاسم</label>
            <input type="text" id="cust_name" maxlength="255" autocomplete="off" placeholder="اسم العميل">
        </div>
        <div>
            <label for="cust_phone_country">كود الدولة (اختياري)</label>
            <?php orange_storefront_render_phone_country_select('cust_phone_country'); ?>
            <p class="card-hint" style="margin:6px 0 0;">إن لم تختر دولة، أدخل الرقم كاملاً بصيغة دولية (<span dir="ltr">+</span> أو <span dir="ltr">00</span>).</p>
        </div>
        <div>
            <label for="cust_phone">الهاتف <span style="color:#b45309;">*</span></label>
            <input type="text" id="cust_phone" class="js-orange-phone-input" maxlength="24" autocomplete="off" dir="ltr" lang="en" placeholder="+965… أو 00… أو رقم وطني مع اختيار الدولة">
        </div>
        <div>
            <label for="cust_email">البريد الإلكتروني</label>
            <input type="email" id="cust_email" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري">
        </div>
        <div>
            <label for="cust_area">المنطقة</label>
            <input type="text" id="cust_area" maxlength="255" autocomplete="off" placeholder="المنطقة">
        </div>
        <div style="grid-column:1/-1;">
            <label for="cust_address">العنوان</label>
            <textarea id="cust_address" rows="2" maxlength="2000" autocomplete="off" placeholder="عنوان التوصيل"></textarea>
        </div>
        <div>
            <label for="cust_limit">حد ائتمان (اختياري)</label>
            <input type="number" id="cust_limit" class="admin-inp-money" step="any" min="0" value="" placeholder="فارغ = بلا حد" inputmode="decimal" lang="en" dir="ltr">
        </div>
        <div style="grid-column:1/-1;">
            <label for="cust_notes">ملاحظات (يشمل سجل ملاحظات الطلبات)</label>
            <textarea id="cust_notes" rows="5" autocomplete="off" placeholder="ملاحظات يدوية + أسطر تلقائية من كل طلب ويب"></textarea>
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
            <input type="search" id="cust_filter" class="party-registry-search" placeholder="اسم، هاتف، بريد، منطقة…" autocomplete="off" lang="ar" dir="rtl" oninput="custFilterRows()">
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
                        <th>المنطقة</th>
                        <th>البريد</th>
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
                        $codeDisp = (string) ($c['code'] ?? '');
                        $areaDisp = (string) ($c['area'] ?? '');
                        $emailDisp = (string) ($c['email'] ?? '');
                        $hayRaw = trim(
                            (string) ($c['name_ar'] ?? '')
                            . ' ' . ($c['phone'] ?? '')
                            . ' ' . $codeDisp
                            . ' ' . $areaDisp
                            . ' ' . $emailDisp
                            . ' ' . ($c['notes'] ?? '')
                        );
                        $hay = function_exists('mb_strtolower') ? mb_strtolower($hayRaw, 'UTF-8') : strtolower($hayRaw);
                        $emailShort = $emailDisp;
                        if (function_exists('mb_strlen') && mb_strlen($emailShort, 'UTF-8') > 28) {
                            $emailShort = mb_substr($emailShort, 0, 26, 'UTF-8') . '…';
                        } elseif (strlen($emailShort) > 28) {
                            $emailShort = substr($emailShort, 0, 26) . '…';
                        }
                        ?>
                        <tr data-cust-search="<?php echo htmlspecialchars($hay, ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo $cid; ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($codeDisp, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($c['name_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars((string) ($c['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($areaDisp, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($emailShort, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($lim, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr"><?php echo number_format($bal, 3); ?></td>
                            <td><?php echo (int) ($c['order_cnt'] ?? 0); ?></td>
                            <td class="party-registry-actions">
                                <button type="button" class="btn-secondary party-registry-btn" onclick='custEdit(<?php echo json_encode([
                                    'id' => $cid,
                                    'code' => $codeDisp,
                                    'name_ar' => (string) ($c['name_ar'] ?? ''),
                                    'phone' => (string) ($c['phone'] ?? ''),
                                    'area' => $areaDisp,
                                    'address' => (string) ($c['address'] ?? ''),
                                    'email' => $emailDisp,
                                    'credit_limit' => $c['credit_limit'] ?? null,
                                    'notes' => (string) ($c['notes'] ?? ''),
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>تعديل</button>
                                <a class="btn btn-secondary party-registry-btn" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_ledger&stmt_party_kind=customer&stmt_party_id=' . (int) $cid . '#partner-account-statement'), ENT_QUOTES, 'UTF-8'); ?>">كشف حساب</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/input-constraints.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
function custPhoneCountryEl() {
    return document.getElementById('cust_phone_country');
}
/** يملأ حقل الدولة والرقم عند التعديل إن وُجد بادئة معروفة. */
function custSplitPhoneForForm(stored) {
    var raw = String(stored || '').trim();
    if (!raw) {
        return { country: '', phone: '' };
    }
    var normFn = window.orangeNormalizeCustomerPhone;
    var norm = normFn ? normFn(raw, null) : null;
    if (!norm) {
        return { country: '', phone: raw };
    }
    var digits = norm.replace(/\D/g, '');
    var prefs = ['965', '92', '91', '63'];
    for (var i = 0; i < prefs.length; i++) {
        var cc = prefs[i];
        if (digits.indexOf(cc) !== 0) {
            continue;
        }
        var nat = digits.slice(cc.length);
        if (nat.length < 8) {
            continue;
        }
        if (normFn && normFn(nat, cc) === norm) {
            return { country: cc, phone: nat };
        }
    }
    return { country: '', phone: norm.charAt(0) === '+' ? norm.slice(1) : norm };
}
function custResetForm() {
    document.getElementById('cust_id').value = '0';
    document.getElementById('cust_code').value = '';
    document.getElementById('cust_name').value = '';
    document.getElementById('cust_phone').value = '';
    var cc = custPhoneCountryEl();
    if (cc) {
        cc.value = '';
    }
    document.getElementById('cust_email').value = '';
    document.getElementById('cust_area').value = '';
    document.getElementById('cust_address').value = '';
    document.getElementById('cust_limit').value = '';
    document.getElementById('cust_notes').value = '';
}
function custEdit(row) {
    document.getElementById('cust_id').value = String(row.id || 0);
    document.getElementById('cust_code').value = row.code || '';
    document.getElementById('cust_name').value = row.name_ar || '';
    var split = custSplitPhoneForForm(row.phone || '');
    var ccEl = custPhoneCountryEl();
    if (ccEl) {
        ccEl.value = split.country || '';
    }
    document.getElementById('cust_phone').value = split.phone || '';
    document.getElementById('cust_email').value = row.email || '';
    document.getElementById('cust_area').value = row.area || '';
    document.getElementById('cust_address').value = row.address || '';
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
    var ccEl = custPhoneCountryEl();
    var phoneCountry = ccEl && ccEl.value ? String(ccEl.value).trim() : '';
    var intlSel = ccEl && ccEl.tagName === 'SELECT' && phoneCountry === '__intl__';
    var ccForNorm = intlSel ? null : phoneCountry && phoneCountry !== '__intl__' ? phoneCountry : null;
    var email = document.getElementById('cust_email').value.trim();
    var area = document.getElementById('cust_area').value.trim();
    var address = document.getElementById('cust_address').value.trim();
    var limRaw = document.getElementById('cust_limit').value.trim();
    var notes = document.getElementById('cust_notes').value.trim();
    if (!phone) {
        alert('الهاتف مطلوب');
        return;
    }
    if (window.orangeNormalizeCustomerPhone) {
        var ok = window.orangeNormalizeCustomerPhone(phone, ccForNorm, intlSel);
        if (!ok) {
            alert('رقم الهاتف غير صالح. استخدم + أو 00 مع كود الدولة، أو اختر الدولة وأدخل الرقم الوطني (8–14 رقماً مع الكود).');
            return;
        }
    }
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('بريد إلكتروني غير صالح');
        return;
    }
    var payload = {
        name_ar: name || 'عميل',
        phone: phone,
        phone_country: phoneCountry || null,
        area: area,
        address: address,
        email: email || null,
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
