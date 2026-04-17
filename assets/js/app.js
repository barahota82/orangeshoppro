function formatMoney(v) {
    return Number(v).toFixed(2) + ' KD';
}

/**
 * تناوب نص مع تلاشي — نفس آلية https://clickstorekw.com/ (setInterval + opacity + setTimeout).
 */
function storefrontOpacityTextLoop(opts) {
    const intervalMs = opts.intervalMs || 5000;
    const fadeMs = opts.fadeMs || 400;
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

    function start() {
        stop();
        const el = opts.getEl();
        const msgs = opts.getMsgs();
        if (!el || msgs.length < 2) {
            return;
        }
        el.style.opacity = '1';
        idx = 0;
        el.textContent = msgs[idx % msgs.length];

        intervalId = setInterval(() => {
            const currentEl = opts.getEl();
            if (!currentEl) {
                return;
            }
            const currentMsgs = opts.getMsgs();
            if (currentMsgs.length < 2) {
                return;
            }
            currentEl.style.opacity = '0';
            fadeId = setTimeout(() => {
                idx = (idx + 1) % currentMsgs.length;
                currentEl.textContent = currentMsgs[idx];
                currentEl.style.opacity = '1';
                fadeId = null;
            }, fadeMs);
        }, intervalMs);
    }

    function isActive() {
        return intervalId !== null;
    }

    return { start, stop, isActive };
}

/**
 * سلوجان الهيدر: عربي → إنجليزي → فلبيني → هندي (مصدر النصوص من config / textarea).
 */
(function rotateStorefrontTagline() {
    const TAGLINE_MS = 5000;
    const FADE_MS = 400;

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
        intervalMs: TAGLINE_MS,
        fadeMs: FADE_MS,
        getEl: () => document.getElementById('brandTaglineText'),
        getMsgs: () => collectMessages(document.getElementById('brandTaglineText')),
    });

    function bootTagline() {
        loop.start();
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
