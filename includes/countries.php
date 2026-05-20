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
 * رموز ISO 4217 الافتراضية لرمز الدولة (حروف صغيرة) — يُوسَّع عند فتح أسواق جديدة.
 *
 * @return array<string, string>
 */
function orange_countries_currency_map(): array
{
    return [
        'kw' => 'KWD',
        'eg' => 'EGP',
        'ae' => 'AED',
        'sa' => 'SAR',
        'bh' => 'BHD',
        'qa' => 'QAR',
        'om' => 'OMR',
        'jo' => 'JOD',
        'lb' => 'LBP',
        'iq' => 'IQD',
        'ma' => 'MAD',
        'tn' => 'TND',
        'dz' => 'DZD',
        'ly' => 'LYD',
        'sd' => 'SDG',
        'ye' => 'YER',
    ];
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

/** ترتيب السجل التالي عند إضافة دولة (MAX + 1). */
function orange_countries_next_sort_order(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'countries')) {
        return 1;
    }
    $next = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM countries')->fetchColumn();

    return $next > 0 ? $next : 1;
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
