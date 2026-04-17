function formatMoney(v) {
    return Number(v).toFixed(2) + ' KD';
}

/**
 * سلوجان الهيدر: تناوب بين اللغات الأربع.
 * يقرأ من data-taglines على العنصر (يعمل حتى لو CSP يمنع سكربت الهيدر و window.APP_TAGLINE_CYCLE).
 */
(function rotateStorefrontTagline() {
    const TAGLINE_MS = 5000;

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

    function start() {
        if (window.__sfTaglineRotationOn) {
            return;
        }
        const el = document.getElementById('brandTaglineText');
        const msgs = collectMessages(el);
        if (!el || msgs.length < 2) {
            return;
        }
        window.__sfTaglineRotationOn = true;
        let i = 0;
        function show(idx) {
            el.textContent = msgs[idx];
        }
        show(i);
        setInterval(() => {
            i = (i + 1) % msgs.length;
            show(i);
        }, TAGLINE_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
    window.addEventListener('load', start);
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
