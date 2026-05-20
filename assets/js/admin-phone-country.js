/**
 * Admin phone country: searchable datalist + Arabic labels from COUNTRY_CODES.
 * Optional __intl__ row (customers / policy §0).
 */
(function () {
    'use strict';

    function intlLabel() {
        return String(
            window.ORANGE_ADMIN_PHONE_INTL_LABEL ||
                (window.APP_T && window.APP_T.phone_country_full_international) ||
                'دولي — الرقم كاملاً بـ + أو 00'
        ).trim();
    }

    function flagToRegion(flagValue) {
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

    function countryNameArabic(item) {
        if (!item) {
            return '';
        }
        var explicit = String(item.country_ar || '').trim();
        if (explicit !== '') {
            return explicit;
        }
        if (typeof Intl === 'undefined' || typeof Intl.DisplayNames !== 'function') {
            return '';
        }
        var region = flagToRegion(item.flag || '');
        if (!region) {
            return '';
        }
        try {
            if (!window.__orangeAdminCountryDisplayNameAr) {
                window.__orangeAdminCountryDisplayNameAr = new Intl.DisplayNames(['ar'], { type: 'region' });
            }
            var translated = window.__orangeAdminCountryDisplayNameAr.of(region);
            if (translated && translated !== region) {
                return String(translated).trim();
            }
        } catch (eDisplayName) {
            return '';
        }
        return '';
    }

    function countryCodeRows(includeIntl) {
        var cacheKey = includeIntl ? 'intl1' : 'intl0';
        if (window.__orangeAdminCountryCodeRowsCache && window.__orangeAdminCountryCodeRowsCache[cacheKey]) {
            return window.__orangeAdminCountryCodeRowsCache[cacheKey];
        }
        var src = Array.isArray(window.COUNTRY_CODES) ? window.COUNTRY_CODES : [];
        var rows = [];
        src.forEach(function (item) {
            if (!item) {
                return;
            }
            var dial = String(item.code || '').replace(/\D/g, '');
            if (!dial) {
                return;
            }
            var countryAr = countryNameArabic(item);
            var country = countryAr !== '' ? countryAr : String(item.country || '').trim();
            var label = country !== '' ? country + ' (+' + dial + ')' : '+' + dial;
            rows.push({ dial: dial, name: country, label: label, isIntl: false });
        });
        if (includeIntl) {
            var il = intlLabel();
            rows.push({ dial: '__intl__', name: il, label: il, isIntl: true });
        }
        if (!window.__orangeAdminCountryCodeRowsCache) {
            window.__orangeAdminCountryCodeRowsCache = {};
        }
        window.__orangeAdminCountryCodeRowsCache[cacheKey] = rows;
        return rows;
    }

    function rowMatchesQuery(row, queryRaw) {
        var q = String(queryRaw || '').trim();
        if (q === '') {
            return true;
        }
        var qLower = q.toLowerCase();
        var qDigits = q.replace(/\D/g, '');
        var label = String((row && row.label) || '').toLowerCase();
        if (label.indexOf(qLower) !== -1) {
            return true;
        }
        if (row && row.isIntl && (qLower.indexOf('دول') !== -1 || qLower.indexOf('intl') !== -1)) {
            return true;
        }
        if (qDigits !== '') {
            var dial = String((row && row.dial) || '');
            if (dial.indexOf(qDigits) !== -1) {
                return true;
            }
            if (('+' + dial).indexOf('+' + qDigits) !== -1) {
                return true;
            }
        }
        return false;
    }

    function labelByDial(dialRaw, includeIntl) {
        var dial = String(dialRaw || '').replace(/\D/g, '');
        if (dialRaw === '__intl__' || String(dialRaw || '').trim() === '__intl__') {
            return includeIntl ? intlLabel() : '';
        }
        if (dial === '') {
            return '';
        }
        var rows = countryCodeRows(includeIntl);
        for (var i = 0; i < rows.length; i++) {
            if (String(rows[i].dial || '') === dial) {
                return String(rows[i].label || '');
            }
        }
        return '';
    }

    function dialFromText(rawValue, includeIntl) {
        var raw = String(rawValue || '').trim();
        if (raw === '') {
            return null;
        }
        if (includeIntl) {
            var il = intlLabel();
            if (raw === '__intl__' || raw.toLowerCase() === il.toLowerCase()) {
                return '__intl__';
            }
        }
        var rows = countryCodeRows(includeIntl);
        var digits = raw.replace(/\D/g, '');
        if (digits !== '') {
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].isIntl) {
                    continue;
                }
                if (String(rows[i].dial || '') === digits) {
                    return String(rows[i].dial || '');
                }
            }
            var byDial = rows.filter(function (row) {
                return !row.isIntl && String(row.dial || '').indexOf(digits) !== -1;
            });
            if (byDial.length === 1) {
                return String(byDial[0].dial || '');
            }
        }
        var lower = raw.toLowerCase();
        var exact = rows.find(function (row) {
            return String(row.label || '').toLowerCase() === lower;
        });
        if (exact) {
            return exact.isIntl ? '__intl__' : String(exact.dial || '');
        }
        var byLabel = rows.filter(function (row) {
            return String(row.label || '').toLowerCase().indexOf(lower) !== -1;
        });
        if (byLabel.length === 1) {
            return byLabel[0].isIntl ? '__intl__' : String(byLabel[0].dial || '');
        }
        return null;
    }

    function setInputByDial(inputEl, dialRaw, includeIntl) {
        if (!inputEl) {
            return;
        }
        var raw = String(dialRaw || '').trim();
        if (raw === '__intl__' && includeIntl) {
            inputEl.value = intlLabel();
            return;
        }
        var dial = raw.replace(/\D/g, '');
        if (dial === '') {
            inputEl.value = '';
            return;
        }
        var label = labelByDial(dial, includeIntl);
        inputEl.value = label !== '' ? label : '+' + dial;
    }

    function defaultCountryDial() {
        var rows = countryCodeRows(false);
        for (var i = 0; i < rows.length; i++) {
            if (String(rows[i].dial || '') === '965') {
                return '965';
            }
        }
        return rows.length && !rows[0].isIntl ? String(rows[0].dial || '') : '965';
    }

    function populateDatalist(inputEl, listEl, searchQuery, includeIntl) {
        if (!inputEl || !listEl) {
            return;
        }
        var query = String(searchQuery != null ? searchQuery : inputEl.value || '').trim();
        var queryDigits = query.replace(/\D/g, '');
        var queryHasPlus = /^\s*\+/.test(query);
        var rows = countryCodeRows(includeIntl).filter(function (row) {
            return rowMatchesQuery(row, query);
        });
        listEl.innerHTML = '';
        rows.forEach(function (row) {
            var opt = document.createElement('option');
            if (row.isIntl) {
                opt.value = row.label;
            } else if (queryDigits !== '') {
                var codePrefix = queryHasPlus ? '+' + String(row.dial || '') : String(row.dial || '');
                var countryName = String(row.name || '').trim();
                opt.value = countryName !== '' ? codePrefix + ' — ' + countryName : codePrefix;
            } else {
                opt.value = row.label;
            }
            listEl.appendChild(opt);
        });
    }

    function forApi(inputEl, includeIntl) {
        if (!inputEl) {
            return null;
        }
        return dialFromText(inputEl.value || '', includeIntl);
    }

    function splitPhoneForForm(stored, preferredDial, preferredNational, includeIntl) {
        var raw = String(stored || '').trim();
        var pref = String(preferredDial || '').trim();
        var prefNational = String(preferredNational || '').replace(/\D/g, '');
        if (pref === '__intl__') {
            return { country: '__intl__', phone: prefNational !== '' ? prefNational : raw };
        }
        if (prefNational !== '') {
            return { country: pref || '', phone: prefNational };
        }
        if (!raw) {
            return { country: pref || '', phone: '' };
        }
        var digits = raw.replace(/\D/g, '');
        if (pref && pref !== '__intl__' && digits.indexOf(pref) === 0) {
            var byPref = digits.slice(pref.length);
            if (byPref !== '') {
                return { country: pref, phone: byPref };
            }
        }
        var normFn = window.orangeNormalizeCustomerPhone;
        var norm = normFn ? normFn(raw, null) : null;
        if (!norm) {
            return { country: pref || (includeIntl ? '__intl__' : ''), phone: raw };
        }
        var normDigits = norm.replace(/\D/g, '');
        var uniq = Object.create(null);
        var prefs = [];
        countryCodeRows(false).forEach(function (row) {
            var cc = String(row.dial || '');
            if (!cc || uniq[cc]) {
                return;
            }
            uniq[cc] = true;
            prefs.push(cc);
        });
        prefs.sort(function (a, b) {
            return b.length - a.length;
        });
        for (var i = 0; i < prefs.length; i++) {
            var cc = prefs[i];
            if (normDigits.indexOf(cc) !== 0) {
                continue;
            }
            var nat = normDigits.slice(cc.length);
            if (nat.length < 4) {
                continue;
            }
            if (normFn && normFn(nat, cc) === norm) {
                return { country: cc, phone: nat };
            }
        }
        if (includeIntl) {
            return {
                country: '__intl__',
                phone: norm.charAt(0) === '+' ? norm.slice(1) : norm,
            };
        }
        return { country: '', phone: norm.charAt(0) === '+' ? norm.slice(1) : norm };
    }

    function bindInput(inputEl, listEl, includeIntl) {
        if (!inputEl || inputEl.getAttribute('data-orange-admin-country-bound') === '1') {
            return;
        }
        inputEl.setAttribute('data-orange-admin-country-bound', '1');
        inputEl.addEventListener('input', function () {
            populateDatalist(inputEl, listEl, inputEl.value || '', includeIntl);
        });
        inputEl.addEventListener('focus', function () {
            populateDatalist(inputEl, listEl, inputEl.value || '', includeIntl);
        });
        inputEl.addEventListener('blur', function () {
            var dial = dialFromText(inputEl.value || '', includeIntl);
            if (dial) {
                setInputByDial(inputEl, dial, includeIntl);
            }
        });
    }

    window.orangeAdminPhoneCountry = {
        intlLabel: intlLabel,
        countryCodeRows: countryCodeRows,
        labelByDial: labelByDial,
        dialFromText: dialFromText,
        setInputByDial: setInputByDial,
        defaultCountryDial: defaultCountryDial,
        populateDatalist: populateDatalist,
        forApi: forApi,
        splitPhoneForForm: splitPhoneForForm,
        bindInput: bindInput,
    };
})();
