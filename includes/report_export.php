<?php

declare(strict_types=1);

/**
 * تصدير تقرير إلى Excel (xls / SpreadsheetML) من الخادم — **كامل الصفوف** لا المعروض فقط.
 * يُستخدم للتقارير التي تقصّ صفوفها في العرض (مثل تفاصيل المردودات المحدودة).
 *
 * يطابق شكل التصدير من المتصفح: رأس (شركة/عنوان/فترة/تاريخ) + جدول + RTL + العربية،
 * مع تحويل الأعمدة الرقمية إلى أرقام قابلة للجمع. بصفر اعتماديات.
 *
 * @param list<string>            $headers     عناوين الأعمدة
 * @param list<array<int,scalar>> $rows        الصفوف (نفس ترتيب الأعمدة)
 * @param list<int>               $numericCols فهارس الأعمدة (0-based) التي تُعامَل كأرقام
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
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $safe = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]+/u', ' ', $filename));
    if ($safe === '') {
        $safe = 'report';
    }
    $safe .= '-' . date('Y-m-d');
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $safe . '.xls"');
    echo "\xEF\xBB\xBF";

    $esc = static function ($s): string {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    $sheet = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]+/u', ' ', $title));
    $sheet = function_exists('mb_substr') ? mb_substr($sheet, 0, 31) : substr($sheet, 0, 31);

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" '
        . 'xmlns:x="urn:schemas-microsoft-com:office:excel" '
        . 'xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8">';
    echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>'
        . '<x:Name>' . $esc($sheet) . '</x:Name><x:WorksheetOptions><x:DisplayRightToLeft/>'
        . '</x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    echo '<style>table{border-collapse:collapse;}'
        . 'td,th{border:0.5pt solid #999999;padding:3px 7px;mso-number-format:"\\@";white-space:nowrap;}'
        . 'th{background:#e8eef5;font-weight:bold;text-align:center;}</style></head><body dir="rtl">';

    if (trim($company) !== '') {
        echo '<div style="font-size:14pt;font-weight:bold;">' . $esc($company) . '</div>';
    }
    echo '<div style="font-size:13pt;font-weight:bold;">' . $esc($title) . '</div>';
    if (trim($subtitle) !== '') {
        echo '<div style="font-size:11pt;">' . $esc($subtitle) . '</div>';
    }
    echo '<div style="font-size:9pt;color:#666666;">تاريخ الطباعة: ' . $esc(date('d/m/Y H:i')) . '</div><br>';

    echo '<table><thead><tr>';
    foreach ($headers as $h) {
        echo '<th>' . $esc($h) . '</th>';
    }
    echo '</tr></thead><tbody>';

    $numSet = array_flip(array_map('intval', $numericCols));
    foreach ($rows as $r) {
        echo '<tr>';
        $i = 0;
        foreach ($r as $cell) {
            if (isset($numSet[$i]) && is_numeric($cell)) {
                echo '<td style="mso-number-format:\'#,##0.###\';">' . $esc((string) (float) $cell) . '</td>';
            } else {
                echo '<td>' . $esc($cell) . '</td>';
            }
            $i++;
        }
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}
