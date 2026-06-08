<?php

declare(strict_types=1);

require_once __DIR__ . '/company_settings.php';
require_once __DIR__ . '/sales_doc_print.php';
require_once __DIR__ . '/date_format.php';
require_once __DIR__ . '/accounting_report_money.php';

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
 * thead ترويسة + عناوين أعمدة الأسطر — تتكرر أعلى كل صفحة طباعة (نمط ta-report-print-table).
 *
 * @param array{title_ar:string,title_en?:string,title_span_id?:string,cols_colspan?:int,with_actions_col?:bool} $ctx
 */
function orange_voucher_print_banner_thead(PDO $pdo, int $countryId, array $ctx): void
{
    $colsColspan = (int) ($ctx['cols_colspan'] ?? 5);
    if ($colsColspan < 4) {
        $colsColspan = 4;
    }
    $withActionsCol = (bool) ($ctx['with_actions_col'] ?? true);
    ?>
<thead class="ta-report-print-thead jv-voucher-print-thead">
    <tr class="ta-report-banner-row">
        <td colspan="<?php echo $colsColspan; ?>" class="ta-report-banner-cell">
            <?php orange_voucher_print_banner_markup($pdo, $countryId, $ctx); ?>
        </td>
    </tr>
    <tr class="ta-report-cols-row jv-voucher-cols-row">
        <th>كود الحساب</th>
        <th>اسم الحساب</th>
        <th>مدين</th>
        <th>دائن</th>
        <?php if ($withActionsCol): ?>
        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
        <?php endif; ?>
    </tr>
</thead>
    <?php
}

/**
 * عناوين أعمدة الأسطر على الشاشة فقط — في الطباعة تُكرَّر من thead (jv-voucher-cols-row).
 */
function orange_voucher_print_lines_cols_screen_tbody(bool $withActionsCol = true): void
{
    ?>
<tbody class="jv-voucher-cols-screen-body jv-print-hide">
    <tr class="jv-voucher-cols-screen-row">
        <th>كود الحساب</th>
        <th>اسم الحساب</th>
        <th>مدين</th>
        <th>دائن</th>
        <?php if ($withActionsCol): ?>
        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
        <?php endif; ?>
    </tr>
</tbody>
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
 * تذييل طباعة السند — نفس التقارير: @page 12mm عام + تاريخ/وقت في @bottom-right.
 */
function orange_voucher_print_metafoot(): void
{
    $printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
    echo orange_accounting_report_print_metafoot_markup($printDatetime);
}
