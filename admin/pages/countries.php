<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/country_provision.php';
require_once __DIR__ . '/../../includes/orange_department_integration.php';

$pdo = orange_admin_page_pdo();
$countries = orange_countries_admin_list($pdo);
$hasTable = orange_table_exists($pdo, 'countries');
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
$editProvision = null;
foreach ($countries as $c) {
    if ($editId > 0 && (int) ($c['id'] ?? 0) === $editId) {
        $editRow = $c;
        $editProvision = orange_country_provision_status($pdo, $editId);
        break;
    }
}
?>
<div class="page-title">
    <h1>الدول</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>countries</code> غير جاهز — حدّث المخطط من السيرفر.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3><?php echo $editRow ? 'تعديل دولة' : 'إضافة دولة'; ?></h3>
    <input type="hidden" id="ctry_id" value="<?php echo $editRow ? (int) $editRow['id'] : '0'; ?>">
    <div class="form-grid ctry-form-grid">
        <div class="ctry-ar">
            <label for="ctry_name_ar">الاسم العربي <span style="color:#b45309;">*</span></label>
            <input type="text" id="ctry_name_ar" maxlength="191" autocomplete="off"
                value="<?php echo $editRow ? htmlspecialchars((string) $editRow['name_ar'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div class="ctry-en">
            <label for="ctry_name_en">English</label>
            <input type="text" id="ctry_name_en" dir="ltr" lang="en" maxlength="191" autocomplete="off"
                value="<?php echo $editRow ? htmlspecialchars((string) $editRow['name_en'], ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div class="ctry-code">
            <label for="ctry_code">رمز الدولة</label>
            <input type="text" id="ctry_code" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" maxlength="8"
                autocomplete="off" readonly tabindex="-1" aria-readonly="true"
                value="<?php echo $editRow ? htmlspecialchars(orange_countries_display_code((string) $editRow['code']), ENT_QUOTES, 'UTF-8') : ''; ?>">
        </div>
        <div class="ctry-currency">
            <label for="ctry_currency">رمز العملة</label>
            <input type="text" id="ctry_currency" class="admin-sort-field admin-sort-field--muted" dir="ltr" maxlength="8"
                autocomplete="off" readonly tabindex="-1" aria-readonly="true"
                value="<?php
                if ($editRow) {
                    $autoCur = orange_countries_currency_for_code((string) ($editRow['code'] ?? ''));
                    echo htmlspecialchars($autoCur !== '' ? $autoCur : (string) ($editRow['currency_code'] ?? ''), ENT_QUOTES, 'UTF-8');
                }
                ?>">
        </div>
        <div class="ctry-timezone">
            <label for="ctry_timezone">المنطقة الزمنية (IANA) <span style="color:#b45309;">*</span></label>
            <input type="text" id="ctry_timezone" dir="ltr" lang="en" maxlength="64" autocomplete="off"
                placeholder="Asia/Kuwait"
                value="<?php echo $editRow ? htmlspecialchars((string) ($editRow['timezone'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
            <p class="card-hint" style="margin:6px 0 0;font-size:12px;line-height:1.45;">مثال: Asia/Kuwait أو Africa/Cairo أو Europe/London — من إعدادات الدولة وليس من كود التطبيق.</p>
        </div>
        <div class="ctry-sort">
            <label for="ctry_sort">الترتيب</label>
            <input type="number" id="ctry_sort" class="admin-sort-field admin-sort-field--muted"
                value="<?php echo $editRow ? (int) ($editRow['sort_order'] ?? 0) : (int) orange_countries_next_sort_order($pdo); ?>"
                disabled tabindex="-1" aria-readonly="true">
        </div>
        <div class="ctry-active">
            <label for="ctry_is_active" class="ctry-active-label">
                <input type="checkbox" id="ctry_is_active" <?php echo !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? 'checked' : ''; ?>>
                نشطة في واجهة المتجر
            </label>
        </div>
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;">
        <button type="button" onclick="saveCountry()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="translateCountryFromAr()" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetCountryForm()">جديد</button>
        <?php if ($editRow): ?>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=countries'), ENT_QUOTES, 'UTF-8'); ?>">إلغاء التعديل</a>
        <button type="button" class="btn-secondary" onclick="runCountryProvision(<?php echo (int) $editRow['id']; ?>)">تهيئة تشغيلية كاملة</button>
        <?php endif; ?>
    </div>
    <?php if ($editRow && is_array($editProvision)): ?>
    <div class="ctry-provision-box" style="margin-top:16px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;">
        <strong>جاهزية شاشات الأدمن</strong>
        <ul class="ctry-provision-list" style="margin:8px 0 0;padding-right:18px;line-height:1.7;">
            <li><?php echo !empty($editProvision['warehouse']) ? '✓' : '○'; ?> مخزن</li>
            <li><?php echo (int) ($editProvision['channels_count'] ?? 0) > 0 ? '✓' : '○'; ?> قنوات (<?php echo (int) ($editProvision['channels_count'] ?? 0); ?> — تُنشأ يدوياً من شاشة قنوات العملاء)</li>
            <li><?php echo (int) ($editProvision['products_count'] ?? 0) > 0 ? '✓' : '○'; ?> منتجات (<?php echo (int) ($editProvision['products_count'] ?? 0); ?>)</li>
            <li><?php echo (int) ($editProvision['accounts_count'] ?? 0) > 0 ? '✓' : '○'; ?> دليل حسابات (<?php echo (int) ($editProvision['accounts_count'] ?? 0); ?>)</li>
            <li><?php echo (int) ($editProvision['gl_settings_count'] ?? 0) > 0 ? '✓' : '○'; ?> إعدادات GL (<?php echo (int) ($editProvision['gl_settings_count'] ?? 0); ?>)</li>
            <li><?php echo !empty($editProvision['has_governorate']) ? '✓' : '○'; ?> محافظة توصيل</li>
        </ul>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>القائمة</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>رمز</th>
                    <th>عربي</th>
                    <th>English</th>
                    <th>عملة</th>
                    <th>Timezone</th>
                    <th>ترتيب</th>
                    <th>نشطة</th>
                    <th>جاهزية</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($countries as $row):
                    $rowProv = orange_country_provision_status($pdo, (int) ($row['id'] ?? 0));
                    $readyCount = 0;
                    if (!empty($rowProv['warehouse'])) {
                        $readyCount++;
                    }
                    if ((int) ($rowProv['channels_count'] ?? 0) > 0) {
                        $readyCount++;
                    }
                    if ((int) ($rowProv['accounts_count'] ?? 0) > 0) {
                        $readyCount++;
                    }
                    if ((int) ($rowProv['products_count'] ?? 0) > 0) {
                        $readyCount++;
                    }
                    $rowTz = trim((string) ($row['timezone'] ?? ''));
                    ?>
                <tr>
                    <td><?php echo (int) $row['id']; ?></td>
                    <td dir="ltr"><code><?php echo htmlspecialchars(orange_countries_display_code((string) $row['code']), ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo htmlspecialchars((string) $row['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) $row['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) $row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><code><?php echo $rowTz !== '' ? htmlspecialchars($rowTz, ENT_QUOTES, 'UTF-8') : '—'; ?></code></td>
                    <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                    <td><?php echo (int) ($row['is_active'] ?? 0) === 1 ? 'نعم' : 'لا'; ?></td>
                    <td><?php echo $readyCount; ?>/4</td>
                    <td><a class="btn-secondary" href="?page=countries&amp;edit=<?php echo (int) $row['id']; ?>">تعديل</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var ctryArTimer = null;
var ctryEnTimer = null;
var ctryDeriveTimer = null;
var ctryIsNew = <?php echo $editRow ? 'false' : 'true'; ?>;

async function deriveCountryFields() {
    if (!ctryIsNew) {
        return;
    }
    var ar = document.getElementById('ctry_name_ar').value.trim();
    var en = document.getElementById('ctry_name_en').value.trim();
    var codeEl = document.getElementById('ctry_code');
    var curEl = document.getElementById('ctry_currency');
    var sortEl = document.getElementById('ctry_sort');
    if (!ar) {
        if (codeEl) codeEl.value = '';
        if (curEl) curEl.value = '';
        return;
    }
    try {
        var res = await postJSON('/admin/api/countries/manage.php', {
            action: 'derive',
            name_ar: ar,
            name_en: en
        });
        if (!res || !res.success) {
            return;
        }
        if (codeEl) codeEl.value = res.code ? res.code : '';
        if (curEl) curEl.value = res.currency_code ? res.currency_code : '';
        if (sortEl && res.next_sort_order) {
            sortEl.value = String(res.next_sort_order);
        }
    } catch (e) {
        /* صامت في المعاينة */
    }
}

function scheduleCountryDerive() {
    if (!ctryIsNew) return;
    clearTimeout(ctryDeriveTimer);
    ctryDeriveTimer = setTimeout(function () {
        deriveCountryFields();
    }, 350);
}

async function translateCountryNames(opts) {
    opts = opts || {};
    var silent = !!opts.silent;
    var forceFromArabic = !!opts.forceFromArabic;
    try {
        var res = await postJSON('/admin/api/translate/names.php', {
            name_ar: document.getElementById('ctry_name_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('ctry_name_en').value.trim()
        });
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        var t = res.translations || {};
        if (t.name_en) document.getElementById('ctry_name_en').value = t.name_en;
        await deriveCountryFields();
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة');
    }
}

function scheduleCountryFromAr() {
    var ar = document.getElementById('ctry_name_ar').value.trim();
    if (!ar) {
        document.getElementById('ctry_name_en').value = '';
        document.getElementById('ctry_code').value = '';
        document.getElementById('ctry_currency').value = '';
        return;
    }
    clearTimeout(ctryArTimer);
    ctryArTimer = setTimeout(function () {
        translateCountryNames({ silent: true, forceFromArabic: true });
    }, 700);
}

function scheduleCountryFromEn() {
    var en = document.getElementById('ctry_name_en').value.trim();
    if (!en) return;
    clearTimeout(ctryEnTimer);
    ctryEnTimer = setTimeout(function () {
        translateCountryNames({ silent: true, forceFromArabic: false });
    }, 600);
}

async function translateCountryFromAr() {
    await translateCountryNames({ silent: false, forceFromArabic: true });
    await deriveCountryFields();
}

function resetCountryForm() {
    window.location.href = <?php echo json_encode(storefront_public_path('/admin/index.php?page=countries'), JSON_UNESCAPED_UNICODE); ?>;
}
function showProvisionReport(res) {
    var lines = (res && res.provision_lines) ? res.provision_lines : [];
    var msg = (res && res.message) ? res.message : '';
    if (lines.length) {
        alert(msg + '\n\n' + lines.join('\n'));
    } else {
        alert(msg || (res.success ? 'تم' : 'فشل'));
    }
}

async function runCountryProvision(countryId) {
    if (!countryId) return;
    var res = await postJSON('/admin/api/countries/manage.php', {
        action: 'provision',
        country_id: countryId
    });
    showProvisionReport(res);
    if (res.success) {
        window.location.reload();
    }
}

async function saveCountry() {
    var tz = document.getElementById('ctry_timezone').value.trim();
    if (!tz) {
        alert('المنطقة الزمنية (IANA) مطلوبة');
        return;
    }
    var res = await postJSON('/admin/api/countries/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('ctry_id').value, 10) || 0,
        name_ar: document.getElementById('ctry_name_ar').value.trim(),
        name_en: document.getElementById('ctry_name_en').value.trim(),
        timezone: tz,
        is_active: document.getElementById('ctry_is_active').checked ? 1 : 0
    });
    showProvisionReport(res);
    if (res.success) {
        window.location.reload();
    }
}

document.getElementById('ctry_name_ar').addEventListener('input', scheduleCountryFromAr);
document.getElementById('ctry_name_en').addEventListener('input', function () {
    scheduleCountryFromEn();
    scheduleCountryDerive();
});
</script>
<?php if ($editRow): ?>
<?php $ctryLocale = orange_department_integration_page_state($pdo, (int) $editRow['id']); ?>
<div class="card" id="country-locale-roles">
    <h3>أدوار لغات المحتوى</h3>
    <p class="card-hint"><?php echo htmlspecialchars((string) $ctryLocale['notice'], ENT_QUOTES, 'UTF-8'); ?></p>
    <p class="card-hint">حفظ الاختيار لا يفعّل قائمة لغات الزائر ولا يغيّر لغة واجهة البرنامج، ولا يفعّل لغة خامسة للعملاء.</p>
    <?php if (empty($ctryLocale['tables_present'])): ?>
    <p>لا يُعرض نموذج الحفظ لأن الجداول غير موجودة. فتح الصفحة لا ينشئها ولا يفترض أن الدولة عربية.</p>
    <?php elseif ($ctryLocale['active'] === []): ?>
    <p>مرجع اللغات لا يضم لغة نشطة يمكن اختيارها. لا يُفترض العربية.</p>
    <?php else: ?>
    <div id="locale-role-grid" style="direction:rtl;">
        <?php foreach ($ctryLocale['active'] as $localeCode): ?>
        <fieldset style="margin:0 0 12px;padding:8px 12px;">
            <legend><?php echo htmlspecialchars((string) $localeCode, ENT_QUOTES, 'UTF-8'); ?></legend>
            <label><input type="checkbox" data-locale="<?php echo htmlspecialchars((string) $localeCode, ENT_QUOTES, 'UTF-8'); ?>" data-role="base" <?php echo ($ctryLocale['base'] ?? '') === $localeCode ? 'checked' : ''; ?>> الأساس</label>
            <label><input type="checkbox" data-locale="<?php echo htmlspecialchars((string) $localeCode, ENT_QUOTES, 'UTF-8'); ?>" data-role="content" <?php echo in_array($localeCode, $ctryLocale['content'], true) ? 'checked' : ''; ?>> المحتوى</label>
            <label><input type="checkbox" data-locale="<?php echo htmlspecialchars((string) $localeCode, ENT_QUOTES, 'UTF-8'); ?>" data-role="admin_ui" <?php echo in_array($localeCode, $ctryLocale['admin_ui'], true) ? 'checked' : ''; ?>> واجهة الإدارة</label>
            <label><input type="checkbox" data-locale="<?php echo htmlspecialchars((string) $localeCode, ENT_QUOTES, 'UTF-8'); ?>" data-role="customer" <?php echo in_array($localeCode, $ctryLocale['customer'], true) ? 'checked' : ''; ?>> العميل</label>
        </fieldset>
        <?php endforeach; ?>
    </div>
    <button type="button" id="locale-roles-save">حفظ أدوار اللغات</button>
    <script>
    document.getElementById('locale-roles-save').addEventListener('click', async function () {
        var assignments = [];
        document.querySelectorAll('#locale-role-grid input[type="checkbox"]:checked').forEach(function (box) {
            assignments.push({ locale: box.getAttribute('data-locale'), role: box.getAttribute('data-role') });
        });
        var res = await postJSON('/admin/api/countries/locale-roles.php', {
            action: 'save_roles',
            country_id: <?php echo (int) $editRow['id']; ?>,
            assignments: assignments
        });
        alert(res.message || (res.success ? 'تم' : 'فشل'));
        if (res.success) window.location.reload();
    });
    </script>
    <?php endif; ?>
</div>
<?php endif; ?>
<style>
.ctry-form-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    grid-template-areas:
        "ar ar ar ar ar ar ar ar ar ar ar ar"
        "active active currency currency code code en en en en sort sort"
        "tz tz tz tz tz tz tz tz tz tz tz tz";
    gap: 14px 18px;
    direction: ltr;
}
.ctry-form-grid .ctry-sort { grid-area: sort; }
.ctry-form-grid .ctry-code { grid-area: code; }
.ctry-form-grid .ctry-currency { grid-area: currency; }
.ctry-form-grid .ctry-timezone { grid-area: tz; }
.ctry-form-grid .ctry-active {
    grid-area: active;
    display: flex;
    align-items: flex-end;
}
.ctry-form-grid .ctry-ar { grid-area: ar; }
.ctry-form-grid .ctry-en { grid-area: en; }
.ctry-form-grid label,
.ctry-form-grid input { direction: rtl; text-align: right; }
.ctry-form-grid #ctry_name_en,
.ctry-form-grid #ctry_code,
.ctry-form-grid #ctry_currency,
.ctry-form-grid #ctry_timezone,
.ctry-form-grid #ctry_sort { direction: ltr; text-align: left; }
.ctry-form-grid .ctry-active-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    margin: 0;
    width: 100%;
    min-height: var(--input-min-h, 36px);
}
@media (max-width: 900px) {
    .ctry-form-grid {
        grid-template-columns: 1fr;
        grid-template-areas:
            "ar"
            "en"
            "sort"
            "code"
            "currency"
            "tz"
            "active";
    }
}
</style>
