<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/brand_identity.php';
$biPreview = storefront_public_path('/admin/brand_identity_preview.php');
$biObject = storefront_public_path('/admin/api/brand_identity/object.php');
$biSlotLabels = [
    'STOREFRONT_BRAND_MARK' => 'علامة المتجر',
    'STOREFRONT_COMPANY_WORDMARK' => 'كلمة الشركة — المتجر',
    'ADMIN_BRAND_MARK' => 'علامة الأدمن والدخول',
    'ADMIN_COMPANY_WORDMARK' => 'كلمة الشركة — الأدمن فقط',
];
$biSlotCodes = orange_brand_identity_slot_codes();
?>
<div class="card" dir="rtl">
    <h2 style="margin-top:0">هوية العلامة</h2>
    <p class="muted">أربعة مواضع صورة فقط. الشعار نص موضعي من اللغات النشطة. المعاينة الفعلية إلزامية قبل التفعيل. لا إعادة تصميم.</p>
    <p id="biStatus" class="muted">جاري التحميل…</p>
    <div id="biCurrent"></div>
    <hr>
    <h3>المواضع الأربعة</h3>
    <p class="muted">كل موضع مستقل: الحالة الحالية، رفع/استبدال، معاينة الأصل والحالي. المواضع غير المرفوعة تُعاد استخدام إصداراتها النشطة الحالية.</p>
    <div id="biSlotGrid" class="bi-slot-grid">
        <?php foreach ($biSlotCodes as $code): ?>
        <section class="card bi-slot-card" data-slot-code="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
            <h3 class="bi-slot-title"><?php echo htmlspecialchars($biSlotLabels[$code] ?? $code, ENT_QUOTES, 'UTF-8'); ?></h3>
            <p class="muted bi-slot-code"><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="bi-slot-current" data-slot-current="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">لا توجد صورة نشطة بعد.</div>
            <label>ملف PNG/WEBP/JPEG
                <input type="file" class="bi-slot-file" data-slot-file="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" accept="image/png,image/webp,image/jpeg">
            </label>
            <button type="button" class="btn bi-slot-upload" data-slot-upload="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">رفع / استبدال</button>
        </section>
        <?php endforeach; ?>
    </div>
    <div id="biDraftSlots" class="muted"></div>
    <hr>
    <h3>الشعار النصي (ليس موضع صورة)</h3>
    <p class="muted">أضف لغة يدوياً. القائمة من مرجع اللغات، وليست لغات واجهة المتجر فقط. حفظ الشعار لا يفعّل اللغة للزائر. لا ترجمة تلقائية. العرض للزائر: لغة الواجهة ثم الإنجليزي ثم فراغ.</p>
    <p id="biRefNotice" class="muted" hidden></p>
    <div id="biSloganFields"></div>
    <button type="button" class="btn" id="biAddSloganLang">+ إضافة لغة</button>
    <hr>
    <label>ملاحظة المسودة <input type="text" id="biNote" value="معاينة هوية"></label>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
        <button type="button" class="btn" id="biPreview">إنشاء معاينة (بدون تفعيل)</button>
        <button type="button" class="btn" id="biActivate" disabled>تفعيل بعد المراجعة الفعلية</button>
    </div>
    <p id="biReviewStatus" class="muted"></p>
    <div id="biRealPreview"></div>
    <hr>
    <h3>أرشيف المواضع والرجوع الجزئي</h3>
    <div id="biArchives"></div>
    <hr>
    <h3>سجل الإصدارات والرجوع الكامل</h3>
    <div id="biHistory"></div>
    <hr>
    <h3>سجل التدقيق</h3>
    <div id="biAudit"></div>
</div>
<style>
.bi-slot-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(16rem,1fr)); gap:12px; }
.bi-slot-card h3 { margin-top:0; }
.bi-slot-current img { height:40px; max-width:160px; object-fit:contain; }
#biSloganFields { background:#ffffff; padding:8px; }
#biSloganTable { width:100%; border-collapse:collapse; background:#ffffff; }
#biSloganTable th, #biSloganTable td { padding:6px 8px; border-bottom:1px solid #e2e8f0; vertical-align:middle; }
#biSloganTable input[type=text], #biSloganTable select { width:100%; box-sizing:border-box; }
.bi-role { font-size:12px; color:#475569; }
</style>
<script>
(function () {
    const api = <?php echo json_encode(storefront_public_path('/admin/api/brand_identity/manage.php'), JSON_UNESCAPED_UNICODE); ?>;
    const previewBase = <?php echo json_encode($biPreview, JSON_UNESCAPED_UNICODE); ?>;
    const objectBase = <?php echo json_encode($biObject, JSON_UNESCAPED_UNICODE); ?>;
    const draft = {};
    let lastPreviewRelease = 0;
    let lastPreviewToken = '';
    let lastLocales = [];
    let lastSnapshot = null;
    let editorSource = { identity_version_id: 0, release_id: 0, kind: 'none' };
    const requiredReviews = [
        'storefront:desktop', 'storefront:mobile',
        'admin:desktop', 'admin:mobile',
        'login:desktop', 'login:mobile'
    ];
    const pendingMeasures = {};
    function el(id) { return document.getElementById(id); }
    function show(msg) { el('biStatus').textContent = msg; }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    }
    function objectUrl(sha, download) {
        return objectBase + '?sha=' + encodeURIComponent(sha) + (download ? '&download=1' : '');
    }
    async function jpost(body, file) {
        if (file) {
            const fd = new FormData();
            Object.keys(body).forEach((k) => fd.append(k, typeof body[k] === 'object' ? JSON.stringify(body[k]) : body[k]));
            fd.append('file', file);
            const r = await fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' });
            return r.json();
        }
        const r = await fetch(api, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        return r.json();
    }
    function referenceRowsFrom(data) {
        if (data && Array.isArray(data.locale_rows) && data.locale_rows.length) {
            return data.locale_rows;
        }
        if (data && Array.isArray(data.language_reference) && data.language_reference.length) {
            return data.language_reference.map((row) => ({
                code: row.code,
                label: row.label || row.code,
                roles: []
            }));
        }
        return [];
    }
    function adoptEditorSourceFrom(data) {
        editorSource = {
            identity_version_id: data && data.displayed_identity_version_id != null ? data.displayed_identity_version_id : 0,
            release_id: data && data.displayed_release_id != null ? data.displayed_release_id : 0,
            kind: data && data.displayed_source_kind ? String(data.displayed_source_kind) : 'none'
        };
    }
    function editorBaseIdForSubmit() {
        const raw = editorSource && editorSource.identity_version_id;
        if (raw === 0 || raw === '0') return 0;
        if (typeof raw === 'number' && Number.isInteger(raw) && raw >= 0) return raw;
        if (typeof raw === 'string' && /^(0|[1-9][0-9]*)$/.test(raw)) return raw;
        return raw;
    }
    function showReferenceNotice(data) {
        const node = el('biRefNotice');
        if (!node) return;
        const text = data && data.language_reference_notice_ar ? String(data.language_reference_notice_ar) : '';
        node.textContent = text;
        node.hidden = text === '';
    }
    function roleTextForCode(code) {
        const ref = referenceRowsFrom(lastSnapshot || {});
        const row = (ref || []).find((r) => String(r.code || '') === String(code || ''));
        if (!row) return '';
        if (row.role_text) return String(row.role_text);
        const roles = Array.isArray(row.roles) ? row.roles : [];
        return roles.length ? roles.join(' · ') : '';
    }
    function updateSloganRoleCells() {
        document.querySelectorAll('#biSloganTable tbody tr').forEach((tr) => {
            const role = tr.querySelector('[data-slogan-role]');
            if (!role) return;
            if (tr.getAttribute('data-retired') === '1') {
                role.textContent = 'محفوظة سابقاً — غير مدعومة في المرجع الحالي، ولن تُنسخ إلى الإصدار التالي';
                return;
            }
            const sel = tr.querySelector('select[data-slogan-locale]');
            const loc = sel ? String(sel.value || '').trim() : '';
            const text = loc ? roleTextForCode(loc) : '';
            role.textContent = text !== '' ? text : '—';
        });
    }
    function collectSloganRows() {
        const rows = [];
        document.querySelectorAll('#biSloganTable tbody tr').forEach((tr) => {
            if (tr.getAttribute('data-retired') === '1') {
                const inp = tr.querySelector('input[data-slogan-text]');
                rows.push({
                    locale: String(tr.getAttribute('data-locale') || ''),
                    text_key: 'STOREFRONT_SLOGAN',
                    text_value: inp ? inp.value : '',
                    retired: true
                });
                return;
            }
            const sel = tr.querySelector('select[data-slogan-locale]');
            const inp = tr.querySelector('input[data-slogan-text]');
            const loc = sel ? String(sel.value || '').trim() : String(tr.getAttribute('data-locale') || '').trim();
            rows.push({
                locale: loc,
                text_key: 'STOREFRONT_SLOGAN',
                text_value: inp ? inp.value : '',
                retired: false,
                selectEl: sel
            });
        });
        return rows;
    }
    function collectSelectedSloganTranslations() {
        const seen = {};
        const out = [];
        collectSloganRows().forEach((row) => {
            if (row.retired) return;
            if (!row.locale) {
                if (String(row.text_value || '').trim() !== '') {
                    const err = new Error('يوجد نص شعار بلا لغة مختارة. اختر اللغة قبل إنشاء المعاينة.');
                    err.focusEl = row.selectEl || null;
                    throw err;
                }
                return;
            }
            if (seen[row.locale]) {
                throw new Error('لغة مكررة');
            }
            seen[row.locale] = true;
            out.push({
                locale: row.locale,
                text_key: 'STOREFRONT_SLOGAN',
                text_value: row.text_value
            });
        });
        return out;
    }
    function usedSloganCodes() {
        return collectSloganRows().map((row) => row.locale).filter(Boolean);
    }
    function sloganOptionHtml(refRows, selected, used) {
        let html = '<option value="">اختر لغة</option>';
        (refRows || []).forEach((row) => {
            const code = String(row.code || '');
            if (!code) return;
            if (used.indexOf(code) >= 0 && code !== selected) return;
            const roles = Array.isArray(row.roles) ? row.roles : [];
            html += '<option value="' + esc(code) + '"' + (code === selected ? ' selected' : '') + '>';
            html += esc((row.label || code) + ' (' + code + ')' + (roles.length ? ' — ' + roles.join(' · ') : ''));
            html += '</option>';
        });
        return html;
    }
    function refreshSloganSelects() {
        const ref = referenceRowsFrom(lastSnapshot || {});
        const used = usedSloganCodes();
        document.querySelectorAll('#biSloganTable select[data-slogan-locale]').forEach((sel) => {
            const current = String(sel.value || '');
            sel.innerHTML = sloganOptionHtml(ref, current, used);
            if (current) sel.value = current;
        });
        updateSloganRoleCells();
    }
    function addSloganLanguageRow(prefill, opts) {
        const table = el('biSloganTable');
        if (!table) {
            renderSloganEditor(lastSnapshot || {}, []);
        }
        const tbody = document.querySelector('#biSloganTable tbody');
        if (!tbody) return;
        const loc = prefill && prefill.locale ? String(prefill.locale) : '';
        const text = prefill && typeof prefill.text_value === 'string' ? prefill.text_value : '';
        const retired = !!(opts && opts.retired);
        const tr = document.createElement('tr');
        tr.setAttribute('data-locale', loc);
        if (retired) {
            tr.setAttribute('data-retired', '1');
            tr.innerHTML = '<td><span data-retired-locale="' + esc(loc) + '">' + esc(loc) + ' — محفوظة سابقاً</span></td>'
                + '<td class="bi-role" data-slogan-role>محفوظة سابقاً — غير مدعومة في المرجع الحالي، ولن تُنسخ إلى الإصدار التالي</td>'
                + '<td><input type="text" data-slogan-text value="' + esc(text) + '" readonly></td>';
            tbody.appendChild(tr);
            return;
        }
        tr.innerHTML = '<td><select data-slogan-locale></select></td><td class="bi-role" data-slogan-role>—</td><td><input type="text" data-slogan-text value="' + esc(text) + '"></td>';
        tbody.appendChild(tr);
        refreshSloganSelects();
        const sel = tr.querySelector('select[data-slogan-locale]');
        if (sel && loc) sel.value = loc;
        refreshSloganSelects();
    }
    function renderSloganEditor(data, existingRows) {
        lastSnapshot = data || lastSnapshot;
        showReferenceNotice(lastSnapshot || {});
        const ref = referenceRowsFrom(data || lastSnapshot || {});
        const refCodes = ref.map((row) => String(row.code || '')).filter(Boolean);
        lastLocales = refCodes;
        const rows = Array.isArray(existingRows) ? existingRows : [];
        let html = '<table class="admin-table" id="biSloganTable"><thead><tr>';
        html += '<th>اللغة</th><th>الدور</th><th>نص الشعار</th>';
        html += '</tr></thead><tbody></tbody></table>';
        if (!ref.length) {
            html = '<p class="muted">مرجع اللغات غير متاح للاختيار حالياً.</p>' + html;
        }
        el('biSloganFields').innerHTML = html;
        rows.forEach((row) => {
            const code = String(row.locale || '');
            const retired = !!(code && refCodes.indexOf(code) < 0);
            addSloganLanguageRow(row, { retired: retired });
        });
        updateSloganRoleCells();
    }
    function renderSloganFields(rows, translations) {
        const saved = [];
        (translations || []).forEach((t) => {
            if (t.text_key !== 'STOREFRONT_SLOGAN') return;
            saved.push({ locale: t.locale, text_value: t.text_value || '' });
        });
        renderSloganEditor({ locale_rows: rows, language_reference: rows }, saved);
    }
    function slotLabel(code) {
        const map = {
            STOREFRONT_BRAND_MARK: 'علامة المتجر',
            STOREFRONT_COMPANY_WORDMARK: 'كلمة الشركة — المتجر',
            ADMIN_BRAND_MARK: 'علامة الأدمن والدخول',
            ADMIN_COMPANY_WORDMARK: 'كلمة الشركة — الأدمن فقط'
        };
        return map[code] || code;
    }
    function fillSlotCards(slots) {
        document.querySelectorAll('[data-slot-current]').forEach((node) => {
            const code = node.getAttribute('data-slot-current');
            const s = (slots || {})[code] || {};
            const st = s.slot_version ? String(s.slot_version.state || '') : '—';
            const img = s.url ? '<img src="' + esc(s.url) + '" alt="">' : '<span class="muted">لا توجد صورة نشطة بعد.</span>';
            const dim = (s.width && s.height) ? (s.width + '×' + s.height + ' (' + Number(s.aspect_ratio || 0).toFixed(3) + ')') : '—';
            const sha = s.object && s.object.sha256 ? s.object.sha256 : '';
            const orig = s.original_object && s.original_object.sha256 ? s.original_object.sha256 : sha;
            const dl = sha ? '<a href="' + esc(objectUrl(sha, true)) + '">الحالي</a>' : '—';
            const dlo = orig ? ' · <a href="' + esc(objectUrl(orig, true)) + '">الأصل</a>' : '';
            node.innerHTML = img + '<p class="muted">الحالة: ' + esc(st) + ' · الأبعاد: ' + esc(dim) + ' · ' + dl + dlo + '</p>';
        });
    }
    function reviewState(data) {
        const rec = (data && data.visual_review) || {};
        const reviews = rec.reviews || {};
        const missing = requiredReviews.filter((k) => !(reviews[k] && reviews[k].ok));
        return { reviews: reviews, missing: missing, complete: missing.length === 0 };
    }
    function renderPreviewFrames(releaseId, token) {
        if (!releaseId) {
            el('biRealPreview').innerHTML = '';
            return;
        }
        const surfaces = ['storefront', 'admin', 'login'];
        const views = ['desktop', 'mobile'];
        const widths = { desktop: 1280, mobile: 390 };
        let html = '<h3>معاينة فعلية قبل التفعيل</h3><p class="muted">هذه إطارات المرشح الفعلي. أكّد كل سطح بعد ظهوره.</p>';
        surfaces.forEach((surface) => {
            views.forEach((viewport) => {
                const key = surface + ':' + viewport;
                const src = previewBase + '?surface=' + encodeURIComponent(surface)
                    + '&viewport=' + encodeURIComponent(viewport)
                    + '&release_id=' + encodeURIComponent(String(releaseId))
                    + '&token=' + encodeURIComponent(token || '')
                    + '&orange_brand_preview=' + encodeURIComponent(String(releaseId));
                html += '<div class="card" style="margin:10px 0" data-review-key="' + esc(key) + '">';
                html += '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center">';
                html += '<strong>' + esc(surface) + ' / ' + esc(viewport) + '</strong>';
                html += '<button type="button" class="btn btn-secondary" data-confirm-review="' + esc(key) + '">أكّدت مراجعة هذا السطح</button>';
                html += '</div>';
                html += '<iframe title="' + esc(key) + '" src="' + esc(src) + '" style="width:' + widths[viewport] + 'px;max-width:100%;height:' + (surface === 'login' ? '520' : '220') + 'px;border:1px solid #cbd5e1;background:#fff;margin-top:8px"></iframe>';
                html += '<p class="muted" data-measure="' + esc(key) + '">بانتظار قياس الإطار…</p>';
                html += '</div>';
            });
        });
        el('biRealPreview').innerHTML = html;
    }
    function render(data, opts) {
        const preserveSlogan = !!(opts && opts.preserveSlogan);
        const keptSlogan = preserveSlogan ? collectSloganRows() : null;
        lastSnapshot = data;
        if (!preserveSlogan) {
            adoptEditorSourceFrom(data);
        }
        if (!data || data.control_available === false) {
            show('جداول هوية العلامة غير مهيأة على هذا الخادم.');
            return;
        }
        show('مخزن الهوية جاهز. صلاحية الصفحة: ' + (data.permission_page || 'brand_identity') + ' / ' + (data.permission_resource || 'settings'));
        if (preserveSlogan && keptSlogan) {
            renderSloganEditor(data, keptSlogan);
        } else {
            const saved = [];
            (data.translations || []).forEach((t) => {
                if (t.text_key !== 'STOREFRONT_SLOGAN') return;
                saved.push({ locale: t.locale, text_value: t.text_value || '' });
            });
            renderSloganEditor(data, saved);
        }
        const currentIds = data.draft_slot_version_ids || {};
        Object.keys(currentIds).forEach((code) => {
            if (!draft[code]) draft[code] = currentIds[code];
        });
        el('biDraftSlots').textContent = 'إصدارات المسودة (إعادة استخدام النشط إن لم يُرفع جديد): ' + JSON.stringify(draft);
        const cur = data.current_release || null;
        const slots = data.slots || {};
        fillSlotCards(slots);
        let html = '';
        if (cur) {
            html += '<p><strong>الإصدار النشط الحالي:</strong> #' + esc(cur.id) + ' — ' + esc(cur.state || '') + '</p>';
        } else {
            html += '<p class="muted">لا يوجد إصدار نشط بعد.</p>';
        }
        el('biCurrent').innerHTML = html;
        const archives = data.slot_archives || {};
        el('biArchives').innerHTML = Object.keys(archives).map((code) => {
            const rows = archives[code] || [];
            return '<details><summary>' + esc(slotLabel(code)) + ' <span class="muted">' + esc(code) + '</span></summary><table class="admin-table"><tbody>' +
                rows.map((row) => {
                    return '<tr><td>#' + esc(row.id) + '</td><td>' + esc(row.state || '') + '</td><td>' + esc(row.created_at || '') + '</td>' +
                        '<td><button type="button" class="btn btn-secondary" data-slot-roll="' + esc(code) + '" data-slot-ver="' + esc(row.id) + '">رجوع لهذا الموضع</button></td></tr>';
                }).join('') + '</tbody></table></details>';
        }).join('') || 'لا أرشيف';
        const hist = data.history || [];
        el('biHistory').innerHTML = hist.map((h) => {
            const curFlag = Number(h.current_flag) === 1 ? ' — الحالي' : '';
            return '<div style="margin-bottom:6px">#' + esc(h.id) + ' ' + esc(h.state || '') + curFlag + ' ' + esc(h.activated_at || '') +
                ' <button type="button" class="btn btn-secondary" data-roll="' + esc(h.id) + '">رجوع كامل</button></div>';
        }).join('') || 'لا سجل';
        const audit = data.audit || [];
        el('biAudit').innerHTML = audit.map((a) => {
            return '<div class="muted">#' + esc(a.id) + ' ' + esc(a.event_type || '') + ' ' + esc(a.created_at || '') + ' ' + esc(a.change_note || '') + '</div>';
        }).join('') || 'لا تدقيق';
        lastPreviewRelease = lastPreviewRelease || Number(data.preview_release_id || 0);
        if (data.visual_review && data.visual_review.token) {
            lastPreviewToken = data.visual_review.token;
        }
        const rs = reviewState(data);
        el('biReviewStatus').textContent = rs.complete
            ? 'اكتملت المراجعة البصرية الفعلية. يمكن التفعيل.'
            : ('لم تكتمل المراجعة. المتبقي: ' + (rs.missing.join('، ') || '—'));
        el('biActivate').disabled = !(lastPreviewRelease > 0 && rs.complete);
        if (lastPreviewRelease > 0) {
            renderPreviewFrames(lastPreviewRelease, lastPreviewToken);
        }
    }
    async function load() {
        const j = await jpost({ action: 'current' });
        render(j.data || j);
    }
    el('biSlotGrid').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('[data-slot-upload]');
        if (!btn) return;
        const slot = btn.getAttribute('data-slot-upload');
        const input = document.querySelector('[data-slot-file="' + slot + '"]');
        const f = input && input.files && input.files[0];
        if (!f) { show('اختر ملفاً لـ ' + slot); return; }
        const j = await jpost({ action: 'upload_slot', slot_code: slot }, f);
        if (!j.success) { show(j.message || 'فشل الرفع'); return; }
        draft[slot] = j.slot_version_id;
        show('تم الرفع: ' + slot);
        render(j.data, { preserveSlogan: true });
    });
    el('biAddSloganLang').addEventListener('click', () => {
        addSloganLanguageRow();
    });
    el('biSloganFields').addEventListener('change', (ev) => {
        if (ev.target && ev.target.matches('select[data-slogan-locale]')) {
            ev.target.closest('tr').setAttribute('data-locale', ev.target.value || '');
            refreshSloganSelects();
            updateSloganRoleCells();
        }
    });
    el('biPreview').addEventListener('click', async () => {
        let translations;
        try {
            translations = collectSelectedSloganTranslations();
        } catch (err) {
            show(String(err.message || err));
            if (err && err.focusEl && typeof err.focusEl.focus === 'function') {
                err.focusEl.focus();
            }
            return;
        }
        const sf = (lastSnapshot && Array.isArray(lastSnapshot.locales)) ? lastSnapshot.locales : [];
        const displayed = editorBaseIdForSubmit();
        const j = await jpost({
            action: 'create_preview_release',
            slot_version_ids: draft,
            translations: translations,
            base_identity_version_id: displayed,
            default_locale: sf.indexOf('en') >= 0 ? 'en' : (sf[0] || 'en'),
            change_note: el('biNote').value
        });
        if (!j.success) { show(j.message || 'تعذر إنشاء المعاينة'); return; }
        lastPreviewRelease = j.release_id;
        lastPreviewToken = j.preview_token || '';
        Object.assign(draft, j.reused_slot_version_ids || {});
        el('biActivate').disabled = true;
        show('معاينة جاهزة. راجع الأسطح الفعلية ثم فعّل.');
        render(j.data);
        renderPreviewFrames(lastPreviewRelease, lastPreviewToken);
    });
    el('biActivate').addEventListener('click', async () => {
        const j = await jpost({
            action: 'activate',
            release_id: lastPreviewRelease
        });
        if (!j.success) { show(j.message || 'تعذر التفعيل'); return; }
        show('تم التفعيل');
        lastPreviewRelease = 0;
        render(j.data);
    });
    el('biHistory').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('[data-roll]');
        if (!btn) return;
        const j = await jpost({ action: 'rollback', historic_release_id: parseInt(btn.getAttribute('data-roll'), 10) });
        if (!j.success) { show(j.message || 'تعذر الرجوع'); return; }
        show('تم الرجوع الكامل');
        render(j.data);
    });
    el('biArchives').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('[data-slot-roll]');
        if (!btn) return;
        const j = await jpost({
            action: 'rollback_slot',
            slot_code: btn.getAttribute('data-slot-roll'),
            historic_slot_version_id: parseInt(btn.getAttribute('data-slot-ver'), 10)
        });
        if (!j.success) { show(j.message || 'تعذر رجوع الموضع'); return; }
        show('تم رجوع الموضع');
        render(j.data);
    });
    el('biRealPreview').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('[data-confirm-review]');
        if (!btn) return;
        const key = btn.getAttribute('data-confirm-review');
        const parts = String(key || '').split(':');
        const measured = pendingMeasures[key] || {};
        const j = await jpost({
            action: 'record_visual_review',
            release_id: lastPreviewRelease,
            surface: parts[0],
            viewport: parts[1],
            measured: measured
        });
        if (!j.success) { show(j.message || 'تعذر تسجيل المراجعة'); return; }
        show('سُجّلت مراجعة ' + key);
        render(j.data, { preserveSlogan: true });
        renderPreviewFrames(lastPreviewRelease, lastPreviewToken);
    });
    window.addEventListener('message', (ev) => {
        const d = ev.data || {};
        if (!d || d.kind !== 'orange_brand_preview_measure') return;
        const key = d.surface + ':' + d.viewport;
        pendingMeasures[key] = { width: d.width, height: d.height, innerWidth: d.innerWidth, innerHeight: d.innerHeight };
        const node = document.querySelector('[data-measure="' + key + '"]');
        if (node) node.textContent = 'قياس الإطار: ' + d.width + '×' + d.height;
    });
    load().catch((e) => show(String(e)));
})();
</script>
