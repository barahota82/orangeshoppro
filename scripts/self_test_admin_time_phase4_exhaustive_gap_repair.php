<?php

declare(strict_types=1);

/**
 * Phase 4 — Final Exhaustive Gap Repair closure (G-EX-01 … G-EX-08).
 * Static/behavioral checks only. No DB writes. No Backup/Restore mutation.
 *
 * Usage: php scripts/self_test_admin_time_phase4_exhaustive_gap_repair.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function p4ex_assert(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

function p4ex_strip_line_comments(string $src): string
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $src) ?: [] as $line) {
        if (str_starts_with(ltrim($line), '//')) {
            continue;
        }
        $out[] = $line;
    }

    return implode("\n", $out);
}

require_once $root . '/includes/admin_time.php';
require_once $root . '/includes/loyalty.php';

// --- G-EX-01 / G-EX-08 writers ---
$siUpdate = (string) file_get_contents($root . '/admin/api/sales-invoices/update.php');
$siOnline = (string) file_get_contents($root . '/includes/sales_invoice_online.php');
foreach (['sales-invoices/update' => $siUpdate, 'sales_invoice_online' => $siOnline] as $label => $src) {
    $code = p4ex_strip_line_comments($src);
    p4ex_assert(str_contains($src, 'orange_admin_time_utc_now_mysql'), "G-EX-01. {$label} uses utc helper");
    p4ex_assert(preg_match('/updated_at\s*=\s*\?/i', $code) === 1, "G-EX-01. {$label} binds updated_at = ?");
    p4ex_assert(!preg_match('/updated_at\s*=\s*NOW\s*\(/i', $code), "G-EX-01. {$label} no NOW()");
}

$savedTz = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');
$utcA = orange_admin_time_utc_now_mysql();
date_default_timezone_set('Asia/Kuwait');
$utcB = orange_admin_time_utc_now_mysql();
date_default_timezone_set($savedTz);
p4ex_assert(
    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utcA) === 1
    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $utcB) === 1
    && abs(strtotime($utcA . ' UTC') - strtotime($utcB . ' UTC')) <= 2,
    'G-EX-01. utc_now_mysql independent of PHP default TZ'
);

$fixtureUnsafe = "\$sets[] = 'updated_at = NOW()';\n";
$fixtureComment = "// updated_at = NOW()\n\$x=1;\n";
$fixtureMulti = "\$sets[] = \"updated_at = NOW()\";\n";
p4ex_assert((bool) preg_match('/updated_at\s*=\s*NOW\s*\(/i', p4ex_strip_line_comments($fixtureUnsafe)), 'G-EX-08. unsafe NOW detected');
p4ex_assert(!preg_match('/updated_at\s*=\s*NOW\s*\(/i', p4ex_strip_line_comments($fixtureComment)), 'G-EX-08. comment ignored');
p4ex_assert((bool) preg_match('/updated_at\s*=\s*NOW\s*\(/i', p4ex_strip_line_comments($fixtureMulti)), 'G-EX-08. multiline detected');

// --- G-EX-02 customer print-card ---
$custCard = (string) file_get_contents($root . '/admin/api/customers/print-card.php');
p4ex_assert(str_contains($custCard, 'orange_admin_time_display_mysql_utc_for_record'), 'G-EX-02. Record-Country Absolute display');
p4ex_assert(!str_contains(p4ex_strip_line_comments($custCard), 'orange_format_datetime_dmY_hi((string) $ordersLastAt)'), 'G-EX-02. no dmY_hi on last order');
p4ex_assert(!str_contains(p4ex_strip_line_comments($custCard), 'orange_format_datetime_dmY_hi(date('), 'G-EX-02. no dmY_hi(date()) print stamp');

// Winter instant: Kuwait UTC+3 vs Cairo UTC+2 → different local walls for same Absolute.
$utcIso = '2026-01-15T21:30:00+00:00';
$kw = orange_admin_time_format_instant_in_iana($utcIso, 'Asia/Kuwait', 'ar', 'datetime');
$eg = orange_admin_time_format_instant_in_iana($utcIso, 'Africa/Cairo', 'ar', 'datetime');
p4ex_assert($kw !== $eg, 'G-EX-02. KW/EG displays differ for same UTC instant');
p4ex_assert((bool) preg_match('/\b(AM|PM)\b/i', $kw) && (bool) preg_match('/\b(AM|PM)\b/i', $eg), 'G-EX-02. AM/PM present');
p4ex_assert(
    orange_admin_time_local_ymd_in_iana($utcIso, 'Asia/Kuwait') === '2026-01-16'
    && orange_admin_time_local_ymd_in_iana($utcIso, 'Africa/Cairo') === '2026-01-15',
    'G-EX-02. near-midnight UTC maps to correct local calendar days'
);

// --- G-EX-03 print generated-at ---
$supCard = (string) file_get_contents($root . '/admin/api/suppliers/print-card.php');
$vpb = (string) file_get_contents($root . '/includes/voucher_print_banner.php');
p4ex_assert(str_contains($supCard, 'orange_admin_time_format_instant_for_country_id') || str_contains($supCard, 'now_display_for_admin_context'), 'G-EX-03. supplier Country IANA stamp');
p4ex_assert(str_contains($vpb, 'orange_admin_time_now_display_for_admin_context'), 'G-EX-03. voucher metafoot Country IANA');
p4ex_assert(!preg_match('/orange_format_datetime_dmY_hi\s*\(\s*date\s*\(/', p4ex_strip_line_comments($supCard)), 'G-EX-03. supplier no dmY_hi(date)');
p4ex_assert(!preg_match('/orange_format_datetime_dmY_hi\s*\(\s*date\s*\(/', p4ex_strip_line_comments($vpb)), 'G-EX-03. voucher no dmY_hi(date)');
p4ex_assert(str_contains($vpb, 'orange_voucher_print_metafoot(?PDO'), 'G-EX-03. metafoot accepts PDO');

// --- G-EX-04 JS print ---
$js = (string) file_get_contents($root . '/admin/assets/admin_sales_doc_ui.js');
p4ex_assert(str_contains($js, 'resolvePrintNowDisplay'), 'G-EX-04. server display resolver');
p4ex_assert(!preg_match('/formatPrintDate\s*\(\s*new\s+Date\s*\(/', $js), 'G-EX-04. no formatPrintDate(new Date())');
p4ex_assert(!preg_match('/dateEl\.textContent\s*=\s*formatPrintDate\s*\(\s*new\s+Date/', $js), 'G-EX-04. dateEl not from Browser Date');
foreach (['company_sales_invoice.php', 'online_sales_invoice.php', 'sales_returns.php'] as $page) {
    $body = (string) file_get_contents($root . '/admin/pages/' . $page);
    p4ex_assert(str_contains($body, 'printNowDisplay'), "G-EX-04. {$page} injects printNowDisplay");
}

// --- G-EX-05 promo defaults ---
$promoJs = (string) file_get_contents($root . '/admin/partials/cart_promo_schedule_js.inc.php');
p4ex_assert(str_contains($promoJs, 'OCP_COUNTRY_TODAY_YMD'), 'G-EX-05. Country today injected');
p4ex_assert(str_contains($promoJs, 'document_date_today_for_admin_context'), 'G-EX-05. server Country date helper');
p4ex_assert(!preg_match('/ocpDefaultScheduleDates[\s\S]*new\s+Date\s*\(/', $promoJs), 'G-EX-05. defaults not from new Date()');

// --- G-EX-06 loyalty ---
$loy = (string) file_get_contents($root . '/includes/loyalty.php');
$loyCode = p4ex_strip_line_comments($loy);
p4ex_assert(str_contains($loy, 'orange_loyalty_expires_at_utc_mysql'), 'G-EX-06. UTC duration helper');
p4ex_assert(!preg_match('/expires_at\s*>\s*NOW\s*\(/i', $loyCode), 'G-EX-06. no expires_at > NOW()');
p4ex_assert(!preg_match('/expires_at\s*<=\s*NOW\s*\(/i', $loyCode), 'G-EX-06. no expires_at <= NOW()');
p4ex_assert(!preg_match('/strtotime\s*\(\s*[\'"]\+/', $loyCode), 'G-EX-06. no strtotime duration');
date_default_timezone_set('America/Los_Angeles');
$expLa = orange_loyalty_expires_at_utc_mysql(1);
date_default_timezone_set('Asia/Kuwait');
$expKw = orange_loyalty_expires_at_utc_mysql(1);
date_default_timezone_set($savedTz);
p4ex_assert(
    is_string($expLa) && is_string($expKw)
    && abs(strtotime($expLa . ' UTC') - strtotime($expKw . ' UTC')) <= 2,
    'G-EX-06. expiry duration independent of PHP TZ'
);
p4ex_assert(orange_loyalty_expires_at_utc_mysql(0) === null, 'G-EX-06. zero months => null');

// --- G-EX-07 preview TTL ---
$preview = (string) file_get_contents($root . '/includes/product_preview.php');
$draft = (string) file_get_contents($root . '/admin/api/products/save-preview-draft.php');
$prevCode = p4ex_strip_line_comments($preview);
$draftCode = p4ex_strip_line_comments($draft);
p4ex_assert(!preg_match('/preview_expires_at[^\n]*NOW\s*\(/i', $prevCode), 'G-EX-07. preview compare no NOW()');
p4ex_assert(str_contains($draft, 'orange_admin_time_utc_now_mysql'), 'G-EX-07. draft expiry from UTC helper');
p4ex_assert(!preg_match('/\$expiresAt\s*=\s*date\s*\(/', $draftCode), 'G-EX-07. no date() expiry writer');
p4ex_assert(str_contains($draft, '+86400'), 'G-EX-07. 24h duration preserved');

// --- Freeze ---
$schema = (string) file_get_contents($root . '/includes/catalog_schema.php');
p4ex_assert(preg_match("/ORANGE_CATALOG_SCHEMA_PHP_REVISION',\s*124\)/", $schema) === 1, 'Schema remains 124');
$reg = (string) file_get_contents($root . '/config/backup_table_registry.json');
p4ex_assert(str_contains($reg, '"schema_revision": 124'), 'Registry schema_revision 124');

echo "\n--- Phase 4 exhaustive gap repair ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
