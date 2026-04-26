/**
 * تواريخ بعرض يوم/شهر/سنة في حقول نصية؛ الـ API يبقى Y-m-d أو Y-m-d H:i:s.
 * يفعّل على .orange-inp-dmy و .orange-inp-dmyhi
 */
(function (global) {
    'use strict';

    function orangePad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function orangeIsoDateToDmy(iso) {
        if (!iso || typeof iso !== 'string') {
            return '';
        }
        var m = iso.trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) {
            return '';
        }
        return m[3] + '/' + m[2] + '/' + m[1];
    }

    function orangeDmyToIso(s) {
        s = String(s || '').trim().replace(/\s/g, '');
        var m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (!m) {
            return '';
        }
        var d = parseInt(m[1], 10);
        var mo = parseInt(m[2], 10);
        var y = parseInt(m[3], 10);
        if (mo < 1 || mo > 12 || d < 1 || d > 31 || y < 1900 || y > 2100) {
            return '';
        }
        var dt = new Date(y, mo - 1, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) {
            return '';
        }
        return y + '-' + orangePad2(mo) + '-' + orangePad2(d);
    }

    function orangeNormalizeDmyInput(el) {
        if (!el) {
            return;
        }
        var iso = orangeDmyToIso(el.value);
        if (iso) {
            el.value = orangeIsoDateToDmy(iso);
        }
    }

    function orangeGetDmyValueAsIso(el) {
        if (!el) {
            return '';
        }
        var iso = orangeDmyToIso(el.value);
        if (iso) {
            return iso;
        }
        var v = String(el.value || '').trim();
        if (/^\d{4}-\d{2}-\d{2}/.test(v)) {
            return v.slice(0, 10);
        }
        return '';
    }

    /** Y-m-d H:i[:s] أو Y-m-dTHH:mm → d/m/Y HH:mm */
    function orangeIsoDatetimeToDmyHi(iso) {
        if (!iso || typeof iso !== 'string') {
            return '';
        }
        var s = iso.trim().replace('T', ' ');
        var m = s.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{1,2}):(\d{2})/);
        if (!m) {
            m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!m) {
                return '';
            }
            return m[3] + '/' + m[2] + '/' + m[1] + ' 00:00';
        }
        return m[3] + '/' + m[2] + '/' + m[1] + ' ' + orangePad2(parseInt(m[4], 10)) + ':' + m[5];
    }

    /** d/m/Y H:i → Y-m-d H:i:00 */
    function orangeDmyHiToSqlDatetime(s) {
        s = String(s || '').trim().replace(/\s+/g, ' ');
        var m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})$/);
        if (!m) {
            return '';
        }
        var d = parseInt(m[1], 10);
        var mo = parseInt(m[2], 10);
        var y = parseInt(m[3], 10);
        var h = parseInt(m[4], 10);
        var mi = parseInt(m[5], 10);
        if (mo < 1 || mo > 12 || d < 1 || d > 31 || y < 1900 || y > 2100) {
            return '';
        }
        if (h < 0 || h > 23 || mi < 0 || mi > 59) {
            return '';
        }
        var dt = new Date(y, mo - 1, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) {
            return '';
        }
        return y + '-' + orangePad2(mo) + '-' + orangePad2(d) + ' ' + orangePad2(h) + ':' + orangePad2(mi) + ':00';
    }

    function orangeInitDmyInputs(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.orange-inp-dmy').forEach(function (el) {
            if (!el.getAttribute('placeholder')) {
                el.setAttribute('placeholder', 'يوم/شهر/سنة');
            }
            el.setAttribute('autocomplete', 'off');
            el.setAttribute('maxlength', '10');
            el.setAttribute('dir', 'ltr');
            el.addEventListener('blur', function () {
                orangeNormalizeDmyInput(el);
            });
        });
        scope.querySelectorAll('.orange-inp-dmyhi').forEach(function (el) {
            if (!el.getAttribute('placeholder')) {
                el.setAttribute('placeholder', 'يوم/شهر/سنة ساعة:دقيقة');
            }
            el.setAttribute('autocomplete', 'off');
            el.setAttribute('maxlength', '16');
            el.setAttribute('dir', 'ltr');
            el.addEventListener('blur', function () {
                var sql = orangeDmyHiToSqlDatetime(el.value);
                if (sql) {
                    el.value = orangeIsoDatetimeToDmyHi(sql);
                }
            });
        });
    }

    global.orangeIsoDateToDmy = orangeIsoDateToDmy;
    global.orangeDmyToIso = orangeDmyToIso;
    global.orangeNormalizeDmyInput = orangeNormalizeDmyInput;
    global.orangeGetDmyValueAsIso = orangeGetDmyValueAsIso;
    global.orangeIsoDatetimeToDmyHi = orangeIsoDatetimeToDmyHi;
    global.orangeDmyHiToSqlDatetime = orangeDmyHiToSqlDatetime;
    global.orangeInitDmyInputs = orangeInitDmyInputs;

    document.addEventListener('DOMContentLoaded', function () {
        orangeInitDmyInputs(document);
    });
})(typeof window !== 'undefined' ? window : this);
