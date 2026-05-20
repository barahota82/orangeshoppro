/**
 * Searchable select combobox (shared): country codes + delivery areas.
 * Country: fills <select data-orange-country-codes> from window.COUNTRY_CODES.
 * Delivery: app.js calls orangeAttachSearchableCombobox after building the select.
 */
(function () {
    function storefrontFlagToRegion(flagValue) {
        var symbols = Array.from(String(flagValue || ''));
        if (symbols.length < 2) {
            return '';
        }
        var region = '';
        for (var i = 0; i < 2; i++) {
            var cp = symbols[i].codePointAt(0);
            if (typeof cp !== 'number' || cp < 0x1f1e6 || cp > 0x1f1ff) {
                return '';
            }
            region += String.fromCharCode(65 + (cp - 0x1f1e6));
        }
        return region;
    }

    /** Country name for combobox: Arabic when APP_LANG=ar, else English from COUNTRY_CODES. */
    function orangeStorefrontCountryLabel(c) {
        if (!c) {
            return '';
        }
        var lang = String((window.APP_LANG || 'en')).toLowerCase();
        if (lang === 'ar') {
            var explicit = String(c.country_ar || '').trim();
            if (explicit !== '') {
                return explicit;
            }
            if (typeof Intl !== 'undefined' && typeof Intl.DisplayNames === 'function') {
                var region = storefrontFlagToRegion(c.flag || '');
                if (region) {
                    try {
                        if (!window.__orangeSfCountryDisplayNameAr) {
                            window.__orangeSfCountryDisplayNameAr = new Intl.DisplayNames(['ar'], {
                                type: 'region',
                            });
                        }
                        var translated = window.__orangeSfCountryDisplayNameAr.of(region);
                        if (translated && translated !== region) {
                            return String(translated).trim();
                        }
                    } catch (eAr) {
                        /* fall through */
                    }
                }
            }
        }
        return String(c.country || '').trim();
    }

    function defaultMatchRow(row, needle) {
        if (!needle) {
            return true;
        }
        var ft = String(row.filterText || row.label || '').trim().toLowerCase();
        if (ft.indexOf(needle) === 0) {
            return true;
        }
        var parts = ft.split(/[\s\-–—'،,]+/);
        for (var i = 0; i < parts.length; i++) {
            if (parts[i] && parts[i].indexOf(needle) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param {HTMLSelectElement} selectEl
     * @param {Array<{value: string, label: string, filterText?: string}>} rows
     * @param {{placeholder?: string, openListAria?: string, inputDir?: string, matchRow?: function(Object, string): boolean}} [opts]
     */
    function orangeAttachSearchableCombobox(selectEl, rows, opts) {
        opts = opts || {};
        if (!selectEl || selectEl.tagName !== 'SELECT' || selectEl.dataset.orangeSearchableCombobox === '1') {
            return;
        }
        if (!rows || !rows.length) {
            return;
        }
        var matchRow = typeof opts.matchRow === 'function' ? opts.matchRow : defaultMatchRow;
        var ph = opts.placeholder != null ? String(opts.placeholder) : '';
        var openAria = opts.openListAria != null ? String(opts.openListAria) : 'Open list';
        var inputDir = opts.inputDir || 'auto';

        selectEl.dataset.orangeSearchableCombobox = '1';
        selectEl.setAttribute('tabindex', '-1');

        var box = document.createElement('div');
        box.className = 'orange-country-combobox';

        var control = document.createElement('div');
        control.className = 'orange-country-combobox__control';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'orange-country-combobox__input';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-haspopup', 'listbox');
        if (inputDir === 'ltr' || inputDir === 'rtl') {
            input.setAttribute('dir', inputDir);
        } else {
            input.setAttribute('dir', 'auto');
        }

        var listId = (selectEl.id || 'orange_ss') + '_listbox';
        input.setAttribute('aria-controls', listId);
        input.setAttribute('aria-expanded', 'false');
        input.placeholder = ph;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'orange-country-combobox__toggle';
        btn.setAttribute('aria-label', openAria);
        btn.innerHTML = '<span class="orange-country-combobox__caret" aria-hidden="true"></span>';

        var ul = document.createElement('ul');
        ul.id = listId;
        ul.className = 'orange-country-combobox__list';
        ul.setAttribute('role', 'listbox');
        ul.hidden = true;

        selectEl.classList.add('orange-country-combobox__native');

        var parent = selectEl.parentNode;
        parent.insertBefore(box, selectEl);
        box.appendChild(control);
        control.appendChild(input);
        control.appendChild(btn);
        box.appendChild(ul);
        box.appendChild(selectEl);

        var open = false;
        var activeIndex = -1;
        var closeTimer = null;
        var docCloser = null;

        function escForAttr(s) {
            return String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        }

        var lid = selectEl.id;
        if (lid) {
            var lb = null;
            try {
                lb = (parent.ownerDocument || document).querySelector('label[for="' + escForAttr(lid) + '"]');
            } catch (eL) {}
            if (!lb && parent.closest) {
                var field = parent.closest('.field');
                if (field) {
                    lb = field.querySelector('label');
                }
            }
            if (lb) {
                lb.addEventListener('click', function (ev) {
                    if (document.activeElement !== input && document.activeElement !== btn) {
                        ev.preventDefault();
                        input.focus();
                    }
                });
            }
        }

        function normalizeNeedle(s) {
            return String(s || '').trim().toLowerCase();
        }

        function getFiltered() {
            var v = selectEl.value;
            if (v) {
                var optSel = selectEl.options[selectEl.selectedIndex];
                if (
                    optSel &&
                    String(optSel.textContent || '').trim() === String(input.value || '').trim()
                ) {
                    return rows.slice();
                }
            }
            var n = normalizeNeedle(input.value);
            return rows.filter(function (r) {
                return matchRow(r, n);
            });
        }

        function renderList(items) {
            ul.innerHTML = '';
            items.forEach(function (r) {
                var li = document.createElement('li');
                li.className = 'orange-country-combobox__option';
                li.setAttribute('role', 'option');
                li.dataset.value = r.value;
                li.textContent = r.label;
                li.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    pick(r);
                });
                ul.appendChild(li);
            });
        }

        function highlight() {
            var lis = ul.querySelectorAll('.orange-country-combobox__option');
            var n = lis.length;
            if (activeIndex >= n) {
                activeIndex = n - 1;
            }
            for (var i = 0; i < n; i++) {
                lis[i].classList.toggle('is-active', i === activeIndex);
                if (i === activeIndex) {
                    lis[i].setAttribute('aria-selected', 'true');
                    try {
                        lis[i].scrollIntoView({ block: 'nearest' });
                    } catch (eSc) {}
                } else {
                    lis[i].removeAttribute('aria-selected');
                }
            }
        }

        function dispatchChange(el) {
            try {
                el.dispatchEvent(new Event('change', { bubbles: true }));
            } catch (eCh) {
                if (document.createEvent) {
                    var ev = document.createEvent('HTMLEvents');
                    ev.initEvent('change', true, true);
                    el.dispatchEvent(ev);
                }
            }
        }

        function pick(r) {
            selectEl.value = r.value;
            dispatchChange(selectEl);
            input.value = r.label;
            input.classList.add('is-filled');
            closeList(true);
            activeIndex = -1;
        }

        function detachDocCloser() {
            if (docCloser) {
                document.removeEventListener('mousedown', docCloser);
                docCloser = null;
            }
        }

        function attachDocCloser() {
            detachDocCloser();
            docCloser = function (e) {
                if (!box.contains(e.target)) {
                    closeList(false);
                }
            };
            setTimeout(function () {
                document.addEventListener('mousedown', docCloser);
            }, 0);
        }

        function openList() {
            if (closeTimer) {
                clearTimeout(closeTimer);
                closeTimer = null;
            }
            var items = getFiltered();
            renderList(items);
            ul.hidden = false;
            open = true;
            input.setAttribute('aria-expanded', 'true');
            activeIndex = items.length ? 0 : -1;
            highlight();
            attachDocCloser();
        }

        function closeList(fromPick) {
            if (closeTimer) {
                clearTimeout(closeTimer);
                closeTimer = null;
            }
            detachDocCloser();
            ul.hidden = true;
            open = false;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
            if (!fromPick) {
                syncInputFromSelect();
            }
        }

        function syncInputFromSelect() {
            var v = selectEl.value;
            if (!v) {
                input.value = '';
                input.classList.remove('is-filled');
                input.placeholder = ph;
                return;
            }
            var opt = selectEl.options[selectEl.selectedIndex];
            if (opt && opt.value) {
                input.value = opt.textContent;
                input.classList.add('is-filled');
            } else {
                input.value = '';
                input.classList.remove('is-filled');
            }
        }

        btn.addEventListener('mousedown', function (e) {
            e.preventDefault();
        });
        btn.addEventListener('click', function () {
            if (open) {
                closeList(false);
            } else {
                input.focus();
                openList();
            }
        });

        input.addEventListener('focus', function () {
            syncInputFromSelect();
            requestAnimationFrame(function () {
                try {
                    input.select();
                } catch (eSel) {}
            });
            openList();
        });

        input.addEventListener('input', function () {
            if (normalizeNeedle(input.value) === '') {
                selectEl.value = '';
                input.classList.remove('is-filled');
                dispatchChange(selectEl);
            }
            var items = getFiltered();
            renderList(items);
            if (!open) {
                ul.hidden = false;
                open = true;
                input.setAttribute('aria-expanded', 'true');
                attachDocCloser();
            }
            activeIndex = items.length ? 0 : -1;
            highlight();
        });

        input.addEventListener('blur', function () {
            closeTimer = setTimeout(function () {
                if (!box.contains(document.activeElement)) {
                    closeList(false);
                }
            }, 160);
        });

        input.addEventListener('keydown', function (e) {
            var key = e.key;
            if (key === 'Escape') {
                if (open) {
                    e.preventDefault();
                    closeList(false);
                }
                return;
            }
            if (key === 'ArrowDown') {
                e.preventDefault();
                if (!open) {
                    openList();
                } else {
                    var lis = ul.querySelectorAll('.orange-country-combobox__option');
                    if (!lis.length) {
                        return;
                    }
                    activeIndex = Math.min(activeIndex + 1, lis.length - 1);
                    if (activeIndex < 0) {
                        activeIndex = 0;
                    }
                    highlight();
                }
                return;
            }
            if (key === 'ArrowUp') {
                e.preventDefault();
                if (!open) {
                    openList();
                } else {
                    var lisUp = ul.querySelectorAll('.orange-country-combobox__option');
                    if (!lisUp.length) {
                        return;
                    }
                    activeIndex = Math.max((activeIndex < 0 ? 0 : activeIndex) - 1, 0);
                    highlight();
                }
                return;
            }
            if (key === 'Enter') {
                if (open && activeIndex >= 0) {
                    var lisE = ul.querySelectorAll('.orange-country-combobox__option');
                    if (lisE[activeIndex]) {
                        var val = lisE[activeIndex].dataset.value;
                        var picked = null;
                        for (var ri = 0; ri < rows.length; ri++) {
                            if (rows[ri].value === val) {
                                picked = rows[ri];
                                break;
                            }
                        }
                        if (picked) {
                            e.preventDefault();
                            pick(picked);
                        }
                    }
                }
                return;
            }
        });

        selectEl.addEventListener('change', function () {
            syncInputFromSelect();
        });

        syncInputFromSelect();
    }

    function orangeAttachCountryCombobox(selectEl) {
        if (!selectEl || selectEl.tagName !== 'SELECT' || selectEl.dataset.orangeCountryCombobox === '1') {
            return;
        }
        var rows = selectEl._orangeCountryRows;
        if (!rows || !rows.length) {
            return;
        }
        var T = window.APP_T || {};
        selectEl.dataset.orangeCountryCombobox = '1';

        function countryMatch(row, needle) {
            if (!needle) {
                return true;
            }
            var names = [String(row.country || ''), String(row.countryEn || '')];
            for (var ni = 0; ni < names.length; ni++) {
                var c = names[ni].toLowerCase();
                if (!c) {
                    continue;
                }
                if (c.indexOf(needle) === 0) {
                    return true;
                }
                var parts = c.split(/[\s\-–—'،,]+/);
                for (var i = 0; i < parts.length; i++) {
                    if (parts[i] && parts[i].indexOf(needle) === 0) {
                        return true;
                    }
                }
            }
            if (/^\d+$/.test(needle) && row.codeDigits.indexOf(needle) === 0) {
                return true;
            }
            return false;
        }

        orangeAttachSearchableCombobox(selectEl, rows, {
            placeholder: T.phone_country_select_placeholder || T.phone_country_label || '',
            openListAria: T.phone_country_open_list || 'Open list',
            inputDir: 'ltr',
            matchRow: countryMatch,
        });
    }

    function orangePopulateCountryCodeSelect(selectEl) {
        if (!selectEl || selectEl.tagName !== 'SELECT' || selectEl.dataset.orangeCountryCodesDone === '1') {
            return;
        }
        var list = window.COUNTRY_CODES;
        if (!list || !list.length) {
            return;
        }
        var T = window.APP_T || {};
        var emptyLabel = T.phone_country_select_placeholder || T.phone_country_label || '—';
        selectEl.innerHTML = '';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = emptyLabel;
        opt0.disabled = true;
        opt0.selected = true;
        selectEl.appendChild(opt0);
        var sorted = list
            .map(function (c, i) {
                return { c: c, i: i };
            })
            .sort(function (a, b) {
                return orangeStorefrontCountryLabel(a.c).localeCompare(orangeStorefrontCountryLabel(b.c));
            });
        var rowsForCombo = [];
        sorted.forEach(function (row) {
            var c = row.c;
            var i = row.i;
            var countryName = orangeStorefrontCountryLabel(c);
            var label = (c.flag ? c.flag + ' ' : '') + countryName + ' (' + c.code + ')';
            rowsForCombo.push({
                value: String(i),
                label: label,
                country: countryName,
                countryEn: String(c.country || '').trim(),
                codeDigits: String(c.code).replace(/\D/g, ''),
            });
            var opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = label;
            selectEl.appendChild(opt);
        });
        var intlLabel = T.phone_country_full_international || 'International — full number';
        var optIntl = document.createElement('option');
        optIntl.value = '__intl__';
        optIntl.textContent = intlLabel;
        selectEl.appendChild(optIntl);
        rowsForCombo.push({
            value: '__intl__',
            label: intlLabel,
            country: intlLabel,
            codeDigits: '',
        });
        selectEl._orangeCountryRows = rowsForCombo;
        selectEl.dataset.orangeCountryCodesDone = '1';
        orangeAttachCountryCombobox(selectEl);
    }

    /**
     * @param {string|HTMLSelectElement|null|undefined} selectIdOrEl
     * @returns {string|null} أرقام البادئة (مثل 965)، أو "" عند اختيار ‎__intl__‎، أو null إن لم يُختر شيء/غير صالح
     */
    function orangeStorefrontPhoneCountryDigits(selectIdOrEl) {
        var el =
            typeof selectIdOrEl === 'string' ? document.getElementById(selectIdOrEl) : selectIdOrEl;
        if (!el || el.tagName !== 'SELECT') {
            return null;
        }
        var v = el.value;
        if (v === '') {
            return null;
        }
        if (v === '__intl__') {
            return '';
        }
        var idx = parseInt(v, 10);
        if (isNaN(idx) || !window.COUNTRY_CODES || !window.COUNTRY_CODES[idx]) {
            return null;
        }
        var code = window.COUNTRY_CODES[idx].code;
        var digits = String(code).replace(/\D/g, '');
        return digits || null;
    }

    window.orangeAttachSearchableCombobox = orangeAttachSearchableCombobox;
    window.orangePopulateCountryCodeSelect = orangePopulateCountryCodeSelect;
    window.orangeStorefrontPhoneCountryDigits = orangeStorefrontPhoneCountryDigits;

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('select[data-orange-country-codes]').forEach(orangePopulateCountryCodeSelect);
    });
})();
