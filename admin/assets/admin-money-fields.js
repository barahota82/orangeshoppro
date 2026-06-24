/**
 * حقول المبالغ (admin-inp-money) + كميات صحيحة (admin-inp-qty) + Enter للحقل التالي.
 */
(function (global) {
    'use strict';

    function readAdminCurrencyDecimals() {
        if (window.ORANGE_ADMIN_MONEY && typeof window.ORANGE_ADMIN_MONEY.decimals === 'number') {
            var od = window.ORANGE_ADMIN_MONEY.decimals;
            return od === 2 || od === 3 ? od : 3;
        }
        var m = document.querySelector('meta[name="orange-admin-currency-decimals"]');
        if (!m) {
            return 3;
        }
        var n = parseInt(String(m.getAttribute('content') || ''), 10);
        return n === 2 || n === 3 ? n : 3;
    }

    var DECIMALS = readAdminCurrencyDecimals();

    function readAdminMoneyZero() {
        if (window.ORANGE_ADMIN_MONEY && typeof window.ORANGE_ADMIN_MONEY.zero === 'string') {
            return window.ORANGE_ADMIN_MONEY.zero;
        }
        return (0).toFixed(DECIMALS);
    }

    function readAdminMoneyStep() {
        if (window.ORANGE_ADMIN_MONEY && typeof window.ORANGE_ADMIN_MONEY.step === 'string') {
            return window.ORANGE_ADMIN_MONEY.step;
        }
        return DECIMALS === 3 ? '0.001' : '0.01';
    }

    function readAdminCurrencyCode() {
        var m = document.querySelector('meta[name="orange-admin-currency"]');
        if (!m) {
            return 'KWD';
        }
        var c = String(m.getAttribute('content') || '').trim().toUpperCase();
        return /^[A-Z]{3}$/.test(c) ? c : 'KWD';
    }

    function readAdminCurrencyUnit() {
        var m = document.querySelector('meta[name="orange-admin-currency-unit"]');
        if (m) {
            var u = String(m.getAttribute('content') || '').trim();
            if (u !== '') {
                return u;
            }
        }
        return readAdminCurrencyCode() === 'KWD' ? 'KD' : readAdminCurrencyCode();
    }

    function formatMoneyAmount(n) {
        var x = parseFloat(n);
        if (isNaN(x)) {
            x = 0;
        }
        return x.toFixed(DECIMALS);
    }

    function zeroMoneyAmount() {
        return readAdminMoneyZero();
    }

    /** مبلغ موجب مُنسَّق، أو فارغ إن كان ≤ 0 (حقول مدين/دائن في السطر). */
    function formatPositiveOrEmpty(n) {
        var x = parseFloat(n);
        if (isNaN(x) || x <= 0) {
            return '';
        }
        return formatMoneyAmount(x);
    }

    function setMoneyInputValue(el, amount) {
        if (!el) {
            return;
        }
        el.value = formatMoneyAmount(amount);
    }

    function setJvTotals(debitElOrId, creditElOrId, sumDebit, sumCredit) {
        var d =
            typeof debitElOrId === 'string'
                ? document.getElementById(debitElOrId)
                : debitElOrId;
        var c =
            typeof creditElOrId === 'string'
                ? document.getElementById(creditElOrId)
                : creditElOrId;
        setMoneyInputValue(d, sumDebit);
        setMoneyInputValue(c, sumCredit);
    }

    function isZeroishMoneyText(s) {
        s = String(s || '').trim().replace(',', '.');
        return s === '' || s === '0' || /^0\.0+$/.test(s);
    }

    /** تطبيع placeholders/قيم الصفر وstep — تلقائي لكل حقول المبالغ في الأدمن. */
    function normalizeMoneyUi(root) {
        root = root || document;
        if (!root.querySelectorAll) {
            return;
        }
        var z = zeroMoneyAmount();
        var step = readAdminMoneyStep();
        root.querySelectorAll('input.admin-inp-money, input.jv-tot-readonly, input.mo-line-net').forEach(function (el) {
            var ph = String(el.getAttribute('placeholder') || '');
            if (isZeroishMoneyText(ph)) {
                el.setAttribute('placeholder', z);
            }
            if (el.type === 'number' || el.getAttribute('type') === 'number') {
                el.setAttribute('step', step);
            }
            if (isZeroishMoneyText(el.value)) {
                /* الحقول القابلة للكتابة (مدين/دائن، مبالغ القبض/الدفع): الصفر استرشادي (placeholder)
                   لا قيمة ثابتة، حتى يكتب المستخدم مباشرة دون الحاجة لمسح الأصفار.
                   الحقول للقراءة فقط (مجاميع/أسطر محسوبة/data-money-allow-zero) تبقى تعرض 0.000. */
                /* حقول إدخال مبوّبة (مثل سعر/تكلفة المنتج) قد تكون disabled مؤقتاً قبل اختيار النوع؛
                   data-money-empty-when-zero يمنع تثبيت 0 حقيقي فيها فيبقى الصفر إرشادياً (placeholder). */
                var keepZeroValue =
                    !el.hasAttribute('data-money-empty-when-zero') && (
                        el.readOnly ||
                        el.disabled ||
                        el.hasAttribute('data-money-allow-zero') ||
                        el.classList.contains('jv-tot-readonly') ||
                        el.classList.contains('mo-line-net')
                    );
                el.value = keepZeroValue ? z : '';
            }
        });
        root.querySelectorAll('.admin-money-display, [data-orange-money-display]').forEach(function (el) {
            if (isZeroishMoneyText(el.textContent)) {
                el.textContent = z;
            }
        });
    }

    function parseQtyRaw(el) {
        return String(el && el.value != null ? el.value : '').trim();
    }

    /**
     * أعداد صحيحة ≥ 0 فقط؛ لا كسور؛ سالب أو غير صالح → تفريغ عند blur.
     * @param {HTMLInputElement} el
     * @param {{allowZero?: boolean}} options
     * @returns {number|null}
     */
    function cleanQtyInput(el, options) {
        options = options || {};
        var allowZero =
            el.getAttribute('data-qty-allow-zero') === '0' ||
            el.getAttribute('data-qty-allow-zero') === 'false'
                ? false
                : options.allowZero !== false;
        var raw = parseQtyRaw(el);
        if (raw === '' || raw === '-') {
            el.value = '';
            return null;
        }
        if (/[.,eE]/.test(raw)) {
            el.value = '';
            return null;
        }
        if (!/^\d+$/.test(raw)) {
            el.value = '';
            return null;
        }
        var n = parseInt(raw, 10);
        if (isNaN(n) || n < 0) {
            el.value = '';
            return null;
        }
        if (n === 0 && !allowZero) {
            el.value = '';
            return null;
        }
        var minA = el.getAttribute('min');
        if (minA !== null && minA !== '') {
            var minV = parseInt(minA, 10);
            if (!isNaN(minV) && n < minV) {
                el.value = '';
                return null;
            }
        }
        var maxA = el.getAttribute('max');
        if (maxA !== null && maxA !== '') {
            var maxV = parseInt(maxA, 10);
            if (!isNaN(maxV) && n > maxV) {
                el.value = '';
                return null;
            }
        }
        el.value = String(n);
        return n;
    }

    function attachQty(el) {
        if (el.getAttribute('data-orange-qty-wired')) {
            return;
        }
        el.addEventListener('blur', function () {
            cleanQtyInput(el);
        });
        el.setAttribute('data-orange-qty-wired', '1');
    }

    function wireNewQtyInputs(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('input.admin-inp-qty').forEach(function (el) {
            if (!el.getAttribute('data-orange-qty-wired')) {
                attachQty(el);
            }
        });
    }

    function parseRaw(el) {
        return String(el && el.value != null ? el.value : '').trim().replace(',', '.');
    }

    /**
     * @param {HTMLInputElement} el
     * @param {{decimals?: number, allowZero?: boolean}} options
     * @returns {number|null}
     */
    function cleanMoneyInput(el, options) {
        options = options || {};
        var allowZero = !!options.allowZero;
        var allowNegative = !!options.allowNegative;
        var decimals = typeof options.decimals === 'number' ? options.decimals : DECIMALS;
        var raw = parseRaw(el);
        if (raw === '' || raw === '-' || raw === '.' || raw === '-.') {
            el.value = '';
            return null;
        }
        var n = parseFloat(raw);
        if (isNaN(n)) {
            el.value = '';
            return null;
        }
        if (!allowNegative && n < 0) {
            el.value = '';
            return null;
        }
        if (n === 0 && !allowZero) {
            el.value = '';
            return null;
        }
        el.value = n.toFixed(decimals);
        return n;
    }

    function companionZero(decimals) {
        var d = typeof decimals === 'number' ? decimals : DECIMALS;
        return (0).toFixed(d);
    }

    function wireDebitCredit(dEl, cEl, options) {
        options = options || {};
        var decimals = typeof options.decimals === 'number' ? options.decimals : DECIMALS;
        var onRecalc = options.onRecalc || function () {};

        function z() {
            return companionZero(decimals);
        }

        function zeroishCompanion(s) {
            var zz = z();
            return (
                s === '' ||
                s === zz ||
                s === '0' ||
                s === '0.0' ||
                s === '0.00' ||
                s === '0.000' ||
                s === '0.0000'
            );
        }

        function syncD() {
            var raw = String(dEl.value || '').trim().replace(',', '.');
            var v = parseFloat(raw || '0');
            if (raw !== '' && !isNaN(v) && v > 0) {
                cEl.value = z();
            }
            onRecalc();
        }
        function syncC() {
            var raw = String(cEl.value || '').trim().replace(',', '.');
            var v = parseFloat(raw || '0');
            if (raw !== '' && !isNaN(v) && v > 0) {
                dEl.value = z();
            }
            onRecalc();
        }
        dEl.addEventListener('input', syncD);
        cEl.addEventListener('input', syncC);
        dEl.addEventListener('change', syncD);
        cEl.addEventListener('change', syncC);
        dEl.addEventListener('blur', function () {
            var rd = cleanMoneyInput(dEl, { decimals: decimals });
            if (rd !== null && rd > 0) {
                cEl.value = z();
            } else {
                if (parseRaw(dEl) === '') {
                    var cr = parseRaw(cEl);
                    if (zeroishCompanion(cr)) {
                        cEl.value = '';
                    }
                }
            }
            onRecalc();
        });
        cEl.addEventListener('blur', function () {
            var dr = parseRaw(dEl);
            var dNum = parseFloat(dr || '0');
            if (dr !== '' && !isNaN(dNum) && dNum > 0) {
                if (cleanMoneyInput(cEl, { decimals: decimals }) === null) {
                    cEl.value = z();
                }
                onRecalc();
                return;
            }
            var rc = cleanMoneyInput(cEl, { decimals: decimals });
            if (rc !== null && rc > 0) {
                dEl.value = z();
            } else {
                if (parseRaw(cEl) === '') {
                    var ddr = parseRaw(dEl);
                    if (zeroishCompanion(ddr) || parseFloat(ddr || '0') === 0) {
                        dEl.value = '';
                    }
                }
            }
            onRecalc();
        });
        dEl.setAttribute('data-orange-money-wired', 'pair');
        cEl.setAttribute('data-orange-money-wired', 'pair');
    }

    function attachSingle(el, options) {
        if (el.getAttribute('data-orange-money-wired')) {
            return;
        }
        options = options || {};
        var decimals = typeof options.decimals === 'number' ? options.decimals : DECIMALS;
        var allowZero = el.hasAttribute('data-money-allow-zero') ? true : !!options.allowZero;
        var allowNegative = el.hasAttribute('data-money-allow-negative') ? true : !!options.allowNegative;
        el.addEventListener('blur', function () {
            cleanMoneyInput(el, {
                decimals: decimals,
                allowZero: allowZero,
                allowNegative: allowNegative
            });
        });
        el.setAttribute('data-orange-money-wired', 'single');
    }

    function tryWireTr(tr) {
        if (!tr || tr.nodeType !== 1) {
            return;
        }
        if (tr.getAttribute('data-orange-dc-tr') === '1') {
            return;
        }
        var d = tr.querySelector('.jv-d, .ob-d');
        var c = tr.querySelector('.jv-c, .ob-c');
        if (!d || !c) {
            return;
        }
        tr.setAttribute('data-orange-dc-tr', '1');
        var recalc = function () {
            if (typeof global.jvRecalc === 'function' && tr.closest('#jv_lines_body')) {
                global.jvRecalc();
            }
            if (typeof global.obRecalc === 'function' && tr.closest('#ob_body')) {
                global.obRecalc();
            }
        };
        wireDebitCredit(d, c, { onRecalc: recalc });
    }

    function wireNewMoneyInputs(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('input.admin-inp-money').forEach(function (el) {
            if (!el.getAttribute('data-orange-money-wired')) {
                attachSingle(el);
            }
        });
    }

    /**
     * حقل الخصم في أسطر الفواتير: يقبل مبلغاً أو نسبة (٪).
     * عند blur: المبلغ يُنسَّق كمبلغ (0.000) مثل بقية المبالغ؛ النسبة تبقى «N%».
     * فارغ/صفر/غير صالح → تفريغ.
     */
    function formatDiscountInput(el) {
        if (!el) {
            return;
        }
        var raw = String(el.value || '').trim().replace(',', '.');
        if (raw === '' || raw === '-' || raw === '.' || raw === '-.') {
            el.value = '';
            return;
        }
        if (raw.charAt(raw.length - 1) === '%') {
            var pct = parseFloat(raw.slice(0, -1));
            if (isNaN(pct) || pct <= 0) {
                el.value = '';
                return;
            }
            el.value = String(parseFloat(pct.toFixed(4))) + '%';
            return;
        }
        var n = parseFloat(raw);
        if (isNaN(n) || n <= 0) {
            el.value = '';
            return;
        }
        el.value = formatMoneyAmount(n);
    }

    function attachDiscount(el) {
        if (el.getAttribute('data-orange-disc-wired')) {
            return;
        }
        el.addEventListener('blur', function () {
            formatDiscountInput(el);
        });
        el.setAttribute('data-orange-disc-wired', '1');
    }

    function wireNewDiscountInputs(root) {
        if (!root || !root.querySelectorAll) {
            return;
        }
        root.querySelectorAll('input.admin-inp-discount').forEach(function (el) {
            if (!el.getAttribute('data-orange-disc-wired')) {
                attachDiscount(el);
            }
        });
    }

    function bootstrap(root) {
        root = root || document;
        normalizeMoneyUi(root);
        root.querySelectorAll('tr').forEach(tryWireTr);
        root.querySelectorAll('input.admin-inp-money').forEach(function (el) {
            if (!el.getAttribute('data-orange-money-wired')) {
                attachSingle(el);
            }
        });
        root.querySelectorAll('input.admin-inp-qty').forEach(function (el) {
            if (!el.getAttribute('data-orange-qty-wired')) {
                attachQty(el);
            }
        });
        wireNewDiscountInputs(root);
    }

    function observe() {
        var main = document.querySelector('.admin-main');
        bootstrap(document);
        if (!main || !global.MutationObserver) {
            return;
        }
        var obs = new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) {
                        return;
                    }
                    if (n.tagName === 'TR') {
                        tryWireTr(n);
                    }
                    if (n.querySelectorAll) {
                        n.querySelectorAll('tr').forEach(tryWireTr);
                        wireNewMoneyInputs(n);
                        wireNewQtyInputs(n);
                        wireNewDiscountInputs(n);
                        normalizeMoneyUi(n);
                    }
                });
            });
        });
        obs.observe(main, { childList: true, subtree: true });
    }

    function skipEnterAdvance(target) {
        if (!target || !target.closest) {
            return true;
        }
        if (target.closest('.gl-pick-modal')) {
            return true;
        }
        return false;
    }

    function initEnterAdvance() {
        var main = document.querySelector('.admin-main');
        if (!main) {
            return;
        }
        var sel =
            'input:not([disabled]):not([type="hidden"]):not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="checkbox"]):not([type="radio"]):not([type="file"]), select:not([disabled])';
        function isVisible(e) {
            return !!(e.offsetWidth || e.offsetHeight || e.getClientRects().length);
        }
        function listFocusable() {
            return Array.prototype.slice.call(main.querySelectorAll(sel)).filter(isVisible);
        }
        main.addEventListener(
            'keydown',
            function (ev) {
                if (ev.key !== 'Enter') {
                    return;
                }
                var t = ev.target;
                if (!main.contains(t) || skipEnterAdvance(t)) {
                    return;
                }
                if (t.tagName === 'TEXTAREA') {
                    return;
                }
                if (t.tagName === 'INPUT' && t.type === 'search') {
                    return;
                }
                if (!t.matches(sel)) {
                    return;
                }
                ev.preventDefault();
                var list = listFocusable();
                var i = list.indexOf(t);
                if (i >= 0 && i < list.length - 1) {
                    list[i + 1].focus();
                }
            },
            true
        );
    }

    global.OrangeMoney = {
        DECIMALS: DECIMALS,
        currencyCode: readAdminCurrencyCode,
        currencyUnit: readAdminCurrencyUnit,
        formatAmount: formatMoneyAmount,
        formatPositiveOrEmpty: formatPositiveOrEmpty,
        zeroAmount: zeroMoneyAmount,
        setInputValue: setMoneyInputValue,
        setJvTotals: setJvTotals,
        inputStep: readAdminMoneyStep,
        normalizeUi: normalizeMoneyUi,
        parseRaw: parseRaw,
        cleanMoneyInput: cleanMoneyInput,
        companionZero: companionZero,
        wireDebitCredit: wireDebitCredit,
        attachSingle: attachSingle,
        formatDiscountInput: formatDiscountInput,
        attachDiscount: attachDiscount,
        bootstrap: bootstrap
    };

    global.fmt3 = formatMoneyAmount;
    global.fmtMoney = formatMoneyAmount;
    global.orangeFmtMoney = formatMoneyAmount;
    global.orangeMoneyZero = zeroMoneyAmount;

    global.OrangeQty = {
        parseQtyRaw: parseQtyRaw,
        cleanQtyInput: cleanQtyInput,
        attachQty: attachQty
    };

    function start() {
        observe();
        initEnterAdvance();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(typeof window !== 'undefined' ? window : this);
