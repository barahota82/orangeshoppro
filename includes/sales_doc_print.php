<?php

declare(strict_types=1);

require_once __DIR__ . '/company_settings.php';
require_once __DIR__ . '/upload_paths.php';

/**
 * @return array{company_name_ar:string,company_name_en:string,logo_url:string,commercial_register:string,phones:string,address:string,vat_number:string,invoice_footer:string,invoice_footer_ar:string,invoice_footer_en:string}
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
        'invoice_footer_ar' => '',
        'invoice_footer_en' => '',
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
        'invoice_footer_ar' => trim((string) ($row['invoice_footer_ar'] ?? '')),
        'invoice_footer_en' => trim((string) ($row['invoice_footer_en'] ?? '')),
    ];
}

/**
 * يقسّم سلسلة أرقام مفصولة بشرطة إلى خلايا <span> للطباعة (محاذاة أعمدة).
 */
function orange_sales_doc_phone_cells(string $phones): string
{
    $parts = preg_split('/\s*-\s*/', trim($phones)) ?: [];
    $html = '';
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $html .= '<span class="sd-print-banner__num" dir="ltr">'
            . htmlspecialchars($part, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return $html;
}

/**
 * صف ثلاثي للطباعة: تسمية عربية (يمين) | القيمة (وسط) | تسمية إنجليزية (يسار).
 * @param string $label تسمية بصيغة «عربي / English» (تُقسَّم على ' / ').
 * @param string $valueHtml HTML القيمة (قد يحوي span بمعرّف للتعبئة عبر JS).
 */
function orange_sales_doc_print_kv(string $label, string $valueHtml): string
{
    $parts = explode(' / ', $label, 2);
    $ar = trim($parts[0] ?? $label);
    $en = trim($parts[1] ?? '');
    $h = '<p class="sd-kv">';
    $h .= '<span class="sd-kv__ar">' . htmlspecialchars($ar, ENT_QUOTES, 'UTF-8') . '</span>';
    $h .= '<span class="sd-kv__val">' . $valueHtml . '</span>';
    $h .= '<span class="sd-kv__en" dir="ltr" lang="en">' . htmlspecialchars($en, ENT_QUOTES, 'UTF-8') . '</span>';
    $h .= '</p>';
    return $h;
}

/**
 * GAP-SALE-DOC-01 مرحلة 4 — ترويسة طباعة موحّدة (INV-C / INV-O / مردود / شراء / مردود شراء).
 * إعادة تصميم احترافي (هوية برتقالية، 3 أعمدة، تسميات ثنائية اللغة):
 *   - عمود الشركة (يمين) + صندوق المستند (وسط) + بلوك الطرف «فاتورة إلى/المورد» (يسار، اختياري).
 *
 * @param array{
 *   prefix:string, doc_title:string, doc_title_en?:string, doc_badge:string,
 *   country_id:int, currency_code?:string, serial_label?:string,
 *   show_party?:bool, party_title?:string, party_rows?:array<array{0:string,1:string,2?:string}>,
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
    $partyTitleValueId = preg_replace('/[^a-z0-9_]/i', '', (string) ($ctx['party_title_value_id'] ?? ''));
    $partyRows = (isset($ctx['party_rows']) && is_array($ctx['party_rows'])) ? $ctx['party_rows'] : [
        ['الاسم / Name', 'party_name', ''],
        ['الهاتف / Phone', 'party_phone', 'ltr'],
        ['المنطقة / Area', 'party_area', ''],
        ['العنوان / Address', 'party_address', ''],
    ];
    $serialLabel = trim((string) ($ctx['serial_label'] ?? 'المسلسل / Serial'));
    $showDocDate = !empty($ctx['show_doc_date']);
    $docDateLabel = trim((string) ($ctx['doc_date_label'] ?? 'تاريخ الفاتورة / Invoice Date'));
    $showPrintDate = array_key_exists('show_print_date', $ctx) ? !empty($ctx['show_print_date']) : true;
    $showQr = !empty($ctx['show_qr']);
    $totalsRows = (isset($ctx['totals_rows']) && is_array($ctx['totals_rows'])) ? $ctx['totals_rows'] : [];
    $showNotes = !empty($ctx['show_notes']);

    $company = orange_sales_doc_print_company(db(), $countryId);

    $nameAr = $company['company_name_ar'];
    $nameEn = $company['company_name_en'];
    $logoUrl = $company['logo_url'];

    $phoneByChannel = !empty($ctx['phone_by_channel']);
    $crValue = $company['commercial_register'];
    // الأعمدة محاذاة صفاً بصف مع عمود اسم الشركة (الأساس): عنوان=صف1، أرقام تواصل=صف2، ض.ق.م=صف3.
    $metaRows = [];
    if ($company['address'] !== '') {
        $metaRows[] = ['label' => '', 'value' => $company['address'], 'row' => 1];
    }
    if ($phoneByChannel || $company['phones'] !== '') {
        $metaRows[] = ['label' => 'Tel', 'value' => $company['phones'], 'id' => $pfx . '_sd_print_phone', 'kind' => 'phone', 'row' => 2];
    }
    if ($company['vat_number'] !== '') {
        $metaRows[] = ['label' => 'ض.ق.م / VAT', 'value' => $company['vat_number'], 'row' => 3];
    }

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
                    <?php if ($crValue !== ''): ?>
                    <p class="sd-print-banner__cr"><span class="sd-print-banner__label">سجل تجاري ( R.C.)</span> : <span dir="ltr" lang="en"><?php echo htmlspecialchars($crValue, ENT_QUOTES, 'UTF-8'); ?></span></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($metaRows !== []): ?>
            <div class="sd-print-banner__company-meta">
                <?php foreach ($metaRows as $row): ?>
                <?php if (($row['kind'] ?? '') === 'phone'): ?>
                <p class="sd-print-banner__phone-row"><?php if ($row['label'] !== ''): ?><span class="sd-print-banner__label"><?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?>:</span> <?php endif; ?><span class="sd-print-banner__nums" id="<?php echo htmlspecialchars((string) $row['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo orange_sales_doc_phone_cells((string) $row['value']); ?></span></p>
                <?php else: ?>
                <p><?php if ($row['label'] !== ''): ?><span class="sd-print-banner__label"><?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?>:</span> <?php endif; ?><span><?php echo htmlspecialchars((string) $row['value'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="sd-print-banner__meta">
            <p class="sd-print-banner__doc-title">
                <span class="sd-print-banner__doc-title-ar"><?php echo htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($docTitleEn !== ''): ?><span class="sd-print-banner__doc-title-en" dir="ltr" lang="en"><?php echo htmlspecialchars($docTitleEn, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
            </p>
            <?php if ($docBadge !== ''): ?>
            <span class="sd-print-banner__badge" dir="ltr" lang="en"><?php echo htmlspecialchars($docBadge, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <div class="sd-print-banner__meta-body">
                <div class="sd-print-banner__meta-lines">
                    <?php echo orange_sales_doc_print_kv($serialLabel, '<strong id="' . $pfx . '_sd_print_serial" class="sd-print-banner__serial" dir="ltr" lang="en">—</strong>'); ?>
                    <?php if ($showDocDate): ?>
                    <?php echo orange_sales_doc_print_kv($docDateLabel, '<span id="' . $pfx . '_sd_print_docdate" class="sd-print-banner__docdate" dir="ltr" lang="en">—</span>'); ?>
                    <?php endif; ?>
                    <?php if ($showPrintDate): ?>
                    <?php echo orange_sales_doc_print_kv('تاريخ الطباعة / Printed', '<span id="' . $pfx . '_sd_print_date" class="sd-print-banner__date" dir="ltr" lang="en">—</span>'); ?>
                    <?php endif; ?>
                    <?php if ($totalsRows !== []): ?>
                    <span class="sd-print-banner__meta-sep" aria-hidden="true"></span>
                    <?php foreach ($totalsRows as $tr): ?>
                    <?php
                    $trLabel = (string) ($tr[0] ?? '');
                    $trSuffix = preg_replace('/[^a-z0-9_]/i', '', (string) ($tr[1] ?? '')) ?: 'total_field';
                    echo orange_sales_doc_print_kv($trLabel, '<strong id="' . $pfx . '_sd_print_' . $trSuffix . '" class="sd-print-banner__total" dir="ltr" lang="en">—</strong>');
                    ?>
                    <?php endforeach; ?>
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
            <?php
            $ptParts = explode(' / ', $partyTitle, 2);
            $ptAr = trim($ptParts[0] ?? $partyTitle);
            $ptEn = trim($ptParts[1] ?? '');
            ?>
            <p class="sd-print-banner__party-title">
                <span class="sd-print-banner__party-title-ar"><?php echo htmlspecialchars($ptAr, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($partyTitleValueId !== ''): ?>
                <span class="sd-print-banner__party-title-num" id="<?php echo $pfx . '_sd_print_' . $partyTitleValueId; ?>" dir="ltr" lang="en">—</span>
                <?php elseif ($ptEn !== ''): ?>
                <span class="sd-print-banner__party-title-en" dir="ltr" lang="en"><?php echo htmlspecialchars($ptEn, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </p>
            <div class="sd-kv-grid">
                <?php foreach ($partyRows as $pr): ?>
                <?php
                $prLabel = (string) ($pr[0] ?? '');
                $prSuffix = preg_replace('/[^a-z0-9_]/i', '', (string) ($pr[1] ?? '')) ?: 'party_field';
                $prDir = ((string) ($pr[2] ?? '')) === 'ltr' ? ' dir="ltr" lang="en"' : '';
                echo orange_sales_doc_print_kv($prLabel, '<span id="' . $pfx . '_sd_print_' . $prSuffix . '" class="sd-print-banner__party-val"' . $prDir . '>—</span>');
                ?>
                <?php endforeach; ?>
            </div>
            <?php if ($showNotes): ?>
            <div class="sd-print-banner__notes">
                <span id="<?php echo $pfx; ?>_sd_print_notes" class="sd-print-banner__notes-val"></span>
            </div>
            <?php endif; ?>
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
 * @param array{country_id:int, show_note?:bool} $ctx
 *   show_note: إظهار النص القانوني (invoice_footer). افتراضياً true.
 *   يُمرَّر false للمستندات التي لا يناسبها النص القانوني للفاتورة (مثل مردود المبيعات).
 */
function orange_sales_doc_print_footer(array $ctx): void
{
    $countryId = (int) ($ctx['country_id'] ?? 0);
    $showNote = !array_key_exists('show_note', $ctx) || !empty($ctx['show_note']);
    $company = orange_sales_doc_print_company(db(), $countryId);
    $footerAr = $showNote ? $company['invoice_footer_ar'] : '';
    $footerEn = $showNote ? $company['invoice_footer_en'] : '';
    $footerLegacy = $showNote ? $company['invoice_footer'] : '';
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
</div>
<?php /* النص القانوني (عربي/إنجليزي منفصلان) أسفل كل صفحة عبر position:fixed في الطباعة (CSS). تحت بعض حالياً. */ ?>
<?php if ($footerAr !== '' || $footerEn !== ''): ?>
<div class="sd-print-legal" aria-hidden="true">
    <?php if ($footerAr !== ''): ?>
    <p class="sd-print-legal__note sd-print-legal__note--ar" dir="rtl" lang="ar"><?php echo htmlspecialchars($footerAr, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($footerEn !== ''): ?>
    <p class="sd-print-legal__note sd-print-legal__note--en" dir="ltr" lang="en"><?php echo htmlspecialchars($footerEn, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
</div>
<?php elseif ($footerLegacy !== ''): ?>
<div class="sd-print-legal" aria-hidden="true">
    <p class="sd-print-legal__note"><?php echo htmlspecialchars($footerLegacy, ENT_QUOTES, 'UTF-8'); ?></p>
</div>
<?php endif; ?>
    <?php
}

/**
 * تذييل صفحة الطباعة للنص القانوني عبر صناديق هامش @page (يتكرر أسفل كل صفحة، ويحجز مساحته):
 *   @bottom-right  = النص العربي (يمين)
 *   @bottom-center = رقم الصفحة «صفحة س من ص»
 *   @bottom-left   = النص الإنجليزي (يسار)
 * يُطبع في صفحات فواتير المبيعات (شركة/أونلاين). يُرجع '' إذا لا يوجد نص قانوني.
 */
function orange_sales_doc_print_legal_pagecss(int $countryId): string
{
    $company = orange_sales_doc_print_company(db(), $countryId);
    $ar = $company['invoice_footer_ar'];
    $en = $company['invoice_footer_en'];
    if ($ar === '' && $en === '' && $company['invoice_footer'] !== '') {
        $ar = $company['invoice_footer'];
    }
    if ($ar === '' && $en === '') {
        return '';
    }
    $esc = static function (string $s): string {
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        $s = str_replace('\\', '\\\\', $s);
        return str_replace('"', '\\"', $s);
    };
    $arCss = $esc($ar);
    $enCss = $esc($en);
    ob_start();
    ?>
<style>
@media print {
    @page {
        margin-bottom: 26mm;
        @bottom-left {
            content: "<?php echo $enCss; ?>";
            font-size: 6.5pt;
            color: #475569;
            direction: ltr;
            vertical-align: top;
            margin: 2mm 6mm 0;
        }
        @bottom-center {
            content: "صفحة " counter(page) " من " counter(pages);
            font-size: 8pt;
            color: #64748b;
            vertical-align: top;
            margin-top: 2mm;
        }
        @bottom-right {
            content: "<?php echo $arCss; ?>";
            font-size: 6.5pt;
            color: #475569;
            direction: rtl;
            vertical-align: top;
            margin: 2mm 6mm 0;
        }
    }
}
</style>
    <?php
    return (string) ob_get_clean();
}
