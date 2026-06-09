<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/payments/payment_core.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = db();
orange_payments_ensure_schema($pdo);
$baCountryId = orange_admin_context_country_id($pdo);
$baCountryLabel = orange_admin_page_country_label($pdo);
$baCurrency = orange_country_functional_currency_code($pdo, $baCountryId);
$baMethodActive = orange_payment_bank_method_active($pdo, $baCountryId);
$baAccounts = orange_payment_bank_accounts($pdo, $baCountryId, false);
require_once __DIR__ . '/../../includes/payments/payment_gateway.php';
$baGwProvider = orange_payment_gateway_default_provider();
$baGwActive = orange_payment_gateway_method_active($pdo, $baCountryId);
$baGwConfigured = orange_payment_gateway_is_configured($baGwProvider, orange_payment_gateway_config($baGwProvider));
?>
<div class="page-title">
    <h1>الحسابات البنكية والدفع المباشر</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($baCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<p class="page-subtitle" style="margin:0 0 0.75rem;">حسابات بنك الشركة لهذه الدولة + تفعيل «التحويل البنكي» كطريقة دفع. تأكيد الدفعات من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=payment_review'), ENT_QUOTES, 'UTF-8'); ?>">مراجعة الدفعات</a>.</p>

<div class="card">
    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;max-width:40rem;">
        <input type="checkbox" id="ba_method_active" <?php echo $baMethodActive ? 'checked' : ''; ?> onchange="baToggleMethod(this.checked)">
        <span><strong>تفعيل التحويل البنكي المباشر</strong> لهذه الدولة (يظهر للعميل كخيار دفع — يتطلب حساباً بنكياً واحداً على الأقل).</span>
    </label>
</div>

<div class="card">
    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;max-width:46rem;<?php echo $baGwConfigured ? '' : 'opacity:.65;'; ?>">
        <input type="checkbox" id="ba_gateway_active" <?php echo $baGwActive ? 'checked' : ''; ?> <?php echo $baGwConfigured ? '' : 'disabled'; ?> onchange="baToggleGateway(this.checked)">
        <span>
            <strong>تفعيل الدفع بالبطاقة / البوابة الإلكترونية</strong> (<?php echo htmlspecialchars($baGwProvider, ENT_QUOTES, 'UTF-8'); ?>) لهذه الدولة — العميل يدفع من صفحة «تتبّع الطلب» عبر زر «ادفع الآن».
            <?php if (!$baGwConfigured): ?>
                <br><span class="muted">المزوّد غير مُعدّ بعد — أضف مفاتيح البوابة في <code>.env.php</code> على السيرفر (راجع <code>.env.example.php</code>) ثم أعد التحميل.</span>
            <?php endif; ?>
        </span>
    </label>
</div>

<div class="card">
    <h3>إضافة / تعديل حساب بنكي</h3>
    <input type="hidden" id="ba_id" value="0">
    <div class="form-grid">
        <div><label>اسم البنك <span style="color:#b45309;">*</span></label><input type="text" id="ba_bank_name"></div>
        <div><label>اسم صاحب الحساب</label><input type="text" id="ba_account_name"></div>
        <div><label>رقم الحساب</label><input type="text" id="ba_account_number" dir="ltr"></div>
        <div><label>IBAN</label><input type="text" id="ba_iban" dir="ltr"></div>
        <div><label>العملة</label><input type="text" id="ba_currency" dir="ltr" value="<?php echo htmlspecialchars($baCurrency, ENT_QUOTES, 'UTF-8'); ?>" maxlength="3"></div>
        <div><label>الترتيب</label><input type="number" id="ba_sort" value="0" style="max-width:120px;"></div>
        <div style="display:flex;align-items:flex-end;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="ba_is_active" checked> نشط</label>
        </div>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="baSave()">حفظ الحساب</button>
        <button type="button" class="btn-secondary" onclick="baReset()">جديد</button>
    </div>
</div>

<div class="card">
    <h3>الحسابات البنكية</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>البنك</th><th>صاحب الحساب</th><th>رقم الحساب</th><th>IBAN</th><th>العملة</th><th>الحالة</th><th></th></tr></thead>
            <tbody id="ba_tbody">
                <?php if ($baAccounts === []): ?>
                    <tr><td colspan="7" class="muted">لا حسابات بعد.</td></tr>
                <?php else: foreach ($baAccounts as $a): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $a['bank_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($a['account_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td dir="ltr"><?php echo htmlspecialchars((string) ($a['account_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td dir="ltr"><?php echo htmlspecialchars((string) ($a['iban'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td dir="ltr"><?php echo htmlspecialchars((string) ($a['currency'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo ((int) ($a['is_active'] ?? 0) === 1) ? 'نشط' : 'موقوف'; ?></td>
                        <td class="stock-actions">
                            <button type="button" class="btn btn-secondary" onclick='baEdit(<?php echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG); ?>)'>تعديل</button>
                            <button type="button" class="btn btn-outline" onclick="baDelete(<?php echo (int) $a['id']; ?>)">حذف</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function baApi(payload) {
    return postJSON('/admin/api/payments/bank-accounts.php', payload);
}
function baToggleMethod(active) {
    baApi({ action: 'toggle_method', active: active ? 1 : 0 }).then(function (r) {
        alert((r && r.message) || 'تم'); }).catch(function (e) { alert(e.message || String(e)); });
}
function baToggleGateway(active) {
    baApi({ action: 'toggle_gateway', active: active ? 1 : 0 }).then(function (r) {
        if (r && r.success === false) { alert(r.message || 'فشل'); var el = document.getElementById('ba_gateway_active'); if (el) el.checked = !active; return; }
        alert((r && r.message) || 'تم');
    }).catch(function (e) { alert(e.message || String(e)); });
}
function baReset() {
    document.getElementById('ba_id').value = '0';
    ['ba_bank_name','ba_account_name','ba_account_number','ba_iban','ba_sort'].forEach(function (id) { document.getElementById(id).value = id === 'ba_sort' ? '0' : ''; });
    document.getElementById('ba_is_active').checked = true;
}
function baEdit(a) {
    document.getElementById('ba_id').value = a.id || 0;
    document.getElementById('ba_bank_name').value = a.bank_name || '';
    document.getElementById('ba_account_name').value = a.account_name || '';
    document.getElementById('ba_account_number').value = a.account_number || '';
    document.getElementById('ba_iban').value = a.iban || '';
    document.getElementById('ba_currency').value = a.currency || '';
    document.getElementById('ba_sort').value = a.sort_order || 0;
    document.getElementById('ba_is_active').checked = parseInt(a.is_active, 10) === 1;
    window.scrollTo(0, 0);
}
function baSave() {
    baApi({
        action: 'save',
        id: parseInt(document.getElementById('ba_id').value, 10) || 0,
        bank_name: document.getElementById('ba_bank_name').value.trim(),
        account_name: document.getElementById('ba_account_name').value.trim(),
        account_number: document.getElementById('ba_account_number').value.trim(),
        iban: document.getElementById('ba_iban').value.trim(),
        currency: document.getElementById('ba_currency').value.trim(),
        sort_order: parseInt(document.getElementById('ba_sort').value, 10) || 0,
        is_active: document.getElementById('ba_is_active').checked ? 1 : 0
    }).then(function (r) {
        if (!r || !r.success) { alert((r && r.message) || 'فشل'); return; }
        location.reload();
    }).catch(function (e) { alert(e.message || String(e)); });
}
function baDelete(id) {
    if (!confirm('حذف هذا الحساب البنكي؟')) return;
    baApi({ action: 'delete', id: id }).then(function (r) {
        if (!r || !r.success) { alert((r && r.message) || 'فشل'); return; }
        location.reload();
    }).catch(function (e) { alert(e.message || String(e)); });
}
</script>
