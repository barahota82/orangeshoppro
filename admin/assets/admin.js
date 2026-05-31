/** يسبق مسارات الأدمن بـ PUBLIC_BASE_PATH عند نشر التطبيق تحت مجلد فرعي. */
function orangeAdminResolveUrl(url) {
    if (typeof url !== 'string') {
        return url;
    }
    if (url.length < 8 || url.indexOf('/admin/') !== 0) {
        return url;
    }
    var raw = typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH : '';
    var base = raw.replace(/\/+$/, '');
    return base === '' ? url : base + url;
}

(function () {
    var nativeFetch = window.fetch;
    if (typeof nativeFetch !== 'function') {
        return;
    }
    window.fetch = function (input, init) {
        if (typeof input === 'string') {
            input = orangeAdminResolveUrl(input);
        }
        return nativeFetch.call(this, input, init);
    };
})();

/**
 * closest آمن من هدف الحدث — يتجنب رمياً إن لم يكن الهدف عنصر DOM (مثلاً عند تفويض النقر).
 * @param {Event} ev
 * @param {string} selector
 * @returns {Element|null}
 */
function orangeAdminClosest(ev, selector) {
    var t = ev && ev.target;
    if (!t || typeof t.closest !== 'function') {
        return null;
    }
    return t.closest(selector);
}

function stripUtf8Bom(s) {
    if (!s || s.length < 1) return s;
    if (s.charCodeAt(0) === 0xfeff) return s.slice(1);
    if (s.length >= 3 && s.charCodeAt(0) === 0xef && s.charCodeAt(1) === 0xbb && s.charCodeAt(2) === 0xbf) {
        return s.slice(3);
    }
    return s;
}

function parseResponseJson(text) {
    if (text == null || text === '') {
        return { ok: false, reason: 'empty' };
    }
    let t = stripUtf8Bom(String(text)).trim();
    if (!t) {
        return { ok: false, reason: 'empty' };
    }
    try {
        const data = JSON.parse(t);
        if (data !== null && typeof data === 'object') {
            return { ok: true, data: data };
        }
    } catch (e) { /* loose parse below */ }
    const start = t.indexOf('{');
    const end = t.lastIndexOf('}');
    if (start >= 0 && end > start) {
        try {
            const data = JSON.parse(t.slice(start, end + 1));
            if (data !== null && typeof data === 'object') {
                return { ok: true, data: data };
            }
        } catch (e2) { /* */ }
    }
    return { ok: false, reason: 'notjson', raw: t };
}

function readableSnippet(s, max) {
    if (!s) return '';
    const noTags = s.replace(/<[^>]+>/g, ' ');
    let out = '';
    for (let i = 0; i < noTags.length && out.length < max + 40; i++) {
        const ch = noTags[i];
        const c = noTags.charCodeAt(i);
        if (c === 9 || c === 10 || c === 13) {
            out += ' ';
            continue;
        }
        if (c === 0xfffd) continue;
        if (c >= 32 && c < 127) {
            out += ch;
            continue;
        }
        if (c >= 0x0600 && c <= 0x06ff) {
            out += ch;
            continue;
        }
        if (c >= 0x0750 && c <= 0x077f) {
            out += ch;
            continue;
        }
    }
    return out.replace(/\s+/g, ' ').trim().slice(0, max);
}

/** رؤوس طلب الأدمن مع سياق الدولة (مبدّل الشريط العلوي). */
function orangeAdminCountryHeaders(extra) {
    var headers = extra && typeof extra === 'object' ? Object.assign({}, extra) : {};
    try {
        var countryMeta = document.querySelector('meta[name="orange-admin-country"]');
        if (countryMeta) {
            var code = String(countryMeta.getAttribute('content') || '').trim();
            if (code) {
                headers['X-Orange-Admin-Country'] = code;
            }
        }
    } catch (eMeta) {
        /* ignore */
    }
    return headers;
}

/** GET/POST fetch مع JSON + سياق الدولة. */
function orangeAdminFetchJson(url, opts) {
    opts = opts || {};
    var headers = orangeAdminCountryHeaders(opts.headers || {});
    return fetch(url, Object.assign({}, opts, {
        credentials: opts.credentials || 'same-origin',
        headers: headers
    })).then(function (r) { return r.json(); });
}

/**
 * POST JSON للأدمن. يمنع النقر المزدوج: يعطّل الزر ويضبط data-orange-postjson-busy، ويستنتج الزر من آخر نقر (ORANGE_POSTJSON_INFER_SUBMITTER) ما لم يُمرَّر submitter أو inferSubmitter:false.
 * @param {string} url
 * @param {object} payload
 * @param {{ submitter?: Element, inferSubmitter?: boolean }|Element} [opts] عنصر الزر أو خيارات؛ inferSubmitter: false يعطّل الربط التلقائي لهذا الطلب فقط (مثلاً طلبين متتابعين من نفس الزر).
 */
function postJSON(url, payload, opts) {
    var options = opts;
    if (options && options.nodeType === 1) {
        options = { submitter: options };
    }
    if (!options || typeof options !== 'object') {
        options = {};
    }
    var submitter = options.submitter || null;
    if (options.inferSubmitter !== false && typeof window !== 'undefined' && window.ORANGE_POSTJSON_INFER_SUBMITTER === true) {
        if (!submitter && window.__orangeLastPointerButton) {
            submitter = window.__orangeLastPointerButton;
            window.__orangeLastPointerButton = null;
        }
    }
    var prevDisabled = false;
    if (submitter && submitter.nodeType === 1) {
        if ('disabled' in submitter) {
            prevDisabled = submitter.disabled;
            submitter.disabled = true;
        }
        submitter.setAttribute('data-orange-postjson-busy', '1');
    }

    var adminCountryHdr = '';
    try {
        var countryMeta = document.querySelector('meta[name="orange-admin-country"]');
        if (countryMeta) {
            adminCountryHdr = String(countryMeta.getAttribute('content') || '').trim();
        }
    } catch (eMeta) {
        adminCountryHdr = '';
    }
    var fetchHeaders = orangeAdminCountryHeaders({
        'Content-Type': 'application/json',
        Accept: 'application/json'
    });
    if (adminCountryHdr) {
        fetchHeaders['X-Orange-Admin-Country'] = adminCountryHdr;
    }

    var chain = fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: fetchHeaders,
        body: JSON.stringify(payload)
    })
        .then(async (r) => {
            const text = await r.text();
            const parsed = parseResponseJson(text);
            if (parsed.ok) {
                return parsed.data;
            }

            const status = r.status;
            let msg;
            if (parsed.reason === 'empty') {
                msg =
                    'رد السيرفر فارغ (HTTP ' +
                    status +
                    '). غالباً: مسار الملف غلط، أو PHP يتوقف قبل إرسال JSON، أو خطأ قاتل في السيرفر.';
            } else {
                msg =
                    'السيرفر لم يرد بـ JSON صالح (HTTP ' +
                    status +
                    '). غالباً: تحذير/خطأ PHP يظهر قبل JSON، أو مسافات/BOM قبل <?php في ملف الـ API، أو الملف محفوظ UTF-16.';
                const hint = readableSnippet(parsed.raw, 100);
                if (hint.length >= 10) {
                    msg += ' — مقتطف مقروء: ' + hint;
                }
                if (/\$pdo|require_once|INFORMATION_SCHEMA|declare\s*\(/.test(hint + (parsed.raw || ''))) {
                    msg +=
                        '\n\n[تشخيص] يبدو أن المتصفح يستقبل كود PHP كنص. ارفع الملفات UTF-8 بدون BOM، وجرب فتح: /admin/api/departments/env-check.php — يجب أن يظهر JSON فقط.';
                }
            }
            return { success: false, message: msg };
        })
        .catch((e) => ({
            success: false,
            message: e.message || 'تعذر الاتصال بالخادم'
        }));

    return chain.finally(function () {
        if (submitter && submitter.nodeType === 1) {
            submitter.removeAttribute('data-orange-postjson-busy');
            if ('disabled' in submitter) {
                submitter.disabled = prevDisabled;
            }
        }
    });
}

(function orangeAdminPostJsonPointerCapture() {
    if (typeof document === 'undefined') {
        return;
    }
    document.addEventListener(
        'click',
        function (e) {
            if (typeof window === 'undefined' || window.ORANGE_POSTJSON_INFER_SUBMITTER !== true) {
                return;
            }
            var t = e.target;
            if (!t || !t.closest) {
                return;
            }
            var btn = t.closest('button, input[type="submit"]');
            if (!btn || btn.hasAttribute('data-no-post-guard')) {
                return;
            }
            if (btn.getAttribute('data-orange-postjson-busy') === '1') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            window.__orangeLastPointerButton = btn;
        },
        true
    );
})();

/** إرسال النموذج (Enter / زر إرسال): يربط نفس آلية postJSON كالنقر على الزر */
(function orangeAdminPostJsonSubmitCapture() {
    if (typeof document === 'undefined') {
        return;
    }
    document.addEventListener(
        'submit',
        function (e) {
            if (typeof window === 'undefined' || window.ORANGE_POSTJSON_INFER_SUBMITTER !== true) {
                return;
            }
            var sub = e.submitter;
            if (!sub || sub.nodeType !== 1) {
                return;
            }
            var tag = sub.tagName;
            if (tag !== 'BUTTON' && tag !== 'INPUT') {
                return;
            }
            if (sub.hasAttribute('data-no-post-guard')) {
                return;
            }
            window.__orangeLastPointerButton = sub;
        },
        true
    );
})();

function getJSON(url) {
    return fetch(url, {
        method: 'GET',
        credentials: 'same-origin'
    })
        .then(async (r) => {
            const text = await r.text();
            const parsed = parseResponseJson(text);
            if (parsed.ok) {
                return parsed.data;
            }
            const status = r.status;
            let msg;
            if (parsed.reason === 'empty') {
                msg =
                    'رد السيرفر فارغ (HTTP ' +
                    status +
                    '). غالباً: مسار الملف غلط، أو PHP يتوقف قبل إرسال JSON، أو خطأ قاتل في السيرفر.';
            } else {
                msg =
                    'السيرفر لم يرد بـ JSON صالح (HTTP ' +
                    status +
                    '). غالباً: تحذير/خطأ PHP يظهر قبل JSON، أو مسافات/BOM قبل <?php في ملف الـ API، أو الملف محفوظ UTF-16.';
                const hint = readableSnippet(parsed.raw, 100);
                if (hint.length >= 10) {
                    msg += ' — مقتطف مقروء: ' + hint;
                }
            }
            return { success: false, message: msg };
        })
        .catch((e) => ({
            success: false,
            message: e.message || 'تعذر الاتصال بالخادم'
        }));
}

/**
 * فشل API مع suggest_admin: يعرض confirm واختياري الانتقال لشاشة الإدارة.
 * @param {object} r ناتج JSON (يجب أن يحتوي success === false)
 * @param {string} [fallbackMsg]
 * @returns {boolean} true إذا وُجد suggest وعُرض الحوار
 */
/**
 * بعد حفظ فاتورة/مردود: عرض خيار فتح القيد في «سندات أخرى» للطباعة.
 * @param {object} res استجابة API (voucher_links)
 * @param {function} [onSkip] يُستدعى عند الرفض أو عدم وجود رابط
 * @returns {boolean} true إذا انتقل المستخدم للقيد
 */
function orangeAdminOfferOpenGlVoucherAfterSave(res, onSkip) {
    var links = res && res.voucher_links;
    if (!links || !links.length) {
        if (typeof onSkip === 'function') {
            onSkip();
        }
        return false;
    }
    var primary = null;
    var i;
    for (i = 0; i < links.length; i++) {
        if (links[i].voucher_id > 0) {
            primary = links[i];
            break;
        }
    }
    if (!primary) {
        for (i = 0; i < links.length; i++) {
            if (links[i].journal_type_id > 0) {
                primary = links[i];
                break;
            }
        }
    }
    if (!primary) {
        if (typeof onSkip === 'function') {
            onSkip();
        }
        return false;
    }
    var pub = typeof window.ORANGE_PUBLIC_BASE_PATH === 'string'
        ? window.ORANGE_PUBLIC_BASE_PATH.replace(/\/+$/, '')
        : '';
    var url = pub + '/admin/index.php?page=other_vouchers&journal_type_id='
        + encodeURIComponent(String(primary.journal_type_id));
    if (primary.voucher_id > 0) {
        url += '&voucher_id=' + encodeURIComponent(String(primary.voucher_id));
    }
    var msg = (res && res.message) ? res.message : 'تم الحفظ';
    if (window.confirm(msg + '\n\nفتح القيد في «سندات أخرى» للطباعة؟')) {
        window.location.href = url;
        return true;
    }
    if (typeof onSkip === 'function') {
        onSkip();
    }
    return false;
}

function orangeAdminOfferSuggestOnFailure(r, fallbackMsg) {
    if (!r || r.success) {
        return false;
    }
    var msg = r.message || fallbackMsg || 'فشل';
    var s = r.suggest_admin;
    if (s && s.href && s.label) {
        if (window.confirm(msg + '\n\nفتح «' + s.label + '»؟')) {
            window.location.href = s.href;
        }
        return true;
    }
    return false;
}

/**
 * بعد نجاح مع تنبيهات (errors): اختيار فتح شاشة الإدارة المقترحة.
 * @returns {boolean} true إذا اختار المستخدم الانتقال (تُتخطى إعادة التحميل الفورية)
 */
function orangeAdminOfferSuggestAfterWarnings(r) {
    if (!r || !r.suggest_admin || !r.suggest_admin.href || !r.suggest_admin.label) {
        return false;
    }
    if (!r.errors || !r.errors.length) {
        return false;
    }
    if (!window.confirm('هناك تنبيهات على بعض البنود. فتح «' + r.suggest_admin.label + '»؟')) {
        return false;
    }
    window.location.href = r.suggest_admin.href;
    return true;
}

(function initAdminSidebarSections() {
    const STORAGE_KEY = 'orangeAdminNavCollapsed';

    function readStored() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw == null || raw === '') {
                return null;
            }
            const o = JSON.parse(raw);
            return o !== null && typeof o === 'object' ? o : null;
        } catch (e) {
            return null;
        }
    }

    function applySectionState(section, collapsed) {
        const btn = section.querySelector('.admin-nav-section-toggle');
        section.classList.toggle('admin-nav-section--collapsed', collapsed);
        if (btn) {
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    }

    function run() {
        const sections = document.querySelectorAll('#admin-nav-drawer .admin-nav-section');
        if (!sections.length) {
            return;
        }

        const stored = readStored();
        const anyActiveOpen = document.querySelector('#admin-nav-drawer .admin-nav-section[data-default-open="1"]');

        sections.forEach(function (section) {
            const id = section.dataset.navSection;
            const btn = section.querySelector('.admin-nav-section-toggle');
            if (!id || !btn) {
                return;
            }

            if (stored && Object.prototype.hasOwnProperty.call(stored, id)) {
                applySectionState(section, stored[id] === true);
            } else if (!stored && anyActiveOpen) {
                applySectionState(section, section.getAttribute('data-default-open') !== '1');
            } else {
                applySectionState(section, false);
            }

            btn.addEventListener('click', function () {
                const collapsed = !section.classList.contains('admin-nav-section--collapsed');
                applySectionState(section, collapsed);
                const next = {};
                document.querySelectorAll('#admin-nav-drawer .admin-nav-section').forEach(function (s) {
                    const sid = s.dataset.navSection;
                    if (sid) {
                        next[sid] = s.classList.contains('admin-nav-section--collapsed');
                    }
                });
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
                } catch (e) { /* */ }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();

(function initAdminMegaDropdowns() {
    function run() {
        var layer = document.querySelector('.admin-mega-layer');
        if (!layer) {
            return;
        }
        var backdrop = document.getElementById('admin-mega-backdrop');
        /* الأزرار داخل الشريط (.admin-topbar-mega) وليست داخل .admin-mega-layer */
        var triggers = document.querySelectorAll('.admin-topbar-mega .admin-mega-trigger[data-mega-panel]');
        var panels = layer.querySelectorAll('.admin-mega-panel');
        if (!triggers.length || !panels.length) {
            return;
        }

        function positionMegaPanel(panel, trig) {
            if (!panel || !trig) {
                return;
            }
            var prevVis = panel.style.visibility;
            panel.style.visibility = 'hidden';
            panel.removeAttribute('hidden');
            var tr = trig.getBoundingClientRect();
            var pw = panel.offsetWidth || 380;
            var ph = panel.offsetHeight || 320;
            var gap = 6;
            var pad = 8;
            var top = tr.bottom + gap;
            if (top + ph > window.innerHeight - pad) {
                top = Math.max(pad, tr.top - ph - gap);
            }
            var isRtl = (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
            var left = isRtl ? (tr.right - pw) : tr.left;
            left = Math.max(pad, Math.min(left, window.innerWidth - pw - pad));
            panel.style.top = Math.round(top) + 'px';
            panel.style.left = Math.round(left) + 'px';
            panel.style.right = 'auto';
            panel.style.visibility = prevVis || '';
        }

        function closeAll() {
            triggers.forEach(function (t) {
                t.setAttribute('aria-expanded', 'false');
            });
            panels.forEach(function (p) {
                p.setAttribute('hidden', '');
                p.style.top = '';
                p.style.left = '';
                p.style.right = '';
            });
            if (backdrop) {
                backdrop.setAttribute('hidden', '');
                backdrop.setAttribute('aria-hidden', 'true');
            }
        }

        function openPanel(id) {
            closeAll();
            var panel = document.getElementById('mega-panel-' + id);
            var trig = document.querySelector('.admin-mega-trigger[data-mega-panel="' + id + '"]');
            if (panel && trig) {
                panel.removeAttribute('hidden');
                positionMegaPanel(panel, trig);
                trig.setAttribute('aria-expanded', 'true');
                if (backdrop) {
                    backdrop.removeAttribute('hidden');
                    backdrop.setAttribute('aria-hidden', 'false');
                }
            }
        }

        window.addEventListener('resize', function () {
            panels.forEach(function (p) {
                if (p.hasAttribute('hidden')) {
                    return;
                }
                var id = (p.id || '').replace(/^mega-panel-/, '');
                var trig = document.querySelector('.admin-mega-trigger[data-mega-panel="' + id + '"]');
                if (trig) {
                    positionMegaPanel(p, trig);
                }
            });
        });

        triggers.forEach(function (tr) {
            tr.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = tr.getAttribute('data-mega-panel');
                if (!id) {
                    return;
                }
                var panel = document.getElementById('mega-panel-' + id);
                var open = panel && !panel.hasAttribute('hidden');
                if (open) {
                    closeAll();
                } else {
                    openPanel(id);
                }
            });
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                closeAll();
            });
        }

        document.addEventListener('click', function (e) {
            if (orangeAdminClosest(e, '.admin-topbar-mega') || orangeAdminClosest(e, '.admin-mega-panel')) {
                return;
            }
            closeAll();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeAll();
            }
        });

        panels.forEach(function (panel) {
            panel.querySelectorAll('a[href]').forEach(function (a) {
                a.addEventListener('click', function () {
                    closeAll();
                });
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();

(function initOrangeAcctReportsNavPins() {
    function normPath(path) {
        if (!path) return '';
        return path.length > 1 && path.charAt(path.length - 1) === '/' ? path.slice(0, -1) : path;
    }

    function stripHash(h) {
        if (!h) return '';
        return String(h).replace(/^#/, '');
    }

    function matchPinnedAcctNav(anchorEl) {
        try {
            var lu = new URL(anchorEl.href);
            var ww = window.location;
            if (normPath(lu.pathname) !== normPath(ww.pathname)) {
                return false;
            }
            var wSearch = ww.search.startsWith('?') ? ww.search.slice(1) : (ww.search || '').replace(/^\?/, '');
            var lSearch = lu.search.startsWith('?') ? lu.search.slice(1) : (lu.search || '').replace(/^\?/, '');
            var wPage = new URLSearchParams(wSearch).get('page') || '';
            var lPage = new URLSearchParams(lSearch).get('page') || '';
            if (!wPage || wPage !== lPage) return false;

            if (wPage === 'financial_report') {
                var lh = stripHash(lu.hash);
                var wh = stripHash(ww.hash);
                if (!lh) return !wh;
                return lh === wh;
            }
            if (wPage === 'partner_reports') {
                var lParams = new URLSearchParams(lSearch);
                var wParams = new URLSearchParams(wSearch);
                var linkAging = lParams.get('aging') === '1';
                var winAging = wParams.get('aging') === '1';
                if (linkAging !== winAging) return false;

                var linkView = lParams.get('view') || '';
                var winView = wParams.get('view') || '';
                if (!linkView) {
                    var lhLegacy = stripHash(lu.hash);
                    if (lhLegacy === 'partner-balances-customers') linkView = 'customers';
                    if (lhLegacy === 'partner-balances-suppliers') linkView = 'suppliers';
                }
                if (!winView) {
                    var whLegacy = stripHash(ww.hash);
                    if (whLegacy === 'partner-balances-customers') winView = 'customers';
                    if (whLegacy === 'partner-balances-suppliers') winView = 'suppliers';
                }

                return linkView === winView;
            }
        } catch (e) {
            return false;
        }
        return false;
    }

    function applyAcctReportPins() {
        var sel = 'a[data-orange-admin-nav-pin="acct-reports"]';
        document.querySelectorAll(sel).forEach(function (a) {
            a.classList.remove('is-active');
        });
        Array.prototype.slice.call(document.querySelectorAll(sel)).filter(matchPinnedAcctNav).forEach(function (a) {
            a.classList.add('is-active');
        });
    }

    function boot() {
        applyAcctReportPins();
        window.addEventListener('hashchange', applyAcctReportPins);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();

(function initAdminTopNavDrawer() {
    function run() {
        var toggle = document.getElementById('admin-menu-toggle');
        var drawer = document.getElementById('admin-nav-drawer');
        var backdrop = document.getElementById('admin-nav-backdrop');
        if (!toggle || !drawer || !backdrop) {
            return;
        }

        function isOpen() {
            return !drawer.hasAttribute('hidden');
        }

        function setOpen(open) {
            if (open) {
                drawer.removeAttribute('hidden');
                backdrop.removeAttribute('hidden');
                backdrop.setAttribute('aria-hidden', 'false');
            } else {
                drawer.setAttribute('hidden', '');
                backdrop.setAttribute('hidden', '');
                backdrop.setAttribute('aria-hidden', 'true');
            }
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function close() {
            if (isOpen()) {
                setOpen(false);
            }
        }

        toggle.addEventListener('click', function () {
            setOpen(!isOpen());
        });

        backdrop.addEventListener('click', function () {
            close();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) {
                close();
                toggle.focus();
            }
        });

        drawer.querySelectorAll('a[href]').forEach(function (a) {
            a.addEventListener('click', function () {
                close();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();

/**
 * تنقّل لوحة مفاتيح بين خلايا جداول البنود (أسهم) — يشبه سلوك شبكة الإدخال.
 * يُفعّل تلقائياً لأي tbody داخل table.admin-doc-lines-table
 */
(function initAdminDocLinesArrowNav() {
    function focusablesInCell(td) {
        if (!td || td.closest('[hidden]')) {
            return [];
        }
        return Array.prototype.slice
            .call(td.querySelectorAll('select, input:not([type="hidden"]):not([disabled]), textarea:not([disabled])'))
            .filter(function (el) {
                if (el.getAttribute('tabindex') === '-1') {
                    return false;
                }
                return el.offsetParent !== null || document.activeElement === el;
            });
    }

    function firstFocusableInCell(td) {
        var list = focusablesInCell(td);
        return list.length ? list[0] : null;
    }

    function cellPosition(tbody, el) {
        var tr = el.closest('tr');
        if (!tr || tr.parentElement !== tbody) {
            return null;
        }
        var td = el.closest('td');
        if (!td || td.parentElement !== tr) {
            return null;
        }
        var r = Array.prototype.indexOf.call(tbody.rows, tr);
        if (r < 0) {
            return null;
        }
        return { r: r, c: td.cellIndex, tr: tr, td: td };
    }

    function focusAt(tbody, r, c) {
        var tr = tbody.rows[r];
        if (!tr) {
            return null;
        }
        var td = tr.cells[c];
        if (!td) {
            return null;
        }
        var el = firstFocusableInCell(td);
        if (el) {
            el.focus();
            if (typeof el.select === 'function' && el.type !== 'number') {
                try {
                    el.select();
                } catch (e) { /* */ }
            }
            return el;
        }
        return null;
    }

    function walkCol(tbody, r, c, delta) {
        var tr = tbody.rows[r];
        if (!tr) {
            return null;
        }
        var nc = c + delta;
        while (nc >= 0 && nc < tr.cells.length) {
            if (focusAt(tbody, r, nc)) {
                return true;
            }
            nc += delta;
        }
        return false;
    }

    function walkRow(tbody, r, c, delta) {
        var nr = r + delta;
        while (nr >= 0 && nr < tbody.rows.length) {
            if (focusAt(tbody, nr, c)) {
                return true;
            }
            var tr = tbody.rows[nr];
            if (tr) {
                for (var k = 0; k < tr.cells.length; k++) {
                    if (focusAt(tbody, nr, k)) {
                        return true;
                    }
                }
            }
            nr += delta;
        }
        return false;
    }

    function onKeydown(e) {
        var key = e.key;
        if (key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'ArrowLeft' && key !== 'ArrowRight') {
            return;
        }
        if (e.altKey || e.ctrlKey || e.metaKey) {
            return;
        }
        var el = e.target;
        if (!el || !el.closest) {
            return;
        }
        var tag = (el.tagName || '').toLowerCase();
        if (tag !== 'input' && tag !== 'select' && tag !== 'textarea') {
            return;
        }
        var tbody = el.closest('table.admin-doc-lines-table tbody');
        if (!tbody) {
            return;
        }
        var pos = cellPosition(tbody, el);
        if (!pos) {
            return;
        }
        var moved = false;
        if (key === 'ArrowLeft') {
            moved = walkCol(tbody, pos.r, pos.c, -1);
        } else if (key === 'ArrowRight') {
            moved = walkCol(tbody, pos.r, pos.c, 1);
        } else if (key === 'ArrowUp') {
            moved = walkRow(tbody, pos.r, pos.c, -1);
        } else if (key === 'ArrowDown') {
            moved = walkRow(tbody, pos.r, pos.c, 1);
        }
        if (moved) {
            e.preventDefault();
            e.stopPropagation();
        }
    }

    document.addEventListener('keydown', onKeydown, true);
})();

/**
 * إشعار سريع في لوحة الإدارة (بديل أنظف عن alert للعمليات البسيطة).
 * @param {string} message
 * @param {'ok'|'err'|'info'} [kind]
 */
function orangeAdminFlash(message, kind) {
    kind = kind || 'info';
    var ex = document.getElementById('orange-admin-flash');
    if (ex) {
        ex.remove();
    }
    var el = document.createElement('div');
    el.id = 'orange-admin-flash';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.className = 'orange-admin-flash orange-admin-flash--' + kind;
    el.textContent = message || '';
    document.body.appendChild(el);
    requestAnimationFrame(function () {
        el.classList.add('orange-admin-flash--show');
    });
    clearTimeout(window.__orangeAdminFlashT);
    window.__orangeAdminFlashT = setTimeout(function () {
        el.classList.remove('orange-admin-flash--show');
        setTimeout(function () {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        }, 280);
    }, 4200);
}
