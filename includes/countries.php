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
    $code = strtolower((string) (preg_replace('/[^a-z0-9]/', '', trim($raw)) ?? ''));
    $legacy = [
        'ae' => 'uae',
        'sa' => 'ksa',
    ];

    return $legacy[$code] ?? $code;
}

/** عرض الرمز بأحرف كبيرة (UAE, KSA, KW, …). */
function orange_countries_display_code(string $code): string
{
    $code = orange_countries_normalize_code($code);

    return $code !== '' ? strtoupper($code) : '';
}

/**
 * سجل الدول المعروفة: رمز السوق (اختصار معروف)، عملة، أسماء.
 * أمثلة: KW، EG، UAE، KSA — وليس ae/sa الداخلية ISO alpha-2.
 *
 * @return array<string, array{currency:string, name_ar:string, name_en:string}>
 */
function orange_countries_catalog_registry(): array
{
    return [
        'kw' => ['currency' => 'KWD', 'name_ar' => 'الكويت', 'name_en' => 'Kuwait'],
        'eg' => ['currency' => 'EGP', 'name_ar' => 'مصر', 'name_en' => 'Egypt'],
        'uae' => ['currency' => 'AED', 'name_ar' => 'الإمارات', 'name_en' => 'United Arab Emirates'],
        'ksa' => ['currency' => 'SAR', 'name_ar' => 'السعودية', 'name_en' => 'Saudi Arabia'],
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
        'tr' => ['currency' => 'TRY', 'name_ar' => 'تركيا', 'name_en' => 'Turkey'],
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
    return 1;
}

/** ترتيب السجل التالي عند إضافة دولة (MAX + 1). */
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
        'unitedarabemirates' => 'uae',
        'uae' => 'uae',
        'theemirates' => 'uae',
        'emirates' => 'uae',
        'saudiarabia' => 'ksa',
        'kingdomofsaudiarabia' => 'ksa',
        'ksa' => 'ksa',
        'turkey' => 'tr',
        'turkiye' => 'tr',
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
 * كود الدولة الحالية للواجهة: ?country= ثم قناة الطلب (§13.7) ثم الكوكي ثم الافتراضي.
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
    if (function_exists('orange_storefront_country_code_from_request_channel')) {
        $fromChannel = orange_storefront_country_code_from_request_channel($pdo);
        if ($fromChannel !== null && $fromChannel !== ''
            && orange_country_row_by_code($pdo, $fromChannel, true) !== null) {
            $memo = $fromChannel;

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

/** سياق الدولة في الأدمن: ?admin_country= ثم كوكي orange_ad_country ثم الكويت. */
function orange_admin_country_cookie_name(): string
{
    return 'orange_ad_country';
}

function orange_admin_read_saved_country_code(): ?string
{
    $name = orange_admin_country_cookie_name();
    if (!isset($_COOKIE[$name])) {
        return null;
    }
    $code = orange_countries_normalize_code((string) $_COOKIE[$name]);

    return $code !== '' ? $code : null;
}

function orange_admin_send_country_cookie(string $countryCode): void
{
    $code = orange_countries_normalize_code($countryCode);
    if ($code === '' || headers_sent()) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    setcookie(orange_admin_country_cookie_name(), $code, [
        'expires' => time() + 3600 * 24 * 400,
        'path' => '/',
        'secure' => $https,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/** دولة مقفلة على جلسة الأدمن (فريق دولة — بند 13.8). */
function orange_admin_session_locked_country_id(): int
{
    if (empty($_SESSION['admin_country_lock'])) {
        return 0;
    }

    return (int) $_SESSION['admin_country_lock'];
}

function orange_admin_is_global(?array $admin): bool
{
    if (is_array($admin) && array_key_exists('country_id', $admin)) {
        return (int) ($admin['country_id'] ?? 0) <= 0;
    }

    return orange_admin_session_locked_country_id() <= 0;
}

/**
 * SQL آمن للاستعلامات بدون prepare: AND alias.country_id = N
 */
function orange_sql_country_and_fragment(PDO $pdo, string $table, string $alias, int $countryId): string
{
    if ($countryId <= 0 || !orange_table_has_country_id($pdo, $table)) {
        return '';
    }
    $col = trim($alias) !== '' ? trim($alias) . '.country_id' : $table . '.country_id';

    return ' AND ' . $col . ' = ' . (int) $countryId;
}

/**
 * رمز الدولة من الطلب الحالي (GET / رأس API / POST form) — دون قراءة php://input.
 */
function orange_admin_peek_country_code_from_request(): ?string
{
    if (isset($_GET['admin_country']) && (string) $_GET['admin_country'] !== '') {
        $code = orange_countries_normalize_code((string) $_GET['admin_country']);

        return $code !== '' ? $code : null;
    }
    if (!empty($_SERVER['HTTP_X_ORANGE_ADMIN_COUNTRY'])) {
        $code = orange_countries_normalize_code((string) $_SERVER['HTTP_X_ORANGE_ADMIN_COUNTRY']);

        return $code !== '' ? $code : null;
    }
    if (isset($_POST['admin_country']) && (string) $_POST['admin_country'] !== '') {
        $code = orange_countries_normalize_code((string) $_POST['admin_country']);

        return $code !== '' ? $code : null;
    }

    return null;
}

function orange_admin_store_country_context(PDO $pdo, string $code, int $countryId): int
{
    if ($countryId <= 0) {
        return orange_countries_default_id($pdo);
    }
    orange_admin_send_country_cookie($code);
    $_SESSION['admin_country_ctx'] = $countryId;
    $GLOBALS['orange_admin_ctx_country_id'] = $countryId;
    $GLOBALS['orange_admin_ctx_country_code'] = orange_countries_normalize_code($code);

    return $countryId;
}

/**
 * يثبت سياق الدولة مبكراً (صفحات الأدمن وواجهات API).
 * الأولوية: GET/رأس/POST → كوكي → جلسة → الكويت الافتراضية.
 */
function orange_admin_bootstrap_country_context(PDO $pdo): int
{
    if (isset($GLOBALS['orange_admin_ctx_country_id']) && (int) $GLOBALS['orange_admin_ctx_country_id'] > 0) {
        return (int) $GLOBALS['orange_admin_ctx_country_id'];
    }
    $locked = orange_admin_session_locked_country_id();
    if ($locked > 0) {
        $GLOBALS['orange_admin_ctx_country_id'] = $locked;

        return $locked;
    }

    $fromRequest = orange_admin_peek_country_code_from_request();
    if ($fromRequest !== null) {
        $row = orange_country_row_by_code($pdo, $fromRequest, false);
        if ($row !== null) {
            return orange_admin_store_country_context($pdo, $fromRequest, (int) $row['id']);
        }
    }

    $fromCookie = orange_admin_read_saved_country_code();
    if ($fromCookie !== null) {
        $row = orange_country_row_by_code($pdo, $fromCookie, false);
        if ($row !== null) {
            return orange_admin_store_country_context($pdo, $fromCookie, (int) $row['id']);
        }
    }

    if (!empty($_SESSION['admin_country_ctx'])) {
        $sessCid = (int) $_SESSION['admin_country_ctx'];
        if ($sessCid > 0) {
            $sessRow = orange_country_row_by_id($pdo, $sessCid, false);
            if ($sessRow !== null) {
                $code = orange_countries_normalize_code((string) ($sessRow['code'] ?? ''));
                $GLOBALS['orange_admin_ctx_country_id'] = $sessCid;
                $GLOBALS['orange_admin_ctx_country_code'] = $code;

                return $sessCid;
            }
            unset($_SESSION['admin_country_ctx']);
        }
    }

    $defaultId = orange_countries_default_id($pdo);
    $GLOBALS['orange_admin_ctx_country_id'] = $defaultId;

    return $defaultId;
}

function orange_admin_context_country_id(PDO $pdo): int
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    if (isset($GLOBALS['orange_admin_ctx_country_id']) && (int) $GLOBALS['orange_admin_ctx_country_id'] > 0) {
        $memo = (int) $GLOBALS['orange_admin_ctx_country_id'];

        return $memo;
    }
    $memo = orange_admin_bootstrap_country_context($pdo);

    return $memo;
}

function orange_admin_context_country_code(PDO $pdo): string
{
    if (!empty($GLOBALS['orange_admin_ctx_country_code'])) {
        return orange_countries_normalize_code((string) $GLOBALS['orange_admin_ctx_country_code']);
    }
    $id = orange_admin_context_country_id($pdo);
    if ($id <= 0) {
        return 'kw';
    }
    $row = orange_country_row_by_id($pdo, $id, false);
    if ($row === null) {
        return 'kw';
    }

    return orange_countries_normalize_code((string) ($row['code'] ?? 'kw'));
}

/** هل أعمدة country_id جاهزة لفصل البيانات per country في الأدمن؟ */
function orange_admin_country_scope_ready(PDO $pdo): bool
{
    return orange_table_has_country_id($pdo, 'suppliers')
        && orange_table_has_country_id($pdo, 'customers')
        && orange_table_has_country_id($pdo, 'accounts');
}

/**
 * يلحق admin_country= بروابط الأدمن (أدمن عام) لثبات السياق مع التنقل.
 */
function orange_admin_href_with_country(string $href, string $countryCode, int $lockedCountryId = 0): string
{
    if ($lockedCountryId > 0) {
        return $href;
    }
    $code = orange_countries_normalize_code($countryCode);
    if ($code === '') {
        return $href;
    }
    if (str_contains($href, 'admin_country=')) {
        return $href;
    }
    $sep = str_contains($href, '?') ? '&' : '?';

    return $href . $sep . 'admin_country=' . rawurlencode($code);
}

function orange_admin_public_href_with_country(string $href, string $countryCode, int $lockedCountryId = 0): string
{
    return storefront_public_path(orange_admin_href_with_country($href, $countryCode, $lockedCountryId));
}

function orange_table_has_country_id(PDO $pdo, string $table): bool
{
    return orange_table_exists($pdo, $table) && orange_table_has_column($pdo, $table, 'country_id');
}

/**
 * @return array{sql:string, param:int}|null
 */
function orange_sql_filter_country_id(PDO $pdo, string $table, string $alias, int $countryId): ?array
{
    if ($countryId <= 0 || !orange_table_has_country_id($pdo, $table)) {
        return null;
    }
    $col = trim($alias) !== '' ? trim($alias) . '.country_id' : $table . '.country_id';

    return ['sql' => ' AND ' . $col . ' = ?', 'param' => $countryId];
}

/**
 * @return array{sql:string,params:list<int>}|null
 */
function orange_accounts_sql_country_filter(PDO $pdo, string $alias = 'a', ?int $countryId = null): ?array
{
    if ($countryId === null) {
        $countryId = orange_admin_context_country_id($pdo);
    }
    if ($countryId <= 0 || !orange_table_has_country_id($pdo, 'accounts')) {
        return null;
    }
    $col = trim($alias) !== '' ? trim($alias) . '.country_id' : 'accounts.country_id';

    return ['sql' => ' AND ' . $col . ' = ?', 'params' => [$countryId]];
}

function orange_accounts_resolve_country_id_for_write(PDO $pdo, ?int $parentId): int
{
    if ($parentId !== null && $parentId > 0 && orange_table_has_country_id($pdo, 'accounts')) {
        $st = $pdo->prepare('SELECT country_id FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$parentId]);
        $pid = (int) ($st->fetchColumn() ?: 0);
        if ($pid > 0) {
            return $pid;
        }
    }
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx > 0) {
        return $ctx;
    }

    return orange_countries_default_id($pdo);
}

function orange_account_country_id(PDO $pdo, int $accountId): int
{
    if ($accountId <= 0 || !orange_table_has_country_id($pdo, 'accounts')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT country_id FROM accounts WHERE id = ? LIMIT 1');
    $st->execute([$accountId]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * @throws RuntimeException
 */
function orange_gl_assert_voucher_accounts_country(PDO $pdo, array $accountIds, int $voucherCountryId): void
{
    if ($voucherCountryId <= 0 || !orange_table_has_country_id($pdo, 'accounts')) {
        return;
    }
    $accountIds = array_values(array_unique(array_filter(
        array_map(static fn ($id): int => (int) $id, $accountIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($accountIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($accountIds), '?'));
    $st = $pdo->prepare(
        'SELECT id FROM accounts WHERE id IN (' . $ph . ') AND country_id = ?'
    );
    $st->execute(array_merge($accountIds, [$voucherCountryId]));
    $ok = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ok) !== count($accountIds)) {
        throw new RuntimeException('أحد حسابات السند لا يتبع الدولة المختارة في لوحة التحكم.');
    }
}

function orange_country_id_for_channel(PDO $pdo, int $channelId): int
{
    if ($channelId <= 0) {
        return orange_storefront_current_country_id($pdo);
    }
    if (orange_channels_has_country_column($pdo)) {
        $st = $pdo->prepare('SELECT country_id FROM channels WHERE id = ? LIMIT 1');
        $st->execute([$channelId]);
        $cid = (int) ($st->fetchColumn() ?: 0);
        if ($cid > 0) {
            return $cid;
        }
    }

    return orange_storefront_current_country_id($pdo);
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

/**
 * بند 13.9 — تهيئة تشغيلية عند تفعيل دولة: مخزن افتراضي + قناة ويب (idempotent).
 *
 * @return array{warehouse_id:int, channel_id:int, created_warehouse:bool, created_channel:bool}
 */
function orange_country_provision_operational(PDO $pdo, int $countryId): array
{
    $out = [
        'warehouse_id' => 0,
        'channel_id' => 0,
        'created_warehouse' => false,
        'created_channel' => false,
    ];
    if ($countryId <= 0) {
        return $out;
    }
    require_once __DIR__ . '/warehouses.php';
    $row = orange_country_row_by_id($pdo, $countryId, false);
    if ($row === null) {
        return $out;
    }

    $whBefore = 0;
    if (orange_table_exists($pdo, 'warehouses')) {
        $stWh = $pdo->prepare('SELECT id FROM warehouses WHERE country_id = ? LIMIT 1');
        $stWh->execute([$countryId]);
        $whBefore = (int) ($stWh->fetchColumn() ?: 0);
    }
    $wid = orange_warehouse_ensure_default_for_country($pdo, $countryId);
    $out['warehouse_id'] = $wid;
    $out['created_warehouse'] = $whBefore <= 0 && $wid > 0;

    if (!orange_table_exists($pdo, 'channels') || !orange_channels_has_country_column($pdo)) {
        return $out;
    }
    $stCh = $pdo->prepare('SELECT id FROM channels WHERE country_id = ? ORDER BY id ASC LIMIT 1');
    $stCh->execute([$countryId]);
    $existingCh = (int) ($stCh->fetchColumn() ?: 0);
    if ($existingCh > 0) {
        $out['channel_id'] = $existingCh;
    } else {
        $code = orange_countries_normalize_code((string) ($row['code'] ?? ''));
        $nameAr = trim((string) ($row['name_ar'] ?? ''));
        $nameEn = trim((string) ($row['name_en'] ?? ''));
        $chName = $nameAr !== '' ? ($nameAr . ' — ويب') : ($nameEn !== '' ? $nameEn . ' — web' : 'Web');
        $slug = $code !== '' ? $code . '-web' : ('c' . $countryId . '-web');
        $pathSegment = 'web';

        $dupSlug = $pdo->prepare('SELECT id FROM channels WHERE country_id = ? AND slug = ? LIMIT 1');
        $dupSlug->execute([$countryId, $slug]);
        if ($dupSlug->fetchColumn()) {
            $slug = $slug . '-' . $countryId;
        }

        $defaultWhNum = 1;

        if (orange_table_has_column($pdo, 'channels', 'channel_kind')) {
            $ins = $pdo->prepare(
                'INSERT INTO channels (name, slug, path_segment, logo, whatsapp_number, warehouse_number, is_active, country_id, channel_kind)
                 VALUES (?, ?, ?, \'\', \'\', ?, 1, ?, \'web\')'
            );
            $ins->execute([$chName, $slug, $pathSegment, $defaultWhNum, $countryId]);
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO channels (name, slug, path_segment, logo, whatsapp_number, warehouse_number, is_active, country_id)
                 VALUES (?, ?, ?, \'\', \'\', ?, 1, ?)'
            );
            $ins->execute([$chName, $slug, $pathSegment, $defaultWhNum, $countryId]);
        }
        $cid = (int) $pdo->lastInsertId();
        $out['channel_id'] = $cid;
        $out['created_channel'] = $cid > 0;
    }

    require_once __DIR__ . '/country_catalog_copy.php';
    $out['catalog_copy'] = orange_country_copy_catalog_from_source($pdo, $countryId);

    return $out;
}

function orange_product_country_id(PDO $pdo, int $productId): int
{
    if ($productId <= 0) {
        return orange_countries_default_id($pdo);
    }
    if (!orange_table_has_country_id($pdo, 'products')) {
        return orange_countries_default_id($pdo);
    }
    $st = $pdo->prepare('SELECT country_id FROM products WHERE id = ? LIMIT 1');
    $st->execute([$productId]);
    $cid = (int) ($st->fetchColumn() ?: 0);

    return $cid > 0 ? $cid : orange_countries_default_id($pdo);
}

function orange_country_document_ref(PDO $pdo, string $prefix, int $serial, int $countryId): string
{
    $prefix = strtoupper(trim($prefix));
    if ($countryId > 0) {
        $row = orange_country_row_by_id($pdo, $countryId, false);
        if ($row !== null) {
            $code = strtoupper(orange_countries_normalize_code((string) ($row['code'] ?? '')));
            if ($code !== '') {
                return $prefix . '-' . $code . '-' . $serial;
            }
        }
    }

    return $prefix . '-' . $serial;
}

/**
 * @throws RuntimeException
 */
function orange_admin_assert_entity_country(PDO $pdo, string $table, int $entityId): void
{
    $allowed = ['orders', 'customers', 'suppliers', 'purchases', 'products', 'journal_vouchers', 'accounts'];
    if (!in_array($table, $allowed, true) || $entityId <= 0 || !orange_table_has_country_id($pdo, $table)) {
        return;
    }
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx <= 0) {
        return;
    }
    $st = $pdo->prepare('SELECT country_id FROM `' . $table . '` WHERE id = ? LIMIT 1');
    $st->execute([$entityId]);
    $rowCid = (int) ($st->fetchColumn() ?: 0);
    if ($rowCid > 0 && $rowCid !== $ctx) {
        throw new RuntimeException('السجل لا يتبع الدولة المختارة في لوحة التحكم.');
    }
}

/**
 * @throws RuntimeException
 */
function orange_admin_assert_row_country(PDO $pdo, string $table, int $rowId): void
{
    if ($rowId <= 0 || !orange_table_has_country_id($pdo, $table)) {
        return;
    }
    $ctx = orange_admin_context_country_id($pdo);
    if ($ctx <= 0) {
        return;
    }
    $st = $pdo->prepare('SELECT country_id FROM `' . $table . '` WHERE id = ? LIMIT 1');
    $st->execute([$rowId]);
    $rowCid = (int) ($st->fetchColumn() ?: 0);
    if ($rowCid > 0 && $rowCid !== $ctx) {
        throw new RuntimeException('السجل لا يتبع الدولة المختارة في لوحة التحكم.');
    }
}
