/**
 * اختيار منتج للعروض (سطر واحد لكل منتج — أي لون/مقاس).
 */
(function (global) {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function ensureModal() {
        if (document.getElementById('ocpp_pick_modal')) {
            return;
        }
        var wrap = document.createElement('div');
        wrap.className = 'gl-pick-modal';
        wrap.id = 'ocpp_pick_modal';
        wrap.hidden = true;
        wrap.setAttribute('aria-hidden', 'true');
        wrap.innerHTML =
            '<div class="gl-pick-modal__backdrop" id="ocpp_pick_backdrop"></div>' +
            '<div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="ocpp_pick_title">' +
            '<h3 id="ocpp_pick_title" class="gl-pick-modal__title">اختيار منتج</h3>' +
            '<p class="card-hint" style="margin:0 0 8px;">نقرتان للاختيار — العرض على المنتج كاملاً (أي لون أو مقاس)</p>' +
            '<input type="search" id="ocpp_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">' +
            '<ul class="gl-pick-modal__list" id="ocpp_pick_list"></ul>' +
            '<div class="admin-form-actions" style="margin-top:10px;">' +
            '<button type="button" class="btn-secondary" id="ocpp_pick_close">إغلاق</button></div></div>';
        document.body.appendChild(wrap);
        document.getElementById('ocpp_pick_backdrop').addEventListener('click', close);
        document.getElementById('ocpp_pick_close').addEventListener('click', close);
        document.getElementById('ocpp_pick_q').addEventListener('input', function () {
            render(state.onPick, this.value);
        });
    }

    var state = { rows: [], onPick: null };

    function close() {
        var m = document.getElementById('ocpp_pick_modal');
        if (m) {
            m.hidden = true;
            m.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('gl-pick-open');
        state.onPick = null;
    }

    function render(onPick, q) {
        var list = document.getElementById('ocpp_pick_list');
        if (!list) {
            return;
        }
        var t = String(q || '').trim().toLowerCase();
        list.innerHTML = '';
        var shown = 0;
        for (var i = 0; i < state.rows.length; i++) {
            var r = state.rows[i];
            if (t) {
                var hay = (r.code + ' ' + r.name + ' ' + r.product_id).toLowerCase();
                if (hay.indexOf(t) === -1) {
                    continue;
                }
            }
            shown++;
            list.appendChild(buildItem(r, onPick));
        }
        if (!shown) {
            list.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
        }
    }

    /* عنصر قائمة بإغلاق صحيح لكل صف (تفادي التقاط آخر r في الحلقة). */
    function buildItem(row, onPick) {
        var li = document.createElement('li');
        li.className = 'gl-pick-item';
        li.setAttribute('role', 'button');
        li.tabIndex = 0;
        li.textContent = (row.code ? row.code + ' — ' : '') + row.name;
        function choose() {
            if (typeof onPick === 'function') {
                onPick(row);
            }
            close();
        }
        li.addEventListener('dblclick', choose);
        li.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                choose();
            }
        });
        return li;
    }

    function open(rows, onPick) {
        ensureModal();
        state.rows = rows || [];
        state.onPick = onPick;
        var m = document.getElementById('ocpp_pick_modal');
        var q = document.getElementById('ocpp_pick_q');
        if (q) {
            q.value = '';
        }
        if (m) {
            m.hidden = false;
            m.setAttribute('aria-hidden', 'false');
        }
        document.body.classList.add('gl-pick-open');
        render(onPick, '');
        if (q) {
            q.focus();
        }
    }

    global.OrangeCartPromoProductPick = { open: open, close: close };
})(typeof window !== 'undefined' ? window : this);
