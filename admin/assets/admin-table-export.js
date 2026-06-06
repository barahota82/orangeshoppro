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

    /* ====== مولّد xlsx حقيقي (Open XML) — يفتح في Excel دون رسالة عدم تطابق التنسيق ====== */
    var CRC_TABLE = (function () {
        var t = [];
        for (var n = 0; n < 256; n++) {
            var c = n;
            for (var k = 0; k < 8; k++) {
                c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
            }
            t[n] = c >>> 0;
        }
        return t;
    })();
    function crc32(bytes) {
        var c = 0xFFFFFFFF;
        for (var i = 0; i < bytes.length; i++) {
            c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
        }
        return (c ^ 0xFFFFFFFF) >>> 0;
    }
    function strBytes(str) {
        if (typeof TextEncoder !== 'undefined') {
            return new TextEncoder().encode(str);
        }
        var u = unescape(encodeURIComponent(str));
        var a = new Uint8Array(u.length);
        for (var i = 0; i < u.length; i++) { a[i] = u.charCodeAt(i); }
        return a;
    }
    function colLetter(n) {
        var s = '';
        n += 1;
        while (n > 0) {
            var m = (n - 1) % 26;
            s = String.fromCharCode(65 + m) + s;
            n = Math.floor((n - 1) / 26);
        }
        return s;
    }
    function xmlEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function sheetXml(rows) {
        var sb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            + '<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0"/></sheetViews>'
            + '<sheetData>';
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            sb += '<row r="' + (r + 1) + '">';
            for (var c = 0; c < row.length; c++) {
                var cell = row[c];
                if (cell == null) { continue; }
                var ref = colLetter(c) + (r + 1);
                if (cell.n && cell.v !== '' && cell.v != null && !isNaN(cell.v)) {
                    sb += '<c r="' + ref + '" t="n"><v>' + (0 + cell.v) + '</v></c>';
                } else {
                    var v = (cell.v == null) ? '' : String(cell.v);
                    if (v !== '') {
                        sb += '<c r="' + ref + '" t="inlineStr"><is><t xml:space="preserve">' + xmlEsc(v) + '</t></is></c>';
                    }
                }
            }
            sb += '</row>';
        }
        sb += '</sheetData></worksheet>';
        return sb;
    }
    function zipStored(files) {
        var chunks = [];
        var offset = 0;
        var entries = [];
        function u16(n) { return new Uint8Array([n & 0xFF, (n >> 8) & 0xFF]); }
        function u32(n) { return new Uint8Array([n & 0xFF, (n >> 8) & 0xFF, (n >> 16) & 0xFF, (n >>> 24) & 0xFF]); }
        function push(a) { chunks.push(a); offset += a.length; }
        for (var i = 0; i < files.length; i++) {
            var nameB = strBytes(files[i].name);
            var dataB = strBytes(files[i].data);
            var crc = crc32(dataB);
            var local = offset;
            push(u32(0x04034b50)); push(u16(20)); push(u16(0x0800)); push(u16(0));
            push(u16(0)); push(u16(0));
            push(u32(crc)); push(u32(dataB.length)); push(u32(dataB.length));
            push(u16(nameB.length)); push(u16(0));
            push(nameB); push(dataB);
            entries.push({ nameB: nameB, crc: crc, size: dataB.length, offset: local });
        }
        var cdStart = offset;
        for (var j = 0; j < entries.length; j++) {
            var e = entries[j];
            push(u32(0x02014b50)); push(u16(20)); push(u16(20)); push(u16(0x0800)); push(u16(0));
            push(u16(0)); push(u16(0));
            push(u32(e.crc)); push(u32(e.size)); push(u32(e.size));
            push(u16(e.nameB.length)); push(u16(0)); push(u16(0)); push(u16(0)); push(u16(0));
            push(u32(0)); push(u32(e.offset));
            push(e.nameB);
        }
        var cdSize = offset - cdStart;
        push(u32(0x06054b50)); push(u16(0)); push(u16(0));
        push(u16(entries.length)); push(u16(entries.length));
        push(u32(cdSize)); push(u32(cdStart)); push(u16(0));
        return new Blob(chunks, { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    }
    function buildXlsx(sheetName, rows) {
        var sn = safeName(sheetName).substring(0, 31) || 'Sheet1';
        var files = [
            { name: '[Content_Types].xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>' },
            { name: '_rels/.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' },
            { name: 'xl/workbook.xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' + xmlEsc(sn) + '" sheetId="1" r:id="rId1"/></sheets></workbook>' },
            { name: 'xl/_rels/workbook.xml.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' },
            { name: 'xl/worksheets/sheet1.xml', data: sheetXml(rows) }
        ];
        return zipStored(files);
    }

    function txtCell(v) { return { v: (v == null ? '' : String(v)), n: false }; }

    /* جدول DOM → صفوف خلايا (المبالغ أرقام، الأكواد نص). */
    function tableToRows(table) {
        var rows = [];
        eachRow(table, function (tr) {
            var cells = tr.children;
            var row = [];
            for (var j = 0; j < cells.length; j++) {
                var c = cells[j];
                if (c.tagName !== 'TD' && c.tagName !== 'TH') { continue; }
                if (isSkipped(c)) { continue; }
                var span = parseInt(c.getAttribute('colspan') || '1', 10) || 1;
                if (isAmountCell(c)) {
                    var num = parseAmount(c.textContent);
                    row.push(num !== null ? { v: num, n: true } : txtCell(cellText(c)));
                } else {
                    row.push(txtCell(cellText(c)));
                }
                for (var k = 1; k < span; k++) { row.push(txtCell('')); }
            }
            rows.push(row);
        });
        return rows;
    }

    function headerRows(company, title, subtitle) {
        var r = [];
        if (company !== '') { r.push([txtCell(company)]); }
        r.push([txtCell(title)]);
        if (subtitle !== '') { r.push([txtCell(subtitle)]); }
        r.push([txtCell('تاريخ الطباعة: ' + nowStamp())]);
        r.push([]);
        return r;
    }

    function exportExcel(table, name) {
        var company = table.getAttribute('data-export-company') || '';
        var title = table.getAttribute('data-export-title') || name;
        var subtitle = table.getAttribute('data-export-subtitle') || '';
        var rows = headerRows(company, title, subtitle).concat(tableToRows(table));
        var blob = buildXlsx(name, rows);
        downloadBlob(safeName(name) + '-' + dateSlug() + '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', [blob]);
    }

    /* تصدير عدّة جداول لتقرير واحد في ورقة واحدة (زر واحد). */
    function exportExcelGroup(tables, name) {
        var first = tables[0];
        var company = first.getAttribute('data-export-company') || '';
        var title = first.getAttribute('data-export-title') || name;
        var subtitle = first.getAttribute('data-export-subtitle') || '';
        var rows = headerRows(company, title, subtitle);
        for (var i = 0; i < tables.length; i++) {
            var label = tables[i].getAttribute('data-export-label') || '';
            if (label !== '') { rows.push([txtCell(label)]); }
            rows = rows.concat(tableToRows(tables[i]));
            rows.push([]);
        }
        var blob = buildXlsx(name, rows);
        downloadBlob(safeName(name) + '-' + dateSlug() + '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', [blob]);
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

    /*
     * إدراج الأزرار داخل حاوية الأدوات بالترتيب الموحّد (عرض ← Excel ← طباعة):
     * بعد زر «عرض» (submit) إن وُجد، وإلا قبل زر الطباعة، وإلا في النهاية
     * — لا نعتمد على نص onclick للطباعة (قد يكون تنبيهاً).
     */
    function insertButtonsInto(target, btns) {
        var submitBtn = target.querySelector('button[type="submit"]');
        var printBtn = target.querySelector('button[onclick*="print"]');
        var anchorAfter = submitBtn && submitBtn.parentNode === target ? submitBtn : null;
        btns.forEach(function (b) {
            b.classList.add('table-export-inline-btn');
            if (anchorAfter) {
                target.insertBefore(b, anchorAfter.nextSibling);
                anchorAfter = b;
            } else if (printBtn) {
                target.insertBefore(b, printBtn);
            } else {
                target.appendChild(b);
            }
        });
    }

    function placeButtons(table, btns) {
        var targetSel = table.getAttribute('data-export-target');
        var target = targetSel ? document.querySelector(targetSel) : null;
        if (target) {
            insertButtonsInto(target, btns);
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

    /* يجد جدول/جداول التقرير الحالي في الصفحة (مجموعة data-export-group أو جدول مفرد). */
    function findReportTables() {
        var grouped = document.querySelectorAll('table[data-export-group]');
        if (grouped.length) {
            return { group: true, tables: Array.prototype.slice.call(grouped) };
        }
        var single = document.querySelector('table[data-export-name]:not([data-export-group])');
        return single ? { group: false, tables: [single] } : null;
    }

    function hostExportName(found) {
        if (!found || !found.tables.length) {
            return 'report';
        }
        return found.tables[0].getAttribute('data-export-name') || 'report';
    }

    /*
     * زر Excel/CSV ثابت دائماً في شريط الأدوات (مثل «طباعة»):
     * علّم الحاوية بـ data-export-host. عند الضغط يبحث عن جدول التقرير ويصدّره،
     * وإن لم يُعرض التقرير بعد (لا يوجد جدول) يُظهر تنبيهاً — بدل إخفاء الزر.
     */
    function initHosts(scope) {
        var hosts = scope.querySelectorAll('[data-export-host]');
        for (var i = 0; i < hosts.length; i++) {
            var host = hosts[i];
            if (host.getAttribute('data-export-host-init') === '1') {
                continue;
            }
            host.setAttribute('data-export-host-init', '1');
            var btns = [];
            btns.push(makeBtn('Excel', function () {
                var found = findReportTables();
                if (!found) {
                    alert('اعرض التقرير أولاً لتصدير Excel.');
                    return;
                }
                if (found.group) {
                    exportExcelGroup(found.tables, hostExportName(found));
                } else {
                    exportExcel(found.tables[0], hostExportName(found));
                }
            }));
            if (host.hasAttribute('data-export-csv')) {
                btns.push(makeBtn('CSV', function () {
                    var found = findReportTables();
                    if (!found) {
                        alert('اعرض التقرير أولاً لتصدير CSV.');
                        return;
                    }
                    exportCsv(found.tables[0], hostExportName(found));
                }));
            }
            insertButtonsInto(host, btns);
        }
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;

        /*
         * إن وُجدت حاوية أدوات معلَّمة data-export-host في الصفحة، نعتمدها (زر ثابت دائماً)
         * ونتخطّى الحقن المعتمد على وجود الجدول لتفادي تكرار الأزرار.
         */
        if (document.querySelector('[data-export-host]')) {
            initHosts(scope);
            return;
        }

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
        var el = document.querySelector('[data-report-name]');
        if (el && (el.getAttribute('data-report-name') || '') !== '') {
            return el.getAttribute('data-report-name') || '';
        }
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

    /* تنزيل تصدير خادمي (a[data-server-export]) عبر iframe مخفي — كي لا تذهب الصفحة لشاشة بيضاء. */
    document.addEventListener('click', function (e) {
        var a = e.target && e.target.closest ? e.target.closest('a[data-server-export]') : null;
        if (!a || !a.getAttribute('href')) {
            return;
        }
        e.preventDefault();
        var ifr = document.createElement('iframe');
        ifr.style.display = 'none';
        ifr.src = a.getAttribute('href');
        document.body.appendChild(ifr);
        setTimeout(function () {
            if (ifr.parentNode) {
                ifr.parentNode.removeChild(ifr);
            }
        }, 120000);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    window.OrangeTableExport = { init: init, exportCsv: exportCsv, exportExcel: exportExcel };
})();
