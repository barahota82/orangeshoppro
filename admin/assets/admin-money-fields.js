/**
 * حقول المبالغ (admin-inp-money) + كميات صحيحة (admin-inp-qty) + Enter للحقل التالي.
 */
(function (global) {
    'use strict';

    var DECIMALS = 3;

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
        var decimals = typeof options.decimals === 'number' ? options.decimals : DECIMALS;
        var raw = parseRaw(el);
        if (raw === '' || raw === '-' || raw === '.' || raw === '-.') {
            el.value = '';
            return null;
        }
        var n = parseFloat(raw);
        if (isNaN(n) || n < 0) {
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
        el.addEventListener('blur', function () {
            cleanMoneyInput(el, { decimals: decimals, allowZero: allowZero });
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

    function bootstrap(root) {
        root = root || document;
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
        parseRaw: parseRaw,
        cleanMoneyInput: cleanMoneyInput,
        companionZero: companionZero,
        wireDebitCredit: wireDebitCredit,
        attachSingle: attachSingle,
        bootstrap: bootstrap
    };

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
