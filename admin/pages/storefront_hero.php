<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'storefront_copy_lines');

/** @var list<array<string, mixed>> $heroLines */
$heroLines = [];
/** @var list<array<string, mixed>> $headerLines */
$headerLines = [];
if ($hasTable) {
    $qh = $pdo->query(
        "SELECT * FROM storefront_copy_lines WHERE scope = 'home_hero' ORDER BY sort_order ASC, id ASC"
    );
    $heroLines = $qh ? ($qh->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $qt = $pdo->query(
        "SELECT * FROM storefront_copy_lines WHERE scope = 'header_tagline' ORDER BY sort_order ASC, id ASC"
    );
    $headerLines = $qt ? ($qt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
/** @var array<string, mixed>|null $heroEdit */
$heroEdit = null;
/** @var array<string, mixed>|null $headerEdit */
$headerEdit = null;
if ($editId > 0 && $hasTable) {
    $st = $pdo->prepare('SELECT * FROM storefront_copy_lines WHERE id = ? LIMIT 1');
    $st->execute([$editId]);
    $er = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($er)) {
        if (($er['scope'] ?? '') === 'home_hero') {
            $heroEdit = $er;
        } elseif (($er['scope'] ?? '') === 'header_tagline') {
            $headerEdit = $er;
        }
    }
}

$heroEditActive = $heroEdit ? (int) ($heroEdit['is_active'] ?? 1) : 1;
$headerEditActive = $headerEdit ? (int) ($headerEdit['is_active'] ?? 1) : 1;

require_once __DIR__ . '/../../includes/storefront_hero.php';
?>
<div class="page-title page-title--stacked">
    <h1>بانر الصفحة الرئيسية</h1>
    <p class="page-subtitle">أضف جمل الـ hero والتناوب تحت الشعار في الهيدر: جدول في الأسفل، تعديل، حذف، وإخفاء/تفعيل. <strong>ترتيب العرض</strong> يُضبط تلقائياً عند الإضافة؛ لإعادة الترتيب استخدم «أعلى / أسفل» في الجدول. النص الظاهر للزائر حسب <strong>لغة واجهته</strong>.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>storefront_copy_lines</code> غير موجود. حدّث المخطط عبر تشغيل الموقع أو لوحة الإدارة.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>شعار الهيدر — جمل التناوب تحت الشعار</h3>
    <p class="card-hint" style="margin:0 0 0.75rem;">يظهر في شريط الهيدر تحت اسم المتجر. إن لم تُضف جمل نشطة يُستعاد النص من الإعداد القديم أو من الترجمة.</p>
    <input type="hidden" id="header_line_id" value="<?php echo $headerEdit ? (int) $headerEdit['id'] : ''; ?>">
    <div class="form-grid" style="margin-top:1rem;">
        <div>
            <label>ترتيب العرض</label>
            <?php if ($headerEdit): ?>
                <input type="number" value="<?php echo (int) ($headerEdit['sort_order'] ?? 0); ?>" disabled title="لتغيير الترتيب استخدم أزرار أعلى/أسفل في الجدول" style="opacity:0.85;">
            <?php else: ?>
                <input type="text" value="تلقائي — تُضاف في نهاية القائمة" disabled style="opacity:0.85;">
            <?php endif; ?>
        </div>
        <div>
            <label>حالة الظهور</label>
            <select id="header_is_active">
                <option value="1" <?php echo $headerEditActive === 1 ? ' selected' : ''; ?>>ظاهر للزوار</option>
                <option value="0" <?php echo $headerEditActive === 0 ? ' selected' : ''; ?>>مخفي (لا يُعرض في المتجر)</option>
            </select>
        </div>
        <div><label>عربي</label><input type="text" id="header_text_ar" maxlength="500" autocomplete="off" value="<?php echo $headerEdit ? htmlspecialchars((string) ($headerEdit['text_ar'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>English</label><input type="text" id="header_text_en" maxlength="500" autocomplete="off" value="<?php echo $headerEdit ? htmlspecialchars((string) ($headerEdit['text_en'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>Filipino</label><input type="text" id="header_text_fil" maxlength="500" autocomplete="off" value="<?php echo $headerEdit ? htmlspecialchars((string) ($headerEdit['text_fil'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>Hindi</label><input type="text" id="header_text_hi" maxlength="500" autocomplete="off" value="<?php echo $headerEdit ? htmlspecialchars((string) ($headerEdit['text_hi'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <button type="button" onclick="saveHeaderCopyLine()" <?php echo !$hasTable ? 'disabled' : ''; ?>><?php echo $headerEdit ? 'حفظ التعديلات' : 'إضافة جملة'; ?></button>
        <button type="button" class="btn btn-secondary" onclick="translateHeaderFromArabic()" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة من العربي</button>
        <?php if ($headerEdit): ?>
            <a class="btn btn-secondary" href="/admin/index.php?page=storefront_hero">إلغاء التعديل</a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3>قائمة جمل الهيدر</h3>
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
                <?php foreach ($headerLines as $hi => $row): ?>
                <tr>
                    <td><?php echo (int) $row['id']; ?></td>
                    <td><?php echo (int) ($row['sort_order'] ?? 0); ?></td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-secondary" style="font-size:0.8rem;padding:0.2rem 0.45rem;" onclick="moveCopyLine('header_tagline', <?php echo (int) $row['id']; ?>, 'up')" <?php echo $hi === 0 ? 'disabled' : ''; ?>>أعلى</button>
                        <button type="button" class="btn btn-secondary" style="font-size:0.8rem;padding:0.2rem 0.45rem;" onclick="moveCopyLine('header_tagline', <?php echo (int) $row['id']; ?>, 'down')" <?php echo $hi === count($headerLines) - 1 ? 'disabled' : ''; ?>>أسفل</button>
                    </td>
                    <td><?php echo htmlspecialchars(orange_storefront_copy_preview_snippet($row), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($row['is_active'] ?? 0) === 1 ? 'نشط' : 'مخفي'; ?></td>
                    <td>
                        <button type="button" class="btn btn-secondary" style="font-size:0.85rem;padding:0.25rem 0.5rem;" onclick="toggleCopyLine('header_tagline', <?php echo (int) $row['id']; ?>, <?php echo (int) ($row['is_active'] ?? 0); ?>)"><?php echo (int) ($row['is_active'] ?? 0) === 1 ? 'إخفاء' : 'تفعيل'; ?></button>
                    </td>
                    <td><a href="/admin/index.php?page=storefront_hero&amp;edit=<?php echo (int) $row['id']; ?>">تعديل</a></td>
                    <td><button type="button" class="btn btn-secondary" style="font-size:0.85rem;padding:0.25rem 0.5rem;" onclick="deleteCopyLine('header_tagline', <?php echo (int) $row['id']; ?>)">حذف</button></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($headerLines === []): ?>
                <tr><td colspan="8" class="card-hint">لا توجد جمل بعد. أضف جملة من النموذج أعلاه.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>جمل الـ hero — الصفحة الرئيسية</h3>
    <p class="card-hint" style="margin:0 0 0.75rem;">الجمل تتناوب في بانر الرئيسية حسب لغة الزائر. يُفضّل وجود جملتين على الأقل للتناوب السلس.</p>
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
        <div><label>عربي</label><input type="text" id="hero_text_ar" maxlength="500" autocomplete="off" value="<?php echo $heroEdit ? htmlspecialchars((string) ($heroEdit['text_ar'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>English</label><input type="text" id="hero_text_en" maxlength="500" autocomplete="off" value="<?php echo $heroEdit ? htmlspecialchars((string) ($heroEdit['text_en'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>Filipino</label><input type="text" id="hero_text_fil" maxlength="500" autocomplete="off" value="<?php echo $heroEdit ? htmlspecialchars((string) ($heroEdit['text_fil'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
        <div><label>Hindi</label><input type="text" id="hero_text_hi" maxlength="500" autocomplete="off" value="<?php echo $heroEdit ? htmlspecialchars((string) ($heroEdit['text_hi'] ?? ''), ENT_QUOTES, 'UTF-8') : ''; ?>"></div>
    </div>
    <div class="actions" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <button type="button" onclick="saveHeroCopyLine()" <?php echo !$hasTable ? 'disabled' : ''; ?>><?php echo $heroEdit ? 'حفظ التعديلات' : 'إضافة جملة'; ?></button>
        <button type="button" class="btn btn-secondary" onclick="translateHeroFromArabic()" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة من العربي</button>
        <?php if ($heroEdit): ?>
            <a class="btn btn-secondary" href="/admin/index.php?page=storefront_hero">إلغاء التعديل</a>
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
                    <td><a href="/admin/index.php?page=storefront_hero&amp;edit=<?php echo (int) $row['id']; ?>">تعديل</a></td>
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

<script>
let headerArTimer = null;
let headerEnTimer = null;
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

async function translateHeaderFromArabic(opts) {
    const silent = !!(opts && opts.silent);
    const forceFromArabic = !!(opts && opts.forceFromArabic);
    try {
        const res = await translateNamesPayload(
            document.getElementById('header_text_ar').value.trim(),
            document.getElementById('header_text_en').value.trim(),
            forceFromArabic
        );
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return false;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('header_text_en').value = t.name_en;
        if (t.name_fil) document.getElementById('header_text_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('header_text_hi').value = t.name_hi;
        return true;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
        return false;
    }
}

function scheduleHeaderFromAr() {
    const nameAr = document.getElementById('header_text_ar').value.trim();
    if (!nameAr) {
        document.getElementById('header_text_en').value = '';
        document.getElementById('header_text_fil').value = '';
        document.getElementById('header_text_hi').value = '';
        return;
    }
    clearTimeout(headerArTimer);
    headerArTimer = setTimeout(function () { translateHeaderFromArabic({ silent: true, forceFromArabic: true }); }, 700);
}

function scheduleHeaderFromEn() {
    const nameEn = document.getElementById('header_text_en').value.trim();
    if (!nameEn) return;
    clearTimeout(headerEnTimer);
    headerEnTimer = setTimeout(function () { translateHeaderFromArabic({ silent: true, forceFromArabic: false }); }, 600);
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

async function saveHeaderCopyLine() {
    var idEl = document.getElementById('header_line_id');
    var id = idEl && idEl.value ? parseInt(idEl.value, 10) : 0;
    var payload = {
        action: 'save',
        scope: 'header_tagline',
        id: id > 0 ? id : 0,
        is_active: parseActive('header_is_active'),
        text_ar: document.getElementById('header_text_ar').value.trim(),
        text_en: document.getElementById('header_text_en').value.trim(),
        text_fil: document.getElementById('header_text_fil').value.trim(),
        text_hi: document.getElementById('header_text_hi').value.trim()
    };
    var res = await postJSON('/admin/api/settings/storefront_copy_lines.php', payload);
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}

async function saveHeroCopyLine() {
    var idEl = document.getElementById('hero_line_id');
    var id = idEl && idEl.value ? parseInt(idEl.value, 10) : 0;
    var payload = {
        action: 'save',
        scope: 'home_hero',
        id: id > 0 ? id : 0,
        is_active: parseActive('hero_is_active'),
        text_ar: document.getElementById('hero_text_ar').value.trim(),
        text_en: document.getElementById('hero_text_en').value.trim(),
        text_fil: document.getElementById('hero_text_fil').value.trim(),
        text_hi: document.getElementById('hero_text_hi').value.trim()
    };
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

document.getElementById('header_text_ar').addEventListener('input', scheduleHeaderFromAr);
document.getElementById('header_text_en').addEventListener('input', scheduleHeaderFromEn);
document.getElementById('hero_text_ar').addEventListener('input', scheduleHeroFromAr);
document.getElementById('hero_text_en').addEventListener('input', scheduleHeroFromEn);
</script>
