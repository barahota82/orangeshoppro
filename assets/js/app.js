function formatMoney(v) {
    return Number(v).toFixed(2) + ' KD';
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

/** موبايل: هيدر ثابت + شريط سفلي يلتصق بـ visual viewport (سحب iOS / شريط العنوان / لوحة المفاتيح) */
(function pinStorefrontChrome() {
    if (!document.body.classList.contains('storefront')) return;
    const header = document.querySelector('.site-header');
    const dock = document.querySelector('.app-bottom-dock');
    const vv = window.visualViewport;
    if (!header || !dock || !vv) return;

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
        const gap = Math.max(0, window.innerHeight - vv.offsetTop - vv.height);
        dock.style.bottom = gap ? `${gap}px` : '';
    }

    vv.addEventListener('resize', sync, { passive: true });
    vv.addEventListener('scroll', sync, { passive: true });
    if (typeof mq.addEventListener === 'function') {
        mq.addEventListener('change', sync);
    } else if (typeof mq.addListener === 'function') {
        mq.addListener(sync);
    }
    window.addEventListener('orientationchange', sync, { passive: true });
    window.addEventListener('load', setHeaderHeightVar, { passive: true });
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
