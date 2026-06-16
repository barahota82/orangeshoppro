<?php

declare(strict_types=1);

/**
 * ترقية لمرة واحدة: إضافة سطر «سياق الدولة» لشاشات الأدمن المتبقية.
 * تشغيل: php scripts/patch_admin_page_country_titles.php
 */

$root = dirname(__DIR__);
$pagesDir = $root . '/admin/pages';

$skip = [
    'journal_entries.php',
    'receipt_voucher.php',
    'payment_voucher.php',
    'other_vouchers.php',
    'year_end_close_vouchers.php',
    'report_trading_account.php',
    'delivery_order_search_single.inc.php',
    'accounting_reports_index.php',
    'settings_index.php',
    'warehouse_purchases_index.php',
    'sales_promotions_index.php',
];

$countryLine = "    <p class=\"card-hint\" style=\"margin:0.35rem 0 0;\"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label(\$pdo), ENT_QUOTES, 'UTF-8'); ?></p>\n";
$bootstrapRequire = "require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';";

$updated = 0;
$skipped = 0;

foreach (glob($pagesDir . '/*.php') as $path) {
    $base = basename($path);
    if (in_array($base, $skip, true)) {
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false || strpos($content, 'سياق الدولة') !== false) {
        ++$skipped;
        continue;
    }

    $orig = $content;

    if (strpos($content, 'admin_page_bootstrap.php') === false) {
        if (preg_match("/require_once __DIR__ \. '\/\\.\\.\\/\\.\\.\\/includes\\/catalog_schema\\.php';/", $content)) {
            $content = preg_replace(
                "/(require_once __DIR__ \. '\/\\.\\.\\/\\.\\.\\/includes\\/catalog_schema\\.php';\\r?\\n)/",
                "$1{$bootstrapRequire}\n",
                $content,
                1
            );
        } elseif (preg_match("/require_once __DIR__ \. '\/\\.\\.\\/\\.\\.\\/includes\\/admin_page_bootstrap\\.php';/", $content) === 0
            && preg_match("/\\\$pdo = (db\\(\\)|orange_admin_page_pdo\\(\\));/", $content)) {
            $content = preg_replace(
                "/(\\\$pdo = (?:db\\(\\)|orange_admin_page_pdo\\(\\));\\r?\\n)/",
                "$1",
                $content,
                1
            );
            if (strpos($content, 'admin_page_bootstrap.php') === false) {
                $content = preg_replace(
                    "/(<\\?php\\r?\\n\\r?\\ndeclare\\(strict_types=1\\);\\r?\\n\\r?\\n)/",
                    "$1{$bootstrapRequire}\n",
                    $content,
                    1
                );
            }
        }
    }

    // page-title with h1 only (no stacked)
    $content = preg_replace(
        '/(<div class="page-title(?:[^"]*)">\\r?\\n\\s*<h1([^>]*)>([^<]+)<\\/h1>\\r?\\n)(<\\/div>)/',
        '$1' . $countryLine . '$4',
        $content
    );

    // page-title--stacked with inner div
    $content = preg_replace(
        '/<div class="page-title page-title--stacked([^"]*)">\\r?\\n\\s*<div>\\r?\\n\\s*<h1([^>]*)>([^<]+)<\\/h1>\\r?\\n(?:\\s*<p class="[^"]*"[^>]*>.*?<\\/p>\\r?\\n)?\\s*<\\/div>\\r?\\n<\\/div>/s',
        '<div class="page-title$1">' . "\n" . '    <h1$2>$3</h1>' . "\n" . $countryLine . '</div>',
        $content
    );

    // page-title--stacked flat (h1 + subtitle siblings)
    $content = preg_replace_callback(
        '/<div class="page-title page-title--stacked([^"]*)">\\r?\\n\\s*<h1([^>]*)>([^<]+)<\\/h1>\\r?\\n\\s*(<p class="page-subtitle"[^>]*>.*?<\\/p>)\\r?\\n<\\/div>/s',
        static function (array $m) use ($countryLine): string {
            return '<div class="page-title' . $m[1] . '">' . "\n"
                . '    <h1' . $m[2] . '>' . $m[3] . '</h1>' . "\n"
                . $countryLine
                . '</div>' . "\n"
                . $m[4];
        },
        $content
    );

    // admin-fy-shell__title standalone
    $content = preg_replace(
        '/<h1 class="admin-fy-shell__title([^"]*)">([^<]+)<\\/h1>/',
        '<div class="page-title">' . "\n"
            . '    <h1$1>$2</h1>' . "\n"
            . $countryLine
            . '</div>',
        $content
    );

    if ($content !== $orig) {
        file_put_contents($path, $content);
        echo "updated: {$base}\n";
        ++$updated;
    } else {
        echo "no-match: {$base}\n";
    }
}

echo "done updated={$updated} skipped_has_country={$skipped}\n";
