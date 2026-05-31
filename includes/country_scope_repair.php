<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/schema_migrations.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/country_provision.php';

/**
 * إصلاح country_id من قناة التسجيل — idempotent.
 * slug القناة مكرّر بين الدول (نسخ provision) — لا UPDATE بـ JOIN slug وحده إلا عند slug فريد عالمياً.
 */
function orange_country_scope_repair_from_channels(PDO $pdo): array
{
    $out = [
        'storefront_accounts' => 0,
        'merge_requests' => 0,
        'customers' => 0,
    ];
    if (!orange_table_exists($pdo, 'channels') || !orange_table_has_column($pdo, 'channels', 'country_id')) {
        return $out;
    }

    if (orange_table_exists($pdo, 'storefront_accounts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'country_id')
        && orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
        $out['storefront_accounts'] += (int) $pdo->exec(
            'UPDATE storefront_accounts sa
             INNER JOIN (
                 SELECT slug, MIN(country_id) AS country_id
                 FROM channels
                 WHERE country_id IS NOT NULL AND country_id > 0
                 GROUP BY slug
                 HAVING COUNT(DISTINCT country_id) = 1
             ) u ON u.slug = sa.registered_channel_slug
             SET sa.country_id = u.country_id
             WHERE sa.registered_channel_slug IS NOT NULL
               AND TRIM(sa.registered_channel_slug) <> \'\'
               AND (sa.country_id IS NULL OR sa.country_id = 0 OR sa.country_id <> u.country_id)'
        );
    }

    if (orange_table_exists($pdo, 'storefront_phone_merge_requests')
        && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'country_id')
        && orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'proposed_channel_slug')) {
        $out['merge_requests'] += (int) $pdo->exec(
            'UPDATE storefront_phone_merge_requests r
             INNER JOIN (
                 SELECT slug, MIN(country_id) AS country_id
                 FROM channels
                 WHERE country_id IS NOT NULL AND country_id > 0
                 GROUP BY slug
                 HAVING COUNT(DISTINCT country_id) = 1
             ) u ON u.slug = r.proposed_channel_slug
             SET r.country_id = u.country_id
             WHERE r.proposed_channel_slug IS NOT NULL
               AND TRIM(r.proposed_channel_slug) <> \'\'
               AND (r.country_id IS NULL OR r.country_id = 0 OR r.country_id <> u.country_id)'
        );
    }

    if (orange_table_exists($pdo, 'customers')
        && orange_table_has_column($pdo, 'customers', 'country_id')
        && orange_table_exists($pdo, 'storefront_accounts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'customer_id')
        && orange_table_has_column($pdo, 'storefront_accounts', 'country_id')) {
        $out['customers'] += (int) $pdo->exec(
            'UPDATE customers c
             INNER JOIN storefront_accounts sa ON sa.customer_id = c.id
             SET c.country_id = sa.country_id
             WHERE sa.country_id IS NOT NULL AND sa.country_id > 0
               AND (c.country_id IS NULL OR c.country_id = 0 OR c.country_id <> sa.country_id)'
        );
    }

    if (orange_table_exists($pdo, 'customers') && orange_table_has_column($pdo, 'customers', 'country_id')) {
        $kwId = orange_countries_default_id($pdo);
        if (orange_table_exists($pdo, 'orders')
            && orange_table_has_column($pdo, 'orders', 'customer_id')
            && orange_table_has_column($pdo, 'orders', 'country_id')) {
            $out['customers'] += (int) $pdo->exec(
                'UPDATE customers c
                 INNER JOIN (
                     SELECT o.customer_id, o.country_id
                     FROM orders o
                     WHERE o.customer_id IS NOT NULL AND o.customer_id > 0
                       AND o.country_id IS NOT NULL AND o.country_id > 0
                     GROUP BY o.customer_id, o.country_id
                 ) x ON x.customer_id = c.id
                 SET c.country_id = x.country_id
                 WHERE c.country_id IS NULL OR c.country_id = 0
                    OR (c.country_id = ' . (int) $kwId . ' AND x.country_id <> c.country_id)'
            );
        }
    }

    return $out;
}

function orange_catalog_migrate_country_scope_repair_v71(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v71';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    orange_country_scope_repair_from_channels($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v71 OK');
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v71 marker: ' . $e->getMessage());
        }
    }
}

function orange_catalog_migrate_country_scope_repair_v72(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v72';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_repair_from_channels($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v72 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v72 marker: ' . $e->getMessage());
        }
    }
}

/** v73 — إصلاح بعد اكتشاف JOIN slug المكرّر + مزامنة customers من storefront_accounts.country_id. */
function orange_catalog_migrate_country_scope_repair_v73(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v73';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_repair_from_channels($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v73 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v73 marker: ' . $e->getMessage());
        }
    }
}

/**
 * v74 — فصل دليل الحسابات per country: backfill KW فقط (لا نسخ تلقائي — v77).
 *
 * @return array{backfill_kw:int, countries_provisioned:int, accounts_copied:int}
 */
function orange_country_scope_repair_accounts_per_country(PDO $pdo): array
{
    $out = [
        'backfill_kw' => 0,
        'countries_provisioned' => 0,
        'accounts_copied' => 0,
    ];
    if (!orange_table_exists($pdo, 'accounts') || !orange_table_exists($pdo, 'countries')) {
        return $out;
    }

    orange_catalog_ensure_country_id_columns($pdo);
    orange_schema_invalidate_column_check('accounts', 'country_id');

    if (!orange_table_has_column($pdo, 'accounts', 'country_id')) {
        return $out;
    }

    $kwId = orange_countries_default_id($pdo);
    if ($kwId > 0) {
        $out['backfill_kw'] = (int) $pdo->exec(
            'UPDATE accounts SET country_id = ' . (int) $kwId
            . ' WHERE country_id IS NULL OR country_id = 0'
        );
    }

    /* v77: لا نسخ دليل الكويت لباقي الدول تلقائياً — التهيئة اليدوية من شاشة الدول فقط. */

    return $out;
}

/** v74 — إصلاح تسريب شجرة الحسابات بين الدول. */
function orange_catalog_migrate_country_scope_repair_v74(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v74';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_repair_accounts_per_country($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v74 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v74 marker: ' . $e->getMessage());
        }
    }
}

/**
 * v75 — فصل شاشات GL الإدارية: backfill country_id + نسخ الأبعاد التحليلية per country.
 *
 * @return array{analytical_backfill:int, edit_lock_backfill:int, dimensions_copied:int}
 */
function orange_country_scope_repair_gl_admin_scope_v75(PDO $pdo): array
{
    $out = [
        'analytical_backfill' => 0,
        'edit_lock_backfill' => 0,
        'dimensions_copied' => 0,
    ];
    $kwId = orange_countries_default_id($pdo);

    if ($kwId > 0 && orange_table_exists($pdo, 'analytical_dimension')
        && orange_table_has_column($pdo, 'analytical_dimension', 'country_id')) {
        $out['analytical_backfill'] = (int) $pdo->exec(
            'UPDATE analytical_dimension SET country_id = ' . (int) $kwId
            . ' WHERE country_id IS NULL OR country_id = 0'
        );
    }

    if ($kwId > 0 && orange_table_exists($pdo, 'orange_edit_lock_registry')
        && orange_table_has_column($pdo, 'orange_edit_lock_registry', 'country_id')) {
        $out['edit_lock_backfill'] = (int) $pdo->exec(
            'UPDATE orange_edit_lock_registry SET country_id = ' . (int) $kwId
            . ' WHERE country_id IS NULL OR country_id = 0'
        );
    }

    if ($kwId <= 0 || !orange_table_exists($pdo, 'countries')
        || !orange_table_exists($pdo, 'analytical_dimension')
        || !orange_table_exists($pdo, 'analytical_dimension_value')) {
        return $out;
    }

    require_once __DIR__ . '/analytical_dimensions.php';
    $countries = $pdo->query('SELECT id FROM countries ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($countries as $cRow) {
        $cid = (int) ($cRow['id'] ?? 0);
        if ($cid <= 0 || $cid === $kwId) {
            continue;
        }
        $stCnt = $pdo->prepare('SELECT COUNT(*) FROM analytical_dimension WHERE country_id = ?');
        $stCnt->execute([$cid]);
        if ((int) $stCnt->fetchColumn() > 0) {
            continue;
        }
        $stSrc = $pdo->prepare('SELECT * FROM analytical_dimension WHERE country_id = ? ORDER BY id ASC');
        $stSrc->execute([$kwId]);
        $dims = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($dims as $dim) {
            if (!is_array($dim)) {
                continue;
            }
            $oldDimId = (int) ($dim['id'] ?? 0);
            unset($dim['id']);
            $dim['country_id'] = $cid;
            $cols = array_keys($dim);
            $pdo->prepare(
                'INSERT INTO analytical_dimension (`' . implode('`, `', $cols) . '`) VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ')'
            )->execute(array_values($dim));
            $newDimId = (int) $pdo->lastInsertId();
            if ($oldDimId <= 0 || $newDimId <= 0) {
                continue;
            }
            $stVals = $pdo->prepare('SELECT * FROM analytical_dimension_value WHERE dimension_id = ? ORDER BY id ASC');
            $stVals->execute([$oldDimId]);
            foreach ($stVals->fetchAll(PDO::FETCH_ASSOC) ?: [] as $val) {
                if (!is_array($val)) {
                    continue;
                }
                unset($val['id']);
                $val['dimension_id'] = $newDimId;
                $vCols = array_keys($val);
                $pdo->prepare(
                    'INSERT INTO analytical_dimension_value (`' . implode('`, `', $vCols) . '`) VALUES ('
                    . implode(', ', array_fill(0, count($vCols), '?')) . ')'
                )->execute(array_values($val));
            }
            $out['dimensions_copied']++;
        }
    }

    return $out;
}

/**
 * v76 — placeholder (لا نسخ GL من الكويت؛ التنظيف في v77).
 *
 * @return array{countries_repaired:int, accounts_copied:int, journal_types_copied:int, fiscal_years_copied:int, gl_settings_copied:int}
 */
function orange_country_scope_repair_gl_admin_missing_v76(PDO $pdo): array
{
    return [
        'countries_repaired' => 0,
        'accounts_copied' => 0,
        'journal_types_copied' => 0,
        'fiscal_years_copied' => 0,
        'gl_settings_copied' => 0,
    ];
}

/** v76 — إصلاح سياق الدولة + إكمال بيانات GL per country. */
function orange_catalog_migrate_country_scope_repair_v76(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v76';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_repair_gl_admin_missing_v76($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v76 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v76 marker: ' . $e->getMessage());
        }
    }
}

/**
 * هل وُجدت قيود/حركة GL فعلية لهذه الدولة؟ (يمنع حذف الدليل المنسوخ).
 */
function orange_country_gl_has_posting_activity(PDO $pdo, int $countryId): bool
{
    if ($countryId <= 0) {
        return false;
    }
    if (orange_table_exists($pdo, 'journal_vouchers') && orange_table_has_column($pdo, 'journal_vouchers', 'country_id')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM journal_vouchers WHERE country_id = ?');
        $st->execute([$countryId]);
        if ((int) $st->fetchColumn() > 0) {
            return true;
        }
    }
    if (orange_table_exists($pdo, 'journal_lines')
        && orange_table_exists($pdo, 'accounts')
        && orange_table_has_column($pdo, 'accounts', 'country_id')) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM journal_lines jl
             INNER JOIN accounts a ON a.id = jl.account_id
             WHERE a.country_id = ?
             LIMIT 1'
        );
        $st->execute([$countryId]);
        if ((int) $st->fetchColumn() > 0) {
            return true;
        }
    }

    return false;
}

/**
 * حذف شجرة accounts لدولة (من الأوراق للجذور) — بلا قيود GL.
 */
function orange_country_delete_accounts_tree_for_country(PDO $pdo, int $countryId): int
{
    if ($countryId <= 0 || !orange_table_exists($pdo, 'accounts')
        || !orange_table_has_column($pdo, 'accounts', 'country_id')) {
        return 0;
    }
    $deleted = 0;
    for ($pass = 0; $pass < 5000; $pass++) {
        $st = $pdo->prepare(
            'SELECT a.id FROM accounts a
             WHERE a.country_id = ?
               AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent_id = a.id)
             LIMIT 200'
        );
        $st->execute([$countryId]);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($ids === []) {
            break;
        }
        foreach ($ids as $rawId) {
            $aid = (int) $rawId;
            if ($aid <= 0) {
                continue;
            }
            try {
                $del = $pdo->prepare('DELETE FROM accounts WHERE id = ? AND country_id = ?');
                $del->execute([$aid, $countryId]);
                if ($del->rowCount() > 0) {
                    $deleted++;
                }
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] delete account #' . $aid . ' cid=' . $countryId . ': ' . $e->getMessage());
                }
            }
        }
    }

    return $deleted;
}

/**
 * إزالة حزمة GL المنسوخة من الكويت لدولة بلا حركة قيود (شجرة موحّدة / v74 / provision).
 *
 * @return array{purged:bool, skipped:string, accounts_deleted:int, journal_types_deleted:int, fiscal_years_deleted:int}
 */
function orange_country_purge_cloned_gl_bundle_if_idle(PDO $pdo, int $countryId): array
{
    $out = [
        'purged' => false,
        'skipped' => '',
        'accounts_deleted' => 0,
        'journal_types_deleted' => 0,
        'fiscal_years_deleted' => 0,
        'dimensions_deleted' => 0,
    ];
    $kwId = orange_countries_default_id($pdo);
    if ($countryId <= 0 || ($kwId > 0 && $countryId === $kwId)) {
        $out['skipped'] = 'kw_or_invalid';

        return $out;
    }
    if (orange_country_gl_has_posting_activity($pdo, $countryId)) {
        $out['skipped'] = 'has_gl_activity';

        return $out;
    }

    if (orange_table_exists($pdo, 'orange_gl_setting_alloc')
        && orange_table_has_column($pdo, 'orange_gl_setting_alloc', 'country_id')) {
        $pdo->prepare('DELETE FROM orange_gl_setting_alloc WHERE country_id = ?')->execute([$countryId]);
    }
    if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')
        && orange_table_has_column($pdo, 'orange_gl_journal_type_rules', 'country_id')) {
        $pdo->prepare('DELETE FROM orange_gl_journal_type_rules WHERE country_id = ?')->execute([$countryId]);
    }
    if (orange_table_exists($pdo, 'orange_gl_account_settings')
        && orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
        $pdo->prepare('DELETE FROM orange_gl_account_settings WHERE country_id = ?')->execute([$countryId]);
    }
    if (orange_table_exists($pdo, 'orange_invoice_line_presets')
        && orange_table_has_column($pdo, 'orange_invoice_line_presets', 'country_id')) {
        $pdo->prepare('DELETE FROM orange_invoice_line_presets WHERE country_id = ?')->execute([$countryId]);
    }
    if (orange_table_exists($pdo, 'journal_types') && orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        $st = $pdo->prepare('DELETE FROM journal_types WHERE country_id = ?');
        $st->execute([$countryId]);
        $out['journal_types_deleted'] = $st->rowCount();
    }
    if (orange_table_exists($pdo, 'fiscal_years') && orange_table_has_column($pdo, 'fiscal_years', 'country_id')) {
        $st = $pdo->prepare('DELETE FROM fiscal_years WHERE country_id = ?');
        $st->execute([$countryId]);
        $out['fiscal_years_deleted'] = $st->rowCount();
    }
    if (orange_table_exists($pdo, 'analytical_dimension')
        && orange_table_has_column($pdo, 'analytical_dimension', 'country_id')
        && orange_table_exists($pdo, 'analytical_dimension_value')) {
        $stDim = $pdo->prepare('SELECT id FROM analytical_dimension WHERE country_id = ?');
        $stDim->execute([$countryId]);
        foreach ($stDim->fetchAll(PDO::FETCH_COLUMN) ?: [] as $dimId) {
            $did = (int) $dimId;
            if ($did <= 0) {
                continue;
            }
            $pdo->prepare('DELETE FROM analytical_dimension_value WHERE dimension_id = ?')->execute([$did]);
            $pdo->prepare('DELETE FROM analytical_dimension WHERE id = ? AND country_id = ?')->execute([$did, $countryId]);
            $out['dimensions_deleted']++;
        }
    }

    $out['accounts_deleted'] = orange_country_delete_accounts_tree_for_country($pdo, $countryId);
    $out['purged'] = $out['accounts_deleted'] > 0
        || $out['journal_types_deleted'] > 0
        || $out['fiscal_years_deleted'] > 0
        || $out['dimensions_deleted'] > 0;

    return $out;
}

/**
 * v77 — إزالة دليل/GL المنسوخ تلقائياً من غير الكويت (بعد شجرة المنتجات الموحّدة / v74).
 *
 * @return array{countries_purged:int, countries_skipped_activity:int, details:list<array<string,mixed>>}
 */
function orange_country_scope_purge_kw_gl_clones_v77(PDO $pdo): array
{
    $out = [
        'countries_purged' => 0,
        'countries_skipped_activity' => 0,
        'details' => [],
    ];
    if (!orange_table_exists($pdo, 'countries')) {
        return $out;
    }
    $kwId = orange_countries_default_id($pdo);
    $countries = $pdo->query('SELECT id FROM countries ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($countries as $cRow) {
        $cid = (int) ($cRow['id'] ?? 0);
        if ($cid <= 0 || ($kwId > 0 && $cid === $kwId)) {
            continue;
        }
        $one = orange_country_purge_cloned_gl_bundle_if_idle($pdo, $cid);
        $one['country_id'] = $cid;
        $out['details'][] = $one;
        if (!empty($one['purged'])) {
            $out['countries_purged']++;
        } elseif (($one['skipped'] ?? '') === 'has_gl_activity') {
            $out['countries_skipped_activity']++;
        }
    }

    return $out;
}

/** v77 — إيقاف نسخ GL التلقائي + تنظيف النسخ المنسوخة من الكويت. */
function orange_catalog_migrate_country_scope_repair_v77(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v77';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_purge_kw_gl_clones_v77($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v77 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v77 marker: ' . $e->getMessage());
        }
    }
}

/** v75 — إصلاح فصل شاشات GL الإدارية (أبعاد، إقفال، …). */
function orange_catalog_migrate_country_scope_repair_v75(PDO $pdo): void
{
    $marker = 'php_country_scope_repair_v75';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $stats = orange_country_scope_repair_gl_admin_scope_v75($pdo);

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v75 OK '
                . json_encode($stats, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country scope repair v75 marker: ' . $e->getMessage());
        }
    }
}
