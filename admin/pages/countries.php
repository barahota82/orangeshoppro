<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$countries = orange_countries_admin_list($pdo);
$hasTable = orange_table_exists($pdo, 'countries');
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
foreach ($countries as $c) {
    if ($editId > 0 && (int) ($c['id'] ?? 0) === $editId) {
        $editRow = $c;
        break;
    }
}
?>
<div class="page-title page-title--stacked">
    <h1>الدول</h1>
    <p class="page-subtitle">أسواق المتجر: عملة، تفعيل للواجهة، وربط القنوات ومناطق التوصيل. حالياً يُفضّل إبقاء <strong>الكويت</strong> فقط نشطة حتى اكتمال التشغيل المحلي، ثم تفعيل مصر والإمارات والسعودية لاحقاً.</p>
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
        <div class="ctry-sort">
            <label for="ctry_sort">الترتيب</label>
            <input type="number" id="ctry_sort" class="admin-sort-field admin-sort-field--muted"
                value="<?php echo $editRow ? (int) ($editRow['sort_order'] ?? 0) : ''; ?>"
                disabled tabindex="-1" aria-readonly="true">
        </div>
        <div class="ctry-code">
            <label for="ctry_code">رمز الدولة</label>
            <input type="text" id="ctry_code" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" maxlength="8"
                autocomplete="off" readonly tabindex="-1" aria-readonly="true"
                value="<?php echo $editRow ? htmlspecialchars((string) $editRow['code'], ENT_QUOTES, 'UTF-8') : ''; ?>">
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
        <div class="ctry-active">
            <label for="ctry_is_active" class="ctry-active-label">
                <input type="checkbox" id="ctry_is_active" <?php echo !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? 'checked' : ''; ?>>
                نشطة في واجهة المتجر
            </label>
        </div>
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
    </div>
    <div class="admin-form-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;">
        <button type="button" onclick="saveCountry()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="translateCountryFromAr()" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة من العربي</button>
        <button type="button" class="btn-secondary" onclick="resetCountryForm()">جديد</button>
        <?php if ($editRow): ?>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=countries'), ENT_QUOTES, 'UTF-8'); ?>">إلغاء التعديل</a>
        <?php endif; ?>
    </div>
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
                    <th>ترتيب</th>
                    <th>نشطة</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($countries as $row): ?>
                <tr>
                    <td><?php echo (int) $row['id']; ?></td>
                    <td dir="ltr"><code><?php echo htmlspecialchars((string) $row['code'], ENT_QUOTES, 'UTF-8'); ?></code></td>
                    <td><?php echo htmlspecialchars((string) $row['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) $row['name_en'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) $row['currency_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                    <td><?php echo (int) ($row['is_active'] ?? 0) === 1 ? 'نعم' : 'لا'; ?></td>
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
        if (sortEl) sortEl.value = '';
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
        if (sortEl) {
            sortEl.value = (res.code && res.next_sort_order) ? String(res.next_sort_order) : '';
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
        document.getElementById('ctry_sort').value = '';
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
async function saveCountry() {
    var res = await postJSON('/admin/api/countries/manage.php', {
        action: 'save',
        id: parseInt(document.getElementById('ctry_id').value, 10) || 0,
        name_ar: document.getElementById('ctry_name_ar').value.trim(),
        name_en: document.getElementById('ctry_name_en').value.trim(),
        is_active: document.getElementById('ctry_is_active').checked ? 1 : 0
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
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
<style>
.ctry-form-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    grid-template-areas:
        "active active currency currency code code sort sort sort sort sort sort"
        "en en en en en en ar ar ar ar ar ar";
    gap: 14px 18px;
    direction: ltr;
}
.ctry-form-grid .ctry-sort { grid-area: sort; }
.ctry-form-grid .ctry-code { grid-area: code; }
.ctry-form-grid .ctry-currency { grid-area: currency; }
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
.ctry-form-grid #ctry_currency { direction: ltr; text-align: left; }
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
            "sort"
            "code"
            "currency"
            "active"
            "ar"
            "en";
    }
}
</style>
