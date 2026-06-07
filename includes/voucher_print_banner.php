<?php

declare(strict_types=1);

require_once __DIR__ . '/company_settings.php';
require_once __DIR__ . '/sales_doc_print.php';

/**
 * ترويسة طباعة السند — نمط التقارير (§9.3 V1).
 *
 * @param array{title_ar:string,title_en?:string,title_span_id?:string} $ctx
 */
function orange_voucher_print_banner(PDO $pdo, int $countryId, array $ctx): void
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
<header class="jv-voucher-print-banner gl-acc-stmt-print-banner" aria-hidden="true">
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
