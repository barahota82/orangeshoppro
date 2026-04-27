/**
 * تواريخ بعرض يوم/شهر/سنة في حقول نصية؛ الـ API يبقى Y-m-d أو Y-m-d H:i:s.
 * يفعّل على .orange-inp-dmy و .orange-inp-dmyhi
 * لكل حقل .orange-inp-dmy (غير المعطّل/للقراءة فقط) يُضاف زر تقويم أصلي (حقل date شفاف) على مستوى لوحة الإدارة.
 */
(function (global) {
    'use strict';

    var orangeDmyPickerSeq = 0;

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

    /**
     * يلف حقل التاريخ النصي مع زر تقويم (type=date غير مرئي فوق الزر) — تنسيق admin.css: .admin-inp-dmy-with-picker
     */
    function orangeWrapDmyWithNativePicker(textEl) {
        if (!textEl || textEl.nodeName !== 'INPUT') {
            return;
        }
        if (textEl.getAttribute('data-orange-dmy-native-picker') === '1') {
            return;
        }
        if (textEl.classList.contains('orange-inp-dmy-no-picker')) {
            return;
        }
        if (textEl.readOnly || textEl.disabled) {
            return;
        }
        if (textEl.closest('.admin-inp-dmy-with-picker')) {
            return;
        }
        var parent = textEl.parentNode;
        if (!parent) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'admin-inp-dmy-with-picker';
        wrap.setAttribute('data-orange-dmy-wrap', '1');

        var pickerWrap = document.createElement('div');
        pickerWrap.className = 'admin-inp-dmy-picker-wrap jv-print-hide';
        pickerWrap.setAttribute('title', 'اختيار من التقويم');

        var face = document.createElement('button');
        face.type = 'button';
        face.className = 'admin-inp-dmy-picker-face';
        face.tabIndex = -1;
        face.setAttribute('aria-hidden', 'true');
        face.innerHTML = '&#128197;';

        var pick = document.createElement('input');
        pick.type = 'date';
        pick.className = 'admin-inp-dmy-picker-native';
        pick.setAttribute('lang', 'en-GB');
        pick.setAttribute('dir', 'ltr');
        pick.setAttribute('title', 'اختيار من التقويم');
        var tid = textEl.id;
        if (!tid) {
            orangeDmyPickerSeq += 1;
            tid = 'orange-dmy-' + String(orangeDmyPickerSeq);
            textEl.id = tid;
        }
        pick.id = tid + '_native_date';
        pick.setAttribute('aria-label', 'تقويم — اختيار التاريخ');

        pickerWrap.appendChild(face);
        pickerWrap.appendChild(pick);

        parent.insertBefore(wrap, textEl);
        wrap.appendChild(textEl);
        wrap.appendChild(pickerWrap);

        textEl.setAttribute('data-orange-dmy-native-picker', '1');

        function syncNativeFromText() {
            var iso = orangeGetDmyValueAsIso(textEl);
            pick.value = iso || '';
        }

        pick.addEventListener('change', function () {
            if (!pick.value) {
                return;
            }
            textEl.value = orangeIsoDateToDmy(pick.value);
            orangeNormalizeDmyInput(textEl);
            syncNativeFromText();
            textEl.dispatchEvent(new Event('input', { bubbles: true }));
            textEl.dispatchEvent(new Event('change', { bubbles: true }));
            textEl.dispatchEvent(new Event('blur', { bubbles: true }));
        });

        syncNativeFromText();
    }

    function orangeInitDmyInputs(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.orange-inp-dmy').forEach(function (el) {
            orangeWrapDmyWithNativePicker(el);
        });
        scope.querySelectorAll('.orange-inp-dmy').forEach(function (el) {
            if (!el.getAttribute('placeholder')) {
                el.setAttribute('placeholder', 'يوم/شهر/سنة');
            }
            el.setAttribute('autocomplete', 'off');
            el.setAttribute('maxlength', '10');
            el.setAttribute('dir', 'ltr');
            if (!el.getAttribute('lang')) {
                el.setAttribute('lang', 'en-GB');
            }
            if (el.getAttribute('data-orange-dmy-blur') === '1') {
                return;
            }
            el.setAttribute('data-orange-dmy-blur', '1');
            el.addEventListener('blur', function () {
                orangeNormalizeDmyInput(el);
                var pw = el.parentElement;
                if (pw && pw.classList.contains('admin-inp-dmy-with-picker')) {
                    var np = pw.querySelector('.admin-inp-dmy-picker-native');
                    if (np) {
                        var iso = orangeGetDmyValueAsIso(el);
                        np.value = iso || '';
                    }
                }
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
