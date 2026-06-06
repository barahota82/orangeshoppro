<?php

declare(strict_types=1);

/**
 * تصدير تقارير إلى **Excel xlsx حقيقي** (Open XML) من الخادم — كامل الصفوف.
 * xlsx حقيقي يفتح في Excel **دون رسالة «تنسيق الملف لا يطابق الامتداد»**.
 * يعتمد على ZipArchive (متاح عادةً)؛ عند غيابه يرجع إلى HTML/xls كحل بديل.
 */

/** فهرس عمود (0-based) → حرف Excel (A, B, …, AA). */
function orange_report_xlsx_col_letter(int $n): string
{
    $s = '';
    $n += 1;
    while ($n > 0) {
        $m = ($n - 1) % 26;
        $s = chr(65 + $m) . $s;
        $n = intdiv($n - 1, 26);
    }

    return $s;
}

function orange_report_xlsx_xml_esc(string $s): string
{
    return str_replace(
        ['&', '<', '>', '"'],
        ['&amp;', '&lt;', '&gt;', '&quot;'],
        $s
    );
}

/**
 * بناء XML لورقة العمل.
 *
 * @param list<list<array{v:mixed,n?:bool}>> $rows
 */
function orange_report_xlsx_sheet_xml(array $rows): string
{
    $sb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView rightToLeft="1" tabSelected="1" workbookViewId="0"/></sheetViews>'
        . '<sheetData>';
    $r = 0;
    foreach ($rows as $row) {
        $r++;
        $sb .= '<row r="' . $r . '">';
        $c = 0;
        foreach ($row as $cell) {
            $ref = orange_report_xlsx_col_letter($c) . $r;
            $isNum = !empty($cell['n']);
            $v = $cell['v'] ?? '';
            if ($isNum && is_numeric($v)) {
                $sb .= '<c r="' . $ref . '" t="n"><v>' . (0 + $v) . '</v></c>';
            } else {
                $text = (string) $v;
                if ($text !== '') {
                    $sb .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . orange_report_xlsx_xml_esc($text) . '</t></is></c>';
                }
            }
            $c++;
        }
        $sb .= '</row>';
    }
    $sb .= '</sheetData></worksheet>';

    return $sb;
}

/**
 * إرسال ملف xlsx (يبني الحزمة عبر ZipArchive) وإنهاء التنفيذ.
 *
 * @param list<list<array{v:mixed,n?:bool}>> $rows
 */
function orange_report_xlsx_send(string $filename, string $sheetName, array $rows): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $safe = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]+/u', ' ', $filename));
    if ($safe === '') {
        $safe = 'report';
    }
    $safe .= '-' . date('Y-m-d');

    $sheet = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]+/u', ' ', $sheetName));
    $sheet = function_exists('mb_substr') ? mb_substr($sheet, 0, 31) : substr($sheet, 0, 31);
    if ($sheet === '') {
        $sheet = 'Sheet1';
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . orange_report_xlsx_xml_esc($sheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';
    $sheetXml = orange_report_xlsx_sheet_xml($rows);

    /* بناء حزمة ZIP بـ PHP خالص (stored، بلا اعتماد على ZipArchive). */
    $data = orange_report_zip_stored([
        ['name' => '[Content_Types].xml', 'data' => $contentTypes],
        ['name' => '_rels/.rels', 'data' => $rels],
        ['name' => 'xl/workbook.xml', 'data' => $workbook],
        ['name' => 'xl/_rels/workbook.xml.rels', 'data' => $workbookRels],
        ['name' => 'xl/worksheets/sheet1.xml', 'data' => $sheetXml],
    ]);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safe . '.xlsx"');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

/**
 * بناء أرشيف ZIP (stored، بلا ضغط) بـ PHP خالص — يطابق مواصفات ZIP.
 *
 * @param list<array{name:string,data:string}> $files
 */
function orange_report_zip_stored(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $f) {
        $name = (string) $f['name'];
        $bytes = (string) $f['data'];
        $crc = crc32($bytes);
        $len = strlen($bytes);
        $nameLen = strlen($name);
        $lf = "PK\x03\x04" . pack('v', 20) . pack('v', 0x0800) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nameLen) . pack('v', 0)
            . $name . $bytes;
        $local .= $lf;
        $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0x0800) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', $nameLen) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', 0) . pack('V', $offset)
            . $name;
        $offset += strlen($lf);
        $count++;
    }
    $cdSize = strlen($central);
    $eocd = "PK\x05\x06" . pack('v', 0) . pack('v', 0) . pack('v', $count) . pack('v', $count)
        . pack('V', $cdSize) . pack('V', $offset) . pack('v', 0);

    return $local . $central . $eocd;
}

/**
 * توافقي: نفس التوقيع القديم (headers + صفوف مسطّحة + أعمدة رقمية) → يبني xlsx حقيقي.
 *
 * @param list<string>            $headers
 * @param list<array<int,scalar>> $rows
 * @param list<int>               $numericCols
 */
function orange_report_xls_output(
    string $filename,
    string $title,
    string $company,
    string $subtitle,
    array $headers,
    array $rows,
    array $numericCols = []
): void {
    $numSet = array_flip(array_map('intval', $numericCols));
    $cellRows = [];
    if (trim($company) !== '') {
        $cellRows[] = [['v' => $company, 'n' => false]];
    }
    $cellRows[] = [['v' => $title, 'n' => false]];
    if (trim($subtitle) !== '') {
        $cellRows[] = [['v' => $subtitle, 'n' => false]];
    }
    $cellRows[] = [['v' => 'تاريخ الطباعة: ' . date('d/m/Y H:i'), 'n' => false]];
    $cellRows[] = [];

    $headRow = [];
    foreach ($headers as $h) {
        $headRow[] = ['v' => $h, 'n' => false];
    }
    $cellRows[] = $headRow;

    foreach ($rows as $r) {
        $cells = [];
        $i = 0;
        foreach ($r as $val) {
            $isNum = isset($numSet[$i]) && is_numeric($val);
            $cells[] = ['v' => $isNum ? (0 + $val) : (string) $val, 'n' => $isNum];
            $i++;
        }
        $cellRows[] = $cells;
    }

    orange_report_xlsx_send($filename, $title, $cellRows);
}
