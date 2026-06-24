function formatMoney(v) {
    var unit = (typeof window !== 'undefined' && window.ORANGE_SF_CURRENCY_UNIT)
        ? String(window.ORANGE_SF_CURRENCY_UNIT)
        : 'KD';
    return Number(v).toFixed(2) + ' ' + unit;
}

/**
 * تناوب نص: fade (البانر) أو slide (السلوجان). يدعم advanceOne يدويًا وربطًا بـ onFullCycle بعد دورة كاملة.
 */
function storefrontOpacityTextLoop(opts) {
    const intervalMs = opts.intervalMs || 5000;
    const fadeMs = opts.fadeMs || 400;
    const variantRaw = opts.variant || 'fade';
    const prefersReduce =
        typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const variant = prefersReduce ? 'fade' : variantRaw;
    const autoInterval = opts.autoInterval !== false;
    let intervalId = null;
    let fadeId = null;
    let idx = 0;

    function stop() {
        if (intervalId !== null) {
            clearInterval(intervalId);
            intervalId = null;
        }
        if (fadeId !== null) {
            clearTimeout(fadeId);
            fadeId = null;
        }
    }

    function resetElStyles(el) {
        el.style.transition = '';
        el.style.opacity = '1';
        el.style.transform = variant === 'slide' ? 'translateY(0)' : '';
    }

    function tick() {
        const currentEl = opts.getEl();
        if (!currentEl) {
            return;
        }
        const currentMsgs = opts.getMsgs();
        if (currentMsgs.length < 2) {
            return;
        }

        const len = currentMsgs.length;
        const prevIdx = idx;
        const ease = 'cubic-bezier(0.4, 0, 0.2, 1)';
        const tFade = `opacity ${fadeMs}ms ${ease}`;
        const tSlide = variant === 'slide' ? `, transform ${fadeMs}ms ${ease}` : '';

        function afterStep(newIdx) {
            idx = newIdx;
            if (typeof opts.onFullCycle === 'function' && prevIdx === len - 1 && newIdx === 0) {
                try {
                    opts.onFullCycle();
                } catch (e) {
                    /* ignore */
                }
            }
        }

        if (variant === 'slide') {
            currentEl.style.transition = tFade + tSlide;
            currentEl.style.opacity = '0';
            currentEl.style.transform = 'translateY(-0.35rem)';
            fadeId = setTimeout(() => {
                const newIdx = (idx + 1) % len;
                afterStep(newIdx);
                currentEl.textContent = currentMsgs[newIdx];
                currentEl.style.transition = 'none';
                currentEl.style.opacity = '0';
                currentEl.style.transform = 'translateY(0.35rem)';
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        currentEl.style.transition = tFade + tSlide;
                        currentEl.style.opacity = '1';
                        currentEl.style.transform = 'translateY(0)';
                        fadeId = null;
                    });
                });
            }, fadeMs);
        } else {
            currentEl.style.transition = tFade;
            currentEl.style.opacity = '0';
            fadeId = setTimeout(() => {
                const newIdx = (idx + 1) % len;
                afterStep(newIdx);
                currentEl.textContent = currentMsgs[newIdx];
                currentEl.style.opacity = '1';
                currentEl.style.transform = '';
                fadeId = null;
            }, fadeMs);
        }
    }

    function start() {
        stop();
        const el = opts.getEl();
        const msgs = opts.getMsgs();
        if (!el || msgs.length < 2) {
            return;
        }
        idx = 0;
        el.textContent = msgs[idx % msgs.length];
        resetElStyles(el);

        if (autoInterval) {
            intervalId = setInterval(tick, intervalMs);
        }
    }

    function isActive() {
        return intervalId !== null;
    }

    return { start, stop, isActive, advanceOne: tick };
}

/**
 * سلوجان الهيدر: على الصفحة الرئيسية يتقدّم مرة واحدة بعد كل دورة كاملة لجمل البانر (ثم الإعادة).
 * على باقي الصفحات: تناوب تلقائي بمدة أطول.
 */
(function rotateStorefrontTagline() {
    const TAGLINE_FALLBACK_MS = 10000;
    const FADE_MS = 550;

    function parseHeroLineCount(raw) {
        if (!raw || typeof raw !== 'string') {
            return 0;
        }
        try {
            const parsed = JSON.parse(raw.trim());
            if (!Array.isArray(parsed)) {
                return 0;
            }
            return parsed.filter((s) => typeof s === 'string' && s.trim() !== '').length;
        } catch (e) {
            return 0;
        }
    }

    function syncTaglineToHomeHero() {
        const heroRot = document.getElementById('homeHeroRotator');
        const ta = document.getElementById('home-hero-lines-json');
        const n = ta && ta.value ? parseHeroLineCount(ta.value) : 0;
        return !!(heroRot && n >= 2);
    }

    const syncToHero = syncTaglineToHomeHero();

    function parseList(jsonStr) {
        if (!jsonStr || typeof jsonStr !== 'string') {
            return [];
        }
        try {
            const parsed = JSON.parse(jsonStr.trim());
            if (!Array.isArray(parsed)) {
                return [];
            }
            return parsed.filter((t) => typeof t === 'string' && t.trim() !== '');
        } catch (e) {
            return [];
        }
    }

    function collectMessages(el) {
        const ta = document.getElementById('storefront-tagline-json');
        if (ta && ta.value) {
            const fromTa = parseList(ta.value);
            if (fromTa.length >= 2) {
                return fromTa;
            }
        }
        const raw = el && el.dataset ? el.dataset.taglines : '';
        if (raw) {
            const fromData = parseList(raw);
            if (fromData.length >= 2) {
                return fromData;
            }
        }
        const w = window.APP_TAGLINE_CYCLE;
        if (!Array.isArray(w)) {
            return [];
        }
        return w.filter((t) => typeof t === 'string' && t.trim() !== '');
    }

    const loop = storefrontOpacityTextLoop({
        intervalMs: TAGLINE_FALLBACK_MS,
        fadeMs: FADE_MS,
        variant: 'slide',
        autoInterval: !syncToHero,
        getEl: () => document.getElementById('brandTaglineText'),
        getMsgs: () => collectMessages(document.getElementById('brandTaglineText')),
    });

    if (syncToHero) {
        window.addEventListener('orange:home-hero-full-cycle', () => loop.advanceOne());
    }

    function bootTagline() {
        loop.start();
        if (syncToHero) {
            return;
        }
        if (!loop.isActive()) {
            setTimeout(() => loop.start(), 120);
            setTimeout(() => loop.start(), 600);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootTagline);
    } else {
        bootTagline();
    }
    window.addEventListener('load', bootTagline);
    window.addEventListener('pageshow', (ev) => {
        if (ev.persisted) {
            loop.stop();
            loop.start();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') {
            return;
        }
        if (!window.matchMedia('(max-width: 1023px)').matches) {
            return;
        }
        if (!document.getElementById('brandTaglineText')) {
            return;
        }
        loop.stop();
        loop.start();
    });
})();

/** الصفحة الرئيسية: 3 جمل بالتناوب حسب لغة الواجهة */
(function rotateHomeHero() {
    const HERO_MS = 5000;

    function parseHeroLines(raw) {
        if (!raw || typeof raw !== 'string') {
            return [];
        }
        try {
            const parsed = JSON.parse(raw.trim());
            if (!Array.isArray(parsed)) {
                return [];
            }
            return parsed.filter((s) => typeof s === 'string' && s.trim() !== '');
        } catch (e) {
            return [];
        }
    }

    const loop = storefrontOpacityTextLoop({
        intervalMs: HERO_MS,
        fadeMs: 400,
        onFullCycle: () => {
            window.dispatchEvent(new CustomEvent('orange:home-hero-full-cycle'));
        },
        getEl: () => document.getElementById('homeHeroRotator'),
        getMsgs: () => {
            const ta = document.getElementById('home-hero-lines-json');
            return ta && ta.value ? parseHeroLines(ta.value) : [];
        },
    });

    function bootHero() {
        if (!document.getElementById('homeHeroRotator')) {
            return;
        }
        loop.start();
        if (!loop.isActive()) {
            setTimeout(() => loop.start(), 120);
            setTimeout(() => loop.start(), 600);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootHero);
    } else {
        bootHero();
    }
    window.addEventListener('load', bootHero);
    window.addEventListener('pageshow', (ev) => {
        if (ev.persisted && document.getElementById('homeHeroRotator')) {
            loop.stop();
            loop.start();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible' || !document.getElementById('homeHeroRotator')) {
            return;
        }
        if (!window.matchMedia('(max-width: 1023px)').matches) {
            return;
        }
        loop.stop();
        loop.start();
    });
})();

function changeMainImage(src, btn) {
    const main = document.getElementById('mainProductImage');
    if (main) main.src = src;
    document.querySelectorAll('.thumb').forEach(t => t.classList.remove('active'));
    if (btn) btn.classList.add('active');
}

/** موبايل: هيدر ثابت + مسافة site-main؛ الشريط السفلي يبقى على bottom:0 من CSS فقط.
 *  ضبط bottom عبر visualViewport كان يسبب قيماً كبيرة على بعض المتصفحات فيخفي الشريط بالكامل. */
(function pinStorefrontChrome() {
    if (!document.body.classList.contains('storefront')) return;
    const header = document.querySelector('.site-header');
    const dock = document.querySelector('.app-bottom-dock');
    if (!header || !dock) return;

    const mq = window.matchMedia('(max-width: 1023px)');

    function setHeaderHeightVar() {
        if (!mq.matches) {
            document.documentElement.style.removeProperty('--sf-fixed-header-h');
            return;
        }
        document.documentElement.style.setProperty('--sf-fixed-header-h', `${header.offsetHeight}px`);
    }

    function sync() {
        if (!mq.matches) {
            dock.style.removeProperty('bottom');
            document.documentElement.style.removeProperty('--sf-fixed-header-h');
            return;
        }
        setHeaderHeightVar();
        dock.style.removeProperty('bottom');
    }

    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', sync);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(sync);
    }
    window.addEventListener('orientationchange', sync, { passive: true });
    window.addEventListener('load', sync, { passive: true });
    window.addEventListener('pageshow', sync);
    if (typeof ResizeObserver !== 'undefined') {
        const ro = new ResizeObserver(setHeaderHeightVar);
        ro.observe(header);
    }
    sync();
})();

/** أزرار «تثبيت» (هيدر + شريط سفلي): Chrome/Android يعرض مطالبة التثبيت؛ iOS يعرض خطوات يدوية. */
(function orangeStorefrontInstallPrompt() {
    if (!document.body.classList.contains('storefront')) {
        return;
    }
    const btns = document.querySelectorAll('[data-orange-install-app]');
    const modal = document.getElementById('orangeInstallModal');
    if (!btns.length || !modal) {
        return;
    }
    const T = window.APP_T || {};
    const titleEl = document.getElementById('orangeInstallModalTitle');
    const introEl = document.getElementById('orangeInstallModalIntro');
    const stepsEl = document.getElementById('orangeInstallModalSteps');
    const okEl = document.getElementById('orangeInstallModalOk');
    const backdrop = modal.querySelector('.orange-install-modal__backdrop');
    let deferredPrompt = null;
    let lastInstallBtn = null;

    /** PWA مفتوح من أيقونة الشاشة: إخفاء التثبيت. نوسّع الفحص لأن بعض المتصفحات لا تُبلغ standalone فقط. */
    function isStandalone() {
        try {
            if (window.navigator.standalone === true) {
                return true;
            }
        } catch (err) {
            /* ignore */
        }
        if (!window.matchMedia) {
            return false;
        }
        const modes = ['standalone', 'fullscreen', 'minimal-ui', 'window-controls-overlay'];
        for (let i = 0; i < modes.length; i += 1) {
            try {
                if (window.matchMedia(`(display-mode: ${modes[i]})`).matches) {
                    return true;
                }
            } catch (err) {
                /* قديم لا يدعم القيمة */
            }
        }
        return false;
    }

    function syncInstallButtonVisibility() {
        const hide = isStandalone();
        btns.forEach((b) => {
            b.hidden = hide;
        });
        document.documentElement.classList.toggle('orange-standalone-pwa', hide);
        return hide;
    }

    function isAppleMobile() {
        const ua = navigator.userAgent || '';
        if (/iPad|iPhone|iPod/i.test(ua)) {
            return true;
        }
        return navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    }

    function fillModal() {
        if (titleEl) {
            titleEl.textContent = T.storefront_install_modal_title || '';
        }
        if (introEl) {
            introEl.textContent = T.storefront_install_modal_intro || '';
        }
        if (stepsEl) {
            const steps = isAppleMobile()
                ? T.storefront_install_ios_steps || ''
                : T.storefront_install_other_steps || '';
            stepsEl.textContent = steps;
        }
        if (okEl) {
            okEl.textContent = T.storefront_install_close || 'OK';
        }
    }

    function openModal() {
        fillModal();
        modal.hidden = false;
        document.documentElement.classList.add('orange-install-modal-open');
        if (okEl) {
            okEl.focus();
        }
    }

    function closeModal() {
        modal.hidden = true;
        document.documentElement.classList.remove('orange-install-modal-open');
        const back = lastInstallBtn && document.contains(lastInstallBtn) ? lastInstallBtn : btns[0];
        if (back && !back.hidden) {
            back.focus();
        }
    }

    syncInstallButtonVisibility();
    window.addEventListener('load', syncInstallButtonVisibility, { passive: true });
    window.addEventListener('pageshow', syncInstallButtonVisibility, { passive: true });

    window.addEventListener('beforeinstallprompt', (e) => {
        if (isStandalone()) {
            return;
        }
        e.preventDefault();
        deferredPrompt = e;
    });

    btns.forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (isStandalone()) {
                return;
            }
            lastInstallBtn = btn;
            if (deferredPrompt) {
                try {
                    deferredPrompt.prompt();
                    await deferredPrompt.userChoice;
                } catch (err) {
                    /* ignore */
                }
                deferredPrompt = null;
                return;
            }
            openModal();
        });
    });

    if (okEl) {
        okEl.addEventListener('click', closeModal);
    }
    if (backdrop) {
        backdrop.addEventListener('click', closeModal);
    }
    modal.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal();
        }
    });
})();

/**
 * قائمة مناطق التوصيل (س8): يستبدل حقل النص بـ select عند توفر مناطق من الأدمن.
 */
function orangeReplaceInputWithDeliveryAreaSelect(inputId, areasList) {
    if (!inputId || !Array.isArray(areasList) || areasList.length === 0) {
        return;
    }
    const input = document.getElementById(inputId);
    if (!input || input.tagName === 'SELECT') {
        return;
    }
    const sel = document.createElement('select');
    sel.id = inputId;
    sel.setAttribute('autocomplete', 'address-level1');
    if (input.name) {
        sel.name = input.name;
    }
    if (input.required) {
        sel.required = true;
    }
    if (input.className) {
        sel.className = input.className;
    }
    const ariaLbl = input.getAttribute('aria-label');
    if (ariaLbl) {
        sel.setAttribute('aria-label', ariaLbl);
    }
    const ph = document.createElement('option');
    ph.value = '';
    ph.textContent =
        (typeof window.APP_T === 'object' && window.APP_T && window.APP_T.checkout_select_area) || '';
    sel.appendChild(ph);
    const comboRows = [];
    for (let i = 0; i < areasList.length; i++) {
        const a = areasList[i];
        if (!a || a.id == null) {
            continue;
        }
        const label = a.name != null ? String(a.name) : '';
        const o = document.createElement('option');
        o.value = String(a.id);
        o.textContent = label;
        sel.appendChild(o);
        comboRows.push({ value: String(a.id), label: label, filterText: label });
    }
    input.parentNode.replaceChild(sel, input);
    const T = typeof window.APP_T === 'object' && window.APP_T ? window.APP_T : {};
    if (typeof window.orangeAttachSearchableCombobox === 'function' && comboRows.length > 0) {
        window.orangeAttachSearchableCombobox(sel, comboRows, {
            placeholder: T.checkout_select_area || '',
            openListAria: T.delivery_area_open_list || T.phone_country_open_list || 'Open list',
            inputDir: 'auto',
        });
    }
}

/**
 * عند بناء قائمة المناطق من الخادم (‎<select>‎ جاهز): يُفعَّل نفس البحث القابل للفلترة كمسار الاستبدال من حقل نصّي.
 */
function orangeEnhanceDeliveryAreaSelect(selectId, areasList, opts) {
    if (!selectId || !Array.isArray(areasList) || areasList.length === 0) {
        return;
    }
    var sel = document.getElementById(selectId);
    if (!sel || sel.tagName !== 'SELECT' || sel.disabled) {
        return;
    }
    if (sel.dataset && sel.dataset.orangeSearchableCombobox === '1') {
        return;
    }
    opts = opts || {};
    var savedAreas = Array.isArray(opts.savedAreas) ? opts.savedAreas : [];
    var T = typeof window.APP_T === 'object' && window.APP_T ? window.APP_T : {};

    var nameById = {};
    var allRows = [];
    for (var i = 0; i < areasList.length; i++) {
        var a = areasList[i];
        if (!a || a.id == null) {
            continue;
        }
        var id = String(a.id);
        var label = a.name != null ? String(a.name) : '';
        nameById[id] = label;
        allRows.push({ value: id, label: label, filterText: label });
    }
    if (!allRows.length || typeof window.orangeAttachSearchableCombobox !== 'function') {
        return;
    }

    // بند (5): العناوين السابقة — تُثبَّت أعلى القائمة (المناطق المفعّلة فقط) + خريطة العنوان للتعبئة التلقائية.
    var savedRows = [];
    var addressById = {};
    var seenSaved = {};
    for (var k = 0; k < savedAreas.length; k++) {
        var sv = savedAreas[k];
        if (!sv || sv.id == null) {
            continue;
        }
        var sid = String(sv.id);
        if (seenSaved[sid] || !Object.prototype.hasOwnProperty.call(nameById, sid)) {
            continue;
        }
        seenSaved[sid] = true;
        savedRows.push({ value: sid, label: nameById[sid], filterText: nameById[sid] });
        addressById[sid] = sv.address != null ? String(sv.address) : '';
    }

    var comboRows;
    if (savedRows.length > 0) {
        comboRows = [{ isHeader: true, label: T.checkout_saved_areas_title || 'عناوين سابقة' }];
        for (var s = 0; s < savedRows.length; s++) {
            comboRows.push(savedRows[s]);
        }
        comboRows.push({ isHeader: true, label: T.checkout_all_areas_title || 'كل المناطق' });
        for (var s2 = 0; s2 < allRows.length; s2++) {
            comboRows.push(allRows[s2]);
        }

        if (!sel.dataset.orangeSavedAddrBound) {
            sel.dataset.orangeSavedAddrBound = '1';
            sel.addEventListener('change', function () {
                var v = sel.value;
                if (!v || !Object.prototype.hasOwnProperty.call(addressById, v)) {
                    return;
                }
                var addr = addressById[v];
                if (!addr) {
                    return;
                }
                var addrEl = document.getElementById('customer_address');
                if (addrEl) {
                    addrEl.value = addr;
                    try {
                        addrEl.dispatchEvent(new Event('input', { bubbles: true }));
                    } catch (eIn) {}
                }
            });
        }
    } else {
        comboRows = allRows;
    }

    window.orangeAttachSearchableCombobox(sel, comboRows, {
        placeholder: T.checkout_select_area || '',
        openListAria: T.delivery_area_open_list || T.phone_country_open_list || 'Open list',
        inputDir: 'auto',
    });
}
window.orangeReplaceInputWithDeliveryAreaSelect = orangeReplaceInputWithDeliveryAreaSelect;
window.orangeEnhanceDeliveryAreaSelect = orangeEnhanceDeliveryAreaSelect;

/** كاش CSS/JS للواجهة — يخفّف بطء التنقل في نافذة PWA مقارنةً بتبويب المتصفح */
(function orangeStorefrontRegisterServiceWorker() {
    if (!document.body || !document.body.classList.contains('storefront')) {
        return;
    }
    if (!('serviceWorker' in navigator)) {
        return;
    }
    const base = typeof window.STOREFRONT_BASE === 'string' ? window.STOREFRONT_BASE : '';
    const url = `${base}/service-worker.php`;
    const scope = base === '' ? '/' : `${base}/`;
    navigator.serviceWorker.register(url, { scope }).catch(() => {});
})();
