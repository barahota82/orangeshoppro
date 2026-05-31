/**
 * اختيار صنف (كود/باركود + نافذة بحث) — فاتورة شراء ومردود مشتريات.
 */
(function (global) {
    'use strict';

    function normCodeKey(s) {
        s = String(s || '').trim();
        return s.replace(/[A-Z]/g, function (ch) {
            return ch.toLowerCase();
        });
    }

    function buildPickByCode(rows) {
        var map = {};
        function reg(row, rawKey) {
            var k = normCodeKey(rawKey);
            if (!k || map[k]) {
                return;
            }
            map[k] = row;
        }
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            reg(r, r.code);
            if (r.barcode) {
                reg(r, r.barcode);
            }
        }
        return map;
    }

    function varLabel(row) {
        var c = row.color || '';
        var z = row.size || '';
        if (c && z) {
            return c + ' / ' + z;
        }
        return c || z || '';
    }

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/"/g, '&quot;');
    }

    function create(cfg) {
        var pickRows = cfg.pickRows || [];
        var pickByCode = buildPickByCode(pickRows);
        var pickTargetRow = null;
        var sel = cfg.selectors || {};
        var modal = cfg.modalIds || {};
        var fmtMoney = cfg.fmtMoney || function (n) {
            return String(n);
        };
        var silentResolve = !!cfg.silentResolve;

        function q(tr, key) {
            return tr ? tr.querySelector(sel[key]) : null;
        }

        function findPickRowByIds(productId, variantId) {
            productId = parseInt(String(productId || '0'), 10) || 0;
            variantId = parseInt(String(variantId || '0'), 10) || 0;
            for (var i = 0; i < pickRows.length; i++) {
                var r = pickRows[i];
                if ((parseInt(String(r.product_id || '0'), 10) || 0) === productId
                    && (parseInt(String(r.variant_id || '0'), 10) || 0) === variantId) {
                    return r;
                }
            }
            return null;
        }

        function clearLine(tr) {
            if (!tr) {
                return;
            }
            var codeEl = q(tr, 'code');
            var pidEl = q(tr, 'productId');
            var vidEl = q(tr, 'variantId');
            var nameEl = q(tr, 'name');
            var varEl = q(tr, 'varLabel');
            var costEl = q(tr, 'cost');
            if (codeEl) {
                codeEl.value = '';
            }
            if (pidEl) {
                pidEl.value = '';
            }
            if (vidEl) {
                vidEl.value = '';
            }
            if (nameEl) {
                nameEl.value = '';
            }
            if (varEl) {
                varEl.value = '';
            }
            if (costEl && !costEl.readOnly) {
                costEl.value = fmtMoney(0);
            }
        }

        function applyPick(tr, row) {
            if (!tr || !row) {
                return;
            }
            var codeEl = q(tr, 'code');
            var pidEl = q(tr, 'productId');
            var vidEl = q(tr, 'variantId');
            var nameEl = q(tr, 'name');
            var varEl = q(tr, 'varLabel');
            var costEl = q(tr, 'cost');
            if (codeEl) {
                codeEl.value = row.code || '';
            }
            if (pidEl) {
                pidEl.value = String(row.product_id || '');
            }
            if (vidEl) {
                vidEl.value = String(row.variant_id || '0');
            }
            if (nameEl) {
                nameEl.value = row.name || '';
            }
            if (varEl) {
                varEl.value = varLabel(row);
            }
            if (costEl) {
                costEl.value = fmtMoney(row.cost || 0);
            }
            if (typeof cfg.onApplyPick === 'function') {
                cfg.onApplyPick(tr, row);
            }
        }

        function resolveCodeForRow(tr, options) {
            options = options || {};
            if (!tr) {
                return false;
            }
            var codeEl = q(tr, 'code');
            var raw = codeEl ? String(codeEl.value || '').trim() : '';
            if (raw === '') {
                clearLine(tr);
                if (typeof cfg.onAfterResolve === 'function') {
                    cfg.onAfterResolve(tr, null);
                }
                return true;
            }
            var row = pickByCode[normCodeKey(raw)];
            if (row) {
                applyPick(tr, row);
                if (typeof cfg.onAfterResolve === 'function') {
                    cfg.onAfterResolve(tr, row);
                }
                return true;
            }
            if (!options.silent && !silentResolve) {
                alert('كود أو باركود غير معروف: ' + raw);
            }
            return false;
        }

        function renderPickTable(filterText) {
            var body = document.getElementById(modal.body);
            if (!body) {
                return;
            }
            var t = String(filterText || '').trim().toLowerCase();
            body.innerHTML = '';
            for (var i = 0; i < pickRows.length; i++) {
                var r = pickRows[i];
                if (t) {
                    var hay = (
                        r.code + ' ' +
                        (r.barcode || '') + ' ' +
                        r.name + ' ' +
                        (r.color || '') + ' ' +
                        (r.size || '')
                    ).toLowerCase();
                    if (hay.indexOf(t) === -1) {
                        continue;
                    }
                }
                var tr = document.createElement('tr');
                tr.className = 'mo-pick-row';
                tr.setAttribute('data-pick-idx', String(i));
                var bc = r.barcode ? escHtml(r.barcode) : '—';
                tr.innerHTML =
                    '<td>' + escHtml(r.code) + '</td>' +
                    '<td dir="ltr">' + bc + '</td>' +
                    '<td>' + escHtml(r.name) + '</td>' +
                    '<td>' + escHtml(r.color || '') + '</td>' +
                    '<td>' + escHtml(r.size || '') + '</td>' +
                    (cfg.showStock
                        ? ('<td class="mo-pick-num" dir="ltr">' + escHtml(String(r.stock_total != null ? r.stock_total : '—')) + '</td>' +
                           '<td class="mo-pick-num" dir="ltr">' + escHtml(String(r.stock_reserved != null ? r.stock_reserved : '—')) + '</td>' +
                           '<td class="mo-pick-num" dir="ltr">' + escHtml(String(r.stock_available != null ? r.stock_available : '—')) + '</td>')
                        : '') +
                    '<td class="mo-pick-num" dir="ltr">' + escHtml(fmtMoney(r.cost || 0)) + '</td>';
                body.appendChild(tr);
            }
        }

        function openPick(tr) {
            pickTargetRow = tr;
            var modalEl = document.getElementById(modal.root);
            var fil = document.getElementById(modal.filter);
            if (!modalEl) {
                return;
            }
            if (fil) {
                fil.value = '';
            }
            renderPickTable('');
            modalEl.removeAttribute('hidden');
            if (fil) {
                setTimeout(function () {
                    fil.focus();
                }, 0);
            }
        }

        function closePick() {
            pickTargetRow = null;
            var modalEl = document.getElementById(modal.root);
            if (modalEl) {
                modalEl.setAttribute('hidden', '');
            }
        }

        function bindModal() {
            var modalEl = document.getElementById(modal.root);
            var bd = document.getElementById(modal.backdrop);
            var fil = document.getElementById(modal.filter);
            var body = document.getElementById(modal.body);
            if (!modalEl || !body) {
                return;
            }
            if (bd) {
                bd.addEventListener('click', closePick);
            }
            if (fil) {
                fil.addEventListener('input', function () {
                    renderPickTable(fil.value);
                });
            }
            body.addEventListener('dblclick', function (e) {
                var trPick = e.target && e.target.closest ? e.target.closest('tr[data-pick-idx]') : null;
                if (!trPick || !pickTargetRow) {
                    return;
                }
                var idx = parseInt(trPick.getAttribute('data-pick-idx'), 10);
                if (idx < 0 || idx >= pickRows.length) {
                    return;
                }
                applyPick(pickTargetRow, pickRows[idx]);
                closePick();
                if (typeof cfg.onAfterResolve === 'function') {
                    cfg.onAfterResolve(pickTargetRow, pickRows[idx]);
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modalEl && !modalEl.hasAttribute('hidden')) {
                    closePick();
                }
            });
        }

        function bindLinesBody(tb) {
            if (!tb || tb.getAttribute('data-pdpick-bound') === '1') {
                return;
            }
            tb.setAttribute('data-pdpick-bound', '1');
            var codeClass = cfg.codeClass || '';
            tb.addEventListener('dblclick', function (e) {
                if (typeof cfg.isViewMode === 'function' && cfg.isViewMode()) {
                    return;
                }
                var inp = e.target;
                if (!inp || !inp.classList || !codeClass || !inp.classList.contains(codeClass)) {
                    return;
                }
                var tr = inp.closest('tr');
                if (tr && tr.parentElement === tb) {
                    openPick(tr);
                }
            });
        }

        return {
            pickRows: pickRows,
            pickByCode: pickByCode,
            normCodeKey: normCodeKey,
            varLabel: varLabel,
            findPickRowByIds: findPickRowByIds,
            clearLine: clearLine,
            applyPick: applyPick,
            resolveCodeForRow: resolveCodeForRow,
            openPick: openPick,
            closePick: closePick,
            bindModal: bindModal,
            bindLinesBody: bindLinesBody,
            renderPickTable: renderPickTable
        };
    }

    global.OrangePurchaseDocProductPick = {
        create: create,
        normCodeKey: normCodeKey,
        buildPickByCode: buildPickByCode
    };
}(window));
