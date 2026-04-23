/**
 * منتقي متغيرات بصري لشاشات عروض السلة (هدايا، كومبو، BOGO).
 */
(function () {
    var state = {
        mode: 'pool',
        targetId: null,
        debounceTimer: null,
        searchSeq: 0
    };

    function $(id) {
        return document.getElementById(id);
    }

    function closePicker() {
        var overlay = $('orange-variant-picker-overlay');
        if (!overlay) {
            return;
        }
        overlay.hidden = true;
        overlay.setAttribute('aria-hidden', 'true');
        state.searchSeq += 1;
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function mergePoolText(textarea, vid) {
        var raw = textarea.value.trim();
        var parts = raw.split(/[\s,;\n]+/).filter(function (p) {
            return p.length > 0;
        });
        var set = {};
        parts.forEach(function (p) {
            set[p] = true;
        });
        var idStr = String(vid);
        if (set[idStr]) {
            return;
        }
        parts.push(idStr);
        textarea.value = parts.join(', ');
    }

    function appendLine(textarea, vid, qty) {
        var q = Math.max(1, parseInt(qty, 10) || 1);
        var line = String(vid) + ' ' + String(q);
        var cur = textarea.value.trim();
        textarea.value = cur ? cur + '\n' + line : line;
    }

    function renderRows(variants) {
        var box = $('orange-vp-results');
        if (!box) {
            return;
        }
        if (!variants || !variants.length) {
            box.innerHTML = '<p class="muted">لا نتائج. جرّب كلمات أخرى أو اترك البحث فارغاً لعرض أول النتائج.</p>';
            return;
        }
        var mode = state.mode;
        var html =
            '<div class="table-wrap orange-vp-table-wrap"><table class="orange-vp-table"><thead><tr>' +
            '<th># متغير</th><th>منتج</th><th>لون</th><th>مقاس</th><th>مخزون</th><th></th>' +
            '</tr></thead><tbody>';
        variants.forEach(function (v) {
            var vid = v.variant_id;
            var pn = v.product_name || '';
            var pen = v.product_name_en || '';
            var sub = pen && pen !== pn ? ' <span class="muted">(' + esc(pen) + ')</span>' : '';
            var label = esc(pn) + sub;
            var btnClass = mode === 'fixed' ? 'btn-secondary orange-vp-pick' : 'btn-secondary orange-vp-pick';
            var btnText = mode === 'fixed' ? 'اختيار' : mode === 'lines' ? 'إضافة سطر' : 'إضافة للقائمة';
            html +=
                '<tr data-vid="' +
                esc(String(vid)) +
                '">' +
                '<td dir="ltr"><code>' +
                esc(String(vid)) +
                '</code></td>' +
                '<td>' +
                label +
                ' <span class="muted" dir="ltr">(منتج #' +
                esc(String(v.product_id)) +
                ')</span></td>' +
                '<td>' +
                esc(v.color || '—') +
                '</td>' +
                '<td>' +
                esc(v.size || '—') +
                '</td>' +
                '<td>' +
                esc(String(v.stock_quantity)) +
                '</td>' +
                '<td><button type="button" class="' +
                btnClass +
                '" data-vid="' +
                esc(String(vid)) +
                '">' +
                btnText +
                '</button></td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        box.innerHTML = html;
        box.querySelectorAll('.orange-vp-pick').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-vid'), 10);
                if (!id) {
                    return;
                }
                var target = state.targetId ? $(state.targetId) : null;
                if (!target) {
                    return;
                }
                if (state.mode === 'fixed') {
                    target.value = String(id);
                    closePicker();
                    return;
                }
                if (state.mode === 'lines') {
                    var qtyEl = $('orange-vp-qty');
                    var qty = qtyEl ? qtyEl.value : '1';
                    appendLine(target, id, qty);
                    return;
                }
                mergePoolText(target, id);
            });
        });
    }

    function runSearch() {
        var qEl = $('orange-vp-q');
        var q = qEl ? String(qEl.value || '').trim() : '';
        var seq = ++state.searchSeq;
        var box = $('orange-vp-results');
        if (box) {
            box.innerHTML = '<p class="muted">جاري التحميل…</p>';
        }
        postJSON('/admin/api/settings/variants_search.php', { q: q, limit: 80 }).then(function (res) {
            if (seq !== state.searchSeq) {
                return;
            }
            if (!res || !res.success) {
                if (box) {
                    box.innerHTML =
                        '<p class="alert-error">' + esc((res && res.message) || 'تعذر التحميل') + '</p>';
                }
                return;
            }
            renderRows(res.variants || []);
        });
    }

    window.orangeOpenVariantPicker = function (opts) {
        opts = opts || {};
        var overlay = $('orange-variant-picker-overlay');
        if (!overlay) {
            return;
        }
        state.mode = opts.mode || 'pool';
        state.targetId = opts.targetId || null;
        var qtyWrap = $('orange-vp-qty-wrap');
        var hint = $('orange-vp-hint');
        if (state.mode === 'lines') {
            if (qtyWrap) {
                qtyWrap.hidden = false;
            }
            if (hint) {
                hint.textContent =
                    'يُضاف كل سطر بالصيغة: رقم_المتغير ثم مسافة ثم الكمية (مثل الكومبو وحزمة شراء BOGO).';
            }
        } else if (state.mode === 'fixed') {
            if (qtyWrap) {
                qtyWrap.hidden = true;
            }
            if (hint) {
                hint.textContent = 'اختر صفاً واحداً ليُنسخ رقم المتغير إلى الحقل ثم يُغلق المنتقي.';
            }
        } else {
            if (qtyWrap) {
                qtyWrap.hidden = true;
            }
            if (hint) {
                hint.textContent =
                    'أضف متغيرات واحداً تلو الآخر؛ لا يُكرر نفس الرقم إن كان موجوداً في القائمة.';
            }
        }
        overlay.hidden = false;
        overlay.setAttribute('aria-hidden', 'false');
        var qInput = $('orange-vp-q');
        if (qInput) {
            qInput.value = '';
        }
        var resBox = $('orange-vp-results');
        if (resBox) {
            resBox.innerHTML = '';
        }
        if (qInput) {
            qInput.focus();
        }
        runSearch();
    };

    document.addEventListener('DOMContentLoaded', function () {
        var overlay = $('orange-variant-picker-overlay');
        if (!overlay) {
            return;
        }
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) {
                closePicker();
            }
        });
        var closeBtn = overlay.querySelector('.orange-vp-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closePicker);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.hidden) {
                closePicker();
            }
        });
        var qEl = $('orange-vp-q');
        if (qEl) {
            qEl.addEventListener('input', function () {
                clearTimeout(state.debounceTimer);
                state.debounceTimer = setTimeout(runSearch, 320);
            });
        }
    });
})();
