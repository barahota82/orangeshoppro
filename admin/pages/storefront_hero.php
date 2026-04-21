<?php

$pdo = db();
orange_catalog_ensure_schema($pdo);
$hasTable = orange_table_exists($pdo, 'storefront_home_hero');
?>
<div class="page-title page-title--stacked">
    <h1>بانر الصفحة الرئيسية</h1>
    <p class="page-subtitle">ثلاث جمل تتناوب في الـ hero حسب لغة الزائر؛ وتحتها جمل التناوب تحت الشعار في شريط الهيدر (أربع لغات). إن تركت حقول لغة فارغة يُستبدل نصها من الترجمة الافتراضية.</p>
</div>

<?php if (!$hasTable): ?>
<div class="card">
    <div class="alert-error">جدول <code>storefront_home_hero</code> غير موجود. حدّث المخطط عبر تشغيل الموقع أو لوحة الإدارة.</div>
</div>
<?php endif; ?>

<div class="card">
    <h3>شعار الهيدر — تناوب تحت الشعار (الترتيب: عربي → English → Filipino → Hindi)</h3>
    <p class="page-subtitle" style="margin-top:0.35rem;">يظهر في جميع صفحات المتجر تحت الشعار. اترك الحقل فارغاً لاستخدام النص الافتراضي من الترجمة لتلك اللغة.</p>
    <div class="form-grid" style="margin-top:1rem;">
        <div><label>عربي</label><input type="text" id="header_tagline_ar" maxlength="500" autocomplete="off"></div>
        <div><label>English</label><input type="text" id="header_tagline_en" maxlength="500" autocomplete="off"></div>
        <div><label>Filipino</label><input type="text" id="header_tagline_fil" maxlength="500" autocomplete="off"></div>
        <div><label>Hindi</label><input type="text" id="header_tagline_hi" maxlength="500" autocomplete="off"></div>
    </div>
    <div class="admin-form-actions" style="margin-top:0.75rem;display:flex;flex-wrap:wrap;gap:10px;">
        <button type="button" class="btn-secondary" onclick="translateHeaderTaglinesFromArabic()" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة شعار الهيدر من العربي</button>
    </div>
</div>

<div class="card">
    <h3>الجمل الثلاث (عربي، English، Filipino، Hindi)</h3>
    <?php for ($line = 1; $line <= 3; $line++): ?>
    <div class="form-grid" style="margin-top:1rem;padding-top:1rem;border-top:<?php echo $line === 1 ? 'none' : '1px solid var(--admin-border, #e8e8e8)'; ?>;">
        <div style="grid-column:1/-1;"><strong>الجملة <?php echo (int) $line; ?></strong></div>
        <div><label>عربي</label><input type="text" id="line<?php echo $line; ?>_ar" maxlength="500" autocomplete="off"></div>
        <div><label>English</label><input type="text" id="line<?php echo $line; ?>_en" maxlength="500" autocomplete="off"></div>
        <div><label>Filipino</label><input type="text" id="line<?php echo $line; ?>_fil" maxlength="500" autocomplete="off"></div>
        <div><label>Hindi</label><input type="text" id="line<?php echo $line; ?>_hi" maxlength="500" autocomplete="off"></div>
    </div>
    <?php endfor; ?>
    <div class="admin-form-actions" style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:10px;">
        <button type="button" onclick="saveStorefrontHero()" <?php echo !$hasTable ? 'disabled' : ''; ?>>حفظ</button>
        <button type="button" class="btn-secondary" onclick="translateAllHeroFromArabic()" <?php echo !$hasTable ? 'disabled' : ''; ?>>ترجمة تلقائية من العربي لكل الجمل</button>
    </div>
</div>

<script>
const heroArTimers = { 1: null, 2: null, 3: null };
const heroEnTimers = { 1: null, 2: null, 3: null };
let headerArTimer = null;
let headerEnTimer = null;

async function translateHeroLine(line, opts = {}) {
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    const prefix = 'line' + line + '_';
    try {
        const payload = {
            name_ar: document.getElementById(prefix + 'ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById(prefix + 'en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return false;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById(prefix + 'en').value = t.name_en;
        if (t.name_fil) document.getElementById(prefix + 'fil').value = t.name_fil;
        if (t.name_hi) document.getElementById(prefix + 'hi').value = t.name_hi;
        return true;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
        return false;
    }
}

function scheduleHeroLineFromAr(line) {
    const arEl = document.getElementById('line' + line + '_ar');
    const nameAr = arEl.value.trim();
    if (!nameAr) {
        document.getElementById('line' + line + '_en').value = '';
        document.getElementById('line' + line + '_fil').value = '';
        document.getElementById('line' + line + '_hi').value = '';
        return;
    }
    clearTimeout(heroArTimers[line]);
    heroArTimers[line] = setTimeout(() => translateHeroLine(line, { silent: true, forceFromArabic: true }), 700);
}

function scheduleHeroLineFromEn(line) {
    const nameEn = document.getElementById('line' + line + '_en').value.trim();
    if (!nameEn) {
        return;
    }
    clearTimeout(heroEnTimers[line]);
    heroEnTimers[line] = setTimeout(() => translateHeroLine(line, { silent: true, forceFromArabic: false }), 600);
}

async function translateAllHeroFromArabic() {
    for (let line = 1; line <= 3; line++) {
        const ar = document.getElementById('line' + line + '_ar').value.trim();
        if (ar) {
            await translateHeroLine(line, { silent: false, forceFromArabic: true });
        }
    }
}

async function translateHeaderTaglineFromArabic(opts = {}) {
    const silent = !!opts.silent;
    const forceFromArabic = !!opts.forceFromArabic;
    try {
        const payload = {
            name_ar: document.getElementById('header_tagline_ar').value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById('header_tagline_en').value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            if (!silent) alert((res && res.message) ? res.message : 'فشل الترجمة');
            return false;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById('header_tagline_en').value = t.name_en;
        if (t.name_fil) document.getElementById('header_tagline_fil').value = t.name_fil;
        if (t.name_hi) document.getElementById('header_tagline_hi').value = t.name_hi;
        return true;
    } catch (e) {
        if (!silent) alert('فشل طلب الترجمة من السيرفر');
        return false;
    }
}

function scheduleHeaderTaglineFromAr() {
    const arEl = document.getElementById('header_tagline_ar');
    const nameAr = arEl.value.trim();
    if (!nameAr) {
        document.getElementById('header_tagline_en').value = '';
        document.getElementById('header_tagline_fil').value = '';
        document.getElementById('header_tagline_hi').value = '';
        return;
    }
    clearTimeout(headerArTimer);
    headerArTimer = setTimeout(() => translateHeaderTaglineFromArabic({ silent: true, forceFromArabic: true }), 700);
}

function scheduleHeaderTaglineFromEn() {
    const nameEn = document.getElementById('header_tagline_en').value.trim();
    if (!nameEn) {
        return;
    }
    clearTimeout(headerEnTimer);
    headerEnTimer = setTimeout(() => translateHeaderTaglineFromArabic({ silent: true, forceFromArabic: false }), 600);
}

async function translateHeaderTaglinesFromArabic() {
    const ar = document.getElementById('header_tagline_ar').value.trim();
    if (ar) {
        await translateHeaderTaglineFromArabic({ silent: false, forceFromArabic: true });
    }
}

async function loadStorefrontHero() {
    const res = await postJSON('/admin/api/settings/storefront_hero.php', { action: 'get' });
    if (!res.success) {
        alert(res.message || 'خطأ');
        return;
    }
    const d = res.data || {};
    ['ar', 'en', 'fil', 'hi'].forEach(function (lang) {
        const hid = 'header_tagline_' + lang;
        const el = document.getElementById(hid);
        if (el) {
            el.value = d[hid] || '';
        }
    });
    for (let line = 1; line <= 3; line++) {
        ['ar', 'en', 'fil', 'hi'].forEach(function (lang) {
            const id = 'line' + line + '_' + lang;
            const col = 'line_' + line + '_' + lang;
            document.getElementById(id).value = d[col] || '';
        });
    }
}

async function saveStorefrontHero() {
    const payload = { action: 'save' };
    ['ar', 'en', 'fil', 'hi'].forEach(function (lang) {
        const hid = 'header_tagline_' + lang;
        payload[hid] = document.getElementById(hid).value.trim();
    });
    for (let line = 1; line <= 3; line++) {
        ['ar', 'en', 'fil', 'hi'].forEach(function (lang) {
            const id = 'line' + line + '_' + lang;
            payload['line_' + line + '_' + lang] = document.getElementById(id).value.trim();
        });
    }
    const res = await postJSON('/admin/api/settings/storefront_hero.php', payload);
    alert(res.message || (res.success ? 'تم الحفظ' : 'فشل الحفظ'));
}

document.getElementById('header_tagline_ar').addEventListener('input', scheduleHeaderTaglineFromAr);
document.getElementById('header_tagline_en').addEventListener('input', scheduleHeaderTaglineFromEn);

for (let line = 1; line <= 3; line++) {
    document.getElementById('line' + line + '_ar').addEventListener('input', function () { scheduleHeroLineFromAr(line); });
    document.getElementById('line' + line + '_en').addEventListener('input', function () { scheduleHeroLineFromEn(line); });
}

loadStorefrontHero();
</script>
