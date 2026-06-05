<?php

declare(strict_types=1);

/**
 * smoke تفعيل الأسواق (EG/UAE/KSA) — تقرير جاهزية للقراءة فقط (لا يفعّل ولا يعدّل بيانات).
 * الاستخدام على السيرفر: php scripts/run_market_smoke.php
 *
 * التفعيل الفعلي يتم من شاشة «الدول» (المشرف العام) — هذا السكربت للتحقق فقط.
 * @see docs/archive/ORANGE_OWNER_MULTICOUNTRY_VISION.txt §10/§13
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo 'CLI only';

    exit(1);
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/catalog_schema.php';
require_once dirname(__DIR__) . '/includes/countries.php';
require_once dirname(__DIR__) . '/includes/currency.php';
require_once dirname(__DIR__) . '/includes/company_settings.php';
require_once dirname(__DIR__) . '/includes/gl_settings.php';
require_once dirname(__DIR__) . '/includes/multicountry_stock_gap.php';

try {
    $pdo = db();
    $rep = orange_multicountry_stock_phase2_gap_report($pdo);

    echo '=== Orange — Market activation smoke (read-only) ===' . PHP_EOL;
    echo 'markets_active=' . (int) ($rep['markets_active'] ?? 0)
        . ' / provision_ready=' . (int) ($rep['markets_provision_ready'] ?? 0)
        . ' / overall_ready=' . (!empty($rep['ready']) ? 'YES' : 'NO') . PHP_EOL;
    echo str_repeat('-', 60) . PHP_EOL;

    foreach (($rep['markets'] ?? []) as $code => $m) {
        if (!is_array($m)) {
            continue;
        }
        $cid = (int) ($m['country_id'] ?? 0);
        $currency = $cid > 0 ? orange_country_functional_currency_code($pdo, $cid) : '—';
        $vatRate = $cid > 0 ? orange_vat_rate_for_country($pdo, $cid) : 0.0;
        $vatAcc = $cid > 0 ? orange_gl_account_id_optional($pdo, 'vat_output', $cid) : null;

        echo strtoupper((string) $code) . ' (country_id=' . $cid . ')' . PHP_EOL;
        echo '  active=' . (!empty($m['is_active']) ? '1' : '0')
            . '  warehouse=' . (!empty($m['warehouse']) ? '1' : '0')
            . '  channels=' . (!empty($m['channels_ok']) ? '1' : '0')
            . '  products=' . (int) ($m['products_count'] ?? 0)
            . '  variants_missing_stock_rows=' . (int) ($m['variants_missing_wvs'] ?? -1) . PHP_EOL;
        echo '  currency=' . $currency
            . '  vat_rate=' . rtrim(rtrim(number_format($vatRate, 3, '.', ''), '0'), '.') . '%'
            . '  vat_output_account=' . ($vatAcc !== null && (int) $vatAcc > 0 ? (string) (int) $vatAcc : '(غير مربوط)') . PHP_EOL;
        echo '  provision_ready=' . (!empty($m['provision_ready']) ? 'YES' : 'NO') . PHP_EOL;
        echo str_repeat('-', 60) . PHP_EOL;
    }

    echo 'ملاحظة: التفعيل من شاشة «الدول» (المشرف العام). VAT اختياري: اربط vat_output + اضبط النسبة بإعدادات الشركة.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Smoke failed: ' . $e->getMessage() . PHP_EOL);

    exit(1);
}
