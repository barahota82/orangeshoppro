function formatMoney(v) {
    return Number(v).toFixed(2) + ' KD';
}

/** سلوجان الهيدر: تناوب بين en → ar → fil → hi */
(function rotateStorefrontTagline() {
    const msgs = window.APP_TAGLINE_CYCLE;
    if (!Array.isArray(msgs) || msgs.length < 2) return;
    const el = document.getElementById('brandTaglineText');
    if (!el) return;
    let i = typeof window.APP_TAGLINE_START === 'number' ? window.APP_TAGLINE_START : 0;
    if (i < 0 || i >= msgs.length) i = 0;

    function show(idx) {
        const t = msgs[idx];
        if (typeof t !== 'string') return;
        el.textContent = t;
    }

    show(i);
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    setInterval(() => {
        i = (i + 1) % msgs.length;
        show(i);
    }, 4500);
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
