<?php
declare(strict_types=1);

/**
 * Isolated country language roles. One table, four meanings:
 * base, content, admin_ui, customer. Not a tenant model.
 * Does not open a project database and does not load config.php.
 */

const ORANGE_CONTENT_ADMIN_UI_READY = ['ar', 'en', 'fil', 'hi'];
const ORANGE_DEPT_NAME_COLUMNS = [
    'ar' => 'name_ar',
    'en' => 'name_en',
    'fil' => 'name_fil',
    'hi' => 'name_hi',
];

function orange_content_locale_code(string $raw): ?string
{
    $c = strtolower(trim($raw));
    if (!preg_match('/^[a-z]{2,3}$/', $c)) {
        return null;
    }
    return $c;
}

function orange_content_text_hash(string $text): string
{
    return hash('sha256', $text);
}

function orange_content_languages_install_sqlite(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS orange_country_locale_role (
        country_id INTEGER NOT NULL,
        locale_code TEXT NOT NULL,
        role TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        UNIQUE (country_id, locale_code, role)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS orange_content_locale_text (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        entity_kind TEXT NOT NULL,
        entity_id INTEGER NOT NULL,
        field_key TEXT NOT NULL,
        locale_code TEXT NOT NULL,
        text_value TEXT NOT NULL,
        origin TEXT NOT NULL,
        source_locale TEXT NULL,
        source_hash TEXT NULL,
        UNIQUE (entity_kind, entity_id, field_key, locale_code)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_ar TEXT NOT NULL DEFAULT \'\',
        name_en TEXT NOT NULL DEFAULT \'\',
        name_fil TEXT NOT NULL DEFAULT \'\',
        name_hi TEXT NOT NULL DEFAULT \'\',
        slug TEXT NOT NULL DEFAULT \'\',
        is_active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS countries (
        id INTEGER PRIMARY KEY,
        sort_order INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS department_countries (
        department_id INTEGER NOT NULL,
        country_id INTEGER NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 0,
        UNIQUE (department_id, country_id)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS orange_language_reference (
        locale_code TEXT PRIMARY KEY,
        is_active INTEGER NOT NULL DEFAULT 0
    )');
}

function orange_language_reference_active_codes(PDO $pdo): array
{
    $st = $pdo->query('SELECT locale_code FROM orange_language_reference WHERE is_active = 1 ORDER BY locale_code ASC');
    $out = [];
    foreach ($st ? $st->fetchAll(PDO::FETCH_COLUMN) : [] as $code) {
        $locale = orange_content_locale_code((string) $code);
        if ($locale !== null && orange_language_catalog_stored_code_operational($locale)) {
            $out[] = $locale;
        }
    }
    return $out;
}

function orange_language_reference_is_active(PDO $pdo, string $locale): bool
{
    $status = orange_language_catalog_support_status($locale);
    if ($status === 'unresolved') {
        throw new RuntimeException('operational_list_unresolved');
    }
    if ($status !== 'allow') {
        return false;
    }
    $st = $pdo->prepare('SELECT is_active FROM orange_language_reference WHERE locale_code = ?');
    $st->execute([$locale]);

    return (int) $st->fetchColumn() === 1;
}

function orange_country_locale_roles_replace(PDO $pdo, int $countryId, array $assignments): void
{
    if ($countryId <= 0) {
        throw new InvalidArgumentException('country_required');
    }
    $rows = [];
    $baseCount = 0;
    foreach ($assignments as $item) {
        $locale = orange_content_locale_code((string) ($item['locale'] ?? ''));
        $role = (string) ($item['role'] ?? '');
        if ($locale === null || !in_array($role, ['base', 'content', 'admin_ui', 'customer'], true)) {
            throw new InvalidArgumentException('role_invalid');
        }
        $status = orange_language_catalog_support_status($locale);
        if ($status === 'unresolved') {
            throw new RuntimeException('operational_list_unresolved');
        }
        if ($status !== 'allow') {
            throw new RuntimeException('removed_locale_refused');
        }
        if (!orange_language_reference_is_active($pdo, $locale)) {
            throw new RuntimeException('locale_not_active');
        }
        if ($role === 'admin_ui' && !in_array($locale, ORANGE_CONTENT_ADMIN_UI_READY, true)) {
            throw new RuntimeException('admin_ui_not_ready');
        }
        if ($role === 'base') {
            $baseCount++;
        }
        $rows[] = [$locale, $role, (int) ($item['sort_order'] ?? 0)];
    }
    if ($baseCount > 1) {
        throw new RuntimeException('multiple_base');
    }
    if ($baseCount !== 1) {
        throw new RuntimeException('base_required');
    }
    $bases = [];
    $content = [];
    foreach ($rows as $row) {
        if ($row[1] === 'base') {
            $bases[] = $row[0];
        }
        if ($row[1] === 'content') {
            $content[] = $row[0];
        }
    }
    if (!in_array($bases[0], $content, true)) {
        throw new RuntimeException('base_not_in_content');
    }
    if ($bases[0] !== 'en' && !orange_language_reference_is_active($pdo, 'en')) {
        throw new RuntimeException('english_reference_inactive');
    }
    $baseLook = $pdo->prepare('SELECT locale_code FROM orange_country_locale_role WHERE country_id = ? AND role = ?');
    $baseLook->execute([$countryId, 'base']);
    foreach ($baseLook->fetchAll(PDO::FETCH_COLUMN) ?: [] as $existingBase) {
        $existingStatus = orange_language_catalog_support_status((string) $existingBase);
        if ($existingStatus === 'unresolved') {
            throw new RuntimeException('operational_list_unresolved');
        }
        if ($existingStatus === 'removed') {
            throw new RuntimeException('removed_base_unresolved');
        }
    }
    $operational = orange_language_catalog_operational_codes();
    if ($operational === []) {
        throw new RuntimeException('operational_list_unresolved');
    }
    $pdo->beginTransaction();
    try {
        $marks = implode(',', array_fill(0, count($operational), '?'));
        $del = $pdo->prepare('DELETE FROM orange_country_locale_role WHERE country_id = ? AND locale_code IN (' . $marks . ')');
        $del->execute(array_merge([$countryId], $operational));
        $ins = $pdo->prepare('INSERT INTO orange_country_locale_role (country_id, locale_code, role, sort_order) VALUES (?, ?, ?, ?)');
        foreach ($rows as $row) {
            $ins->execute([$countryId, $row[0], $row[1], $row[2]]);
        }
        $check = $pdo->prepare('SELECT COUNT(*) FROM orange_country_locale_role WHERE country_id = ? AND role = ?');
        $check->execute([$countryId, 'base']);
        if ((int) $check->fetchColumn() !== 1) {
            throw new RuntimeException('base_required');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * A disabled unready customer box is not an instruction to delete the stored role.
 * A new unready customer role is not activated. Ready customer roles follow the
 * submitted checkboxes. A stored role outside the operational list stays in
 * place: it is not deleted here and it is not written again as a new acceptance.
 * An explicit assignment of a removed code is refused before any delete.
 *
 * @param list<array<string,mixed>> $assignments
 * @param list<mixed> $explicitDrops
 * @return list<array<string,mixed>>
 */
function orange_country_locale_customer_save_policy(PDO $pdo, int $countryId, array $assignments, array $explicitDrops): array
{
    $ready = array_fill_keys(function_exists('orange_storefront_ui_live_ready_locales') ? orange_storefront_ui_live_ready_locales() : [], true);
    $drops = [];
    foreach ($explicitDrops as $raw) {
        $code = orange_content_locale_code((string) $raw);
        if ($code !== null) {
            $drops[$code] = true;
        }
    }
    $existing = [];
    $read = orange_country_locale_roles_read($pdo, $countryId);
    foreach (is_array($read['customer'] ?? null) ? $read['customer'] : [] as $code) {
        $existing[(string) $code] = true;
    }
    $out = [];
    $keptCustomer = [];
    foreach ($assignments as $item) {
        if (!is_array($item)) {
            continue;
        }
        $locale = orange_content_locale_code((string) ($item['locale'] ?? ''));
        $role = (string) ($item['role'] ?? '');
        if ($locale !== null) {
            $status = orange_language_catalog_support_status($locale);
            if ($status === 'unresolved') {
                throw new RuntimeException('operational_list_unresolved');
            }
            if ($status === 'removed') {
                throw new RuntimeException('removed_locale_refused');
            }
        }
        if ($locale === null || $role !== 'customer') {
            $out[] = $item;
            continue;
        }
        if (isset($drops[$locale])) {
            continue;
        }
        if (!isset($ready[$locale]) && !isset($existing[$locale])) {
            continue;
        }
        $out[] = $item;
        $keptCustomer[$locale] = true;
    }
    foreach (array_keys($existing) as $locale) {
        if (orange_language_catalog_support_status((string) $locale) !== 'allow') {
            continue;
        }
        if (isset($ready[$locale]) || isset($drops[$locale]) || isset($keptCustomer[$locale])) {
            continue;
        }
        $out[] = ['locale' => $locale, 'role' => 'customer', 'sort_order' => 0];
    }

    return $out;
}

function orange_country_locale_customer_wired(string $mode): bool
{
    return $mode !== 'legacy_unconfigured';
}

function orange_country_locale_roles_read(PDO $pdo, int $countryId): array
{
    $st = $pdo->prepare('SELECT locale_code, role, sort_order FROM orange_country_locale_role WHERE country_id = ? ORDER BY sort_order ASC, locale_code ASC');
    $st->execute([$countryId]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($all === []) {
        return [
            'mode' => 'legacy_unconfigured',
            'base' => null,
            'content' => [],
            'admin_ui' => [],
            'customer' => [],
            'customer_wired' => orange_country_locale_customer_wired('legacy_unconfigured'),
        ];
    }
    $bucket = ['base' => [], 'content' => [], 'admin_ui' => [], 'customer' => []];
    foreach ($all as $row) {
        $role = (string) $row['role'];
        if (isset($bucket[$role])) {
            $bucket[$role][] = (string) $row['locale_code'];
        }
    }
    if (count($bucket['base']) > 1) {
        return [
            'mode' => 'invalid_multiple_base',
            'base' => null,
            'content' => $bucket['content'],
            'admin_ui' => $bucket['admin_ui'],
            'customer' => $bucket['customer'],
            'customer_wired' => orange_country_locale_customer_wired('invalid_multiple_base'),
        ];
    }
    if (count($bucket['base']) === 0) {
        return [
            'mode' => 'no_base',
            'base' => null,
            'content' => $bucket['content'],
            'admin_ui' => $bucket['admin_ui'],
            'customer' => $bucket['customer'],
            'customer_wired' => orange_country_locale_customer_wired('no_base'),
        ];
    }
    return [
        'mode' => 'configured',
        'base' => $bucket['base'][0],
        'content' => $bucket['content'],
        'admin_ui' => $bucket['admin_ui'],
        'customer' => $bucket['customer'],
        'customer_wired' => orange_country_locale_customer_wired('configured'),
    ];
}

function orange_content_panel_locales(array $roles, array $activeLocales = []): array
{
    if (($roles['mode'] ?? '') !== 'configured' || !is_string($roles['base'] ?? null)) {
        return [];
    }
    $base = $roles['base'];
    $active = array_values(array_unique($activeLocales));
    $out = [];
    if ($base !== 'en' && in_array('en', $active, true)) {
        $out[] = 'en';
    }
    foreach ($roles['content'] as $code) {
        if ($code === $base || $code === 'en') {
            continue;
        }
        $out[] = $code;
    }
    return array_values(array_unique($out));
}

/**
 * Active rows of orange_language_reference. Not an invented ISO list.
 * Rich columns match the brand-identity contract when they are present.
 * A thin table keeps codes only and does not invent native names.
 * An absent table does not fall back to the four-language slogan seed.
 * This read does not create or fill the table.
 *
 * @return array{source:string,entries:list<array{code:string,label:string,native:string,dir:string,direction_known:bool}>,names_available:bool}
 */
function orange_language_catalog(PDO $pdo): array
{
    $empty = [
        'source' => 'absent',
        'entries' => [],
        'names_available' => false,
    ];
    if (orange_language_catalog_support_status('en') === 'unresolved') {
        return [
            'source' => 'operational_list_unresolved',
            'entries' => [],
            'names_available' => false,
        ];
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'orange_language_reference'");
            if (!$st || !$st->fetchColumn()) {
                return $empty;
            }
            $info = $pdo->query('PRAGMA table_info(orange_language_reference)');
            $names = [];
            foreach ($info ? $info->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                $names[] = (string) ($row['name'] ?? '');
            }
        } else {
            $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute(['orange_language_reference']);
            $names = [];
            foreach ($st->fetchAll(PDO::FETCH_NUM) ?: [] as $row) {
                $names[] = (string) ($row[0] ?? '');
            }
            if ($names === []) {
                return $empty;
            }
        }
    } catch (Throwable $e) {
        return $empty;
    }
    $has = static function (string $column) use ($names): bool {
        return in_array($column, $names, true);
    };
    $rich = $has('label_en') && $has('label_native') && $has('dir');
    $order = 'locale_code ASC';
    if ($has('sort_order') && $has('id')) {
        $order = 'sort_order ASC, id ASC';
    } elseif ($has('sort_order')) {
        $order = 'sort_order ASC, locale_code ASC';
    }
    if ($rich) {
        $sql = 'SELECT locale_code, label_en, label_native, dir FROM orange_language_reference WHERE is_active = 1 ORDER BY ' . $order;
    } else {
        $sql = 'SELECT locale_code FROM orange_language_reference WHERE is_active = 1 ORDER BY ' . $order;
    }
    try {
        $rows = $pdo->query($sql);
        $fetched = $rows ? $rows->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [
            'source' => 'unreadable',
            'entries' => [],
            'names_available' => false,
        ];
    }
    $entries = [];
    $seen = [];
    foreach ($fetched ?: [] as $row) {
        $code = orange_content_locale_code((string) ($row['locale_code'] ?? ''));
        if ($code === null || isset($seen[$code]) || !orange_language_catalog_stored_code_operational($code)) {
            continue;
        }
        $seen[$code] = true;
        if ($rich) {
            $label = trim((string) ($row['label_en'] ?? ''));
            $native = trim((string) ($row['label_native'] ?? ''));
            $dirRaw = (string) ($row['dir'] ?? '');
            $dir = $dirRaw === 'rtl' ? 'rtl' : 'ltr';
            $entries[] = [
                'code' => $code,
                'label' => $label !== '' ? $label : $code,
                'native' => $native !== '' ? $native : $code,
                'dir' => $dir,
                'direction_known' => true,
            ];
            continue;
        }
        $knownDir = in_array($code, ['ar', 'en', 'fil', 'hi'], true);
        $entries[] = [
            'code' => $code,
            'label' => $code,
            'native' => $code,
            'dir' => $code === 'ar' ? 'rtl' : ($knownDir ? 'ltr' : ''),
            'direction_known' => $knownDir,
        ];
    }

    return [
        'source' => $rich ? 'orange_language_reference' : 'orange_language_reference_codes_only',
        'entries' => $entries,
        'names_available' => $rich,
    ];
}

function orange_language_catalog_support_status(string $code, ?string $catalogPath = null): string
{
    $path = $catalogPath ?? (__DIR__ . '/orange_language_catalog_data.php');
    if ($catalogPath !== null && !is_file($catalogPath)) {
        return 'unresolved';
    }
    if (!function_exists('orange_language_catalog_code_operational')) {
        if (!is_file($path)) {
            return 'unresolved';
        }
        require_once $path;
    }
    if (!function_exists('orange_language_catalog_code_operational')) {
        return 'unresolved';
    }
    $normalized = strtolower(trim($code));
    if ($normalized === '' || !orange_language_catalog_code_operational($normalized)) {
        return 'removed';
    }

    return 'allow';
}

function orange_language_catalog_assert_live_write(string $code): void
{
    $status = orange_language_catalog_support_status($code);
    if ($status === 'unresolved') {
        throw new RuntimeException('operational_list_unresolved');
    }
    if ($status !== 'allow') {
        throw new RuntimeException('removed_locale_refused');
    }
}

function orange_language_catalog_stored_code_operational(string $code): bool
{
    return orange_language_catalog_support_status($code) === 'allow';
}

function orange_content_customer_ready(string $locale): bool
{
    $code = orange_content_locale_code($locale);
    if ($code === null) {
        return false;
    }
    if (!function_exists('orange_storefront_ui_live_ready_locales')) {
        require_once __DIR__ . '/orange_storefront_ui_readiness.php';
    }

    return in_array($code, orange_storefront_ui_live_ready_locales(), true)
        && orange_language_catalog_stored_code_operational($code);
}
