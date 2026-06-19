function ocpFmtIsoDate(s) {
    if (!s) return '';
    return String(s).substring(0, 10);
}
function ocpAlwaysOnEl(prefix) {
    return document.getElementById(prefix + '_always_on');
}
function ocpIsAlwaysOn(prefix) {
    var el = ocpAlwaysOnEl(prefix);
    return !!(el && el.checked);
}
function ocpSyncAlwaysOnUi(prefix) {
    var alwaysOn = ocpIsAlwaysOn(prefix);
    var fromEl = document.getElementById(prefix + '_valid_from');
    var toEl = document.getElementById(prefix + '_valid_to');
    [fromEl, toEl].forEach(function (el) {
        if (!el) return;
        el.disabled = alwaysOn;
        el.required = !alwaysOn;
        el.setAttribute('aria-disabled', alwaysOn ? 'true' : 'false');
    });
}
function ocpSetAlwaysOn(prefix, enabled) {
    var el = ocpAlwaysOnEl(prefix);
    if (!el) return;
    el.checked = !!enabled;
    ocpSyncAlwaysOnUi(prefix);
}
function ocpBindAlwaysOn(prefix) {
    var el = ocpAlwaysOnEl(prefix);
    if (!el) return;
    if (el.dataset.ocpBound === '1') {
        ocpSyncAlwaysOnUi(prefix);
        return;
    }
    el.dataset.ocpBound = '1';
    el.addEventListener('change', function () {
        ocpSyncAlwaysOnUi(prefix);
    });
    ocpSyncAlwaysOnUi(prefix);
}
function ocpSetDmyFromIso(fieldId, iso) {
    var el = document.getElementById(fieldId);
    if (!el || !iso) return;
    var d = String(iso).substring(0, 10);
    if (typeof orangeSetDmyValueFromIso === 'function') {
        orangeSetDmyValueFromIso(el, d);
    } else {
        el.value = d;
    }
}
function ocpGetIso(fieldId) {
    var el = document.getElementById(fieldId);
    if (!el) return '';
    if (typeof orangeGetDmyValueAsIso === 'function') {
        return orangeGetDmyValueAsIso(el) || '';
    }
    return el.value.trim();
}
function ocpSchedulePayload(prefix) {
    return {
        valid_from: ocpGetIso(prefix + '_valid_from'),
        valid_to: ocpGetIso(prefix + '_valid_to'),
        is_always_on: ocpIsAlwaysOn(prefix) ? 1 : 0
    };
}
function ocpStatusLabel(r) {
    var pr = (r.auto_paused_reason || '');
    if (pr === 'promo_stock') {
        return 'موقوف — نفاد مخزون العرض';
    }
    if (pr === 'gift_stock') {
        return 'موقوف — عدم توفر الهدية';
    }
    if (parseInt(r.is_active, 10) !== 1) {
        return 'غير نشط';
    }
    if (parseInt(r.is_always_on, 10) === 1) {
        return 'نشط (دائم)';
    }
    return 'نشط';
}
function ocpScheduleLabel(r) {
    if (parseInt(r.is_always_on, 10) === 1) {
        return 'تفعيل دائم';
    }
    return ocpFmtIsoDate(r.valid_from) + ' → ' + ocpFmtIsoDate(r.valid_to);
}
function ocpDefaultScheduleDates(prefix) {
    var today = new Date();
    var end = new Date(today.getTime());
    end.setFullYear(end.getFullYear() + 1);
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    var f = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());
    var t = end.getFullYear() + '-' + pad(end.getMonth() + 1) + '-' + pad(end.getDate());
    ocpSetDmyFromIso(prefix + '_valid_from', f);
    ocpSetDmyFromIso(prefix + '_valid_to', t);
    ocpSyncAlwaysOnUi(prefix);
}
