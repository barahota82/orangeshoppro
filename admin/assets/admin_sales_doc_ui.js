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

    /**
     * يضمن توكن المستند ويرسم QR في صندوق الطباعة، ثم ينادي done() (دائماً).
     * docKind: inv_c|inv_o|sales_return|purchase|purchase_return ؛ docId: رقم المستند المحفوظ.
     */
    function setDocQr(prefix, docKind, docId, done) {
        done = typeof done === 'function' ? done : function () {};
        var box = document.getElementById(String(prefix || 'sd') + '_sd_print_qr');
        if (!box) { done(); return; }
        var id = parseInt(String(docId || '0'), 10) || 0;
        // مؤقّت (وضع معاينة): اعرض QR يشير لصفحة المعاينة داخل المربع دائماً حتى قبل الحفظ ليُمسح بالكاميرا.
        if (typeof global.qrcode === 'function') {
            try {
                var pkind = String(docKind || '').trim();
                var purl = global.location.origin + '/pages/document.php?preview=1' + (pkind ? '&kind=' + encodeURIComponent(pkind) : '');
                var pqr = global.qrcode(0, 'M');
                pqr.addData(purl);
                pqr.make();
                box.innerHTML = pqr.createImgTag(3, 0);
                var pimg = box.querySelector('img');
                if (pimg) { pimg.style.width = '100%'; pimg.style.height = 'auto'; pimg.style.display = 'block'; }
            } catch (e) { box.innerHTML = ''; }
        }
        done();
        return;
        /* eslint-disable no-unreachable */
        // صندوق الباركود يبقى ظاهراً دائماً؛ يُملأ فقط للمستند المحفوظ (التوكن يُولَّد بعد الحفظ).
        if (!docKind || id <= 0 || typeof global.qrcode !== 'function') {
            box.innerHTML = '';
            done();
            return;
        }
        var url = '/admin/api/doc-token/ensure.php?doc_kind=' + encodeURIComponent(docKind) + '&doc_id=' + id;
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.url) {
                    try {
                        var qr = global.qrcode(0, 'M');
                        qr.addData(String(res.url));
                        qr.make();
                        box.innerHTML = qr.createImgTag(3, 0);
                        var img = box.querySelector('img');
                        if (img) { img.style.width = '100%'; img.style.height = 'auto'; img.style.display = 'block'; }
                    } catch (e) { /* اترك الصندوق فارغاً عند الفشل */ }
                }
                done();
            })
            .catch(function () { done(); });
    }

    function buildPdfTitle(opts) {
        var label = String(opts.docLabel || '').trim();
        var serial = '';
        if (opts.serialElId) {
            var el = document.getElementById(opts.serialElId);
            if (el) serial = String(el.value || el.textContent || '').trim();
        }
        if (serial === '') return label;
        return label !== '' ? (label + ' رقم ' + serial) : serial;
    }

    function bindPrintButton(btnId, opts) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            syncPrintBanner(opts);
            if (typeof opts.beforePrint === 'function') {
                if (opts.beforePrint() === false) return;
            }
            var pdfTitle = buildPdfTitle(opts);
            var openDialog = function () {
                if (typeof global.orangeAdminOpenPrintDialog === 'function') {
                    global.orangeAdminOpenPrintDialog(pdfTitle);
                } else {
                    global.print();
                }
            };
            var docId = typeof opts.docId === 'function' ? opts.docId() : 0;
            if (opts.docKind && (parseInt(String(docId || '0'), 10) || 0) > 0) {
                setDocQr(opts.prefix, opts.docKind, docId, openDialog);
            } else {
                setDocQr(opts.prefix, opts.docKind, 0, openDialog);
            }
        });
    }

    /* ===== GAP-ACC-07-DISP: صندوق إجماليات سفلي موحّد (طباعة) ===== */

    // اتجاه كل line_kind محاسبياً (ثابت — مطابق orange_invoice_ancillary_line_kind_catalog).
    var ANCILLARY_SIDE_BY_KIND = {
        sales_credit_revenue: 'credit',
        sales_debit_contra: 'debit',
        sales_credit_liability: 'credit',
        purchase_debit_asset: 'debit',
        purchase_debit_landed: 'debit',
        purchase_debit_vat_input: 'debit',
        purchase_credit_contra: 'credit'
    };
    var ANCILLARY_VAT_KINDS = { sales_credit_liability: 1, purchase_debit_vat_input: 1 };

    function parseMoney(txt) {
        var s = String(txt == null ? '' : txt).replace(/[^0-9.\-]/g, '');
        var v = parseFloat(s);
        return isFinite(v) ? v : 0;
    }

    function money3(v) {
        var n = Number(v) || 0;
        return n.toFixed(3);
    }

    /**
     * الإشارة المعروضة للعميل/المورد لبند إضافي:
     *   مبيعات: دائن (+) / مدين (−).   مشتريات: مدين (+) / دائن (−).
     * @returns {number} +1 أو -1
     */
    function ancillaryDisplaySign(context, lineKind) {
        var side = ANCILLARY_SIDE_BY_KIND[String(lineKind || '')] || '';
        if (String(context) === 'purchase') {
            return side === 'debit' ? 1 : -1;
        }
        return side === 'credit' ? 1 : -1;
    }

    /**
     * يبني صفوف صندوق الإجماليات السفلي ويملأ الإجمالي النهائي أعلى الفاتورة.
     * opts: {
     *   prefix, context:'sales'|'purchase',
     *   subtotalId, discountId, netId,          // معرّفات إجماليات الشاشة
     *   collectExtra: function -> [{line_kind, amount, label_ar, show_on_print}],
     *   unit,                                    // وحدة العملة (نص)
     *   labels: { items, items_disc, net_items, vat },  // {ar,en}
     *   finalLabel: {ar,en}
     * }
     * @returns {number} الإجمالي النهائي
     */
    function renderDocTotals(opts) {
        opts = opts || {};
        var prefix = String(opts.prefix || 'sd');
        var context = String(opts.context || 'sales');
        var unit = String(opts.unit || '');
        var L = opts.labels || {};
        var subtotal = parseMoney(getText(opts.subtotalId));
        var itemsDisc = parseMoney(getText(opts.discountId));
        var netItems = parseMoney(getText(opts.netId));
        if (!isFinite(netItems) || (opts.netId == null)) netItems = subtotal - itemsDisc;

        var lines = (typeof opts.collectExtra === 'function') ? (opts.collectExtra() || []) : [];
        var rows = [];
        rows.push({ kind: 'sub', label: L.items, val: subtotal, sign: 0 });
        if (itemsDisc > 0) {
            rows.push({ kind: 'disc', label: L.items_disc, val: itemsDisc, sign: -1 });
        }
        rows.push({ kind: 'net', label: L.net_items, val: netItems, sign: 0, sep: true });

        var grand = netItems;
        lines.forEach(function (ln) {
            if (!ln || !ln.show_on_print) return;
            var amt = Number(ln.amount) || 0;
            if (amt <= 0) return;
            var sign = ancillaryDisplaySign(context, ln.line_kind);
            grand += sign * amt;
            var lbl = String(ln.label_ar || '').trim();
            if (lbl === '' && ANCILLARY_VAT_KINDS[String(ln.line_kind || '')]) {
                lbl = (L.vat && L.vat.ar) ? L.vat.ar : 'ضريبة القيمة المضافة';
            }
            if (lbl === '') lbl = (sign > 0 ? 'بند إضافي' : 'خصم');
            rows.push({ kind: 'extra', label: { ar: lbl, en: '' }, val: amt, sign: sign });
        });

        var fl = opts.finalLabel || { ar: 'الإجمالي', en: 'Total' };
        rows.push({ kind: 'final', label: fl, val: grand, sign: 0, unit: unit });

        var body = document.getElementById(prefix + '_sd_print_totals_body');
        if (body) {
            var html = '';
            rows.forEach(function (r) {
                var cls = 'sd-print-totals__row sd-print-totals__row--' + r.kind + (r.sep ? ' sd-print-totals__row--sep' : '');
                var lab = r.label || {};
                var labHtml = '<span class="sd-print-totals__lbl-ar">'
                    + escapeHtml(String(lab.ar || '')) + '</span>';
                if (lab.en) {
                    labHtml += '<span class="sd-print-totals__lbl-en" dir="ltr" lang="en">'
                        + escapeHtml(String(lab.en)) + '</span>';
                }
                var signTxt = r.sign > 0 ? '+\u00a0' : (r.sign < 0 ? '\u2212\u00a0' : '');
                var unitTxt = r.unit ? (' <span class="sd-print-totals__unit">' + escapeHtml(r.unit) + '</span>') : '';
                html += '<tr class="' + cls + '">'
                    + '<td class="sd-print-totals__lbl">' + labHtml + '</td>'
                    + '<td class="sd-print-totals__val" dir="ltr" lang="en">'
                    + signTxt + money3(r.val) + unitTxt + '</td></tr>';
            });
            body.innerHTML = html;
        }

        // أسطر داخل جدول الأصناف (للطباعة/الـ QR فقط) — بديل الصندوق السفلي المنفصل.
        if (opts.intableId) {
            var intbl = document.getElementById(String(opts.intableId));
            if (intbl) {
                var cspan = Math.max(1, parseInt(opts.intableColspan, 10) || 7);
                var ihtml = '';
                rows.forEach(function (r) {
                    var cls2 = 'sd-intable-tot sd-intable-tot--' + r.kind + (r.sep ? ' sd-intable-tot--sep' : '');
                    var lab2 = (r.label && r.label.ar) ? r.label.ar : '';
                    var signTxt2 = r.sign > 0 ? '+\u00a0' : (r.sign < 0 ? '\u2212\u00a0' : '');
                    var unitTxt2 = r.unit ? (' ' + escapeHtml(r.unit)) : '';
                    ihtml += '<tr class="' + cls2 + '">'
                        + '<td colspan="' + cspan + '" class="sd-intable-tot__lbl">' + escapeHtml(lab2) + '</td>'
                        + '<td class="sd-intable-tot__val" dir="ltr" lang="en">'
                        + signTxt2 + money3(r.val) + unitTxt2 + '</td></tr>';
                });
                intbl.innerHTML = ihtml;
            }
        }

        var headEl = document.getElementById(prefix + '_sd_print_total');
        if (headEl) headEl.textContent = money3(grand);

        // إخراج على الشاشة (اختياري): ملخّص البنود الإضافية الظاهرة + الإجمالي النهائي.
        if (opts.screenExtraId) {
            var sc = document.getElementById(String(opts.screenExtraId));
            if (sc) {
                var sh = '';
                rows.forEach(function (r) {
                    if (r.kind !== 'extra') return;
                    var sgn = r.sign < 0 ? '\u2212\u00a0' : '+\u00a0';
                    var col = r.sign < 0 ? '#b91c1c' : '#0f172a';
                    var lab = (r.label && r.label.ar) ? r.label.ar : '';
                    sh += '<span style="color:#64748b;">' + escapeHtml(lab) + ':</span> '
                        + '<strong class="admin-money-display" dir="ltr" lang="en" style="color:' + col + ';">'
                        + sgn + money3(r.val) + '</strong><br>';
                });
                sc.innerHTML = sh;
            }
        }
        if (opts.grandOutId) {
            var gEl = document.getElementById(String(opts.grandOutId));
            if (gEl) gEl.textContent = money3(grand);
        }
        if (opts.grandLabelId && opts.finalLabel) {
            var glEl = document.getElementById(String(opts.grandLabelId));
            if (glEl) glEl.textContent = (opts.finalLabel.ar || '') + ':';
        }

        // عرض مطوي (3 أسطر): الرسوم الإضافية الظاهرة (+) تُضاف لخانة «الإجمالي»،
        // والخصومات الإضافية الظاهرة (−) تُضاف لخانة «الخصم»، والصافي = الإجمالي − الخصم (= grand).
        if (opts.foldTotalId || opts.foldDiscountId || opts.foldNetId) {
            var foldChargeSum = 0;
            var foldDiscExtra = 0;
            lines.forEach(function (ln) {
                if (!ln || !ln.show_on_print) return;
                var amt2 = Number(ln.amount) || 0;
                if (amt2 <= 0) return;
                if (ancillaryDisplaySign(context, ln.line_kind) > 0) foldChargeSum += amt2;
                else foldDiscExtra += amt2;
            });
            var foldTotal = subtotal + foldChargeSum;
            var foldDiscount = itemsDisc + foldDiscExtra;
            var foldNet = foldTotal - foldDiscount;
            var setFold = function (id, v) {
                if (!id) return;
                var el = document.getElementById(String(id));
                if (el) el.textContent = money3(v);
            };
            setFold(opts.foldTotalId, foldTotal);
            setFold(opts.foldDiscountId, foldDiscount);
            setFold(opts.foldNetId, foldNet);
        }
        return grand;
    }

    function getText(id) {
        if (!id) return '';
        var el = document.getElementById(String(id));
        return el ? String(el.textContent || '').trim() : '';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    global.orangeSalesDocSetPhoneCells = setPhoneCells;
    global.orangeSalesDocUi = {
        rememberChannel: rememberChannel,
        applyDefaultChannel: applyDefaultChannel,
        syncPrintBanner: syncPrintBanner,
        bindPrintButton: bindPrintButton,
        setPhoneCells: setPhoneCells,
        setDocQr: setDocQr,
        renderDocTotals: renderDocTotals,
        ancillaryDisplaySign: ancillaryDisplaySign
    };
}(typeof window !== 'undefined' ? window : this));
