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

    function exportExcel(table, name) {
        var clone = table.cloneNode(true);
        var skips = clone.querySelectorAll('[data-export-skip], .gl-acc-stmt-no-print');
        for (var i = 0; i < skips.length; i++) {
            if (skips[i].parentNode) {
                skips[i].parentNode.removeChild(skips[i]);
            }
        }
        var sheet = safeName(name).substring(0, 31);
        var html =
            '\ufeff<html xmlns:o="urn:schemas-microsoft-com:office:office" ' +
            'xmlns:x="urn:schemas-microsoft-com:office:excel" ' +
            'xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8">' +
            '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>' +
            '<x:Name>' + sheet + '</x:Name><x:WorksheetOptions><x:DisplayRightToLeft/>' +
            '</x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->' +
            '<style>table{border-collapse:collapse;}td,th{border:0.5pt solid #999999;padding:2px 5px;mso-number-format:"\\@";}' +
            'th{background:#f0f0f0;font-weight:bold;}</style></head>' +
            '<body dir="rtl">' + clone.outerHTML + '</body></html>';
        downloadBlob(safeName(name) + '.xls', 'application/vnd.ms-excel;charset=utf-8', [html]);
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
        var out = [];
        var bx = makeBtn('Excel', function () { exportExcel(table, name); });
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

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var tables = scope.querySelectorAll('table[data-export-name]');
        for (var i = 0; i < tables.length; i++) {
            var table = tables[i];
            if (table.getAttribute('data-export-init') === '1') {
                continue;
            }
            /* الأفضل: حقن الأزرار في شريط إجراءات موجود (أعلى التقرير، خارج هيكله). */
            var targetSel = table.getAttribute('data-export-target');
            var target = targetSel ? document.querySelector(targetSel) : null;
            if (target) {
                table.setAttribute('data-export-init', '1');
                exportButtons(table, true).forEach(function (b) { target.appendChild(b); });
                continue;
            }
            /* احتياطي: شريط مستقل فوق الجدول. */
            table.setAttribute('data-export-init', '1');
            var bar = document.createElement('div');
            bar.className = 'table-export-bar gl-acc-stmt-no-print';
            var lbl = document.createElement('span');
            lbl.className = 'table-export-bar__label';
            lbl.textContent = 'تنزيل:';
            bar.appendChild(lbl);
            exportButtons(table, false).forEach(function (b) { b.classList.add('table-export-bar__btn'); bar.appendChild(b); });
            var anchor = table.closest('.table-wrap') || table;
            if (anchor.parentNode) {
                anchor.parentNode.insertBefore(bar, anchor);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    window.OrangeTableExport = { init: init, exportCsv: exportCsv, exportExcel: exportExcel };
})();
