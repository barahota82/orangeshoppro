<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/upload_paths.php';
require_once __DIR__ . '/../includes/countries.php';
require_once __DIR__ . '/../includes/storefront_country_store.php';
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/orange_storefront_customer_lang.php';
require_once __DIR__ . '/../includes/storefront_entry.php';

$pdo = db();
orange_catalog_ensure_storefront_read_bootstrap($pdo);

$pickerPage = 'home';
$pickerExtra = [];
$pickerLangRequested = function_exists('current_lang') ? current_lang() : 'ar';
if (!empty($GLOBALS['orange_sf_picker_page']) && is_string($GLOBALS['orange_sf_picker_page'])) {
    $allowedPages = ['home', 'cart', 'track', 'product', 'register', 'verify_email'];
    $cand = (string) $GLOBALS['orange_sf_picker_page'];
    if (in_array($cand, $allowedPages, true)) {
        $pickerPage = $cand;
    }
}
if (!empty($GLOBALS['orange_sf_picker_extra']) && is_array($GLOBALS['orange_sf_picker_extra'])) {
    if (isset($GLOBALS['orange_sf_picker_extra']['id']) && (int) $GLOBALS['orange_sf_picker_extra']['id'] > 0) {
        $pickerExtra['id'] = (int) $GLOBALS['orange_sf_picker_extra']['id'];
    }
    if (isset($GLOBALS['orange_sf_picker_extra']['token']) && is_string($GLOBALS['orange_sf_picker_extra']['token'])) {
        $tok = trim($GLOBALS['orange_sf_picker_extra']['token']);
        if ($tok !== '' && preg_match('/^[A-Za-z0-9._\-]{8,128}$/', $tok) === 1) {
            $pickerExtra['token'] = $tok;
        }
    }
}
if (!empty($GLOBALS['orange_sf_picker_lang']) && is_string($GLOBALS['orange_sf_picker_lang'])) {
    // احتفظ باللغة المطلوبة كما وصلت (لا حصر ar/en/fil/hi)؛ التقييم لاحقاً لكل دولة مستهدفة.
    $pl = orange_storefront_normalize_lang_only_code((string) $GLOBALS['orange_sf_picker_lang']);
    if ($pl !== null) {
        $pickerLangRequested = $pl;
    }
}

$lang = $pickerLangRequested;
$options = orange_countries_storefront_active($pdo, $lang);
$title = $lang === 'ar' ? 'اختر دولة التسوق' : 'Choose shopping country';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo $lang === 'ar' ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/css/main.css')), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="storefront-country-select">
<main class="container" style="max-width:28rem;margin:3rem auto;padding:1.5rem;">
    <h1 style="font-size:1.35rem;margin-bottom:1rem;"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <ul style="list-style:none;padding:0;margin:0;display:grid;gap:0.75rem;">
        <?php foreach ($options as $opt) {
            $code = orange_countries_normalize_code((string) ($opt['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $cid = (int) ($opt['id'] ?? 0);
            // أدوار الدولة المستهدفة ∩ اللغات الجاهزة — لا حارس الدولة الحالية.
            $hrefLang = $cid > 0
                ? orange_storefront_entry_select_public_lang($pdo, $cid, [$pickerLangRequested])
                : $pickerLangRequested;
            $href = htmlspecialchars(storefront_url($pickerPage, $code, $hrefLang, $pickerExtra), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string) ($opt['name'] ?? $code), ENT_QUOTES, 'UTF-8');
            ?>
        <li>
            <a class="btn-primary" style="display:block;text-align:center;padding:0.85rem 1rem;"
               href="<?php echo $href; ?>"
               data-country-code="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>"
               data-href-lang="<?php echo htmlspecialchars($hrefLang, ENT_QUOTES, 'UTF-8'); ?>"
               data-requested-lang="<?php echo htmlspecialchars($pickerLangRequested, ENT_QUOTES, 'UTF-8'); ?>"
            ><?php echo $label; ?></a>
        </li>
        <?php } ?>
    </ul>
</main>
</body>
</html>
