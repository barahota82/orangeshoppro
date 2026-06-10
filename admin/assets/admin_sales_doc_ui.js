/**
 * GAP-SALE-DOC-01 مرحلة 4 — قناة افتراضية (آخر استخدام) + ترويسة طباعة موحّدة.
 */
(function (global) {
    'use strict';

    function storageKey(countryId) {
        return 'orange_sales_doc_channel_c' + (parseInt(String(countryId || '0'), 10) || 0);
    }

    function rememberChannel(countryId, channelId) {
        var cid = parseInt(String(channelId || '0'), 10) || 0;
        if (cid <= 0) return;
        try {
            localStorage.setItem(storageKey(countryId), String(cid));
        } catch (e) { /* ignore */ }
    }

    function readStoredChannel(countryId) {
        try {
            var raw = localStorage.getItem(storageKey(countryId));
            return parseInt(String(raw || '0'), 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    function optionExists(selectEl, channelId) {
        if (!selectEl || channelId <= 0) return false;
        return !!selectEl.querySelector('option[value="' + String(channelId) + '"]');
    }

    function applyDefaultChannel(selectEl, countryId, phpDefaultId) {
        if (!selectEl || selectEl.disabled) return;
        var stored = readStoredChannel(countryId);
        if (stored > 0 && optionExists(selectEl, stored)) {
            selectEl.value = String(stored);
            return;
        }
        var def = parseInt(String(phpDefaultId || '0'), 10) || 0;
        if (def > 0 && optionExists(selectEl, def)) {
            selectEl.value = String(def);
        }
    }

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function formatPrintDate(d) {
        return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear()
            + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    function syncPrintBanner(opts) {
        opts = opts || {};
        var prefix = String(opts.prefix || 'sd');
        var serialEl = document.getElementById(prefix + '_sd_print_serial');
        var dateEl = document.getElementById(prefix + '_sd_print_date');
        var serialSource = opts.serialElId ? document.getElementById(opts.serialElId) : null;
        if (serialEl && serialSource) {
            var serial = String(serialSource.value || '').trim();
            serialEl.textContent = serial !== '' ? serial : '—';
        }
        if (dateEl) {
            dateEl.textContent = formatPrintDate(new Date());
        }
    }

    function contactPhoneTokens(companyPhone, channelWaMap, channelId) {
        var tokens = [];
        String(companyPhone || '').split('-').forEach(function (t) {
            t = t.trim();
            if (t !== '') tokens.push(t);
        });
        var cid = parseInt(String(channelId || '0'), 10) || 0;
        if (cid > 0 && channelWaMap) {
            var wa = String(channelWaMap[cid] || channelWaMap[String(cid)] || '').trim();
            if (wa !== '') tokens.push(wa);
        }
        return tokens;
    }

    function setPhoneCells(containerId, companyPhone, channelWaMap, channelId) {
        var box = document.getElementById(containerId);
        if (!box) return;
        var tokens = contactPhoneTokens(companyPhone, channelWaMap, channelId);
        box.textContent = '';
        if (!tokens.length) {
            box.textContent = '—';
            return;
        }
        tokens.forEach(function (t) {
            var span = document.createElement('span');
            span.className = 'sd-print-banner__num';
            span.setAttribute('dir', 'ltr');
            span.textContent = t;
            box.appendChild(span);
        });
    }

    function bindPrintButton(btnId, opts) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            syncPrintBanner(opts);
            if (typeof opts.beforePrint === 'function') {
                if (opts.beforePrint() === false) return;
            }
            global.print();
        });
    }

    global.orangeSalesDocSetPhoneCells = setPhoneCells;
    global.orangeSalesDocUi = {
        rememberChannel: rememberChannel,
        applyDefaultChannel: applyDefaultChannel,
        syncPrintBanner: syncPrintBanner,
        bindPrintButton: bindPrintButton,
        setPhoneCells: setPhoneCells
    };
}(typeof window !== 'undefined' ? window : this));
