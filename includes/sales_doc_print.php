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
 * GAP-SALE-DOC-01 مرحلة 4 — ترويسة طباعة موحّدة (INV-C / INV-O / مردود / شراء / مردود شراء).
 * إعادة تصميم احترافي (هوية برتقالية، 3 أعمدة، تسميات ثنائية اللغة):
 *   - عمود الشركة (يمين) + صندوق المستند (وسط) + بلوك الطرف «فاتورة إلى/المورد» (يسار، اختياري).
 *
 * @param array{
 *   prefix:string, doc_title:string, doc_title_en?:string, doc_badge:string,
 *   country_id:int, currency_code?:string,
 *   show_party?:bool, party_title?:string,
 *   show_doc_date?:bool, doc_date_label?:string,
 *   show_print_date?:bool, show_qr?:bool
 * } $ctx
 */
function orange_sales_doc_print_banner(array $ctx): void
{
    $prefix = preg_replace('/[^a-z0-9_]/i', '', (string) ($ctx['prefix'] ?? 'sd')) ?: 'sd';
    $pfx = htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8');
    $docTitle = trim((string) ($ctx['doc_title'] ?? 'مستند مبيعات'));
    $docTitleEn = trim((string) ($ctx['doc_title_en'] ?? ''));
    $docBadge = trim((string) ($ctx['doc_badge'] ?? ''));
    $countryId = (int) ($ctx['country_id'] ?? 0);
    $currencyCode = trim((string) ($ctx['currency_code'] ?? ''));

    $showParty = !empty($ctx['show_party']);
    $partyTitle = trim((string) ($ctx['party_title'] ?? 'فاتورة إلى / Bill To'));
    $showDocDate = !empty($ctx['show_doc_date']);
    $docDateLabel = trim((string) ($ctx['doc_date_label'] ?? 'تاريخ الفاتورة / Invoice Date'));
    $showPrintDate = array_key_exists('show_print_date', $ctx) ? !empty($ctx['show_print_date']) : true;
    $showQr = !empty($ctx['show_qr']);

    $company = orange_sales_doc_print_company(db(), $countryId);

    $nameAr = $company['company_name_ar'];
    $nameEn = $company['company_name_en'];
    $logoUrl = $company['logo_url'];

    $headClass = 'sd-print-banner__head' . ($showParty ? ' sd-print-banner__head--with-party' : '');
    ?>
<div class="sd-print-banner" aria-hidden="true">
    <div class="<?php echo $headClass; ?>">
        <div class="sd-print-banner__brand">
            <div class="sd-print-banner__brand-id">
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
            <?php
            $metaRows = [];
            if ($company['commercial_register'] !== '') {
                $metaRows[] = ['س.ت / C.R.', $company['commercial_register']];
            }
            if ($company['vat_number'] !== '') {
                $metaRows[] = ['ض.ق.م / VAT', $company['vat_number']];
            }
            if ($company['phones'] !== '') {
                $metaRows[] = ['هاتف / Tel', $company['phones']];
            }
            if ($company['address'] !== '') {
                $metaRows[] = ['العنوان / Address', $company['address']];
            }
            if ($metaRows !== []): ?>
            <div class="sd-print-banner__company-meta">
                <?php foreach ($metaRows as $row): ?>
                <p><span class="sd-print-banner__label"><?php echo htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8'); ?>:</span> <span><?php echo htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8'); ?></span></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="sd-print-banner__meta">
            <p class="sd-print-banner__doc-title">
                <?php echo htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($docTitleEn !== ''): ?><span class="sd-print-banner__doc-title-en" dir="ltr" lang="en"> / <?php echo htmlspecialchars($docTitleEn, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
            </p>
            <?php if ($docBadge !== ''): ?>
            <span class="sd-print-banner__badge" dir="ltr" lang="en"><?php echo htmlspecialchars($docBadge, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <div class="sd-print-banner__meta-body">
                <div class="sd-print-banner__meta-lines">
                    <p class="sd-print-banner__serial-row">
                        <span class="sd-print-banner__label">المسلسل / Serial:</span>
                        <strong id="<?php echo $pfx; ?>_sd_print_serial" class="sd-print-banner__serial" dir="ltr" lang="en">—</strong>
                    </p>
                    <?php if ($showDocDate): ?>
                    <p class="sd-print-banner__docdate-row">
                        <span class="sd-print-banner__label"><?php echo htmlspecialchars($docDateLabel, ENT_QUOTES, 'UTF-8'); ?>:</span>
                        <span id="<?php echo $pfx; ?>_sd_print_docdate" class="sd-print-banner__docdate" dir="ltr" lang="en">—</span>
                    </p>
                    <?php endif; ?>
                    <?php if ($showPrintDate): ?>
                    <p class="sd-print-banner__date-row">
                        <span class="sd-print-banner__label">تاريخ الطباعة / Printed:</span>
                        <span id="<?php echo $pfx; ?>_sd_print_date" class="sd-print-banner__date" dir="ltr" lang="en">—</span>
                    </p>
                    <?php endif; ?>
                    <?php if ($currencyCode !== ''): ?>
                    <p class="sd-print-banner__currency-row">
                        <span class="sd-print-banner__label">العملة / Currency:</span>
                        <span dir="ltr" lang="en"><?php echo htmlspecialchars($currencyCode, ENT_QUOTES, 'UTF-8'); ?></span>
                    </p>
                    <?php endif; ?>
                </div>
                <?php if ($showQr): ?>
                <div class="sd-print-banner__qr" aria-hidden="true">
                    <span class="sd-print-banner__qr-box" id="<?php echo $pfx; ?>_sd_print_qr"></span>
                    <span class="sd-print-banner__qr-cap">QR</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($showParty): ?>
        <div class="sd-print-banner__party">
            <p class="sd-print-banner__party-title"><?php echo htmlspecialchars($partyTitle, ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="sd-print-banner__party-row"><span class="sd-print-banner__label">الاسم / Name:</span> <span id="<?php echo $pfx; ?>_sd_print_party_name" class="sd-print-banner__party-val">—</span></p>
            <p class="sd-print-banner__party-row"><span class="sd-print-banner__label">الكود / Code:</span> <span id="<?php echo $pfx; ?>_sd_print_party_code" class="sd-print-banner__party-val" dir="ltr" lang="en">—</span></p>
            <p class="sd-print-banner__party-row"><span class="sd-print-banner__label">الهاتف / Phone:</span> <span id="<?php echo $pfx; ?>_sd_print_party_phone" class="sd-print-banner__party-val" dir="ltr" lang="en">—</span></p>
            <p class="sd-print-banner__party-row"><span class="sd-print-banner__label">المنطقة / Area:</span> <span id="<?php echo $pfx; ?>_sd_print_party_area" class="sd-print-banner__party-val">—</span></p>
            <p class="sd-print-banner__party-row"><span class="sd-print-banner__label">العنوان / Address:</span> <span id="<?php echo $pfx; ?>_sd_print_party_address" class="sd-print-banner__party-val">—</span></p>
        </div>
        <?php endif; ?>
    </div>
</div>
    <?php
}

/**
 * تذييل طباعة لفاتورة العميل (شكر + توقيع/ختم + invoice_footer).
 * يُوضع في نهاية منطقة الطباعة `.jv-print-area` للمستندات التي يستلمها العميل (المبيعات).
 *
 * @param array{country_id:int} $ctx
 */
function orange_sales_doc_print_footer(array $ctx): void
{
    $countryId = (int) ($ctx['country_id'] ?? 0);
    $company = orange_sales_doc_print_company(db(), $countryId);
    $footerNote = $company['invoice_footer'];
    ?>
<div class="sd-print-footer" aria-hidden="true">
    <p class="sd-print-footer__thanks">شكراً لتعاملكم / Thank you for your business</p>
    <div class="sd-print-footer__signatures">
        <div class="sd-print-footer__sign">
            <span class="sd-print-footer__sign-line"></span>
            <span class="sd-print-footer__sign-label">التوقيع / Signature</span>
        </div>
        <div class="sd-print-footer__sign">
            <span class="sd-print-footer__sign-line"></span>
            <span class="sd-print-footer__sign-label">الختم / Stamp</span>
        </div>
    </div>
    <?php if ($footerNote !== ''): ?>
    <p class="sd-print-footer__note"><?php echo htmlspecialchars($footerNote, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
</div>
    <?php
}
