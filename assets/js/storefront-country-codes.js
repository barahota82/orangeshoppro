/**
 * Fills <select data-orange-country-codes> from window.COUNTRY_CODES (assets/js/country-codes.js).
 */
(function () {
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
                return String(a.c.country).localeCompare(String(b.c.country));
            });
        sorted.forEach(function (row) {
            var c = row.c;
            var i = row.i;
            var opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = (c.flag ? c.flag + ' ' : '') + c.country + ' (' + c.code + ')';
            selectEl.appendChild(opt);
        });
        selectEl.dataset.orangeCountryCodesDone = '1';
    }

    /**
     * @param {string|HTMLSelectElement|null|undefined} selectIdOrEl
     * @returns {string|null} digits only, e.g. "965", or null if international / invalid
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
        var idx = parseInt(v, 10);
        if (isNaN(idx) || !window.COUNTRY_CODES || !window.COUNTRY_CODES[idx]) {
            return null;
        }
        var code = window.COUNTRY_CODES[idx].code;
        var digits = String(code).replace(/\D/g, '');
        return digits || null;
    }

    window.orangePopulateCountryCodeSelect = orangePopulateCountryCodeSelect;
    window.orangeStorefrontPhoneCountryDigits = orangeStorefrontPhoneCountryDigits;

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('select[data-orange-country-codes]').forEach(orangePopulateCountryCodeSelect);
    });
})();
