<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';

function orange_department_countries_table_ready(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'department_countries');
}

/**
 * SQL AND fragment: master active + active in country (fallback: master only if no junction table).
 */
function orange_department_country_active_sql(PDO $pdo, string $deptAlias, int $countryId): string
{
    $alias = trim($deptAlias) !== '' ? trim($deptAlias) : 'd';
    $master = $alias . '.is_active = 1';
    if ($countryId <= 0 || !orange_department_countries_table_ready($pdo)) {
        return $master;
    }

    return $master . ' AND EXISTS (
        SELECT 1 FROM department_countries dc
        WHERE dc.department_id = ' . $alias . '.id
          AND dc.country_id = ' . (int) $countryId . '
          AND dc.is_active = 1
    )';
}

/**
 * @return array<int, bool> department_id => active in country
 */
function orange_department_countries_active_map(PDO $pdo, int $countryId): array
{
    $out = [];
    if ($countryId <= 0 || !orange_table_exists($pdo, 'departments')) {
        return $out;
    }
    if (!orange_department_countries_table_ready($pdo)) {
        $rows = $pdo->query('SELECT id, is_active FROM departments')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $out[(int) ($r['id'] ?? 0)] = (int) ($r['is_active'] ?? 0) === 1;
        }

        return $out;
    }
    $st = $pdo->prepare(
        'SELECT d.id, COALESCE(dc.is_active, 0) AS country_active
         FROM departments d
         LEFT JOIN department_countries dc ON dc.department_id = d.id AND dc.country_id = ?
         ORDER BY d.sort_order ASC, d.id ASC'
    );
    $st->execute([$countryId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            $out[$id] = (int) ($r['country_active'] ?? 0) === 1;
        }
    }

    return $out;
}

function orange_department_countries_set(PDO $pdo, int $departmentId, int $countryId, bool $active): void
{
    if ($departmentId <= 0 || $countryId <= 0 || !orange_department_countries_table_ready($pdo)) {
        return;
    }
    $st = $pdo->prepare(
        'INSERT INTO department_countries (department_id, country_id, is_active)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)'
    );
    $st->execute([$departmentId, $countryId, $active ? 1 : 0]);
}

/** قسم جديد: غير نشط في كل الدول المسجّلة. */
function orange_department_countries_seed_inactive_all(PDO $pdo, int $departmentId): void
{
    if ($departmentId <= 0 || !orange_department_countries_table_ready($pdo) || !orange_table_exists($pdo, 'countries')) {
        return;
    }
    $rows = $pdo->query('SELECT id FROM countries ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $cid = (int) ($r['id'] ?? 0);
        if ($cid > 0) {
            orange_department_countries_set($pdo, $departmentId, $cid, false);
        }
    }
}

/** دولة جديدة: صف لكل قسم — غير نشط افتراضياً. */
function orange_department_countries_seed_for_new_country(PDO $pdo, int $countryId, bool $activeDefault = false): void
{
    if ($countryId <= 0 || !orange_department_countries_table_ready($pdo) || !orange_table_exists($pdo, 'departments')) {
        return;
    }
    $deptIds = $pdo->query('SELECT id FROM departments ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($deptIds as $did) {
        $id = (int) $did;
        if ($id > 0) {
            orange_department_countries_set($pdo, $id, $countryId, $activeDefault);
        }
    }
}

/**
 * @return array{copied:int, skipped:bool, reason:string}
 */
function orange_department_countries_copy_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = ['copied' => 0, 'skipped' => true, 'reason' => ''];
    if ($targetCountryId <= 0 || !orange_department_countries_table_ready($pdo)) {
        $out['reason'] = 'no_table';

        return $out;
    }
    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    if ($sourceCountryId <= 0 || $sourceCountryId === $targetCountryId) {
        $out['reason'] = 'no_source';

        return $out;
    }
    $stSrc = $pdo->prepare('SELECT department_id, is_active FROM department_countries WHERE country_id = ?');
    $stSrc->execute([$sourceCountryId]);
    $srcRows = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($srcRows === []) {
        $out['reason'] = 'source_empty';

        return $out;
    }
    foreach ($srcRows as $r) {
        $did = (int) ($r['department_id'] ?? 0);
        if ($did <= 0) {
            continue;
        }
        orange_department_countries_set($pdo, $did, $targetCountryId, (int) ($r['is_active'] ?? 0) === 1);
        $out['copied']++;
    }
    $out['skipped'] = $out['copied'] <= 0;
    if (!$out['skipped']) {
        $out['reason'] = 'copied';
    }

    return $out;
}

function orange_department_countries_require_global_admin(): void
{
    require_once __DIR__ . '/admin_permissions.php';
    $pdo = db();
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $st = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$adminId]);
    $admin = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($admin) || !orange_admin_has_full_access($admin)) {
        json_response(['success' => false, 'message' => 'إدارة الأقسام العامة للمشرف العام فقط'], 403);
    }
}
