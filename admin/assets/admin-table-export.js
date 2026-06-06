/**
 * Orange — تصدير جداول التقارير (Excel + CSV) موحّد.
 *
 * الاستخدام: علّم جدول التقرير بسمة data-export-name="اسم الملف".
 * يُضاف تلقائياً شريط أزرار (Excel / CSV [/ طباعة]) فوق الجدول.
 *
 * - Excel: صيغة xls (HTML + MIME application/vnd.ms-excel) تدعم العربية + RTL، بصفر اعتماديات.
 * - CSV: UTF-8 مع BOM (تفتح العربية سليمة في Excel).
 * - يتجاهل العناصر المعلَّمة data-export-skip و .gl-acc-stmt-no-print.
 * - اختياري: data-export-print لإضافة زر «طباعة / حفظ PDF».
 */
(function () {
    'use strict';

    function cellText(cell) {
        var t = cell.innerText || cell.textContent || '';
        return t.replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function isSkipped(el) {
        if (!el) {
            return false;
        }
        if (el.hasAttribute && (el.hasAttribute('data-export-skip') || el.classList.contains('gl-acc-stmt-no-print'))) {
            return true;
        }
        return false;
    }

    function safeName(name) {
        return String(name || 'report').replace(/[\\\/\?\*\[\]:]+/g, ' ').trim() || 'report';
    }

    function downloadBlob(filename, mime, parts) {
        var blob = new Blob(parts, { type: mime });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }, 0);
    }

    function eachRow(table, fn) {
        var rows = table.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
            var tr = rows[i];
            if (isSkipped(tr)) {
                continue;
            }
            fn(tr);
        }
    }

    function exportCsv(table, name) {
        var lines = [];
        eachRow(table, function (tr) {
            var cells = tr.children;
            var out = [];
            for (var j = 0; j < cells.length; j++) {
                var c = cells[j];
                if (c.tagName !== 'TD' && c.tagName !== 'TH') {
                    continue;
                }
                if (isSkipped(c)) {
                    continue;
                }
                var span = parseInt(c.getAttribute('colspan') || '1', 10) || 1;
                out.push('"' + cellText(c).replace(/"/g, '""') + '"');
                for (var k = 1; k < span; k++) {
                    out.push('""');
                }
            }
            lines.push(out.join(','));
        });
        downloadBlob(safeName(name) + '.csv', 'text/csv;charset=utf-8', ['\ufeff' + lines.join('\r\n')]);
    }

    function htmlEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    function nowStamp() {
        var d = new Date();
        return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    function dateSlug() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    /* تحويل الأرقام العربية/الهندية إلى لاتينية. */
    function toLatinDigits(s) {
        return String(s)
            .replace(/[\u0660-\u0669]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); })
            .replace(/[\u06f0-\u06f9]/g, function (d) { return String(d.charCodeAt(0) - 0x06f0); });
    }

    /* يحوّل نص مبلغ معروض إلى رقم خام (يزيل الفواصل والعملة؛ الأقواس = سالب). null إن لم يكن رقماً. */
    function parseAmount(text) {
        var t = toLatinDigits(text == null ? '' : text).trim();
        if (t === '') {
            return null;
        }
        var neg = /^\(.*\)$/.test(t);
        t = t.replace(/[()]/g, '');
        t = t.replace(/\u066b/g, '.').replace(/\u066c/g, '');
        t = t.replace(/[,\u00a0\s]/g, '');
        var m = t.match(/^-?\d+(\.\d+)?$/);
        if (!m) {
            return null;
        }
        var v = parseFloat(m[0]);
        if (isNaN(v)) {
            return null;
        }
        return neg && v > 0 ? -v : v;
    }

    /* خلية رقمية = مبلغ (تُحوَّل لرقم) بشرط ألا تكون كوداً/معرّفاً نصياً (dir=ltr أو data-export-text). */
    function isAmountCell(cell) {
        if (cell.tagName !== 'TD') {
            return false;
        }
        if (!cell.classList.contains('gl-acc-stmt-col-num') && !cell.classList.contains('cf-col-amount')) {
            return false;
        }
        if (cell.getAttribute('dir') === 'ltr' || cell.hasAttribute('data-export-text') || cell.classList.contains('tb-col-code')) {
            return false;
        }
        return true;
    }

    /* نسخة جدول جاهزة للتصدير: حذف العناصر المُستثناة + تحويل المبالغ لأرقام. */
    function processedTableHtml(table) {
        var clone = table.cloneNode(true);
        var skips = clone.querySelectorAll('[data-export-skip], .gl-acc-stmt-no-print');
        for (var i = 0; i < skips.length; i++) {
            if (skips[i].parentNode) {
                skips[i].parentNode.removeChild(skips[i]);
            }
        }
        var amountCells = clone.querySelectorAll('td.gl-acc-stmt-col-num, td.cf-col-amount');
        for (var n = 0; n < amountCells.length; n++) {
            var ac = amountCells[n];
            if (!isAmountCell(ac)) {
                continue;
            }
            var num = parseAmount(ac.textContent);
            if (num === null) {
                continue;
            }
            ac.setAttribute('style', (ac.getAttribute('style') || '') + ';mso-number-format:"#,##0.###";');
            ac.textContent = String(num);
        }
        return clone.outerHTML;
    }

    function headerBlockHtml(company, title, subtitle) {
        var head = '';
        if (company !== '') {
            head += '<div style="font-size:14pt;font-weight:bold;">' + htmlEsc(company) + '</div>';
        }
        head += '<div style="font-size:13pt;font-weight:bold;">' + htmlEsc(title) + '</div>';
        if (subtitle !== '') {
            head += '<div style="font-size:11pt;">' + htmlEsc(subtitle) + '</div>';
        }
        head += '<div style="font-size:9pt;color:#666666;">تاريخ الطباعة: ' + htmlEsc(nowStamp()) + '</div><br>';
        return head;
    }

    function buildXlsDoc(sheetName, bodyHtml) {
        return '\ufeff<html xmlns:o="urn:schemas-microsoft-com:office:office" ' +
            'xmlns:x="urn:schemas-microsoft-com:office:excel" ' +
            'xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8">' +
            '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>' +
            '<x:Name>' + sheetName + '</x:Name><x:WorksheetOptions><x:DisplayRightToLeft/>' +
            '</x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->' +
            '<style>table{border-collapse:collapse;}' +
            'td,th{border:0.5pt solid #999999;padding:3px 7px;mso-number-format:"\\@";white-space:nowrap;vertical-align:middle;}' +
            'th{background:#e8eef5;font-weight:bold;text-align:center;}</style></head>' +
            '<body dir="rtl">' + bodyHtml + '</body></html>';
    }

    function exportExcel(table, name) {
        var company = table.getAttribute('data-export-company') || '';
        var title = table.getAttribute('data-export-title') || name;
        var subtitle = table.getAttribute('data-export-subtitle') || '';
        var doc = buildXlsDoc(safeName(name).substring(0, 31), headerBlockHtml(company, title, subtitle) + processedTableHtml(table));
        downloadBlob(safeName(name) + '-' + dateSlug() + '.xls', 'application/vnd.ms-excel;charset=utf-8', [doc]);
    }

    /* تصدير عدّة جداول لتقرير واحد في ورقة Excel واحدة (زر واحد). */
    function exportExcelGroup(tables, name) {
        var first = tables[0];
        var company = first.getAttribute('data-export-company') || '';
        var title = first.getAttribute('data-export-title') || name;
        var subtitle = first.getAttribute('data-export-subtitle') || '';
        var body = headerBlockHtml(company, title, subtitle);
        for (var i = 0; i < tables.length; i++) {
            var label = tables[i].getAttribute('data-export-label') || '';
            if (label !== '') {
                body += '<div style="font-size:12pt;font-weight:bold;margin:10px 0 2px;">' + htmlEsc(label) + '</div>';
            }
            body += processedTableHtml(tables[i]) + '<br>';
        }
        var doc = buildXlsDoc(safeName(name).substring(0, 31), body);
        downloadBlob(safeName(name) + '-' + dateSlug() + '.xls', 'application/vnd.ms-excel;charset=utf-8', [doc]);
    }

    function makeBtn(label, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn-secondary';
        b.textContent = label;
        b.addEventListener('click', onClick);
        return b;
    }

    function exportButtons(table, inline) {
        var name = table.getAttribute('data-export-name') || 'report';
        /* وسم مختصر للزر عند تعدّد الجداول في نفس التقرير (مثلاً «الإيرادات»/«المصروفات»). */
        var shortLabel = table.getAttribute('data-export-label') || '';
        var excelLabel = shortLabel !== '' ? 'Excel - ' + shortLabel : 'Excel';
        var out = [];
        var bx = makeBtn(excelLabel, function () { exportExcel(table, name); });
        out.push(bx);
        /* CSV اختياري (مطفأ افتراضياً بقرار المالك 2026-06-06) — يُفعَّل بسمة data-export-csv. */
        if (table.hasAttribute('data-export-csv')) {
            out.push(makeBtn('CSV', function () { exportCsv(table, name); }));
        }
        if (table.hasAttribute('data-export-print')) {
            out.push(makeBtn('طباعة / حفظ PDF', function () { window.print(); }));
        }
        if (inline) {
            out.forEach(function (b) { b.classList.add('table-export-inline-btn'); });
        }
        return out;
    }

    function placeButtons(table, btns) {
        var targetSel = table.getAttribute('data-export-target');
        var target = targetSel ? document.querySelector(targetSel) : null;
        if (target) {
            btns.forEach(function (b) { b.classList.add('table-export-inline-btn'); target.appendChild(b); });
            return;
        }
        var bar = document.createElement('div');
        bar.className = 'table-export-bar gl-acc-stmt-no-print';
        var lbl = document.createElement('span');
        lbl.className = 'table-export-bar__label';
        lbl.textContent = 'تنزيل:';
        bar.appendChild(lbl);
        btns.forEach(function (b) { b.classList.add('table-export-bar__btn'); bar.appendChild(b); });
        var anchor = table.closest('.table-wrap') || table;
        if (anchor.parentNode) {
            anchor.parentNode.insertBefore(bar, anchor);
        }
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;

        /* أولاً: تقارير متعددة الجداول مجمّعة بـ data-export-group → زر Excel واحد لورقة واحدة. */
        var grouped = scope.querySelectorAll('table[data-export-group]');
        var groups = {};
        var order = [];
        for (var g = 0; g < grouped.length; g++) {
            var gt = grouped[g];
            if (gt.getAttribute('data-export-init') === '1') {
                continue;
            }
            gt.setAttribute('data-export-init', '1');
            var key = gt.getAttribute('data-export-group') || '';
            if (!groups[key]) {
                groups[key] = [];
                order.push(key);
            }
            groups[key].push(gt);
        }
        order.forEach(function (key) {
            var list = groups[key];
            var first = list[0];
            var gname = first.getAttribute('data-export-name') || 'report';
            placeButtons(first, [makeBtn('Excel', function () { exportExcelGroup(list, gname); })]);
        });

        /* ثم: الجداول المفردة. */
        var tables = scope.querySelectorAll('table[data-export-name]:not([data-export-group])');
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            if (table.getAttribute('data-export-init') === '1') {
                continue;
            }
            table.setAttribute('data-export-init', '1');
            placeButtons(table, exportButtons(table, false));
        }
    }

    /* اسم ملف PDF عند الحفظ = اسم التقرير: نضبط عنوان المستند وقت الطباعة ونعيده بعدها. */
    function reportNameForPrint() {
        var t = document.querySelector('table[data-export-name]');
        return t ? (t.getAttribute('data-export-name') || '') : '';
    }
    var savedDocTitle = null;
    window.addEventListener('beforeprint', function () {
        var rn = reportNameForPrint();
        if (rn !== '') {
            savedDocTitle = document.title;
            document.title = rn + ' ' + dateSlug();
        }
    });
    window.addEventListener('afterprint', function () {
        if (savedDocTitle !== null) {
            document.title = savedDocTitle;
            savedDocTitle = null;
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    window.OrangeTableExport = { init: init, exportCsv: exportCsv, exportExcel: exportExcel };
})();
