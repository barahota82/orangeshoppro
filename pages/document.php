<?php

declare(strict_types=1);

/**
 * صفحة عامة لعرض مستند الفاتورة/المردود عبر توكن QR (س27 — استثناء معتمد 2026-06-12).
 * العميل يختار اللغة (ar/en/fil/hi) ويرى نسخة قريبة من الفاتورة المطبوعة (بلا باركود).
 * noindex — لا تُفهرَس.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/upload_paths.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/sales_doc_print.php';
require_once __DIR__ . '/../includes/document_public_token.php';
require_once __DIR__ . '/../includes/public_document_view.php';

$allowedLang = ['ar', 'en', 'fil', 'hi'];
$lang = strtolower(trim((string) ($_GET['lang'] ?? '')));
if (! in_array($lang, $allowedLang, true)) {
    $lang = 'ar';
}
$_GET['lang'] = $lang; // حتى تترجم current_lang()/storefront_product_display_name

$token = trim((string) ($_GET['t'] ?? ''));
$isRtl = ($lang === 'ar');
$dir = $isRtl ? 'rtl' : 'ltr';

/* ترجمة محلية لتسميات الصفحة (مستقلة عن مصفوفات config). */
$L = [
    'ar' => [
        'inv_c' => 'فاتورة مبيعات', 'inv_o' => 'فاتورة مبيعات',
        'sales_return' => 'مردود مبيعات', 'purchase' => 'فاتورة مشتريات', 'purchase_return' => 'مردود مشتريات',
        'date' => 'التاريخ', 'customer' => 'العميل', 'supplier' => 'المورد', 'phone' => 'الهاتف',
        'bill_to' => 'فاتورة إلى', 'supplier_to' => 'المورد',
        'lbl_name' => 'الاسم', 'lbl_area' => 'المنطقة', 'lbl_address' => 'العنوان',
        'rc' => 'سجل تجاري', 'vat' => 'ض.ق.م', 'tel' => 'هاتف',
        'col_no' => '#', 'col_item' => 'الصنف', 'col_variant' => 'اللون / المقاس', 'col_qty' => 'الكمية',
        'col_price' => 'سعر الوحدة', 'col_discount' => 'خصم', 'col_total' => 'الإجمالي',
        'discount_total' => 'قيمة الخصم',
        'subtotal_inv' => 'إجمالي الفاتورة', 'net_inv' => 'مبلغ الفاتورة',
        'subtotal_ret' => 'إجمالي المردود', 'net_ret' => 'صافي المردود',
        'thanks' => 'شكراً لتعاملكم', 'signature' => 'التوقيع', 'stamp' => 'الختم',
        'not_found' => 'المستند غير موجود أو انتهت صلاحية الرابط.', 'language' => 'اللغة', 'serial' => 'رقم المستند',
    ],
    'en' => [
        'inv_c' => 'Sales Invoice', 'inv_o' => 'Sales Invoice',
        'sales_return' => 'Sales Return', 'purchase' => 'Purchase Invoice', 'purchase_return' => 'Purchase Return',
        'date' => 'Date', 'customer' => 'Customer', 'supplier' => 'Supplier', 'phone' => 'Phone',
        'bill_to' => 'Bill To', 'supplier_to' => 'Supplier',
        'lbl_name' => 'Name', 'lbl_area' => 'Area', 'lbl_address' => 'Address',
        'rc' => 'C.R.', 'vat' => 'VAT', 'tel' => 'Tel',
        'col_no' => '#', 'col_item' => 'Item', 'col_variant' => 'Color / Size', 'col_qty' => 'Qty',
        'col_price' => 'Unit Price', 'col_discount' => 'Discount', 'col_total' => 'Total',
        'discount_total' => 'Discount',
        'subtotal_inv' => 'Invoice Total', 'net_inv' => 'Amount Due',
        'subtotal_ret' => 'Return Total', 'net_ret' => 'Net Return',
        'thanks' => 'Thank you for your business', 'signature' => 'Signature', 'stamp' => 'Stamp',
        'not_found' => 'Document not found or the link has expired.', 'language' => 'Language', 'serial' => 'Document No.',
    ],
    'fil' => [
        'inv_c' => 'Sales Invoice', 'inv_o' => 'Sales Invoice',
        'sales_return' => 'Sales Return', 'purchase' => 'Purchase Invoice', 'purchase_return' => 'Purchase Return',
        'date' => 'Petsa', 'customer' => 'Customer', 'supplier' => 'Supplier', 'phone' => 'Telepono',
        'bill_to' => 'Para Kay', 'supplier_to' => 'Supplier',
        'lbl_name' => 'Pangalan', 'lbl_area' => 'Lugar', 'lbl_address' => 'Address',
        'rc' => 'C.R.', 'vat' => 'VAT', 'tel' => 'Tel',
        'col_no' => '#', 'col_item' => 'Item', 'col_variant' => 'Kulay / Sukat', 'col_qty' => 'Dami',
        'col_price' => 'Presyo', 'col_discount' => 'Diskwento', 'col_total' => 'Kabuuan',
        'discount_total' => 'Diskwento',
        'subtotal_inv' => 'Kabuuang Invoice', 'net_inv' => 'Halagang Babayaran',
        'subtotal_ret' => 'Kabuuang Return', 'net_ret' => 'Net na Return',
        'thanks' => 'Salamat sa inyong pakikipagkalakalan', 'signature' => 'Lagda', 'stamp' => 'Selyo',
        'not_found' => 'Hindi mahanap ang dokumento o nag-expire na ang link.', 'language' => 'Wika', 'serial' => 'Dokumento No.',
    ],
    'hi' => [
        'inv_c' => 'बिक्री चालान', 'inv_o' => 'बिक्री चालान',
        'sales_return' => 'बिक्री वापसी', 'purchase' => 'खरीद चालान', 'purchase_return' => 'खरीद वापसी',
        'date' => 'तारीख', 'customer' => 'ग्राहक', 'supplier' => 'आपूर्तिकर्ता', 'phone' => 'फ़ोन',
        'bill_to' => 'बिल प्राप्तकर्ता', 'supplier_to' => 'आपूर्तिकर्ता',
        'lbl_name' => 'नाम', 'lbl_area' => 'क्षेत्र', 'lbl_address' => 'पता',
        'rc' => 'C.R.', 'vat' => 'VAT', 'tel' => 'फ़ोन',
        'col_no' => '#', 'col_item' => 'वस्तु', 'col_variant' => 'रंग / माप', 'col_qty' => 'मात्रा',
        'col_price' => 'इकाई मूल्य', 'col_discount' => 'छूट', 'col_total' => 'कुल',
        'discount_total' => 'छूट',
        'subtotal_inv' => 'चालान कुल', 'net_inv' => 'देय राशि',
        'subtotal_ret' => 'वापसी कुल', 'net_ret' => 'शुद्ध वापसी',
        'thanks' => 'आपके व्यवसाय के लिए धन्यवाद', 'signature' => 'हस्ताक्षर', 'stamp' => 'मुहर',
        'not_found' => 'दस्तावेज़ नहीं मिला या लिंक की अवधि समाप्त हो गई।', 'language' => 'भाषा', 'serial' => 'दस्तावेज़ सं.',
    ],
];
$tt = static function (string $key) use ($L, $lang): string {
    return $L[$lang][$key] ?? ($L['en'][$key] ?? $key);
};

/* وضع معاينة مؤقت (?preview=1) — بيانات تجريبية فقط لاستعراض شكل الصفحة بلا توكن.
   مؤقّت: يُزال لاحقاً ويبقى التوليد عبر التوكن بعد الحفظ. */
$preview = isset($_GET['preview']) && (string) $_GET['preview'] !== '0' && (string) $_GET['preview'] !== '';

$doc = null;
$currencyUnit = '';
if ($preview) {
    $previewKind = strtolower(trim((string) ($_GET['kind'] ?? 'inv_c')));
    if (! in_array($previewKind, ['inv_c', 'inv_o', 'sales_return'], true)) {
        $previewKind = 'inv_c';
    }
    $previewSerial = ['inv_c' => 'INV-C-KW-1', 'inv_o' => 'INV-O-KW-1', 'sales_return' => 'SR-KW-1'][$previewKind];
    $currencyUnit = 'KD';
    $sampleName = ['ar' => 'منتج تجريبي', 'en' => 'Sample Product', 'fil' => 'Halimbawang Produkto', 'hi' => 'नमूना उत्पाद'][$lang] ?? 'Sample Product';
    $sampleVar = ['ar' => 'أحمر / M', 'en' => 'Red / M', 'fil' => 'Pula / M', 'hi' => 'लाल / M'][$lang] ?? 'Red / M';
    $sampleCust = ['ar' => 'عميل تجريبي', 'en' => 'Sample Customer', 'fil' => 'Halimbawang Customer', 'hi' => 'नमूना ग्राहक'][$lang] ?? 'Sample Customer';
    $doc = [
        'doc_kind' => $previewKind,
        'serial' => $previewSerial,
        'date' => date('Y-m-d'),
        'party_kind' => 'customer',
        'party_name' => $sampleCust,
        'party_phone' => '5000 0000',
        'party_area' => ['ar' => 'السالمية', 'en' => 'Salmiya'][$lang] ?? 'Salmiya',
        'party_address' => '',
        'country_id' => 0,
        'lines' => [
            ['name' => $sampleName . ' 1', 'variant' => $sampleVar, 'qty' => 2, 'price' => 3.5, 'discount' => 0.5, 'total' => 6.5],
            ['name' => $sampleName . ' 2', 'variant' => '', 'qty' => 1, 'price' => 12.25, 'discount' => 0, 'total' => 12.25],
            ['name' => $sampleName . ' 3', 'variant' => $sampleVar, 'qty' => 3, 'price' => 1.0, 'discount' => 0, 'total' => 3.0],
        ],
        'subtotal' => 22.25,
        'discount_total' => 0.5,
        'net_total' => 21.75,
    ];
} else {
    try {
        $pdo = db();
        $found = $token !== '' ? orange_doc_public_token_lookup($pdo, $token) : null;
        if ($found !== null) {
            $doc = orange_public_document_load($pdo, $found['doc_kind'], $found['doc_id'], (int) ($found['country_id'] ?? 0));
            if ($doc !== null) {
                $cid = (int) ($doc['country_id'] ?? 0);
                $currencyUnit = orange_storefront_currency_unit($pdo, $cid > 0 ? $cid : null);
            }
        }
    } catch (Throwable $e) {
        $doc = null;
    }
}

/* بيانات الشركة (شعار/اسم/سجل/هواتف/عنوان/ضريبة/نص قانوني) من إعدادات الشركة. */
$company = [
    'company_name_ar' => '', 'company_name_en' => '', 'logo_url' => '',
    'commercial_register' => '', 'phones' => '', 'address' => '', 'vat_number' => '',
    'invoice_footer_ar' => '', 'invoice_footer_en' => '', 'invoice_footer' => '',
];
if ($doc !== null) {
    try {
        $company = orange_sales_doc_print_company(db(), (int) ($doc['country_id'] ?? 0));
    } catch (Throwable $e) {
        // أبقِ الافتراضات
    }
}

$fmtMoney = static function ($n) use ($currencyUnit): string {
    $s = number_format((float) $n, 3, '.', ',');

    return $currencyUnit !== '' ? ($s . ' ' . $currencyUnit) : $s;
};

/* روابط مبدّل اللغة (نفس التوكن، lang مختلف). */
$langLinks = [];
foreach ($allowedLang as $lc) {
    $langLinks[$lc] = $preview
        ? (storefront_public_path('/pages/document.php') . '?' . http_build_query(['preview' => 1, 'kind' => $doc['doc_kind'] ?? 'inv_c', 'lang' => $lc]))
        : orange_doc_public_relative_url($token, $lc);
}
$langLabels = ['ar' => 'العربية', 'en' => 'English', 'fil' => 'Filipino', 'hi' => 'हिन्दी'];

$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$isReturnDoc = ($doc !== null) && in_array($doc['doc_kind'], ['sales_return', 'purchase_return'], true);
$isSupplierDoc = ($doc !== null) && in_array($doc['doc_kind'], ['purchase', 'purchase_return'], true);
$showLegal = ($doc !== null) && in_array($doc['doc_kind'], ['inv_c', 'inv_o'], true);
$legalNote = '';
if ($showLegal) {
    $legalNote = $lang === 'ar'
        ? ($company['invoice_footer_ar'] ?: ($company['invoice_footer'] ?: $company['invoice_footer_en']))
        : ($company['invoice_footer_en'] ?: ($company['invoice_footer'] ?: $company['invoice_footer_ar']));
}

/* خلايا الهواتف. */
$phoneCells = [];
foreach (preg_split('/\s*-\s*/', (string) $company['phones']) ?: [] as $p) {
    $p = trim((string) $p);
    if ($p !== '') {
        $phoneCells[] = $p;
    }
}

header('X-Robots-Tag: noindex, nofollow', true);
?>
<!DOCTYPE html>
<html lang="<?php echo $esc($lang); ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="format-detection" content="telephone=no">
    <title><?php echo $esc($doc !== null ? ($tt($doc['doc_kind']) . ' — ' . $doc['serial']) : $tt('not_found')); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; background: #f1f5f9; color: #0f172a; }
        .doc-wrap { max-width: 860px; margin: 0 auto; padding: 14px; }
        .doc-langbar { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; margin: 8px 0 14px; }
        .doc-langbar a { text-decoration: none; font-size: 0.85rem; padding: 6px 12px; border-radius: 999px; border: 1px solid #cbd5e1; color: #334155; background: #fff; }
        .doc-langbar a.is-active { background: #ea580c; color: #fff; border-color: #ea580c; font-weight: 700; }
        .doc-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; box-shadow: 0 1px 3px rgba(15,23,42,.06); }

        /* الترويسة: 3 أعمدة كالطباعة (شركة | مستند | طرف). */
        .inv-head { display: grid; grid-template-columns: 1.25fr 1fr 1.2fr; gap: 12px; border-bottom: 3px solid #ea580c; padding-bottom: 12px; margin-bottom: 14px; }
        .inv-brand__id { display: flex; flex-direction: row; align-items: center; gap: 8px; min-width: 0; }
        .inv-brand__id-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .inv-brand__logo { max-width: 70px; max-height: 60px; object-fit: contain; flex: 0 0 auto; }
        .inv-brand__name-ar { font-size: 0.98rem; font-weight: 800; color: #ea580c; display: inline-block; white-space: nowrap; transform-origin: <?php echo $isRtl ? 'right' : 'left'; ?> center; }
        .inv-brand__name-en { font-size: 0.8rem; font-weight: 700; color: #c2410c; display: inline-block; white-space: nowrap; }
        .inv-brand .lbl { color: #94a3b8; }
        .num { direction: ltr; unicode-bidi: isolate; }

        .inv-doc { text-align: center; }
        .inv-doc__title { font-size: 1.25rem; font-weight: 800; color: #0f172a; margin-bottom: 8px; }
        .inv-doc__rows { font-size: 0.85rem; color: #334155; line-height: 1.9; }
        .inv-doc__rows .lbl { color: #94a3b8; }

        .inv-party { border: 1px solid #fed7aa; background: #fff7ed; border-radius: 8px; padding: 8px 10px; font-size: 0.82rem; color: #334155; min-width: 0; }
        .inv-party__title { font-weight: 800; color: #c2410c; margin-bottom: 4px; font-size: 0.9rem; border-bottom: 1px dashed #fdba74; padding-bottom: 3px; }
        .inv-party__row { line-height: 1.7; word-break: break-word; }
        .inv-party__row .lbl { color: #94a3b8; }

        /* شبكة الأصناف. */
        .doc-lines-wrap { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table.doc-lines { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 0.85rem; table-layout: fixed; }
        table.doc-lines th { background: #ea580c; color: #fff; padding: 8px 5px; text-align: center; border: 1px solid #fb923c; font-weight: 700; }
        table.doc-lines td { padding: 7px 5px; border: 1px solid #e2e8f0; text-align: center; }
        table.doc-lines th, table.doc-lines td { word-break: break-word; overflow-wrap: anywhere; }
        table.doc-lines td.doc-name { text-align: <?php echo $isRtl ? 'right' : 'left'; ?>; }
        table.doc-lines tbody tr:nth-child(even) td { background: #fff7ed; }
        table.doc-lines col.c-no { width: 6%; }
        table.doc-lines col.c-item { width: 28%; }
        table.doc-lines col.c-variant { width: 16%; }
        table.doc-lines col.c-qty { width: 9%; }
        table.doc-lines col.c-price { width: 14%; }
        table.doc-lines col.c-discount { width: 13%; }
        table.doc-lines col.c-total { width: 14%; }

        .doc-totals { margin-top: 12px; margin-<?php echo $isRtl ? 'left' : 'right'; ?>: 0; margin-<?php echo $isRtl ? 'right' : 'left'; ?>: auto; max-width: 320px; font-size: 0.9rem; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
        .doc-totals div { display: flex; justify-content: space-between; padding: 6px 12px; }
        .doc-totals div + div { border-top: 1px solid #f1f5f9; }
        .doc-totals .doc-net { background: #ea580c; color: #fff; font-weight: 800; font-size: 1.02rem; border-top: 0; }

        /* تذييل: شكر + نص قانوني + بيانات تواصل بالوسط أسفل الصفحة. */
        .inv-footer { margin-top: 20px; border-top: 1px dashed #cbd5e1; padding-top: 12px; }
        .inv-footer__thanks { text-align: center; font-weight: 700; color: #0f172a; margin-bottom: 12px; }
        .inv-footer__legal { font-size: 0.72rem; color: #475569; line-height: 1.5; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; text-align: justify; margin-bottom: 12px; }
        .inv-contact { text-align: center; color: #475569; font-size: 0.82rem; line-height: 1.7; border-top: 1px solid #f1f5f9; padding-top: 10px; }
        .inv-contact__phones { direction: ltr; unicode-bidi: isolate; font-weight: 600; }
        .inv-contact__phones a { color: #ea580c; text-decoration: none; }

        .doc-empty { text-align: center; padding: 60px 20px; color: #64748b; }

        @media (max-width: 680px) {
            .inv-head { grid-template-columns: 1fr; }
            .inv-doc { text-align: <?php echo $isRtl ? 'right' : 'left'; ?>; }
            .doc-totals { max-width: 100%; }
        }
        @media (max-width: 560px) {
            .doc-card { padding: 12px; }
            table.doc-lines { font-size: 0.76rem; }
            table.doc-lines th, table.doc-lines td { padding: 5px 3px; }
        }
    </style>
</head>
<body>
<div class="doc-wrap">
    <div class="doc-langbar">
        <?php foreach ($allowedLang as $lc): ?>
            <a href="<?php echo $esc($langLinks[$lc]); ?>" class="<?php echo $lc === $lang ? 'is-active' : ''; ?>"><?php echo $esc($langLabels[$lc]); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($doc === null): ?>
        <div class="doc-card">
            <div class="inv-brand__name-ar">ORANGE</div>
            <div class="doc-empty"><?php echo $esc($tt('not_found')); ?></div>
        </div>
    <?php else: ?>
        <div class="doc-card">
            <div class="inv-head">
                <div class="inv-brand">
                    <div class="inv-brand__id">
                        <?php if ($company['logo_url'] !== ''): ?>
                            <img class="inv-brand__logo" src="<?php echo $esc($company['logo_url']); ?>" alt="">
                        <?php endif; ?>
                        <div class="inv-brand__id-text">
                            <?php if ($company['company_name_ar'] !== ''): ?>
                                <div class="inv-brand__name-ar" id="invNameAr"><?php echo $esc($company['company_name_ar']); ?></div>
                            <?php elseif ($company['company_name_en'] === ''): ?>
                                <div class="inv-brand__name-ar">ORANGE</div>
                            <?php endif; ?>
                            <?php if ($company['company_name_en'] !== ''): ?>
                                <div class="inv-brand__name-en" id="invNameEn" dir="ltr" lang="en"><?php echo $esc($company['company_name_en']); ?></div>
                            <?php endif; ?>
                            <?php if ($company['commercial_register'] !== ''): ?>
                                <div style="font-size:0.78rem;color:#475569;"><span class="lbl"><?php echo $esc($tt('rc')); ?>:</span> <span class="num"><?php echo $esc($company['commercial_register']); ?></span></div>
                            <?php endif; ?>
                            <?php if ($company['vat_number'] !== ''): ?>
                                <div style="font-size:0.78rem;color:#475569;"><span class="lbl"><?php echo $esc($tt('vat')); ?>:</span> <span class="num"><?php echo $esc($company['vat_number']); ?></span></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="inv-doc">
                    <div class="inv-doc__title"><?php echo $esc($tt($doc['doc_kind'])); ?></div>
                    <div class="inv-doc__rows">
                        <div><span class="lbl"><?php echo $esc($tt('serial')); ?>:</span> <b class="num"><?php echo $esc($doc['serial']); ?></b></div>
                        <?php if (($doc['date'] ?? '') !== ''): ?>
                            <div><span class="lbl"><?php echo $esc($tt('date')); ?>:</span> <b class="num"><?php echo $esc($doc['date']); ?></b></div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                $partyRows = [];
                if (trim((string) ($doc['party_name'] ?? '')) !== '') {
                    $partyRows[] = [$tt('lbl_name'), $doc['party_name'], false];
                }
                if (trim((string) ($doc['party_phone'] ?? '')) !== '') {
                    $partyRows[] = [$tt('phone'), $doc['party_phone'], true];
                }
                if (trim((string) ($doc['party_area'] ?? '')) !== '') {
                    $partyRows[] = [$tt('lbl_area'), $doc['party_area'], false];
                }
                if (trim((string) ($doc['party_address'] ?? '')) !== '') {
                    $partyRows[] = [$tt('lbl_address'), $doc['party_address'], false];
                }
                ?>
                <?php if ($partyRows !== []): ?>
                <div class="inv-party">
                    <div class="inv-party__title"><?php echo $esc($isSupplierDoc ? $tt('supplier_to') : $tt('bill_to')); ?></div>
                    <?php foreach ($partyRows as $pr): ?>
                        <div class="inv-party__row"><span class="lbl"><?php echo $esc($pr[0]); ?>:</span>
                            <?php if ($pr[2]): ?><span class="num" dir="ltr"><?php echo $esc($pr[1]); ?></span><?php else: ?><?php echo $esc($pr[1]); ?><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="doc-lines-wrap">
            <table class="doc-lines">
                <colgroup>
                    <col class="c-no"><col class="c-item"><col class="c-variant"><col class="c-qty">
                    <col class="c-price"><col class="c-discount"><col class="c-total">
                </colgroup>
                <thead>
                    <tr>
                        <th><?php echo $esc($tt('col_no')); ?></th>
                        <th><?php echo $esc($tt('col_item')); ?></th>
                        <th><?php echo $esc($tt('col_variant')); ?></th>
                        <th><?php echo $esc($tt('col_qty')); ?></th>
                        <th><?php echo $esc($tt('col_price')); ?></th>
                        <th><?php echo $esc($tt('col_discount')); ?></th>
                        <th><?php echo $esc($tt('col_total')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 0; foreach ($doc['lines'] as $ln): $i++; ?>
                        <tr>
                            <td class="num"><?php echo $esc((string) $i); ?></td>
                            <td class="doc-name"><?php echo $esc($ln['name']); ?></td>
                            <td><?php echo $esc($ln['variant']); ?></td>
                            <td class="num"><?php echo $esc((string) (0 + $ln['qty'])); ?></td>
                            <td class="num"><?php echo $esc($fmtMoney($ln['price'])); ?></td>
                            <td class="num"><?php echo $esc($fmtMoney($ln['discount'])); ?></td>
                            <td class="num"><?php echo $esc($fmtMoney($ln['total'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="doc-totals">
                <div><span><?php echo $esc($tt($isReturnDoc ? 'subtotal_ret' : 'subtotal_inv')); ?></span><span class="num"><?php echo $esc($fmtMoney($doc['subtotal'])); ?></span></div>
                <div><span><?php echo $esc($tt('discount_total')); ?></span><span class="num"><?php echo $esc($fmtMoney($doc['discount_total'])); ?></span></div>
                <div class="doc-net"><span><?php echo $esc($tt($isReturnDoc ? 'net_ret' : 'net_inv')); ?></span><span class="num"><?php echo $esc($fmtMoney($doc['net_total'])); ?></span></div>
            </div>

            <div class="inv-footer">
                <?php if (! $isSupplierDoc): ?>
                    <div class="inv-footer__thanks"><?php echo $esc($tt('thanks')); ?></div>
                    <?php if ($legalNote !== ''): ?>
                        <div class="inv-footer__legal" dir="<?php echo $lang === 'ar' ? 'rtl' : 'ltr'; ?>"><?php echo $esc($legalNote); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($company['address'] !== '' || $phoneCells !== []): ?>
                <div class="inv-contact">
                    <?php if ($company['address'] !== ''): ?>
                        <div><?php echo $esc($company['address']); ?></div>
                    <?php endif; ?>
                    <?php if ($phoneCells !== []): ?>
                        <div class="inv-contact__phones"><?php
                        $phoneHtml = [];
                        foreach ($phoneCells as $pc) {
                            $tel = preg_replace('/[^0-9+]/', '', $pc);
                            $phoneHtml[] = '<a href="tel:' . $esc($tel) . '">' . $esc($pc) . '</a>';
                        }
                        echo implode(' - ', $phoneHtml);
                        ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var ar = document.getElementById('invNameAr'), en = document.getElementById('invNameEn');
    if (!ar || !en) { return; }
    function fit() {
        ar.style.transform = '';
        var aw = ar.offsetWidth, ew = en.offsetWidth;
        if (aw > 4 && ew > 4) { ar.style.transform = 'scaleX(' + (ew / aw) + ')'; }
    }
    fit();
    window.addEventListener('resize', fit);
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fit); }
})();
</script>
</body>
</html>
