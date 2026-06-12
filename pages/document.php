<?php

declare(strict_types=1);

/**
 * صفحة عامة لعرض مستند الفاتورة/المردود عبر توكن QR (س27 — استثناء معتمد 2026-06-12).
 * العميل يختار اللغة (ar/en/fil/hi) ويرى نفس الفاتورة المطبوعة على ورقته.
 * noindex — لا تُفهرَس.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/upload_paths.php';
require_once __DIR__ . '/../includes/countries.php';
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
        'inv_c' => 'فاتورة مبيعات', 'inv_o' => 'فاتورة مبيعات أونلاين',
        'sales_return' => 'مردود مبيعات', 'purchase' => 'فاتورة مشتريات', 'purchase_return' => 'مردود مشتريات',
        'date' => 'التاريخ', 'customer' => 'العميل', 'supplier' => 'المورد', 'phone' => 'الهاتف',
        'col_item' => 'الصنف', 'col_variant' => 'اللون / المقاس', 'col_qty' => 'الكمية',
        'col_price' => 'سعر الوحدة', 'col_discount' => 'خصم', 'col_total' => 'الإجمالي',
        'subtotal' => 'إجمالي البنود', 'discount_total' => 'قيمة الخصم', 'net_total' => 'صافي الإجمالي',
        'not_found' => 'المستند غير موجود أو انتهت صلاحية الرابط.', 'language' => 'اللغة', 'serial' => 'رقم المستند',
    ],
    'en' => [
        'inv_c' => 'Sales Invoice', 'inv_o' => 'Online Sales Invoice',
        'sales_return' => 'Sales Return', 'purchase' => 'Purchase Invoice', 'purchase_return' => 'Purchase Return',
        'date' => 'Date', 'customer' => 'Customer', 'supplier' => 'Supplier', 'phone' => 'Phone',
        'col_item' => 'Item', 'col_variant' => 'Color / Size', 'col_qty' => 'Qty',
        'col_price' => 'Unit Price', 'col_discount' => 'Discount', 'col_total' => 'Total',
        'subtotal' => 'Subtotal', 'discount_total' => 'Discount', 'net_total' => 'Net Total',
        'not_found' => 'Document not found or the link has expired.', 'language' => 'Language', 'serial' => 'Document No.',
    ],
    'fil' => [
        'inv_c' => 'Sales Invoice', 'inv_o' => 'Online Sales Invoice',
        'sales_return' => 'Sales Return', 'purchase' => 'Purchase Invoice', 'purchase_return' => 'Purchase Return',
        'date' => 'Petsa', 'customer' => 'Customer', 'supplier' => 'Supplier', 'phone' => 'Telepono',
        'col_item' => 'Item', 'col_variant' => 'Kulay / Sukat', 'col_qty' => 'Dami',
        'col_price' => 'Presyo', 'col_discount' => 'Diskwento', 'col_total' => 'Kabuuan',
        'subtotal' => 'Subtotal', 'discount_total' => 'Diskwento', 'net_total' => 'Net na Kabuuan',
        'not_found' => 'Hindi mahanap ang dokumento o nag-expire na ang link.', 'language' => 'Wika', 'serial' => 'Dokumento No.',
    ],
    'hi' => [
        'inv_c' => 'बिक्री चालान', 'inv_o' => 'ऑनलाइन बिक्री चालान',
        'sales_return' => 'बिक्री वापसी', 'purchase' => 'खरीद चालान', 'purchase_return' => 'खरीद वापसी',
        'date' => 'तारीख', 'customer' => 'ग्राहक', 'supplier' => 'आपूर्तिकर्ता', 'phone' => 'फ़ोन',
        'col_item' => 'वस्तु', 'col_variant' => 'रंग / माप', 'col_qty' => 'मात्रा',
        'col_price' => 'इकाई मूल्य', 'col_discount' => 'छूट', 'col_total' => 'कुल',
        'subtotal' => 'उप-योग', 'discount_total' => 'छूट', 'net_total' => 'शुद्ध कुल',
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
    $currencyUnit = 'KD';
    $sampleName = ['ar' => 'منتج تجريبي', 'en' => 'Sample Product', 'fil' => 'Halimbawang Produkto', 'hi' => 'नमूना उत्पाद'][$lang] ?? 'Sample Product';
    $sampleVar = ['ar' => 'أحمر / M', 'en' => 'Red / M', 'fil' => 'Pula / M', 'hi' => 'लाल / M'][$lang] ?? 'Red / M';
    $sampleCust = ['ar' => 'عميل تجريبي', 'en' => 'Sample Customer', 'fil' => 'Halimbawang Customer', 'hi' => 'नमूना ग्राहक'][$lang] ?? 'Sample Customer';
    $doc = [
        'doc_kind' => 'inv_c',
        'serial' => 'INV-C-KW-1',
        'date' => date('Y-m-d'),
        'party_kind' => 'customer',
        'party_name' => $sampleCust,
        'party_phone' => '5000 0000',
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

$fmtMoney = static function ($n) use ($currencyUnit): string {
    $s = number_format((float) $n, 3, '.', ',');

    return $currencyUnit !== '' ? ($s . ' ' . $currencyUnit) : $s;
};

/* روابط مبدّل اللغة (نفس التوكن، lang مختلف). */
$langLinks = [];
foreach ($allowedLang as $lc) {
    $langLinks[$lc] = $preview
        ? (storefront_public_path('/pages/document.php') . '?' . http_build_query(['preview' => 1, 'lang' => $lc]))
        : orange_doc_public_relative_url($token, $lc);
}
$langLabels = ['ar' => 'العربية', 'en' => 'English', 'fil' => 'Filipino', 'hi' => 'हिन्दी'];

header('X-Robots-Tag: noindex, nofollow', true);
$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?php echo $esc($lang); ?>" dir="<?php echo $dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $esc($doc !== null ? ($tt($doc['doc_kind']) . ' — ' . $doc['serial']) : $tt('not_found')); ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; background: #f1f5f9; color: #0f172a; }
        .doc-wrap { max-width: 820px; margin: 0 auto; padding: 16px; }
        .doc-langbar { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; margin: 10px 0 16px; }
        .doc-langbar a { text-decoration: none; font-size: 0.85rem; padding: 6px 12px; border-radius: 999px; border: 1px solid #cbd5e1; color: #334155; background: #fff; }
        .doc-langbar a.is-active { background: #ea580c; color: #fff; border-color: #ea580c; font-weight: 700; }
        .doc-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 22px; box-shadow: 0 1px 3px rgba(15,23,42,.06); }
        .doc-brand { font-size: 1.5rem; font-weight: 800; color: #ea580c; letter-spacing: .5px; }
        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 2px solid #ea580c; padding-bottom: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .doc-type { font-size: 1.15rem; font-weight: 700; }
        .doc-meta { font-size: 0.9rem; color: #475569; line-height: 1.8; }
        .doc-meta b { color: #0f172a; }
        table.doc-lines { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.88rem; }
        table.doc-lines th { background: #ea580c; color: #fff; padding: 8px 6px; text-align: center; border: 1px solid #fff; font-weight: 700; }
        table.doc-lines td { padding: 7px 6px; border: 1px solid #e2e8f0; text-align: center; }
        table.doc-lines td.doc-name { text-align: <?php echo $isRtl ? 'right' : 'left'; ?>; }
        .doc-totals { margin-top: 14px; margin-<?php echo $isRtl ? 'left' : 'right'; ?>: 0; margin-<?php echo $isRtl ? 'right' : 'left'; ?>: auto; max-width: 320px; font-size: 0.92rem; }
        .doc-totals div { display: flex; justify-content: space-between; padding: 4px 0; }
        .doc-totals .doc-net { border-top: 2px solid #0f172a; margin-top: 4px; padding-top: 8px; font-weight: 800; font-size: 1.05rem; }
        .doc-empty { text-align: center; padding: 60px 20px; color: #64748b; }
        .num { direction: ltr; unicode-bidi: isolate; }
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
            <div class="doc-brand">ORANGE</div>
            <div class="doc-empty"><?php echo $esc($tt('not_found')); ?></div>
        </div>
    <?php else: ?>
        <div class="doc-card">
            <div class="doc-head">
                <div>
                    <div class="doc-brand">ORANGE</div>
                    <div class="doc-type"><?php echo $esc($tt($doc['doc_kind'])); ?></div>
                </div>
                <div class="doc-meta">
                    <div><?php echo $esc($tt('serial')); ?>: <b class="num"><?php echo $esc($doc['serial']); ?></b></div>
                    <?php if ($doc['date'] !== ''): ?><div><?php echo $esc($tt('date')); ?>: <b class="num"><?php echo $esc($doc['date']); ?></b></div><?php endif; ?>
                    <?php if (trim((string) $doc['party_name']) !== ''): ?>
                        <div><?php echo $esc($doc['party_kind'] === 'supplier' ? $tt('supplier') : $tt('customer')); ?>: <b><?php echo $esc($doc['party_name']); ?></b></div>
                    <?php endif; ?>
                    <?php if (trim((string) $doc['party_phone']) !== ''): ?>
                        <div><?php echo $esc($tt('phone')); ?>: <b class="num"><?php echo $esc($doc['party_phone']); ?></b></div>
                    <?php endif; ?>
                </div>
            </div>

            <table class="doc-lines">
                <thead>
                    <tr>
                        <th><?php echo $esc($tt('col_item')); ?></th>
                        <th><?php echo $esc($tt('col_variant')); ?></th>
                        <th><?php echo $esc($tt('col_qty')); ?></th>
                        <th><?php echo $esc($tt('col_price')); ?></th>
                        <th><?php echo $esc($tt('col_discount')); ?></th>
                        <th><?php echo $esc($tt('col_total')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($doc['lines'] as $ln): ?>
                        <tr>
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

            <div class="doc-totals">
                <div><span><?php echo $esc($tt('subtotal')); ?></span><span class="num"><?php echo $esc($fmtMoney($doc['subtotal'])); ?></span></div>
                <div><span><?php echo $esc($tt('discount_total')); ?></span><span class="num"><?php echo $esc($fmtMoney($doc['discount_total'])); ?></span></div>
                <div class="doc-net"><span><?php echo $esc($tt('net_total')); ?></span><span class="num"><?php echo $esc($fmtMoney($doc['net_total'])); ?></span></div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
