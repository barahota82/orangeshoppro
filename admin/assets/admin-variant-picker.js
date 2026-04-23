/**
 * منتقي متغيرات بصري لشاشات عروض السلة (هدايا، كومبو، BOGO).
 * تصفية: قسم → فئة → فئة فرعية + بحث نصي.
 */
(function () {
    var state = {
        mode: 'pool',
        targetId: null,
        debounceTimer: null,
        searchSeq: 0
    };

    var filterTreeCache = null;
    var filterTreeLoading = null;

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

    function loadFilterTree() {
        if (filterTreeCache) {
            return Promise.resolve(filterTreeCache);
        }
        if (filterTreeLoading) {
            return filterTreeLoading;
        }
        filterTreeLoading = postJSON('/admin/api/settings/variants_search.php', { action: 'filter_tree' }).then(
            function (res) {
                filterTreeLoading = null;
                if (res && res.success) {
                    filterTreeCache = res;
                    return res;
                }
                return res;
            }
        );
        return filterTreeLoading;
    }

    function syncDeptVisibility() {
        var w = $('orange-vp-dept-wrap');
        if (!w) {
            return;
        }
        var deps = filterTreeCache && filterTreeCache.departments ? filterTreeCache.departments : [];
        w.style.display = deps.length ? '' : 'none';
    }

    function fillDepartmentsFromCache() {
        var ds = $('orange-vp-dept');
        if (!ds || !filterTreeCache) {
            return;
        }
        ds.innerHTML = '<option value="">— كل الأقسام —</option>';
        (filterTreeCache.departments || []).forEach(function (d) {
            var o = document.createElement('option');
            o.value = String(d.id);
            var lab = (d.name_ar || d.name_en || '#' + d.id).trim();
            o.textContent = lab + ' (#' + d.id + ')';
            ds.appendChild(o);
        });
        ds.value = '';
    }

    function rebuildCategoryOptions() {
        var sel = $('orange-vp-cat');
        if (!sel || !filterTreeCache) {
            return;
        }
        var deptVal = ($('orange-vp-dept') && $('orange-vp-dept').value) || '';
        var cats = filterTreeCache.categories || [];
        var filtered = cats;
        if (deptVal !== '') {
            var di = parseInt(deptVal, 10);
            filtered = cats.filter(function (c) {
                return c.department_id != null && parseInt(c.department_id, 10) === di;
            });
        }
        var prev = sel.value;
        sel.innerHTML = '<option value="">— كل الفئات —</option>';
        filtered.forEach(function (c) {
            var o = document.createElement('option');
            o.value = String(c.id);
            var lab = (c.name_ar || c.name_en || '#' + c.id).trim();
            o.textContent = lab + ' (#' + c.id + ')';
            sel.appendChild(o);
        });
        if (prev && filtered.some(function (c) { return String(c.id) === prev; })) {
            sel.value = prev;
        } else {
            sel.value = '';
        }
        rebuildSubcategoryOptions();
    }

    function rebuildSubcategoryOptions() {
        var sel = $('orange-vp-sub');
        if (!sel || !filterTreeCache) {
            return;
        }
        var catVal = ($('orange-vp-cat') && $('orange-vp-cat').value) || '';
        var subs = filterTreeCache.subcategories || [];
        var filtered =
            catVal === ''
                ? []
                : subs.filter(function (s) {
                      return String(s.category_id) === String(catVal);
                  });
        var prev = sel.value;
        sel.innerHTML = '<option value="">— كل الفئات الفرعية —</option>';
        filtered.forEach(function (s) {
            var o = document.createElement('option');
            o.value = String(s.id);
            var lab = (s.name_ar || s.name_en || '#' + s.id).trim();
            o.textContent = lab + ' (#' + s.id + ')';
            sel.appendChild(o);
        });
        if (prev && filtered.some(function (s) { return String(s.id) === prev; })) {
            sel.value = prev;
        } else {
            sel.value = '';
        }
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
            box.innerHTML =
                '<p class="muted">لا نتائج ضمن التصفية والبحث الحاليين. جرّب «مسح التصفية» أو نصاً أوسع.</p>';
            return;
        }
        var mode = state.mode;
        var html =
            '<div class="table-wrap orange-vp-table-wrap"><table class="orange-vp-table"><thead><tr>' +
            '<th># متغير</th><th>منتج / هدية</th><th>لون</th><th>مقاس</th><th>مخزون</th><th></th>' +
            '</tr></thead><tbody>';
        variants.forEach(function (v) {
            var vid = v.variant_id;
            var pn = v.product_name || '';
            var pen = v.product_name_en || '';
            var sub = pen && pen !== pn ? ' <span class="muted">(' + esc(pen) + ')</span>' : '';
            var label = esc(pn) + sub;
            var btnClass = 'btn-secondary orange-vp-pick';
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
        var fd = 0;
        var fc = 0;
        var fs = 0;
        var de = $('orange-vp-dept');
        var ce = $('orange-vp-cat');
        var se = $('orange-vp-sub');
        if (de && de.value) {
            fd = parseInt(de.value, 10) || 0;
        }
        if (ce && ce.value) {
            fc = parseInt(ce.value, 10) || 0;
        }
        if (se && se.value) {
            fs = parseInt(se.value, 10) || 0;
        }
        postJSON('/admin/api/settings/variants_search.php', {
            q: q,
            limit: 80,
            department_id: fd,
            category_id: fc,
            subcategory_id: fs
        }).then(function (res) {
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
                    'يُضاف كل سطر بالصيغة: رقم_المتغير ثم مسافة ثم الكمية (مثل الكومبو وحزمة شراء BOGO). صفوف المنتجات أدناه هي نفس أصناف المتجر (الهدية منتج بمتغير لون/مقاس).';
            }
        } else if (state.mode === 'fixed') {
            if (qtyWrap) {
                qtyWrap.hidden = true;
            }
            if (hint) {
                hint.textContent =
                    'صفِّ القسم/الفئة/الفرعية ثم اختر المتغير؛ الهدية في النظام = منتج نشط كأي صنف.';
            }
        } else {
            if (qtyWrap) {
                qtyWrap.hidden = true;
            }
            if (hint) {
                hint.textContent =
                    'صفِّ الهيكل أو ابحث؛ أضف متغيرات للقائمة دون تكرار. الهدية والمنتج المعروض للبيع يستخدمان نفس الجدول.';
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
            resBox.innerHTML = '<p class="muted">جاري تحميل التصفية…</p>';
        }

        loadFilterTree()
            .then(function (res) {
                if (!res || !res.success) {
                    if (resBox) {
                        resBox.innerHTML =
                            '<p class="alert-error">' +
                            esc((res && res.message) || 'تعذر تحميل أقسام الفئات') +
                            '</p>';
                    }
                    return;
                }
                filterTreeCache = res;
                fillDepartmentsFromCache();
                syncDeptVisibility();
                var catEl = $('orange-vp-cat');
                var subEl = $('orange-vp-sub');
                var deptEl = $('orange-vp-dept');
                if (deptEl) {
                    deptEl.value = '';
                }
                if (catEl) {
                    catEl.value = '';
                }
                if (subEl) {
                    subEl.value = '';
                }
                rebuildCategoryOptions();
                if (qInput) {
                    qInput.focus();
                }
                runSearch();
            })
            .catch(function () {
                if (resBox) {
                    resBox.innerHTML = '<p class="alert-error">تعذر الاتصال بالخادم</p>';
                }
            });
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
        var deptEl = $('orange-vp-dept');
        if (deptEl) {
            deptEl.addEventListener('change', function () {
                var cat = $('orange-vp-cat');
                var sub = $('orange-vp-sub');
                if (cat) {
                    cat.value = '';
                }
                if (sub) {
                    sub.value = '';
                }
                rebuildCategoryOptions();
                runSearch();
            });
        }
        var catEl = $('orange-vp-cat');
        if (catEl) {
            catEl.addEventListener('change', function () {
                var sub = $('orange-vp-sub');
                if (sub) {
                    sub.value = '';
                }
                rebuildSubcategoryOptions();
                runSearch();
            });
        }
        var subEl = $('orange-vp-sub');
        if (subEl) {
            subEl.addEventListener('change', function () {
                runSearch();
            });
        }
        var clr = $('orange-vp-clear-filters');
        if (clr) {
            clr.addEventListener('click', function () {
                if (deptEl) {
                    deptEl.value = '';
                }
                if (catEl) {
                    catEl.value = '';
                }
                if (subEl) {
                    subEl.value = '';
                }
                rebuildCategoryOptions();
                runSearch();
            });
        }
    });
})();
