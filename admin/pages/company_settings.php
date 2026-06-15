<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/storefront_payment_settings.php';

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
$csRow = [];
if ($hasTable) {
    $fetched = orange_company_settings_row($pdo, $csCountryId);
    if (is_array($fetched)) {
        $csRow = $fetched;
    }
}
$csHasPayOnlineCol = orange_company_settings_has_payment_online_column($pdo);
$csPayOnlineChecked = $csHasPayOnlineCol && (int) ($csRow['payment_online_enabled'] ?? 0) === 1;
$csField = static function (array $row, string $key): string {
    return htmlspecialchars(trim((string) ($row[$key] ?? '')), ENT_QUOTES, 'UTF-8');
};
$csHasSaved = false;
foreach (['company_name_ar', 'company_name_en', 'commercial_register', 'phones', 'address', 'vat_number', 'invoice_footer_ar', 'invoice_footer_en'] as $csKey) {
    if (trim((string) ($csRow[$csKey] ?? '')) !== '') {
        $csHasSaved = true;
        break;
    }
}
$csUpdatedAt = trim((string) ($csRow['updated_at'] ?? ''));
?>
<div class="page-title">
    <h1>بيانات الشركة</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php if ($csScoped && $csCountryId > 0): ?>
<?php if ($csHasSaved): ?>
<p class="card-hint" style="margin:0.25rem 0 0;color:#166534;line-height:1.55;">
    بيانات محفوظة<?php if ($csUpdatedAt !== ''): ?> — آخر تحديث: <?php echo htmlspecialchars($csUpdatedAt, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>.
</p>
<?php endif; ?>
<?php elseif (!$csScoped): ?>
<p class="card-hint" style="margin:0;color:#92400e;">
    تنبيه: عمود <code dir="ltr">country_id</code> غير مفعّل بعد على جدول بيانات الشركة.
</p>
<?php endif; ?>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>company_settings</code> غير موجود. شغّل ترحيل الإعدادات أو أنشئ الجدول.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>تعديل بيانات الشركة</h3>
    <div class="form-grid">
        <div><label>اسم الشركة (عربي)</label><input type="text" id="company_name_ar" value="<?php echo $csField($csRow, 'company_name_ar'); ?>"></div>
        <div><label>اسم الشركة (English)</label><input type="text" id="company_name_en" value="<?php echo $csField($csRow, 'company_name_en'); ?>"></div>
        <div><label>أرقام التواصل</label><input type="text" id="phones" value="<?php echo $csField($csRow, 'phones'); ?>"></div>
        <div><label>السجل التجاري</label><input type="text" id="commercial_register" value="<?php echo $csField($csRow, 'commercial_register'); ?>"></div>
        <div><label>العنوان</label><textarea id="address" rows="3"><?php echo $csField($csRow, 'address'); ?></textarea></div>
        <div><label>شعار الشركة (للطباعة)</label>
            <input type="file" id="company_logo_file" accept="image/png,image/webp,image/jpeg">
            <input type="hidden" id="company_logo" value="<?php echo $csField($csRow, 'company_logo'); ?>">
            <p class="card-hint" style="margin:0.3rem 0 0;">PNG أو WebP بخلفية شفافة (يُفضّل). بعد الرفع يُحفظ مع بيانات الشركة.</p>
            <div id="company_logo_preview_wrap" style="margin-top:0.5rem;<?php echo trim((string) ($csRow['company_logo'] ?? '')) !== '' ? '' : 'display:none;'; ?>">
                <img id="company_logo_preview" src="<?php echo trim((string) ($csRow['company_logo'] ?? '')) !== '' ? htmlspecialchars('/uploads/company/' . basename((string) $csRow['company_logo']), ENT_QUOTES, 'UTF-8') : ''; ?>" alt="" style="max-height:64px;max-width:180px;object-fit:contain;border-radius:6px;border:1px solid #e5e7eb;">
            </div>
        </div>
        <div><label>الرقم الضريبي (للفواتير)</label><input type="text" id="vat_number" placeholder="إن وُجد" value="<?php echo $csField($csRow, 'vat_number'); ?>"></div>
        <div><label>نسبة ضريبة القيمة المضافة % (تلقائي على الفواتير — الكويت 0)</label><input type="number" id="vat_rate" min="0" max="99.999" step="0.001" lang="en" dir="ltr" placeholder="0" value="<?php echo htmlspecialchars((string) ($csRow['vat_rate'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div><label>حد تنبيه الأدمن «قارب على النفاذ» (داخلي — التنبيه عند الرصيد ≤ هذا الرقم)</label><input type="number" id="low_stock_threshold" min="1" max="100000" step="1" lang="en" dir="ltr" placeholder="3" value="<?php echo htmlspecialchars((string) ($csRow['low_stock_threshold'] ?? '3'), ENT_QUOTES, 'UTF-8'); ?>"></div>
        <div><label>حد إظهار «كمية محدودة» للعميل (واجهة المتجر — يظهر عند الرصيد ≤ هذا الرقم)</label><input type="number" id="customer_low_stock_threshold" min="1" max="100000" step="1" lang="en" dir="ltr" placeholder="5" value="<?php echo htmlspecialchars((string) ($csRow['customer_low_stock_threshold'] ?? '5'), ENT_QUOTES, 'UTF-8'); ?>"></div>
        <?php
        $csFooterAr = trim((string) ($csRow['invoice_footer_ar'] ?? ''));
        $csFooterEn = trim((string) ($csRow['invoice_footer_en'] ?? ''));
        ?>
        <div style="grid-column:1/-1;"><label>نص قانوني أسفل الفاتورة — عربي (اختياري)</label><textarea id="invoice_footer_ar" rows="2" dir="rtl" placeholder="مثال: سياسة الاستبدال والاسترجاع — خلال ١٤ يوماً ..."><?php echo htmlspecialchars($csFooterAr, ENT_QUOTES, 'UTF-8'); ?></textarea></div>
        <div style="grid-column:1/-1;"><label>نص قانوني أسفل الفاتورة — إنجليزي (اختياري)</label><textarea id="invoice_footer_en" rows="2" dir="ltr" lang="en" placeholder="e.g., Return &amp; Exchange Policy: within 14 days ..."><?php echo htmlspecialchars($csFooterEn, ENT_QUOTES, 'UTF-8'); ?></textarea></div>
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
        <input type="checkbox" id="payment_online_enabled" value="1"<?php echo $csPayOnlineChecked ? ' checked' : ''; ?>>
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
    var vrEl = document.getElementById('vat_rate');
    if (vrEl) vrEl.value = (d.vat_rate !== undefined && d.vat_rate !== null) ? d.vat_rate : '0';
    var lstEl = document.getElementById('low_stock_threshold');
    if (lstEl) lstEl.value = (d.low_stock_threshold !== undefined && d.low_stock_threshold !== null && d.low_stock_threshold !== '') ? d.low_stock_threshold : '3';
    var clsEl = document.getElementById('customer_low_stock_threshold');
    if (clsEl) clsEl.value = (d.customer_low_stock_threshold !== undefined && d.customer_low_stock_threshold !== null && d.customer_low_stock_threshold !== '') ? d.customer_low_stock_threshold : '5';
    var fAr = document.getElementById('invoice_footer_ar');
    var fEn = document.getElementById('invoice_footer_en');
    if (fAr) fAr.value = d.invoice_footer_ar || '';
    if (fEn) fEn.value = d.invoice_footer_en || '';
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
        vat_rate: (document.getElementById('vat_rate') ? parseFloat(document.getElementById('vat_rate').value) : 0) || 0,
        low_stock_threshold: (document.getElementById('low_stock_threshold') ? parseInt(document.getElementById('low_stock_threshold').value, 10) : 3) || 3,
        customer_low_stock_threshold: (document.getElementById('customer_low_stock_threshold') ? parseInt(document.getElementById('customer_low_stock_threshold').value, 10) : 5) || 5,
        invoice_footer_ar: (document.getElementById('invoice_footer_ar') ? document.getElementById('invoice_footer_ar').value.trim() : ''),
        invoice_footer_en: (document.getElementById('invoice_footer_en') ? document.getElementById('invoice_footer_en').value.trim() : ''),
        payment_online_enabled: document.getElementById('payment_online_enabled') && document.getElementById('payment_online_enabled').checked ? 1 : 0
    });
    if (res.success) {
        await loadCompanySettings();
    }
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ'));
}

document.getElementById('company_logo_file').addEventListener('change', async function () {
    const file = this.files[0];
    if (!file) return;
    if (file.size > 4 * 1024 * 1024) { alert('حجم الملف أكبر من 4 ميجا'); return; }
    const fd = new FormData();
    fd.append('logo', file);
    try {
        const r = await fetch('/admin/api/settings/upload-company-logo.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (j.success && j.filename) {
            document.getElementById('company_logo').value = j.filename;
            const prev = document.getElementById('company_logo_preview');
            const wrap = document.getElementById('company_logo_preview_wrap');
            if (prev) prev.src = '/uploads/company/' + j.filename;
            if (wrap) wrap.style.display = '';
            alert('تم رفع الشعار — اضغط «حفظ» لتثبيت الاسم.');
        } else {
            alert(j.message || 'فشل رفع الشعار');
        }
    } catch (e) { alert(e.message || 'خطأ في الرفع'); }
});

// عند التحميل أظهر المعاينة لو موجود
(function () {
    var logo = document.getElementById('company_logo').value.trim();
    if (logo) {
        var wrap = document.getElementById('company_logo_preview_wrap');
        var img = document.getElementById('company_logo_preview');
        if (wrap) wrap.style.display = '';
        if (img && !img.src) img.src = '/uploads/company/' + logo;
    }
})();
</script>
