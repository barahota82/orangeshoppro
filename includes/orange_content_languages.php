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
        if ($locale !== null) {
            $out[] = $locale;
        }
    }
    return $out;
}

function orange_language_reference_is_active(PDO $pdo, string $locale): bool
{
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
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM orange_country_locale_role WHERE country_id = ?');
        $del->execute([$countryId]);
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
            'customer_wired' => false,
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
            'customer_wired' => false,
        ];
    }
    if (count($bucket['base']) === 0) {
        return [
            'mode' => 'no_base',
            'base' => null,
            'content' => $bucket['content'],
            'admin_ui' => $bucket['admin_ui'],
            'customer' => $bucket['customer'],
            'customer_wired' => false,
        ];
    }
    return [
        'mode' => 'configured',
        'base' => $bucket['base'][0],
        'content' => $bucket['content'],
        'admin_ui' => $bucket['admin_ui'],
        'customer' => $bucket['customer'],
        'customer_wired' => false,
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

function orange_content_customer_ready(string $locale): bool
{
    return false;
}
