<?php

declare(strict_types=1);

/**
 * نافذة منتقي المنتجات (المتغيّرات) المشتركة — تحاكي منتقي الحساب في سند القيد.
 * تُضمَّن مرة واحدة في الصفحة. الاستخدام من JS:
 *   OrangeProductPicker.open(function (variant) { ... });
 * تستدعي variants_search وتُعيد كائن المتغيّر:
 *   { variant_id, product_id, product_name, product_name_en, color, size, stock_quantity }
 * إثراء الكود/الرصيد/التكلفة يتم عبر variant_info الخاص بكل شاشة.
 */

if (defined('ORANGE_PRODUCT_PICK_MODAL_RENDERED')) {
    return;
}
define('ORANGE_PRODUCT_PICK_MODAL_RENDERED', true);

$orangeProductPickSearchUrl = storefront_public_path('/admin/api/settings/variants_search.php');
?>
<div class="gl-pick-modal" id="orange_prod_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="orange_prod_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="orange_prod_pick_title">
        <h3 id="orange_prod_pick_title" class="gl-pick-modal__title">اختيار صنف (متغيّر)</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">ابحث بالاسم أو الكود ثم انقر للاختيار</p>
        <input type="search" id="orange_prod_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالاسم أو الكود…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="orange_prod_pick_list"></ul>
        <button type="button" class="btn-secondary" id="orange_prod_pick_close">إغلاق</button>
    </div>
</div>
<style>
.gl-pick-modal#orange_prod_pick_modal { z-index: 12100; }
</style>
<script>
window.OrangeProductPicker = (function () {
    var SEARCH_URL = <?php echo json_encode($orangeProductPickSearchUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var pickCb = null;
    var seq = 0;
    var timer = null;

    function modal() { return document.getElementById('orange_prod_pick_modal'); }
    function listEl() { return document.getElementById('orange_prod_pick_list'); }
    function qEl() { return document.getElementById('orange_prod_pick_q'); }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function close() {
        var m = modal();
        if (m) {
            m.hidden = true;
            m.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('gl-pick-open');
        pickCb = null;
    }

    function apply(v) {
        var cb = pickCb;
        close();
        if (cb) {
            cb(v);
        }
    }

    function render(variants) {
        var ul = listEl();
        if (!ul) {
            return;
        }
        if (!variants || !variants.length) {
            ul.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
            return;
        }
        ul.innerHTML = '';
        variants.forEach(function (v) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            var vlbl = [v.color, v.size].filter(function (x) { return x; }).join(' / ');
            li.textContent = (v.product_name || '') + (vlbl ? ' — ' + vlbl : '')
                + '  (#' + (v.variant_id || '') + ' · رصيد: ' + (v.stock_quantity != null ? v.stock_quantity : 0) + ')';
            function choose() { apply(v); }
            li.addEventListener('click', choose);
            li.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ' ') {
                    ev.preventDefault();
                    choose();
                }
            });
            ul.appendChild(li);
        });
    }

    function load(q) {
        var mySeq = ++seq;
        var ul = listEl();
        if (ul) {
            ul.innerHTML = '<li class="gl-pick-empty">جارٍ التحميل…</li>';
        }
        fetch(SEARCH_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store',
            body: JSON.stringify({ q: q || '', limit: 120 })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (mySeq !== seq) {
                return;
            }
            if (!data || !data.success) {
                if (ul) {
                    ul.innerHTML = '<li class="gl-pick-empty">' + esc((data && data.message) || 'تعذر التحميل') + '</li>';
                }
                return;
            }
            render(data.variants || []);
        }).catch(function (e) {
            if (ul) {
                ul.innerHTML = '<li class="gl-pick-empty">' + esc(e.message || String(e)) + '</li>';
            }
        });
    }

    function open(cb) {
        pickCb = cb || null;
        var m = modal();
        var q = qEl();
        if (!m || !q) {
            return;
        }
        q.value = '';
        m.hidden = false;
        m.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        load('');
        q.focus();
    }

    (function bind() {
        var q = qEl();
        var backdrop = document.getElementById('orange_prod_pick_backdrop');
        var closeBtn = document.getElementById('orange_prod_pick_close');
        if (q && !q.getAttribute('data-opp-bound')) {
            q.setAttribute('data-opp-bound', '1');
            q.addEventListener('input', function () {
                if (timer) {
                    clearTimeout(timer);
                }
                timer = setTimeout(function () { load(q.value.trim()); }, 280);
            });
        }
        if (backdrop) {
            backdrop.addEventListener('click', close);
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', close);
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') {
                return;
            }
            var m = modal();
            if (m && !m.hidden) {
                close();
            }
        }, true);
    })();

    return { open: open, close: close };
})();
</script>
