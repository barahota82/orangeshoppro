<?php
/**
 * @var array<string,mixed> $orange_uc
 */
$secJ = $orange_uc['section_opts_json'] ?? '[]';
$catJ = $orange_uc['category_opts_json'] ?? '[]';
$ucNextDeptJ = json_encode($orange_uc['next_sort_by_department'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}';
$ucNextSecJ = json_encode($orange_uc['next_sort_by_section'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}';
$ucNextCatJ = json_encode($orange_uc['next_sort_by_category'] ?? [], JSON_UNESCAPED_UNICODE) ?: '{}';
?>
<script>
const ucSectionOptions = <?php echo $secJ; ?>;
const ucCategoryOptions = <?php echo $catJ; ?>;
const ucNextSortByDept = <?php echo $ucNextDeptJ; ?>;
const ucNextSortBySec = <?php echo $ucNextSecJ; ?>;
const ucNextSortByCat = <?php echo $ucNextCatJ; ?>;
let ucTimers = {};
let ucEnTimers = {};
let ucSaving = false;
const ucSlugManual = { sec: false, cat: false, sub: false };
let ucSlugSkipInputEvent = false;
const ucSlugRefreshTimers = {};

function ucSlugifyLabel(str) {
    return String(str || '')
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/[\s-]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function refreshUcSlug(which) {
    if (which !== 'sec' && ucSlugManual[which]) {
        return;
    }
    const map = { sec: 'uc_sec_', cat: 'uc_cat_', sub: 'uc_sub_' };
    const p = map[which];
    if (!p) {
        return;
    }
    const enEl = document.getElementById(p + 'name_en');
    const slugEl = document.getElementById(p + 'slug');
    if (!enEl || !slugEl || slugEl.disabled) {
        return;
    }
    const next = ucSlugifyLabel(enEl.value.trim());
    if (!next) {
        if (which === 'sec' || !ucSlugManual[which]) {
            ucSlugSkipInputEvent = true;
            slugEl.value = '';
            setTimeout(function () { ucSlugSkipInputEvent = false; }, 0);
        }
        return;
    }
    ucSlugSkipInputEvent = true;
    slugEl.value = next;
    setTimeout(function () { ucSlugSkipInputEvent = false; }, 0);
}

function scheduleUcSlugRefresh(which) {
    if (ucSlugRefreshTimers[which]) {
        clearTimeout(ucSlugRefreshTimers[which]);
    }
    ucSlugRefreshTimers[which] = setTimeout(function () { refreshUcSlug(which); }, 120);
}

function ucBindSlugAuto(which, prefix) {
    const slugEl = document.getElementById(prefix + 'slug');
    if (!slugEl) {
        return;
    }
    slugEl.addEventListener('input', function () {
        if (ucSlugSkipInputEvent) {
            return;
        }
        ucSlugManual[which] = slugEl.value.trim() !== '';
    });
}

function ucEnsureSlugBeforeSave(prefix, which) {
    const slugEl = document.getElementById(prefix + 'slug');
    if (!slugEl || slugEl.disabled) {
        return;
    }
    if (!slugEl.value.trim()) {
        ucSlugManual[which] = false;
        refreshUcSlug(which);
    }
}

function parseSortPayload(raw) {
    const t = String(raw || '').trim();
    return t === '' ? 0 : ((parseInt(t, 10) || 0));
}

function ucPickNextSort(map, rawId) {
    const n = parseInt(String(rawId || ''), 10) || 0;
    if (n <= 0) {
        return '';
    }
    if (map == null) {
        return '1';
    }
    const v = map[n] !== undefined && map[n] !== null ? map[n] : map[String(n)];
    const num = typeof v === 'number' ? v : parseInt(String(v || ''), 10);
    if (num > 0) {
        return String(num);
    }
    return '1';
}

function ucApplyNextSortForNewSec() {
    if ((parseInt(document.getElementById('uc_sec_id').value || '0', 10) || 0) > 0) {
        return;
    }
    const d = document.getElementById('uc_sec_department_id');
    const s = document.getElementById('uc_sec_sort');
    if (!d || !s || d.disabled) {
        return;
    }
    s.value = ucPickNextSort(ucNextSortByDept, d.value);
}

function ucTryAutoSingleSection() {
    const sel = document.getElementById('uc_cat_section_id');
    if (!sel || sel.disabled || sel.options.length !== 2) {
        return;
    }
    sel.selectedIndex = 1;
}

function ucApplyNextSortForNewCat() {
    if ((parseInt(document.getElementById('uc_cat_id').value || '0', 10) || 0) > 0) {
        return;
    }
    const sel = document.getElementById('uc_cat_section_id');
    const s = document.getElementById('uc_cat_sort');
    if (!sel || !s || sel.disabled) {
        return;
    }
    s.value = ucPickNextSort(ucNextSortBySec, sel.value);
}

function ucTryAutoSingleSubcategoryParent() {
    const sel = document.getElementById('uc_sub_category_id');
    if (!sel || sel.disabled || sel.options.length !== 2) {
        return;
    }
    sel.selectedIndex = 1;
}

function ucApplyNextSortForNewSub() {
    if ((parseInt(document.getElementById('uc_sub_id').value || '0', 10) || 0) > 0) {
        return;
    }
    const sel = document.getElementById('uc_sub_category_id');
    const s = document.getElementById('uc_sub_sort');
    if (!sel || !s || sel.disabled) {
        return;
    }
    s.value = ucPickNextSort(ucNextSortByCat, sel.value);
}

async function ucPost(url, payload) {
    ucSaving = true;
    try {
        const res = await postJSON(url, payload);
        alert(res.message || (res.success ? 'تم الحفظ' : 'فشل'));
        if (res.success) location.reload();
    } catch (e) {
        alert('فشل الاتصال بالخادم أثناء الحفظ');
    } finally {
        ucSaving = false;
    }
}

function resetUcSection() {
    ucSlugManual.sec = false;
    document.getElementById('uc_sec_id').value = '0';
    var dsel = document.getElementById('uc_sec_department_id');
    if (dsel && dsel.options.length) dsel.selectedIndex = 0;
    document.getElementById('uc_sec_slug').value = '';
    document.getElementById('uc_sec_name_ar').value = '';
    document.getElementById('uc_sec_name_en').value = '';
    document.getElementById('uc_sec_name_fil').value = '';
    document.getElementById('uc_sec_name_hi').value = '';
    document.getElementById('uc_sec_active').value = '1';
    ucApplyNextSortForNewSec();
}

function editUcSection(j) {
    document.getElementById('uc_sec_id').value = String(j.id);
    document.getElementById('uc_sec_department_id').value = String(j.department_id);
    document.getElementById('uc_sec_slug').value = j.slug || '';
    document.getElementById('uc_sec_sort').value = j.sort_order > 0 ? String(j.sort_order) : '';
    document.getElementById('uc_sec_name_ar').value = j.name_ar || '';
    document.getElementById('uc_sec_name_en').value = j.name_en || '';
    document.getElementById('uc_sec_name_fil').value = j.name_fil || '';
    document.getElementById('uc_sec_name_hi').value = j.name_hi || '';
    document.getElementById('uc_sec_active').value = String(j.is_active === 0 ? 0 : 1);
    ucSlugManual.sec = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function saveUcSection() {
    if (ucSaving) return;
    ucEnsureSlugBeforeSave('uc_sec_', 'sec');
    const id = parseInt(document.getElementById('uc_sec_id').value || '0', 10) || 0;
    const payload = {
        department_id: parseInt(document.getElementById('uc_sec_department_id').value || '0', 10) || 0,
        slug: document.getElementById('uc_sec_slug').value.trim(),
        name_ar: document.getElementById('uc_sec_name_ar').value.trim(),
        name_en: document.getElementById('uc_sec_name_en').value.trim(),
        name_fil: document.getElementById('uc_sec_name_fil').value.trim(),
        name_hi: document.getElementById('uc_sec_name_hi').value.trim(),
        sort_order: parseSortPayload(document.getElementById('uc_sec_sort').value),
        is_active: parseInt(document.getElementById('uc_sec_active').value || '1', 10) ? 1 : 0
    };
    if (id > 0) payload.id = id;
    ucPost('/admin/api/unified_catalog/save_section.php', payload);
}

function resetUcCategory() {
    ucSlugManual.cat = false;
    document.getElementById('uc_cat_id').value = '0';
    document.getElementById('uc_cat_section_id').value = '';
    document.getElementById('uc_cat_slug').value = '';
    document.getElementById('uc_cat_sort').value = '';
    document.getElementById('uc_cat_name_ar').value = '';
    document.getElementById('uc_cat_name_en').value = '';
    document.getElementById('uc_cat_name_fil').value = '';
    document.getElementById('uc_cat_name_hi').value = '';
    document.getElementById('uc_cat_active').value = '1';
    ucTryAutoSingleSection();
    ucApplyNextSortForNewCat();
}

function editUcCategory(j) {
    document.getElementById('uc_cat_id').value = String(j.id);
    ucEnsureOption('uc_cat_section_id', j.catalog_section_id, ucSectionOptions);
    document.getElementById('uc_cat_slug').value = j.slug || '';
    document.getElementById('uc_cat_sort').value = j.sort_order > 0 ? String(j.sort_order) : '';
    document.getElementById('uc_cat_name_ar').value = j.name_ar || '';
    document.getElementById('uc_cat_name_en').value = j.name_en || '';
    document.getElementById('uc_cat_name_fil').value = j.name_fil || '';
    document.getElementById('uc_cat_name_hi').value = j.name_hi || '';
    document.getElementById('uc_cat_active').value = String(j.is_active === 0 ? 0 : 1);
    ucSlugManual.cat = !!(j.slug && String(j.slug).trim() !== '');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function ucEnsureOption(selectId, val, pool) {
    const sel = document.getElementById(selectId);
    if (!sel || val == null) return;
    const v = String(val);
    const exists = Array.prototype.some.call(sel.options, function (o) { return o.value === v; });
    if (!exists && Array.isArray(pool)) {
        const hit = pool.find(function (x) { return String(x.id) === v; });
        const opt = document.createElement('option');
        opt.value = v;
        opt.textContent = hit && hit.label ? hit.label : ('#' + v);
        sel.insertBefore(opt, sel.options[1] || null);
    }
    sel.value = v;
}

function saveUcCategory() {
    if (ucSaving) return;
    ucEnsureSlugBeforeSave('uc_cat_', 'cat');
    const id = parseInt(document.getElementById('uc_cat_id').value || '0', 10) || 0;
    const payload = {
        catalog_section_id: parseInt(document.getElementById('uc_cat_section_id').value || '0', 10) || 0,
        slug: document.getElementById('uc_cat_slug').value.trim(),
        name_ar: document.getElementById('uc_cat_name_ar').value.trim(),
        name_en: document.getElementById('uc_cat_name_en').value.trim(),
        name_fil: document.getElementById('uc_cat_name_fil').value.trim(),
        name_hi: document.getElementById('uc_cat_name_hi').value.trim(),
        sort_order: parseSortPayload(document.getElementById('uc_cat_sort').value),
        is_active: parseInt(document.getElementById('uc_cat_active').value || '1', 10) ? 1 : 0
    };
    if (id > 0) payload.id = id;
    ucPost('/admin/api/unified_catalog/save_category.php', payload);
}

function resetUcSubcategory() {
    ucSlugManual.sub = false;
    document.getElementById('uc_sub_id').value = '0';
    document.getElementById('uc_sub_category_id').value = '';
    document.getElementById('uc_sub_slug').value = '';
    document.getElementById('uc_sub_sort').value = '';
    document.getElementById('uc_sub_name_ar').value = '';
    document.getElementById('uc_sub_name_en').value = '';
    document.getElementById('uc_sub_name_fil').value = '';
    document.getElementById('uc_sub_name_hi').value = '';
    document.getElementById('uc_sub_active').value = '1';
    ucTryAutoSingleSubcategoryParent();
    ucApplyNextSortForNewSub();
}

function editUcSubcategory(j) {
    document.getElementById('uc_sub_id').value = String(j.id);
    ucEnsureOption('uc_sub_category_id', j.catalog_category_id, ucCategoryOptions);
    document.getElementById('uc_sub_slug').value = j.slug || '';
    document.getElementById('uc_sub_sort').value = j.sort_order > 0 ? String(j.sort_order) : '';
    document.getElementById('uc_sub_name_ar').value = j.name_ar || '';
    document.getElementById('uc_sub_name_en').value = j.name_en || '';
    document.getElementById('uc_sub_name_fil').value = j.name_fil || '';
    document.getElementById('uc_sub_name_hi').value = j.name_hi || '';
    document.getElementById('uc_sub_active').value = String(j.is_active === 0 ? 0 : 1);
    ucSlugManual.sub = !!(j.slug && String(j.slug).trim() !== '');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function saveUcSubcategory() {
    if (ucSaving) return;
    ucEnsureSlugBeforeSave('uc_sub_', 'sub');
    const id = parseInt(document.getElementById('uc_sub_id').value || '0', 10) || 0;
    const payload = {
        catalog_category_id: parseInt(document.getElementById('uc_sub_category_id').value || '0', 10) || 0,
        slug: document.getElementById('uc_sub_slug').value.trim(),
        name_ar: document.getElementById('uc_sub_name_ar').value.trim(),
        name_en: document.getElementById('uc_sub_name_en').value.trim(),
        name_fil: document.getElementById('uc_sub_name_fil').value.trim(),
        name_hi: document.getElementById('uc_sub_name_hi').value.trim(),
        sort_order: parseSortPayload(document.getElementById('uc_sub_sort').value),
        is_active: parseInt(document.getElementById('uc_sub_active').value || '1', 10) ? 1 : 0
    };
    if (id > 0) payload.id = id;
    ucPost('/admin/api/unified_catalog/save_subcategory.php', payload);
}

async function translateUc(which) {
    const map = {
        sec: { ar: 'uc_sec_name_ar', en: 'uc_sec_name_en', fil: 'uc_sec_name_fil', hi: 'uc_sec_name_hi' },
        cat: { ar: 'uc_cat_name_ar', en: 'uc_cat_name_en', fil: 'uc_cat_name_fil', hi: 'uc_cat_name_hi' },
        sub: { ar: 'uc_sub_name_ar', en: 'uc_sub_name_en', fil: 'uc_sub_name_fil', hi: 'uc_sub_name_hi' }
    };
    const m = map[which];
    if (!m) return;
    const forceFromArabic = true;
    try {
        const payload = {
            name_ar: document.getElementById(m.ar).value.trim(),
            name_en: forceFromArabic ? '' : document.getElementById(m.en).value.trim()
        };
        const res = await postJSON('/admin/api/translate/names.php', payload);
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'فشل الترجمة');
            return;
        }
        const t = res.translations || {};
        if (t.name_en) document.getElementById(m.en).value = t.name_en;
        if (t.name_fil) document.getElementById(m.fil).value = t.name_fil;
        if (t.name_hi) document.getElementById(m.hi).value = t.name_hi;
        refreshUcSlug(which);
    } catch (e) {
        alert('فشل طلب الترجمة');
    }
}

function scheduleUcTranslate(which) {
    const map = {
        sec: { ar: 'uc_sec_name_ar', en: 'uc_sec_name_en', fil: 'uc_sec_name_fil', hi: 'uc_sec_name_hi' },
        cat: { ar: 'uc_cat_name_ar', en: 'uc_cat_name_en', fil: 'uc_cat_name_fil', hi: 'uc_cat_name_hi' },
        sub: { ar: 'uc_sub_name_ar', en: 'uc_sub_name_en', fil: 'uc_sub_name_fil', hi: 'uc_sub_name_hi' }
    };
    const m = map[which];
    if (!m) return;
    const arEl = document.getElementById(m.ar);
    if (ucTimers[which]) clearTimeout(ucTimers[which]);
    ucTimers[which] = setTimeout(async function () {
        const nameAr = arEl.value.trim();
        if (!nameAr) {
            document.getElementById(m.en).value = '';
            document.getElementById(m.fil).value = '';
            document.getElementById(m.hi).value = '';
            return;
        }
        try {
            const res = await postJSON('/admin/api/translate/names.php', { name_ar: nameAr, name_en: '' });
            if (res && res.success) {
                const t = res.translations || {};
                if (t.name_en) document.getElementById(m.en).value = t.name_en;
                if (t.name_fil) document.getElementById(m.fil).value = t.name_fil;
                if (t.name_hi) document.getElementById(m.hi).value = t.name_hi;
                refreshUcSlug(which);
            }
        } catch (e) { /* silent */ }
    }, 700);
}

function scheduleUcFromEn(which) {
    const map = {
        sec: { en: 'uc_sec_name_en', fil: 'uc_sec_name_fil', hi: 'uc_sec_name_hi' },
        cat: { en: 'uc_cat_name_en', fil: 'uc_cat_name_fil', hi: 'uc_cat_name_hi' },
        sub: { en: 'uc_sub_name_en', fil: 'uc_sub_name_fil', hi: 'uc_sub_name_hi' }
    };
    const m = map[which];
    if (!m) return;
    if (ucEnTimers[which]) clearTimeout(ucEnTimers[which]);
    ucEnTimers[which] = setTimeout(async function () {
        const nameEn = document.getElementById(m.en).value.trim();
        if (!nameEn) return;
        try {
            const res = await postJSON('/admin/api/translate/names.php', { name_ar: '', name_en: nameEn });
            if (res && res.success) {
                const t = res.translations || {};
                if (t.name_fil) document.getElementById(m.fil).value = t.name_fil;
                if (t.name_hi) document.getElementById(m.hi).value = t.name_hi;
            }
        } catch (e) { /* silent */ }
    }, 650);
}

(function () {
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest('.uc-edit-sec');
        if (b && b.dataset.json) {
            try { editUcSection(JSON.parse(b.dataset.json)); } catch (e) { alert('تعذر قراءة البيانات'); }
            return;
        }
        b = ev.target.closest('.uc-edit-cat');
        if (b && b.dataset.json) {
            try { editUcCategory(JSON.parse(b.dataset.json)); } catch (e) { alert('تعذر قراءة البيانات'); }
            return;
        }
        b = ev.target.closest('.uc-edit-sub');
        if (b && b.dataset.json) {
            try { editUcSubcategory(JSON.parse(b.dataset.json)); } catch (e) { alert('تعذر قراءة البيانات'); }
        }
    });
    [['uc_sec_name_ar', 'sec'], ['uc_cat_name_ar', 'cat'], ['uc_sub_name_ar', 'sub']].forEach(function (pair) {
        var el = document.getElementById(pair[0]);
        if (el) el.addEventListener('input', function () { scheduleUcTranslate(pair[1]); });
    });
    [['uc_sec_name_en', 'sec'], ['uc_cat_name_en', 'cat'], ['uc_sub_name_en', 'sub']].forEach(function (pair) {
        var el = document.getElementById(pair[0]);
        if (el) {
            el.addEventListener('input', function () {
                scheduleUcFromEn(pair[1]);
                scheduleUcSlugRefresh(pair[1]);
            });
        }
    });
    ucBindSlugAuto('cat', 'uc_cat_');
    ucBindSlugAuto('sub', 'uc_sub_');
    var dDept = document.getElementById('uc_sec_department_id');
    if (dDept) {
        dDept.addEventListener('change', function () { ucApplyNextSortForNewSec(); });
    }
    var dSec = document.getElementById('uc_cat_section_id');
    if (dSec) {
        dSec.addEventListener('change', function () { ucApplyNextSortForNewCat(); });
    }
    var dCat = document.getElementById('uc_sub_category_id');
    if (dCat) {
        dCat.addEventListener('change', function () { ucApplyNextSortForNewSub(); });
    }
    ucApplyNextSortForNewSec();
    ucTryAutoSingleSection();
    ucApplyNextSortForNewCat();
    ucTryAutoSingleSubcategoryParent();
    ucApplyNextSortForNewSub();
})();
</script>
