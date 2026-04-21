/**
 * Phone normalization (must match includes/phone_validation.php rules).
 * @param {string} raw
 * @param {string|null|undefined} countryDialDigits e.g. "965"
 * @returns {string|null}
 */
function orangeNormalizeCustomerPhone(raw, countryDialDigits) {
    var s = String(raw || '').trim();
    if (s === '') {
        return null;
    }
    if (s.indexOf('00') === 0) {
        s = '+' + s.slice(2);
    }
    if (/[a-zA-Z\u0600-\u06FF]/.test(s)) {
        return null;
    }
    if (/[^\d+\s\-().]/.test(s)) {
        return null;
    }
    var hasPlus = s.charAt(0) === '+';
    var digits = s.replace(/\D/g, '');
    if (!digits || digits.length > 14) {
        return null;
    }
    if (digits.charAt(0) === '0') {
        return null;
    }
    var cc = countryDialDigits ? String(countryDialDigits).replace(/\D/g, '') : '';
    if (cc && cc.charAt(0) === '0') {
        cc = '';
    }
    if (hasPlus) {
        if (digits.length < 8 || digits.length > 14) {
            return null;
        }
        return '+' + digits;
    }
    if (cc) {
        var full = cc + digits;
        if (full.length < 8 || full.length > 14) {
            return null;
        }
        return '+' + full;
    }
    if (digits.length === 8 && /^[569]/.test(digits)) {
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

/** @param {string|HTMLSelectElement|null|undefined} selectIdOrEl */
function orangeStorefrontPhoneCountryDigits(selectIdOrEl) {
    var el =
        typeof selectIdOrEl === 'string'
            ? document.getElementById(selectIdOrEl)
            : selectIdOrEl;
    if (!el || el.tagName !== 'SELECT') {
        return null;
    }
    var v = String(el.value || '').trim();
    return v === '' ? null : v;
}

window.orangeStorefrontPhoneCountryDigits = orangeStorefrontPhoneCountryDigits;

function orangeSanitizePhoneInput(el) {
    if (!el || el.readOnly) {
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
});
