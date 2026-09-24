<?php
declare(strict_types=1);
$biPreview = storefront_public_path('/admin/brand_identity_preview.php');
$biObject = storefront_public_path('/admin/api/brand_identity/object.php');
?>
<div class="card" dir="rtl">
    <h2 style="margin-top:0">هوية العلامة</h2>
    <p class="muted">أربعة مواضع صورة فقط. الشعار نص موضعي من اللغات النشطة. المعاينة الفعلية إلزامية قبل التفعيل. لا إعادة تصميم.</p>
    <p id="biStatus" class="muted">جاري التحميل…</p>
    <div id="biCurrent"></div>
    <hr>
    <h3>رفع أو استبدال موضع</h3>
    <div class="pd-form-grid" style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end">
        <label>الموضع
            <select id="biSlot"></select>
        </label>
        <label>ملف PNG/WEBP/JPEG
            <input type="file" id="biFile" accept="image/png,image/webp,image/jpeg">
        </label>
        <button type="button" class="btn" id="biUpload">رفع</button>
    </div>
    <p class="muted">المواضع غير المرفوعة تُعاد استخدام إصداراتها النشطة الحالية. يمكن تعديل صورة واحدة أو الشعار فقط.</p>
    <div id="biDraftSlots" class="muted"></div>
    <hr>
    <h3>الشعار النصي (ليس موضع صورة)</h3>
    <div id="biSloganFields" style="display:grid;grid-template-columns:1fr 1fr;gap:8px"></div>
    <p class="muted">العرض: لغة الواجهة ثم الإنجليزي ثم فراغ.</p>
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
    function sloganValue(locale) {
        const node = el('biSlogan_' + locale);
        return node ? node.value : '';
    }
    function renderSloganFields(locales, translations) {
        lastLocales = Array.isArray(locales) ? locales : [];
        const by = {};
        (translations || []).forEach((t) => {
            if (t.text_key !== 'STOREFRONT_SLOGAN') return;
            by[t.locale] = t.text_value || '';
        });
        el('biSloganFields').innerHTML = lastLocales.map((loc) => {
            return '<label>' + esc(loc) + ' <input type="text" id="biSlogan_' + esc(loc) + '" value="' + esc(by[loc] || '') + '"></label>';
        }).join('') || '<p class="muted">لا توجد لغات نشطة من سلطة Control.</p>';
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
    function renderSlotSelect(codes) {
        const sel = el('biSlot');
        sel.innerHTML = (codes || []).map((c) => '<option value="' + esc(c) + '">' + esc(slotLabel(c)) + '</option>').join('');
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
    function render(data) {
        lastSnapshot = data;
        if (!data || data.control_available === false) {
            show('مخزن الهوية غير مهيأ على هذا الجهاز (Control معزول فقط).');
            return;
        }
        show('مخزن الهوية جاهز. صلاحية الصفحة: ' + (data.permission_page || 'brand_identity') + ' / ' + (data.permission_resource || 'settings'));
        renderSlotSelect(data.slot_codes || []);
        renderSloganFields(data.locales || [], data.translations || []);
        const currentIds = data.draft_slot_version_ids || {};
        Object.keys(currentIds).forEach((code) => {
            if (!draft[code]) draft[code] = currentIds[code];
        });
        el('biDraftSlots').textContent = 'إصدارات المسودة (إعادة استخدام النشط إن لم يُرفع جديد): ' + JSON.stringify(draft);
        const cur = data.current_release || null;
        const slots = data.slots || {};
        let html = '';
        if (cur) {
            html += '<p><strong>الإصدار النشط الحالي:</strong> #' + esc(cur.id) + ' — ' + esc(cur.state || '') + '</p>';
        } else {
            html += '<p class="muted">لا يوجد إصدار نشط بعد.</p>';
        }
        html += '<table class="admin-table"><thead><tr><th>الموضع</th><th>الحالة</th><th>الأبعاد</th><th>الحالي</th><th>تنزيل</th></tr></thead><tbody>';
        Object.keys(slots).forEach((code) => {
            const s = slots[code] || {};
            const st = s.slot_version ? String(s.slot_version.state || '') : '—';
            const img = s.url ? '<img src="' + esc(s.url) + '" alt="" style="height:32px;max-width:120px;object-fit:contain">' : '—';
            const dim = (s.width && s.height) ? (s.width + '×' + s.height + ' (' + Number(s.aspect_ratio || 0).toFixed(3) + ')') : '—';
            const sha = s.object && s.object.sha256 ? s.object.sha256 : '';
            const orig = s.original_object && s.original_object.sha256 ? s.original_object.sha256 : sha;
            const dl = sha ? '<a href="' + esc(objectUrl(sha, true)) + '">الحالي</a>' : '—';
            const dlo = orig ? ' · <a href="' + esc(objectUrl(orig, true)) + '">الأصل</a>' : '';
            html += '<tr><td>' + esc(slotLabel(code)) + '<br><span class="muted">' + esc(code) + '</span></td><td>' + esc(st) + '</td><td>' + esc(dim) + '</td><td>' + img + '</td><td>' + dl + dlo + '</td></tr>';
        });
        html += '</tbody></table>';
        el('biCurrent').innerHTML = html;
        const archives = data.slot_archives || {};
        el('biArchives').innerHTML = Object.keys(archives).map((code) => {
            const rows = archives[code] || [];
            return '<details><summary>' + esc(slotLabel(code)) + '</summary><table class="admin-table"><tbody>' +
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
    el('biUpload').addEventListener('click', async () => {
        const f = el('biFile').files[0];
        if (!f) { show('اختر ملفاً'); return; }
        const slot = el('biSlot').value;
        const j = await jpost({ action: 'upload_slot', slot_code: slot }, f);
        if (!j.success) { show(j.message || 'فشل الرفع'); return; }
        draft[slot] = j.slot_version_id;
        show('تم الرفع: ' + slot);
        render(j.data);
    });
    el('biPreview').addEventListener('click', async () => {
        const translations = lastLocales.map((loc) => ({
            locale: loc,
            text_key: 'STOREFRONT_SLOGAN',
            text_value: sloganValue(loc)
        }));
        const j = await jpost({
            action: 'create_preview_release',
            slot_version_ids: draft,
            translations: translations,
            default_locale: lastLocales.indexOf('en') >= 0 ? 'en' : (lastLocales[0] || 'en'),
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
        render(j.data);
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
