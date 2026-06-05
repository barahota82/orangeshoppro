<?php

declare(strict_types=1);

require_once __DIR__ . '/company_settings.php';
require_once __DIR__ . '/upload_paths.php';

/**
 * @return array{company_name_ar:string,company_name_en:string,logo_url:string,commercial_register:string,phones:string,address:string,vat_number:string,invoice_footer:string}
 */
function orange_sales_doc_print_company(PDO $pdo, int $countryId): array
{
    $defaults = [
        'company_name_ar' => '',
        'company_name_en' => '',
        'logo_url' => '',
        'commercial_register' => '',
        'phones' => '',
        'address' => '',
        'vat_number' => '',
        'invoice_footer' => '',
    ];
    $row = orange_company_settings_row($pdo, $countryId > 0 ? $countryId : null, false);
    if (!is_array($row)) {
        return $defaults;
    }

    $logoRaw = trim((string) ($row['company_logo'] ?? ''));
    $logoUrl = '';
    if ($logoRaw !== '') {
        if (preg_match('#^https?://#i', $logoRaw) === 1) {
            $logoUrl = $logoRaw;
        } else {
            $path = (isset($logoRaw[0]) && $logoRaw[0] === '/')
                ? $logoRaw
                : '/uploads/company/' . rawurlencode(basename($logoRaw));
            $logoUrl = storefront_public_path($path);
        }
    }

    return [
        'company_name_ar' => trim((string) ($row['company_name_ar'] ?? '')),
        'company_name_en' => trim((string) ($row['company_name_en'] ?? '')),
        'logo_url' => $logoUrl,
        'commercial_register' => trim((string) ($row['commercial_register'] ?? '')),
        'phones' => trim((string) ($row['phones'] ?? '')),
        'address' => trim((string) ($row['address'] ?? '')),
        'vat_number' => trim((string) ($row['vat_number'] ?? '')),
        'invoice_footer' => trim((string) ($row['invoice_footer'] ?? '')),
    ];
}

/**
 * GAP-SALE-DOC-01 مرحلة 4 — ترويسة طباعة موحّدة (INV-C / INV-O / مردود).
 *
 * @param array{prefix:string,doc_title:string,doc_badge:string,country_id:int,currency_code?:string} $ctx
 */
function orange_sales_doc_print_banner(array $ctx): void
{
    $prefix = preg_replace('/[^a-z0-9_]/i', '', (string) ($ctx['prefix'] ?? 'sd')) ?: 'sd';
    $docTitle = trim((string) ($ctx['doc_title'] ?? 'مستند مبيعات'));
    $docBadge = trim((string) ($ctx['doc_badge'] ?? ''));
    $countryId = (int) ($ctx['country_id'] ?? 0);
    $currencyCode = trim((string) ($ctx['currency_code'] ?? ''));

    $company = orange_sales_doc_print_company(db(), $countryId);

    $nameAr = $company['company_name_ar'];
    $nameEn = $company['company_name_en'];
    $logoUrl = $company['logo_url'];
    ?>
<div class="sd-print-banner" aria-hidden="true">
    <div class="sd-print-banner__head">
        <div class="sd-print-banner__brand">
            <?php if ($logoUrl !== ''): ?>
            <img class="sd-print-banner__logo" src="<?php echo htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <?php endif; ?>
            <div class="sd-print-banner__titles">
                <?php if ($nameAr !== ''): ?>
                <p class="sd-print-banner__name-ar"><?php echo htmlspecialchars($nameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if ($nameEn !== ''): ?>
                <p class="sd-print-banner__name-en" dir="ltr" lang="en"><?php echo htmlspecialchars($nameEn, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="sd-print-banner__meta">
            <p class="sd-print-banner__doc-title"><?php echo htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($docBadge !== ''): ?>
            <span class="sd-print-banner__badge" dir="ltr" lang="en"><?php echo htmlspecialchars($docBadge, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <p class="sd-print-banner__serial-row">
                <span class="sd-print-banner__label">المسلسل:</span>
                <strong id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_sd_print_serial" class="sd-print-banner__serial" dir="ltr" lang="en">—</strong>
            </p>
            <p class="sd-print-banner__date-row">
                <span class="sd-print-banner__label">تاريخ الطباعة:</span>
                <span id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_sd_print_date" class="sd-print-banner__date" dir="ltr" lang="en">—</span>
            </p>
            <?php if ($currencyCode !== ''): ?>
            <p class="sd-print-banner__currency-row">
                <span class="sd-print-banner__label">العملة:</span>
                <span dir="ltr" lang="en"><?php echo htmlspecialchars($currencyCode, ENT_QUOTES, 'UTF-8'); ?></span>
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $metaBits = [];
    if ($company['commercial_register'] !== '') {
        $metaBits[] = 'س.ت: ' . $company['commercial_register'];
    }
    if ($company['vat_number'] !== '') {
        $metaBits[] = 'ض.ق.م: ' . $company['vat_number'];
    }
    if ($company['phones'] !== '') {
        $metaBits[] = 'هاتف: ' . $company['phones'];
    }
    if ($company['address'] !== '') {
        $metaBits[] = $company['address'];
    }
    if ($metaBits !== []): ?>
    <p class="sd-print-banner__company-meta"><?php echo htmlspecialchars(implode(' — ', $metaBits), ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($company['invoice_footer'] !== ''): ?>
    <p class="sd-print-banner__footer-hint"><?php echo htmlspecialchars($company['invoice_footer'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
</div>
    <?php
}
