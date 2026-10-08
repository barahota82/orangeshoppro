<?php
declare(strict_types=1);

/**
 * Isolated create/update for departments.
 * Stands in for admin/api/departments/save.php and update.php without config.php.
 * Keeps global-admin gate, slug uniqueness, sort, Arabic duplicate rule, and
 * inactive country rows on create. The record function owns the transaction.
 */

function orange_department_assert_global_admin(array $actor): void
{
    $id = (int) ($actor['id'] ?? 0);
    $active = (int) ($actor['is_active'] ?? 0) === 1;
    $super = (int) ($actor['is_superuser'] ?? 0) === 1;
    $global = $super || (int) ($actor['country_id'] ?? 0) <= 0;
    if ($id <= 0 || !$active || !$global || !empty($actor['from_client'])) {
        throw new RuntimeException('admin_forbidden');
    }
}

if (!function_exists('orange_normalize_arabic_name')) {
    function orange_normalize_arabic_name(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        $s = str_replace("\u{0640}", '', $s);
        $s = preg_replace('/[\x{200C}\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E4}\x{06E7}\x{06E8}\x{06EA}-\x{06ED}]/u', '', $s) ?? $s;
        $s = str_replace(
            ["\u{0622}", "\u{0623}", "\u{0625}", "\u{0671}", "\u{0672}", "\u{0673}", "\u{0675}"],
            "\u{0627}",
            $s
        );
        $s = str_replace("\u{0649}", "\u{064A}", $s);
        $s = str_replace("\u{0629}", "\u{0647}", $s);
        $s = str_replace("\u{0624}", "\u{0648}", $s);
        $s = str_replace("\u{0626}", "\u{064A}", $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return $s;
    }
}

if (!function_exists('orange_rows_normalized_arabic_conflict')) {
    function orange_rows_normalized_arabic_conflict(array $rows, string $idKey, string $nameKey, string $candidateRaw, ?int $excludeId = null): bool
    {
        $target = orange_normalize_arabic_name($candidateRaw);
        if ($target === '') {
            return false;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rid = (int) ($row[$idKey] ?? 0);
            if ($excludeId !== null && $rid === $excludeId) {
                continue;
            }
            $val = (string) ($row[$nameKey] ?? '');
            if (orange_normalize_arabic_name($val) === $target) {
                return true;
            }
        }
        return false;
    }
}

function orange_department_slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text) ?? '';
    $text = preg_replace('/[\s-]+/', '-', $text) ?? '';
    return trim($text, '-');
}

function orange_department_unique_slug(PDO $pdo, string $slug, ?int $excludeId): string
{
    $base = trim($slug);
    if ($base === '') {
        throw new RuntimeException('slug_required');
    }
    $candidate = $base;
    $i = 2;
    while (true) {
        if ($excludeId === null) {
            $st = $pdo->prepare('SELECT id FROM departments WHERE slug = ? LIMIT 1');
            $st->execute([$candidate]);
        } else {
            $st = $pdo->prepare('SELECT id FROM departments WHERE slug = ? AND id <> ? LIMIT 1');
            $st->execute([$candidate, $excludeId]);
        }
        if (!$st->fetch()) {
            return $candidate;
        }
        $candidate = $base . '-' . $i;
        $i++;
    }
}

function orange_department_next_sort(PDO $pdo): int
{
    $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM departments')->fetchColumn();
    return $sort > 0 ? $sort : 1;
}

function orange_department_seed_inactive_countries(PDO $pdo, int $departmentId): void
{
    $rows = $pdo->query('SELECT id FROM countries ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $sql = $driver === 'sqlite'
        ? 'INSERT OR IGNORE INTO department_countries (department_id, country_id, is_active) VALUES (?, ?, 0)'
        : 'INSERT IGNORE INTO department_countries (department_id, country_id, is_active) VALUES (?, ?, 0)';
    $ins = $pdo->prepare($sql);
    foreach ($rows as $row) {
        $cid = (int) ($row['id'] ?? 0);
        if ($cid > 0) {
            $ins->execute([$departmentId, $cid]);
        }
    }
}

function orange_department_arabic_column_text(string $base, string $baseText, array $locales, ?array $existing): string
{
    if ($base === 'ar') {
        return $baseText;
    }
    if (isset($locales['ar']) && is_array($locales['ar']) && !empty($locales['ar']['present'])) {
        return (string) ($locales['ar']['text'] ?? '');
    }
    return (string) ($existing['name_ar'] ?? '');
}

function orange_department_prepare_common(PDO $pdo, array $actor, int $countryId, string $baseText, string $slug, array $locales, ?int $excludeId, ?array $existing): array
{
    orange_department_assert_global_admin($actor);
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    if ($roles['mode'] !== 'configured' || !is_string($roles['base'])) {
        throw new RuntimeException('locale_settings_' . $roles['mode']);
    }
    $base = (string) $roles['base'];
    if (trim($baseText) === '') {
        throw new RuntimeException('base_text_required');
    }
    $arabic = orange_department_arabic_column_text($base, $baseText, $locales, $existing);
    $depRows = $pdo->query('SELECT id, name_ar FROM departments')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (orange_rows_normalized_arabic_conflict($depRows, 'id', 'name_ar', $arabic, $excludeId)) {
        throw new RuntimeException('arabic_duplicate');
    }
    $slugText = trim($slug);
    if ($slugText === '') {
        $english = $base === 'en' ? $baseText : (string) (($locales['en']['text'] ?? '') ?: ($existing['name_en'] ?? ''));
        $slugText = orange_department_slugify($english);
    }
    $slugText = orange_department_unique_slug($pdo, $slugText, $excludeId);
    return [$roles, $base, $slugText];
}

function orange_department_record_create(PDO $pdo, array $actor, int $countryId, string $baseText, array $locales, string $slug, int $sort): array
{
    [, $base, $slugText] = orange_department_prepare_common($pdo, $actor, $countryId, $baseText, $slug, $locales, null, null);
    if ($sort <= 0) {
        $sort = orange_department_next_sort($pdo);
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('transaction_already_open');
    }
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO departments (name_ar, name_en, name_fil, name_hi, slug, is_active, sort_order) VALUES (\'\', \'\', \'\', \'\', ?, 1, ?)');
        $ins->execute([$slugText, $sort]);
        $id = (int) $pdo->lastInsertId();
        orange_department_seed_inactive_countries($pdo, $id);
        orange_department_locale_save($pdo, $countryId, $id, $baseText, $locales, null, false);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['id' => $id, 'slug' => $slugText, 'sort_order' => $sort, 'base' => $base];
}

function orange_department_record_update(PDO $pdo, array $actor, int $countryId, int $departmentId, string $baseText, array $locales, string $slug, int $sort): array
{
    $existing = orange_department_load($pdo, $departmentId);
    [, $base, $slugText] = orange_department_prepare_common($pdo, $actor, $countryId, $baseText, $slug, $locales, $departmentId, $existing);
    if ($sort <= 0) {
        $sort = (int) ($existing['sort_order'] ?? 0);
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('transaction_already_open');
    }
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE departments SET slug = ?, sort_order = ? WHERE id = ?');
        $upd->execute([$slugText, $sort, $departmentId]);
        orange_department_locale_save($pdo, $countryId, $departmentId, $baseText, $locales, null, false);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['id' => $departmentId, 'slug' => $slugText, 'sort_order' => $sort, 'base' => $base];
}
