/**
 * Phone normalization (must match includes/phone_validation.php rules).
 * @param {string} raw
 * @param {string|null|undefined} countryDialDigits e.g. "965"; use null with internationalFullNumberField when قائمة «دولي كامل»
 * @param {boolean} [internationalFullNumberField] يعطّل افتراض الكويت لـ 8 أرقام وطنية
 * @returns {string|null}
 */
function orangeNormalizeCustomerPhone(raw, countryDialDigits, internationalFullNumberField) {
    internationalFullNumberField = !!internationalFullNumberField;
    var s = String(raw || '').trim();
    if (s === '') {
        return null;
    }
    if (/[a-zA-Z\u0600-\u06FF]/.test(s)) {
        return null;
    }
    if (/[^\d+\s\-().]/.test(s)) {
        return null;
    }

    var cc = countryDialDigits ? String(countryDialDigits).replace(/\D/g, '') : '';
    if (cc && cc.charAt(0) === '0') {
        cc = '';
    }
    if (cc) {
        if (s.charAt(0) === '+' || s.indexOf('00') === 0) {
            return null;
        }
    } else if (s.indexOf('00') === 0) {
        s = '+' + s.slice(2);
    }

    var hasPlus = s.charAt(0) === '+';
    var digits = s.replace(/\D/g, '');
    if (!digits || digits.length > 14) {
        return null;
    }
    if (digits.charAt(0) === '0') {
        return null;
    }

    if (cc) {
        if (digits.indexOf(cc) === 0) {
            digits = digits.slice(cc.length);
        }
        if (!digits) {
            return null;
        }
        var full = cc + digits;
        if (full.length < 8 || full.length > 14) {
            return null;
        }
        return '+' + full;
    }

    if (hasPlus) {
        if (digits.length < 8 || digits.length > 14) {
            return null;
        }
        return '+' + digits;
    }

    if (!internationalFullNumberField && digits.length === 8 && /^[569]/.test(digits)) {
        var ku = '965' + digits;
        if (ku.length > 14) {
            return null;
        }
        return '+' + ku;
    }
    if (digits.length >= 8 && digits.length <= 14) {
        return '+' + digits;
    }
    return null;
}

window.orangeNormalizeCustomerPhone = orangeNormalizeCustomerPhone;

function orangeSanitizePhoneInput(el) {
    if (!el || el.readOnly) {
        return;
    }
    var selId = el.getAttribute('data-orange-national-phone');
    if (selId) {
        orangeSanitizeNationalPhoneInput(el, selId);
        return;
    }
    var v = el.value;
    var out = '';
    for (var i = 0; i < v.length; i++) {
        var ch = v.charAt(i);
        if (/\d/.test(ch)) {
            out += ch;
            continue;
        }
        if (ch === '+' && out.indexOf('+') === -1 && out.length === 0) {
            out += ch;
            continue;
        }
        if (' -().'.indexOf(ch) !== -1) {
            out += ch;
        }
    }
    if (out !== v) {
        el.value = out;
    }
}

/** National digits only; strip duplicate country prefix if user pasted full number. */
function orangeSanitizeNationalPhoneInput(el, countrySelectId) {
    if (!el || el.readOnly) {
        return;
    }
    var cc =
        typeof window.orangeStorefrontPhoneCountryDigits === 'function'
            ? window.orangeStorefrontPhoneCountryDigits(countrySelectId)
            : null;
    if (cc === null) {
        return;
    }
    if (cc === '') {
        var vFull = el.value;
        var outFull = '';
        for (var fi = 0; fi < vFull.length; fi++) {
            var chf = vFull.charAt(fi);
            if (/\d/.test(chf)) {
                outFull += chf;
                continue;
            }
            if (chf === '+' && outFull.indexOf('+') === -1 && outFull.length === 0) {
                outFull += chf;
                continue;
            }
            if (' -().'.indexOf(chf) !== -1) {
                outFull += chf;
            }
        }
        if (outFull !== vFull) {
            el.value = outFull;
        }
        return;
    }
    var v = el.value.replace(/\D/g, '');
    if (cc && v.indexOf(cc) === 0) {
        v = v.slice(cc.length);
    }
    if (el.value !== v) {
        el.value = v;
    }
}

function orangeAttachDigitsOnly(el) {
    function strip() {
        var v = el.value;
        var cleaned = v.replace(/[^\d]/g, '');
        if (v !== cleaned) {
            el.value = cleaned;
        }
    }
    el.addEventListener('input', strip);
    el.addEventListener('blur', strip);
}

function orangeAttachRequiredFieldI18n() {
    var msg = (window.APP_T && window.APP_T.validation_value_missing) || '';
    if (!msg) {
        return;
    }
    /* Capture so the translated message wins over the browser default ("Please fill out this field"). */
    document.addEventListener(
        'invalid',
        function (ev) {
            var el = ev.target;
            if (!el || typeof el.matches !== 'function' || !el.matches('input[required], select[required], textarea[required]')) {
                return;
            }
            if (el.validity.valueMissing) {
                el.setCustomValidity(msg);
            }
        },
        true
    );
    document.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (el) {
        el.addEventListener('input', function () {
            el.setCustomValidity('');
        });
        el.addEventListener('change', function () {
            el.setCustomValidity('');
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-orange-digits-only]').forEach(orangeAttachDigitsOnly);
    document.querySelectorAll('.js-orange-phone-input').forEach(function (el) {
        el.addEventListener('input', function () {
            orangeSanitizePhoneInput(el);
        });
        el.addEventListener('blur', function () {
            orangeSanitizePhoneInput(el);
        });
    });
    orangeAttachRequiredFieldI18n();
});
