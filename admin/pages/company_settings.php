<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'company_settings');

$csCountryId = orange_admin_settings_effective_country_id($pdo);
$csCountryRow = orange_country_row_by_id($pdo, $csCountryId, false);
$csCountryLabel = trim((string) ($csCountryRow['name_ar'] ?? ''));
if ($csCountryLabel === '' && $csCountryRow !== null) {
    $csCountryLabel = trim((string) ($csCountryRow['name_en'] ?? ''));
}
if ($csCountryLabel === '') {
    $csCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}
$csScoped = orange_company_settings_has_country_column($pdo);
?>
<div class="page-title page-title--stacked">
    <h1>بيانات الشركة</h1>
    <p class="page-subtitle">الهوية والعنوان وبيانات الفاتورة الضريبية تظهر للعملاء والمستندات الرسمية.</p>
    <?php if ($csScoped && $csCountryId > 0): ?>
    <p class="card-hint" style="margin:0.35rem 0 0;line-height:1.55;">
        سياق الدولة: <strong><?php echo htmlspecialchars($csCountryLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
        — بيانات الشركة المعروضة لهذه الدولة فقط. إن ظهرت بيانات الكويت فعدّلها هنا لبيانات مصر (قد تكون نُسخت عند الإنشاء).
    </p>
    <?php elseif (!$csScoped): ?>
    <p class="card-hint" style="margin:0;color:#92400e;">
        تنبيه: عمود <code dir="ltr">country_id</code> غير مفعّل بعد على جدول بيانات الشركة.
    </p>
    <?php endif; ?>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>company_settings</code> غير موجود. شغّل ترحيل الإعدادات أو أنشئ الجدول.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>تعديل بيانات الشركة</h3>
    <div class="form-grid">
        <div><label>اسم الشركة (عربي)</label><input type="text" id="company_name_ar"></div>
        <div><label>اسم الشركة (English)</label><input type="text" id="company_name_en"></div>
        <div><label>شعار الشركة (اسم الملف)</label><input type="text" id="company_logo"></div>
        <div><label>السجل التجاري</label><input type="text" id="commercial_register"></div>
        <div><label>أرقام التواصل</label><input type="text" id="phones"></div>
        <div><label>العنوان</label><textarea id="address" rows="3"></textarea></div>
        <div><label>الرقم الضريبي (للفواتير)</label><input type="text" id="vat_number" placeholder="إن وُجد"></div>
        <div style="grid-column:1/-1;"><label>نص قانوني أسفل الفاتورة (اختياري)</label><textarea id="invoice_footer" rows="2" placeholder="مثال: سداد خلال ٣٠ يوم — البضاعة تُسلّم بحالة جيدة"></textarea></div>
    </div>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCompanySettings()">حفظ</button>
    </div>
</div>

<div class="card">
    <h3>الدفع الإلكتروني (المتجر)</h3>
    <p class="card-hint" style="margin:0 0 12px;line-height:1.55;">
        مفتاح تشغيل/إيقاف خيار «الدفع الإلكتروني» للعملاء في هذه الدولة.
        عند الإيقاف يبقى <strong>الدفع عند الاستلام</strong> فقط. ربط بوابة الدفع الفعلية (KNET / Visa…) خطوة لاحقة عند الجاهزية القانونية.
    </p>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
        <input type="checkbox" id="payment_online_enabled" value="1">
        <span>تفعيل الدفع الإلكتروني للعملاء</span>
    </label>
    <div class="admin-form-actions">
        <button type="button" onclick="saveCompanySettings()">حفظ</button>
    </div>
</div>

<script>
async function loadCompanySettings() {
    const res = await postJSON('/admin/api/settings/company.php', { action: 'get' });
    if (!res.success) {
        alert(res.message || 'خطأ');
        return;
    }
    const d = res.data || {};
    document.getElementById('company_name_ar').value = d.company_name_ar || '';
    document.getElementById('company_name_en').value = d.company_name_en || '';
    document.getElementById('company_logo').value = d.company_logo || '';
    document.getElementById('commercial_register').value = d.commercial_register || '';
    document.getElementById('phones').value = d.phones || '';
    document.getElementById('address').value = d.address || '';
    document.getElementById('vat_number').value = d.vat_number || '';
    document.getElementById('invoice_footer').value = d.invoice_footer || '';
    const payEl = document.getElementById('payment_online_enabled');
    if (payEl) {
        payEl.checked = parseInt(String(d.payment_online_enabled || '0'), 10) === 1;
    }
}

async function saveCompanySettings() {
    const res = await postJSON('/admin/api/settings/company.php', {
        action: 'save',
        company_name_ar: document.getElementById('company_name_ar').value.trim(),
        company_name_en: document.getElementById('company_name_en').value.trim(),
        company_logo: document.getElementById('company_logo').value.trim(),
        commercial_register: document.getElementById('commercial_register').value.trim(),
        phones: document.getElementById('phones').value.trim(),
        address: document.getElementById('address').value.trim(),
        vat_number: document.getElementById('vat_number').value.trim(),
        invoice_footer: document.getElementById('invoice_footer').value.trim(),
        payment_online_enabled: document.getElementById('payment_online_enabled') && document.getElementById('payment_online_enabled').checked ? 1 : 0
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ'));
}

loadCompanySettings();
</script>
