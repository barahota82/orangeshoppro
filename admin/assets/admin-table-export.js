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
        if (el.classList && (el.classList.contains('ta-report-banner-row') || el.classList.contains('ta-report-grid-row'))) {
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
    /* أنماط xlsx: 0=ترويسة، 1=عناوين أعمدة، 2=بيانات نص، 3=بيانات رقم */
    var XLSX_STYLES_XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        + '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        + '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        + '<fonts count="2">'
        + '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        + '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        + '</fonts>'
        + '<fills count="3">'
        + '<fill><patternFill patternType="none"/></fill>'
        + '<fill><patternFill patternType="gray125"/></fill>'
        + '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>'
        + '</fills>'
        + '<borders count="2">'
        + '<border><left/><right/><top/><bottom/><diagonal/></border>'
        + '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right>'
        + '<top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>'
        + '</borders>'
        + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        + '<cellXfs count="4">'
        + '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        + '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
        + '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        + '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
        + '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        + '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'
        + '<alignment horizontal="center" vertical="center"/></xf>'
        + '</cellXfs>'
        + '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        + '</styleSheet>';

    function xlsxStyleAttr(styleId) {
        return styleId > 0 ? ' s="' + styleId + '"' : '';
    }

    /* تحويل عرض الخلية بالبكسل (Calibri 11) إلى وحدة عرض عمود Excel */
    function pxToExcelColWidth(px) {
        var w = (px - 5) / 7;
        if (w < 4) {
            w = 4;
        }
        if (w > 90) {
            w = 90;
        }
        return Math.round(w * 100) / 100;
    }

    /* عرض الأعمدة من colgroup (نسب %) أو من صف العناوين المرسوم على الشاشة */
    function tableColWidths(table) {
        var tableW = table.getBoundingClientRect().width;
        if (tableW > 0) {
            var cg = table.querySelector('colgroup');
            if (cg) {
                var cols = cg.querySelectorAll('col');
                if (cols.length > 0) {
                    var fromColgroup = [];
                    for (var ci = 0; ci < cols.length; ci++) {
                        var colEl = cols[ci];
                        var px = colEl.getBoundingClientRect().width;
                        if (px <= 0) {
                            var cw = window.getComputedStyle(colEl).width || '';
                            if (cw.indexOf('%') !== -1) {
                                px = tableW * (parseFloat(cw) / 100);
                            } else {
                                px = parseFloat(cw) || 0;
                            }
                        }
                        fromColgroup.push(pxToExcelColWidth(px));
                    }
                    if (fromColgroup.length > 0) {
                        return fromColgroup;
                    }
                }
            }
        }

        var headerRow = table.querySelector('tr.ta-report-cols-row');
        if (!headerRow) {
            var trs = table.querySelectorAll('tr');
            for (var ti = 0; ti < trs.length; ti++) {
                if (isSkipped(trs[ti])) {
                    continue;
                }
                var ths = trs[ti].querySelectorAll('th');
                if (ths.length > 0) {
                    headerRow = trs[ti];
                    break;
                }
            }
        }
        if (!headerRow) {
            return [];
        }

        var widths = [];
        var cells = headerRow.children;
        for (var j = 0; j < cells.length; j++) {
            var c = cells[j];
            if (c.tagName !== 'TH' && c.tagName !== 'TD') {
                continue;
            }
            if (isSkipped(c)) {
                continue;
            }
            var span = parseInt(c.getAttribute('colspan') || '1', 10) || 1;
            var px = c.getBoundingClientRect().width;
            var perCol = span > 0 ? (px / span) : px;
            var excelW = pxToExcelColWidth(perCol);
            for (var k = 0; k < span; k++) {
                widths.push(excelW);
            }
        }
        return widths;
    }

    function mergeColWidths(tables) {
        var merged = [];
        for (var t = 0; t < tables.length; t++) {
            var ws = tableColWidths(tables[t]);
            for (var i = 0; i < ws.length; i++) {
                if (!merged[i] || ws[i] > merged[i]) {
                    merged[i] = ws[i];
                }
            }
        }
        return merged;
    }

    function colsXml(colWidths) {
        if (!colWidths || !colWidths.length) {
            return '';
        }
        var sb = '<cols>';
        for (var i = 0; i < colWidths.length; i++) {
            var w = colWidths[i];
            if (!w || w <= 0) {
                continue;
            }
            var idx = i + 1;
            sb += '<col min="' + idx + '" max="' + idx + '" width="' + w + '" customWidth="1"/>';
        }
        sb += '</cols>';
        return sb;
    }

    function sheetXml(rows, colWidths) {
        var sb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            + '<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0"/></sheetViews>'
            + colsXml(colWidths)
            + '<sheetData>';
        for (var r = 0; r < rows.length; r++) {
            var row = rows[r];
            sb += '<row r="' + (r + 1) + '">';
            for (var c = 0; c < row.length; c++) {
                var cell = row[c];
                if (cell == null) { continue; }
                var ref = colLetter(c) + (r + 1);
                var styleId = cell.s > 0 ? cell.s : 0;
                var styled = styleId > 0;
                if (cell.n && cell.v !== '' && cell.v != null && !isNaN(cell.v)) {
                    sb += '<c r="' + ref + '"' + xlsxStyleAttr(styleId) + ' t="n"><v>' + (0 + cell.v) + '</v></c>';
                } else {
                    var v = (cell.v == null) ? '' : String(cell.v);
                    if (v !== '' || styled) {
                        if (v === '') {
                            sb += '<c r="' + ref + '"' + xlsxStyleAttr(styleId) + '/>';
                        } else {
                            sb += '<c r="' + ref + '"' + xlsxStyleAttr(styleId)
                                + ' t="inlineStr"><is><t xml:space="preserve">' + xmlEsc(v) + '</t></is></c>';
                        }
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
    function buildXlsx(sheetName, rows, colWidths) {
        var sn = safeName(sheetName).substring(0, 31) || 'Sheet1';
        var files = [
            { name: '[Content_Types].xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>' },
            { name: '_rels/.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' },
            { name: 'xl/workbook.xml', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' + xmlEsc(sn) + '" sheetId="1" r:id="rId1"/></sheets></workbook>' },
            { name: 'xl/_rels/workbook.xml.rels', data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' },
            { name: 'xl/styles.xml', data: XLSX_STYLES_XML },
            { name: 'xl/worksheets/sheet1.xml', data: sheetXml(rows, colWidths) }
        ];
        return zipStored(files);
    }

    function txtCell(v, styleId) {
        return { v: (v == null ? '' : String(v)), n: false, s: styleId > 0 ? styleId : 0 };
    }

    /* جدول DOM → صفوف خلايا (المبالغ أرقام، الأكواد نص، برواز شبكة كاملة). */
    function tableToRows(table) {
        var rows = [];
        eachRow(table, function (tr) {
            var cells = tr.children;
            var row = [];
            var isHeader = false;
            for (var h = 0; h < cells.length; h++) {
                if (cells[h].tagName === 'TH') {
                    isHeader = true;
                    break;
                }
            }
            for (var j = 0; j < cells.length; j++) {
                var c = cells[j];
                if (c.tagName !== 'TD' && c.tagName !== 'TH') { continue; }
                if (isSkipped(c)) { continue; }
                var span = parseInt(c.getAttribute('colspan') || '1', 10) || 1;
                var styleId = isHeader ? 1 : 2;
                if (isAmountCell(c)) {
                    var num = parseAmount(c.textContent);
                    if (num !== null && !isHeader) {
                        row.push({ v: num, n: true, s: 3 });
                    } else {
                        row.push(txtCell(cellText(c), isHeader ? 1 : 2));
                    }
                } else {
                    row.push(txtCell(cellText(c), styleId));
                }
                for (var k = 1; k < span; k++) { row.push(txtCell('', styleId)); }
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
        var colWidths = tableColWidths(table);
        var blob = buildXlsx(name, rows, colWidths);
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
        var colWidths = mergeColWidths(tables);
        var blob = buildXlsx(name, rows, colWidths);
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

    /*
     * طباعة التقارير: اسم المستند هو ما يُحفَظ به ملف PDF.
     * - السندات/القيود: window.orangeAdminVoucherPrintTitle (يُضبط عبر orangeAdminOpenPrintDialog) — له الأولوية.
     * - التقارير: نبني الاسم من اسم التقرير (data-export-name) + الفترة (data-export-subtitle)
     *   حتى يأخذ الحفظ «اسم التقرير + التاريخ» (قرار المالك). إن لم يوجد جدول تصدير نُبقي العنوان
     *   فارغاً لتقليل ترويسة/تذييل المتصفح. مع @page{margin:0} في admin.css.
     */
    function orangeBuildReportPrintTitle() {
        /* الاسم: عنوان التقرير الظاهر (H1) أولاً — هو ما يراه المالك (مثل «الحركة الشهرية لحساب»)؛
         * ثم data-export-name كبديل (اسم ملف Excel). */
        var h1 = document.querySelector('.page-title h1');
        var dataEl = document.querySelector('[data-export-name]');
        var name = h1 ? (h1.textContent || '').trim() : '';
        if (name === '' && dataEl) {
            name = (dataEl.getAttribute('data-export-name') || '').trim();
        }
        if (name === '') {
            return '';
        }
        /* التفصيل: اسم الحساب/الفترة من data-export-subtitle (أينما وُجد). */
        var subEl = document.querySelector('[data-export-subtitle]');
        var subtitle = subEl ? (subEl.getAttribute('data-export-subtitle') || '').trim() : '';
        var title = subtitle !== '' ? (name + ' - ' + subtitle) : name;
        /* محارف غير صالحة في أسماء الملفات → شرطة، ثم تنظيف الفراغات. */
        return title.replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, ' ').trim();
    }
    var savedDocTitle = null;
    window.addEventListener('beforeprint', function () {
        if (window.orangeAdminVoucherPrintTitle) {
            savedDocTitle = document.title;
            document.title = window.orangeAdminVoucherPrintTitle;
            return;
        }
        savedDocTitle = document.title;
        var reportTitle = orangeBuildReportPrintTitle();
        document.title = reportTitle !== '' ? reportTitle : ' ';
    });
    window.addEventListener('afterprint', function () {
        if (window.orangeAdminVoucherPrintTitle) {
            savedDocTitle = null;
            return;
        }
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
