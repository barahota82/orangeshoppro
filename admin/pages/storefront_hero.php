<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/storefront_hero.php';
require_once __DIR__ . '/../../includes/orange_content_locale_field.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'storefront_copy_lines');
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$copyScoped = orange_storefront_copy_has_country_column($pdo);
$ctxCountryRow = orange_country_row_by_id($pdo, $ctxCountryId, false);
$ctxCountryLabel = trim((string) ($ctxCountryRow['name_ar'] ?? ''));
if ($ctxCountryLabel === '' && $ctxCountryRow !== null) {
    $ctxCountryLabel = trim((string) ($ctxCountryRow['name_en'] ?? ''));
}
if ($ctxCountryLabel === '') {
    $ctxCountryLabel = orange_countries_display_code(orange_admin_context_country_code($pdo));
}

/** @var list<array<string, mixed>> $heroLines */
$heroLines = [];
if ($hasTable) {
    if ($copyScoped && $ctxCountryId > 0) {
        $qh = $pdo->prepare(
            "SELECT * FROM storefront_copy_lines WHERE country_id = ? AND scope = 'home_hero' ORDER BY sort_order ASC, id ASC"
        );
        $qh->execute([$ctxCountryId]);
        $heroLines = $qh->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $qh = $pdo->query(
            "SELECT * FROM storefront_copy_lines WHERE scope = 'home_hero' ORDER BY sort_order ASC, id ASC"
        );
        $heroLines = $qh ? ($qh->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
/** @var array<string, mixed>|null $heroEdit */
$heroEdit = null;
if ($editId > 0 && $hasTable) {
    if ($copyScoped && $ctxCountryId > 0) {
        $st = $pdo->prepare('SELECT * FROM storefront_copy_lines WHERE id = ? AND country_id = ? AND scope = ? LIMIT 1');
        $st->execute([$editId, $ctxCountryId, 'home_hero']);
    } else {
        $st = $pdo->prepare('SELECT * FROM storefront_copy_lines WHERE id = ? AND scope = ? LIMIT 1');
        $st->execute([$editId, 'home_hero']);
    }
    $er = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($er)) {
        $heroEdit = $er;
    }
}

$heroEditActive = $heroEdit ? (int) ($heroEdit['is_active'] ?? 1) : 1;
$localeReady = false;
$localeRoles = ['mode' => 'legacy_unconfigured', 'base' => null, 'content' => [], 'admin_ui' => [], 'customer' => []];
$localeActive = [];
$heroLocaleView = null;
if ($hasTable && $ctxCountryId > 0) {
    try {
        $localeReady = orange_content_locale_screen_ready($pdo, $ctxCountryId);
        if ($localeReady) {
            $localeRoles = orange_country_locale_roles_read($pdo, $ctxCountryId);
            $localeActive = orange_language_reference_active_codes($pdo);
            if (is_array($heroEdit)) {
                $heroLocaleView = orange_content_locale_field_read($pdo, $ctxCountryId, 'copy_line', (int) $heroEdit['id'], $heroEdit);
            }
        }
    } catch (Throwable $e) {
        $localeReady = false;
    }
}
$heroLocaleBoot = [
    'ready' => $localeReady,
    'roles' => $localeRoles,
    'active' => $localeActive,
    'record' => [
        'id' => $heroEdit ? (int) $heroEdit['id'] : 0,
        'base_text' => is_array($heroLocaleView) ? (string) ($heroLocaleView['base_text'] ?? '') : '',
        'locales' => is_array($heroLocaleView) ? ($heroLocaleView['locales'] ?? []) : [],
    ],
];
?>
<div class="page-title">
    <h1>بانر الصفحة الرئيسية</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>storefront_copy_lines</code> غير موجود. حدّث المخطط عبر تشغيل الموقع أو لوحة الإدارة.</div>
</div>
<?php endif; ?>

<div class="card">
    <p class="card-hint" style="margin:0;">شعار الهيدر يبقى في شاشة هوية العلامة. صفوف الشعار القديمة محفوظة ولا تُحرَّر أو تُحذف من هذه الشاشة.</p>
</div>

<div class="card">
    <h3>جمل الـ hero — الصفحة الرئيسية</h3>
    <input type="hidden" id="hero_line_id" value="<?php echo $heroEdit ? (int) $heroEdit['id'] : ''; ?>">
    <div class="form-grid" style="margin-top:1rem;">
        <div>
            <label>ترتيب العرض</label>
            <?php if ($heroEdit): ?>
                <input type="number" value="<?php echo (int) ($heroEdit['sort_order'] ?? 0); ?>" disabled title="لتغيير الترتيب استخدم أزرار أعلى/أسفل في الجدول" style="opacity:0.85;">
            <?php else: ?>
                <input type="text" value="تلقائي — تُضاف في نهاية القائمة" disabled style="opacity:0.85;">
            <?php endif; ?>
        </div>
        <div>
            <label>حالة الظهور</label>
            <select id="hero_is_active">
                <option value="1" <?php echo $heroEditActive === 1 ? ' selected' : ''; ?>>ظاهر للزوار</option>
                <option value="0" <?php echo $heroEditActive === 0 ? ' selected' : ''; ?>>مخفي (لا يُعرض في المتجر)</option>
            </select>
        </div>
        <?php if ($localeReady): ?>
        <div style="grid-column:1 / -1;"><div id="hero-locale-app"></div></div>
        <?php else: ?>
        <div><label>عربي</label><input type="text" id="hero_text_ar" maxlength="500" autocomplete="off" value="<?php echo $heroEdit ? htmlspecialchars((string) ($heroEdit['text_ar'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>English</label><input type="text" id="hero_text_en" maxlength="500" autocomplete="off" value="<?php echo $heroEdit ? htmlspecialchars((string) ($heroEdit['text_en'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>Filipino</label><input type="text" id="hero_text_fil" maxlength="500" autocomplete="off" value="<?php echo $heroEdit ? htmlspecialchars((string) ($heroEdit['text_fil'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>Hindi</label><input type="text" id="hero_text_hi" maxlength="500" autocomplete="off" value="<?php echo $heroEdit ? htmlspecialchars((string) ($heroEdit['text_hi'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <?php endif; ?>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <button type="button" onclick="saveHeroCopyLine()" <?php echo !$hasTable ? 'disabled' : ''; ?>><?php echo $heroEdit ? 'حفظ التعديلات' : 'إضافة جملة'; ?></button>
        <?php if (!$localeReady): ?>
        <button type="button" class="btn btn-secondary" onclick="translateHeroFromArabic()" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة من العربي</button>
        <?php endif; ?>
        <?php if ($heroEdit): ?>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=storefront_hero'), ENT_QUOTES, 'UTF-8'); ?>">إلغاء التعديل</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3>قائمة جمل الـ hero</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>ترتيب</th>
                    <th>أعلى / أسفل</th>
                    <th>معاينة نص</th>
                    <th>الحالة</th>
                    <th>إخفاء / تفعيل</th>
                    <th>تعديل</th>
                    <th>حذف</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($heroLines as $gi => $row): ?>
                <tr>
                    <td><?php echo (int) $row['id']; ?></td>
                    <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-secondary" style="font-size:0.8rem;padding:0.2rem 0.45rem;" onclick="moveCopyLine('home_hero', <?php echo (int) $row['id']; ?>, 'up')" <?php echo $gi === 0 ? 'disabled' : ''; ?>>أعلى</button>
                        <button type="button" class="btn btn-secondary" style="font-size:0.8rem;padding:0.2rem 0.45rem;" onclick="moveCopyLine('home_hero', <?php echo (int) $row['id']; ?>, 'down')" <?php echo $gi === count($heroLines) - 1 ? 'disabled' : ''; ?>>أسفل</button>
                    </td>
                    <td><?php echo htmlspecialchars(orange_storefront_copy_preview_snippet($row), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['is_active'] ?? 0) === 1 ? 'نشط' : 'مخفي'; ?></td>
                    <td>
                        <button type="button" class="btn btn-secondary" style="font-size:0.85rem;padding:0.25rem 0.5rem;" onclick="toggleCopyLine('home_hero', <?php echo (int) $row['id']; ?>, <?php echo (int) ($row['is_active'] ?? 0); ?>)"><?php echo (int) ($row['is_active'] ?? 0) === 1 ? 'إخفاء' : 'تفعيل'; ?></button>
                    </td>
                    <td><a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=storefront_hero&edit=' . (int) $row['id']), ENT_QUOTES, 'UTF-8'); ?>">تعديل</a></td>
                    <td><button type="button" class="btn btn-secondary" style="font-size:0.85rem;padding:0.25rem 0.5rem;" onclick="deleteCopyLine('home_hero', <?php echo (int) $row['id']; ?>)">حذف</button></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($heroLines === []): ?>
                <tr><td colspan="8" class="card-hint">لا توجد جمل بعد. أضف جملة من النموذج أعلاه.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($localeReady): ?>
<script src="<?php echo htmlspecialchars(storefront_public_path('/admin/assets/js/content_locale_panel.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path('/admin/assets/js/content_locale_text_bind.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
<script>
var HERO_LOCALE = <?php echo json_encode($heroLocaleBoot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var heroLocaleApi = null;
let heroArTimer = null;
let heroEnTimer = null;

async function translateNamesPayload(nameAr, nameEn, forceFromArabic) {
    const payload = {
        name_ar: nameAr,
        name_en: forceFromArabic ? '' : nameEn
    };
    const res = await postJSON('/admin/api/translate/names.php', payload);
    return res;
}

async function translateHeroFromArabic(opts) {
    const silent = !!(opts && opts.silent);
    const forceFromArabic = !!(opts && opts.forceFromArabic);
    try {
        const res = await translateNamesPayload(
            document.getElementById('hero_text_ar').value.trim(),
            document.getElementById('hero_text_en').value.trim(),
            forceFromArabic
        );
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return false;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('hero_text_en').value = t.name_en;
        if (t.name_fil) document.getElementById('hero_text_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('hero_text_hi').value = t.name_hi;
        return true;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
        return false;
    }
}

function scheduleHeroFromAr() {
    const nameAr = document.getElementById('hero_text_ar').value.trim();
    if (!nameAr) {
        document.getElementById('hero_text_en').value = '';
        document.getElementById('hero_text_fil').value = '';
        document.getElementById('hero_text_hi').value = '';
        return;
    }
    clearTimeout(heroArTimer);
    heroArTimer = setTimeout(function () { translateHeroFromArabic({ silent: true, forceFromArabic: true }); }, 700);
}

function scheduleHeroFromEn() {
    const nameEn = document.getElementById('hero_text_en').value.trim();
    if (!nameEn) return;
    clearTimeout(heroEnTimer);
    heroEnTimer = setTimeout(function () { translateHeroFromArabic({ silent: true, forceFromArabic: false }); }, 600);
}

function parseActive(id) {
    var el = document.getElementById(id);
    return el && el.value === '0' ? 0 : 1;
}

async function saveHeroCopyLine() {
    var idEl = document.getElementById('hero_line_id');
    var id = idEl && idEl.value ? parseInt(idEl.value, 10) : 0;
    var payload = {
        action: 'save',
        scope: 'home_hero',
        id: id > 0 ? id : 0,
        is_active: parseActive('hero_is_active')
    };
    if (HERO_LOCALE && HERO_LOCALE.ready && heroLocaleApi) {
        var text = heroLocaleApi.getPayload();
        payload.base_text = text.base_text || '';
        payload.locales = text.locales || {};
    } else {
        payload.text_ar = document.getElementById('hero_text_ar').value.trim();
        payload.text_en = document.getElementById('hero_text_en').value.trim();
        payload.text_fil = document.getElementById('hero_text_fil').value.trim();
        payload.text_hi = document.getElementById('hero_text_hi').value.trim();
    }
    var res = await postJSON('/admin/api/settings/storefront_copy_lines.php', payload);
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}

async function toggleCopyLine(scope, id, currentlyActive) {
    var next = currentlyActive ? 0 : 1;
    var res = await postJSON('/admin/api/settings/storefront_copy_lines.php', {
        action: 'toggle',
        scope: scope,
        id: id,
        is_active: next
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}

async function deleteCopyLine(scope, id) {
    if (!confirm('حذف هذه الجملة نهائياً؟')) return;
    var res = await postJSON('/admin/api/settings/storefront_copy_lines.php', {
        action: 'delete',
        scope: scope,
        id: id
    });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}

async function moveCopyLine(scope, id, direction) {
    var res = await postJSON('/admin/api/settings/storefront_copy_lines.php', {
        action: 'move',
        scope: scope,
        id: id,
        direction: direction
    });
    if (res.success) {
        location.reload();
        return;
    }
    if (res.message === 'لا يمكن النقل في هذا الاتجاه') {
        return;
    }
    alert(res.message || 'فشل تحديث الترتيب');
}

if (HERO_LOCALE && HERO_LOCALE.ready && window.OrangeContentLocaleTextBind) {
    heroLocaleApi = OrangeContentLocaleTextBind.mount(document.getElementById('hero-locale-app'), {
        roles: HERO_LOCALE.roles,
        active: HERO_LOCALE.active,
        record: HERO_LOCALE.record
    });
} else {
    var heroAr = document.getElementById('hero_text_ar');
    var heroEn = document.getElementById('hero_text_en');
    if (heroAr) heroAr.addEventListener('input', scheduleHeroFromAr);
    if (heroEn) heroEn.addEventListener('input', scheduleHeroFromEn);
}
</script>
