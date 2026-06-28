/*
 * منتقي «الألوان المسموحة لكل مكوّن» — مشترك بين شاشتي الكومبو وBOGO.
 * تقييد عرض فقط على صفحة العرض (لا يُغيّر أهلية الخصم؛ المطابقة بالمنتج والكمية).
 * يخزّن المفاتيح المختارة في tr.dataset.allowedColors (JSON) ويقرأها باني الصفوف.
 * يُضمَّن داخل وسم <script> قائم؛ لذا هو JS خام بلا وسوم (مثل cart_promo_schedule_js).
 */
window.OrangePromoColors = (function () {
    var cache = {};

    function loadColors(pid, cb) {
        pid = parseInt(pid, 10) || 0;
        if (pid <= 0) { cb([], 0); return; }
        if (cache[pid]) { cb(cache[pid].colors, cache[pid].has_colors); return; }
        fetch('/admin/api/cart_promotions/component_colors.php?id=' + pid, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var colors = (d && d.success && Array.isArray(d.colors)) ? d.colors : [];
                var has = (d && d.has_colors) ? 1 : 0;
                cache[pid] = { colors: colors, has_colors: has };
                cb(colors, has);
            })
            .catch(function () { cb([], 0); });
    }

    function selectedOf(tr) {
        try {
            var raw = tr.getAttribute('data-allowed-colors') || '[]';
            var arr = JSON.parse(raw);
            return Array.isArray(arr) ? arr.map(String) : [];
        } catch (e) { return []; }
    }

    function setSelected(tr, keys) {
        tr.setAttribute('data-allowed-colors', JSON.stringify(keys || []));
    }

    function updateBtnLabel(btn, tr) {
        var n = selectedOf(tr).length;
        btn.textContent = n > 0 ? ('ألوان (' + n + ')') : 'كل الألوان';
    }

    function attach(tr, td, pid, selected) {
        setSelected(tr, Array.isArray(selected) ? selected.map(String) : []);
        td.innerHTML = '';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-secondary';
        btn.style.fontSize = '12px';
        btn.style.padding = '4px 8px';
        var box = document.createElement('div');
        box.style.display = 'none';
        box.style.marginTop = '6px';
        box.style.maxHeight = '160px';
        box.style.overflow = 'auto';
        box.style.border = '1px solid var(--border, #ddd)';
        box.style.borderRadius = '6px';
        box.style.padding = '6px';
        box.style.background = 'var(--surface, #fff)';
        var loaded = false;

        function renderChecks(colors, hasColors) {
            box.innerHTML = '';
            if (!hasColors || !colors.length) {
                var p = document.createElement('p');
                p.className = 'page-subtitle';
                p.style.margin = '0';
                p.textContent = 'لا ألوان لهذا المنتج (سيظهر بكل متغيّراته).';
                box.appendChild(p);
                return;
            }
            var sel = selectedOf(tr);
            var selSet = {};
            sel.forEach(function (k) { selSet[k] = true; });
            colors.forEach(function (c) {
                var key = String(c.key != null ? c.key : '');
                if (key === '') { return; }
                var lab = document.createElement('label');
                lab.style.display = 'flex';
                lab.style.gap = '8px';
                lab.style.alignItems = 'center';
                lab.style.padding = '3px 0';
                lab.style.cursor = 'pointer';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.value = key;
                cb.checked = !!selSet[key];
                cb.addEventListener('change', function () {
                    var keys = [];
                    box.querySelectorAll('input[type="checkbox"]').forEach(function (x) {
                        if (x.checked) { keys.push(x.value); }
                    });
                    setSelected(tr, keys);
                    updateBtnLabel(btn, tr);
                });
                var span = document.createElement('span');
                span.textContent = String(c.label != null && c.label !== '' ? c.label : key);
                lab.appendChild(cb);
                lab.appendChild(span);
                box.appendChild(lab);
            });
        }

        btn.addEventListener('click', function () {
            if (!loaded) {
                btn.disabled = true;
                loadColors(pid, function (colors, hasColors) {
                    loaded = true;
                    btn.disabled = false;
                    renderChecks(colors, hasColors);
                    box.style.display = 'block';
                });
                return;
            }
            box.style.display = (box.style.display === 'none') ? 'block' : 'none';
        });

        updateBtnLabel(btn, tr);
        td.appendChild(btn);
        td.appendChild(box);
    }

    return { attach: attach, selectedOf: selectedOf, load: loadColors };
})();
