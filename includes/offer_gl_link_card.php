<?php

declare(strict_types=1);

require_once __DIR__ . '/invoice_ancillary_lines.php';
require_once __DIR__ . '/gl_settings.php';

/**
 * اسم وكود حساب الدليل بالمعرّف (للعرض فقط).
 *
 * @return array{code:string,name:string}|null
 */
function orange_offer_gl_link_account_label(PDO $pdo, int $accountId): ?array
{
    if ($accountId <= 0 || !orange_table_exists($pdo, 'accounts')) {
        return null;
    }
    $st = $pdo->prepare('SELECT code, name FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$accountId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'code' => (string) ($row['code'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

/**
 * كارت «الربط المحاسبي» للقراءة فقط لشاشة عرض.
 * يعرض لكل مفتاح نظامي (بند فاتورة إضافي) الحساب المربوط أو تنبيهاً عند عدم الربط،
 * ولكل مفتاح قيد تلقائي (gl_account_settings) الحساب المربوط أو تنبيهاً.
 *
 * @param list<string> $systemKeys مفاتيح بنود الفاتورة الإضافية (orange_invoice_ancillary_system_key_catalog)
 * @param list<string> $glKeys     مفاتيح القيود التلقائية (orange_gl_setting_key_labels)
 */
function orange_offer_gl_link_card_html(
    PDO $pdo,
    array $systemKeys = [],
    array $glKeys = [],
    string $title = 'الربط المحاسبي (للقراءة فقط)'
): string {
    $cid = orange_gl_settings_effective_country_id($pdo);
    $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $presetsHref = storefront_public_path('/admin/index.php?page=invoice_line_presets');
    $glHref = storefront_public_path('/admin/index.php?page=gl_account_settings');

    $presetMap = $systemKeys !== []
        ? orange_invoice_ancillary_system_key_presets_map($pdo, $cid, true)
        : [];
    $sysCatalog = orange_invoice_ancillary_system_key_catalog();
    $glLabels = orange_gl_setting_key_labels();

    $rows = [];

    foreach ($systemKeys as $key) {
        $k = orange_invoice_ancillary_system_key_normalize($key);
        if ($k === null) {
            continue;
        }
        $label = (string) ($sysCatalog[$k]['label_ar'] ?? $k);
        $preset = $presetMap[$k] ?? null;
        $accId = is_array($preset) ? (int) ($preset['account_id'] ?? 0) : 0;
        $invLabel = '';
        if (is_array($preset)) {
            $invLabel = trim((string) ($preset['label_ar'] ?? ''));
            if ($invLabel === '') {
                $invLabel = trim((string) ($preset['label_en'] ?? ''));
            }
        }
        $invCell = $invLabel !== ''
            ? $esc($invLabel)
            : '<span style="color:#9ca3af;">—</span>';
        if ($accId > 0) {
            $acc = orange_offer_gl_link_account_label($pdo, $accId);
            $accText = $acc !== null
                ? trim($acc['code'] . ' — ' . $acc['name'])
                : ('#' . $accId);
            $rows[] = '<tr><td>' . $esc($label) . '</td>'
                . '<td>' . $invCell . '</td>'
                . '<td><strong style="color:#1b5e20;">' . $esc($accText) . '</strong></td>'
                . '<td>بند فاتورة إضافي</td></tr>';
        } else {
            $rows[] = '<tr style="background:#fdecea;"><td>' . $esc($label) . '</td>'
                . '<td>' . $invCell . '</td>'
                . '<td><strong style="color:#b71c1c;">غير مربوط</strong> — '
                . '<a href="' . $esc($presetsHref) . '">اربطه من «بنود الفاتورة الإضافية»</a></td>'
                . '<td>بند فاتورة إضافي</td></tr>';
        }
    }

    foreach ($glKeys as $key) {
        $key = trim($key);
        if ($key === '' || !isset($glLabels[$key])) {
            continue;
        }
        $label = (string) $glLabels[$key];
        $accId = orange_gl_setting_bound_account_id_raw($pdo, $key, $cid);
        if ($accId > 0) {
            $acc = orange_offer_gl_link_account_label($pdo, $accId);
            $accText = $acc !== null
                ? trim($acc['code'] . ' — ' . $acc['name'])
                : ('#' . $accId);
            $rows[] = '<tr><td>' . $esc($label) . '</td>'
                . '<td><span style="color:#9ca3af;">—</span></td>'
                . '<td><strong style="color:#1b5e20;">' . $esc($accText) . '</strong></td>'
                . '<td>قيد تلقائي</td></tr>';
        } else {
            $rows[] = '<tr style="background:#fdecea;"><td>' . $esc($label) . '</td>'
                . '<td><span style="color:#9ca3af;">—</span></td>'
                . '<td><strong style="color:#b71c1c;">غير مربوط</strong> — '
                . '<a href="' . $esc($glHref) . '">اربطه من «حسابات القيود التلقائية»</a></td>'
                . '<td>قيد تلقائي</td></tr>';
        }
    }

    if ($rows === []) {
        return '';
    }

    $html = '<div class="card">'
        . '<h3>' . $esc($title) . '</h3>'
        . '<p class="card-hint" style="margin:0 0 0.6rem;">قيمة العرض تظهر كبند على الفاتورة وتُرحَّل على الحساب المربوط أدناه. '
        . '«المسمى على الفاتورة» هو نص بند الفاتورة الإضافي المربوط (يُحرَّر من «بنود الفاتورة الإضافية»). '
        . 'لا يوجد أي حساب مثبّت في الكود — الربط من شاشات الحسابات. عند عدم الربط لا يُطبَّق القيد ويظهر تنبيه أحمر.</p>'
        . '<div class="table-wrap"><table><thead><tr>'
        . '<th>البند</th><th>المسمى على الفاتورة</th><th>الحساب المربوط</th><th>النوع</th>'
        . '</tr></thead><tbody>'
        . implode('', $rows)
        . '</tbody></table></div></div>';

    return $html;
}
