<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<array{id:int, code:string, name_ar:string, name_en:string, currency_code:string, sort_order:int, is_active:int}>
 */
function orange_countries_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'countries')) {
        return [];
    }
    $st = $pdo->query(
        'SELECT id, code, name_ar, name_en, currency_code, sort_order, is_active
         FROM countries ORDER BY sort_order ASC, id ASC'
    );

    return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * @return list<array{id:int, code:string, name:string, currency_code:string}>
 */
function orange_countries_storefront_active(PDO $pdo, string $lang): array
{
    if (!orange_table_exists($pdo, 'countries')) {
        return [];
    }
    $lang = preg_match('/^(ar|en|fil|hi)$/', $lang) ? $lang : 'en';
    $st = $pdo->query(
        'SELECT id, code, name_ar, name_en, currency_code
         FROM countries WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
    );
    if (!$st) {
        return [];
    }
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $ar = trim((string) ($row['name_ar'] ?? ''));
        $en = trim((string) ($row['name_en'] ?? ''));
        $name = $lang === 'ar' ? ($ar !== '' ? $ar : $en) : ($en !== '' ? $en : $ar);
        $out[] = [
            'id' => (int) $row['id'],
            'code' => strtolower(trim((string) ($row['code'] ?? ''))),
            'name' => $name,
            'currency_code' => strtoupper(trim((string) ($row['currency_code'] ?? ''))),
        ];
    }

    return $out;
}

function orange_storefront_country_cookie_name(): string
{
    return 'orange_sf_ctry';
}

function orange_countries_normalize_code(string $raw): string
{
    return strtolower((string) (preg_replace('/[^a-z0-9]/', '', trim($raw)) ?? ''));
}

/**
 * سجل الدول المعروفة: رمز، عملة، أسماء (مصدر واحد للاشتقاق التلقائي).
 *
 * @return array<string, array{currency:string, name_ar:string, name_en:string}>
 */
function orange_countries_catalog_registry(): array
{
    return [
        'kw' => ['currency' => 'KWD', 'name_ar' => 'الكويت', 'name_en' => 'Kuwait'],
        'eg' => ['currency' => 'EGP', 'name_ar' => 'مصر', 'name_en' => 'Egypt'],
        'ae' => ['currency' => 'AED', 'name_ar' => 'الإمارات', 'name_en' => 'United Arab Emirates'],
        'sa' => ['currency' => 'SAR', 'name_ar' => 'السعودية', 'name_en' => 'Saudi Arabia'],
        'bh' => ['currency' => 'BHD', 'name_ar' => 'البحرين', 'name_en' => 'Bahrain'],
        'qa' => ['currency' => 'QAR', 'name_ar' => 'قطر', 'name_en' => 'Qatar'],
        'om' => ['currency' => 'OMR', 'name_ar' => 'عُمان', 'name_en' => 'Oman'],
        'jo' => ['currency' => 'JOD', 'name_ar' => 'الأردن', 'name_en' => 'Jordan'],
        'lb' => ['currency' => 'LBP', 'name_ar' => 'لبنان', 'name_en' => 'Lebanon'],
        'iq' => ['currency' => 'IQD', 'name_ar' => 'العراق', 'name_en' => 'Iraq'],
        'ma' => ['currency' => 'MAD', 'name_ar' => 'المغرب', 'name_en' => 'Morocco'],
        'tn' => ['currency' => 'TND', 'name_ar' => 'تونس', 'name_en' => 'Tunisia'],
        'dz' => ['currency' => 'DZD', 'name_ar' => 'الجزائر', 'name_en' => 'Algeria'],
        'ly' => ['currency' => 'LYD', 'name_ar' => 'ليبيا', 'name_en' => 'Libya'],
        'sd' => ['currency' => 'SDG', 'name_ar' => 'السودان', 'name_en' => 'Sudan'],
        'ye' => ['currency' => 'YER', 'name_ar' => 'اليمن', 'name_en' => 'Yemen'],
    ];
}

/**
 * @return array<string, string>
 */
function orange_countries_currency_map(): array
{
    $map = [];
    foreach (orange_countries_catalog_registry() as $code => $row) {
        $map[$code] = $row['currency'];
    }

    return $map;
}

/** عملة تلقائية من رمز الدولة؛ فارغ إن لم يُعرَف الرمز في الخريطة. */
function orange_countries_currency_for_code(string $code): string
{
    $code = orange_countries_normalize_code($code);
    if ($code === '') {
        return '';
    }
    $map = orange_countries_currency_map();
    $cur = $map[$code] ?? '';

    return strtoupper($cur);
}

function orange_countries_sort_order_step(): int
{
    return 10;
}

/** ترتيب السجل التالي عند إضافة دولة (خطوة 10: 10، 20، 30، …). */
function orange_countries_next_sort_order(PDO $pdo): int
{
    $step = orange_countries_sort_order_step();
    if (!orange_table_exists($pdo, 'countries')) {
        return $step;
    }
    $max = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM countries')->fetchColumn();
    if ($max <= 0) {
        return $step;
    }

    return $max + $step;
}

function orange_countries_normalize_name_en_key(string $nameEn): string
{
    return strtolower((string) (preg_replace('/[^a-z0-9]/', '', trim($nameEn)) ?? ''));
}

/**
 * @return array<string, string>
 */
function orange_countries_en_code_aliases(): array
{
    return [
        'unitedarabemirates' => 'ae',
        'uae' => 'ae',
        'theemirates' => 'ae',
        'saudiarabia' => 'sa',
        'kingdomofsaudiarabia' => 'sa',
        'ksa' => 'sa',
    ];
}

/** رمز الدولة من الاسم العربي/الإنجليزي؛ فارغ إن لم يُعرَف في السجل. */
function orange_countries_code_for_names(string $nameAr, string $nameEn): string
{
    if (!function_exists('orange_normalize_arabic_name')) {
        require_once __DIR__ . '/arabic_name_duplicate.php';
    }
    $arNorm = orange_normalize_arabic_name(trim($nameAr));
    $enKey = orange_countries_normalize_name_en_key($nameEn);

    foreach (orange_countries_catalog_registry() as $code => $row) {
        if ($arNorm !== '' && orange_normalize_arabic_name((string) $row['name_ar']) === $arNorm) {
            return $code;
        }
        $rowEn = orange_countries_normalize_name_en_key((string) $row['name_en']);
        if ($enKey !== '' && $rowEn !== '' && $enKey === $rowEn) {
            return $code;
        }
    }

    if ($enKey !== '') {
        $aliases = orange_countries_en_code_aliases();
        if (isset($aliases[$enKey])) {
            return $aliases[$enKey];
        }
    }

    return '';
}

/**
 * @return array{id:int, code:string, name_ar:string, name_en:string, currency_code:string, is_active:int}|null
 */
function orange_country_row_by_code(PDO $pdo, string $code, bool $requireActive = false): ?array
{
    $code = orange_countries_normalize_code($code);
    if ($code === '' || !orange_table_exists($pdo, 'countries')) {
        return null;
    }
    $sql = 'SELECT id, code, name_ar, name_en, currency_code, sort_order, is_active FROM countries WHERE code = ?';
    if ($requireActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * @return array{id:int, code:string, name_ar:string, name_en:string, currency_code:string, is_active:int}|null
 */
function orange_country_row_by_id(PDO $pdo, int $id, bool $requireActive = false): ?array
{
    if ($id <= 0 || !orange_table_exists($pdo, 'countries')) {
        return null;
    }
    $sql = 'SELECT id, code, name_ar, name_en, currency_code, sort_order, is_active FROM countries WHERE id = ?';
    if ($requireActive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function orange_countries_default_id(PDO $pdo): int
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $row = orange_country_row_by_code($pdo, 'kw', false);
    if ($row !== null) {
        $memo = (int) $row['id'];

        return $memo;
    }
    if (orange_table_exists($pdo, 'countries')) {
        $id = $pdo->query('SELECT id FROM countries ORDER BY sort_order ASC, id ASC LIMIT 1')->fetchColumn();
        if ($id !== false) {
            $memo = (int) $id;

            return $memo;
        }
    }
    $memo = 0;

    return $memo;
}

function orange_storefront_read_saved_country_code(): ?string
{
    $name = orange_storefront_country_cookie_name();
    if (!isset($_COOKIE[$name])) {
        return null;
    }
    $code = orange_countries_normalize_code((string) $_COOKIE[$name]);

    return $code !== '' ? $code : null;
}

/**
 * كود الدولة الحالية للواجهة: ?country= ثم الكوكي ثم الكويت.
 */
function orange_storefront_current_country_code(PDO $pdo): string
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    if (isset($_GET['country']) && (string) $_GET['country'] !== '') {
        $fromGet = orange_countries_normalize_code((string) $_GET['country']);
        if ($fromGet !== '' && orange_country_row_by_code($pdo, $fromGet, true) !== null) {
            $memo = $fromGet;

            return $memo;
        }
    }
    $fromCookie = orange_storefront_read_saved_country_code();
    if ($fromCookie !== null && orange_country_row_by_code($pdo, $fromCookie, true) !== null) {
        $memo = $fromCookie;

        return $memo;
    }
    $def = orange_country_row_by_code($pdo, 'kw', true);
    if ($def !== null) {
        $memo = orange_countries_normalize_code((string) $def['code']);

        return $memo;
    }
    $active = orange_countries_storefront_active($pdo, 'en');
    if ($active !== []) {
        $memo = (string) $active[0]['code'];

        return $memo;
    }
    $memo = 'kw';

    return $memo;
}

function orange_storefront_current_country_id(PDO $pdo): int
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $code = orange_storefront_current_country_code($pdo);
    $row = orange_country_row_by_code($pdo, $code, true);
    if ($row !== null) {
        $memo = (int) $row['id'];

        return $memo;
    }
    $memo = orange_countries_default_id($pdo);

    return $memo;
}

function orange_storefront_send_country_cookie(string $countryCode): void
{
    $code = orange_countries_normalize_code($countryCode);
    if ($code === '') {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    setcookie(orange_storefront_country_cookie_name(), $code, [
        'expires' => time() + 3600 * 24 * 400,
        'path' => function_exists('orange_storefront_channel_cookie_path')
            ? orange_storefront_channel_cookie_path()
            : '/',
        'secure' => $https,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * تسمية الدولة للعرض حسب لغة الواجهة.
 *
 * @param array{name_ar?:string, name_en?:string} $row
 */
function orange_country_label_from_row(array $row, string $lang): string
{
    $ar = trim((string) ($row['name_ar'] ?? ''));
    $en = trim((string) ($row['name_en'] ?? ''));
    if ($lang === 'ar') {
        return $ar !== '' ? $ar : $en;
    }

    return $en !== '' ? $en : $ar;
}

/** سياق الدولة في الأدمن: ?admin_country= ثم الكويت. */
function orange_admin_context_country_id(PDO $pdo): int
{
    if (isset($_GET['admin_country']) && (string) $_GET['admin_country'] !== '') {
        $code = orange_countries_normalize_code((string) $_GET['admin_country']);
        $row = orange_country_row_by_code($pdo, $code, false);
        if ($row !== null) {
            return (int) $row['id'];
        }
    }

    return orange_countries_default_id($pdo);
}

/**
 * @return list<string>
 */
function orange_channel_kinds_allowed(): array
{
    return ['web', 'whatsapp', 'other'];
}

function orange_channel_kind_normalize(string $raw): string
{
    $k = strtolower(trim($raw));
    if (!in_array($k, orange_channel_kinds_allowed(), true)) {
        return 'other';
    }

    return $k;
}

/**
 * SQL fragment: channels.country_id = ? (no-op if column missing).
 */
function orange_channels_has_country_column(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'channels') && orange_table_has_column($pdo, 'channels', 'country_id');
}

function orange_delivery_areas_has_country_column(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'delivery_areas') && orange_table_has_column($pdo, 'delivery_areas', 'country_id');
}
