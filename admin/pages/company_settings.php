<?php
$pdo = db();
$hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'company_settings'")->fetchColumn();
?>
<div class="page-title page-title--stacked">
    <h1>بيانات الشركة</h1>
    <p class="page-subtitle">الهوية والعنوان وبيانات الفاتورة الضريبية تظهر للعملاء والمستندات الرسمية.</p>
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
        invoice_footer: document.getElementById('invoice_footer').value.trim()
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ'));
}

loadCompanySettings();
</script>
