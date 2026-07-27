<?php

declare(strict_types=1);

require_once __DIR__ . '/company_settings.php';
require_once __DIR__ . '/sales_doc_print.php';
require_once __DIR__ . '/date_format.php';
require_once __DIR__ . '/accounting_report_money.php';
require_once __DIR__ . '/admin_time.php';

/**
 * محتوى ترويسة طباعة السند (داخل header) — §9.3 V1.
 *
 * @param array{title_ar:string,title_en?:string,title_span_id?:string} $ctx
 */
function orange_voucher_print_banner_markup(PDO $pdo, int $countryId, array $ctx): void
{
    $titleAr = trim((string) ($ctx['title_ar'] ?? 'سند'));
    $titleSpanId = trim((string) ($ctx['title_span_id'] ?? ''));
    if ($titleAr === '') {
        $titleAr = 'سند';
    }
    $titleEn = trim((string) ($ctx['title_en'] ?? ''));

    $company = orange_sales_doc_print_company($pdo, $countryId);
    $logoUrl = trim((string) ($company['logo_url'] ?? ''));
    $nameAr = trim((string) ($company['company_name_ar'] ?? ''));
    if ($nameAr === '') {
        $nameAr = orange_company_settings_name_ar($pdo);
    }
    $cr = trim((string) ($company['commercial_register'] ?? ''));
    $address = trim((string) ($company['address'] ?? ''));
    $phones = trim((string) ($company['phones'] ?? ''));
    ?>
<header class="gl-acc-stmt-print-banner">
    <div class="pl-month-brand-row">
        <div class="pl-month-brand">
            <?php if ($logoUrl !== ''): ?>
                <img class="pl-month-print-logo" src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php endif; ?>
            <div class="pl-month-brand-text">
                <?php if ($nameAr !== ''): ?>
                    <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($nameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if ($cr !== ''): ?>
                    <p class="pl-month-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars($cr, ENT_QUOTES, 'UTF-8'); ?></span></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="pl-month-contact">
            <?php if ($address !== ''): ?>
                <p class="pl-month-contact-line"><?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if ($phones !== ''): ?>
                <p class="pl-month-contact-line"><span dir="ltr"><?php echo htmlspecialchars($phones, ENT_QUOTES, 'UTF-8'); ?></span></p>
            <?php endif; ?>
        </div>
    </div>
    <h2 class="gl-acc-stmt-print-title ta-report-print-title">
        <span class="gl-acc-stmt-print-title-ar" lang="ar"<?php echo $titleSpanId !== '' ? ' id="' . htmlspecialchars($titleSpanId, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($titleAr, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php if ($titleEn !== ''): ?>
            <span class="gl-acc-stmt-print-title-en" lang="en" dir="ltr"><?php echo htmlspecialchars($titleEn, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
    </h2>
</header>
    <?php
}

/**
 * thead ترويسة — تتكرر أعلى كل صفحة طباعة (نمط ta-report-print-table).
 *
 * @param array{title_ar:string,title_en?:string,title_span_id?:string} $ctx
 */
function orange_voucher_print_banner_thead(PDO $pdo, int $countryId, array $ctx): void
{
    ?>
<thead class="ta-report-print-thead jv-voucher-print-thead">
    <tr class="ta-report-banner-row">
        <td class="ta-report-banner-cell">
            <?php orange_voucher_print_banner_markup($pdo, $countryId, $ctx); ?>
        </td>
    </tr>
</thead>
    <?php
}

/**
 * @deprecated استخدم orange_voucher_print_banner_thead داخل jv-voucher-print-sheet
 * @param array{title_ar:string,title_en?:string,title_span_id?:string} $ctx
 */
function orange_voucher_print_banner(PDO $pdo, int $countryId, array $ctx): void
{
    orange_voucher_print_banner_markup($pdo, $countryId, $ctx);
}

/**
 * تذييل طباعة السند — تاريخ/وقت يمين + أرقام صفحات يسار (نمط التقارير §9.3 V5).
 * طابع الطباعة = Absolute now معروض بـ IANA دولة سياق الإدمن (12h AM/PM).
 */
function orange_voucher_print_metafoot(?PDO $pdo = null): void
{
    $printDatetime = '—';
    if ($pdo instanceof PDO) {
        try {
            $printDatetime = orange_admin_time_now_display_for_admin_context($pdo, 'ar', 'datetime');
        } catch (OrangeAdminTimeConfigException $e) {
            $printDatetime = '—';
        }
    }
    /*
     * §9.3 — هامش سفلي أكبر للسندات فقط (لا التقارير): @page في admin.css = 12mm عاماً؛
     * شاشات السندات/الذمم/OB تستدعي هذه الدالة فقط — تجربة تقليل انفصال سطر الحساب عن البيان.
     */
    echo '<style>@media print{@page{margin:0 0 18mm 0;}}</style>';
    echo orange_accounting_report_print_metafoot_markup($printDatetime);
}
