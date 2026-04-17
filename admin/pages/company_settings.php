<?php
$pdo = db();
$hasTable = (bool)$pdo->query("SHOW TABLES LIKE 'company_settings'")->fetchColumn();
?>
<div class="page-title">
    <h1>بيانات الشركة</h1>
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
    </div>
    <div class="actions" style="margin-top:14px;">
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
}

async function saveCompanySettings() {
    const res = await postJSON('/admin/api/settings/company.php', {
        action: 'save',
        company_name_ar: document.getElementById('company_name_ar').value.trim(),
        company_name_en: document.getElementById('company_name_en').value.trim(),
        company_logo: document.getElementById('company_logo').value.trim(),
        commercial_register: document.getElementById('commercial_register').value.trim(),
        phones: document.getElementById('phones').value.trim(),
        address: document.getElementById('address').value.trim()
    });
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ'));
}

loadCompanySettings();
</script>
